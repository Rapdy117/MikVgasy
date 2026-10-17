-- Cycle SQL des licences par Device.
CREATE TABLE IF NOT EXISTS device_licenses (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    device_id VARCHAR(191) NOT NULL,
    license_key TEXT NOT NULL,
    license_fingerprint VARCHAR(128) DEFAULT NULL,
    expiry VARCHAR(64) DEFAULT NULL,
    license_type VARCHAR(16) NOT NULL DEFAULT 'lifetime',
    holder_name VARCHAR(160) DEFAULT NULL,
    holder_contact VARCHAR(80) DEFAULT NULL,
    holder_email VARCHAR(190) DEFAULT NULL,
    status VARCHAR(24) NOT NULL DEFAULT 'generated',
    generated_by_user_id INT DEFAULT NULL,
    generated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    activated_at DATETIME DEFAULT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_device_license (device_id, license_key(191)),
    KEY idx_device_license_status (device_id, status),
    KEY idx_device_license_expiry (expiry)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO device_licenses (device_id, license_key, license_fingerprint, expiry, status, generated_at, activated_at)
SELECT d.id, d.license_key, d.device_fingerprint, NULLIF(d.license_expiry, ''),
       CASE WHEN d.license_status='active' THEN 'active' ELSE 'generated' END,
       COALESCE(d.created_at, NOW()),
       CASE WHEN d.license_status='active' THEN COALESCE(d.updated_at, NOW()) ELSE NULL END
FROM network_devices d
WHERE COALESCE(d.license_key, '') <> ''
  AND NOT EXISTS (SELECT 1 FROM device_licenses l WHERE l.device_id=d.id AND l.license_key=d.license_key);
