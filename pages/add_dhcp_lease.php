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
$isMikrotik = is_array($activeDevice) && (($activeDevice['type'] ?? '') === 'mikrotik');
$leases = [];
$leasesError = null;
$leaseId = trim((string)($_GET['lease_id'] ?? ''));
$isEditMode = $leaseId !== '';
$formTitle = $isEditMode ? 'Modifier Bail DHCP' : 'Ajouter Bail DHCP';
$submitLabel = $isEditMode ? 'Mettre a jour' : 'Enregistrer';
$formData = [
    'address' => trim((string)($_GET['address'] ?? '')),
    'mac' => trim((string)($_GET['mac'] ?? '')),
    'host_name' => trim((string)($_GET['host_name'] ?? '')),
    'server' => trim((string)($_GET['server'] ?? '')),
    'comment' => trim((string)($_GET['comment'] ?? '')),
    'disabled' => trim((string)($_GET['disabled'] ?? '')) === '1',
];

if ($isMikrotik) {
    try {
        $leases = getMikrotikDhcpLeases(200);
    } catch (Throwable $e) {
        $leasesError = trim((string)$e->getMessage());
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<title><?= htmlspecialchars($formTitle) ?></title>

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="../css/theme.css">
<link rel="stylesheet" href="../css/network_devices.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
</head>
<body>

<div class="d-flex" id="wrapper">
<?php include __DIR__ . '/../includes/sidebar.php'; ?>

<div id="page-content-wrapper">
<div class="container-fluid py-3">

<div id="messageArea" style="display:none;"></div>

<h5 class="text-white mb-3">
    <i class="fa fa-network-wired me-2"></i> <?= htmlspecialchars($formTitle) ?>
</h5>

<div class="row">
<div class="col-lg-6 mb-3">
<div class="card shadow-sm">
<div class="card-body">
<div class="d-flex justify-content-between align-items-center mb-3">
    <h6 class="text-white mb-0">
        <i class="fa fa-list me-2"></i> Baux existants
    </h6>
    <a href="dhcp_leases.php" class="btn btn-test">
        <i class="fa fa-arrow-left me-1"></i> Retour
    </a>
</div>

<?php if (!$isMikrotik): ?>
    <div class="p-4 text-center text-white-50">
        Cette page est disponible quand le device actif est de type MikroTik.
    </div>
<?php elseif ($leasesError !== null): ?>
    <div class="p-4 text-center text-danger">
        <?= htmlspecialchars($leasesError) ?>
    </div>
<?php else: ?>
<div class="table-responsive">
<table class="table network-device-table table-hover align-middle small text-nowrap">
    <thead>
        <tr>
            <th>Adresse</th>
            <th>MAC</th>
            <th>Nom d hote</th>
            <th>Serveur</th>
        </tr>
    </thead>
    <tbody>
        <?php if (!$leases): ?>
        <tr>
            <td colspan="4" class="text-center py-4 text-white-50">Aucun bail existant</td>
        </tr>
        <?php else: ?>
        <?php foreach ($leases as $lease): ?>
        <tr>
            <td><?= htmlspecialchars((string)(($lease['address'] ?? '') !== '' ? $lease['address'] : '-')) ?></td>
            <td><?= htmlspecialchars((string)(($lease['mac'] ?? '') !== '' ? $lease['mac'] : '-')) ?></td>
            <td><?= htmlspecialchars((string)(($lease['host_name'] ?? '') !== '' ? $lease['host_name'] : '-')) ?></td>
            <td><?= htmlspecialchars((string)(($lease['server'] ?? '') !== '' ? $lease['server'] : '-')) ?></td>
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

<div class="col-lg-6 mb-3">
<div class="card shadow-sm">
<div class="card-body">

<h6 class="text-white mb-3">
    <i class="fa fa-plus-circle me-2"></i> Nouveau bail
</h6>

<?php if (!$isMikrotik): ?>
    <div class="text-center text-muted mb-3">
        <i class="fa fa-address-card fa-2x mb-2"></i>
        <p>Activez un device MikroTik pour ajouter un bail DHCP.</p>
    </div>
<?php else: ?>
<form id="addDhcpLeaseForm" class="network-device-form" method="POST" autocomplete="off">
<input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string)$_SESSION['csrf_token']) ?>">
<input type="hidden" name="lease_id" value="<?= htmlspecialchars($leaseId) ?>">

<div class="d-flex justify-content-between align-items-center mb-3">
    <h6 class="text-white mt-3 mb-2">
        <i class="fa fa-info-circle me-2"></i> Parametres
    </h6>
    <div class="d-flex gap-2">
        <button type="submit" class="btn btn-save" id="saveBtn">
            <i class="fa fa-save me-1"></i> <?= htmlspecialchars($submitLabel) ?>
        </button>
        <a href="dhcp_leases.php" class="btn btn-test">
            <i class="fa fa-times me-1"></i> Annuler
        </a>
    </div>
</div>

<div class="input-group mb-2">
    <span class="input-group-text">Adresse</span>
    <input type="text" class="form-control" name="address" placeholder="Ex: 192.168.88.20" value="<?= htmlspecialchars($formData['address']) ?>">
</div>

<div class="input-group mb-2">
    <span class="input-group-text">MAC</span>
    <input type="text" class="form-control" name="mac" placeholder="Ex: AA:BB:CC:DD:EE:FF" value="<?= htmlspecialchars($formData['mac']) ?>">
</div>

<div class="input-group mb-2">
    <span class="input-group-text">Nom d hote</span>
    <input type="text" class="form-control" name="host_name" placeholder="Optionnel" value="<?= htmlspecialchars($formData['host_name']) ?>">
</div>

<div class="input-group mb-2">
    <span class="input-group-text">Serveur</span>
    <input type="text" class="form-control" name="server" placeholder="Nom du serveur DHCP" value="<?= htmlspecialchars($formData['server']) ?>">
</div>

<div class="input-group mb-2">
    <span class="input-group-text">Commentaire</span>
    <input type="text" class="form-control" name="comment" placeholder="Commentaire optionnel" value="<?= htmlspecialchars($formData['comment']) ?>">
</div>

<div class="form-check form-switch text-white mt-2">
    <input class="form-check-input" type="checkbox" role="switch" id="disabled" name="disabled" value="1" <?= $formData['disabled'] ? 'checked' : '' ?>>
    <label class="form-check-label" for="disabled">Desactive</label>
</div>
</form>
<?php endif; ?>

</div>
</div>
</div>
</div>

</div>
</div>
</div>

<script src="../js/sidebar.js?v=20260402a"></script>
<script src="../js/add_dhcp_lease.js"></script>
</body>
</html>
