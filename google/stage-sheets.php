<?php
/**
 * CLI-only Google Sheets staging importer.
 *
 * Writes immutable raw row versions to spreadsheet_source_row_versions.
 * It does NOT insert/update/delete assets, identifiers, attachments,
 * yard assignments, tracking records, or Google Sheets.
 *
 * Run from the project root:
 *   C:\xampp\php\php.exe google\stage-sheets.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

require_once __DIR__ . '/connection.php';
require_once __DIR__ . '/../modules/profiling/extract-sheets.php';

function fleetStagePdo(): PDO
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

function fleetJsonEncode($value): string
{
    return json_encode(
        $value,
        JSON_UNESCAPED_UNICODE
        | JSON_UNESCAPED_SLASHES
        | JSON_PRESERVE_ZERO_FRACTION
        | JSON_THROW_ON_ERROR
    );
}

/** Compare two immutable sheet snapshots, preferring exact raw-row matches so
 * row-number shifts caused by an insertion or deletion do not look like edits. */
function fleetDiffSourceRows(array $oldRows, array $newRows): array
{
    $oldRemaining = array_values($oldRows);
    $newRemaining = array_values($newRows);
    $newByHash = [];
    foreach ($newRemaining as $i => $row) $newByHash[$row['row_hash']][] = $i;
    $matchedNew = [];
    $matchedOld = [];
    foreach ($oldRemaining as $i => $row) {
        if (empty($newByHash[$row['row_hash']])) continue;
        $j = array_shift($newByHash[$row['row_hash']]);
        $matchedOld[$i] = true;
        $matchedNew[$j] = true;
    }
    $oldLeft = [];
    foreach ($oldRemaining as $i => $row) if (!isset($matchedOld[$i])) $oldLeft[$i] = $row;
    $newLeft = [];
    foreach ($newRemaining as $i => $row) if (!isset($matchedNew[$i])) $newLeft[$i] = $row;

    $events = [];
    $newByNumber = [];
    foreach ($newLeft as $j => $row) $newByNumber[(int)$row['row_number']][] = $j;
    foreach ($oldLeft as $i => $old) {
        $rowNumber = (int)$old['row_number'];
        if (empty($newByNumber[$rowNumber])) continue;
        $j = array_shift($newByNumber[$rowNumber]);
        $new = $newLeft[$j];
        $events[] = ['change_type'=>'ROW_UPDATED','old'=>$old,'new'=>$new];
        unset($oldLeft[$i], $newLeft[$j]);
    }
    foreach ($oldLeft as $old) $events[] = ['change_type'=>'ROW_DELETED','old'=>$old,'new'=>null];
    foreach ($newLeft as $new) $events[] = ['change_type'=>'ROW_ADDED','old'=>null,'new'=>$new];
    return $events;
}

