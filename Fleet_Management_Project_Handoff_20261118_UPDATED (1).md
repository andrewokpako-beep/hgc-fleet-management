# Fleet Management Project — Handoff
**Updated:** 18 November 2026  
**Project:** `C:\xampp\htdocs\fleet-management`  
**Current stage:** Source review complete enough to begin import staging design; production import not yet run.

## Core rules
- Google Sheets are read-only. Never write to source sheets.
- Preserve raw cell values, source spreadsheet/tab, and source row numbers.
- Never use a sheet row number as a permanent asset ID.
- Truck heads and trailers are separate assets; attachment relationships are separate records.
- Kenmugala remains a separate location name; do not automatically map it to Nakonde.
- Keep both independently updated 21 Miles unregistered source lists as distinct source observations, even where their contents match.
- Tracking status/geofence is a time-specific observation, not asset master data or permanent yard assignment.
- Apply only validated, non-conflicting changes automatically in a later sync. Conflicts, suspicious changes, uncertain matches and possible duplicates require human review.
- Do not delete or mark assets missing unless a successful validated full sync proves absence.
- Dashboard figures are inconsistent and not authoritative.
- Do not import ambiguous asset/attachment relationships.

## Environment
- Windows + XAMPP; project root `C:\xampp\htdocs\fleet-management`.
- PHP previously reported as 8.0.28.
- Google service account file outside public web root: `C:/xampp/secure/fleet/google-service-account.json`.
- Never request credentials/private keys.
- Verify `DB_PASS` in `config/config.php` is the actual local MySQL password; an earlier config upload showed a placeholder.
- Composer, project config, Google connection scripts and comprehensive MySQL schema are in place. Avoid overwriting existing setup/schema.
- Both spreadsheet connection tests previously succeeded.

## Source spreadsheets
1. Consolidated Trucks — ID `1kwKwJBsX1FMfMW5C8HKiyOMsnxvsja8PuaEDdcqubPM`
   - `Ngwerere_Tankers_status`, `Ngwerere_Flatbeds_Status`
   - `21Miles_Tankers_Unregistered`, `21Miles_Flatbed_Unregistered`
   - `Nakonde_Tankers_unregistered`, `Nakonde_Flatbed_unregistered`
   - `DASHBOARD`
2. Ngwerere / 21 Miles Trucks — ID `1uc36Fwf-Svg3rK6F5vdIKghn2Kx7XcVpRnNTqt4iRTg`
   - `Ngwerere`, `Unregistered_Ngwerere`, `Registered_21Miles`
   - `Unregistered_21Miles`, `Unregistered_21Miles_NoTrailer`
   - `Dashboard`, `Breakdown`

## Stage 2 profiling
Run ID `20261118_195110`:
- 14 worksheets, 803 populated rows.
- 1,280 broad identifier candidate cells, 1,046 unique normalized candidates, 197 possible repeated-value groups.
- Snapshot: `logs/profiling/snapshot_20261118_195110.json`.
- Report: `reports/profiling/profiling_report_20261118_195110.json`.
- CLI: `C:\xampp\php\php.exe google\profile-sheets.php`.
- Read-only; local JSON outputs only. Broad candidate detection needs column-aware interpretation.

## Reconciliation results

### 21 Miles unregistered
Both source pairs are independently maintained, per user:
- `21Miles_Tankers_Unregistered` vs `Unregistered_21Miles`: 66 each; all match by normalized value, sequence and row position.
- `21Miles_Flatbed_Unregistered` vs `Unregistered_21Miles_NoTrailer`: 32 each; all match by normalized value, sequence and row position.
- 98 unique identifiers across those two categories, not total fleet count.
- Preserve both source observations and provenance.

### Ngwerere
- Registered tankers: 204 head/trailer pairs; 204 distinct heads, 203 distinct trailer identifiers. Trailer `AID4541ZM` assigned in source to two heads: `AID4525ZM` (row 37) and `AID5128ZM` (row 97); human review.
- Tanker statuses: 183 ACTIVE, 8 ACCIDENTED, 2 WORKSHOP - CRITICAL, 1 PARTS USED, 10 YET TO BE DEPLOYED.
- Registered flatbeds: 41 heads; 40 populated trailer identifiers. `CAL5672ZM` has no trailer value (row 42). Statuses: 29 ACTIVE, 12 WAITING FOR INSPECTION.
- Tracking tab: 214 objects, 214 distinct normalized identifiers; 36 overlap registered tanker heads and 7 overlap registered flatbed heads in earlier reconciliation. Includes non-Ngwerere geofences; use only as tracking observations.
- Unregistered_Ngwerere: flatbed-only IDs `LZ1B23GEXS0032532` (row 2), `LZ1B23GE1S0032533` (row 3); head-only IDs `LVBS6PEB2RR014911` (row 7), `LVBS6PEB2RR014889` (row 8).

### Nakonde
- Tanker sheet: 57 heads and 57 trailer-column values; 57 unique heads, 55 unique trailer values. Trailer values `1289` (rows 22,53) and `1275` (rows 35,54) repeat. Row 22 also has extra column C value `LJRT123A5S2311269`; human review. Yard labels: 25 Kenmugala, 9 PowerTools, 23 MARSTAN. Keep Kenmugala separate from Nakonde until explicitly mapped.
- Flatbed sheet: structured rows 2–31; 19 heads, 19 attached trailers, 31 first-unattached trailer values, 9 second-unattached trailer values. 78 populated identifier cells, 76 distinct normalized values. `9964` appears as both head and attached trailer (row 26); `2610` repeats in unattached columns (rows 27,31). Trailer-only rows 13–23 are standalone trailer candidates. Rows 37 (`sadfjj`) and 42 (`kjlkjkj`) are stray text, not assets. Yard labels in structured rows: 22 Kenmugala, 8 PowerTools.

### Registered_21Miles — latest review
Worksheet `Registered_21Miles` contains 12 populated data rows in each of two sections:
- A:B = Registered Tankers (Horse, Tanker)
- D:E = Registered Flatbeds (Horse, Trailer)
Findings:
- 11 of 12 horse IDs repeat in both sections on the same row after harmless punctuation normalization.
- 11 of 12 trailer IDs repeat in both sections on the same row.
- 10 rows have the same normalized horse/trailer pair in both sections.
- Row 6 differs: tanker horse `CAH9085`, flatbed horse `CAH9095`; both show trailer `CAJ2981`.
- Row 13: tanker pair `CAL5672` / `CAK9516`; flatbed trailer is dots placeholder.
- Rows 10–11 have trailing dots on flatbed horse values `CAJ3181.` and `CAL5641.`; preserve raw text, normalize for comparison.
- 7 registered horse identifiers overlap the Ngwerere tracking sheet: `CAJ3391`, `CAJ3186`, `CAL5640`, `CAJ3181`, `CAL5672`, `CAH9224`, `CAH9095`. All appear with Harvest Depot - 21 Miles except CAH9095 has no geofence. Trailer `CAL6057` also appears in tracking.
- None of these Registered_21Miles IDs matched the Ngwerere registered head/trailer columns in the captured snapshot.
- Dashboard says 85 registered 21 Miles assets (73 tankers + 12 flatbeds), but this worksheet only shows 12 tanker pairs and 12 flatbed pairs. Dashboard count is unverified; source for the other claimed 61 tankers is unknown.
- Full review artifact: `Fleet_Management_Registered_21Miles_Review_20261118.md`.

## Existing delivered reports
- `Fleet_Management_Stage2_Reconciliation_Preview_20261118.md`
- `Fleet_Management_Ngwerere_Nakonde_Preview_20261118.md`
- `Fleet_Management_Registered_21Miles_Review_20261118.md`

## Next recommended stage
Start import staging design and inspect the existing schema/config/scripts before writing production import code. Use a raw-source staging layer with provenance, normalized identifiers, candidate assets, and a human-review queue. Load only unambiguous candidates into a provisional inventory; keep ambiguous pairings and repeated identifiers pending. Store tracking snapshots separately. Then run a dry-run report and reconcile counts before any production asset import.

## Explicitly not done
- No production fleet assets imported into MySQL yet.
- No Google Sheets changed.
- No assets merged, deleted, or marked missing.
- No ambiguous attachment relationship approved.


## Latest milestone — Registered_21Miles and schema review (18 November 2026)

### Registered_21Miles
- Reviewed 12 data rows in each side-by-side section: tanker Horse/Tanker and flatbed Horse/Trailer.
- 11 of 12 horse identifiers and 11 of 12 trailer identifiers repeat across both sections after harmless punctuation normalization.
- Row 6 differs: tanker horse `CAH9085`, flatbed horse `CAH9095`, same trailer `CAJ2981`.
- Row 13 flatbed trailer is dots placeholder; tanker side lists `CAK9516`.
- Rows 10–11 flatbed horse values include trailing dots; preserve raw values.
- Seven registered horse IDs overlap the Ngwerere tracking feed; trailer `CAL6057` also appears there. Tracking status/geofence remains observation-only.
- None of the Registered_21Miles IDs matched the Ngwerere registered head/trailer columns in the captured snapshot.
- Dashboard says 85 registered 21 Miles assets (73 tanker + 12 flatbed), while the worksheet contains 12 rows per section. Treat the dashboard count as unverified.
- Detailed report: `Fleet_Management_Registered_21Miles_Review_20261118.md`.

### Existing SQL schema review
- Reviewed Library file `fleet_management_schema.sql` (22,486 bytes).
- Existing schema already covers assets, identifiers, attachments, yards, spreadsheet sources/sheets, sync runs, source rows, field rules, reconciliation, tracking, users and audit logs. Do not recreate the schema.
- Schema has `spreadsheet_source_rows` unique on `(sheet_id, source_row_key)` and a single `sync_run_id`; this is a latest-row record, not an immutable per-run history. Add a run-scoped append-only row-version/staging table before building repeatable imports.
- Keep `asset_identifiers.normalized_value` non-unique to permit ambiguity review.
- `asset_attachments` lacks a DB-level active-relationship overlap guard; validate attachment conflicts in PHP and route them to review.
- `spreadsheet_sheets.mapping_config` and `field_source_rules` are intended for confirmed mappings/authority.
- Existing seed yards are Ngwerere, 21 Miles, Nakonde. User says Kenmugala remains a separate location name; do not map it to Nakonde. Decide how to represent the separate location before seeding it.
- No local DB connection was made in this review; no MySQL records were changed.

