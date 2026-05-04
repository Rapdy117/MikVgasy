<?php
session_start();
require_once '../includes/message.php';
require_once '../includes/auth.php';
require_once '../includes/app_context.php';
require_once '../includes/page_helpers.php';

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: ../index.php');
    exit();
}
requireCurrentPageAccess();

$csrfToken = ensureCsrfToken();

$context = ['app' => buildAppContext()];

$activeDeviceType = strtolower(trim((string)($context['app']['device']['type'] ?? 'other')));
$activeDeviceId = (string)($context['app']['device']['id'] ?? '');
?>

<?php
$pageTitle = 'Recharge';
require_once '../includes/layout_header.php';
?>
<style>
.user-recharge-wrapper {
        min-height: calc(100vh - var(--navbar-height) - 11px);
    }

    .user-recharge-container {
        padding-top: 5px !important;
        padding-bottom: 5px !important;
    }

    .user-recharge-page-title {
        font-size: calc(0.875rem + 2px);
    }

    .user-recharge-guide {
        font-size: 12px;
    }

    .user-recharge-guide h6 {
        color: var(--theme-primary);
    }

    .recharge-preview-grid {
        display: grid;
        grid-template-columns: repeat(3, minmax(0, 1fr));
        column-gap: 14px;
        row-gap: 6px;
    }

    .recharge-preview-items {
        display: flex;
        flex-direction: column;
        gap: 6px;
    }

    .recharge-preview-item {
        display: flex;
        align-items: stretch;
        justify-content: space-between;
        gap: 8px;
        padding: 4px 8px;
        border-radius: 6px;
        background: rgba(22, 32, 51, 0.7);
        border: 1px solid rgba(148, 163, 184, 0.14);
        min-height: 32px;
    }

    .recharge-preview-label {
        color: var(--theme-text-muted);
        font-weight: 600;
        font-size: 12px;
        display: flex;
        align-items: center;
        flex: 0 0 auto;
    }

    .recharge-preview-value {
        color: var(--theme-text);
        font-weight: 600;
        font-size: 12px;
        text-align: right;
        margin-left: auto;
        min-width: 0;
        overflow-wrap: anywhere;
        white-space: normal;
        display: flex;
        align-items: center;
        justify-content: flex-end;
    }

    .recharge-state-message {
        border-radius: 8px;
        padding: 10px 12px;
        border: 1px solid rgba(148, 163, 184, 0.18);
        background: rgba(14, 22, 36, 0.72);
        color: var(--theme-text);
        font-size: 0.82rem;
        line-height: 1.35;
        backdrop-filter: blur(10px);
    }

    .recharge-state-message-warning {
        border-color: rgba(245, 158, 11, 0.48);
        background: linear-gradient(135deg, rgba(245, 158, 11, 0.16), rgba(14, 22, 36, 0.72));
        color: #fff7df;
    }

    .recharge-preview-card-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 10px;
    }

    .recharge-preview-card-title {
        display: flex;
        align-items: center;
        min-width: 0;
    }

    .recharge-data-unit-control {
        flex: 0 0 200px;
        max-width: 200px;
        margin-bottom: 0 !important;
    }

    .recharge-toast {
        background: rgba(14, 22, 36, 0.94) !important;
        color: #fff !important;
        border: 1px solid rgba(148, 163, 184, 0.22) !important;
        border-radius: 9px;
        box-shadow: 0 12px 30px rgba(0, 0, 0, 0.34);
        backdrop-filter: blur(12px);
        overflow: hidden;
    }

    .recharge-toast .d-flex {
        background: linear-gradient(135deg, rgba(14, 22, 36, 0.9), rgba(22, 32, 51, 0.82));
    }

    .recharge-toast--success {
        border-color: rgba(34, 197, 94, 0.95) !important;
        box-shadow: 0 12px 30px rgba(0, 0, 0, 0.34), 0 0 8px rgba(34, 197, 94, 0.35);
    }

    .recharge-toast--success .d-flex {
        background: linear-gradient(135deg, rgba(34, 197, 94, 0.16), rgba(14, 22, 36, 0.82));
    }

    .recharge-toast--info {
        border-color: rgba(23, 162, 184, 0.95) !important;
        box-shadow: 0 12px 30px rgba(0, 0, 0, 0.34), 0 0 8px rgba(23, 162, 184, 0.35);
    }

    .recharge-toast--info .d-flex {
        background: linear-gradient(135deg, rgba(23, 162, 184, 0.16), rgba(14, 22, 36, 0.82));
    }

    .recharge-toast--warning {
        border-color: #f59e0b !important;
        box-shadow: 0 12px 30px rgba(0, 0, 0, 0.34), 0 0 8px rgba(245, 158, 11, 0.35);
    }

    .recharge-toast--warning .d-flex {
        background: linear-gradient(135deg, rgba(245, 158, 11, 0.12), rgba(14, 22, 36, 0.82));
    }

    .recharge-toast--danger {
        border-color: #dc3545 !important;
        box-shadow: 0 12px 30px rgba(0, 0, 0, 0.34), 0 0 10px rgba(220, 53, 69, 0.35);
    }

    .recharge-toast--danger .d-flex {
        background: linear-gradient(135deg, rgba(220, 53, 69, 0.15), rgba(14, 22, 36, 0.82));
    }

    @supports (grid-template-rows: subgrid) {
        .recharge-preview-grid {
            grid-template-rows: auto repeat(6, auto);
        }

        .recharge-preview-grid > div {
            display: grid;
            grid-row: span 7;
            grid-template-rows: subgrid;
            min-width: 0;
        }

        .recharge-preview-grid h6 {
            align-self: end;
        }

        .recharge-preview-items {
            display: contents;
        }
    }
