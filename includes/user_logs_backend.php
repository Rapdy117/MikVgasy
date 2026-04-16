<?php

require_once __DIR__ . '/device_manager.php';
require_once __DIR__ . '/app_context.php';
require_once __DIR__ . '/mikrotik_backend.php';
require_once __DIR__ . '/operation_history.php';
require_once __DIR__ . '/event_translators.php';

function formatUserLogsDeviceLabel(array $device): string
{
    $name = trim((string)($device['name'] ?? ''));
    $ip = trim((string)($device['ip'] ?? ($device['host'] ?? '')));

    if ($name !== '' && $ip !== '') {
        return sprintf('%s (%s)', $name, $ip);
    }
    if ($name !== '') {
        return $name;
    }
    if ($ip !== '') {
        return $ip;
    }
    return '-';
}

function parseMikrotikLogDateTime(?string $value): array
{
    $raw = trim((string)$value);
    if ($raw === '') {
        return ['-', '-'];
    }

    if (preg_match('/^(\\d{4}-\\d{2}-\\d{2})[ T](\\d{2}:\\d{2}:\\d{2})$/', $raw, $matches)) {
        return [$matches[1], $matches[2]];
    }

    if (preg_match('/^(\\d{4}-\\d{2}-\\d{2})$/', $raw, $matches)) {
        return [$matches[1], '-'];
    }

    if (preg_match('/^(\\d{2}:\\d{2}:\\d{2})$/', $raw, $matches)) {
        return ['-', $matches[1]];
    }

    if (preg_match('/^(?<mon>[a-zA-Z]{3})\\/?(?<day>\\d{1,2})(?:\\/(?<year>\\d{2,4}))?\\s+(?<time>\\d{2}:\\d{2}:\\d{2})$/', $raw, $matches)) {
        $monthKey = strtolower($matches['mon']);
        $monthMap = [
            'jan' => 1,
            'feb' => 2,
            'mar' => 3,
            'apr' => 4,
            'may' => 5,
            'jun' => 6,
            'jul' => 7,
            'aug' => 8,
            'sep' => 9,
            'oct' => 10,
            'nov' => 11,
            'dec' => 12,
            'fev' => 2,
            'avr' => 4,
            'mai' => 5,
            'aou' => 8,
        ];

        if (isset($monthMap[$monthKey])) {
            $year = (int)($matches['year'] ?? date('Y'));
            if ($year > 0 && $year < 100) {
                $year += 2000;
            }

            $date = sprintf('%04d-%02d-%02d', $year, $monthMap[$monthKey], (int)$matches['day']);
            return [$date, $matches['time']];
        }
    }

    return ['-', $raw];
}

function mapRadiusTerminateCauseToStatus(string $cause, bool $isOnline): string
{
    if ($isOnline) {
        return 'En ligne';
    }

    return match (trim($cause)) {
        'Idle-Timeout' => 'Idle Timeout',
        'Session-Timeout' => 'Time Limit',
        'User-Request' => 'Deconnexion',
        'Admin-Reset' => 'Coupe admin',
        'Lost-Service', 'Lost-Carrier' => 'Perte service',
        default => trim($cause) !== '' ? trim($cause) : 'Hors ligne',
    };
}

function formatAmountLabel($value): string
{
    $amount = (float)($value ?? 0);
    $formatted = number_format($amount, 2, '.', ' ');
    return rtrim(rtrim($formatted, '0'), '.');
}

function rechargeModeLabel(string $mode): string
{
    return match ($mode) {
        'replace_offer' => 'Changement profil',
        'extend_offer' => 'Rechargement',
        'accumulate_offer' => 'Réabonnement',
        default => $mode !== '' ? $mode : 'Recharge',
    };
}

/**
 * Contexte applicatif + device actif (aligné sur getActiveDeviceRecord, pas sur un device partiel seul).
 */
