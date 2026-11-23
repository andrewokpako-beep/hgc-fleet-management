-- Promote the user's approved, matched legacy expiry entries into the normal
-- per-asset certificate register while preserving their Sheet provenance.
ALTER TABLE certificate_submissions
    ADD COLUMN record_source ENUM('WORKFLOW','LEGACY_SHEET') NOT NULL DEFAULT 'WORKFLOW' AFTER certificate_catalog_id,
    ADD COLUMN source_legacy_value_id BIGINT UNSIGNED DEFAULT NULL AFTER record_source,
    ADD UNIQUE KEY uq_certificate_submission_legacy_source (source_legacy_value_id),
    ADD CONSTRAINT fk_certificate_submission_legacy_value FOREIGN KEY (source_legacy_value_id) REFERENCES certificate_sheet_register_values(id) ON DELETE SET NULL,
    MODIFY COLUMN document_storage_name VARCHAR(100) DEFAULT NULL,
    MODIFY COLUMN original_filename VARCHAR(255) DEFAULT NULL,
    MODIFY COLUMN mime_type VARCHAR(100) DEFAULT NULL,
    MODIFY COLUMN file_sha256 CHAR(64) DEFAULT NULL,
    MODIFY COLUMN submitted_by BIGINT UNSIGNED DEFAULT NULL;

ALTER TABLE certificate_events
    MODIFY COLUMN event_type ENUM('SUBMITTED','APPROVED','DECLINED','SUPERSEDED','DOCUMENT_VIEWED','EXPIRY_CHANGE_REQUESTED','EXPIRY_UPDATED','DOCUMENT_REMOVAL_REQUESTED','DOCUMENT_REMOVAL_APPROVED','DOCUMENT_REMOVED','CHANGE_DECLINED','LEGACY_IMPORTED') NOT NULL;

INSERT INTO certificate_submissions(
    certificate_type_id,certificate_catalog_id,record_source,source_legacy_value_id,
    certificate_name,sheet_certificate_column,asset_id,asset_scope,registration_snapshot,
    driver_name_snapshot,issue_date,expiry_date,document_storage_name,original_filename,
    mime_type,file_sha256,status,submitted_by,reviewed_by,reviewed_at,submitted_at
)
SELECT
    ct.id,v.certificate_catalog_id,'LEGACY_SHEET',v.id,
    c.display_name,v.certificate_column,v.asset_id,v.asset_scope,v.registration_snapshot,
    NULL,NULL,v.expiry_date,NULL,NULL,NULL,NULL,'APPROVED',NULL,v.reviewed_by,v.reviewed_at,v.imported_at
FROM certificate_sheet_register_values v
JOIN certificate_catalog c ON c.id=v.certificate_catalog_id
JOIN certificate_types ct ON ct.asset_scope=v.asset_scope AND ct.type_code=CASE v.asset_scope
    WHEN 'HORSE' THEN 'HORSE_CERT' WHEN 'TANKER' THEN 'TANKER_CERT' END
WHERE v.review_status='APPROVED' AND v.value_status='DATE' AND v.asset_match_status='MATCHED'
  AND v.expiry_date IS NOT NULL AND v.asset_id IS NOT NULL
  AND NOT EXISTS (SELECT 1 FROM certificate_submissions cs WHERE cs.source_legacy_value_id=v.id);

INSERT INTO certificate_events(certificate_submission_id,actor_user_id,event_type,previous_status,new_status,reason,details_json)
SELECT cs.id,NULL,'LEGACY_IMPORTED',NULL,'APPROVED',v.review_note,
       JSON_OBJECT('legacy_value_id',v.id,'sheet_name',v.sheet_name,'source_row_number',v.source_row_number,
                   'certificate_column',v.certificate_column,'source_value',v.source_value,'expiry_date',v.expiry_date)
FROM certificate_submissions cs
JOIN certificate_sheet_register_values v ON v.id=cs.source_legacy_value_id
WHERE cs.record_source='LEGACY_SHEET'
  AND NOT EXISTS (SELECT 1 FROM certificate_events e WHERE e.certificate_submission_id=cs.id AND e.event_type='LEGACY_IMPORTED');

UPDATE certificate_sheet_register_values v
JOIN certificate_submissions cs ON cs.source_legacy_value_id=v.id
SET v.source_submission_id=cs.id
WHERE cs.record_source='LEGACY_SHEET';
