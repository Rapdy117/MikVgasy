<?php

require_once __DIR__ . '/mikrotik_backend.php';

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

function loadGroupReplyAttributes(PDO $pdo, string $groupname): array
{
    $groupname = trim($groupname);
    if ($groupname === '') {
        return [];
    }

    $stmt = $pdo->prepare("SELECT attribute FROM radgroupreply WHERE groupname = ?");
    $stmt->execute([$groupname]);

    $attributes = [];
    foreach (($stmt->fetchAll(PDO::FETCH_COLUMN) ?: []) as $attribute) {
        $name = trim((string)$attribute);
        if ($name !== '') {
            $attributes[$name] = true;
        }
    }

    return $attributes;
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

function canWriteUserReplyAttribute(string $attribute, array $user = []): bool
{
    // In this deployment, FreeRADIUS accepts Max-Octets on the profile/group,
    // but rejects authentication when it is written directly to radreply.
    if ($attribute === 'Max-Octets') {
        return false;
    }

    return true;
}

function shouldWriteUserReplyAttribute(
    string $attribute,
    array $capabilities = [],
    array $groupReplyAttributes = [],
    array $user = []
): bool {
    if (!hasCapability($capabilities, $attribute)) {
        return false;
    }

    if (!canWriteUserReplyAttribute($attribute, $user)) {
        return false;
    }

    return !isset($groupReplyAttributes[$attribute]);
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

    if (!hasCapability($capabilities, 'WISPr-Bandwidth-Max-Down') || !hasCapability($capabilities, 'WISPr-Bandwidth-Max-Up')) {
        return;
    }

    if (strpos($rateLimit, '/') === false) {
        return;
    }

    [$up, $down] = explode('/', $rateLimit, 2);

    insertReply($pdo, $group, 'WISPr-Bandwidth-Max-Down', convertToBits($down));
    insertReply($pdo, $group, 'WISPr-Bandwidth-Max-Up', convertToBits($up));
}

function applyUserRateLimit($pdo, $username, $rateLimit, $nasType, array $capabilities = [], array $groupReplyAttributes = [])
{
    if (empty($rateLimit)) {
        return;
    }

    if (
        !shouldWriteUserReplyAttribute('WISPr-Bandwidth-Max-Down', $capabilities, $groupReplyAttributes)
        || !shouldWriteUserReplyAttribute('WISPr-Bandwidth-Max-Up', $capabilities, $groupReplyAttributes)
    ) {
        return;
    }

    if (strpos($rateLimit, '/') === false) {
        return;
    }

    [$up, $down] = explode('/', $rateLimit, 2);
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
    $businessSource = (string)($nasContext['business_source'] ?? '');
    $nasType = (string)($nasContext['nas_type'] ?? '');
    $capabilities = $nasContext['capabilities'] ?? [];

    if ($businessSource === '' || $nasType === '') {
        throw new InvalidArgumentException('nas_context incomplet: business_source et nas_type requis');
    }

    if ($businessSource === 'radius') {
        syncProfileToRadius($pdo, $profile, $nasType, $capabilities);
        return;
    }

    if ($businessSource === 'mikrotik_local') {
        syncProfileToMikrotik($profile, $nasContext);
        return;
    }

    throw new Exception("Ce type de NAS ne passe pas par la base metier / FreeRADIUS");
}

function updateProfileInRadius($pdo, $profile, $nasType, array $capabilities = [])
{
    $group = $profile['name'];
    $oldGroup = trim((string)($profile['old_name'] ?? ''));
    if ($oldGroup === '') {
        $oldGroup = $group;
    }

    if ($oldGroup !== $group) {
        $stmt = $pdo->prepare("
            UPDATE radusergroup
            SET groupname = ?
            WHERE groupname = ?
        ");
        $stmt->execute([$group, $oldGroup]);

        $pdo->prepare("DELETE FROM radgroupreply WHERE groupname = ?")
            ->execute([$oldGroup]);
    }

    syncProfileToRadius($pdo, $profile, $nasType, $capabilities);
}

function updateProfileToNasBackend($pdo, $profile, array $nasContext)
{
    $businessSource = (string)($nasContext['business_source'] ?? '');
    $nasType = (string)($nasContext['nas_type'] ?? '');
    $capabilities = $nasContext['capabilities'] ?? [];

    if ($businessSource === '' || $nasType === '') {
        throw new InvalidArgumentException('nas_context incomplet: business_source et nas_type requis');
    }

    if ($businessSource === 'radius') {
        updateProfileInRadius($pdo, $profile, $nasType, $capabilities);
        return;
    }

    if ($businessSource === 'mikrotik_local') {
        updateProfileInMikrotik($profile, $nasContext);
        return;
    }

    throw new Exception("Ce type de NAS ne passe pas par la base metier / FreeRADIUS");
}

function deleteProfileFromRadius($pdo, string $groupname): void
{
    $groupname = trim($groupname);
    if ($groupname === '') {
        return;
    }

    $stmt = $pdo->prepare("DELETE FROM radgroupreply WHERE groupname = ?");
    $stmt->execute([$groupname]);

    $stmt = $pdo->prepare("DELETE FROM radgroupcheck WHERE groupname = ?");
    $stmt->execute([$groupname]);
}

function syncUserToRadius($pdo, array $user, string $groupname): void
{
    $username = $user['username'];
    $password = $user['password'];
    $nasType = (string)($user['nas_type'] ?? '');
    if ($nasType === '') {
        throw new InvalidArgumentException('nas_type requis pour syncUserToRadius');
    }
    $capabilities = $user['capabilities'] ?? [];
    $groupReplyAttributes = loadGroupReplyAttributes($pdo, $groupname);

    $stmt = $pdo->prepare("DELETE FROM radcheck WHERE username = ? AND attribute = 'Auth-Type'");
    $stmt->execute([$username]);

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

    if (($user['session_timeout'] ?? 0) > 0 && shouldWriteUserReplyAttribute('Session-Timeout', $capabilities, $groupReplyAttributes, $user)) {
        insertUserReply($pdo, $username, 'Session-Timeout', $user['session_timeout']);
    }

    if (($user['simultaneous_use'] ?? 0) > 0 && shouldWriteUserReplyAttribute('Simultaneous-Use', $capabilities, $groupReplyAttributes, $user)) {
        insertUserReply($pdo, $username, 'Simultaneous-Use', $user['simultaneous_use']);
    }

    if (($user['idle_timeout'] ?? 0) > 0 && shouldWriteUserReplyAttribute('Idle-Timeout', $capabilities, $groupReplyAttributes, $user)) {
        insertUserReply($pdo, $username, 'Idle-Timeout', $user['idle_timeout']);
    }

    if (
        ($user['data_limit'] ?? 0) > 0
        && shouldWriteUserReplyAttribute('Max-Octets', $capabilities, $groupReplyAttributes, $user)
    ) {
        insertUserReply($pdo, $username, 'Max-Octets', convertMegabytesToBytes($user['data_limit']));
    }

    if (!empty($user['expiration_date'])) {
        insertUserReply($pdo, $username, 'Expiration', $user['expiration_date']);
    }

    applyUserRateLimit($pdo, $username, $user['rate_limit'] ?? null, $nasType, $capabilities, $groupReplyAttributes);
}

function updateUserInRadius($pdo, array $user, string $groupname): void
{
    $username = $user['username'];
    $oldUsername = $user['old_username'] ?? $username;
    $nasType = (string)($user['nas_type'] ?? '');
    if ($nasType === '') {
        throw new InvalidArgumentException('nas_type requis pour updateUserInRadius');
    }
    $capabilities = $user['capabilities'] ?? [];
    $groupReplyAttributes = loadGroupReplyAttributes($pdo, $groupname);

    $stmt = $pdo->prepare("DELETE FROM radcheck WHERE username = ? AND attribute = 'Auth-Type'");
    $stmt->execute([$oldUsername]);
    if ($oldUsername !== $username) {
        $stmt->execute([$username]);
    }

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

    if (($user['session_timeout'] ?? 0) > 0 && shouldWriteUserReplyAttribute('Session-Timeout', $capabilities, $groupReplyAttributes, $user)) {
        insertUserReply($pdo, $username, 'Session-Timeout', $user['session_timeout']);
    }

    if (($user['simultaneous_use'] ?? 0) > 0 && shouldWriteUserReplyAttribute('Simultaneous-Use', $capabilities, $groupReplyAttributes, $user)) {
        insertUserReply($pdo, $username, 'Simultaneous-Use', $user['simultaneous_use']);
    }

    if (($user['idle_timeout'] ?? 0) > 0 && shouldWriteUserReplyAttribute('Idle-Timeout', $capabilities, $groupReplyAttributes, $user)) {
        insertUserReply($pdo, $username, 'Idle-Timeout', $user['idle_timeout']);
    }

    if (
        ($user['data_limit'] ?? 0) > 0
        && shouldWriteUserReplyAttribute('Max-Octets', $capabilities, $groupReplyAttributes, $user)
    ) {
        insertUserReply($pdo, $username, 'Max-Octets', convertMegabytesToBytes($user['data_limit']));
    }

    if (!empty($user['expiration_date'])) {
        insertUserReply($pdo, $username, 'Expiration', $user['expiration_date']);
    }

    applyUserRateLimit($pdo, $username, $user['rate_limit'] ?? null, $nasType, $capabilities, $groupReplyAttributes);
}

function syncUserToNasBackend($pdo, array $user, string $groupname, array $nasContext): void
{
    $businessSource = (string)($nasContext['business_source'] ?? '');

    if ($businessSource === '') {
        throw new InvalidArgumentException('business_source requis dans nasContext');
    }

    if ($businessSource === 'radius') {
        $user['nas_type'] = (string)($nasContext['nas_type'] ?? '');
        $user['capabilities'] = $nasContext['capabilities'] ?? [];
        if ($user['nas_type'] === '') {
            throw new InvalidArgumentException('nas_type requis dans nasContext pour sync RADIUS');
        }
        syncUserToRadius($pdo, $user, $groupname);
        return;
    }

    if ($businessSource === 'mikrotik_local') {
        syncUserToMikrotik($user, $groupname, $nasContext);
        return;
    }

    throw new Exception("Ce type de NAS ne passe pas par la base metier / FreeRADIUS");
}

function updateUserToNasBackend($pdo, array $user, string $groupname, array $nasContext): void
{
    $businessSource = (string)($nasContext['business_source'] ?? '');

    if ($businessSource === '') {
        throw new InvalidArgumentException('business_source requis dans nasContext');
    }

    if ($businessSource === 'radius') {
        $user['nas_type'] = (string)($nasContext['nas_type'] ?? '');
        $user['capabilities'] = $nasContext['capabilities'] ?? [];
        if ($user['nas_type'] === '') {
            throw new InvalidArgumentException('nas_type requis dans nasContext pour sync RADIUS');
        }
        updateUserInRadius($pdo, $user, $groupname);
        return;
    }

    if ($businessSource === 'mikrotik_local') {
        updateUserInMikrotik($user, $groupname, $nasContext);
        return;
    }

    throw new Exception("Ce type de NAS ne passe pas par la base metier / FreeRADIUS");
}
