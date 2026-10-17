<?php

require_once __DIR__ . '/../api/mikrotik_traffic_listener.php';

$lock = fopen(sys_get_temp_dir() . '/radius_manager_mikrotik_traffic.lock', 'c');
$globalLock = fopen(sys_get_temp_dir() . '/radius_manager_mikrotik_collector_global.lock', 'c');
if ($lock === false || $globalLock === false || !flock($lock, LOCK_EX | LOCK_NB) || !flock($globalLock, LOCK_EX | LOCK_NB)) {
    exit(0);
}

$rows = $pdo->query("SELECT * FROM network_devices WHERE is_active=1 AND type='mikrotik' ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
$results = [];

foreach ($rows as $row) {
    try {
        $device = decryptFields(normalizeDeviceRecord($row), DEVICE_SENSITIVE_FIELDS);
        $payload = trafficListenerBuild($device);
        trafficListenerRecordSample($pdo, $payload);
        $results[] = [
            'device_id' => (string)($device['id'] ?? ''),
            'success' => true,
            'captured_at' => (string)($payload['captured_at'] ?? ''),
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
flock($lock, LOCK_UN);
fclose($globalLock);
fclose($lock);

echo json_encode(['success' => true, 'results' => $results], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), PHP_EOL;
