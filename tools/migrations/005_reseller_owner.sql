ALTER TABLE local_admin_users ADD COLUMN owner_admin_id BIGINT UNSIGNED NULL AFTER role, ADD INDEX idx_local_admin_owner (owner_admin_id);
UPDATE local_admin_users SET owner_admin_id = id WHERE role IN ('superadmin','admin') AND owner_admin_id IS NULL;
ALTER TABLE local_admin_users ADD CONSTRAINT fk_local_admin_owner FOREIGN KEY (owner_admin_id) REFERENCES local_admin_users(id) ON DELETE RESTRICT;
