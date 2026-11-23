<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/certificate-auth.php';
$user=fleet_cert_require_user();fleet_cert_require_permission($user,'page.settings');$db=fleet_db();
try{
    if(($_SERVER['REQUEST_METHOD']??'GET')==='GET'){$q=$db->prepare('SELECT setting_value FROM system_settings WHERE setting_key=?');$q->execute(['session_idle_timeout_minutes']);$minutes=(int)($q->fetchColumn()?:30);json_response(['ok'=>true,'idle_timeout_minutes'=>max(5,min(480,$minutes))]);}
    if(($_SERVER['REQUEST_METHOD']??'')!=='POST')json_response(['ok'=>false,'message'=>'Method not allowed.'],405);
    fleet_cert_require_same_origin_post();$in=json_decode((string)file_get_contents('php://input'),true);if(!is_array($in))json_response(['ok'=>false,'message'=>'Invalid session-settings request.'],400);$minutes=filter_var($in['idle_timeout_minutes']??null,FILTER_VALIDATE_INT);if($minutes===false||$minutes<5||$minutes>480)json_response(['ok'=>false,'message'=>'Choose an idle logout duration between 5 minutes and 8 hours.'],400);
    $q=$db->prepare('INSERT INTO system_settings(setting_key,setting_value,description) VALUES(?,?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)');$q->execute(['session_idle_timeout_minutes',(string)$minutes,'Sign out after this many minutes without user activity.']);fleet_cert_log($db,(int)$user['id'],'UPDATE','SESSION_SECURITY',null,'Changed idle logout duration to '.$minutes.' minutes.');json_response(['ok'=>true,'message'=>'Inactive-session logout duration saved.','idle_timeout_minutes'=>$minutes]);
}catch(Throwable $e){error_log('Session settings: '.$e->getMessage());json_response(['ok'=>false,'message'=>'The inactive logout setting could not be saved.'],500);}
