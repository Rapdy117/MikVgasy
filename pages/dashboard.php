<?php
session_start(); // Démarre la session pour gérer l'état de connexion

// Inclure la fonction de message
require_once '../includes/message.php'; // Chemin correct depuis le dossier 'dashboard'
require_once '../includes/app_context.php';

// Vérifie si l'utilisateur est connecté. Si non, redirige vers la page de connexion.
// La variable de session 'logged_in' est maintenant cohérente avec index.php
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    set_message('Veuillez vous connecter pour accéder à cette page.', 'danger'); // Utilisation de set_message
    header('Location: ../index.php'); // Redirige vers la page de connexion racine
    exit();
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Si l'utilisateur est connecté, vous pouvez récupérer son nom d'utilisateur si besoin
$username = $_SESSION['username'] ?? 'Utilisateur';
$appContext = buildAppContext();

?>

<?php
$pageTitle = 'Tableau de Bord Général';
$extraCss = [
    '../css/traffic_control.css?v=20260915idle2',
    '../css/dashboard.css?v=20261015a',
];
$extraHeadJs = array (
  0 => '../assets/vendor/chart/chart.umd.min.js',
  1 => '../assets/vendor/chart/moment-with-locales.min.js',
  2 => '../assets/vendor/chart/chartjs-adapter-moment.min.js',
  3 => '../assets/vendor/chart/chartjs-plugin-streaming.js',
);
$contentClass = 'dashboard-page';
require_once '../includes/layout_header.php';

// Disque du serveur applicatif (lecture seule, calcule a chaque chargement - pas de nouvelle API).
$diskTotal = @disk_total_space('/');
$diskFree = @disk_free_space('/');
$diskPercent = ($diskTotal && $diskFree !== false && $diskTotal > 0) ? round((1 - $diskFree / $diskTotal) * 100) : null;
$diskLevel = $diskPercent === null ? '' : ($diskPercent >= 90 ? 'is-danger' : ($diskPercent >= 75 ? 'is-warning' : ''));
$diskFreeGo = $diskFree !== false ? round($diskFree / 1073741824, 1) : null;
?>