### Current stage and next action
- Source profiling and column-aware reconciliation: completed for the captured snapshot.
- SQL schema review: completed.
- Dry-run importer and production import: not started.
- Frontend: not started.
- To integrate safely with the user's exact project, obtain current `google/connection.php` and `google/profile-sheets.php` (plus changed config only if applicable; redact DB password). Do not send the service-account JSON or secrets.
- Next: implement append-only raw row-version staging and a CLI dry-run importer using existing Google read-only connection, then inspect dry-run counts before any asset-table writes.


## Latest uploaded PHP files reviewed (18 November 2026)
- User supplied `google/connection.php` and `google/profile-sheets.php`.
- `connection.php` requires `config/config.php` and Composer autoload, creates a Google Sheets service using `GOOGLE_CREDENTIALS_FILE`, and requests only `SPREADSHEETS_READONLY` scope. No credentials/private key are embedded in the uploaded file.
- `profile-sheets.php` is CLI-only, uses the existing connection and four profiling modules, writes a local JSON snapshot/report, and explicitly does not write to Google Sheets or MySQL.
- Both uploaded PHP files passed PHP syntax checks in the review environment.
- The profiler is an orchestrator only; it does not expose the extraction module's row structure or database connection. The next importer must reuse the existing Google read-only connection rather than duplicate credentials/authentication.
- Exact next dependencies needed before implementing a compatible staging importer: current `config/config.php` with only the DB password value replaced by `[REDACTED]` (keep constant names and DSN structure), and `modules/profiling/extract-sheets.php`. Do not upload the Google service-account JSON, private keys, or real passwords.
- The schema review remains: add run-scoped immutable raw row versions before repeatable imports; no production assets have been inserted.

## Latest update — configuration and extraction review (18 November 2026)
- User confirmed the current local MySQL connection password and supplied the current `config.php` and `extract-sheets.php` files.
- `config.php` defines `APP_NAME`, `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS`, `GOOGLE_CREDENTIALS_FILE`, both spreadsheet IDs, and the `Africa/Lusaka` timezone. The supplied file contains a live credential; do not copy its value into this handoff, logs, reports, generated code examples, or future messages.
- `extract-sheets.php` defines `fleetColumnLetter()`, `fleetRowHasData()`, and `extractFleetSheets(Google\\Service\\Sheets $service)`. It reads metadata and the full declared grid for both configured source spreadsheets, requests `UNFORMATTED_VALUE`, and returns populated rows with original source row numbers and source/sheet provenance. It does not write to Google Sheets or MySQL.
- Both supplied PHP files passed `php -l` syntax checks in the review environment.
- No database connection was attempted and no MySQL or Google Sheets data was changed.
- Before implementing the importer, obtain the current `google/connection.php` and the exact existing SQL schema file. The Library listing available in this session did not surface the previously reviewed `fleet_management_schema.sql`; do not recreate or guess the schema.
- Next: inspect those two dependencies, then design and implement the append-only run-scoped raw row staging table and CLI dry-run importer. Preserve all core data-integrity rules above. No production asset import is authorized or completed.
- Standing workflow instruction from user: update this handoff document whenever project work or implementation changes are made. Do not store or reproduce credentials in the handoff.


## Latest milestone — staging dependencies reviewed (18 November 2026)

### Files received and inspected
- `google/connection.php`: loads `config/config.php` and Composer autoload; creates the Google Sheets client with `SPREADSHEETS_READONLY` scope.
- `modules/profiling/extract-sheets.php`: extracts both configured spreadsheets and all declared worksheets; requests `UNFORMATTED_VALUE`; preserves original worksheet row numbers and raw row arrays; omits fully blank rows; performs no Google Sheets writes.
- `config/config.php`: confirms DB host/name/user/password constants, Google credential path, both spreadsheet IDs, and `Africa/Lusaka` timezone. Credentials are intentionally excluded from this handoff.
- `fleet_management_schema.sql`: confirms the existing source, sheet, sync-run, source-row, asset, attachment, tracking and reconciliation tables. No need to recreate the schema.
- No live MySQL connection was made and no database records were changed during this review.

### Implementation prepared
- Migration: `20261118_create_source_row_versions.sql`
- CLI staging importer: `stage-sheets.php`
- The migration adds `spreadsheet_source_row_versions`, an append-only, run-scoped raw-row history table.
- Each version stores `sync_run_id`, `sheet_id`, source row key, original source row number, raw JSON values, SHA-256 row hash and capture timestamp.
- `ROW:<source row number>` is only a worksheet-row locator within a sync run; it is not an asset ID.
- Each spreadsheet receives its own `spreadsheet_sync_runs` record because the existing schema associates one run with one source.
- The staging CLI writes only source/sheet metadata as needed and raw row versions. It does not write to assets, identifiers, attachments, yard history, tracking or Google Sheets.
- No missing/absence logic is applied.
- The importer reuses the existing Google connection and extraction module and builds PDO from the existing DB constants.

### Validation and execution status
- PHP syntax checks passed for `connection.php`, `extract-sheets.php` and `stage-sheets.php`.
- Migration and importer have been prepared but NOT executed against the user's local MySQL database or live Google Sheets.
- The migration must be applied before running the importer.
- Run from the project root: `C:\xampp\php\php.exe google\stage-sheets.php`.
- First execution must be treated as a staging test; review source/run/sheet/row counts before proceeding.
- Production asset import remains not started and requires dry-run reconciliation plus explicit approval.

### Next action
1. Place the migration at `database/migrations/20261118_create_source_row_versions.sql`.
2. Place the importer at `google/stage-sheets.php`.
3. Apply the migration once in the `fleet_management` database.
4. Run the CLI staging importer from the project root.
5. Review the resulting run IDs and staged counts. Do not proceed to asset-table writes yet.

## Latest milestone — raw-source staging executed successfully (18 November 2026)

### User-reported execution
- User confirmed the SQL migration was applied successfully and ran `C:\xampp\php\php.exe google\stage-sheets.php` from `C:\xampp\htdocs\fleet-management`.
- CLI output reported `Google Sheets access: READ ONLY` and `Asset-table writes: DISABLED`.
- `CONSOLIDATED_TRUCKS`: sync run ID `1`, 7 worksheets, 445 raw rows, `SUCCESS`.
- `NGWERERE_21MILES`: sync run ID `2`, 7 worksheets, 358 raw rows, `SUCCESS`.
- Total staged: 14 worksheets and 803 raw rows; CLI reported no production fleet assets changed.
- These totals exactly match the prior profiling baseline (14 worksheets / 803 populated rows).

### Current verified state and limitations
- Staging execution is confirmed by the user's pasted CLI output. The assistant has not independently connected to the user's local MySQL instance or queried the resulting database rows.
- Raw source rows are staged in `spreadsheet_source_row_versions`; run and sheet metadata are recorded by the importer.
- No production asset import, identifier creation, attachment import, yard assignment, tracking import, merge, deletion, or missing-asset marking has been performed or authorized.
- Google Sheets remain read-only; no source spreadsheet writes were made.

### Next action — verify database contents before reconciliation
Run these read-only SQL checks in phpMyAdmin against the project database and share the results:

```sql
SELECT id, source_id, run_status, rows_read, started_at, completed_at
FROM spreadsheet_sync_runs
WHERE id IN (1, 2)
ORDER BY id;

SELECT sync_run_id, COUNT(*) AS staged_rows,
       COUNT(DISTINCT sheet_id) AS worksheet_count
FROM spreadsheet_source_row_versions
WHERE sync_run_id IN (1, 2)
GROUP BY sync_run_id
ORDER BY sync_run_id;

SELECT s.sync_run_id, sh.sheet_name, COUNT(*) AS staged_rows
FROM spreadsheet_source_row_versions AS s
JOIN spreadsheet_sheets AS sh ON sh.id = s.sheet_id
WHERE s.sync_run_id IN (1, 2)
GROUP BY s.sync_run_id, sh.id, sh.sheet_name
ORDER BY s.sync_run_id, sh.sheet_name;
```

- Expected totals by run: run 1 = 445 rows / 7 worksheets; run 2 = 358 rows / 7 worksheets; overall = 803 rows / 14 worksheets. Per-sheet counts should be reviewed against the profiler output if available.
- If these checks agree, proceed to read-only reconciliation/dry-run design. Do not write to production asset tables until all identity and relationship conflicts have been reviewed and the user explicitly approves.

### Standing workflow instruction
- Continue updating this handoff after every meaningful project change. Keep credentials and secrets out of this document.

## Latest milestone — database staging verification (18 November 2026)

### User-provided phpMyAdmin evidence reviewed
- `spreadsheet_sync_runs` confirms run `1` for source `1` has `run_status=SUCCESS` and `rows_read=445`; run `2` for source `2` has `run_status=SUCCESS` and `rows_read=358`.
- Aggregated `spreadsheet_source_row_versions` results confirm run `1` contains 445 staged rows across 7 distinct sheet IDs; run `2` contains 358 staged rows across 6 distinct sheet IDs.
- Per-sheet results show run `1` populated counts: `21Miles_Flatbed_Unregistered` 33; `21Miles_Tankers_Unregistered` 67; `DASHBOARD` 5; `Nakonde_Flatbed_unregistered` 33; `Nakonde_Tankers_unregistered` 58; `Ngwerere_Flatbeds_Status` 43; `Ngwerere_Tankers_status` 206. These sum to 445.
- Per-sheet results show run `2` populated counts: `Dashboard` 23; `Ngwerere` 215; `Registered_21Miles` 14; `Unregistered_21Miles` 67; `Unregistered_21Miles_NoTrailer` 33; `Unregistered_Ngwerere` 6. These sum to 358.
- Combined staged rows remain 803, matching the successful CLI output and prior profiling baseline.

### Outstanding worksheet-count detail
- CLI staging output reported 7 worksheets for each source (14 total), but the aggregate database query counts only 6 distinct sheet IDs with staged rows for run `2`.
- This is not evidence of lost populated rows: all 358 rows reported for run `2` are present in the staged-row aggregate and the six listed sheet counts sum to 358.
- A worksheet containing no nonblank rows would have no records in `spreadsheet_source_row_versions`, so it would not appear in `COUNT(DISTINCT sheet_id)` or the per-sheet result. The screenshots alone do not prove whether the seventh worksheet is empty or whether sheet metadata was not persisted; this must be checked using a LEFT JOIN from all sheet metadata for each source/run to the staged row versions.

