<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); }
require_once __DIR__ . '/certificate-sheet-sync.php';
require_once __DIR__ . '/../includes/bootstrap.php';
$db = fleet_db();
$result = certificateSheetProcessPending($db);
echo '[' . date('Y-m-d H:i:s') . '] Certificate Sheet sync: ' . $result['synced'] . ' updated, ' . $result['failed'] . ' still pending.' . PHP_EOL;
