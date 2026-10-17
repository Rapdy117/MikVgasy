<?php

require_once __DIR__ . '/vouchers.php';
require_once __DIR__ . '/operation_history.php';
require_once __DIR__ . '/profile_schema.php';
require_once __DIR__ . '/admin_notifications.php';
require_once __DIR__ . '/tenancy.php';


/** SQL scope for recouvrement sources. Caller must pass the returned params to PDO. */
function recouvrementTenantPredicate(string $alias, array &$params, string $prefix, string $actorColumn = 'operator_username', bool $hasDevice = true): string
{
    $role = strtolower((string)($_SESSION['user_role'] ?? 'admin'));
    if ($role === 'superadmin') {
        return '1=1';
    }
    $owner = max(0, (int)($_SESSION['owner_admin_id'] ?? $_SESSION['local_admin_id'] ?? 0));
    $params[$prefix . '_owner'] = $owner;
    if ($role === 'reseller') {
        $params[$prefix . '_user'] = max(0, (int)($_SESSION['local_admin_id'] ?? 0));
        $params[$prefix . '_actor'] = trim((string)($_SESSION['username'] ?? ''));
        if ($actorColumn === 'actor_username') {
            return sprintf('(%s.owner_admin_id = :%s_owner AND %s.%s = :%s_actor)', $alias, $prefix, $alias, $actorColumn, $prefix);
        }
        return sprintf('(%s.owner_admin_id = :%s_owner AND (%s.created_by_user_id = :%s_user OR %s.%s = :%s_actor))', $alias, $prefix, $alias, $prefix, $alias, $actorColumn, $prefix);
    }
    if ($hasDevice) {
        $params[$prefix . '_device_owner'] = $owner;
        return sprintf('(%s.owner_admin_id = :%s_owner OR (%s.owner_admin_id IS NULL AND %s.device_id IN (SELECT nd.id FROM network_devices nd WHERE nd.owner_admin_id = :%s_device_owner)))', $alias, $prefix, $alias, $alias, $prefix);
    }
    return sprintf('%s.owner_admin_id = :%s_owner', $alias, $prefix);
}

function recouvrementAssertOwner(PDO $pdo, int $ownerAdminId, ?string $operator = null): void
{
    $role = strtolower((string)($_SESSION['user_role'] ?? 'admin'));
    if ($role === 'superadmin') return;
    $currentOwner = max(0, (int)($_SESSION['owner_admin_id'] ?? $_SESSION['local_admin_id'] ?? 0));
    if ($ownerAdminId !== $currentOwner) throw new RuntimeException('Périmètre de recouvrement non autorisé.');
    if ($role === 'reseller' && $operator !== null && trim($operator) !== trim((string)($_SESSION['username'] ?? ''))) {
        throw new RuntimeException('Revendeur non autorisé.');
    }
}


