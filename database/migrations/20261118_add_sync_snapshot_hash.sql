-- Prevent the scheduled poller from appending an identical full snapshot on every check.
ALTER TABLE spreadsheet_sync_runs
    ADD COLUMN snapshot_hash CHAR(64) NULL AFTER triggered_by;
