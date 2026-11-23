<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/certificate-auth.php';
$user=fleet_cert_require_user();
if(!fleet_cert_user_can($user,'page.certificates')&&!fleet_cert_user_can($user,'page.certificate-settings')&&!fleet_cert_user_can($user,'page.certificate-approvals'))json_response(['ok'=>false,'message'=>'Your role cannot view certificate types.'],403);
$db=fleet_db();
try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
        $rows=$db->query("SELECT c.id,c.catalog_code,c.display_name,c.sheet_column,c.is_active,c.is_archived,c.sort_order,EXISTS(SELECT 1 FROM certificate_catalog_change_requests r WHERE r.certificate_catalog_id=c.id AND r.status='PENDING') pending_change, (SELECT r.action FROM certificate_catalog_change_requests r WHERE r.certificate_catalog_id=c.id AND r.status='PENDING' ORDER BY r.id DESC LIMIT 1) pending_action FROM certificate_catalog c WHERE c.is_archived=0 ORDER BY c.sort_order,c.display_name")->fetchAll(PDO::FETCH_ASSOC);
        json_response(['ok'=>true,'types'=>$rows,'can_manage'=>fleet_cert_user_can($user,'certificates.manage')]);
    }
    fleet_cert_require_same_origin_post();fleet_cert_require_permission($user,'certificates.manage');
    $input=json_decode((string)file_get_contents('php://input'),true);
    if(!is_array($input)) json_response(['ok'=>false,'message'=>'Invalid certificate type request.'],400);
    $action=strtoupper(trim((string)($input['action']??'CREATE')));
    if(in_array($action,['DISABLE_REQUEST','ENABLE_REQUEST','ARCHIVE_REQUEST'],true)){
        $changeAction=['DISABLE_REQUEST'=>'DISABLE','ENABLE_REQUEST'=>'ENABLE','ARCHIVE_REQUEST'=>'ARCHIVE'][$action];
        $id=(int)($input['id']??0);
        if($id<1)json_response(['ok'=>false,'message'=>'Select a valid certificate type.'],400);
        $db->beginTransaction();
        $q=$db->prepare('SELECT id,display_name,is_active,is_archived FROM certificate_catalog WHERE id=? FOR UPDATE');$q->execute([$id]);$type=$q->fetch(PDO::FETCH_ASSOC);
        if(!$type||(int)$type['is_archived']){$db->rollBack();json_response(['ok'=>false,'message'=>'This certificate type is archived or no longer exists.'],409);}
        if(($changeAction==='DISABLE'&&!(int)$type['is_active'])||($changeAction==='ENABLE'&&(int)$type['is_active'])){$db->rollBack();json_response(['ok'=>false,'message'=>'This certificate type already has the requested status.'],409);}
        $q=$db->prepare("SELECT id FROM certificate_catalog_change_requests WHERE certificate_catalog_id=? AND status='PENDING' FOR UPDATE");$q->execute([$id]);
        if($q->fetchColumn()){$db->rollBack();json_response(['ok'=>false,'message'=>'A change request for this type is already awaiting approval.'],409);}
        $db->prepare("INSERT INTO certificate_catalog_change_requests(certificate_catalog_id,action,requested_by) VALUES(?,?,?)")->execute([$id,$changeAction,(int)$user['id']]);
        $requestId=(int)$db->lastInsertId();fleet_cert_log($db,(int)$user['id'],'REQUEST_'.$changeAction,'CERTIFICATE_TYPE',$id,'Requested '.$changeAction.' of certificate type '.$type['display_name'].'.');$db->commit();
        $label=['DISABLE'=>'Disable','ENABLE'=>'Enable','ARCHIVE'=>'Archive'][$changeAction];
        json_response(['ok'=>true,'message'=>$label.' request submitted for approval.','id'=>$requestId]);
    }
    if($action!=='CREATE')json_response(['ok'=>false,'message'=>'Unsupported certificate type action.'],400);
    fleet_cert_require_permission($user,'certificates.manage');
    $name=trim((string)($input['display_name']??''));$column=trim((string)($input['sheet_column']??''));
    $allowed=['CROSS BORDER PERMIT (ZIM)','CROSS BORDER PERMIT (MOZ)','ZCSA','ELECTRICAL CERTIFICATE','DIP CHART','ASSIZER','IDENTITIES','COMESA','CVG','PROFESSIONAL INSURANCE','FITNESS','ROAD TAX','C-29'];
    if($name===''||mb_strlen($name)>150)json_response(['ok'=>false,'message'=>'Enter a certificate type name up to 150 characters.'],400);
    if($column!==''&&!in_array($column,$allowed,true))json_response(['ok'=>false,'message'=>'Choose a valid certificate register column or leave it unmapped.'],400);
    $ascii=iconv('UTF-8','ASCII//TRANSLIT',$name);$base=strtoupper(trim((string)preg_replace('/[^A-Za-z0-9]+/','_',$ascii!==false?$ascii:$name),'_'));$base=substr($base,0,70)?:'CUSTOM_CERTIFICATE';$code=$base;$n=1;$check=$db->prepare('SELECT 1 FROM certificate_catalog WHERE catalog_code=? LIMIT 1');
    while(true){$check->execute([$code]);if(!$check->fetchColumn())break;$suffix='_'.(++$n);$code=substr($base,0,80-strlen($suffix)).$suffix;}
    $sort=(int)$db->query('SELECT COALESCE(MAX(sort_order),0)+10 FROM certificate_catalog')->fetchColumn();$q=$db->prepare('INSERT INTO certificate_catalog(catalog_code,display_name,sheet_column,is_active,sort_order) VALUES(?,?,?,1,?)');
    try{$q->execute([$code,$name,$column!==''?$column:null,$sort]);}catch(PDOException $e){if((string)$e->getCode()==='23000')json_response(['ok'=>false,'message'=>'That Sheet column is already assigned to another certificate type.'],409);throw $e;}
    $id=(int)$db->lastInsertId();fleet_cert_log($db,(int)$user['id'],'CREATE','CERTIFICATE_TYPE',$id,'Created certificate type '.$name.($column!==''?' mapped to '.$column:' without a Sheet mapping').'.');
    json_response(['ok'=>true,'message'=>'Certificate type created. It is now available in the Asset Certificate form.','id'=>$id]);
} catch(Throwable $e) {
    if($db->inTransaction())$db->rollBack();error_log('Certificate catalog: '.$e->getMessage());
    json_response(['ok'=>false,'message'=>'Could not save the certificate type request. Check server logs if this continues.'],500);
}
