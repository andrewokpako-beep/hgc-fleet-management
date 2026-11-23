<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/certificate-auth.php';
$user=fleet_cert_require_user();$db=fleet_db();
try{
    $q=$db->prepare('SELECT setting_value FROM system_settings WHERE setting_key=?');$q->execute(['session_idle_timeout_minutes']);$minutes=max(5,min(480,(int)($q->fetchColumn()?:30)));
    if(($_SERVER['REQUEST_METHOD']??'GET')==='GET')json_response(['ok'=>true,'idle_timeout_minutes'=>$minutes]);
    if(($_SERVER['REQUEST_METHOD']??'')!=='POST')json_response(['ok'=>false,'message'=>'Method not allowed.'],405);
    fleet_cert_require_same_origin_post();$_SESSION['fleet_last_user_activity_at']=time();json_response(['ok'=>true,'idle_timeout_minutes'=>$minutes]);
}catch(Throwable $e){error_log('Session activity: '.$e->getMessage());json_response(['ok'=>false,'message'=>'The active session could not be renewed.'],500);}
