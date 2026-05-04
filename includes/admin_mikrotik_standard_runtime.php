<?php

require_once __DIR__ . '/mikrotik_standard_io.php';

function adminMikrotikStandardContextFromDevice(array $device): array
{
    if (normalizeDeviceType((string)($device['type'] ?? '')) !== 'mikrotik') {
        throw new RuntimeException('Le device cible n est pas de type MikroTik.');
    }

    $deviceId = trim((string)($device['id'] ?? ''));
    if ($deviceId === '') {
        throw new RuntimeException('ID du device MikroTik manquant.');
    }

    $host = trim((string)($device['host'] ?? ''));
    $address = extractDeviceAddress($host);

    return [
        'nas_id' => 0,
        'nasname' => $address !== '' ? $address : $host,
        'shortname' => trim((string)($device['name'] ?? $deviceId)),
        'nas_type' => 'mikrotik',
        'business_source' => 'mikrotik_local',
        'backend_driver' => 'mikrotik_api',
        'device_type' => 'mikrotik',
        'device' => $device,
    ];
}

function adminMikrotikStandardConnectDevice(array $device): RouterosAPI
{
    adminMikrotikStandardContextFromDevice($device);

    return connectToMikrotikApiByDevice($device);
}

function adminMikrotikStandardReadIdentity(array $device): ?string
{
    $api = adminMikrotikStandardConnectDevice($device);

    try {
        $identityRows = $api->comm('/system/identity/print');
        if (!is_array($identityRows)) {
            throw new RuntimeException('Reponse MikroTik invalide.');
        }

        $firstRow = $identityRows[0] ?? [];
        $routerIdentity = trim((string)($firstRow['name'] ?? ''));

        return $routerIdentity !== '' ? $routerIdentity : null;
    } finally {
        $api->disconnect();
    }
}

function adminMikrotikStandardExtractAddressPoolNames(array $rows): array
{
    $addressPools = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $name = trim((string)($row['name'] ?? ''));
        if ($name !== '') {
            $addressPools[strtolower($name)] = $name;
        }
    }

    natcasesort($addressPools);
    return array_values($addressPools);
}

function adminMikrotikStandardReadTargetInfo(array $device): array
{
    $api = adminMikrotikStandardConnectDevice($device);

    try {
        $identityRows = $api->comm('/system/identity/print');
        $firstRow = is_array($identityRows) ? ($identityRows[0] ?? []) : [];
        $routerIdentity = is_array($firstRow) ? trim((string)($firstRow['name'] ?? '')) : '';

        $poolRows = $api->comm('/ip/pool/print');
        $addressPools = adminMikrotikStandardExtractAddressPoolNames(is_array($poolRows) ? $poolRows : []);

        return [
            'router_identity' => $routerIdentity !== '' ? $routerIdentity : null,
            'address_pools' => $addressPools,
        ];
    } finally {
        $api->disconnect();
    }
}

function adminMikrotikStandardFindBackendSpecificUserRow(array $payload, string $username): ?array
{
    $rows = $payload['backend_specific']['mikrotik']['users'] ?? [];
    if (!is_array($rows)) {
        return null;
    }

    $needle = strtolower(trim($username));
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        if (strtolower(trim((string)($row['username'] ?? ''))) === $needle) {
            return $row;
        }
    }

    return null;
}

function adminMikrotikStandardBuildProfilePayload(array $profileRow, ?array $backendSpecificRow = null): array
{
    $name = trim((string)($profileRow['name'] ?? ''));
    if ($name === '') {
        throw new RuntimeException('Profil sans nom.');
    }

    $bs = $backendSpecificRow ?? [];

    return [
        'name' => $name,
        'rate_limit' => trim((string)($profileRow['rate_limit'] ?? '')) ?: null,
        'simultaneous_use' => max(1, (int)($profileRow['shared_users'] ?? 1)),
        'session_timeout' => $profileRow['session_timeout'] !== null ? max(0, (int)$profileRow['session_timeout']) : 0,
        'data_quota_mb' => $profileRow['data_quota_mb'] !== null ? max(0, (int)$profileRow['data_quota_mb']) : 0,
        'expired_mode' => trim((string)($profileRow['expired_mode'] ?? 'none')) ?: 'none',
        'price' => trim((string)($profileRow['price'] ?? '')),
        'selling_price' => trim((string)($profileRow['selling_price'] ?? '')),
        'lock_user' => 0,
        'parent_queue' => trim((string)($bs['parent_queue'] ?? '')),
        'validity_routeros' => trim((string)($bs['validity_routeros'] ?? ($profileRow['validity'] ?? ''))),
        'ip_pool' => trim((string)($bs['ip_pool'] ?? '')),
    ];
}

