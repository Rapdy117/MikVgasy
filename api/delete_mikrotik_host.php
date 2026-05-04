<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/mikrotik_backend.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/operation_history.php';

session_start();

header('Content-Type: application/json');

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'message' => 'Acces refuse',
    ]);
    exit;
}

$csrfToken = trim((string)($_POST['csrf_token'] ?? ''));
if ($csrfToken === '' || !hash_equals((string)($_SESSION['csrf_token'] ?? ''), $csrfToken)) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'CSRF invalide',
    ]);
    exit;
}

$hostIds = $_POST['host_ids'] ?? [];
if (!is_array($hostIds)) {
    $singleHostId = trim((string)($_POST['host_id'] ?? ''));
    $hostIds = $singleHostId !== '' ? [$singleHostId] : [];
}

$hostIds = array_values(array_filter(array_map(static fn($value) => trim((string)$value), $hostIds)));
if ($hostIds === []) {
    http_response_code(422);
    echo json_encode([
        'success' => false,
        'message' => 'Host introuvable',
    ]);
    exit;
}

try {
    requireMikrotikNasContextForActiveDevice();
    foreach ($hostIds as $hostId) {
        removeMikrotikHost($hostId);
    }

    try {
        $pdo = getDBConnection();
        ensureOperationHistoryTable($pdo);
        recordOperationHistory($pdo, [
            'actor_username' => (string)($_SESSION['username'] ?? 'admin'),
            'operation_scope' => 'mikrotik',
            'operation_type' => 'host_remove',
            'target_username' => count($hostIds) === 1 ? $hostIds[0] : null,
            'details' => json_encode([
                'host_ids' => $hostIds,
                'count' => count($hostIds),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
    } catch (Throwable $logError) {
        error_log('[delete_mikrotik_host] log failed: ' . $logError->getMessage());
    }

    echo json_encode([
        'success' => true,
        'message' => count($hostIds) > 1 ? 'Hosts supprimes avec succes.' : 'Host supprime avec succes.',
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
    ]);
}
