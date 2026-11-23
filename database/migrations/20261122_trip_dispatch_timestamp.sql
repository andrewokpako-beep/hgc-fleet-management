-- Records when an approved trip was actually dispatched. Completion is blocked
-- until at least five minutes after this timestamp.
ALTER TABLE fleet_trips
  ADD COLUMN dispatched_at DATETIME NULL AFTER decided_at;

-- For trips already marked in progress before this timestamp existed, use
-- approval time as the conservative earliest possible dispatch time.
UPDATE fleet_trips
SET dispatched_at = COALESCE(decided_at, requested_at)
WHERE status = 'IN_PROGRESS' AND dispatched_at IS NULL;

CREATE INDEX idx_fleet_trip_dispatch ON fleet_trips(status, dispatched_at);
