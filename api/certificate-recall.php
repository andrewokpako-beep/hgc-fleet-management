<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/certificate-auth.php';
$user=fleet_cert_require_user();fleet_cert_require_permission($user,'certificates.submit');
fleet_cert_require_same_origin_post();
if(($_SERVER['REQUEST_METHOD']??'')!=='POST')json_response(['ok'=>false,'message'=>'Method not allowed.'],405);
$input=json_decode((string)file_get_contents('php://input'),true)??[];$id=(int)($input['id']??0);
if($id<1)json_response(['ok'=>false,'message'=>'Select a valid certificate submission.'],400);
$db=fleet_db();$path=null;
try{
 $db->beginTransaction();$q=$db->prepare('SELECT id,submitted_by,status,document_storage_name,original_filename,file_sha256 FROM certificate_submissions WHERE id=? FOR UPDATE');$q->execute([$id]);$row=$q->fetch(PDO::FETCH_ASSOC);
 if(!$row|| (int)$row['submitted_by']!==(int)$user['id']){$db->rollBack();json_response(['ok'=>false,'message'=>'You can only recall a certificate you submitted.'],403);}
 if($row['status']!=='PENDING'){$db->rollBack();json_response(['ok'=>false,'message'=>'Only a certificate still awaiting approval can be recalled.'],409);}
 if((string)$row['document_storage_name']!=='')$path='C:/xampp/secure/fleet/certificates/'.basename((string)$row['document_storage_name']);
 $db->prepare("UPDATE certificate_submissions SET status='WITHDRAWN',document_storage_name='' WHERE id=? AND status='PENDING'")->execute([$id]);
 cert_event($db,$id,(int)$user['id'],'SUBMISSION_RECALLED','PENDING','WITHDRAWN','Submitter recalled the certificate before approval.',['original_filename'=>$row['original_filename'],'file_sha256'=>$row['file_sha256']]);
 fleet_cert_log($db,(int)$user['id'],'RECALL','CERTIFICATE',$id,'Recalled pending certificate submission '.$row['original_filename'].' before approval.');$db->commit();
 if($path&&is_file($path)&&!@unlink($path))error_log('Could not remove recalled certificate upload for submission #'.$id);
 json_response(['ok'=>true,'message'=>'Your pending certificate submission was recalled. It will not enter approval or Google Sheet sync.']);
}catch(Throwable $e){if($db->inTransaction())$db->rollBack();error_log('Certificate submission recall: '.$e->getMessage());json_response(['ok'=>false,'message'=>'Could not recall the certificate submission. Please try again.'],500);}
