CREATE TABLE IF NOT EXISTS certificate_catalog_change_requests (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    certificate_catalog_id BIGINT UNSIGNED NOT NULL,
    action ENUM('ARCHIVE') NOT NULL DEFAULT 'ARCHIVE',
    status ENUM('PENDING','APPROVED','DECLINED') NOT NULL DEFAULT 'PENDING',
    requested_by BIGINT UNSIGNED NOT NULL,
    requested_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    reviewed_by BIGINT UNSIGNED DEFAULT NULL,
    reviewed_at DATETIME DEFAULT NULL,
    decline_reason TEXT DEFAULT NULL,
    PRIMARY KEY (id),
    KEY idx_certificate_catalog_change_pending (status, requested_at),
    KEY idx_certificate_catalog_change_target (certificate_catalog_id, status),
    CONSTRAINT fk_certificate_catalog_change_catalog FOREIGN KEY (certificate_catalog_id) REFERENCES certificate_catalog(id) ON DELETE RESTRICT,
    CONSTRAINT fk_certificate_catalog_change_requester FOREIGN KEY (requested_by) REFERENCES users(id) ON DELETE RESTRICT,
    CONSTRAINT fk_certificate_catalog_change_reviewer FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE certificate_catalog
    ADD COLUMN is_archived TINYINT(1) NOT NULL DEFAULT 0 AFTER is_active;

ALTER TABLE certificate_catalog_change_requests
    MODIFY action ENUM('DISABLE','ENABLE','ARCHIVE') NOT NULL DEFAULT 'ARCHIVE';
