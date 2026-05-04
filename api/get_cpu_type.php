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

session_write_close();

try {
    $device = requireActiveDevice();

    if (($device['type'] ?? '') === 'mikrotik') {
        $resource = getMikrotikSystemResource();
        $routerboard = getMikrotikRouterboardInfo();
        $parts = array_filter([
            trim((string)($resource['architecture-name'] ?? '')),
            trim((string)($resource['cpu'] ?? '')),
            trim((string)($routerboard['model'] ?? '')),
        ], static fn($value) => $value !== '');

        echo json_encode([
            'label' => $parts !== [] ? implode(' | ', $parts) : 'MikroTik CPU',
            'device_type' => 'mikrotik',
            'business_source' => resolveDeviceBusinessSource('mikrotik'),
            'backend_driver' => deviceBackendDriverForApiResponse($device),
            'supported' => true,
        ]);
        exit;
    }

    if (($device['type'] ?? '') !== 'opnsense') {
        echo json_encode([
            'label' => 'CPU indisponible',
            'device_type' => deviceTypeLabelForApiResponse($device),
            'business_source' => deviceBusinessSourceForApiResponse(deviceTypeLabelForApiResponse($device)),
            'backend_driver' => deviceBackendDriverForApiResponse($device),
            'supported' => false,
        ]);
        exit;
    }

    $response = opnsenseApiRequest($device, '/api/diagnostics/cpu_usage/getcputype');

    if (!($response['success'] ?? false)) {
        echo json_encode([
            'label' => 'CPU OPNsense indisponible',
            'device_type' => 'opnsense',
            'business_source' => resolveDeviceBusinessSource('opnsense'),
            'backend_driver' => deviceBackendDriverForApiResponse($device),
            'supported' => false,
            'message' => (string)($response['message'] ?? 'CPU indisponible'),
        ]);
        exit;
    }

    echo json_encode($response['data']);
} catch (Exception $e) {
    $safeDevice = isset($device) && is_array($device) ? $device : ['type' => 'opnsense'];
    echo json_encode([
        'label' => 'CPU OPNsense indisponible',
        'device_type' => deviceTypeLabelForApiResponse($safeDevice),
        'business_source' => deviceBusinessSourceForApiResponse(deviceTypeLabelForApiResponse($safeDevice)),
        'backend_driver' => deviceBackendDriverForApiResponse($safeDevice),
        'supported' => false,
        'message' => $e->getMessage(),
    ]);
}
