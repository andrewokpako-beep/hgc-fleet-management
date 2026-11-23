# FleetDesk UI v1.4 — page routing and visible data loading

## What this patch changes
- Ensures `source-data` is registered in the page-title map so the Source Observations page can actually render.
- Adds versioned CSS/JavaScript URLs (`v=1.4`) to force browsers to fetch the updated frontend instead of using a stale cached file.
- Keeps the v1.3 API error reporting so failed requests are shown on the page and logged in the browser console.
- Makes Sync & Reports validate the API response shape and show a useful visible message if the response is not what the page expects.
- Does not alter the database, staging rows, assets, review cases, attachments, tracking, or Google Sheets.

## Installation (XAMPP)
1. Stop making changes to the project and make a backup of `C:\xampp\htdocs\fleet-management`.
2. Extract this ZIP into `C:\xampp\htdocs\fleet-management`, preserving the folders and allowing overwrite of `index.php` and `assets\js\app.js`.
3. Start/restart Apache in XAMPP.
4. Open `http://localhost/fleet-management/?page=reports` and press Ctrl+F5.
5. Confirm that the Sync & Reports table shows the two successful runs (445 and 358 rows).
6. Open `http://localhost/fleet-management/?page=source-data` and confirm that the Source Observations page opens.
7. Open `http://localhost/fleet-management/api/source-observations.php` directly. It should return JSON with `ok:true` and `total:803`.
8. Open `http://localhost/fleet-management/` and confirm the dashboard shows 803 staged rows and the two recent sync runs.

## Important interpretation
- 803 are staged source observations, not approved fleet assets.
- Fleet Register remains empty until approved assets are imported through a controlled workflow.
- Review Queue remains empty because there are currently zero `reconciliation_cases` records; the seven review issues remain in the review workbook/report.
- Attachments and Tracking/Yards remain placeholders until validated operational data exists.

If any page is still blank, send the exact JSON from the endpoint it calls and a screenshot of the page. Do not send database passwords, Google service-account JSON, or private keys.
