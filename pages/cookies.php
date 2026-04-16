<?php
session_start();

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/device_manager.php';
require_once __DIR__ . '/../includes/mikrotik_backend.php';

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: ../index.php');
    exit();
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$store = loadDeviceStore();
$activeDevice = getActiveDeviceRecord($store);
$activeType = strtolower((string)($activeDevice['type'] ?? ''));
$isMikrotik = $activeType === 'mikrotik';
$cookies = [];
$cookiesError = null;

if ($isMikrotik) {
    try {
        $cookies = getMikrotikCookies(300);
    } catch (Throwable $e) {
        $cookiesError = trim((string)$e->getMessage());
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<title>Cookies Hotspot</title>

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Roboto:wght@300;400;500;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="../css/theme.css">
<link rel="stylesheet" href="../css/hosts.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
</head>
<body>

<div class="d-flex" id="wrapper">
<?php include_once __DIR__ . '/../includes/sidebar.php'; ?>

<div id="page-content-wrapper">
<div class="container-fluid py-3">

<div id="messageArea" style="display:none;"></div>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h4 class="text-white mb-0">
        <i class="fa fa-cookie-bite me-2"></i> Cookies
    </h4>
    <span class="text-white-50 small">
        <?= $isMikrotik ? 'Source active : MikroTik / hotspot cookie' : 'Disponible quand le device actif est MikroTik' ?>
    </span>
</div>

<div class="card shadow-sm">
<div class="card-body p-0">
<?php if (!$isMikrotik): ?>
    <div class="hosts-empty-state text-center p-4">
        <div class="hosts-empty-icon mb-3">
            <i class="fa fa-diagram-project"></i>
        </div>
        <h6 class="text-white mb-2">Module MikroTik natif</h6>
        <p class="text-white-50 mb-3">
            Les <strong>Cookies</strong> correspondent aux cookies hotspot RouterOS.
            Cette page n'a pas d'equivalent metier commun pour OPNsense ou FreeRADIUS.
        </p>
        <div class="d-flex justify-content-center gap-2 flex-wrap">
            <a href="/pages/sessions_list.php" class="btn btn-save">
                <i class="fa fa-list me-1"></i> Sessions actives
            </a>
            <a href="/pages/hosts.php" class="btn btn-test">
                <i class="fa fa-laptop me-1"></i> Hosts
            </a>
        </div>
    </div>
<?php elseif ($cookiesError !== null): ?>
    <div class="p-4 text-center text-danger">
        <?= htmlspecialchars($cookiesError) ?>
    </div>
<?php else: ?>
<div class="d-flex justify-content-end align-items-center px-3 pt-3">
    <button type="button" class="btn btn-delete" id="deleteSelectedCookiesBtn" disabled>
        <i class="fa fa-trash me-1"></i> Supprimer
    </button>
</div>
<div class="table-responsive">
<table
    class="table table-striped table-hover table-dark mb-0 align-middle table-standard"
    data-sort-table="1"
    data-default-sort-key="user"
    data-default-sort-direction="asc"
    data-csrf-token="<?= htmlspecialchars((string)$_SESSION['csrf_token'], ENT_QUOTES) ?>"
>
<thead>
<tr>
    <th style="width: 36px;">
        <input type="checkbox" class="form-check-input mt-0" id="selectAllCookies" aria-label="Tout sélectionner">
    </th>
    <th data-sort-key="id" data-sort-type="text">ID</th>
    <th data-sort-key="user" data-sort-type="text">Utilisateur</th>
    <th data-sort-key="mac" data-sort-type="text">MAC</th>
    <th data-sort-key="address" data-sort-type="text">IP</th>
    <th data-sort-key="server" data-sort-type="text">Serveur</th>
    <th data-sort-key="uptime" data-sort-type="duration">Uptime</th>
    <th data-sort-key="expires_in" data-sort-type="duration">Expiration</th>
    <th>Action</th>
</tr>
</thead>
<tbody id="cookiesTableBody">
<?php if (!$cookies): ?>
<tr data-sort-disabled="1">
    <td colspan="9" class="text-center py-4 text-white-50">Aucun cookie present sur le routeur actif.</td>
</tr>
<?php else: ?>
<?php foreach ($cookies as $cookie): ?>
<tr
    id="cookie-row-<?= htmlspecialchars($cookie['id'] !== '' ? $cookie['id'] : md5(json_encode($cookie)), ENT_QUOTES) ?>"
    data-id="<?= htmlspecialchars((string)($cookie['id'] ?? ''), ENT_QUOTES) ?>"
    data-user="<?= htmlspecialchars((string)($cookie['user'] ?? ''), ENT_QUOTES) ?>"
    data-mac="<?= htmlspecialchars((string)($cookie['mac'] ?? ''), ENT_QUOTES) ?>"
    data-address="<?= htmlspecialchars((string)($cookie['address'] ?? ''), ENT_QUOTES) ?>"
    data-server="<?= htmlspecialchars((string)($cookie['server'] ?? ''), ENT_QUOTES) ?>"
    data-uptime="<?= htmlspecialchars((string)($cookie['uptime'] ?? ''), ENT_QUOTES) ?>"
    data-expires_in="<?= htmlspecialchars((string)($cookie['expires_in'] ?? ''), ENT_QUOTES) ?>"
>
    <td>
        <input type="checkbox" class="form-check-input cookie-select" value="<?= htmlspecialchars((string)($cookie['id'] ?? ''), ENT_QUOTES) ?>" aria-label="Sélectionner ce cookie">
    </td>
    <td><?= htmlspecialchars((string)($cookie['id'] !== '' ? $cookie['id'] : '-')) ?></td>
    <td><?= htmlspecialchars((string)(($cookie['user'] ?? '') !== '' ? $cookie['user'] : '-')) ?></td>
    <td><?= htmlspecialchars((string)(($cookie['mac'] ?? '') !== '' ? $cookie['mac'] : '-')) ?></td>
    <td><?= htmlspecialchars((string)(($cookie['address'] ?? '') !== '' ? $cookie['address'] : '-')) ?></td>
    <td><?= htmlspecialchars((string)(($cookie['server'] ?? '') !== '' ? $cookie['server'] : '-')) ?></td>
    <td class="text-end"><?= htmlspecialchars((string)(($cookie['uptime'] ?? '') !== '' ? $cookie['uptime'] : '-')) ?></td>
    <td class="text-end"><?= htmlspecialchars((string)(($cookie['expires_in'] ?? '') !== '' ? $cookie['expires_in'] : '-')) ?></td>
    <td class="action-cell">
        <button
            type="button"
            class="btn btn-delete btn-sm profile-action-btn js-delete-cookie"
            title="Supprimer ce cookie"
            data-cookie-id="<?= htmlspecialchars((string)($cookie['id'] ?? ''), ENT_QUOTES) ?>"
            data-row-id="<?= htmlspecialchars('cookie-row-' . ($cookie['id'] !== '' ? $cookie['id'] : md5(json_encode($cookie))), ENT_QUOTES) ?>"
            <?= (($cookie['id'] ?? '') !== '') ? '' : 'disabled' ?>
        >
            <i class="fas fa-trash"></i>
        </button>
    </td>
</tr>
<?php endforeach; ?>
<?php endif; ?>
</tbody>
</table>
</div>
<?php endif; ?>
</div>
</div>

</div>
</div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="../js/sidebar.js?v=20260402a"></script>
<script src="../js/table_sort.js"></script>
<script src="../js/cookies.js"></script>

</body>
</html>
