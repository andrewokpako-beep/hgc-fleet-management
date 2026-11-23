<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/certificate-auth.php';
fleet_cert_require_user();
try{
 $db=fleet_db();
 $totals=$db->query("SELECT COUNT(*) total, SUM(status='APPROVED') approved, SUM(status='PENDING') pending, SUM(status='DECLINED') declined, SUM(status='APPROVED' AND expiry_date<CURDATE()) expired, SUM(status='APPROVED' AND expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(),INTERVAL 30 DAY)) expiring_30 FROM certificate_submissions")->fetch()?:[];
 $byName=$db->query("SELECT COALESCE(NULLIF(cs.certificate_name,''),ct.display_name) certificate_name,cs.asset_scope,SUM(cs.status='APPROVED') approved,SUM(cs.status='PENDING') pending,SUM(cs.status='DECLINED') declined FROM certificate_submissions cs JOIN certificate_types ct ON ct.id=cs.certificate_type_id GROUP BY COALESCE(NULLIF(cs.certificate_name,''),ct.display_name),cs.asset_scope ORDER BY COUNT(*) DESC,certificate_name LIMIT 20")->fetchAll();
 $buckets=$db->query("SELECT SUM(expiry_date<CURDATE()) overdue,SUM(expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(),INTERVAL 7 DAY)) within_7,SUM(expiry_date>DATE_ADD(CURDATE(),INTERVAL 7 DAY) AND expiry_date<=DATE_ADD(CURDATE(),INTERVAL 30 DAY)) within_30,SUM(expiry_date>DATE_ADD(CURDATE(),INTERVAL 30 DAY)) later FROM certificate_submissions WHERE status='APPROVED'")->fetch()?:[];
 json_response(['ok'=>true,'totals'=>array_map(static fn($v)=>(int)($v??0),$totals),'by_name'=>$byName,'expiry_buckets'=>array_map(static fn($v)=>(int)($v??0),$buckets)]);
}catch(Throwable $e){error_log('Certificate dashboard: '.$e->getMessage());json_response(['ok'=>false,'message'=>'Certificate dashboard data could not be loaded.'],500);}