function fleetStageSource(
    PDO $pdo,
    array $source,
    array $worksheets
): array {
    $sourceLookup = $pdo->prepare(
        'SELECT id FROM spreadsheet_sources WHERE source_code = :source_code LIMIT 1'
    );
    $sourceLookup->execute(['source_code' => $source['source_code']]);
    $sourceId = $sourceLookup->fetchColumn();

    if (!$sourceId) {
        throw new RuntimeException(
            'Source is not registered in spreadsheet_sources: ' . $source['source_code']
        );
    }

    $snapshotRows = [];
    foreach ($worksheets as $worksheet) {
        $sheetRows = [];
        foreach ($worksheet['rows'] as $row) {
            $rawJson = fleetJsonEncode(array_values($row['values'] ?? []));
            $sheetRows[] = [(int)($row['source_row_number'] ?? 0), hash('sha256', $rawJson)];
        }
        $snapshotRows[] = [(string)$worksheet['sheet_id'], (string)$worksheet['sheet_name'], $sheetRows];
    }
    $snapshotHash = hash('sha256', fleetJsonEncode($snapshotRows));
    $lastSnapshot = $pdo->prepare(
        "SELECT id, rows_read, snapshot_hash FROM spreadsheet_sync_runs
         WHERE source_id = :source_id AND run_status = 'SUCCESS' AND snapshot_hash IS NOT NULL
         ORDER BY id DESC LIMIT 1"
    );
    $lastSnapshot->execute(['source_id' => $sourceId]);
    $previous = $lastSnapshot->fetch();
    if ($previous && hash_equals((string)$previous['snapshot_hash'], $snapshotHash)) {
        return [
            'source_code' => $source['source_code'],
            'sync_run_id' => (int)$previous['id'],
            'worksheets_staged' => count($worksheets),
            'rows_staged' => (int)$previous['rows_read'],
            'status' => 'UNCHANGED',
        ];
    }

    $startedAt = date('Y-m-d H:i:s');
    $createRun = $pdo->prepare(
        "INSERT INTO spreadsheet_sync_runs
            (source_id, started_at, run_status, triggered_by)
         VALUES (:source_id, :started_at, 'RUNNING', 'CLI_STAGE_IMPORT')"
    );
    $createRun->execute([
        'source_id' => $sourceId,
        'started_at' => $startedAt,
    ]);
    $runId = (int)$pdo->lastInsertId();

    $previousRowsBySheet = [];
    if ($previous) {
        $oldRowsStmt = $pdo->prepare(
            'SELECT sheet_id,source_row_number,raw_data,row_hash FROM spreadsheet_source_row_versions WHERE sync_run_id=? ORDER BY sheet_id,source_row_number'
        );
        $oldRowsStmt->execute([(int)$previous['id']]);
        foreach ($oldRowsStmt->fetchAll() as $oldRow) {
            $previousRowsBySheet[(int)$oldRow['sheet_id']][] = [
                'row_number'=>(int)$oldRow['source_row_number'],
                'raw_data'=>(string)$oldRow['raw_data'],
                'row_hash'=>(string)$oldRow['row_hash'],
            ];
        }
    }

    $rowsStaged = 0;
    $worksheetsStaged = 0;
    $currentRowsBySheet = [];
    $sheetNamesById = [];

    try {
        $pdo->beginTransaction();

        $upsertSheet = $pdo->prepare(
            'INSERT INTO spreadsheet_sheets
                (source_id, sheet_gid, sheet_name)
             VALUES (:source_id, :sheet_gid, :sheet_name)
             ON DUPLICATE KEY UPDATE sheet_name = VALUES(sheet_name)'
        );

        $findSheet = $pdo->prepare(
            'SELECT id FROM spreadsheet_sheets
             WHERE source_id = :source_id AND sheet_gid = :sheet_gid
             LIMIT 1'
        );

        $insertVersion = $pdo->prepare(
            'INSERT INTO spreadsheet_source_row_versions
                (sync_run_id, sheet_id, source_row_key, source_row_number,
                 raw_data, row_hash, captured_at)
             VALUES
                (:sync_run_id, :sheet_id, :source_row_key, :source_row_number,
                 :raw_data, :row_hash, :captured_at)'
        );

        foreach ($worksheets as $worksheet) {
            $upsertSheet->execute([
                'source_id' => $sourceId,
                'sheet_gid' => (string)$worksheet['sheet_id'],
                'sheet_name' => $worksheet['sheet_name'],
            ]);

            $findSheet->execute([
                'source_id' => $sourceId,
                'sheet_gid' => (string)$worksheet['sheet_id'],
            ]);
            $sheetId = (int)$findSheet->fetchColumn();

            if (!$sheetId) {
                throw new RuntimeException(
                    'Unable to resolve database sheet ID for ' . $worksheet['sheet_name']
                );
            }

            $worksheetsStaged++;
            $sheetNamesById[$sheetId] = (string)$worksheet['sheet_name'];

            foreach ($worksheet['rows'] as $row) {
                $rowNumber = (int)$row['source_row_number'];
                if ($rowNumber < 1 || !isset($row['values']) || !is_array($row['values'])) {
                    throw new RuntimeException(
                        'Invalid extracted row in worksheet ' . $worksheet['sheet_name']
                    );
                }

                // The row key is only a location within this source worksheet snapshot.
                // It is not an asset ID and is deliberately scoped to this sync run.
                $rowKey = 'ROW:' . $rowNumber;
                $rawJson = fleetJsonEncode(array_values($row['values']));
                $rowHash = hash('sha256', $rawJson);
                $currentRowsBySheet[$sheetId][] = [
                    'row_number'=>$rowNumber,
                    'raw_data'=>$rawJson,
                    'row_hash'=>$rowHash,
                ];

                $insertVersion->execute([
                    'sync_run_id' => $runId,
                    'sheet_id' => $sheetId,
                    'source_row_key' => $rowKey,
                    'source_row_number' => $rowNumber,
                    'raw_data' => $rawJson,
                    'row_hash' => $rowHash,
                    'captured_at' => date('Y-m-d H:i:s'),
                ]);

                $rowsStaged++;
            }
        }

        $pdo->commit();

        $completeRun = $pdo->prepare(
            "UPDATE spreadsheet_sync_runs
             SET completed_at = :completed_at,
                 run_status = 'SUCCESS',
                 rows_read = :rows_read,
                 rows_valid = :rows_valid,
                 rows_rejected = 0,
                 changes_applied = 0,
                 snapshot_hash = :snapshot_hash,
                 conflicts_created = 0
             WHERE id = :run_id"
        );
        $completeRun->execute([
            'completed_at' => date('Y-m-d H:i:s'),
            'rows_read' => $rowsStaged,
            'rows_valid' => $rowsStaged,
            'snapshot_hash' => $snapshotHash,
            'run_id' => $runId,
        ]);

        if ($previous) {
            $writeChange = $pdo->prepare(
                'INSERT INTO spreadsheet_source_change_log
                    (source_id,sheet_id,previous_sync_run_id,sync_run_id,change_type,
                     previous_source_row_number,source_row_number,previous_data,new_data,detected_at,decision_status)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?)'
            );
            foreach ($worksheets as $worksheet) {
                $sheetIdStmt = $pdo->prepare('SELECT id FROM spreadsheet_sheets WHERE source_id=? AND sheet_gid=? LIMIT 1');
                $sheetIdStmt->execute([$sourceId,(string)$worksheet['sheet_id']]);
                $sheetId = (int)$sheetIdStmt->fetchColumn();
                if (!$sheetId) continue;
                $changes = fleetDiffSourceRows(
                    $previousRowsBySheet[$sheetId] ?? [],
                    $currentRowsBySheet[$sheetId] ?? []
                );
                foreach ($changes as $change) {
                    $old = $change['old']; $new = $change['new'];
                    $writeChange->execute([
                        $sourceId,$sheetId,(int)$previous['id'],$runId,$change['change_type'],
                        $old['row_number'] ?? null,$new['row_number'] ?? null,
                        $old['raw_data'] ?? null,$new['raw_data'] ?? null,date('Y-m-d H:i:s'),
                        $change['change_type']==='ROW_DELETED'?'PENDING':'APPROVED',
                    ]);
                }
            }
        }

        return [
            'source_code' => $source['source_code'],
            'sync_run_id' => $runId,
            'worksheets_staged' => $worksheetsStaged,
            'rows_staged' => $rowsStaged,
            'status' => 'SUCCESS',
        ];
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        $failRun = $pdo->prepare(
            "UPDATE spreadsheet_sync_runs
             SET completed_at = :completed_at,
                 run_status = 'FAILED',
                 error_summary = :error_summary
             WHERE id = :run_id"
        );
        $failRun->execute([
            'completed_at' => date('Y-m-d H:i:s'),
            'error_summary' => substr($error->getMessage(), 0, 60000),
            'run_id' => $runId,
        ]);

        throw $error;
    }
}

