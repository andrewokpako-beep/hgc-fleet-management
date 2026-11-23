ALTER TABLE roles
  ADD COLUMN permissions_json LONGTEXT NULL,
  ADD COLUMN menu_order_json LONGTEXT NULL;

UPDATE roles SET
  permissions_json='["page.dashboard","page.fleet","page.operations","page.operations-approvals","page.pending-approvals","page.trips","page.maintenance","page.work-orders","page.maintenance-log","page.attachments","page.tracking","page.reviews","page.reports","page.source-data","page.source-operations","page.unregistered","page.locations","page.audit","page.ai-assistant","page.settings","page.asset-certificates","page.certificates","page.certificate-approvals","page.certificate-settings","operations.request","operations.manage","operations.approve","readiness.request","readiness.approve","trips.request","trips.manage","trips.approve","maintenance.inspect","maintenance.work_orders","maintenance.resolve_defects","certificates.submit","certificates.request_changes","certificates.approve","certificates.manage","certificates.settings","certificates.smart_fix","reports.export","tracking.settings","settings.ai","users.manage","roles.manage"]',
  menu_order_json='["dashboard","fleet","operations","operations-approvals","pending-approvals","trips","maintenance","work-orders","maintenance-log","attachments","tracking","reviews","reports","source-data","source-operations","unregistered","locations","audit","ai-assistant","settings","asset-certificates","certificates","certificate-approvals","certificate-settings"]'
WHERE role_name='ADMIN';

UPDATE roles SET
  permissions_json='["page.dashboard","page.fleet","page.operations","page.operations-approvals","page.pending-approvals","page.trips","page.maintenance","page.work-orders","page.maintenance-log","page.attachments","page.tracking","page.reviews","page.reports","page.source-data","page.source-operations","page.unregistered","page.locations","page.audit","page.asset-certificates","page.certificates","page.certificate-approvals","certificates.manage","operations.request","operations.approve","readiness.request","readiness.approve","trips.request","trips.approve","maintenance.inspect","maintenance.work_orders","maintenance.resolve_defects","certificates.submit","certificates.request_changes","certificates.approve","certificates.smart_fix","reports.export"]',
  menu_order_json='["dashboard","fleet","operations","operations-approvals","pending-approvals","trips","maintenance","work-orders","maintenance-log","attachments","tracking","reviews","reports","source-data","source-operations","unregistered","locations","audit","asset-certificates","certificates","certificate-approvals"]'
WHERE role_name='REVIEWER';

UPDATE roles SET
  permissions_json='["page.dashboard","page.fleet","page.operations","page.pending-approvals","page.trips","page.maintenance","page.work-orders","page.maintenance-log","page.attachments","page.tracking","page.reviews","page.reports","page.source-data","page.source-operations","page.unregistered","page.locations","page.asset-certificates","page.certificates"]',
  menu_order_json='["dashboard","fleet","operations","pending-approvals","trips","maintenance","work-orders","maintenance-log","attachments","tracking","reviews","reports","source-data","source-operations","unregistered","locations","asset-certificates","certificates"]'
WHERE role_name='VIEWER';

INSERT INTO roles(role_name,description,permissions_json,menu_order_json)
SELECT 'OPERATOR','Can submit day-to-day fleet, trip, maintenance and certificate requests.',
  '["page.dashboard","page.fleet","page.operations","page.pending-approvals","page.trips","page.maintenance","page.work-orders","page.maintenance-log","page.attachments","page.tracking","page.reviews","page.reports","page.source-data","page.source-operations","page.unregistered","page.locations","page.asset-certificates","page.certificates","operations.request","readiness.request","trips.request","maintenance.inspect","maintenance.work_orders","maintenance.resolve_defects","certificates.submit","certificates.request_changes","reports.export"]',
  '["dashboard","fleet","operations","pending-approvals","trips","maintenance","work-orders","maintenance-log","attachments","tracking","reviews","reports","source-data","source-operations","unregistered","locations","asset-certificates","certificates"]'
WHERE NOT EXISTS(SELECT 1 FROM roles WHERE role_name='OPERATOR');

INSERT INTO roles(role_name,description,permissions_json,menu_order_json)
SELECT 'EDITOR_ONLY','Can request and maintain records; approvals remain with designated reviewers.',
  '["page.dashboard","page.fleet","page.operations","page.trips","page.maintenance","page.work-orders","page.maintenance-log","page.attachments","page.tracking","page.reviews","page.reports","page.asset-certificates","page.certificates","operations.request","readiness.request","trips.request","maintenance.inspect","maintenance.work_orders","maintenance.resolve_defects","certificates.submit","certificates.request_changes","reports.export"]',
  '["dashboard","fleet","operations","trips","maintenance","work-orders","maintenance-log","attachments","tracking","reviews","reports","asset-certificates","certificates"]'
WHERE NOT EXISTS(SELECT 1 FROM roles WHERE role_name='EDITOR_ONLY');
