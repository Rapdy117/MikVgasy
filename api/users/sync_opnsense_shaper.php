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

if (!isset($_SESSION['logged_in'])) {
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

$username = post_string_or_null('username');
$interface = post_string_or_null('interface');

if ($username === null) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Username manquant',
    ]);
    exit;
}

try {
    $result = syncOpnsenseUserShaper($pdo, $username, $interface);

    recordOperationHistory($pdo, [
        'operation_scope' => 'admin',
        'operation_type' => 'user_update',
        'actor_username' => (string)($_SESSION['username'] ?? ''),
        'actor_role' => (string)($_SESSION['user_role'] ?? 'administrator'),
        'target_type' => 'user',
        'target_name' => (string)($result['user']['username'] ?? $username),
        'target_ref' => isset($result['user']['id']) ? (string)$result['user']['id'] : null,
        'device_id' => (string)($result['device']['id'] ?? ''),
        'profile_name' => (string)($result['user']['profile_name'] ?? ''),
        'summary' => 'Shaper OPNsense synchronise',
        'details_json' => [
            'interface' => (string)($result['interface'] ?? ''),
            'session_ip' => (string)($result['session']['ipAddress'] ?? ''),
            'profile_rate_limit' => (string)($result['rate_limit'] ?? ''),
            'pipe_down' => (string)($result['pipes']['down']['description'] ?? ''),
            'pipe_up' => (string)($result['pipes']['up']['description'] ?? ''),
            'rule_down' => (string)($result['rules']['down']['description'] ?? ''),
            'rule_up' => (string)($result['rules']['up']['description'] ?? ''),
        ],
    ]);

    echo json_encode([
        'success' => true,
        'message' => 'Shaper OPNsense synchronise',
        'username' => (string)($result['user']['username'] ?? $username),
        'profile_name' => (string)($result['user']['profile_name'] ?? ''),
        'session_ip' => (string)($result['session']['ipAddress'] ?? ''),
        'interface' => (string)($result['interface'] ?? ''),
        'rate_limit' => (string)($result['rate_limit'] ?? ''),
        'pipes' => [
            'down' => (string)($result['pipes']['down']['description'] ?? ''),
            'up' => (string)($result['pipes']['up']['description'] ?? ''),
        ],
        'rules' => [
            'down' => (string)($result['rules']['down']['description'] ?? ''),
            'up' => (string)($result['rules']['up']['description'] ?? ''),
        ],
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
    ]);
}
