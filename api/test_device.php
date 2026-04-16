<?php
header('Content-Type: application/json');

require_once __DIR__ . '/../includes/device_manager.php';
require_once __DIR__ . '/../includes/lib/routeros_api.class.php';

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

$rawType = post_string_or_null('type');
if ($rawType === null) {
    echo json_encode([
        'success' => false,
        'log' => "❌ Type de device manquant",
    ]);
    exit;
}

try {
    $type = normalizeDeviceType($rawType);
} catch (InvalidArgumentException $e) {
    echo json_encode([
        'success' => false,
        'log' => '❌ ' . $e->getMessage(),
    ]);
    exit;
}

if ($type === 'radius') {
    $radiusDriver = resolveDeviceBackend($type);
    echo json_encode([
        'success' => false,
        'device_type' => $type,
        'backend_driver' => $radiusDriver,
        'business_source' => resolveDeviceBusinessSource($type),
        'device_status' => 'offline',
        'log' => "❌ Le test de connexion API n'est pas applicable pour RADIUS.",
    ]);
    exit;
}

$host = post_string_or_null('host');
$key = post_string_or_null('api_key');
$secret = post_string_or_null('api_secret');
$verify_ssl = ($_POST['verify_ssl'] ?? 'false') === 'true';
$statusOnly = isset($_POST['status_only']);

if ($host === null) {
    echo json_encode([
        'success' => false,
        'log' => "❌ Host manquant"
    ]);
    exit;
}

if ($key === null || $secret === null) {
    echo json_encode([
        'success' => false,
        'log' => "❌ Identifiants API manquants"
    ]);
    exit;
}

function respond_test(bool $success, string $type, string $message, int $httpCode = 0): void
{
    global $statusOnly;

    $driver = resolveDeviceBackend($type);
    $payload = [
        'success' => $success,
        'device_type' => $type,
        'backend_driver' => $driver,
        'device_status' => $success ? 'active' : 'offline',
    ];

    if (!$statusOnly) {
        $payload['log'] = $message . ($httpCode > 0 ? "\nHTTP: $httpCode" : '');
    }

    echo json_encode($payload);
    exit;
}

function buildOpnsenseStatusUrls(string $host): array
{
    $host = rtrim($host, '/');
    $urls = [];

    if (preg_match('#^https?://#i', $host)) {
        $urls[] = $host . '/api/core/system/status';
        $parsed = parse_url($host);
        if (is_array($parsed) && !empty($parsed['host']) && !empty($parsed['scheme'])) {
            $altScheme = strtolower((string)$parsed['scheme']) === 'https' ? 'http' : 'https';
            $authority = (string)$parsed['host'] . (isset($parsed['port']) ? ':' . (int)$parsed['port'] : '');
            $path = isset($parsed['path']) ? (string)$parsed['path'] : '';
            $urls[] = $altScheme . '://' . $authority . rtrim($path, '/') . '/api/core/system/status';
        }
    } else {
        $urls[] = 'https://' . $host . '/api/core/system/status';
        $urls[] = 'http://' . $host . '/api/core/system/status';
    }

    return array_values(array_unique($urls));
}

function executeOpnsenseStatusRequest(string $url, string $key, string $secret, bool $verifySsl): array
{
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERPWD => $key . ":" . $secret,
        CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
        CURLOPT_SSL_VERIFYPEER => $verifySsl,
        CURLOPT_SSL_VERIFYHOST => $verifySsl ? 2 : 0,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_FOLLOWLOCATION => false,
    ]);

    $response = curl_exec($ch);
    $error = trim((string)curl_error($ch));
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $redirectUrl = (string)curl_getinfo($ch, CURLINFO_REDIRECT_URL);
    curl_close($ch);

    return [
        'url' => $url,
        'response' => is_string($response) ? $response : '',
        'error' => $error,
        'http_code' => $httpCode,
        'redirect_url' => $redirectUrl,
    ];
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
        $mkDriver = resolveDeviceBackend($type);
        echo json_encode([
            'success' => false,
            'device_type' => $type,
            'backend_driver' => $mkDriver,
            'device_status' => 'offline',
            'log' => "❌ Device hors ligne\nHost: {$routerHost}:{$routerPort}\n" . ($tcpProbe['error'] ?? 'Connexion impossible'),
        ]);
        exit;
    }

    if (!$api->connect($routerHost, $key, $secret)) {
        $error = trim((string)($api->error_str ?? 'Connexion MikroTik impossible'));
        $mkDriver = resolveDeviceBackend($type);
        echo json_encode([
            'success' => false,
            'device_type' => $type,
            'backend_driver' => $mkDriver,
            'device_status' => 'connected',
            'log' => "⚠ Device joignable mais le test de connexion a echoue\nHost: {$routerHost}:{$routerPort}\n" . $error,
        ]);
        exit;
    }

    $resource = $api->comm('/system/resource/print');
    $api->disconnect();

    if (!is_array($resource)) {
        respond_test(false, $type, "❌ Reponse API invalide\nHost: {$routerHost}:{$routerPort}");
    }

    respond_test(true, $type, "✔ Connexion reussie\nType: MIKROTIK\nBackend: " . resolveDeviceBackend($type) . "\nHost: {$routerHost}:{$routerPort}");
}

