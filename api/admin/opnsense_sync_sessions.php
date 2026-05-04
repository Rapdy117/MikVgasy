<?php

require '../../config/db.php';
require_once '../../includes/auth.php';
require_once '../../includes/opnsense_shaper.php';
require_once '../../includes/operation_history.php';

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
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'message' => 'CSRF invalide',
        ]);
        exit;
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
        'message' => 'Acces reserve a l administrateur',
    ]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'message' => 'Methode non autorisee',
    ]);
    exit;
}

require_valid_csrf();

$interface = post_string_or_null('interface');

try {
    $result = reconcileOpnsenseActiveSessions($pdo, $interface);

    recordOperationHistory($pdo, [
        'operation_scope' => 'admin',
        'operation_type' => 'system_update',
        'actor_username' => (string)($_SESSION['username'] ?? ''),
        'actor_role' => (string)($_SESSION['user_role'] ?? 'administrator'),
        'target_type' => 'system',
        'target_name' => 'opnsense_shaper',
        'summary' => 'Resynchronisation des sessions OPNsense',
        'details_json' => [
            'interface' => (string)($result['interface'] ?? ''),
            'sessions' => (int)($result['sessions'] ?? 0),
            'synced' => $result['synced'] ?? [],
            'skipped' => $result['skipped'] ?? [],
            'deleted_rules' => $result['deleted_rules'] ?? [],
        ],
    ]);

    echo json_encode([
        'success' => true,
        'message' => 'Synchronisation OPNsense lancee',
        'interface' => (string)($result['interface'] ?? ''),
        'sessions' => (int)($result['sessions'] ?? 0),
        'synced' => $result['synced'] ?? [],
        'skipped' => $result['skipped'] ?? [],
        'errors' => $result['errors'] ?? [],
        'deleted_rules' => $result['deleted_rules'] ?? [],
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
    ]);
}