function recouvrementExecuteScoped(PDO $pdo, string $sql, string $table, string $alias, string $actorColumn = 'operator_username', bool $hasDevice = true): PDOStatement
{
    $params = [];
    $scope = recouvrementTenantPredicate($alias, $params, 'scope_' . preg_replace('/[^a-z0-9_]/i', '_', $alias), $actorColumn, $hasDevice);
    if (!preg_match('/\b' . preg_quote($alias, '/') . '\s*\./i', $sql)) {
        $sql = preg_replace('/(\bFROM\s+' . preg_quote($table, '/') . ')(\b)/i', '$1 ' . $alias . '$2', $sql, 1);
    }
    if (preg_match('/\bWHERE\s+' . preg_quote($alias, '/') . '\s*\./i', $sql)) {
        $sql = preg_replace('/\bWHERE\s+' . preg_quote($alias, '/') . '\s*\./i', 'WHERE ' . $scope . ' AND ' . $alias . '.', $sql, 1);
    } elseif (preg_match('/\bWHERE\b/i', $sql)) {
        $sql = preg_replace('/\bWHERE\b/i', 'WHERE ' . $scope . ' AND ', $sql, 1);
    } else {
        $sql = rtrim($sql) . ' WHERE ' . $scope;
    }
    $stmt = $pdo->prepare($sql);
    foreach ($params as $key => $value) {
        $stmt->bindValue(':' . ltrim($key, ':'), $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
    $stmt->execute();
    return $stmt;
}

function requireRecouvrementAccess(string $message = 'Accès recouvrement non autorisé.'): void
{
    if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
        header('Location: /index.php');
        exit();
    }
    $role = strtolower((string)($_SESSION['user_role'] ?? ''));
    if (!in_array($role, ['superadmin', 'admin', 'reseller'], true)) {
        if (function_exists('set_message')) set_message($message, 'warning');
        header('Location: /pages/dashboard.php');
        exit();
    }
}

function ensureRecouvrementInvoicesTable(PDO $pdo): void
{
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS recouvrement_invoices (
            id INT(11) NOT NULL AUTO_INCREMENT,
            invoice_number VARCHAR(50) NOT NULL,
            operator_username VARCHAR(100) NOT NULL,
            owner_admin_id INT(11) DEFAULT NULL,
            device_id VARCHAR(100) DEFAULT NULL,
            created_by_user_id INT(11) DEFAULT NULL,
            period_from DATE NOT NULL,
            period_to DATE NOT NULL,
            selected_lines_json LONGTEXT DEFAULT NULL,
            summary_json LONGTEXT DEFAULT NULL,
            movements_json LONGTEXT DEFAULT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            created_by VARCHAR(100) DEFAULT NULL,
            paid_by VARCHAR(100) DEFAULT NULL,
            paid_at DATETIME DEFAULT NULL,
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_recouvrement_invoice_number (invoice_number),
            KEY idx_recouvrement_invoice_operator (operator_username),
            KEY idx_recouvrement_invoice_owner (owner_admin_id),
            KEY idx_recouvrement_invoice_device (device_id),
            KEY idx_recouvrement_invoice_status (status),
            KEY idx_recouvrement_invoice_created_at (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
    );
    try {
        $columns = $pdo->query("SHOW COLUMNS FROM recouvrement_invoices")->fetchAll(PDO::FETCH_COLUMN) ?: [];
        if (!in_array('owner_admin_id', $columns, true)) $pdo->exec("ALTER TABLE recouvrement_invoices ADD COLUMN owner_admin_id INT(11) DEFAULT NULL AFTER operator_username");
        if (!in_array('device_id', $columns, true)) $pdo->exec("ALTER TABLE recouvrement_invoices ADD COLUMN device_id VARCHAR(100) DEFAULT NULL AFTER owner_admin_id");
        if (!in_array('created_by_user_id', $columns, true)) $pdo->exec("ALTER TABLE recouvrement_invoices ADD COLUMN created_by_user_id INT(11) DEFAULT NULL AFTER device_id");
    } catch (Throwable $e) { }
}

function ensureRecouvrementInvoiceMovementsTable(PDO $pdo): void
{
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS recouvrement_invoice_movements (
            id INT(11) NOT NULL AUTO_INCREMENT,
            invoice_id INT(11) NOT NULL,
            source_type VARCHAR(30) NOT NULL,
            source_id INT(11) NOT NULL,
            operator_username VARCHAR(100) DEFAULT NULL,
            username VARCHAR(100) DEFAULT NULL,
            profile_name VARCHAR(100) DEFAULT NULL,
            created_at DATETIME DEFAULT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_invoice_source (source_type, source_id),
            KEY idx_recouvrement_invoice_movements_invoice (invoice_id),
            KEY idx_recouvrement_invoice_movements_operator (operator_username)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
    );
}

if (!function_exists('notifyMissingVoucherPrice')) {
    function notifyMissingVoucherPrice(PDO $pdo): void
    {
        $stmt = $pdo->query("
            SELECT DISTINCT v.profile_id, COALESCE(NULLIF(v.profile_name, ''), p.name) AS profile_name, COALESCE(v.price, p.price) AS profile_price
            FROM vouchers v
            LEFT JOIN profiles p ON p.id = v.profile_id
            WHERE v.used_at IS NOT NULL
        ");
        $rows = $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];

        foreach ($rows as $row) {
            $profileId = (int)($row['profile_id'] ?? 0);
            if ($profileId <= 0) {
                continue;
            }
            $price = (float)($row['profile_price'] ?? 0);
            if ($price > 0) {
                continue;
            }

            $sourceRef = 'profile:' . $profileId;
            if (adminNotificationExists($pdo, 'voucher_price_missing', $sourceRef)) {
                continue;
            }

            $profileName = trim((string)($row['profile_name'] ?? ''));
            $profileLabel = $profileName !== '' ? $profileName : ('Profil #' . $profileId);

            createAdminNotification($pdo, [
                'severity' => 'warning',
                'category' => 'commercial',
                'source_type' => 'voucher_price_missing',
                'source_ref' => $sourceRef,
                'title' => 'Prix profil manquant',
                'message' => sprintf('Le profil %s utilisé par des vouchers n a pas de prix valide.', $profileLabel),
                'details_json' => [
                    'profile_id' => $profileId,
                    'profile_name' => $profileName,
                    'price' => $row['profile_price'],
                ],
            ]);
        }
    }
}

function normalizeRecouvrementSelectedLines(string $operator, array $items): array
{
    $selectedLines = [];

    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }

        $itemOperator = trim((string)($item['operator'] ?? ''));
        $itemUsername = trim((string)($item['username'] ?? ''));
        $itemProfile = trim((string)($item['profile'] ?? ''));

        if ($itemOperator !== $operator || $itemUsername === '' || $itemUsername === '-') {
            continue;
        }

        $selectedLines[] = [
            'username' => $itemUsername,
            'profile' => $itemProfile !== '' ? $itemProfile : '-',
        ];
    }

    return array_values(array_map('unserialize', array_unique(array_map('serialize', $selectedLines))));
}


