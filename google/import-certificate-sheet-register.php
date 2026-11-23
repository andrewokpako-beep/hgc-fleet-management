<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); }
require_once __DIR__ . '/certificate-sheet-sync.php';
require_once __DIR__ . '/../includes/bootstrap.php';

function certificateLegacyParse($raw, string $display): array
{
    $text = trim($display);
    if ($text === '') return ['status' => null, 'date' => null];
    if (is_numeric($raw) && (float)$raw >= 1 && (float)$raw <= 100000 && preg_match('/\d/', $text)) {
        $days = (int)floor((float)$raw);
        $date = (new DateTimeImmutable('1899-12-30'))->modify('+' . $days . ' days')->format('Y-m-d');
        return ['status' => 'DATE', 'date' => $date];
    }
    $value = strtoupper(preg_replace('/\s+/', ' ', $text));
    if (in_array($value, ['NIL', 'N/A', 'NA', 'NONE', '-', 'NO'], true)) return ['status' => 'NIL', 'date' => null];
    if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $value, $m)) {
        $year=(int)$m[1];$month=(int)$m[2];$day=(int)$m[3];
        if (checkdate($month,$day,$year)) return ['status'=>'DATE','date'=>sprintf('%04d-%02d-%02d',$year,$month,$day)];
        return ['status'=>'INVALID','date'=>null];
    }
    if (preg_match('/^(\d{1,2})[\s\/-]+([A-ZÀ-Ü]+)[\s\/-]+(\d{2,4})$/u', $value, $m)) {
        $months=['JAN'=>1,'JANUARY'=>1,'FEB'=>2,'FEBRUARY'=>2,'MAR'=>3,'MARCH'=>3,'APR'=>4,'APRIL'=>4,'MAY'=>5,'JUN'=>6,'JUNE'=>6,'JUIN'=>6,'JUL'=>7,'JULY'=>7,'AUG'=>8,'AUGUST'=>8,'SEP'=>9,'SEPT'=>9,'SEPTEMBER'=>9,'OCT'=>10,'OCTOBER'=>10,'NOV'=>11,'NOVEMBER'=>11,'DEC'=>12,'DECEMBER'=>12];
        $day=(int)$m[1];$month=$months[$m[2]]??0;$year=(int)$m[3];if(strlen($m[3])===2)$year+=($year<50?2000:1900);
        if($month&&checkdate($month,$day,$year))return ['status'=>'DATE','date'=>sprintf('%04d-%02d-%02d',$year,$month,$day)];
    }
    return ['status'=>'INVALID','date'=>null];
}

