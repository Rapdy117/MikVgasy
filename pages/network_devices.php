<?php
session_start();
require_once '../includes/auth.php';

/* =========================
   SECURITY
========================= */
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: ../index.php');
    exit();
}
requireAdministratorAccess();
?>

<?php
$pageTitle = 'Équipements Réseau';
$extraCss = array (
  0 => '../css/network_devices.css',
);
require_once '../includes/layout_header.php';
?>

<div class="card shadow-sm mb-3">
    <div class="card-body py-3">
        <div class="d-flex align-items-center text-white" style="font-size: calc(0.875rem + 2px);">
            <i class="fa fa-network-wired me-2"></i>
            <span class="small fw-semibold">Équipements Réseau</span>
        </div>
    </div>
</div>

<div class="row">

<!-- =========================
     LEFT: TABLE
========================= -->
<div class="col-lg-6 mb-3">
<div class="card shadow-sm h-100">
<div class="card-header standard-card-header">
    <i class="fa fa-list me-2"></i> Équipements
</div>
<div class="card-body">

<div class="d-flex justify-content-end mb-3">
    <button type="button" id="newDeviceBtn" class="btn btn-save">
        <i class="fa fa-plus me-1"></i> Nouveau
    </button>
</div>

<div class="table-responsive">
<table class="table table-dark table-hover table-striped mb-0 network-device-table table-standard" data-sort-table="1">
    <thead>
        <tr>
            <th>Nom</th>
            <th>Host</th>
            <th>Type</th>
            <th>Statut</th>
        </tr>
    </thead>
    <tbody id="deviceTableBody"></tbody>
</table>
</div>

<div class="mt-3">
    <h6 class="text-white small mb-2">
        <i class="fa fa-circle-info me-2 backend-status-icon backend-status-offline" id="backendStatusIcon"></i> Backend actif
    </h6>

    <div id="deviceBackendStatus" class="small">
        <span class="text-white-50">Chargement...</span>
    </div>
</div>

</div>
</div>
</div>

<!-- =========================
     RIGHT: DETAILS
========================= -->
<div class="col-lg-6 mb-3">
<div class="card shadow-sm h-100">
<div class="card-header standard-card-header">
    <i class="fa fa-server me-2"></i> Détails de l'équipement
</div>
<div class="card-body">

<div class="text-center text-white-50 py-5" id="deviceEmptyState">
    <i class="fa fa-network-wired fa-3x mb-3 opacity-25"></i>
    <p class="mb-0">Sélectionnez un équipement ou créez-en un nouveau</p>
</div>

<div id="deviceContent" class="d-none">

<form id="networkDeviceForm" class="network-device-form" method="POST" autocomplete="off">

<input type="hidden" name="id">

<div class="d-flex justify-content-between align-items-center mb-3">
    <h6 class="text-white small mb-0">
        <i class="fa fa-info-circle me-2"></i> Connexion
    </h6>

    <div class="d-flex gap-2">
        <button type="button" class="btn btn-test" id="editBtn">
            <i class="fa fa-edit me-1"></i> Modifier
        </button>

        <button type="submit" class="btn btn-save d-none" id="saveBtn">
            <i class="fa fa-save me-1"></i> Sauvegarder
        </button>

        <button type="button" class="btn btn-test d-none" id="cancelBtn">
            <i class="fa fa-times me-1"></i> Annuler
        </button>

        <button type="button" class="btn btn-delete d-none" id="deleteBtn">
            <i class="fa fa-trash me-1"></i> Supprimer
        </button>

        <button type="button" id="activateDeviceBtn" class="btn btn-save">
            <i class="fa fa-thumb-tack me-1"></i> Activer
        </button>

        <button type="button" id="testDevice" class="btn btn-test">
            <i class="fa fa-plug me-1"></i> Tester
        </button>
    </div>
</div>

<input type="hidden" name="is_active" id="isActiveSelect" value="0">

<div class="input-group mb-2">
    <span class="input-group-text">Nom</span>
    <input type="text" class="form-control"
           data-device-field="1"
           name="device_name"
           disabled
           placeholder="Ex: Routeur-1">
</div>

<div class="input-group mb-2">
    <span class="input-group-text">Type</span>
    <select class="form-select" name="type" data-device-field="1" disabled>
        <option value="opnsense">OPNsense</option>
        <option value="mikrotik">MikroTik</option>
        <option value="radius">RADIUS</option>
    </select>
</div>

<div class="input-group mb-2">
    <span class="input-group-text">IP / Host</span>
    <input type="text" class="form-control"
           data-device-field="1"
           name="host"
           disabled
           placeholder="10.10.10.1">
</div>

<div class="input-group mb-2">
    <span class="input-group-text">Login API</span>
    <input type="text" class="form-control"
           data-device-field="1"
           disabled
           name="api_key">
</div>

<div class="input-group mb-2">
    <span class="input-group-text">Mot de passe API</span>
    <input type="password" class="form-control"
           data-device-field="1"
           disabled
           name="api_secret">
</div>

<div class="input-group mb-2">
    <span class="input-group-text">Vérifier SSL</span>
    <select class="form-select" name="verify_ssl" data-device-field="1" disabled>
        <option value="false">Non</option>
        <option value="true">Oui</option>
    </select>
</div>

</form>

<div class="mt-4 pt-2 border-top border-white-10">
    <h6 class="text-white small mb-3">
        <i class="fa fa-terminal me-2"></i> Journal du test
    </h6>

    <div id="testStatus" class="p-3 bg-dark rounded border border-white-10 small font-monospace" style="min-height: 80px;">
        <span class="text-white-50">Aucun test effectué</span>
    </div>
</div>

</div>

</div>
</div>
</div>

</div>

<?php
$extraJs = array (
  0 => '../js/network_device.js',
);
require_once '../includes/layout_footer.php';
?>
