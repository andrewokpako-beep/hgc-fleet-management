-- FleetDesk terminology: Horse is the powered unit; trailers are Tanker or Flatbed.
UPDATE asset_types SET type_name='Horse',description='Powered truck unit (Horse)' WHERE type_code='TRUCK_HEAD';
UPDATE asset_types SET type_name='Tanker',description='Tanker trailer' WHERE type_code='TANKER_TRAILER';
UPDATE asset_types SET type_name='Flatbed',description='Flatbed trailer' WHERE type_code='FLATBED_TRAILER';

-- These imported candidates all came from the Nakonde Flatbed worksheet.
UPDATE assets a
JOIN asset_types old_type ON old_type.id=a.asset_type_id AND old_type.type_code='OTHER_TRAILER'
JOIN asset_identifiers i ON i.asset_id=a.id AND i.is_primary=1
JOIN asset_types flatbed ON flatbed.type_code='FLATBED_TRAILER'
SET a.asset_type_id=flatbed.id
WHERE i.source_note LIKE '%/Nakonde_Flatbed_unregistered%';
