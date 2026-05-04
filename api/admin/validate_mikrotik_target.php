<?php

session_start();

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/device_manager.php';
require_once __DIR__ . '/../../includes/admin_mikrotik_standard_runtime.php';

header('Content-Type: application/json; charset=UTF-8');

function validateMikrotikTargetFail(int $statusCode, string $message): void
{
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode([
        'success' => false,
        'message' => $message,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function require_valid_csrf(): void
{
    $token = trim((string)($_POST['csrf_token'] ?? ''));
    if ($token === '' || !hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        throw new RuntimeException('CSRF invalide');
    }
}

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    validateMikrotikTargetFail(403, 'Unauthorized');
}

if (!isAdministrator()) {
    validateMikrotikTargetFail(403, 'Accès réservé à l administrateur');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    validateMikrotikTargetFail(405, 'Methode non autorisee.');
}

try {
    require_valid_csrf();

    $deviceId = trim((string)($_POST['device_id'] ?? ''));
    if ($deviceId === '') {
        throw new RuntimeException('Serveur cible requis.');
    }

    $store = loadDeviceStore();
    $device = findDeviceById($store, $deviceId);
    if (!is_array($device)) {
        throw new RuntimeException('Serveur cible introuvable.');
    }

    if (normalizeDeviceType((string)($device['type'] ?? '')) !== 'mikrotik') {
        throw new RuntimeException('Le device cible n est pas de type MikroTik.');
    }

    $targetInfo = adminMikrotikStandardReadTargetInfo($device);

    echo json_encode([
        'success' => true,
        'message' => 'Routeur MikroTik cible joignable.',
        'device_id' => $deviceId,
        'router_identity' => $targetInfo['router_identity'] ?? null,
        'address_pools' => $targetInfo['address_pools'] ?? [],
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
} catch (Throwable $e) {
    validateMikrotikTargetFail(500, $e->getMessage());
}
