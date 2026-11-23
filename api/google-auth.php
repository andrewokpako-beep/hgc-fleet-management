<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/certificate-auth.php';
require_once __DIR__.'/../includes/google-oauth-settings.php';
require_once __DIR__.'/../vendor/autoload.php';

fleet_cert_session();
$db=fleet_db();
function google_auth_settings(PDO $db): array {
    $q=$db->query("SELECT setting_key,setting_value FROM system_settings WHERE setting_key IN ('google_workspace_signin_enabled','google_workspace_domain','google_require_workspace_domain','google_oauth_client_id','google_oauth_redirect_uri')");$s=$q->fetchAll(PDO::FETCH_KEY_PAIR);$s['google_oauth_client_secret']=fleet_google_oauth_secret();return $s;
}
function google_auth_redirect(string $status): never {
    header('Location: ../login.php?google='.rawurlencode($status),true,302);exit;
}
$action=(string)($_GET['action']??'start');
try {
    $s=google_auth_settings($db);
    $enabled=filter_var($s['google_workspace_signin_enabled']??'0',FILTER_VALIDATE_BOOLEAN);
    $requireDomain=filter_var($s['google_require_workspace_domain']??'1',FILTER_VALIDATE_BOOLEAN);
    if($action==='status')json_response(['ok'=>true,'enabled'=>$enabled,'domain'=>(string)($s['google_workspace_domain']??''),'require_workspace_domain'=>$requireDomain]);
    if(!$enabled || trim((string)($s['google_oauth_client_id']??''))==='' || trim((string)($s['google_oauth_client_secret']??''))==='' || ($requireDomain&&trim((string)($s['google_workspace_domain']??''))===''))google_auth_redirect('not_configured');
    $redirect=trim((string)($s['google_oauth_redirect_uri']??''));
    if($redirect==='') {
        $proto=strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO']??''));$https=(!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off')||$proto==='https';
        $base=rtrim(dirname(dirname((string)($_SERVER['SCRIPT_NAME']??'/api/google-auth.php'))),'/');
        $redirect=($https?'https':'http').'://'.(string)($_SERVER['HTTP_HOST']??'localhost').$base.'/api/google-auth.php?action=callback';
    }
    $client=new Google\Client();$client->setClientId((string)$s['google_oauth_client_id']);$client->setClientSecret((string)$s['google_oauth_client_secret']);$client->setRedirectUri($redirect);$client->setScopes(['openid','email','profile']);$client->setAccessType('online');
    if($action==='start') {
        $state=bin2hex(random_bytes(32));$nonce=bin2hex(random_bytes(32));$_SESSION['google_oauth_state']=$state;$_SESSION['google_oauth_nonce']=$nonce;$_SESSION['google_oauth_redirect']=$redirect;
        $client->setState($state);$authParams=['nonce'=>$nonce];if($requireDomain)$authParams['hd']=strtolower(trim((string)$s['google_workspace_domain']));$url=$client->createAuthUrl(null,$authParams);header('Location: '.$url,true,302);exit;
    }
    if($action!=='callback')google_auth_redirect('failed');
    $state=(string)($_GET['state']??'');$expected=(string)($_SESSION['google_oauth_state']??'');$nonce=(string)($_SESSION['google_oauth_nonce']??'');$redirect=(string)($_SESSION['google_oauth_redirect']??$redirect);
    unset($_SESSION['google_oauth_state'],$_SESSION['google_oauth_nonce'],$_SESSION['google_oauth_redirect']);
    if($state===''||$expected===''||!hash_equals($expected,$state)){error_log('Google OAuth callback rejected: state missing or mismatched; session cookie present='.(isset($_COOKIE[session_name()])?'yes':'no'));google_auth_redirect('state');}
    if(isset($_GET['error'])){error_log('Google OAuth callback ended with provider error: '.preg_replace('/[^a-zA-Z0-9_.-]/','',(string)$_GET['error']));google_auth_redirect('denied');}
    if(trim((string)($_GET['code']??''))===''){error_log('Google OAuth callback did not contain an authorization code.');google_auth_redirect('missing_code');}
    $client->setRedirectUri($redirect);$token=$client->fetchAccessTokenWithAuthCode((string)$_GET['code']);if(isset($token['error'])){error_log('Google OAuth token exchange rejected: '.preg_replace('/[^a-zA-Z0-9_.-]/','',(string)$token['error']));google_auth_redirect('token');}
    $claims=$client->verifyIdToken((string)($token['id_token']??''));if(!is_array($claims)){error_log('Google OAuth token verification failed.');google_auth_redirect('token_verify');}
    $domain=strtolower(trim((string)$s['google_workspace_domain']));$hosted=strtolower(trim((string)($claims['hd']??'')));$email=strtolower(trim((string)($claims['email']??'')));$sub=trim((string)($claims['sub']??''));
    if($nonce===''||!hash_equals($nonce,(string)($claims['nonce']??''))||($requireDomain&&$hosted!==$domain)||empty($claims['email_verified'])||$email===''||$sub==='')google_auth_redirect('domain');
    $db->beginTransaction();
    try {
        $find=$db->prepare('SELECT id,google_sub,is_active FROM users WHERE google_sub=? LIMIT 1');$find->execute([$sub]);$u=$find->fetch();
        if(!$u){$find=$db->prepare('SELECT id,google_sub,is_active FROM users WHERE LOWER(email)=? LIMIT 1');$find->execute([$email]);$u=$find->fetch();if($u){if(!empty($u['google_sub'])&&!hash_equals((string)$u['google_sub'],$sub)){ $db->rollBack();google_auth_redirect('account_conflict');}$db->prepare('UPDATE users SET google_sub=? WHERE id=?')->execute([$sub,(int)$u['id']]);}}
        if(!$u){$name=trim((string)($claims['name']??''));if($name==='')$name=$email;$base=preg_replace('/[^A-Za-z0-9._-]/','_',strstr($email,'@',true)?:'workspace_user');$username=substr($base,0,60);$check=$db->prepare('SELECT COUNT(*) FROM users WHERE username=?');$check->execute([$username]);if((int)$check->fetchColumn()>0)$username=substr($base,0,50).'_'.substr(hash('sha256',$sub),0,8);$ins=$db->prepare('INSERT INTO users(full_name,username,email,google_sub,password_hash,is_active) VALUES(?,?,?,?,NULL,1)');$ins->execute([$name,$username,$email,$sub]);$u=['id'=>(int)$db->lastInsertId(),'is_active'=>1];fleet_cert_log($db,(int)$u['id'],'CREATE','USER',(int)$u['id'],'Workspace user signed in; awaiting role assignment.');}
        if(!(int)$u['is_active']){$db->rollBack();google_auth_redirect('inactive');}
        $db->prepare('UPDATE users SET last_login_at=NOW() WHERE id=?')->execute([(int)$u['id']]);fleet_cert_log($db,(int)$u['id'],'LOGIN','USER',(int)$u['id'],'Signed in with Google Workspace.');$db->commit();
    }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    session_regenerate_id(true);$_SESSION['fleet_certificate_user_id']=(int)$u['id'];
    $roles=$db->prepare("SELECT COUNT(*) FROM user_roles ur JOIN roles r ON r.id=ur.role_id WHERE ur.user_id=? AND r.role_name='APP_CONTROLLER'");$roles->execute([(int)$u['id']]);$isController=(int)$roles->fetchColumn()>0;$anyRole=$db->prepare('SELECT COUNT(*) FROM user_roles WHERE user_id=?');$anyRole->execute([(int)$u['id']]);header('Location: ../'.($isController?'otp.php':((int)$anyRole->fetchColumn()===0?'welcome.php':'index.php')),true,302);exit;
}catch(Throwable $e){if($db->inTransaction())$db->rollBack();error_log('Google Workspace sign-in: '.$e->getMessage());google_auth_redirect('failed');}