try {
    echo "Fleet Management raw-source staging\n";
    echo "Google Sheets access: READ ONLY\n";
    echo "Asset-table writes: DISABLED\n\n";

    $service = getGoogleSheetsService();
    $extracted = extractFleetSheets($service);

    if (empty($extracted['read_only'])) {
        throw new RuntimeException('Extraction did not confirm read-only mode.');
    }

    $pdo = fleetStagePdo();
    $results = [];

    foreach ($extracted['sources'] as $source) {
        $sourceWorksheets = array_values(array_filter(
            $extracted['worksheets'],
            static function (array $worksheet) use ($source): bool {
                return $worksheet['source_code'] === $source['source_code'];
            }
        ));

        $results[] = fleetStageSource($pdo, $source, $sourceWorksheets);
    }

    echo "STAGING SUMMARY\n";
    foreach ($results as $result) {
        printf(
            "%s | run %d | %d worksheets | %d rows | %s\n",
            $result['source_code'],
            $result['sync_run_id'],
            $result['worksheets_staged'],
            $result['rows_staged'],
            $result['status']
        );
    }

    $totalRows = array_sum(array_column($results, 'rows_staged'));
    $totalSheets = array_sum(array_column($results, 'worksheets_staged'));
    printf("\nTOTAL: %d worksheets, %d raw rows staged.\n", $totalSheets, $totalRows);
    echo "No production fleet assets were changed.\n";
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, "STAGING FAILED: " . $error->getMessage() . "\n");
    exit(1);
}
