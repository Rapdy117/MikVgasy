<?php
session_start();

require_once '../includes/message.php';
require_once '../includes/auth.php';
require_once '../config/db.php';
require_once '../includes/vouchers.php';
require_once '../includes/operation_history.php';
require_once '../includes/local_admins.php';
require_once '../includes/recouvrement_invoices.php';
require_once '../includes/profile_schema.php';
require_once '../includes/admin_notifications.php';

requireAdministratorAccess('La page recouvrement est réservée à l administrateur.');

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$summary = [
    'total_recharges' => 0,
    'total_voucher_batches' => 0,
    'total_vouchers' => 0,
    'montant_total' => 0.0,
    'operateurs' => 0,
    'profils' => 0,
    'commercial_operations' => 0,
];

$selectedDay = trim((string)($_GET['day'] ?? ''));
$selectedMonthRaw = array_key_exists('month', $_GET) ? trim((string)$_GET['month']) : (string)(int)gmdate('m');
$selectedYearRaw = array_key_exists('year', $_GET) ? trim((string)$_GET['year']) : (string)(int)gmdate('Y');
$selectedMonth = ctype_digit($selectedMonthRaw) ? (int)$selectedMonthRaw : '';
$selectedYear = ctype_digit($selectedYearRaw) ? (int)$selectedYearRaw : '';
$selectedReseller = trim((string)($_GET['reseller'] ?? ''));
$userSummaryItems = [];
$resellerOptions = [];
$invoiceDateFrom = gmdate('Y-m-01');
$invoiceDateTo = gmdate('Y-m-d');
$recouvrementClientRows = [];
$recouvrementClientVouchers = [];
$recouvrementClientOperations = [];
$monthNames = [
    1 => 'Janvier',
    2 => 'Fevrier',
    3 => 'Mars',
    4 => 'Avril',
    5 => 'Mai',
    6 => 'Juin',
    7 => 'Juillet',
    8 => 'Aout',
    9 => 'Septembre',
    10 => 'Octobre',
    11 => 'Novembre',
    12 => 'Decembre',
];

function recouvrementDateFilterSql(string $column, int|string $year, int|string $month, string $day): string
{
    $conditions = [];
    if (is_int($year)) {
        $conditions[] = 'YEAR(' . $column . ') = ' . $year;
    }
    if (is_int($month)) {
        $conditions[] = 'MONTH(' . $column . ') = ' . $month;
    }
    if (ctype_digit($day)) {
        $conditions[] = 'DAY(' . $column . ') = ' . (int)$day;
    }

    return $conditions === [] ? '' : ' AND ' . implode(' AND ', $conditions);
}

function recouvrementOperatorFilterSql(PDO $pdo, string $column, string $operator): string
{
    $value = trim($operator);
    return $value === '' ? '' : ' AND ' . $column . ' = ' . $pdo->quote($value);
}

