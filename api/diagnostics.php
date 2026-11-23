<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';

try {
    $db = fleet_db();
    $tables = [
        'spreadsheet_sources',
        'spreadsheet_sheets',
        'spreadsheet_sync_runs',
        'spreadsheet_source_rows',
        'spreadsheet_source_row_versions',
        'assets',
        'reconciliation_cases',
    ];
    $present = [];
    foreach ($tables as $table) {
        $present[$table] = table_exists($db, $table);
    }

    $counts = [];
    foreach (['spreadsheet_sources', 'spreadsheet_sheets', 'spreadsheet_sync_runs',
              'spreadsheet_source_rows', 'spreadsheet_source_row_versions',
              'assets', 'reconciliation_cases'] as $table) {
        if ($present[$table]) {
            $counts[$table] = (int)$db->query("SELECT COUNT(*) FROM `$table`")->fetchColumn();
        }
    }

    $runs = [];
    if ($present['spreadsheet_sync_runs'] && $present['spreadsheet_sources']) {
        $runs = $db->query(
            'SELECT r.id, s.source_code, r.run_status, r.rows_read, r.rows_valid,
                    r.rows_rejected, r.started_at, r.completed_at
             FROM spreadsheet_sync_runs r
             INNER JOIN spreadsheet_sources s ON s.id = r.source_id
             ORDER BY r.id DESC LIMIT 10'
        )->fetchAll();
    }

    $versionRowsByRun = [];
    if ($present['spreadsheet_source_row_versions']) {
        $versionRowsByRun = $db->query(
            'SELECT sync_run_id, COUNT(*) AS row_count
             FROM spreadsheet_source_row_versions
             GROUP BY sync_run_id ORDER BY sync_run_id DESC'
        )->fetchAll();
    }

    json_response([
        'ok' => true,
        'database_name' => (string)$db->query('SELECT DATABASE()')->fetchColumn(),
        'tables' => $present,
        'counts' => $counts,
        'recent_sync_runs' => $runs,
        'version_rows_by_sync_run' => $versionRowsByRun,
        'note' => 'Read-only diagnostic. No records were changed.',
    ]);
} catch (Throwable $e) {
    json_response([
        'ok' => false,
        'error_type' => get_class($e),
        'sql_state' => $e instanceof PDOException && isset($e->errorInfo[0]) ? $e->errorInfo[0] : null,
        'message' => 'The diagnostic could not complete. Check that XAMPP MySQL is running and the selected database/schema is accessible.',
    ], 500);
}
