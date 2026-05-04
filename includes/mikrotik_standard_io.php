<?php

require_once __DIR__ . '/mikrotik_backend.php';

function mikrotikStandardNormalizeExpirationDate(?string $raw): string
{
    $value = trim((string)$raw);
    if ($value === '') {
        return '';
    }

    if (preg_match('/^\d{4}-\d{2}-\d{2}/', $value, $matches)) {
        return $matches[0];
    }

    return '';
}

function mikrotikStandardParseIntervalToSeconds($value): ?int
{
    if ($value === null) {
        return null;
    }

    $raw = trim((string)$value);
    if ($raw === '' || $raw === '-' || $raw === '0') {
        return null;
    }

    $lower = strtolower($raw);
    if (in_array($lower, ['illimite', 'illimité', 'unlimited', 'none'], true)) {
        return null;
    }

    if (ctype_digit($raw)) {
        $seconds = (int)$raw;
        return $seconds > 0 ? $seconds : null;
    }

    $seconds = parseRouterosIntervalToSeconds($raw);
    return $seconds > 0 ? $seconds : null;
}

function mikrotikStandardBytesToMegabytes($bytes): ?int
{
    $value = (float)$bytes;
    if ($value <= 0) {
        return null;
    }

    return (int)round($value / 1024 / 1024);
}

function mikrotikStandardNormalizeExpiredMode(?string $raw): string
{
    $value = strtolower(trim((string)$raw));

    return match ($value) {
        'remove', 'rem' => 'remove',
        'notice', 'ntf' => 'notice',
        'remove_record', 'remove & record', 'remc' => 'remove_record',
        'notice_record', 'notice & record', 'ntfc' => 'notice_record',
        default => 'none',
    };
}

function mikrotikStandardNormalizeBoolean($value): bool
{
    if (is_bool($value)) {
        return $value;
    }

    $normalized = strtolower(trim((string)$value));
    return in_array($normalized, ['1', 'true', 'yes', 'enable', 'enabled'], true);
}

function mikrotikStandardNormalizeImportMode(?string $raw): string
{
    return strtolower(trim((string)$raw)) === 'replace' ? 'replace' : 'skip';
}

function mikrotikStandardStatusRaw(array $user): string
{
    return !empty($user['disabled']) ? 'disabled' : 'active';
}

function mikrotikStandardStatusEffective(array $user): string
{
    if (!empty($user['disabled'])) {
        return 'disabled';
    }

    $expirationDate = mikrotikStandardNormalizeExpirationDate((string)($user['comment'] ?? ''));
    if ($expirationDate !== '') {
        $today = new DateTimeImmutable('today', new DateTimeZone('UTC'));
        $expiration = DateTimeImmutable::createFromFormat('Y-m-d', $expirationDate, new DateTimeZone('UTC'));
        if ($expiration instanceof DateTimeImmutable && $expiration < $today) {
            return 'expired';
        }
    }

    return 'active';
}

function mikrotikStandardUserSessionTimeout(array $user): ?int
{
    $limitUptimeRaw = trim((string)($user['limit_uptime'] ?? ''));
    if ($limitUptimeRaw !== '') {
        $seconds = parseRouterosIntervalToSeconds($limitUptimeRaw);
        return $seconds > 0 ? $seconds : null;
    }

    $fallback = (int)($user['profile_session_timeout_seconds'] ?? 0);
    return $fallback > 0 ? $fallback : null;
}

function mikrotikStandardUserRemainingBytes(array $user): ?int
{
    $limitBytes = (float)($user['limit_bytes_total'] ?? 0);
    if ($limitBytes <= 0) {
        return null;
    }

    $consumedBytes = (float)($user['user_bytes_total'] ?? 0);
    return (int)max(0, $limitBytes - $consumedBytes);
}

