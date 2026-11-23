<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/certificate-auth.php';
$user=fleet_cert_require_user();fleet_cert_require_permission($user,'page.work-orders');$db=fleet_db();
if(($_SERVER['REQUEST_METHOD']??'GET')!=='GET')json_response(['ok'=>false,'message'=>'Method not allowed.'],405);
try{
 $assets=$db->query("SELECT a.id,a.primary_identifier,t.type_name FROM assets a JOIN asset_types t ON t.id=a.asset_type_id WHERE a.is_active=1 ORDER BY t.type_name,a.primary_identifier")->fetchAll();
 $orders=$db->query("SELECT w.*,a.primary_identifier asset_identifier,t.type_name asset_type,reporter.full_name reported_by_name,closer.full_name closed_by_name,vi.checked_at final_inspection_at FROM maintenance_work_orders w JOIN assets a ON a.id=w.asset_id JOIN asset_types t ON t.id=a.asset_type_id JOIN users reporter ON reporter.id=w.reported_by LEFT JOIN users closer ON closer.id=w.closed_by LEFT JOIN vehicle_inspections vi ON vi.id=w.final_inspection_id ORDER BY (w.status IN ('OPEN','IN_PROGRESS','REPAIR_COMPLETE')) DESC,w.target_completion_date,w.id DESC LIMIT 500")->fetchAll();
 $events=$db->query("SELECT e.*,COALESCE(NULLIF(u.full_name,''),NULLIF(u.email,''),'Former user') actor_name FROM maintenance_work_order_events e LEFT JOIN users u ON u.id=e.actor_id ORDER BY e.work_order_id,e.created_at,e.id")->fetchAll();$eventMap=[];foreach($events as $event)$eventMap[(int)$event['work_order_id']][]=$event;
 foreach($orders as &$order){$order['events']=$eventMap[(int)$order['id']]??[];$order['is_overdue']=in_array($order['status'],['OPEN','IN_PROGRESS','REPAIR_COMPLETE'],true)&&$order['target_completion_date']<date('Y-m-d');}unset($order);
 json_response(['ok'=>true,'assets'=>$assets,'work_orders'=>$orders,'can_manage'=>fleet_cert_user_can($user,'maintenance.work_orders')]);
}catch(Throwable $e){error_log('Maintenance work orders: '.$e->getMessage());json_response(['ok'=>false,'message'=>'Repair work orders could not be loaded.'],500);}
