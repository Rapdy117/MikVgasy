<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/device_manager.php';
require_once __DIR__ . '/../includes/opnsense_ip_bindings.php';

session_start();

header('Content-Type: application/json');

function post_string(string $key): string
{
    return trim((string)($_POST[$key] ?? ''));
}

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$csrfToken = post_string('csrf_token');
if ($csrfToken === '' || !hash_equals((string)($_SESSION['csrf_token'] ?? ''), $csrfToken)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'CSRF invalide']);
    exit;
}

try {
    $device = requireActiveDeviceType('opnsense');
    updateOpnsenseIpBinding(
        $device,
        post_string('original_zone_uuid'),
        post_string('original_value'),
        [
            'zone_uuid' => post_string('zone_uuid'),
            'binding_value' => post_string('binding_value'),
            'type' => post_string('type'),
        ],
        post_string('original_kind')
    );

    echo json_encode([
        'success' => true,
        'message' => 'Bypass portail OPNsense mis a jour.',
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
    ]);
}
