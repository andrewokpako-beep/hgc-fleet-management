<?php
declare(strict_types=1); require_once __DIR__.'/../config/config.php';
function fleet_db(): PDO { static $p=null; if($p instanceof PDO)return $p; $p=new PDO('mysql:host='.DB_HOST.';dbname='.DB_NAME.';charset=utf8mb4',DB_USER,DB_PASS,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]); return $p; }
function json_response(array $d,int $s=200): never { http_response_code($s); header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store'); echo json_encode($d,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); exit; }
function table_exists(PDO $p,string $t): bool {$q=$p->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?');$q->execute([$t]);return (int)$q->fetchColumn()>0;}
function count_sql(PDO $p,string $s): int {try{return (int)$p->query($s)->fetchColumn();}catch(Throwable $e){return 0;}}

// Every browser-facing PHP page and API is private by default. Keep the login
// endpoint public so it can establish the existing certificate-user session.
if (PHP_SAPI !== 'cli') {
    $script = (string)($_SERVER['SCRIPT_NAME'] ?? '');
    $lowerScript=strtolower($script);
    $isLoginEndpoint = str_ends_with($lowerScript, '/api/certificate-auth.php') || str_ends_with($lowerScript, '/api/google-auth.php') || str_ends_with($lowerScript, '/api/controller-otp.php') || str_ends_with($lowerScript, '/otp.php');
    if (!$isLoginEndpoint) {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_name('fleet_certificate_session');
            session_set_cookie_params([
                'lifetime'=>0,
                'path'=>'/',
                'secure'=>(!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https',
                'httponly'=>true,
                'samesite'=>'Lax',
            ]);
            session_start();
        }
        $userId = (int)($_SESSION['fleet_certificate_user_id'] ?? 0);
        $active = false;
        if ($userId > 0) {
            $check = fleet_db()->prepare('SELECT is_active FROM users WHERE id=? LIMIT 1');
            $check->execute([$userId]);
            $active = (int)$check->fetchColumn() === 1;
        }
        $timedOut=false;
        if($active){
            $timeoutQuery=fleet_db()->prepare("SELECT setting_value FROM system_settings WHERE setting_key=?");$timeoutQuery->execute(['session_idle_timeout_minutes']);$timeoutMinutes=(int)($timeoutQuery->fetchColumn()?:30);$timeoutMinutes=max(5,min(480,$timeoutMinutes));
            $lastActivity=(int)($_SESSION['fleet_last_user_activity_at']??0);
            if($lastActivity>0&&time()-$lastActivity>=$timeoutMinutes*60){$_SESSION=[];session_regenerate_id(true);$active=false;$timedOut=true;}
            elseif($lastActivity<1)$_SESSION['fleet_last_user_activity_at']=time();
        }
        if (!$active) {
            unset($_SESSION['fleet_certificate_user_id']);
            if (str_contains(strtolower($script), '/api/')) {
                json_response(['ok'=>false,'session_expired'=>$timedOut,'message'=>$timedOut?'You were signed out after being inactive. Sign in again to continue.':'Sign in to access FleetDesk.'],401);
            }
            $base = rtrim(dirname($script), '/\\');
            if (str_ends_with(strtolower($base), '/api')) $base = dirname($base);
            if ($base === '.' || $base === DIRECTORY_SEPARATOR) $base = '';
            header('Location: '.$base.'/login.php'.($timedOut?'?session=expired':''), true, 302);
            exit;
        }
        $controller=$userId>0?fleet_db()->prepare("SELECT COUNT(*) FROM user_roles ur JOIN roles r ON r.id=ur.role_id WHERE ur.user_id=? AND r.role_name='APP_CONTROLLER'"):null;
        if($controller){$controller->execute([$userId]);if((int)$controller->fetchColumn()>0){$verified=(int)($_SESSION['fleet_controller_otp_user_id']??0)===$userId&&(int)($_SESSION['fleet_controller_otp_expires']??0)>time();if(!$verified){unset($_SESSION['fleet_controller_otp_user_id'],$_SESSION['fleet_controller_otp_expires']);if(str_contains($lowerScript,'/api/'))json_response(['ok'=>false,'otp_required'=>true,'message'=>'App Controller verification is required.'],401);$base=rtrim(dirname($script),'/\\');if($base==='.'||$base===DIRECTORY_SEPARATOR)$base='';header('Location: '.$base.'/otp.php',true,302);exit;}}}
    }
}
