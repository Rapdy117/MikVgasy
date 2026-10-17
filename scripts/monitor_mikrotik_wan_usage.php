<?php

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/device_manager.php';
require_once __DIR__ . '/../includes/mikrotik_backend.php';
require_once __DIR__ . '/../includes/mikrotik_wan_usage_collector.php';
require_once __DIR__ . '/../includes/wan_cap_monitor.php';

// Attend le verrou au lieu de sortir : une collecte sautée crée un trou dans l'historique WAN.
function acquireCollectorLock($handle, int $seconds = 45): bool
{
    $deadline = time() + $seconds;
    do {
        if (flock($handle, LOCK_EX | LOCK_NB)) {
            return true;
        }
        usleep(500000);
    } while (time() < $deadline);
    return false;
}

$ownLock = fopen(sys_get_temp_dir() . '/radius_manager_mikrotik_wan_usage.lock', 'c');
$globalLock = fopen(sys_get_temp_dir() . '/radius_manager_mikrotik_collector_global.lock', 'c');
if ($ownLock === false || $globalLock === false || !acquireCollectorLock($ownLock) || !acquireCollectorLock($globalLock)) {
    error_log('monitor_mikrotik_wan_usage: verrou occupé plus de 45 s, collecte WAN non exécutée');
    exit(1);
}

$rows = $pdo->query("SELECT * FROM network_devices WHERE is_active=1 AND type='mikrotik' ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
$results = [];

foreach ($rows as $row) {
    try {
        $device = decryptFields(normalizeDeviceRecord($row), DEVICE_SENSITIVE_FIELDS);
        $api = connectToMikrotikApiByDevice($device);
        try {
            $summary = trafficControlCollectWanUsage($pdo, $api, $device);
            enforceWanDailyCaps($pdo, $api, $device, $summary);
        } finally {
            $api->disconnect();
        }
        $results[] = [
            'device_id' => (string)($device['id'] ?? ''),
            'success' => true,
            'interfaces' => array_keys($summary),
            'captured_at' => date('Y-m-d H:i:s'),
        ];
    } catch (Throwable $error) {
        $results[] = [
            'device_id' => (string)($row['id'] ?? ''),
            'success' => false,
            'message' => $error->getMessage(),
        ];
    }
    unset($device);
}

flock($globalLock, LOCK_UN);
flock($ownLock, LOCK_UN);
fclose($globalLock);
fclose($ownLock);

echo json_encode(['success' => true, 'results' => $results], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), PHP_EOL;