<div id="messageArea" style="display: none;"></div>

                <div class="row dashboard-layout">
                    <div class="col-lg-7 col-md-12 mb-3">

                        <div class="row mb-3">
                            <div class="col-12">
                                <div class="card hotspot-card h-100 shadow-sm">
                                    <div class="card-header standard-card-header">
                                        <i class="fas fa-wifi me-2"></i>Hotspot
                                    </div>
                                    <div class="card-body py-3">
                                        <div class="traffic-workspace">
                                        <div class="traffic-overview-status-row dashboard-hotspot-tiles">
                                            <article class="traffic-overview-check card-shortcut" style="--card-accent:#38bdf8" onclick="redirectTo('sessions_list.php');" role="button" tabindex="0">
                                                <div class="traffic-card-copy">
                                                    <span class="traffic-card-node">Hotspot</span>
                                                    <span class="traffic-card-label">Sessions actives</span>
                                                </div>
                                                <div class="traffic-card-metric">
                                                    <span class="traffic-card-icon"><i class="fas fa-wifi" aria-hidden="true"></i></span>
                                                    <strong id="activeHotspotUsersCount">--</strong>
                                                </div>
                                            </article>
                                            <article class="traffic-overview-check card-shortcut" style="--card-accent:#a78bfa" onclick="redirectTo('users_list.php');" role="button" tabindex="0">
                                                <div class="traffic-card-copy">
                                                    <span class="traffic-card-node">Hotspot</span>
                                                    <span class="traffic-card-label">Tous les utilisateurs</span>
                                                </div>
                                                <div class="traffic-card-metric">
                                                    <span class="traffic-card-icon"><i class="fas fa-users" aria-hidden="true"></i></span>
                                                    <strong id="connectedUsersCount">--</strong>
                                                </div>
                                            </article>
                                            <article class="traffic-overview-check card-shortcut" style="--card-accent:#22c55e" onclick="redirectTo('add_hotspot_user.php');" role="button" tabindex="0">
                                                <div class="traffic-card-copy">
                                                    <span class="traffic-card-node">Action</span>
                                                    <span class="traffic-card-label">Ajouter Utilisateur</span>
                                                </div>
                                                <div class="traffic-card-metric">
                                                    <span class="traffic-card-icon"><i class="fas fa-user-plus" aria-hidden="true"></i></span>
                                                    <strong><i class="fas fa-arrow-right" aria-hidden="true"></i></strong>
                                                </div>
                                            </article>
                                            <article class="traffic-overview-check card-shortcut" style="--card-accent:#f59e0b" onclick="redirectTo('generate.php');" role="button" tabindex="0">
                                                <div class="traffic-card-copy">
                                                    <span class="traffic-card-node">Action</span>
                                                    <span class="traffic-card-label">Génération Ticket</span>
                                                </div>
                                                <div class="traffic-card-metric">
                                                    <span class="traffic-card-icon"><i class="fas fa-ticket-alt" aria-hidden="true"></i></span>
                                                    <strong><i class="fas fa-arrow-right" aria-hidden="true"></i></strong>
                                                </div>
                                            </article>
                                        </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="row mb-3 dashboard-bandwidth-row">
                            <div class="col-12">
                                <div class="card shadow-sm dashboard-bandwidth-card">
                                    <div class="card-header standard-card-header">
                                        <i class="fas fa-chart-area me-2"></i>Bande Passante Live
                                    </div>
                                    <div class="card-body py-3">
                                        <div class="traffic-workspace">
                                        <div id="bandwidthChartContainer" class="traffic-history-body dashboard-bandwidth-body">
                                            <div class="traffic-history-canvas dashboard-bandwidth-canvas">
                                                <canvas id="bandwidthTrafficChart" aria-label="Courbe de bande passante Download/Upload"></canvas>
                                            </div>
                                            <div class="traffic-history-stats">
                                                <div class="traffic-history-stat" id="dashboardDownloadStat" style="--stat-color:#22c55e">
                                                    <span>Download</span>
                                                    <strong id="downloadRateLive">--</strong>
                                                    <small>débit instantané</small>
                                                </div>
                                                <div class="traffic-history-stat" id="dashboardUploadStat" style="--stat-color:#38bdf8">
                                                    <span>Upload</span>
                                                    <strong id="uploadRateLive">--</strong>
                                                    <small>débit instantané</small>
                                                </div>
                                            </div>
                                        </div>
                                        <p class="traffic-legend-text text-end mt-2 mb-0" id="bandwidthAdditionalInfo"></p>
                                        <hr class="dashboard-kit-separator">
                                        <div class="traffic-overview-status-row" id="dashboardKitTiles" style="grid-template-columns: repeat(2, minmax(0, 1fr));">
                                            <p class="text-white-50 mb-0">Chargement…</p>
                                        </div>
                                        <div class="dashboard-kit-global-bar" id="dashboardKitGlobalBar" title="Répartition du jour — chargement…"></div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="row mb-3 dashboard-sales-row">
                            <div class="col-12">
                                <div class="card shadow-sm">
                                    <div class="card-header standard-card-header">
                                        <i class="fas fa-cash-register me-2"></i>Bilan
                                    </div>
                                    <div class="card-body py-3">
                                        <div class="sales-inline-card sales-inline-card-standalone">
                                            <div class="dashboard-summary-list">
                                                <a class="dashboard-summary-item dashboard-summary-link" href="reports.php?period=day" title="Voir le rapport détaillé du jour">
                                                    <span class="dashboard-summary-label">Montant du jour</span>
                                                    <div class="text-end flex-shrink-0">
                                                        <strong class="dashboard-summary-value d-block" id="summarySalesToday">--</strong>
                                                        <span class="sales-summary-meta" id="summarySalesTodayMeta">--</span>
                                                    </div>
                                                </a>
                                                <a class="dashboard-summary-item dashboard-summary-link" href="reports.php?period=month" title="Voir le rapport détaillé du mois">
                                                    <span class="dashboard-summary-label">Montant mensuel</span>
                                                    <div class="text-end flex-shrink-0">
                                                        <strong class="dashboard-summary-value d-block" id="summarySalesMonthly">--</strong>
                                                        <span class="sales-summary-meta" id="summarySalesMonthlyMeta">--</span>
                                                    </div>
                                                </a>
                                            </div>
                                            <div class="sales-mini-stats mt-2">
                                                <div class="sales-mini-stat">
                                                    <span class="sales-mini-stat-label">Jours actifs</span>
                                                    <strong class="sales-mini-stat-value" id="salesActiveDays">--</strong>
                                                </div>
                                                <div class="sales-mini-stat">
                                                    <span class="sales-mini-stat-label">Pic du mois</span>
                                                    <strong class="sales-mini-stat-value" id="salesPeakDay">--</strong>
                                                </div>
                                            </div>
                                            <div class="sales-trend-card mt-2">
                                                <div class="sales-trend-header">
                                                    <span class="dashboard-summary-label">Tendance journalière</span>
                                                </div>
                                                <div class="sales-trend-bars" id="salesTrendBars">
                                                    <span class="sales-trend-empty">Chargement...</span>
                                                </div>
                                                <div class="sales-trend-footer">
                                                    <strong class="dashboard-summary-value" id="salesTrendMonthLabel">Mois en cours</strong>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                    </div>

                    <div class="col-lg-5 col-md-12 mb-3 dashboard-right-column">
                        <div class="row mb-3 dashboard-health-row">
                            <div class="col-12">
                                <div class="card shadow-sm">
                                    <div class="card-header standard-card-header">
                                        <i class="fas fa-heart-pulse me-2"></i>État réseau &amp; serveur
                                    </div>
                                    <div class="card-body py-3">
                                        <div class="traffic-workspace">
                                        <div class="traffic-overview-status-row" style="grid-template-columns: repeat(2, minmax(0, 1fr));">
                                            <article class="traffic-overview-check" id="dashboardWanTile" style="--card-accent:#22c55e">
                                                <div class="traffic-card-copy">
                                                    <span class="traffic-card-node">État WAN</span>
                                                    <span class="traffic-card-label" id="dashboardWanLabel">--</span>
                                                    <small id="dashboardWanDetail">Lecture routeur…</small>
                                                </div>
                                                <div class="traffic-card-metric">
                                                    <span class="traffic-card-icon"><i class="fa fa-tower-broadcast" aria-hidden="true"></i></span>
                                                    <strong id="dashboardWanValue">--</strong>
                                                </div>
                                            </article>
                                            <?php
                                            $diskAccent = $diskLevel === 'is-danger' ? '#ef4444' : ($diskLevel === 'is-warning' ? '#f59e0b' : '#17a2b8');
                                            ?>
                                            <article class="traffic-overview-check" style="--card-accent:<?= htmlspecialchars($diskAccent, ENT_QUOTES) ?>">
                                                <div class="traffic-card-copy">
                                                    <span class="traffic-card-node">Disque serveur</span>
                                                    <span class="traffic-card-label">Espace utilisé</span>
                                                    <small><?= $diskFreeGo !== null ? $diskFreeGo . ' Go libres' : 'Indisponible' ?></small>
                                                </div>
                                                <div class="traffic-card-metric">
                                                    <span class="traffic-card-icon"><i class="fa fa-database" aria-hidden="true"></i></span>
                                                    <strong><?= $diskPercent !== null ? $diskPercent . '%' : '--' ?></strong>
                                                </div>
                                            </article>
                                        </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="row mb-3 dashboard-device-row">
                            <div class="col-12">
                                <div class="card revenue-card h-100 shadow-sm">
                                    <div class="card-header standard-card-header d-flex align-items-center gap-2">
                                        <i class="fas fa-network-wired me-2"></i><span id="deviceSummaryTitle">Device actif</span>
                                        <span class="small text-white-50" id="deviceSummarySubtitle"></span>
                                    </div>
                                    <div class="card-body py-3">
                                        <div class="device-card-grid">
                                            <div class="dashboard-summary-list">
                                                <div class="dashboard-summary-item">
                                                    <span class="dashboard-summary-label">Nom</span>
                                                    <strong class="dashboard-summary-value" id="deviceNameValue">--</strong>
                                                </div>
                                                <div class="dashboard-summary-item">
                                                    <span class="dashboard-summary-label dashboard-summary-label-stack">
                                                        <span>Version</span>
                                                        <span>Backend</span>
                                                    </span>
                                                    <strong class="dashboard-summary-value dashboard-summary-value-stack" id="deviceVersionValue">--</strong>
                                                </div>
                                                <div class="dashboard-summary-item dashboard-summary-item-status">
                                                    <span class="dashboard-summary-label">Statut</span>
                                                    <div class="dashboard-status-popover-wrap">
                                                        <strong class="dashboard-summary-value" id="deviceStatusValue">--</strong>
                                                        <div class="dashboard-status-popover" id="deviceStatusPopover" hidden></div>
                                                    </div>
                                                </div>
                                                <div class="dashboard-summary-item dashboard-summary-item-zones">
                                                    <span class="dashboard-summary-label text-nowrap">Zones / Scope</span>
                                                    <strong class="dashboard-summary-value text-nowrap" id="deviceZonesValue">--</strong>
                                                </div>
                                            </div>
                                            <div class="cpu-gauge-wrap">
                                                <div class="cpu-type" id="cpuTypeLabel">Chargement CPU...</div>
                                                <div class="cpu-gauge-svg" id="cpuGauge">
                                                    <svg viewBox="0 0 160 160" aria-label="Jauge CPU et RAM">
                                                        <circle class="cpu-ring-track" cx="80" cy="80" r="60"></circle>
                                                        <circle class="cpu-ring-value cpu-ring-value-outer" id="cpuGaugeOuterValue" cx="80" cy="80" r="60"></circle>
                                                        <circle class="cpu-ring-track" cx="80" cy="80" r="48"></circle>
                                                        <circle class="cpu-ring-value cpu-ring-value-inner" id="cpuGaugeInnerValue" cx="80" cy="80" r="48"></circle>
                                                    </svg>
                                                    <div class="cpu-gauge-center">
                                                        <strong id="cpuTotalLive">--%</strong>
                                                        <span>CPU</span>
                                                    </div>
                                                </div>
                                                <div class="cpu-gauge-legend">
                                                    <span class="cpu-gauge-legend-item cpu-gauge-legend-cpu">CPU <strong id="cpuGaugeCpuLabel">--%</strong></span>
                                                    <span class="cpu-gauge-legend-item cpu-gauge-legend-ram">RAM <strong id="cpuGaugeRamLabel">--%</strong></span>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="row mb-3 dashboard-events-row">
                            <div class="col-12">
                                <div class="card hotspot-log-card h-100 shadow-sm">
                                    <div class="card-header standard-card-header">
                                        <i class="fas fa-stream me-2"></i>Derniers Événements
                                    </div>
                                    <div class="card-body py-3 dashboard-events-card-body">
                                        <div class="dashboard-events-table-wrap">
                                            <table class="table table-striped table-hover table-dark table-sm mb-0 recent-events-table table-standard">
                                                <thead>
                                                    <tr>
                                                        <th class="text-white-50 text-start" style="white-space: nowrap;">Heure</th>
                                                        <th class="text-white-50">User</th>
                                                        <th class="text-white-50" title="Message">Message</th>
                                                    </tr>
                                                </thead>
                                                <tbody id="recentEventsTableBody">
                                                    <tr>
                                                        <td class="recent-time-cell"><span class="recent-time-date">-</span><span class="recent-time-hour">-</span></td>
                                                        <td class="recent-user-cell">-</td>
                                                        <td>-</td>
                                                    </tr>
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                    </div>
                </div>
            </div>
        </div>
    </div>
    
