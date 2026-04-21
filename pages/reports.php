<?php
session_start();

require_once '../includes/message.php';
require_once '../config/db.php';
require_once '../includes/vouchers.php';
require_once '../includes/operation_history.php';
require_once '../includes/device_manager.php';
require_once '../includes/profile_schema.php';
require_once '../includes/admin_notifications.php';

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    set_message('Veuillez vous connecter pour accéder à cette page.', 'danger');
    header('Location: ../index.php');
    exit();
}

ensureVouchersTable($pdo);
syncVoucherUsage($pdo);
ensureOperationHistoryTable($pdo);
ensureProfilesExtendedSchema($pdo);

function notifyMissingVoucherPrice(PDO $pdo): void
{
    $stmt = $pdo->query("
        SELECT DISTINCT v.profile_id, p.name AS profile_name, p.price AS profile_price
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

notifyMissingVoucherPrice($pdo);

function reportEntriesUnionSql(): string
{
    return "
        SELECT
            CASE
                WHEN created_at IS NULL THEN NULL
                WHEN TRIM(CAST(created_at AS CHAR)) = '' THEN NULL
                WHEN CAST(created_at AS CHAR) = '0000-00-00 00:00:00' THEN NULL
                ELSE CAST(created_at AS CHAR)
            END AS created_at,
            device_id,
            username,
            profile_name,
            mode,
            operator_username,
            effect_summary,
            amount_value
        FROM recharge_history
        UNION ALL
        SELECT
            CASE
                WHEN v.used_at IS NULL THEN NULL
                WHEN TRIM(CAST(v.used_at AS CHAR)) = '' THEN NULL
                WHEN CAST(v.used_at AS CHAR) = '0000-00-00 00:00:00' THEN NULL
                ELSE CAST(v.used_at AS CHAR)
            END AS created_at,
            NULL AS device_id,
            COALESCE(NULLIF(v.username, ''), v.used_by) AS username,
            COALESCE(p.name, '') AS profile_name,
            'voucher_first_login' AS mode,
            v.printed_by AS operator_username,
            '1er login voucher' AS effect_summary,
            COALESCE(p.price, 0) AS amount_value
        FROM vouchers v
        LEFT JOIN profiles p ON p.id = v.profile_id
        WHERE v.used_at IS NOT NULL
        UNION ALL
        SELECT
            CASE
                WHEN fl.first_login IS NULL THEN NULL
                WHEN TRIM(CAST(fl.first_login AS CHAR)) = '' THEN NULL
                WHEN CAST(fl.first_login AS CHAR) = '0000-00-00 00:00:00' THEN NULL
                ELSE CAST(fl.first_login AS CHAR)
            END AS created_at,
            oh.device_id AS device_id,
            oh.target_name AS username,
            oh.profile_name AS profile_name,
            'user_create_first_login' AS mode,
            oh.actor_username AS operator_username,
            '1er login compte' AS effect_summary,
            0 AS amount_value
        FROM operation_history oh
        INNER JOIN (
            SELECT username, MIN(acctstarttime) AS first_login
            FROM radacct
            WHERE acctstarttime IS NOT NULL
            GROUP BY username
        ) fl ON fl.username = oh.target_name
        WHERE oh.operation_type = 'user_create'
        UNION ALL
        SELECT
            CASE
                WHEN created_at IS NULL THEN NULL
                WHEN TRIM(CAST(created_at AS CHAR)) = '' THEN NULL
                WHEN CAST(created_at AS CHAR) = '0000-00-00 00:00:00' THEN NULL
                ELSE CAST(created_at AS CHAR)
            END AS created_at,
            device_id,
            target_name AS username,
            profile_name,
            operation_type AS mode,
            actor_username AS operator_username,
            summary AS effect_summary,
            0 AS amount_value
        FROM operation_history
        WHERE operation_scope = 'commercial'
          AND operation_type IN ('user_remove_record', 'user_notice_record')
    ";
}

function reportCreatedAtDateSql(): string
{
    return "LEFT(created_at, 10)";
}

function reportCreatedAtMonthSql(): string
{
    return "LEFT(created_at, 7)";
}

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
        PRIMARY KEY (id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
);

$deviceStore = loadDeviceStore();
$activeDevice = getActiveDeviceRecord($deviceStore);
$activeDeviceId = (string)($activeDevice['id'] ?? '');
$selectedServerId = 'all';

$deviceLabelById = [];
foreach (($deviceStore['devices'] ?? []) as $device) {
    $deviceId = (string)($device['id'] ?? '');
    if ($deviceId === '') {
        continue;
    }

    $deviceName = trim((string)($device['name'] ?? ''));
    $deviceIp = trim((string)($device['ip'] ?? ($device['host'] ?? '')));

    if ($deviceName !== '' && $deviceIp !== '') {
        $deviceLabelById[$deviceId] = sprintf('%s (%s)', $deviceName, $deviceIp);
    } elseif ($deviceName !== '') {
        $deviceLabelById[$deviceId] = $deviceName;
    } elseif ($deviceIp !== '') {
        $deviceLabelById[$deviceId] = $deviceIp;
    } else {
        $deviceLabelById[$deviceId] = '-';
    }
}

$recentSql = "
    SELECT created_at, device_id, username, profile_name, mode, operator_username, effect_summary, amount_value
    FROM (" . reportEntriesUnionSql() . ") report_entries
    WHERE created_at IS NOT NULL
";
$recentSql .= " ORDER BY created_at DESC LIMIT 300";
$recentStmt = $pdo->prepare($recentSql);
$recentStmt->execute();

$recentItems = $recentStmt ? ($recentStmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];

if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="recharge_reports_' . gmdate('Ymd_His') . '.csv"');

    $out = fopen('php://output', 'w');
    fputcsv($out, ['Date', 'Serveur', 'Utilisateur', 'Profil', 'Mode', 'Operateur', 'Effet', 'Montant'], ';');
    foreach ($recentItems as $item) {
        $itemDeviceId = trim((string)($item['device_id'] ?? ''));
        $itemServerLabel = $itemDeviceId !== '' ? ($deviceLabelById[$itemDeviceId] ?? $itemDeviceId) : '-';
        fputcsv($out, [
            (string)($item['created_at'] ?? ''),
            (string)$itemServerLabel,
            (string)($item['username'] ?? ''),
            (string)($item['profile_name'] ?? ''),
            reportModeLabel((string)($item['mode'] ?? '')),
            (string)($item['operator_username'] ?? ''),
            (string)($item['effect_summary'] ?? ''),
            reportAmountLabel($item['amount_value'] ?? 0),
        ], ';');
    }
    fclose($out);
    exit();
}

function reportAmountLabel($value): string
{
    $amount = (float)($value ?? 0);
    return formatNumberWithThousands($amount, 2);
}

function formatNumberWithThousands(float $value, int $decimals = 2): string
{
    $formatted = number_format($value, $decimals, '.', ' ');
    if ($decimals > 0) {
        $formatted = rtrim(rtrim($formatted, '0'), '.');
    }

    return $formatted;
}

function reportModeLabel(string $mode): string
{
    return match ($mode) {
        'replace_offer' => 'Changement',
        'extend_offer' => 'Rechargement',
        'accumulate_offer' => 'Reabonnement',
        'voucher_first_login' => 'Voucher (1er login)',
        'user_create_first_login' => 'Compte (1er login)',
        'user_notice_record' => 'Expiration conservée et comptabilisée',
        'user_remove_record' => 'Suppression auto sur quota',
        default => $mode !== '' ? $mode : '-',
    };
}

function formatFrenchMonthLabel(DateTimeImmutable $date): string
{
    if (class_exists('IntlDateFormatter')) {
        $formatter = new IntlDateFormatter(
            'fr_FR',
            IntlDateFormatter::NONE,
            IntlDateFormatter::NONE,
            $date->getTimezone()->getName(),
            null,
            'MMMM yyyy'
        );

        if ($formatter !== false) {
            $formatted = $formatter->format($date);
            if ($formatted !== false) {
                return ucfirst((string)$formatted);
            }
        }
    }

    static $months = [
        1 => 'janvier',
        2 => 'fevrier',
        3 => 'mars',
        4 => 'avril',
        5 => 'mai',
        6 => 'juin',
        7 => 'juillet',
        8 => 'aout',
        9 => 'septembre',
        10 => 'octobre',
        11 => 'novembre',
        12 => 'decembre',
    ];

    $month = $months[(int)$date->format('n')] ?? $date->format('F');

    return ucfirst($month . ' ' . $date->format('Y'));
}

function buildTrendSeries(PDO $pdo): array
{
    $today = new DateTimeImmutable('today');
    $currentMonthStart = $today->modify('first day of this month');
    $currentMonthEnd = $today->modify('last day of this month');
    $previousMonthStart = $currentMonthStart->modify('-1 month');
    $previousMonthEnd = $currentMonthStart->modify('-1 day');

    $stmt = $pdo->prepare("
        SELECT " . reportCreatedAtDateSql() . " AS day_key,
               COUNT(*) AS recharge_count,
               COALESCE(SUM(amount_value), 0) AS total_amount,
               COALESCE(AVG(amount_value), 0) AS avg_amount
        FROM (" . reportEntriesUnionSql() . ") report_entries
        WHERE created_at IS NOT NULL
          AND " . reportCreatedAtDateSql() . " BETWEEN :start_prev AND :end_current
        GROUP BY " . reportCreatedAtDateSql() . "
        ORDER BY " . reportCreatedAtDateSql() . " ASC
    ");
    $stmt->execute([
        ':start_prev' => $previousMonthStart->format('Y-m-d'),
        ':end_current' => $currentMonthEnd->format('Y-m-d'),
    ]);

    $grouped = [];
    foreach (($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) as $row) {
        $grouped[(string)$row['day_key']] = [
            'count' => (int)($row['recharge_count'] ?? 0),
            'amount' => round((float)($row['total_amount'] ?? 0), 2),
            'avg' => round((float)($row['avg_amount'] ?? 0), 2),
        ];
    }

    $maxDays = max(
        (int)$previousMonthEnd->format('j'),
        (int)$currentMonthEnd->format('j')
    );

    $labels = [];
    $previousAmounts = [];
    $currentAmounts = [];
    $currentAverages = [];
    $currentCounts = [];

    for ($day = 1; $day <= $maxDays; $day++) {
        $labels[] = str_pad((string)$day, 2, '0', STR_PAD_LEFT);

        $prevKey = $previousMonthStart->setDate(
            (int)$previousMonthStart->format('Y'),
            (int)$previousMonthStart->format('m'),
            min($day, (int)$previousMonthEnd->format('j'))
        )->format('Y-m-d');
        $currKey = $currentMonthStart->setDate(
            (int)$currentMonthStart->format('Y'),
            (int)$currentMonthStart->format('m'),
            min($day, (int)$currentMonthEnd->format('j'))
        )->format('Y-m-d');

        $previousAmounts[] = $day <= (int)$previousMonthEnd->format('j') ? ($grouped[$prevKey]['amount'] ?? 0) : null;
        $currentAmounts[] = $day <= (int)$currentMonthEnd->format('j') ? ($grouped[$currKey]['amount'] ?? 0) : null;
        $currentAverages[] = $day <= (int)$today->format('j') ? ($grouped[$currKey]['avg'] ?? 0) : null;
        $currentCounts[] = $day <= (int)$today->format('j') ? ($grouped[$currKey]['count'] ?? 0) : null;
    }

    return [
        'labels' => $labels,
        'previous_month_label' => formatFrenchMonthLabel($previousMonthStart),
        'current_month_label' => formatFrenchMonthLabel($currentMonthStart),
        'previous_amounts' => $previousAmounts,
        'current_amounts' => $currentAmounts,
        'current_averages' => $currentAverages,
        'current_counts' => $currentCounts,
    ];
}

$trendSeries = buildTrendSeries($pdo);

$currentMonthStatsStmt = $pdo->query("
    SELECT
        COUNT(*) AS recharge_count,
        COALESCE(SUM(amount_value), 0) AS total_amount,
        COALESCE(AVG(amount_value), 0) AS avg_amount,
        COUNT(DISTINCT username) AS distinct_users,
        COUNT(DISTINCT profile_name) AS distinct_profiles
    FROM (" . reportEntriesUnionSql() . ") report_entries
    WHERE created_at IS NOT NULL
      AND " . reportCreatedAtMonthSql() . " = DATE_FORMAT(CURRENT_DATE(), '%Y-%m')
");
$currentMonthStats = $currentMonthStatsStmt ? ($currentMonthStatsStmt->fetch(PDO::FETCH_ASSOC) ?: []) : [];

$extraStatsStmt = $pdo->query("
    SELECT
        (SELECT profile_name
         FROM (" . reportEntriesUnionSql() . ") report_entries
         WHERE created_at IS NOT NULL
           AND " . reportCreatedAtMonthSql() . " = DATE_FORMAT(CURRENT_DATE(), '%Y-%m')
         GROUP BY profile_name
         ORDER BY COUNT(*) DESC, profile_name ASC
         LIMIT 1) AS top_profile,
        (SELECT operator_username
         FROM (" . reportEntriesUnionSql() . ") report_entries
         WHERE created_at IS NOT NULL
           AND " . reportCreatedAtMonthSql() . " = DATE_FORMAT(CURRENT_DATE(), '%Y-%m')
           AND operator_username IS NOT NULL AND operator_username <> ''
         GROUP BY operator_username
         ORDER BY COALESCE(SUM(amount_value), 0) DESC, operator_username ASC
         LIMIT 1) AS top_reseller,
        (SELECT COALESCE(SUM(amount_value), 0)
         FROM (" . reportEntriesUnionSql() . ") report_entries
         WHERE created_at IS NOT NULL
           AND " . reportCreatedAtMonthSql() . " = DATE_FORMAT(CURRENT_DATE(), '%Y-%m')
           AND operator_username IS NOT NULL AND operator_username <> ''
         GROUP BY operator_username
         ORDER BY COALESCE(SUM(amount_value), 0) DESC, operator_username ASC
         LIMIT 1) AS top_reseller_amount,
        (SELECT operator_username
         FROM (" . reportEntriesUnionSql() . ") report_entries
         WHERE created_at IS NOT NULL
           AND " . reportCreatedAtMonthSql() . " = DATE_FORMAT(CURRENT_DATE(), '%Y-%m')
           AND operator_username IS NOT NULL AND operator_username <> ''
         GROUP BY operator_username
         ORDER BY COUNT(*) DESC, operator_username ASC
         LIMIT 1) AS top_operator,
        (SELECT " . reportCreatedAtDateSql() . "
         FROM (" . reportEntriesUnionSql() . ") report_entries
         WHERE created_at IS NOT NULL
           AND " . reportCreatedAtMonthSql() . " = DATE_FORMAT(CURRENT_DATE(), '%Y-%m')
         GROUP BY " . reportCreatedAtDateSql() . "
         ORDER BY COALESCE(SUM(amount_value), 0) DESC, " . reportCreatedAtDateSql() . " ASC
         LIMIT 1) AS best_day,
        (SELECT COALESCE(AVG(daily_amount), 0)
         FROM (
            SELECT " . reportCreatedAtDateSql() . " AS day_key, COALESCE(SUM(amount_value), 0) AS daily_amount
            FROM (" . reportEntriesUnionSql() . ") report_entries
            WHERE created_at IS NOT NULL
              AND " . reportCreatedAtMonthSql() . " = DATE_FORMAT(CURRENT_DATE(), '%Y-%m')
            GROUP BY " . reportCreatedAtDateSql() . "
         ) daily_stats) AS avg_daily_amount
");
$extraStats = $extraStatsStmt ? ($extraStatsStmt->fetch(PDO::FETCH_ASSOC) ?: []) : [];

$topResellerName = trim((string)($extraStats['top_reseller'] ?? ''));
$topResellerAmount = reportAmountLabel($extraStats['top_reseller_amount'] ?? 0);
if ($topResellerName !== '') {
    $extraStats['top_reseller_label'] = sprintf('%s (%s)', $topResellerName, $topResellerAmount);
} else {
    $extraStats['top_reseller_label'] = '-';
}

$modeStatsStmt = $pdo->query("
    SELECT mode,
           COUNT(*) AS total_count
    FROM (" . reportEntriesUnionSql() . ") report_entries
    WHERE created_at IS NOT NULL
      AND " . reportCreatedAtMonthSql() . " = DATE_FORMAT(CURRENT_DATE(), '%Y-%m')
    GROUP BY mode
    ORDER BY total_count DESC
");
$modeStats = $modeStatsStmt ? ($modeStatsStmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];

$resellerStatsStmt = $pdo->query("
    SELECT operator_username,
           COALESCE(SUM(amount_value), 0) AS total_amount
    FROM (" . reportEntriesUnionSql() . ") report_entries
    WHERE created_at IS NOT NULL
      AND " . reportCreatedAtMonthSql() . " = DATE_FORMAT(CURRENT_DATE(), '%Y-%m')
      AND operator_username IS NOT NULL AND operator_username <> ''
    GROUP BY operator_username
    ORDER BY total_amount DESC, operator_username ASC
    LIMIT 4
");
$resellerStats = $resellerStatsStmt ? ($resellerStatsStmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
?>

<?php
$pageTitle = 'Rapports';
$extraHeadJs = array (
  0 => '../assets/vendor/chart/chart.umd.min.js',
);
require_once '../includes/layout_header.php';
?>

<div class="card shadow-sm mb-3 reports-tabs-card">
                <div class="card-header standard-card-header reports-tabs-header">
                    <div class="reports-tabs-title">
                        <i class="fas fa-file-lines me-2"></i>
                        <span>Rapports</span>
                    </div>
                    <ul class="nav nav-tabs reports-tabs" id="reportsTabs" role="tablist">
                        <li class="nav-item" role="presentation">
                            <button class="nav-link active" id="reports-analysis-tab" data-bs-toggle="tab" data-bs-target="#reports-analysis" type="button" role="tab" aria-controls="reports-analysis" aria-selected="true">
                                <i class="fa fa-chart-pie me-1"></i> Analyse financière
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="reports-details-tab" data-bs-toggle="tab" data-bs-target="#reports-details" type="button" role="tab" aria-controls="reports-details" aria-selected="false">
                                <i class="fa fa-table me-1"></i> Détails rapport
                            </button>
                        </li>
                    </ul>
                    <div class="d-flex align-items-center reports-export-row reports-tab-actions" id="reportsExportActions">
                        <button type="button" class="btn btn-save" id="exportReportsXlsxBtn">
                            <i class="fa fa-file-excel me-1"></i> Export XLSX
                        </button>
                        <button type="button" class="btn btn-test" id="printReportsBtn">
                            <i class="fa fa-file-pdf me-1"></i> PDF
                        </button>
                    </div>
                </div>
                <div class="card-body">
                    <div class="tab-content">
                        <div class="tab-pane fade show active" id="reports-analysis" role="tabpanel" aria-labelledby="reports-analysis-tab">
                            <div class="row g-3 align-items-stretch">
                                <div class="col-lg-8">
                                    <div class="reports-chart-card h-100">
                                        <div class="reports-chart-header">Montant journalier (mois en cours)</div>
                                        <div class="reports-chart-wrap reports-chart-wrap-lg">
                                            <canvas id="reportsAmountChart"></canvas>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-lg-4">
                                    <div class="reports-summary-panel h-100">
                                        <div class="small text-white-50 mb-2">Résumé du mois en cours</div>
                                        <div class="dashboard-summary-list reports-summary-grid">
                                            <div class="dashboard-summary-item">
                                                <span class="dashboard-summary-label">Mois</span>
                                                <span class="dashboard-summary-value"><?= htmlspecialchars($trendSeries['current_month_label']) ?></span>
                                            </div>
                                            <div class="dashboard-summary-item">
                                                <span class="dashboard-summary-label">Montant total</span>
                                                <span class="dashboard-summary-value"><?= htmlspecialchars(reportAmountLabel($currentMonthStats['total_amount'] ?? 0)) ?></span>
                                            </div>
                                            <div class="dashboard-summary-item">
                                                <span class="dashboard-summary-label">Panier moyen</span>
                                                <span class="dashboard-summary-value"><?= htmlspecialchars(reportAmountLabel($currentMonthStats['avg_amount'] ?? 0)) ?></span>
                                            </div>
                                            <div class="dashboard-summary-item">
                                                <span class="dashboard-summary-label">Recharges</span>
                                                <span class="dashboard-summary-value"><?= (int)($currentMonthStats['recharge_count'] ?? 0) ?></span>
                                            </div>
                                            <div class="dashboard-summary-item">
                                                <span class="dashboard-summary-label">Utilisateurs</span>
                                                <span class="dashboard-summary-value"><?= (int)($currentMonthStats['distinct_users'] ?? 0) ?></span>
                                            </div>
                                            <div class="dashboard-summary-item">
                                                <span class="dashboard-summary-label">Profils</span>
                                                <span class="dashboard-summary-value"><?= (int)($currentMonthStats['distinct_profiles'] ?? 0) ?></span>
                                            </div>
                                            <div class="dashboard-summary-item">
                                                <span class="dashboard-summary-label">Top revendeur</span>
                                                <span class="dashboard-summary-value"><?= htmlspecialchars((string)($extraStats['top_reseller_label'] ?? '-')) ?></span>
                                            </div>
                                            <div class="dashboard-summary-item">
                                                <span class="dashboard-summary-label">Top opérateur</span>
                                                <span class="dashboard-summary-value"><?= htmlspecialchars((string)($extraStats['top_operator'] ?? '-')) ?></span>
                                            </div>
                                            <div class="dashboard-summary-item">
                                                <span class="dashboard-summary-label">Meilleur jour</span>
                                                <span class="dashboard-summary-value"><?= htmlspecialchars((string)($extraStats['best_day'] ?? '-')) ?></span>
                                            </div>
                                            <div class="dashboard-summary-item">
                                                <span class="dashboard-summary-label">Moyenne / jour</span>
                                                <span class="dashboard-summary-value"><?= htmlspecialchars(reportAmountLabel($extraStats['avg_daily_amount'] ?? 0)) ?></span>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="row g-3 mt-1">
                                <div class="col-lg-4 col-md-6">
                                    <div class="reports-chart-card h-100">
                                        <div class="reports-chart-header">Répartition des recharges</div>
                                        <div class="reports-chart-wrap">
                                            <canvas id="reportsModeChart"></canvas>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-lg-4 col-md-6">
                                    <div class="reports-chart-card h-100">
                                        <div class="reports-chart-header">Top revendeurs (montant)</div>
                                        <div class="reports-chart-wrap">
                                            <canvas id="reportsUserChart"></canvas>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-lg-4 col-md-12">
                                    <div class="reports-chart-card h-100">
                                        <div class="reports-chart-header">Recharges par jour</div>
                                        <div class="reports-chart-wrap">
                                            <canvas id="reportsCountChart"></canvas>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="tab-pane fade reports-details-pane" id="reports-details" role="tabpanel" aria-labelledby="reports-details-tab">
                            <div class="reports-table-panel">
                                <div class="row g-2 align-items-center mb-3">
                                    <div class="col-md-2">
                                        <select class="form-select reports-filter-select" id="reportDayFilter">
                                            <option value="">Jour</option>
                                            <?php for ($day = 1; $day <= 31; $day++): ?>
                                                <option value="<?= str_pad((string)$day, 2, '0', STR_PAD_LEFT) ?>">
                                                    <?= str_pad((string)$day, 2, '0', STR_PAD_LEFT) ?>
                                                </option>
                                            <?php endfor; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-2">
                                        <select class="form-select reports-filter-select" id="reportMonthFilter">
                                            <option value="">Mois</option>
                                            <?php for ($month = 1; $month <= 12; $month++): ?>
                                                <option value="<?= str_pad((string)$month, 2, '0', STR_PAD_LEFT) ?>">
                                                    <?= str_pad((string)$month, 2, '0', STR_PAD_LEFT) ?>
                                                </option>
                                            <?php endfor; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-2">
                                        <select class="form-select reports-filter-select" id="reportYearFilter">
                                            <option value="">Année</option>
                                            <?php
                                            $yearValues = [];
                                            foreach ($recentItems as $item) {
                                                $year = substr((string)($item['created_at'] ?? ''), 0, 4);
                                                if ($year !== '' && ctype_digit($year)) {
                                                    $yearValues[$year] = true;
                                                }
                                            }
                                            krsort($yearValues);
                                            foreach (array_keys($yearValues) as $year):
                                            ?>
                                                <option value="<?= htmlspecialchars($year) ?>"><?= htmlspecialchars($year) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="input-group reports-search-group">
                                            <span class="input-group-text"><i class="fa fa-search"></i></span>
                                            <input type="text" class="form-control" id="reportSearchInput" placeholder="Rechercher une recharge...">
                                        </div>
                                    </div>
                                </div>

                                <div class="table-responsive reports-table-scroll">
                                    <table class="table table-dark table-hover table-striped mb-0 reports-table table-standard" data-sort-table="1" data-default-sort-key="date" data-default-sort-direction="desc">
                                        <thead>
                                            <tr>
                                                <th data-sort-key="date" data-sort-type="text">Date</th>
                                                <th data-sort-key="server" data-sort-type="text">Serveur</th>
                                                <th data-sort-key="username" data-sort-type="text">Utilisateur</th>
                                                <th data-sort-key="profile" data-sort-type="text">Profil</th>
                                                <th data-sort-key="mode" data-sort-type="text">Operation</th>
                                                <th data-sort-key="operator" data-sort-type="text">Opérateur</th>
                                                <th data-sort-key="effect" data-sort-type="text">Effet</th>
                                                <th data-sort-key="amount" data-sort-type="number">Montant</th>
                                            </tr>
                                        </thead>
                                        <tbody id="reportTableBody">
                                            <?php if (!$recentItems): ?>
                                                <tr data-sort-disabled="1">
                                                    <td colspan="8" class="text-center">Aucune recharge enregistrée</td>
                                                </tr>
                                            <?php else: ?>
                                                <?php foreach ($recentItems as $item): ?>
                                                    <?php
                                                    $createdAt = (string)($item['created_at'] ?? '');
                                                    $dayValue = strlen($createdAt) >= 10 ? substr($createdAt, 8, 2) : '';
                                                    $monthValue = strlen($createdAt) >= 7 ? substr($createdAt, 5, 2) : '';
                                                    $yearValue = strlen($createdAt) >= 4 ? substr($createdAt, 0, 4) : '';
                                                    $itemDeviceId = trim((string)($item['device_id'] ?? ''));
                                                    $itemServerLabel = $itemDeviceId !== '' ? ($deviceLabelById[$itemDeviceId] ?? $itemDeviceId) : '-';
                                                    ?>
                                                    <tr
                                                        data-day="<?= htmlspecialchars($dayValue) ?>"
                                                        data-month="<?= htmlspecialchars($monthValue) ?>"
                                                        data-year="<?= htmlspecialchars($yearValue) ?>"
                                                        data-server-id="<?= htmlspecialchars($itemDeviceId) ?>">
                                                        <td><?= htmlspecialchars($createdAt !== '' ? $createdAt : '-') ?></td>
                                                        <td><?= htmlspecialchars($itemServerLabel) ?></td>
                                                        <td><?= htmlspecialchars((string)($item['username'] ?? '-')) ?></td>
                                                        <td><?= htmlspecialchars((string)($item['profile_name'] ?? '-')) ?></td>
                                                        <td><?= htmlspecialchars(reportModeLabel((string)($item['mode'] ?? ''))) ?></td>
                                                        <td><?= htmlspecialchars((string)($item['operator_username'] ?? '-')) ?></td>
                                                        <td><?= htmlspecialchars((string)($item['effect_summary'] ?? '-')) ?></td>
                                                        <td><?= htmlspecialchars(reportAmountLabel($item['amount_value'] ?? 0)) ?></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

        </div>
    </div>
</div>


<?php
$extraJs = array (
  0 => '../js/table_sort.js',
  1 => 'https://cdn.jsdelivr.net/npm/xlsx@0.19.3/dist/xlsx.full.min.js',
);
require_once '../includes/layout_footer.php';
?>
