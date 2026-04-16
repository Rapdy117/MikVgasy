<?php
require '../../config/db.php';
require_once '../../includes/device_manager.php';
require_once '../../includes/mikrotik_backend.php';
require_once '../../includes/user_schema.php';
require_once '../../includes/recharge_preview_service.php';

session_start();

header('Content-Type: application/json');

register_shutdown_function(static function (): void {
    $error = error_get_last();
    if ($error === null) {
        return;
    }

    $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
    if (!in_array($error['type'] ?? 0, $fatalTypes, true)) {
        return;
    }

    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'message' => 'Erreur PHP: ' . ($error['message'] ?? 'Fatal error'),
    ]);
});

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
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

try {
    require_valid_csrf();

    $deviceId = post_string_or_null('device_id');
    $username = post_string_or_null('username');
    $profileId = post_string_or_null('profile_id');
    $profileName = post_string_or_null('profile_name');
    $mode = post_string_or_null('mode');

    if ($deviceId === null || $username === null || $mode === null) {
        throw new RuntimeException('Champs obligatoires manquants');
    }

    if (!in_array($mode, ['replace_offer', 'extend_offer', 'accumulate_offer'], true)) {
        throw new RuntimeException('Mode invalide');
    }

    $store = loadDeviceStore();
    $device = findDeviceById($store, $deviceId);
    if (!$device) {
        throw new RuntimeException('Device introuvable');
    }

    $backendContext = resolveRechargeBackendContext($pdo, $device);
    $nasContext = $backendContext['nas_context'];
    $businessSource = $backendContext['business_source'];

    if ($businessSource === 'mikrotik_local') {
        if ($profileName === null) {
            throw new RuntimeException('Profil manquant');
        }
        $profileValue = $profileName;
    } else {
        if ($profileId === null) {
            throw new RuntimeException('Profil manquant');
        }
        $profileValue = $profileId;
    }

    $result = buildRechargePreview($pdo, $device, $username, $profileValue, $mode, $profileId, $profileName, $nasContext);
    echo json_encode(['success' => true] + $result);
    while (ob_get_level() > 0) {
        ob_end_flush();
    }
} catch (Throwable $e) {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
    ]);
}
