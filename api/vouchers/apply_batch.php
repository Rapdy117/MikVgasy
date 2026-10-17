<?php

require_once '../../config/db.php';
require_once '../../includes/vouchers.php';
require_once '../../includes/device_manager.php';
require_once '../../includes/license.php';

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
    $device = requireAccessibleDevice($deviceStore, (string)($batch['device_id'] ?? ''));

    if (!$device) {
        throw new RuntimeException('Serveur introuvable');
    }

    requireDeviceLicensed($device);

    $batch['printed_by'] = trim((string)($_SESSION['username'] ?? ''));
    $batch['printed_at'] = date('Y-m-d H:i:s');
    $startTime = microtime(true);
    $saveResult = savePreparedVoucherBatch($pdo, $batch);
    $saveDuration = microtime(true) - $startTime;
    error_log(sprintf('[PERF] savePreparedVoucherBatch: %.3f sec for %d entries', $saveDuration, count($saveResult['ids'] ?? [])));

    $voucherIds = $saveResult['ids'] ?? [];
    if (count($voucherIds) === 0) {
        throw new RuntimeException('Aucun voucher n a pu être enregistré. Vérifiez les doublons et réessayez.');
    }

    recordVoucherBatchHistory($pdo, $batch, $saveResult);

    $_SESSION['last_printed_voucher_ids'] = $voucherIds;
    $_SESSION['last_printed_voucher_ticket_options'] = $batch['ticket_options'] ?? [];
    $_SESSION['last_printed_voucher_profile_defaults'] = is_array($batch['profile_defaults'] ?? null) ? $batch['profile_defaults'] : [];
    $_SESSION['last_printed_voucher_profile_name'] = (string)($batch['profile_name'] ?? '');
    $_SESSION['last_printed_voucher_render_payload'] = [
        'items' => is_array($saveResult['entries'] ?? null) ? $saveResult['entries'] : [],
        'ticket_options' => is_array($batch['ticket_options'] ?? null) ? $batch['ticket_options'] : [],
        'profile_defaults' => is_array($batch['profile_defaults'] ?? null) ? $batch['profile_defaults'] : [],
        'profile_name' => (string)($batch['profile_name'] ?? ''),
    ];
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
