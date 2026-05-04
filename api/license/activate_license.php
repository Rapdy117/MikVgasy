<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/device_manager.php';
require_once __DIR__ . '/../../includes/license.php';
require_once __DIR__ . '/../../includes/backend_agent.php';

session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['logged_in'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Non autorisé']);
    exit;
}

function require_valid_csrf_token(): void
{
    $token = trim((string)($_POST['csrf_token'] ?? ''));
    if ($token === '' || !hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'CSRF invalide']);
        exit;
    }
}

require_valid_csrf_token();

try {
    $storeDeviceId = trim((string)($_POST['store_device_id'] ?? ''));
    $licenseKey    = trim((string)($_POST['license_key']     ?? ''));

    if ($storeDeviceId === '' || $licenseKey === '') {
        throw new RuntimeException('Paramètres manquants.');
    }

    $store  = loadDeviceStore();
    $device = findDeviceById($store, $storeDeviceId);

    if ($device === null) {
        throw new RuntimeException('Device introuvable.');
    }

    $fingerprint = trim((string)($device['device_fingerprint'] ?? ''));
    if ($fingerprint === '') {
        throw new RuntimeException('Ce device n\'a pas encore été identifié (fingerprint absent). Testez la connexion d\'abord.');
    }

    $deviceType = (string)($device['type'] ?? 'dev');
    $deviceId   = formatDeviceId($fingerprint, $deviceType);
    $activation = backendAgentActivateLicense($licenseKey, $deviceId);
    backendAgentCheckLicense($deviceId);

    /* Mise à jour du device dans le store */
    foreach ($store['devices'] as &$d) {
        if (($d['id'] ?? '') === $storeDeviceId) {
            $d['license_key']    = $licenseKey;
            $d['license_status'] = 'active';
            $d['license_expiry'] = (string)($activation['data']['expires_at'] ?? 'never');
            $d['license_issued'] = date('Y-m-d');
            break;
        }
    }
    unset($d);

    saveDeviceStore($store);

    echo json_encode([
        'success'    => true,
        'message'    => 'Routeur licencié avec succès.',
        'device_id'  => $deviceId,
        'expiry'     => $activation['data']['expires_at'] ?? 'never',
        'issued'     => null,
        'status'     => 'active',
    ]);

} catch (\Throwable $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