### Next read-only verification query
Run in phpMyAdmin:

```sql
SELECT
    r.id AS sync_run_id,
    src.source_code,
    sh.id AS sheet_id,
    sh.sheet_name,
    COUNT(v.id) AS staged_rows
FROM spreadsheet_sync_runs AS r
JOIN spreadsheet_sources AS src ON src.id = r.source_id
JOIN spreadsheet_sheets AS sh ON sh.source_id = src.id
LEFT JOIN spreadsheet_source_row_versions AS v
    ON v.sync_run_id = r.id
   AND v.sheet_id = sh.id
WHERE r.id IN (1, 2)
GROUP BY r.id, src.source_code, sh.id, sh.sheet_name
ORDER BY r.id, sh.sheet_name;
```

- This should list every worksheet metadata record for the source associated with each run, including a zero count if a worksheet has no staged nonblank rows.
- Expected outcome: 7 sheet metadata rows for each run's source. If run `2` shows seven rows and one has `staged_rows=0`, the discrepancy is explained by an empty worksheet. If only six sheet metadata rows exist for source `2`, inspect the importer/extraction metadata path before reconciliation.

### Current go/no-go state
- Run status and all populated raw-row counts are verified from user screenshots.
- Worksheet metadata completeness for source/run `2` remains to be confirmed with the LEFT JOIN query above.
- Reconciliation design can proceed after that check, but no production asset writes, identifier creation, attachment import, yard assignment, tracking import, merges, deletions, or missing-asset marking have occurred or are authorized.
- Google Sheets remain read-only.

## Latest milestone — all worksheet metadata verified (18 November 2026)

### User-provided phpMyAdmin evidence reviewed
- The LEFT JOIN verification query returned 14 worksheet metadata rows: 7 for `CONSOLIDATED_TRUCKS` / run `1`, and 7 for `NGWERERE_21MILES` / run `2`.
- Run `1` worksheet staged-row counts remain: `21Miles_Flatbed_Unregistered` 33; `21Miles_Tankers_Unregistered` 67; `DASHBOARD` 5; `Nakonde_Flatbed_unregistered` 33; `Nakonde_Tankers_unregistered` 58; `Ngwerere_Flatbeds_Status` 43; `Ngwerere_Tankers_status` 206. Total 445.
- Run `2` worksheet staged-row counts: `Breakdown` 0; `Dashboard` 23; `Ngwerere` 215; `Registered_21Miles` 14; `Unregistered_21Miles` 67; `Unregistered_21Miles_NoTrailer` 33; `Unregistered_Ngwerere` 6. Total 358.
- The earlier six-sheet aggregate for run `2` counted only worksheets with staged rows. The seventh worksheet is `Breakdown`, which is present in sheet metadata and has zero nonblank rows. This fully explains the apparent discrepancy; it is not a staging failure.
- Final verified staging totals: 14 worksheet metadata records, 803 raw source rows, two successful sync runs (445 + 358). These match the CLI output and prior profiling baseline.

### Current go/no-go state
- Raw-source staging and worksheet metadata verification are complete.
- Google Sheets access was read-only; no source spreadsheet writes were made.
- No production asset rows, identifiers, attachments, yard assignments, tracking observations, merges, deletions, or missing-asset flags have been written.
- Next development step: implement/execute a strictly read-only reconciliation dry run using the staged raw rows. It must preserve source provenance and original row numbers, keep truck heads and trailers distinct, avoid using row numbers as asset IDs, and report candidate matches, duplicates, conflicts, and uncertain attachment relationships for human review. It must not write to production asset tables.
- No asset import is authorized until the reconciliation output is reviewed and the user explicitly approves a separately defined import plan.

## Latest milestone — reconciliation preparation (18 November 2026)

### Confirmed staging state
- User supplied phpMyAdmin screenshots verifying sync runs `1` and `2` are `SUCCESS`, with 445 and 358 rows respectively.
- LEFT JOIN verification returned all 14 worksheet metadata records. Run `1` has 7 populated worksheets / 445 rows. Run `2` has 6 populated worksheets / 358 rows plus the empty `Breakdown` worksheet (0 rows).
- Combined: 14 worksheets, 803 staged raw rows. This matches the user's CLI staging output and the prior profiling baseline.

### Reconciliation safety and current work
- Reconciliation must remain a dry run: no writes to `assets`, `asset_identifiers`, `asset_attachments`, yard history, tracking, or reconciliation decision tables.
- The raw staging table contains JSON arrays of source cell values plus source row number, sheet ID, sync run ID, and row hash. It does not yet contain confirmed per-sheet column mappings.
- Do not apply broad identifier detection indiscriminately: prior profiling found many candidate cells, and the source review explicitly requires column-aware interpretation.
- Existing handoff findings remain human-review rules: repeated trailer identifiers, the Nakonde extra identifier column, flatbed stray text, Registered_21Miles side-by-side differences, 21 Miles paired source observations, and tracking-only observations.
- No production asset rows or reconciliation cases have been written.

### New diagnostic utility prepared
- File: `google/inspect-staged-layout.php`.
- Purpose: read-only inspection of up to the first eight populated raw rows in every worksheet for selected sync runs, so exact header/column positions can be confirmed before coding column-aware matching rules.
- Default command from project root: `C:\\xampp\\php\\php.exe google\\inspect-staged-layout.php`.
- Optional run IDs: `C:\\xampp\\php\\php.exe google\\inspect-staged-layout.php 1,2`.
- It queries only `spreadsheet_sync_runs`, `spreadsheet_sources`, `spreadsheet_sheets`, and `spreadsheet_source_row_versions`; it does not write to MySQL or Google Sheets and does not access production asset tables.
- PHP syntax validation passed with the available CLI PHP runtime. It has not yet been executed against the user's local MySQL database.

### Next action
- User should copy `inspect-staged-layout.php` into `C:\\xampp\\htdocs\\fleet-management\\google\\inspect-staged-layout.php`, run the command above, and paste the complete output.
- Use that output to lock exact per-sheet headers/column indexes and then implement the true read-only reconciliation dry run, preserving raw values and source provenance. Do not infer mappings from row positions alone or write production assets.

## Latest milestone — staged layout inspected and reconciliation dry-run utility prepared (18 November 2026)

### User-provided layout inspection
- User ran `google/inspect-staged-layout.php` successfully against sync runs 1 and 2. It read staged raw rows only and made no changes.
- Confirmed source layouts:
  - `CONSOLIDATED_TRUCKS / Ngwerere_Tankers_status`: row 2 headers; data begins row 3; column C = truck registration, D = tanker registration, B = make, E = comment/status.
  - `CONSOLIDATED_TRUCKS / Ngwerere_Flatbeds_Status`: row 2 headers; data begins row 3; column C = truck registration, D = flatbed registration, B = make, E = comment/status.
  - `CONSOLIDATED_TRUCKS / Nakonde_Tankers_unregistered`: columns A/B are head/tanker-trailer VIN candidates; column D carries yard context.
  - `CONSOLIDATED_TRUCKS / Nakonde_Flatbed_unregistered`: columns A-D are head, attached trailer, unattached trailer 1, unattached trailer 2; column E carries yard context. Only VIN-shaped values should be considered identifiers. Keep unattached trailer columns separate from confirmed attachments.
  - `NGWERERE_21MILES / Registered_21Miles`: row 1 section titles, row 2 headers, data from row 3; A:B tanker Horse/Tanker and D:E flatbed Horse/Trailer. Row 6 has a real discrepancy: tanker horse `CAH9085` vs flatbed horse `CAH9095`, both with trailer `CAJ2981`.
  - `NGWERERE_21MILES / Ngwerere`: column A tracking object, B status, C geofence; observation-only, not permanent yard assignment.
  - One-column IT-number worksheets are independent source observations. Preserve original text and source row; do not treat IT values as permanent asset IDs or merge observations solely on repeated IT values.
  - Dashboard sheets are excluded from identity extraction. `Breakdown` was verified as an empty worksheet.
- The inspection output confirms all expected populated worksheet structures; source row numbers are retained as provenance only, never asset IDs.

### Reconciliation utility prepared
- File: `google/reconcile-staged.php` (provided separately as `reconcile-staged.php`).
- Purpose: read-only, column-aware source-to-source analysis of immutable staged rows for runs 1 and 2.
- Reads sync-run metadata and `spreadsheet_source_row_versions`; no reads from production asset tables are needed because no fleet assets have yet been imported.
- Uses exact known worksheet/column mappings; excludes dashboard rows; extracts registered plate observations, VIN-shaped Nakonde identifiers, 21 Miles IT observations, Registered_21Miles paired sections, and tracking observations.
- Reports repeated normalized identifiers, exact cross-source overlaps, identifiers appearing in multiple asset roles, Registered_21Miles row disagreements, and exact tracking-to-asset identifier matches.
- Does not fuzzy-match, merge, assign yards, create attachments, update status, write reconciliation cases, or modify any MySQL table or Google Sheet.
- Writes only local JSON and Markdown reports under `reports/reconciliation/`.
- PHP syntax check passed in the review environment. It has NOT yet been executed against the user's local database; output and counts remain pending.

### Next action
- Copy `reconcile-staged.php` to `C:\\xampp\\htdocs\\fleet-management\\google\\reconcile-staged.php`.
- Run from project root: `C:\\xampp\\php\\php.exe google\\reconcile-staged.php`.
- Share the complete CLI output. Review generated report totals and flagged cases before any separate import design.

### Safety status
- No production asset, identifier, attachment, yard history, tracking, reconciliation case, or decision rows have been written.
- Google Sheets remain read-only.
- No asset import is authorized until dry-run findings have been reviewed and a separate import plan is explicitly approved.

## Latest milestone — first reconciliation report reviewed and classification corrections prepared (18 November 2026)

