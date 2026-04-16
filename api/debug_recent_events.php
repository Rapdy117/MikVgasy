<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/device_manager.php';
require_once __DIR__ . '/../includes/mikrotik_backend.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

function matchHotspotPattern(string $message): ?string
{
    $patterns = [
        'login failed' => '/login\s+failed/i',
        'invalid username or password' => '/invalid\s+username\s+or\s+password/i',
        'trying to log in' => '/trying\s+to\s+log\s+in/i',
        'log in by' => '/log\s+in\s+by/i',
        'logged in' => '/logged\s+in/i',
        'logged out' => '/logged\s+out/i',
        'logout' => '/\blogout\b/i',
        'keepalive timeout' => '/keepalive\s+timeout/i',
        'idle timeout' => '/idle\s+timeout/i',
        'session timeout' => '/session\s+timeout/i',
        'user request' => '/user\s+request/i',
        'no more sessions are allowed' => '/no\s+more\s+sessions\s+are\s+allowed/i',
        'disconnected' => '/\bdisconnected\b/i',
    ];

    foreach ($patterns as $label => $regex) {
        if (preg_match($regex, $message)) {
            return $label;
        }
    }

    return null;
}

function parseEvent(string $timeLabel, string $message, string $pattern): array
{
    $clean = preg_replace('/^\-\>\:\s*/', '', $message);
    $clean = trim((string)$clean);

    $user = '-';
    $action = $clean;

    if (preg_match('/^(?<user>[^:]+?)\s*:\s*(?<action>.+)$/', $clean, $matches)) {
        $user = trim((string)$matches['user']);
        $action = trim((string)$matches['action']);
    }

    $action = preg_replace('/\s+/', ' ', (string)$action);

    return [
        'time' => $timeLabel !== '' ? $timeLabel : '--',
        'user' => $user !== '' ? $user : '-',
        'action' => $action !== '' ? $action : '-',
        'pattern_matched' => $pattern,
    ];
}

try {
    $device = requireActiveDevice();
    $deviceType = strtolower(trim((string)($device['type'] ?? '')));

    if ($deviceType !== 'mikrotik') {
        http_response_code(400);
        echo json_encode([
            'error' => 'Le device actif n\'est pas MikroTik.',
            'device_type' => $deviceType,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    $api = connectToMikrotikApiByDevice($device);
    try {
        ensureMikrotikHotspotLogging($api);

        $rows = $api->comm('/log/print', [
            '.proplist' => 'time,topics,message',
            '.limit' => '200',
        ]);
    } finally {
        $api->disconnect();
    }

    $rawLogs = [];
    $filteredEvents = [];
    $patternsMatched = [];

    $seenRaw = [];
    $seenFiltered = [];

    foreach (array_reverse(is_array($rows) ? $rows : []) as $row) {
        if (!is_array($row)) {
            continue;
        }

        $time = trim((string)($row['time'] ?? ''));
        $topics = trim((string)($row['topics'] ?? ''));
        $message = trim((string)($row['message'] ?? ''));

        if ($message === '') {
            continue;
        }

        $isHotspotTopic = str_contains(strtolower($topics), 'hotspot');
        $isPrefixed = str_starts_with($message, '->');
        if (!$isHotspotTopic && !$isPrefixed) {
            continue;
        }

        $pattern = matchHotspotPattern($message);
        if ($pattern === null) {
            continue;
        }

        $rawSignature = strtolower(($time !== '' ? $time : '--') . '|' . $topics . '|' . $message);
        if (!isset($seenRaw[$rawSignature]) && count($rawLogs) < 20) {
            $seenRaw[$rawSignature] = true;
            $rawLogs[] = [
                'time' => $time !== '' ? $time : '--',
                'topics' => $topics,
                'message' => $message,
                'pattern_matched' => $pattern,
            ];
        }

        $parsed = parseEvent($time, $message, $pattern);
        $filteredSignature = strtolower($parsed['time'] . '|' . $parsed['user'] . '|' . $parsed['action'] . '|' . $pattern);
        if (!isset($seenFiltered[$filteredSignature]) && count($filteredEvents) < 20) {
            $seenFiltered[$filteredSignature] = true;
            $filteredEvents[] = $parsed;
            $patternsMatched[] = [
                'time' => $parsed['time'],
                'user' => $parsed['user'],
                'pattern' => $pattern,
            ];
        }

        if (count($rawLogs) >= 20 && count($filteredEvents) >= 20) {
            break;
        }
    }

    echo json_encode([
        'device' => [
            'id' => (string)($device['id'] ?? ''),
            'name' => (string)($device['name'] ?? ''),
            'host' => (string)($device['host'] ?? ''),
            'type' => $deviceType,
        ],
        'raw_logs' => $rawLogs,
        'filtered_events' => $filteredEvents,
        'patterns_matched' => $patternsMatched,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'error' => $e->getMessage(),
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}
