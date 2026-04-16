<?php
require '../../config/db.php';
require_once '../../includes/auth.php';
require_once '../../includes/mikrotik_backend.php';
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
    requireActiveDeviceType('mikrotik');

    $oldUsername = post_string_or_null('old_username');
    $username = post_string_or_null('username');
    $password = post_string_or_null('password');
    $status = strtolower(trim((string)($_POST['status'] ?? '')));

    if ($oldUsername === null || $username === null) {
        throw new RuntimeException('Nom utilisateur manquant.');
    }

    if ($status !== '' && !in_array($status, ['active', 'disabled', 'expired'], true)) {
        throw new RuntimeException('Statut invalide.');
    }

    $api = connectToActiveMikrotikApi();
    try {
        $existing = findMikrotikUserByName($api, $oldUsername);
        if (!$existing || !isset($existing['.id'])) {
            throw new RuntimeException('Utilisateur introuvable sur MikroTik.');
        }

        $payload = [
            '.id' => (string)$existing['.id'],
            'name' => $username,
        ];
        if ($password !== null && trim($password) !== '') {
            $payload['password'] = trim($password);
        }
        if ($status !== '') {
            $payload['disabled'] = ($status === 'disabled') ? 'yes' : 'no';
        }

        $api->comm('/ip/hotspot/user/set', $payload);
    } finally {
        $api->disconnect();
    }

    recordOperationHistory($pdo, [
        'operation_scope' => 'admin',
        'operation_type' => 'user_update',
        'actor_username' => (string)($_SESSION['username'] ?? ''),
        'actor_role' => (string)($_SESSION['user_role'] ?? 'administrator'),
        'target_type' => 'user',
        'target_name' => $username,
        'summary' => 'Utilisateur MikroTik mis à jour',
        'details_json' => [
            'backend_driver' => 'mikrotik_api',
            'old_username' => $oldUsername,
            'password_changed' => $password !== null && $password !== '',
            'status' => $status !== '' ? $status : null,
        ],
    ]);

    echo json_encode([
        'success' => true,
        'message' => 'Utilisateur MikroTik mis a jour.',
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
    ]);
}
