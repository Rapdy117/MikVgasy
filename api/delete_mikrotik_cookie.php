<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/mikrotik_backend.php';

session_start();

header('Content-Type: application/json');

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'message' => 'Unauthorized',
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

$cookieIds = $_POST['cookie_ids'] ?? [];
if (!is_array($cookieIds)) {
    $singleCookieId = trim((string)($_POST['cookie_id'] ?? ''));
    $cookieIds = $singleCookieId !== '' ? [$singleCookieId] : [];
}

$cookieIds = array_values(array_filter(array_map(static fn($value) => trim((string)$value), $cookieIds)));

if ($cookieIds === []) {
    http_response_code(422);
    echo json_encode([
        'success' => false,
        'message' => 'Cookie introuvable',
    ]);
    exit;
}

try {
    requireMikrotikNasContextForActiveDevice();
    foreach ($cookieIds as $cookieId) {
        removeMikrotikCookie($cookieId);
    }

    echo json_encode([
        'success' => true,
        'message' => count($cookieIds) > 1 ? 'Cookies supprimés avec succès.' : 'Cookie supprimé avec succès.',
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
    ]);
}
