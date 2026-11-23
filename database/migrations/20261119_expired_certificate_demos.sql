-- Five clearly labelled expired certificate examples for the renewal workflow.
-- Idempotent: existing rows with these reserved demo names are left unchanged.
-- Only active assets are referenced; no asset data or uploaded files are changed.
INSERT INTO certificate_submissions (
    certificate_type_id, certificate_name, asset_id, asset_scope,
    registration_snapshot, issue_date, expiry_date,
    document_storage_name, original_filename, mime_type, file_sha256,
    status, submitted_by, reviewed_by, reviewed_at, submitted_at
)
SELECT ct.id, demo.certificate_name, demo.asset_id, demo.asset_scope,
       COALESCE(a.primary_identifier, 'Consolidated Fleet'),
       DATE_SUB(CURDATE(), INTERVAL 455 DAY), DATE_SUB(CURDATE(), INTERVAL 90 DAY),
       '', CONCAT('DEMO ONLY - no document - ', demo.certificate_name, '.txt'),
       'text/plain', SHA2(CONCAT('FLEETDESK-DEMO-EXPIRED-', demo.certificate_name, '-', COALESCE(demo.asset_id, 0)), 256),
       'APPROVED', u.id, u.id, DATE_SUB(NOW(), INTERVAL 90 DAY), DATE_SUB(NOW(), INTERVAL 455 DAY)
FROM (
    SELECT 'HORSE' AS asset_scope, 'HORSE_CERT' AS type_code,
           'DEMO — Horse Roadworthiness Certificate' AS certificate_name,
           (SELECT a.id FROM assets a JOIN asset_types at ON at.id=a.asset_type_id WHERE at.type_code='TRUCK_HEAD' AND a.is_active=1 ORDER BY a.id LIMIT 1) AS asset_id
    UNION ALL SELECT 'HORSE', 'HORSE_CERT', 'DEMO — Horse Insurance Certificate',
           (SELECT a.id FROM assets a JOIN asset_types at ON at.id=a.asset_type_id WHERE at.type_code='TRUCK_HEAD' AND a.is_active=1 ORDER BY a.id LIMIT 1 OFFSET 1)
    UNION ALL SELECT 'TANKER', 'TANKER_CERT', 'DEMO — Tanker Safety Inspection Certificate',
           (SELECT a.id FROM assets a JOIN asset_types at ON at.id=a.asset_type_id WHERE at.type_code='TANKER_TRAILER' AND a.is_active=1 ORDER BY a.id LIMIT 1)
    UNION ALL SELECT 'FLATBED', 'FLATBED_CERT', 'DEMO — Flatbed Annual Inspection Certificate',
           (SELECT a.id FROM assets a JOIN asset_types at ON at.id=a.asset_type_id WHERE at.type_code='FLATBED_TRAILER' AND a.is_active=1 ORDER BY a.id LIMIT 1)
    UNION ALL SELECT 'FLEET', 'FLEET_CONSOLIDATED_CERT', 'DEMO — Consolidated Fleet Operating Certificate', NULL
) demo
JOIN certificate_types ct ON ct.type_code=demo.type_code AND ct.is_active=1
JOIN users u ON u.id=(SELECT MIN(id) FROM users WHERE is_active=1)
LEFT JOIN assets a ON a.id=demo.asset_id
WHERE (demo.asset_scope='FLEET' OR demo.asset_id IS NOT NULL)
  AND NOT EXISTS (SELECT 1 FROM certificate_submissions existing WHERE existing.certificate_name=demo.certificate_name);

INSERT INTO certificate_events (certificate_submission_id,actor_user_id,event_type,previous_status,new_status,reason,details_json,created_at)
SELECT cs.id,cs.submitted_by,'SUBMITTED',NULL,'PENDING','Demo record for expired certificate workflow',
       JSON_OBJECT('demo',true,'certificate_name',cs.certificate_name),cs.submitted_at
FROM certificate_submissions cs
WHERE cs.certificate_name LIKE 'DEMO — %'
  AND NOT EXISTS (SELECT 1 FROM certificate_events e WHERE e.certificate_submission_id=cs.id AND e.event_type='SUBMITTED');

INSERT INTO certificate_events (certificate_submission_id,actor_user_id,event_type,previous_status,new_status,reason,details_json,created_at)
SELECT cs.id,cs.reviewed_by,'APPROVED','PENDING','APPROVED','Demo record for expired certificate workflow',
       JSON_OBJECT('demo',true,'certificate_name',cs.certificate_name),cs.reviewed_at
FROM certificate_submissions cs
WHERE cs.certificate_name LIKE 'DEMO — %'
  AND NOT EXISTS (SELECT 1 FROM certificate_events e WHERE e.certificate_submission_id=cs.id AND e.event_type='APPROVED');
