ALTER TABLE admin_notifications ADD COLUMN owner_admin_id INT NULL AFTER id;
ALTER TABLE admin_notifications ADD KEY idx_admin_notifications_owner (owner_admin_id);