try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS recharge_history (
            id INT(11) NOT NULL AUTO_INCREMENT,
            username VARCHAR(100) NOT NULL,
            profile_name VARCHAR(100) DEFAULT NULL,
            mode VARCHAR(50) NOT NULL,
            operator_username VARCHAR(100) DEFAULT NULL,
            effect_summary TEXT DEFAULT NULL,
            amount_value DECIMAL(10,2) DEFAULT 0.00,
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");

    ensureVouchersTable($pdo);
    runVoucherUsageMaintenanceIfDue($pdo, 'recouvrement_voucher_usage');
    ensureVoucherHistoryTable($pdo);
    ensureOperationHistoryTable($pdo);
    ensureLocalAdminTable($pdo);
    ensureRecouvrementInvoiceMovementsTable($pdo);
    ensureProfilesExtendedSchema($pdo);
    notifyMissingVoucherPrice($pdo);

    $stmt = $pdo->query("
        SELECT
            COUNT(*) AS total_recharges,
            COALESCE(SUM(amount_value), 0) AS montant_total,
            COUNT(DISTINCT operator_username) AS operateurs,
            COUNT(DISTINCT profile_name) AS profils
        FROM recharge_history
    ");
    $summaryRow = $stmt ? ($stmt->fetch(PDO::FETCH_ASSOC) ?: []) : [];
    $summary['total_recharges'] = (int)($summaryRow['total_recharges'] ?? 0);
    $summary['montant_total'] = (float)($summaryRow['montant_total'] ?? 0);
    $summary['operateurs'] = (int)($summaryRow['operateurs'] ?? 0);
    $summary['profils'] = (int)($summaryRow['profils'] ?? 0);

    $voucherSummaryStmt = $pdo->query("
        SELECT
            COUNT(*) AS total_voucher_batches,
            COALESCE(SUM(quantity), 0) AS total_vouchers,
            COUNT(DISTINCT operator_username) AS voucher_operateurs,
            COUNT(DISTINCT profile_name) AS voucher_profils
        FROM voucher_history
    ");
    $voucherSummary = $voucherSummaryStmt ? ($voucherSummaryStmt->fetch(PDO::FETCH_ASSOC) ?: []) : [];
    $summary['total_voucher_batches'] = (int)($voucherSummary['total_voucher_batches'] ?? 0);
    $summary['total_vouchers'] = (int)($voucherSummary['total_vouchers'] ?? 0);
    $summary['operateurs'] = max($summary['operateurs'], (int)($voucherSummary['voucher_operateurs'] ?? 0));
    $summary['profils'] = max($summary['profils'], (int)($voucherSummary['voucher_profils'] ?? 0));

    $operationSummaryStmt = $pdo->query("
        SELECT SUM(CASE WHEN operation_scope = 'commercial' THEN 1 ELSE 0 END) AS commercial_operations
        FROM operation_history
    ");
    $operationSummary = $operationSummaryStmt ? ($operationSummaryStmt->fetch(PDO::FETCH_ASSOC) ?: []) : [];
    $summary['commercial_operations'] = (int)($operationSummary['commercial_operations'] ?? 0);

    $rechargeRowsStmt = $pdo->query("
        SELECT id, username, profile_name, operator_username, effect_summary, amount_value, created_at
        FROM recharge_history
        WHERE NOT EXISTS (
            SELECT 1
            FROM recouvrement_invoice_movements rim
            WHERE rim.source_type = 'recharge'
              AND rim.source_id = recharge_history.id
        )
        " . recouvrementDateFilterSql('created_at', $selectedYear, $selectedMonth, $selectedDay) . "
        " . recouvrementOperatorFilterSql($pdo, 'operator_username', $selectedReseller) . "
        ORDER BY created_at DESC, id DESC
        LIMIT 500
    ");
    $rechargeRows = $rechargeRowsStmt ? ($rechargeRowsStmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];

    $summaryMap = [];
    foreach ($rechargeRows as $row) {
        $operator = trim((string)($row['operator_username'] ?? ''));
        $username = trim((string)($row['username'] ?? ''));

        if ($operator !== '') {
            $resellerOptions[$operator] = true;
        }

        if ($username === '') {
            continue;
        }

        $key = mb_strtolower($operator . '|' . $username);
        if (!isset($summaryMap[$key])) {
            $rowKey = 'row_' . md5($key);
            $summaryMap[$key] = [
                'row_key' => $rowKey,
                'operator_username' => $operator !== '' ? $operator : '-',
                'username' => $username,
                'profile_name' => trim((string)($row['profile_name'] ?? '')) ?: '-',
                'last_summary' => trim((string)($row['effect_summary'] ?? '')) ?: '-',
                'last_created_at' => trim((string)($row['created_at'] ?? '')) ?: '-',
                'recharge_count' => 0,
                'amount_total' => 0.0,
            ];
            $recouvrementClientRows[$rowKey] = [
                'operator' => $operator !== '' ? $operator : '-',
                'username' => $username,
                'profile' => trim((string)($row['profile_name'] ?? '')) ?: '-',
                'entries' => [],
            ];
        }

        $summaryMap[$key]['recharge_count'] += 1;
        $summaryMap[$key]['amount_total'] += (float)($row['amount_value'] ?? 0);
        $recouvrementClientRows[$summaryMap[$key]['row_key']]['entries'][] = [
            'entry_id' => 'recharge_' . (int)($row['id'] ?? 0),
            'entry_type' => 'recharge',
            'source_type' => 'recharge',
            'created_at' => trim((string)($row['created_at'] ?? '')),
            'amount_value' => (float)($row['amount_value'] ?? 0),
            'summary' => trim((string)($row['effect_summary'] ?? '')) ?: '-',
        ];
    }

    $voucherUseStmt = $pdo->query("
        SELECT
            v.id,
            v.used_at,
            v.used_by,
            v.username,
            v.profile_id,
            v.profile_name AS voucher_profile_name,
            v.price AS voucher_price,
            v.printed_by
        FROM vouchers v
        WHERE v.used_at IS NOT NULL
          AND NOT EXISTS (
              SELECT 1
              FROM recouvrement_invoice_movements rim
              WHERE rim.source_type = 'voucher_use'
                AND rim.source_id = v.id
          )
        " . recouvrementDateFilterSql('v.used_at', $selectedYear, $selectedMonth, $selectedDay) . "
        " . recouvrementOperatorFilterSql($pdo, 'v.printed_by', $selectedReseller) . "
        ORDER BY v.used_at DESC, v.id DESC
        LIMIT 500
    ");
    $voucherUseRows = $voucherUseStmt ? ($voucherUseStmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
    foreach ($voucherUseRows as $row) {
        $operator = trim((string)($row['printed_by'] ?? ''));
        $username = trim((string)($row['username'] ?? ''));
        if ($username === '') {
            $username = trim((string)($row['used_by'] ?? ''));
        }
        $createdAt = trim((string)($row['used_at'] ?? ''));
        if ($operator === '' || $username === '' || $createdAt === '') {
            continue;
        }

        $profile = trim((string)($row['voucher_profile_name'] ?? '')) ?: '-';
        $amountValue = (float)($row['voucher_price'] ?? 0);
        $summaryText = '1er login voucher';
        $key = mb_strtolower($operator . '|' . $username);

        if (!isset($summaryMap[$key])) {
            $rowKey = 'row_' . md5($key);
            $summaryMap[$key] = [
                'row_key' => $rowKey,
                'operator_username' => $operator,
                'username' => $username,
                'profile_name' => $profile,
                'last_summary' => $summaryText,
                'last_created_at' => $createdAt,
                'recharge_count' => 0,
                'amount_total' => 0.0,
            ];
            $recouvrementClientRows[$rowKey] = [
                'operator' => $operator,
                'username' => $username,
                'profile' => $profile,
                'entries' => [],
            ];
        }

        $summaryMap[$key]['recharge_count'] += 1;
        $summaryMap[$key]['amount_total'] += $amountValue;
        if ($createdAt > (string)($summaryMap[$key]['last_created_at'] ?? '')) {
            $summaryMap[$key]['last_created_at'] = $createdAt;
            $summaryMap[$key]['last_summary'] = $summaryText;
            $summaryMap[$key]['profile_name'] = $profile;
        }

        $recouvrementClientRows[$summaryMap[$key]['row_key']]['entries'][] = [
            'entry_id' => 'voucher_use_' . (int)($row['id'] ?? 0),
            'entry_type' => 'recharge',
            'source_type' => 'voucher_use',
            'created_at' => $createdAt,
            'amount_value' => $amountValue,
            'summary' => $summaryText,
        ];
    }

    $voucherHistoryStmt = $pdo->query("
        SELECT id, operator_username, profile_name, quantity, created_at
        FROM voucher_history
        WHERE NOT EXISTS (
            SELECT 1
            FROM recouvrement_invoice_movements rim
            WHERE rim.source_type = 'voucher_batch'
              AND rim.source_id = voucher_history.id
        )
        " . recouvrementDateFilterSql('created_at', $selectedYear, $selectedMonth, $selectedDay) . "
        " . recouvrementOperatorFilterSql($pdo, 'operator_username', $selectedReseller) . "
        ORDER BY created_at DESC, id DESC
        LIMIT 500
    ");
    $voucherHistoryRows = $voucherHistoryStmt ? ($voucherHistoryStmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
    foreach ($voucherHistoryRows as $row) {
        $operator = trim((string)($row['operator_username'] ?? ''));
        if ($operator === '') {
            continue;
        }
        $resellerOptions[$operator] = true;
        $recouvrementClientVouchers[] = [
            'operator' => $operator,
            'profile' => trim((string)($row['profile_name'] ?? '')) ?: '-',
            'quantity' => (int)($row['quantity'] ?? 0),
            'created_at' => trim((string)($row['created_at'] ?? '')),
        ];
    }

    $operationRowsStmt = $pdo->query("
        SELECT actor_username, target_name, profile_name, amount_value, summary, operation_type, created_at
        FROM operation_history
        WHERE operation_scope = 'commercial'
          AND COALESCE(operation_type, '') NOT IN (
              'voucher_batch',
              'recharge',
              'user_notice_record',
              'user_remove_record'
          )
        " . recouvrementDateFilterSql('created_at', $selectedYear, $selectedMonth, $selectedDay) . "
        " . recouvrementOperatorFilterSql($pdo, 'actor_username', $selectedReseller) . "
        ORDER BY created_at DESC, id DESC
        LIMIT 500
    ");
    $operationRows = $operationRowsStmt ? ($operationRowsStmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
    foreach ($operationRows as $row) {
        $operator = trim((string)($row['actor_username'] ?? ''));
        if ($operator === '') {
            continue;
        }
        $resellerOptions[$operator] = true;
        $target = trim((string)($row['target_name'] ?? ''));
        $profile = trim((string)($row['profile_name'] ?? '')) ?: '-';
        $createdAt = trim((string)($row['created_at'] ?? ''));
        $summaryText = trim((string)($row['summary'] ?? '')) ?: operationTypeLabel((string)($row['operation_type'] ?? ''));
        $amountValue = (float)($row['amount_value'] ?? 0);

        $recouvrementClientOperations[] = [
            'operator' => $operator,
            'username' => $target !== '' ? $target : '-',
            'profile' => $profile,
            'summary' => $summaryText,
            'amount_value' => $amountValue,
            'created_at' => $createdAt,
        ];

        if ($target !== '') {
            $key = mb_strtolower($operator . '|' . $target);
            if (!isset($summaryMap[$key])) {
                $rowKey = 'row_' . md5($key);
                $summaryMap[$key] = [
                    'row_key' => $rowKey,
                    'operator_username' => $operator,
                    'username' => $target,
                    'profile_name' => $profile,
                    'last_summary' => $summaryText,
                    'last_created_at' => $createdAt !== '' ? $createdAt : '-',
                    'recharge_count' => 0,
                    'amount_total' => 0.0,
                ];
                $recouvrementClientRows[$rowKey] = [
                    'operator' => $operator,
                    'username' => $target,
                    'profile' => $profile,
                    'entries' => [],
                ];
            }

            $summaryMap[$key]['recharge_count'] += 1;
            $summaryMap[$key]['amount_total'] += $amountValue;
            if ($createdAt !== '' && $createdAt > (string)($summaryMap[$key]['last_created_at'] ?? '')) {
                $summaryMap[$key]['last_created_at'] = $createdAt;
                $summaryMap[$key]['last_summary'] = $summaryText;
                $summaryMap[$key]['profile_name'] = $profile;
            }

            $recouvrementClientRows[$summaryMap[$key]['row_key']]['entries'][] = [
                'entry_id' => 'operation_' . md5($operator . '|' . $target . '|' . $createdAt . '|' . $summaryText),
                'entry_type' => 'operation',
                'source_type' => 'operation',
                'created_at' => $createdAt,
                'amount_value' => $amountValue,
                'summary' => $summaryText,
            ];
            continue;
        }
    }

    $accountFirstLoginStmt = $pdo->query("
        SELECT
            oh.id,
            oh.actor_username,
            oh.target_name,
            oh.profile_name,
            fl.first_login
        FROM operation_history oh
        INNER JOIN (
            SELECT username, MIN(acctstarttime) AS first_login
            FROM radacct
            WHERE acctstarttime IS NOT NULL AND acctstarttime <> ''
            GROUP BY username
        ) fl ON fl.username = oh.target_name
        WHERE oh.operation_type = 'user_create'
        " . recouvrementDateFilterSql('fl.first_login', $selectedYear, $selectedMonth, $selectedDay) . "
        " . recouvrementOperatorFilterSql($pdo, 'oh.actor_username', $selectedReseller) . "
        ORDER BY fl.first_login DESC, oh.id DESC
        LIMIT 500
    ");
    $accountFirstLoginRows = $accountFirstLoginStmt ? ($accountFirstLoginStmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
    foreach ($accountFirstLoginRows as $row) {
        $operator = trim((string)($row['actor_username'] ?? ''));
        $target = trim((string)($row['target_name'] ?? ''));
        $createdAt = trim((string)($row['first_login'] ?? ''));
        if ($operator === '' || $target === '' || $createdAt === '') {
            continue;
        }

        $profile = trim((string)($row['profile_name'] ?? '')) ?: '-';
        $summaryText = '1er login compte';

        $recouvrementClientOperations[] = [
            'operator' => $operator,
            'username' => $target,
            'profile' => $profile,
            'summary' => $summaryText,
            'amount_value' => 0,
            'created_at' => $createdAt,
        ];

        $key = mb_strtolower($operator . '|' . $target);
        if (!isset($summaryMap[$key])) {
            $rowKey = 'row_' . md5($key);
            $summaryMap[$key] = [
                'row_key' => $rowKey,
                'operator_username' => $operator,
                'username' => $target,
                'profile_name' => $profile,
                'last_summary' => $summaryText,
                'last_created_at' => $createdAt,
                'recharge_count' => 0,
                'amount_total' => 0.0,
            ];
            $recouvrementClientRows[$rowKey] = [
                'operator' => $operator,
                'username' => $target,
                'profile' => $profile,
                'entries' => [],
            ];
        }

        if ($createdAt > (string)($summaryMap[$key]['last_created_at'] ?? '')) {
            $summaryMap[$key]['last_created_at'] = $createdAt;
            $summaryMap[$key]['last_summary'] = $summaryText;
            $summaryMap[$key]['profile_name'] = $profile;
        }

        $recouvrementClientRows[$summaryMap[$key]['row_key']]['entries'][] = [
            'entry_id' => 'user_first_login_' . (int)($row['id'] ?? 0),
            'entry_type' => 'operation',
            'source_type' => 'user_first_login',
            'created_at' => $createdAt,
            'amount_value' => 0,
            'summary' => $summaryText,
        ];
    }

    $userSummaryItems = array_values($summaryMap);
    usort($userSummaryItems, static function (array $a, array $b): int {
        return strcmp((string)($b['last_created_at'] ?? ''), (string)($a['last_created_at'] ?? ''));
    });

    $adminRows = listLocalAdmins($pdo);
    $resellerOptions = array_values(array_filter($adminRows, static function (array $admin): bool {
        return (int)($admin['is_active'] ?? 0) === 1;
    }));
} catch (Throwable $e) {
    $userSummaryItems = [];
    $resellerOptions = [];
}
?>

<?php
$pageTitle = 'Recouvrement';
$htmlClass = 'recouvrement-page';
$bodyClass = 'recouvrement-page';
$extraCss = [
    '../css/recouvrement.css',
];
require_once '../includes/layout_header.php';
?>

<div class="row recouvrement-page-layout-row g-3">
    <div class="col-lg-9 mb-3 mb-lg-0 recouvrement-main-col">
        <div class="card shadow-sm administration-card recouvrement-main-card">
            <div class="card-header">
                <form method="GET" class="mb-0" autocomplete="off">
                    <div class="row g-2 align-items-end">
                        <div class="col-md-2">
                            <label class="form-label text-white-50 small mb-1">Jour</label>
                            <select class="form-select user-logs-filter-select" id="recouvrementFilterDay" name="day">
                                <option value="">Tous</option>
                                <?php for ($i = 1; $i <= 31; $i++): ?>
                                <option value="<?= $i ?>" <?= $selectedDay === (string)$i ? 'selected' : '' ?>><?= str_pad((string)$i, 2, '0', STR_PAD_LEFT) ?></option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label text-white-50 small mb-1">Mois</label>
                            <select class="form-select user-logs-filter-select" id="recouvrementFilterMonth" name="month">
                                <option value="" <?= $selectedMonth === '' ? 'selected' : '' ?>>Tous les mois</option>
                                <?php foreach ($monthNames as $number => $label): ?>
                                <option value="<?= $number ?>" <?= $selectedMonth === $number ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label text-white-50 small mb-1">Annee</label>
                            <select class="form-select user-logs-filter-select" id="recouvrementFilterYear" name="year">
                                <option value="" <?= $selectedYear === '' ? 'selected' : '' ?>>Toutes</option>
                                <?php for ($y = 2018; $y <= (int)gmdate('Y'); $y++): ?>
                                <option value="<?= $y ?>" <?= $selectedYear === $y ? 'selected' : '' ?>><?= $y ?></option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label text-white-50 small mb-1">Revendeur</label>
                            <select class="form-select user-logs-filter-select" id="recouvrementFilterReseller" name="reseller">
                                <option value="">Tous</option>
                                <?php foreach ($resellerOptions as $reseller): ?>
                                <option value="<?= htmlspecialchars((string)($reseller['username'] ?? '')) ?>" <?= $selectedReseller === (string)($reseller['username'] ?? '') ? 'selected' : '' ?>>
                                    <?= htmlspecialchars((string)($reseller['username'] ?? '')) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2 text-md-end">
                            <a href="recouvrement.php?day=&month=&year=&reseller=" class="btn btn-save">
                                <i class="fa fa-sync me-1"></i> Tout
                            </a>
                        </div>
                    </div>
                </form>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive recouvrement-table-scroll">
                    <table class="table table-dark table-hover table-striped mb-0 administration-table table-standard" data-sort-table="1" data-default-sort-key="date" data-default-sort-direction="desc">
                        <thead>
                            <tr>
                                <th class="recouvrement-select-col">
                                    <input type="checkbox" class="form-check-input mt-0" id="selectAllRecouvrementRows" aria-label="Tout sélectionner">
                                </th>
                                <th data-sort-key="operator" data-sort-type="text">Revendeur</th>
                                <th data-sort-key="username" data-sort-type="text">Utilisateur</th>
                                <th data-sort-key="profile" data-sort-type="text">Profil</th>
                                <th data-sort-key="recharges" data-sort-type="number">Qté</th>
                                <th data-sort-key="summary" data-sort-type="text">Dernière opération</th>
                                <th data-sort-key="date" data-sort-type="date">Date</th>
                                <th data-sort-key="amount" data-sort-type="currency">Montant</th>
                            </tr>
                        </thead>
                        <tbody id="recouvrementHistoryBody">
                            <?php if (!$userSummaryItems): ?>
                                <tr><td colspan="8" class="text-center">Aucune recharge disponible</td></tr>
                            <?php else: ?>
                                <?php foreach ($userSummaryItems as $item): ?>
                                    <?php
                                        $rowKey = (string)($item['row_key'] ?? '');
                                        $operator = trim((string)($item['operator_username'] ?? '')) ?: '-';
                                        $target = trim((string)($item['username'] ?? '')) ?: '-';
                                        $profile = trim((string)($item['profile_name'] ?? '')) ?: '-';
                                        $summaryText = trim((string)($item['last_summary'] ?? '')) ?: '-';
                                        $countLabel = (int)($item['recharge_count'] ?? 0);
                                        $valueLabel = number_format((float)($item['amount_total'] ?? 0), 2, ',', ' ');
                                        $createdAt = trim((string)($item['last_created_at'] ?? '')) ?: '-';
                                        $createdAtDate = $createdAt !== '-' ? substr($createdAt, 0, 10) : '';
                                        $rowSearch = mb_strtolower(implode(' ', [
                                            $operator,
                                            $target,
                                            $profile,
                                            $summaryText,
                                            $valueLabel,
                                        ]));
                                    ?>
                                    <tr
                                        data-row-key="<?= htmlspecialchars($rowKey) ?>"
                                        data-recouvrement-id="<?= htmlspecialchars($rowKey) ?>"
                                        data-reseller="<?= htmlspecialchars(mb_strtolower($operator)) ?>"
                                        data-user="<?= htmlspecialchars(mb_strtolower($target)) ?>"
                                        data-search="<?= htmlspecialchars($rowSearch) ?>"
                                        data-operator="<?= htmlspecialchars($operator) ?>"
                                        data-username="<?= htmlspecialchars($target) ?>"
                                        data-profile="<?= htmlspecialchars($profile) ?>"
                                        data-recharges="<?= htmlspecialchars((string)$countLabel) ?>"
                                        data-amount="<?= htmlspecialchars($valueLabel) ?>"
                                        data-summary="<?= htmlspecialchars($summaryText) ?>"
                                        data-date="<?= htmlspecialchars($createdAt) ?>"
                                        data-date-iso="<?= htmlspecialchars($createdAtDate) ?>"
                                    >
                                        <td class="recouvrement-select-col">
                                            <input type="checkbox" class="form-check-input recouvrement-select" value="<?= htmlspecialchars($rowKey) ?>" aria-label="Sélectionner cette ligne">
                                        </td>
                                        <td><?= htmlspecialchars($operator) ?></td>
                                        <td><?= htmlspecialchars($target) ?></td>
                                        <td><?= htmlspecialchars($profile) ?></td>
                                        <td><?= $countLabel ?></td>
                                        <td><?= htmlspecialchars($summaryText) ?></td>
                                        <td><?= htmlspecialchars($createdAtDate !== '' ? $createdAtDate : $createdAt) ?></td>
                                        <td><?= htmlspecialchars($valueLabel) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <div class="col-lg-3 mb-3 mb-lg-0 recouvrement-side-col">
        <div class="card shadow-sm administration-card">
            <div class="card-header">
                <i class="fa fa-chart-column me-2"></i> Résumé sélection
            </div>
            <div class="card-body">
                <div class="network-device-form mb-0">
                    <div class="input-group mb-3">
                        <span class="input-group-text">Revendeur</span>
                        <input type="text" class="form-control" id="recouvrementDetailOperator" value="-" readonly>
                    </div>
                    <div class="recouvrement-summary-grid">
                        <div class="input-group mb-0">
                            <span class="input-group-text">Lignes payantes</span>
                            <input type="text" class="form-control" id="recouvrementMetricRecharges" value="0" readonly>
                        </div>
                        <div class="input-group mb-0">
                            <span class="input-group-text">Vouchers utilisés</span>
                            <input type="text" class="form-control" id="recouvrementMetricVoucherBatches" value="0" readonly>
                        </div>
                        <div class="input-group mb-0">
                            <span class="input-group-text">Recharges compte</span>
                            <input type="text" class="form-control" id="recouvrementMetricVouchers" value="0" readonly>
                        </div>
                        <div class="input-group mb-0">
                            <span class="input-group-text">Montant lignes</span>
                            <input type="text" class="form-control" id="recouvrementMetricAmount" value="0,00" readonly>
                        </div>
                        <div class="input-group mb-0">
                            <span class="input-group-text">Revendeurs</span>
                            <input type="text" class="form-control" id="recouvrementMetricOperators" value="0" readonly>
                        </div>
                        <div class="input-group mb-0">
                            <span class="input-group-text">Profils</span>
                            <input type="text" class="form-control" id="recouvrementMetricProfiles" value="0" readonly>
                        </div>
                        <div class="input-group mb-0">
                            <span class="input-group-text">Autres opérations</span>
                            <input type="text" class="form-control" id="recouvrementMetricCommercialOperations" value="0" readonly>
                        </div>
                        <div class="input-group mb-0">
                            <span class="input-group-text">Utilisateurs</span>
                            <input type="text" class="form-control" id="recouvrementMetricUsers" value="0" readonly>
                        </div>
                    </div>
                </div>
                <div class="recouvrement-detail-actions">
                    <a
                        href="#"
                        class="btn btn-save disabled"
                        id="recouvrementInvoiceLink"
                        target="_blank"
                        aria-disabled="true"
                    >
                        <i class="fa fa-print me-2"></i> Sortir la facture du revendeur
                    </a>
                    <a href="/pages/recouvrement_invoices.php" class="btn btn-test ms-2">
                        <i class="fa fa-file-invoice-dollar me-2"></i> Suivi facture
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>

</div>
</div>
</div>
<script>
window.recouvrementData = <?= json_encode([
    'rows' => $recouvrementClientRows,
    'vouchers' => $recouvrementClientVouchers,
    'operations' => $recouvrementClientOperations,
    'csrfToken' => (string)($_SESSION['csrf_token'] ?? ''),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
</script>

<?php
$extraJs = array (
  0 => '../js/table_sort.js',
  1 => '../js/recouvrement.js?v=20260328a',
);
require_once '../includes/layout_footer.php';
?>