</style>

<div id="messageArea" style="display:none;"></div>

<form id="userRechargeForm" class="network-device-form" method="POST" autocomplete="off">
<input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">

<div class="row align-items-stretch">
<div class="col-md-6 d-flex">
<div class="w-100 d-flex flex-column gap-3">
<div class="card text-white">
<div class="card-header standard-card-header">
    <i class="fa fa-repeat me-2"></i> Recharge utilisateur
</div>

<div class="card-body text-white">

<div class="input-group">
<span class="input-group-text">Serveur</span>
<select class="form-select" name="device_id" id="rechargeDeviceSelect" required>
    <option value="">-- Choisir un serveur --</option>
</select>
</div>

<div class="input-group">
<span class="input-group-text">Utilisateur</span>
<input type="text" class="form-control" id="rechargeUserSearch" placeholder="Rechercher un utilisateur..." autocomplete="off" disabled>
</div>
<div id="rechargeUserResults" class="list-group mt-1 mb-2" style="display:none; max-height: 220px; overflow-y: auto;"></div>
<select class="form-select d-none" name="username" id="rechargeUserSelect" required disabled>
    <option value="">-- Choisir un utilisateur --</option>
</select>

<div class="input-group">
<span class="input-group-text">Mode</span>
<select class="form-select" name="mode" id="rechargeModeSelect" required>
    <option value="">-- Choisir un mode --</option>
    <option value="replace_offer">Changement d'offre</option>
    <option value="extend_offer">Rechargement</option>
    <option value="accumulate_offer">Reabonnement</option>
</select>
</div>

<div class="row g-2 mt-1">
<div class="col-md-6">
<div class="input-group">
<span class="input-group-text">Profil actuel</span>
<select class="form-select" id="rechargeCurrentProfileSelect" disabled>
    <option value="">-- Profil actuel --</option>
</select>
</div>
</div>
<div class="col-md-6">
<div class="input-group">
<span class="input-group-text" id="rechargeProfileLabel">Nouveau profil</span>
<select class="form-select" id="rechargeProfileSelect" required disabled>
    <option value="">-- Choisir un profil --</option>
</select>
</div>
</div>
</div>

