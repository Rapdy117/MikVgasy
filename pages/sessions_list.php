<?php
session_start();

require_once '../includes/message.php';
require_once '../config/db.php';
require_once '../includes/session_formatters.php';
require_once '../includes/sessions_backend.php';

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    set_message('Veuillez vous connecter pour accéder à cette page.', 'danger');
    header('Location: ../index.php');
    exit();
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$activeDevice = getActiveDeviceContext()['device'] ?? null;
$sessionsData = loadActiveSessionsData($pdo, $activeDevice);
$activeDeviceType = $sessionsData['activeDeviceType'];
$canDisconnectRemotely = $sessionsData['canDisconnectRemotely'];
$sessionSourceLabel = $sessionsData['sessionSourceLabel'];
$sessions = $sessionsData['sessions'];
$isMikrotikSessions = $sessionsData['isMikrotikSessions'];
$isOpnsenseSessions = $sessionsData['isOpnsenseSessions'];
$sessionProfileMap = $sessionsData['sessionProfileMap'];

if (isset($_GET['_partial']) && $_GET['_partial'] === 'sessions'
    && strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest') {
    header('Content-Type: text/html; charset=UTF-8');
    require __DIR__ . '/../includes/sessions_list_table_body.php';
    exit;
}
?>
<!DOCTYPE html>
<html lang="fr" class="sessions-list-page">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sessions Actives</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@300;400;500;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link rel="stylesheet" href="../css/theme.css">
        <link rel="stylesheet" href="../css/sessions_list.css">
</head>

<body data-session-mode="<?= htmlspecialchars($isMikrotikSessions ? 'mikrotik' : ($isOpnsenseSessions ? 'opnsense' : 'radius'), ENT_QUOTES) ?>">

<div class="d-flex" id="wrapper">
    <?php include '../includes/sidebar.php'; ?>

    <div id="page-content-wrapper">
        <div class="container-fluid py-3 sessions-list-shell">
            <?php display_message(); ?>
            <div id="messageArea" style="display: none;"></div>

            <div class="alert alert-info py-2 px-3 small mb-3 page-flow-explanation">
                <div class="fw-semibold">Sessions / Logs</div>
                <div>Source active : <?= htmlspecialchars($sessionSourceLabel) ?></div>
            </div>

            <div class="card shadow-sm mb-4">
                <div class="card-header users-list-card-header d-flex align-items-center justify-content-between flex-wrap gap-2">
                    <div class="d-flex align-items-center users-list-card-title text-truncate">
                        <i class="fa fa-users me-2 flex-shrink-0"></i>
                        <span>Sessions Actives</span>
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
                                    <th class="action-header">Déconnexion</th>
                                    <?php else: ?>
                                    <th data-sort-key="id" data-sort-type="text">ID</th>
                                    <th data-sort-key="session" data-sort-type="text">Session</th>
                                    <th data-sort-key="username" data-sort-type="text">Utilisateur</th>
                                    <th data-sort-key="profile" data-sort-type="text">Profil</th>
                                    <th data-sort-key="address" data-sort-type="text">Adresse IP</th>
                                    <th data-sort-key="mac" data-sort-type="text">MAC</th>
                                    <th class="text-end" data-sort-key="duration" data-sort-type="number">Durée</th>
                                    <th class="text-end" data-sort-key="tx_speed" data-sort-type="number">TX</th>
                                    <th class="text-end" data-sort-key="rx_speed" data-sort-type="number">RX</th>
                                    <th class="text-end" data-sort-key="upload" data-sort-type="number">Upload</th>
                                    <th class="text-end" data-sort-key="download" data-sort-type="number">Download</th>
                                    <th data-sort-key="login" data-sort-type="text">Login</th>
                                    <th class="action-header">Déconnexion</th>
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

        </div>
    </div>
</div>

<div id="spinner-overlay">
    <div class="spinner"></div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="../js/sidebar.js?v=20260402a"></script>
<script src="../js/table_sort.js"></script>
<script>
window.SESSIONS_LIST_CONFIG = {
    csrfToken: '<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES) ?>',
    isOpnsenseSessions: <?= $isOpnsenseSessions ? 'true' : 'false' ?>
};
</script>
<script src="../js/pages/sessions_list.js?v=20260408b"></script>
</body>
</html>
