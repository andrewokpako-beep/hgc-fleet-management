<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/certificate-auth.php';
require_once __DIR__.'/../includes/controller-totp.php';
$user=fleet_cert_require_user();if(!in_array('APP_CONTROLLER',$user['roles']??[],true))json_response(['ok'=>false,'message'=>'This verification is reserved for the App Controller role.'],403);$db=fleet_db();$id=(int)$user['id'];
try {
    $q=$db->prepare('SELECT controller_otp_secret_enc,controller_otp_enabled_at,controller_otp_last_counter FROM users WHERE id=?');$q->execute([$id]);$row=$q->fetch();if(!$row)json_response(['ok'=>false,'message'=>'Account not found.'],404);
    if(($_SERVER['REQUEST_METHOD']??'GET')==='GET')json_response(['ok'=>true,'enrolled'=>!empty($row['controller_otp_enabled_at'])]);
    fleet_cert_require_same_origin_post();$in=json_decode((string)file_get_contents('php://input'),true);if(!is_array($in))json_response(['ok'=>false,'message'=>'Invalid OTP request.'],400);$action=(string)($in['action']??'');
    if($action==='begin') {if(!empty($row['controller_otp_enabled_at']))json_response(['ok'=>false,'message'=>'Authenticator verification is already enrolled.'],409);$secret=fleet_totp_base32_encode(random_bytes(20));$db->prepare('UPDATE users SET controller_otp_secret_enc=?,controller_otp_last_counter=NULL WHERE id=?')->execute([fleet_controller_otp_encrypt($id,$secret),$id]);$issuerName='FleetDesk App Controller';$label=rawurlencode($issuerName.':'.(string)$user['email']);$issuer=rawurlencode($issuerName);json_response(['ok'=>true,'secret'=>$secret,'otpauth_uri'=>'otpauth://totp/'.$label.'?'.http_build_query(['secret'=>$secret,'issuer'=>$issuer,'algorithm'=>'SHA1','digits'=>6,'period'=>30],'','&',PHP_QUERY_RFC3986)]);}
    if(!in_array($action,['verify_setup','verify'],true))json_response(['ok'=>false,'message'=>'Unknown verification action.'],400);
    if($action==='verify_setup'&&!empty($row['controller_otp_enabled_at']))json_response(['ok'=>false,'message'=>'Authenticator verification is already enrolled.'],409);
    if($action==='verify'&&empty($row['controller_otp_enabled_at']))json_response(['ok'=>false,'message'=>'Set up your authenticator before signing in.'],409);
    if(empty($row['controller_otp_secret_enc']))json_response(['ok'=>false,'message'=>'Start authenticator setup first.'],409);
    $secret=fleet_controller_otp_decrypt($id,(string)$row['controller_otp_secret_enc']);$counter=fleet_totp_match($secret,(string)($in['code']??''));if($counter===null)json_response(['ok'=>false,'message'=>'That code is not valid. Check your authenticator and try again.'],400);$last=$row['controller_otp_last_counter']===null?-1:(int)$row['controller_otp_last_counter'];if($counter<=$last)json_response(['ok'=>false,'message'=>'That code was already used. Wait for the next authenticator code.'],409);
    if($action==='verify_setup')$db->prepare('UPDATE users SET controller_otp_enabled_at=NOW(),controller_otp_last_counter=? WHERE id=?')->execute([$counter,$id]);else $db->prepare('UPDATE users SET controller_otp_last_counter=? WHERE id=?')->execute([$counter,$id]);
    $_SESSION['fleet_controller_otp_user_id']=$id;$_SESSION['fleet_controller_otp_expires']=time()+28800;json_response(['ok'=>true,'message'=>$action==='verify_setup'?'Authenticator is set up. App Controller access is now unlocked.':'Verification complete.','redirect'=>'index.php']);
}catch(Throwable $e){error_log('App Controller OTP: '.$e->getMessage());json_response(['ok'=>false,'message'=>'Could not verify the authenticator. Check secure storage and try again.'],500);}
