<?php

require_once __DIR__ . '/device_manager.php';
require_once __DIR__ . '/mikrotik_backend.php';
require_once __DIR__ . '/opnsense_shaper.php';

function loadSessionProfileMap(PDO $pdo, array $usernames): array
{
    $usernames = array_values(array_unique(array_filter(array_map(
        static fn ($value): string => trim((string)$value),
        $usernames
    ))));

    if ($usernames === []) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($usernames), '?'));
    $stmt = $pdo->prepare("
        SELECT rug.username, rug.groupname
        FROM radusergroup rug
        WHERE rug.username IN ($placeholders)
    ");
    $stmt->execute($usernames);

    $map = [];
    foreach (($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) as $row) {
        $username = trim((string)($row['username'] ?? ''));
        if ($username === '') {
            continue;
        }

        $map[$username] = trim((string)($row['groupname'] ?? ''));
    }

    return $map;
}

function loadRadiusActiveSessions(PDO $pdo, int $limit = 100): array
{
    $stmt = $pdo->query("
        SELECT
            radacctid,
            acctsessionid,
            username,
            framedipaddress,
            callingstationid,
            acctsessiontime,
            acctinputoctets,
            acctoutputoctets,
            nasipaddress,
            acctstarttime
        FROM radacct
        WHERE acctstoptime IS NULL
        ORDER BY acctstarttime DESC
        LIMIT " . (int)$limit . "
    ");

    return $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
}

function loadOpnsenseActiveSessions(array $activeDevice): array
{
    $response = opnsenseApiRequest($activeDevice, '/api/captiveportal/session/search');
    if (!($response['success'] ?? false)) {
        return [];
    }

    return is_array($response['data']['rows'] ?? null) ? $response['data']['rows'] : [];
}

function loadActiveSessionsData(PDO $pdo, ?array $activeDevice): array
{
    if (!is_array($activeDevice) || trim((string)($activeDevice['type'] ?? '')) === '') {
        $activeDeviceType = 'radius';
    } else {
        $activeDeviceType = normalizeDeviceType((string)$activeDevice['type']);
    }
    $isMikrotikSessions = $activeDeviceType === 'mikrotik';
    $isOpnsenseSessions = $activeDeviceType === 'opnsense';
    $canDisconnectRemotely = $activeDevice && in_array($activeDeviceType, ['opnsense', 'mikrotik'], true);

    $sessionSourceLabel = 'FreeRADIUS / radacct';
    $sessions = [];

    if ($isMikrotikSessions) {
        $sessionSourceLabel = 'MikroTik / hotspot active';
        $sessions = getMikrotikHotspotActiveUsers(100);
    } elseif ($isOpnsenseSessions && is_array($activeDevice)) {
        $sessionSourceLabel = 'OPNsense / captive portal active';
        $sessions = loadOpnsenseActiveSessions($activeDevice);
    } else {
        $sessions = loadRadiusActiveSessions($pdo, 100);
    }

    $sessionProfileMap = loadSessionProfileMap($pdo, array_map(
        static function ($session): string {
            return trim((string)($session['username'] ?? $session['userName'] ?? $session['user'] ?? ''));
        },
        $sessions
    ));

    return [
        'activeDeviceType' => $activeDeviceType,
        'isMikrotikSessions' => $isMikrotikSessions,
        'isOpnsenseSessions' => $isOpnsenseSessions,
        'canDisconnectRemotely' => $canDisconnectRemotely,
        'sessionSourceLabel' => $sessionSourceLabel,
        'sessions' => $sessions,
        'sessionProfileMap' => $sessionProfileMap,
    ];
}
