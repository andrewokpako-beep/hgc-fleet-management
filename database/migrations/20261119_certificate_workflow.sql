-- Certificate submissions, version approvals, driver assignments, and alert preferences.
CREATE TABLE IF NOT EXISTS certificate_types (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    type_code VARCHAR(40) NOT NULL,
    asset_scope ENUM('HORSE','TANKER','FLATBED','FLEET') NOT NULL,
    display_name VARCHAR(150) NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_certificate_type_code (type_code),
    KEY idx_certificate_scope (asset_scope,is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO certificate_types (type_code,asset_scope,display_name) VALUES
('HORSE_CERT','HORSE','Horse Certificate'),
('TANKER_CERT','TANKER','Tanker Certificate'),
('FLATBED_CERT','FLATBED','Flatbed Certificate'),
('FLEET_CONSOLIDATED_CERT','FLEET','Consolidated Fleet Certificate');

CREATE TABLE IF NOT EXISTS drivers (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    full_name VARCHAR(150) NOT NULL,
    phone VARCHAR(50) DEFAULT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_by BIGINT UNSIGNED DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_driver_active (is_active,full_name),
    CONSTRAINT fk_drivers_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS asset_driver_assignments (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    asset_id BIGINT UNSIGNED NOT NULL,
    driver_id BIGINT UNSIGNED NOT NULL,
    assigned_from DATETIME NOT NULL,
    assigned_to DATETIME DEFAULT NULL,
    assigned_by BIGINT UNSIGNED DEFAULT NULL,
    notes VARCHAR(500) DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_driver_assignment_current (asset_id,assigned_to,assigned_from),
    KEY idx_driver_assignment_driver (driver_id,assigned_to),
    CONSTRAINT fk_driver_assignment_asset FOREIGN KEY (asset_id) REFERENCES assets(id),
    CONSTRAINT fk_driver_assignment_driver FOREIGN KEY (driver_id) REFERENCES drivers(id),
    CONSTRAINT fk_driver_assignment_user FOREIGN KEY (assigned_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS certificate_submissions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    certificate_type_id BIGINT UNSIGNED NOT NULL,
    asset_id BIGINT UNSIGNED DEFAULT NULL,
    asset_scope ENUM('HORSE','TANKER','FLATBED','FLEET') NOT NULL,
    registration_snapshot VARCHAR(150) NOT NULL DEFAULT '',
    driver_name_snapshot VARCHAR(150) DEFAULT NULL,
    issue_date DATE DEFAULT NULL,
    expiry_date DATE NOT NULL,
    document_storage_name VARCHAR(100) NOT NULL,
    original_filename VARCHAR(255) NOT NULL,
    mime_type VARCHAR(100) NOT NULL,
    file_sha256 CHAR(64) NOT NULL,
    status ENUM('PENDING','APPROVED','DECLINED','SUPERSEDED') NOT NULL DEFAULT 'PENDING',
    submitted_by BIGINT UNSIGNED NOT NULL,
    reviewed_by BIGINT UNSIGNED DEFAULT NULL,
    reviewed_at DATETIME DEFAULT NULL,
    decline_reason TEXT DEFAULT NULL,
    replaces_submission_id BIGINT UNSIGNED DEFAULT NULL,
    submitted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_certificate_approval (status,submitted_at),
    KEY idx_certificate_asset_current (asset_id,certificate_type_id,status,expiry_date),
    KEY idx_certificate_expiry (status,expiry_date),
    KEY idx_certificate_scope (asset_scope,certificate_type_id,status),
    CONSTRAINT fk_certificate_type FOREIGN KEY (certificate_type_id) REFERENCES certificate_types(id),
    CONSTRAINT fk_certificate_asset FOREIGN KEY (asset_id) REFERENCES assets(id),
    CONSTRAINT fk_certificate_submitter FOREIGN KEY (submitted_by) REFERENCES users(id),
    CONSTRAINT fk_certificate_reviewer FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_certificate_replaces FOREIGN KEY (replaces_submission_id) REFERENCES certificate_submissions(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS certificate_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    certificate_submission_id BIGINT UNSIGNED NOT NULL,
    actor_user_id BIGINT UNSIGNED DEFAULT NULL,
    event_type ENUM('SUBMITTED','APPROVED','DECLINED','SUPERSEDED','DOCUMENT_VIEWED') NOT NULL,
    previous_status VARCHAR(20) DEFAULT NULL,
    new_status VARCHAR(20) DEFAULT NULL,
    reason TEXT DEFAULT NULL,
    details_json LONGTEXT DEFAULT NULL,
    ip_address VARCHAR(45) DEFAULT NULL,
    user_agent VARCHAR(500) DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_certificate_event (certificate_submission_id,created_at),
    KEY idx_certificate_actor (actor_user_id,created_at),
    CONSTRAINT fk_certificate_event_submission FOREIGN KEY (certificate_submission_id) REFERENCES certificate_submissions(id),
    CONSTRAINT fk_certificate_event_actor FOREIGN KEY (actor_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO system_settings (setting_key,setting_value,description) VALUES
('certificate_expiry_lead_days','30','Show approved certificates expiring within this number of days.'),
('certificate_alarm_time','08:00','Local browser time to sound the daily certificate expiry alarm.');
