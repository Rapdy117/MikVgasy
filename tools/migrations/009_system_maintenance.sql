CREATE TABLE IF NOT EXISTS system_maintenance_runs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    action VARCHAR(40) NOT NULL,
    table_name VARCHAR(64) DEFAULT NULL,
    cutoff_year SMALLINT DEFAULT NULL,
    affected_rows INT UNSIGNED NOT NULL DEFAULT 0,
    archive_path VARCHAR(255) DEFAULT NULL,
    actor_user_id INT DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id), KEY idx_maintenance_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
