-- Vouchers: metadonnees de generation, premier usage et isolation multi-tenant
ALTER TABLE vouchers ADD COLUMN used_device_id VARCHAR(100) DEFAULT NULL AFTER used_at;
ALTER TABLE vouchers ADD COLUMN usage_source VARCHAR(40) DEFAULT NULL AFTER used_device_id;
ALTER TABLE vouchers ADD COLUMN owner_admin_id INT(11) DEFAULT NULL AFTER usage_source;
ALTER TABLE vouchers ADD COLUMN created_by_user_id INT(11) DEFAULT NULL AFTER owner_admin_id;
CREATE INDEX idx_vouchers_used_device ON vouchers (used_device_id);
CREATE INDEX idx_vouchers_owner ON vouchers (owner_admin_id);
CREATE INDEX idx_vouchers_created_by ON vouchers (created_by_user_id);
UPDATE vouchers v JOIN network_devices d ON d.id=v.device_id SET v.owner_admin_id=d.owner_admin_id WHERE v.owner_admin_id IS NULL AND d.owner_admin_id IS NOT NULL;
