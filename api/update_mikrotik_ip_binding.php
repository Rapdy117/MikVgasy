<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/mikrotik_backend.php';

session_start();

header('Content-Type: application/json');

function post_string(string $key): string
{
    return trim((string)($_POST[$key] ?? ''));
}

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'message' => 'Unauthorized',
    ]);
    exit;
}

$csrfToken = post_string('csrf_token');
if ($csrfToken === '' || !hash_equals((string)($_SESSION['csrf_token'] ?? ''), $csrfToken)) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'CSRF invalide',
    ]);
    exit;
}

try {
    requireMikrotikNasContextForActiveDevice();
    $bindingId = post_string('binding_id');
    if ($bindingId === '') {
        throw new RuntimeException('Binding introuvable.');
    }

    updateMikrotikIpBinding($bindingId, [
        'address' => post_string('address'),
        'mac' => post_string('mac'),
        'to_address' => post_string('to_address'),
        'type' => post_string('type'),
        'server' => post_string('server'),
        'comment' => post_string('comment'),
        'disabled' => post_string('disabled') === '1',
    ]);

    echo json_encode([
        'success' => true,
        'message' => 'IP binding mis à jour.',
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
    ]);
}
