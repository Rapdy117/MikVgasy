<?php

function backendAgentProjectRoot(): string
{
    return dirname(__DIR__);
}

function backendAgentExecutablePath(): string
{
    return backendAgentProjectRoot() . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'agent' . DIRECTORY_SEPARATOR . 'backend-agent.exe';
}

function backendAgentRun(array $arguments): array
{
    $config = backendAgentServiceConfig();
    $command = (string)($arguments[0] ?? '');
    $request = backendAgentBuildServiceRequest($arguments);
    $endpoint = match ($command) {
        'check-license' => '/v1/check-license',
        'check-integrity' => '/v1/check-integrity',
        'authorize-action' => '/v1/authorize-action',
        'apply-recharge' => '/v1/apply-recharge',
        default => throw new RuntimeException('Commande backend agent non supportée par le service: ' . $command),
    };

    $jsonPayload = json_encode($request, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (!is_string($jsonPayload)) {
        throw new RuntimeException('Payload service agent invalide.');
    }

    $raw = backendAgentServicePost(rtrim($config['url'], '/') . $endpoint, $config['token'], $jsonPayload);
    $response = json_decode($raw, true);
    if (!is_array($response)) {
        throw new RuntimeException('Réponse backend agent service invalide: ' . ($raw !== '' ? $raw : 'vide'));
    }

    if (empty($response['success'])) {
        $message = trim((string)($response['message'] ?? 'Action refusée par backend-agent.exe'));
        $code = trim((string)($response['code'] ?? 'AGENT_REFUSED'));
        throw new RuntimeException($code . ': ' . $message);
    }

    return $response;
}

function backendAgentServicePost(string $url, string $token, string $jsonPayload): string
{
    if (!extension_loaded('curl')) {
        throw new RuntimeException('Extension PHP cURL requise pour backend-agent service.');
    }

    $curl = curl_init($url);
    if ($curl === false) {
        throw new RuntimeException('Initialisation cURL impossible pour backend-agent service.');
    }

    curl_setopt_array($curl, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $jsonPayload,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'X-Agent-Token: ' . $token,
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 5,
    ]);

    $raw = curl_exec($curl);
    $error = curl_error($curl);
    curl_close($curl);

    if (!is_string($raw)) {
        throw new RuntimeException('Backend agent service indisponible: ' . ($error !== '' ? $error : $url));
    }

    return trim($raw);
}

function backendAgentServiceConfig(): array
{
    $path = backendAgentProjectRoot() . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'license' . DIRECTORY_SEPARATOR . 'agent_service.json';
    if (!is_file($path)) {
        throw new RuntimeException('Configuration service agent manquante: ' . $path);
    }

    $config = json_decode((string)file_get_contents($path), true);
    if (!is_array($config)) {
        throw new RuntimeException('Configuration service agent invalide.');
    }

    $url = trim((string)($config['url'] ?? ''));
    $token = trim((string)($config['token'] ?? ''));
    if ($url === '' || $token === '') {
        throw new RuntimeException('Configuration service agent incomplete.');
    }

    return [
        'url' => $url,
        'token' => $token,
    ];
}

function backendAgentBuildServiceRequest(array $arguments): array
{
    $command = (string)($arguments[0] ?? '');
    $values = backendAgentParseArguments(array_slice($arguments, 1));

    return match ($command) {
        'check-license' => [
            'device_id' => (string)($values['device-id'] ?? ''),
            'payload' => new stdClass(),
        ],
        'check-integrity' => [
            'payload' => new stdClass(),
        ],
        'authorize-action' => [
            'action' => (string)($values['action'] ?? ''),
            'device_id' => (string)($values['device-id'] ?? ''),
            'payload' => backendAgentDecodePayload((string)($values['payload'] ?? '{}')),
        ],
        'apply-recharge' => [
            'payload' => [
                'device_id' => (string)($values['device-id'] ?? ''),
                'username' => (string)($values['username'] ?? ''),
                'profile_value' => (string)($values['profile-value'] ?? ''),
                'mode' => (string)($values['mode'] ?? ''),
            ],
        ],
        default => throw new RuntimeException('Commande backend agent inconnue: ' . $command),
    };
}

