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
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Surveillance du Trafic</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@300;400;500;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <script src="../assets/vendor/chart/chart.umd.min.js"></script>
    <script src="../assets/vendor/chart/moment-with-locales.min.js"></script>
    <script src="../assets/vendor/chart/chartjs-adapter-moment.min.js"></script>
    <script src="../assets/vendor/chart/chartjs-plugin-streaming.js"></script>
    <link rel="stylesheet" href="../css/theme.css">
    <link rel="stylesheet" href="../css/traffic_monitoring.css?v=20260403a">
</head>

<body>

    <div class="d-flex" id="wrapper">
        <?php include '../includes/sidebar.php'; ?>

        <div id="page-content-wrapper">
            <div class="container-fluid py-3">
                <?php display_message(); ?>
                <div id="messageArea" style="display: none;"></div>
                <input type="hidden" id="trafficSourceLabel" value="<?= htmlspecialchars($activeDeviceLabel) ?>">
                <input type="hidden" id="trafficSourceHost" value="<?= htmlspecialchars($activeDeviceHost) ?>">
                <input type="hidden" id="trafficSourceType" value="<?= htmlspecialchars($activeDeviceType) ?>">

                <div class="row">
                    <div class="col-12 mb-4">
                        <div class="card shadow-sm h-100">
                            <div class="card-header standard-card-header d-flex align-items-center justify-content-between flex-wrap gap-2">
                                <div class="d-flex align-items-center text-truncate">
                                    <i class="fas fa-chart-area me-2 flex-shrink-0"></i>
                                    <span>Bande Passante Live</span>
                                </div>
                                <div class="traffic-toolbar">
                                    <div class="input-group traffic-source-group">
                                        <span class="input-group-text">Source monitoring</span>
                                        <select class="form-select" id="trafficInterfaceSelect">
                                            <option value="">Chargement...</option>
                                        </select>
                                    </div>
                                    <div class="input-group traffic-source-group traffic-refresh-group">
                                        <span class="input-group-text">Rafraîchissement</span>
                                        <select class="form-select" id="trafficRefreshSelect">
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
    <div id="spinner-overlay">
        <div class="spinner"></div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../js/sidebar.js?v=20260402a"></script>
    <script src="../js/traffic_monitoring.js"></script>
</body>
</html>
