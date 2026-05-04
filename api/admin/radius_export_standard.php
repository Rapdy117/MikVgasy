<?php

session_start();

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/operation_history.php';
require_once __DIR__ . '/../../includes/radius_standard_io.php';

function radiusExportStandardFail(int $statusCode, string $message): void
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
    radiusExportStandardFail(403, 'Unauthorized');
}

if (!isAdministrator()) {
    radiusExportStandardFail(403, 'Seul l administrateur peut exporter la configuration OPNsense / RADIUS.');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    radiusExportStandardFail(405, 'Methode non autorisee.');
}

$csrf = trim((string)($_POST['csrf_token'] ?? ''));
if ($csrf === '' || !hash_equals($_SESSION['csrf_token'] ?? '', $csrf)) {
    radiusExportStandardFail(403, 'CSRF invalide');
}

try {
    $target = radiusStandardResolveTarget($pdo, (string)($_POST['device_id'] ?? ''));
    $device = $target['device'];
    $nasContext = $target['nas_context'];
    $sourceBackend = (string)$target['source_backend'];
    $document = buildRadiusStandardExportDocument($pdo, $nasContext);
    $profileCount = count($document['profiles'] ?? []);
    $userCount = count($document['users'] ?? []);
    $deviceId = trim((string)($device['id'] ?? ''));

    recordOperationHistory($pdo, [
        'operation_scope' => 'admin',
        'operation_type' => 'standard_export_radius_v2',
        'actor_username' => (string)($_SESSION['username'] ?? ''),
        'actor_role' => (string)($_SESSION['user_role'] ?? 'administrator'),
        'target_type' => 'device',
        'target_name' => (string)($device['name'] ?? $device['host'] ?? $deviceId),
        'target_ref' => $deviceId,
        'device_id' => $deviceId,
        'summary' => 'Export standard OPNsense / RADIUS v2',
        'details_json' => [
            'source_backend' => $sourceBackend,
            'nas_id' => (int)($nasContext['nas_id'] ?? 0),
            'nas_type' => (string)($nasContext['nas_type'] ?? ''),
            'profiles' => $profileCount,
            'users' => $userCount,
        ],
    ]);

    $baseName = trim((string)($device['name'] ?? ''));
    if ($baseName === '') {
        $baseName = trim((string)($device['host'] ?? $deviceId));
    }
    $baseName = preg_replace('/[^a-zA-Z0-9_-]+/', '_', $baseName);
    $fileName = $sourceBackend . '_standard_v2_' . $baseName . '_' . gmdate('Ymd_His') . '.json';

    header('Content-Type: application/json; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $fileName . '"');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');

    echo json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
} catch (Throwable $exception) {
    radiusExportStandardFail(500, $exception->getMessage());
}
