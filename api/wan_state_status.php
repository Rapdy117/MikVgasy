<?php
// Etat WAN actuel (lecture seule) pour l'affichage : calcule l'etat depuis le routeur, sans rien ecrire.
header('Content-Type: application/json');
session_start();
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/device_manager.php';
require_once __DIR__ . '/../includes/mikrotik_backend.php';
require_once __DIR__ . '/../includes/multi_wan_config.php';
require_once __DIR__ . '/../includes/wan_state.php';
if (empty($_SESSION['logged_in'])) { http_response_code(403); echo json_encode(['success' => false]); exit; }
try {
    $device = requireActiveDevice();
    $wans = [];
    foreach (multiWanLoadConfig($device)['sources'] ?? [] as $slot => $src) {
        if (!empty($src['enabled']) && trim((string)($src['interface'] ?? '')) !== '') {
            $wans[strtoupper((string)$slot)] = trim((string)$src['interface']);
        }
    }
    $api = connectToMikrotikApiByDevice($device);
    try {
        $read = wanStateReadRouter($api, $wans);
    } finally {
        $api->disconnect();
    }
    $state = $read['usable'] >= $read['total'] ? WAN_STATE_NORMAL : ($read['usable'] > 0 ? WAN_STATE_DEGRADED : WAN_STATE_DOWN);
    echo json_encode(['success' => true, 'state' => $state, 'usable' => $read['usable'], 'total' => $read['total']]);
} catch (Throwable $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
