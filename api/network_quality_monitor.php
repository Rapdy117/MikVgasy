<?php

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/device_manager.php';
require_once __DIR__ . '/../includes/network_quality_monitor.php';

if (session_status() === PHP_SESSION_NONE) session_start();
header('Content-Type: application/json; charset=utf-8');

function networkQualityReply(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || !isAdministrator()) {
    networkQualityReply(['success' => false, 'message' => 'Accès réservé à l administrateur.'], 403);
}

$deviceId = trim((string)($_GET['device_id'] ?? ''));
$hours = (int)($_GET['hours'] ?? 24);

try {
    $device = requireAccessibleDevice(loadDeviceStore(), $deviceId);
    if (normalizeDeviceType((string)($device['type'] ?? '')) !== 'mikrotik') {
        throw new RuntimeException('Le device sélectionné n est pas un MikroTik.');
    }
    session_write_close();
    networkQualityReply([
        'success' => true,
        'device' => ['id' => $deviceId, 'name' => getDeviceDisplayLabel($device)],
        'report' => networkQualityReport($pdo, $deviceId, $hours),
        'captured_at' => date(DATE_ATOM),
    ]);
} catch (Throwable $error) {
    networkQualityReply([
        'success' => false,
        'message' => $error->getMessage(),
        'report' => null,
        'captured_at' => date(DATE_ATOM),
    ], 200);
}
