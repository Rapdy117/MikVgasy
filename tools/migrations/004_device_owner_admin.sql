ALTER TABLE network_devices ADD COLUMN owner_admin_id BIGINT UNSIGNED NULL AFTER id, ADD INDEX idx_network_devices_owner (owner_admin_id);
UPDATE network_devices d JOIN local_admin_users u ON u.username='admin' AND u.role='superadmin' AND u.is_active=1 SET d.owner_admin_id=u.id WHERE d.owner_admin_id IS NULL;
ALTER TABLE network_devices MODIFY owner_admin_id BIGINT UNSIGNED NOT NULL;
