<?php

require_once __DIR__ . '/device_manager.php';
require_once __DIR__ . '/mikrotik_backend.php';
require_once __DIR__ . '/profile_catalog.php';
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
            device_id VARCHAR(100) DEFAULT NULL,
            profile_name VARCHAR(100) DEFAULT NULL,
            price DECIMAL(10,2) DEFAULT NULL,
            selling_price DECIMAL(10,2) DEFAULT NULL,
            printed_by VARCHAR(100) DEFAULT NULL,
            used TINYINT(1) DEFAULT 0,
            used_by VARCHAR(100) DEFAULT NULL,
            used_at DATETIME DEFAULT NULL,
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY code (code),
            UNIQUE KEY idx_vouchers_username (username),
            KEY profile_id (profile_id),
            KEY idx_vouchers_device_id (device_id),
            KEY idx_vouchers_profile_name (profile_name),
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
        if (!in_array('device_id', $columns, true)) {
            $pdo->exec("ALTER TABLE vouchers ADD COLUMN device_id VARCHAR(100) DEFAULT NULL AFTER profile_id");
        }
        if (!in_array('profile_name', $columns, true)) {
            $pdo->exec("ALTER TABLE vouchers ADD COLUMN profile_name VARCHAR(100) DEFAULT NULL AFTER device_id");
        }
        if (!in_array('price', $columns, true)) {
            $pdo->exec("ALTER TABLE vouchers ADD COLUMN price DECIMAL(10,2) DEFAULT NULL AFTER profile_name");
        }
        if (!in_array('selling_price', $columns, true)) {
            $pdo->exec("ALTER TABLE vouchers ADD COLUMN selling_price DECIMAL(10,2) DEFAULT NULL AFTER price");
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
        $indexNames = array_map(
            static fn(array $row): string => (string)($row['Key_name'] ?? ''),
            $pdo->query("SHOW INDEX FROM vouchers")->fetchAll(PDO::FETCH_ASSOC) ?: []
        );
        if (!in_array('idx_vouchers_device_id', $indexNames, true)) {
            $pdo->exec("ALTER TABLE vouchers ADD KEY idx_vouchers_device_id (device_id)");
        }
        if (!in_array('idx_vouchers_profile_name', $indexNames, true)) {
            $pdo->exec("ALTER TABLE vouchers ADD KEY idx_vouchers_profile_name (profile_name)");
        }
    } catch (Throwable $e) {
        // migration best effort
    }

    $schemaEnsured = true;
}

function normalizeVoucherCommercialAmount($value): ?string
{
    $raw = trim((string)($value ?? ''));
    if ($raw === '' || $raw === '-') {
        return null;
    }

    if (!is_numeric($raw)) {
        return null;
    }

    $amount = (float)$raw;
    if ($amount <= 0) {
        return null;
    }

    return number_format($amount, 2, '.', '');
}

function resolveVoucherProfileCommercialSnapshot(PDO $pdo, array $deviceStore, string $deviceId, string $profileName): array
{
    $profileName = trim($profileName);
    if ($profileName === '') {
        return ['price' => null, 'selling_price' => null];
    }

    $device = findDeviceById($deviceStore, trim($deviceId));
    if (!is_array($device)) {
        return ['price' => null, 'selling_price' => null];
    }

    $catalog = loadProfileCatalogForDevice($pdo, $device, ['sort' => 'none']);
    $profile = findProfileCatalogEntryByName($catalog, $profileName);
    if (!is_array($profile)) {
        return ['price' => null, 'selling_price' => null];
    }

    return [
        'price' => normalizeVoucherCommercialAmount($profile['price'] ?? null),
        'selling_price' => normalizeVoucherCommercialAmount($profile['selling_price'] ?? null),
    ];
}

