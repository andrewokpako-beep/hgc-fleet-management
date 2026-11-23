CREATE TABLE IF NOT EXISTS maintenance_work_orders (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  work_order_number VARCHAR(40) NOT NULL,
  asset_id BIGINT UNSIGNED NOT NULL,
  reported_by BIGINT UNSIGNED NOT NULL,
  reported_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  issue_summary VARCHAR(500) NOT NULL,
  issue_details TEXT NOT NULL,
  priority ENUM('LOW','NORMAL','HIGH','URGENT') NOT NULL DEFAULT 'NORMAL',
  target_completion_date DATE NOT NULL,
  status ENUM('OPEN','IN_PROGRESS','REPAIR_COMPLETE','CLOSED','CANCELLED') NOT NULL DEFAULT 'OPEN',
  repair_completed_at DATETIME NULL,
  repair_notes TEXT NULL,
  closed_by BIGINT UNSIGNED NULL,
  closed_at DATETIME NULL,
  final_inspection_id BIGINT UNSIGNED NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_maintenance_work_order_number (work_order_number),
  KEY idx_maintenance_work_asset_status (asset_id,status),
  KEY idx_maintenance_work_due (status,target_completion_date),
  CONSTRAINT fk_maintenance_work_asset FOREIGN KEY (asset_id) REFERENCES assets(id),
  CONSTRAINT fk_maintenance_work_reporter FOREIGN KEY (reported_by) REFERENCES users(id),
  CONSTRAINT fk_maintenance_work_closer FOREIGN KEY (closed_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS maintenance_work_order_events (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  work_order_id BIGINT UNSIGNED NOT NULL,
  actor_id BIGINT UNSIGNED NOT NULL,
  event_type VARCHAR(40) NOT NULL,
  details TEXT NOT NULL,
  old_status VARCHAR(24) NULL,
  new_status VARCHAR(24) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_work_order_events (work_order_id,created_at),
  CONSTRAINT fk_work_order_event_order FOREIGN KEY (work_order_id) REFERENCES maintenance_work_orders(id),
  CONSTRAINT fk_work_order_event_actor FOREIGN KEY (actor_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE vehicle_inspections
  ADD COLUMN work_order_id BIGINT UNSIGNED NULL AFTER trip_id,
  ADD KEY idx_vehicle_inspection_work_order (work_order_id),
  ADD CONSTRAINT fk_vehicle_inspection_work_order FOREIGN KEY (work_order_id) REFERENCES maintenance_work_orders(id) ON DELETE SET NULL;

ALTER TABLE maintenance_work_orders
  ADD CONSTRAINT fk_maintenance_work_final_inspection FOREIGN KEY (final_inspection_id) REFERENCES vehicle_inspections(id) ON DELETE SET NULL;
