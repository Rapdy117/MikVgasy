<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/device_manager.php';

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

if (($device['type'] ?? '') !== 'opnsense') {
    emitSse([
        'error' => 'Flux trafic indisponible pour le device actif ' . getDeviceDisplayLabel($device),
        'supported' => false,
        'device_type' => (string)($device['type'] ?? 'other'),
        'backend' => (string)($device['backend'] ?? 'generic'),
    ]);
    exit;
}

$url = $device['host'] . '/api/diagnostics/traffic/stream/1';

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