<div class="card mt-3 text-white">
    <div class="card-header recharge-preview-card-header">
        <span class="recharge-preview-card-title"><i class="fa fa-eye me-2"></i> Apercu</span>
    </div>
    <div class="card-body text-white">
        <div id="rechargePreviewEmpty" class="d-none"></div>

        <div id="rechargePreviewContent" class="d-none">
            <div class="recharge-preview-grid">
                <div>
                    <h6 class="text-white">Actuel</h6>
                    <div class="recharge-preview-items">
                        <div class="recharge-preview-item"><span class="recharge-preview-label">Profil</span><span class="recharge-preview-value" id="currentProfileValue">-</span></div>
                        <div class="recharge-preview-item"><span class="recharge-preview-label">Temps restant</span><span class="recharge-preview-value" id="currentTimeValue">-</span></div>
                        <div class="recharge-preview-item"><span class="recharge-preview-label">Validité</span><span class="recharge-preview-value" id="currentValidityValue">-</span></div>
                        <div class="recharge-preview-item"><span class="recharge-preview-label">Data restante</span><span class="recharge-preview-value" id="currentDataValue">-</span></div>
                        <div class="recharge-preview-item"><span class="recharge-preview-label">Rate Limit</span><span class="recharge-preview-value" id="currentRateValue">-</span></div>
                        <div class="recharge-preview-item"><span class="recharge-preview-label">Expiration</span><span class="recharge-preview-value" id="currentExpirationValue">-</span></div>
                    </div>
                </div>
                <div>
                    <h6 class="text-white">Offre</h6>
                    <div class="recharge-preview-items">
                        <div class="recharge-preview-item"><span class="recharge-preview-label">Profil</span><span class="recharge-preview-value" id="offerProfileValue">-</span></div>
                        <div class="recharge-preview-item"><span class="recharge-preview-label">Time Limit</span><span class="recharge-preview-value" id="offerTimeValue">-</span></div>
                        <div class="recharge-preview-item"><span class="recharge-preview-label">Validité</span><span class="recharge-preview-value" id="offerValidityValue">-</span></div>
                        <div class="recharge-preview-item"><span class="recharge-preview-label">Data Limit</span><span class="recharge-preview-value" id="offerDataValue">-</span></div>
                        <div class="recharge-preview-item"><span class="recharge-preview-label">Rate Limit</span><span class="recharge-preview-value" id="offerRateValue">-</span></div>
                        <div class="recharge-preview-item"><span class="recharge-preview-label">Expiration</span><span class="recharge-preview-value" id="offerExpirationValue">-</span></div>
                    </div>
                </div>
                <div>
                    <h6 class="text-white">Projete</h6>
                    <div class="recharge-preview-items">
                        <div class="recharge-preview-item"><span class="recharge-preview-label">Profil</span><span class="recharge-preview-value" id="projectedProfileValue">-</span></div>
                        <div class="recharge-preview-item"><span class="recharge-preview-label">Time limite</span><span class="recharge-preview-value" id="projectedTimeValue">-</span></div>
                        <div class="recharge-preview-item"><span class="recharge-preview-label">Validité</span><span class="recharge-preview-value" id="projectedValidityValue">-</span></div>
                        <div class="recharge-preview-item"><span class="recharge-preview-label">Data limite</span><span class="recharge-preview-value" id="projectedDataValue">-</span></div>
                        <div class="recharge-preview-item"><span class="recharge-preview-label">Rate Limit</span><span class="recharge-preview-value" id="projectedRateValue">-</span></div>
                        <div class="recharge-preview-item"><span class="recharge-preview-label">Expiration</span><span class="recharge-preview-value" id="projectedExpirationValue">-</span></div>
                    </div>
                </div>
            </div>

            <div class="recharge-state-message recharge-state-message-warning mt-3 mb-0 d-none" id="rechargeNotesBox"></div>
        </div>
    </div>
</div>

<div class="mt-3 text-end">
    <button type="button" class="btn btn-save" id="applyRechargeBtn" disabled>
        <i class="fa fa-check me-1"></i> Valider
    </button>
</div>

</div>
</div>

