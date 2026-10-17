<?php
// Consommation par Kit (WAN), lecture seule : pas d'appel RouterOS, uniquement la base locale
// deja alimentee par mikrotik_wan_usage_collector.php (meme table que traffic_monitoring.php).
header('Content-Type: application/json');
session_start();
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/device_manager.php';
require_once __DIR__ . '/../includes/multi_wan_config.php';
require_once __DIR__ . '/../includes/mikrotik_wan_usage_collector.php';

if (empty($_SESSION['logged_in'])) {
    http_response_code(403);
    echo json_encode(['success' => false]);
    exit;
}

try {
    $device = requireActiveDevice();
    $config = multiWanLoadConfig($device);
    $sources = is_array($config['sources'] ?? null) ? $config['sources'] : [];
    $interfaces = [];
    foreach ($sources as $slot => $source) {
        if (!is_array($source) || empty($source['enabled'])) continue;
        $interface = trim((string)($source['interface'] ?? ''));
        if ($interface !== '') $interfaces[$slot] = $interface;
    }

    $deviceId = trim((string)(($device['id'] ?? '') ?: ($device['host'] ?? '')));
    $usage = $interfaces !== []
        ? trafficControlWanUsageSummary($pdo, $deviceId, array_values($interfaces))
        : [];

    $kits = [];
    foreach ($sources as $slot => $source) {
        if (!is_array($source)) continue;
        $interface = trim((string)($source['interface'] ?? ''));
        $enabled = isset($interfaces[$slot]);
        $row = $enabled ? ($usage[$interface] ?? []) : [];
        $kits[] = [
            'slot' => $slot,
            'label' => (string)($source['label'] ?? strtoupper($slot)),
            'interface' => $interface,
            'enabled' => $enabled,
            'day_bytes' => (int)($row['day_bytes'] ?? 0),
            'month_bytes' => (int)($row['month_bytes'] ?? 0),
            'daily_target_gb' => (float)($source['daily_target_gb'] ?? 0),
        ];
    }

    echo json_encode(['success' => true, 'kits' => $kits]);
} catch (Throwable $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
