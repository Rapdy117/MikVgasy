<?php

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/device_manager.php';
require_once __DIR__ . '/../includes/device_probe.php';
require_once __DIR__ . '/../includes/admin_notifications.php';

function monitorProbeHeadline(array $result): string
{
    $log = trim((string)($result['log'] ?? ''));
    if ($log === '') {
        return 'Echec du test de connexion';
    }

    $parts = preg_split('/\r\n|\r|\n/', $log);
    $headline = trim((string)($parts[0] ?? ''));

    return $headline !== '' ? $headline : $log;
}

function monitorCreateDeviceNotification(PDO $pdo, array $device, string $state, array $result, int $previousFailures): void
{
    $deviceId = trim((string)($device['id'] ?? ''));
    $deviceLabel = getDeviceDisplayLabel($device);
    $hostValue = trim((string)($device['host'] ?? ($device['ip'] ?? '')));
    $checkedAt = date('Y-m-d H:i:s');

    if ($state === 'offline') {
        createAdminNotification($pdo, [
            'severity' => 'critical',
            'category' => 'device',
            'source_type' => 'device_health',
            'source_ref' => $deviceId . '|offline|' . date('YmdHis', strtotime($checkedAt)),
            'title' => 'Device hors ligne',
            'message' => sprintf(
                'Le device %s ne repond plus ou le test a echoue. Host: %s. %s',
                $deviceLabel,
                $hostValue !== '' ? $hostValue : '-',
                monitorProbeHeadline($result)
            ),
            'details_json' => [
                'device_id' => $deviceId,
                'device_name' => (string)($device['name'] ?? ''),
                'device_type' => (string)($device['type'] ?? ''),
                'host' => $hostValue,
                'backend_driver' => (string)($device['backend_driver'] ?? ''),
                'probe_result' => $result,
            ],
        ]);
        return;
    }

    createAdminNotification($pdo, [
        'severity' => 'success',
        'category' => 'device',
        'source_type' => 'device_health',
        'source_ref' => $deviceId . '|online|' . date('YmdHis', strtotime($checkedAt)),
        'title' => 'Device retabli',
        'message' => sprintf(
            'Le device %s est de nouveau joignable. Host: %s. Panne precedente: %d controle(s) en echec.',
            $deviceLabel,
            $hostValue !== '' ? $hostValue : '-',
            max(1, $previousFailures)
        ),
        'details_json' => [
            'device_id' => $deviceId,
            'device_name' => (string)($device['name'] ?? ''),
            'device_type' => (string)($device['type'] ?? ''),
            'host' => $hostValue,
            'backend_driver' => (string)($device['backend_driver'] ?? ''),
            'probe_result' => $result,
        ],
    ]);
}

ensureAdminNotificationsTable($pdo);
ensureDeviceHealthMonitorTable($pdo);

$store = loadDeviceStore();
$devices = is_array($store['devices'] ?? null) ? $store['devices'] : [];
$checkedAt = date('Y-m-d H:i:s');

foreach ($devices as $device) {
    $deviceId = trim((string)($device['id'] ?? ''));
    if ($deviceId === '') {
        continue;
    }

    try {
        $deviceType = normalizeDeviceType((string)($device['type'] ?? ''));
    } catch (Throwable $e) {
        continue;
    }

    if ($deviceType === 'radius' || !canProbeDevice($device)) {
        saveDeviceHealthMonitorState($pdo, [
            'device_id' => $deviceId,
            'device_name' => (string)($device['name'] ?? ''),
            'device_type' => $deviceType,
            'host' => (string)($device['host'] ?? ''),
            'last_state' => 'not_supported',
            'last_error_message' => null,
            'last_checked_at' => $checkedAt,
            'last_success_at' => null,
            'consecutive_failures' => 0,
        ]);
        continue;
    }

    try {
        $result = probeDeviceConnection($device, false);
    } catch (Throwable $e) {
        $result = [
            'success' => false,
            'device_type' => $deviceType,
            'backend_driver' => resolveDeviceBackend($deviceType),
            'device_status' => 'offline',
            'log' => '❌ Exception pendant le controle automatique: ' . $e->getMessage(),
        ];
    }

    $currentState = !empty($result['success']) ? 'online' : 'offline';
    $previous = getDeviceHealthMonitorState($pdo, $deviceId) ?? [];
    $previousState = strtolower(trim((string)($previous['last_state'] ?? 'unknown')));
    $previousFailures = max(0, (int)($previous['consecutive_failures'] ?? 0));
    $newFailures = $currentState === 'offline' ? ($previousFailures + 1) : 0;

    if ($currentState === 'offline' && $previousState !== 'offline') {
        monitorCreateDeviceNotification($pdo, $device, 'offline', $result, $previousFailures);
    } elseif ($currentState === 'online' && $previousState === 'offline') {
        monitorCreateDeviceNotification($pdo, $device, 'online', $result, $previousFailures);
    }

    saveDeviceHealthMonitorState($pdo, [
        'device_id' => $deviceId,
        'device_name' => (string)($device['name'] ?? ''),
        'device_type' => $deviceType,
        'host' => (string)($device['host'] ?? ''),
        'last_state' => $currentState,
        'last_error_message' => !empty($result['success']) ? null : trim((string)($result['log'] ?? '')),
        'last_checked_at' => $checkedAt,
        'last_success_at' => !empty($result['success']) ? $checkedAt : (string)($previous['last_success_at'] ?? ''),
        'consecutive_failures' => $newFailures,
    ]);
}
