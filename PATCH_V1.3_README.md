# FleetDesk UI v1.3 — data-loading diagnostics patch

## Files in this patch
- `index.php`
- `assets/js/app.js`

## What changed
- Added the missing `source-data` page title mapping. This removes PHP undefined-array-key notices on the Source Observations page.
- AJAX requests now disable browser caching and validate HTTP status plus JSON response format.
- Dashboard, Fleet Register, Reports, and Source Observations now display a useful API/server error instead of silently leaving the workspace blank. Detailed browser-console errors are also logged.
- No SQL schema, configuration, credentials, Composer files, Google Sheets, or production fleet records are changed.

## Install
1. Back up `C:\xampp\htdocs\fleet-management`.
2. Extract this ZIP into the project root `C:\xampp\htdocs\fleet-management`, allowing the two files to overwrite their existing copies.
3. Keep your existing `config/config.php` and all database contents unchanged.
4. Restart Apache from XAMPP, then hard-refresh the browser with `Ctrl+F5`.
5. Open `http://localhost/fleet-management/api/diagnostics.php` and inspect the JSON. It should report database `fleet_management`, 2 sources, 14 sheets, 2 successful runs, and 803 rows in `spreadsheet_source_row_versions` based on the last verified state.
6. Open `http://localhost/fleet-management/` and check Dashboard, Reports, and Source Observations.

## Important
This is a diagnostics/visibility patch, not proof that the user's local database is reachable from this review environment. The database endpoint must be tested on the user's XAMPP machine. If the dashboard still fails, copy the JSON from `api/diagnostics.php` and the exact on-screen API error. Do not share `config.php`, passwords, service-account JSON, or private keys.

The expected state remains 0 confirmed assets and 803 staged source rows. No proposed candidates are imported by this patch.