<?php
$extraJs = array (
  0 => '../js/dashboard.js?v=20261012l',
);
require_once '../includes/layout_footer.php';
?>
<script>
/* État WAN lu sur le routeur (lecture seule), même source que la pastille de traffic_monitoring.php. */
(function () {
    var valueEl = document.getElementById('dashboardWanValue');
    var labelEl = document.getElementById('dashboardWanLabel');
    var detailEl = document.getElementById('dashboardWanDetail');
    var tileEl = document.getElementById('dashboardWanTile');
    if (!valueEl || !tileEl) return;
    var labels = {
        NORMAL: ['Normal', '#22c55e'],
        DEGRADED: ['Dégradé', '#f59e0b'],
        DOWN: ['Hors service', '#ef4444'],
    };
    fetch('../api/wan_state_status.php', { credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (d) {
            if (!d.success) {
                if (labelEl) labelEl.textContent = 'Indisponible';
                if (detailEl) detailEl.textContent = 'Lecture impossible';
                return;
            }
            var l = labels[d.state] || [d.state, '#17a2b8'];
            if (labelEl) labelEl.textContent = l[0];
            tileEl.style.setProperty('--card-accent', l[1]);
            valueEl.textContent = (d.usable ?? '-') + '/' + (d.total ?? '-');
            if (detailEl) detailEl.textContent = (d.usable ?? '-') + ' / ' + (d.total ?? '-') + ' WAN utilisables';
        })
        .catch(function () {
            if (labelEl) labelEl.textContent = 'Indisponible';
            if (detailEl) detailEl.textContent = 'Lecture impossible';
        });
})();

/* Consommation par Kit (WAN) : lecture seule depuis la base locale (meme table que
   traffic_monitoring.php), pas d'appel RouterOS. Pas de quota ici : les barres montrent la part
   de chaque Kit dans le trafic total, pas une limite a ne pas depasser. */
(function () {
    var tilesEl = document.getElementById('dashboardKitTiles');
    var barEl = document.getElementById('dashboardKitGlobalBar');
    if (!tilesEl || !barEl) return;
    var identityAccents = ['#38bdf8', '#a78bfa', '#22c55e', '#f59e0b'];

    function formatBytes(bytes) {
        bytes = Number(bytes) || 0;
        if (bytes >= 1e12) return (bytes / 1e12).toFixed(2).replace('.', ',') + ' To';
        if (bytes >= 1e9) return (bytes / 1e9).toFixed(2).replace('.', ',') + ' Go';
        if (bytes >= 1e6) return (bytes / 1e6).toFixed(1).replace('.', ',') + ' Mo';
        return bytes + ' o';
    }

    function shortenKitLabel(label) {
        return String(label || '').split('·')[0].trim();
    }

    fetch('../api/wan_usage_summary.php', { credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (d) {
            if (!d.success || !Array.isArray(d.kits) || d.kits.length === 0) {
                tilesEl.innerHTML = '<p class="text-white-50 mb-0">Aucun Kit WAN actif configuré.</p>';
                barEl.innerHTML = '';
                barEl.title = 'Consommation WAN cumulée / cible journalière cumulée — indisponible';
                return;
            }
            var enabledKits = d.kits.filter(function (kit) { return kit.enabled; });
            var total = enabledKits.reduce(function (sum, kit) { return sum + (Number(kit.day_bytes) || 0); }, 0);
            tilesEl.style.gridTemplateColumns = 'repeat(' + Math.min(4, d.kits.length) + ', minmax(0, 1fr))';

            /* Vraie donnee : consommation WAN cumulee / cible journaliere cumulee, meme logique que
               wanCapTargetTotals()/renderGlobalMonthlyCap() dans traffic_monitoring.js - seuls les
               Kits avec une cible journaliere definie (daily_target_gb > 0) comptent. */
            var cappedKits = enabledKits.filter(function (kit) { return Number(kit.daily_target_gb) > 0; });
            var targetGb = cappedKits.reduce(function (sum, kit) { return sum + Number(kit.daily_target_gb); }, 0);
            var consumedBytes = cappedKits.reduce(function (sum, kit) { return sum + (Number(kit.day_bytes) || 0); }, 0);
            if (targetGb > 0) {
                var targetBytes = targetGb * 1e9;
                var ratio = consumedBytes / targetBytes;
                var pctCap = Math.max(0, Math.min(100, ratio * 100));
                if (pctCap > 0 && pctCap < 1.5) pctCap = 1.5;
                barEl.innerHTML = '<span style="width:' + (100 - pctCap).toFixed(2) + '%"></span>';
                barEl.title = 'Consommation WAN cumulée / cible journalière cumulée — ' + formatBytes(consumedBytes) + ' / ' + targetGb + ' Go (' + pctCap.toFixed(1) + '%)';
            } else {
                barEl.innerHTML = '';
                barEl.title = 'Consommation WAN cumulée / cible journalière cumulée — aucune cible journalière définie';
            }
            tilesEl.innerHTML = d.kits.map(function (kit, i) {
                var slotCode = String(kit.slot || '').toUpperCase();
                if (!kit.enabled) {
                    return '' +
                        '<article class="traffic-overview-check is-kit-disabled" style="--kit-ratio:0" title="' + shortenKitLabel(kit.label).replace(/"/g, '') + '">' +
                            '<div class="traffic-card-copy">' +
                                '<span class="traffic-card-node">' + slotCode + '</span>' +
                                '<small>Réserve · Non configuré</small>' +
                            '</div>' +
                            '<div class="traffic-card-metric">' +
                                '<span class="traffic-card-icon"><i class="fas fa-ban" aria-hidden="true"></i></span>' +
                                '<strong>--</strong>' +
                            '</div>' +
                        '</article>';
                }
                var bytes = Number(kit.day_bytes) || 0;
                var sharePct = total > 0 ? (bytes / total * 100) : 0;
                var kitTargetGb = Number(kit.daily_target_gb) || 0;
                /* La barre/couleur refletent la consommation du Kit par rapport a SA PROPRE cible
                   journaliere (ex. 90 Go / 400 Go = 22.5%), pas sa part dans le trafic total du
                   jour - sinon un Kit avec une grosse cible mais peu de trafic relatif aux autres
                   Kits paraissait "presque plein" a tort. */
                var quotaPct = kitTargetGb > 0 ? Math.min(100, (bytes / (kitTargetGb * 1e9)) * 100) : 0;
                var identity = identityAccents[i % identityAccents.length];
                var quotaLabel = kitTargetGb > 0
                    ? (quotaPct.toFixed(1) + '% / ' + kitTargetGb + ' Go')
                    : (sharePct.toFixed(1) + '% du trafic (pas de cible)');
                return '' +
                    '<article class="traffic-overview-check" style="--card-accent:' + identity + ';--kit-ratio:' + quotaPct.toFixed(2) + '" title="' + shortenKitLabel(kit.label).replace(/"/g, '') + '">' +
                        '<div class="traffic-card-copy">' +
                            '<span class="traffic-card-node">' + slotCode + '</span>' +
                            '<small>' + quotaLabel + '</small>' +
                        '</div>' +
                        '<div class="traffic-card-metric">' +
                            '<span class="traffic-card-icon"><i class="fas fa-satellite-dish" aria-hidden="true"></i></span>' +
                            '<strong>' + formatBytes(bytes) + '</strong>' +
                        '</div>' +
                    '</article>';
            }).join('');
        })
        .catch(function () {
            tilesEl.innerHTML = '<p class="text-white-50 mb-0">Lecture impossible.</p>';
            barEl.title = 'Consommation WAN cumulée / cible journalière cumulée — indisponible';
        });
})();
</script>
