<?php

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/device_manager.php';
require_once __DIR__ . '/../includes/mikrotik_backend.php';
require_once __DIR__ . '/../includes/mikrotik_hotspot_log_collector.php';

function acquireHotspotLogCollectorLock($handle, int $seconds = 45): bool
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

$lock = fopen(sys_get_temp_dir() . '/radius_manager_mikrotik_hotspot_logs.lock', 'c');
if ($lock === false || !acquireHotspotLogCollectorLock($lock)) {
    error_log('monitor_mikrotik_hotspot_logs: verrou occupé plus de 45 s, collecte non exécutée');
    exit(1);
}

$rows = $pdo->query("SELECT * FROM network_devices WHERE is_active=1 AND type='mikrotik' ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
$results = [];

foreach ($rows as $row) {
    try {
        $device = decryptFields(normalizeDeviceRecord($row), DEVICE_SENSITIVE_FIELDS);
        $api = connectToMikrotikApiByDevice($device);
        try {
            $inserted = mikrotikHotspotLogCollect($pdo, $device, $api);
        } finally {
            $api->disconnect();
        }
        $results[] = [
            'device_id' => (string)($device['id'] ?? ''),
            'success' => true,
            'inserted' => $inserted,
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

flock($lock, LOCK_UN);
fclose($lock);

echo json_encode(['success' => true, 'results' => $results], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), PHP_EOL;
