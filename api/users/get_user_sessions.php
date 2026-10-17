<?php
require '../../config/db.php';
require_once '../../includes/device_manager.php';
require_once '../../includes/mikrotik_backend.php';
require_once '../../includes/opnsense_shaper.php';
require_once '../../includes/radius_credit_runtime.php';
require_once '../../includes/user_schema.php';
require_once '../../includes/mikrotik_hotspot_log_collector.php';

session_start();

function parse_date_or_null(?string $value): ?DateTimeImmutable
{
    $raw = trim((string)$value);
    if ($raw === '') {
        return null;
    }

    if (preg_match('/^[0-9]+(?:\.[0-9]+)?$/', $raw)) {
        $seconds = (int)floor((float)$raw);
        if ($seconds > 0) {
            try {
                $date = new DateTimeImmutable('@' . $seconds);
                return $date->setTimezone(new DateTimeZone('UTC'));
            } catch (Throwable $e) {
            }
        }
    }

    try {
        return new DateTimeImmutable($raw, new DateTimeZone('UTC'));
    } catch (Throwable $e) {
        return null;
    }
}

function effective_session_seconds(array $row): int
{
    $sessionSeconds = (int)($row['acctsessiontime'] ?? 0);
    if ($sessionSeconds > 0) {
        return $sessionSeconds;
    }

    $start = parse_date_or_null((string)($row['acctstarttime'] ?? ''));
    if (!$start instanceof DateTimeImmutable) {
        return 0;
    }

    $stop = parse_date_or_null((string)($row['acctstoptime'] ?? ''));
    $end = $stop instanceof DateTimeImmutable ? $stop : new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $seconds = $end->getTimestamp() - $start->getTimestamp();

    return max(0, $seconds);
}

function format_duration_label(int $seconds): string
{
    if ($seconds <= 0) {
        return '0s';
    }

    $days = intdiv($seconds, 86400);
    $remainder = $seconds % 86400;
    $hours = intdiv($remainder, 3600);
    $remainder %= 3600;
    $minutes = intdiv($remainder, 60);
    $secs = $remainder % 60;

    $parts = [];
    if ($days > 0) {
        $parts[] = $days . 'j';
    }
    if ($hours > 0) {
        $parts[] = $hours . 'h';
    }
    if ($minutes > 0) {
        $parts[] = $minutes . 'm';
    }
    if ($secs > 0 || $parts === []) {
        $parts[] = $secs . 's';
    }

    return implode(' ', $parts);
}

function get_string_or_null(string $key): ?string
{
    $value = trim((string)($_GET[$key] ?? ''));
    return $value === '' ? null : $value;
}

function get_int_or_null(string $key): ?int
{
    $raw = trim((string)($_GET[$key] ?? ''));
    if ($raw === '' || !preg_match('/^[0-9]+$/', $raw)) {
        return null;
    }

    return (int)$raw;
}

function get_nas_type_by_id(PDO $pdo, ?int $nasId): ?string
{
    if ($nasId === null || $nasId <= 0) {
        return null;
    }

    $stmt = $pdo->prepare("
        SELECT LOWER(COALESCE(type, '')) AS nas_type
        FROM nas
        WHERE id = ?
        LIMIT 1
    ");
    $stmt->execute([$nasId]);
    $value = trim((string)$stmt->fetchColumn());

    return $value !== '' ? $value : null;
}

function get_first_non_empty_string(array $row, array $keys): string
{
    foreach ($keys as $key) {
        if (!array_key_exists($key, $row)) {
            continue;
        }

        $value = trim((string)$row[$key]);
        if ($value !== '') {
            return $value;
        }
    }

    return '';
}

function normalize_device_host_label(array $device): string
{
    $host = trim((string)($device['host'] ?? ''));
    if ($host === '') {
        return '';
    }

    $parsedHost = parse_url($host, PHP_URL_HOST);
    if (is_string($parsedHost) && trim($parsedHost) !== '') {
        return trim($parsedHost);
    }

    return preg_replace('#^https?://#i', '', $host) ?? $host;
}

function extract_opnsense_session_start(array $session): ?string
{
    $candidateKeys = [
        'startTime',
        'start',
        'sessionStart',
        'sessionStartTime',
        'created',
        'createdTime',
        'loginTime',
    ];

    foreach ($candidateKeys as $key) {
        $value = trim((string)($session[$key] ?? ''));
        if ($value === '') {
            continue;
        }

        $date = parse_date_or_null($value);

        return $date instanceof DateTimeImmutable ? $date->format('Y-m-d H:i:s') : $value;
    }

    return null;
}

if (!isset($_SESSION['logged_in'])) {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'message' => 'Votre session a expire. Reconnectez-vous puis reessayez.',
    ]);
    exit;
}

