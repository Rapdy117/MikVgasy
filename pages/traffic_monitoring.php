<?php
session_start(); // Démarre la session pour gérer l'état de connexion
require_once '../config/db.php';
require_once '../includes/device_manager.php';
require_once '../includes/auth.php';

// Inclure la fonction de message
require_once '../includes/message.php'; // Chemin correct depuis le dossier 'dashboard'

// Vérifie si l'utilisateur est connecté. Si non, redirige vers la page de connexion.
// La variable de session 'logged_in' est maintenant cohérente avec index.php
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    set_message('Veuillez vous connecter pour accéder à cette page.', 'danger'); // Utilisation de set_message
    header('Location: ../index.php'); // Redirige vers la page de connexion racine
    exit();
}

$store = loadDeviceStore();
$activeDevice = getActiveDeviceRecord($store);
$activeDeviceLabel = $activeDevice ? getDeviceDisplayLabel($activeDevice) : 'Aucun device actif';
$activeDeviceType = strtoupper((string)($activeDevice['type'] ?? 'other'));
$activeDeviceHost = trim((string)($activeDevice['host'] ?? ($activeDevice['ip'] ?? '')));
$activeDeviceId = trim((string)($activeDevice['id'] ?? ''));
$isMikrotik = strtolower((string)($activeDevice['type'] ?? '')) === 'mikrotik';
$isAdmin = isAdministrator();
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

?>

<?php
$pageTitle = 'Surveillance du Trafic';
$extraCss = [
    '../css/traffic_control.css?v=20260915idle2',
    '../css/traffic_monitoring.css?v=20261016a',
];
$extraHeadJs = array (
  0 => '../assets/vendor/chart/chart.umd.min.js',
  1 => '../assets/vendor/chart/moment-with-locales.min.js',
  2 => '../assets/vendor/chart/chartjs-adapter-moment.min.js',
  3 => '../assets/vendor/chart/chartjs-plugin-streaming.js',
);
require_once '../includes/layout_header.php';
?>

