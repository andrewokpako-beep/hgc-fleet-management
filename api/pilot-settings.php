<?php
declare(strict_types=1);
require_once __DIR__.'/ai-common.php';
require_once __DIR__.'/../includes/certificate-auth.php';

$user = fleet_cert_require_user();
fleet_cert_require_permission($user,'tracking.settings');

function pilot_secure_paths(): array
{
    $appRoot = realpath(dirname(__DIR__));
    $secureDir = realpath(dirname(GOOGLE_CREDENTIALS_FILE));
    if (!$appRoot || !$secureDir || !is_dir($secureDir) || !is_writable($secureDir)) {
        throw new RuntimeException('The secure credential directory is unavailable or not writable.');
    }
    $prefix = strtolower(rtrim($appRoot, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR);
    if (str_starts_with(strtolower(rtrim($secureDir, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR), $prefix)) {
        throw new RuntimeException('Tracking API credentials must be stored outside the public application directory.');
    }
    return [$secureDir.'/fleet-pilot-master.key', $secureDir.'/fleet-pilot-settings.enc'];
}

function pilot_load_settings(): array
{
    [$keyPath, $settingsPath] = pilot_secure_paths();
    if (!is_file($settingsPath)) return ['endpoint'=>'https://gps.naviafri.com/api/api.php', 'command_parameter'=>'cmd', 'list_command'=>'list', 'username'=>'', 'password'=>''];
    $key = is_file($keyPath) ? file_get_contents($keyPath) : false;
    $box = json_decode((string)file_get_contents($settingsPath), true);
    if (!is_string($key) || strlen($key) !== 32 || !is_array($box)) throw new RuntimeException('Saved tracking API settings are unavailable or invalid.');
    $iv=base64_decode((string)($box['iv']??''),true); $tag=base64_decode((string)($box['tag']??''),true); $cipher=base64_decode((string)($box['ciphertext']??''),true);
    if ($iv===false||$tag===false||$cipher===false) throw new RuntimeException('Saved tracking API settings are malformed.');
    $plain=openssl_decrypt($cipher,'aes-256-gcm',$key,OPENSSL_RAW_DATA,$iv,$tag,'fleet-pilot-settings-v1');
    $data=is_string($plain)?json_decode($plain,true):null;
    if (!is_array($data)) throw new RuntimeException('Saved tracking API settings could not be decrypted.');
    if (preg_match('~^https://gps\.naviafri\.com/?$~i', (string)($data['endpoint']??''))) $data['endpoint']='https://gps.naviafri.com/api/api.php';
    return array_merge(['endpoint'=>'https://gps.naviafri.com/api/api.php','command_parameter'=>'cmd','list_command'=>'list','username'=>'','password'=>''],$data);
}

function pilot_save_settings(array $settings): void
{
    [$keyPath,$settingsPath]=pilot_secure_paths();
    if (!is_file($keyPath)) {
        if (file_put_contents($keyPath,random_bytes(32),LOCK_EX)!==32) throw new RuntimeException('Could not create the tracking API encryption key.');
        @chmod($keyPath,0600);
    }
    $key=file_get_contents($keyPath);
    if (!is_string($key)||strlen($key)!==32) throw new RuntimeException('The tracking API encryption key is invalid.');
    $iv=random_bytes(12);$tag='';$payload=json_encode($settings,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
    $cipher=openssl_encrypt($payload,'aes-256-gcm',$key,OPENSSL_RAW_DATA,$iv,$tag,'fleet-pilot-settings-v1');
    if (!is_string($cipher)) throw new RuntimeException('Could not encrypt the tracking API settings.');
    $box=json_encode(['iv'=>base64_encode($iv),'tag'=>base64_encode($tag),'ciphertext'=>base64_encode($cipher)],JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
    if (file_put_contents($settingsPath,$box,LOCK_EX)===false) throw new RuntimeException('Could not save tracking API settings securely.');
    @chmod($settingsPath,0600);
}

function pilot_validate_settings(array $input, array $current): array
{
    $endpoint=trim((string)($input['endpoint']??$current['endpoint']));
    // The bare host serves the web site; Naviafri's documented JSON API route is /api/api.php.
    if (preg_match('~^https://gps\.naviafri\.com/?$~i', $endpoint)) $endpoint='https://gps.naviafri.com/api/api.php';
    $parts=parse_url($endpoint);
    $host=strtolower((string)($parts['host']??''));
    $allowedHost=$host==='naviafri.com'||str_ends_with($host,'.naviafri.com')||$host==='pilot-gps.com'||str_ends_with($host,'.pilot-gps.com');
    if (!$parts||strtolower((string)($parts['scheme']??''))!=='https'||!$allowedHost||isset($parts['user'])||isset($parts['pass'])||isset($parts['fragment'])) {
        throw new InvalidArgumentException('Use an HTTPS API endpoint hosted by Naviafri or PILOT GPS. Do not include a username or password in the URL.');
    }
    $param=trim((string)($input['command_parameter']??$current['command_parameter']));
    $command=trim((string)($input['list_command']??$current['list_command']));
    if (!preg_match('/^[A-Za-z][A-Za-z0-9_]{0,40}$/',$param)||!preg_match('/^[A-Za-z0-9_-]{1,80}$/',$command)) {
        throw new InvalidArgumentException('The command parameter or vehicle-list command is invalid.');
    }
    $username=trim((string)($input['username']??$current['username']));
    $password=(string)($input['password']??'');
    if ($password==='') $password=(string)$current['password'];
    if ($username===''||$password==='') throw new InvalidArgumentException('Enter the API username and password before testing or saving.');
    if (strlen($username)>255||strlen($password)>1000) throw new InvalidArgumentException('The API username or password is too long.');
    return ['endpoint'=>rtrim($endpoint,'/'),'command_parameter'=>$param,'list_command'=>$command,'username'=>$username,'password'=>$password];
}

function pilot_test_connection(array $settings): array
{
    $url=$settings['endpoint'];
    $query=parse_url($url,PHP_URL_QUERY);
    parse_str(is_string($query)?$query:'',$params);
    unset($params[$settings['command_parameter']]);
    $params[$settings['command_parameter']]=$settings['list_command'];
    $base=preg_replace('/\?.*$/','',$url);
    $url=$base.'?'.http_build_query($params,'','&',PHP_QUERY_RFC3986);
    if (!function_exists('curl_init')) throw new RuntimeException('PHP cURL is not enabled. Enable the cURL extension and restart Apache.');
    $ch=curl_init($url);
    curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>12,CURLOPT_TIMEOUT=>30,CURLOPT_HTTPAUTH=>CURLAUTH_BASIC,CURLOPT_USERPWD=>$settings['username'].':'.$settings['password'],CURLOPT_HTTPHEADER=>['Accept: application/json'],CURLOPT_FOLLOWLOCATION=>false,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2]);
    $raw=curl_exec($ch);$errno=curl_errno($ch);$error=curl_error($ch);$status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);curl_close($ch);
    if (!is_string($raw)) throw new RuntimeException('No HTTP response was received (cURL '.$errno.'). Check PHP cURL, TLS certificates, network and proxy settings.'.($error!==''?' Details: '.mb_substr($error,0,180):''));
    if ($status===401) throw new RuntimeException('The API endpoint is responding, but Naviafri/PILOT rejected the Basic Auth credentials (HTTP 401). The website login may not be enabled for API use. Ask Naviafri to enable API access and confirm the API username and password for your account.');
    if ($status===403) throw new RuntimeException('The API endpoint is responding, but this account is not allowed to use the requested API (HTTP 403). Ask Naviafri to enable vehicle-list API access for this account.');
    if ($status<200||$status>=300) throw new RuntimeException('The API returned HTTP '.$status.'. Confirm the API endpoint and command with Naviafri, then check the API credentials.');
    $data=json_decode($raw,true);
    if (!is_array($data)) throw new RuntimeException('The endpoint responded, but did not return JSON. Confirm the API endpoint path.');
    $vehicles=null;
    foreach (['list','vehicles','data','results'] as $key) if (isset($data[$key])&&is_array($data[$key])) {$vehicles=$data[$key];break;}
    if ($vehicles===null&&array_is_list($data)) $vehicles=$data;
    if ($vehicles===null) {
        $message=(string)($data['msg']??$data['message']??$data['error']??'');
        throw new RuntimeException('The endpoint returned JSON but no recognized vehicle list. '.($message!==''?'API says: '.mb_substr($message,0,180):'Confirm the enabled list command and response format with Naviafri.'));
    }
    return ['ok'=>true,'http_status'=>$status,'vehicle_count'=>count($vehicles),'message'=>'Connection succeeded; a vehicle list was returned.','response_keys'=>array_slice(array_map('strval',array_keys($data)),0,20)];
}

try {
    if (($_SERVER['REQUEST_METHOD']??'GET')==='GET') {
        $settings=pilot_load_settings();
        json_response(['ok'=>true,'configured'=>$settings['username']!==''&&$settings['password']!=='','endpoint'=>$settings['endpoint'],'command_parameter'=>$settings['command_parameter'],'list_command'=>$settings['list_command'],'username'=>$settings['username']]);
    }
    if (($_SERVER['REQUEST_METHOD']??'')!=='POST'||!fleet_ai_same_origin_request()||(string)($_SERVER['HTTP_X_REQUESTED_WITH']??'')!=='XMLHttpRequest') json_response(['ok'=>false,'message'=>'Request was not accepted. Open Settings from this FleetDesk site.'],405);
    $input=json_decode((string)file_get_contents('php://input'),true);
    if (!is_array($input)) json_response(['ok'=>false,'message'=>'Invalid tracking API settings request.'],400);
    $current=pilot_load_settings();
    $settings=pilot_validate_settings($input,$current);
    $action=(string)($input['action']??'test');
    if ($action==='save') {
        pilot_save_settings($settings);
        json_response(['ok'=>true,'message'=>'Tracking API settings saved encrypted outside the web directory.']);
    }
    if ($action!=='test') json_response(['ok'=>false,'message'=>'Unknown settings action.'],400);
    json_response(pilot_test_connection($settings));
} catch (InvalidArgumentException $e) {
    json_response(['ok'=>false,'message'=>$e->getMessage()],400);
} catch (Throwable $e) {
    error_log('Fleet tracking API settings: '.$e->getMessage());
    json_response(['ok'=>false,'message'=>$e->getMessage()],500);
}
