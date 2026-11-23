<?php
declare(strict_types=1);

function fleet_google_oauth_paths(): array {
    $root=realpath(dirname(__DIR__));$dir=realpath(dirname(GOOGLE_CREDENTIALS_FILE));
    if(!$root||!$dir||!is_dir($dir)||!is_writable($dir))throw new RuntimeException('The secure Google credential directory is unavailable.');
    if(str_starts_with(strtolower(rtrim($dir,DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR),strtolower(rtrim($root,DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR)))throw new RuntimeException('Google OAuth credentials must be stored outside the public application directory.');
    return [$dir.'/fleet-google-oauth.key',$dir.'/fleet-google-oauth.enc'];
}
function fleet_google_oauth_valid_redirect_uri(string $uri): bool {
    if(!filter_var($uri,FILTER_VALIDATE_URL))return false;
    $parts=parse_url($uri);if(!is_array($parts)||isset($parts['user'])||isset($parts['pass'])||empty($parts['host']))return false;
    $scheme=strtolower((string)($parts['scheme']??''));$host=strtolower(trim((string)$parts['host'],'[]'));
    if($scheme==='https')return true;
    return $scheme==='http'&&in_array($host,['localhost','127.0.0.1','::1'],true);
}
function fleet_google_oauth_secret(bool $createKey=false,string $newSecret=''): string {
    [$keyPath,$settingsPath]=fleet_google_oauth_paths();
    if(!is_file($keyPath)){if(!$createKey)return '';if(file_put_contents($keyPath,random_bytes(32),LOCK_EX)!==32)throw new RuntimeException('Could not create the Google OAuth encryption key.');@chmod($keyPath,0600);}
    $key=file_get_contents($keyPath);if(!is_string($key)||strlen($key)!==32)throw new RuntimeException('Google OAuth encryption key is invalid.');
    if($newSecret!==''){$iv=random_bytes(12);$tag='';$cipher=openssl_encrypt($newSecret,'aes-256-gcm',$key,OPENSSL_RAW_DATA,$iv,$tag,'fleet-google-oauth-v1');if(!is_string($cipher))throw new RuntimeException('Could not encrypt the Google OAuth client secret.');$box=json_encode(['iv'=>base64_encode($iv),'tag'=>base64_encode($tag),'ciphertext'=>base64_encode($cipher)],JSON_THROW_ON_ERROR);if(file_put_contents($settingsPath,$box,LOCK_EX)===false)throw new RuntimeException('Could not securely save the Google OAuth client secret.');@chmod($settingsPath,0600);return $newSecret;}
    if(!is_file($settingsPath))return '';$box=json_decode((string)file_get_contents($settingsPath),true);if(!is_array($box))throw new RuntimeException('Saved Google OAuth secret is invalid.');$iv=base64_decode((string)($box['iv']??''),true);$tag=base64_decode((string)($box['tag']??''),true);$cipher=base64_decode((string)($box['ciphertext']??''),true);if($iv===false||$tag===false||$cipher===false)throw new RuntimeException('Saved Google OAuth secret is malformed.');$plain=openssl_decrypt($cipher,'aes-256-gcm',$key,OPENSSL_RAW_DATA,$iv,$tag,'fleet-google-oauth-v1');if(!is_string($plain))throw new RuntimeException('Could not decrypt the Google OAuth client secret.');return $plain;
}
