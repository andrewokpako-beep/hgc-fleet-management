ALTER TABLE certificate_submissions
    ADD COLUMN certificate_name VARCHAR(150) DEFAULT NULL AFTER certificate_type_id;

UPDATE certificate_submissions cs
JOIN certificate_types ct ON ct.id=cs.certificate_type_id
SET cs.certificate_name=ct.display_name
WHERE cs.certificate_name IS NULL OR TRIM(cs.certificate_name)='';