$attempts = [];
$successfulAttempt = null;
$suggestedHost = null;
$statusUrls = buildOpnsenseStatusUrls($host);
$probeTargets = [];

foreach ($statusUrls as $url) {
    $parsed = parse_url($url);
    if (!is_array($parsed) || empty($parsed['host'])) {
        continue;
    }

    $probeHost = (string)$parsed['host'];
    $probePort = isset($parsed['port'])
        ? (int)$parsed['port']
        : (((string)($parsed['scheme'] ?? 'https')) === 'http' ? 80 : 443);
    $targetKey = $probeHost . ':' . $probePort;
    $probeTargets[$targetKey] = [
        'host' => $probeHost,
        'port' => $probePort,
    ];
}

$firstProbeError = '';
$firstProbeTarget = null;
$probeReachable = false;
foreach ($probeTargets as $target) {
    $tcpProbe = probeTcpHost($target['host'], $target['port']);
    if ($tcpProbe['reachable']) {
        $probeReachable = true;
        break;
    }

    if ($firstProbeTarget === null) {
        $firstProbeTarget = $target;
    }
    if ($firstProbeError === '' && trim((string)($tcpProbe['error'] ?? '')) !== '') {
        $firstProbeError = trim((string)$tcpProbe['error']);
    }
}

if (!$probeReachable && $firstProbeTarget !== null) {
    $opnDriver = resolveDeviceBackend($type);
    echo json_encode([
        'success' => false,
        'device_type' => $type,
        'backend_driver' => $opnDriver,
        'device_status' => 'offline',
        'log' => "❌ Device hors ligne\nHost: {$firstProbeTarget['host']}:{$firstProbeTarget['port']}\n"
            . ($firstProbeError !== '' ? $firstProbeError : 'Connexion impossible'),
    ]);
    exit;
}

foreach ($statusUrls as $url) {
    $attempt = executeOpnsenseStatusRequest($url, (string)$key, (string)$secret, $verify_ssl);
    $attempts[] = $attempt;

    if ($attempt['error'] !== '') {
        continue;
    }

    $decoded = json_decode((string)$attempt['response'], true);
    if ((int)$attempt['http_code'] === 200 && is_array($decoded)) {
        $successfulAttempt = $attempt;
        $parsed = parse_url((string)$attempt['url']);
        if (is_array($parsed) && isset($parsed['scheme'], $parsed['host'])) {
            $suggestedHost = (string)$parsed['scheme'] . '://' . (string)$parsed['host']
                . (isset($parsed['port']) ? ':' . (int)$parsed['port'] : '');
        }
        break;
    }
}

if (is_array($successfulAttempt)) {
    $okDriver = resolveDeviceBackend($type);
    $message = "✔ Connexion reussie\nType: " . strtoupper($type) . "\nBackend: " . $okDriver;
    $firstUrl = $attempts[0]['url'] ?? '';
    $okUrl = $successfulAttempt['url'] ?? '';
    if ($firstUrl !== '' && $okUrl !== '' && $firstUrl !== $okUrl) {
        $message .= "\n⚠ Le host semble utiliser un autre schema.\nURL valide detectee: {$okUrl}";
    }

    $payload = [
        'success' => true,
        'device_type' => $type,
        'backend_driver' => $okDriver,
        'device_status' => 'active',
        'log' => $message . "\nHTTP: " . (int)($successfulAttempt['http_code'] ?? 200),
    ];
    if ($suggestedHost !== null) {
        $payload['suggested_host'] = $suggestedHost;
    }
    echo json_encode($payload);
    exit;
}

$firstError = '';
foreach ($attempts as $attempt) {
    if (trim((string)($attempt['error'] ?? '')) !== '') {
        $firstError = trim((string)$attempt['error']);
        break;
    }
}

if ($firstError === '') {
    foreach ($attempts as $attempt) {
        $code = (int)($attempt['http_code'] ?? 0);
        $redirect = trim((string)($attempt['redirect_url'] ?? ''));
        if ($code >= 300 && $code < 400 && $redirect !== '') {
            $firstError = "Le endpoint API redirige vers: {$redirect}\nVérifiez l'IP/port d'administration OPNsense (portail captif ou mauvaise interface).";
            break;
        }
    }
}

if ($firstError === '' && isset($attempts[0])) {
    $firstError = 'Response: ' . substr((string)($attempts[0]['response'] ?? ''), 0, 200);
    if ((int)($attempts[0]['http_code'] ?? 0) > 0) {
        $firstError .= "\nHTTP: " . (int)$attempts[0]['http_code'];
    }
}

$failDriver = resolveDeviceBackend($type);
echo json_encode([
    'success' => false,
    'device_type' => $type,
    'backend_driver' => $failDriver,
    'device_status' => 'connected',
    'log' => "⚠ Device joignable mais le test de connexion a echoue\n" . $firstError,
]);
