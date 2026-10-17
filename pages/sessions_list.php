<?php
session_start();

require_once '../includes/message.php';
require_once '../config/db.php';
require_once '../includes/session_formatters.php';
require_once '../includes/sessions_backend.php';
require_once '../includes/multi_wan_config.php';
require_once '../includes/multi_wan_allocator.php';

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    set_message('Veuillez vous connecter pour accéder à cette page.', 'danger');
    header('Location: ../index.php');
    exit();
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$activeDevice = getActiveDeviceContext()['device'] ?? null;
$activeDeviceId = trim((string)($activeDevice['id'] ?? ''));
$sessionsData = loadActiveSessionsData($pdo, $activeDevice);
$activeDeviceType = $sessionsData['activeDeviceType'];
$canDisconnectRemotely = $sessionsData['canDisconnectRemotely'];
$sessionSourceLabel = $sessionsData['sessionSourceLabel'];
$sessions = $sessionsData['sessions'];
$sessionCount = count($sessions);
$isMikrotikSessions = $sessionsData['isMikrotikSessions'];
$isOpnsenseSessions = $sessionsData['isOpnsenseSessions'];
$sessionProfileMap = $sessionsData['sessionProfileMap'];
$sessionWanOverrideOptions = [];
$sessionWanOverrides = [];
if ($isMikrotikSessions && is_array($activeDevice)) {
    $sessionWanOverrideOptions = multiWanConfigWanOptions(multiWanLoadConfig($activeDevice));
    $sessionDeviceKey = trim((string)($activeDevice['id'] ?? $activeDevice['host'] ?? $activeDeviceId));
    if ($sessionDeviceKey !== '') {
        $sessionWanOverrides = multiWanLoadUserOverrides($pdo, $sessionDeviceKey);
    }
}
$sessionWanCounts = ['WAN1' => 0, 'WAN2' => 0, 'WAN3' => 0, 'WAN4' => 0, 'none' => 0];
if ($isMikrotikSessions) {
    foreach ($sessions as $session) {
        $wan = strtoupper(trim((string)($session['wan'] ?? '')));
        if (isset($sessionWanCounts[$wan])) {
            $sessionWanCounts[$wan]++;
        } else {
            $sessionWanCounts['none']++;
        }
    }
}

if (isset($_GET['_partial']) && $_GET['_partial'] === 'sessions'
    && strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest') {
    header('Content-Type: text/html; charset=UTF-8');
    require __DIR__ . '/../includes/sessions_list_table_body.php';
    exit;
}
?>

<?php
$pageTitle = 'Sessions Actives';
$htmlClass = 'sessions-list-page';
$bodyClass = 'sessions-list-page';
$contentClass = 'sessions-list-shell';
$bodyAttributes = [
    'data-session-mode' => $isMikrotikSessions ? 'mikrotik' : ($isOpnsenseSessions ? 'opnsense' : 'radius'),
    'data-device-id' => $activeDeviceId,
];
$extraCss = [
    '../css/sessions_list.css',
];
require_once '../includes/layout_header.php';
?>