### User-provided dry-run output
- User ran `google/reconcile-staged.php` against sync runs `1,2`.
- The run completed read-only: 803 staged rows read; no MySQL or Google Sheets writes; no production asset writes.
- Initial report: 829 identifier observations; 740 normalized identifier+role groups; 89 repeated groups; 66 exact cross-source repeated groups; 214 tracking observations; 17 exact tracking matches; 12 Registered_21Miles paired rows examined; 2 pair disagreements.
- User uploaded the generated Markdown and JSON reports. Both were inspected in full/structured form.

### Findings confirmed by report review
- Registered_21Miles row 6 is a genuine head-registration disagreement: tanker section `CAH9085 / CAJ2981`, flatbed section `CAH9095 / CAJ2981`. Preserve both observations and require human review.
- Registered_21Miles row 13 has matching head `CAL5672` but the flatbed trailer cell is a dot placeholder (`.................`), not a valid identifier. This is an incomplete comparison, not a confirmed pair disagreement.
- The first script classified `21Miles_Flatbed_Unregistered` IT values as `TRAILER` and `Unregistered_21Miles_NoTrailer` IT values as `UNRESOLVED_ASSET`. This generated false high-severity cross-role warnings for the same IT references across independent source lists.
- IT values do not, by themselves, prove a physical asset role or permanent unique asset identity. Revised script now uses neutral `IT_REFERENCE` for all one-column IT lists, preserving worksheet/source provenance and separate observations. They are excluded from physical asset cross-role conflict warnings.
- Repeated values across independent source lists remain source observations; they are not merged or concluded to be duplicate physical assets.
- The previous counts (89 repeated groups / 66 cross-source repeated groups / 2 pair disagreements) are the original script's baseline only and are expected to change after corrected classification. Do not treat old high-severity IT role warnings as confirmed fleet conflicts.

### Corrected reconciliation script prepared
- Updated file: `google/reconcile-staged.php` (downloadable copy: `reconcile-staged.php`).
- IT identifier classification changed to neutral `IT_REFERENCE`; no physical role inferred from the one-column IT list.
- Registered_21Miles pair comparison now compares only when both cells contain valid identifier-like values. Blank/dot placeholders are recorded as incomplete comparisons, not disagreements.
- Genuine non-matching comparable values remain high-severity human-review findings.
- Summary now separately counts incomplete paired rows.
- Tracking remains exact-match observation-only. No fuzzy matching, yard assignment, attachments, merges, asset creation, or source writes.
- PHP syntax validation passed (`php -l`). Script has not yet been rerun against the user's local database; corrected counts are pending.

### Next action
- Replace `C:\xampp\htdocs\fleet-management\google\reconcile-staged.php` with the corrected file.
- Run from project root: `C:\xampp\php\php.exe google\reconcile-staged.php`.
- Share the new CLI output and new Markdown/JSON reports. Review revised counts and confirm only row 6 remains a genuine Registered_21Miles pair disagreement; row 13 should be incomplete/INFO.
- No production asset import or reconciliation decision writes are authorized. Google Sheets remain read-only.

## Latest milestone — corrected reconciliation dry run executed successfully (18 November 2026)

### User-provided corrected CLI output
- Corrected `google/reconcile-staged.php` completed against sync runs `1,2` in read-only mode.
- 803 raw staged rows read; 829 identifier observations; 214 tracking observations; 17 exact tracking-to-asset identifier matches.
- Corrected counts: 708 distinct normalized identifier+role groups; 121 repeated identifier groups; 98 exact cross-source repeated groups.
- Registered_21Miles: 12 paired rows examined; 1 genuine disagreement; 1 incomplete paired row.
- Dashboard rows excluded: 28. Non-identifier cells ignored: 99.
- User's CLI confirms: no database or source data changed.

### Corrected findings
- Only confirmed high-priority paired-row disagreement is Registered_21Miles source row 6: tanker section `CAH9085 / CAJ2981`; flatbed section `CAH9095 / CAJ2981`. Preserve both observations and require human confirmation.
- Registered_21Miles source row 13 is now INFO/incomplete: head `CAL5672` matches; flatbed trailer cell is `.................`, treated as placeholder. No disagreement asserted.
- Dashboard exclusion, independent 21 Miles IT-list preservation, and tracking observation-only safeguards remain in place.
- The increase from 89 to 121 repeated identifier groups and 66 to 98 exact cross-source repeated groups follows the revised neutral `IT_REFERENCE` grouping. These are repeated reference observations, not a count of duplicate physical assets. Do not interpret these totals as confirmed duplicate vehicles/trailers.

### Current state and next action
- Corrected dry-run CLI summary is received and internally consistent with the intended classification changes.
- New report paths reported by user:
  - `reports/reconciliation/reconciliation_dry_run_20261118_205440.md`
  - `reports/reconciliation/reconciliation_dry_run_20261118_205440.json`
- The new report files have not yet been uploaded for detailed inspection. Request both files before signing off the detailed reconciliation findings or designing any production import plan.
- No production assets, identifiers, attachments, yard history, tracking, reconciliation decisions, or asset-import rows have been written. Google Sheets remain read-only. No import is authorized.


## Latest milestone — corrected reconciliation reports reviewed (18 November 2026)

### Report files received and cross-checked
- Reviewed the user's uploaded Markdown report `reconciliation_dry_run_20261118_205440.md` and JSON report `reconciliation_dry_run_20261118_205440.json`.
- Both report timestamps are `2026-11-18T20:54:40+02:00`; both refer to sync runs 1 and 2.
- Markdown and JSON executive summaries agree on all headline metrics:
  - 803 staged raw rows; 829 identifier observations.
  - 708 distinct normalized identifier+role groups; 121 repeated groups; 98 exact cross-source repeated groups.
  - 214 tracking observations; 17 exact tracking-to-asset identifier matches.
  - 12 Registered_21Miles paired rows; 1 disagreement; 1 incomplete comparison.
  - 28 dashboard rows excluded; 99 non-identifier cells ignored.
- JSON explicitly records `read_only=true`, `production_writes=false`, `database_writes=false`, and `google_sheets_writes=false`. The report limitations state that the DB was used only for SELECTs against sync metadata and staged raw rows.

### Detailed review and interpretation
- Identifier observations break down to 536 `REGISTERED_SOURCE`, 97 `UNREGISTERED_SOURCE`, and 196 `UNREGISTERED_IT_SOURCE`; roles are 307 `HEAD`, 326 `TRAILER`, and 196 `IT_REFERENCE`.
- All 98 exact cross-source repeated groups are `IT_REFERENCE` overlaps between the two independently maintained 21 Miles lists. These are preserved as distinct source observations, not merged physical assets.
- The 121 repeated identifier groups comprise those 98 IT-reference overlaps plus 23 same-source repeats within the side-by-side `Registered_21Miles` worksheet (11 HEAD and 12 TRAILER groups). The repeated-group count is therefore not a duplicate-asset count.
- The `Registered_21Miles` pair records are internally consistent: rows 3–5 and 7–12 and 14 match across the two sections; row 6 has a genuine head mismatch (`CAH9085` vs `CAH9095`) while both trailer values are `CAJ2981`; row 13 has matching head `CAL5672` and a placeholder flatbed trailer value, so it is incomplete rather than a disagreement.
- The two Nakonde worksheets are parsed into head/trailer candidate observations. The flatbed sheet's separate unattached-trailer columns remain independent trailer candidates; the report explicitly does not create attachment relationships.
- `Unregistered_Ngwerere` contributes two trailer candidates and two head candidates from its distinct source rows; no relationship between those heads and trailers is inferred.
- Tracking exact matches remain observations only. The report includes status/geofence snapshots, not permanent yard assignments. No fuzzy matching is reported.
- Dashboard and empty Breakdown are excluded from identity creation. No production-asset comparison is possible yet because no production fleet assets have been imported.

### Review outcome
- The corrected report is internally consistent with the corrected CLI summary and the documented source-layout rules. No new parser defect or report/summary mismatch was identified in this review.
- The only high-priority human review item confirmed by these reports is the `Registered_21Miles` row 6 head conflict. Row 13 remains an incomplete comparison.
- This is a sign-off of the dry-run report's structure and stated safeguards only. It is **not** approval to create assets, merge identifiers, create attachments, assign yards, or import production data.
- Continue to preserve source provenance, raw values, worksheet row numbers, and independent list observations. Keep Google Sheets read-only. No production writes or asset import have been authorized.

### Next implementation step
- Prepare a **review-only proposed asset register and human-approval queue** from the staged observations. Keep it as generated report files (not database production rows), with one candidate per supported physical identifier/role and every source observation/provenance retained.
- Put ambiguous identity, role, duplicate, and attachment decisions into explicit review cases. Do not infer attachments from side-by-side list position except where the source layout explicitly labels an attached-trailer column; even then, preserve it as a proposed relationship for validation rather than silently writing it.
- Do not treat IT_REFERENCE observations as physical assets until the user confirms what the IT identifiers represent and how they map to actual fleet assets.

## Latest milestone — proposed asset register and human-approval queue prepared (18 November 2026)

### Review package generated from corrected dry-run report
- Input: `reports/reconciliation/reconciliation_dry_run_20261118_205440.json` (sync runs 1 and 2; 803 staged rows; 829 identifier observations).
- Output workbook: `Fleet_Management_Proposed_Asset_Register_Review_20261118.xlsx`.
- Supporting CSV exports: `proposed_asset_candidates_20261118.csv`, `human_approval_queue_20261118.csv`, `candidate_attachment_links_20261118.csv`, and `it_reference_observations_20261118.csv`.
- Workbook sheets: Read Me, Proposed Assets, Approval Queue, Candidate Links, IT References, All Observations.

### Candidate counts and interpretation
- 610 unique physical identifier candidates grouped by `(asset role, normalized identifier)`: 296 HEAD and 314 TRAILER. These are proposed identity candidates only, not 610 approved or imported production assets.
- 98 distinct IT references are separated onto their own sheet. They are not included in the physical candidate count and are not assigned a HEAD/TRAILER role.
- 829 raw identifier observations are retained in the All Observations sheet with source, sync run, worksheet, original row number, column, field, role, raw value, normalized value and classification.
- 302 source-declared head/trailer candidate links are listed separately. Every link is marked `REVIEW_REQUIRED`; none is a confirmed active attachment.
- The approval queue contains 7 issues: repeated trailer `AID4541ZM` across Ngwerere tanker source rows 37 and 97; Registered_21Miles row 6 conflict; row 13 incomplete trailer comparison; Nakonde tanker row 22 extra column C value; numeric trailer-like values `1289`/`1275` excluded by VIN-only extraction; the independent 21 Miles IT lists; and standalone unregistered Ngwerere heads/trailers.

