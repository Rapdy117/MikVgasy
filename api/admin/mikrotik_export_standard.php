<?php

session_start();

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/device_manager.php';
require_once __DIR__ . '/../../includes/operation_history.php';
require_once __DIR__ . '/../../includes/mikrotik_standard_io.php';

function mikrotikExportStandardFail(int $statusCode, string $message): void
{
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode([
        'success' => false,
        'message' => $message,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    mikrotikExportStandardFail(403, 'Unauthorized');
}

if (!isAdministrator()) {
    mikrotikExportStandardFail(403, 'Seul l administrateur peut exporter la configuration MikroTik.');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    mikrotikExportStandardFail(405, 'Methode non autorisee.');
}

$csrf = trim((string)($_POST['csrf_token'] ?? ''));
if ($csrf === '' || !hash_equals($_SESSION['csrf_token'] ?? '', $csrf)) {
    mikrotikExportStandardFail(403, 'CSRF invalide');
}

$deviceId = trim((string)($_POST['device_id'] ?? ''));
if ($deviceId === '') {
    mikrotikExportStandardFail(422, 'Device MikroTik obligatoire.');
}

try {
    $store = loadDeviceStore();
    $device = findDeviceById($store, $deviceId);
    if (!is_array($device)) {
        throw new RuntimeException('Device introuvable.');
    }

    if (deriveDeviceType($device) !== 'mikrotik') {
        throw new RuntimeException('Le device choisi n est pas de type MikroTik.');
    }

    $document = buildMikrotikStandardExportDocument($device);
    $profileCount = count($document['profiles'] ?? []);
    $userCount = count($document['users'] ?? []);

    recordOperationHistory($pdo, [
        'operation_scope' => 'admin',
        'operation_type' => 'mikrotik_export_standard_v2',
        'actor_username' => (string)($_SESSION['username'] ?? ''),
        'actor_role' => (string)($_SESSION['user_role'] ?? 'administrator'),
        'target_type' => 'device',
        'target_name' => (string)($device['name'] ?? $device['host'] ?? $deviceId),
        'target_ref' => $deviceId,
        'summary' => 'Export standard MikroTik v2',
        'details_json' => [
            'profiles' => $profileCount,
            'users' => $userCount,
        ],
    ]);

    $baseName = trim((string)($device['name'] ?? ''));
    if ($baseName === '') {
        $baseName = trim((string)($device['host'] ?? $deviceId));
    }
    $baseName = preg_replace('/[^a-zA-Z0-9_-]+/', '_', $baseName);
    $fileName = 'mikrotik_standard_v2_' . $baseName . '_' . gmdate('Ymd_His') . '.json';

    header('Content-Type: application/json; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $fileName . '"');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');

    echo json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
} catch (Throwable $e) {
    mikrotikExportStandardFail(500, $e->getMessage());
}
