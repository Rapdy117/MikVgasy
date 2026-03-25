<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/device_manager.php';

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

function opnsenseGet(array $device, string $path): array
{
    $ch = curl_init();

    curl_setopt_array($ch, [
        CURLOPT_URL => $device['host'] . $path,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
        CURLOPT_USERPWD => $device['api_key'] . ':' . $device['api_secret'],
        CURLOPT_SSL_VERIFYPEER => (bool)$device['verify_ssl'],
        CURLOPT_SSL_VERIFYHOST => !empty($device['verify_ssl']) ? 2 : 0,
        CURLOPT_TIMEOUT => 5,
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
    ]);

    $raw = curl_exec($ch);
    $error = curl_error($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);

    curl_close($ch);

    if ($raw === false || $error !== '') {
        return ['success' => false, 'error' => $error !== '' ? $error : 'Erreur cURL inconnue'];
    }

    $decoded = json_decode($raw, true);
    if ($httpCode < 200 || $httpCode >= 300 || !is_array($decoded)) {
        return ['success' => false, 'error' => 'Reponse OPNsense invalide sur ' . $path];
    }

    return ['success' => true, 'data' => $decoded];
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
    $device = loadActiveOpnSenseDevice();
    $activityResponse = opnsenseGet($device, '/api/diagnostics/activity/get_activity');

    if (!$activityResponse['success']) {
        throw new Exception($activityResponse['error']);
    }

    $cpu = extractCpuTotals($activityResponse['data']);
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
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'error' => $e->getMessage(),
    ]);
}
