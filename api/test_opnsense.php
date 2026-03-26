<?php
header('Content-Type: application/json');

require_once __DIR__ . '/../includes/device_manager.php';
require_once __DIR__ . '/../docs/mikhmon/lib/routeros_api.class.php';

function post_string_or_null(string $key): ?string
{
    $value = trim((string)($_POST[$key] ?? ''));
    return $value === '' ? null : $value;
}

function probeTcpHost(string $host, int $port, float $timeout = 3.0): array
{
    $errno = 0;
    $errstr = '';
    $socket = @fsockopen($host, $port, $errno, $errstr, $timeout);

    if (is_resource($socket)) {
        fclose($socket);
        return [
            'reachable' => true,
            'error' => null,
        ];
    }

    return [
        'reachable' => false,
        'error' => trim($errstr) !== '' ? trim($errstr) : ('Erreur socket ' . $errno),
    ];
}

// =========================
// GET INPUT
// =========================
$type = normalizeDeviceType((string)($_POST['type'] ?? 'opnsense'));
$host = post_string_or_null('host');
$key = post_string_or_null('api_key');
$secret = post_string_or_null('api_secret');
$verify_ssl = ($_POST['verify_ssl'] ?? 'false') === 'true';
$statusOnly = isset($_POST['status_only']);

// =========================
// VALIDATION
// =========================
if ($host === null) {
    echo json_encode([
        'success' => false,
        'log' => "❌ Missing host"
    ]);
    exit;
}

if ($type === 'other') {
    echo json_encode([
        'success' => false,
        'log' => "❌ Test backend indisponible pour ce type de device"
    ]);
    exit;
}

if ($key === null || $secret === null) {
    echo json_encode([
        'success' => false,
        'log' => "❌ Missing API credentials"
    ]);
    exit;
}

function respond_test(bool $success, string $type, string $message, int $httpCode = 0): void
{
    global $statusOnly;

    $payload = [
        'success' => $success,
        'device_type' => $type,
        'backend' => resolveDeviceBackend($type),
        'device_status' => $success ? 'active' : 'offline',
    ];

    if (!$statusOnly) {
        $payload['log'] = $message . ($httpCode > 0 ? "\nHTTP: $httpCode" : '');
    }

    echo json_encode($payload);
    exit;
}

$host = normalizeDeviceHost($host);

if ($type === 'mikrotik') {
    $parsedHost = parse_url($host);
    $routerHost = is_array($parsedHost) && !empty($parsedHost['host']) ? (string)$parsedHost['host'] : $host;
    $routerPort = is_array($parsedHost) && !empty($parsedHost['port'])
        ? (int)$parsedHost['port']
        : ((is_array($parsedHost) && (($parsedHost['scheme'] ?? '') === 'https')) ? 8729 : 8728);
    $routerSsl = $routerPort === 8729 || (is_array($parsedHost) && (($parsedHost['scheme'] ?? '') === 'https'));

    $api = new RouterosAPI();
    $api->port = $routerPort;
    $api->ssl = $routerSsl;
    $api->timeout = 5;
    $api->attempts = 1;
    $api->delay = 0;

    $tcpProbe = probeTcpHost($routerHost, $routerPort);
    if (!$tcpProbe['reachable']) {
        echo json_encode([
            'success' => false,
            'device_type' => $type,
            'backend' => resolveDeviceBackend($type),
            'device_status' => 'offline',
            'log' => "❌ Device hors ligne\nHost: {$routerHost}:{$routerPort}\n" . ($tcpProbe['error'] ?? 'Connexion impossible'),
        ]);
        exit;
    }

    if (!$api->connect($routerHost, $key, $secret)) {
        $error = trim((string)($api->error_str ?? 'Connexion MikroTik impossible'));
        echo json_encode([
            'success' => false,
            'device_type' => $type,
            'backend' => resolveDeviceBackend($type),
            'device_status' => 'connected',
            'log' => "⚠ Device connecte au reseau mais test API echoue\nHost: {$routerHost}:{$routerPort}\n" . $error,
        ]);
        exit;
    }

    $resource = $api->comm('/system/resource/print');
    $api->disconnect();

    if (!is_array($resource)) {
        respond_test(false, $type, "❌ MikroTik API response invalide\nHost: {$routerHost}:{$routerPort}");
    }

    respond_test(true, $type, "✔ Connected successfully\nType: MIKROTIK\nBackend: " . resolveDeviceBackend($type) . "\nHost: {$routerHost}:{$routerPort}");
}

$parsedHost = parse_url($host);
$apiHost = is_array($parsedHost) && !empty($parsedHost['host']) ? (string)$parsedHost['host'] : preg_replace('#^https?://#', '', $host);
$apiPort = is_array($parsedHost) && !empty($parsedHost['port'])
    ? (int)$parsedHost['port']
    : ((is_array($parsedHost) && (($parsedHost['scheme'] ?? '') === 'http')) ? 80 : 443);
$tcpProbe = probeTcpHost($apiHost, $apiPort);

if (!$tcpProbe['reachable']) {
    echo json_encode([
        'success' => false,
        'device_type' => $type,
        'backend' => resolveDeviceBackend($type),
        'device_status' => 'offline',
        'log' => "❌ Device hors ligne\nHost: {$apiHost}:{$apiPort}\n" . ($tcpProbe['error'] ?? 'Connexion impossible'),
    ]);
    exit;
}

$url = rtrim($host, '/') . '/api/core/system/status';

// =========================
// CURL INIT
// =========================
$ch = curl_init();

curl_setopt_array($ch, [
    CURLOPT_URL => $url,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_USERPWD => $key . ":" . $secret,
    CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
    CURLOPT_SSL_VERIFYPEER => $verify_ssl,
    CURLOPT_SSL_VERIFYHOST => $verify_ssl ? 2 : 0,
    CURLOPT_TIMEOUT => 10
]);

$response = curl_exec($ch);
$error = curl_error($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

curl_close($ch);

// =========================
// CURL ERROR
// =========================
if ($error) {
    echo json_encode([
        'success' => false,
        'device_type' => $type,
        'backend' => resolveDeviceBackend($type),
        'device_status' => 'connected',
        'log' => "⚠ Device connecte au reseau mais test API echoue\n" . $error
    ]);
    exit;
}

// =========================
// RESPONSE ANALYSIS
// =========================
$decoded = json_decode($response, true);

// =========================
// SUCCESS CHECK
// =========================
if ($http_code === 200 && is_array($decoded)) {
    respond_test(true, $type, "✔ Connected successfully\nType: " . strtoupper($type) . "\nBackend: " . resolveDeviceBackend($type), $http_code);
}

// =========================
// FAILED
// =========================
echo json_encode([
    'success' => false,
    'device_type' => $type,
    'backend' => resolveDeviceBackend($type),
    'device_status' => 'connected',
    'log' => "⚠ Device connecte au reseau mais test API echoue\nResponse: " . substr($response, 0, 200) . ($http_code > 0 ? "\nHTTP: $http_code" : ''),
]);
