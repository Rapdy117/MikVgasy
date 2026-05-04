<?php

require_once __DIR__ . '/vouchers.php';
require_once __DIR__ . '/operation_history.php';
require_once __DIR__ . '/profile_schema.php';
require_once __DIR__ . '/admin_notifications.php';

function ensureRecouvrementInvoicesTable(PDO $pdo): void
{
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS recouvrement_invoices (
            id INT(11) NOT NULL AUTO_INCREMENT,
            invoice_number VARCHAR(50) NOT NULL,
            operator_username VARCHAR(100) NOT NULL,
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
            KEY idx_recouvrement_invoice_status (status),
            KEY idx_recouvrement_invoice_created_at (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
    );
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

function calculateRecouvrementInvoiceSnapshot(
    PDO $pdo,
    string $operator,
    DateTimeImmutable $fromDate,
    DateTimeImmutable $toDate,
    array $selectedLines = []
): array {
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

    $snapshot = calculateRecouvrementInvoiceSnapshot($pdo, $operator, $fromDate, $toDate, $selectedLines);
    $invoiceNumber = nextRecouvrementInvoiceNumber($pdo);
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("
            INSERT INTO recouvrement_invoices (
                invoice_number,
                operator_username,
                period_from,
                period_to,
                selected_lines_json,
                summary_json,
                movements_json,
                status,
                created_by
            ) VALUES (?, ?, ?, ?, ?, ?, ?, 'pending', ?)
        ");
        $stmt->execute([
            $invoiceNumber,
            $operator,
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

    $stmt = $pdo->prepare("
        SELECT *
        FROM recouvrement_invoices
        WHERE id = ?
        LIMIT 1
    ");
    $stmt->execute([$invoiceId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ?: null;
}

function listRecouvrementInvoices(PDO $pdo, int $limit = 100): array
{
    ensureRecouvrementInvoicesTable($pdo);

    $stmt = $pdo->prepare("
        SELECT id, invoice_number, operator_username, period_from, period_to, status, created_by, paid_by, paid_at, created_at, summary_json
        FROM recouvrement_invoices
        ORDER BY id DESC
        LIMIT ?
    ");
    $stmt->bindValue(1, max(1, min(500, $limit)), PDO::PARAM_INT);
    $stmt->execute();

    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function markRecouvrementInvoicePaid(PDO $pdo, int $invoiceId, ?string $paidBy = null): bool
{
    ensureRecouvrementInvoicesTable($pdo);

    $stmt = $pdo->prepare("
        UPDATE recouvrement_invoices
        SET status = 'paid',
            paid_by = ?,
            paid_at = NOW()
        WHERE id = ?
          AND status <> 'paid'
    ");

    return $stmt->execute([
        $paidBy !== null && trim($paidBy) !== '' ? trim($paidBy) : null,
        $invoiceId,
    ]) && $stmt->rowCount() > 0;
}