function buildMikrotikStandardCommonProfileRow(array $profile): array
{
    $sharedUsers = array_key_exists('shared_users', $profile)
        ? (int)$profile['shared_users']
        : (int)($profile['simultaneous_use'] ?? 0);
    $sessionTimeout = (int)($profile['session_timeout'] ?? 0);
    $validitySeconds = (int)($profile['validity_time'] ?? 0);
    $dataQuotaMb = isset($profile['data_quota_mb']) && (int)$profile['data_quota_mb'] > 0
        ? (int)$profile['data_quota_mb']
        : null;

    $priceOut = '';
    if (array_key_exists('price', $profile) && $profile['price'] !== null && $profile['price'] !== '') {
        $priceOut = trim((string)$profile['price']);
    }
    $sellingOut = '';
    if (array_key_exists('selling_price', $profile) && $profile['selling_price'] !== null && $profile['selling_price'] !== '') {
        $sellingOut = trim((string)$profile['selling_price']);
    }

    return [
        'name' => trim((string)($profile['name'] ?? '')),
        'rate_limit' => trim((string)($profile['rate_limit'] ?? '')),
        'shared_users' => $sharedUsers > 0 ? $sharedUsers : 0,
        'session_timeout' => $sessionTimeout > 0 ? $sessionTimeout : null,
        'validity' => trim((string)($profile['validity_routeros'] ?? ($profile['validity'] ?? ''))),
        'validity_seconds' => $validitySeconds > 0 ? $validitySeconds : null,
        'data_quota_mb' => $dataQuotaMb,
        'expired_mode' => mikrotikStandardNormalizeExpiredMode((string)($profile['expired_mode'] ?? 'none')),
        'price' => $priceOut,
        'selling_price' => $sellingOut,
    ];
}

function buildMikrotikStandardRawProfileRow(array $profile): array
{
    return [
        'name' => trim((string)($profile['name'] ?? '')),
        'validity_routeros' => trim((string)($profile['validity_routeros'] ?? ($profile['validity'] ?? ''))),
        'ip_pool' => trim((string)($profile['ip_pool'] ?? '')),
        'parent_queue' => trim((string)($profile['parent_queue'] ?? '')),
    ];
}

function buildMikrotikStandardCommonUserRow(array $user): array
{
    return [
        'username' => trim((string)($user['username'] ?? '')),
        'password' => (string)($user['password'] ?? ''),
        'profile' => trim((string)($user['profile'] ?? '')),
        'status_effective' => mikrotikStandardStatusEffective($user),
        'expiration_date' => mikrotikStandardNormalizeExpirationDate((string)($user['comment'] ?? '')),
        'session_timeout' => mikrotikStandardUserSessionTimeout($user),
        'data_limit' => mikrotikStandardUserRemainingBytes($user),
        'session_total_seconds' => max(0, (int)($user['user_session_time_seconds'] ?? 0)),
        'data_consumed_bytes' => max(0, (int)round((float)($user['user_bytes_total'] ?? 0))),
    ];
}

function buildMikrotikStandardRawUserRow(array $user): array
{
    return [
        'username' => trim((string)($user['username'] ?? '')),
        'status_raw' => mikrotikStandardStatusRaw($user),
        'disabled_raw' => !empty($user['disabled']),
        'limit_uptime_raw' => trim((string)($user['limit_uptime'] ?? '')),
        'limit_bytes_total_raw' => trim((string)($user['limit_bytes_total'] ?? '')),
        'comment_raw' => trim((string)($user['comment'] ?? '')),
    ];
}

function buildMikrotikStandardExportDocument(array $device): array
{
    $profiles = loadMikrotikHotspotProfilesCached($device, 1);
    $users = getMikrotikHotspotUsers(0, $device);

    $commonProfiles = [];
    $rawProfiles = [];
    foreach ($profiles as $profile) {
        if (!is_array($profile)) {
            continue;
        }
        $name = trim((string)($profile['name'] ?? ''));
        if ($name === '') {
            continue;
        }
        $commonProfiles[] = buildMikrotikStandardCommonProfileRow($profile);
        $rawProfiles[] = buildMikrotikStandardRawProfileRow($profile);
    }

    $commonUsers = [];
    $rawUsers = [];
    foreach ($users as $user) {
        if (!is_array($user)) {
            continue;
        }
        $username = trim((string)($user['username'] ?? ''));
        if ($username === '') {
            continue;
        }
        $commonUsers[] = buildMikrotikStandardCommonUserRow($user);
        $rawUsers[] = buildMikrotikStandardRawUserRow($user);
    }

    usort($commonProfiles, static fn(array $a, array $b): int => strcasecmp($a['name'], $b['name']));
    usort($rawProfiles, static fn(array $a, array $b): int => strcasecmp($a['name'], $b['name']));
    usort($commonUsers, static fn(array $a, array $b): int => strcasecmp($a['username'], $b['username']));
    usort($rawUsers, static fn(array $a, array $b): int => strcasecmp($a['username'], $b['username']));

    return [
        'format' => 'radius-manager-standard',
        'version' => 2,
        'source_backend' => 'mikrotik',
        'exported_at' => gmdate('Y-m-d\TH:i:s\Z'),
        'profiles' => $commonProfiles,
        'users' => $commonUsers,
        'backend_specific' => [
            'mikrotik' => [
                'profiles' => $rawProfiles,
                'users' => $rawUsers,
            ],
        ],
    ];
}

