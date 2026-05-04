<?php

function ensureUsersExtendedSchema(PDO $pdo): void
{
    static $done = false;

    if ($done) {
        return;
    }

    $existing = [];
    $stmt = $pdo->query('SHOW COLUMNS FROM users');
    foreach (($stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : []) as $column) {
        $existing[(string)($column['Field'] ?? '')] = true;
    }

    $alterStatements = [];

    if (!isset($existing['session_timeout'])) {
        $alterStatements[] = 'ADD COLUMN session_timeout INT(11) DEFAULT NULL AFTER profile_id';
    }

    if (!isset($existing['data_limit'])) {
        $alterStatements[] = 'ADD COLUMN data_limit INT(11) DEFAULT NULL AFTER session_timeout';
    }

    if (!isset($existing['current_credit_time'])) {
        $alterStatements[] = 'ADD COLUMN current_credit_time INT(11) NOT NULL DEFAULT 0 AFTER data_limit';
    }

    if (!isset($existing['current_credit_data'])) {
        $alterStatements[] = 'ADD COLUMN current_credit_data BIGINT(20) NOT NULL DEFAULT 0 AFTER current_credit_time';
    }

    if (!isset($existing['imported_session_total_seconds'])) {
        $alterStatements[] = 'ADD COLUMN imported_session_total_seconds BIGINT(20) UNSIGNED NOT NULL DEFAULT 0 AFTER current_credit_data';
    }

    if (!isset($existing['imported_data_consumed_bytes'])) {
        $alterStatements[] = 'ADD COLUMN imported_data_consumed_bytes BIGINT(20) UNSIGNED NOT NULL DEFAULT 0 AFTER imported_session_total_seconds';
    }

    if ($alterStatements !== []) {
        $pdo->exec('ALTER TABLE users ' . implode(', ', $alterStatements));
    }

    $done = true;
}

function ensureUserCounterBaselinesSchema(PDO $pdo): void
{
    static $done = false;

    if ($done) {
        return;
    }

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS user_counter_baselines (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            device_id VARCHAR(191) NOT NULL,
            username VARCHAR(191) NOT NULL,
            imported_session_total_seconds BIGINT UNSIGNED NOT NULL DEFAULT 0,
            imported_data_consumed_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_device_username (device_id, username),
            KEY idx_username (username)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $done = true;
}

function loadUserCounterBaselinesByDevice(PDO $pdo, string $deviceId, array $usernames): array
{
    ensureUserCounterBaselinesSchema($pdo);

    $deviceId = trim($deviceId);
    if ($deviceId === '') {
        return [];
    }

    $normalized = [];
    foreach ($usernames as $username) {
        $value = trim((string)$username);
        if ($value !== '') {
            $normalized[] = $value;
        }
    }

    $normalized = array_values(array_unique($normalized));
    if ($normalized === []) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($normalized), '?'));
    $stmt = $pdo->prepare("
        SELECT username, imported_session_total_seconds, imported_data_consumed_bytes
        FROM user_counter_baselines
        WHERE device_id = ?
          AND username IN ($placeholders)
    ");
    $stmt->execute(array_merge([$deviceId], $normalized));

    $map = [];
    foreach (($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) as $row) {
        $username = strtolower(trim((string)($row['username'] ?? '')));
        if ($username === '') {
            continue;
        }

        $map[$username] = [
            'imported_session_total_seconds' => max(0, (int)($row['imported_session_total_seconds'] ?? 0)),
            'imported_data_consumed_bytes' => max(0, (int)($row['imported_data_consumed_bytes'] ?? 0)),
        ];
    }

    return $map;
}

function loadUserCounterBaseline(PDO $pdo, string $deviceId, string $username): array
{
    $deviceId = trim($deviceId);
    $username = trim($username);

    if ($deviceId === '' || $username === '') {
        return [
            'imported_session_total_seconds' => 0,
            'imported_data_consumed_bytes' => 0,
        ];
    }

    $map = loadUserCounterBaselinesByDevice($pdo, $deviceId, [$username]);

    return $map[strtolower($username)] ?? [
        'imported_session_total_seconds' => 0,
        'imported_data_consumed_bytes' => 0,
    ];
}

function upsertUserCounterBaseline(
    PDO $pdo,
    string $deviceId,
    string $username,
    int $importedSessionTotalSeconds,
    int $importedDataConsumedBytes
): void {
    ensureUserCounterBaselinesSchema($pdo);

    $deviceId = trim($deviceId);
    $username = trim($username);

    if ($deviceId === '' || $username === '') {
        throw new InvalidArgumentException('device_id et username requis pour la baseline utilisateur.');
    }

    $stmt = $pdo->prepare("
        INSERT INTO user_counter_baselines (
            device_id,
            username,
            imported_session_total_seconds,
            imported_data_consumed_bytes
        ) VALUES (?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            imported_session_total_seconds = VALUES(imported_session_total_seconds),
            imported_data_consumed_bytes = VALUES(imported_data_consumed_bytes)
    ");
    $stmt->execute([
        $deviceId,
        $username,
        max(0, $importedSessionTotalSeconds),
        max(0, $importedDataConsumedBytes),
    ]);
}
