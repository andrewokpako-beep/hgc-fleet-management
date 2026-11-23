-- Read-only legacy mirror of expiry values imported from the live certificate Sheet.
-- These rows are not document-backed approvals in certificate_submissions.
CREATE TABLE IF NOT EXISTS certificate_sheet_register_values (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    spreadsheet_id VARCHAR(100) NOT NULL,
    sheet_name VARCHAR(50) NOT NULL,
    source_row_number INT UNSIGNED NOT NULL,
    asset_scope ENUM('HORSE','TANKER') NOT NULL,
    asset_id BIGINT UNSIGNED DEFAULT NULL,
    asset_match_status ENUM('MATCHED','UNMATCHED','AMBIGUOUS') NOT NULL,
    registration_snapshot VARCHAR(150) NOT NULL,
    certificate_column VARCHAR(80) NOT NULL,
    source_value VARCHAR(255) NOT NULL,
    expiry_date DATE DEFAULT NULL,
    value_status ENUM('DATE','NIL','INVALID') NOT NULL,
    origin ENUM('SHEET_IMPORT','FLEETDESK_APPROVED') NOT NULL DEFAULT 'SHEET_IMPORT',
    source_submission_id BIGINT UNSIGNED DEFAULT NULL,
    imported_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_certificate_register_cell (spreadsheet_id,sheet_name,source_row_number,certificate_column),
    KEY idx_certificate_register_asset (asset_scope,registration_snapshot),
    KEY idx_certificate_register_expiry (value_status,expiry_date),
    CONSTRAINT fk_certificate_register_asset FOREIGN KEY (asset_id) REFERENCES assets(id) ON DELETE SET NULL,
    CONSTRAINT fk_certificate_register_submission FOREIGN KEY (source_submission_id) REFERENCES certificate_submissions(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
