<?php

require_once __DIR__ . '/device_manager.php';
require_once __DIR__ . '/mikrotik_backend.php';
require_once __DIR__ . '/profile_catalog.php';
require_once __DIR__ . '/user_provisioning.php';
require_once __DIR__ . '/operation_history.php';
require_once __DIR__ . '/tenancy.php';

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
            used_device_id VARCHAR(100) DEFAULT NULL,
            usage_source VARCHAR(40) DEFAULT NULL,
            owner_admin_id INT(11) DEFAULT NULL,
            created_by_user_id INT(11) DEFAULT NULL,
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY code (code),
            UNIQUE KEY idx_vouchers_username (username),
            KEY profile_id (profile_id),
            KEY idx_vouchers_device_id (device_id),
            KEY idx_vouchers_profile_name (profile_name),
            KEY idx_vouchers_used (used),
            KEY idx_vouchers_used_at (used_at),
            KEY idx_vouchers_used_device (used_device_id),
            KEY idx_vouchers_owner (owner_admin_id),
            KEY idx_vouchers_created_by (created_by_user_id)
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
        if (!in_array('used_device_id', $columns, true)) {
            $pdo->exec("ALTER TABLE vouchers ADD COLUMN used_device_id VARCHAR(100) DEFAULT NULL AFTER used_at");
        }
        if (!in_array('usage_source', $columns, true)) {
            $pdo->exec("ALTER TABLE vouchers ADD COLUMN usage_source VARCHAR(40) DEFAULT NULL AFTER used_device_id");
        }
        if (!in_array('owner_admin_id', $columns, true)) {
            $pdo->exec("ALTER TABLE vouchers ADD COLUMN owner_admin_id INT(11) DEFAULT NULL AFTER usage_source");
        }
        if (!in_array('created_by_user_id', $columns, true)) {
            $pdo->exec("ALTER TABLE vouchers ADD COLUMN created_by_user_id INT(11) DEFAULT NULL AFTER owner_admin_id");
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
        $pdo->exec("UPDATE vouchers v JOIN network_devices d ON d.id=v.device_id SET v.owner_admin_id=d.owner_admin_id WHERE v.owner_admin_id IS NULL AND d.owner_admin_id IS NOT NULL");
        foreach ([
            'idx_vouchers_used_device' => 'used_device_id',
            'idx_vouchers_owner' => 'owner_admin_id',
            'idx_vouchers_created_by' => 'created_by_user_id',
        ] as $indexName => $columnName) {
            if (!in_array($indexName, $indexNames, true)) {
                $pdo->exec("ALTER TABLE vouchers ADD KEY {$indexName} ({$columnName})");
            }
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

function ensureVoucherHistoryTable(PDO $pdo): void
{
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS voucher_history (
            id INT(11) NOT NULL AUTO_INCREMENT,
            device_id VARCHAR(100) DEFAULT NULL,
            owner_admin_id INT(11) DEFAULT NULL,
            created_by_user_id INT(11) DEFAULT NULL,
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
            KEY idx_voucher_history_owner (owner_admin_id),
            KEY idx_voucher_history_created_by (created_by_user_id),
            KEY idx_voucher_history_profile (profile_name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
    );
    try {
        $columns = $pdo->query("SHOW COLUMNS FROM voucher_history")->fetchAll(PDO::FETCH_COLUMN) ?: [];
        if (!in_array('owner_admin_id', $columns, true)) {
            $pdo->exec("ALTER TABLE voucher_history ADD COLUMN owner_admin_id INT(11) DEFAULT NULL AFTER device_id");
        }
        if (!in_array('created_by_user_id', $columns, true)) {
            $pdo->exec("ALTER TABLE voucher_history ADD COLUMN created_by_user_id INT(11) DEFAULT NULL AFTER owner_admin_id");
        }
        $pdo->exec("UPDATE voucher_history h JOIN network_devices d ON d.id=h.device_id SET h.owner_admin_id=d.owner_admin_id WHERE h.owner_admin_id IS NULL AND d.owner_admin_id IS NOT NULL");
    } catch (Throwable $e) { }
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
            owner_admin_id,
            created_by_user_id,
            profile_id,
            profile_name,
            operator_username,
            quantity,
            first_username,
            last_username
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");

    $stmt->execute([
        trim((string)($batch['device_id'] ?? '')) ?: null,
        max(0, (int)($_SESSION['owner_admin_id'] ?? $_SESSION['local_admin_id'] ?? 0)) ?: null,
        max(0, (int)($_SESSION['local_admin_id'] ?? 0)) ?: null,
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

    $batchStartTime = microtime(true);
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
                printed_by,
                owner_admin_id,
                created_by_user_id
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ');
        $savedIds = [];
        $savedUsers = [];
        $savedEntries = [];
        $batchDeviceId = trim((string)($batch['device_id'] ?? ''));
        $batchProfileName = trim((string)($batch['profile_name'] ?? ''));
        $profileDefaults = is_array($batch['profile_defaults'] ?? null) ? $batch['profile_defaults'] : [];
        $voucherPrice = normalizeVoucherCommercialAmount($profileDefaults['price'] ?? null);
        $voucherSellingPrice = normalizeVoucherCommercialAmount($profileDefaults['selling_price'] ?? null);
        $printedBy = trim((string)($batch['printed_by'] ?? ''));
        $ownerAdminId = max(0, (int)($_SESSION['owner_admin_id'] ?? $_SESSION['local_admin_id'] ?? 0));
        $createdByUserId = max(0, (int)($_SESSION['local_admin_id'] ?? 0));

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
                    $ownerAdminId > 0 ? $ownerAdminId : null,
                    $createdByUserId > 0 ? $createdByUserId : null,
                ]);
                $savedId = (int)$pdo->lastInsertId();
                $savedIds[] = $savedId;
                $savedUsers[] = [
                    'id' => (int)($provisioned['user_id'] ?? 0),
                    'username' => $username,
                ];
                $savedEntries[] = array_merge(
                    [
                        'id' => $savedId,
                        'code' => $code,
                        'username' => $username,
                        'password' => $password,
                        'profile_name' => $batchProfileName,
                        'price' => $voucherPrice,
                        'selling_price' => $voucherSellingPrice,
                        'printed_by' => $printedBy,
                        'printed_at' => (string)($batch['printed_at'] ?? ''),
                    ],
                    $profileDefaults
                );
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
        purgeVoucherOverflow($pdo, 5000);
        $batchDuration = microtime(true) - $batchStartTime;
        error_log(sprintf('[PERF] savePreparedVoucherBatch total: %.3f sec for %d entries', $batchDuration, count($savedIds)));
        return [
            'saved' => count($savedIds),
            'ids' => $savedIds,
            'users' => $savedUsers,
            'entries' => $savedEntries,
        ];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function purgeVoucherOverflow(PDO $pdo, int $limit = 5000): int
{
    $limit = max(1, $limit);
    $count = (int)$pdo->query('SELECT COUNT(*) FROM vouchers')->fetchColumn();
    if ($count <= $limit) return 0;
    $remove = $count - $limit;
    $stmt = $pdo->prepare('DELETE FROM vouchers ORDER BY created_at ASC, id ASC LIMIT ' . $remove);
    $stmt->execute();
    return $stmt->rowCount();
}

function syncVoucherUsage(PDO $pdo): array
{
    ensureVouchersTable($pdo);
    $voucherRows = $pdo->query("SELECT id, code, username, device_id FROM vouchers WHERE (used = 0 OR used_at IS NULL) ORDER BY id ASC LIMIT 5000")?->fetchAll(PDO::FETCH_ASSOC) ?: [];
    if (!$voucherRows) return ['updated' => 0];
    $firstUsageByKey = [];
    $devices = [];
    try { $devices = $pdo->query("SELECT id,host FROM network_devices WHERE is_active=1")->fetchAll(PDO::FETCH_ASSOC) ?: []; } catch (Throwable $e) {}
    $findDevice = static function (string $nasIp) use ($devices): ?string {
        $nasIp = trim($nasIp);
        if ($nasIp === '') return null;
        foreach ($devices as $device) {
            $host = trim((string)($device['ip'] ?? $device['host'] ?? ''));
            if ($host === $nasIp || extractDeviceAddress($host) === $nasIp) return (string)($device['id'] ?? '');
        }
        return null;
    };
    try {
        $rows = $pdo->query("SELECT username, nasipaddress, MIN(acctstarttime) AS first_login FROM radacct WHERE acctstarttime IS NOT NULL GROUP BY username, nasipaddress")->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as $row) {
            $u = trim((string)($row['username'] ?? '')); $t = trim((string)($row['first_login'] ?? '')); if ($u === '' || $t === '') continue;
            $usageDeviceId = $findDevice((string)($row['nasipaddress'] ?? ''));
            if ($usageDeviceId === null) continue;
            $candidate = ['at' => $t, 'device_id' => $usageDeviceId, 'source' => 'radius'];
            $usageKey = $u . '|' . $usageDeviceId;
            if (!isset($firstUsageByKey[$usageKey]) || strcmp($t, $firstUsageByKey[$usageKey]['at']) < 0) $firstUsageByKey[$usageKey] = $candidate;
        }
    } catch (Throwable $e) {}
    $update = $pdo->prepare("UPDATE vouchers SET used=1, used_by=:used_by, used_at=:used_at, used_device_id=:used_device_id, usage_source=:usage_source WHERE id=:id AND (used=0 OR used_at IS NULL)");
    $updated = 0;
    foreach ($voucherRows as $row) {
        $u = trim((string)($row['username'] ?? ''));
        $voucherDeviceId = trim((string)($row['device_id'] ?? ''));
        if ($u === '' || $voucherDeviceId === '') continue;
        $usageKey = $u . '|' . $voucherDeviceId;
        if (!isset($firstUsageByKey[$usageKey])) continue;
        $usage = $firstUsageByKey[$usageKey];
        $update->execute([':used_by'=>$u, ':used_at'=>$usage['at'], ':used_device_id'=>$usage['device_id'] ?: ($row['device_id'] ?: null), ':usage_source'=>$usage['source'], ':id'=>(int)$row['id']]);
        $updated += $update->rowCount();
    }
    return ['updated' => $updated];
}