function recouvrementAssertOperatorAccess(PDO $pdo, string $operator): void
{
    $operator = trim($operator);
    if ($operator === '') throw new RuntimeException('Revendeur manquant.');
    $role = strtolower((string)($_SESSION['user_role'] ?? 'admin'));
    if ($role === 'superadmin') return;
    if ($role === 'reseller' && $operator !== trim((string)($_SESSION['username'] ?? ''))) {
        throw new RuntimeException('Revendeur non autorisé.');
    }
    $stmt = $pdo->prepare("SELECT role, owner_admin_id, id FROM local_admin_users WHERE username = ? LIMIT 1");
    $stmt->execute([$operator]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $owner = max(0, (int)($_SESSION['owner_admin_id'] ?? $_SESSION['local_admin_id'] ?? 0));
    if (!$row || strtolower((string)($row['role'] ?? '')) !== 'reseller' || (int)($row['owner_admin_id'] ?? 0) !== $owner) {
        throw new RuntimeException('Revendeur hors périmètre.');
    }
}

function calculateRecouvrementInvoiceSnapshot(
    PDO $pdo,
    string $operator,
    DateTimeImmutable $fromDate,
    DateTimeImmutable $toDate,
    array $selectedLines = []
): array {
    recouvrementAssertOperatorAccess($pdo, $operator);
    $periodStart = $fromDate->format('Y-m-d') . ' 00:00:00';
    $periodEnd = $toDate->format('Y-m-d') . ' 23:59:59';

    $summary = [
        'operator' => $operator,
        'period_from' => $fromDate->format('Y-m-d'),
        'period_to' => $toDate->format('Y-m-d'),
        'total_recharges' => 0,
        'total_amount' => 0.0,
        'users_count' => 0,
        'profiles_count' => 0,
        'voucher_batches' => 0,
        'voucher_total' => 0,
        'commercial_operations' => 0,
    ];
    $movementItems = [];

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS recharge_history (
            id INT(11) NOT NULL AUTO_INCREMENT,
            username VARCHAR(100) NOT NULL,
            profile_name VARCHAR(100) DEFAULT NULL,
            mode VARCHAR(50) NOT NULL,
            operator_username VARCHAR(100) DEFAULT NULL,
            effect_summary TEXT DEFAULT NULL,
            amount_value DECIMAL(10,2) DEFAULT 0.00,
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
    );

    ensureVoucherHistoryTable($pdo);
    ensureVouchersTable($pdo);
    syncVoucherUsage($pdo);
    ensureOperationHistoryTable($pdo);
    ensureRecouvrementInvoicesTable($pdo);
    ensureRecouvrementInvoiceMovementsTable($pdo);
    ensureProfilesExtendedSchema($pdo);
    syncVoucherCommercialSnapshots($pdo);
    notifyMissingVoucherPrice($pdo);

    $rechargeSummaryStmt = $pdo->prepare("
        SELECT
            COUNT(*) AS total_recharges,
            COALESCE(SUM(amount_value), 0) AS total_amount,
            COUNT(DISTINCT username) AS users_count,
            COUNT(DISTINCT profile_name) AS profiles_count
        FROM recharge_history
        WHERE operator_username = :operator
          AND created_at BETWEEN :period_start AND :period_end
          AND NOT EXISTS (
              SELECT 1
              FROM recouvrement_invoice_movements rim
              WHERE rim.source_type = 'recharge'
                AND rim.source_id = recharge_history.id
          )
    ");
    $rechargeSummaryStmt->execute([
        ':operator' => $operator,
        ':period_start' => $periodStart,
        ':period_end' => $periodEnd,
    ]);
    $rechargeSummary = $rechargeSummaryStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $summary['total_recharges'] = (int)($rechargeSummary['total_recharges'] ?? 0);
    $summary['total_amount'] = (float)($rechargeSummary['total_amount'] ?? 0);
    $summary['users_count'] = (int)($rechargeSummary['users_count'] ?? 0);
    $summary['profiles_count'] = (int)($rechargeSummary['profiles_count'] ?? 0);

    $voucherUseSummaryStmt = $pdo->prepare("
        SELECT
            COUNT(*) AS total_uses,
            COALESCE(SUM(COALESCE(v.price, p.price, 0)), 0) AS total_amount
        FROM vouchers v
        LEFT JOIN profiles p ON p.id = v.profile_id
        WHERE v.printed_by = :operator
          AND v.used_at BETWEEN :period_start AND :period_end
          AND NOT EXISTS (
              SELECT 1
              FROM recouvrement_invoice_movements rim
              WHERE rim.source_type = 'voucher_use'
                AND rim.source_id = v.id
          )
    ");
    $voucherUseSummaryStmt->execute([
        ':operator' => $operator,
        ':period_start' => $periodStart,
        ':period_end' => $periodEnd,
    ]);
    $voucherUseSummary = $voucherUseSummaryStmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $summary['total_recharges'] += (int)($voucherUseSummary['total_uses'] ?? 0);
    $summary['total_amount'] += (float)($voucherUseSummary['total_amount'] ?? 0);

    $combinedUsersStmt = $pdo->prepare("
        SELECT COUNT(DISTINCT username) AS users_count
        FROM (
            SELECT username
            FROM recharge_history
            WHERE operator_username = :operator
              AND created_at BETWEEN :period_start AND :period_end
              AND NOT EXISTS (
                  SELECT 1
                  FROM recouvrement_invoice_movements rim
                  WHERE rim.source_type = 'recharge'
                    AND rim.source_id = recharge_history.id
              )
            UNION
            SELECT COALESCE(NULLIF(v.username, ''), v.used_by) AS username
            FROM vouchers v
            WHERE v.printed_by = :operator
              AND v.used_at BETWEEN :period_start AND :period_end
              AND NOT EXISTS (
                  SELECT 1
                  FROM recouvrement_invoice_movements rim
                  WHERE rim.source_type = 'voucher_use'
                    AND rim.source_id = v.id
              )
        ) users
        WHERE username IS NOT NULL AND username <> ''
    ");
    $combinedUsersStmt->execute([
        ':operator' => $operator,
        ':period_start' => $periodStart,
        ':period_end' => $periodEnd,
    ]);
    $summary['users_count'] = (int)($combinedUsersStmt->fetchColumn() ?: $summary['users_count']);

    $combinedProfilesStmt = $pdo->prepare("
        SELECT COUNT(DISTINCT profile_name) AS profiles_count
        FROM (
            SELECT COALESCE(NULLIF(profile_name, ''), '-') AS profile_name
            FROM recharge_history
            WHERE operator_username = :operator
              AND created_at BETWEEN :period_start AND :period_end
              AND NOT EXISTS (
                  SELECT 1
                  FROM recouvrement_invoice_movements rim
                  WHERE rim.source_type = 'recharge'
                    AND rim.source_id = recharge_history.id
              )
            UNION
            SELECT COALESCE(NULLIF(v.profile_name, ''), p.name, '-') AS profile_name
            FROM vouchers v
            LEFT JOIN profiles p ON p.id = v.profile_id
            WHERE v.printed_by = :operator
              AND v.used_at BETWEEN :period_start AND :period_end
              AND NOT EXISTS (
                  SELECT 1
                  FROM recouvrement_invoice_movements rim
                  WHERE rim.source_type = 'voucher_use'
                    AND rim.source_id = v.id
              )
        ) profiles
        WHERE profile_name IS NOT NULL AND profile_name <> ''
    ");
    $combinedProfilesStmt->execute([
        ':operator' => $operator,
        ':period_start' => $periodStart,
        ':period_end' => $periodEnd,
    ]);
    $summary['profiles_count'] = (int)($combinedProfilesStmt->fetchColumn() ?: $summary['profiles_count']);

    $voucherSummaryStmt = $pdo->prepare("
        SELECT
            COUNT(*) AS voucher_batches,
            COALESCE(SUM(quantity), 0) AS voucher_total
        FROM voucher_history
        WHERE operator_username = :operator
          AND created_at BETWEEN :period_start AND :period_end
          AND NOT EXISTS (
              SELECT 1
              FROM recouvrement_invoice_movements rim
              WHERE rim.source_type = 'voucher_batch'
                AND rim.source_id = voucher_history.id
          )
    ");
    $voucherSummaryStmt->execute([
        ':operator' => $operator,
        ':period_start' => $periodStart,
        ':period_end' => $periodEnd,
    ]);
    $voucherSummary = $voucherSummaryStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $summary['voucher_batches'] = (int)($voucherSummary['voucher_batches'] ?? 0);
    $summary['voucher_total'] = (int)($voucherSummary['voucher_total'] ?? 0);

    $operationSummaryStmt = $pdo->prepare("
        SELECT COUNT(*) AS commercial_operations
        FROM operation_history
        WHERE operation_scope = 'commercial'
          AND actor_username = :operator
          AND created_at BETWEEN :period_start AND :period_end
    ");
    $operationSummaryStmt->execute([
        ':operator' => $operator,
        ':period_start' => $periodStart,
        ':period_end' => $periodEnd,
    ]);
    $operationSummary = $operationSummaryStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $summary['commercial_operations'] = (int)($operationSummary['commercial_operations'] ?? 0);

    $movementsStmt = $pdo->prepare("
        SELECT
            'recharge' AS movement_type,
            id AS source_id,
            created_at,
            username AS target_name,
            COALESCE(NULLIF(profile_name, ''), '-') AS profile_name,
            effect_summary AS summary,
            amount_value,
            NULL AS quantity,
            NULL AS first_username,
            NULL AS last_username
        FROM recharge_history
        WHERE operator_username = :operator
          AND created_at BETWEEN :period_start AND :period_end
          AND NOT EXISTS (
              SELECT 1
              FROM recouvrement_invoice_movements rim
              WHERE rim.source_type = 'recharge'
                AND rim.source_id = recharge_history.id
          )

        UNION ALL

        SELECT
            'voucher_batch' AS movement_type,
            id AS source_id,
            created_at,
            '-' AS target_name,
            COALESCE(NULLIF(profile_name, ''), '-') AS profile_name,
            CONCAT('Lot vouchers ', COALESCE(NULLIF(profile_name, ''), '-')) AS summary,
            NULL AS amount_value,
            quantity,
            COALESCE(NULLIF(first_username, ''), '-') AS first_username,
            COALESCE(NULLIF(last_username, ''), '-') AS last_username
        FROM voucher_history
        WHERE operator_username = :operator
          AND created_at BETWEEN :period_start AND :period_end
          AND NOT EXISTS (
              SELECT 1
              FROM recouvrement_invoice_movements rim
              WHERE rim.source_type = 'voucher_batch'
                AND rim.source_id = voucher_history.id
          )

        UNION ALL

        SELECT
            'voucher_use' AS movement_type,
            v.id AS source_id,
            v.used_at AS created_at,
            COALESCE(NULLIF(v.username, ''), v.used_by) AS target_name,
            COALESCE(NULLIF(v.profile_name, ''), p.name, '-') AS profile_name,
            '1er login voucher' AS summary,
            COALESCE(v.price, p.price, 0) AS amount_value,
            NULL AS quantity,
            NULL AS first_username,
            NULL AS last_username
        FROM vouchers v
        LEFT JOIN profiles p ON p.id = v.profile_id
        WHERE v.printed_by = :operator
          AND v.used_at BETWEEN :period_start AND :period_end
          AND NOT EXISTS (
              SELECT 1
              FROM recouvrement_invoice_movements rim
              WHERE rim.source_type = 'voucher_use'
                AND rim.source_id = v.id
          )

        ORDER BY created_at ASC
    ");
    $movementsStmt->execute([
        ':operator' => $operator,
        ':period_start' => $periodStart,
        ':period_end' => $periodEnd,
    ]);
    $movementItems = $movementsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    if ($selectedLines !== []) {
        $lineMap = [];
        foreach ($selectedLines as $item) {
            $lineMap[mb_strtolower((string)($item['username'] ?? '') . '|' . (string)($item['profile'] ?? '-'))] = true;
        }

        $movementItems = array_values(array_filter($movementItems, static function (array $item) use ($lineMap): bool {
            if (!in_array((string)($item['movement_type'] ?? ''), ['recharge', 'voucher_use'], true)) {
                return false;
            }

            $username = trim((string)($item['target_name'] ?? ''));
            $profile = trim((string)($item['profile_name'] ?? '')) ?: '-';
            return isset($lineMap[mb_strtolower($username . '|' . $profile)]);
        }));

        $summary['total_recharges'] = count($movementItems);
        $summary['total_amount'] = array_reduce($movementItems, static function (float $sum, array $item): float {
            return $sum + (float)($item['amount_value'] ?? 0);
        }, 0.0);
        $summary['users_count'] = count(array_unique(array_map(static fn(array $item): string => (string)($item['target_name'] ?? '-'), $movementItems)));
        $summary['profiles_count'] = count(array_unique(array_map(static fn(array $item): string => (string)($item['profile_name'] ?? '-'), $movementItems)));
        $summary['voucher_batches'] = 0;
        $summary['voucher_total'] = 0;
        $summary['commercial_operations'] = count($movementItems);
    }

    return [
        'summary' => $summary,
        'movements' => $movementItems,
        'selected_lines' => $selectedLines,
    ];
}

function nextRecouvrementInvoiceNumber(PDO $pdo): string
{
    ensureRecouvrementInvoicesTable($pdo);

    $prefix = 'FAC-' . gmdate('Ym') . '-';
    $stmt = $pdo->prepare("
        SELECT invoice_number
        FROM recouvrement_invoices
        WHERE invoice_number LIKE ?
        ORDER BY id DESC
        LIMIT 1
    ");
    $stmt->execute([$prefix . '%']);
    $lastNumber = trim((string)($stmt->fetchColumn() ?: ''));

    $sequence = 1;
    if ($lastNumber !== '' && preg_match('/(\d{4})$/', $lastNumber, $matches)) {
        $sequence = ((int)$matches[1]) + 1;
    }

    return $prefix . str_pad((string)$sequence, 4, '0', STR_PAD_LEFT);
}

function createRecouvrementInvoice(
    PDO $pdo,
    string $operator,
    DateTimeImmutable $fromDate,
    DateTimeImmutable $toDate,
    array $selectedLines,
    ?string $createdBy = null
): array {
    ensureRecouvrementInvoicesTable($pdo);
    ensureRecouvrementInvoiceMovementsTable($pdo);
    recouvrementAssertOperatorAccess($pdo, $operator);

    $snapshot = calculateRecouvrementInvoiceSnapshot($pdo, $operator, $fromDate, $toDate, $selectedLines);
    $invoiceNumber = nextRecouvrementInvoiceNumber($pdo);
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("
            INSERT INTO recouvrement_invoices (
                invoice_number,
                operator_username,
                owner_admin_id,
                device_id,
                created_by_user_id,
                period_from,
                period_to,
                selected_lines_json,
                summary_json,
                movements_json,
                status,
                created_by
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?)
        ");
        $ownerAdminId = max(0, (int)($_SESSION['owner_admin_id'] ?? $_SESSION['local_admin_id'] ?? 0)) ?: null;
        $createdByUserId = max(0, (int)($_SESSION['local_admin_id'] ?? 0)) ?: null;
        $deviceId = trim((string)($_SESSION['active_device_id'] ?? '')) ?: null;
        $stmt->execute([
            $invoiceNumber,
            $operator,
            $ownerAdminId,
            $deviceId,
            $createdByUserId,
            $fromDate->format('Y-m-d'),
            $toDate->format('Y-m-d'),
            json_encode($snapshot['selected_lines'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            json_encode($snapshot['summary'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            json_encode($snapshot['movements'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            $createdBy !== null && trim($createdBy) !== '' ? trim($createdBy) : null,
        ]);

        $invoiceId = (int)$pdo->lastInsertId();
        $movementStmt = $pdo->prepare("
            INSERT INTO recouvrement_invoice_movements (
                invoice_id,
                source_type,
                source_id,
                operator_username,
                username,
                profile_name,
                created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?)
        ");

        foreach ($snapshot['movements'] as $movement) {
            $sourceType = trim((string)($movement['movement_type'] ?? ''));
            $sourceId = (int)($movement['source_id'] ?? 0);
            if ($sourceType === '' || $sourceId <= 0) {
                continue;
            }

            $movementStmt->execute([
                $invoiceId,
                $sourceType,
                $sourceId,
                $operator,
                trim((string)($movement['target_name'] ?? '')) ?: null,
                trim((string)($movement['profile_name'] ?? '')) ?: null,
                trim((string)($movement['created_at'] ?? '')) ?: null,
            ]);
        }

        $pdo->commit();
        return [
            'id' => $invoiceId,
            'invoice_number' => $invoiceNumber,
            'summary' => $snapshot['summary'],
            'movements' => $snapshot['movements'],
            'selected_lines' => $snapshot['selected_lines'],
        ];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function getRecouvrementInvoice(PDO $pdo, int $invoiceId): ?array
{
    ensureRecouvrementInvoicesTable($pdo);
    $params = [':invoice_id' => $invoiceId];
    $scopeParams = [];
    $scope = recouvrementTenantPredicate('ri', $scopeParams, 'get_invoice', 'operator_username', false);
    $params += $scopeParams;
    $stmt = $pdo->prepare("SELECT ri.* FROM recouvrement_invoices ri WHERE ri.id = :invoice_id AND {$scope} LIMIT 1");
    foreach ($params as $key => $value) $stmt->bindValue(str_starts_with((string)$key, ':') ? (string)$key : ':' . $key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
    $stmt->execute();
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function listRecouvrementInvoices(PDO $pdo, int $limit = 100): array
{
    ensureRecouvrementInvoicesTable($pdo);
    $params = [];
    $scope = recouvrementTenantPredicate('ri', $params, 'list_invoice', 'operator_username', false);
    $stmt = $pdo->prepare("SELECT ri.id, ri.invoice_number, ri.operator_username, ri.period_from, ri.period_to, ri.status, ri.created_by, ri.paid_by, ri.paid_at, ri.created_at, ri.summary_json FROM recouvrement_invoices ri WHERE {$scope} ORDER BY ri.id DESC LIMIT :limit");
    foreach ($params as $key => $value) $stmt->bindValue(':' . ltrim($key, ':'), $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
    $stmt->bindValue(':limit', max(1, min(500, $limit)), PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function markRecouvrementInvoicePaid(PDO $pdo, int $invoiceId, ?string $paidBy = null): bool
{
    ensureRecouvrementInvoicesTable($pdo);
    $params = [':invoice_id' => $invoiceId, ':paid_by' => $paidBy !== null && trim($paidBy) !== '' ? trim($paidBy) : null];
    $scopeParams = [];
    $scope = recouvrementTenantPredicate('ri', $scopeParams, 'mark_invoice', 'operator_username', false);
    $params += $scopeParams;
    $stmt = $pdo->prepare("UPDATE recouvrement_invoices ri SET ri.status = 'paid', ri.paid_by = :paid_by, ri.paid_at = NOW() WHERE ri.id = :invoice_id AND ri.status <> 'paid' AND {$scope}");
    foreach ($params as $key => $value) $stmt->bindValue(str_starts_with((string)$key, ':') ? (string)$key : ':' . $key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
    return $stmt->execute() && $stmt->rowCount() > 0;
}
