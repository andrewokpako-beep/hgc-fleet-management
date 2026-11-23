<?php
/** Retire assets only after every current source snapshot stops listing them. */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); }
require_once __DIR__ . '/../includes/bootstrap.php';

$runIds = array_values(array_unique(array_filter(array_map('intval', explode(',', (string)($argv[1] ?? ''))), static fn($id) => $id > 0)));
if (!$runIds) { fwrite(STDERR,"Supply one or more successful sync run IDs.\n"); exit(1); }
$db=fleet_db();
$marks=implode(',',array_fill(0,count($runIds),'?'));
$events=$db->prepare("SELECT l.id,l.sync_run_id,l.change_type,l.previous_source_row_number,l.previous_data,l.new_data,
                             s.source_code,sh.sheet_name
                      FROM spreadsheet_source_change_log l
                      JOIN spreadsheet_sources s ON s.id=l.source_id
                      JOIN spreadsheet_sheets sh ON sh.id=l.sheet_id
                      WHERE l.sync_run_id IN ($marks) AND l.change_type IN ('ROW_DELETED','ROW_UPDATED')
                      ORDER BY l.id");
$events->execute($runIds);
$currentRuns=$db->prepare("SELECT v.raw_data FROM spreadsheet_source_row_versions v
                           JOIN spreadsheet_sync_runs r ON r.id=v.sync_run_id
                           WHERE r.run_status='SUCCESS' AND r.id=(SELECT MAX(r2.id) FROM spreadsheet_sync_runs r2 WHERE r2.source_id=r.source_id AND r2.run_status='SUCCESS')");
$activeAssets=$db->prepare("SELECT a.id,a.primary_identifier,a.is_active,ai.normalized_value,ai.source_note
                            FROM assets a JOIN asset_identifiers ai ON ai.asset_id=a.id AND ai.is_primary=1
                            WHERE a.is_active=1 AND LOCATE(?,ai.source_note)>0");
$update=$db->prepare('UPDATE assets SET is_active=0 WHERE id=? AND is_active=1');
$history=$db->prepare("INSERT INTO asset_change_history (asset_id,sync_run_id,source_row_id,reconciliation_case_id,field_name,old_value,new_value,change_origin,changed_at,changed_by)
                       VALUES (?, ?, NULL, NULL, 'source_presence', ?, ?, 'SHEET_SYNC', NOW(), NULL)");
$retired=0;$retiredByRun=[];
foreach($events->fetchAll() as $event){
    $oldCells=json_decode((string)$event['previous_data'],true)?:[];
    $newCells=$event['new_data']===null?[]:(json_decode((string)$event['new_data'],true)?:[]);
    $oldValues=[];foreach($oldCells as $cell){if(!is_scalar($cell))continue;$value=strtoupper((string)preg_replace('/[^A-Z0-9]/','',(string)$cell));if(strlen($value)>=5&&strlen($value)<=17)$oldValues[$value]=true;}
    $newValues=[];foreach($newCells as $cell){if(!is_scalar($cell))continue;$value=strtoupper((string)preg_replace('/[^A-Z0-9]/','',(string)$cell));if(strlen($value)>=5&&strlen($value)<=17)$newValues[$value]=true;}
    $row=(int)$event['previous_source_row_number'];
    $provenance=(string)$event['source_code'].'/'.(string)$event['sheet_name'].' row '.$row.' col ';
    $activeAssets->execute([$provenance]);
    $candidates=$activeAssets->fetchAll();
    if(!$candidates)continue;
    // Build a current-only identifier set from the latest successful full snapshots.
    $currentValues=[];$currentRuns->execute();
    foreach($currentRuns->fetchAll(PDO::FETCH_COLUMN) as $raw){$cells=json_decode((string)$raw,true);if(!is_array($cells))continue;foreach($cells as $cell){if(!is_scalar($cell))continue;$value=strtoupper((string)preg_replace('/[^A-Z0-9]/','',(string)$cell));if(strlen($value)>=5&&strlen($value)<=17)$currentValues[$value]=true;}}
    foreach($candidates as $asset){
        $norm=strtoupper((string)$asset['normalized_value']);
        if(!isset($oldValues[$norm])||isset($newValues[$norm])||isset($currentValues[$norm]))continue;
        $before=json_encode(['active'=>true,'primary_identifier'=>$asset['primary_identifier'],'normalized_identifier'=>$norm,'source_provenance'=>$asset['source_note'],'source_change_id'=>(int)$event['id']],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
        $after=json_encode(['active'=>false,'reason'=>'No longer present in the latest successful source snapshot','source_change_id'=>(int)$event['id']],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
        $db->beginTransaction();
        try{$update->execute([(int)$asset['id']]);if($update->rowCount()){$history->execute([(int)$asset['id'],(int)$event['sync_run_id'],$before,$after]);$retired++;$runId=(int)$event['sync_run_id'];$retiredByRun[$runId]=($retiredByRun[$runId]??0)+1;}$db->commit();}
        catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    }
}
if($retiredByRun){$updateRun=$db->prepare('UPDATE spreadsheet_sync_runs SET changes_applied=changes_applied+? WHERE id=?');foreach($retiredByRun as $runId=>$count)$updateRun->execute([$count,$runId]);}
printf("Source change application complete. Soft-retired assets: %d. Historical records remain stored.\n",$retired);
