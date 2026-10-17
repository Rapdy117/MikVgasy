-- Radius Manager - fondation SQL multi-tenant
-- Prerequis: MySQL 8.0.29+ / MySQL 9.x.
-- Cette migration ne modifie aucune table FreeRADIUS rad*.

START TRANSACTION;

CREATE TABLE IF NOT EXISTS schema_migrations (
    version VARCHAR(100) NOT NULL,
    applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (version)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS system_installation (
    id TINYINT UNSIGNED NOT NULL,
    installation_uuid CHAR(36) NOT NULL,
    installed_by_user_id INT NULL,
    installed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    schema_version VARCHAR(100) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_system_installation_uuid (installation_uuid),
    CONSTRAINT chk_single_installation CHECK (id = 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE local_admin_users
    MODIFY COLUMN role ENUM('superadmin','admin','reseller') NOT NULL DEFAULT 'admin',
    ADD COLUMN IF NOT EXISTS owner_admin_id INT NULL AFTER role,
    ADD COLUMN IF NOT EXISTS must_change_password TINYINT(1) NOT NULL DEFAULT 1 AFTER owner_admin_id,
    ADD COLUMN IF NOT EXISTS last_login_at DATETIME NULL AFTER is_active,
    ADD INDEX IF NOT EXISTS idx_local_admin_owner (owner_admin_id),
    ADD INDEX IF NOT EXISTS idx_local_admin_role_active (role, is_active);

UPDATE local_admin_users SET role = 'admin' WHERE role = '';

CREATE TABLE IF NOT EXISTS network_devices (
    id VARCHAR(191) NOT NULL,
    owner_admin_id INT NOT NULL,
    nas_id INT NULL,
    name VARCHAR(191) NOT NULL,
    type ENUM('mikrotik','opnsense','radius') NOT NULL,
    authentication_mode ENUM('router_local','radius_sql','mixed_explicit') NOT NULL DEFAULT 'router_local',
    host VARCHAR(255) NOT NULL,
    port INT NULL,
    api_key_encrypted TEXT NULL,
    api_secret_encrypted TEXT NULL,
    radius_secret_encrypted TEXT NULL,
    verify_ssl TINYINT(1) NOT NULL DEFAULT 0,
    vendor VARCHAR(100) NULL,
    device_fingerprint VARCHAR(255) NULL,
    hardware_info_json JSON NULL,
    license_key TEXT NULL,
    license_status VARCHAR(50) NULL,
    license_expiry DATETIME NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_network_device_nas (nas_id),
    KEY idx_network_device_owner (owner_admin_id),
    KEY idx_network_device_owner_active (owner_admin_id, is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS management_user_devices (
    user_id INT NOT NULL,
    device_id VARCHAR(191) NOT NULL,
    assigned_by_user_id INT NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, device_id),
    KEY idx_management_device (device_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS management_user_preferences (
    user_id INT NOT NULL,
    active_device_id VARCHAR(191) NULL,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id),
    KEY idx_preference_device (active_device_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS hotspot_resource_ownership (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    device_id VARCHAR(191) NOT NULL,
    resource_type ENUM('user','profile','voucher','recharge') NOT NULL,
    resource_key VARCHAR(191) NOT NULL,
    auth_source ENUM('router_local','radius_sql') NOT NULL,
    owner_admin_id INT NOT NULL,
    created_by_user_id INT NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_hotspot_resource (device_id, resource_type, resource_key),
    KEY idx_hotspot_owner_creator (owner_admin_id, created_by_user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS user_migration_history (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    migration_uuid CHAR(36) NOT NULL,
    username VARCHAR(191) NOT NULL,
    source_device_id VARCHAR(191) NOT NULL,
    source_auth_source ENUM('router_local','radius_sql') NOT NULL,
    target_device_id VARCHAR(191) NOT NULL,
    target_auth_source ENUM('router_local','radius_sql') NOT NULL,
    action ENUM('created','updated','skipped','failed') NOT NULL,
    actor_user_id INT NULL,
    actor_username VARCHAR(100) NULL,
    details_json JSON NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_user_migration_uuid (migration_uuid),
    KEY idx_user_migration_username (username),
    KEY idx_user_migration_source (source_device_id, source_auth_source),
    KEY idx_user_migration_target (target_device_id, target_auth_source)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE users
    ADD COLUMN IF NOT EXISTS owner_admin_id INT NULL AFTER id,
    ADD COLUMN IF NOT EXISTS created_by_user_id INT NULL AFTER owner_admin_id,
    ADD COLUMN IF NOT EXISTS device_id VARCHAR(191) NULL AFTER created_by_user_id,
    ADD COLUMN IF NOT EXISTS auth_source ENUM('radius_sql') NOT NULL DEFAULT 'radius_sql' AFTER device_id,
    ADD COLUMN IF NOT EXISTS origin_device_id VARCHAR(191) NULL AFTER auth_source,
    ADD COLUMN IF NOT EXISTS origin_auth_source ENUM('router_local','radius_sql') NULL AFTER origin_device_id,
    ADD INDEX IF NOT EXISTS idx_users_tenant (owner_admin_id, created_by_user_id),
    ADD INDEX IF NOT EXISTS idx_users_device (device_id);

ALTER TABLE profiles
    ADD COLUMN IF NOT EXISTS owner_admin_id INT NULL AFTER id,
    ADD COLUMN IF NOT EXISTS created_by_user_id INT NULL AFTER owner_admin_id,
    ADD COLUMN IF NOT EXISTS device_id VARCHAR(191) NULL AFTER created_by_user_id,
    ADD COLUMN IF NOT EXISTS radius_groupname VARCHAR(64) NULL AFTER name,
    ADD UNIQUE INDEX IF NOT EXISTS uq_profiles_radius_groupname (radius_groupname),
    ADD INDEX IF NOT EXISTS idx_profiles_tenant_device (owner_admin_id, device_id);

UPDATE profiles
SET radius_groupname = CONCAT('profile_', id)
WHERE radius_groupname IS NULL OR radius_groupname = '';

ALTER TABLE vouchers
    ADD COLUMN IF NOT EXISTS owner_admin_id INT NULL AFTER id,
    ADD COLUMN IF NOT EXISTS created_by_user_id INT NULL AFTER owner_admin_id,
    ADD INDEX IF NOT EXISTS idx_vouchers_tenant_creator (owner_admin_id, created_by_user_id);

ALTER TABLE recharge_history
    ADD COLUMN IF NOT EXISTS owner_admin_id INT NULL AFTER id,
    ADD COLUMN IF NOT EXISTS created_by_user_id INT NULL AFTER owner_admin_id,
    ADD INDEX IF NOT EXISTS idx_recharge_tenant_creator (owner_admin_id, created_by_user_id);

ALTER TABLE operation_history
    ADD COLUMN IF NOT EXISTS owner_admin_id INT NULL AFTER id,
    ADD COLUMN IF NOT EXISTS actor_user_id INT NULL AFTER owner_admin_id,
    ADD INDEX IF NOT EXISTS idx_operation_tenant_actor (owner_admin_id, actor_user_id);

ALTER TABLE recouvrement_invoices
    ADD COLUMN IF NOT EXISTS owner_admin_id INT NULL AFTER id,
    ADD COLUMN IF NOT EXISTS created_by_user_id INT NULL AFTER owner_admin_id,
    ADD INDEX IF NOT EXISTS idx_invoice_tenant_creator (owner_admin_id, created_by_user_id);

INSERT IGNORE INTO schema_migrations (version)
VALUES ('001_multi_tenant_foundation');

COMMIT;
