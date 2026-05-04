<?php

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/crypto.php';

/* Champs sensibles chiffrés dans le device store */
const DEVICE_SENSITIVE_FIELDS = ['api_key', 'api_secret', 'secret'];

function &deviceStoreRuntimeCache(): array
{
    static $cache = [
        'mtime' => null,
        'store' => null,
    ];

    return $cache;
}

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
        'radius', 'freeradius' => 'radius',
        default => throw new InvalidArgumentException(sprintf(
            'Type de device invalide: %s',
            $type !== '' ? $type : '(vide)'
        )),
    };
}

function deriveDeviceType(array $device): string
{
    $rawType = trim((string)($device['type'] ?? ''));
    if ($rawType !== '') {
        return normalizeDeviceType($rawType);
    }

    $vendor = strtolower(trim((string)($device['vendor'] ?? '')));

    return match ($vendor) {
        'mikrotik' => 'mikrotik',
        'opnsense' => 'opnsense',
        'radius', 'freeradius' => 'radius',
        default => throw new InvalidArgumentException('Type de device manquant ou invalide'),
    };
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
        'radius' => 'radius',
    };
}

/**
 * Driver d'exécution API pour un device (clé canonique : backend_driver ; backend = alias rétrocompat).
 */
function resolveDeviceRecordBackendDriver(array $device): string
{
    $raw = trim((string)($device['backend_driver'] ?? $device['backend'] ?? ''));
    if ($raw !== '') {
        return $raw;
    }

    return resolveDeviceBackend((string)($device['type'] ?? ''));
}

/**
 * Type device pour réponses API : opnsense | mikrotik | radius, ou null si indéterminé (jamais other / generic / unknown).
 */
function deviceTypeLabelForApiResponse(array $device): ?string
{
    $raw = trim((string)($device['type'] ?? ''));
    if ($raw === '') {
        return null;
    }
    try {
        return normalizeDeviceType($raw);
    } catch (InvalidArgumentException $e) {
        return null;
    }
}

/**
 * Driver API pour réponses JSON : opnsense_api | mikrotik_api | radius, ou null si indéterminé.
 */
function deviceBackendDriverForApiResponse(array $device): ?string
{
    $raw = trim((string)($device['backend_driver'] ?? $device['backend'] ?? ''));
    if ($raw !== '') {
        return $raw;
    }
    $type = trim((string)($device['type'] ?? ''));
    if ($type === '') {
        return null;
    }
    try {
        return resolveDeviceBackend($type);
    } catch (InvalidArgumentException $e) {
        return null;
    }
}

/**
 * Source métier (radius | mikrotik_local) dérivée du type device normalisé.
 */
function deviceBusinessSourceForApiResponse(?string $normalizedDeviceType): ?string
{
    if ($normalizedDeviceType === null || $normalizedDeviceType === '') {
        return null;
    }
    try {
        return resolveDeviceBusinessSource($normalizedDeviceType);
    } catch (InvalidArgumentException $e) {
        return null;
    }
}

function resolveDeviceBusinessSource(string $type): string
{
    return match (normalizeDeviceType($type)) {
        'mikrotik' => 'mikrotik_local',
        'opnsense', 'radius' => 'radius',
    };
}

