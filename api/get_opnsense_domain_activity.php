<?php

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/device_manager.php';
require_once __DIR__ . '/../includes/opnsense_domains.php';

session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'message' => 'Votre session a expire. Reconnectez-vous puis reessayez.',
    ]);
    exit;
}

try {
    $device = requireActiveOpnsenseDevice();
    $queries = fetchOpnsenseDomainQueries($device, 100);
    $totals = fetchOpnsenseDomainTotals($device, 10);

    echo json_encode([
        'success' => true,
        'device_id' => (string)($device['id'] ?? ''),
        'queries' => $queries,
        'totals' => $totals,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => trim((string)$e->getMessage()) !== '' ? trim((string)$e->getMessage()) : 'Lecture des domaines OPNsense impossible.',
    ]);
}
