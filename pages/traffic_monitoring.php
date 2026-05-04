<?php
session_start(); // Démarre la session pour gérer l'état de connexion
require_once '../includes/device_manager.php';

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

?>

<?php
$pageTitle = 'Surveillance du Trafic';
$extraCss = [
    '../css/traffic_monitoring.css',
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

                <div class="row">
                    <div class="col-12 mb-4">
                        <div class="card shadow-sm h-100">
                            <div class="card-header standard-card-header traffic-monitoring-card-header d-flex align-items-center justify-content-between flex-nowrap gap-2 min-w-0">
                                <div class="d-flex align-items-center min-w-0 text-truncate flex-shrink-1 me-1 me-sm-2">
                                    <i class="fas fa-chart-area me-2 flex-shrink-0"></i>
                                    <span>Bande Passante Live</span>
                                </div>
                                <div class="d-flex align-items-stretch flex-nowrap gap-1 gap-sm-2 flex-shrink-0 min-w-0 traffic-toolbar" role="group" aria-label="Paramètres du graphique">
                                    <div class="input-group traffic-source-group traffic-monitoring-iface">
                                        <span class="input-group-text" title="Interface ou source de mesure" aria-hidden="true">
                                            <i class="fa fa-network-wired"></i>
                                        </span>
                                        <select class="form-select" id="trafficInterfaceSelect" aria-label="Source monitoring">
                                            <option value="">Chargement...</option>
                                        </select>
                                    </div>
                                    <div class="input-group traffic-source-group traffic-refresh-group">
                                        <span class="input-group-text" title="Période de rafraîchissement" aria-hidden="true">
                                            <i class="fa fa-clock"></i>
                                        </span>
                                        <select class="form-select" id="trafficRefreshSelect" aria-label="Période de rafraîchissement (secondes)">
                                            <option value="2000">2s</option>
                                            <option value="10000" selected>10s</option>
                                        </select>
                                    </div>
                                </div>
                            </div>
                            <div class="card-body py-3">
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
                                <p class="traffic-legend-text text-end mt-2 mb-0" id="bandwidthAdditionalInfo">Telemetrie live en attente...</p>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="row">
                    <div class="col-lg-4 col-md-6 mb-3">
                        <div class="card shadow-sm h-100">
                            <div class="card-body text-center">
                                <div class="text-white-50 small mb-2">Debit download actuel</div>
                                <div class="text-white fs-4 fw-bold" id="downloadRateMirror">--</div>
                            </div>
                        </div>
                    </div>
                    <div class="col-lg-4 col-md-6 mb-3">
                        <div class="card shadow-sm h-100">
                            <div class="card-body text-center">
                                <div class="text-white-50 small mb-2">Debit upload actuel</div>
                                <div class="text-white fs-4 fw-bold" id="uploadRateMirror">--</div>
                            </div>
                        </div>
                    </div>
                    <div class="col-lg-4 col-md-12 mb-3">
                        <div class="card shadow-sm h-100">
                            <div class="card-body text-center">
                                <div class="text-white-50 small mb-2">Derniere mise a jour</div>
                                <div class="text-white fs-5 fw-bold" id="trafficLastUpdate">--</div>
                            </div>
                        </div>
                    </div>
                </div>

            </div>
        </div>
        </div>
    
<?php
$extraJs = array (
  0 => '../js/traffic_monitoring.js',
);
require_once '../includes/layout_footer.php';
?>
