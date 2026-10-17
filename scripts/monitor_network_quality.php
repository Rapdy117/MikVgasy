<?php

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/network_quality_monitor.php';
require_once __DIR__ . '/../includes/mikrotik_backend.php';
require_once __DIR__ . '/../includes/multi_wan_config.php';
require_once __DIR__ . '/../includes/wan_state.php';
require_once __DIR__ . '/../includes/admin_notifications.php';

$lock = fopen(sys_get_temp_dir() . '/radius_manager_network_quality.lock', 'c');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    exit(0);
}

ensureNetworkQualityTables($pdo);
$rows = $pdo->query("SELECT * FROM network_devices WHERE is_active=1 AND type='mikrotik' ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
$results = [];

foreach ($rows as $row) {
    try {
        $device = decryptFields(normalizeDeviceRecord($row), DEVICE_SENSITIVE_FIELDS);
        $sample = collectNetworkQualitySample($pdo, $device);
        // Journal de transitions WAN (P2) : écrit seulement si l'état change. Une erreur ici ne bloque pas la collecte.
        try {
            $wans = [];
            foreach (multiWanLoadConfig($device)['sources'] ?? [] as $slot => $src) {
                if (!empty($src['enabled']) && trim((string)($src['interface'] ?? '')) !== '') {
                    $wans[strtoupper((string)$slot)] = trim((string)$src['interface']);
                }
            }
            // Charge routeur : vérification toutes les 5 minutes (la transition reste visible en moins de 5 min).
            if ($wans && ((int)date('i') % 5) === 0) {
                $journalApi = connectToMikrotikApiByDevice($device);
                try {
                    wanStateJournalCycle($journalApi, $wans, $pdo, (string)$device['id']);
                } finally {
                    $journalApi->disconnect();
                }
            }
        } catch (Throwable $ignored) {
        }
        $results[] = [
            'device_id' => (string)$device['id'],
            'success' => true,
            'severity' => (string)$sample['severity'],
            'diagnosis' => (string)$sample['diagnosis'],
            'sampled_at' => (string)$sample['sampled_at'],
        ];
    } catch (Throwable $error) {
        $device = isset($device) && is_array($device) ? $device : $row;
        try {
            saveNetworkQualityFailure($pdo, $device, $error);
        } catch (Throwable $ignored) {
        }
        $results[] = [
            'device_id' => (string)($device['id'] ?? ''),
            'success' => false,
            'message' => $error->getMessage(),
        ];
    }
    unset($device);
}

flock($lock, LOCK_UN);
fclose($lock);

echo json_encode(['success' => true, 'results' => $results], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), PHP_EOL;
