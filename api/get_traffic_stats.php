<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/device_manager.php';
require_once __DIR__ . '/../includes/mikrotik_backend.php';
require_once __DIR__ . '/../includes/opnsense_shaper.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json');

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    http_response_code(403);
    echo json_encode([
        'error' => 'Unauthorized',
    ]);
    exit;
}

try {
    $device = requireActiveDevice();
    $requestedInterface = trim((string)($_GET['interface'] ?? ''));

    if (($device['type'] ?? '') === 'mikrotik') {
        $interfaces = listMikrotikInterfaces();
        $filteredInterfaces = array_values(array_filter($interfaces, static function ($interface): bool {
            if (!is_array($interface)) {
                return false;
            }

            $name = strtolower(trim((string)($interface['name'] ?? '')));
            return $name !== '' && $name !== 'lo' && $name !== 'loopback';
        }));

        $selectedInterface = $requestedInterface;
        if ($selectedInterface === '') {
            $selected = selectMikrotikDashboardInterface($filteredInterfaces);
            $selectedInterface = (string)($selected['name'] ?? '');
        }

        $sample = getMikrotikTrafficSample($selectedInterface);
        $interfaceOptions = array_map(static function (array $interface): array {
            return [
                'name' => (string)($interface['name'] ?? ''),
                'running' => strtolower((string)($interface['running'] ?? 'false')) === 'true',
                'disabled' => strtolower((string)($interface['disabled'] ?? 'false')) === 'true',
                'type' => (string)($interface['type'] ?? ''),
            ];
        }, $filteredInterfaces);

        echo json_encode([
            'time' => microtime(true),
            'interval' => 2000,
            'interfaces' => [
                'wan' => [
                    'name' => $sample['interface'],
                    'inbytes' => $sample['rx_bps'],
                    'outbytes' => $sample['tx_bps'],
                ],
            ],
            'metric_mode' => 'rate',
            'interface_label' => $sample['interface'],
            'interface_options' => $interfaceOptions,
            'selected_interface' => $sample['interface'],
            'last_update' => date('H:i:s'),
        ]);
        exit;
    }

    if (($device['type'] ?? '') !== 'opnsense') {
        echo json_encode([
            'time' => microtime(true),
            'interval' => 2000,
            'interfaces' => [],
            'last_update' => date('H:i:s'),
            'supported' => false,
            'device_type' => deviceTypeLabelForApiResponse($device),
            'business_source' => deviceBusinessSourceForApiResponse(deviceTypeLabelForApiResponse($device)),
            'backend_driver' => deviceBackendDriverForApiResponse($device),
        ]);
        exit;
    }

    $trafficResponse = opnsenseApiRequest($device, '/api/diagnostics/traffic/interface');

    if (!($trafficResponse['success'] ?? false)) {
        echo json_encode([
            'time' => microtime(true),
            'interval' => 2000,
            'interfaces' => [],
            'last_update' => date('H:i:s'),
            'supported' => false,
            'device_type' => 'opnsense',
            'business_source' => resolveDeviceBusinessSource('opnsense'),
            'backend_driver' => deviceBackendDriverForApiResponse($device),
            'message' => (string)($trafficResponse['message'] ?? 'Trafic indisponible'),
        ]);
        exit;
    }

    $interfaces = $trafficResponse['data']['interfaces'] ?? [];
    $timestamp = (float)($trafficResponse['data']['time'] ?? microtime(true));
    $filteredInterfaces = [];

    foreach ($interfaces as $key => $stats) {
        $label = strtolower((string)($stats['name'] ?? $key));
        if ($label === 'loopback' || $key === 'lo0') {
            continue;
        }
        $filteredInterfaces[(string)$key] = $stats;
    }

    if ($filteredInterfaces === []) {
        $filteredInterfaces = $interfaces;
    }

    echo json_encode([
        'time' => $timestamp,
        'interval' => 2000,
        'interfaces' => $filteredInterfaces,
        'last_update' => date('H:i:s'),
        'device_type' => 'opnsense',
        'business_source' => resolveDeviceBusinessSource('opnsense'),
        'backend_driver' => deviceBackendDriverForApiResponse($device),
    ]);
} catch (Exception $e) {
    echo json_encode([
        'time' => microtime(true),
        'interval' => 2000,
        'interfaces' => [],
        'last_update' => date('H:i:s'),
        'supported' => false,
        'device_type' => isset($device) && is_array($device) ? deviceTypeLabelForApiResponse($device) : null,
        'business_source' => isset($device) && is_array($device)
            ? deviceBusinessSourceForApiResponse(deviceTypeLabelForApiResponse($device))
            : null,
        'backend_driver' => isset($device) && is_array($device) ? deviceBackendDriverForApiResponse($device) : null,
        'message' => $e->getMessage(),
    ]);
}
