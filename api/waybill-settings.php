<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/certificate-auth.php';
$user=fleet_cert_require_user();fleet_cert_require_permission($user,'page.settings');
$db=fleet_db();
$logoPath='C:/xampp/secure/fleet/waybill-brand.jpg';
function waybill_brand_settings(PDO $db):array{$q=$db->prepare('SELECT setting_key,setting_value FROM system_settings WHERE setting_key IN (?,?)');$q->execute(['waybill_company_name','waybill_logo_updated_at']);return $q->fetchAll(PDO::FETCH_KEY_PAIR);}
try{
    if(($_SERVER['REQUEST_METHOD']??'GET')==='GET'){
        if((string)($_GET['action']??'')==='logo'){
            if(!is_file($logoPath)){http_response_code(404);exit;}
            header('Content-Type: image/jpeg');header('Content-Disposition: inline; filename="waybill-logo.jpg"');header('Cache-Control: private, no-store');header('X-Content-Type-Options: nosniff');readfile($logoPath);exit;
        }
        $settings=waybill_brand_settings($db);json_response(['ok'=>true,'company_name'=>(string)($settings['waybill_company_name']??'FleetDesk'),'logo_exists'=>is_file($logoPath),'logo_updated_at'=>(string)($settings['waybill_logo_updated_at']??'')]);
    }
    if(($_SERVER['REQUEST_METHOD']??'')!=='POST')json_response(['ok'=>false,'message'=>'Method not allowed.'],405);
    fleet_cert_require_same_origin_post();
    $name=trim((string)($_POST['company_name']??''));if($name===''||mb_strlen($name)>100)json_response(['ok'=>false,'message'=>'Enter a company name between 1 and 100 characters.'],400);
    $upload=$_FILES['logo']??null;$hasUpload=is_array($upload)&&($upload['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_NO_FILE;
    if($hasUpload){
        if(($upload['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK)json_response(['ok'=>false,'message'=>'The logo upload did not complete. Choose a JPG or PNG image under 5 MB.'],400);
        if((int)$upload['size']<1||(int)$upload['size']>5*1024*1024)json_response(['ok'=>false,'message'=>'Choose a JPG or PNG logo under 5 MB.'],400);
        $mime=(new finfo(FILEINFO_MIME_TYPE))->file($upload['tmp_name']);$image=@getimagesize($upload['tmp_name']);
        if($mime!=='image/jpeg'||!is_array($image)||($image['mime']??'')!=='image/jpeg')json_response(['ok'=>false,'message'=>'The logo could not be prepared as a JPEG image. Please choose a JPG or PNG file again.'],400);
        $width=(int)$image[0];$height=(int)$image[1];if($width<1||$height<1||$width>3000||$height>2000)json_response(['ok'=>false,'message'=>'Logo dimensions must be 3,000 × 2,000 pixels or smaller.'],400);
        $dir=dirname($logoPath);if(!is_dir($dir)&&!mkdir($dir,0700,true)&&!is_dir($dir))json_response(['ok'=>false,'message'=>'Secure logo storage is unavailable.'],500);
        $temp=$dir.'/waybill-brand-'.bin2hex(random_bytes(12)).'.tmp';if(!move_uploaded_file($upload['tmp_name'],$temp))json_response(['ok'=>false,'message'=>'The logo could not be stored securely.'],500);
        if(!@rename($temp,$logoPath)){@unlink($temp);json_response(['ok'=>false,'message'=>'The new logo could not replace the saved logo.'],500);}
    }
    if(filter_var($_POST['remove_logo']??false,FILTER_VALIDATE_BOOLEAN)&&!$hasUpload&&is_file($logoPath)&&!@unlink($logoPath))json_response(['ok'=>false,'message'=>'The saved logo could not be removed.'],500);
    $q=$db->prepare('INSERT INTO system_settings(setting_key,setting_value,description) VALUES(?,?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)');$q->execute(['waybill_company_name',$name,'Company name printed on operational trip waybills.']);if($hasUpload)$q->execute(['waybill_logo_updated_at',date('Y-m-d H:i:s'),'Timestamp of the saved operational waybill logo.']);
    fleet_cert_log($db,(int)$user['id'],'UPDATE','WAYBILL_BRANDING',null,'Updated operational waybill branding.');
    json_response(['ok'=>true,'message'=>'Waybill branding saved.']);
}catch(Throwable $e){error_log('Waybill branding settings: '.$e->getMessage());json_response(['ok'=>false,'message'=>'Waybill branding could not be saved. Check server logs if this continues.'],500);}