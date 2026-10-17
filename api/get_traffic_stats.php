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
    $requestedInterfaces = array_values(array_unique(array_filter(array_map(
        static fn($value): string => trim((string)$value),
        explode(',', (string)($_GET['interfaces'] ?? $_GET['interface'] ?? ''))
    ), static fn(string $value): bool => $value !== '')));

    if (($device['type'] ?? '') === 'mikrotik') {
        $interfaces = listMikrotikInterfaces();
        $filteredInterfaces = array_values(array_filter($interfaces, static function ($interface): bool {
            if (!is_array($interface)) {
                return false;
            }

            $name = strtolower(trim((string)($interface['name'] ?? '')));
            return $name !== '' && $name !== 'lo' && $name !== 'loopback';
        }));

        $selectedInterfaces = array_values(array_filter($requestedInterfaces, static function (string $requested) use ($filteredInterfaces): bool {
            foreach ($filteredInterfaces as $interface) {
                if ((string)($interface['name'] ?? '') === $requested) {
                    return true;
                }
            }
            return false;
        }));
        if ($selectedInterfaces === []) {
            $selected = selectMikrotikDashboardInterface($filteredInterfaces);
            $selectedInterfaces = [(string)($selected['name'] ?? '')];
        }

        $trafficInterfaces = [];
        foreach (getMikrotikTrafficSamples($selectedInterfaces) as $sample) {
            $trafficInterfaces[$sample['interface']] = [
                'name' => $sample['interface'],
                'inbytes' => $sample['rx_bps'],
                'outbytes' => $sample['tx_bps'],
                'rx_bytes' => $sample['rx_bytes'],
                'tx_bytes' => $sample['tx_bytes'],
            ];
        }
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
            'interfaces' => $trafficInterfaces,
            'metric_mode' => 'rate',
            'interface_label' => implode(', ', $selectedInterfaces),
            'interface_options' => $interfaceOptions,
            'selected_interface' => $selectedInterfaces[0],
            'selected_interfaces' => $selectedInterfaces,
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

    $selectedInterfaces = array_values(array_filter($requestedInterfaces, static fn(string $requested): bool => array_key_exists($requested, $filteredInterfaces)));
    if ($selectedInterfaces === []) {
        $selectedInterfaces = [(string)array_key_first($filteredInterfaces)];
    }

    $selectedInterface = $selectedInterfaces[0];
    $selectedStats = is_array($filteredInterfaces[$selectedInterface] ?? null) ? $filteredInterfaces[$selectedInterface] : [];
    $interfaceOptions = [];
    foreach ($filteredInterfaces as $key => $stats) {
        if (!is_array($stats)) {
            continue;
        }
        $interfaceOptions[] = [
            'name' => (string)$key,
            'label' => (string)($stats['name'] ?? $key),
            'running' => true,
        ];
    }

    echo json_encode([
        'time' => $timestamp,
        'interval' => 2000,
        'interfaces' => $filteredInterfaces,
        'interface_options' => $interfaceOptions,
        'selected_interface' => $selectedInterface,
        'selected_interfaces' => $selectedInterfaces,
        'interface_label' => (string)($selectedStats['name'] ?? $selectedInterface),
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
