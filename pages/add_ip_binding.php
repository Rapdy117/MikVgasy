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
$selectedBindingId = trim((string)($_GET['binding_id'] ?? ''));
$selectedZoneUuid = trim((string)($_GET['zone_uuid'] ?? ''));
$selectedBindingValue = trim((string)($_GET['binding_value'] ?? ($_GET['binding_mac'] ?? '')));
$selectedBindingKind = trim((string)($_GET['binding_kind'] ?? detectOpnsenseBindingKind($selectedBindingValue)));
$selectedBinding = null;
$opnsenseZones = [];

if ($isMikrotik) {
    try {
        $bindings = getMikrotikIpBindings(200);
        if ($selectedBindingId !== '') {
            foreach ($bindings as $binding) {
                if ((string)($binding['id'] ?? '') === $selectedBindingId) {
                    $selectedBinding = $binding;
                    break;
                }
            }
        }
    } catch (Throwable $e) {
        $bindingsError = trim((string)$e->getMessage());
    }
} elseif ($isOpnsense && is_array($activeDevice)) {
    try {
        $bindings = listOpnsenseIpBindings($activeDevice);
        $opnsenseZones = listOpnsenseCaptivePortalZones($activeDevice);
        if ($selectedZoneUuid !== '' && $selectedBindingValue !== '') {
            foreach ($bindings as $binding) {
                if ((string)($binding['zone_uuid'] ?? '') === $selectedZoneUuid
                    && (string)($binding['binding_kind'] ?? '') === $selectedBindingKind
                    && (string)($binding['binding_value'] ?? '') === ($selectedBindingKind === 'mac'
                        ? normalizeMacAddress($selectedBindingValue)
                        : normalizeOpnsenseAllowedAddress($selectedBindingValue))) {
                    $selectedBinding = $binding;
                    break;
                }
            }
        }
    } catch (Throwable $e) {
        $bindingsError = trim((string)$e->getMessage());
    }
}
?>
<?php
$pageTitle = $selectedBinding ? 'Modifier IP Binding' : 'Ajouter IP Binding';
$extraCss = array (
  0 => '../css/network_devices.css',
);
require_once __DIR__ . '/../includes/layout_header.php';
?>

<div class="card shadow-sm mb-3">
    <div class="card-body py-3">
        <div class="d-flex align-items-center text-white" style="font-size: calc(0.875rem + 2px);">
            <i class="fa fa-address-book me-2"></i>
            <span class="small fw-semibold"><?= $selectedBinding ? 'Modifier IP Binding' : 'Ajouter IP Binding' ?></span>
        </div>
    </div>
</div>

<div class="row">
<div class="col-lg-6 mb-3">
<div class="card shadow-sm h-100">
<div class="card-header standard-card-header">
    <i class="fa fa-list me-2"></i> Bindings existants
</div>
<div class="card-body">

<div class="d-flex justify-content-end mb-3">
    <a href="ip_bindings.php" class="btn btn-test">
        <i class="fa fa-arrow-left me-1"></i> Retour
    </a>
</div>

<?php if (!$isMikrotik && !$isOpnsense): ?>
    <div class="p-5 text-center text-white-50 opacity-50">
        <i class="fa fa-info-circle fa-2x mb-3"></i>
        <p class="mb-0">Cette page est disponible avec un device actif MikroTik ou OPNsense.</p>
    </div>
<?php elseif ($bindingsError !== null): ?>
    <div class="p-5 text-center text-danger">
        <i class="fa fa-exclamation-triangle fa-2x mb-3"></i>
        <p class="mb-0"><?= htmlspecialchars($bindingsError) ?></p>
    </div>