$db=fleet_db();$service=getGoogleSheetsService();$dryRun=in_array('--dry-run',$argv??[],true);$tabs=['Trucks'=>'HORSE','Tankers'=>'TANKER'];$typeCodes=['HORSE'=>'TRUCK_HEAD','TANKER'=>'TANKER_TRAILER'];
$assetMap=[];
$assets=$db->query("SELECT a.id,a.primary_identifier,t.type_code FROM assets a JOIN asset_types t ON t.id=a.asset_type_id WHERE t.type_code IN ('TRUCK_HEAD','TANKER_TRAILER')")->fetchAll(PDO::FETCH_ASSOC);
foreach($assets as $a){$key=$a['type_code'].'|'.certificateSheetNorm((string)$a['primary_identifier']);$assetMap[$key][(int)$a['id']]=true;}
foreach($db->query("SELECT ai.asset_id,ai.normalized_value,t.type_code FROM asset_identifiers ai JOIN assets a ON a.id=ai.asset_id JOIN asset_types t ON t.id=a.asset_type_id WHERE t.type_code IN ('TRUCK_HEAD','TANKER_TRAILER')") as $ident){
    $assetMap[$ident['type_code'].'|'.certificateSheetNorm((string)$ident['normalized_value'])][(int)$ident['asset_id']]=true;
}
$catalogMap=[];foreach($db->query('SELECT id,sheet_column FROM certificate_catalog WHERE is_active=1 AND sheet_column IS NOT NULL') as $catalog)$catalogMap[(string)$catalog['sheet_column']]=(int)$catalog['id'];
$upsert=$db->prepare("INSERT INTO certificate_sheet_register_values(spreadsheet_id,sheet_name,source_row_number,asset_scope,asset_id,asset_match_status,registration_snapshot,certificate_column,certificate_catalog_id,source_value,expiry_date,value_status,origin,source_submission_id)
VALUES(?,?,?,?,?,?,?,?,?,?,?,?,'SHEET_IMPORT',NULL)
ON DUPLICATE KEY UPDATE review_status=IF(source_value<=>VALUES(source_value) AND expiry_date<=>VALUES(expiry_date) AND asset_id<=>VALUES(asset_id) AND asset_match_status<=>VALUES(asset_match_status),review_status,'UNVERIFIED'),review_source=IF(review_status='UNVERIFIED',NULL,review_source),reviewed_by=IF(review_status='UNVERIFIED',NULL,reviewed_by),reviewed_at=IF(review_status='UNVERIFIED',NULL,reviewed_at),review_note=IF(review_status='UNVERIFIED',NULL,review_note),source_submission_id=IF(source_value<=>VALUES(source_value) AND expiry_date<=>VALUES(expiry_date) AND asset_id<=>VALUES(asset_id) AND asset_match_status<=>VALUES(asset_match_status),source_submission_id,NULL),origin=IF(origin='FLEETDESK_APPROVED' AND review_status='APPROVED',origin,'SHEET_IMPORT'),certificate_catalog_id=VALUES(certificate_catalog_id),asset_scope=VALUES(asset_scope),asset_id=VALUES(asset_id),asset_match_status=VALUES(asset_match_status),registration_snapshot=VALUES(registration_snapshot),source_value=VALUES(source_value),expiry_date=VALUES(expiry_date),value_status=VALUES(value_status),imported_at=CURRENT_TIMESTAMP");
$summary=['tabs'=>[],'rows_seen'=>0,'cells_imported'=>0,'date_values'=>0,'nil_values'=>0,'invalid_values'=>0,'unmatched_cells'=>0,'ambiguous_cells'=>0,'blank_cells_skipped'=>0];
foreach($tabs as $tab=>$scope){
    $range="'{$tab}'!A1:N1000";
    $formatted=$service->spreadsheets_values->get(SHEET_CERTIFICATE_REGISTER_ID,$range,['valueRenderOption'=>'FORMATTED_VALUE'])->getValues()??[];
    $unformatted=$service->spreadsheets_values->get(SHEET_CERTIFICATE_REGISTER_ID,$range,['valueRenderOption'=>'UNFORMATTED_VALUE','dateTimeRenderOption'=>'SERIAL_NUMBER'])->getValues()??[];
    if(count($formatted)<1)throw new RuntimeException('The "'.$tab.'" tab has no header row.');
    $headers=$formatted[0];$colIndexes=[];
    foreach(certificateSheetColumns() as $name){$found=null;foreach($headers as $i=>$header)if(strcasecmp(trim((string)$header),$name)===0){$found=$i;break;}if($found===null)throw new RuntimeException('Expected certificate column "'.$name.'" is missing from tab "'.$tab.'".');$colIndexes[$name]=$found;}
    $tabSummary=['rows_seen'=>0,'cells_imported'=>0,'dates'=>0,'nil'=>0,'invalid'=>0];
    for($i=1;$i<count($formatted);$i++){
        $row=$formatted[$i];$rawRow=$unformatted[$i]??[];$registration=trim((string)($row[0]??''));if($registration==='')continue;
        $tabSummary['rows_seen']++;$summary['rows_seen']++;
        $type=$typeCodes[$scope];$ids=array_keys($assetMap[$type.'|'.certificateSheetNorm($registration)]??[]);$assetId=count($ids)===1?$ids[0]:null;$matchStatus=count($ids)===1?'MATCHED':(count($ids)>1?'AMBIGUOUS':'UNMATCHED');
        foreach($colIndexes as $column=>$columnIndex){$display=trim((string)($row[$columnIndex]??''));if($display===''){$summary['blank_cells_skipped']++;continue;}
            $parsed=certificateLegacyParse($rawRow[$columnIndex]??$display,$display);$status=$parsed['status']??'INVALID';$rawValue=(string)($row[$columnIndex]??'');
            if(!$dryRun)$upsert->execute([SHEET_CERTIFICATE_REGISTER_ID,$tab,$i+1,$scope,$assetId,$matchStatus,$registration,$column,$catalogMap[$column]??null,$rawValue,$parsed['date'],$status]);
            $tabSummary['cells_imported']++;$summary['cells_imported']++;
            if($status==='DATE'){$tabSummary['dates']++;$summary['date_values']++;}elseif($status==='NIL'){$tabSummary['nil']++;$summary['nil_values']++;}else{$tabSummary['invalid']++;$summary['invalid_values']++;}
            if($matchStatus==='UNMATCHED')$summary['unmatched_cells']++;elseif($matchStatus==='AMBIGUOUS')$summary['ambiguous_cells']++;
        }
    }
    $summary['tabs'][$tab]=$tabSummary;
}
$summary['mode']=$dryRun?'DRY_RUN':'IMPORTED';
echo json_encode($summary,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE).PHP_EOL;
