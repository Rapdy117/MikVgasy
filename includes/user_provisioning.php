<?php

require_once __DIR__ . '/nas_resolver.php';
require_once __DIR__ . '/radius_sync.php';
require_once __DIR__ . '/user_schema.php';
require_once __DIR__ . '/mikrotik_backend.php';

function resolveProvisioningNasContext(PDO $pdo, ?int $nasId = null, ?string $deviceId = null): array
{
    return resolveNasContextFromInputs($pdo, $nasId, $deviceId);
}

function resolveProvisioningProfile(PDO $pdo, array $nasContext, int $profileId = 0, ?string $profileName = null, array $defaults = []): array
{
    $resolvedProfileId = $profileId;
    $resolvedProfileName = trim((string)($profileName ?? ''));
    $profile = null;
    $businessSource = (string)($nasContext['business_source'] ?? '');
    $isMikrotikLocal = $businessSource === 'mikrotik_local';

    if (!$isMikrotikLocal && $resolvedProfileId > 0) {
        $stmt = $pdo->prepare("
            SELECT id, name, rate_limit, session_timeout, idle_timeout, validity_time, data_quota_mb, simultaneous_use
            FROM profiles
            WHERE id = ?
            LIMIT 1
        ");
        $stmt->execute([$resolvedProfileId]);
        $profile = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    if (!$isMikrotikLocal && $profile === null && $resolvedProfileName !== '') {
        $stmt = $pdo->prepare("
            SELECT id, name, rate_limit, session_timeout, idle_timeout, validity_time, data_quota_mb, simultaneous_use
            FROM profiles
            WHERE name = ?
            LIMIT 1
        ");
        $stmt->execute([$resolvedProfileName]);
        $profile = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    if ($isMikrotikLocal) {
        if ($resolvedProfileName === '') {
            throw new RuntimeException('Profil MikroTik introuvable sur le routeur. Nom requis.');
        }

        $api = connectToMikrotikApiForNasContext($nasContext);
        try {
            $routerProfile = findMikrotikProfileByName($api, $resolvedProfileName);
        } finally {
            $api->disconnect();
        }

        if (!$routerProfile) {
            throw new RuntimeException('Profil MikroTik introuvable sur le routeur.');
        }

        $metadata = parseMikrotikOnLoginMetadata((string)($routerProfile['on-login'] ?? ''));
        $validitySeconds = parseRouterosIntervalToSeconds((string)($metadata['validity'] ?? ''));
        $sessionTimeoutSeconds = parseRouterosIntervalToSeconds((string)($routerProfile['session-timeout'] ?? ''));
        $limitBytes = trim((string)($routerProfile['limit-bytes-total'] ?? ''));
        $limitBytesValue = $limitBytes !== '' ? (float)$limitBytes : 0;
        $dataQuotaMb = $limitBytesValue > 0 ? (int)round($limitBytesValue / 1024 / 1024) : 0;

        $profile = [
            'id' => 0,
            'name' => $resolvedProfileName,
            'rate_limit' => trim((string)($routerProfile['rate-limit'] ?? '')) ?: null,
            'session_timeout' => $sessionTimeoutSeconds,
            'idle_timeout' => 0,
            'validity_time' => $validitySeconds,
            'data_quota_mb' => $dataQuotaMb,
            'simultaneous_use' => (int)($routerProfile['shared-users'] ?? 0),
        ];
    }

    $profileName = trim((string)($profile['name'] ?? ''));
    $profileId = (int)($profile['id'] ?? 0);
    $allowMissingId = $isMikrotikLocal;
    $defaults = is_array($defaults) ? $defaults : [];

    if (!$isMikrotikLocal && $profile !== null && $defaults !== []) {
        if (empty($profile['rate_limit']) && !empty($defaults['rate_limit'])) {
            $profile['rate_limit'] = (string)$defaults['rate_limit'];
        }
        if ((int)($profile['session_timeout'] ?? 0) <= 0 && isset($defaults['session_timeout'])) {
            $profile['session_timeout'] = (int)$defaults['session_timeout'];
        }
        if ((int)($profile['idle_timeout'] ?? 0) <= 0 && isset($defaults['idle_timeout'])) {
            $profile['idle_timeout'] = (int)$defaults['idle_timeout'];
        }
        if ((int)($profile['validity_time'] ?? 0) <= 0 && isset($defaults['validity_time'])) {
            $profile['validity_time'] = (int)$defaults['validity_time'];
        }
        if ((int)($profile['data_quota_mb'] ?? 0) <= 0 && isset($defaults['data_quota_mb'])) {
            $profile['data_quota_mb'] = (int)$defaults['data_quota_mb'];
        }
        if ((int)($profile['simultaneous_use'] ?? 0) <= 0 && isset($defaults['simultaneous_use'])) {
            $profile['simultaneous_use'] = (int)$defaults['simultaneous_use'];
        }
    }

    if (!is_array($profile) || $profileName === '') {
        throw new RuntimeException('Profil introuvable');
    }

    if ($profileId <= 0 && !$allowMissingId) {
        throw new RuntimeException('Profil introuvable');
    }

    return [
        'id' => $profileId,
        'name' => $profileName,
        'rate_limit' => trim((string)($profile['rate_limit'] ?? '')) ?: null,
        'session_timeout' => isset($profile['session_timeout']) ? (int)$profile['session_timeout'] : null,
        'idle_timeout' => isset($profile['idle_timeout']) ? (int)$profile['idle_timeout'] : null,
        'validity_time' => isset($profile['validity_time']) ? (int)$profile['validity_time'] : null,
        'data_quota_mb' => isset($profile['data_quota_mb']) ? (int)$profile['data_quota_mb'] : null,
        'simultaneous_use' => max(1, (int)($profile['simultaneous_use'] ?? 1)),
    ];
}

function buildProvisionedUserPayload(array $profile, array $input): array
{
    $hasExplicitSessionTimeout = array_key_exists('session_timeout', $input) && $input['session_timeout'] !== null;
    $hasExplicitDataLimit = array_key_exists('data_limit', $input) && $input['data_limit'] !== null;
    $resolvedSessionTimeout = $hasExplicitSessionTimeout
        ? max(0, (int)$input['session_timeout'])
        : max(0, (int)($profile['session_timeout'] ?? 0));
    $resolvedDataLimitMb = $hasExplicitDataLimit
        ? max(0, (int)$input['data_limit'])
        : max(0, (int)($profile['data_quota_mb'] ?? 0));

    return [
        'username' => trim((string)($input['username'] ?? '')),
        'password' => trim((string)($input['password'] ?? '')),
        'status' => trim((string)($input['status'] ?? 'active')) ?: 'active',
        'session_timeout' => $resolvedSessionTimeout,
        'data_limit' => $resolvedDataLimitMb,
        'idle_timeout' => max(0, (int)($profile['idle_timeout'] ?? 0)),
        'simultaneous_use' => max(1, (int)($profile['simultaneous_use'] ?? 1)),
        'rate_limit' => $profile['rate_limit'] ?? null,
        'expiration_date' => $input['expiration_date'] ?? null,
        'current_credit_time' => $resolvedSessionTimeout,
        // current_credit_data is stored in bytes in users table.
        'current_credit_data' => max(0, $resolvedDataLimitMb) * 1024 * 1024,
    ];
}

function provisionUserWithProfile(PDO $pdo, array $input): array
{
    $username = trim((string)($input['username'] ?? ''));
    $password = trim((string)($input['password'] ?? ''));

    if ($username === '' || $password === '') {
        throw new RuntimeException('Le nom d utilisateur et le mot de passe sont obligatoires.');
    }

    $nasIdIn = isset($input['nas_id']) ? (int)$input['nas_id'] : 0;
    $nasContext = resolveProvisioningNasContext(
        $pdo,
        $nasIdIn > 0 ? $nasIdIn : null,
        isset($input['device_id']) ? (string)$input['device_id'] : null
    );

    $profile = resolveProvisioningProfile(
        $pdo,
        $nasContext,
        isset($input['profile_id']) ? (int)$input['profile_id'] : 0,
        isset($input['profile_name']) ? (string)$input['profile_name'] : null,
        is_array($input['profile_defaults'] ?? null) ? $input['profile_defaults'] : []
    );

    $businessSource = (string)($nasContext['business_source'] ?? '');
    $resolvedNasId = (int)($nasContext['nas_id'] ?? 0);
    if ($resolvedNasId <= 0) {
        throw new RuntimeException('NAS introuvable');
    }

    $userPayload = buildProvisionedUserPayload($profile, $input);
    syncUserToNasBackend($pdo, $userPayload, $profile['name'], $nasContext);

    $userId = null;
    $storageScope = 'backend_only';

    if ($businessSource !== 'mikrotik_local') {
        ensureUsersExtendedSchema($pdo);
        $stmt = $pdo->prepare("
            INSERT INTO users (
                username,
                password,
                nas_id,
                profile_id,
                session_timeout,
                data_limit,
                current_credit_time,
                current_credit_data
            )
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $userPayload['username'],
            $userPayload['password'],
            $resolvedNasId,
            $profile['id'],
            $userPayload['session_timeout'],
            $userPayload['data_limit'],
            $userPayload['current_credit_time'],
            $userPayload['current_credit_data'],
        ]);

        $userId = (int)$pdo->lastInsertId();
        $storageScope = 'local_database';
    }

    return [
        'user_id' => $userId,
        'profile' => $profile,
        'nas_context' => $nasContext,
        'user_payload' => $userPayload,
        'storage_scope' => $storageScope,
    ];
}
