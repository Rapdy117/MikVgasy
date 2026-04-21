<?php

require_once __DIR__ . '/device_manager.php';
require_once __DIR__ . '/lib/routeros_api.class.php';

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
        CURLOPT_USERPWD => $key . ':' . $secret,
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

function buildDeviceProbeResult(
    bool $success,
    string $type,
    string $message,
    int $httpCode = 0,
    bool $statusOnly = false,
    array $extra = []
): array {
    $payload = [
        'success' => $success,
        'device_type' => $type,
        'backend_driver' => resolveDeviceBackend($type),
        'business_source' => resolveDeviceBusinessSource($type),
        'device_status' => $success ? 'active' : 'offline',
    ];

    if (!$statusOnly) {
        $payload['log'] = $message . ($httpCode > 0 ? "\nHTTP: {$httpCode}" : '');
    }

    return array_merge($payload, $extra);
}

function probeDeviceConnection(array $device, bool $statusOnly = false): array
{
    $rawType = trim((string)($device['type'] ?? ''));
    if ($rawType === '') {
        return [
            'success' => false,
            'log' => '❌ Type de device manquant',
        ];
    }

    try {
        $type = normalizeDeviceType($rawType);
    } catch (InvalidArgumentException $e) {
        return [
            'success' => false,
            'log' => '❌ ' . $e->getMessage(),
        ];
    }

    if ($type === 'radius') {
        return buildDeviceProbeResult(
            false,
            $type,
            "❌ Le test de connexion API n'est pas applicable pour RADIUS.",
            0,
            $statusOnly
        );
    }

    $host = normalizeDeviceHost((string)($device['host'] ?? ''));
    $key = trim((string)($device['api_key'] ?? ''));
    $secret = trim((string)($device['api_secret'] ?? ($device['secret'] ?? '')));
    $verifySsl = !empty($device['verify_ssl']);

    if ($host === '') {
        return buildDeviceProbeResult(false, $type, '❌ Host manquant', 0, $statusOnly);
    }

    if ($key === '' || $secret === '') {
        return buildDeviceProbeResult(false, $type, '❌ Identifiants API manquants', 0, $statusOnly);
    }

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
            return buildDeviceProbeResult(
                false,
                $type,
                "❌ Device hors ligne\nHost: {$routerHost}:{$routerPort}\n" . ($tcpProbe['error'] ?? 'Connexion impossible'),
                0,
                $statusOnly
            );
        }

        if (!$api->connect($routerHost, $key, $secret)) {
            $error = trim((string)($api->error_str ?? 'Connexion MikroTik impossible'));
            return buildDeviceProbeResult(
                false,
                $type,
                "⚠ Device joignable mais le test de connexion a echoue\nHost: {$routerHost}:{$routerPort}\n{$error}",
                0,
                $statusOnly,
                ['device_status' => 'connected']
            );
        }

        $resource = $api->comm('/system/resource/print');
        $api->disconnect();

        if (!is_array($resource)) {
            return buildDeviceProbeResult(
                false,
                $type,
                "❌ Reponse API invalide\nHost: {$routerHost}:{$routerPort}",
                0,
                $statusOnly
            );
        }

        return buildDeviceProbeResult(
            true,
            $type,
            "✔ Connexion reussie\nType: MIKROTIK\nBackend: " . resolveDeviceBackend($type) . "\nHost: {$routerHost}:{$routerPort}",
            0,
            $statusOnly
        );
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
        return buildDeviceProbeResult(
            false,
            $type,
            "❌ Device hors ligne\nHost: {$firstProbeTarget['host']}:{$firstProbeTarget['port']}\n"
                . ($firstProbeError !== '' ? $firstProbeError : 'Connexion impossible'),
            0,
            $statusOnly
        );
    }

    foreach ($statusUrls as $url) {
        $attempt = executeOpnsenseStatusRequest($url, $key, $secret, $verifySsl);
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
        $message = "✔ Connexion reussie\nType: " . strtoupper($type) . "\nBackend: " . resolveDeviceBackend($type);
        $firstUrl = $attempts[0]['url'] ?? '';
        $okUrl = $successfulAttempt['url'] ?? '';
        if ($firstUrl !== '' && $okUrl !== '' && $firstUrl !== $okUrl) {
            $message .= "\n⚠ Le host semble utiliser un autre schema.\nURL valide detectee: {$okUrl}";
        }

        $payload = buildDeviceProbeResult(
            true,
            $type,
            $message,
            (int)($successfulAttempt['http_code'] ?? 200),
            $statusOnly
        );
        if ($suggestedHost !== null) {
            $payload['suggested_host'] = $suggestedHost;
        }
        return $payload;
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

    return buildDeviceProbeResult(
        false,
        $type,
        "⚠ Device joignable mais le test de connexion a echoue\n{$firstError}",
        0,
        $statusOnly,
        ['device_status' => 'connected']
    );
}