function adminMikrotikStandardBuildUserPayload(array $userRow, ?array $backendSpecificRow = null): array
{
    $username = trim((string)($userRow['username'] ?? ''));
    if ($username === '') {
        throw new RuntimeException('Utilisateur sans username.');
    }

    $profile = trim((string)($userRow['profile'] ?? ''));
    if ($profile === '') {
        throw new RuntimeException('Utilisateur ' . $username . ' sans profil.');
    }

    $bs = $backendSpecificRow ?? [];

    $expirationDate = mikrotikStandardNormalizeExpirationDate((string)($userRow['expiration_date'] ?? ''));
    $commentRaw = trim((string)($bs['comment_raw'] ?? ''));
    if ($commentRaw === '' && $expirationDate !== '') {
        $commentRaw = $expirationDate;
    }

    $limitUptimeRaw = trim((string)($bs['limit_uptime_raw'] ?? ''));
    if ($limitUptimeRaw === '' && $userRow['session_timeout'] !== null && (int)$userRow['session_timeout'] > 0) {
        $limitUptimeRaw = mikrotikIntervalFromSeconds((int)$userRow['session_timeout']);
    }

    $limitBytesTotalRaw = trim((string)($bs['limit_bytes_total_raw'] ?? ''));
    if ($limitBytesTotalRaw === '') {
        $remainingBytes = $userRow['data_limit'] !== null ? max(0, (int)$userRow['data_limit']) : 0;
        $consumedBytes = max(0, (int)($userRow['data_consumed_bytes'] ?? 0));
        $reconstructedTotalBytes = $remainingBytes + $consumedBytes;
        if ($reconstructedTotalBytes > 0) {
            $limitBytesTotalRaw = (string)$reconstructedTotalBytes;
        }
    }

    $statusRaw = trim((string)($bs['status_raw'] ?? ''));
    $status = $statusRaw !== '' ? mikrotikStandardNormalizeStatus($statusRaw) : mikrotikStandardNormalizeStatus((string)($userRow['status_effective'] ?? 'active'));

    return [
        'username' => $username,
        'password' => (string)($userRow['password'] ?? ''),
        'profile' => $profile,
        'status' => $status,
        'disabled_raw' => $status === 'disabled',
        'comment_raw' => $commentRaw,
        'limit_uptime_raw' => $limitUptimeRaw,
        'limit_bytes_total_raw' => $limitBytesTotalRaw,
        'imported_session_total_seconds' => max(0, (int)($userRow['session_total_seconds'] ?? 0)),
        'imported_data_consumed_bytes' => max(0, (int)($userRow['data_consumed_bytes'] ?? 0)),
    ];
}

function adminMikrotikStandardUpsertProfile(RouterosAPI $api, array $profile, string $mode, array $device): string
{
    $profileName = trim((string)($profile['name'] ?? ''));
    if ($profileName === '') {
        throw new RuntimeException('Profil sans nom.');
    }

    $lookupName = trim((string)($profile['old_name'] ?? $profileName));
    $existing = findMikrotikProfileByName($api, $lookupName);
    if (!$existing && $lookupName !== $profileName) {
        $existing = findMikrotikProfileByName($api, $profileName);
    }

    if ($existing && $mode === 'skip') {
        return 'skipped';
    }

    $payload = [
        'name' => $profileName,
        'shared-users' => (string)max(1, (int)($profile['simultaneous_use'] ?? 1)),
        'status-autorefresh' => '1m',
        'on-login' => mikrotikBuildProfileOnLogin($profile),
    ];

    $rateLimit = trim((string)($profile['rate_limit'] ?? ''));
    if ($rateLimit !== '') {
        $payload['rate-limit'] = $rateLimit;
    }

    $addressPool = trim((string)($profile['ip_pool'] ?? $profile['address_pool'] ?? ''));
    if ($addressPool !== '') {
        $payload['address-pool'] = $addressPool;
    }

    $parentQueue = trim((string)($profile['parent_queue'] ?? ''));
    if ($parentQueue !== '') {
        $payload['parent-queue'] = $parentQueue;
    }

    $sessionTimeoutSeconds = max(0, (int)($profile['session_timeout'] ?? 0));
    if ($sessionTimeoutSeconds > 0) {
        $payload['session-timeout'] = mikrotikIntervalFromSeconds($sessionTimeoutSeconds);
    }

    if ($existing && isset($existing['.id'])) {
        $payload['.id'] = (string)$existing['.id'];
        $response = $api->comm('/ip/hotspot/user/profile/set', $payload);
        mikrotikAssertNoTrap($response, 'Le routeur MikroTik a refuse la mise a jour du profil importe.');
        invalidateMikrotikHotspotProfilesCache($device);
        ensureMikrotikProfileScheduler($api, $profile, $lookupName);
        adminMikrotikStandardAssertProfileExists($api, $profileName);

        return 'updated';
    }

    $response = $api->comm('/ip/hotspot/user/profile/add', $payload);
    mikrotikAssertNoTrap($response, 'Le routeur MikroTik a refuse la creation du profil importe.');
    invalidateMikrotikHotspotProfilesCache($device);
    ensureMikrotikProfileScheduler($api, $profile, $lookupName);
    adminMikrotikStandardAssertProfileExists($api, $profileName);

    return 'created';
}

