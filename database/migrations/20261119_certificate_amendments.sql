ALTER TABLE certificate_submissions
    MODIFY COLUMN document_storage_name VARCHAR(100) NOT NULL,
    MODIFY COLUMN status ENUM('PENDING','APPROVED','DECLINED','SUPERSEDED','WITHDRAWN') NOT NULL;

ALTER TABLE certificate_events
    MODIFY COLUMN event_type ENUM('SUBMITTED','APPROVED','DECLINED','SUPERSEDED','DOCUMENT_VIEWED','EXPIRY_CHANGE_REQUESTED','EXPIRY_UPDATED','DOCUMENT_REMOVAL_REQUESTED','DOCUMENT_REMOVAL_APPROVED','DOCUMENT_REMOVED','CHANGE_DECLINED') NOT NULL;

CREATE TABLE IF NOT EXISTS certificate_amendments (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    certificate_submission_id BIGINT UNSIGNED NOT NULL,
    action ENUM('UPDATE_EXPIRY','REMOVE_DOCUMENT') NOT NULL,
    requested_expiry_date DATE DEFAULT NULL,
    request_reason TEXT DEFAULT NULL,
    status ENUM('PENDING','APPROVED','DECLINED') NOT NULL DEFAULT 'PENDING',
    requested_by BIGINT UNSIGNED NOT NULL,
    requested_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    reviewed_by BIGINT UNSIGNED DEFAULT NULL,
    reviewed_at DATETIME DEFAULT NULL,
    decline_reason TEXT DEFAULT NULL,
    PRIMARY KEY (id),
    KEY idx_certificate_amendments_pending (status, requested_at),
    KEY idx_certificate_amendments_submission (certificate_submission_id, status),
    CONSTRAINT fk_certificate_amendments_submission FOREIGN KEY (certificate_submission_id) REFERENCES certificate_submissions(id) ON DELETE RESTRICT,
    CONSTRAINT fk_certificate_amendments_requester FOREIGN KEY (requested_by) REFERENCES users(id) ON DELETE RESTRICT,
    CONSTRAINT fk_certificate_amendments_reviewer FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
