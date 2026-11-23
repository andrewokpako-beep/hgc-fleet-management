ALTER TABLE operation_change_requests
  MODIFY asset_id BIGINT UNSIGNED NULL,
  MODIFY action_type VARCHAR(40) NOT NULL;
