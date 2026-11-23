<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/certificate-auth.php';
$user=fleet_cert_require_user();fleet_cert_require_permission($user,'page.attachments');

try {
    $db = fleet_db();
    $summary = $db->query("SELECT
        (SELECT COUNT(*) FROM assets a JOIN asset_types t ON t.id=a.asset_type_id WHERE a.is_active=1 AND t.type_code='TRUCK_HEAD') horses,
        (SELECT COUNT(DISTINCT aa.head_asset_id) FROM asset_attachments aa JOIN assets h ON h.id=aa.head_asset_id JOIN assets tr ON tr.id=aa.trailer_asset_id WHERE aa.attached_to IS NULL AND h.is_active=1 AND tr.is_active=1) linked_horses,
        (SELECT COUNT(*) FROM assets a JOIN asset_types t ON t.id=a.asset_type_id WHERE a.is_active=1 AND t.type_code IN ('TANKER_TRAILER','FLATBED_TRAILER')) trailers,
        (SELECT COUNT(DISTINCT da.asset_id) FROM asset_driver_assignments da JOIN assets a ON a.id=da.asset_id WHERE da.assigned_to IS NULL AND a.is_active=1) assigned_drivers")->fetch() ?: [];

    $trucks = $db->query("SELECT
        h.id, h.primary_identifier AS horse,
        h.make AS make, h.model AS model, h.manufacture_year,
        h.registration_status, h.last_seen_at,
        hs.status_name AS status, hy.yard_name AS current_yard,
        (SELECT COUNT(*) FROM asset_attachments aa WHERE aa.head_asset_id=h.id AND aa.attached_to IS NULL) AS attachment_count,
        (SELECT aa.id FROM asset_attachments aa WHERE aa.head_asset_id=h.id AND aa.attached_to IS NULL ORDER BY aa.attached_from DESC,aa.id DESC LIMIT 1) AS attachment_id,
        (SELECT tr.id FROM asset_attachments aa JOIN assets tr ON tr.id=aa.trailer_asset_id WHERE aa.head_asset_id=h.id AND aa.attached_to IS NULL AND tr.is_active=1 ORDER BY aa.attached_from DESC,aa.id DESC LIMIT 1) AS trailer_asset_id,
        (SELECT tr.primary_identifier FROM asset_attachments aa JOIN assets tr ON tr.id=aa.trailer_asset_id JOIN asset_types tt ON tt.id=tr.asset_type_id WHERE aa.head_asset_id=h.id AND aa.attached_to IS NULL AND tr.is_active=1 ORDER BY aa.attached_from DESC,aa.id DESC LIMIT 1) AS trailer,
        (SELECT tt.type_name FROM asset_attachments aa JOIN assets tr ON tr.id=aa.trailer_asset_id JOIN asset_types tt ON tt.id=tr.asset_type_id WHERE aa.head_asset_id=h.id AND aa.attached_to IS NULL AND tr.is_active=1 ORDER BY aa.attached_from DESC,aa.id DESC LIMIT 1) AS trailer_type,
        (SELECT da.id FROM asset_driver_assignments da JOIN drivers d ON d.id=da.driver_id WHERE da.asset_id=h.id AND da.assigned_to IS NULL AND d.is_active=1 ORDER BY da.assigned_from DESC,da.id DESC LIMIT 1) AS driver_assignment_id,
        (SELECT d.id FROM asset_driver_assignments da JOIN drivers d ON d.id=da.driver_id WHERE da.asset_id=h.id AND da.assigned_to IS NULL AND d.is_active=1 ORDER BY da.assigned_from DESC,da.id DESC LIMIT 1) AS driver_id,
        (SELECT d.full_name FROM asset_driver_assignments da JOIN drivers d ON d.id=da.driver_id WHERE da.asset_id=h.id AND da.assigned_to IS NULL AND d.is_active=1 ORDER BY da.assigned_from DESC,da.id DESC LIMIT 1) AS driver,
        (SELECT d.phone FROM asset_driver_assignments da JOIN drivers d ON d.id=da.driver_id WHERE da.asset_id=h.id AND da.assigned_to IS NULL AND d.is_active=1 ORDER BY da.assigned_from DESC,da.id DESC LIMIT 1) AS driver_phone,
        (SELECT s.status_text FROM tracking_objects o JOIN tracking_snapshots s ON s.tracking_object_id=o.id WHERE o.asset_id=h.id AND o.is_active=1 ORDER BY s.observed_at DESC,s.id DESC LIMIT 1) AS tracking_status,
        (SELECT g.geofence_name FROM tracking_objects o JOIN tracking_snapshots s ON s.tracking_object_id=o.id LEFT JOIN geofences g ON g.id=s.geofence_id WHERE o.asset_id=h.id AND o.is_active=1 ORDER BY s.observed_at DESC,s.id DESC LIMIT 1) AS last_reported_location,
        (SELECT s.observed_at FROM tracking_objects o JOIN tracking_snapshots s ON s.tracking_object_id=o.id WHERE o.asset_id=h.id AND o.is_active=1 ORDER BY s.observed_at DESC,s.id DESC LIMIT 1) AS tracking_observed_at
      FROM assets h
      JOIN asset_types ht ON ht.id=h.asset_type_id AND ht.type_code='TRUCK_HEAD'
      LEFT JOIN asset_statuses hs ON hs.id=h.current_status_id
      LEFT JOIN yards hy ON hy.id=h.current_yard_id
      WHERE h.is_active=1
        AND EXISTS(SELECT 1 FROM asset_attachments active_link JOIN assets active_trailer ON active_trailer.id=active_link.trailer_asset_id AND active_trailer.is_active=1 WHERE active_link.head_asset_id=h.id AND active_link.attached_to IS NULL)
        AND EXISTS(SELECT 1 FROM asset_driver_assignments active_driver JOIN drivers assigned_driver ON assigned_driver.id=active_driver.driver_id AND assigned_driver.is_active=1 WHERE active_driver.asset_id=h.id AND active_driver.assigned_to IS NULL)
      ORDER BY h.primary_identifier")->fetchAll();

    $inspectionMap=[];
    foreach($db->query('SELECT vi.* FROM vehicle_inspections vi JOIN (SELECT asset_id,MAX(id) last_id FROM vehicle_inspections GROUP BY asset_id) latest ON latest.last_id=vi.id') as $check)$inspectionMap[(int)$check['asset_id']]=$check;
    $fitnessMap=[];
    foreach($db->query('SELECT fc.*,u.full_name checked_by_name FROM driver_fitness_checks fc JOIN users u ON u.id=fc.checked_by JOIN (SELECT driver_id,MAX(id) last_id FROM driver_fitness_checks GROUP BY driver_id) latest ON latest.last_id=fc.id') as $check){$check['reference']='FIT-'.str_pad((string)$check['id'],6,'0',STR_PAD_LEFT);$fitnessMap[(int)$check['driver_id']]=$check;}
    $readinessMap=[];
    foreach($db->query('SELECT * FROM combination_readiness_approvals ORDER BY id DESC') as $approval){$key=implode(':',[(int)$approval['attachment_id'],(int)$approval['driver_assignment_id'],(int)$approval['horse_inspection_id'],(int)($approval['trailer_inspection_id']??0),(int)$approval['driver_fitness_check_id']]);if(!isset($readinessMap[$key]))$readinessMap[$key]=$approval;}
    $unresolved=[];foreach($db->query('SELECT asset_id,COUNT(*) total FROM maintenance_logs WHERE resolved_at IS NULL GROUP BY asset_id') as $row)$unresolved[(int)$row['asset_id']]=(int)$row['total'];$workHolds=[];foreach($db->query("SELECT asset_id,COUNT(*) total FROM maintenance_work_orders WHERE status IN ('OPEN','IN_PROGRESS','REPAIR_COMPLETE') GROUP BY asset_id") as $row)$workHolds[(int)$row['asset_id']]=(int)$row['total'];
    $activeTrips=$db->query("SELECT t.id,t.trip_number,t.status,t.dispatched_at,t.scheduled_departure,t.horse_asset_id,t.trailer_asset_id,t.driver_id,t.destination FROM fleet_trips t WHERE t.status IN ('APPROVED','IN_PROGRESS') ORDER BY t.requested_at DESC,t.id DESC")->fetchAll();$tripMap=[];foreach($activeTrips as $trip){$key=implode(':',[(int)$trip['horse_asset_id'],(int)($trip['trailer_asset_id']??0),(int)$trip['driver_id']]);if(!isset($tripMap[$key]))$tripMap[$key]=$trip;}
    foreach($trucks as &$truck){$horseCheck=$inspectionMap[(int)$truck['id']]??null;$trailerCheck=!empty($truck['trailer_asset_id'])?($inspectionMap[(int)$truck['trailer_asset_id']]??null):null;$fitness=!empty($truck['driver_id'])?($fitnessMap[(int)$truck['driver_id']]??null):null;$truck['horse_inspection']=$horseCheck;$truck['trailer_inspection']=$trailerCheck;$truck['driver_fitness']=$fitness;$truck['open_defects']=($unresolved[(int)$truck['id']]??0)+(!empty($truck['trailer_asset_id'])?($unresolved[(int)$truck['trailer_asset_id']]??0):0);$truck['open_work_orders']=($workHolds[(int)$truck['id']]??0)+(!empty($truck['trailer_asset_id'])?($workHolds[(int)$truck['trailer_asset_id']]??0):0);$truck['active_trip']=$tripMap[implode(':',[(int)$truck['id'],(int)($truck['trailer_asset_id']??0),(int)($truck['driver_id']??0)])]??null;$truck['readiness_status']=null;$truck['readiness_request_id']=null;$truck['readiness_can_decide']=false;if($truck['attachment_id']&&$truck['driver_assignment_id']&&$horseCheck&&$fitness){$key=implode(':',[(int)$truck['attachment_id'],(int)$truck['driver_assignment_id'],(int)$horseCheck['id'],(int)($trailerCheck['id']??0),(int)$fitness['id']]);$approval=$readinessMap[$key]??null;$truck['readiness_status']=$approval['status']??null;$truck['readiness_request_id']=$approval?(int)$approval['id']:null;$truck['readiness_can_decide']=$approval&&$approval['status']==='PENDING'&&fleet_cert_user_can($user,'readiness.approve')&&(int)$approval['requested_by']!==(int)$user['id'];}}
    unset($truck);

    $trailers = $db->query("SELECT tr.id,tr.primary_identifier AS registration,t.type_name AS type,
        tr.make,tr.model,tr.manufacture_year,tr.registration_status,s.status_name AS status,y.yard_name AS current_yard
      FROM assets tr JOIN asset_types t ON t.id=tr.asset_type_id
      LEFT JOIN asset_statuses s ON s.id=tr.current_status_id
      LEFT JOIN yards y ON y.id=tr.current_yard_id
      LEFT JOIN asset_attachments aa ON aa.trailer_asset_id=tr.id AND aa.attached_to IS NULL
      WHERE tr.is_active=1 AND t.type_code IN ('TANKER_TRAILER','FLATBED_TRAILER') AND aa.id IS NULL
      ORDER BY t.type_name,tr.primary_identifier")->fetchAll();

    $linkableHorses=$db->query("SELECT a.id,a.primary_identifier,t.type_name FROM assets a JOIN asset_types t ON t.id=a.asset_type_id WHERE a.is_active=1 AND t.type_code='TRUCK_HEAD' ORDER BY a.primary_identifier")->fetchAll();
    $linkableTrailers=$db->query("SELECT a.id,a.primary_identifier,t.type_name FROM assets a JOIN asset_types t ON t.id=a.asset_type_id WHERE a.is_active=1 AND t.type_code IN ('TANKER_TRAILER','FLATBED_TRAILER') ORDER BY t.type_name,a.primary_identifier")->fetchAll();
    $activeLinks=$db->query("SELECT aa.id,aa.head_asset_id,aa.trailer_asset_id,h.primary_identifier horse,t.primary_identifier trailer,tt.type_name trailer_type,aa.attached_from,aa.notes FROM asset_attachments aa JOIN assets h ON h.id=aa.head_asset_id JOIN assets t ON t.id=aa.trailer_asset_id JOIN asset_types tt ON tt.id=t.asset_type_id WHERE aa.attached_to IS NULL AND h.is_active=1 AND t.is_active=1 ORDER BY h.primary_identifier")->fetchAll();
    $pendingLinkRequests=[];foreach($db->query("SELECT id,request_number,action_type,payload_json,previous_json FROM operation_change_requests WHERE request_status='PENDING' AND action_type IN ('LINK_TRAILER','UNLINK_TRAILER') ORDER BY requested_at DESC,id DESC") as $r){$r['payload']=json_decode((string)$r['payload_json'],true)?:[];$r['previous']=json_decode((string)$r['previous_json'],true)?:[];unset($r['payload_json'],$r['previous_json']);$pendingLinkRequests[]=$r;}
    json_response(['ok'=>true,'pending_link_requests'=>$pendingLinkRequests,'can_manage'=>fleet_cert_user_can($user,'operations.request'),'can_manage_trips'=>fleet_cert_user_can($user,'trips.manage'),'can_review'=>fleet_cert_user_can($user,'readiness.approve'),'can_request_readiness'=>fleet_cert_user_can($user,'readiness.request'),'linkable_horses'=>$linkableHorses,'linkable_trailers'=>$linkableTrailers,'active_links'=>$activeLinks,'unlinked_trailers'=>$trailers,'summary'=>[
        'horses'=>(int)($summary['horses']??0),
        'linked_horses'=>(int)($summary['linked_horses']??0),
        'unlinked_horses'=>max(0,(int)($summary['horses']??0)-(int)($summary['linked_horses']??0)),
        'trailers'=>(int)($summary['trailers']??0),
        'assigned_drivers'=>(int)($summary['assigned_drivers']??0),
        'unlinked_trailers'=>count($trailers),
    ],'trucks'=>$trucks,'unlinked_trailers'=>$trailers]);
} catch (Throwable $e) {
    error_log('Full Truck workspace: '.$e->getMessage());
    json_response(['ok'=>false,'message'=>'Full Truck data could not be loaded.'],500);
}