function syncVoucherCommercialSnapshots(PDO $pdo): void
{
    ensureVouchersTable($pdo);
    ensureVoucherHistoryTable($pdo);

    $deviceStore = loadDeviceStore();
    $snapshotCache = [];
    $histories = $pdo->query("
        SELECT id, device_id, profile_name, operator_username, quantity, created_at
        FROM voucher_history
        WHERE profile_name IS NOT NULL
          AND profile_name <> ''
          AND created_at IS NOT NULL
        ORDER BY id DESC
        LIMIT 500
    ")->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $update = $pdo->prepare("
        UPDATE vouchers
        SET
            device_id = CASE WHEN device_id IS NULL OR device_id = '' THEN :device_id ELSE device_id END,
            profile_name = CASE WHEN profile_name IS NULL OR profile_name = '' THEN :profile_name ELSE profile_name END,
            price = CASE WHEN (price IS NULL OR price = 0) AND :price IS NOT NULL THEN :price ELSE price END,
            selling_price = CASE WHEN (selling_price IS NULL OR selling_price = 0) AND :selling_price IS NOT NULL THEN :selling_price ELSE selling_price END
        WHERE created_at BETWEEN DATE_SUB(:created_at, INTERVAL 20 SECOND) AND DATE_ADD(:created_at, INTERVAL 3 SECOND)
          AND (
              (printed_by = :operator_username)
              OR (printed_by IS NULL AND :operator_username = '')
          )
          AND (
              device_id IS NULL OR device_id = ''
              OR profile_name IS NULL OR profile_name = ''
              OR price IS NULL OR price = 0
              OR selling_price IS NULL OR selling_price = 0
          )
    ");

    foreach ($histories as $history) {
        $deviceId = trim((string)($history['device_id'] ?? ''));
        $profileName = trim((string)($history['profile_name'] ?? ''));
        $createdAt = trim((string)($history['created_at'] ?? ''));
        if ($deviceId === '' || $profileName === '' || $createdAt === '') {
            continue;
        }

        $cacheKey = $deviceId . '|' . $profileName;
        if (!array_key_exists($cacheKey, $snapshotCache)) {
            try {
                $snapshotCache[$cacheKey] = resolveVoucherProfileCommercialSnapshot($pdo, $deviceStore, $deviceId, $profileName);
            } catch (Throwable $e) {
                $snapshotCache[$cacheKey] = ['price' => null, 'selling_price' => null];
            }
        }

        $snapshot = $snapshotCache[$cacheKey];
        $update->execute([
            ':device_id' => $deviceId,
            ':profile_name' => $profileName,
            ':price' => $snapshot['price'] ?? null,
            ':selling_price' => $snapshot['selling_price'] ?? null,
            ':created_at' => $createdAt,
            ':operator_username' => trim((string)($history['operator_username'] ?? '')),
        ]);
    }
}

function runVoucherUsageMaintenanceIfDue(PDO $pdo, string $scope, int $ttlSeconds = 300): void
{
    $safeScope = preg_replace('/[^a-z0-9_\-]/i', '_', $scope);
    $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'radius_manager_maintenance';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }

    $stamp = $dir . DIRECTORY_SEPARATOR . $safeScope . '.stamp';
    if (is_file($stamp) && (time() - (int)filemtime($stamp)) < $ttlSeconds) {
        return;
    }

    syncVoucherUsage($pdo);
    @touch($stamp);
}

function runVoucherCommercialSnapshotMaintenanceIfDue(PDO $pdo, string $scope, int $ttlSeconds = 1800): void
{
    $safeScope = preg_replace('/[^a-z0-9_\-]/i', '_', $scope);
    $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'radius_manager_maintenance';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }

    $stamp = $dir . DIRECTORY_SEPARATOR . $safeScope . '.stamp';
    if (is_file($stamp) && (time() - (int)filemtime($stamp)) < $ttlSeconds) {
        return;
    }

    syncVoucherCommercialSnapshots($pdo);
    @touch($stamp);
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
    $profileDefaults = is_array($batch['profile_defaults'] ?? null) ? $batch['profile_defaults'] : [];
    $unitAmount = normalizeVoucherCommercialAmount($profileDefaults['price'] ?? null);
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
    $voucherHistoryId = (int)$pdo->lastInsertId();

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
        'amount_value' => $unitAmount,
        'summary' => sprintf('%d voucher(s) enregistrés et imprimés', $saved),
        'details_json' => [
            'first_username' => trim((string)($firstEntry['username'] ?? '')),
            'last_username' => trim((string)($lastEntry['username'] ?? '')),
            'profile_id' => (int)($batch['profile_id'] ?? 0),
            'profile_defaults' => $profileDefaults,
        ],
    ]);

    createAdminNotification($pdo, [
        'severity' => 'info',
        'category' => 'voucher',
        'source_type' => 'voucher_batch_print',
        'source_ref' => (string)$voucherHistoryId,
        'title' => 'Lot vouchers imprimé',
        'message' => sprintf(
            '%d voucher(s) imprimés pour le profil %s par %s.',
            $saved,
            trim((string)($batch['profile_name'] ?? '')) ?: 'Voucher',
            trim((string)($batch['printed_by'] ?? '')) ?: 'system'
        ),
        'details_json' => [
            'voucher_history_id' => $voucherHistoryId,
            'quantity' => $saved,
            'profile_name' => trim((string)($batch['profile_name'] ?? '')) ?: null,
            'operator_username' => trim((string)($batch['printed_by'] ?? '')) ?: null,
            'first_username' => trim((string)($firstEntry['username'] ?? '')) ?: null,
            'last_username' => trim((string)($lastEntry['username'] ?? '')) ?: null,
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
        $insert = $pdo->prepare('
            INSERT INTO vouchers (
                code,
                username,
                password,
                profile_id,
                device_id,
                profile_name,
                price,
                selling_price,
                printed_by
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ');
        $savedIds = [];
        $savedUsers = [];
        $batchDeviceId = trim((string)($batch['device_id'] ?? ''));
        $batchProfileName = trim((string)($batch['profile_name'] ?? ''));
        $profileDefaults = is_array($batch['profile_defaults'] ?? null) ? $batch['profile_defaults'] : [];
        $voucherPrice = normalizeVoucherCommercialAmount($profileDefaults['price'] ?? null);
        $voucherSellingPrice = normalizeVoucherCommercialAmount($profileDefaults['selling_price'] ?? null);
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

                $insert->execute([
                    $code,
                    $username,
                    $password,
                    $profileId > 0 ? $profileId : null,
                    $batchDeviceId !== '' ? $batchDeviceId : null,
                    $batchProfileName !== '' ? $batchProfileName : null,
                    $voucherPrice,
                    $voucherSellingPrice,
                    $printedBy !== '' ? $printedBy : null,
                ]);
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
