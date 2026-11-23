<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/certificate-auth.php';

fleet_cert_session();
$db = fleet_db();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
try {
    if ($method === 'GET') {
        $count = (int)$db->query('SELECT COUNT(*) FROM users')->fetchColumn();
        $user = fleet_cert_user();
        $google=$db->prepare("SELECT setting_value FROM system_settings WHERE setting_key='google_workspace_signin_enabled'");$google->execute();
        json_response(['ok'=>true,'setup_required'=>$count===0,'google_signin_enabled'=>filter_var((string)$google->fetchColumn(),FILTER_VALIDATE_BOOLEAN),'user'=>$user ? ['id'=>(int)$user['id'],'full_name'=>$user['full_name'],'username'=>$user['username'],'roles'=>$user['roles']] : null]);
    }
    fleet_cert_require_same_origin_post();
    $input = json_decode((string)file_get_contents('php://input'),true);
    if (!is_array($input)) json_response(['ok'=>false,'message'=>'Invalid authentication request.'],400);
    $action = (string)($input['action'] ?? '');
    if ($action === 'logout') {
        $user = fleet_cert_user();
        if ($user) fleet_cert_log($db,(int)$user['id'],'LOGOUT','USER',(int)$user['id'],'Signed out of certificate management.');
        $_SESSION = [];
        session_regenerate_id(true);
        json_response(['ok'=>true]);
    }
    if ($action === 'setup') {
        if (!in_array((string)($_SERVER['REMOTE_ADDR'] ?? ''),['127.0.0.1','::1'],true) || isset($_SERVER['HTTP_X_FORWARDED_FOR']) || isset($_SERVER['HTTP_CF_CONNECTING_IP'])) json_response(['ok'=>false,'message'=>'Initial administrator setup must be completed directly from this computer.'],403);
        if ((int)$db->query('SELECT COUNT(*) FROM users')->fetchColumn() !== 0) json_response(['ok'=>false,'message'=>'Initial setup is already complete. Sign in instead.'],409);
        $name=trim((string)($input['full_name']??''));$username=trim((string)($input['username']??''));$email=trim((string)($input['email']??''));$password=(string)($input['password']??'');
        if ($name==='' || !preg_match('/^[A-Za-z0-9._-]{3,80}$/',$username) || strlen($password)<12 || ($email!==''&&!filter_var($email,FILTER_VALIDATE_EMAIL))) json_response(['ok'=>false,'message'=>'Enter a name, a username of 3–80 letters/numbers, a valid optional email, and a password of at least 12 characters.'],400);
        $role=$db->prepare("SELECT id FROM roles WHERE role_name='ADMIN' LIMIT 1");$role->execute();$roleId=(int)$role->fetchColumn();if(!$roleId)json_response(['ok'=>false,'message'=>'ADMIN role is not configured.'],500);
        $db->beginTransaction();
        try {
            $insert=$db->prepare('INSERT INTO users (full_name,username,email,password_hash,is_active) VALUES (?,?,?,?,1)');
            $insert->execute([$name,$username,$email!==''?$email:null,password_hash($password,PASSWORD_DEFAULT)]);
            $userId=(int)$db->lastInsertId();$link=$db->prepare('INSERT INTO user_roles (user_id,role_id) VALUES (?,?)');$link->execute([$userId,$roleId]);
            fleet_cert_log($db,$userId,'CREATE','USER',$userId,'Created the initial certificate administrator account.');
            $db->commit();
        } catch(Throwable $e) { if($db->inTransaction())$db->rollBack(); throw $e; }
        session_regenerate_id(true);$_SESSION['fleet_certificate_user_id']=$userId;
        json_response(['ok'=>true,'message'=>'Administrator account created.']);
    }
    if ($action === 'login') {
        $googleEnabled=$db->prepare("SELECT setting_value FROM system_settings WHERE setting_key='google_workspace_signin_enabled'");$googleEnabled->execute();if(filter_var((string)$googleEnabled->fetchColumn(),FILTER_VALIDATE_BOOLEAN))json_response(['ok'=>false,'message'=>'Password sign-in is disabled. Continue with your Google Workspace account.'],403);
        $username=trim((string)($input['username']??''));$password=(string)($input['password']??'');
        $stmt=$db->prepare('SELECT id,full_name,password_hash,is_active FROM users WHERE username=? LIMIT 1');$stmt->execute([$username]);$user=$stmt->fetch();
        if (!$user || !(int)$user['is_active'] || !password_verify($password,(string)$user['password_hash'])) json_response(['ok'=>false,'message'=>'Username or password is incorrect.'],401);
        session_regenerate_id(true);$_SESSION['fleet_certificate_user_id']=(int)$user['id'];
        $db->prepare('UPDATE users SET last_login_at=NOW() WHERE id=?')->execute([(int)$user['id']]);
        fleet_cert_log($db,(int)$user['id'],'LOGIN','USER',(int)$user['id'],'Signed in to certificate management.');
        $roles=$db->prepare('SELECT COUNT(*) FROM user_roles WHERE user_id=?');$roles->execute([(int)$user['id']]);$anyRole=(int)$roles->fetchColumn()>0;$controller=$db->prepare("SELECT COUNT(*) FROM user_roles ur JOIN roles r ON r.id=ur.role_id WHERE ur.user_id=? AND r.role_name='APP_CONTROLLER'");$controller->execute([(int)$user['id']]);$isController=(int)$controller->fetchColumn()>0;if($isController){unset($_SESSION['fleet_controller_otp_user_id'],$_SESSION['fleet_controller_otp_expires']);json_response(['ok'=>true,'message'=>'Signed in. App Controller verification is required.','needs_otp'=>true]);}json_response(['ok'=>true,'message'=>'Signed in.','needs_role'=>!$anyRole]);
    }
    json_response(['ok'=>false,'message'=>'Unknown authentication action.'],400);
} catch(Throwable $e) {
    error_log('Certificate authentication: '.$e->getMessage());
    json_response(['ok'=>false,'message'=>'Authentication request failed. Check the user account details.'],500);
}
