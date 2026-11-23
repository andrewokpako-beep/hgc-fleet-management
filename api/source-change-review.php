<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/certificate-auth.php';
$user=fleet_cert_require_user();
fleet_cert_require_same_origin_post();
fleet_cert_require_permission($user,'source_changes.approve');
$db=fleet_db();
$input=json_decode((string)file_get_contents('php://input'),true);
$id=(int)($input['id']??0);
$decision=strtoupper(trim((string)($input['decision']??'')));
if($id<1||!in_array($decision,['APPROVE','DECLINE'],true))json_response(['ok'=>false,'message'=>'Choose a deletion request and approve or decline it.'],400);
function source_change_norm(string $v):string{return strtoupper((string)preg_replace('/[^A-Z0-9]/','',$v));}
try{
 $db->beginTransaction();
 $q=$db->prepare("SELECT l.*,s.source_code,sh.sheet_name FROM spreadsheet_source_change_log l JOIN spreadsheet_sources s ON s.id=l.source_id JOIN spreadsheet_sheets sh ON sh.id=l.sheet_id WHERE l.id=? FOR UPDATE");
 $q->execute([$id]);$event=$q->fetch();
 if(!$event||$event['change_type']!=='ROW_DELETED')throw new InvalidArgumentException('This item is not a source-row deletion request.');
 if($event['decision_status']!=='PENDING')throw new InvalidArgumentException('This source deletion has already been reviewed.');
 $affected=[];
 if($decision==='APPROVE'){
  $oldCells=json_decode((string)$event['previous_data'],true)?:[];$oldIds=[];
  foreach($oldCells as $cell){if(!is_scalar($cell))continue;$norm=source_change_norm((string)$cell);if(strlen($norm)>=5&&strlen($norm)<=20)$oldIds[$norm]=true;}
  if($oldIds){
   $current=[];
   $rows=$db->query("SELECT v.raw_data FROM spreadsheet_source_row_versions v JOIN spreadsheet_sync_runs r ON r.id=v.sync_run_id WHERE r.run_status='SUCCESS' AND r.id=(SELECT MAX(r2.id) FROM spreadsheet_sync_runs r2 WHERE r2.source_id=r.source_id AND r2.run_status='SUCCESS')")->fetchAll(PDO::FETCH_COLUMN);
   foreach($rows as $raw){$cells=json_decode((string)$raw,true);if(!is_array($cells))continue;foreach($cells as $cell){if(!is_scalar($cell))continue;$norm=source_change_norm((string)$cell);if(strlen($norm)>=5&&strlen($norm)<=20)$current[$norm]=true;}}
   $marks=implode(',',array_fill(0,count($oldIds),'?'));
   $prefix=$event['source_code'].'/'.$event['sheet_name'];
   $params=array_merge(array_keys($oldIds),[$prefix]);
   $find=$db->prepare("SELECT a.id,a.primary_identifier,ai.normalized_value,ai.source_note FROM assets a JOIN asset_identifiers ai ON ai.asset_id=a.id AND ai.is_primary=1 WHERE a.is_active=1 AND ai.normalized_value IN ($marks) AND LOCATE(?,ai.source_note)>0 FOR UPDATE");
   $find->execute($params);
   foreach($find->fetchAll() as $asset){
    if(isset($current[source_change_norm((string)$asset['normalized_value'])]))continue;
    $before=json_encode(['active'=>true,'identifier'=>$asset['primary_identifier'],'source'=>$asset['source_note'],'source_change_id'=>$id],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
    $after=json_encode(['active'=>false,'reason'=>'Sheet row deletion approved','source_change_id'=>$id],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
    $assetId=(int)$asset['id'];
    $db->prepare('UPDATE assets SET is_active=0 WHERE id=?')->execute([$assetId]);
    $db->prepare('UPDATE asset_driver_assignments SET assigned_to=NOW() WHERE asset_id=? AND assigned_to IS NULL')->execute([$assetId]);
    $db->prepare('UPDATE asset_attachments SET attached_to=NOW() WHERE (head_asset_id=? OR trailer_asset_id=?) AND attached_to IS NULL')->execute([$assetId,$assetId]);
    $history=$db->prepare("INSERT INTO asset_change_history(asset_id,sync_run_id,source_row_id,reconciliation_case_id,field_name,old_value,new_value,change_origin,changed_at,changed_by) VALUES(?,?,NULL,NULL,'source_presence',?,?,'SHEET_SYNC',NOW(),NULL)");
    $history->execute([$assetId,(int)$event['sync_run_id'],$before,$after]);$affected[]=$asset['primary_identifier'];
   }
  }
 }
 $note=$decision==='APPROVE'?($affected?'Archived assets after approved Sheet deletion: '.implode(', ',$affected):'Deletion acknowledged; matching assets remain active because they still occur in another current source row.'):'Deletion declined; existing FleetDesk data stays unchanged.';
 $status=$decision==='APPROVE'?'APPROVED':'DECLINED';
 $db->prepare('UPDATE spreadsheet_source_change_log SET decision_status=?,decided_by=?,decided_at=NOW(),decision_note=? WHERE id=?')->execute([$status,(int)$user['id'],$note,$id]);
 fleet_cert_log($db,(int)$user['id'],'SOURCE_DELETE_'.$status,'SOURCE_CHANGE',$id,$note);
 $db->commit();
 $message=$decision==='DECLINE'?'Source deletion declined. No FleetDesk asset data changed.':($affected?'Sheet deletion approved. Archived: '.implode(', ',$affected).'.':'Deletion reviewed. FleetDesk kept matching assets active because they remain in another current source row.');
 json_response(['ok'=>true,'message'=>$message,'affected'=>$affected]);
}catch(InvalidArgumentException $e){if($db->inTransaction())$db->rollBack();json_response(['ok'=>false,'message'=>$e->getMessage()],409);}catch(Throwable $e){if($db->inTransaction())$db->rollBack();error_log('Source change approval: '.$e->getMessage());json_response(['ok'=>false,'message'=>'The source deletion decision could not be saved. No change was applied.'],500);}

