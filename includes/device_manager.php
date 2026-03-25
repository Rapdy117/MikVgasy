<?php

require_once __DIR__ . '/../config/config.php';

function deviceConfigFilePath(): string
{
    return __DIR__ . '/../config/opnsense.json';
}

function normalizeDeviceType(string $type): string
{
    $normalized = strtolower(trim($type));

    return match ($normalized) {
        'opnsense' => 'opnsense',
        'mikrotik' => 'mikrotik',
        'radius', 'other', 'autre' => 'other',
        default => 'other',
    };
}

function deriveDeviceType(array $device): string
{
    $vendor = strtolower(trim((string)($device['vendor'] ?? '')));
    $type = normalizeDeviceType((string)($device['type'] ?? 'opnsense'));

    if ($type === 'other' && $vendor === 'mikrotik') {
        return 'mikrotik';
    }

    return $type;
}

function normalizeDeviceHost(string $host): string
{
    $host = trim($host);

    if ($host === '') {
        return '';
    }

    if (preg_match('#^https?://#i', $host)) {
        return rtrim($host, '/');
    }

    return $host;
}

function extractDeviceAddress(string $host): string
{
    $host = trim($host);
    if ($host === '') {
        return '';
    }

    $parsedHost = parse_url($host, PHP_URL_HOST);
    if (is_string($parsedHost) && $parsedHost !== '') {
        return $parsedHost;
    }

    return preg_replace('#:\d+$#', '', $host) ?? $host;
}

function resolveDeviceBackend(string $type): string
{
    return match (normalizeDeviceType($type)) {
        'opnsense' => 'opnsense_api',
        'mikrotik' => 'mikrotik_api',
        default => 'generic',
    };
}

function normalizeDeviceRecord(array $device): array
{
    $type = deriveDeviceType($device);
    $host = normalizeDeviceHost((string)($device['host'] ?? ''));

    return [
        'id' => (string)($device['id'] ?? ''),
        'name' => trim((string)($device['name'] ?? '')),
        'type' => $type,
        'host' => $host,
        'ip' => extractDeviceAddress($host),
        'backend' => resolveDeviceBackend($type),
        'api_key' => trim((string)($device['api_key'] ?? '')),
        'api_secret' => trim((string)($device['api_secret'] ?? ($device['secret'] ?? ''))),
        'secret' => trim((string)($device['secret'] ?? ($device['api_secret'] ?? ''))),
        'verify_ssl' => !empty($device['verify_ssl']),
        'port' => isset($device['port']) ? (int)$device['port'] : null,
        'vendor' => isset($device['vendor']) ? (string)$device['vendor'] : null,
        'created_at' => $device['created_at'] ?? null,
        'updated_at' => $device['updated_at'] ?? null,
    ];
}

function ensureDeviceStore(array $payload): array
{
    $devices = array_map('normalizeDeviceRecord', $payload['devices'] ?? []);

    return [
        'active_device_id' => isset($payload['active_device_id']) ? (string)$payload['active_device_id'] : null,
        'devices' => $devices,
    ];
}

function loadDeviceStore(): array
{
    $file = deviceConfigFilePath();

    if (!is_file($file)) {
        return [
            'active_device_id' => null,
            'devices' => [],
        ];
    }

    $payload = json_decode((string)file_get_contents($file), true);
    if (!is_array($payload)) {
        return [
            'active_device_id' => null,
            'devices' => [],
        ];
    }

    return ensureDeviceStore($payload);
}

function saveDeviceStore(array $store): void
{
    $normalized = ensureDeviceStore($store);
    file_put_contents(deviceConfigFilePath(), json_encode($normalized, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
}

function findDeviceById(array $store, ?string $deviceId): ?array
{
    if ($deviceId === null || $deviceId === '') {
        return null;
    }

    foreach ($store['devices'] as $device) {
        if (($device['id'] ?? '') === $deviceId) {
            return $device;
        }
    }

    return null;
}

function pickDefaultDevice(array $store): ?array
{
    return $store['devices'][0] ?? null;
}

function getActiveDeviceRecord(array $store): ?array
{
    $sessionDeviceId = isset($_SESSION['active_device_id']) ? (string)$_SESSION['active_device_id'] : null;
    $storedDeviceId = isset($store['active_device_id']) ? (string)$store['active_device_id'] : null;

    $device = findDeviceById($store, $sessionDeviceId)
        ?? findDeviceById($store, $storedDeviceId)
        ?? pickDefaultDevice($store);

    if ($device) {
        $_SESSION['active_device_id'] = $device['id'];
    } else {
        unset($_SESSION['active_device_id']);
    }

    return $device;
}

function setActiveDeviceId(string $deviceId): ?array
{
    $store = loadDeviceStore();
    $device = findDeviceById($store, $deviceId);

    if (!$device) {
        return null;
    }

    $store['active_device_id'] = $device['id'];
    $_SESSION['active_device_id'] = $device['id'];
    saveDeviceStore($store);

    return $device;
}

function getNavbarDeviceInfo(): array
{
    $store = loadDeviceStore();
    $device = getActiveDeviceRecord($store);

    if ($device) {
        return [
            'id' => $device['id'],
            'name' => $device['name'] !== '' ? $device['name'] : strtoupper($device['type']),
            'type' => $device['type'],
            'host' => $device['host'],
            'ip' => $device['ip'],
            'backend' => $device['backend'],
        ];
    }

    return [
        'id' => null,
        'name' => 'Aucun device',
        'type' => 'other',
        'host' => '',
        'ip' => '',
        'backend' => 'generic',
    ];
}

function getActiveDeviceContext(): array
{
    $store = loadDeviceStore();
    $activeDevice = getActiveDeviceRecord($store);

    if (!$activeDevice) {
        return [
            'device' => null,
            'source' => 'none',
        ];
    }

    return [
        'device' => $activeDevice,
        'source' => 'active_device',
    ];
}

function requireActiveDevice(): array
{
    $context = getActiveDeviceContext();
    $device = $context['device'] ?? null;

    if (!$device) {
        throw new RuntimeException('Aucun device actif configure.');
    }

    return $device;
}

function requireActiveDeviceType(string $expectedType): array
{
    $device = requireActiveDevice();

    if (($device['type'] ?? '') !== normalizeDeviceType($expectedType)) {
        throw new RuntimeException(sprintf(
            'Le device actif "%s" est de type %s. Backend %s requis.',
            $device['name'] !== '' ? $device['name'] : ($device['ip'] !== '' ? $device['ip'] : 'inconnu'),
            strtoupper((string)($device['type'] ?? 'other')),
            strtoupper(normalizeDeviceType($expectedType))
        ));
    }

    return $device;
}

function canProbeDevice(array $device): bool
{
    $type = (string)($device['type'] ?? 'other');

    if ($type === 'opnsense') {
        return !empty($device['host']) && !empty($device['api_key']) && !empty($device['api_secret']);
    }

    if ($type === 'mikrotik') {
        return !empty($device['host']) && !empty($device['api_key']) && !empty($device['api_secret']);
    }

    return false;
}

function getDeviceDisplayLabel(array $device): string
{
    $name = trim((string)($device['name'] ?? ''));
    $ip = trim((string)($device['ip'] ?? ''));
    $type = strtoupper((string)($device['type'] ?? 'other'));

    if ($name !== '' && $ip !== '') {
        return sprintf('%s (%s, %s)', $name, $ip, $type);
    }

    if ($name !== '') {
        return sprintf('%s (%s)', $name, $type);
    }

    if ($ip !== '') {
        return sprintf('%s (%s)', $ip, $type);
    }

    return $type;
}
