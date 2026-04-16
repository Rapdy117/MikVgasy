<?php
session_start();

header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/vouchers.php';

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Non autorise']);
    exit();
}

try {
    $result = syncVoucherUsage($pdo);
    echo json_encode([
        'success' => true,
        'updated' => (int)($result['updated'] ?? 0),
        'checked_at' => gmdate('c'),
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
    ]);
}
