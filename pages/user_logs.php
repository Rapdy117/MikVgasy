<?php
session_start();
require_once '../config/db.php';
require_once '../includes/auth.php';
require_once '../includes/user_logs_backend.php';

/* SECURITY */
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: ../index.php');
    exit();
}

requireCurrentPageAccess();

/* CSRF */
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$selectedDay = trim((string)($_GET['day'] ?? ''));
$selectedMonth = trim((string)($_GET['month'] ?? ''));
$selectedYear = trim((string)($_GET['year'] ?? ''));
$selectedSource = trim((string)($_GET['source'] ?? 'all'));
if ($selectedSource === '') {
    $selectedSource = 'all';
}

$logsView = loadUserLogsViewData($pdo, [
    'day' => $selectedDay,
    'month' => $selectedMonth,
    'year' => $selectedYear,
    'source' => $selectedSource,
]);

$context = $logsView['context'] ?? [];
$view = $logsView['view'] ?? [];
$combinedRows = $logsView['rows'] ?? [];
$errors = $logsView['errors'] ?? [];

$isMikrotik = (bool)($context['is_mikrotik'] ?? false);
$isRadiusLike = (bool)($context['is_radius_like'] ?? false);
$pageTitle = (string)($view['page_title'] ?? 'User Logs');
$monthNames = $view['month_names'] ?? [];
$sourceFilterOptions = $view['source_filter_options'] ?? [];

$hotspotEventsError = $errors['hotspot'] ?? null;
$radiusSessionsError = $errors['radius_sessions'] ?? null;
$operationRowsError = $errors['operations'] ?? null;
$rechargeRowsError = $errors['recharges'] ?? null;
$radiusAuthError = $errors['radius_auth'] ?? null;

$currentMonth = (int)date('m');
$currentYear = (int)date('Y');
$monthValue = $selectedMonth !== '' ? (int)$selectedMonth : $currentMonth;
$yearValue = $selectedYear !== '' ? (int)$selectedYear : $currentYear;
$dayValue = $selectedDay !== '' ? (int)$selectedDay : 0;
?>

<!DOCTYPE html>
<html lang="fr" class="user-logs-page">
<head>
<meta charset="UTF-8">
<title>User Logs</title>

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="../css/theme.css">
<link rel="stylesheet" href="../css/user_logs.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">

</head>

<body class="user-logs-page">

<div class="d-flex" id="wrapper">

<?php include_once '../includes/sidebar.php'; ?>

<div id="page-content-wrapper">
<div class="container-fluid py-3">
<div class="user-logs-shell">

<div class="row user-logs-layout-row">
<div class="col-12 mb-3">
<div class="card user-logs-card">
<div class="card-header standard-card-header d-flex align-items-center justify-content-between flex-wrap gap-2">
    <div class="d-flex align-items-center text-truncate">
        <i class="fa fa-users me-2 flex-shrink-0"></i>
        <span><?= htmlspecialchars($pageTitle) ?></span>
    </div>
