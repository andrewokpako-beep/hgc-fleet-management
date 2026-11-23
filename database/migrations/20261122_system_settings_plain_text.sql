-- FleetDesk system settings are plain text values, not necessarily JSON.
-- The previous JSON_VALID check rejected legitimate settings such as OAuth
-- Workspace domains, callback URLs, alarm times and company names.
ALTER TABLE system_settings MODIFY setting_value LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL;
