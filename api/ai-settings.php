<?php
declare(strict_types=1);
require_once __DIR__.'/ai-common.php';
require_once __DIR__.'/../includes/certificate-auth.php';

$settingsUser = fleet_cert_require_user();
fleet_cert_require_permission($settingsUser,'settings.ai');
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
try {
    if ($method === 'GET') {
        $settings = fleet_ai_load_settings();
        json_response(['ok'=>true,'configured'=>$settings['api_key']!=='','model'=>$settings['model']]);
    }
    if ($method !== 'POST' || !fleet_ai_same_origin_request() || (string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== 'XMLHttpRequest') {
        json_response(['ok'=>false,'message'=>'Request was not accepted. Open Settings from this FleetDesk site.'],405);
    }
    $input = json_decode((string)file_get_contents('php://input'), true);
    if (!is_array($input)) json_response(['ok'=>false,'message'=>'Invalid settings request.'],400);
    $current = fleet_ai_load_settings();
    $apiKey = trim((string)($input['api_key'] ?? ''));
    if ($apiKey === '') $apiKey = $current['api_key'];
    if (strlen($apiKey) < 20 || strlen($apiKey) > 500) json_response(['ok'=>false,'message'=>'Enter a valid OpenAI API key.'],400);
    $model = trim((string)($input['model'] ?? $current['model']));
    if (!preg_match('/^[A-Za-z0-9._-]{2,100}$/', $model)) json_response(['ok'=>false,'message'=>'Enter a valid model ID.'],400);
    fleet_ai_save_settings($apiKey, $model);
    json_response(['ok'=>true,'configured'=>true,'model'=>$model,'message'=>'AI settings saved securely.']);
} catch (Throwable $e) {
    error_log('Fleet AI settings: '.$e->getMessage());
    json_response(['ok'=>false,'message'=>$e->getMessage()],500);
}
