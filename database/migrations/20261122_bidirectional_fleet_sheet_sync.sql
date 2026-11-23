ALTER TABLE spreadsheet_source_change_log
  ADD COLUMN decision_status ENUM('PENDING','APPROVED','DECLINED') NOT NULL DEFAULT 'PENDING' AFTER detected_at,
  ADD COLUMN decided_by BIGINT UNSIGNED NULL AFTER prompted_at,
  ADD COLUMN decided_at DATETIME NULL AFTER decided_by,
  ADD COLUMN decision_note VARCHAR(500) NULL AFTER decided_at,
  ADD KEY idx_source_change_pending_decision (decision_status,change_type,detected_at);

INSERT INTO system_settings(setting_key,setting_value,description)
VALUES ('fleet_sheet_sync_enabled','0','Allow approved FleetDesk fleet records to be mirrored into app-managed Google Sheets tabs.')
ON DUPLICATE KEY UPDATE description=VALUES(description);

UPDATE spreadsheet_source_change_log SET decision_status='APPROVED' WHERE change_type<>'ROW_DELETED';
UPDATE spreadsheet_source_change_log SET decision_status='APPROVED',decision_at=COALESCE(decision_at,detected_at),decision_note=COALESCE(decision_note,'Historical deletion predates approval-gated source changes.') WHERE change_type='ROW_DELETED';

UPDATE roles SET permissions_json=JSON_ARRAY_APPEND(COALESCE(permissions_json,'[]'),'$','source_changes.approve') WHERE role_name='REVIEWER';