</div>
<div class="card-body user-logs-card-body">
    <?php if ($isMikrotik || $isRadiusLike): ?>
    <form method="GET" class="mb-3" autocomplete="off" id="userLogsFilters">
        <div class="row g-2 align-items-end">
            <?php if ($isRadiusLike): ?>
            <div class="col-lg-1 col-md-2">
                <label class="form-label text-white-50 small mb-1">Jour</label>
                <select class="form-select user-logs-filter-select" name="day">
                    <option value="">Tous</option>
                    <?php for ($i = 1; $i <= 31; $i++): ?>
                    <option value="<?= $i ?>" <?= $dayValue === $i ? 'selected' : '' ?>><?= str_pad((string)$i, 2, '0', STR_PAD_LEFT) ?></option>
                    <?php endfor; ?>
                </select>
            </div>
            <div class="col-lg-2 col-md-3">
                <label class="form-label text-white-50 small mb-1">Mois</label>
                <select class="form-select user-logs-filter-select" name="month">
                    <?php foreach ($monthNames as $number => $label): ?>
                    <option value="<?= $number ?>" <?= $monthValue === $number ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-lg-1 col-md-2">
                <label class="form-label text-white-50 small mb-1">Annee</label>
                <select class="form-select user-logs-filter-select" name="year">
                    <?php for ($y = 2018; $y <= (int)date('Y'); $y++): ?>
                    <option value="<?= $y ?>" <?= $yearValue === $y ? 'selected' : '' ?>><?= $y ?></option>
                    <?php endfor; ?>
                </select>
            </div>
            <?php endif; ?>
            <div class="col-lg-2 col-md-3">
                <label class="form-label text-white-50 small mb-1">Source</label>
                <select class="form-select user-logs-filter-select" name="source">
                    <?php foreach ($sourceFilterOptions as $value => $label): ?>
                        <option value="<?= htmlspecialchars($value, ENT_QUOTES) ?>"<?= $selectedSource === $value ? ' selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="<?= $isRadiusLike ? 'col-lg-6 col-md-12' : 'col-lg-10 col-md-12' ?>">
                <div class="row g-2 align-items-end user-logs-search-bar">
                    <div class="col-md-7">
                        <div class="user-logs-filter-field">
                            <label class="form-label text-white-50 small mb-1">Recherche</label>
                            <div class="input-group user-logs-search-group">
                                <span class="input-group-text">
                                    <i class="fa fa-search"></i>
                                </span>
                                <input id="userLogsFilter" type="search" class="form-control" placeholder="Rechercher dans les logs" autocomplete="off">
                            </div>
                        </div>
                    </div>
                    <div class="col-md-5 text-md-end">
                        <div class="user-logs-filter-field">
                            <label class="form-label text-white-50 small mb-1">&nbsp;</label>
                            <div class="user-logs-actions">
                                <a href="user_logs.php" class="btn btn-save">
                                    <i class="fa fa-sync me-1"></i> Tout
                                </a>
                                <button type="button" class="btn btn-test" id="userLogsAutoRefreshBtn">
                                    <i class="fa fa-clock-rotate-left me-1"></i> Auto 30s
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </form>
    <?php endif; ?>

    <div class="user-logs-table-wrap table-responsive">
        <table class="table table-striped table-hover table-dark mb-0 align-middle users-table table-standard" id="userLogsTable" data-sort-table="1" data-default-sort-key="datetime" data-default-sort-direction="desc">
            <thead>
                <tr>
                    <th data-sort-key="datetime" data-sort-type="date">Date</th>
                    <th data-sort-key="time" data-sort-type="text">Heure</th>
                    <th data-sort-key="username" data-sort-type="text">Utilisateur</th>
                    <th data-sort-key="profile" data-sort-type="text">Profil</th>
                    <th data-sort-key="address" data-sort-type="text">Adresse</th>
                    <th data-sort-key="mac" data-sort-type="text">MAC</th>
                    <th data-sort-key="action" data-sort-type="text">Action</th>
                    <th data-sort-key="status" data-sort-type="text">Statut</th>
                    <th data-sort-key="server" data-sort-type="text">Serveur</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$isMikrotik && !$isRadiusLike): ?>
                <tr data-sort-disabled="1">
                    <td colspan="9" class="text-center text-white-50 py-4">
                        Cette page sera alimentee quand le device actif expose des logs utilisateur.
                    </td>
                </tr>
                <?php elseif ($hotspotEventsError !== null || $radiusSessionsError !== null || $operationRowsError !== null || $rechargeRowsError !== null || $radiusAuthError !== null): ?>
                <tr data-sort-disabled="1">
                    <td colspan="9" class="text-center text-danger py-4">
                        <?= htmlspecialchars($hotspotEventsError ?? $radiusSessionsError ?? $operationRowsError ?? $rechargeRowsError ?? $radiusAuthError ?? 'Erreur de chargement') ?>
                    </td>
                </tr>
                <?php elseif (empty($combinedRows)): ?>
                <tr data-sort-disabled="1">
                    <td colspan="9" class="text-center text-white-50 py-4">
                        Aucun log utilisateur ou evenement hotspot detecte pour le filtre courant.
                    </td>
                </tr>
                <?php else: ?>
                    <?php foreach ($combinedRows as $log): ?>
                    <?php
                    $dateValue = (string)($log['date'] ?? '-');
                    $timeValue = (string)($log['time'] ?? '-');
                    $datetimeValue = ($dateValue !== '-' ? $dateValue : '0000-00-00') . ' ' . ($timeValue !== '-' ? $timeValue : '00:00:00');
                    $searchValue = mb_strtolower(implode(' ', [
                        (string)($log['date'] ?? ''),
                        (string)($log['time'] ?? ''),
                        (string)($log['username'] ?? ''),
                        (string)($log['profile'] ?? ''),
                        (string)($log['address'] ?? ''),
                        (string)($log['mac'] ?? ''),
                        (string)($log['action'] ?? ''),
                        (string)($log['status'] ?? ''),
                        (string)($log['server'] ?? ''),
                    ]));
                    ?>
                    <tr
                        data-datetime="<?= htmlspecialchars($datetimeValue, ENT_QUOTES) ?>"
                        data-time="<?= htmlspecialchars($timeValue, ENT_QUOTES) ?>"
                        data-username="<?= htmlspecialchars((string)($log['username'] ?? '-'), ENT_QUOTES) ?>"
                        data-profile="<?= htmlspecialchars((string)($log['profile'] ?? '-'), ENT_QUOTES) ?>"
                        data-address="<?= htmlspecialchars((string)($log['address'] ?? '-'), ENT_QUOTES) ?>"
                        data-mac="<?= htmlspecialchars((string)($log['mac'] ?? '-'), ENT_QUOTES) ?>"
                        data-action="<?= htmlspecialchars((string)($log['action'] ?? '-'), ENT_QUOTES) ?>"
                        data-status="<?= htmlspecialchars((string)($log['status'] ?? '-'), ENT_QUOTES) ?>"
                        data-server="<?= htmlspecialchars((string)($log['server'] ?? '-'), ENT_QUOTES) ?>"
                        data-source="<?= htmlspecialchars((string)($log['source_key'] ?? ''), ENT_QUOTES) ?>"
                        data-search="<?= htmlspecialchars($searchValue, ENT_QUOTES) ?>"
                    >
                        <td><?= htmlspecialchars($log['date']) ?></td>
                        <td><?= htmlspecialchars($log['time']) ?></td>
                        <td><?= htmlspecialchars($log['username']) ?></td>
                        <td><?= htmlspecialchars($log['profile'] !== '' ? $log['profile'] : '-') ?></td>
                        <td><?= htmlspecialchars($log['address'] !== '' ? $log['address'] : '-') ?></td>
                        <td><?= htmlspecialchars($log['mac'] !== '' ? $log['mac'] : '-') ?></td>
                        <td><?= htmlspecialchars($log['action'] !== '' ? $log['action'] : '-') ?></td>
                        <td><?= htmlspecialchars($log['status'] !== '' ? $log['status'] : '-') ?></td>
                        <td><?= htmlspecialchars($log['server'] !== '' ? $log['server'] : '-') ?></td>
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

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<script src="../js/sidebar.js?v=20260402a"></script>
<script src="../js/table_sort.js"></script>
<script>
document.addEventListener('DOMContentLoaded', () => {
    const filterInput = document.getElementById('userLogsFilter');
    const table = document.getElementById('userLogsTable');
    const autoRefreshBtn = document.getElementById('userLogsAutoRefreshBtn');
    const filterForm = document.getElementById('userLogsFilters');
    const autoRefreshStorageKey = 'user_logs.auto_refresh';
    let autoRefreshTimer = null;

    if (!filterInput || !table) {
        return;
    }

    filterInput.addEventListener('input', () => {
        const needle = filterInput.value.trim().toLowerCase();
        const rows = table.querySelectorAll('tbody tr');

        rows.forEach((row) => {
            if (row.dataset.sortDisabled === '1') {
                return;
            }
            const text = (row.dataset.search || row.textContent).toLowerCase();
            row.style.display = needle === '' || text.includes(needle) ? '' : 'none';
        });
    });

    if (filterForm) {
        const selects = filterForm.querySelectorAll('select');
        selects.forEach((select) => {
            select.addEventListener('change', () => {
                filterForm.submit();
            });
        });
    }

    const setAutoRefreshState = (enabled) => {
        if (autoRefreshTimer) {
            clearInterval(autoRefreshTimer);
            autoRefreshTimer = null;
        }
        if (enabled) {
            autoRefreshTimer = window.setInterval(() => {
                window.location.reload();
            }, 30000);
            if (autoRefreshBtn) {
                autoRefreshBtn.classList.remove('btn-test');
                autoRefreshBtn.classList.add('btn-save');
                autoRefreshBtn.innerHTML = '<i class="fa fa-clock-rotate-left me-1"></i> Auto 30s ON';
            }
        } else if (autoRefreshBtn) {
            autoRefreshBtn.classList.remove('btn-save');
            autoRefreshBtn.classList.add('btn-test');
            autoRefreshBtn.innerHTML = '<i class="fa fa-clock-rotate-left me-1"></i> Auto 30s';
        }
        localStorage.setItem(autoRefreshStorageKey, enabled ? '1' : '0');
    };

    if (autoRefreshBtn) {
        const initialEnabled = localStorage.getItem(autoRefreshStorageKey) === '1';
        setAutoRefreshState(initialEnabled);
        autoRefreshBtn.addEventListener('click', () => {
            const enabled = localStorage.getItem(autoRefreshStorageKey) === '1';
            setAutoRefreshState(!enabled);
        });
    }
});
</script>

</body>
</html>
