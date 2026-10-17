ALTER TABLE operation_history ADD COLUMN owner_admin_id INT NULL AFTER id;
ALTER TABLE operation_history ADD KEY idx_operation_history_owner (owner_admin_id);
