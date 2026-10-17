<?php
session_start();
require_once '../config/db.php';
require_once '../includes/auth.php';
require_once '../includes/user_logs_backend.php';
require_once '../includes/mobile_detect.php';

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
$selectedDeviceId = trim((string)($_GET['device_id'] ?? ''));
if ($selectedSource === '') {
    $selectedSource = 'all';
}
$searchQuery = trim((string)($_GET['q'] ?? ''));

$logsView = loadUserLogsViewData($pdo, [
    'day' => $selectedDay,
    'month' => $selectedMonth,
    'year' => $selectedYear,
    'source' => $selectedSource,
    'device_id' => $selectedDeviceId,
]);

$context = $logsView['context'] ?? [];
$view = $logsView['view'] ?? [];
$combinedRows = $logsView['rows'] ?? [];
$errors = $logsView['errors'] ?? [];

/* Recherche cote serveur sur l'ensemble de l'historique, avant pagination : la pagination
   decoupant l'affichage en pages de 1000/200 lignes, une recherche purement cote client (JS) ne
   portait plus que sur la page courante au lieu des ~15k lignes totales. */
if ($searchQuery !== '') {
    $searchNeedle = mb_strtolower($searchQuery);
    $combinedRows = array_values(array_filter($combinedRows, static function (array $row) use ($searchNeedle): bool {
        $haystack = mb_strtolower(implode(' ', [
            (string)($row['date'] ?? ''),
            (string)($row['time'] ?? ''),
            (string)($row['username'] ?? ''),
            (string)($row['profile'] ?? ''),
            (string)($row['address'] ?? ''),
            (string)($row['mac'] ?? ''),
            (string)($row['action'] ?? ''),
            (string)($row['status'] ?? ''),
            (string)($row['server'] ?? ''),
        ]));
        return str_contains($haystack, $searchNeedle);
    }));
}

/* Regle Mobile / Performance (docs/project_rules.md) : la page pouvait peser ~18 Mo (tout le
   volume 48h rendu d'un coup), lourd meme sur desktop. Pagination plutot qu'un plafond silencieux :
   toutes les lignes restent accessibles, juste pas dans un seul bloc HTML geant. Les lignes sont
   deja triees du plus recent au plus ancien (sortUserLogRows). */
$isMobileRequest = isMobileRequest();
/* Sur PC, une recherche doit remonter TOUS les resultats correspondants (pas de pagination a
   re-parcourir page par page) : le volume filtre est deja bien plus petit que les ~15k lignes
   totales. La pagination ne s'applique qu'en navigation libre (sans recherche), et toujours sur
   mobile (volume de donnees cellulaires). */
$paginationActive = $isMobileRequest || $searchQuery === '';
$perPage = $isMobileRequest ? 200 : 1000;
$totalLogRows = count($combinedRows);
if ($paginationActive) {
    $totalPages = max(1, (int)ceil($totalLogRows / $perPage));
    $currentPage = max(1, min($totalPages, (int)($_GET['page'] ?? 1)));
    $combinedRows = array_slice($combinedRows, ($currentPage - 1) * $perPage, $perPage);
} else {
    $totalPages = 1;
    $currentPage = 1;
}

$pagerBaseParams = [
    'day' => $selectedDay,
    'month' => $selectedMonth,
    'year' => $selectedYear,
    'source' => $selectedSource,
    'device_id' => $selectedDeviceId,
    'q' => $searchQuery,
];

$isMikrotik = (bool)($context['is_mikrotik'] ?? false);
$isRadiusLike = (bool)($context['is_radius_like'] ?? false);
$pageTitle = (string)($view['page_title'] ?? 'User Logs');
$monthNames = $view['month_names'] ?? [];
$sourceFilterOptions = $view['source_filter_options'] ?? [];
$deviceOptions = $view['device_options'] ?? [];
$selectedDeviceId = (string)($view['selected_device_id'] ?? $selectedDeviceId);

$hotspotEventsError = $errors['hotspot'] ?? null;
$radiusSessionsError = $errors['radius_sessions'] ?? null;

$currentMonth = (int)date('m');
$currentYear = (int)date('Y');
$monthValue = $selectedMonth !== '' ? (int)$selectedMonth : $currentMonth;
$yearValue = $selectedYear !== '' ? (int)$selectedYear : $currentYear;
$dayValue = $selectedDay !== '' ? (int)$selectedDay : 0;
?>

