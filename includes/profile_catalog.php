<?php

require_once __DIR__ . '/device_manager.php';
require_once __DIR__ . '/mikrotik_backend.php';

function profileCatalogNormalizeLocalRow(array $profile): array
{
    return [
        'id' => (int)($profile['id'] ?? 0),
        'name' => (string)($profile['name'] ?? ''),
        'router_id' => '',
        'select_value' => (string)((int)($profile['id'] ?? 0)),
        'service_type' => (string)($profile['service_type'] ?? ''),
        'rate_limit' => (string)($profile['rate_limit'] ?? ''),
        'session_timeout' => isset($profile['session_timeout']) ? (int)$profile['session_timeout'] : 0,
        'idle_timeout' => isset($profile['idle_timeout']) ? (int)$profile['idle_timeout'] : 0,
        'validity_time' => isset($profile['validity_time']) ? (int)$profile['validity_time'] : 0,
        'validity' => '',
        'data_quota_mb' => isset($profile['data_quota_mb']) ? (int)$profile['data_quota_mb'] : 0,
        'simultaneous_use' => isset($profile['simultaneous_use']) ? (int)$profile['simultaneous_use'] : 0,
        'ip_pool' => (string)($profile['ip_pool'] ?? ''),
        'expired_mode' => (string)($profile['expired_mode'] ?? ''),
        'grace_period' => isset($profile['grace_period']) ? (int)$profile['grace_period'] : null,
        'price' => isset($profile['price']) && $profile['price'] !== null ? (string)$profile['price'] : '',
        'selling_price' => isset($profile['selling_price']) && $profile['selling_price'] !== null ? (string)$profile['selling_price'] : '',
        'lock_user' => isset($profile['lock_user']) ? (int)$profile['lock_user'] : null,
        'parent_queue' => (string)($profile['parent_queue'] ?? ''),
        'account_type' => (string)($profile['account_type'] ?? ''),
    ];
}

function profileCatalogNormalizeMikrotikRow(array $profile): array
{
    $routerId = (string)($profile['id'] ?? '');
    $name = (string)($profile['name'] ?? '');
    $selectValue = $routerId !== '' ? $routerId : $name;

    return [
        'id' => 0,
        'name' => $name,
        'router_id' => $routerId,
        'select_value' => $selectValue,
        'service_type' => 'hotspot',
        'rate_limit' => (string)($profile['rate_limit'] ?? ''),
        'session_timeout' => isset($profile['session_timeout']) ? (int)$profile['session_timeout'] : 0,
        'idle_timeout' => 0,
        'validity_time' => isset($profile['validity_time']) ? (int)$profile['validity_time'] : 0,
        'validity' => (string)($profile['validity'] ?? ''),
        'data_quota_mb' => isset($profile['data_quota_mb']) ? (int)$profile['data_quota_mb'] : 0,
        'simultaneous_use' => isset($profile['simultaneous_use']) ? (int)$profile['simultaneous_use'] : 0,
        'ip_pool' => (string)($profile['ip_pool'] ?? ''),
        'expired_mode' => (string)($profile['expired_mode'] ?? ''),
        'grace_period' => null,
        'price' => isset($profile['price']) && $profile['price'] !== null ? (string)$profile['price'] : '',
        'selling_price' => isset($profile['selling_price']) && $profile['selling_price'] !== null ? (string)$profile['selling_price'] : '',
        'lock_user' => isset($profile['lock_user']) ? (int)$profile['lock_user'] : null,
        'parent_queue' => (string)($profile['parent_queue'] ?? ''),
        'account_type' => 'hotspot',
    ];
}

function profileCatalogSortProfiles(array $profiles, string $sortMode): array
{
    if ($sortMode === 'name_asc') {
        usort($profiles, static fn(array $a, array $b): int => strcasecmp((string)($a['name'] ?? ''), (string)($b['name'] ?? '')));
    }

    if ($sortMode === 'id_desc') {
        usort($profiles, static fn(array $a, array $b): int => ((int)($b['id'] ?? 0)) <=> ((int)($a['id'] ?? 0)));
    }

    return $profiles;
}

function loadProfileCatalogForDevice(PDO $pdo, array $device, array $options = []): array
{
    $deviceType = normalizeDeviceType((string)($device['type'] ?? ''));
    $businessSource = resolveDeviceBusinessSource($deviceType);
    $backendDriver = resolveDeviceBackend($deviceType);
    $sortMode = (string)($options['sort'] ?? 'name_asc');

    if ($businessSource === 'mikrotik_local') {
        $profiles = [];
        foreach (loadMikrotikHotspotProfilesCached($device, 60) as $routerProfile) {
            $name = trim((string)($routerProfile['name'] ?? ''));
            if ($name === '') {
                continue;
            }

            $profiles[] = profileCatalogNormalizeMikrotikRow($routerProfile);
        }

        return [
            'source' => 'mikrotik',
            'business_source' => $businessSource,
            'backend_driver' => $backendDriver,
            'device_type' => $deviceType,
            'profiles' => profileCatalogSortProfiles($profiles, $sortMode),
        ];
    }

    $stmt = $pdo->query("
        SELECT
            id,
            name,
            service_type,
            rate_limit,
            session_timeout,
            idle_timeout,
            validity_time,
            data_quota_mb,
            simultaneous_use,
            ip_pool,
            expired_mode,
            grace_period,
            price,
            selling_price,
            lock_user,
            parent_queue,
            account_type
        FROM profiles
    ");

    $profiles = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $profile) {
        $profiles[] = profileCatalogNormalizeLocalRow($profile);
    }

    return [
        'source' => 'local',
        'business_source' => $businessSource,
        'backend_driver' => $backendDriver,
        'device_type' => $deviceType,
        'profiles' => profileCatalogSortProfiles($profiles, $sortMode),
    ];
}

function findProfileCatalogEntryByName(array $catalog, string $profileName): ?array
{
    $needle = trim($profileName);
    if ($needle === '') {
        return null;
    }

    foreach (($catalog['profiles'] ?? []) as $profile) {
        if ((string)($profile['name'] ?? '') === $needle) {
            return $profile;
        }
    }

    return null;
}
