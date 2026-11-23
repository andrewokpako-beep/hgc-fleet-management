<?php
require_once __DIR__.'/../includes/bootstrap.php';
try {
 $db=fleet_db();$export=(string)($_GET['export']??'')==='1'; $sync=$db->query("SELECT s.source_code,s.source_name,r.run_status,r.started_at,r.completed_at,r.rows_read,r.rows_valid,r.rows_rejected,r.conflicts_created FROM spreadsheet_sync_runs r JOIN spreadsheet_sources s ON s.id=r.source_id ORDER BY r.id DESC".($export?'':' LIMIT 40'))->fetchAll();
 $reviews=$db->query("SELECT id,case_type,severity,case_status,description,detected_at FROM reconciliation_cases ORDER BY id DESC".($export?'':' LIMIT 500'))->fetchAll();
 json_response(['ok'=>true,'data'=>['sync'=>$sync,'reviews'=>$reviews]]);
} catch(Throwable $e){error_log('Fleet reports: '.$e->getMessage());json_response(['ok'=>false,'message'=>'Reports could not be loaded.'],500);}
