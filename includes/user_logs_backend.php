<?php

require_once __DIR__ . '/device_manager.php';
require_once __DIR__ . '/app_context.php';
require_once __DIR__ . '/mikrotik_backend.php';
require_once __DIR__ . '/mikrotik_hotspot_log_collector.php';
require_once __DIR__ . '/hotspot_mac_labels.php';
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

/**
 * Contexte applicatif + device actif (aligné sur getActiveDeviceRecord, pas sur un device partiel seul).
 */
function buildUserLogsContext(?array $selectedDevice = null): array
{
    $context = [
        'app' => buildAppContext(),
    ];

    $store = loadDeviceStore();
    $activeDevice = is_array($selectedDevice) ? $selectedDevice : getActiveDeviceRecord($store);
    if (!is_array($activeDevice) || trim((string)($activeDevice['type'] ?? '')) === '') {
        $activeDeviceType = 'radius';
    } else {
        $activeDeviceType = normalizeDeviceType((string)$activeDevice['type']);
    }
    $context['is_mikrotik'] = is_array($activeDevice) && $activeDeviceType === 'mikrotik';
    $context['is_radius_like'] = is_array($activeDevice) && in_array($activeDeviceType, ['opnsense', 'radius'], true);

    $context['device'] = $context['app']['device'] ?? null;
    if (is_array($selectedDevice)) {
        $context['device'] = $selectedDevice;
    }
    $context['device_type'] = strtolower(trim((string)($context['device']['type'] ?? '')));

    return $context;
}