function normalizeDeviceRecord(array $device): array
{
    $type = deriveDeviceType($device);
    $host = normalizeDeviceHost((string)($device['host'] ?? ''));
    $merged = array_merge($device, ['type' => $type, 'host' => $host]);
    $backendDriver = resolveDeviceRecordBackendDriver($merged);

    return [
        'id' => (string)($device['id'] ?? ''),
        'name' => trim((string)($device['name'] ?? '')),
        'type' => $type,
        'host' => $host,
        'ip' => extractDeviceAddress($host),
        'backend_driver' => $backendDriver,
        'backend' => $backendDriver,
        'business_source' => resolveDeviceBusinessSource($type),
        'api_key' => trim((string)($device['api_key'] ?? '')),
        'api_secret' => trim((string)($device['api_secret'] ?? ($device['secret'] ?? ''))),
        'secret' => trim((string)($device['secret'] ?? ($device['api_secret'] ?? ''))),
        'verify_ssl' => !empty($device['verify_ssl']),
        'port' => isset($device['port']) ? (int)$device['port'] : null,
        'vendor' => isset($device['vendor']) ? (string)$device['vendor'] : null,
        'created_at'          => $device['created_at'] ?? null,
        'updated_at'          => $device['updated_at'] ?? null,
        /* Licence */
        'device_fingerprint'  => trim((string)($device['device_fingerprint'] ?? '')),
        'hardware_info'       => is_array($device['hardware_info'] ?? null) ? $device['hardware_info'] : [],
        'license_key'         => trim((string)($device['license_key']    ?? '')),
        'license_status'      => trim((string)($device['license_status'] ?? '')),
        'license_expiry'      => trim((string)($device['license_expiry'] ?? '')),
        'license_issued'      => trim((string)($device['license_issued'] ?? '')),
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
    $runtimeCache =& deviceStoreRuntimeCache();
    $mtime = is_file($file) ? filemtime($file) : null;

    if (is_array($runtimeCache['store']) && $runtimeCache['mtime'] === $mtime) {
        return $runtimeCache['store'];
    }

    if (!is_file($file)) {
        $runtimeCache['mtime'] = null;
        $runtimeCache['store'] = [
            'active_device_id' => null,
            'devices' => [],
        ];

        return $runtimeCache['store'];
    }

    $payload = json_decode((string)file_get_contents($file), true);
    if (!is_array($payload)) {
        $runtimeCache['mtime'] = $mtime;
        $runtimeCache['store'] = [
            'active_device_id' => null,
            'devices' => [],
        ];

        return $runtimeCache['store'];
    }

    $store = ensureDeviceStore($payload);

    /* Déchiffre les champs sensibles à la lecture */
    $store['devices'] = array_map(static function (array $device): array {
        return decryptFields($device, DEVICE_SENSITIVE_FIELDS);
    }, $store['devices']);

    $runtimeCache['mtime'] = $mtime;
    $runtimeCache['store'] = $store;

    return $runtimeCache['store'];
}

function saveDeviceStore(array $store): void
{
    $normalized = ensureDeviceStore($store);
    $forSave = $normalized;
    $forSave['devices'] = array_map(static function (array $device): array {
        $out = $device;
        unset($out['backend']);
        /* Chiffre les champs sensibles avant écriture */
        $out = encryptFields($out, DEVICE_SENSITIVE_FIELDS);

        return $out;
    }, $normalized['devices']);

    file_put_contents(deviceConfigFilePath(), json_encode($forSave, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);

    $runtimeCache =& deviceStoreRuntimeCache();
    $runtimeCache['mtime'] = filemtime(deviceConfigFilePath()) ?: time();
    $runtimeCache['store'] = $normalized;
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

function findDeviceByAddress(array $store, ?string $address, ?string $type = null): ?array
{
    $needle = trim((string)$address);
    if ($needle === '') {
        return null;
    }

    $needle = strtolower(extractDeviceAddress($needle));
    $expectedType = $type !== null ? normalizeDeviceType($type) : null;

    foreach ($store['devices'] as $device) {
        $deviceType = normalizeDeviceType((string)($device['type'] ?? ''));
        if ($expectedType !== null && $deviceType !== $expectedType) {
            continue;
        }

        $deviceAddress = strtolower(trim((string)($device['ip'] ?? '')));
        if ($deviceAddress === '') {
            $deviceAddress = strtolower(extractDeviceAddress((string)($device['host'] ?? '')));
        }

        if ($deviceAddress !== '' && $deviceAddress === $needle) {
            return $device;
        }
    }

    return null;
}

function getActiveDeviceRecord(array $store): ?array
{
    $storedDeviceId = isset($store['active_device_id']) ? (string)$store['active_device_id'] : null;

    if ($storedDeviceId === null || $storedDeviceId === '') {
        unset($_SESSION['active_device_id']);
        return null;
    }

    $device = findDeviceById($store, $storedDeviceId);
    if (!$device) {
        unset($_SESSION['active_device_id']);
        return null;
    }

    // Cache technique de la session: la source de vérité reste le store.
    $_SESSION['active_device_id'] = $device['id'];

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
        $driver = resolveDeviceRecordBackendDriver($device);

        return [
            'id' => $device['id'],
            'name' => $device['name'] !== '' ? $device['name'] : strtoupper($device['type']),
            'type' => $device['type'],
            'host' => $device['host'],
            'ip' => $device['ip'],
            'backend_driver' => $driver,
            'business_source' => $device['business_source'] ?? resolveDeviceBusinessSource((string)$device['type']),
        ];
    }

    return [
        'id' => null,
        'name' => 'Aucun device',
        'type' => null,
        'host' => '',
        'ip' => '',
        'backend_driver' => null,
        'business_source' => null,
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

function getDeviceContextCapabilities(?array $device): array
{
    if (!$device) {
        return [
            'is_mikrotik' => false,
            'is_opnsense' => false,
            'is_radius' => false,
            'supports_live_traffic' => false,
            'supports_hotspot_logs' => false,
            'supports_radius_sync' => false,
        ];
    }

    $type = normalizeDeviceType((string)($device['type'] ?? ''));

    return [
        'is_mikrotik' => $type === 'mikrotik',
        'is_opnsense' => $type === 'opnsense',
        'is_radius' => $type === 'radius',
        'supports_live_traffic' => in_array($type, ['mikrotik', 'opnsense'], true),
        'supports_hotspot_logs' => $type === 'mikrotik',
        'supports_radius_sync' => in_array($type, ['opnsense', 'radius'], true),
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
            strtoupper((string)($device['type'] ?? 'inconnu')),
            strtoupper(normalizeDeviceType($expectedType))
        ));
    }

    return $device;
}

function canProbeDevice(array $device): bool
{
    $type = normalizeDeviceType((string)($device['type'] ?? ''));

    if ($type === 'opnsense') {
        return !empty($device['host']) && !empty($device['api_key']) && !empty($device['api_secret']);
    }

    if ($type === 'mikrotik') {
        return !empty($device['host']) && !empty($device['api_key']) && !empty($device['api_secret']);
    }

    return false;
}

function connectionStateBackendLabel(string $backendDriver): string
{
    return match ($backendDriver) {
        'opnsense_api' => 'API OPNsense',
        'mikrotik_api' => 'API MikroTik',
        'radius' => 'RADIUS',
        default => $backendDriver !== '' ? $backendDriver : '—',
    };
}

function connectionStateBusinessSourceLabel(string $businessSource): string
{
    return match ($businessSource) {
        'mikrotik_local' => 'Profils locaux (MikroTik)',
        'radius' => 'RADIUS (FreeRADIUS / intégration)',
        default => $businessSource !== '' ? $businessSource : '—',
    };
}

/**
 * État d’affichage « connexion / test » aligné sur canProbeDevice et les champs normalisés.
 *
 * @return array{
 *   supported: bool,
 *   status: string,
 *   label: string,
 *   backend_driver: string|null,
 *   business_source: string|null,
 *   label_backend: string|null,
 *   label_business_source: string|null
 * }
 */
function buildDeviceConnectionState(?array $device): array
{
    if (!$device) {
        return [
            'supported' => false,
            'status' => 'not_configured',
            'label' => 'Aucun device actif',
            'backend_driver' => null,
            'business_source' => null,
            'label_backend' => null,
            'label_business_source' => null,
        ];
    }

    $type = normalizeDeviceType((string)($device['type'] ?? ''));
    $backendDriver = resolveDeviceRecordBackendDriver($device);

    $businessSource = trim((string)($device['business_source'] ?? ''));
    if ($businessSource === '') {
        $businessSource = resolveDeviceBusinessSource($type);
    }

    $labelBackend = connectionStateBackendLabel($backendDriver);
    $labelBusiness = connectionStateBusinessSourceLabel($businessSource);

    $supported = canProbeDevice($device);

    if ($supported) {
        $label = 'Test de connexion disponible (bouton « Tester »).';
    } elseif ($type === 'radius') {
        $label = 'Le test de connexion API ne s’applique pas aux serveurs RADIUS.';
    } else {
        $label = 'Configuration incomplète : renseignez l’hôte et les identifiants API pour activer le test.';
    }

    return [
        'supported' => $supported,
        'status' => $supported ? 'ready' : 'not_supported',
        'label' => $label,
        'backend_driver' => $backendDriver,
        'business_source' => $businessSource,
        'label_backend' => $labelBackend,
        'label_business_source' => $labelBusiness,
    ];
}

function getDeviceDisplayLabel(array $device): string
{
    $name = trim((string)($device['name'] ?? ''));
    $ip = trim((string)($device['ip'] ?? ''));
    $type = strtoupper(trim((string)($device['type'] ?? '')));
    if ($type === '') {
        $type = 'INCONNU';
    }

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
