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

function normalizeDeviceRecord(array $device): array
{
    $type = normalizeDeviceType((string)($device['type'] ?? 'opnsense'));
    $host = trim((string)($device['host'] ?? ''));

    return [
        'id' => (string)($device['id'] ?? ''),
        'name' => trim((string)($device['name'] ?? '')),
        'type' => $type,
        'host' => $host,
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
    foreach ($store['devices'] as $device) {
        if (($device['type'] ?? '') === 'opnsense') {
            return $device;
        }
    }

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
        ];
    }

    return [
        'id' => null,
        'name' => 'OPNsense',
        'type' => 'opnsense',
        'host' => preg_replace('#^https?://#', '', rtrim(OPN_SENSE_URL, '/')),
    ];
}

function loadActiveOpnSenseDevice(): array
{
    $store = loadDeviceStore();
    $activeDevice = getActiveDeviceRecord($store);

    if (
        $activeDevice &&
        ($activeDevice['type'] ?? '') === 'opnsense' &&
        !empty($activeDevice['host']) &&
        !empty($activeDevice['api_key']) &&
        !empty($activeDevice['api_secret'])
    ) {
        return [
            'id' => $activeDevice['id'],
            'name' => $activeDevice['name'] !== '' ? $activeDevice['name'] : 'OPNsense',
            'host' => rtrim((string)$activeDevice['host'], '/'),
            'api_key' => (string)$activeDevice['api_key'],
            'api_secret' => (string)$activeDevice['api_secret'],
            'verify_ssl' => !empty($activeDevice['verify_ssl']),
            'source' => 'active_device',
        ];
    }

    foreach ($store['devices'] as $device) {
        if (
            ($device['type'] ?? '') === 'opnsense' &&
            !empty($device['host']) &&
            !empty($device['api_key']) &&
            !empty($device['api_secret'])
        ) {
            return [
                'id' => $device['id'],
                'name' => $device['name'] !== '' ? $device['name'] : 'OPNsense',
                'host' => rtrim((string)$device['host'], '/'),
                'api_key' => (string)$device['api_key'],
                'api_secret' => (string)$device['api_secret'],
                'verify_ssl' => !empty($device['verify_ssl']),
                'source' => 'fallback_opnsense',
            ];
        }
    }

    return [
        'id' => null,
        'name' => 'OPNsense',
        'host' => rtrim(OPN_SENSE_URL, '/'),
        'api_key' => OPN_SENSE_API_KEY,
        'api_secret' => OPN_SENSE_API_SECRET,
        'verify_ssl' => CURL_VERIFY_SSL,
        'source' => 'config_fallback',
    ];
}
