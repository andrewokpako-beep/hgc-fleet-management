# Fleet workbooks compared with FleetDesk

**Reviewed:** 18 November 2026  
**Workbooks:** `Consolidated Trucks.xlsx` and `Ngwerere_21Miles_Trucks.xlsx`

## What is in FleetDesk now

- The app stages all 14 tabs from the two workbooks. The original captured source set was 803 populated rows. The scheduled poll created one additional full immutable snapshot per workbook, so the version table currently contains 1,606 rows.
- The controlled import created 610 unique classified vehicle records: 296 Horses, 239 Tankers and 75 Flatbeds. IT-reference-only values are not treated as physical assets.
- The Review Queue contains 123 open cases for repeated identifiers and unresolved source comparisons.
- The Location Report currently groups those imported records by source-reported yard label: Ngwerere 492, Kenmugala 93, and 21 Miles Yard 25. This is source context, not a live count of vehicles physically present at each yard.
- The Source Operations Report shows 214 latest Ngwerere tracking observations, status percentages, geofences, source Dashboard metrics and 21 Miles row-level counts.
- The Source Operations Report now includes the Consolidated workbook's `Ngwerere_Flatbeds_Status` data: **29 Active (70.7%)** and **12 Waiting for Inspection (29.3%)**, 41 status-bearing Horse/Flatbed rows. Source row 42 has a Horse identifier, a blank Flatbed identifier and `WAITING FOR INSPECTION`; it is included in the status denominator.
- A hidden one-minute Task Scheduler action fetches Sheets read-only, stages changed workbook snapshots, runs reconciliation, then imports eligible new identifiers and creates review evidence. A database lock prevents overlapping pipelines. Unchanged snapshots are skipped. The first background run completed successfully and skipped production writes because neither source had changed.

## Workbook findings and gaps

### Consolidated Trucks

- `Ngwerere_Flatbeds_Status` contains 41 Horse/Flatbed rows: 29 Active and 12 Waiting for Inspection. The app shows these as a source report; it does **not** assign `current_status_id` on the individual asset rows yet. Status belongs to the source pair row and should not be attached to a Horse or Flatbed record until the relationship is confirmed.
- `Ngwerere_Tankers_status` also includes per-row status/comment values. The current app does not yet expose its full row-level status breakdown in the Source Operations Report or persist those statuses on assets.
- The `DASHBOARD` formulas in this workbook have blank cached results and `#DIV/0!` outputs. Those dashboard formulas cannot be treated as a trustworthy operational summary.
- The 21 Miles unregistered lists contain 66 tanker IT references and 32 flatbed IT references in each of two independently maintained lists. Matching list values remain separate source observations; they are not 196 physical assets.
- Nakonde yard labels, including Kenmugala, are source labels. They remain separate and are not inferred from tracking geofences.

### Ngwerere / 21 Miles Trucks

- `Registered_21Miles` has 12 rows in each side-by-side section, not 85 verified registered units. Row 6 has a Horse mismatch (`CAH9085` versus `CAH9095`) with the same Tanker; row 13 has a blank/placeholder Flatbed identifier. Both remain in the Review Queue.
- The `Dashboard` sheet reports 85 registered, 105 unregistered and 190 total for 21 Miles. It also has conflicting totals elsewhere: the combined headline says 330, the yard comparison sums to 430, and another total cell says 463. These figures are displayed as unverified source-dashboard metrics.
- The latest raw tracking rows classify 148 Parking, 55 No Connection, 3 Moving/In Trip and 8 Other. The Dashboard reports 11 In Trip / Moving (5.1%), so the tracking-row result and dashboard value disagree. The Source Operations Report displays both separately.
- `Unregistered_21Miles` and `Unregistered_21Miles_NoTrailer` are IT/reference lists. They do not by themselves establish asset type, physical existence, or Horse-to-trailer relationships.

## Still not operationally complete

- Assets do not yet have confirmed current-yard assignments (`current_yard_id` is blank for all 610); the yard chart is source-attributed only.
- No Horse-to-Tanker or Horse-to-Flatbed attachments are confirmed or stored. The 123 review cases remain open until decisions are made.
- No asset has an assigned current status in `current_status_id`; tracking snapshots/objects have not been written to their operational tables. Status and geofence reporting currently reads the staged source rows.
- The automated pipeline is insert-only for newly eligible identifiers. It does not automatically change or retire existing asset data; such changes still require a validated rule or a human review.
- The Audit Trail shows initial import events, but no named user is associated with them. User attribution for future create/edit operations requires authenticated write workflows; this UI currently has no asset edit workflow.
- The scheduled task runs hidden every minute while the `Prince` Windows account is signed in. It was not configured for operation after sign-out because background logon registration was denied by Windows access control. Check `logs/scheduled-sync/pipeline.log` for each poll and any error.
