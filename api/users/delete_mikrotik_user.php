<?php
require '../../config/db.php';
require_once '../../includes/auth.php';
require_once '../../includes/mikrotik_backend.php';
require_once '../../includes/operation_history.php';
require_once '../../includes/backend_agent.php';

session_start();

header('Content-Type: application/json');

function post_string_or_null(string $key): ?string
{
    $value = trim((string)($_POST[$key] ?? ''));
    return $value === '' ? null : $value;
}

function require_valid_csrf(): void
{
    $token = trim((string)($_POST['csrf_token'] ?? ''));
    if ($token === '' || !hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        throw new RuntimeException('CSRF invalide');
    }
}

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'message' => 'Unauthorized',
    ]);
    exit;
}
if (!isAdministrator()) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'message' => 'Accès réservé à l administrateur',
    ]);
    exit;
}

try {
    require_valid_csrf();

    $username = post_string_or_null('username');
    $deviceId = post_string_or_null('device_id');
    if ($username === null) {
        throw new RuntimeException('Utilisateur manquant.');
    }
    if ($deviceId === null) {
        throw new RuntimeException('Serveur requis.');
    }

    $deviceStore = loadDeviceStore();
    $device = findDeviceById($deviceStore, $deviceId);
    if (!is_array($device) || normalizeDeviceType((string)($device['type'] ?? '')) !== 'mikrotik') {
        throw new RuntimeException('Serveur MikroTik introuvable.');
    }

    requireDeviceLicensed($device);
    backendAgentAuthorizeDeviceAction($device, 'mikrotik-delete-user', [
        'username' => $username,
    ]);

    $nasContext = [
        'device' => $device,
        'device_type' => 'mikrotik',
        'business_source' => 'mikrotik_local',
        'backend_driver' => 'mikrotik_api',
    ];
    deleteUserFromMikrotik($username, $nasContext);

    recordOperationHistory($pdo, [
        'operation_scope' => 'admin',
        'operation_type' => 'user_delete',
        'actor_username' => (string)($_SESSION['username'] ?? ''),
        'actor_role' => (string)($_SESSION['user_role'] ?? 'administrator'),
        'target_type' => 'user',
        'target_name' => $username,
        'summary' => 'Utilisateur MikroTik supprimé',
        'details_json' => [
            'backend_driver' => 'mikrotik_api',
            'device_id' => $deviceId,
        ],
    ]);

    echo json_encode([
        'success' => true,
        'message' => 'Utilisateur MikroTik supprime.',
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
    ]);
}