<div id="messageArea" style="display: none;"></div>

            <div class="card shadow-sm mb-4">
                <div class="card-header users-list-card-header d-flex align-items-center justify-content-between flex-wrap gap-2">
                    <div class="d-flex align-items-center users-list-card-title text-truncate">
                        <i class="fa fa-users me-2 flex-shrink-0"></i>
                        <span>Sessions Actives</span>
                        <span id="sessionsListCount" class="sessions-list-count ms-2"><?= (int)$sessionCount ?></span>
                        <?php if ($isMikrotikSessions): ?>
                        <span class="sessions-wan-summary" aria-label="Répartition WAN des sessions actives">
                            <span class="sessions-wan-count sessions-wan-count--wan1"><span class="sessions-wan-count__label">WAN1</span><strong id="sessionsWan1Count"><?= (int)$sessionWanCounts['WAN1'] ?></strong></span>
                            <span class="sessions-wan-count sessions-wan-count--wan2"><span class="sessions-wan-count__label">WAN2</span><strong id="sessionsWan2Count"><?= (int)$sessionWanCounts['WAN2'] ?></strong></span>
                        </span>
                        <?php endif; ?>
                    </div>
                    <div class="d-flex align-items-center justify-content-end flex-wrap gap-2 flex-grow-1">
                        <div class="sessions-header-search users-filter-field">
                            <div class="input-group users-filter-field-search">
                                <span class="input-group-text">
                                    <i class="fa fa-search me-2"></i>Recherche
                                </span>
                                <input id="sessionsSearchFilter" type="search" class="form-control" placeholder="Nom, profil, IP, MAC..." autocomplete="off">
                            </div>
                        </div>
                        <div class="d-flex align-items-center gap-2 flex-shrink-0 sessions-table-actions">
                            <button type="button" class="btn btn-test" id="sessionsManualRefreshBtn" title="Recharger la liste des sessions">
                                <i class="fa fa-rotate me-1"></i> Actualiser
                            </button>
                            <div class="dropdown">
                                <button
                                    class="btn btn-test dropdown-toggle"
                                    type="button"
                                    id="sessionsColumnsToggle"
                                    data-bs-toggle="dropdown"
                                    data-bs-auto-close="outside"
                                    aria-expanded="false"
                                >
                                    <i class="fa fa-table-columns me-1"></i> Colonnes
                                </button>
                                <div class="dropdown-menu dropdown-menu-end profile-columns-menu p-2" id="sessionsColumnsMenu" aria-labelledby="sessionsColumnsToggle"></div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="card-body">
                    <div class="sessions-table-panel users-table-panel">
                    <div class="table-responsive sessions-table-scroll users-table-scroll">
                        <table class="table table-striped table-hover table-dark sessions-table users-table align-middle small text-nowrap table-standard mb-0" data-sort-table="1" data-default-sort-key="id" data-default-sort-direction="desc">
                            <thead>
                                <tr>
                                    <?php if ($isOpnsenseSessions): ?>
                                    <th data-sort-key="username" data-sort-type="text">Utilisateur</th>
                                    <th data-sort-key="profile" data-sort-type="text">Profil</th>
                                    <th data-sort-key="address" data-sort-type="text">Adresse IP</th>
                                    <th data-sort-key="mac" data-sort-type="text">MAC</th>
                                    <th class="text-end" data-sort-key="duration" data-sort-type="number">Durée</th>
                                    <th class="text-end" data-sort-key="tx_speed" data-sort-type="number">TX</th>
                                    <th class="text-end" data-sort-key="rx_speed" data-sort-type="number">RX</th>
                                    <th class="text-end" data-sort-key="upload" data-sort-type="number">Upload</th>
                                    <th class="text-end" data-sort-key="download" data-sort-type="number">Download</th>
                                    <th data-sort-key="login" data-sort-type="text">Source auth</th>
                                    <th class="action-header">Actions</th>
                                    <?php else: ?>
                                    <th data-sort-key="id" data-sort-type="text">ID</th>
                                    <th data-sort-key="session" data-sort-type="text">Session</th>
                                    <th data-sort-key="username" data-sort-type="text">Utilisateur</th>
                                    <th data-sort-key="profile" data-sort-type="text">Profil</th>
                                    <th data-sort-key="address" data-sort-type="text">Adresse IP</th>
                                    <th data-sort-key="mac" data-sort-type="text">MAC</th>
                                    <?php if ($isMikrotikSessions): ?>
                                    <th data-sort-key="wan" data-sort-type="text">WAN</th>
                                    <?php endif; ?>
                                    <th class="text-end" data-sort-key="duration" data-sort-type="number">Durée</th>
                                    <th class="text-end" data-sort-key="tx_speed" data-sort-type="number">TX</th>
                                    <th class="text-end" data-sort-key="rx_speed" data-sort-type="number">RX</th>
                                    <th class="text-end" data-sort-key="upload" data-sort-type="number">Upload</th>
                                    <th class="text-end" data-sort-key="download" data-sort-type="number">Download</th>
                                    <th data-sort-key="login" data-sort-type="text">Login</th>
                                    <th class="action-header">Actions</th>
                                    <?php endif; ?>
                                </tr>
                            </thead>
                            <tbody id="sessionsTableBody">
                                <?php include '../includes/sessions_list_table_body.php'; ?>
                            </tbody>
                        </table>
                    </div>
                    </div>
                </div>
            </div>

<?php if ($isMikrotikSessions): ?>
<div class="modal fade session-wan-modal" id="sessionWanOverrideModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <div>
                    <h5 class="modal-title">Exception WAN client</h5>
                    <p class="modal-subtitle mb-0" id="sessionWanOverrideTarget">Session sélectionnée</p>
                </div>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Fermer"></button>
            </div>
            <div class="modal-body">
                <div class="session-wan-modal-help">
                    Ce choix force seulement ce client. Les sources inactives restent visibles mais non sélectionnables.
                </div>
                <fieldset class="session-wan-choice-list" id="sessionWanOverrideChoices">
                    <legend class="visually-hidden">Choisir le WAN forcé</legend>
                </fieldset>
                <div class="session-wan-modal-feedback" id="sessionWanOverrideFeedback" role="status" aria-live="polite"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-test" data-bs-dismiss="modal">Annuler</button>
                <button type="button" class="btn btn-apply" id="sessionWanOverrideSaveBtn">
                    <i class="fa fa-check me-1"></i> Appliquer
                </button>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

        </div>
    </div>
</div>

<script>
window.SESSIONS_LIST_CONFIG = {
    csrfToken: '<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES) ?>',
    isOpnsenseSessions: <?= $isOpnsenseSessions ? 'true' : 'false' ?>,
    isMikrotikSessions: <?= $isMikrotikSessions ? 'true' : 'false' ?>,
    deviceId: <?= json_encode($activeDeviceId, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>,
    wanOverrideOptions: <?= json_encode($sessionWanOverrideOptions, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>
};
</script>
<?php
$extraJs = array (
  0 => '../js/table_sort.js?v=20260920b',
  1 => '../js/pages/sessions_list.js?v=20260920b',
);
require_once '../includes/layout_footer.php';
?>
