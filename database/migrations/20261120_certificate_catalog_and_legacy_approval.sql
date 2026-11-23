-- Reusable certificate type catalog plus explicit review state for Sheet imports.
CREATE TABLE IF NOT EXISTS certificate_catalog (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    catalog_code VARCHAR(80) NOT NULL,
    display_name VARCHAR(150) NOT NULL,
    sheet_column VARCHAR(80) DEFAULT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    sort_order INT NOT NULL DEFAULT 100,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_certificate_catalog_code (catalog_code),
    UNIQUE KEY uq_certificate_catalog_sheet_column (sheet_column),
    KEY idx_certificate_catalog_active (is_active,sort_order,display_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO certificate_catalog(catalog_code,display_name,sheet_column,is_active,sort_order) VALUES
('CROSS_BORDER_PERMIT_ZIM','CROSS BORDER PERMIT (ZIM)','CROSS BORDER PERMIT (ZIM)',1,10),
('CROSS_BORDER_PERMIT_MOZ','CROSS BORDER PERMIT (MOZ)','CROSS BORDER PERMIT (MOZ)',1,20),
('ZCSA','ZCSA','ZCSA',1,30),
('ELECTRICAL_CERTIFICATE','ELECTRICAL CERTIFICATE','ELECTRICAL CERTIFICATE',1,40),
('DIP_CHART','DIP CHART','DIP CHART',1,50),
('ASSIZER','ASSIZER','ASSIZER',1,60),
('IDENTITIES','IDENTITIES','IDENTITIES',1,70),
('COMESA','COMESA','COMESA',1,80),
('CVG','CVG','CVG',1,90),
('PROFESSIONAL_INSURANCE','PROFESSIONAL INSURANCE','PROFESSIONAL INSURANCE',1,100),
('FITNESS','FITNESS','FITNESS',1,110),
('ROAD_TAX','ROAD TAX','ROAD TAX',1,120),
('C29','C-29','C-29',1,130);

ALTER TABLE certificate_submissions
    ADD COLUMN certificate_catalog_id BIGINT UNSIGNED DEFAULT NULL AFTER certificate_type_id,
    ADD KEY idx_certificate_catalog (certificate_catalog_id),
    ADD CONSTRAINT fk_certificate_submission_catalog FOREIGN KEY (certificate_catalog_id) REFERENCES certificate_catalog(id) ON DELETE SET NULL;

ALTER TABLE certificate_sheet_register_values
    ADD COLUMN certificate_catalog_id BIGINT UNSIGNED DEFAULT NULL AFTER certificate_column,
    ADD COLUMN review_status ENUM('UNVERIFIED','APPROVED','REJECTED') NOT NULL DEFAULT 'UNVERIFIED' AFTER value_status,
    ADD COLUMN review_source ENUM('USER_REQUEST','FLEETDESK_REVIEW') DEFAULT NULL AFTER review_status,
    ADD COLUMN reviewed_by BIGINT UNSIGNED DEFAULT NULL AFTER review_source,
    ADD COLUMN reviewed_at DATETIME DEFAULT NULL AFTER reviewed_by,
    ADD COLUMN review_note VARCHAR(500) DEFAULT NULL AFTER reviewed_at,
    ADD KEY idx_certificate_sheet_legacy_review (review_status,value_status,asset_match_status),
    ADD KEY idx_certificate_sheet_legacy_catalog (certificate_catalog_id),
    ADD CONSTRAINT fk_certificate_sheet_legacy_catalog FOREIGN KEY (certificate_catalog_id) REFERENCES certificate_catalog(id) ON DELETE SET NULL,
    ADD CONSTRAINT fk_certificate_sheet_legacy_reviewer FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL;

UPDATE certificate_sheet_register_values v
JOIN certificate_catalog c ON c.sheet_column=v.certificate_column
SET v.certificate_catalog_id=c.id;

-- The user explicitly requested that valid parsed values matched to known fleet assets
-- be approved as legacy expiry records. They remain separate from document-backed certificates.
UPDATE certificate_sheet_register_values
SET review_status='APPROVED',
    review_source='USER_REQUEST',
    reviewed_at=NOW(),
    review_note='Bulk legacy approval requested by the user: valid parsed expiry matched to a FleetDesk asset.'
WHERE value_status='DATE' AND asset_match_status='MATCHED';