/**
 * @return array{profiles: array, users: array, document_version: int}
 */
function mikrotikStandardParseImportDocument(array $payload): array
{
    $format = trim((string)($payload['format'] ?? ''));
    if ($format !== 'radius-manager-standard') {
        throw new RuntimeException('Format standard invalide.');
    }

    $version = (int)($payload['version'] ?? 0);
    if (!in_array($version, [1, 2], true)) {
        throw new RuntimeException('Version de document non supportee.');
    }

    $sourceBackend = strtolower(trim((string)($payload['source_backend'] ?? '')));
    $legacyBackend = strtolower(trim((string)($payload['backend'] ?? '')));
    if ($sourceBackend !== 'mikrotik' && $legacyBackend !== 'mikrotik') {
        throw new RuntimeException('Ce document standard n est pas un export MikroTik.');
    }

    $profiles = $payload['profiles'] ?? [];
    $users = $payload['users'] ?? [];

    if (!is_array($profiles) || !is_array($users)) {
        throw new RuntimeException('Document standard invalide : profils ou utilisateurs manquants.');
    }

    if ($version === 1) {
        return [
            'profiles' => $profiles,
            'users' => $users,
            'document_version' => 1,
        ];
    }

    $mik = $payload['backend_specific']['mikrotik'] ?? null;
    if (!is_array($mik)) {
        throw new RuntimeException('Document v2 invalide : backend_specific.mikrotik manquant.');
    }

    $rawProfiles = is_array($mik['profiles'] ?? null) ? $mik['profiles'] : [];
    $rawUsers = is_array($mik['users'] ?? null) ? $mik['users'] : [];

    $rawProfilesByName = [];
    foreach ($rawProfiles as $rp) {
        if (!is_array($rp)) {
            continue;
        }
        $k = strtolower(trim((string)($rp['name'] ?? '')));
        if ($k !== '') {
            $rawProfilesByName[$k] = $rp;
        }
    }

    $mergedProfiles = [];
    foreach ($profiles as $cp) {
        if (!is_array($cp)) {
            continue;
        }
        $k = strtolower(trim((string)($cp['name'] ?? '')));
        $mergedProfiles[] = $k !== '' && isset($rawProfilesByName[$k])
            ? array_merge($cp, $rawProfilesByName[$k])
            : $cp;
    }

    $rawUsersByName = [];
    foreach ($rawUsers as $ru) {
        if (!is_array($ru)) {
            continue;
        }
        $k = strtolower(trim((string)($ru['username'] ?? '')));
        if ($k !== '') {
            $rawUsersByName[$k] = $ru;
        }
    }

    $mergedUsers = [];
    foreach ($users as $cu) {
        if (!is_array($cu)) {
            continue;
        }
        $k = strtolower(trim((string)($cu['username'] ?? '')));
        $mergedUsers[] = $k !== '' && isset($rawUsersByName[$k])
            ? array_merge($cu, $rawUsersByName[$k])
            : $cu;
    }

    return [
        'profiles' => $mergedProfiles,
        'users' => $mergedUsers,
        'document_version' => 2,
    ];
}

