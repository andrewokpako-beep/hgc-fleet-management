<?php
require_once __DIR__.'/../includes/bootstrap.php';
try {
    $db = fleet_db();
    $typeCounts = $db->query("SELECT t.type_code, COUNT(*) AS n FROM assets a JOIN asset_types t ON t.id=a.asset_type_id WHERE a.is_active=1 GROUP BY t.type_code")->fetchAll();
    $counts = ['HORSE'=>0,'TANKER'=>0,'FLATBED'=>0];
    foreach ($typeCounts as $r) {
        $key = match ($r['type_code']) {'TRUCK_HEAD'=>'HORSE','TANKER_TRAILER'=>'TANKER','FLATBED_TRAILER'=>'FLATBED',default=>null};
        if ($key !== null) $counts[$key] = (int)$r['n'];
    }
    // Attribute confirmed app assets to the worksheet references preserved on their primary identifier.
    $sourceSheetNames = ['Ngwerere_Tankers_status','Ngwerere_Flatbeds_Status','Nakonde_Tankers_unregistered','Nakonde_Flatbed_unregistered','Registered_21Miles','Unregistered_Ngwerere'];
    $sourceSheetCounts = [];
    $sourceSheetSeen = [];
    foreach ($sourceSheetNames as $sheetName) {
        $sourceSheetCounts[$sheetName] = ['sheet_name'=>$sheetName,'horses'=>0,'tankers'=>0,'flatbeds'=>0,'total'=>0];
        $sourceSheetSeen[$sheetName] = [];
    }
    $sourceRows = $db->query("SELECT a.id,t.type_code,ai.source_note FROM assets a JOIN asset_types t ON t.id=a.asset_type_id JOIN asset_identifiers ai ON ai.asset_id=a.id AND ai.is_primary=1 WHERE a.is_active=1")->fetchAll();
    foreach ($sourceRows as $sourceRow) {
        $typeKey = match ($sourceRow['type_code']) {'TRUCK_HEAD'=>'horses','TANKER_TRAILER'=>'tankers','FLATBED_TRAILER'=>'flatbeds',default=>null};
        if ($typeKey === null) continue;
        preg_match_all('~(?:^|;\\s*)[^/;]+/([^/;]+)\\s+row\\s+\\d+~', (string)$sourceRow['source_note'], $matches);
        foreach ($matches[1] ?? [] as $sheetName) {
            if (!isset($sourceSheetSeen[$sheetName]) || isset($sourceSheetSeen[$sheetName][(int)$sourceRow['id']])) continue;
            $sourceSheetSeen[$sheetName][(int)$sourceRow['id']] = true;
            $sourceSheetCounts[$sheetName][$typeKey]++;
            $sourceSheetCounts[$sheetName]['total']++;
        }
    }
    $assets = array_sum($counts);
    $reviews = (int)$db->query("SELECT COUNT(*) FROM reconciliation_cases WHERE case_status IN ('OPEN','IN_REVIEW')")->fetchColumn();
    $attached = (int)$db->query('SELECT COUNT(*) FROM asset_attachments WHERE attached_to IS NULL')->fetchColumn();
    $rows = table_exists($db,'spreadsheet_source_row_versions') ? (int)$db->query('SELECT COUNT(*) FROM spreadsheet_source_row_versions')->fetchColumn() : 0;

    // The source worksheet/yard label is reported as source context, not as live GPS state.
    $assetsRows = $db->query("SELECT a.id,t.type_code,ai.source_note FROM assets a JOIN asset_types t ON t.id=a.asset_type_id LEFT JOIN asset_identifiers ai ON ai.asset_id=a.id AND ai.is_primary=1 WHERE a.is_active=1")->fetchAll();
    $sheetLookup = $db->query('SELECT id,source_id,sheet_name FROM spreadsheet_sheets')->fetchAll();
    $sheetsByName = [];
    foreach ($sheetLookup as $sheet) $sheetsByName[$sheet['sheet_name'].'|'.$sheet['source_id']] = (int)$sheet['id'];
    $sourceIds = [];
    foreach ($db->query('SELECT id,source_code FROM spreadsheet_sources') as $source) $sourceIds[$source['source_code']] = (int)$source['id'];
    $rowStmt = $db->prepare('SELECT raw_data FROM spreadsheet_source_row_versions WHERE sync_run_id=? AND sheet_id=? AND source_row_number=? LIMIT 1');
    $yardCounts = [];
    foreach ($assetsRows as $asset) {
        $location = 'Unspecified';
        $note = (string)($asset['source_note'] ?? '');
        if (preg_match('/^([^\/]+)\/([^\/]+) row (\d+) col ([A-Z]+)/', $note, $m)) {
            $sourceCode=$m[1]; $sheetName=$m[2]; $sourceRow=(int)$m[3];
            if (str_starts_with($sheetName,'Ngwerere_') || $sheetName==='Unregistered_Ngwerere') $location='Ngwerere';
            elseif ($sheetName==='Registered_21Miles') $location='21 Miles Yard';
            elseif (str_starts_with($sheetName,'Nakonde_')) {
                $sourceId=$sourceIds[$sourceCode] ?? 0;
                $sheetId=$sheetsByName[$sheetName.'|'.$sourceId] ?? 0;
                if($sourceId && $sheetId){
                    $runMatch=[]; preg_match('/\(run (\d+)\)/',$note,$runMatch); $runId=(int)($runMatch[1]??0);
                    $rowStmt->execute([$runId,$sheetId,$sourceRow]); $raw=$rowStmt->fetchColumn(); $cells=$raw===false?[]:(json_decode((string)$raw,true)?:[]);
                    $yardIndex=$sheetName==='Nakonde_Tankers_unregistered'?3:4;
                    $yard=trim((string)($cells[$yardIndex]??''));
                    if($yard!=='' && !preg_match('/^(total|nakonde_flatbeds)/i',$yard)) $location=$yard;
                    else $location='Nakonde';
                } else $location='Nakonde';
            }
        }
        if(!isset($yardCounts[$location])) $yardCounts[$location]=['yard_name'=>$location,'horses'=>0,'tankers'=>0,'flatbeds'=>0,'total'=>0];
        $type=match($asset['type_code']){'TRUCK_HEAD'=>'horses','TANKER_TRAILER'=>'tankers','FLATBED_TRAILER'=>'flatbeds',default=>null};
        if($type){$yardCounts[$location][$type]++;$yardCounts[$location]['total']++;}
    }
    uasort($yardCounts,static fn($a,$b)=>$b['total']<=>$a['total']);
    $runs=$db->query('SELECT s.source_code,s.source_name,r.run_status,r.started_at,r.completed_at,r.rows_read,r.rows_valid,r.rows_rejected,r.conflicts_created FROM spreadsheet_sync_runs r JOIN spreadsheet_sources s ON s.id=r.source_id ORDER BY r.id DESC LIMIT 8')->fetchAll();
    $last=$db->query("SELECT MAX(completed_at) completed_at FROM spreadsheet_sync_runs WHERE run_status='SUCCESS'")->fetch();
    json_response(['ok'=>true,'stats'=>['assets'=>$assets,'horses'=>$counts['HORSE'],'tankers'=>$counts['TANKER'],'flatbeds'=>$counts['FLATBED'],'reviews'=>$reviews,'attachments'=>$attached,'rows'=>$rows],'source_sheet_counts'=>array_values($sourceSheetCounts),'yard_counts'=>array_values($yardCounts),'runs'=>$runs,'last'=>$last,'database_name'=>(string)$db->query('SELECT DATABASE()')->fetchColumn()]);
} catch(Throwable $e) { error_log('Fleet dashboard: '.$e->getMessage()); json_response(['ok'=>false,'message'=>'Dashboard data query failed. Check PHP error log.'],500); }
