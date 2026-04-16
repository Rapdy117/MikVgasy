<?php
require '../../config/db.php';
require_once '../../includes/device_manager.php';
require_once '../../includes/mikrotik_backend.php';

session_start();

function parse_date_or_null(?string $value): ?DateTimeImmutable
{
    $raw = trim((string)$value);
    if ($raw === '') {
        return null;
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

if ($username === null) {
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'message' => 'Utilisateur manquant.',
    ]);
    exit;
}

try {
    $activeDevice = getActiveDeviceContext()['device'] ?? null;

    if (($activeDevice['type'] ?? '') === 'mikrotik') {
        $users = getMikrotikHotspotUsers(500);
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
                'sessions' => [],
            ]);
            exit;
        }

        $totalBytes = (float)($matched['bytes_in'] ?? 0) + (float)($matched['bytes_out'] ?? 0);
        $summaryDataMb = round($totalBytes / 1024 / 1024, 2);
        $summaryDataLabel = $summaryDataMb > 0 ? number_format($summaryDataMb, 2, '.', '') . ' MB' : '-';
        $activeUptime = trim((string)($matched['active_uptime'] ?? ''));
        $summarySessionLabel = $activeUptime !== '' ? $activeUptime : '-';
        $uptimeSeconds = $activeUptime !== '' ? parseRouterosIntervalToSeconds($activeUptime) : 0;
        $sessionStart = $uptimeSeconds > 0
            ? gmdate('Y-m-d H:i:s', time() - $uptimeSeconds)
            : '-';

        $sessionRows = [];
        if (!empty($matched['online'])) {
            $sessionRows[] = [
                'start' => $sessionStart,
                'stop' => 'En cours',
                'duration' => $summarySessionLabel,
                'duration_seconds' => 0,
                'data_mb' => $summaryDataMb,
                'ip' => (string)($matched['active_address'] ?? ''),
                'mac' => (string)($matched['active_mac'] ?? ''),
                'nas' => (string)($matched['active_server'] ?? ''),
            ];
        }

        echo json_encode([
            'success' => true,
            'online' => !empty($matched['online']),
            'ip' => (string)($matched['active_address'] ?? ''),
            'mac' => (string)($matched['active_mac'] ?? ''),
            'nas' => (string)($matched['active_server'] ?? ''),
            'observation_mode' => 'active',
            'summary_data_mb' => $summaryDataMb,
            'summary_data_display' => $summaryDataLabel,
            'summary_duration_display' => $summarySessionLabel,
            'total_data_mb' => $summaryDataMb,
            'total_session_seconds' => 0,
            'total_session_label' => $summarySessionLabel,
            'sessions' => $sessionRows,
        ]);
        exit;
    }

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
        ORDER BY acctstarttime DESC
        LIMIT 50
    ");

    $stmt->execute([$username]);
    $sessions = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $result = [];
    $online = false;
    $current_ip = null;
    $current_mac = null;
    $current_nas = null;
    $totalDataMb = 0.0;
    $totalSessionSeconds = 0;

    foreach ($sessions as $s) {

        /* =========================
           DATA CALCUL
        ========================= */
        $total_bytes = ($s['acctinputoctets'] ?? 0) + ($s['acctoutputoctets'] ?? 0);
        $total_mb = round($total_bytes / 1024 / 1024, 2);
        $durationSeconds = effective_session_seconds($s);
        $totalDataMb += $total_mb;
        $totalSessionSeconds += $durationSeconds;

        /* =========================
           ONLINE DETECTION
        ========================= */
        if (empty($s['acctstoptime'])) {
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

    $totalDataMb = round($totalDataMb, 2);
    $totalSessionLabel = format_duration_label($totalSessionSeconds);

    /* =========================
       RESPONSE
    ========================= */
    echo json_encode([
        'success' => true,
        'online' => $online,
        'ip' => $current_ip,
        'mac' => $current_mac,
        'nas' => $current_nas,
        'observation_mode' => 'cumulative',
        'summary_data_mb' => $totalDataMb,
        'summary_data_display' => $totalDataMb > 0 ? number_format($totalDataMb, 2, '.', '') . ' MB' : '-',
        'summary_duration_display' => $totalSessionLabel,
        'total_data_mb' => $totalDataMb,
        'total_session_seconds' => $totalSessionSeconds,
        'total_session_label' => $totalSessionLabel,
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
