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
$extraHeadJs = array (
  0 => '../assets/vendor/chart/chart.umd.min.js',
  1 => '../assets/vendor/chart/moment-with-locales.min.js',
  2 => '../assets/vendor/chart/chartjs-adapter-moment.min.js',
  3 => '../assets/vendor/chart/chartjs-plugin-streaming.js',
);
require_once '../includes/layout_header.php';
?>

<div id="messageArea" style="display: none;"></div>

                <div class="row dashboard-layout">
                    <div class="col-lg-7 col-md-12 mb-3">

                        <div class="row mb-3">
                            <div class="col-12">
                                <div class="card hotspot-card h-100 shadow-sm">
                                    <div class="card-body py-3">
                                        <div class="d-flex align-items-center mb-2 text-white" style="font-size: calc(0.875rem + 2px);">
                                            <i class="fas fa-wifi me-2"></i>
                                            <span class="small fw-semibold">Hotspot</span>
                                        </div>
                                        <div class="row g-3 hotspot-cards-container">
                                            <div class="col-6 col-md-4 col-lg-3">
                                                <div class="card card-shortcut h-100" onclick="redirectTo('sessions_list.php');">
                                                    <div class="card-body text-center d-flex flex-column justify-content-center align-items-center py-3">
                                                        <div class="d-flex justify-content-center align-items-center mb-2">
                                                            <i class="fas fa-wifi fa-2x text-primary me-2"></i>
                                                            <p class="card-text fw-bold text-white fs-4 mb-0" id="activeHotspotUsersCount">--</p>
                                                        </div>
                                                        <h6 class="card-title text-white small">Sessions actives</h6>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="col-6 col-md-4 col-lg-3">
                                                <div class="card card-shortcut h-100" onclick="redirectTo('users_list.php');">
                                                    <div class="card-body text-center d-flex flex-column justify-content-center align-items-center py-3">
                                                        <div class="d-flex justify-content-center align-items-center mb-2">
                                                            <i class="fas fa-users fa-2x text-info me-2"></i>
                                                            <p class="card-text fw-bold text-white fs-4 mb-0" id="connectedUsersCount">--</p>
                                                        </div>
                                                        <h6 class="card-title text-white small">Tous les utilisateurs</h6>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="col-6 col-md-4 col-lg-3">
                                                <div class="card card-shortcut h-100" onclick="redirectTo('add_hotspot_user.php');">
                                                    <div class="card-body text-center d-flex flex-column justify-content-center align-items-center py-3">
                                                        <i class="fas fa-user-plus fa-2x mb-2 text-success"></i>
                                                        <h6 class="card-title text-white small">Ajouter Utilisateur</h6>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="col-6 col-md-4 col-lg-3">
                                                <div class="card card-shortcut h-100" onclick="redirectTo('generate.php');">
                                                    <div class="card-body text-center d-flex flex-column justify-content-center align-items-center py-3">
                                                        <i class="fas fa-ticket-alt fa-2x mb-2 text-info"></i>
                                                        <h6 class="card-title text-white small">Génération Ticket</h6>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="row mb-3 dashboard-bandwidth-row">
                            <div class="col-lg-8 col-md-12 mb-3 mb-lg-0">
                                <div class="card shadow-sm h-100">
                                    <div class="card-body py-3">
                                        <div class="d-flex align-items-center mb-2 text-white" style="font-size: calc(0.875rem + 2px);">
                                            <i class="fas fa-chart-area me-2"></i>
                                            <span class="small fw-semibold">Bande Passante Live</span>
                                        </div>
                                        <div id="bandwidthChartContainer" class="traffic-live-grid traffic-live-grid-stacked">
                                            <div class="traffic-live-panel traffic-live-panel-download">
                                                <div class="traffic-live-header">
                                                    <span class="traffic-live-title">Download</span>
                                                    <strong class="traffic-live-value traffic-live-value-download" id="downloadRateLive">--</strong>
                                                </div>
                                                <div class="traffic-live-canvas-wrap">
                                                    <canvas id="downloadTrafficChart" class="traffic-live-canvas traffic-live-canvas-download" aria-label="Courbe download"></canvas>
                                                </div>
                                            </div>
                                            <div class="traffic-live-panel traffic-live-panel-upload">
                                                <div class="traffic-live-header">
                                                    <span class="traffic-live-title">Upload</span>
                                                    <strong class="traffic-live-value traffic-live-value-upload" id="uploadRateLive">--</strong>
                                                </div>
                                                <div class="traffic-live-canvas-wrap">
                                                    <canvas id="uploadTrafficChart" class="traffic-live-canvas traffic-live-canvas-upload" aria-label="Courbe upload"></canvas>
                                                </div>
                                            </div>
                                        </div>
                                        <p class="traffic-legend-text text-end mt-2 mb-0" id="bandwidthAdditionalInfo">
                                            Fenetre live : -- | Refresh : --
                                        </p>
                                        <p class="traffic-legend-text traffic-legend-text-small text-end mt-1 mb-0" id="trafficInterfacesInfo">
                                            Interfaces : --
                                        </p>
                                    </div>
                                </div>
                            </div>
                            <div class="col-lg-4 col-md-12">
                                <div class="card shadow-sm h-100">
                                    <div class="card-body py-3">
                                        <div class="d-flex align-items-center mb-2 text-white" style="font-size: calc(0.875rem + 2px);">
                                            <i class="fas fa-cash-register me-2"></i>
                                            <span class="small fw-semibold">Bilan</span>
                                        </div>
                                        <div class="sales-inline-card sales-inline-card-standalone">
                                            <div class="dashboard-summary-list">
                                                <div class="dashboard-summary-item">
                                                    <span class="dashboard-summary-label">Montant du jour</span>
                                                    <div class="text-end flex-shrink-0">
                                                        <strong class="dashboard-summary-value d-block" id="summarySalesToday">--</strong>
                                                        <span class="sales-summary-meta" id="summarySalesTodayMeta">--</span>
                                                    </div>
                                                </div>
                                                <div class="dashboard-summary-item">
                                                    <span class="dashboard-summary-label">Montant mensuel</span>
                                                    <div class="text-end flex-shrink-0">
                                                        <strong class="dashboard-summary-value d-block" id="summarySalesMonthly">--</strong>
                                                        <span class="sales-summary-meta" id="summarySalesMonthlyMeta">--</span>
                                                    </div>
                                                </div>
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
                        <div class="row mb-3 dashboard-device-row">
                            <div class="col-12">
                                <div class="card revenue-card h-100 shadow-sm">
                                    <div class="card-body py-3">
                                        <div class="d-flex align-items-center mb-2 text-white" style="font-size: calc(0.875rem + 2px);">
                                            <i class="fas fa-network-wired me-2"></i>
                                            <span class="small fw-semibold" id="deviceSummaryTitle">Device actif</span>
                                            <span class="small text-white-50 ms-2" id="deviceSummarySubtitle"></span>
                                        </div>
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
                                    <div class="card-body py-3 dashboard-events-card-body">
                                        <div class="d-flex align-items-center justify-content-start text-start mb-2 text-white" style="font-size: calc(0.875rem + 2px);">
                                            <i class="fas fa-stream me-2"></i>
                                            <span class="small fw-semibold">Derniers Événements</span>
                                        </div>
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
  0 => '../js/dashboard.js',
);
require_once '../includes/layout_footer.php';
?>
