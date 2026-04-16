<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/device_manager.php';
require_once __DIR__ . '/../includes/mikrotik_backend.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

try {
    $device = requireActiveDevice();
    $deviceType = strtolower(trim((string)($device['type'] ?? '')));

    if ($deviceType !== 'mikrotik') {
        http_response_code(400);
        echo json_encode([
            'error' => 'Le device actif n\'est pas MikroTik.',
            'device_type' => $deviceType,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    $api = connectToMikrotikApiByDevice($device);
    try {
        $test1 = $api->comm('/log/print');

        $test2 = $api->comm('/log/print', [
            '.proplist' => 'time,message,topics',
        ]);

        $test3 = $api->comm('/log/print', [
            '?topics' => 'hotspot',
            '.proplist' => 'time,message,topics',
        ]);
    } finally {
        $api->disconnect();
    }

    $test1 = array_slice(is_array($test1) ? $test1 : [], 0, 5);
    $test2 = array_slice(is_array($test2) ? $test2 : [], 0, 5);
    $test3 = array_slice(is_array($test3) ? $test3 : [], 0, 5);

    echo json_encode([
        'test1' => $test1,
        'test2' => $test2,
        'test3' => $test3,
        'counts' => [
            'test1' => count($test1),
            'test2' => count($test2),
            'test3' => count($test3),
        ],
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'error' => $e->getMessage(),
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}