<?php
$htmlClass = 'user-logs-page';
$bodyClass = 'user-logs-page';
$extraCss = [
    '../css/user_logs.css?v=20261017f',
];
$pageTitle = 'User Logs';
require_once '../includes/layout_header.php';
?>

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
        <div class="user-logs-filter-row">
            <?php if ($isRadiusLike): ?>
            <div class="user-logs-filter-item user-logs-filter-item--day">
                <label class="form-label text-white-50 small mb-1">Jour</label>
                <select class="form-select user-logs-filter-select" name="day">
                    <option value="">Tous</option>
                    <?php for ($i = 1; $i <= 31; $i++): ?>
                    <option value="<?= $i ?>" <?= $dayValue === $i ? 'selected' : '' ?>><?= str_pad((string)$i, 2, '0', STR_PAD_LEFT) ?></option>
                    <?php endfor; ?>
                </select>
            </div>
            <div class="user-logs-filter-item user-logs-filter-item--month">
                <label class="form-label text-white-50 small mb-1">Mois</label>
                <select class="form-select user-logs-filter-select" name="month">
                    <?php foreach ($monthNames as $number => $label): ?>
                    <option value="<?= $number ?>" <?= $monthValue === $number ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="user-logs-filter-item user-logs-filter-item--year">
                <label class="form-label text-white-50 small mb-1">Annee</label>
                <select class="form-select user-logs-filter-select" name="year">
                    <?php for ($y = 2018; $y <= (int)date('Y'); $y++): ?>
                    <option value="<?= $y ?>" <?= $yearValue === $y ? 'selected' : '' ?>><?= $y ?></option>
                    <?php endfor; ?>
                </select>
            </div>
            <?php endif; ?>
            <div class="user-logs-filter-item user-logs-filter-item--device">
                <label class="form-label text-white-50 small mb-1">Device</label>
                <select class="form-select user-logs-filter-select" name="device_id">
                    <?php foreach ($deviceOptions as $deviceId => $deviceLabel): ?>
                        <option value="<?= htmlspecialchars((string)$deviceId, ENT_QUOTES) ?>"<?= $selectedDeviceId === (string)$deviceId ? ' selected' : '' ?>><?= htmlspecialchars((string)$deviceLabel) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="user-logs-filter-item user-logs-filter-item--source">
                <label class="form-label text-white-50 small mb-1">Source</label>
                <select class="form-select user-logs-filter-select" name="source">
                    <?php foreach ($sourceFilterOptions as $value => $label): ?>
                        <option value="<?= htmlspecialchars($value, ENT_QUOTES) ?>"<?= $selectedSource === $value ? ' selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="user-logs-filter-search">
                <div class="row g-2 align-items-end user-logs-search-bar">
                    <div class="col-md-7">
                        <div class="user-logs-filter-field">
                            <label class="form-label text-white-50 small mb-1">Recherche</label>
                            <div class="input-group user-logs-search-group">
                                <span class="input-group-text">
                                    <i class="fa fa-search"></i>
                                </span>
                                <input id="userLogsFilter" name="q" type="search" class="form-control" placeholder="Rechercher dans les logs (Entrée pour chercher sur tout l'historique)" autocomplete="off" value="<?= htmlspecialchars($searchQuery, ENT_QUOTES) ?>">
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
                                <?php if (!$isMobileRequest): ?>
                                <button type="button" class="btn btn-test" id="userLogsAutoRefreshBtn">
                                    <i class="fa fa-clock-rotate-left me-1"></i> Auto 30s
                                </button>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </form>
    <?php endif; ?>

    <?php
    $pagerLink = static function (int $targetPage) use ($pagerBaseParams): string {
        $params = $pagerBaseParams;
        $params['page'] = $targetPage;
        $params = array_filter($params, static fn($v) => $v !== '' && $v !== null);
        return 'user_logs.php?' . http_build_query($params);
    };
    ?>
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-2">
        <p class="text-white-50 small mb-0">
            <?= $totalLogRows ?> événement(s)<?= $isMobileRequest ? ' · affichage mobile allégé' : '' ?><?= $paginationActive ? ' — page ' . $currentPage . '/' . $totalPages : ' — tous les résultats affichés' ?>
        </p>
        <?php if ($totalPages > 1): ?>
        <nav class="d-flex gap-2" aria-label="Pagination des logs">
            <a class="btn btn-sm btn-save<?= $currentPage <= 1 ? ' disabled' : '' ?>" href="<?= htmlspecialchars($pagerLink(max(1, $currentPage - 1)), ENT_QUOTES) ?>"><i class="fa fa-chevron-left me-1"></i>Précédent</a>
            <a class="btn btn-sm btn-save<?= $currentPage >= $totalPages ? ' disabled' : '' ?>" href="<?= htmlspecialchars($pagerLink(min($totalPages, $currentPage + 1)), ENT_QUOTES) ?>">Suivant<i class="fa fa-chevron-right ms-1"></i></a>
        </nav>
        <?php endif; ?>
    </div>

    <div class="user-logs-table-wrap table-responsive system-log-table-scroll">
        <table class="table table-sm table-striped table-hover table-dark mb-0 align-middle users-table table-standard small" id="userLogsTable" data-sort-table="1" data-default-sort-key="datetime" data-default-sort-direction="desc">
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
                <?php elseif ($hotspotEventsError !== null || $radiusSessionsError !== null): ?>
                <tr data-sort-disabled="1">
                    <td colspan="9" class="text-center text-danger py-4">
                        <?= htmlspecialchars($hotspotEventsError ?? $radiusSessionsError ?? 'Erreur de chargement') ?>
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


<?php
$extraJs = [
    '../js/table_sort.js',
    '../js/user_logs.js?v=20260425a',
];
require_once '../includes/layout_footer.php';
?>