### Safeguards / known limitations
- The 11 same-row repeated HEAD values and 11 same-row repeated TRAILER values in `Registered_21Miles` are parallel-section evidence, not automatically separate physical assets. They are grouped into one candidate identifier each, with provenance retained.
- `AID4541ZM` appears against two different Ngwerere tanker source rows and remains a HIGH review item; do not create overlapping active attachment relationships without human confirmation and effective dates.
- Nakonde tanker values `1289` and `1275` and the extra column C value `LJRT123A5S2311269` are not included as physical candidates because the current extraction did not classify them under approved A/B VIN mappings. They are explicitly surfaced for review rather than silently discarded or guessed.
- The workbook does not assign permanent yards, infer Kenmugala = Nakonde, create physical attachments, approve duplicates, or decide status.
- This is a report-generation step only. No DB writes, production asset imports, reconciliation decision writes, or Google Sheets writes were made.

### Next action
- User reviews the workbook, especially all 7 approval queue items and the 302 candidate links. Decisions should be returned by `Candidate Link Ref` / `Proposed Ref` and source provenance.
- After human decisions, prepare a separate import mapping and validation plan. No production import is authorized until the review decisions and import plan are explicitly approved.


## Latest milestone — user confirmed direction to proceed while preserving familiar spreadsheet workflow (18 November 2026)

### User's product direction
- Continue toward a web interface with a dashboard that improves visibility, optimization and progress.
- Do not make the interface feel unfamiliar or radically change how users currently work with the sheets.
- Preserve the recognizable spreadsheet pattern: familiar sheet groupings, row-oriented tables, existing column names/order and side-by-side head/trailer structures where the source uses them.
- Avoid unnecessary new columns, excessive statuses, and filling blank cells with repeated “No” / “N/A” values. Keep blank source cells visually blank unless a meaningful status is known.

### Interface direction prepared
- Created `Fleet_Management_Interface_Direction_20261118.md`.
- The proposed web navigation is intentionally small: Dashboard, Fleet Register, Attachments, Tracking / Yard Activity, Review Queue, and Sync & Reports (administrative).
- Dashboard is a summary layer, not a replacement for the register. KPI totals must be validated; unverified source dashboard totals must not be presented as authoritative.
- Fleet Register keeps familiar source views (Ngwerere Tankers/Flatbeds, 21 Miles Registered/Unregistered, Nakonde Tankers/Flatbeds) and preserves their source-specific column patterns. IT lists remain simple reference lists, not physical assets.
- Search, filters, row detail/provenance, exports and review tools are added around the familiar tables rather than replacing their structure.
- Head and trailer remain separate assets. Attachment relationships require explicit approval and effective dates. Tracking/geofence remains time-stamped observation, not permanent yard assignment.

### Meaning of “proceed with all confirmation”
- Treat the user's message as authorization to continue project implementation and UI planning.
- Do not interpret it as factual approval of every candidate, disputed identifier, or the 302 proposed attachment links. The AID4541ZM repeated trailer, Registered_21Miles row 6 conflict, incomplete row 13 and other unresolved source mappings remain pending until individually resolved. This protects the register from incorrect fleet records.

### Implementation boundary / next required input
- The workspace currently has schema, config/extraction/staging/reconciliation scripts and the review workbook, but not the complete live project directory or existing UI source pages.
- Before changing the UI, obtain the current project ZIP or the existing dashboard/page, shared header/navigation, stylesheet and relevant JavaScript files. Then inspect and extend the existing interface incrementally rather than replacing it.
- No production asset imports, production database changes, or Google Sheets writes were performed in this milestone.

---

## 2026-11-18 — First FleetDesk web interface milestone

User clarified that there is no legacy frontend to preserve; the frontend is being built new. Direction: polished fleet dashboard, left navigation for reports/workspaces, mobile responsive layout, AJAX search and forms, while retaining familiar spreadsheet-like fleet tables and conservative data rules.

Created in project folder:
- `index.php` — responsive app shell and six workspaces: Overview, Fleet Register, Attachments, Tracking & Yards, Review Queue, Sync & Reports.
- `includes/bootstrap.php` — shared PDO and JSON response helpers.
- `api/dashboard.php` — read-only validated-record dashboard counts and latest source sync activity.
- `api/search-assets.php` — AJAX asset search with identifier/make/model search, type and registration filters, 20-row pagination.
- `api/reports.php` — read-only sync and reconciliation report data.
- `assets/css/app.css` — responsive visual system, sidebar, mobile drawer, cards, spreadsheet-style tables.
- `assets/js/app.js` — AJAX loading, debounced search, filters, pagination, report refresh and CSV export.
- `README_UI_INSTALL.md` — local installation notes.

Implementation safeguards:
- UI endpoints are SELECT-only; no asset/attachment/review/yards writes.
- Google Sheets remain read-only.
- Dashboard numbers derive from `assets`, sync and review tables, not source spreadsheet dashboard totals.
- Fleet search joins the existing `assets`, `asset_types`, `yards`, and `asset_identifiers` schema.
- Attachments and tracking pages are intentionally informational placeholders until validated workflows are designed.
- The UI does not treat candidate attachment relationships as approved links.
- No schema migration, production import, or source spreadsheet write was performed in this milestone.

Validation: `php -l` passed for all PHP files in the supplied project archive plus the new UI files. JavaScript syntax checked with `node --check` where Node is available. Database-backed browser testing was not possible in this environment because the user's local XAMPP/MySQL instance is not accessible here. User should preserve local `config/config.php` and credentials, back up the project folder, and copy only the new UI files/folders as described in `README_UI_INSTALL.md`.

## UI v1.1 correction (2026-11-18)
- User reported dashboard showing zero data and supplied screenshot.
- Confirmed this is partly expected: no assets have been imported into `assets`; the 610 candidate assets remain in the human-review workbook and must not be presented as confirmed records.
- Identified a UI query mismatch: dashboard counted `spreadsheet_source_rows`, while the completed staging process stored its 803 raw observations in `spreadsheet_source_row_versions`.
- Corrected dashboard staged-row count to prefer `spreadsheet_source_row_versions`, with fallback to `spreadsheet_source_rows`.
- Corrected reconciliation review query fields to schema names: `case_status`, `severity`, `description`, `detected_at`; open review count uses `OPEN` and `IN_REVIEW`.
- New package: `fleet-management-ui-v1.1.zip`. PHP lint passed for the revised dashboard and reports endpoints.
- This patch does not import assets, approve candidate links, modify source spreadsheets, or change database records.

## 2026-11-18 — UI feedback and location separation reaffirmed

- User explicitly reaffirmed that **Ngwerere and 21 Miles must remain separate locations** in the web interface and reporting. Do not combine them into a single “Ngwerere / 21 Miles Trucks” location, yard, filter, summary, or register grouping.
- Present Ngwerere and 21 Miles as distinct location views/filters. Preserve source worksheet distinctions within each location. Nakonde remains separate as well; Kenmugala remains a separate location name and must not be mapped to Nakonde without explicit approval.
- Dashboard and register should make location separation visible and understandable, while preserving the familiar spreadsheet-style row and column patterns.
- User is testing the dashboard data-fix patch (`fleet-dashboard-data-fix.zip`). Await their result before making further changes to the running UI.
- Confirmed database diagnostic: database `fleet_management`; 2 sources; 14 worksheets; 2 successful sync runs; 803 rows in `spreadsheet_source_row_versions` (358 for `NGWERERE_21MILES`, 445 for `CONSOLIDATED_TRUCKS`); 0 rows in `spreadsheet_source_rows`; 0 confirmed assets; 0 reconciliation cases. Diagnostic was read-only.
- Expected dashboard state after data-fix: confirmed assets/heads/trailers remain 0; staged source rows should show 803; recent activity should show both successful runs.
- Continue updating this handoff after every meaningful project change. Never include credentials or passwords.

## 2026-11-18 — UI v1.2 data visibility investigation

- User reported that Dashboard and all other pages appear blank despite the Google Sheets connected indicator.
- Re-inspected the actual uploaded `fleet-management-ui-v1.1.zip` and the original schema, rather than assuming the browser state.
- Root causes confirmed from code:
  - The dashboard API and JavaScript used different response contracts across successive patches. The dashboard patch returned `stats.rows`, `stats.reviews`, `stats.attachments`, `runs`, and `last`; the active v1.1 JavaScript expects those keys, while an earlier API variant used different names. UI must keep the API/JS contract aligned.
  - Reports page reads `data.sync` and `data.reviews`; the corrected API now returns those names and schema-correct columns (`case_status`, `severity`, `description`, `detected_at`).
  - Fleet Register is intentionally based on `assets`; it will be empty while no approved assets have been created.
  - Attachments and Tracking/Yards are still placeholder workspaces because no approved attachment links or tracking snapshots have been imported into their operational tables. The empty states must explain this, not imply a failed connection.
  - Review queue is empty because `reconciliation_cases` has 0 rows. The 7 dry-run issues are in the generated review workbook/reconciliation report, not inserted into the database.
- Created `fleet-management-ui-v1.2-data-fix.zip`:
  - Replaces `api/dashboard.php` and aligns response keys with the v1.1 JavaScript.
  - Replaces `api/reports.php` with schema-correct sync/review queries and expected JSON response shape.
  - Adds read-only `api/source-observations.php` querying `spreadsheet_source_row_versions`.
  - Adds a Source observations page to `index.php`, with AJAX search, pagination, raw values, source/worksheet/row provenance, and a worksheet-name location filter that keeps Ngwerere, 21 Miles and Nakonde separate.
  - Extends `assets/js/app.js` for the new source page.
  - Does not create assets, attachments, reconciliation cases, or tracking snapshots; does not write to Google Sheets or production records.
- PHP syntax checks passed for the new dashboard, reports, source-observations endpoints and index.php. Node.js syntax check passed for app.js.
- Not yet tested against the user's local XAMPP database/browser. User should install the patch and verify the new Source observations page shows the 803 staged rows. Fleet Register will remain 0 until approved assets are imported. Attachments/tracking will remain empty until validated operational data is created. Review queue remains empty until approved issue cases are explicitly loaded through a controlled workflow.
- Always preserve separate location views for Ngwerere, 21 Miles, Nakonde and Kenmugala. The new source filter is based on worksheet-name matching and should be checked against actual sheet names in browser.


