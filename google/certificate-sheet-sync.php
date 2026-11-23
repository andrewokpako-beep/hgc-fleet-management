<?php
declare(strict_types=1);

require_once __DIR__ . '/connection.php';

function certificateSheetColumns(): array
{
    return [
        'CROSS BORDER PERMIT (ZIM)', 'CROSS BORDER PERMIT (MOZ)', 'ZCSA',
        'ELECTRICAL CERTIFICATE', 'DIP CHART', 'ASSIZER', 'IDENTITIES',
        'COMESA', 'CVG', 'PROFESSIONAL INSURANCE', 'FITNESS', 'ROAD TAX', 'C-29',
    ];
}

function certificateSheetNorm(string $value): string
{
    return strtoupper((string)preg_replace('/[^A-Z0-9]/', '', $value));
}

function certificateSheetSyncEnabled(PDO $db): bool
{
    $q=$db->prepare("SELECT setting_value FROM system_settings WHERE setting_key='certificate_sheet_sync_enabled' LIMIT 1");
    $q->execute();$value=$q->fetchColumn();
    return $value===false||filter_var((string)$value,FILTER_VALIDATE_BOOLEAN);
}

function certificateSheetQueue(PDO $db, int $submissionId): void
{
    $approved=$db->prepare("SELECT id FROM certificate_submissions WHERE id=? AND status='APPROVED' LIMIT 1");
    $approved->execute([$submissionId]);
    if(!$approved->fetchColumn())throw new RuntimeException('Only approved certificates can be queued for the Google Sheet.');
    $q = $db->prepare("INSERT INTO certificate_sheet_sync_queue(certificate_submission_id,status,attempts,last_error,synced_at)
        VALUES(?,'PENDING',0,NULL,NULL) ON DUPLICATE KEY UPDATE status='PENDING',last_error=NULL,synced_at=NULL");
    $q->execute([$submissionId]);
}

function certificateSheetSyncOne(PDO $db, int $queueId, ?string $spreadsheetId = null, ?string $tabOverride = null): bool
{
    if(!certificateSheetSyncEnabled($db))return false;
    $spreadsheetId ??= SHEET_CERTIFICATE_REGISTER_ID;
    $q = $db->prepare("SELECT q.id queue_id,cs.id,cs.asset_id,cs.certificate_catalog_id,cs.asset_scope,cs.registration_snapshot,cs.expiry_date,cs.sheet_certificate_column,COALESCE((SELECT ca.reviewed_by FROM certificate_amendments ca WHERE ca.certificate_submission_id=cs.id AND ca.status='APPROVED' ORDER BY ca.reviewed_at DESC,ca.id DESC LIMIT 1),cs.reviewed_by) reviewed_by,cs.status certificate_status
        FROM certificate_sheet_sync_queue q JOIN certificate_submissions cs ON cs.id=q.certificate_submission_id
        WHERE q.id=? AND q.status='PENDING'");
    $q->execute([$queueId]);
    $c = $q->fetch(PDO::FETCH_ASSOC);
    if (!$c) return false;
    if ($c['certificate_status'] !== 'APPROVED') throw new RuntimeException('Only approved certificates can be written to the register.');
    if (!in_array($c['asset_scope'], ['HORSE', 'TANKER'], true)) throw new RuntimeException('The certificate register contains only Horse and Tanker tabs.');
    $columnName = (string)$c['sheet_certificate_column'];
    if (!in_array($columnName, certificateSheetColumns(), true)) throw new RuntimeException('A valid certificate register column was not selected.');

    $service = getGoogleSheetsWriterService();
    $spreadsheet = $service->spreadsheets->get($spreadsheetId, ['includeGridData' => false]);
    $tab = $tabOverride ?? ($c['asset_scope'] === 'HORSE' ? 'Trucks' : 'Tankers');
    $sheet = null;
    foreach ($spreadsheet->getSheets() as $candidate) {
        if ($candidate->getProperties()->getTitle() === $tab) { $sheet = $candidate; break; }
    }
    if (!$sheet) throw new RuntimeException('Expected tab "' . $tab . '" was not found in the certificate register.');

    $headerValues = $service->spreadsheets_values->get($spreadsheetId, "'{$tab}'!A1:Z1")->getValues() ?? [];
    $headers = $headerValues[0] ?? [];
    $headerIndex = null;
    foreach ($headers as $index => $header) {
        if (strcasecmp(trim((string)$header), $columnName) === 0) { $headerIndex = $index; break; }
    }
    if ($headerIndex === null) throw new RuntimeException('Column "' . $columnName . '" was not found on tab "' . $tab . '".');

    $rows = $service->spreadsheets_values->get($spreadsheetId, "'{$tab}'!A2:A")->getValues() ?? [];
    $registration = certificateSheetNorm((string)$c['registration_snapshot']);
    $matches = [];
    foreach ($rows as $index => $row) {
        if (certificateSheetNorm((string)($row[0] ?? '')) === $registration) $matches[] = $index + 2;
    }
    if (count($matches) !== 1) throw new RuntimeException(count($matches) === 0
        ? 'Registration "' . $c['registration_snapshot'] . '" was not found on tab "' . $tab . '"; no row was appended.'
        : 'Registration "' . $c['registration_snapshot'] . '" appears more than once on tab "' . $tab . '"; no cell was changed.');

    $column = '';
    for ($n = $headerIndex + 1; $n > 0; $n = intdiv($n - 1, 26)) $column = chr(65 + (($n - 1) % 26)) . $column;
    $range = "'{$tab}'!{$column}{$matches[0]}";
    $body = new Google\Service\Sheets\ValueRange(['values' => [[$c['expiry_date']]]]);
    $service->spreadsheets_values->update($spreadsheetId, $range, $body, ['valueInputOption' => 'USER_ENTERED']);

    $mirror = $db->prepare("INSERT INTO certificate_sheet_register_values(spreadsheet_id,sheet_name,source_row_number,asset_scope,asset_id,asset_match_status,registration_snapshot,certificate_column,certificate_catalog_id,source_value,expiry_date,value_status,review_status,review_source,reviewed_by,reviewed_at,review_note,origin,source_submission_id)
        VALUES(?,?,?,?,?,'MATCHED',?,?,?,?,?,'DATE','APPROVED','FLEETDESK_REVIEW',?,NOW(),'Approved through FleetDesk certificate review.','FLEETDESK_APPROVED',?)
        ON DUPLICATE KEY UPDATE asset_scope=VALUES(asset_scope),asset_id=VALUES(asset_id),asset_match_status='MATCHED',registration_snapshot=VALUES(registration_snapshot),certificate_catalog_id=VALUES(certificate_catalog_id),source_value=VALUES(source_value),expiry_date=VALUES(expiry_date),value_status='DATE',review_status='APPROVED',review_source='FLEETDESK_REVIEW',reviewed_by=VALUES(reviewed_by),reviewed_at=NOW(),review_note=VALUES(review_note),origin='FLEETDESK_APPROVED',source_submission_id=VALUES(source_submission_id)");
    $mirror->execute([$spreadsheetId,$tab,$matches[0],$c['asset_scope'],$c['asset_id'],$c['registration_snapshot'],$columnName,$c['certificate_catalog_id'],$c['expiry_date'],$c['expiry_date'],$c['reviewed_by'],$c['id']]);

    $done = $db->prepare("UPDATE certificate_sheet_sync_queue SET status='SYNCED',attempts=attempts+1,last_error=NULL,synced_at=NOW() WHERE id=?");
    $done->execute([$queueId]);
    return true;
}

function certificateSheetProcessPending(PDO $db, int $limit = 25): array
{
    $limit = max(1, min(100, $limit));
    $result = ['synced' => 0, 'failed' => 0, 'paused'=>false];
    if(!certificateSheetSyncEnabled($db)){$result['paused']=true;return $result;}
    if ((int)$db->query("SELECT GET_LOCK('fleet_certificate_sheet_sync',0)")->fetchColumn() !== 1) return $result;
    try {
        $pending=$db->prepare("SELECT q.id FROM certificate_sheet_sync_queue q JOIN certificate_submissions cs ON cs.id=q.certificate_submission_id AND cs.status='APPROVED' WHERE q.status='PENDING' ORDER BY q.created_at,q.id LIMIT ?");
        $pending->bindValue(1,$limit,PDO::PARAM_INT);$pending->execute();$ids=$pending->fetchAll(PDO::FETCH_COLUMN);
        foreach ($ids as $id) {
            try {
                if (certificateSheetSyncOne($db, (int)$id)) $result['synced']++;
            } catch (Throwable $e) {
                $fail = $db->prepare("UPDATE certificate_sheet_sync_queue SET attempts=attempts+1,last_error=? WHERE id=? AND status='PENDING'");
                $fail->execute([mb_substr($e->getMessage(), 0, 1000), (int)$id]);
                $result['failed']++;
                error_log('Certificate Sheet sync queue #' . (int)$id . ': ' . $e->getMessage());
            }
        }
    } finally {
        $db->query("SELECT RELEASE_LOCK('fleet_certificate_sheet_sync')");
    }
    return $result;
}
