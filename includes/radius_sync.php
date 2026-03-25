<?php

function insertReply($pdo, $group, $attribute, $value)
{
    $stmt = $pdo->prepare("
        INSERT INTO radgroupreply (groupname, attribute, op, value)
        VALUES (?, ?, ':=', ?)
    ");
    $stmt->execute([$group, $attribute, $value]);
}

function insertUserReply($pdo, $username, $attribute, $value)
{
    $stmt = $pdo->prepare("
        INSERT INTO radreply (username, attribute, op, value)
        VALUES (?, ?, ':=', ?)
    ");
    $stmt->execute([$username, $attribute, $value]);
}

function convertMegabytesToBytes($megabytes)
{
    return (int)$megabytes * 1024 * 1024;
}

function hasCapability(array $capabilities, string $attribute): bool
{
    if ($capabilities === []) {
        return true;
    }

    return in_array($attribute, $capabilities, true);
}

function convertToBits($rate)
{
    $rate = strtoupper(trim($rate));

    if (strpos($rate, 'M') !== false) return (int)$rate * 1000000;
    if (strpos($rate, 'K') !== false) return (int)$rate * 1000;

    return (int)$rate;
}

function applyRateLimit($pdo, $group, $rateLimit, $nasType, array $capabilities = [])
{
    if (empty($rateLimit)) return;

    if ($nasType === 'mikrotik' && hasCapability($capabilities, 'Mikrotik-Rate-Limit')) {
        insertReply($pdo, $group, 'Mikrotik-Rate-Limit', $rateLimit);
    } else {
        if (!hasCapability($capabilities, 'WISPr-Bandwidth-Max-Down') || !hasCapability($capabilities, 'WISPr-Bandwidth-Max-Up')) {
            return;
        }

        if (strpos($rateLimit, '/') === false) {
            return;
        }

        list($down, $up) = explode('/', $rateLimit);

        insertReply($pdo, $group, 'WISPr-Bandwidth-Max-Down', convertToBits($down));
        insertReply($pdo, $group, 'WISPr-Bandwidth-Max-Up', convertToBits($up));
    }
}

function applyUserRateLimit($pdo, $username, $rateLimit, $nasType, array $capabilities = [])
{
    if (empty($rateLimit)) {
        return;
    }

    if ($nasType === 'mikrotik' && hasCapability($capabilities, 'Mikrotik-Rate-Limit')) {
        insertUserReply($pdo, $username, 'Mikrotik-Rate-Limit', $rateLimit);
        return;
    }

    if (!hasCapability($capabilities, 'WISPr-Bandwidth-Max-Down') || !hasCapability($capabilities, 'WISPr-Bandwidth-Max-Up')) {
        return;
    }

    if (strpos($rateLimit, '/') === false) {
        return;
    }

    list($down, $up) = explode('/', $rateLimit);
    insertUserReply($pdo, $username, 'WISPr-Bandwidth-Max-Down', convertToBits($down));
    insertUserReply($pdo, $username, 'WISPr-Bandwidth-Max-Up', convertToBits($up));
}

function syncProfileToRadius($pdo, $profile, $nasType, array $capabilities = [])
{
    $group = $profile['name'];

    // nettoyer
    $pdo->prepare("DELETE FROM radgroupreply WHERE groupname = ?")
        ->execute([$group]);

    // attributs de base
    if (($profile['session_timeout'] ?? 0) > 0 && hasCapability($capabilities, 'Session-Timeout'))
        insertReply($pdo, $group, 'Session-Timeout', $profile['session_timeout']);

    if (($profile['idle_timeout'] ?? 0) > 0 && hasCapability($capabilities, 'Idle-Timeout'))
        insertReply($pdo, $group, 'Idle-Timeout', $profile['idle_timeout']);

    if (($profile['simultaneous_use'] ?? 0) > 0 && hasCapability($capabilities, 'Simultaneous-Use'))
        insertReply($pdo, $group, 'Simultaneous-Use', $profile['simultaneous_use']);

    if (($profile['data_quota_mb'] ?? 0) > 0 && hasCapability($capabilities, 'Max-Octets'))
        insertReply($pdo, $group, 'Max-Octets', convertMegabytesToBytes($profile['data_quota_mb']));

    // rate limit
    applyRateLimit($pdo, $group, $profile['rate_limit'] ?? null, $nasType, $capabilities);
}

function syncProfileToNasBackend($pdo, $profile, array $nasContext)
{
    $backend = $nasContext['backend'] ?? 'radius';
    $nasType = $nasContext['nas_type'] ?? 'other';
    $capabilities = $nasContext['capabilities'] ?? [];

    if ($backend === 'radius') {
        syncProfileToRadius($pdo, $profile, $nasType, $capabilities);
        return;
    }

    throw new Exception("Backend NAS non supporte pour la synchro profil");
}

function syncUserToRadius($pdo, array $user, string $groupname): void
{
    $username = $user['username'];
    $password = $user['password'];
    $nasType = $user['nas_type'] ?? 'other';
    $capabilities = $user['capabilities'] ?? [];

    $stmt = $pdo->prepare("
        INSERT INTO radcheck (username, attribute, op, value)
        VALUES (?, 'Cleartext-Password', ':=', ?)
    ");
    $stmt->execute([$username, $password]);

    $stmt = $pdo->prepare("
        INSERT INTO radusergroup (username, groupname, priority)
        VALUES (?, ?, 1)
    ");
    $stmt->execute([$username, $groupname]);

    if (($user['session_timeout'] ?? 0) > 0 && hasCapability($capabilities, 'Session-Timeout')) {
        insertUserReply($pdo, $username, 'Session-Timeout', $user['session_timeout']);
    }

    if (($user['simultaneous_use'] ?? 0) > 0 && hasCapability($capabilities, 'Simultaneous-Use')) {
        insertUserReply($pdo, $username, 'Simultaneous-Use', $user['simultaneous_use']);
    }

    if (($user['idle_timeout'] ?? 0) > 0 && hasCapability($capabilities, 'Idle-Timeout')) {
        insertUserReply($pdo, $username, 'Idle-Timeout', $user['idle_timeout']);
    }

    if (($user['data_limit'] ?? 0) > 0 && hasCapability($capabilities, 'Max-Octets')) {
        insertUserReply($pdo, $username, 'Max-Octets', convertMegabytesToBytes($user['data_limit']));
    }

    if (!empty($user['expiration_date'])) {
        insertUserReply($pdo, $username, 'Expiration', $user['expiration_date']);
    }

    applyUserRateLimit($pdo, $username, $user['rate_limit'] ?? null, $nasType, $capabilities);
}

function updateUserInRadius($pdo, array $user, string $groupname): void
{
    $username = $user['username'];
    $oldUsername = $user['old_username'] ?? $username;
    $nasType = $user['nas_type'] ?? 'other';
    $capabilities = $user['capabilities'] ?? [];

    $stmt = $pdo->prepare("
        UPDATE radcheck
        SET username = ?, value = ?
        WHERE username = ? AND attribute = 'Cleartext-Password'
    ");
    $stmt->execute([$username, $user['password'], $oldUsername]);

    $stmt = $pdo->prepare("
        UPDATE radusergroup
        SET username = ?, groupname = ?
        WHERE username = ?
    ");
    $stmt->execute([$username, $groupname, $oldUsername]);

    $stmt = $pdo->prepare("DELETE FROM radreply WHERE username = ?");
    $stmt->execute([$oldUsername]);

    if (($user['session_timeout'] ?? 0) > 0 && hasCapability($capabilities, 'Session-Timeout')) {
        insertUserReply($pdo, $username, 'Session-Timeout', $user['session_timeout']);
    }

    if (($user['simultaneous_use'] ?? 0) > 0 && hasCapability($capabilities, 'Simultaneous-Use')) {
        insertUserReply($pdo, $username, 'Simultaneous-Use', $user['simultaneous_use']);
    }

    if (($user['idle_timeout'] ?? 0) > 0 && hasCapability($capabilities, 'Idle-Timeout')) {
        insertUserReply($pdo, $username, 'Idle-Timeout', $user['idle_timeout']);
    }

    if (($user['data_limit'] ?? 0) > 0 && hasCapability($capabilities, 'Max-Octets')) {
        insertUserReply($pdo, $username, 'Max-Octets', convertMegabytesToBytes($user['data_limit']));
    }

    if (!empty($user['expiration_date'])) {
        insertUserReply($pdo, $username, 'Expiration', $user['expiration_date']);
    }

    applyUserRateLimit($pdo, $username, $user['rate_limit'] ?? null, $nasType, $capabilities);
}

function syncUserToNasBackend($pdo, array $user, string $groupname, array $nasContext): void
{
    $backend = $nasContext['backend'] ?? 'radius';

    if ($backend === 'radius') {
        $user['nas_type'] = $nasContext['nas_type'] ?? 'other';
        $user['capabilities'] = $nasContext['capabilities'] ?? [];
        syncUserToRadius($pdo, $user, $groupname);
        return;
    }

    throw new Exception("Backend NAS non supporte pour la synchro utilisateur");
}

function updateUserToNasBackend($pdo, array $user, string $groupname, array $nasContext): void
{
    $backend = $nasContext['backend'] ?? 'radius';

    if ($backend === 'radius') {
        $user['nas_type'] = $nasContext['nas_type'] ?? 'other';
        $user['capabilities'] = $nasContext['capabilities'] ?? [];
        updateUserInRadius($pdo, $user, $groupname);
        return;
    }

    throw new Exception("Backend NAS non supporte pour la mise a jour utilisateur");
}
