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

session_write_close();

const DASHBOARD_LIVE_TRAFFIC_CACHE = '/tmp/opnsense_dashboard_live_traffic_cache.json';

function chooseTrafficInterface(array $interfaces): ?string
{
    if (isset($interfaces['wan'])) {
        return 'wan';
    }

    foreach ($interfaces as $key => $stats) {
        $label = strtolower((string)($stats['name'] ?? $key));
        if ($label === 'loopback' || $key === 'lo0') {
            continue;
        }
        return (string)$key;
    }

    $keys = array_keys($interfaces);
    return $keys !== [] ? (string)$keys[0] : null;
}

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

function loadTrafficCache(): array
{
    if (!is_file(DASHBOARD_LIVE_TRAFFIC_CACHE)) {
        return [];
    }

    $decoded = json_decode((string)file_get_contents(DASHBOARD_LIVE_TRAFFIC_CACHE), true);
    return is_array($decoded) ? $decoded : [];
}

function saveTrafficCache(array $cache): void
{
    file_put_contents(DASHBOARD_LIVE_TRAFFIC_CACHE, json_encode($cache, JSON_PRETTY_PRINT), LOCK_EX);
}

$device = [];
try {
    $device = requireActiveDevice();

    if (($device['type'] ?? '') !== 'opnsense') {
        $deviceTypeLabel = deviceTypeLabelForApiResponse($device);
        $driver = deviceBackendDriverForApiResponse($device);
        $businessSource = deviceBusinessSourceForApiResponse($deviceTypeLabel);
        echo json_encode([
            'time' => microtime(true),
            'last_update' => date('H:i:s'),
            'supported' => false,
            'device_type' => $deviceTypeLabel,
            'business_source' => $businessSource,
            'backend_driver' => $driver,
            'cpu' => [
                'total' => 0,
                'user' => 0,
                'system' => 0,
                'idle' => 0,
            ],
            'traffic' => [
                'rx_bps' => 0,
                'tx_bps' => 0,
                'interfaces' => 'N/A',
            ],
        ]);
        exit;
    }

    $trafficResponse = opnsenseApiRequest($device, '/api/diagnostics/traffic/interface');
    $cpuResponse = opnsenseApiRequest($device, '/api/diagnostics/activity/get_activity');

    if (!($trafficResponse['success'] ?? false) || !($cpuResponse['success'] ?? false)) {
        $driver = deviceBackendDriverForApiResponse($device);
        echo json_encode([
            'time' => microtime(true),
            'last_update' => date('H:i:s'),
            'supported' => false,
            'device_type' => 'opnsense',
            'business_source' => resolveDeviceBusinessSource('opnsense'),
            'backend_driver' => $driver,
            'cpu' => [
                'total' => 0,
                'user' => 0,
                'system' => 0,
                'idle' => 0,
            ],
            'traffic' => [
                'rx_bps' => 0,
                'tx_bps' => 0,
                'interfaces' => 'N/A',
            ],
            'message' => implode(' | ', array_filter([
                !($trafficResponse['success'] ?? false) ? (string)($trafficResponse['message'] ?? 'Trafic indisponible') : '',
                !($cpuResponse['success'] ?? false) ? (string)($cpuResponse['message'] ?? 'CPU indisponible') : '',
            ])),
        ]);
        exit;
    }

    $interfaces = $trafficResponse['data']['interfaces'] ?? [];
    $selectedInterface = chooseTrafficInterface($interfaces);
    $timestamp = (float)($trafficResponse['data']['time'] ?? microtime(true));

    $bytesReceived = 0;
    $bytesTransmitted = 0;
    $labels = [];

    if ($selectedInterface !== null && isset($interfaces[$selectedInterface])) {
        $stats = $interfaces[$selectedInterface];
        $bytesReceived += (int)($stats['bytes received'] ?? 0);
        $bytesTransmitted += (int)($stats['bytes transmitted'] ?? 0);
        $labels[] = (string)($stats['name'] ?? strtoupper($selectedInterface));
    }

    $cache = loadTrafficCache();
    $cacheKey = md5($device['host'] . '|' . (string)$selectedInterface);
    $entry = $cache[$cacheKey] ?? [
        'rx' => null,
        'tx' => null,
        'time' => null,
    ];

    $downloadBps = 0.0;
    $uploadBps = 0.0;

    if ($entry['rx'] !== null && $entry['tx'] !== null && $entry['time'] !== null) {
        $deltaTime = max($timestamp - (float)$entry['time'], 0.25);
        $deltaRx = max($bytesReceived - (int)$entry['rx'], 0);
        $deltaTx = max($bytesTransmitted - (int)$entry['tx'], 0);

        $downloadBps = ($deltaRx * 8) / $deltaTime;
        $uploadBps = ($deltaTx * 8) / $deltaTime;
    }

    $cache[$cacheKey] = [
        'rx' => $bytesReceived,
        'tx' => $bytesTransmitted,
        'time' => $timestamp,
    ];
    saveTrafficCache($cache);

    $cpu = extractCpuTotals($cpuResponse['data']);

    echo json_encode([
        'time' => $timestamp,
        'last_update' => date('H:i:s'),
        'device_type' => 'opnsense',
        'business_source' => resolveDeviceBusinessSource('opnsense'),
        'backend_driver' => deviceBackendDriverForApiResponse($device),
        'cpu' => [
            'total' => $cpu['total'],
            'user' => $cpu['user'],
            'system' => $cpu['system'],
            'idle' => $cpu['idle'],
        ],
        'traffic' => [
            'rx_bps' => round($downloadBps, 2),
            'tx_bps' => round($uploadBps, 2),
            'interfaces' => implode(', ', $labels),
        ],
    ]);
} catch (Exception $e) {
    $safe = is_array($device) && $device !== [] ? $device : [];
    $deviceTypeLabel = $safe !== [] ? deviceTypeLabelForApiResponse($safe) : null;
    $driver = $safe !== [] ? deviceBackendDriverForApiResponse($safe) : null;
    $businessSource = deviceBusinessSourceForApiResponse($deviceTypeLabel);
    echo json_encode([
        'time' => microtime(true),
        'last_update' => date('H:i:s'),
        'supported' => false,
        'device_type' => $deviceTypeLabel,
        'business_source' => $businessSource,
        'backend_driver' => $driver,
        'cpu' => [
            'total' => 0,
            'user' => 0,
            'system' => 0,
            'idle' => 0,
        ],
        'traffic' => [
            'rx_bps' => 0,
            'tx_bps' => 0,
            'interfaces' => 'N/A',
        ],
        'message' => $e->getMessage(),
    ]);
}