function backendAgentParseArguments(array $arguments): array
{
    $values = [];
    for ($index = 0; $index < count($arguments); $index++) {
        $key = (string)$arguments[$index];
        if (!str_starts_with($key, '--')) {
            continue;
        }

        $name = substr($key, 2);
        $values[$name] = (string)($arguments[$index + 1] ?? '');
        $index++;
    }

    return $values;
}

function backendAgentDecodePayload(string $payload): array|stdClass
{
    $decoded = json_decode($payload, true);
    if (!is_array($decoded)) {
        throw new RuntimeException('Payload backend agent invalide.');
    }

    if ($decoded === []) {
        return new stdClass();
    }

    return $decoded;
}

function backendAgentAuthorizeAction(string $action, string $deviceId, array $payload = []): array
{
    $jsonPayload = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (!is_string($jsonPayload)) {
        throw new RuntimeException('Payload agent invalide.');
    }

    return backendAgentRun([
        'authorize-action',
        '--action',
        $action,
        '--device-id',
        $deviceId,
        '--payload',
        $jsonPayload,
        '--app-dir',
        backendAgentProjectRoot(),
    ]);
}

function backendAgentDeviceLicenseId(array $device): string
{
    $fingerprint = trim((string)($device['device_fingerprint'] ?? ''));
    if ($fingerprint === '') {
        throw new RuntimeException('Device fingerprint manquant pour backend-agent.exe.');
    }

    $type = strtolower(trim((string)($device['type'] ?? 'dev')));
    $prefix = match ($type) {
        'mikrotik' => 'MK',
        'opnsense' => 'OPN',
        'radius' => 'RAD',
        default => 'DEV',
    };
    $hex = strtoupper(substr($fingerprint, 0, 12));

    return sprintf('%s-%s-%s-%s', $prefix, substr($hex, 0, 4), substr($hex, 4, 4), substr($hex, 8, 4));
}

function backendAgentAuthorizeDeviceAction(array $device, string $action, array $payload = []): array
{
    return backendAgentAuthorizeAction($action, backendAgentDeviceLicenseId($device), $payload);
}

function backendAgentCheckLicense(string $deviceId): array
{
    return backendAgentRun([
        'check-license',
        '--device-id',
        $deviceId,
        '--app-dir',
        backendAgentProjectRoot(),
    ]);
}

function backendAgentCheckIntegrity(): array
{
    return backendAgentRun([
        'check-integrity',
        '--app-dir',
        backendAgentProjectRoot(),
    ]);
}

function backendAgentActivateLicense(string $licenseKey, string $deviceId): array
{
    $exe = backendAgentProjectRoot() . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'agent' . DIRECTORY_SEPARATOR . 'activation-key.exe';
    if (!is_file($exe)) {
        throw new RuntimeException('Activation agent indisponible: ' . $exe);
    }

    $command = escapeshellarg($exe)
        . ' activate --license ' . escapeshellarg($licenseKey)
        . ' --device-id ' . escapeshellarg($deviceId)
        . ' --app-dir ' . escapeshellarg(backendAgentProjectRoot());

    $output = [];
    $exitCode = 0;
    exec($command . ' 2>&1', $output, $exitCode);

    $raw = trim(implode("\n", $output));
    $response = json_decode($raw, true);
    if (!is_array($response)) {
        throw new RuntimeException('Réponse activation agent invalide: ' . ($raw !== '' ? $raw : 'vide'));
    }

    if ($exitCode !== 0 || empty($response['success'])) {
        $message = trim((string)($response['message'] ?? 'Activation refusée par activation-key.exe'));
        $code = trim((string)($response['code'] ?? 'ACTIVATION_REFUSED'));
        throw new RuntimeException($code . ': ' . $message);
    }

    return $response;
}

function backendAgentApplyRecharge(string $deviceId, string $username, string $profileValue, string $mode): array
{
    return backendAgentRun([
        'apply-recharge',
        '--device-id',
        $deviceId,
        '--username',
        $username,
        '--profile-value',
        $profileValue,
        '--mode',
        $mode,
        '--app-dir',
        backendAgentProjectRoot(),
    ]);
}
