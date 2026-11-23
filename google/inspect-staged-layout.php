<?php
/**
 * Read-only staged worksheet layout inspector.
 *
 * Reads only spreadsheet_source_row_versions and metadata. It does not write
 * to MySQL, Google Sheets, or any production fleet table.
 *
 * Run from project root:
 *   C:\xampp\php\php.exe google\inspect-staged-layout.php
 * Optional run IDs:
 *   C:\xampp\php\php.exe google\inspect-staged-layout.php 1,2
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

require_once __DIR__ . '/../config/config.php';

function fleetInspectorPdo(): PDO
{
    foreach (['DB_HOST', 'DB_NAME', 'DB_USER', 'DB_PASS'] as $constant) {
        if (!defined($constant)) {
            throw new RuntimeException("Missing database configuration constant: {$constant}");
        }
    }

    $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
    return new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
}

function fleetDecodeRow(string $json): array
{
    $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    return is_array($decoded) ? $decoded : [];
}

try {
    $runIds = [1, 2];
    if (isset($argv[1]) && trim($argv[1]) !== '') {
        $parsed = array_filter(array_map('intval', explode(',', $argv[1])), static function ($id) {
            return $id > 0;
        });
        if (!$parsed) {
            throw new InvalidArgumentException('Provide one or more positive sync run IDs, e.g. 1,2.');
        }
        $runIds = array_values(array_unique($parsed));
    }

    $pdo = fleetInspectorPdo();
    $placeholders = implode(',', array_fill(0, count($runIds), '?'));

    $sheetSql = "SELECT r.id AS sync_run_id, src.source_code, sh.id AS sheet_id,
                        sh.sheet_name, COUNT(v.id) AS staged_rows
                 FROM spreadsheet_sync_runs r
                 JOIN spreadsheet_sources src ON src.id = r.source_id
                 JOIN spreadsheet_sheets sh ON sh.source_id = src.id
                 LEFT JOIN spreadsheet_source_row_versions v
                   ON v.sync_run_id = r.id AND v.sheet_id = sh.id
                 WHERE r.id IN ($placeholders)
                 GROUP BY r.id, src.source_code, sh.id, sh.sheet_name
                 ORDER BY r.id, sh.sheet_name";
    $sheetStmt = $pdo->prepare($sheetSql);
    $sheetStmt->execute($runIds);
    $sheets = $sheetStmt->fetchAll();

    $rowSql = "SELECT source_row_number, raw_data
               FROM spreadsheet_source_row_versions
               WHERE sync_run_id = ? AND sheet_id = ?
               ORDER BY source_row_number
               LIMIT 8";
    $rowStmt = $pdo->prepare($rowSql);

    echo "FLEET MANAGEMENT — STAGED LAYOUT INSPECTION\n";
    echo "READ ONLY: no database writes, no Google Sheets writes, no asset-table access.\n";
    echo 'Sync runs: ' . implode(', ', $runIds) . "\n";
    echo str_repeat('=', 78) . "\n";

    foreach ($sheets as $sheet) {
        printf(
            "\nRUN %d | %s | %s | %d staged rows\n",
            (int)$sheet['sync_run_id'],
            $sheet['source_code'],
            $sheet['sheet_name'],
            (int)$sheet['staged_rows']
        );
        echo str_repeat('-', 78) . "\n";

        if ((int)$sheet['staged_rows'] === 0) {
            echo "[EMPTY WORKSHEET]\n";
            continue;
        }

        $rowStmt->execute([(int)$sheet['sync_run_id'], (int)$sheet['sheet_id']]);
        foreach ($rowStmt->fetchAll() as $row) {
            $values = fleetDecodeRow($row['raw_data']);
            printf("Row %d | %s\n", (int)$row['source_row_number'], json_encode(
                array_values($values),
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
            ));
        }
    }

    echo "\nEND OF INSPECTION. No data was changed.\n";
} catch (Throwable $error) {
    fwrite(STDERR, "INSPECTION FAILED: " . $error->getMessage() . "\n");
    exit(1);
}
