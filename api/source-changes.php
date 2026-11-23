<?php
require_once __DIR__.'/../includes/certificate-auth.php';
$user=fleet_cert_require_user();fleet_cert_require_permission($user,'page.dashboard');
try {
    $db=fleet_db();
    if((string)($_GET['prompt']??'')==='1'){
        $ids=$db->query("SELECT id FROM spreadsheet_source_change_log WHERE prompted_at IS NULL AND change_type='ROW_DELETED' AND decision_status='PENDING' ORDER BY detected_at,id LIMIT 10")->fetchAll(PDO::FETCH_COLUMN);
        if($ids){$marks=implode(',',array_fill(0,count($ids),'?'));$mark=$db->prepare("UPDATE spreadsheet_source_change_log SET prompted_at=NOW() WHERE id IN ($marks)");$mark->execute(array_map('intval',$ids));}
        $items=[];
        if($ids){$marks=implode(',',array_fill(0,count($ids),'?'));$q=$db->prepare("SELECT l.id,l.change_type,l.previous_source_row_number,l.source_row_number,l.previous_data,l.new_data,l.detected_at,l.decision_status,s.source_name,sh.sheet_name FROM spreadsheet_source_change_log l JOIN spreadsheet_sources s ON s.id=l.source_id JOIN spreadsheet_sheets sh ON sh.id=l.sheet_id WHERE l.id IN ($marks) ORDER BY l.detected_at DESC,l.id DESC");$q->execute(array_map('intval',$ids));$items=$q->fetchAll();}
        foreach($items as &$item){$item['previous_data']=$item['previous_data']?json_decode($item['previous_data'],true):null;$item['new_data']=$item['new_data']?json_decode($item['new_data'],true):null;}unset($item);
        json_response(['ok'=>true,'items'=>$items]);
    }
    $export=(string)($_GET['export']??'')==='1';
    $q=$db->prepare("SELECT l.id,l.change_type,l.previous_source_row_number,l.source_row_number,l.previous_data,l.new_data,l.detected_at,l.decision_status,s.source_name,sh.sheet_name FROM spreadsheet_source_change_log l JOIN spreadsheet_sources s ON s.id=l.source_id JOIN spreadsheet_sheets sh ON sh.id=l.sheet_id ORDER BY l.detected_at DESC,l.id DESC".($export?'':' LIMIT ?'));
    if(!$export)$q->bindValue(1,100,PDO::PARAM_INT);$q->execute();
    $items=$q->fetchAll();foreach($items as &$item){$item['previous_data']=$item['previous_data']?json_decode($item['previous_data'],true):null;$item['new_data']=$item['new_data']?json_decode($item['new_data'],true):null;}unset($item);
    json_response(['ok'=>true,'items'=>$items]);
}catch(Throwable $e){error_log('Fleet source changes: '.$e->getMessage());json_response(['ok'=>false,'message'=>'Source change history could not be loaded.'],500);}
