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
require_once '../includes/page_helpers.php';
$csrfToken = ensureCsrfToken();
?>

<?php
$pageTitle = 'Équipements Réseau';
$extraCss = array (
  0 => '../css/network_devices.css',
);
require_once '../includes/layout_header.php';
?>
<input type="hidden" id="pagecsrfToken" value="<?= htmlspecialchars($csrfToken) ?>">

<div class="card shadow-sm mb-3">
    <div class="card-body py-3">
        <div class="d-flex align-items-center justify-content-between text-white" style="font-size: calc(0.875rem + 2px);">
            <div class="d-flex align-items-center">
                <i class="fa fa-network-wired me-2"></i>
                <span class="small fw-semibold">Équipements Réseau</span>
            </div>
            <div class="d-flex align-items-center gap-2">
                <a href="../tools/license_manager.php" target="_blank" class="btn btn-sm btn-save">
                    <i class="fa fa-key me-1"></i> Générateur licence
                </a>
                <span class="badge bg-info text-dark">
                    <i class="fa fa-shield-halved me-1"></i> Licences via agent Windows
                </span>
            </div>
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

    <div id="testStatus" class="p-3 bg-dark rounded border border-white-10 small font-monospace" style="min-height: 80px; white-space: pre-wrap; word-break: break-word;">
        <span class="text-white-50">Aucun test effectué</span>
    </div>
</div>

</div>

</div>
</div>
</div>

</div>

<!-- ═══ MODAL ACTIVATION LICENCE ═══ -->
<div class="modal fade" id="licenceModal" tabindex="-1" aria-labelledby="licenceModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" style="max-width:500px;">
        <div class="modal-content" style="background:#0b0f1a;border:1px solid #1e2a3a;border-radius:12px;">

            <!-- EN-TÊTE -->
            <div class="modal-header border-0 pb-2" style="background:linear-gradient(135deg,#1e3a5f,#0f2a4a);border-radius:11px 11px 0 0;">
                <div class="d-flex align-items-center gap-2 flex-grow-1">
                    <i class="fa fa-key" style="color:#fbbf24;"></i>
                    <div>
                        <h6 class="modal-title mb-0 text-white" id="licenceModalLabel">Activation de licence</h6>
                        <small style="color:#94a3b8;" id="licModalDeviceName">—</small>
                    </div>
                    <span class="badge bg-warning text-dark ms-auto" id="licModalBadge">Sans licence</span>
                </div>
                <button type="button" class="btn-close btn-close-white ms-2" data-bs-dismiss="modal" aria-label="Fermer"></button>
            </div>

            <div class="modal-body" style="padding:1.25rem;">

                <!-- ① INFOS ROUTEUR -->
                <div class="mb-3">
                    <div class="small fw-semibold mb-2" style="color:#fbbf24;">
                        <i class="fa fa-circle-info me-1"></i> Informations de votre routeur
                    </div>
                    <div style="background:#0f172a;border:1px solid #1e2a3a;border-radius:8px;padding:.75rem;">
                        <div class="d-flex align-items-center justify-content-between mb-1">
                            <span style="color:#94a3b8;font-size:.82rem;">Device ID</span>
                            <div class="d-flex align-items-center gap-2">
                                <code id="licModalDeviceId" style="color:#fbbf24;font-size:.82rem;letter-spacing:.06em;">—</code>
                                <button id="licModalCopyId" class="btn btn-sm py-0 px-2"
                                    style="font-size:.7rem;background:#1e2a3a;color:#94a3b8;border:1px solid #334155;">
                                    <i class="fa fa-copy"></i>
                                </button>
                            </div>
                        </div>
                        <div id="licModalSnRow" class="d-flex justify-content-between d-none">
                            <span style="color:#94a3b8;font-size:.82rem;">N° Série</span>
                            <code id="licModalSn" style="color:#e2e8f0;font-size:.82rem;">—</code>
                        </div>
                        <div id="licModalTypeRow" class="d-flex justify-content-between d-none mt-1">
                            <span style="color:#94a3b8;font-size:.82rem;">Type</span>
                            <span id="licModalType" style="color:#38bdf8;font-weight:600;font-size:.82rem;">—</span>
                        </div>
                        <div id="licModalModelRow" class="d-flex justify-content-between d-none mt-1">
                            <span style="color:#94a3b8;font-size:.82rem;">Modèle</span>
                            <span id="licModalModel" style="color:#e2e8f0;font-size:.82rem;">—</span>
                        </div>
                    </div>
                </div>

                <!-- ② CONTACTER -->
                <div class="mb-3">
                    <div class="small fw-semibold mb-2" style="color:#38bdf8;">
                        <i class="fa fa-paper-plane me-1"></i> Contacter l'administrateur
                    </div>
                    <input type="text" id="licModalClientName" class="form-control form-control-sm mb-2"
                        placeholder="Votre nom / société (optionnel)"
                        style="background:#0f172a;border-color:#1e2a3a;color:#e2e8f0;">
                    <div class="d-flex gap-2" id="licModalContactBtns">
                        <button id="licModalWaBtn" class="btn btn-sm flex-fill d-none"
                            style="background:#25D366;color:#fff;">
                            <i class="fab fa-whatsapp me-1"></i> WhatsApp
                        </button>
                        <button id="licModalEmailBtn" class="btn btn-sm btn-test flex-fill d-none">
                            <i class="fa fa-envelope me-1"></i> Email
                        </button>
                        <button id="licModalCopyMsgBtn" class="btn btn-sm flex-fill"
                            style="background:#1e2a3a;color:#cbd5e1;border:1px solid #334155;">
                            <i class="fa fa-clipboard me-1"></i> Copier message
                        </button>
                    </div>
                </div>

                <!-- ③ CLÉ DE LICENCE -->
                <div style="border-top:1px solid #1e2a3a;padding-top:1rem;">
                    <div class="small fw-semibold mb-2" style="color:#4ade80;">
                        <i class="fa fa-check-circle me-1"></i> Coller la clé reçue
                    </div>
                    <input type="text" id="licModalKeyInput" class="form-control mb-2"
                        placeholder="LIC-XXXX-XXXX-XXXX..."
                        style="font-family:monospace;font-size:.85rem;background:#0f172a;border-color:#1e2a3a;color:#fbbf24;">
                    <button class="btn btn-save w-100" id="licModalActivateBtn">
                        <i class="fa fa-check-circle me-2"></i> Activer la licence
                    </button>
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