<div class="card">
    <div class="card-header standard-card-header">
        <i class="fa fa-history me-2"></i> Historique de recharge
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-striped table-hover table-dark mb-0 align-middle small text-nowrap table-standard">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Utilisateur</th>
                        <th>Profil</th>
                        <th>Mode</th>
                        <th>Operateur</th>
                        <th>Effet</th>
                    </tr>
                </thead>
                <tbody id="rechargeHistoryTable">
                    <tr>
                        <td class="text-white-50">-</td>
                        <td class="text-white-50">-</td>
                        <td class="text-white-50">-</td>
                        <td class="text-white-50">-</td>
                        <td class="text-white-50">-</td>
                        <td class="text-white-50">Aucun historique</td>
                    </tr>
                    <tr>
                        <td class="text-white-50">-</td>
                        <td class="text-white-50">-</td>
                        <td class="text-white-50">-</td>
                        <td class="text-white-50">-</td>
                        <td class="text-white-50">-</td>
                        <td class="text-white-50">-</td>
                    </tr>
                    <tr>
                        <td class="text-white-50">-</td>
                        <td class="text-white-50">-</td>
                        <td class="text-white-50">-</td>
                        <td class="text-white-50">-</td>
                        <td class="text-white-50">-</td>
                        <td class="text-white-50">-</td>
                    </tr>
                    <tr>
                        <td class="text-white-50">-</td>
                        <td class="text-white-50">-</td>
                        <td class="text-white-50">-</td>
                        <td class="text-white-50">-</td>
                        <td class="text-white-50">-</td>
                        <td class="text-white-50">-</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</div>
</div>
</div>

<div class="col-md-6 d-flex">
<div class="card shadow-sm h-100 w-100 guide-content user-recharge-guide">
<div class="card-header standard-card-header">
    <i class="fa fa-info-circle me-2"></i> Guide de recharge
</div>

<div class="card-body text-light d-flex flex-column justify-content-start">
<h6 class="fw-bold mb-1">Étapes</h6>
<ul class="mb-2 ps-3 text-light">
    <li><b>Serveur :</b> choisissez le routeur (device) cible : l’aperçu et l’application utilisent ce même équipement.</li>
    <li><b>Utilisateur :</b> choisissez le compte à recharger sur ce serveur.</li>
    <li><b>Mode :</b> choisissez comment appliquer l’offre (profil sélectionné).</li>
    <li><b>Profil actuel / nouveau profil :</b> selon le mode, le profil courant est conservé ou remplacé par celui choisi.</li>
</ul>

<h6 class="fw-bold mb-1">Modes</h6>
<ul class="mb-2 ps-3 text-light">
    <li><b>Changement d’offre :</b> applique le nouveau profil. L’expiration reste vide (attente du premier login / cycle Hotspot). Les compteurs data consommée et temps cumulé repartent à zéro.</li>
    <li><b>Rechargement :</b> conserve le profil courant et ajoute à vos quotas le temps et la data de l’offre (à partir de votre temps restant et de votre data restante). Applicable uniquement si le compte n’est pas expiré ; l’expiration est prolongée avec la validité du profil.</li>
    <li><b>Réabonnement :</b> même profil obligatoire. Même principe d’ajout sur le temps restant et la data restante. L’expiration reste vide si elle est absente ou expirée ; sinon on prolonge avec la validité du profil. Les compteurs repartent à zéro pour le nouveau cycle.</li>
</ul>

<h6 class="fw-bold mb-1">Par type de device</h6>
<ul class="mb-0 ps-3 text-light">
    <li><b>MikroTik :</b> application réelle pour les trois modes. Le cumul exige le même profil et un compte non expiré.</li>
    <li><b>RADIUS :</b> aperçu métier disponible ; l’application réelle sera alignée ensuite sur les mêmes règles.</li>
    <li><b>OPNsense :</b> aperçu métier pour préparer l’équivalence backend.</li>
</ul>
</div>
</div>
</div>
</div>

</form>

</div>
</div>
</div>

<div class="toast-container position-fixed bottom-0 end-0 p-3" style="z-index: 1080;">
    <div id="rechargeToast" class="toast recharge-toast recharge-toast--success" role="alert" aria-live="assertive" aria-atomic="true">
        <div class="d-flex">
            <div class="toast-body" id="rechargeToastBody">Recharge appliquee.</div>
            <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>
        </div>
    </div>
</div>


<?php
$extraJs = array (
  0 => '../js/user_recharge.js?v=20260402d',
);
require_once '../includes/layout_footer.php';
?>
