<?php
/**
 * Scheduled source-sheet fetch -> staging and reconciliation, plus optional FleetDesk managed-tab writes.
 * Unresolved relationships/conflicts are routed to the review queue by the importer.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); }
require_once __DIR__.'/../includes/bootstrap.php';

$root=dirname(__DIR__);$logDir=$root.'/logs/scheduled-sync';if(!is_dir($logDir)&&!mkdir($logDir,0775,true)&&!is_dir($logDir)){fwrite(STDERR,"Could not create scheduled-sync log directory.\n");exit(1);}
$logFile=$logDir.'/pipeline.log';
function fleetSyncLog(string $message): void { global $logFile; $line='['.date('Y-m-d H:i:s').'] '.$message.PHP_EOL; file_put_contents($logFile,$line,FILE_APPEND|LOCK_EX); echo $line; }
function fleetRunStep(array $command,string $cwd): array {
    $pipes=[];$process=proc_open($command,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,$cwd);
    if(!is_resource($process))throw new RuntimeException('Unable to start pipeline step: '.implode(' ',$command));
    fclose($pipes[0]);$stdout=stream_get_contents($pipes[1]);fclose($pipes[1]);$stderr=stream_get_contents($pipes[2]);fclose($pipes[2]);$code=proc_close($process);
    return [$code,(string)$stdout,(string)$stderr];
}

$db=fleet_db();$lock=(int)$db->query("SELECT GET_LOCK('fleet_management_scheduled_sync',0)")->fetchColumn();
if($lock!==1){fleetSyncLog('Skipped: a previous sync pipeline is still running.');exit(0);}
try {
    $php=PHP_BINARY;
    fleetSyncLog('Started. Source spreadsheets are staged; sheet deletions require in-app approval, while approved FleetDesk data may mirror to managed tabs.');
    [$certCode,$certOut,$certErr]=fleetRunStep([$php,$root.'/google/sync-certificates.php'],$root);
    file_put_contents($logFile,$certOut.($certErr!==""?"\nCertificate sync STDERR: ".$certErr:""),FILE_APPEND|LOCK_EX);
    if($certCode!==0)fleetSyncLog('Certificate Sheet queue worker exited with code '.$certCode.'.');
    [$fleetWriteCode,$fleetWriteOut,$fleetWriteErr]=fleetRunStep([$php,$root.'/google/push-fleet-sheets.php'],$root);
    file_put_contents($logFile,$fleetWriteOut.($fleetWriteErr!==''?"\nFleet Sheet STDERR: ".$fleetWriteErr:""),FILE_APPEND|LOCK_EX);
    if($fleetWriteCode!==0)fleetSyncLog('Fleet Sheet writer exited with code '.$fleetWriteCode.'.');
    [$code,$out,$err]=fleetRunStep([$php,$root.'/google/stage-sheets.php'],$root);
    file_put_contents($logFile,$out.($err!==''?"\nSTDERR: ".$err:""),FILE_APPEND|LOCK_EX);
    if($code!==0)throw new RuntimeException('Raw staging failed with exit code '.$code.'.');
    $changed=preg_match('/\|\s+SUCCESS\s*$/m',$out)===1;
    if(!$changed){fleetSyncLog('No source changes detected; no reconciliation or asset writes needed.');exit(0);}

    $latest=$db->query("SELECT s.source_code,MAX(r.id) AS run_id FROM spreadsheet_sources s JOIN spreadsheet_sync_runs r ON r.source_id=s.id WHERE r.run_status='SUCCESS' GROUP BY s.id,s.source_code ORDER BY s.source_code")->fetchAll();
    $runIds=array_map(static fn($r)=>(int)$r['run_id'],$latest);
    if(count($runIds)<2)throw new RuntimeException('Expected successful snapshots for both registered source workbooks.');
    $runArg=implode(',',$runIds);
    fleetSyncLog('Reconciliation started for latest successful source runs: '.$runArg);
    [$code,$out,$err]=fleetRunStep([$php,$root.'/google/reconcile-staged.php',$runArg],$root);
    file_put_contents($logFile,$out.($err!==''?"\nSTDERR: ".$err:""),FILE_APPEND|LOCK_EX);
    if($code!==0)throw new RuntimeException('Reconciliation failed with exit code '.$code.'; production import was not run.');
    if(!preg_match_all('/reports[\\\\\/]reconciliation[\\\\\/]reconciliation_dry_run_[0-9_]+\.json/',$out,$matches)||empty($matches[0]))throw new RuntimeException('Reconciliation completed without a report path; production import was not run.');
    $relative=end($matches[0]);$reportPath=$root.'/'.str_replace(['\\','/'],DIRECTORY_SEPARATOR,$relative);
    if(!is_file($reportPath))throw new RuntimeException('Reconciliation report file is missing; production import was not run.');
    fleetSyncLog('Safe asset import started from '.$relative);
    [$code,$out,$err]=fleetRunStep([$php,$root.'/google/import-approved-candidates.php',$reportPath],$root);
    file_put_contents($logFile,$out.($err!==''?"\nSTDERR: ".$err:""),FILE_APPEND|LOCK_EX);
    if($code!==0)throw new RuntimeException('Candidate import failed with exit code '.$code.'.');
    [$code,$out,$err]=fleetRunStep([$php,$root.'/google/apply-source-changes.php',$runArg],$root);
    file_put_contents($logFile,$out.($err!==''?"\nSTDERR: ".$err:""),FILE_APPEND|LOCK_EX);
    if($code!==0)throw new RuntimeException('Source change archival failed with exit code '.$code.'.');
    fleetSyncLog('Completed successfully. Review cases remain open for unresolved items.');
} catch(Throwable $e) {
    fleetSyncLog('FAILED: '.$e->getMessage());
    exit(1);
} finally {
    $db->query("SELECT RELEASE_LOCK('fleet_management_scheduled_sync')");
}
