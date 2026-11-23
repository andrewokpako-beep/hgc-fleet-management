# FleetDesk operational data and workbook synchronization plan

**Prepared:** 2026-11-22  
**Goal:** Make FleetDesk the normal place staff manage fleet operations, while
keeping the two existing Google workbooks useful as controlled, auditable data
views and import/export channels.

## Recommended operating model

FleetDesk's application database is the authoritative operational record.
Existing workbook data is imported and reconciled into the application. After
that, application changes are written to dedicated FleetDesk-managed worksheet
tabs. If an administrator edits a managed worksheet, FleetDesk detects the
change and presents it as an inbound review before applying it to the database.
This gives a controlled two-way workflow without silently making two systems
compete as masters.

The existing business tabs remain intact during migration. In particular,
dashboards, formulas, side-by-side registration lists, tracking snapshots,
legacy unregistered lists, and existing status reports must not be treated as
flat database tables or rewritten by a generic sync job.

## What the current project already has

- MySQL tables for assets, asset types, alternate identifiers, yards, current
  asset status, yard history, drivers, driver assignments, certificates,
  attachment relationships, change history, audit events, and spreadsheet
  snapshots/reconciliation.
- A private staff login and role checks. New operational write APIs must use
  these checks and record the acting user.
- A read-only Sheets connection used by `google/stage-sheets.php`; the scheduled
  job captures immutable workbook versions and reconciles changes.
- A separate Sheets writer used for approved certificate data. It does not yet
  write asset, yard, driver, or assignment records to the two source workbooks.
- The two sources have 14 tabs combined. The live layout includes dashboards,
  status reports, source observations, legacy lists and side-by-side register
  sections. Several first rows are titles rather than headers; DB metadata
  currently marks every sheet as header row 1 and has no explicit field mapping.
- The 2026-11-18 gap review recorded 610 imported classified assets and 123 open
  reconciliation cases. Current yard assignments and driver assignments are
  not yet confirmed across the imported fleet, so the app must not invent them.

## App-managed worksheet structure

Add dedicated tabs rather than inserting columns into legacy report layouts:

### `FleetDesk_Assets`

Stable FleetDesk asset ID, primary identifier, asset type, make, model, year,
registration state, active state, current location, current status, revision,
updated timestamp, updated-by and sync marker.

### `FleetDesk_Locations`

Stable location ID, location code, name, description, active state, revision,
updated timestamp and sync marker.

### `FleetDesk_Drivers`

Stable driver ID, name, phone, active state, revision, updated timestamp and
sync marker. Restrict personal details to the intended admin/staff audience.

### `FleetDesk_Driver_Assignments`

Stable assignment ID, asset ID/identifier, driver ID/name, start time, end time,
notes, revision, updated timestamp and sync marker. Preserve ended assignments
as history instead of overwriting them.

### `FleetDesk_Asset_Location_History`

Append-only movement history with asset ID, old/new location, effective time,
recorded time, actor and origin. Do not confuse a last GPS observation with a
human-confirmed yard assignment.

The exact columns should be defined from the current DB field model and approved
before any workbook tab is created. Additional app-managed entities can be
added through the same versioned schema registry.

## Settings behavior

Create a separate workbook-sync settings area with one configuration per
workbook/entity mapping:

- Workbook and target managed tab.
- Entity/data set to publish (assets, locations, drivers, assignments, history).
- Sync direction: app-to-sheet, sheet-to-app review, or both with review.
- Whether FleetDesk may create a missing *managed* tab or append a missing
  required column to that tab.
- Required field mapping, schema version, last successful sync, and health state.
- Connection test, dry-run preview, paused/enabled control, and last error.

Automatic schema changes are limited to FleetDesk-managed tabs. Settings must
show a preview of proposed tab/column creation. They must never add columns to a
legacy sheet without an explicit mapping and migration preview.

## Synchronization safeguards

1. Write DB changes and an outbound sync outbox event in the same DB transaction.
2. A background worker sends idempotent upserts keyed by stable FleetDesk IDs;
   it must not append duplicate assets when a retry follows a timeout.
3. Record worksheet ID, row, entity ID and revision in the sync ledger. Avoid
   relying on row position as identity.