## 2026-11-18 — UI v1.3 data-loading diagnostics patch prepared

- Reopened the current uploaded `fleet-management.zip` and inspected the active `index.php`, `assets/js/app.js`, dashboard/reports/search/source-observations APIs, shared bootstrap and diagnostics endpoint.
- Confirmed the uploaded archive does not include the reviewed SQL schema file or a database export, and this environment cannot connect to the user's local XAMPP/MySQL instance. Therefore live SQL/API success cannot be claimed from static review alone.
- Confirmed all application PHP files in the project archive passed `php -l`; `assets/js/app.js` passed `node --check`. The long Composer vendor tree was not exhaustively linted because the bulk lint command exceeded the execution time limit; the application PHP files themselves were checked.
- Found a definite page-rendering defect: `source-data` was missing from the `$names` page-title map even though the page was implemented and linked. Added the mapping.
- Found a diagnosability defect: the shared AJAX helper did not check HTTP status or JSON validity, and dashboard failures were silently swallowed. Updated the helper to report non-JSON/server errors and made the Dashboard, Fleet Register, Reports and Source Observations show actionable errors instead of blank states.
- Prepared `fleet-management-ui-v1.3-debug-fix.zip` containing only `index.php`, `assets/js/app.js`, and installation notes. No config, schema, vendor files, credentials, Google Sheets, or database records are changed.
- Next validation on the user's XAMPP machine: install the patch, open `/api/diagnostics.php`, and confirm the last verified state (database `fleet_management`, 2 sources, 14 sheets, 2 successful runs, 803 staged rows, 0 confirmed assets). Then inspect any exact API error displayed by the UI.
- No production asset imports or writes are authorized or performed. Preserve Ngwerere, 21 Miles, Nakonde and Kenmugala as separate location views; Google Sheets remain read-only.

## 2026-11-18 — UI v1.4 follow-up after live API verification

### User-provided live endpoint results
- `api/dashboard.php` returns `ok=true`, staged `rows=803`, two successful sync runs (358 and 445 rows), zero confirmed assets, zero reviews, and database `fleet_management`.
- `api/reports.php` returns `ok=true`, two sync records and an empty review list.
- Therefore the dashboard and reports data APIs are responding successfully. The zero asset and review counts are consistent with the current review-only stage and must not be fabricated as production assets or database review cases.

### Source inspection and correction
- Re-opened the exact uploaded `fleet-management.zip` and inspected `index.php`, `assets/js/app.js`, and the relevant APIs.
- Found that `index.php` renders a navigation link and a `source-data` page branch, but its `$names` page map omitted the `source-data` key. The page router therefore resets `?page=source-data` to the dashboard rather than rendering Source Observations.
- The frontend script had silent failure paths in the original archive. The previously prepared v1.3 script improves API error visibility; v1.4 retains this and adds explicit report response validation/error messages.
- Prepared `fleet-management-ui-v1.4-fix.zip`: fixes the source-data page map, adds cache-busting query versions to CSS/JS so the browser fetches the updated files, and makes report loading errors visible. PHP and JavaScript syntax checks passed.
- Patch is frontend-only. It does not modify database rows, staging data, asset/review records, Google Sheets, or credentials.

### User verification required
- Install v1.4 over a backed-up project, then hard refresh.
- Verify `/?page=reports` visibly shows the two sync runs; `/?page=source-data` opens Source Observations; `/api/source-observations.php` returns `ok=true` and `total=803`; dashboard shows 803 staged rows and two recent runs.
- If reports API continues returning the supplied two sync rows but the page remains blank after v1.4, request a screenshot and the exact endpoint response from the same browser session; the API/database are already confirmed healthy.
- Fleet Register, Review Queue, Attachments, and Tracking/Yards retain their documented empty/placeholder state until validated records are explicitly imported or approved.

## 2026-11-18 — Dashboard display resilience follow-up

- Read the supplied handoff and inspected the current local project files. Confirmed the live database is `fleet_management`; read-only dashboard API returns 803 staged rows, two successful sync runs, 0 approved assets and 0 open review cases. Reports API returns the same two runs and an empty review list; Source Observations returns 803 total rows.
- Made `assets/js/app.js` dashboard rendering validate the stats response, coerce missing/optional numeric values safely, and guard optional DOM elements. This prevents an absent optional count or malformed response from aborting the whole dashboard update.
- Added a dashboard note linking to Source Observations and explaining that staged source rows are not approved fleet assets. Updated CSS/JS cache versions to 1.5.
- PHP lint passed for the index and data APIs; `node --check` passed for `assets/js/app.js`. Read-only CLI execution of dashboard and reports endpoints returned `ok=true`, 803 staged rows, two sync runs, 0 assets and 0 review cases.
- No database records, Google Sheets, credentials, or asset/review data were changed. Fleet Register remains empty until validated assets are imported; Attachments and Tracking remain placeholders until their validated records are available.

## 2026-11-18 — Initial controlled asset import and review queue

- User directed that unambiguous assets be imported while unresolved items remain pending in a separate table for tracking.
- Added `google/import-approved-candidates.php`, a CLI-only, transactional, idempotent import using the reviewed reconciliation JSON and the existing DB schema. It imports classified HEAD/TRAILER vehicle identifiers only; IT-only references are excluded. Source provenance is retained in identifier notes. No attachments, tracking observations, inferred yard assignments, or source-sheet edits are made.
- Executed successfully against `fleet_management`: 610 unique vehicle assets and 610 primary identifiers imported (296 truck heads, 314 trailers); 123 open reconciliation cases created. The review cases preserve source evidence in JSON and include repeats, the Registered_21Miles row 6 disagreement, and its incomplete row 13. These are visible through the separate Review Queue.
- Read-only verification: 803 staged source rows remain; dashboard API returns 610 assets and 123 reviews; reports API returns 2 sync runs and now allows up to 500 review rows so all current 123 cases are visible. Attachment count remains 0; no permanent yard assignments were created.
- PHP syntax checks passed for the importer and UI endpoints; JavaScript syntax check passed. The first import attempt failed validation and rolled back cleanly; the corrected retry committed in one transaction.
- Preserve the pending review cases until the user resolves them. Do not infer attachments or mark source assets missing.

## 2026-11-18 — Fleet terminology, location reports, and audit trail

- User clarified the fleet structure: **Horse** is the powered unit; trailers are **Tanker** or **Flatbed**. Horses attach to either trailer type only through a separate confirmed attachment relationship.
- Updated asset type display names in the database to Horse, Tanker, and Flatbed. The 22 imported trailer candidates previously held as `OTHER_TRAILER` were reclassified as Flatbed because they came from the Nakonde Flatbed worksheet. UI filters, dashboard totals and asset search use the three user-facing types.
- Dashboard chart now shows Horse/Tanker/Flatbed counts and a yard chart. Added standalone Location Report and Unregistered Assets pages. Counts are grouped by source-reported worksheet/yard labels, not live GPS. Current location report: Ngwerere 492 assets (247 Horses, 203 Tankers, 42 Flatbeds); Kenmugala 93 (36/24/33); 21 Miles 25 (13/12/0). These source-level groups retain the distinction between Kenmugala and Nakonde; no current yard/GPS assignment was made.
- Unregistered Assets page/API returns 97 records with source-location filters (Kenmugala 93, Ngwerere 4) and Horse/Tanker/Flatbed classification.
- Added an Audit Trail report/API backed by `asset_change_history` and `activity_logs`. Added 610 `INITIAL_IMPORT` history events for the imported assets. They correctly show `System import (user not recorded)` because the current app has no login/edit workflow and the original import did not capture a person. Future named-user attribution requires write actions to run under an authenticated user and populate `changed_by` / `user_id`.
- PHP lint and Node syntax checks passed. Dashboard API returns the counts above; unregistered API returns 97 rows; audit API returns 610 creation events. No spreadsheet writes, attachment links, or live yard assignments were made.

## 2026-11-18 — 21 Miles visibility, register location filter, and operations percentages

- User reported 21 Miles was not visible as a distinct location and requested location filtering in the register. Dashboard and search APIs now label this location explicitly as **21 Miles Yard**. Fleet Register location dropdown filters the full result set by source-reported location; verified `21 Miles Yard` filter returns 25 records, all labeled with that location. Dropdown values are populated from the available source locations.
- Changed yard charts to use equal-size location cards with prominent totals and within-yard Horse/Tanker/Flatbed composition bars, so the 21 Miles Yard card remains visible even though its current captured asset count is smaller than Ngwerere.
- Added the **Source Operations Report** based on the user's supplied screenshot. It shows the latest 214-object tracking snapshot with computed status percentages (148 Parking / 69.2%, 55 No Connection / 25.7%, 3 In Trip/Moving / 1.4%, 8 Other / 3.7%), top geofences, and the source Dashboard's own metrics and percentages as explicitly unverified.
- The source Dashboard says 11 In Trip/Moving (5.1%), while the latest raw tracking status values classify 3 as Moving (1.4%); both are shown with distinct labels so the discrepancy is visible. The source Dashboard's 21 Miles total of 190 (85 registered + 105 unregistered) is also shown as unverified; row-level capture remains 12 rows per Registered_21Miles section and 66/32 values in each independent unregistered IT list.
- Kept the two independently maintained 21 Miles lists separate. Source operations API now reads the latest successful run per source, rather than only one source's latest run.
- PHP lint and JavaScript syntax checks pass. Operations API returns 214 tracking objects; source Dashboard percentages; and independent list counts. Location-filter API returns 25 records for 21 Miles Yard. No source spreadsheet data was changed.

## 2026-11-19 — Certificate register, approvals, and expiry alerts

