<?php
require __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/device_manager.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json');

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    http_response_code(403);
    echo json_encode([
        'error' => 'Unauthorized',
    ]);
    exit;
}

session_write_close();

function opnsenseGet(array $device, string $path): array
{
    $url = $device['host'] . $path;
    $ch = curl_init();

    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
        CURLOPT_USERPWD => $device['api_key'] . ':' . $device['api_secret'],
        CURLOPT_SSL_VERIFYPEER => (bool)$device['verify_ssl'],
        CURLOPT_SSL_VERIFYHOST => !empty($device['verify_ssl']) ? 2 : 0,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
        ],
    ]);

    $raw = curl_exec($ch);
    $error = curl_error($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);

    curl_close($ch);

    if ($raw === false || $error !== '') {
        return [
            'success' => false,
            'error' => $error !== '' ? $error : 'Erreur cURL inconnue',
        ];
    }

    $decoded = json_decode($raw, true);

    if ($httpCode < 200 || $httpCode >= 300 || $decoded === null) {
        return [
            'success' => false,
            'error' => 'Reponse OPNsense invalide sur ' . $path,
            'http_code' => $httpCode,
            'raw' => $raw,
        ];
    }

    return [
        'success' => true,
        'data' => $decoded,
    ];
}

function opnsensePost(array $device, string $path, array $payload): array
{
    $url = $device['host'] . $path;
    $ch = curl_init();

    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
        CURLOPT_USERPWD => $device['api_key'] . ':' . $device['api_secret'],
        CURLOPT_SSL_VERIFYPEER => (bool)$device['verify_ssl'],
        CURLOPT_SSL_VERIFYHOST => !empty($device['verify_ssl']) ? 2 : 0,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($payload),
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            'Content-Type: application/x-www-form-urlencoded',
        ],
    ]);

    $raw = curl_exec($ch);
    $error = curl_error($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);

    curl_close($ch);

    if ($raw === false || $error !== '') {
        return [
            'success' => false,
            'error' => $error !== '' ? $error : 'Erreur cURL inconnue',
        ];
    }

    $decoded = json_decode($raw, true);

    if ($httpCode < 200 || $httpCode >= 300 || $decoded === null) {
        return [
            'success' => false,
            'error' => 'Reponse OPNsense invalide sur ' . $path,
            'http_code' => $httpCode,
            'raw' => $raw,
        ];
    }

    return [
        'success' => true,
        'data' => $decoded,
    ];
}

function opnsenseMultiGet(array $device, array $paths): array
{
    $multiHandle = curl_multi_init();
    $handles = [];

    foreach ($paths as $path) {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $device['host'] . $path,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
            CURLOPT_USERPWD => $device['api_key'] . ':' . $device['api_secret'],
            CURLOPT_SSL_VERIFYPEER => (bool)$device['verify_ssl'],
            CURLOPT_SSL_VERIFYHOST => !empty($device['verify_ssl']) ? 2 : 0,
            CURLOPT_TIMEOUT => 8,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
            ],
        ]);
        curl_multi_add_handle($multiHandle, $ch);
        $handles[$path] = $ch;
    }

    do {
        $status = curl_multi_exec($multiHandle, $running);
        if ($running) {
            curl_multi_select($multiHandle, 1.0);
        }
    } while ($running && $status === CURLM_OK);

    $results = [];

    foreach ($handles as $path => $ch) {
        $raw = curl_multi_getcontent($ch);
        $error = curl_error($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $decoded = json_decode($raw, true);

        if ($raw === false || $error !== '') {
            $results[$path] = [
                'success' => false,
                'error' => $error !== '' ? $error : 'Erreur cURL inconnue',
            ];
        } elseif ($httpCode < 200 || $httpCode >= 300 || $decoded === null) {
            $results[$path] = [
                'success' => false,
                'error' => 'Reponse OPNsense invalide sur ' . $path,
                'http_code' => $httpCode,
            ];
        } else {
            $results[$path] = [
                'success' => true,
                'data' => $decoded,
            ];
        }

        curl_multi_remove_handle($multiHandle, $ch);
        curl_close($ch);
    }

    curl_multi_close($multiHandle);

    return $results;
}

function normalizeZones($zonesData): array
{
    if (is_array($zonesData)) {
        return array_values(array_map('strval', array_values($zonesData)));
    }

    return [];
}

function formatRecentEventAction(string $eventType, string $ipAddress): string
{
    if ($eventType === 'AUTH') {
        return sprintf('Connexion depuis %s', $ipAddress);
    }

    if ($eventType === 'LOGOUT') {
        return sprintf('Deconnexion depuis %s', $ipAddress);
    }

    if ($eventType === 'DENY') {
        return sprintf('Acces refuse depuis %s', $ipAddress);
    }

    return sprintf('Evenement depuis %s', $ipAddress);
}