<div id="messageArea" style="display: none;"></div>
                <input type="hidden" id="trafficSourceLabel" value="<?= htmlspecialchars($activeDeviceLabel) ?>">
                <input type="hidden" id="trafficSourceHost" value="<?= htmlspecialchars($activeDeviceHost) ?>">
                <input type="hidden" id="trafficSourceType" value="<?= htmlspecialchars($activeDeviceType) ?>">
                <input type="hidden" id="trafficControlDeviceId" value="<?= htmlspecialchars($activeDeviceId, ENT_QUOTES) ?>">
                <input type="hidden" id="trafficControlCsrf" value="<?= htmlspecialchars((string)$_SESSION['csrf_token'], ENT_QUOTES) ?>">
                <button type="button" id="trafficListenerToggle" hidden></button>
                <button type="button" id="trafficListenerRefreshNow" hidden></button>
                <select id="trafficListenerRefresh" hidden><option value="60000" selected>60 secondes</option></select>
                <span id="trafficListenerState" hidden></span><span id="trafficListenerNotice" hidden></span>

                <?php if ($isMikrotik && $isAdmin): ?>
                <div class="row traffic-workspace">
                    <div class="col-12 mb-2">
                        <div class="card shadow-sm">
                            <div class="card-header standard-card-header d-flex justify-content-between align-items-center gap-2 flex-wrap">
                                <span><i class="fa fa-gauge-high me-2"></i>Contrôle du bridge Hotspot</span>
                                <span class="badge text-bg-secondary" id="interfaceQueueState">Lecture RouterOS…</span>
                            </div>
                            <div class="card-body">
                                <form id="interfaceQueueForm" class="network-device-form traffic-queue-form row g-3 align-items-end">
                                    <div class="col-lg-3 col-md-6"><label class="form-label" for="queueInterface">Bridge Hotspot</label><div class="input-group"><span class="input-group-text"><i class="fa fa-diagram-project"></i></span><select class="form-select" id="queueInterface" required><option value="">Identification…</option></select></div></div>
                                    <div class="col-lg-2 col-md-6"><label class="form-label" for="queueDownload">Download</label><div class="input-group"><span class="input-group-text"><i class="fa fa-arrow-down"></i></span><input class="form-control traffic-queue-value" id="queueDownload" type="number" min="0.1" max="100000" step="0.1" required><span class="input-group-text traffic-queue-unit">Mbit/s</span></div></div>
                                    <div class="col-lg-2 col-md-6"><label class="form-label" for="queueUpload">Upload</label><div class="input-group"><span class="input-group-text"><i class="fa fa-arrow-up"></i></span><input class="form-control traffic-queue-value" id="queueUpload" type="number" min="0.1" max="100000" step="0.1" required><span class="input-group-text traffic-queue-unit">Mbit/s</span></div></div>
                                    <div class="col-lg-2 col-md-6"><label class="form-label" for="queuePriority">Priorité</label><div class="input-group"><span class="input-group-text"><i class="fa fa-ranking-star"></i></span><select class="form-select" id="queuePriority"><?php for ($priority = 1; $priority <= 8; $priority++): ?><option value="<?= $priority ?>"<?= $priority === 8 ? ' selected' : '' ?>><?= $priority ?><?= $priority === 1 ? ' — haute' : ($priority === 8 ? ' — basse' : '') ?></option><?php endfor; ?></select></div></div>
                                    <div class="col-lg-3 d-flex gap-2 traffic-queue-actions"><button class="btn btn-primary btn-sm traffic-queue-action" type="submit"><i class="fa fa-floppy-disk me-1"></i>Appliquer</button><button class="btn btn-outline-warning btn-sm traffic-queue-action" id="queueToggle" type="button" disabled><i class="fa fa-power-off me-1"></i>État</button></div>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <?php if ($isMikrotik && $isAdmin): ?>
                <div class="row traffic-workspace">
                    <div class="col-12 mb-2">
                        <div class="traffic-history-grid traffic-wan-parameter-grid" aria-label="Paramètres des interfaces WAN">
                            <article class="traffic-wan-parameter is-active" data-wan-slot="wan1"><div class="traffic-wan-parameter-head"><button type="button" class="btn btn-sm btn-save traffic-wan-name-button" data-wan-title aria-label="Nom de la source WAN 1">Starlink 1 · principale</button><button type="button" class="traffic-wan-title-edit" aria-label="Modifier Nom de la source WAN 1"><i class="fa fa-pen"></i></button></div><label><i class="fa fa-network-wired traffic-wan-field-icon" aria-hidden="true"></i>Interface<select data-wan-interface data-default-interface="ether1"><option value="ether1" selected>ether1</option></select></label><small class="traffic-wan-interface-status" data-wan-interface-status></small><label><i class="fa fa-scale-balanced traffic-wan-field-icon" aria-hidden="true"></i>Poids PCC<span class="traffic-wan-percent-field"><input type="number" min="0" max="100" value="20" readonly><span>%</span></span></label><div class="traffic-wan-progress" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0"><span></span></div></article>
                            <article class="traffic-wan-parameter is-active" data-wan-slot="wan2"><div class="traffic-wan-parameter-head"><button type="button" class="btn btn-sm btn-save traffic-wan-name-button" data-wan-title aria-label="Nom de la source WAN 2">Starlink 2 · secondaire</button><button type="button" class="traffic-wan-title-edit" aria-label="Modifier Nom de la source WAN 2"><i class="fa fa-pen"></i></button></div><label><i class="fa fa-network-wired traffic-wan-field-icon" aria-hidden="true"></i>Interface<select data-wan-interface data-default-interface="ether2"><option value="ether2" selected>ether2</option></select></label><small class="traffic-wan-interface-status" data-wan-interface-status></small><label><i class="fa fa-scale-balanced traffic-wan-field-icon" aria-hidden="true"></i>Poids PCC<span class="traffic-wan-percent-field"><input type="number" min="0" max="100" value="80" readonly><span>%</span></span></label><div class="traffic-wan-progress" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0"><span></span></div></article>
                            <article class="traffic-wan-parameter" data-wan-slot="wan3"><div class="traffic-wan-parameter-head"><button type="button" class="btn btn-sm btn-save traffic-wan-name-button" data-wan-title aria-label="Nom de la source WAN 3">Réserve 3</button><button type="button" class="traffic-wan-title-edit" aria-label="Modifier Nom de la source WAN 3"><i class="fa fa-pen"></i></button></div><label><i class="fa fa-network-wired traffic-wan-field-icon" aria-hidden="true"></i>Interface<select data-wan-interface data-default-interface="ether3"><option value="ether3" selected>ether3</option></select></label><small class="traffic-wan-interface-status" data-wan-interface-status></small><label><i class="fa fa-scale-balanced traffic-wan-field-icon" aria-hidden="true"></i>Poids PCC<span class="traffic-wan-percent-field"><input type="number" min="0" max="100" value="0" readonly><span>%</span></span></label><div class="traffic-wan-progress" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0"><span></span></div></article>
                            <article class="traffic-wan-parameter" data-wan-slot="wan4"><div class="traffic-wan-parameter-head"><button type="button" class="btn btn-sm btn-save traffic-wan-name-button" data-wan-title aria-label="Nom de la source WAN 4">Réserve 4</button><button type="button" class="traffic-wan-title-edit" aria-label="Modifier Nom de la source WAN 4"><i class="fa fa-pen"></i></button></div><label><i class="fa fa-network-wired traffic-wan-field-icon" aria-hidden="true"></i>Interface<select data-wan-interface data-default-interface="ether4"><option value="ether4" selected>ether4</option></select></label><small class="traffic-wan-interface-status" data-wan-interface-status></small><label><i class="fa fa-scale-balanced traffic-wan-field-icon" aria-hidden="true"></i>Poids PCC<span class="traffic-wan-percent-field"><input type="number" min="0" max="100" value="0" readonly><span>%</span></span></label><div class="traffic-wan-progress" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0"><span></span></div></article>
                        </div>
                        <section class="card shadow-sm mb-2 traffic-usage-daily" aria-label="Consommation utilisateurs" tabindex="-1">
