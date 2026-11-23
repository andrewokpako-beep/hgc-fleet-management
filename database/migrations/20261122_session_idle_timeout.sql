INSERT INTO system_settings(setting_key,setting_value,description)
VALUES('session_idle_timeout_minutes','30','Sign out after this many minutes without user activity.')
ON DUPLICATE KEY UPDATE setting_value=setting_value;