function buildUserLogsContext(): array
{
    $context = [
        'app' => buildAppContext(),
    ];

    $store = loadDeviceStore();
    $activeDevice = getActiveDeviceRecord($store);
    if (!is_array($activeDevice) || trim((string)($activeDevice['type'] ?? '')) === '') {
        $activeDeviceType = 'radius';
    } else {
        $activeDeviceType = normalizeDeviceType((string)$activeDevice['type']);
    }
    $context['is_mikrotik'] = is_array($activeDevice) && $activeDeviceType === 'mikrotik';
    $context['is_radius_like'] = is_array($activeDevice) && in_array($activeDeviceType, ['opnsense', 'radius'], true);

    $context['device'] = $context['app']['device'] ?? null;
    $context['device_type'] = strtolower(trim((string)($context['device']['type'] ?? '')));

    return $context;
}

function buildUserLogsDeviceMaps(array $store): array
{
    $activeDevice = getActiveDeviceRecord($store);
    $activeDeviceId = (string)($activeDevice['id'] ?? '');
    $activeDeviceLabel = is_array($activeDevice) ? formatUserLogsDeviceLabel($activeDevice) : '-';

    $deviceLabelById = [];
    $deviceByIp = [];

    foreach (($store['devices'] ?? []) as $device) {
        $deviceId = trim((string)($device['id'] ?? ''));
        if ($deviceId === '') {
            continue;
        }

        $deviceLabelById[$deviceId] = formatUserLogsDeviceLabel($device);

        $ip = trim((string)($device['ip'] ?? ''));
        if ($ip !== '') {
            $deviceByIp[$ip] = $deviceId;
        }
    }

    $selectedServerId = 'active';
    $queryDeviceId = '';
    if ($selectedServerId === 'active') {
        $queryDeviceId = $activeDeviceId;
    } elseif ($selectedServerId !== 'all') {
        $queryDeviceId = $selectedServerId;
    }

    $queryDeviceIp = '';
    if ($queryDeviceId !== '') {
        foreach (($store['devices'] ?? []) as $device) {
            if ((string)($device['id'] ?? '') === $queryDeviceId) {
                $queryDeviceIp = trim((string)($device['ip'] ?? ''));
                break;
            }
        }
    }

    return [
        'active_device' => $activeDevice,
        'active_device_id' => $activeDeviceId,
        'active_device_label' => $activeDeviceLabel,
        'device_label_by_id' => $deviceLabelById,
        'device_by_ip' => $deviceByIp,
        'query_device_id' => $queryDeviceId,
        'query_device_ip' => $queryDeviceIp,
    ];
}

function buildUserLogsFilters(array $filters): array
{
    $selectedDay = trim((string)($filters['day'] ?? ''));
    $selectedMonth = trim((string)($filters['month'] ?? ''));
    $selectedYear = trim((string)($filters['year'] ?? ''));
    $selectedSource = trim((string)($filters['source'] ?? 'all'));

    if ($selectedSource === '') {
        $selectedSource = 'all';
    }

    $dayFilter = '';
    if ($selectedDay !== '' && $selectedMonth !== '' && $selectedYear !== '') {
        $dayFilter = sprintf('%04d-%02d-%02d', (int)$selectedYear, (int)$selectedMonth, (int)$selectedDay);
    }

    return [
        'day' => $selectedDay,
        'month' => $selectedMonth,
        'year' => $selectedYear,
        'source' => $selectedSource,
        'day_filter' => $dayFilter,
    ];
}

