<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/certificate-auth.php';
$user=fleet_cert_require_user();
$db=fleet_db();if(($_SERVER['REQUEST_METHOD']??'GET')==='GET'&&!fleet_cert_user_can($user,'page.certificate-approvals'))json_response(['ok'=>false,'message'=>'Your role cannot view certificate approvals.'],403);
try{
 if(($_SERVER['REQUEST_METHOD']??'GET')==='GET'){
  $q=$db->query("SELECT ca.id,ca.certificate_submission_id,ca.action,ca.requested_expiry_date,ca.request_reason,ca.status,ca.requested_at,ca.requested_by,ca.reviewed_at,ca.decline_reason,cs.asset_id,cs.asset_scope,cs.registration_snapshot,cs.issue_date,cs.expiry_date current_expiry_date,COALESCE(NULLIF(cs.certificate_name,''),ct.display_name) certificate_name,cs.original_filename,ru.full_name requested_by_name,vu.full_name reviewed_by_name FROM certificate_amendments ca JOIN certificate_submissions cs ON cs.id=ca.certificate_submission_id JOIN certificate_types ct ON ct.id=cs.certificate_type_id JOIN users ru ON ru.id=ca.requested_by LEFT JOIN users vu ON vu.id=ca.reviewed_by ORDER BY ca.requested_at DESC LIMIT 500");
  json_response(['ok'=>true,'amendments'=>$q->fetchAll(),'can_approve'=>fleet_cert_user_can($user,'certificates.approve')]);
 }
 if(($_SERVER['REQUEST_METHOD']??'')!=='POST')json_response(['ok'=>false,'message'=>'Method not allowed.'],405);
 fleet_cert_require_permission($user,'certificates.request_changes');
 fleet_cert_require_same_origin_post();$in=json_decode((string)file_get_contents('php://input'),true)??[];$id=(int)($in['certificate_id']??0);$action=strtoupper(trim((string)($in['action']??'')));$date=trim((string)($in['requested_expiry_date']??''));$reason=trim((string)($in['reason']??''));
 if($id<1||!in_array($action,['UPDATE_EXPIRY','REMOVE_DOCUMENT'],true))json_response(['ok'=>false,'message'=>'Choose an approved certificate and a valid change request.'],400);
 if($action==='REMOVE_DOCUMENT'&&mb_strlen($reason)<3)json_response(['ok'=>false,'message'=>'Enter why this document is no longer required.'],400);
 if($action==='UPDATE_EXPIRY'&&(!preg_match('/^\d{4}-\d{2}-\d{2}$/',$date)||!checkdate((int)substr($date,5,2),(int)substr($date,8,2),(int)substr($date,0,4))))json_response(['ok'=>false,'message'=>'Enter a valid new expiry date.'],400);
 $db->beginTransaction();
 $q=$db->prepare("SELECT cs.*,COALESCE(NULLIF(cs.certificate_name,''),ct.display_name) certificate_display_name FROM certificate_submissions cs JOIN certificate_types ct ON ct.id=cs.certificate_type_id WHERE cs.id=? FOR UPDATE");$q->execute([$id]);$certificate=$q->fetch();
 if(!$certificate||$certificate['status']!=='APPROVED'){$db->rollBack();json_response(['ok'=>false,'message'=>'Only an approved certificate can be changed.'],409);}
 if($action==='UPDATE_EXPIRY'&&($date<=$certificate['expiry_date']||$date<=date('Y-m-d'))){$db->rollBack();json_response(['ok'=>false,'message'=>'The renewal date must be later than today and the existing expiry date.'],400);}
 $q=$db->prepare("SELECT id FROM certificate_amendments WHERE certificate_submission_id=? AND status='PENDING' FOR UPDATE");$q->execute([$id]);if($q->fetchColumn()){$db->rollBack();json_response(['ok'=>false,'message'=>'A change request for this certificate is already awaiting approval.'],409);}
 $q=$db->prepare('INSERT INTO certificate_amendments(certificate_submission_id,action,requested_expiry_date,request_reason,requested_by) VALUES(?,?,?,?,?)');$q->execute([$id,$action,$action==='UPDATE_EXPIRY'?$date:null,$reason!==''?$reason:null,(int)$user['id']]);$amendmentId=(int)$db->lastInsertId();
 $event=$action==='UPDATE_EXPIRY'?'EXPIRY_CHANGE_REQUESTED':'DOCUMENT_REMOVAL_REQUESTED';cert_event($db,$id,(int)$user['id'],$event,'APPROVED','APPROVED',$reason?:null,['amendment_id'=>$amendmentId,'requested_expiry_date'=>$action==='UPDATE_EXPIRY'?$date:null,'action'=>$action]);fleet_cert_log($db,(int)$user['id'],'REQUEST_'.$action,'CERTIFICATE',$id,'Requested '.$action.' for '.$certificate['certificate_display_name'].' on '.$certificate['registration_snapshot'].' for approval.');$db->commit();
 json_response(['ok'=>true,'message'=>$action==='UPDATE_EXPIRY'?'Expiry date change submitted for approval.':'Document removal submitted for approval.','id'=>$amendmentId]);
}catch(Throwable $e){if($db->inTransaction())$db->rollBack();error_log('Certificate change request: '.$e->getMessage());json_response(['ok'=>false,'message'=>'Could not submit the certificate change request. Check server logs.'],500);}
