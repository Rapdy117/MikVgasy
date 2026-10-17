<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/device_manager.php';
require_once __DIR__ . '/../../includes/license.php';

session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['logged_in']) || !isAdministrator()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Activation réservée à l’administration']);
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
    $device = requireAccessibleDevice($store, $storeDeviceId);

    if ($device === null) {
        throw new RuntimeException('Device introuvable.');
    }

    $fingerprint = trim((string)($device['device_fingerprint'] ?? ''));
    if ($fingerprint === '') {
        throw new RuntimeException('Ce device n\'a pas encore été identifié (fingerprint absent). Testez la connexion d\'abord.');
    }

    $result = validateLicenseKey($licenseKey, $fingerprint);

    if ($result['expired']) {
        throw new RuntimeException('Cette clé de licence est expirée (expirée le ' . $result['expiry'] . ').');
    }

    if (!$result['valid']) {
        throw new RuntimeException('Clé de licence invalide. Vérifiez qu\'elle correspond bien à ce routeur.');
    }

    ensureLicenseRegistry($pdo);
    $registry = $pdo->prepare('SELECT id FROM device_licenses WHERE device_id=? AND license_key=? LIMIT 1');
    $registry->execute([$storeDeviceId, $licenseKey]);
    if (!$registry->fetchColumn()) {
        throw new RuntimeException('Cette licence n’est pas enregistrée pour ce Device.');
    }
    /* Mise à jour ciblée : ne jamais réattribuer le Device à l’opérateur courant. */
    $update = $pdo->prepare("UPDATE network_devices SET license_key=?, license_status='active', license_expiry=?, license_issued=?, updated_at=NOW() WHERE id=? AND is_active=1");
    $update->execute([$licenseKey, $result['expiry'], $result['issued'] ?? date('Y-m-d'), $storeDeviceId]);
    if ($update->rowCount() < 1) throw new RuntimeException('Device inactif ou introuvable.');

    markLicenseActivated($pdo, $storeDeviceId, $licenseKey, (string)$result['expiry']);

    $deviceType = (string)($device['type'] ?? 'dev');
    $deviceId   = formatDeviceId($fingerprint, $deviceType);

    echo json_encode([
        'success'    => true,
        'message'    => 'Routeur licencié avec succès.',
        'device_id'  => $deviceId,
        'expiry'     => $result['expiry'],
        'issued'     => $result['issued'] ?? null,
        'status'     => 'active',
    ]);

} catch (\Throwable $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
