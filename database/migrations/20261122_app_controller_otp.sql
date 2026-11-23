ALTER TABLE users
  ADD COLUMN controller_otp_secret_enc MEDIUMTEXT NULL,
  ADD COLUMN controller_otp_enabled_at DATETIME NULL,
  ADD COLUMN controller_otp_last_counter BIGINT NULL;

INSERT INTO roles(role_name,description,permissions_json,menu_order_json)
SELECT 'APP_CONTROLLER','Application controller: full access, role administration, and mandatory authenticator verification.',permissions_json,menu_order_json
FROM roles admin_role
WHERE admin_role.role_name='ADMIN'
  AND NOT EXISTS(SELECT 1 FROM roles existing_role WHERE existing_role.role_name='APP_CONTROLLER');
