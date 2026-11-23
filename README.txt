FLEETDESK UI v1.2 PATCH

Copy/merge the contents into C:\\xampp\\htdocs\\fleet-management\\.
Replace index.php and assets/js/app.js; add/replace api/dashboard.php, api/reports.php and add api/source-observations.php.
Do not replace config/config.php, vendor, credentials or database files.

This patch aligns API response fields with the existing v1.1 JavaScript and adds a read-only Source observations page over spreadsheet_source_row_versions. It does not create assets, attachments, reconciliation cases, tracking snapshots, or write to Google Sheets.

Location filter is worksheet-name based and keeps Ngwerere, 21 Miles and Nakonde separate. Verify each worksheet label in the source page; unusual names may fall under Other.