<div class="card-body">
<div class="traffic-usage-top">
<div class="traffic-usage-chart-card traffic-progress-frame" id="trafficUsageChartCard">
<div class="traffic-usage-card-head"><strong><i class="fa fa-wave-square traffic-title-icon" aria-hidden="true"></i><span id="trafficUsageChartTitle">Total par heure</span></strong><span id="wanStateBadge" class="badge bg-secondary ms-2" title="Etat WAN (lecture routeur)">Etat WAN : …</span><div class="traffic-usage-chart-controls"><div class="traffic-usage-chart-toggle" role="group" aria-label="Période de la courbe"><button type="button" class="active" aria-pressed="true" data-usage-chart-mode="day">Jour</button><button type="button" aria-pressed="false" data-usage-chart-mode="month">Mois</button></div><label class="traffic-usage-cycle-control" data-usage-date-control for="trafficUsageDate"><span>Date</span><input class="form-control form-control-sm" type="date" id="trafficUsageDate" aria-label="Date de consommation"></label><label class="traffic-usage-cycle-control d-none" data-usage-cycle-control for="trafficUsageCycleStart"><span>Cycle</span><select id="trafficUsageCycleStart" class="form-select form-select-sm"><option value="1" selected>Jour 1</option><option value="5">Jour 5</option><option value="10">Jour 10</option><option value="15">Jour 15</option><option value="20">Jour 20</option><option value="25">Jour 25</option></select></label></div></div>
<div class="traffic-usage-chart"><canvas id="trafficUsageDailyChart" role="img" aria-label="Consommation totale collectée"></canvas></div>
<p class="traffic-section-help mb-0" id="trafficUsageNote">Comptage par session/source : seuls les deltas positifs collectés sont totalisés.</p>
<div class="traffic-wan-global-cap" id="trafficMonthlyCapBar" hidden><div class="traffic-wan-global-cap-label"><span><i class="fa fa-calendar-days" aria-hidden="true"></i> <span data-monthly-cap-label>Consommation WAN cumulée / cible mensuelle cumulée</span></span><strong data-monthly-cap-value>-- / -- Go</strong></div></div>
</div>
<div class="traffic-usage-summary" aria-label="Synthèse WAN">
<div class="traffic-progress-frame" data-wan-summary-slot="wan1"><span data-wan-summary-title>WAN 1</span><div class="traffic-usage-value"><strong data-wan-summary-value>--</strong><i class="fa fa-network-wired" aria-hidden="true"></i></div><small data-wan-summary-detail>Interface en attente</small><div class="traffic-wan-progress" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0" data-wan-summary-cap-bar><span></span></div></div>
<div data-wan-summary-slot="wan2"><span data-wan-summary-title>WAN 2</span><div class="traffic-usage-value"><strong data-wan-summary-value>--</strong><i class="fa fa-satellite-dish" aria-hidden="true"></i></div><small data-wan-summary-detail>Interface en attente</small><div class="traffic-wan-progress" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0" data-wan-summary-cap-bar><span></span></div></div>
<div data-wan-summary-slot="wan3"><span data-wan-summary-title>WAN 3</span><div class="traffic-usage-value"><strong data-wan-summary-value>--</strong><i class="fa fa-network-wired" aria-hidden="true"></i></div><small data-wan-summary-detail>Réserve inactive</small><div class="traffic-wan-progress" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0" data-wan-summary-cap-bar><span></span></div></div>
<div data-wan-summary-slot="wan4"><span data-wan-summary-title>WAN 4</span><div class="traffic-usage-value"><strong data-wan-summary-value>--</strong><i class="fa fa-network-wired" aria-hidden="true"></i></div><small data-wan-summary-detail>Réserve inactive</small><div class="traffic-wan-progress" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0" data-wan-summary-cap-bar><span></span></div></div>
</div>
</div>
</div>
</section>
                    </div>
                </div>
                <?php endif; ?>


            </div>
        </div>
        </div>