<?php else: ?>
<div class="table-responsive">
<table class="table table-dark table-hover table-striped mb-0 table-standard">
    <thead>
        <tr>
            <th>Adresse</th>
            <th>MAC</th>
            <th>Type</th>
            <th>Adresse To</th>
            <th>Serveur</th>
            <th>Commentaire</th>
        </tr>
    </thead>
    <tbody>
        <?php if (!$bindings): ?>
        <tr>
            <td colspan="6" class="text-center py-4 text-white-50 opacity-50">Aucun binding existant</td>
        </tr>
        <?php else: ?>
        <?php foreach ($bindings as $binding): ?>
        <tr>
            <td><?= htmlspecialchars((string)(($binding['address'] ?? '') !== '' ? $binding['address'] : '-')) ?></td>
            <td><?= htmlspecialchars((string)(($binding['mac'] ?? '') !== '' ? $binding['mac'] : '-')) ?></td>
            <td><?= htmlspecialchars((string)(($binding['type'] ?? '') !== '' ? $binding['type'] : '-')) ?></td>
            <td><?= htmlspecialchars((string)(($binding['to_address'] ?? '') !== '' ? $binding['to_address'] : '-')) ?></td>
            <td><?= htmlspecialchars((string)(($binding['server'] ?? '') !== '' ? $binding['server'] : '-')) ?></td>
            <td><?= htmlspecialchars((string)(($binding['comment'] ?? '') !== '' ? $binding['comment'] : '-')) ?></td>
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
<div class="card shadow-sm h-100">
<div class="card-header standard-card-header">
    <i class="fa fa-plus-circle me-2"></i> <?= $selectedBinding ? 'Édition du binding' : 'Nouveau binding' ?>
</div>
<div class="card-body">

<?php if (!$isMikrotik && !$isOpnsense): ?>
    <div class="text-center text-white-50 py-5 opacity-50">
        <i class="fa fa-address-card fa-3x mb-3"></i>
        <p>Activez un device MikroTik ou OPNsense pour ajouter un binding.</p>
    </div>
<?php else: ?>
<form id="addIpBindingForm" class="network-device-form" method="POST" autocomplete="off">
<input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string)$_SESSION['csrf_token']) ?>">
<input type="hidden" name="binding_id" value="<?= htmlspecialchars((string)($selectedBinding['id'] ?? '')) ?>">
<input type="hidden" name="backend_type" value="<?= htmlspecialchars($activeType) ?>">
<?php if ($isOpnsense): ?>
<input type="hidden" name="original_zone_uuid" value="<?= htmlspecialchars((string)($selectedBinding['zone_uuid'] ?? ''), ENT_QUOTES) ?>">
<input type="hidden" name="original_value" value="<?= htmlspecialchars((string)($selectedBinding['binding_value'] ?? ''), ENT_QUOTES) ?>">
<input type="hidden" name="original_kind" value="<?= htmlspecialchars((string)($selectedBinding['binding_kind'] ?? ''), ENT_QUOTES) ?>">
<?php endif; ?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h6 class="text-white small mb-0">
        <i class="fa fa-info-circle me-2"></i> Paramètres
    </h6>

    <div class="d-flex gap-2">
        <button type="submit" class="btn btn-save" id="saveBtn">
            <i class="fa fa-save me-1"></i> Enregistrer
        </button>

        <?php if ($selectedBinding): ?>
        <button type="button" class="btn btn-delete" id="deleteBtn">
            <i class="fa fa-trash me-1"></i> Supprimer
        </button>
        <?php endif; ?>

        <a href="ip_bindings.php" class="btn btn-test" id="cancelBtn">
            <i class="fa fa-times me-1"></i> Annuler
        </a>
    </div>
</div>

<?php if ($isMikrotik): ?>
<div class="input-group mb-2">
    <span class="input-group-text">MAC</span>
    <input type="text" class="form-control" name="mac" placeholder="Ex: AA:BB:CC:DD:EE:FF" value="<?= htmlspecialchars((string)($selectedBinding['mac'] ?? '')) ?>">
</div>

