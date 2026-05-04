<?php

/**
 * Helpers pour l import JSON standard v2 (export MikroTik) vers le backend RADIUS / OPNsense.
 * Peut etre charge apres mikrotik_standard_io.php : ne pas redeclarer les helpers deja definis.
 */

if (!function_exists('mikrotikStandardNormalizeImportMode')) {
    function mikrotikStandardNormalizeImportMode(?string $raw): string
    {
        return strtolower(trim((string)$raw)) === 'replace' ? 'replace' : 'skip';
    }
}

function mikrotikStandardNormalizeSensitiveImport(?string $raw): bool
{
    $value = strtolower(trim((string)$raw));
    return in_array($value, ['1', 'true', 'yes', 'on'], true);
}

function mikrotikStandardNormalizeStatus(string $raw): string
{
    $value = strtolower(trim($raw));
    return in_array($value, ['active', 'disabled', 'expired'], true) ? $value : 'active';
}

function mikrotikRadiusImportNormalizeExpirationDate(?string $raw): ?string
{
    $value = trim((string)$raw);
    if ($value === '') {
        return null;
    }

    if (preg_match('/^\d{4}-\d{2}-\d{2}/', $value, $matches)) {
        return $matches[0];
    }

    return null;
}

function mikrotikStandardIsProtectedProfile(string $name): bool
{
    return strtolower(trim($name)) === 'default';
}

function mikrotikStandardIsSensitiveUsername(string $username): bool
{
    return strtolower(trim($username)) === 'admin';
}

function mikrotikStandardFindBackendSpecificProfileRow(array $payload, string $profileName): ?array
{
    $rows = $payload['backend_specific']['mikrotik']['profiles'] ?? [];
    if (!is_array($rows)) {
        return null;
    }

    $needle = strtolower(trim($profileName));
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        if (strtolower(trim((string)($row['name'] ?? ''))) === $needle) {
            return $row;
        }
    }

    return null;
}

function mikrotikStandardParseRadiusImportDocumentV2(array $payload): array
{
    $format = trim((string)($payload['format'] ?? ''));
    $version = (int)($payload['version'] ?? 0);
    $sourceBackend = strtolower(trim((string)($payload['source_backend'] ?? ($payload['backend'] ?? ''))));

    if ($format !== 'radius-manager-standard') {
        throw new RuntimeException('Format standard invalide.');
    }

    if ($version !== 2) {
        throw new RuntimeException('Seul le format standard v2 est accepte par cet import.');
    }

    if ($sourceBackend !== 'mikrotik') {
        throw new RuntimeException('Ce document standard n est pas un export MikroTik.');
    }

    $profiles = $payload['profiles'] ?? [];
    $users = $payload['users'] ?? [];

    if (!is_array($profiles) || !is_array($users)) {
        throw new RuntimeException('Document standard invalide : profils ou utilisateurs manquants.');
    }

    return [
        'profiles' => $profiles,
        'users' => $users,
        'payload' => $payload,
    ];
}

function mikrotikRadiusImportNormalizeProfileRow(array $profileRow, ?array $backendSpecificRow = null): array
{
    $name = trim((string)($profileRow['name'] ?? ''));
    if ($name === '') {
        throw new RuntimeException('Profil sans nom.');
    }

    $sharedUsers = (int)($profileRow['shared_users'] ?? 1);
    $sessionTimeout = array_key_exists('session_timeout', $profileRow) && $profileRow['session_timeout'] !== null
        ? (int)$profileRow['session_timeout']
        : null;
    $validitySeconds = array_key_exists('validity_seconds', $profileRow) && $profileRow['validity_seconds'] !== null
        ? (int)$profileRow['validity_seconds']
        : null;
    $dataQuotaMb = array_key_exists('data_quota_mb', $profileRow) && $profileRow['data_quota_mb'] !== null
        ? (int)$profileRow['data_quota_mb']
        : null;

    return [
        'name' => $name,
        'service_type' => 'hotspot',
        'rate_limit' => trim((string)($profileRow['rate_limit'] ?? '')) ?: null,
        'session_timeout' => $sessionTimeout !== null && $sessionTimeout > 0 ? $sessionTimeout : null,
        'idle_timeout' => null,
        'validity_time' => $validitySeconds !== null && $validitySeconds > 0 ? $validitySeconds : null,
        'data_quota_mb' => $dataQuotaMb !== null && $dataQuotaMb > 0 ? $dataQuotaMb : null,
        'expired_mode' => trim((string)($profileRow['expired_mode'] ?? 'none')) ?: 'none',
        'price' => trim((string)($profileRow['price'] ?? '')) !== '' ? (float)$profileRow['price'] : null,
        'selling_price' => trim((string)($profileRow['selling_price'] ?? '')) !== '' ? (float)$profileRow['selling_price'] : null,
        'lock_user' => 0,
        'parent_queue' => trim((string)($backendSpecificRow['parent_queue'] ?? '')) ?: null,
        'validity_routeros' => trim((string)($backendSpecificRow['validity_routeros'] ?? ($profileRow['validity'] ?? ''))) ?: null,
        'simultaneous_use' => $sharedUsers > 0 ? $sharedUsers : 1,
        'ip_pool' => trim((string)($backendSpecificRow['ip_pool'] ?? '')) ?: null,
        'account_type' => 'standard',
    ];
}

function mikrotikRadiusImportNormalizeUserRow(array $userRow): array
{
    $username = trim((string)($userRow['username'] ?? ''));
    if ($username === '') {
        throw new RuntimeException('Utilisateur sans username.');
    }

    $profileName = trim((string)($userRow['profile'] ?? ''));
    if ($profileName === '') {
        throw new RuntimeException('Utilisateur ' . $username . ' sans profil.');
    }

    $quotaSeconds = array_key_exists('session_timeout', $userRow) && $userRow['session_timeout'] !== null
        ? max(0, (int)$userRow['session_timeout'])
        : null;
    $consumedSeconds = max(0, (int)($userRow['session_total_seconds'] ?? 0));
    $remainingSeconds = $quotaSeconds !== null ? max(0, $quotaSeconds - $consumedSeconds) : null;

    $remainingBytes = array_key_exists('data_limit', $userRow) && $userRow['data_limit'] !== null
        ? max(0, (int)$userRow['data_limit'])
        : null;
    $remainingMegabytes = $remainingBytes !== null ? (int)max(0, round($remainingBytes / 1024 / 1024)) : null;

    return [
        'username' => $username,
        'password' => (string)($userRow['password'] ?? ''),
        'profile' => $profileName,
        'status' => mikrotikStandardNormalizeStatus((string)($userRow['status_effective'] ?? 'active')),
        'expiration_date' => mikrotikRadiusImportNormalizeExpirationDate(
            (string)($userRow['expiration_date'] ?? '')
        ),
        'remaining_seconds' => $remainingSeconds,
        'remaining_megabytes' => $remainingMegabytes,
        'remaining_bytes' => $remainingBytes,
        'imported_session_total_seconds' => $consumedSeconds,
        'imported_data_consumed_bytes' => max(0, (int)($userRow['data_consumed_bytes'] ?? 0)),
    ];
}
