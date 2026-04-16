<?php

require_once __DIR__ . '/device_manager.php';
require_once __DIR__ . '/voucher_ticket_helpers.php';
require_once __DIR__ . '/mikrotik_backend.php';

function generateVoucherBatchCode(string $prefix, int $length): string
{
    $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $token = '';
    $max = strlen($chars) - 1;

    for ($i = 0; $i < $length; $i++) {
        $token .= $chars[random_int(0, $max)];
    }

    $prefix = strtoupper(trim($prefix));

    return $prefix !== '' ? $prefix . '-' . $token : $token;
}

function generateVoucherBatchSecret(int $length): string
{
    $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789abcdefghijkmnpqrstuvwxyz';
    $token = '';
    $max = strlen($chars) - 1;

    for ($i = 0; $i < $length; $i++) {
        $token .= $chars[random_int(0, $max)];
    }

    return $token;
}

function findVoucherProfileById(PDO $pdo, int $profileId): ?array
{
    if ($profileId <= 0) {
        return null;
    }

    $profileStmt = $pdo->prepare('
        SELECT id, name, rate_limit, session_timeout, idle_timeout, validity_time, data_quota_mb, simultaneous_use, price, selling_price
        FROM profiles
        WHERE id = ?
        LIMIT 1
    ');
    $profileStmt->execute([$profileId]);

    $profile = $profileStmt->fetch(PDO::FETCH_ASSOC);

    return is_array($profile) ? $profile : null;
}

function findVoucherProfileByName(PDO $pdo, string $profileName): ?array
{
    $profileName = trim($profileName);
    if ($profileName === '') {
        return null;
    }

    $profileStmt = $pdo->prepare('
        SELECT id, name, rate_limit, session_timeout, idle_timeout, validity_time, data_quota_mb, simultaneous_use, price, selling_price
        FROM profiles
        WHERE name = ?
        LIMIT 1
    ');
    $profileStmt->execute([$profileName]);

    $profile = $profileStmt->fetch(PDO::FETCH_ASSOC);

    return is_array($profile) ? $profile : null;
}

function createVoucherLocalProfileForMikrotik(PDO $pdo, string $profileName): array
{
    $profileName = trim($profileName);
    if ($profileName === '') {
        throw new RuntimeException('Profil introuvable');
    }

    $insertProfile = $pdo->prepare('INSERT INTO profiles (name, service_type, simultaneous_use) VALUES (?, ?, ?)');
    $insertProfile->execute([$profileName, 'hotspot', 1]);

    return [
        'id' => (int)$pdo->lastInsertId(),
        'name' => $profileName,
        'rate_limit' => null,
        'session_timeout' => null,
        'idle_timeout' => null,
        'validity_time' => null,
        'data_quota_mb' => null,
        'simultaneous_use' => 1,
        'price' => null,
        'selling_price' => null,
    ];
}

function resolveVoucherBatchProfile(PDO $pdo, array $deviceStore, int $profileId, string $profileName, string $deviceId): ?array
{
    $profileName = trim($profileName);
    $device = null;
    if (trim($deviceId) !== '') {
        $device = findDeviceById($deviceStore, $deviceId);
    } else {
        $device = getActiveDeviceRecord($deviceStore);
    }

    if (!is_array($device) || trim((string)($device['type'] ?? '')) === '') {
        throw new RuntimeException('Device requis pour le lot de vouchers.');
    }

    $deviceType = normalizeDeviceType((string)$device['type']);

    // Source d'autorité par type de NAS : pour MikroTik, lire directement le profil routeur.
    if ($deviceType === 'mikrotik' && is_array($device)) {
        $targetProfileName = $profileName;
        if ($targetProfileName === '' && $profileId > 0) {
            $localProfile = findVoucherProfileById($pdo, $profileId);
            $targetProfileName = trim((string)($localProfile['name'] ?? ''));
        }

        if ($targetProfileName !== '') {
            $api = connectToMikrotikApiByDevice($device);
            try {
                $routerProfile = findMikrotikProfileByName($api, $targetProfileName);
            } finally {
                $api->disconnect();
            }

            if (is_array($routerProfile)) {
                $localProfile = findVoucherProfileByName($pdo, $targetProfileName);
                if (!$localProfile) {
                    $localProfile = createVoucherLocalProfileForMikrotik($pdo, $targetProfileName);
                }

                $metadata = parseMikrotikOnLoginMetadata((string)($routerProfile['on-login'] ?? ''));
                $validitySeconds = parseRouterosIntervalToSeconds((string)($metadata['validity'] ?? ''));
                $sessionTimeoutSeconds = parseRouterosIntervalToSeconds((string)($routerProfile['session-timeout'] ?? ''));
                $limitBytes = trim((string)($routerProfile['limit-bytes-total'] ?? ''));
                $limitBytesValue = $limitBytes !== '' ? (float)$limitBytes : 0;
                $dataQuotaMb = $limitBytesValue > 0
                    ? (int)round($limitBytesValue / 1024 / 1024)
                    : max(0, (int)($metadata['data_quota_mb'] ?? 0));

                return [
                    'id' => (int)($localProfile['id'] ?? 0),
                    'name' => $targetProfileName,
                    'service_type' => 'hotspot',
                    'rate_limit' => trim((string)($routerProfile['rate-limit'] ?? '')),
                    'session_timeout' => $sessionTimeoutSeconds > 0 ? $sessionTimeoutSeconds : null,
                    'idle_timeout' => null,
                    'validity_time' => $validitySeconds > 0 ? $validitySeconds : null,
                    'data_quota_mb' => $dataQuotaMb > 0 ? $dataQuotaMb : null,
                    'simultaneous_use' => (int)($routerProfile['shared-users'] ?? 0),
                    'price' => (string)($metadata['price'] ?? ''),
                    'selling_price' => (string)($metadata['selling_price'] ?? ''),
                ];
            }
        }

        // Fallback local si la lecture routeur échoue.
        if ($profileId > 0) {
            return findVoucherProfileById($pdo, $profileId);
        }
        if ($profileName !== '') {
            $localProfile = findVoucherProfileByName($pdo, $profileName);
            if ($localProfile) {
                return $localProfile;
            }
            return createVoucherLocalProfileForMikrotik($pdo, $profileName);
        }
        return null;
    }

    // Flux local/RADIUS/OPNsense : source locale SQL.
    if ($profileId > 0) {
        return findVoucherProfileById($pdo, $profileId);
    }
    if ($profileName !== '') {
        return findVoucherProfileByName($pdo, $profileName);
    }

    return null;
}

function normalizePostedVoucherTicketOptions(array $input): array
{
    return normalizeVoucherTicketOptions([
        'format' => trim((string)($input['ticket_format'] ?? 'small')),
        'ssid' => trim((string)($input['ssid'] ?? '')),
        'dns' => trim((string)($input['dns'] ?? '')),
        'show_profile_name' => isset($input['show_profile_name']),
        'show_rate_limit' => isset($input['show_rate_limit']),
        'show_price' => isset($input['show_price']),
        'show_data_limit' => isset($input['show_data_limit']),
        'show_time_limit' => isset($input['show_time_limit']),
        'show_qr' => isset($input['show_qr']),
        'show_logo' => isset($input['show_logo']),
        'logo_text' => trim((string)($input['logo_text'] ?? '')),
        'logo_url' => trim((string)($input['logo_url'] ?? '')),
    ]);
}

function buildPendingVoucherBatch(array $profile, string $deviceId, int $quantity, string $prefix, int $length, array $ticketOptions = []): array
{
    $generatedEntries = [];
    $generatedUsernames = [];

    while (count($generatedEntries) < $quantity) {
        $username = generateVoucherBatchCode($prefix, $length);
        if (in_array($username, $generatedUsernames, true)) {
            continue;
        }

        $generatedUsernames[] = $username;
        $generatedEntries[] = [
            'code' => $username,
            'username' => $username,
            'password' => generateVoucherBatchSecret($length),
        ];
    }

    return [
        'device_id' => $deviceId,
        'profile_id' => (int)$profile['id'],
        'profile_name' => (string)$profile['name'],
        'profile_defaults' => [
            'rate_limit' => trim((string)($profile['rate_limit'] ?? '')) ?: null,
            'session_timeout' => isset($profile['session_timeout']) ? (int)$profile['session_timeout'] : null,
            'idle_timeout' => isset($profile['idle_timeout']) ? (int)$profile['idle_timeout'] : null,
            'validity_time' => isset($profile['validity_time']) ? (int)$profile['validity_time'] : null,
            'data_quota_mb' => isset($profile['data_quota_mb']) ? (int)$profile['data_quota_mb'] : null,
            'simultaneous_use' => max(1, (int)($profile['simultaneous_use'] ?? 1)),
            'price' => isset($profile['price']) && $profile['price'] !== null ? (string)$profile['price'] : null,
            'selling_price' => isset($profile['selling_price']) && $profile['selling_price'] !== null ? (string)$profile['selling_price'] : null,
        ],
        'quantity' => $quantity,
        'prefix' => $prefix,
        'length' => $length,
        'format' => 'small',
        'ticket_options' => normalizeVoucherTicketOptions($ticketOptions),
        'entries' => $generatedEntries,
        'prepared_at' => date('Y-m-d H:i:s'),
    ];
}