4. Compare revisions and hashes before writes. If a Sheet cell changed since the
   app's last confirmed revision, create an inbound conflict/review instead of
   silently overwriting either side.
5. Mark app-originated writes so the existing snapshot poller does not convert
   them into duplicate source-change cases or import loops.
6. Create backups/snapshots before schema migrations and keep append-only audit
   history for asset, yard, location and driver changes.
7. Validate permissions, required identifiers, duplicate registrations,
   foreign keys, date ranges and current assignment uniqueness in FleetDesk
   before publishing.
8. Never auto-create app assets from ambiguous legacy/reference values. Route
   uncertain rows through reconciliation.

## Rollout sequence

### Phase 0 — inventory and permissions

- Export a recoverable copy of both workbooks and capture tab IDs, protected
  ranges, formulas, filters and current sharing.
- Confirm which workbook will hold each managed tab and whether existing files
  or new private workbooks are preferred.
- Confirm the Google service account has Editor permission on each selected
  target workbook; the current source sync still uses read-only access.
- Restrict workbook viewers/editors to named administrators and the service
  account. Google Drive sharing is a separate permission surface; FleetDesk's
  Sheets API scope cannot by itself make a workbook admin-only.

### Phase 1 — operational workflows in FleetDesk

- Build role-protected create/edit/archive forms and APIs for assets, locations,
  drivers, driver assignments and confirmed location changes.
- Use transactions, duplicate checks, historical assignment/location rows and
  per-user audit records. Keep destructive actions as archive/inactivate.
- Add asset matching and legacy-data review so the initial import and edits are
  traceable.

### Phase 2 — managed-sheet schema and outbox

- Add migrations for mapping configuration, row/entity/revision ledger,
  idempotent outbound queue, inbound review queue and schema version tracking.
- Add a dry-run that reports the exact proposed worksheet/tab/column operations.
- Add the background publisher and retry/backoff after dry-run review and a
  successful write test to a dedicated managed tab.

### Phase 3 — controlled inbound changes and access lockdown

- Poll managed tabs for admin edits and show conflicts/proposals in FleetDesk.
- Apply inbound edits only after review; distinguish app-written revisions from
  external edits.
- Verify that intended users can use FleetDesk and that workbook sharing is
  limited to named administrators and the service account.

### Phase 4 — cutover and validation

- Reconcile counts and stable IDs between DB and managed tabs.
- Keep original legacy tabs archived/preserved until the owner signs off on the
  replacement views.
- Monitor retry queues, change logs, and permission failures before enabling
  routine sync.

## Decisions captured from the request

- Day-to-day asset and operations work should happen in FleetDesk.
- The two source workbooks should become protected, managed counterparts rather
  than open editing surfaces for all staff.
- FleetDesk should be able to create missing required columns in configured
  app-managed sheet structures.
- Existing workbook contents and formulas must be preserved during migration.
- Where "two-way" is enabled, external Sheet edits are reviewed before they
  alter the authoritative app database.

## Not changed in this discovery pass

No source spreadsheet cell, tab, formula, or sharing permission was changed.
No broad writable Sheets scope or operational data-sync job was enabled. The
existing scheduled source snapshot/reconciliation flow remains untouched until
the managed-tab maps and permissions are ready.

## Implementation progress (2026-11-22)

Phase 1 has started. FleetDesk now has a Fleet operations workspace for listing
and filtering the asset register, creating/editing/archiving assets, creating
and disabling locations and drivers, assigning/reassigning drivers to Horses,
changing confirmed current location/status, and viewing recent assignment and
yard-location history. The new write endpoints are admin-only, use transactions,
validate duplicate identifiers and active references, and log changes to the
existing activity and asset history tables. Driver/location periods are kept
as history; archive actions do not physically delete operational records.

Validation completed: PHP lint passed for both operations endpoints and the
page controller; JavaScript syntax check passed; page markup rendered in CLI;
read-only DB queries executed against 612 asset rows and the assignment/location
history queries returned successfully. No fleet records were inserted or
modified during validation.

The workbook outbox, flexible managed-tab settings, background publication and
reviewed inbound edits remain the next implementation phases. No source workbook
cells, tabs or sharing settings have been changed.
