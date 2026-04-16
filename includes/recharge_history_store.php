<?php

function ensureRechargeHistoryTable(PDO $pdo): void
{
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS recharge_history (
            id INT(11) NOT NULL AUTO_INCREMENT,
            device_id VARCHAR(100) DEFAULT NULL,
            device_type VARCHAR(30) DEFAULT NULL,
            username VARCHAR(100) NOT NULL,
            profile_name VARCHAR(100) NOT NULL,
            mode VARCHAR(30) NOT NULL,
            operator_username VARCHAR(100) DEFAULT NULL,
            effect_summary VARCHAR(255) DEFAULT NULL,
            amount_value DECIMAL(10,2) DEFAULT NULL,
            amount_label VARCHAR(50) DEFAULT NULL,
            current_profile VARCHAR(100) DEFAULT NULL,
            current_time_limit VARCHAR(100) DEFAULT NULL,
            current_data_limit VARCHAR(100) DEFAULT NULL,
            current_expiration VARCHAR(50) DEFAULT NULL,
            projected_profile VARCHAR(100) DEFAULT NULL,
            projected_time_limit VARCHAR(100) DEFAULT NULL,
            projected_data_limit VARCHAR(100) DEFAULT NULL,
            projected_expiration VARCHAR(50) DEFAULT NULL,
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_recharge_history_username (username),
            KEY idx_recharge_history_device (device_id),
            KEY idx_recharge_history_created_at (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
    );

    $columns = [];
    $stmt = $pdo->query('SHOW COLUMNS FROM recharge_history');
    if ($stmt instanceof PDOStatement) {
        $columns = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
    }

    if (!in_array('amount_value', $columns, true)) {
        $pdo->exec('ALTER TABLE recharge_history ADD COLUMN amount_value DECIMAL(10,2) DEFAULT NULL AFTER effect_summary');
    }

    if (!in_array('amount_label', $columns, true)) {
        $pdo->exec('ALTER TABLE recharge_history ADD COLUMN amount_label VARCHAR(50) DEFAULT NULL AFTER amount_value');
    }
}

function effect_summary_from_mode(string $mode): string
{
    return match ($mode) {
        'replace_offer' => 'Changement d\'offre',
        'extend_offer' => 'Rajout sur temps et data',
        'accumulate_offer' => 'Cumul sur temps et data',
        default => 'Recharge appliquee',
    };
}

function saveRechargeHistory(
    PDO $pdo,
    array $device,
    string $username,
    string $profileName,
    string $mode,
    string $operator,
    array $preview,
    array $amount = ['value' => null, 'label' => '']
): void {
    ensureRechargeHistoryTable($pdo);

    $stmt = $pdo->prepare(
        'INSERT INTO recharge_history (
            device_id,
            device_type,
            username,
            profile_name,
            mode,
            operator_username,
            effect_summary,
            amount_value,
            amount_label,
            current_profile,
            current_time_limit,
            current_data_limit,
            current_expiration,
            projected_profile,
            projected_time_limit,
            projected_data_limit,
            projected_expiration
        ) VALUES (
            :device_id,
            :device_type,
            :username,
            :profile_name,
            :mode,
            :operator_username,
            :effect_summary,
            :amount_value,
            :amount_label,
            :current_profile,
            :current_time_limit,
            :current_data_limit,
            :current_expiration,
            :projected_profile,
            :projected_time_limit,
            :projected_data_limit,
            :projected_expiration
        )'
    );

    $stmt->execute([
        ':device_id' => (string)($device['id'] ?? ''),
        ':device_type' => (string)($device['type'] ?? ''),
        ':username' => $username,
        ':profile_name' => $profileName,
        ':mode' => $mode,
        ':operator_username' => $operator,
        ':effect_summary' => effect_summary_from_mode($mode),
        ':amount_value' => $amount['value'] ?? null,
        ':amount_label' => ($amount['label'] ?? '') !== '' ? $amount['label'] : null,
        ':current_profile' => (string)($preview['current']['profile'] ?? ''),
        ':current_time_limit' => (string)($preview['current']['time_limit'] ?? ''),
        ':current_data_limit' => (string)($preview['current']['data_limit'] ?? ''),
        ':current_expiration' => (string)($preview['current']['expiration'] ?? ''),
        ':projected_profile' => (string)($preview['projected']['profile'] ?? ''),
        ':projected_time_limit' => (string)($preview['projected']['time_limit'] ?? ''),
        ':projected_data_limit' => (string)($preview['projected']['data_limit'] ?? ''),
        ':projected_expiration' => (string)($preview['projected']['expiration'] ?? ''),
    ]);
}
