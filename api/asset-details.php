<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/bootstrap.php';

try {
    $db=fleet_db();
    $registration=trim((string)($_GET['registration']??''));
    $normalized=preg_replace('/[^A-Z0-9]/','',strtoupper($registration));
    if($normalized===''||strlen($normalized)>40)json_response(['ok'=>false,'message'=>'Enter a valid asset registration.'],400);

    $q=$db->prepare("SELECT a.id,a.primary_identifier,a.make,a.model,a.manufacture_year,a.registration_status,a.is_active,a.last_seen_at,t.type_name asset_type,t.type_code,y.yard_name,s.status_name current_status FROM assets a JOIN asset_types t ON t.id=a.asset_type_id LEFT JOIN yards y ON y.id=a.current_yard_id LEFT JOIN asset_statuses s ON s.id=a.current_status_id WHERE REPLACE(REPLACE(UPPER(a.primary_identifier),'-',''),' ','')=? OR EXISTS(SELECT 1 FROM asset_identifiers ai WHERE ai.asset_id=a.id AND REPLACE(REPLACE(UPPER(ai.normalized_value),'-',''),' ','')=?) ORDER BY a.is_active DESC,a.id LIMIT 1");
    $q->execute([$normalized,$normalized]);$asset=$q->fetch(PDO::FETCH_ASSOC);
    if(!$asset)json_response(['ok'=>false,'message'=>'No confirmed asset matches that registration.'],404);

    $id=(int)$asset['id'];
    $driverQuery=$db->prepare("SELECT d.full_name,d.phone FROM asset_driver_assignments da JOIN drivers d ON d.id=da.driver_id WHERE da.asset_id=? AND da.assigned_to IS NULL AND d.is_active=1 ORDER BY da.assigned_from DESC,da.id DESC LIMIT 1");
    $driverQuery->execute([$id]);$asset['driver']=$driverQuery->fetch(PDO::FETCH_ASSOC)?:null;
    $attachmentQuery=$db->prepare("SELECT a2.primary_identifier,t2.type_name FROM asset_attachments aa JOIN assets a2 ON a2.id=CASE WHEN aa.head_asset_id=? THEN aa.trailer_asset_id ELSE aa.head_asset_id END JOIN asset_types t2 ON t2.id=a2.asset_type_id WHERE (aa.head_asset_id=? OR aa.trailer_asset_id=?) AND aa.attached_to IS NULL ORDER BY aa.attached_from DESC,aa.id DESC");
    $attachmentQuery->execute([$id,$id,$id]);$asset['attached_assets']=$attachmentQuery->fetchAll(PDO::FETCH_ASSOC);
    $certQuery=$db->prepare("SELECT COALESCE(NULLIF(cs.certificate_name,''),ct.display_name) certificate_name,cs.issue_date,cs.expiry_date,cs.status,cs.record_source FROM certificate_submissions cs JOIN certificate_types ct ON ct.id=cs.certificate_type_id WHERE cs.asset_id=? AND cs.status IN ('APPROVED','SUPERSEDED') ORDER BY cs.expiry_date DESC,cs.id DESC");
    $certQuery->execute([$id]);$asset['certificates']=$certQuery->fetchAll(PDO::FETCH_ASSOC);
    json_response(['ok'=>true,'asset'=>$asset]);
}catch(Throwable $e){error_log('Report asset details: '.$e->getMessage());json_response(['ok'=>false,'message'=>'Asset details could not be loaded.'],500);}
