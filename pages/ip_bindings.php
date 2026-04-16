<?php
session_start();

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/device_manager.php';
require_once __DIR__ . '/../includes/mikrotik_backend.php';
require_once __DIR__ . '/../includes/opnsense_ip_bindings.php';

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
$isOpnsense = $activeType === 'opnsense';
$bindings = [];
$bindingsError = null;

if ($isMikrotik) {
    try {
        $bindings = getMikrotikIpBindings(300);
    } catch (Throwable $e) {
        $bindingsError = trim((string)$e->getMessage());
    }
} elseif ($isOpnsense && is_array($activeDevice)) {
    try {
        $bindings = listOpnsenseIpBindings($activeDevice);
    } catch (Throwable $e) {
        $bindingsError = trim((string)$e->getMessage());
    }
}

$ipBindingsTableView = ($isMikrotik || $isOpnsense) && $bindingsError === null;
?>
<!DOCTYPE html>
<html lang="fr" class="ip-bindings-page<?= $ipBindingsTableView ? ' ip-bindings-page--table' : '' ?>">
<head>
<meta charset="UTF-8">
<title>IP Bindings</title>

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Roboto:wght@300;400;500;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="../css/theme.css">
<link rel="stylesheet" href="../css/users_list.css">
<link rel="stylesheet" href="../css/ip_bindings.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
</head>
<body class="ip-bindings-page<?= $ipBindingsTableView ? ' ip-bindings-page--table' : '' ?>">

<div class="d-flex" id="wrapper">
<?php include_once __DIR__ . '/../includes/sidebar.php'; ?>

<div id="page-content-wrapper">
<div class="container-fluid py-3">
<div class="card shadow-sm">
<div class="card-body">
<div class="d-flex flex-wrap align-items-center gap-3 ip-bindings-page-header mb-3">
    <h5 class="text-white mb-0">
        <i class="fa fa-list me-2"></i> Liste des bindings
    </h5>
    <div class="ip-bindings-toolbar ms-auto">
        <?php if ($isMikrotik || $isOpnsense): ?>
        <div class="input-group users-search-group ip-bindings-search-group mb-0">
            <span class="input-group-text">
                <i class="fa fa-search"></i>
            </span>
            <input
                type="search"
                class="form-control"
                id="bindingsSearchInput"
                placeholder="Rechercher une adresse, un MAC, un type, un serveur ou un commentaire"
                autocomplete="off"
            >
        </div>
        <a href="add_ip_binding.php" class="btn btn-save flex-shrink-0">
            <i class="fa fa-plus me-1"></i> Ajouter
        </a>
        <?php endif; ?>
    </div>
</div>

<?php if (!$isMikrotik && !$isOpnsense): ?>
    <div class="p-4 text-center text-white-50">
        Cette page affiche les bindings MikroTik ou les bypass portail OPNsense selon le device actif.
    </div>
<?php elseif ($bindingsError !== null): ?>
    <div class="p-4 text-center text-danger">
        <?= htmlspecialchars($bindingsError) ?>
    </div>
<?php else: ?>
<div class="table-responsive ip-bindings-table-scroll">
<table class="table table-striped table-hover table-dark mb-0 align-middle users-table bindings-table small table-standard" data-sort-table="1" data-default-sort-key="address" data-default-sort-direction="asc">
<thead>
<tr>
    <th class="bindings-col-left bindings-col-address" data-sort-key="address" data-sort-type="text">Adresse</th>
    <th class="bindings-col-left" data-sort-key="mac" data-sort-type="text">MAC</th>
    <th class="bindings-col-left" data-sort-key="type" data-sort-type="text">Type</th>
    <th class="bindings-col-left" data-sort-key="to_address" data-sort-type="text">Adresse To</th>
    <th class="bindings-col-left" data-sort-key="server" data-sort-type="text">Serveur</th>
    <th class="bindings-col-left" data-sort-key="comment" data-sort-type="text">Commentaire</th>
    <th data-sort-key="status" data-sort-type="text">Statut</th>
    <th>Action</th>
</tr>
</thead>
<tbody>
<?php if (!$bindings): ?>
<tr data-sort-disabled="1">
    <td colspan="8" class="text-center py-4 text-white-50">Aucun IP binding present sur le routeur actif.</td>
</tr>
<?php else: ?>
<?php foreach ($bindings as $binding): ?>
<?php
$editHref = 'add_ip_binding.php?binding_id=' . rawurlencode((string)($binding['id'] ?? ''));
if ($isOpnsense) {
    $editHref = 'add_ip_binding.php?zone_uuid=' . rawurlencode((string)($binding['zone_uuid'] ?? ''))
        . '&binding_kind=' . rawurlencode((string)($binding['binding_kind'] ?? ''))
        . '&binding_value=' . rawurlencode((string)($binding['binding_value'] ?? ''));
}
?>
<tr
    class="binding-row"
    data-edit-href="<?= htmlspecialchars($editHref, ENT_QUOTES) ?>"
    data-id="<?= htmlspecialchars((string)($binding['id'] ?? ''), ENT_QUOTES) ?>"
    data-address="<?= htmlspecialchars((string)($binding['address'] ?? ''), ENT_QUOTES) ?>"
    data-mac="<?= htmlspecialchars((string)($binding['mac'] ?? ''), ENT_QUOTES) ?>"
    data-type="<?= htmlspecialchars((string)($binding['type'] ?? ''), ENT_QUOTES) ?>"
    data-to_address="<?= htmlspecialchars((string)($binding['to_address'] ?? ''), ENT_QUOTES) ?>"
    data-server="<?= htmlspecialchars((string)($binding['server'] ?? ''), ENT_QUOTES) ?>"
    data-comment="<?= htmlspecialchars((string)($binding['comment'] ?? ''), ENT_QUOTES) ?>"
    data-status="<?= htmlspecialchars((string)($binding['status'] ?? (!empty($binding['disabled']) ? 'Desactive' : 'Actif')), ENT_QUOTES) ?>"
>
    <td class="bindings-col-left bindings-col-address"><?= htmlspecialchars((string)(($binding['address'] ?? '') !== '' ? $binding['address'] : '-')) ?></td>
    <td class="bindings-col-left"><?= htmlspecialchars((string)(($binding['mac'] ?? '') !== '' ? $binding['mac'] : '-')) ?></td>
    <td class="bindings-col-left"><?= htmlspecialchars((string)(($binding['type'] ?? '') !== '' ? $binding['type'] : '-')) ?></td>
    <td class="bindings-col-left"><?= htmlspecialchars((string)(($binding['to_address'] ?? '') !== '' ? $binding['to_address'] : '-')) ?></td>
    <td class="bindings-col-left"><?= htmlspecialchars((string)(($binding['server'] ?? '') !== '' ? $binding['server'] : '-')) ?></td>
    <td class="bindings-col-left bindings-comment-cell" title="<?= htmlspecialchars((string)(($binding['comment'] ?? '') !== '' ? $binding['comment'] : '-')) ?>"><?= htmlspecialchars((string)(($binding['comment'] ?? '') !== '' ? $binding['comment'] : '-')) ?></td>
    <td class="bindings-status-cell"><?= htmlspecialchars((string)($binding['status'] ?? (!empty($binding['disabled']) ? 'Desactive' : 'Actif'))) ?></td>
    <td class="action-cell">
        <a href="<?= htmlspecialchars($editHref) ?>" class="btn btn-test btn-sm profile-action-btn" title="Modifier ce binding">
            <i class="fas fa-pen"></i>
        </a>
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
<script src="../js/ip_bindings_list.js?v=20260330b"></script>
</body>
</html>
