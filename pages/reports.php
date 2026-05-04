<?php
session_start();

require_once '../includes/message.php';
require_once '../config/db.php';
require_once '../includes/vouchers.php';
require_once '../includes/operation_history.php';
require_once '../includes/device_manager.php';
require_once '../includes/profile_schema.php';
require_once '../includes/admin_notifications.php';
require_once '../includes/commercial_report_source.php';

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    set_message('Veuillez vous connecter pour accéder à cette page.', 'danger');
    header('Location: ../index.php');
    exit();
}

ensureVouchersTable($pdo);
runVoucherUsageMaintenanceIfDue($pdo, 'reports_voucher_usage');
runVoucherCommercialSnapshotMaintenanceIfDue($pdo, 'reports_voucher_snapshots');
ensureOperationHistoryTable($pdo);
ensureProfilesExtendedSchema($pdo);

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

notifyMissingVoucherPrice($pdo);

function reportEntriesUnionSql(): string
{
    return commercialReportEntriesUnionSql();
}

function reportCreatedAtDateSql(): string
{
    return commercialReportCreatedAtDateSql();
}

function reportCreatedAtMonthSql(): string
{
    return commercialReportCreatedAtMonthSql();
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
    SELECT created_at, device_id, username, profile_name, profile_type, mode, operator_username, effect_summary, amount_value
    FROM (" . reportEntriesUnionSql() . ") report_entries
    WHERE created_at IS NOT NULL
";
$recentSql .= " ORDER BY created_at DESC LIMIT 300";
$recentStmt = $pdo->prepare($recentSql);
$recentStmt->execute();

$recentItems = $recentStmt ? ($recentStmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];

$profileTypeValues = [];
$operationValues = [];
foreach ($recentItems as $item) {
    $profileType = trim((string)($item['profile_type'] ?? ''));
    if ($profileType !== '') {
        $profileTypeValues[$profileType] = true;
    }

    $mode = trim((string)($item['mode'] ?? ''));
    if ($mode !== '') {
        $operationValues[$mode] = reportModeLabel($mode);
    }
}
ksort($profileTypeValues);
asort($operationValues);

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
$htmlClass = 'reports-page';
$bodyClass = 'reports-page';
$extraCss = array (
  0 => '../css/reports.css',
);
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
                            <i class="fa fa-file-pdf me-1"></i> Export PDF
                        </button>
                    </div>
                </div>
                <div class="card-body">
                    <div class="tab-content">
                        <div class="tab-pane fade show active" id="reports-analysis" role="tabpanel" aria-labelledby="reports-analysis-tab">
                            <div class="row g-3 align-items-stretch">
                                <div class="col-lg-8">
                                    <div class="reports-chart-card h-100">
                                        <div class="reports-chart-header">
                                            <span class="reports-chart-icon reports-chart-icon-amount"><i class="fa fa-chart-line"></i></span>
                                            <span>Montant journalier (mois en cours)</span>
                                        </div>
                                        <div class="reports-chart-wrap reports-chart-wrap-lg">
                                            <canvas id="reportsAmountChart"></canvas>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-lg-4">
                                    <div class="reports-summary-panel h-100">
                                        <div class="reports-chart-header">
                                            <span class="reports-chart-icon reports-chart-icon-summary"><i class="fa fa-gauge-high"></i></span>
                                            <span>Résumé du mois en cours</span>
                                        </div>
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
                                        <div class="reports-chart-header">
                                            <span class="reports-chart-icon reports-chart-icon-mode"><i class="fa fa-chart-pie"></i></span>
                                            <span>Répartition des recharges</span>
                                        </div>
                                        <div class="reports-chart-wrap">
                                            <canvas id="reportsModeChart"></canvas>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-lg-4 col-md-6">
                                    <div class="reports-chart-card h-100">
                                        <div class="reports-chart-header">
                                            <span class="reports-chart-icon reports-chart-icon-user"><i class="fa fa-ranking-star"></i></span>
                                            <span>Top revendeurs (montant)</span>
                                        </div>
                                        <div class="reports-chart-wrap">
                                            <canvas id="reportsUserChart"></canvas>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-lg-4 col-md-12">
                                    <div class="reports-chart-card h-100">
                                        <div class="reports-chart-header">
                                            <span class="reports-chart-icon reports-chart-icon-count"><i class="fa fa-chart-column"></i></span>
                                            <span>Recharges par jour</span>
                                        </div>
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
                                    <div class="col-md-2">
                                        <select class="form-select reports-filter-select" id="reportProfileTypeFilter">
                                            <option value="">Type profil</option>
                                            <?php foreach (array_keys($profileTypeValues) as $profileType): ?>
                                                <option value="<?= htmlspecialchars(mb_strtolower($profileType)) ?>"><?= htmlspecialchars($profileType) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-2">
                                        <select class="form-select reports-filter-select" id="reportOperationFilter">
                                            <option value="">Operation</option>
                                            <?php foreach ($operationValues as $operationValue => $operationLabel): ?>
                                                <option value="<?= htmlspecialchars(mb_strtolower((string)$operationValue)) ?>"><?= htmlspecialchars((string)$operationLabel) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-2">
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
                                                        data-profile-type="<?= htmlspecialchars(mb_strtolower(trim((string)($item['profile_type'] ?? '')))) ?>"
                                                        data-operation="<?= htmlspecialchars(mb_strtolower(trim((string)($item['mode'] ?? '')))) ?>"
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
  1 => 'https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js',
);
ob_start();
?>
document.addEventListener('DOMContentLoaded', () => {
    const amountCanvas = document.getElementById('reportsAmountChart');
    const modeCanvas = document.getElementById('reportsModeChart');
    const userCanvas = document.getElementById('reportsUserChart');
    const countCanvas = document.getElementById('reportsCountChart');
    const searchInput = document.getElementById('reportSearchInput');
    const dayFilter = document.getElementById('reportDayFilter');
    const monthFilter = document.getElementById('reportMonthFilter');
    const yearFilter = document.getElementById('reportYearFilter');
    const profileTypeFilter = document.getElementById('reportProfileTypeFilter');
    const operationFilter = document.getElementById('reportOperationFilter');
    const reportTableBody = document.getElementById('reportTableBody');
    const printReportsBtn = document.getElementById('printReportsBtn');
    const exportReportsXlsxBtn = document.getElementById('exportReportsXlsxBtn');

    const formatAmount = (value) => {
        const numeric = Number(value);
        if (!Number.isFinite(numeric)) {
            return '0';
        }
        return numeric.toLocaleString('fr-FR', { minimumFractionDigits: 0, maximumFractionDigits: 2 });
    };
    const formatCurrency = (value) => `${formatAmount(value)} F`;
    const todayLabel = String(new Date().getDate()).padStart(2, '0');

    const baseGrid = {
        color: 'rgba(148, 163, 184, 0.08)',
        drawBorder: false,
    };

    if (amountCanvas && typeof Chart !== 'undefined') {
        const ctx = amountCanvas.getContext('2d');
        const gradient = ctx.createLinearGradient(0, 0, 0, 260);
        gradient.addColorStop(0, 'rgba(56, 189, 248, 0.35)');
        gradient.addColorStop(1, 'rgba(56, 189, 248, 0.02)');

        new Chart(ctx, {
            type: 'line',
            data: {
                labels: <?= json_encode($trendSeries['labels'], JSON_UNESCAPED_UNICODE) ?>,
                datasets: [
                    {
                        label: <?= json_encode($trendSeries['current_month_label'], JSON_UNESCAPED_UNICODE) ?>,
                        data: <?= json_encode($trendSeries['current_amounts']) ?>,
                        borderColor: '#38bdf8',
                        backgroundColor: gradient,
                        fill: true,
                        tension: 0.42,
                        borderWidth: 2,
                        pointRadius: 0,
                        pointHoverRadius: 5,
                        pointHitRadius: 12,
                    },
                    {
                        label: <?= json_encode($trendSeries['previous_month_label'], JSON_UNESCAPED_UNICODE) ?>,
                        data: <?= json_encode($trendSeries['previous_amounts']) ?>,
                        borderColor: '#f59e0b',
                        backgroundColor: 'rgba(245, 158, 11, 0.06)',
                        borderDash: [5, 5],
                        tension: 0.42,
                        borderWidth: 2,
                        pointRadius: 0,
                        pointHoverRadius: 4,
                        pointHitRadius: 12,
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: {
                    mode: 'index',
                    intersect: false,
                },
                scales: {
                    x: {
                        ticks: { color: 'rgba(226, 232, 240, 0.68)', maxTicksLimit: 12 },
                        grid: baseGrid,
                    },
                    y: {
                        ticks: {
                            color: 'rgba(226, 232, 240, 0.68)',
                            callback: (value) => formatCurrency(value),
                        },
                        grid: baseGrid,
                    },
                },
                plugins: {
                    legend: {
                        labels: { color: '#e5eefc', usePointStyle: true, pointStyle: 'line' },
                    },
                    tooltip: {
                        backgroundColor: 'rgba(2, 6, 23, 0.94)',
                        borderColor: 'rgba(56, 189, 248, 0.35)',
                        borderWidth: 1,
                        padding: 10,
                        callbacks: {
                            label: (context) => `${context.dataset.label}: ${formatCurrency(context.parsed.y)}`,
                        },
                    },
                },
            },
        });
    }

    if (countCanvas && typeof Chart !== 'undefined') {
        new Chart(countCanvas.getContext('2d'), {
            type: 'bar',
            data: {
                labels: <?= json_encode($trendSeries['labels'], JSON_UNESCAPED_UNICODE) ?>,
                datasets: [
                    {
                        label: 'Recharges',
                        data: <?= json_encode($trendSeries['current_counts']) ?>,
                        backgroundColor: (context) => context.chart.data.labels[context.dataIndex] === todayLabel ? 'rgba(56, 189, 248, 0.72)' : 'rgba(129, 140, 248, 0.45)',
                        borderColor: '#818cf8',
                        borderWidth: 1,
                        borderRadius: 7,
                        maxBarThickness: 18,
                    },
                ],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    x: {
                        ticks: { color: 'rgba(226, 232, 240, 0.68)', maxTicksLimit: 12 },
                        grid: baseGrid,
                    },
                    y: {
                        ticks: { color: 'rgba(226, 232, 240, 0.68)', precision: 0 },
                        grid: baseGrid,
                    },
                },
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        backgroundColor: 'rgba(2, 6, 23, 0.94)',
                        borderColor: 'rgba(129, 140, 248, 0.35)',
                        borderWidth: 1,
                        padding: 10,
                        callbacks: {
                            label: (context) => `${context.parsed.y} recharge(s)`,
                        },
                    },
                },
            },
        });
    }

    if (modeCanvas && typeof Chart !== 'undefined') {
        new Chart(modeCanvas.getContext('2d'), {
            type: 'doughnut',
            data: {
                labels: <?= json_encode(array_map(fn ($row) => reportModeLabel((string)($row['mode'] ?? '')), $modeStats), JSON_UNESCAPED_UNICODE) ?>,
                datasets: [
                    {
                        data: <?= json_encode(array_map(fn ($row) => (int)($row['total_count'] ?? 0), $modeStats)) ?>,
                        backgroundColor: [
                            '#38bdf8',
                            '#22c55e',
                            '#f59e0b',
                            '#f97316',
                            '#a78bfa',
                            '#94a3b8',
                        ],
                        borderColor: 'rgba(2, 6, 23, 0.88)',
                        borderWidth: 2,
                        hoverOffset: 8,
                    },
                ],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '62%',
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: { color: '#e5eefc', boxWidth: 10, usePointStyle: true },
                    },
                    tooltip: {
                        backgroundColor: 'rgba(2, 6, 23, 0.94)',
                        borderColor: 'rgba(167, 139, 250, 0.35)',
                        borderWidth: 1,
                        padding: 10,
                    },
                },
            },
        });
    }

    if (userCanvas && typeof Chart !== 'undefined') {
        new Chart(userCanvas.getContext('2d'), {
            type: 'bar',
            data: {
                labels: <?= json_encode(array_map(fn ($row) => (string)($row['operator_username'] ?? '-'), $resellerStats), JSON_UNESCAPED_UNICODE) ?>,
                datasets: [
                    {
                        label: 'Montant',
                        data: <?= json_encode(array_map(fn ($row) => (float)($row['total_amount'] ?? 0), $resellerStats)) ?>,
                        backgroundColor: 'rgba(34, 197, 94, 0.48)',
                        borderColor: '#22c55e',
                        borderWidth: 1,
                        borderRadius: 7,
                        maxBarThickness: 16,
                    },
                ],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                indexAxis: 'y',
                scales: {
                    x: {
                        ticks: {
                            color: 'rgba(226, 232, 240, 0.68)',
                            callback: (value) => formatCurrency(value),
                        },
                        grid: baseGrid,
                    },
                    y: {
                        ticks: { color: 'rgba(226, 232, 240, 0.68)' },
                        grid: { display: false },
                    },
                },
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        backgroundColor: 'rgba(2, 6, 23, 0.94)',
                        borderColor: 'rgba(34, 197, 94, 0.35)',
                        borderWidth: 1,
                        padding: 10,
                        callbacks: {
                            label: (context) => formatCurrency(context.parsed.x),
                        },
                    },
                },
            },
        });
    }

    const applyReportFilters = () => {
        if (!reportTableBody) {
            return;
        }

        const query = (searchInput?.value || '').trim().toLowerCase();
        const selectedDay = dayFilter?.value || '';
        const selectedMonth = monthFilter?.value || '';
        const selectedYear = yearFilter?.value || '';
        const selectedProfileType = profileTypeFilter?.value || '';
        const selectedOperation = operationFilter?.value || '';

        reportTableBody.querySelectorAll('tr').forEach((row) => {
            if (row.dataset.sortDisabled === '1') {
                row.style.display = '';
                return;
            }

            const rowText = row.textContent.toLowerCase();
            const matchesText = query === '' || rowText.includes(query);
            const matchesDay = selectedDay === '' || row.dataset.day === selectedDay;
            const matchesMonth = selectedMonth === '' || row.dataset.month === selectedMonth;
            const matchesYear = selectedYear === '' || row.dataset.year === selectedYear;
            const matchesProfileType = selectedProfileType === '' || row.dataset.profileType === selectedProfileType;
            const matchesOperation = selectedOperation === '' || row.dataset.operation === selectedOperation;
            row.style.display = (matchesText && matchesDay && matchesMonth && matchesYear && matchesProfileType && matchesOperation) ? '' : 'none';
        });
    };

    [searchInput, dayFilter, monthFilter, yearFilter, profileTypeFilter, operationFilter].forEach((element) => {
        if (!element) {
            return;
        }
        element.addEventListener('input', applyReportFilters);
        element.addEventListener('change', applyReportFilters);
    });

    const buildVisibleReportTable = () => {
        const table = document.querySelector('#reports-details .reports-table-panel table');
        if (!table) {
            return null;
        }

        const exportedTable = document.createElement('table');
        const tableHead = table.querySelector('thead');
        const tableBody = document.createElement('tbody');

        if (tableHead) {
            exportedTable.appendChild(tableHead.cloneNode(true));
        }

        table.querySelectorAll('tbody tr').forEach((row) => {
            if (row.style.display === 'none') {
                return;
            }
            tableBody.appendChild(row.cloneNode(true));
        });

        exportedTable.appendChild(tableBody);
        return exportedTable;
    };

    if (printReportsBtn) {
        printReportsBtn.addEventListener('click', () => {
            const table = buildVisibleReportTable();
            if (!table) {
                window.print();
                return;
            }

            const printWindow = window.open('', '_blank');
            if (!printWindow) {
                window.print();
                return;
            }

            const title = 'Rapports - Détails';
            const styles = `
                <style>
                    body { font-family: Arial, sans-serif; padding: 16px; color: #0f172a; }
                    h1 { font-size: 18px; margin: 0 0 12px; }
                    table { width: 100%; border-collapse: collapse; font-size: 12px; }
                    th, td { border: 1px solid #cbd5f5; padding: 6px 8px; text-align: left; }
                    th { background: #e2e8f0; font-weight: 600; }
                </style>
            `;

            printWindow.document.open();
            printWindow.document.write(`<!doctype html><html><head><title>${title}</title>${styles}</head><body><h1>${title}</h1>${table.outerHTML}</body></html>`);
            printWindow.document.close();
            printWindow.focus();
            printWindow.print();
        });
    }

    if (exportReportsXlsxBtn) {
        exportReportsXlsxBtn.addEventListener('click', () => {
            if (typeof XLSX === 'undefined') {
                alert('Export XLSX indisponible : la librairie XLSX n est pas chargee.');
                return;
            }

            const table = buildVisibleReportTable();
            if (!table) {
                return;
            }

            const workbook = XLSX.utils.table_to_book(table, { sheet: 'Rapports' });
            XLSX.writeFile(workbook, `rapports_${new Date().toISOString().slice(0, 10)}.xlsx`);
        });
    }
});
<?php
$extraScript = ob_get_clean();
require_once '../includes/layout_footer.php';
?>