- Added the certificate workflow migration and applied it to the local `fleet_management` database. It creates certificate types for Horse, Tanker, Flatbed, and Consolidated Fleet; driver assignment support; versioned certificate submissions; append-only certificate events; and default expiry/alarm settings.
- Added `certificates.php` with registration lookup, asset/yard/type details, current Horse driver assignment when recorded, prior approved certificate and expiry, upload form, complete submission register, and certificate audit history. Trailer submissions require choosing Tanker or Flatbed. Consolidated fleet certificates do not require an asset registration.
- Added reviewer approval and decline workflow. A new submission stays pending and does not replace an existing approved version until approval. Approvers cannot review their own submission. A decline reason is required and stored in the event log and activity log.
- Added PDF/JPG/PNG upload validation (2 MB maximum, matching the current XAMPP `upload_max_filesize = 2M`). Certificate files are stored outside the public application directory under `C:/xampp/secure/fleet/certificates` with random storage names; authenticated document views are logged.
- Added expiry lists to both dashboard indexes and certificate register, with configurable lead days and browser-local alarm time in Certificate Settings. The sound alert needs the dashboard/register open and a one-time click on “Enable sound alarm” to satisfy browser audio rules.
- Added ADMIN, REVIEWER, and VIEWER account handling using existing user/role tables. The current database has no user accounts, so the first administrator must be created through the certificate page’s first-use setup form before users can submit or approve certificates. Existing driver assignments are not populated; the lookup clearly reports when no driver is recorded.
- Local HTTP smoke checks returned 200 for the register, approval, settings, and expiry endpoints; approvals correctly return 401 while signed out. PHP lint passed for all new and modified PHP pages/APIs; JavaScript syntax check passed. No certificate documents or accounts were created during verification.

## 2026-11-19 — Main index routing and sidebar navigation

- Enabled Location, Tracking & Yards, Source Operations, Source Observations, and Unregistered pages directly on `index.php`; removed the redirect to the alternate index.
- Changed `index_notsure.php` to redirect to the main index (preserving its requested page), so it no longer renders a separate “unsure” interface when entered by URL. No app navigation points to it.
- Removed the Tracking status chart and duplicate Ngwerere tracking-status table from Source Operations. The dedicated Tracking & Yards page remains available.
- Made the sidebar navigation independently scrollable on short viewports and bumped the stylesheet cache version.
- Verified Source Operations returns HTTP 200 from the main index, the alternate URL returns a redirect, and the source-operations page no longer renders its Tracking status chart. PHP syntax checks pass.

## 2026-11-19 — Certificate lookup, shared sidebar, and AI transport fix

- Fixed the certificate lookup SQL ambiguity (`id` is now qualified as `cs.id` in the prior-certificate join). Exact lookup for Flatbed `LZ1B23GE9S0032571` now returns its asset details.
- Added near-match guidance without auto-selecting an asset. The registration typed in the reported screen is one character different from the closest database registration (`O` vs `0`); the UI now offers that candidate for the user to verify.
- Rebuilt certificate pages inside the FleetDesk shell with the same scrollable left sidebar and mobile menu toggle as the main indexes.
- Apache’s PHP runtime does not load its cURL extension because an old Apache DLL conflicts with the current PHP extension. AI API calls now fall back to PHP HTTPS streams with peer/name verification and the configured CA file. A local AI request for case 125 returned an advisory successfully; no fleet records changed.
- PHP lint and JS syntax checks pass; certificate settings page renders the shared sidebar. Temporary diagnostic endpoints were removed.

## 2026-11-22 — Google Workspace sign-in and compact navigation

- Added an Admin-controlled Google Workspace sign-in toggle and configuration fields under Certificate Settings. The callback URL must exactly match a Google OAuth web-client redirect URI. The Workspace domain is enforced against the verified Google ID token hosted-domain claim; OAuth state and nonce are checked, and the Google subject is the stable account identifier.
- Google client secrets are encrypted with AES-256-GCM and stored under the configured secure directory outside the public app. The secret is never returned to the browser. The SSO switch defaults off; local password login remains available until an administrator configures and enables Google sign-in.
- Added the separate built-in `APP_CONTROLLER` role. The specified address `andrew.okpako@harvestgl.net` was provisioned as a passwordless App Controller account (user 7), with no Admin role. Its first Google sign-in is linked by verified email, then requires authenticator enrollment.
- App Controller has full permission override and can assign/change roles, including App Controller. Admin cannot access global Settings, Certificate Settings, or role management. Admin can use the separate Staff accounts page to add passwordless Workspace users and assign ordinary roles; the App Controller role is filtered and rejected server-side for Admin. Admin can configure only the initial Google OAuth connection through the restricted one-time `google-setup.php` page while SSO is off.
- Added TOTP authenticator enrollment and verification for App Controller. OTP secrets are AES-256-GCM encrypted outside the web directory, codes have a 30-second period, one prior/current/next interval is accepted, reused counters are rejected, and controller verification expires after eight hours. The bootstrap migration adds the OTP columns and creates the protected role.
- Users created in Staff accounts need only a name and Workspace email. A verified Google sign-in links to the pre-created email or provisions an unassigned account. Unassigned users land on a welcome page explaining that an administrator must assign a role first. When SSO is enabled, password sign-in is rejected.
- Added the users schema migration `20261122_google_workspace_signin.sql` and applied it locally: `password_hash` is nullable and `google_sub` has a unique nullable index.
- Grouped the application sidebar into collapsible Overview, Fleet operations, Safety & dispatch, Reports, and Administration sections. The role editor's sidebar order is now a compact two-column list with an internal scroll area and numbered move controls.
- Verification: PHP lint and JavaScript syntax checks passed. RFC 6238 TOTP test vector matched (`287082`). Permission checks confirmed Admin cannot access Settings/role management but can access Staff; App Controller has the full override. Local HTTP checks returned 200 for login/status and 302 from OAuth start while SSO is off. Database role/account/schema changes are applied. OAuth round-trip still requires the Workspace OAuth web-client credentials and callback URL to be configured on `google-setup.php` and registered in Google Cloud Console.

## 2026-11-22 follow-up: sign-in domain option, staff modal, report spacing, and waybill
- Fixed the malformed `<title>` on `google-setup.php`; authenticated Admin HTTP check returned 200 with the setup form.
- Added a default-on Workspace hosted-domain restriction toggle to initial Google setup and certificate settings. When off, any Google account with a verified email can authenticate; FleetDesk still only grants permissions attached to that specific app user, and unassigned accounts go to the access-pending welcome page. Login status now communicates the selected mode. Google OAuth still needs client ID/secret configuration and SSO remains disabled until configured.
- Changed staff enable/disable confirmation from browser `confirm()` to an accessible FleetDesk modal. Admin staff-list API hides App Controller accounts and does not offer the protected role; App Controller visibility/management remains restricted to that role.
- Added consistent 16px vertical spacing between adjacent report panels.
- Rebuilt `api/trip-waybill.php` as a branded one-page A4 operational waybill with trip status/timestamps, route, customer, schedule, odometer, Horse/Trailer/driver, cargo, notes, signature lines, and SAP invoice disclaimer. The route now requires `page.trips` permission.
- SQL injection review: user search values use prepared statements; report filter SQL is assembled from fixed allowlisted clauses; pagination limits are bound as integers in source observation, legacy certificate, sync queue, source-change, and audit queries. Imported sheet source IDs now use a prepared lookup. PDO native prepares remain enabled.
- Verification: PHP lint passed for changed PHP entrypoints, `node --check` passed for staff/certificate scripts, local staff GET confirmed App Controller exclusion, source observation/source change/audit GETs returned success, and the waybill rendered as a single A4 page for visual review.
- Remaining database hardening: FleetDesk still connects as MySQL `root` with an empty local XAMPP password. Provisioning a restricted DML-only runtime account was attempted but execution policy rejected the database-user creation command; no grants or database accounts were changed. This remains a separate security follow-up because it limits the impact of a future injection flaw.

## 2026-11-22 — Waybill branding controls

- Added App Controller Settings > Waybill branding fields for the company name and an optional PNG/JPEG logo. Browser-side conversion resizes the image onto a white background; the API verifies and stores the JPEG outside the public web directory at `C:/xampp/secure/fleet/waybill-brand.jpg`.
- Generated operational waybills now print the configured company name and logo, with the FleetDesk mark used if no logo is configured. The PDF remains an operational trip sheet and explicitly points financial invoices/tax documents to SAP Business One.
- Verification: PHP lint passed for `index.php`, `api/waybill-settings.php`, and `api/trip-waybill.php`; `node --check` passed for `assets/js/waybill-settings.js`. User should set the company name/upload logo in Settings before branded waybills are generated.
- Google OAuth guidance: a personal Google account may own the Cloud project; choose External audience, register the exact callback shown by FleetDesk, and add accounts as test users while the OAuth app is in Testing. Disabling the Workspace-domain restriction broadens which verified Google accounts may sign in, but FleetDesk role permissions still apply. The designated App Controller must sign in using `andrew.okpako@harvestgl.net` and complete OTP enrollment.

## 2026-11-22 — Local OAuth setup validation fix

- The initial Google OAuth setup API incorrectly required every callback to use HTTPS, rejecting the app's valid localhost callback even though Google permits HTTP localhost redirects for development. It now permits HTTP only for `localhost`, `127.0.0.1`, and `::1`; all non-local callback URLs must use HTTPS.
- Workspace domain remains optional when the domain restriction checkbox is off. The setup error now clarifies both requirements.
- The certificate-settings API uses the same callback URL validator to prevent insecure public HTTP redirects.
- Verification: PHP lint passed for the OAuth helper and both setup/settings APIs. URI validation accepted localhost and HTTPS tunnel callbacks, and rejected a public HTTP callback.

## 2026-11-22 — Google setup database constraint fix

- Apache's error log showed the Google OAuth bootstrap was failing on `system_settings.setting_value` CHECK `JSON_VALID`, not credentials or file permissions. Settings are plain text throughout FleetDesk, so this database-only CHECK rejected valid values such as `harvestgl.net` and callback URLs.
- Added `database/migrations/20261122_system_settings_plain_text.sql` to remove the incompatible column CHECK by restoring the intended LONGTEXT column definition, and applied it to the local FleetDesk database. No existing setting values were changed.
- Verification: `SHOW CREATE TABLE` confirms the JSON check is gone. A transaction successfully inserted `harvestgl.net` as a setting and rolled back; a follow-up query confirmed the temporary probe row was absent. OAuth PHP lint passed.
- User can retry Save Google sign-in setup. The prior error occurred after secure secret encryption succeeded but before settings rows were saved; resubmitting stores the remaining configuration.

