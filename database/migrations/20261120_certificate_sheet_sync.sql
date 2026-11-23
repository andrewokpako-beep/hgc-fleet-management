-- Explicit certificate-to-register column and retryable Google Sheets sync queue.
ALTER TABLE certificate_submissions
    ADD COLUMN sheet_certificate_column VARCHAR(80) DEFAULT NULL AFTER certificate_name;

CREATE TABLE IF NOT EXISTS certificate_sheet_sync_queue (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    certificate_submission_id BIGINT UNSIGNED NOT NULL,
    status ENUM('PENDING','SYNCED') NOT NULL DEFAULT 'PENDING',
    attempts INT UNSIGNED NOT NULL DEFAULT 0,
    last_error VARCHAR(1000) DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    synced_at DATETIME DEFAULT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_certificate_sheet_sync_submission (certificate_submission_id),
    KEY idx_certificate_sheet_sync_pending (status,created_at),
    CONSTRAINT fk_certificate_sheet_sync_submission FOREIGN KEY (certificate_submission_id)
        REFERENCES certificate_submissions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
