<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/device_manager.php';
require_once __DIR__ . '/../../includes/license.php';
require_once __DIR__ . '/../../includes/notification_service.php';

session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['logged_in'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Non autorisé']);
    exit;
}

try {
    $deviceId      = trim((string)($_POST['device_id']       ?? ''));
    $clientName    = trim((string)($_POST['client_name']     ?? ''));
    $clientEmail   = trim((string)($_POST['client_email']    ?? ''));
    $clientWa      = trim((string)($_POST['client_whatsapp'] ?? ''));

    if ($deviceId === '') {
        throw new RuntimeException('Device ID manquant.');
    }

    /* Retrouve le device depuis son device_id formaté */
    $store  = loadDeviceStore();
    $device = null;
    foreach ($store['devices'] as $d) {
        $fp   = trim((string)($d['device_fingerprint'] ?? ''));
        $type = trim((string)($d['type'] ?? 'dev'));
        if ($fp !== '' && formatDeviceId($fp, $type) === $deviceId) {
            $device = $d;
            break;
        }
    }

    if ($device === null) {
        throw new RuntimeException('Device introuvable pour ce Device ID.');
    }

    $fingerprint  = (string)($device['device_fingerprint'] ?? '');
    $deviceType   = (string)($device['type'] ?? '');
    $hwInfo       = is_array($device['hardware_info'] ?? null) ? $device['hardware_info'] : [];
    $serialNumber = trim((string)($hwInfo['serial']  ?? ''));
    $deviceModel  = trim((string)($hwInfo['board']   ?? $hwInfo['product'] ?? $deviceType));

    $request = [
        'device_id'       => $deviceId,
        'serial_number'   => $serialNumber,
        'device_type'     => $deviceType,
        'device_model'    => $deviceModel,
        'device_name'     => (string)($device['name'] ?? ''),
        'client_name'     => $clientName,
        'client_email'    => $clientEmail,
        'client_whatsapp' => $clientWa,
    ];

    /* Envoie les notifications */
    $notifResults = triggerLicenseRequestNotification($request);

    /* Lien wa.me pré-rempli pour que le client contacte l'admin */
    $config = loadNotificationConfig();
    $adminPhone = preg_replace('/[^0-9+]/', '', (string)($config['whatsapp']['admin_phone'] ?? ''));
    $waText = "Bonjour, je souhaite obtenir une licence pour mon routeur.\nDevice ID : {$deviceId}\nNom : {$clientName}";
    $waLink = $adminPhone !== '' ? 'https://wa.me/' . ltrim($adminPhone, '+') . '?text=' . rawurlencode($waText) : null;

    $adminEmail = trim((string)($config['email']['to_email'] ?? ''));
    $mailtoLink = $adminEmail !== '' ? 'mailto:' . $adminEmail . '?subject=' . rawurlencode("Demande de licence — {$deviceId}") . '&body=' . rawurlencode($waText) : null;

    echo json_encode([
        'success'       => true,
        'device_id'     => $deviceId,
        'notif_email'   => $notifResults['email']    ?? [],
        'notif_wa'      => $notifResults['whatsapp'] ?? [],
        'wa_link'       => $waLink,
        'mailto_link'   => $mailtoLink,
        'admin_phone'   => $adminPhone,
        'admin_email'   => $adminEmail,
    ]);

} catch (\Throwable $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
