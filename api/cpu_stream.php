<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/device_manager.php';
require_once __DIR__ . '/../includes/mikrotik_backend.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

session_write_close();

function emitSse(array $payload): void
{
    echo 'event: message' . "\n";
    echo 'data: ' . json_encode($payload) . "\n\n";
    @ob_flush();
    @flush();
}

ignore_user_abort(true);
set_time_limit(0);

while (ob_get_level() > 0) {
    ob_end_flush();
}
ob_implicit_flush(true);

header('Content-Type: text/event-stream');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Connection: keep-alive');
header('X-Accel-Buffering: no');

$device = requireActiveDevice();

if (($device['type'] ?? '') === 'mikrotik') {
    while (!connection_aborted()) {
        try {
            $cacheKey = trim((string)($device['id'] ?? $device['host'] ?? 'active'));
            $cacheFile = sys_get_temp_dir() . '/mikrotik_cpu_stream_' . md5($cacheKey) . '.json';
            $cacheTtl = 2;
            $cpuLoad = null;
            if (is_file($cacheFile)) {
                $cached = json_decode((string)file_get_contents($cacheFile), true);
                if (is_array($cached) && isset($cached['time'], $cached['cpu'])) {
                    if ((time() - (int)$cached['time']) <= $cacheTtl) {
                        $cpuLoad = (float)$cached['cpu'];
                    }
                }
            }

            if ($cpuLoad === null) {
                $resource = getMikrotikSystemResource();
                $cpuLoad = (float)($resource['cpu-load'] ?? 0);
                file_put_contents($cacheFile, json_encode([
                    'time' => time(),
                    'cpu' => $cpuLoad,
                ], JSON_UNESCAPED_SLASHES));
            }

            emitSse([
                'total' => round($cpuLoad, 2),
                'user' => round($cpuLoad, 2),
                'system' => 0,
                'idle' => round(max(0, 100 - $cpuLoad), 2),
                'last_update' => date('H:i:s'),
                'supported' => true,
                'device_type' => 'mikrotik',
            ]);
        } catch (Throwable $e) {
            emitSse([
                'error' => $e->getMessage(),
                'supported' => false,
                'device_type' => 'mikrotik',
                'business_source' => resolveDeviceBusinessSource('mikrotik'),
                'backend_driver' => deviceBackendDriverForApiResponse($device),
            ]);
            exit;
        }

        sleep(2);
    }

    exit;
}

if (($device['type'] ?? '') !== 'opnsense') {
    emitSse([
        'error' => 'Flux CPU indisponible pour le device actif ' . getDeviceDisplayLabel($device),
        'supported' => false,
        'device_type' => deviceTypeLabelForApiResponse($device),
        'business_source' => deviceBusinessSourceForApiResponse(deviceTypeLabelForApiResponse($device)),
        'backend_driver' => deviceBackendDriverForApiResponse($device),
    ]);
    exit;
}

$url = $device['host'] . '/api/diagnostics/cpu_usage/stream';

$buffer = '';
$currentEvent = [];

$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL => $url,
    CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
    CURLOPT_USERPWD => $device['api_key'] . ':' . $device['api_secret'],
    CURLOPT_SSL_VERIFYPEER => (bool)$device['verify_ssl'],
    CURLOPT_SSL_VERIFYHOST => !empty($device['verify_ssl']) ? 2 : 0,
    CURLOPT_TIMEOUT => 0,
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_HTTPHEADER => [
        'Accept: text/event-stream',
        'Cache-Control: no-cache',
    ],
    CURLOPT_WRITEFUNCTION => static function ($ch, $chunk) use (&$buffer, &$currentEvent) {
        $buffer .= $chunk;

        while (($pos = strpos($buffer, "\n")) !== false) {
            $line = rtrim(substr($buffer, 0, $pos), "\r");
            $buffer = substr($buffer, $pos + 1);

            if ($line === '') {
                if (($currentEvent['event'] ?? '') === 'message' && !empty($currentEvent['data'])) {
                    $decoded = json_decode($currentEvent['data'], true);

                    if (is_array($decoded)) {
                        emitSse($decoded);
                    }
                }

                $currentEvent = [];
                continue;
            }

            if (str_starts_with($line, 'event:')) {
                $currentEvent['event'] = trim(substr($line, 6));
                continue;
            }

            if (str_starts_with($line, 'data:')) {
                $dataLine = trim(substr($line, 5));
                $currentEvent['data'] = ($currentEvent['data'] ?? '') . $dataLine;
            }
        }

        if (connection_aborted()) {
            return 0;
        }

        return strlen($chunk);
    },
]);

$result = curl_exec($ch);
$error = curl_error($ch);
curl_close($ch);

if ($result === false && $error !== '' && !connection_aborted()) {
    emitSse([
        'error' => $error,
    ]);
}
