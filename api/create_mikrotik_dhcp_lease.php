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
    $payload = [
        'address' => post_string('address'),
        'mac' => post_string('mac'),
        'host_name' => post_string('host_name'),
        'server' => post_string('server'),
        'comment' => post_string('comment'),
        'disabled' => post_string('disabled') === '1',
    ];

    $action = syncMikrotikDhcpLease($payload);
    $message = $action === 'updated' ? 'Lease DHCP fixe mis à jour.' : 'Lease DHCP fixe ajouté.';

    echo json_encode([
        'success' => true,
        'message' => $message,
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
    ]);
}
