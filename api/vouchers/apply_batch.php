<?php

require_once '../../config/db.php';
require_once '../../includes/vouchers.php';
require_once '../../includes/device_manager.php';
require_once '../../includes/license.php';
require_once '../../includes/backend_agent.php';

header('Content-Type: application/json');

session_start();

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Non autorisé']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Méthode non autorisée']);
    exit;
}

$csrf = trim((string)($_POST['csrf_token'] ?? ''));
if ($csrf === '' || !hash_equals($_SESSION['csrf_token'] ?? '', $csrf)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'CSRF invalide']);
    exit;
}

$batch = $_SESSION['pending_voucher_batch'] ?? null;

if (!$batch || empty($batch['entries'])) {
    echo json_encode(['success' => false, 'message' => 'Aucun lot à appliquer']);
    exit;
}

try {
    $deviceStore = loadDeviceStore();
    $device = findDeviceById($deviceStore, (string)($batch['device_id'] ?? ''));

    if (!$device) {
        throw new RuntimeException('Serveur introuvable');
    }

    requireDeviceLicensed($device);
    $licenseDeviceId = formatDeviceId((string)($device['device_fingerprint'] ?? ''), (string)($device['type'] ?? 'dev'));
    backendAgentAuthorizeAction('voucher-apply-batch', $licenseDeviceId, [
        'count' => count($batch['entries'] ?? []),
        'profile_name' => (string)($batch['profile_name'] ?? ''),
        'device_type' => (string)($device['type'] ?? ''),
    ]);

    $batch['printed_by'] = trim((string)($_SESSION['username'] ?? ''));
    $saveResult = savePreparedVoucherBatch($pdo, $batch);
    recordVoucherBatchHistory($pdo, $batch, $saveResult);

    $voucherIds = $saveResult['ids'] ?? [];
    $_SESSION['last_printed_voucher_ids'] = $voucherIds;
    $_SESSION['last_printed_voucher_ticket_options'] = $batch['ticket_options'] ?? [];
    $_SESSION['last_printed_voucher_profile_defaults'] = is_array($batch['profile_defaults'] ?? null) ? $batch['profile_defaults'] : [];
    $_SESSION['last_printed_voucher_profile_name'] = (string)($batch['profile_name'] ?? '');
    unset($_SESSION['pending_voucher_batch']);

    echo json_encode([
        'success' => true,
        'voucher_ids' => $voucherIds,
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
    ]);
}