function mikrotikStandardNormalizeProfileImportRow(array $row): array
{
    $name = trim((string)($row['name'] ?? ''));
    if ($name === '') {
        throw new RuntimeException('Nom de profil manquant.');
    }

    $sharedUsers = array_key_exists('simultaneous_use', $row)
        ? (int)$row['simultaneous_use']
        : (array_key_exists('shared_users', $row) ? (int)$row['shared_users'] : 1);
    $sharedUsers = max(1, $sharedUsers);

    $sessionTimeout = mikrotikStandardParseIntervalToSeconds($row['session_timeout'] ?? null) ?? 0;
    $validitySeconds = mikrotikStandardParseIntervalToSeconds($row['validity_seconds'] ?? ($row['validity_time'] ?? null));
    $validityRouteros = trim((string)($row['validity_routeros'] ?? ($row['validity'] ?? '')));
    if ($validityRouteros === '' && $validitySeconds !== null && $validitySeconds > 0) {
        $validityRouteros = mikrotikIntervalFromSeconds($validitySeconds);
    }

    $expiredMode = mikrotikStandardNormalizeExpiredMode($row['expired_mode'] ?? 'none');
    if ($expiredMode !== 'none' && $validityRouteros === '') {
        throw new RuntimeException('Validite MikroTik manquante pour le profil "' . $name . '".');
    }

    return [
        'name' => $name,
        'rate_limit' => trim((string)($row['rate_limit'] ?? '')),
        'simultaneous_use' => $sharedUsers,
        'session_timeout' => $sessionTimeout,
        'validity_time' => $validitySeconds,
        'validity_routeros' => $validityRouteros,
        'data_quota_mb' => max(0, (int)($row['data_quota_mb'] ?? 0)),
        'price' => trim((string)($row['price'] ?? '')),
        'selling_price' => trim((string)($row['selling_price'] ?? '')),
        'ip_pool' => trim((string)($row['ip_pool'] ?? '')),
        'parent_queue' => trim((string)($row['parent_queue'] ?? '')),
        'expired_mode' => $expiredMode,
        'lock_user' => mikrotikStandardNormalizeBoolean($row['lock_user'] ?? false) ? 1 : 0,
    ];
}

function mikrotikStandardNormalizeUserImportRow(array $row): array
{
    $username = trim((string)($row['username'] ?? ''));
    if ($username === '') {
        throw new RuntimeException('Nom utilisateur manquant.');
    }

    $profileName = trim((string)($row['profile'] ?? ''));
    if ($profileName === '') {
        throw new RuntimeException('Profil utilisateur manquant pour "' . $username . '".');
    }

    $rawBytes = trim((string)($row['limit_bytes_total_raw'] ?? ''));
    if ($rawBytes !== '' && is_numeric($rawBytes) && (float)$rawBytes > 0) {
        $dataLimit = max(0, (int)round((float)$rawBytes / 1024 / 1024));
    } else {
        $dataLimitRaw = $row['data_limit'] ?? null;
        $dataLimit = $dataLimitRaw === null || $dataLimitRaw === ''
            ? 0
            : max(0, (int)$dataLimitRaw);
    }

    $statusEffective = strtolower(trim((string)($row['status_effective'] ?? '')));
    $statusRaw = strtolower(trim((string)($row['status'] ?? ($row['status_raw'] ?? 'active'))));
    if ($statusRaw === 'disabled' || $statusEffective === 'disabled') {
        $status = 'disabled';
    } else {
        $status = 'active';
    }

    $sessionTimeout = mikrotikStandardParseIntervalToSeconds($row['session_timeout'] ?? null);
    if ($sessionTimeout === null) {
        $sessionTimeout = mikrotikStandardParseIntervalToSeconds($row['limit_uptime_raw'] ?? ($row['limit_uptime'] ?? null));
    }
    $sessionTimeout = $sessionTimeout ?? 0;

    $expirationDate = mikrotikStandardNormalizeExpirationDate((string)($row['expiration_date'] ?? ($row['comment_raw'] ?? ($row['comment'] ?? ''))));

    return [
        'username' => $username,
        'password' => (string)($row['password'] ?? ''),
        'profile' => $profileName,
        'status' => $status,
        'session_timeout' => $sessionTimeout,
        'data_limit' => $dataLimit,
        'expiration_date' => $expirationDate,
    ];
}

function mikrotikStandardIsImplicitTrialUsername(string $username): bool
{
    return strtolower(trim($username)) === 'default-trial';
}
