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
<div class="col-lg-8 order-lg-1 mb-3">
<div class="card shadow-sm h-100">
<div class="card-header standard-card-header">
    <i class="fa fa-list me-2"></i> Bindings existants
</div>
<div class="card-body">

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
        <?php
        if ($isMikrotik) {
            $editHref = 'add_ip_binding.php?binding_id=' . urlencode((string)($binding['id'] ?? ''));
        } else {
            $editHref = 'add_ip_binding.php?zone_uuid=' . urlencode((string)($binding['zone_uuid'] ?? ''))
                . '&binding_value=' . urlencode((string)($binding['binding_value'] ?? ''))
                . '&binding_kind=' . urlencode((string)($binding['binding_kind'] ?? ''));
        }
        ?>
        <tr style="cursor:pointer" onclick="window.location.href='<?= htmlspecialchars($editHref, ENT_QUOTES) ?>'">
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

<div class="col-lg-4 order-lg-2 mb-3">
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

<div class="d-flex justify-content-end align-items-center mb-4">
    <div class="d-flex gap-2">
        <a href="add_ip_binding.php" class="btn btn-test" id="newBindingBtn">
            <i class="fa fa-plus me-1"></i> Nouveau
        </a>
        <button type="submit" class="btn btn-save" id="saveBtn">
            <i class="fa fa-save me-1"></i> Enregistrer
        </button>

        <?php if ($selectedBinding): ?>
        <button type="button" class="btn btn-delete" id="deleteBtn">
            <i class="fa fa-trash me-1"></i> Supprimer
        </button>
        <?php endif; ?>

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

<?php if ($isMikrotik): ?>
<?php if ($selectedBinding): ?>
<div class="modal fade" id="deleteBindingModal" tabindex="-1" aria-labelledby="deleteBindingModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content text-white" style="background:#0b0f1a;border:1px solid #1e2a3a">
            <div class="modal-header border-0">
                <h5 class="modal-title text-danger" id="deleteBindingModalLabel"><i class="fa fa-trash me-2"></i>Confirmer la suppression</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Fermer"></button>
            </div>
            <div class="modal-body">
                <p>Voulez-vous supprimer cet IP binding ?</p>
                <dl class="row small mb-3">
                    <dt class="col-4">Adresse</dt><dd class="col-8" id="deleteBindingAddress">-</dd>
                    <dt class="col-4">MAC</dt><dd class="col-8" id="deleteBindingMac">-</dd>
                    <dt class="col-4">Commentaire</dt><dd class="col-8" id="deleteBindingComment">-</dd>
                </dl>
                <div class="form-check form-switch">
                    <input class="form-check-input" type="checkbox" role="switch" id="deleteMatchingDhcpLease" name="delete_dhcp_lease" value="1">
                    <label class="form-check-label" for="deleteMatchingDhcpLease">Supprimer aussi le lease DHCP fixe correspondant</label>
                </div>
                <div id="deleteBindingMessage" class="small mt-3" role="status"></div>
            </div>
            <div class="modal-footer border-0">
                <button type="button" class="btn btn-test" data-bs-dismiss="modal">Annuler</button>
                <button type="button" class="btn btn-delete" id="confirmDeleteBindingBtn">Supprimer</button>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>
<div class="modal fade" id="dhcpLeaseSyncModal" tabindex="-1" aria-labelledby="dhcpLeaseSyncModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content text-white" style="background:#0b0f1a;border:1px solid #1e2a3a">
            <div class="modal-header border-0">
                <h5 class="modal-title" id="dhcpLeaseSyncModalLabel"><i class="fa fa-network-wired me-2"></i>Synchroniser le matériel</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Fermer"></button>
            </div>
            <form id="dhcpLeaseSyncForm" class="network-device-form">
                <div class="modal-body">
                    <p class="text-white-50 small">Créer ou mettre à jour un lease DHCP fixe pour ce matériel MikroTik ?</p>
                    <div class="input-group mb-2"><span class="input-group-text">Adresse</span><input class="form-control" id="dhcpSyncAddress" name="address" required></div>
                    <div class="input-group mb-2"><span class="input-group-text">MAC</span><input class="form-control" id="dhcpSyncMac" name="mac" required></div>
                    <div class="input-group mb-2"><span class="input-group-text">Nom</span><input class="form-control" name="host_name"></div>
                    <div class="input-group mb-2"><span class="input-group-text">Serveur</span><input class="form-control" name="server"></div>
                    <div class="input-group"><span class="input-group-text">Commentaire</span><input class="form-control" name="comment"></div>
                    <div class="form-check form-switch text-white mt-3">
                        <input class="form-check-input" type="checkbox" role="switch" id="dhcpSyncDisabled" name="disabled" value="1">
                        <label class="form-check-label" for="dhcpSyncDisabled">Lease DHCP désactivé</label>
                    </div>
                    <div id="dhcpLeaseSyncMessage" class="small mt-3" role="status"></div>
                </div>
                <div class="modal-footer border-0">
                    <button type="button" class="btn btn-test" id="skipDhcpSyncBtn" data-bs-dismiss="modal">Ignorer</button>
                    <button type="submit" class="btn btn-save" id="syncDhcpLeaseBtn"><i class="fa fa-arrows-rotate me-1"></i>Synchroniser</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

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
