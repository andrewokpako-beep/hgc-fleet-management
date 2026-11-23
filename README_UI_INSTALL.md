# FleetDesk UI - installation

1. Back up your current `C:\xampp\htdocs\fleet-management` folder.
2. Copy the new `index.php`, `api`, `includes`, and `assets` folders into `C:\xampp\htdocs\fleet-management`.
3. Keep your existing `config/config.php`, Google credentials outside the web root, `google` scripts, Composer `vendor` folder, and existing database. Do not replace your local config with a sample or overwrite your database.
4. Confirm `config/config.php` has the correct local database connection values.
5. Confirm the existing Fleet Management schema has been imported and Apache/MySQL are running in XAMPP.
6. Open `http://localhost/fleet-management/`.

The UI is read-only in this first milestone. Dashboard, fleet search, report and review views use AJAX JSON endpoints. Fleet search supports identifier/make/model, type and registration filters, 20-row pagination, and CSV export of the currently returned page. No asset creation, deletion, attachment approval, yard assignment, reconciliation decision, or Google Sheets write is implemented.

If there are no assets yet, the register will be empty by design. This matches the current project state: staged source observations exist, but no production fleet assets have been imported. Some dashboard cards will therefore show zero until validated records are loaded.

The interface loads Google Fonts from Google Fonts when internet access is available; system sans-serif fallbacks are provided.