function adminMikrotikStandardAssertProfileExists(RouterosAPI $api, string $profileName): void
{
    $profile = findMikrotikProfileByName($api, $profileName);
    if (!is_array($profile) || trim((string)($profile['.id'] ?? '')) === '') {
        throw new RuntimeException('Profil MikroTik non confirme apres ecriture: ' . $profileName);
    }
}

function adminMikrotikStandardAssertUserExists(RouterosAPI $api, string $username): void
{
    $user = findMikrotikUserByName($api, $username);
    if (!is_array($user) || trim((string)($user['.id'] ?? '')) === '') {
        throw new RuntimeException('Utilisateur MikroTik non confirme apres ecriture: ' . $username);
    }
}

function adminMikrotikStandardUpsertUser(RouterosAPI $api, array $user, string $mode): string
{
    $existing = findMikrotikUserByName($api, $user['username']);
    if ($existing && $mode === 'skip') {
        return 'skipped';
    }

    $payload = [
        'name' => $user['username'],
        'profile' => $user['profile'],
        'disabled' => $user['disabled_raw'] ? 'yes' : 'no',
        'comment' => $user['comment_raw'],
    ];

    if ($user['password'] !== '') {
        $payload['password'] = $user['password'];
    }

    if ($user['limit_uptime_raw'] !== '') {
        $payload['limit-uptime'] = $user['limit_uptime_raw'];
    }

    if ($user['limit_bytes_total_raw'] !== '') {
        $payload['limit-bytes-total'] = $user['limit_bytes_total_raw'];
    }

    if ($existing && isset($existing['.id'])) {
        $payload['.id'] = (string)$existing['.id'];
        $response = $api->comm('/ip/hotspot/user/set', $payload);
        mikrotikAssertNoTrap($response, 'Le routeur MikroTik a refuse la mise a jour de l utilisateur importe.');
        adminMikrotikStandardAssertUserExists($api, (string)$user['username']);

        return 'updated';
    }

    $response = $api->comm('/ip/hotspot/user/add', $payload);
    mikrotikAssertNoTrap($response, 'Le routeur MikroTik a refuse la creation de l utilisateur importe.');
    adminMikrotikStandardAssertUserExists($api, (string)$user['username']);

    return 'created';
}

function adminMikrotikStandardNoWriteMessage(array $profileSummary, array $userSummary): string
{
    $profileErrors = count($profileSummary['errors'] ?? []);
    $userErrors = count($userSummary['errors'] ?? []);
    $firstError = $profileSummary['errors'][0] ?? $userSummary['errors'][0] ?? '';

    $message = 'Aucun element ecrit sur le routeur MikroTik cible.'
        . ' Profils ignores: ' . (int)($profileSummary['skipped'] ?? 0)
        . ', proteges: ' . (int)($profileSummary['protected'] ?? 0)
        . ', erreurs: ' . $profileErrors
        . '. Utilisateurs ignores: ' . (int)($userSummary['skipped'] ?? 0)
        . ', sensibles ignores: ' . (int)($userSummary['sensitive_skipped'] ?? 0)
        . ', invalides ignores: ' . (int)($userSummary['invalid_skipped'] ?? 0)
        . ', erreurs: ' . $userErrors
        . '.';

    if ($firstError !== '') {
        $message .= ' Premiere erreur: ' . $firstError;
    }

    return $message;
}
