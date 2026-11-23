<?php
/**
 * Stage 2 read-only profiler. CLI ONLY to avoid exposing fleet data via HTTP.
 * Run: C:\xampp\php\php.exe google\profile-sheets.php
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Run this profiler from Command Prompt, not a browser.\n");
}

require_once __DIR__ . '/connection.php';
require_once __DIR__ . '/../modules/profiling/extract-sheets.php';
require_once __DIR__ . '/../modules/profiling/normalize-identifiers.php';
require_once __DIR__ . '/../modules/profiling/compare-sources.php';
require_once __DIR__ . '/../modules/profiling/generate-report.php';

$root = dirname(__DIR__);
$snapshotDir = $root . '/logs/profiling';
$reportDir = $root . '/reports/profiling';

foreach ([$snapshotDir, $reportDir] as $dir) {
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('Cannot create directory: ' . $dir);
    }
}

$runId = date('Ymd_His');
echo "Fleet Management - Stage 2 Read-only Profiling\n";
echo "Run ID: {$runId}\n";
echo "Reading Google Sheets. No writes or database imports will occur.\n\n";

try {
    $service = getGoogleSheetsService();
    $profile = extractFleetSheets($service);
    $profile['run_id'] = $runId;
    $profile['started_at'] = date(DATE_ATOM);

    $snapshotPath = $snapshotDir . '/snapshot_' . $runId . '.json';
    $snapshotJson = json_encode($profile, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($snapshotJson === false || file_put_contents($snapshotPath, $snapshotJson . PHP_EOL, LOCK_EX) === false) {
        throw new RuntimeException('Could not save raw snapshot. Check folder permissions.');
    }

    $identifierIndex = buildIdentifierIndex($profile);
    $comparison = compareIdentifierSources($identifierIndex);
    $report = buildProfilingReport($profile, $identifierIndex, $comparison);

    $reportPath = $reportDir . '/profiling_report_' . $runId . '.json';
    $reportJson = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($reportJson === false || file_put_contents($reportPath, $reportJson . PHP_EOL, LOCK_EX) === false) {
        throw new RuntimeException('Could not save report. Check folder permissions.');
    }

    echo "SUCCESS\n";
    echo "Worksheets read: " . count($profile['worksheets']) . "\n";
    echo "Populated rows: " . array_sum(array_column($profile['worksheets'], 'populated_row_count')) . "\n";
    echo "Identifier candidates: " . count($identifierIndex) . "\n";
    echo "Possible duplicate groups: " . count($comparison['duplicate_groups']) . "\n";
    echo "Raw snapshot: logs/profiling/snapshot_{$runId}.json\n";
    echo "Report: reports/profiling/profiling_report_{$runId}.json\n";
    echo "\nNo Google Sheets cells or MySQL records were changed.\n";
} catch (Throwable $e) {
    fwrite(STDERR, "PROFILING FAILED: " . $e->getMessage() . PHP_EOL);
    error_log('[Fleet profiling] ' . $e->getMessage());
    exit(1);
}