## 2026-11-22 — Sign-out control

- Added a visible Sign out action to the main FleetDesk top bar and the one-time Google OAuth setup page. Certificate pages already expose their sign-out control.
- The action posts to the existing same-origin authentication logout endpoint, which clears the full FleetDesk PHP session (including Google auth and App Controller OTP verification state), regenerates the session ID, then redirects to the login screen.
- Verification: PHP lint passed for `index.php` and `google-setup.php`; JavaScript syntax checks passed; a same-origin POST to the logout API returned HTTP 200 with `{\"ok\":true}`.

## 2026-11-22 — Google sign-in callback failure

- Reviewed Apache access logs: Google redirected back with an authorization code, but the callback request did not include OAuth `state`, so FleetDesk correctly rejected the login as uncorrelated.
- Changed Google authorization URL construction to use the Google PHP client's `setState()` and supported query-parameter API instead of manually appending state/nonce strings.
- Added safe callback-stage logging (state, provider denial, token exchange, token verification) without logging OAuth codes or secrets, and clearer login-page messages for each stage.
- Verification: PHP lint passed. A local request to the Google authorization start endpoint returned HTTP 302 with exactly one state and nonce parameter and the configured localhost callback URI. Actual Google account sign-in still needs a browser retry.

## 2026-11-22 — Idle logout, account identity, role permissions, and OTP scan

- Added a Settings control for inactivity logout (5 minutes to 8 hours, default 30). Timeout uses user input events rather than background polling and is enforced by the PHP session guard, so background sync/API requests cannot keep an idle user authenticated. Added `database/migrations/20261122_session_idle_timeout.sql` and initialized the local database setting to 30 minutes.
- Added a same-origin activity endpoint, a server-protected settings endpoint, and a browser timer on the main app, certificate pages, and initial Google setup. An expired session is cleared and sent to login with an inactivity message. Unauthenticated activity requests return 401.
- Main FleetDesk and certificate headers now show the signed-in user's full name and initials. The Settings user-role assignment table now shows email as a column.
- App Controller can now edit the built-in Admin role's permissions/menu while the role name stays fixed. APP_CONTROLLER remains immutable; custom roles cannot receive the protected role-management or user-management actions. The hard authorization rule still denies Admin access to Settings and role management.
- Added local QR generation for App Controller TOTP enrollment using the MIT-licensed qrcode-generator 2.0.4 library, including UTF-8 otpauth encoding. The QR is generated in the browser and never sent to a third-party QR service; manual key entry remains available. The user's current App Controller is already enrolled, so the QR appears only during an uncompleted enrollment and does not expose an enrolled secret.
- Fixed the waybill logo preview's hidden-state CSS so an absent logo no longer renders a broken image icon beside the placeholder.
- Verification: PHP lint passed for affected PHP pages/endpoints; JavaScript syntax checks passed; a sample authenticator URI generated a valid 53-module QR SVG; login page returned HTTP 200 and showed the timeout message; unauthenticated activity API returned 401; local timeout setting is 30 minutes.

## 2026-11-22 — Session timeout selector clipping

- The shared `.panel { overflow:hidden }` style clipped the native idle-timeout select menu at the Session security card boundary. Added a dedicated session-settings panel class with visible overflow and elevated stacking order so its dropdown can extend over the next card. Bumped the main app stylesheet URL to refresh the browser cache.
- Verification: PHP lint passed for `index.php`; confirmed the new class and cache version are present.

## 2026-11-22 — Trip actions on Full Truck and dedicated asset links

- Moved trip Dispatch and Complete controls out of Trip management and onto the matching Horse + Trailer + assigned driver row in Full Truck. Trip management remains the place to create trips and approve/decline requests; waybill download remains in the trip register.
- Dispatch and completion now open a confirmation modal. Completion collects the ending odometer in that modal, replacing the old scattered inline field behavior.
- Added `fleet_trips.dispatched_at` and a five-minute server-side minimum between dispatch and completion. The API also rejects completion before dispatch and prevents the same Horse, Trailer, or driver from being tied to multiple approved/in-progress trips. Migration `20261122_trip_dispatch_timestamp.sql` was applied to the local `fleet_management` database.
- Added a separate Asset links page for requesting Horse + Trailer links and requesting removal. Both operations remain subject to a different reviewer. Link removal is blocked while an approved or in-progress trip uses that combination.
- Confirmed Sheet scope in `google/push-fleet-sheets.php`: approved link state and history is mirrored to `_FleetDesk_Horse_Trailer_Links` in each configured workbook on the scheduled sync. Requests do not alter either Sheet before approval. Trip records/statuses remain app-only because the current workbook mirror schema has no trip ledger; the waybill remains downloadable from FleetDesk.
- The original user-owned workbook tabs remain untouched. The existing Fleet Sheet sync toggle controls writes to FleetDesk-owned tabs.
- Verification passed: PHP syntax checks passed for the page and modified APIs; Node syntax checks passed for trip, Full Truck, Asset links, and operation approval JavaScript. The local database confirms the dispatch timestamp column/index, the five-minute threshold boundaries (299 seconds blocked, 300 seconds allowed), and backfilled the one pre-existing IN_PROGRESS trip from its approval time (the earliest defensible dispatch point). Full Truck API returns the expected unauthenticated 401 JSON when called without a session. One existing trip metadata field was backfilled as described above; no trip status, asset data, or Google Sheet data was changed.


## 2026-11-22 — Prevent duplicate approval requests

- FleetDesk now rejects a second pending request for the same item/change across asset, location, driver, assignment, archive, Full Truck readiness, and Horse + Trailer link/unlink workflows. Pending link/unlink requests also lock both involved assets from conflicting link requests. The API checks inside the transaction, so a second browser tab or direct request cannot bypass the rule.
- Trip creation now rejects another pending trip for the same Horse + Trailer + driver. The trip selector labels and disables combinations that already have a pending trip, and explains that the reviewer must decide first.
- Certificate submission rejects a duplicate pending certificate for the same asset and catalog type. The certificate form checks pending status as the type/asset is selected and displays a pending message before submission; the API repeats the check before saving.
- Fleet operation rows show “Pending approval” in place of a duplicate action. Asset links show pending removal status and exclude assets in pending link requests from new-link selection. Readiness already exposed its pending status and now retains the request rather than superseding it when the same request is sent again.
- Approved-only Google Sheets sync remains unchanged. Pending requests remain in FleetDesk and do not write to either workbook; approved changes continue through the existing scheduled sheet sync and settings toggle.
- Verification: PHP lint passed for the changed APIs; Node syntax checks passed for operational, asset-link, trip, and certificate scripts. No real approval records or Google Sheet rows were modified during verification.


## 2026-11-22 — Sidebar navigation icons

- Replaced the sidebar's Unicode character icons with consistent inline SVG line icons for every menu item and menu group. This avoids font-dependent symbols rendering inconsistently and gives menu entries recognizable shapes.
- Made each collapsible group title explicit in the markup and replaced the text-chevron glyph with a CSS chevron. The section names remain visible whether the group is expanded or collapsed.
- Bumped the app stylesheet cache version so browsers load the new icon styling.
- Verification: PHP lint passed for the shared navigation helper and `index.php`; SVG paths are embedded locally and require no external icon library.


## 2026-11-23 — Sidebar menu hierarchy

- Increased sidebar section heading weight and contrast, and made section labels slightly larger. Submenu links use a lighter weight, with the selected submenu retaining bold emphasis.
- Bumped the app stylesheet version to refresh cached sidebar styles.
- Verification: `index.php` PHP lint passed.


## 2026-11-23 — Repair completion modal fields

- Repair work-order mode-specific fields were marked hidden and disabled in JavaScript, but the shared label display rule overrode the browser's hidden behavior. This made edit-only fields appear in the repair-completion modal as visible, non-editable controls.
- Added an explicit hidden-state rule for inactive mode-specific controls and bumped the app CSS cache version. Repair completion now shows only its repair notes and completion confirmation controls; edit mode retains issue summary, priority, target date, diagnosis, and update notes.
- Verification: PHP lint passed for `index.php`; JavaScript syntax check passed for `assets/js/work-orders.js`.


## 2026-11-23 — Repair work-order update action mapping

- The Update details modal submitted the UI mode name `edit`, while the work-order API expects the action `update`. The API therefore rejected saves with “Choose a valid repair work order action.” The form now maps the edit UI mode to the API action and keeps other modal actions unchanged.
- Verification: JavaScript syntax check passed for `assets/js/work-orders.js`; API action names were compared against the form action mapping.


## 2026-11-23 — Repair update script cache refresh

- Follow-up screenshot confirmed the browser still sent `edit` after the action mapping fix; the work-order script URL was still versioned `v=1`, so the browser reused its cached copy. Bumped it to `v=2` to force the corrected `update` mapping to load.
- Verification: confirmed the served page references `work-orders.js?v=2`, the local JS maps edit to update, and PHP lint passed for `index.php`.


## 2026-11-23 — Repair history attribution and notes

- Repair update events now include the exact notes entered in the Update details modal, so each saved note remains visible in the event history as well as the current repair register.
- History entries now display the event type, actor name, timestamp, and details in separate readable blocks. Styling adds wrapped text and card separation for long notes.
- Event actor lookup now uses a left join and falls back to the user email or “Former user” if a name is unavailable.
- Verification: PHP syntax checks passed for work-order APIs and index; JavaScript syntax check passed for the work-order renderer.


## 2026-11-23 — Initiation date filters for reports

- Added From and To date inputs to report tables with an initiation/submission timestamp column (initiated, submitted, raised, created, reported, or opened date/author).
- Date range filters combine with the smart text filter, refresh pagination counts, and are included in CSV exports.
- Bumped the shared report script version so browsers load the new filtering behavior.
- Verification: JavaScript syntax check passed for `assets/js/report-export.js`.


## 2026-11-23 — Keep report status and action columns readable

- Shared report enhancement now tags Status/State and Action/Review columns by their headers across report tables, including asynchronously loaded certificate tables.
- These columns stay on one line with intact action buttons. Descriptive report columns continue to wrap long content.
- Bumped the shared stylesheet and report script versions on both the main app and standalone certificate page.
- Verification: JavaScript syntax check and PHP lint passed for both page entry points.
