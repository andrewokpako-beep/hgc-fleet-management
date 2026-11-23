<?php
/**
 * Import unambiguous vehicle identifier candidates from the latest reconciliation JSON.
 * IT references, inferred attachments, tracking state, and yard assignments are excluded.
 * Open review cases are created for source disagreements and repeated identifiers.
 * Run from project root: php google/import-approved-candidates.php [report.json]
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); }
require_once __DIR__ . '/../includes/bootstrap.php';

$reportPath = $argv[1] ?? (dirname(__DIR__) . '/reports/reconciliation/reconciliation_dry_run_20261118_205440.json');
if (!is_file($reportPath)) { fwrite(STDERR, "Report not found: {$reportPath}\n"); exit(1); }
$report = json_decode((string)file_get_contents($reportPath), true, 512, JSON_THROW_ON_ERROR);
if (($report['report_type'] ?? '') !== 'fleet_management_reconciliation_dry_run' || empty($report['observations'])) {
    fwrite(STDERR, "Invalid reconciliation report. No changes made.\n"); exit(1);
}
$db = fleet_db();
$db->beginTransaction();
try {
    $typeRows = $db->query('SELECT id,type_code FROM asset_types')->fetchAll();
    $typeIds = [];
    foreach ($typeRows as $t) $typeIds[$t['type_code']] = (int)$t['id'];
    foreach (['TRUCK_HEAD','TANKER_TRAILER','FLATBED_TRAILER','OTHER_TRAILER'] as $required) {
        if (!isset($typeIds[$required])) throw new RuntimeException("Required asset type missing: {$required}");
    }
    $allowedRuns = array_fill_keys(array_map('intval', $report['sync_run_ids'] ?? []), true);
    $rowsStmt = $db->prepare('SELECT raw_data FROM spreadsheet_source_row_versions WHERE sync_run_id=? AND sheet_id=? AND source_row_number=? LIMIT 1');
    $sourceIdStmt = $db->prepare('SELECT source_id FROM spreadsheet_sheets WHERE id=?');
    $existingId = $db->prepare('SELECT ai.asset_id FROM asset_identifiers ai WHERE ai.identifier_type=? AND ai.normalized_value=? LIMIT 1');
    $insertAsset = $db->prepare('INSERT INTO assets (asset_type_id,primary_identifier,make,registration_status,is_active) VALUES (?,?,?,?,1)');
    $insertIdentifier = $db->prepare('INSERT INTO asset_identifiers (asset_id,identifier_type,raw_value,normalized_value,is_primary,source_note) VALUES (?,?,?,?,1,?)');
    $insertAudit = $db->prepare("INSERT INTO asset_change_history (asset_id,sync_run_id,source_row_id,reconciliation_case_id,field_name,old_value,new_value,change_origin,changed_at,changed_by) VALUES (?,NULL,NULL,NULL,'initial_import',NULL,?,'INITIAL_IMPORT',NOW(),NULL)");
    $byNorm = [];
    foreach ($report['observations'] as $o) {
        if (($o['asset_role'] ?? '') === 'IT_REFERENCE') continue;
        $norm = strtoupper((string)preg_replace('/[^A-Z0-9]/', '', (string)($o['normalized_value'] ?? '')));
        if ($norm === '' || !isset($allowedRuns[(int)$o['sync_run_id']])) continue;
        $byNorm[$norm][] = $o;
    }
    $inserted = 0; $alreadyPresent = 0; $casesCreated = 0;
    foreach ($byNorm as $norm => $items) {
        $roles = array_unique(array_column($items, 'asset_role'));
        if (count($roles) !== 1) continue;
        $role = (string)reset($roles);
        if (!in_array($role, ['HEAD','TRAILER'], true)) continue;
        $first = $items[0];
        $sheet = (string)$first['sheet_name'];
        $field = (string)$first['field'];
        $type = $role === 'HEAD' ? 'TRUCK_HEAD' : (str_contains(strtolower($field), 'tanker') ? 'TANKER_TRAILER' : (str_contains(strtolower($field), 'flatbed') || str_contains(strtolower($sheet), 'flatbed') ? 'FLATBED_TRAILER' : 'OTHER_TRAILER'));
        $registered = in_array($first['classification'] ?? '', ['REGISTERED_SOURCE'], true) ? 'REGISTERED' : 'UNREGISTERED';
        $existingId->execute([$role, $norm]);
        if ($existingId->fetchColumn()) { $alreadyPresent++; continue; }
        $make = null;
        if (in_array($sheet, ['Ngwerere_Tankers_status','Ngwerere_Flatbeds_Status'], true)) {
            $rowsStmt->execute([(int)$first['sync_run_id'],(int)$first['sheet_id'],(int)$first['source_row_number']]);
            $raw = $rowsStmt->fetchColumn();
            $cells = $raw === false ? [] : (json_decode((string)$raw, true) ?: []);
            $candidateMake = trim((string)($cells[1] ?? ''));
            $make = $candidateMake !== '' ? $candidateMake : null;
        }
        $insertAsset->execute([$typeIds[$type],(string)$first['raw_value'],$make,$registered]);
        $assetId = (int)$db->lastInsertId();
        $provenance = [];
        foreach ($items as $item) $provenance[] = $item['source_code'].'/'.$item['sheet_name'].' row '.$item['source_row_number'].' col '.$item['column_letter'].' (run '.$item['sync_run_id'].')';
        $insertIdentifier->execute([$assetId,$role,(string)$first['raw_value'],$norm,implode('; ',array_unique($provenance))]);
        $auditValue=json_encode(['identifier'=>(string)$first['raw_value'],'asset_type'=>$type,'registration_status'=>$registered,'source_provenance'=>array_values(array_unique($provenance))],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
        $insertAudit->execute([$assetId,$auditValue]);
        $inserted++;
    }

    $caseStmt = $db->prepare("INSERT IGNORE INTO reconciliation_cases (case_number,asset_id,sync_run_id,case_type,severity,case_status,field_name,description,detected_at) VALUES (?,NULL,?,?,?,?,?,?,NOW())");
    $evidenceStmt = $db->prepare('INSERT INTO reconciliation_evidence (case_id,source_row_id,source_id,sheet_id,field_name,observed_value,normalized_value) VALUES (?,NULL,?,?,?,?,?)');
    $addCase = function(string $key,string $type,string $severity,string $field,string $description,array $observations) use ($db,$caseStmt,$evidenceStmt,&$casesCreated): void {
        $runId=(int)($observations[0]['sync_run_id'] ?? 0); if($runId<1) return;
        $caseNo='SRC-'.strtoupper(substr(hash('sha256',$key),0,20));
        $caseStmt->execute([$caseNo,$runId,$type,$severity,'OPEN',$field,$description]);
        if($caseStmt->rowCount()===0){$q=$db->prepare('SELECT id FROM reconciliation_cases WHERE case_number=?');$q->execute([$caseNo]);$caseId=(int)$q->fetchColumn();}else{$caseId=(int)$db->lastInsertId();$casesCreated++;}
        foreach($observations as $o){$sourceIdStmt->execute([(int)$o['sheet_id']]);$sourceId=(int)$sourceIdStmt->fetchColumn();$observed=json_encode(['raw_value'=>(string)$o['raw_value'],'source_code'=>$o['source_code'],'sheet_name'=>$o['sheet_name'],'source_row_number'=>(int)$o['source_row_number'],'column_letter'=>$o['column_letter'],'sync_run_id'=>(int)$o['sync_run_id']],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);$evidenceStmt->execute([$caseId,$sourceId,(int)$o['sheet_id'],(string)$o['field'],$observed,(string)$o['normalized_value']]);}
    };
    foreach (($report['registered_21miles_pairs'] ?? []) as $pair) {
        if (($pair['head_match'] ?? null) === false || ($pair['trailer_match'] ?? null) === false) {
            $obs=array_values(array_filter($report['observations'],fn($o)=>(int)$o['source_row_number']===(int)$pair['source_row_number'] && $o['sheet_name']==='Registered_21Miles'));
            $addCase('registered-21miles-conflict-'.$pair['source_row_number'],'IDENTITY_AMBIGUITY','HIGH','registered_pair','Registered_21Miles row '.$pair['source_row_number'].' disagrees between sections. Tanker: '.$pair['tanker_head'].' / '.$pair['tanker_trailer'].'; flatbed: '.$pair['flatbed_head'].' / '.$pair['flatbed_trailer'].'. Review before linking assets.',$obs);
        }
        if (empty($pair['head_comparable']) || empty($pair['trailer_comparable'])) {
            $obs=array_values(array_filter($report['observations'],fn($o)=>(int)$o['source_row_number']===(int)$pair['source_row_number'] && $o['sheet_name']==='Registered_21Miles'));
            $addCase('registered-21miles-incomplete-'.$pair['source_row_number'],'MISSING_DATA','LOW','registered_pair','Registered_21Miles row '.$pair['source_row_number'].' has a blank or placeholder in one section; source values preserved.',$obs);
        }
    }
    foreach (($report['repeated_identifiers'] ?? []) as $group) {
        $obs=[];
        foreach (($group['observations'] ?? []) as $line) {
            if (preg_match('/^([^\/]+)\/([^\/]+)\/row (\d+) col ([A-Z]+)/',$line,$m)) {
                foreach($report['observations'] as $o) if($o['source_code']===$m[1]&&$o['sheet_name']===$m[2]&&(int)$o['source_row_number']===(int)$m[3]&&$o['column_letter']===$m[4]&&$o['normalized_value']===$group['normalized_value']){$obs[]=$o;break;}
            }
        }
        if(count($obs)>1) $addCase('repeat-'.$group['asset_role'].'-'.$group['normalized_value'],'POSSIBLE_DUPLICATE','MEDIUM','identifier','Identifier '.$group['normalized_value'].' is repeated in '.$group['observation_count'].' source observations. One asset record was retained with all occurrences in provenance; verify these observations.',$obs);
    }
    $db->commit();
    printf("Import complete. New assets: %d; already present: %d; open review cases created: %d.\n",$inserted,$alreadyPresent,$casesCreated);
    echo "No attachments, tracking observations, or permanent yard assignments were imported.\n";
} catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    fwrite(STDERR,"Import rolled back: ".$e->getMessage()."\n"); exit(1);
}
