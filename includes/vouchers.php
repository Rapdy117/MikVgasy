<?php

require_once __DIR__ . '/device_manager.php';
require_once __DIR__ . '/mikrotik_backend.php';
require_once __DIR__ . '/user_provisioning.php';
require_once __DIR__ . '/operation_history.php';

function ensureVouchersTable(PDO $pdo): void
{
    static $schemaEnsured = false;
    if ($schemaEnsured) {
        return;
    }

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS vouchers (
            id INT(11) NOT NULL AUTO_INCREMENT,
            code VARCHAR(100) NOT NULL,
            username VARCHAR(100) DEFAULT NULL,
            password VARCHAR(100) DEFAULT NULL,
            profile_id INT(11) DEFAULT NULL,
            printed_by VARCHAR(100) DEFAULT NULL,
            used TINYINT(1) DEFAULT 0,
            used_by VARCHAR(100) DEFAULT NULL,
            used_at DATETIME DEFAULT NULL,
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY code (code),
            UNIQUE KEY idx_vouchers_username (username),
            KEY profile_id (profile_id),
            KEY idx_vouchers_used (used),
            KEY idx_vouchers_used_at (used_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
    );

    try {
        $columns = $pdo->query("SHOW COLUMNS FROM vouchers")->fetchAll(PDO::FETCH_COLUMN) ?: [];
        if (!in_array('username', $columns, true)) {
            $pdo->exec("ALTER TABLE vouchers ADD COLUMN username VARCHAR(100) DEFAULT NULL AFTER code");
        }
        if (!in_array('password', $columns, true)) {
            $pdo->exec("ALTER TABLE vouchers ADD COLUMN password VARCHAR(100) DEFAULT NULL AFTER username");
        }
        if (!in_array('printed_by', $columns, true)) {
            $pdo->exec("ALTER TABLE vouchers ADD COLUMN printed_by VARCHAR(100) DEFAULT NULL AFTER profile_id");
        }
        foreach ($pdo->query("SHOW COLUMNS FROM vouchers WHERE Field = 'profile_id'")->fetchAll(PDO::FETCH_ASSOC) ?: [] as $column) {
            if (strtoupper((string)($column['Null'] ?? '')) === 'NO') {
                $pdo->exec("ALTER TABLE vouchers MODIFY profile_id INT(11) DEFAULT NULL");
            }
        }
        if (!in_array('idx_vouchers_username', array_map(
            static fn(array $row): string => (string)($row['Key_name'] ?? ''),
            $pdo->query("SHOW INDEX FROM vouchers")->fetchAll(PDO::FETCH_ASSOC) ?: []
        ), true)) {
            $pdo->exec("ALTER TABLE vouchers ADD UNIQUE KEY idx_vouchers_username (username)");
        }
    } catch (Throwable $e) {
        // migration best effort
    }

    $schemaEnsured = true;
}

function ensureVoucherHistoryTable(PDO $pdo): void
{
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS voucher_history (
            id INT(11) NOT NULL AUTO_INCREMENT,
            device_id VARCHAR(100) DEFAULT NULL,
            profile_id INT(11) DEFAULT NULL,
            profile_name VARCHAR(100) DEFAULT NULL,
            operator_username VARCHAR(100) DEFAULT NULL,
            quantity INT(11) NOT NULL DEFAULT 0,
            first_username VARCHAR(100) DEFAULT NULL,
            last_username VARCHAR(100) DEFAULT NULL,
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_voucher_history_created_at (created_at),
            KEY idx_voucher_history_operator (operator_username),
            KEY idx_voucher_history_profile (profile_name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
    );
}

function recordVoucherBatchHistory(PDO $pdo, array $batch, array $saveResult): void
{
    ensureVoucherHistoryTable($pdo);

    $saved = (int)($saveResult['saved'] ?? 0);
    if ($saved <= 0) {
        return;
    }

    $entries = is_array($batch['entries'] ?? null) ? $batch['entries'] : [];
    $firstEntry = $entries[0] ?? [];
    $lastEntry = $entries !== [] ? $entries[count($entries) - 1] : [];

    $stmt = $pdo->prepare("
        INSERT INTO voucher_history (
            device_id,
            profile_id,
            profile_name,
            operator_username,
            quantity,
            first_username,
            last_username
        ) VALUES (?, ?, ?, ?, ?, ?, ?)
    ");

    $stmt->execute([
        trim((string)($batch['device_id'] ?? '')) ?: null,
        (int)($batch['profile_id'] ?? 0) > 0 ? (int)$batch['profile_id'] : null,
        trim((string)($batch['profile_name'] ?? '')) ?: null,
        trim((string)($batch['printed_by'] ?? '')) ?: null,
        $saved,
        trim((string)($firstEntry['username'] ?? '')) ?: null,
        trim((string)($lastEntry['username'] ?? '')) ?: null,
    ]);

    recordOperationHistory($pdo, [
        'operation_scope' => 'commercial',
        'operation_type' => 'voucher_batch',
        'actor_username' => trim((string)($batch['printed_by'] ?? '')) ?: null,
        'actor_role' => (string)($_SESSION['user_role'] ?? 'administrator'),
        'target_type' => 'voucher_batch',
        'target_name' => trim((string)($batch['profile_name'] ?? '')) ?: 'Voucher',
        'device_id' => trim((string)($batch['device_id'] ?? '')) ?: null,
        'profile_name' => trim((string)($batch['profile_name'] ?? '')) ?: null,
        'quantity' => $saved,
        'summary' => sprintf('%d voucher(s) enregistrés et imprimés', $saved),
        'details_json' => [
            'first_username' => trim((string)($firstEntry['username'] ?? '')),
            'last_username' => trim((string)($lastEntry['username'] ?? '')),
            'profile_id' => (int)($batch['profile_id'] ?? 0),
        ],
    ]);
}

function savePreparedVoucherBatch(PDO $pdo, array $batch): array
{
    ensureVouchersTable($pdo);

    $entries = $batch['entries'] ?? [];
    $profileId = (int)($batch['profile_id'] ?? 0);
    if (!is_array($entries) || $entries === []) {
        return ['saved' => 0, 'ids' => []];
    }

    $pdo->beginTransaction();
    try {
        $insert = $pdo->prepare('INSERT INTO vouchers (code, username, password, profile_id, printed_by) VALUES (?, ?, ?, ?, ?)');
        $savedIds = [];
        $savedUsers = [];
        $batchDeviceId = trim((string)($batch['device_id'] ?? ''));
        $batchProfileName = trim((string)($batch['profile_name'] ?? ''));
        $profileDefaults = is_array($batch['profile_defaults'] ?? null) ? $batch['profile_defaults'] : [];
        $printedBy = trim((string)($batch['printed_by'] ?? ''));

        foreach ($entries as $index => $entry) {
            $username = trim((string)($entry['username'] ?? ''));
            $password = trim((string)($entry['password'] ?? ''));
            $code = trim((string)($entry['code'] ?? $username));
            if ($username === '' || $password === '' || $code === '') {
                continue;
            }

            $savepoint = 'voucher_entry_' . $index;
            $pdo->exec("SAVEPOINT $savepoint");

            try {
                $provisioned = provisionUserWithProfile($pdo, [
                    'username' => $username,
                    'password' => $password,
                    'profile_id' => $profileId,
                    'profile_name' => $batchProfileName,
                    'device_id' => $batchDeviceId,
                    'profile_defaults' => $profileDefaults,
                ]);

                $insert->execute([$code, $username, $password, $profileId > 0 ? $profileId : null, $printedBy !== '' ? $printedBy : null]);
                $savedIds[] = (int)$pdo->lastInsertId();
                $savedUsers[] = [
                    'id' => (int)($provisioned['user_id'] ?? 0),
                    'username' => $username,
                ];
            } catch (PDOException $e) {
                $pdo->exec("ROLLBACK TO SAVEPOINT $savepoint");
                if (($e->errorInfo[1] ?? null) === 1062) {
                    continue;
                }
                throw $e;
            } catch (RuntimeException $e) {
                $pdo->exec("ROLLBACK TO SAVEPOINT $savepoint");
                $message = trim((string)$e->getMessage());
                if ($message === 'Utilisateur deja present sur MikroTik.' || $message === 'Ce nom d utilisateur existe deja. Choisissez-en un autre.') {
                    continue;
                }
                throw $e;
            }
        }

        $pdo->commit();
        return ['saved' => count($savedIds), 'ids' => $savedIds, 'users' => $savedUsers];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function syncVoucherUsage(PDO $pdo): array
{
    ensureVouchersTable($pdo);

    $voucherRows = $pdo->query("SELECT code, username FROM vouchers WHERE (used = 0 OR used_at IS NULL)")
        ?->fetchAll(PDO::FETCH_ASSOC) ?: [];

    if (!$voucherRows) {
        return ['updated' => 0];
    }

    $firstUsageByCode = [];

    try {
        $store = loadDeviceStore();
        $activeDevice = getActiveDeviceRecord($store);
        $isMikrotik = is_array($activeDevice) && (($activeDevice['type'] ?? '') === 'mikrotik');

        if ($isMikrotik) {
            $logs = getMikrotikUserLogs(null, null, 3000);
            foreach ($logs as $log) {
                $username = trim((string)($log['username'] ?? ''));
                $date = trim((string)($log['date'] ?? ''));
                $time = trim((string)($log['time'] ?? ''));
                if ($username === '' || $date === '' || $time === '') {
                    continue;
                }

                $timestamp = str_replace('/', '-', $date) . ' ' . $time;
                if (!isset($firstUsageByCode[$username]) || strcmp($timestamp, $firstUsageByCode[$username]) < 0) {
                    $firstUsageByCode[$username] = $timestamp;
                }
            }
        }
    } catch (Throwable $e) {
        // La synchro MikroTik ne doit pas bloquer la page.
    }

    try {
        $radacctRows = $pdo->query("
            SELECT username, MIN(acctstarttime) AS first_login
            FROM radacct
            WHERE acctstarttime IS NOT NULL
            GROUP BY username
        ")->fetchAll(PDO::FETCH_ASSOC) ?: [];

        foreach ($radacctRows as $row) {
            $username = trim((string)($row['username'] ?? ''));
            $firstLogin = trim((string)($row['first_login'] ?? ''));
            if ($username === '' || $firstLogin === '') {
                continue;
            }

            if (!isset($firstUsageByCode[$username]) || strcmp($firstLogin, $firstUsageByCode[$username]) < 0) {
                $firstUsageByCode[$username] = $firstLogin;
            }
        }
    } catch (Throwable $e) {
        // La synchro RADIUS reste best effort.
    }

    $updateStmt = $pdo->prepare("
        UPDATE vouchers
        SET used = 1,
            used_by = :used_by,
            used_at = :used_at
        WHERE code = :code
          AND (used = 0 OR used_at IS NULL)
    ");

    $updated = 0;
    foreach ($voucherRows as $voucherRow) {
        $voucherUsername = trim((string)($voucherRow['username'] ?? ''));
        $voucherCode = trim((string)($voucherRow['code'] ?? ''));
        $lookupKey = $voucherUsername !== '' ? $voucherUsername : $voucherCode;

        if ($lookupKey === '' || !isset($firstUsageByCode[$lookupKey])) {
            continue;
        }

        $updateStmt->execute([
            ':used_by' => $lookupKey,
            ':used_at' => $firstUsageByCode[$lookupKey],
            ':code' => $voucherCode,
        ]);

        $updated += $updateStmt->rowCount();
    }

    return ['updated' => $updated];
}