function buildUserLogsDeviceMaps(array $store, string $requestedDeviceId = ''): array
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

    $allowedDeviceIds = array_keys($deviceLabelById);
    $queryDeviceId = $activeDeviceId;
    $requestedDeviceId = trim($requestedDeviceId);
    if ($requestedDeviceId !== '' && $requestedDeviceId !== 'active' && in_array($requestedDeviceId, $allowedDeviceIds, true)) {
        $queryDeviceId = $requestedDeviceId;
    }
    $selectedDevice = null;
    foreach (($store['devices'] ?? []) as $device) {
        if ((string)($device['id'] ?? '') === $queryDeviceId) {
            $selectedDevice = $device;
            break;
        }
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
        'selected_device' => $selectedDevice,
        'allowed_device_ids' => $allowedDeviceIds,
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

/* Lit l'archive locale (mikrotik_hotspot_log_events) au lieu du buffer live /log de RouterOS :
   ce buffer est volatile (memory-lines=1000, tous topics confondus) et se reecrit en quelques
   heures sur ce reseau - on perdait l'historique au-dela. L'archive est alimentee par un cron
   toutes les 2h (scripts/monitor_mikrotik_hotspot_logs.php), retention 48h. */
function loadMikrotikHotspotEvents(PDO $pdo, array $context): array
{
    $events = [];
    $error = null;

    if (!$context['is_mikrotik']) {
        return ['rows' => $events, 'error' => $error];
    }

    $deviceId = trim((string)(($context['device']['id'] ?? '') ?: ($context['device']['host'] ?? '')));
    if ($deviceId === '') {
        return ['rows' => $events, 'error' => $error];
    }

    try {
        /* 30000 couvre largement la fenetre de retention de 48h (mesure : ~521 lignes/heure,
           ~25000 lignes max attendues) - l'ancienne limite de 2000 ne couvrait que ~4h, pas les
           48h prevues. */
        foreach (mikrotikHotspotLogFetch($pdo, $deviceId, 30000) as $row) {
            $events[] = [
                'time' => (string)($row['logged_at'] ?? ''),
                'user' => (string)($row['username'] ?? ''),
                'address' => (string)($row['address'] ?? ''),
                'action' => (string)($row['action'] ?? ''),
                'status' => (string)($row['status'] ?? ''),
                'raw_message' => (string)($row['raw_message'] ?? ''),
            ];
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }

    /* Comble l'ecart entre le dernier passage cron (toutes les 2h) et maintenant : lecture live du
       routeur, seulement ce qui est plus recent que la derniere ligne archivee. Echec silencieux
       (routeur injoignable) : on garde l'archive, pas d'erreur bloquante pour un complement. */
    try {
        $lastArchived = mikrotikHotspotLogLastTimestamp($pdo, $deviceId);
        $liveGapEvents = mikrotikHotspotLogFetchLiveGap($context['device'] ?? [], $lastArchived);
        foreach ($liveGapEvents as $liveEvent) {
            $events[] = $liveEvent;
        }
    } catch (Throwable $e) {
        // non bloquant : l'archive locale reste affichee telle quelle
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


function buildMikrotikProfileMap(array $context): array
{
    $map = [];

    if (!$context['is_mikrotik']) {
        return $map;
    }

    try {
        /* 0 = pas de troncature : getMikrotikHotspotUsers() lit deja TOUS les comptes depuis
           RouterOS quel que soit $limit (il ne fait qu'un array_slice en sortie), donc relever
           ce chiffre ne coute rien de plus - juste eviter de perdre des profils au-dela de 100. */
        foreach (getMikrotikHotspotUsers(0, $context['device'] ?? null) as $u) {
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

function normalizeMikrotikLogRows(array $hotspotEvents, string $activeDeviceLabel, array $profileMap, bool $isMikrotik, array $macLabels = []): array
{
    $rows = [];
    $macPattern = '/^(?:[0-9A-Fa-f]{2}:){5}[0-9A-Fa-f]{2}$/';

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
        $mac = '-';
        /* Les logins par MAC (hotspot "login by mac") portent la MAC en guise de nom d'utilisateur :
           on l'affiche avec son libellé enregistre (hotspot_mac_labels), la MAC brute en secours. */
        if (preg_match($macPattern, $username)) {
            $mac = strtoupper($username);
            $username = $macLabels[$mac] ?? $mac;
        }

        $profile = '-';
        if ($isMikrotik && $username !== '-' && $username !== '') {
            $profile = $profileMap[$username] ?? ($mac !== '-' ? ($profileMap[$mac] ?? '-') : '-');
        }

        $rows[] = [
            'date' => $hotspotDate !== '' ? $hotspotDate : '-',
            'time' => $hotspotTime !== '' ? $hotspotTime : '-',
            'username' => $username,
            'profile' => $profile,
            'address' => (string)($log['address'] ?? '-'),
            'mac' => $mac,
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
    $preparedFilters = buildUserLogsFilters($filters);
    $store = loadDeviceStore();
    $deviceMaps = buildUserLogsDeviceMaps($store, (string)($filters['device_id'] ?? ''));
    $context = buildUserLogsContext($deviceMaps['selected_device'] ?? null);

    $mikrotikLogs = loadMikrotikHotspotEvents($pdo, $context);
    $radiusSessions = loadRadiusSessionRows($pdo, $context, $preparedFilters, $deviceMaps);
    $mikrotikProfileMap = buildMikrotikProfileMap($context);
    $macLabels = [];
    if ($context['is_mikrotik']) {
        $macDeviceId = trim((string)(($context['device']['id'] ?? '') ?: ($context['device']['host'] ?? '')));
        if ($macDeviceId !== '') {
            $macLabels = getHotspotMacLabels($pdo, $macDeviceId);
        }
    }

    $combinedRows = array_merge(
        [],
        normalizeMikrotikLogRows($mikrotikLogs['rows'], formatUserLogsDeviceLabel($context['device'] ?? []), $mikrotikProfileMap, $context['is_mikrotik'], $macLabels),
        normalizeRadiusSessionRows($radiusSessions['rows'], $radiusSessions['first_login_map'], $deviceMaps)
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
            'selected_device_id' => (string)($deviceMaps['query_device_id'] ?? ''),
            'device_options' => $deviceMaps['device_label_by_id'],
            'source_filter_options' => [
                'all' => 'Toutes sources',
                'details_event' => 'Détails événements',
                'radius_session' => 'RADIUS sessions',
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
            'device_id' => (string)($deviceMaps['query_device_id'] ?? ''),
        ],
        'rows' => $combinedRows,
        'errors' => [
            'hotspot' => $mikrotikLogs['error'],
            'radius_sessions' => $radiusSessions['error'],
        ],
    ];
}