<div class="traffic-wan-edit-modal" id="wanEditModal" hidden aria-hidden="true">
    <div class="traffic-wan-edit-backdrop" data-wan-edit-close></div>
    <section class="traffic-wan-edit-dialog" role="dialog" aria-modal="true" aria-labelledby="wanEditModalTitle">
        <div class="traffic-wan-edit-header">
            <div>
                <span class="traffic-wan-edit-kicker">Paramètres source WAN</span>
                <h3 id="wanEditModalTitle">Modifier la source</h3>
            </div>
            <button type="button" class="traffic-wan-edit-close" data-wan-edit-close aria-label="Fermer"><i class="fa fa-xmark"></i></button>
        </div>
        <div class="traffic-wan-edit-body">
            <label class="traffic-wan-edit-field" for="wanEditTitleInput">
                <span><i class="fa fa-tag" aria-hidden="true"></i> Titre</span>
                <input type="text" id="wanEditTitleInput" maxlength="60" autocomplete="off">
            </label>
            <label class="traffic-wan-edit-field" for="wanEditInterfaceSelect">
                <span><i class="fa fa-network-wired" aria-hidden="true"></i> Interface</span>
                <select id="wanEditInterfaceSelect"></select>
            </label>
            <label class="traffic-wan-edit-field" for="wanEditPccInput">
                <span><i class="fa fa-scale-balanced" aria-hidden="true"></i> Poids PCC</span>
                <div class="traffic-wan-edit-percent"><input type="number" id="wanEditPccInput" min="0" max="100" step="1"><b>%</b></div>
            </label>
            <label class="traffic-wan-edit-field" for="wanEditDailyTargetInput">
                <span><i class="fa fa-gauge-high" aria-hidden="true"></i> Cible quotidienne</span>
                <div class="traffic-wan-edit-percent"><input type="number" id="wanEditDailyTargetInput" min="1" max="100000" step="1" required><b>Go</b></div>
            </label>
            <label class="traffic-wan-edit-field" for="wanEditMonthlyTargetInput">
                <span><i class="fa fa-calendar-days" aria-hidden="true"></i> Cible mensuelle</span>
                <div class="traffic-wan-edit-percent"><input type="number" id="wanEditMonthlyTargetInput" min="1" max="1000000" step="1" required><b>Go</b></div>
            </label>
            <p class="traffic-wan-edit-help" id="wanEditHelp">Le poids PCC est envoyé à RouterOS et appliqué immédiatement.</p>
        </div>
        <div class="traffic-wan-edit-actions">
            <button type="button" class="btn btn-test" data-wan-edit-close>Annuler</button>
            <button type="button" class="btn btn-save" id="wanEditSaveBtn"><i class="fa fa-floppy-disk me-1"></i> Enregistrer</button>
        </div>
    </section>
</div>

<?php
$extraJs = array (
    0 => '../js/traffic_listener.js?v=20261011a',
    1 => '../js/traffic_monitoring.js?v=20261016a',
);
require_once '../includes/layout_footer.php';
?>
<script>
/* Etat WAN lu sur le routeur (lecture seule) : NORMAL, DEGRADED ou DOWN. */
(function () {
    var badge = document.getElementById('wanStateBadge');
    if (!badge) return;
    var labels = { NORMAL: ['Normal', 'bg-success'], DEGRADED: ['Dégradé', 'bg-warning'], DOWN: ['Hors service', 'bg-danger'] };
    fetch('../api/wan_state_status.php', { credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (d) {
            if (!d.success) { badge.textContent = 'Etat WAN : indisponible'; return; }
            var l = labels[d.state] || [d.state, 'bg-secondary'];
            badge.textContent = 'Etat WAN : ' + l[0] + ' (' + d.usable + '/' + d.total + ')';
            badge.className = 'badge ms-2 ' + l[1];
        })
        .catch(function () { badge.textContent = 'Etat WAN : indisponible'; });
})();
</script>
