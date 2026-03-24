<?php

function insertReply($pdo, $group, $attribute, $value)
{
    $stmt = $pdo->prepare("
        INSERT INTO radgroupreply (groupname, attribute, op, value)
        VALUES (?, ?, ':=', ?)
    ");
    $stmt->execute([$group, $attribute, $value]);
}

function convertToBits($rate)
{
    $rate = strtoupper(trim($rate));

    if (strpos($rate, 'M') !== false) return (int)$rate * 1000000;
    if (strpos($rate, 'K') !== false) return (int)$rate * 1000;

    return (int)$rate;
}

function applyRateLimit($pdo, $group, $rateLimit, $nasType)
{
    if (empty($rateLimit)) return;

    if ($nasType === 'mikrotik') {
        insertReply($pdo, $group, 'Mikrotik-Rate-Limit', $rateLimit);
    } else {
        list($down, $up) = explode('/', $rateLimit);

        insertReply($pdo, $group, 'WISPr-Bandwidth-Max-Down', convertToBits($down));
        insertReply($pdo, $group, 'WISPr-Bandwidth-Max-Up', convertToBits($up));
    }
}

function syncProfileToRadius($pdo, $profile, $nasType)
{
    $group = $profile['name'];

    // nettoyer
    $pdo->prepare("DELETE FROM radgroupreply WHERE groupname = ?")
        ->execute([$group]);

    // attributs de base
    if ($profile['session_timeout'])
        insertReply($pdo, $group, 'Session-Timeout', $profile['session_timeout']);

    if ($profile['idle_timeout'])
        insertReply($pdo, $group, 'Idle-Timeout', $profile['idle_timeout']);

    if ($profile['simultaneous_use'])
        insertReply($pdo, $group, 'Simultaneous-Use', $profile['simultaneous_use']);

    if ($profile['data_quota_mb'])
        insertReply($pdo, $group, 'Max-Octets', $profile['data_quota_mb'] * 1024 * 1024);

    // rate limit
    applyRateLimit($pdo, $group, $profile['rate_limit'], $nasType);
}