<div class="input-group mb-2">
    <span class="input-group-text">Adresse</span>
    <input type="text" class="form-control" name="address" placeholder="Ex: 192.168.88.10" value="<?= htmlspecialchars((string)($selectedBinding['address'] ?? '')) ?>">
</div>

<div class="input-group mb-2">
    <span class="input-group-text">To Address</span>
    <input type="text" class="form-control" name="to_address" placeholder="Optionnel" value="<?= htmlspecialchars((string)($selectedBinding['to_address'] ?? '')) ?>">
</div>

<div class="input-group mb-2">
    <span class="input-group-text">Type</span>
    <select class="form-select" name="type" required>
        <option value="regular" <?= (($selectedBinding['type'] ?? 'regular') === 'regular') ? 'selected' : '' ?>>regular</option>
        <option value="bypassed" <?= (($selectedBinding['type'] ?? '') === 'bypassed') ? 'selected' : '' ?>>bypassed</option>
        <option value="blocked" <?= (($selectedBinding['type'] ?? '') === 'blocked') ? 'selected' : '' ?>>blocked</option>
    </select>
</div>

<div class="input-group mb-2">
    <span class="input-group-text">Serveur</span>
    <input type="text" class="form-control" name="server" placeholder="all ou nom du serveur" value="<?= htmlspecialchars((string)($selectedBinding['server'] ?? '')) ?>">
</div>

<div class="input-group mb-2">
    <span class="input-group-text">Commentaire</span>
    <input type="text" class="form-control" name="comment" placeholder="Commentaire optionnel" value="<?= htmlspecialchars((string)($selectedBinding['comment'] ?? '')) ?>">
</div>

<div class="form-check form-switch text-white mt-3">
    <input class="form-check-input" type="checkbox" role="switch" id="disabled" name="disabled" value="1" <?= !empty($selectedBinding['disabled']) ? 'checked' : '' ?>>
    <label class="form-check-label" for="disabled">Désactivé</label>
</div>
<?php elseif ($isOpnsense): ?>
<div class="input-group mb-2">
    <span class="input-group-text">Adresse / MAC</span>
    <input
        type="text"
        class="form-control"
        name="binding_value"
        placeholder="Ex: 10.10.10.25, 10.10.10.0/24 ou BA:F5:3E:B0:F1:BB"
        value="<?= htmlspecialchars((string)($selectedBinding['binding_value'] ?? '')) ?>"
        required
    >
</div>

<div class="input-group mb-2">
    <span class="input-group-text">Type</span>
    <select class="form-select" name="type" required>
        <option value="bypassed" selected>Bypass portail</option>
    </select>
</div>

<div class="input-group mb-3">
    <span class="input-group-text">Zone</span>
    <select class="form-select" name="zone_uuid" required>
        <option value="">Choisir une zone</option>
        <?php foreach ($opnsenseZones as $zone): ?>
        <option value="<?= htmlspecialchars((string)$zone['uuid'], ENT_QUOTES) ?>" <?= (($selectedBinding['zone_uuid'] ?? '') === $zone['uuid']) ? 'selected' : '' ?>>
            <?= htmlspecialchars((string)$zone['description']) ?>
        </option>
        <?php endforeach; ?>
    </select>
</div>

<div class="small text-white-50 mt-3 pt-3 border-top border-white-10">
    <i class="fa fa-info-circle me-1"></i> Pour OPNsense, ce module pilote les adresses IP, réseaux et MAC en bypass portail dans la zone captive.
</div>
<?php endif; ?>
</form>

<?php endif; ?>

</div>
</div>
</div>
</div>

<?php
$extraScript = "
window.ipBindingEditorConfig = {
    mode: " . json_encode($selectedBinding ? 'edit' : 'create') . ",
    device_type: " . json_encode($activeType) . "
};
";
$extraJs = array (
  0 => '../js/add_ip_binding.js?v=20260330c',
);
require_once __DIR__ . '/../includes/layout_footer.php';
?>
