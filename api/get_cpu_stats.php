<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/device_manager.php';
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

const DASHBOARD_CPU_CACHE = '/tmp/opnsense_dashboard_cpu_cache.json';

function extractCpuTotals(array $activity): array
{
    $headers = $activity['headers'] ?? [];

    foreach ($headers as $line) {
        if (strpos($line, 'CPU:') !== 0) {
            continue;
        }

        preg_match('/CPU:\s*([0-9.]+)% user,\s*([0-9.]+)% nice,\s*([0-9.]+)% system,\s*([0-9.]+)% interrupt,\s*([0-9.]+)% idle/i', $line, $matches);

        if ($matches) {
            $user = (float)$matches[1];
            $nice = (float)$matches[2];
            $system = (float)$matches[3];
            $interrupt = (float)$matches[4];
            $idle = (float)$matches[5];

            return [
                'total' => round($user + $nice + $system + $interrupt, 2),
                'idle' => round($idle, 2),
                'user' => round($user, 2),
                'system' => round($system + $interrupt, 2),
            ];
        }
    }

    return [
        'total' => 0.0,
        'idle' => 100.0,
        'user' => 0.0,
        'system' => 0.0,
    ];
}

function loadCpuCache(): array
{
    if (!is_file(DASHBOARD_CPU_CACHE)) {
        return [
            'labels' => [],
            'total' => [],
            'user' => [],
            'system' => [],
        ];
    }

    $decoded = json_decode((string)file_get_contents(DASHBOARD_CPU_CACHE), true);
    return is_array($decoded) ? $decoded : [
        'labels' => [],
        'total' => [],
        'user' => [],
        'system' => [],
    ];
}

function saveCpuCache(array $history): void
{
    file_put_contents(DASHBOARD_CPU_CACHE, json_encode($history, JSON_PRETTY_PRINT), LOCK_EX);
}

try {
    $device = requireActiveDevice();

    if (($device['type'] ?? '') !== 'opnsense') {
        echo json_encode([
            'current_cpu_total' => 0,
            'current_cpu_user' => 0,
            'current_cpu_system' => 0,
            'current_cpu_idle' => 0,
            'cpu_history' => [
                'labels' => [],
                'total' => [],
                'user' => [],
                'system' => [],
            ],
            'last_update' => date('H:i:s'),
            'supported' => false,
            'device_type' => deviceTypeLabelForApiResponse($device),
            'business_source' => deviceBusinessSourceForApiResponse(deviceTypeLabelForApiResponse($device)),
            'backend_driver' => deviceBackendDriverForApiResponse($device),
        ]);
        exit;
    }

    $activityResponse = opnsenseApiRequest($device, '/api/diagnostics/activity/get_activity');

    if (!($activityResponse['success'] ?? false)) {
        echo json_encode([
            'current_cpu_total' => 0,
            'current_cpu_user' => 0,
            'current_cpu_system' => 0,
            'current_cpu_idle' => 0,
            'cpu_history' => [
                'labels' => [],
                'total' => [],
                'user' => [],
                'system' => [],
            ],
            'last_update' => date('H:i:s'),
            'supported' => false,
            'device_type' => 'opnsense',
            'business_source' => resolveDeviceBusinessSource('opnsense'),
            'backend_driver' => deviceBackendDriverForApiResponse($device),
            'message' => (string)($activityResponse['message'] ?? 'CPU indisponible'),
        ]);
        exit;
    }

    $cpu = extractCpuTotals($activityResponse['data'] ?? []);
    $history = loadCpuCache();

    $history['labels'][] = date('H:i:s');
    $history['total'][] = $cpu['total'];
    $history['user'][] = $cpu['user'];
    $history['system'][] = $cpu['system'];

    $history['labels'] = array_slice($history['labels'], -30);
    $history['total'] = array_slice($history['total'], -30);
    $history['user'] = array_slice($history['user'], -30);
    $history['system'] = array_slice($history['system'], -30);

    saveCpuCache($history);

    echo json_encode([
        'current_cpu_total' => $cpu['total'],
        'current_cpu_user' => $cpu['user'],
        'current_cpu_system' => $cpu['system'],
        'current_cpu_idle' => $cpu['idle'],
        'cpu_history' => $history,
        'last_update' => date('H:i:s'),
        'device_type' => 'opnsense',
        'business_source' => resolveDeviceBusinessSource('opnsense'),
        'backend_driver' => deviceBackendDriverForApiResponse($device),
    ]);
} catch (Exception $e) {
    echo json_encode([
        'current_cpu_total' => 0,
        'current_cpu_user' => 0,
        'current_cpu_system' => 0,
        'current_cpu_idle' => 0,
        'cpu_history' => [
            'labels' => [],
            'total' => [],
            'user' => [],
            'system' => [],
        ],
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