function extractRecentUserEvents(array $rows, int $limit = 5): array
{
    $events = [];

    foreach ($rows as $row) {
        $line = trim((string)($row['line'] ?? ''));
        if ($line === '') {
            continue;
        }

        if (!preg_match('/\b(AUTH|LOGOUT|DENY)\s+(.+?)\s+\(([^)]+)\)\s+zone\b/i', $line, $matches)) {
            continue;
        }

        $eventType = strtoupper((string)$matches[1]);
        $userName = trim((string)$matches[2]);
        $ipAddress = trim((string)$matches[3]);
        $timestampRaw = (string)($row['timestamp'] ?? '');
        $timestamp = strtotime($timestampRaw);

        $events[] = [
            'time' => $timestamp !== false ? date('H:i', $timestamp) : '--:--',
            'user' => $userName !== '' ? $userName : 'Utilisateur',
            'action' => formatRecentEventAction($eventType, $ipAddress !== '' ? $ipAddress : 'IP inconnue'),
            'timestamp_sort' => $timestamp !== false ? $timestamp : 0,
        ];
    }

    usort($events, static function (array $a, array $b): int {
        return (int)($b['timestamp_sort'] ?? 0) <=> (int)($a['timestamp_sort'] ?? 0);
    });

    $events = array_slice($events, 0, $limit);

    return array_map(static function (array $event): array {
        unset($event['timestamp_sort']);
        return $event;
    }, $events);
}

try {
    $device = loadActiveOpnSenseDevice();

    $responses = opnsenseMultiGet($device, [
        '/api/core/system/status',
        '/api/diagnostics/system/systemInformation',
        '/api/diagnostics/system/systemResources',
        '/api/captiveportal/session/zones',
        '/api/captiveportal/session/search',
    ]);

    $statusResponse = $responses['/api/core/system/status'] ?? ['success' => false, 'error' => 'status manquant'];
    $infoResponse = $responses['/api/diagnostics/system/systemInformation'] ?? ['success' => false, 'error' => 'systemInformation manquant'];
    $resourcesResponse = $responses['/api/diagnostics/system/systemResources'] ?? ['success' => false, 'error' => 'systemResources manquant'];
    $zonesResponse = $responses['/api/captiveportal/session/zones'] ?? ['success' => false, 'error' => 'zones manquant'];
    $sessionsResponse = $responses['/api/captiveportal/session/search'] ?? ['success' => false, 'error' => 'sessions manquant'];

    if (
        !$statusResponse['success'] ||
        !$infoResponse['success'] ||
        !$resourcesResponse['success']
    ) {
        throw new Exception('Impossible de charger les diagnostics OPNsense principaux.');
    }

    $systemStatus = $statusResponse['data']['metadata']['system'] ?? [];
    $systemInfo = $infoResponse['data'];
    $resources = $resourcesResponse['data']['memory'] ?? [];
    $zones = $zonesResponse['success'] ? normalizeZones($zonesResponse['data']) : [];
    $sessionRows = $sessionsResponse['success'] ? ($sessionsResponse['data']['rows'] ?? []) : [];
    $activeHotspotUsers = $sessionsResponse['success'] ? (int)($sessionsResponse['data']['total'] ?? 0) : 0;

    $totalUsers = (int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
    $ticketsUsedToday = (int)$pdo->query("SELECT COUNT(*) FROM vouchers WHERE used = 1 AND DATE(used_at) = CURDATE()")->fetchColumn();
    $salesMonthly = (int)$pdo->query("SELECT COUNT(*) FROM vouchers WHERE used = 1 AND YEAR(used_at) = YEAR(CURDATE()) AND MONTH(used_at) = MONTH(CURDATE())")->fetchColumn();
    $salesTrendStmt = $pdo->query("
        SELECT DAY(used_at) AS sale_day, COUNT(*) AS total
        FROM vouchers
        WHERE used = 1
          AND used_at IS NOT NULL
          AND YEAR(used_at) = YEAR(CURDATE())
          AND MONTH(used_at) = MONTH(CURDATE())
        GROUP BY DAY(used_at)
        ORDER BY sale_day ASC
    ");
    $salesTrendRaw = $salesTrendStmt->fetchAll(PDO::FETCH_KEY_PAIR);

    $memoryTotalMb = (int)($resources['total_frmt'] ?? 0);
    $memoryUsedMb = (int)($resources['used_frmt'] ?? 0);
    $memoryUsedPercent = $memoryTotalMb > 0 ? round(($memoryUsedMb / $memoryTotalMb) * 100, 2) : 0;

    $recentEvents = [];
    $userLogsResponse = opnsensePost($device, '/api/diagnostics/log/core/portalauth', [
        'rowCount' => 100,
        'current' => 1,
        'validFrom' => time() - 86400,
    ]);

    if ($userLogsResponse['success']) {
        $recentEvents = extractRecentUserEvents($userLogsResponse['data']['rows'] ?? []);
    }

    $daysInMonth = (int)date('t');
    $salesDailyTrend = [];
    for ($day = 1; $day <= $daysInMonth; $day++) {
        $salesDailyTrend[] = [
            'day' => $day,
            'total' => (int)($salesTrendRaw[$day] ?? 0),
        ];
    }

    echo json_encode([
        'active_hotspot_users' => $activeHotspotUsers,
        'total_users' => $totalUsers,
        'sales_today' => $ticketsUsedToday,
        'sales_monthly' => $salesMonthly,
        'sales_daily_trend' => $salesDailyTrend,
        'memory_used_percent' => $memoryUsedPercent,
        'opnsense_name' => (string)($systemInfo['name'] ?? $device['name']),
        'opnsense_version' => (string)(($systemInfo['versions'][0] ?? 'Version inconnue')),
        'opnsense_status' => (string)($systemStatus['status'] ?? 'UNKNOWN'),
        'opnsense_message' => (string)($systemStatus['message'] ?? ''),
        'opnsense_zones' => $zones,
        'opnsense_zone_count' => count($zones),
        'last_update' => date('H:i:s'),
        'recent_events' => $recentEvents,
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'error' => $e->getMessage(),
    ]);
}
