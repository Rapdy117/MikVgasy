<?php
session_start();

header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/mikrotik_backend.php';

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Non autorise']);
    exit();
}

try {
    requireMikrotikNasContextForActiveDevice();
    $csrf = trim((string)($_POST['csrf_token'] ?? ''));
    if ($csrf === '' || !hash_equals($_SESSION['csrf_token'] ?? '', $csrf)) {
        throw new RuntimeException('CSRF invalide');
    }

    removeMikrotikScheduler((string)($_POST['scheduler_id'] ?? ''));

    echo json_encode(['success' => true, 'message' => 'Scheduler supprime']);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