/* =========================
   INPUT
========================= */
$username = get_string_or_null('username');
$nasId = get_int_or_null('nas_id');
$deviceId = get_string_or_null('device_id');

if ($username === null) {
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'message' => 'Utilisateur manquant.',
    ]);
    exit;
}

try {
    if ($deviceId === null) {
        throw new RuntimeException('Device requis.');
    }
    $deviceStore = loadDeviceStore();
    $activeDevice = requireAccessibleDevice($deviceStore, $deviceId);
    $nasType = get_nas_type_by_id($pdo, $nasId);
    $useMikrotikSessions = $nasType === 'mikrotik'
        || ($nasType === null && ($activeDevice['type'] ?? '') === 'mikrotik');

    if ($useMikrotikSessions) {
        $users = getMikrotikHotspotUsers(0, $activeDevice);
        $matched = null;

        foreach ($users as $user) {
            if ((string)($user['username'] ?? '') === $username) {
                $matched = $user;
                break;
            }
        }

        if ($matched === null) {
            echo json_encode([
                'success' => true,
                'online' => false,
                'ip' => null,
                'mac' => null,
                'nas' => null,
                'observation_mode' => 'active',
                'summary_data_mb' => 0,
                'summary_data_display' => '-',
                'summary_duration_display' => '-',
                'total_data_mb' => 0,
                'total_session_seconds' => 0,
                'total_session_label' => '-',
                'imported_session_total_seconds' => 0,
                'imported_data_consumed_bytes' => 0,
                'sessions' => [],
            ]);
            exit;
        }

        $activeSessionBytes = (float)($matched['bytes_in'] ?? 0) + (float)($matched['bytes_out'] ?? 0);
        $activeSessionDataMb = round($activeSessionBytes / 1024 / 1024, 2);
        $displayTotalDataBytes = max(0, (int)round((float)($matched['user_bytes_total'] ?? 0)));
        $displayTotalDataMb = round($displayTotalDataBytes / 1024 / 1024, 2);
        $summaryDataLabel = formatMikrotikBytesLimit($displayTotalDataBytes);
        $activeUptime = trim((string)($matched['active_uptime'] ?? ''));
        $activeUptimeLabel = $activeUptime !== '' ? $activeUptime : '-';
        $displayTotalSessionSeconds = max(0, (int)($matched['user_session_time_seconds'] ?? 0));
        $summarySessionLabel = $displayTotalSessionSeconds > 0
            ? format_duration_label($displayTotalSessionSeconds)
            : '-';
        $uptimeSeconds = $activeUptime !== '' ? parseRouterosIntervalToSeconds($activeUptime) : 0;
        $sessionStart = $uptimeSeconds > 0
            ? gmdate('Y-m-d H:i:s', time() - $uptimeSeconds)
            : '-';

        $sessionRows = [];
        if (!empty($matched['online'])) {
            $sessionRows[] = [
                'start' => $sessionStart,
                'stop' => 'En cours',
                'duration' => $activeUptimeLabel,
                'duration_seconds' => $uptimeSeconds,
                'data_mb' => $activeSessionDataMb,
                'ip' => (string)($matched['active_address'] ?? ''),
                'mac' => (string)($matched['active_mac'] ?? ''),
                'nas' => (string)($matched['active_server'] ?? ''),
                'source' => 'MikroTik',
            ];
        }

        // Les sessions precedentes sont disponibles uniquement si le routeur
        // a alimente radacct via le comptage RADIUS. Elles restent distinguees
        // de l observation courante MikroTik.
        $historySql = "
            SELECT acctstarttime, acctstoptime, acctsessiontime,
                   acctinputoctets, acctoutputoctets, framedipaddress,
                   callingstationid, nasipaddress
            FROM radacct
            WHERE username = ?
        ";
        $historyParams = [$username];
        $historyNasAddress = trim((string)($activeDevice['host'] ?? ($activeDevice['ip'] ?? '')));
        if ($historyNasAddress !== '') {
            $historySql .= " AND nasipaddress = ?";
            $historyParams[] = $historyNasAddress;
        }
        $historySql .= " ORDER BY acctstarttime DESC LIMIT 50";
        $historyStmt = $pdo->prepare($historySql);
        $historyStmt->execute($historyParams);
        $radacctHistoryRows = $historyStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($radacctHistoryRows as $historyRow) {
            $startValue = (string)($historyRow['acctstarttime'] ?? '');
            $stopValue = (string)($historyRow['acctstoptime'] ?? '');
            $durationSeconds = effective_session_seconds($historyRow);
            $dataMb = round(((float)($historyRow['acctinputoctets'] ?? 0) + (float)($historyRow['acctoutputoctets'] ?? 0)) / 1024 / 1024, 2);
            $sessionRows[] = [
                'start' => $startValue,
                'stop' => $stopValue !== '' ? $stopValue : 'En cours (RADIUS)',
                'duration' => format_duration_label($durationSeconds),
                'duration_seconds' => $durationSeconds,
                'data_mb' => $dataMb,
                'ip' => (string)($historyRow['framedipaddress'] ?? ''),
                'mac' => (string)($historyRow['callingstationid'] ?? ''),
                'nas' => (string)($historyRow['nasipaddress'] ?? ''),
                'source' => 'RADIUS / radacct',
            ];
        }

        /* Pas de RADIUS sur ce device (mikrotik_local) : on reconstruit l'historique a partir du
           journal Hotspot deja archive (mikrotik_hotspot_log_events), sans nouvelle collecte. */
        $historySourceLabel = 'RADIUS / radacct';
        if ($radacctHistoryRows === []) {
            $logDeviceId = trim((string)(($activeDevice['id'] ?? '') ?: ($activeDevice['host'] ?? '')));
            $reconstructed = $logDeviceId !== '' ? mikrotikHotspotLogReconstructSessions($pdo, $logDeviceId, $username, 50) : [];
            if ($reconstructed !== []) {
                $historySourceLabel = 'Journal Hotspot (reconstruit)';
                foreach ($reconstructed as $session) {
                    $startDate = parse_date_or_null((string)($session['start'] ?? ''));
                    $stopDate = parse_date_or_null((string)($session['stop'] ?? ''));
                    $durationSeconds = ($startDate instanceof DateTimeImmutable && $stopDate instanceof DateTimeImmutable)
                        ? max(0, $stopDate->getTimestamp() - $startDate->getTimestamp())
                        : 0;
                    $sessionRows[] = [
                        'start' => (string)($session['start'] ?? ''),
                        'stop' => (string)($session['stop'] ?? '') . (!empty($session['approx_stop']) ? ' (approx.)' : ''),
                        'duration' => format_duration_label($durationSeconds),
                        'duration_seconds' => $durationSeconds,
                        'data_mb' => null,
                        'ip' => (string)($session['ip'] ?? ''),
                        'mac' => '',
                        'nas' => normalize_device_host_label($activeDevice),
                        'source' => 'Journal Hotspot (reconstruit)',
                    ];
                }
            }
        }

        echo json_encode([
            'success' => true,
            'online' => !empty($matched['online']),
            'ip' => (string)($matched['active_address'] ?? ''),
            'mac' => (string)($matched['active_mac'] ?? ''),
            'nas' => (string)($matched['active_server'] ?? ''),
            'observation_mode' => 'active',
            'history_source' => $historySourceLabel,
            'summary_data_mb' => $displayTotalDataMb,
            'summary_data_display' => $summaryDataLabel,
            'summary_duration_display' => $summarySessionLabel,
            'total_data_mb' => $displayTotalDataMb,
            'total_session_seconds' => $displayTotalSessionSeconds,
            'total_session_label' => $summarySessionLabel,
            'current_session_uptime_label' => $activeUptimeLabel,
            'imported_session_total_seconds' => 0,
            'imported_data_consumed_bytes' => 0,
            'sessions' => $sessionRows,
        ]);
        exit;
    }

    ensureUsersExtendedSchema($pdo);
    ensureUserCounterBaselinesSchema($pdo);

    /* =========================
       1. GET SESSIONS
    ========================= */
    $stmt = $pdo->prepare("
        SELECT 
            acctstarttime,
            acctstoptime,
            acctsessiontime,
            acctinputoctets,
            acctoutputoctets,
            framedipaddress,
            callingstationid,
            nasipaddress
        FROM radacct
        WHERE username = ?
          AND nasipaddress = ?
        ORDER BY acctstarttime DESC
        LIMIT 50
    ");

    $stmt->execute([$username, extractDeviceAddress((string)($activeDevice['host'] ?? ($activeDevice['ip'] ?? '')))]);
    $sessions = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $result = [];
    $online = false;
    $current_ip = null;
    $current_mac = null;
    $current_nas = null;
    $hasOpenAccountingSession = false;
    $totalDataBytes = 0;
    $totalSessionSeconds = 0;
    $observationMode = 'cumulative';

    $importedSessionTotalSeconds = 0;
    $importedDataConsumedBytes = 0;
    $userBaselineStmt = $pdo->prepare("
        SELECT imported_session_total_seconds, imported_data_consumed_bytes
        FROM users
        WHERE username = ?
        LIMIT 1
    ");
    $userBaselineStmt->execute([$username]);
    $userBaseline = $userBaselineStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $importedSessionTotalSeconds = max(0, (int)($userBaseline['imported_session_total_seconds'] ?? 0));
    $importedDataConsumedBytes = max(0, (int)($userBaseline['imported_data_consumed_bytes'] ?? 0));
    $activeDeviceId = trim((string)($activeDevice['id'] ?? ''));
    $baselineScopeId = resolveUserCounterBaselineScopeId('radius', $activeDeviceId);
    $resetBaseline = $baselineScopeId !== ''
        ? loadUserCounterBaseline($pdo, $baselineScopeId, $username)
        : [
            'imported_session_total_seconds' => 0,
            'imported_data_consumed_bytes' => 0,
        ];
    $resetSessionTotalSeconds = max(0, (int)($resetBaseline['imported_session_total_seconds'] ?? 0));
    $resetDataConsumedBytes = max(0, (int)($resetBaseline['imported_data_consumed_bytes'] ?? 0));

    foreach ($sessions as $s) {

        /* =========================
           DATA CALCUL
        ========================= */
        $total_bytes = ($s['acctinputoctets'] ?? 0) + ($s['acctoutputoctets'] ?? 0);
        $total_mb = round($total_bytes / 1024 / 1024, 2);
        $durationSeconds = effective_session_seconds($s);
        $totalDataBytes += (int)$total_bytes;
        $totalSessionSeconds += $durationSeconds;

        /* =========================
           ONLINE DETECTION
        ========================= */
        if (empty($s['acctstoptime'])) {
            $hasOpenAccountingSession = true;
            $online = true;
            $current_ip = $s['framedipaddress'];
            $current_mac = $s['callingstationid'];
            $current_nas = $s['nasipaddress'];
        }

        $result[] = [
            'start' => $s['acctstarttime'],
            'stop' => $s['acctstoptime'],
            'duration' => format_duration_label($durationSeconds),
            'duration_seconds' => $durationSeconds,
            'data_mb' => $total_mb,
            'ip' => $s['framedipaddress'],
            'mac' => $s['callingstationid'],
            'nas' => $s['nasipaddress'],
        ];
    }

    $useOpnsenseHybrid = $nasType === 'opnsense'
        || ($nasType === null && ($activeDevice['type'] ?? '') === 'opnsense');

    if ($useOpnsenseHybrid && ($activeDevice['type'] ?? '') === 'opnsense') {
        try {
            $activeSession = findCaptivePortalSessionByUsername($activeDevice, $username);
        } catch (Throwable $e) {
            $activeSession = null;
        }

        if (is_array($activeSession) && $activeSession !== []) {
            $activeIp = get_first_non_empty_string($activeSession, ['ipAddress', 'ip', 'framedipaddress']);
            $activeMac = get_first_non_empty_string($activeSession, ['macAddress', 'mac', 'callingStationId', 'callingstationid']);
            $activeNas = get_first_non_empty_string($activeSession, ['nasipaddress', 'interface', 'interfaces', 'zone']);
            if ($activeNas === '') {
                $activeNas = normalize_device_host_label($activeDevice);
            }

            $online = true;
            if ($current_ip === null || trim((string)$current_ip) === '') {
                $current_ip = $activeIp !== '' ? $activeIp : null;
            }
            if ($current_mac === null || trim((string)$current_mac) === '') {
                $current_mac = $activeMac !== '' ? $activeMac : null;
            }
            if ($current_nas === null || trim((string)$current_nas) === '') {
                $current_nas = $activeNas !== '' ? $activeNas : null;
            }

            if (!$hasOpenAccountingSession) {
                $activeStart = extract_opnsense_session_start($activeSession);
                $activeDurationSeconds = 0;
                $activeDurationLabel = '-';

                $activeStartDate = $activeStart !== null ? parse_date_or_null($activeStart) : null;
                if ($activeStartDate instanceof DateTimeImmutable) {
                    $activeDurationSeconds = max(
                        0,
                        (new DateTimeImmutable('now', new DateTimeZone('UTC')))->getTimestamp() - $activeStartDate->getTimestamp()
                    );
                    $activeDurationLabel = format_duration_label($activeDurationSeconds);
                    $totalSessionSeconds += $activeDurationSeconds;
                }

                array_unshift($result, [
                    'start' => $activeStart ?? '',
                    'stop' => '',
                    'duration' => $activeDurationLabel,
                    'duration_seconds' => $activeDurationSeconds,
                    'data_mb' => null,
                    'ip' => $activeIp,
                    'mac' => $activeMac,
                    'nas' => $activeNas,
                ]);
            }

            $observationMode = 'hybrid';
        }
    }

    $cycleSessionSeconds = max(0, $totalSessionSeconds - $resetSessionTotalSeconds);
    $cycleDataBytes = max(0, $totalDataBytes - $resetDataConsumedBytes);
    $displayTotalSessionSeconds = $importedSessionTotalSeconds + $cycleSessionSeconds;
    $displayTotalDataBytes = $importedDataConsumedBytes + $cycleDataBytes;
    $displayTotalDataMb = round($displayTotalDataBytes / 1024 / 1024, 2);
    $totalSessionLabel = format_duration_label($displayTotalSessionSeconds);

    /* =========================
       RESPONSE
    ========================= */
    echo json_encode([
        'success' => true,
        'online' => $online,
        'ip' => $current_ip,
        'mac' => $current_mac,
        'nas' => $current_nas,
        'observation_mode' => $observationMode,
        'summary_data_mb' => $displayTotalDataMb,
        'summary_data_display' => formatMikrotikBytesLimit($displayTotalDataBytes),
        'summary_duration_display' => $totalSessionLabel,
        'total_data_mb' => $displayTotalDataMb,
        'total_session_seconds' => $displayTotalSessionSeconds,
        'total_session_label' => $totalSessionLabel,
        'imported_session_total_seconds' => $importedSessionTotalSeconds,
        'imported_data_consumed_bytes' => $importedDataConsumedBytes,
        'sessions' => $result,
    ]);

} catch (Exception $e) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'message' => trim((string)$e->getMessage()) !== '' ? trim((string)$e->getMessage()) : 'Chargement des sessions impossible.',
    ]);
}
