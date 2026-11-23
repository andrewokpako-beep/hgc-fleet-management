<?php
/**
 * Fleet Management — read-only reconciliation dry run.
 * Reads immutable staged row versions for sync runs 1 and 2.
 * Writes ONLY local JSON/Markdown reports under reports/reconciliation/.
 * Never writes to MySQL, Google Sheets, assets, identifiers, attachments,
 * yard history, tracking tables, or reconciliation decision tables.
 *
 * Run from project root:
 *   C:\\xampp\\php\\php.exe google\\reconcile-staged.php
 * Optional run IDs:
 *   C:\\xampp\\php\\php.exe google\\reconcile-staged.php 1,2
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

require_once __DIR__ . '/../config/config.php';

function reconPdo(): PDO
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

function reconNorm(string $value): string
{
    return strtoupper((string)preg_replace('/[^A-Z0-9]/', '', trim($value)));
}

function reconCell(array $row, int $index): string
{
    if (!array_key_exists($index, $row) || is_array($row[$index]) || is_object($row[$index])) {
        return '';
    }
    return trim((string)$row[$index]);
}

function reconLooksLikeVin(string $value): bool
{
    $v = reconNorm($value);
    return strlen($v) === 17 && preg_match('/^[A-HJ-NPR-Z0-9]{17}$/', $v) === 1;
}

function reconLooksLikeIt(string $value): bool
{
    return preg_match('/^IT\s*[- ]?\s*\d{3,}$/i', trim($value)) === 1;
}

function reconAdd(array &$observations, array &$issues, array $meta, string $raw, string $assetRole, string $field): void
{
    $raw = trim($raw);
    if ($raw === '') {
        return;
    }
    $norm = reconNorm($raw);
    $observations[] = [
        'source_code' => $meta['source_code'],
        'sync_run_id' => (int)$meta['sync_run_id'],
        'sheet_name' => $meta['sheet_name'],
        'sheet_id' => (int)$meta['sheet_id'],
        'source_row_number' => (int)$meta['source_row_number'],
        'column_index' => $meta['column_index'],
        'column_letter' => $meta['column_letter'],
        'field' => $field,
        'asset_role' => $assetRole,
        'raw_value' => $raw,
        'normalized_value' => $norm,
        'classification' => $meta['classification'] ?? 'CANDIDATE',
    ];
}

function reconCol(int $zeroBased): string
{
    $n = $zeroBased + 1;
    $s = '';
    while ($n > 0) {
        $r = ($n - 1) % 26;
        $s = chr(65 + $r) . $s;
        $n = intdiv($n - 1, 26);
    }
    return $s;
}

function reconMarkdown(array $report): string
{
    $s = [];
    $s[] = '# Fleet Management — Reconciliation Dry-Run Report';
    $s[] = '';
    $s[] = '- Generated: ' . $report['generated_at'];
    $s[] = '- Sync runs: ' . implode(', ', $report['sync_run_ids']);
    $s[] = '- Mode: READ ONLY';
    $s[] = '- Production writes: NONE';
    $s[] = '';
    $s[] = '## Executive summary';
    $s[] = '';
    foreach ($report['summary'] as $k => $v) {
        $s[] = '- ' . $k . ': **' . $v . '**';
    }
    $s[] = '';
    $s[] = '## Findings requiring attention';
    $s[] = '';
    foreach ($report['findings'] as $f) {
        $s[] = '- **' . $f['severity'] . ' — ' . $f['title'] . '**: ' . $f['detail'];
    }
    $s[] = '';
    $s[] = '## Worksheet interpretation';
    $s[] = '';
    foreach ($report['worksheet_notes'] as $n) {
        $s[] = '- ' . $n;
    }
    $s[] = '';
    $s[] = '## Exact cross-source identifier overlaps';
    $s[] = '';
    if (!$report['cross_source_overlaps']) {
        $s[] = 'No exact normalized cross-source overlaps were detected among the configured asset observations.';
    } else {
        foreach ($report['cross_source_overlaps'] as $g) {
            $s[] = '- `' . $g['normalized_value'] . '` (' . $g['asset_role'] . '): ' . implode('; ', $g['observations']);
        }
    }
    $s[] = '';
    $s[] = '## Repeated identifier observations';
    $s[] = '';
    if (!$report['repeated_identifiers']) {
        $s[] = 'No repeated normalized identifiers were found in the configured asset observations.';
    } else {
        foreach ($report['repeated_identifiers'] as $g) {
            $s[] = '- `' . $g['normalized_value'] . '` (' . $g['asset_role'] . ', ' . $g['observation_count'] . ' observations): ' . implode('; ', $g['observations']);
        }
    }
    $s[] = '';
    $s[] = '## Limitations and safeguards';
    $s[] = '';
    foreach ($report['limitations'] as $n) {
        $s[] = '- ' . $n;
    }
    $s[] = '';
    $s[] = '## Provenance detail';
    $s[] = '';
    $s[] = 'Full row-level observations are in the companion JSON report. Each observation includes source, sync run, worksheet, original source row, column, raw value, normalized value, and asset role.';
    $s[] = '';
    return implode("\n", $s);
}

try {
    $runIds = [1, 2];
    if (isset($argv[1]) && trim($argv[1]) !== '') {
        $ids = array_values(array_unique(array_filter(array_map('intval', explode(',', $argv[1])), static function ($v) { return $v > 0; })));
        if (!$ids) {
            throw new InvalidArgumentException('Supply positive run IDs, e.g. 1,2.');
        }
        $runIds = $ids;
    }

    $pdo = reconPdo();
    $marks = implode(',', array_fill(0, count($runIds), '?'));
    $runStmt = $pdo->prepare("SELECT r.id, r.source_id, r.run_status, r.rows_read, src.source_code
                              FROM spreadsheet_sync_runs r
                              JOIN spreadsheet_sources src ON src.id = r.source_id
                              WHERE r.id IN ($marks) ORDER BY r.id");
    $runStmt->execute($runIds);
    $runs = $runStmt->fetchAll();
    if (count($runs) !== count($runIds)) {
        throw new RuntimeException('One or more requested sync runs were not found.');
    }
    foreach ($runs as $run) {
        if ($run['run_status'] !== 'SUCCESS') {
            throw new RuntimeException('Sync run ' . $run['id'] . ' is not SUCCESS; reconciliation stopped.');
        }
    }

    $sql = "SELECT v.sync_run_id, v.sheet_id, v.source_row_number, v.raw_data,
                   src.source_code, sh.sheet_name
            FROM spreadsheet_source_row_versions v
            JOIN spreadsheet_sync_runs r ON r.id = v.sync_run_id
            JOIN spreadsheet_sources src ON src.id = r.source_id
            JOIN spreadsheet_sheets sh ON sh.id = v.sheet_id
            WHERE v.sync_run_id IN ($marks)
            ORDER BY v.sync_run_id, sh.sheet_name, v.source_row_number";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($runIds);

    $observations = [];
    $findings = [];
    $worksheetCounts = [];
    $registeredPairs = [];
    $tracking = [];
    $rawRowsRead = 0;
    $ignoredDashboardRows = 0;
    $ignoredNonIdentifierCells = 0;
    $emptyOrHeaderRows = 0;

    while ($record = $stmt->fetch()) {
        $rawRowsRead++;
        $row = json_decode($record['raw_data'], true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($row)) { $row = []; }
        $sheet = $record['sheet_name'];
        $run = (int)$record['sync_run_id'];
        $source = $record['source_code'];
        $worksheetCounts[$run . '|' . $sheet] = ($worksheetCounts[$run . '|' . $sheet] ?? 0) + 1;
        $meta = [
            'source_code' => $source,
            'sync_run_id' => $run,
            'sheet_name' => $sheet,
            'sheet_id' => $record['sheet_id'],
            'source_row_number' => $record['source_row_number'],
        ];

        // Dashboard sheets are explicitly excluded from asset identity extraction.
        if (strcasecmp($sheet, 'DASHBOARD') === 0) {
            $ignoredDashboardRows++;
            continue;
        }

        if ($source === 'CONSOLIDATED_TRUCKS' && in_array($sheet, ['Ngwerere_Tankers_status', 'Ngwerere_Flatbeds_Status'], true)) {
            // Row 1 is the section title; row 2 is the header; records begin at row 3.
            if ((int)$record['source_row_number'] < 3) { $emptyOrHeaderRows++; continue; }
            $isTanker = $sheet === 'Ngwerere_Tankers_status';
            $head = reconCell($row, 2);
            $trailer = reconCell($row, 3);
            $make = reconCell($row, 1);
            $status = reconCell($row, 4);
            foreach ([[2, $head, 'HEAD', 'truck_registration'], [3, $trailer, 'TRAILER', $isTanker ? 'tanker_registration' : 'flatbed_registration']] as $item) {
                [$idx, $value, $role, $field] = $item;
                if ($value !== '' && preg_match('/^[A-Z0-9]{5,15}$/i', reconNorm($value))) {
                    $m = $meta + ['column_index' => $idx, 'column_letter' => reconCol($idx), 'classification' => 'REGISTERED_SOURCE'];
                    reconAdd($observations, $findings, $m, $value, $role, $field);
                } elseif ($value !== '') { $ignoredNonIdentifierCells++; }
            }
            continue;
        }

        if ($source === 'CONSOLIDATED_TRUCKS' && $sheet === 'Nakonde_Tankers_unregistered') {
            if ((int)$record['source_row_number'] < 2) { $emptyOrHeaderRows++; continue; }
            $head = reconCell($row, 0); $trailer = reconCell($row, 1);
            foreach ([[0, $head, 'HEAD', 'vin'], [1, $trailer, 'TRAILER', 'tanker_trailer_vin']] as $item) {
                [$idx, $value, $role, $field] = $item;
                if (reconLooksLikeVin($value)) {
                    $m = $meta + ['column_index' => $idx, 'column_letter' => reconCol($idx), 'classification' => 'UNREGISTERED_SOURCE'];
                    reconAdd($observations, $findings, $m, $value, $role, $field);
                } elseif ($value !== '') { $ignoredNonIdentifierCells++; }
            }
            continue;
        }

        if ($source === 'CONSOLIDATED_TRUCKS' && $sheet === 'Nakonde_Flatbed_unregistered') {
            if ((int)$record['source_row_number'] < 2) { $emptyOrHeaderRows++; continue; }
            foreach ([[0, 'HEAD', 'vin_head'], [1, 'TRAILER', 'attached_flatbed_trailer'], [2, 'TRAILER', 'unattached_trailer_1'], [3, 'TRAILER', 'unattached_trailer_2']] as $spec) {
                [$idx, $role, $field] = $spec;
                $value = reconCell($row, $idx);
                if ($value === '') { continue; }
                if (reconLooksLikeVin($value)) {
                    $m = $meta + ['column_index' => $idx, 'column_letter' => reconCol($idx), 'classification' => 'UNREGISTERED_SOURCE'];
                    reconAdd($observations, $findings, $m, $value, $role, $field);
                } else { $ignoredNonIdentifierCells++; }
            }
            continue;
        }

        if ($source === 'NGWERERE_21MILES' && $sheet === 'Registered_21Miles') {
            // Row 1 titles, row 2 headers, data begins at row 3. Two side-by-side lists.
            if ((int)$record['source_row_number'] < 3) { $emptyOrHeaderRows++; continue; }
            $leftHead = reconCell($row, 0); $leftTrailer = reconCell($row, 1);
            $rightHead = reconCell($row, 3); $rightTrailer = reconCell($row, 4);
            foreach ([[0,$leftHead,'HEAD','registered_tanker_horse','TANKER_SECTION'],[1,$leftTrailer,'TRAILER','registered_tanker_trailer','TANKER_SECTION'],[3,$rightHead,'HEAD','registered_flatbed_horse','FLATBED_SECTION'],[4,$rightTrailer,'TRAILER','registered_flatbed_trailer','FLATBED_SECTION']] as $spec) {
                [$idx,$value,$role,$field,$section] = $spec;
                if ($value !== '' && preg_match('/^[A-Z0-9]{5,15}$/i', reconNorm($value))) {
                    $m = $meta + ['column_index'=>$idx,'column_letter'=>reconCol($idx),'classification'=>'REGISTERED_SOURCE'];
                    reconAdd($observations, $findings, $m, $value, $role, $field);
                } elseif ($value !== '' && !preg_match('/^[.\-]+$/', $value)) { $ignoredNonIdentifierCells++; }
            }
            $leftHeadValid = $leftHead !== '' && preg_match('/^[A-Z0-9]{5,15}$/i', reconNorm($leftHead)) === 1;
            $rightHeadValid = $rightHead !== '' && preg_match('/^[A-Z0-9]{5,15}$/i', reconNorm($rightHead)) === 1;
            $leftTrailerValid = $leftTrailer !== '' && preg_match('/^[A-Z0-9]{5,15}$/i', reconNorm($leftTrailer)) === 1;
            $rightTrailerValid = $rightTrailer !== '' && preg_match('/^[A-Z0-9]{5,15}$/i', reconNorm($rightTrailer)) === 1;
            $registeredPairs[] = [
                'source_row_number' => (int)$record['source_row_number'],
                'tanker_head' => $leftHead, 'tanker_trailer' => $leftTrailer,
                'flatbed_head' => $rightHead, 'flatbed_trailer' => $rightTrailer,
                'head_comparable' => ($leftHeadValid && $rightHeadValid),
                'trailer_comparable' => ($leftTrailerValid && $rightTrailerValid),
                'head_match' => ($leftHeadValid && $rightHeadValid) ? (reconNorm($leftHead) === reconNorm($rightHead)) : null,
                'trailer_match' => ($leftTrailerValid && $rightTrailerValid) ? (reconNorm($leftTrailer) === reconNorm($rightTrailer)) : null,
            ];
            continue;
        }

        if ($source === 'NGWERERE_21MILES' && $sheet === 'Ngwerere') {
            if ((int)$record['source_row_number'] < 2) { $emptyOrHeaderRows++; continue; }
            $object = reconCell($row, 0); $status = reconCell($row, 1); $geofence = reconCell($row, 2);
            if ($object !== '') {
                $tracking[] = [
                    'source_code'=>$source,'sync_run_id'=>$run,'sheet_name'=>$sheet,
                    'source_row_number'=>(int)$record['source_row_number'],
                    'tracking_object_raw'=>$object,'tracking_object_normalized'=>reconNorm($object),
                    'status'=>$status,'geofence'=>$geofence,
                ];
            }
            continue;
        }

        // One-column IT lists. Keep source observations separate even if IDs repeat.
        if (($source === 'CONSOLIDATED_TRUCKS' && in_array($sheet, ['21Miles_Tankers_Unregistered','21Miles_Flatbed_Unregistered'], true))
            || ($source === 'NGWERERE_21MILES' && in_array($sheet, ['Unregistered_21Miles','Unregistered_21Miles_NoTrailer'], true))) {
            if ((int)$record['source_row_number'] < 2) { $emptyOrHeaderRows++; continue; }
            $value = reconCell($row, 0);
            if (reconLooksLikeIt($value)) {
                $role = 'IT_REFERENCE'; // IT number only; physical asset role is not proven by this identifier.
                $m = $meta + ['column_index'=>0,'column_letter'=>'A','classification'=>'UNREGISTERED_IT_SOURCE'];
                reconAdd($observations, $findings, $m, $value, $role, 'it_identifier');
            } elseif ($value !== '') { $ignoredNonIdentifierCells++; }
            continue;
        }

        if ($source === 'NGWERERE_21MILES' && $sheet === 'Unregistered_Ngwerere') {
            // This tab has two labeled blocks: flatbed-only IT/VIN values, then horse-only values.
            $rowNo = (int)$record['source_row_number'];
            $value = reconCell($row, 0);
            if ($rowNo >= 2 && $rowNo <= 3 && $value !== '') {
                $m = $meta + ['column_index'=>0,'column_letter'=>'A','classification'=>'UNREGISTERED_SOURCE'];
                reconAdd($observations, $findings, $m, $value, 'TRAILER', 'flatbed_without_head_identifier');
            } elseif ($rowNo >= 7 && $value !== '') {
                $m = $meta + ['column_index'=>0,'column_letter'=>'A','classification'=>'UNREGISTERED_SOURCE'];
                reconAdd($observations, $findings, $m, $value, 'HEAD', 'horse_without_trailer_identifier');
            } else { $emptyOrHeaderRows++; }
            continue;
        }
    }

    // Exact repeats grouped by role. A repeat is evidence for review, not a merge instruction.
    $groups = [];
    foreach ($observations as $o) {
        if ($o['normalized_value'] === '') { continue; }
        $key = $o['asset_role'] . '|' . $o['normalized_value'];
        $groups[$key][] = $o;
    }
    $repeated = []; $crossSource = [];
    foreach ($groups as $key => $items) {
        if (count($items) < 2) { continue; }
        [$role, $norm] = explode('|', $key, 2);
        $sourceCodes = array_values(array_unique(array_column($items, 'source_code')));
        $details = array_map(static function ($o) {
            return $o['source_code'] . '/' . $o['sheet_name'] . '/row ' . $o['source_row_number'] . ' col ' . $o['column_letter'] . ' [' . $o['raw_value'] . ']';
        }, $items);
        $entry = ['normalized_value'=>$norm,'asset_role'=>$role,'observation_count'=>count($items),'source_codes'=>$sourceCodes,'observations'=>$details];
        $repeated[] = $entry;
        if (count($sourceCodes) > 1) { $crossSource[] = $entry; }
    }

    // Registered 21 Miles paired sections: only report rows where the two lists disagree.
    $pairDisagreements = array_values(array_filter($registeredPairs, static function ($p) {
        return ($p['head_match'] === false) || ($p['trailer_match'] === false);
    }));
    $pairIncomplete = array_values(array_filter($registeredPairs, static function ($p) {
        return !$p['head_comparable'] || !$p['trailer_comparable'];
    }));
    if ($pairDisagreements) {
        foreach ($pairDisagreements as $p) {
            $findings[] = [
                'severity'=>'HIGH',
                'title'=>'Registered_21Miles side-by-side pair disagreement at source row ' . $p['source_row_number'],
                'detail'=>'Tanker section: ' . $p['tanker_head'] . ' / ' . $p['tanker_trailer'] . '; flatbed section: ' . $p['flatbed_head'] . ' / ' . $p['flatbed_trailer'] . '. Preserve both observations and require human confirmation.',
            ];
        }
    }
    foreach ($pairIncomplete as $p) {
        $findings[] = [
            'severity'=>'INFO',
            'title'=>'Registered_21Miles incomplete comparison at source row ' . $p['source_row_number'],
            'detail'=>'At least one side contains a blank or placeholder rather than a comparable identifier. Raw values are preserved; no disagreement is asserted. Tanker section: ' . $p['tanker_head'] . ' / ' . $p['tanker_trailer'] . '; flatbed section: ' . $p['flatbed_head'] . ' / ' . $p['flatbed_trailer'] . '.',
        ];
    }

    // Repeated VINs used in more than one role are a relationship/identity review item.
    $roleByNorm = [];
    foreach ($observations as $o) {
        if ($o['asset_role'] === 'IT_REFERENCE') { continue; }
        $roleByNorm[$o['normalized_value']][$o['asset_role']][] = $o;
    }
    foreach ($roleByNorm as $norm => $roles) {
        if (count($roles) > 1) {
            $roleNames = implode(', ', array_keys($roles));
            $findings[] = ['severity'=>'HIGH','title'=>'Identifier observed in multiple asset roles: ' . $norm,
                'detail'=>'The same normalized value occurs as ' . $roleNames . '. This may be a source duplication or data-quality issue; do not auto-merge or infer identity.'];
        }
    }

    // Tracking comparisons are intentionally strict exact normalized matches; no fuzzy suffix matching.
    $assetNorms = [];
    foreach ($observations as $o) { $assetNorms[$o['normalized_value']][] = $o; }
    $trackingExactMatches = [];
    foreach ($tracking as $t) {
        $n = $t['tracking_object_normalized'];
        if (isset($assetNorms[$n])) {
            $trackingExactMatches[] = ['tracking_object'=>$t['tracking_object_raw'],'source_row_number'=>$t['source_row_number'],
                'status'=>$t['status'],'geofence'=>$t['geofence'],'asset_observations'=>count($assetNorms[$n])];
        }
    }

    $findings[] = ['severity'=>'INFO','title'=>'Dashboard rows excluded from identity matching',
        'detail'=>$ignoredDashboardRows . ' dashboard source rows were excluded; dashboard totals are not treated as an asset register.'];
    $findings[] = ['severity'=>'INFO','title'=>'Independent 21 Miles IT lists preserved',
        'detail'=>'The two independently maintained source lists remain separate observations. Repeated IT values are not treated as duplicate physical assets or merged records.'];
    $findings[] = ['severity'=>'INFO','title'=>'Tracking treated as observation-only',
        'detail'=>count($tracking) . ' tracking objects were extracted. Only exact normalized identifier matches are reported; no yard assignment or permanent asset identity is inferred from geofence text.'];

    $report = [
        'report_type'=>'fleet_management_reconciliation_dry_run',
        'generated_at'=>date('c'),
        'sync_run_ids'=>$runIds,
        'run_metadata'=>$runs,
        'read_only'=>true,
        'production_writes'=>false,
        'database_writes'=>false,
        'google_sheets_writes'=>false,
        'raw_staged_rows_read'=>$rawRowsRead,
        'summary'=>[
            'Raw staged rows read'=>$rawRowsRead,
            'Asset identifier observations'=>count($observations),
            'Distinct normalized identifier + role groups'=>count($groups),
            'Repeated identifier groups'=>count($repeated),
            'Exact cross-source repeated groups'=>count($crossSource),
            'Tracking observations'=>count($tracking),
            'Exact tracking-to-asset identifier matches'=>count($trackingExactMatches),
            'Registered 21 Miles paired rows examined'=>count($registeredPairs),
            'Registered 21 Miles paired-row disagreements'=>count($pairDisagreements),
            'Registered 21 Miles incomplete paired rows'=>count($pairIncomplete),
            'Dashboard rows excluded'=>$ignoredDashboardRows,
            'Non-identifier cells ignored'=>$ignoredNonIdentifierCells,
        ],
        'findings'=>$findings,
        'worksheet_notes'=>[
            'Ngwerere_Tankers_status: column C is truck registration, column D is tanker registration; column B is make and column E is comment/status.',
            'Ngwerere_Flatbeds_Status: column C is truck registration, column D is flatbed registration; column B is make and column E is comment/status.',
            'Nakonde_Tankers_unregistered: columns A/B are head/trailer VIN candidates; yard text in column D is contextual only.',
            'Nakonde_Flatbed_unregistered: columns A-D are head, attached trailer, unattached trailer 1, unattached trailer 2; column E is yard text. Only VIN-shaped values are extracted.',
            'Registered_21Miles: A:B and D:E are separate tanker and flatbed sections. Their row-wise differences are review cases, not automatic corrections.',
            '21 Miles one-column IT sheets are classified as IT_REFERENCE only; the identifier does not establish a head/trailer role or permanent asset identity. Independently maintained lists remain separate source observations.',
            'Ngwerere tracking sheet: column A object label, B status, C geofence. Status/geofence are time-specific observations.',
            'Dashboard and Breakdown do not create asset identities; Breakdown was empty in the verified snapshot.',
        ],
        'observations'=>$observations,
        'repeated_identifiers'=>$repeated,
        'cross_source_overlaps'=>$crossSource,
        'tracking_observations'=>$tracking,
        'tracking_exact_matches'=>$trackingExactMatches,
        'registered_21miles_pairs'=>$registeredPairs,
        'limitations'=>[
            'This is a source-to-source observation analysis only. It does not compare against production assets because no production fleet assets have been imported.',
            'A normalized exact match is a candidate relationship, not proof that two source rows describe the same physical asset.',
            'No fuzzy matching, automatic asset merging, attachment creation, yard assignment, or status update is performed.',
            'The Nakonde flatbed sheet contains two separate unattached-trailer columns; those values are independent trailer candidates, not confirmed attachments.',
            'The Registered_21Miles side-by-side lists contain a known row-level conflict; all disagreements remain for human review.',
            'The source sheets do not establish that every IT number is a permanent unique asset identifier or prove a physical head/trailer role. All one-column IT values use the neutral IT_REFERENCE role; source worksheet remains the context. Repeated IT values across independent lists are not duplicate-asset conclusions.',
            'The local output files are reports only; the database connection is used for SELECT statements against sync metadata and staged raw rows.',
        ],
    ];

    $outDir = dirname(__DIR__) . '/reports/reconciliation';
    if (!is_dir($outDir) && !mkdir($outDir, 0775, true) && !is_dir($outDir)) {
        throw new RuntimeException('Unable to create local report directory: ' . $outDir);
    }
    $stamp = date('Ymd_His');
    $jsonPath = $outDir . '/reconciliation_dry_run_' . $stamp . '.json';
    $mdPath = $outDir . '/reconciliation_dry_run_' . $stamp . '.md';
    file_put_contents($jsonPath, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    file_put_contents($mdPath, reconMarkdown($report));

    echo "FLEET MANAGEMENT — RECONCILIATION DRY RUN\n";
    echo "READ ONLY: SELECT queries only; no MySQL, Google Sheets, or production asset writes.\n";
    echo 'Sync runs: ' . implode(', ', $runIds) . "\n\n";
    echo "SUMMARY\n";
    foreach ($report['summary'] as $label => $value) { printf("%-48s %s\n", $label . ':', $value); }
    echo "\nFINDINGS\n";
    foreach ($findings as $f) { echo '[' . $f['severity'] . '] ' . $f['title'] . "\n  " . $f['detail'] . "\n"; }
    echo "\nREPORT FILES\n";
    echo $mdPath . "\n" . $jsonPath . "\n";
    echo "\nEND. No database or source data was changed.\n";
} catch (Throwable $error) {
    fwrite(STDERR, "RECONCILIATION FAILED: " . $error->getMessage() . "\n");
    exit(1);
}
