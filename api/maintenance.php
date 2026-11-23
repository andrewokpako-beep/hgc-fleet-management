<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/certificate-auth.php';
$user=fleet_cert_require_user();fleet_cert_require_permission($user,'page.maintenance');
$db=fleet_db();
try{
 $assets=$db->query("SELECT a.id,a.primary_identifier,t.type_code,t.type_name FROM assets a JOIN asset_types t ON t.id=a.asset_type_id WHERE a.is_active=1 ORDER BY t.type_name,a.primary_identifier")->fetchAll();
 $drivers=$db->query('SELECT id,full_name,phone FROM drivers WHERE is_active=1 ORDER BY full_name')->fetchAll();
 $inspections=$db->query('SELECT vi.id,vi.asset_id,a.primary_identifier,vi.checked_at,vi.odometer,vi.result,vi.checklist_json,vi.critical_defects,vi.notes,u.full_name checked_by FROM vehicle_inspections vi JOIN assets a ON a.id=vi.asset_id JOIN users u ON u.id=vi.checked_by ORDER BY vi.id DESC LIMIT 250')->fetchAll();
 foreach($inspections as &$r)$r['checklist']=json_decode((string)$r['checklist_json'],true)?:[];unset($r);
 $fitness=$db->query('SELECT fc.id,fc.driver_id,d.full_name driver_name,fc.checked_at,fc.licence_checked,fc.licence_expiry,fc.rested,fc.fit_to_drive,fc.result,fc.notes,u.full_name checked_by FROM driver_fitness_checks fc JOIN drivers d ON d.id=fc.driver_id JOIN users u ON u.id=fc.checked_by ORDER BY fc.id DESC LIMIT 150')->fetchAll();foreach($fitness as &$check)$check['reference']='FIT-'.str_pad((string)$check['id'],6,'0',STR_PAD_LEFT);unset($check);
 $logs=$db->query('SELECT ml.id,ml.asset_id,a.primary_identifier,ml.inspection_id,ml.reported_at,ml.defect_summary,ml.severity,ml.corrective_action,ml.resolved_at,ml.cost,ml.currency,u.full_name reported_by FROM maintenance_logs ml JOIN assets a ON a.id=ml.asset_id JOIN users u ON u.id=ml.reported_by ORDER BY ml.id DESC LIMIT 250')->fetchAll();
 $trips=$db->query("SELECT id,trip_number,status FROM fleet_trips WHERE status IN ('PENDING','APPROVED','IN_PROGRESS') ORDER BY scheduled_departure DESC LIMIT 100")->fetchAll();
 $workOrders=$db->query("SELECT id,work_order_number,asset_id,status FROM maintenance_work_orders WHERE status IN ('OPEN','IN_PROGRESS','REPAIR_COMPLETE') ORDER BY reported_at DESC")->fetchAll();
 json_response(['ok'=>true,'assets'=>$assets,'drivers'=>$drivers,'inspections'=>$inspections,'fitness_checks'=>$fitness,'maintenance_logs'=>$logs,'trips'=>$trips,'work_orders'=>$workOrders,'can_inspect'=>fleet_cert_user_can($user,'maintenance.inspect'),'can_resolve_defects'=>fleet_cert_user_can($user,'maintenance.resolve_defects')]);
}catch(Throwable $e){error_log('Maintenance data: '.$e->getMessage());json_response(['ok'=>false,'message'=>'Maintenance records could not be loaded.'],500);}
