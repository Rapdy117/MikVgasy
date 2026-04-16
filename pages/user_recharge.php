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
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<title>Recharge</title>

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="../css/theme.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
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

    .user-recharge-guide .card-header {
        background-color: var(--theme-card-soft) !important;
        color: var(--theme-primary) !important;
        border-bottom: 1px solid rgba(255, 255, 255, 0.08) !important;
    }

    .user-recharge-guide h6 {
        color: var(--theme-primary);
    }

    .recharge-preview-grid {
        display: grid;
        grid-template-columns: repeat(3, minmax(0, 1fr));
        gap: 14px;
    }

    .recharge-preview-items {
        display: flex;
        flex-direction: column;
        gap: 6px;
    }

    .recharge-preview-item {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 8px;
        padding: 4px 8px;
        border-radius: 6px;
        background: rgba(22, 32, 51, 0.7);
        border: 1px solid rgba(148, 163, 184, 0.14);
    }

    .recharge-preview-label {
        color: var(--theme-text-muted);
        font-weight: 600;
        font-size: 12px;
    }

    .recharge-preview-value {
        color: var(--theme-text);
        font-weight: 600;
        font-size: 12px;
        text-align: right;
        margin-left: auto;
    }
</style>
</head>
<body data-active-device-type="<?= htmlspecialchars($activeDeviceType, ENT_QUOTES) ?>" data-active-device-id="<?= htmlspecialchars($activeDeviceId, ENT_QUOTES) ?>">

<div class="d-flex user-recharge-wrapper" id="wrapper">
<?php include_once '../includes/sidebar.php'; ?>

<div id="page-content-wrapper">
<div class="container-fluid user-recharge-container">
<?php display_message(); ?>
<div id="messageArea" style="display:none;"></div>

<form id="userRechargeForm" class="network-device-form" method="POST" autocomplete="off">
<input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">

<div class="row align-items-stretch">
<div class="col-md-6 d-flex">
<div class="w-100 d-flex flex-column gap-3">
<div class="card text-white">
<div class="card-header">
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
    <div class="card-header">
        <i class="fa fa-eye me-2"></i> Apercu
    </div>
    <div class="card-body text-white">
        <div id="rechargePreviewEmpty" class="text-white-50 text-center py-4">
            Choisissez un utilisateur, un profil et un mode pour preparer l apercu.
        </div>

        <div id="rechargePreviewContent" class="d-none">
            <div class="d-flex justify-content-end mb-2">
                <div class="input-group input-group-sm" style="max-width: 200px;">
                    <span class="input-group-text">Unite data</span>
                    <select class="form-select" id="rechargeDataUnitSelect">
                        <option value="KB">KB</option>
                        <option value="MB" selected>MB</option>
                        <option value="GB">GB</option>
                    </select>
                </div>
            </div>
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

            <div class="alert alert-warning small mt-3 mb-0 d-none" id="rechargeNotesBox"></div>
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
    <div class="card-header">
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
<div class="card-header py-2">
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
    <li><b>Changement d’offre :</b> applique le nouveau profil. L’expiration reste vide (attente du premier login / cycle Hotspot).</li>
    <li><b>Rechargement :</b> conserve le profil courant et ajoute à vos quotas le temps et la data de l’offre (à partir de votre temps restant et de votre data restante). L’expiration ne bouge que si elle existe déjà et que le compte est encore valide.</li>
    <li><b>Réabonnement :</b> même profil obligatoire. Même principe d’ajout sur le temps restant et la data restante. L’expiration reste vide si elle est absente ou expirée ; sinon on prolonge avec la validité.</li>
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
    <div id="rechargeToast" class="toast text-bg-success border-0" role="alert" aria-live="assertive" aria-atomic="true">
        <div class="d-flex">
            <div class="toast-body" id="rechargeToastBody">Recharge appliquee.</div>
            <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="../js/sidebar.js?v=20260402a"></script>
<script src="../js/user_recharge.js?v=20260402d"></script>
</body>
</html>
