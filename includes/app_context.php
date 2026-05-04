<?php

require_once __DIR__ . '/device_manager.php';
require_once __DIR__ . '/auth.php';

function buildAppContext(): array
{
    $deviceContext = getActiveDeviceContext();
    $activeDevice = $deviceContext['device'] ?? null;

    $device = [
        'id' => null,
        'name' => 'Aucun device',
        'type' => null,
        'host' => '',
        'ip' => '',
        'backend_driver' => null,
        'business_source' => null,
    ];

    if (is_array($activeDevice) && trim((string)($activeDevice['type'] ?? '')) !== '') {
        $normType = normalizeDeviceType((string)$activeDevice['type']);
        $driver = resolveDeviceRecordBackendDriver($activeDevice);
        $device = [
            'id' => (string)($activeDevice['id'] ?? ''),
            'name' => trim((string)($activeDevice['name'] ?? '')) !== ''
                ? (string)$activeDevice['name']
                : strtoupper($normType),
            'type' => $normType,
            'host' => (string)($activeDevice['host'] ?? ''),
            'ip' => (string)($activeDevice['ip'] ?? ''),
            'backend_driver' => $driver,
            'business_source' => (string)($activeDevice['business_source'] ?? resolveDeviceBusinessSource($normType)),
        ];
    }

    return [
        'user' => [
            'username' => currentLocalUsername(),
            'role' => currentLocalUserRole(),
            'is_admin' => isAdministrator(),
            'is_authenticated' => isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true,
        ],
        'device' => $device,
        'capabilities' => getDeviceContextCapabilities(is_array($activeDevice) ? $activeDevice : null),
    ];
}