function loadMikrotikHotspotEvents(array $context): array
{
    $events = [];
    $error = null;

    if (!$context['is_mikrotik']) {
        return ['rows' => $events, 'error' => $error];
    }

    try {
        foreach (getMikrotikHotspotLogs(400) as $row) {
            if (!is_array($row)) {
                continue;
            }

            $parsed = parseMikrotikHotspotLogMessage($row);
            if ($parsed !== null) {
                $events[] = $parsed;
            }
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }

    return ['rows' => $events, 'error' => $error];
}

function loadRadiusSessionRows(PDO $pdo, array $context, array $filters, array $deviceMaps): array
{
    $rows = [];
    $error = null;
    $firstLoginMap = [];

    if (!$context['is_radius_like']) {
        return ['rows' => $rows, 'error' => $error, 'first_login_map' => $firstLoginMap];
    }

    try {
        $sql = "
            SELECT
                username,
                acctstarttime,
                acctstoptime,
                acctsessiontime,
                acctterminatecause,
                framedipaddress,
                callingstationid,
                nasipaddress
            FROM radacct
            WHERE 1 = 1
        ";
        $params = [];

        if ($filters['day_filter'] !== '') {
            $sql .= " AND DATE(COALESCE(acctstoptime, acctstarttime)) = ?";
            $params[] = $filters['day_filter'];
        } elseif ($filters['month'] !== '' && $filters['year'] !== '') {
            $sql .= " AND YEAR(COALESCE(acctstoptime, acctstarttime)) = ? AND MONTH(COALESCE(acctstoptime, acctstarttime)) = ?";
            $params[] = (int)$filters['year'];
            $params[] = (int)$filters['month'];
        }

        if ($deviceMaps['query_device_ip'] !== '') {
            $sql .= " AND nasipaddress = ?";
            $params[] = $deviceMaps['query_device_ip'];
        }

        $sql .= " ORDER BY COALESCE(acctstoptime, acctstarttime) DESC, radacctid DESC LIMIT 1000";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }

    try {
        $firstLoginSql = "
            SELECT username, MIN(acctstarttime) AS first_start
            FROM radacct
            WHERE acctstarttime IS NOT NULL AND acctstarttime <> ''
        ";
        $firstLoginParams = [];

        if ($deviceMaps['query_device_ip'] !== '') {
            $firstLoginSql .= " AND nasipaddress = ?";
            $firstLoginParams[] = $deviceMaps['query_device_ip'];
        }

        $firstLoginSql .= " GROUP BY username";
        $stmt = $pdo->prepare($firstLoginSql);
        $stmt->execute($firstLoginParams);

        foreach (($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) as $row) {
            $username = trim((string)($row['username'] ?? ''));
            $firstStart = trim((string)($row['first_start'] ?? ''));
            if ($username !== '' && $firstStart !== '') {
                $firstLoginMap[$username] = $firstStart;
            }
        }
    } catch (Throwable $e) {
        // non bloquant
    }

    return ['rows' => $rows, 'error' => $error, 'first_login_map' => $firstLoginMap];
}

function loadRadiusAuthRows(PDO $pdo, array $context, array $filters): array
{
    $rows = [];
    $error = null;

    if (!$context['is_radius_like']) {
        return ['rows' => $rows, 'error' => $error];
    }

    try {
        $tableCheck = $pdo->query("SHOW TABLES LIKE 'radpostauth'");
        $hasRadPostAuth = $tableCheck && $tableCheck->fetchColumn();

        if ($hasRadPostAuth) {
            $sql = "
                SELECT username, reply, authdate
                FROM radpostauth
                WHERE reply IS NOT NULL AND reply <> ''
            ";
            $params = [];

            if ($filters['day_filter'] !== '') {
                $sql .= " AND DATE(authdate) = ?";
                $params[] = $filters['day_filter'];
            } elseif ($filters['month'] !== '' && $filters['year'] !== '') {
                $sql .= " AND YEAR(authdate) = ? AND MONTH(authdate) = ?";
                $params[] = (int)$filters['year'];
                $params[] = (int)$filters['month'];
            }

            $sql .= " ORDER BY authdate DESC LIMIT 500";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }

    return ['rows' => $rows, 'error' => $error];
}

function loadOperationHistoryRows(PDO $pdo, array $context, array $filters, array $deviceMaps): array
{
    $rows = [];
    $error = null;

    if (!$context['is_mikrotik'] && !$context['is_radius_like']) {
        return ['rows' => $rows, 'error' => $error];
    }

    try {
        ensureOperationHistoryTable($pdo);

        $sql = "
            SELECT
                operation_type,
                actor_username,
                target_name,
                summary,
                device_id,
                profile_name,
                created_at
            FROM operation_history
            WHERE operation_type IN ('user_notice_record', 'user_remove_record', 'session_disconnect')
        ";
        $params = [];

        if ($filters['day_filter'] !== '') {
            $sql .= " AND DATE(created_at) = ?";
            $params[] = $filters['day_filter'];
        } elseif ($filters['month'] !== '' && $filters['year'] !== '') {
            $sql .= " AND YEAR(created_at) = ? AND MONTH(created_at) = ?";
            $params[] = (int)$filters['year'];
            $params[] = (int)$filters['month'];
        }

        if ($deviceMaps['query_device_id'] !== '') {
            $sql .= " AND device_id = ?";
            $params[] = $deviceMaps['query_device_id'];
        }

        $sql .= " ORDER BY created_at DESC, id DESC LIMIT 500";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }

    return ['rows' => $rows, 'error' => $error];
}

function loadRechargeHistoryRows(PDO $pdo, array $filters, array $deviceMaps): array
{
    $rows = [];
    $error = null;

    try {
        $sql = "
            SELECT
                created_at,
                device_id,
                username,
                profile_name,
                mode,
                operator_username,
                effect_summary,
                amount_value
            FROM recharge_history
            WHERE 1 = 1
        ";
        $params = [];

        if ($filters['day_filter'] !== '') {
            $sql .= " AND DATE(created_at) = ?";
            $params[] = $filters['day_filter'];
        } elseif ($filters['month'] !== '' && $filters['year'] !== '') {
            $sql .= " AND YEAR(created_at) = ? AND MONTH(created_at) = ?";
            $params[] = (int)$filters['year'];
            $params[] = (int)$filters['month'];
        }

        if ($deviceMaps['query_device_id'] !== '') {
            $sql .= " AND device_id = ?";
            $params[] = $deviceMaps['query_device_id'];
        }

        $sql .= " ORDER BY created_at DESC, id DESC LIMIT 500";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }

    return ['rows' => $rows, 'error' => $error];
}

function buildMikrotikProfileMap(array $context): array
{
    $map = [];

    if (!$context['is_mikrotik']) {
        return $map;
    }

    try {
        foreach (getMikrotikHotspotUsers(100) as $u) {
            $name = trim((string)($u['username'] ?? $u['name'] ?? ''));
            $profile = trim((string)($u['profile'] ?? ''));
            if ($name !== '') {
                $map[$name] = $profile !== '' ? $profile : '-';
            }
        }
    } catch (Throwable $e) {
        // non bloquant
    }

    return $map;
}

function normalizeMikrotikLogRows(array $hotspotEvents, string $activeDeviceLabel, array $profileMap, bool $isMikrotik): array
{
    $rows = [];

    foreach ($hotspotEvents as $log) {
        $hotspotAction = (string)translateHotspotAction((string)($log['action'] ?? '-'));
        $hotspotStatus = (string)translateHotspotStatus((string)($log['status'] ?? 'info'));
        if ($hotspotStatus === 'Echec' && str_contains(strtolower($hotspotAction), 'invalid password')) {
            $hotspotStatus = 'Mot de passe invalide';
        }

        [$hotspotDate, $hotspotTime] = parseMikrotikLogDateTime((string)($log['time'] ?? ''));
        if ($hotspotDate === '-' && $hotspotTime === '-') {
            $hotspotTime = (string)($log['time'] ?? '-');
        }

        $username = (string)($log['user'] ?? '-');
        $profile = '-';
        if ($isMikrotik && $username !== '-' && $username !== '') {
            $profile = $profileMap[$username] ?? '-';
        }

        $rows[] = [
            'date' => $hotspotDate !== '' ? $hotspotDate : '-',
            'time' => $hotspotTime !== '' ? $hotspotTime : '-',
            'username' => $username,
            'profile' => $profile,
            'address' => (string)($log['address'] ?? '-'),
            'mac' => '-',
            'action' => $hotspotAction,
            'status' => $hotspotStatus,
            'server' => $activeDeviceLabel,
            'source_key' => 'details_event',
        ];
    }

    return $rows;
}

function normalizeRadiusSessionRows(array $radiusSessions, array $firstLoginMap, array $deviceMaps): array
{
    $rows = [];

    foreach ($radiusSessions as $session) {
        $stopAt = trim((string)($session['acctstoptime'] ?? ''));
        $startAt = trim((string)($session['acctstarttime'] ?? ''));
        $anchor = $stopAt !== '' ? $stopAt : $startAt;
        $timestamp = $anchor !== '' ? strtotime($anchor) : false;
        $isOnline = $stopAt === '';
        $terminateCause = trim((string)($session['acctterminatecause'] ?? ''));
        $isFirstLogin = $startAt !== '' && isset($firstLoginMap[(string)($session['username'] ?? '')]) && $firstLoginMap[(string)($session['username'] ?? '')] === $startAt;

        $action = $isOnline
            ? 'Session active'
            : ($terminateCause !== '' ? $terminateCause : 'Session stoppee');
        if ($isFirstLogin) {
            $action .= ' (1er login)';
        }

        $server = '-';
        if ($deviceMaps['query_device_id'] !== '') {
            $server = $deviceMaps['device_label_by_id'][$deviceMaps['query_device_id']] ?? $deviceMaps['query_device_id'];
        } else {
            $nasIp = (string)($session['nasipaddress'] ?? '');
            if ($nasIp !== '' && isset($deviceMaps['device_by_ip'][$nasIp]) && $deviceMaps['device_by_ip'][$nasIp] !== '') {
                $server = $deviceMaps['device_label_by_id'][$deviceMaps['device_by_ip'][$nasIp]] ?? $nasIp;
            } elseif ($nasIp !== '') {
                $server = $nasIp;
            }
        }

        $rows[] = [
            'date' => $timestamp ? date('Y-m-d', $timestamp) : '-',
            'time' => $timestamp ? date('H:i:s', $timestamp) : '-',
            'username' => (string)($session['username'] ?? '-'),
            'profile' => '-',
            'address' => (string)($session['framedipaddress'] ?? '-'),
            'mac' => (string)($session['callingstationid'] ?? '-'),
            'action' => $action,
            'status' => mapRadiusTerminateCauseToStatus($terminateCause, $isOnline),
            'server' => $server,
            'source_key' => 'radius_session',
        ];
    }

    return $rows;
}

function normalizeRadiusAuthRows(array $radiusAuthRows, array $deviceMaps): array
{
    $rows = [];

    foreach ($radiusAuthRows as $row) {
        $authDate = trim((string)($row['authdate'] ?? ''));
        $timestamp = $authDate !== '' ? strtotime($authDate) : false;
        $reply = strtolower(trim((string)($row['reply'] ?? '')));

        if ($reply === '' || !str_contains($reply, 'reject')) {
            continue;
        }

        $rows[] = [
            'date' => $timestamp ? date('Y-m-d', $timestamp) : '-',
            'time' => $timestamp ? date('H:i:s', $timestamp) : '-',
            'username' => (string)($row['username'] ?? '-'),
            'profile' => '-',
            'address' => '-',
            'mac' => '-',
            'action' => 'Mot de passe invalide',
            'status' => 'Echec',
            'server' => $deviceMaps['query_device_id'] !== ''
                ? ($deviceMaps['device_label_by_id'][$deviceMaps['query_device_id']] ?? $deviceMaps['query_device_id'])
                : '-',
            'source_key' => 'radius_auth',
        ];
    }

    return $rows;
}

function normalizeRechargeRows(array $rechargeRows, array $deviceMaps): array
{
    $rows = [];

    foreach ($rechargeRows as $row) {
        $createdAt = trim((string)($row['created_at'] ?? ''));
        $timestamp = $createdAt !== '' ? strtotime($createdAt) : false;
        $profileName = trim((string)($row['profile_name'] ?? ''));
        $amountValue = (float)($row['amount_value'] ?? 0);
        $mode = trim((string)($row['mode'] ?? ''));
        $summary = trim((string)($row['effect_summary'] ?? ''));
        $action = $summary !== '' ? $summary : rechargeModeLabel($mode);

        if ($amountValue > 0) {
            $action = sprintf('%s (%s)', $action, formatAmountLabel($amountValue));
        }

        $deviceId = (string)($row['device_id'] ?? '');

        $rows[] = [
            'date' => $timestamp ? date('Y-m-d', $timestamp) : '-',
            'time' => $timestamp ? date('H:i:s', $timestamp) : '-',
            'username' => (string)($row['username'] ?? '-'),
            'profile' => $profileName !== '' ? $profileName : '-',
            'address' => '-',
            'mac' => '-',
            'action' => $action,
            'status' => rechargeModeLabel($mode),
            'server' => (string)($deviceMaps['device_label_by_id'][$deviceId] ?? ($deviceId !== '' ? $deviceId : '-')),
            'source_key' => 'recharge',
        ];
    }

    return $rows;
}

function normalizeOperationRows(array $operationRows, array $deviceMaps): array
{
    $rows = [];

    foreach ($operationRows as $row) {
        $createdAt = trim((string)($row['created_at'] ?? ''));
        $timestamp = $createdAt !== '' ? strtotime($createdAt) : false;
        $targetName = trim((string)($row['target_name'] ?? ''));
        $actorUsername = trim((string)($row['actor_username'] ?? ''));
        $summary = trim((string)($row['summary'] ?? ''));
        $operationType = trim((string)($row['operation_type'] ?? ''));
        $profileName = trim((string)($row['profile_name'] ?? ''));
        $deviceId = (string)($row['device_id'] ?? '');

        $rows[] = [
            'date' => $timestamp ? date('Y-m-d', $timestamp) : '-',
            'time' => $timestamp ? date('H:i:s', $timestamp) : '-',
            'username' => $targetName !== '' ? $targetName : ($actorUsername !== '' ? $actorUsername : 'Système'),
            'profile' => $profileName !== '' ? $profileName : '-',
            'address' => '-',
            'mac' => '-',
            'action' => $summary !== '' ? $summary : operationTypeLabel($operationType),
            'status' => match ($operationType) {
                'user_create' => 'Ajout user',
                'user_update' => 'Maj user',
                'user_delete' => 'Suppression user',
                'user_notice_record' => 'Expiration',
                'user_remove_record' => 'Suppression quota',
                'session_disconnect' => 'Déconnexion',
                default => 'Operation',
            },
            'server' => (string)($deviceMaps['device_label_by_id'][$deviceId] ?? ($deviceId !== '' ? $deviceId : '-')),
            'source_key' => 'operation',
        ];
    }

    return $rows;
}

function dedupeMikrotikUserLogRows(array $rows): array
{
    $normalizeKeyPart = static function (?string $value): string {
        $value = preg_replace('/\s+/', ' ', trim((string)$value)) ?? '';
        return mb_strtolower($value);
    };

    $deduped = [];
    $seenKeys = [];

    foreach ($rows as $row) {
        $key = implode('|', [
            $normalizeKeyPart((string)($row['date'] ?? '')),
            $normalizeKeyPart((string)($row['time'] ?? '')),
            $normalizeKeyPart((string)($row['username'] ?? '')),
            $normalizeKeyPart((string)($row['action'] ?? '')),
            $normalizeKeyPart((string)($row['status'] ?? '')),
            $normalizeKeyPart((string)($row['address'] ?? '')),
            $normalizeKeyPart((string)($row['server'] ?? '')),
        ]);

        if (isset($seenKeys[$key])) {
            continue;
        }

        $seenKeys[$key] = true;
        $deduped[] = $row;
    }

    return $deduped;
}

function sortUserLogRows(array $rows): array
{
    usort($rows, static function (array $a, array $b): int {
        $left = (($a['date'] ?? '-') !== '-' ? $a['date'] : '0000-00-00') . ' ' . ($a['time'] ?? '');
        $right = (($b['date'] ?? '-') !== '-' ? $b['date'] : '0000-00-00') . ' ' . ($b['time'] ?? '');
        return strcmp($right, $left);
    });

    return $rows;
}

function filterUserLogRowsBySource(array $rows, string $selectedSource): array
{
    if ($selectedSource === 'all') {
        return $rows;
    }

    return array_values(array_filter($rows, static function (array $row) use ($selectedSource): bool {
        return (string)($row['source_key'] ?? '') === $selectedSource;
    }));
}

function loadUserLogsViewData(PDO $pdo, array $filters = []): array
{
    $context = buildUserLogsContext();
    $store = loadDeviceStore();
    $deviceMaps = buildUserLogsDeviceMaps($store);
    $preparedFilters = buildUserLogsFilters($filters);

    $mikrotikLogs = loadMikrotikHotspotEvents($context);
    $radiusSessions = loadRadiusSessionRows($pdo, $context, $preparedFilters, $deviceMaps);
    $radiusAuth = loadRadiusAuthRows($pdo, $context, $preparedFilters);
    $operations = loadOperationHistoryRows($pdo, $context, $preparedFilters, $deviceMaps);
    $recharges = loadRechargeHistoryRows($pdo, $preparedFilters, $deviceMaps);
    $mikrotikProfileMap = buildMikrotikProfileMap($context);

    $combinedRows = array_merge(
        [],
        normalizeMikrotikLogRows($mikrotikLogs['rows'], $deviceMaps['active_device_label'], $mikrotikProfileMap, $context['is_mikrotik']),
        normalizeRadiusSessionRows($radiusSessions['rows'], $radiusSessions['first_login_map'], $deviceMaps),
        normalizeRadiusAuthRows($radiusAuth['rows'], $deviceMaps),
        normalizeRechargeRows($recharges['rows'], $deviceMaps),
        normalizeOperationRows($operations['rows'], $deviceMaps)
    );

    if ($context['is_mikrotik']) {
        $combinedRows = dedupeMikrotikUserLogRows($combinedRows);
    }

    $combinedRows = sortUserLogRows($combinedRows);
    $combinedRows = filterUserLogRowsBySource($combinedRows, $preparedFilters['source']);

    return [
        'context' => $context,
        'view' => [
            'page_title' => $context['is_radius_like'] && !$context['is_mikrotik'] ? 'Sessions / Logs' : 'User Logs',
            'active_device_label' => $deviceMaps['active_device_label'],
            'source_filter_options' => [
                'all' => 'Toutes sources',
                'details_event' => 'Détails événements',
                'radius_session' => 'RADIUS sessions',
                'radius_auth' => 'RADIUS auth',
                'recharge' => 'Recharges',
                'operation' => 'Opérations',
            ],
            'month_names' => [
                1 => 'Janvier',
                2 => 'Fevrier',
                3 => 'Mars',
                4 => 'Avril',
                5 => 'Mai',
                6 => 'Juin',
                7 => 'Juillet',
                8 => 'Aout',
                9 => 'Septembre',
                10 => 'Octobre',
                11 => 'Novembre',
                12 => 'Decembre',
            ],
        ],
        'filters' => [
            'day' => $preparedFilters['day'],
            'month' => $preparedFilters['month'],
            'year' => $preparedFilters['year'],
            'source' => $preparedFilters['source'],
        ],
        'rows' => $combinedRows,
        'errors' => [
            'hotspot' => $mikrotikLogs['error'],
            'radius_sessions' => $radiusSessions['error'],
            'operations' => $operations['error'],
            'recharges' => $recharges['error'],
            'radius_auth' => $radiusAuth['error'],
        ],
    ];
}
