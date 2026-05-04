<?php
require __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/device_manager.php';
require_once __DIR__ . '/../includes/mikrotik_backend.php';
require_once __DIR__ . '/../includes/opnsense_shaper.php';
require_once __DIR__ . '/../includes/operation_history.php';
require_once __DIR__ . '/../includes/vouchers.php';
require_once __DIR__ . '/../includes/profile_schema.php';
require_once __DIR__ . '/../includes/commercial_report_source.php';

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
    if (is_array($zonesData) && isset($zonesData['rows']) && is_array($zonesData['rows'])) {
        $zones = [];

        foreach ($zonesData['rows'] as $row) {
            if (!is_array($row)) {
                continue;
            }

            $label = trim((string)($row['description'] ?? ''));
            if ($label === '') {
                $label = trim((string)($row['zoneid'] ?? ''));
            }
            if ($label === '') {
                $label = trim((string)($row['interfaces'] ?? ''));
            }
            if ($label === '') {
                continue;
            }

            $zones[] = $label;
        }

        return array_values(array_unique($zones));
    }

    if (is_array($zonesData)) {
        return array_values(array_map('strval', array_values($zonesData)));
    }

    return [];
}

function buildOpnsenseUnavailablePayload(
    array $device,
    int $totalUsers,
    int $activeHotspotUsersDb,
    array $commercialSummary,
    array $salesDailyTrend,
    string $message,
    array $recentEvents = []
): array {
    $deviceName = (string)($device['name'] ?? 'Device');

    return [
        'active_hotspot_users' => $activeHotspotUsersDb,
        'total_users' => $totalUsers,
        'sales_today' => formatCommercialAmount((float)($commercialSummary['today_amount'] ?? 0)),
        'sales_monthly' => formatCommercialAmount((float)($commercialSummary['month_amount'] ?? 0)),
        'sales_today_count' => (int)($commercialSummary['today_count'] ?? 0),
        'sales_monthly_count' => (int)($commercialSummary['month_count'] ?? 0),
        'sales_today_amount' => (float)($commercialSummary['today_amount'] ?? 0),
        'sales_monthly_amount' => (float)($commercialSummary['month_amount'] ?? 0),
        'sales_daily_trend' => $salesDailyTrend,
        'memory_used_percent' => 0,
        'device_name' => $deviceName,
        'device_type' => 'opnsense',
        'business_source' => resolveDeviceBusinessSource('opnsense'),
        'device_host' => (string)($device['host'] ?? ''),
        'device_ip' => (string)($device['ip'] ?? ''),
        'device_backend_driver' => deviceBackendDriverForApiResponse($device),
        'device_model' => '',
        'device_status' => 'DEGRADED',
        'device_message' => $message,
        'device_version' => 'Version inconnue',
        'device_zones' => [],
        'device_zone_count' => 0,
        'telemetry_supported' => false,
        'opnsense_name' => $deviceName,
        'opnsense_version' => 'Version inconnue',
        'opnsense_status' => 'DEGRADED',
        'opnsense_message' => $message,
        'opnsense_zones' => [],
        'opnsense_zone_count' => 0,
        'last_update' => date('H:i:s'),
        'recent_events' => $recentEvents,
    ];
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
            'time' => $timestamp !== false ? date('H:i:s', $timestamp) : '--:--:--',
            'user' => $userName !== '' ? $userName : 'Utilisateur',
            'action' => formatRecentEventAction($eventType, $ipAddress !== '' ? $ipAddress : 'IP inconnue'),
            'timestamp_sort' => $timestamp !== false ? $timestamp : 0,
        ];
    }

    usort($events, static function (array $a, array $b): int {
        return (int)($b['timestamp_sort'] ?? 0) <=> (int)($a['timestamp_sort'] ?? 0);
    });

    $events = array_slice($events, 0, $limit);

    return $events;
}

function buildOperationRecentEvents(PDO $pdo, int $limit = 5, ?string $deviceId = null): array
{
    ensureOperationHistoryTable($pdo);

    $sql = "
        SELECT operation_type, actor_username, target_name, summary, created_at
        FROM operation_history
    ";
    $params = [];
    if ($deviceId !== null && trim($deviceId) !== '') {
        $sql .= " WHERE device_id = ?";
        $params[] = trim($deviceId);
    }
    $sql .= " ORDER BY created_at DESC, id DESC LIMIT " . (int)$limit;
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    $events = [];
    foreach (($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) as $row) {
        $timestamp = strtotime((string)($row['created_at'] ?? '')) ?: time();
        $user = trim((string)($row['target_name'] ?? ''));
        if ($user === '') {
            $user = trim((string)($row['actor_username'] ?? ''));
        }

        $action = trim((string)($row['summary'] ?? ''));
        if ($action === '') {
            $action = operationTypeLabel((string)($row['operation_type'] ?? ''));
        }

        $events[] = [
            'time' => date('H:i:s', $timestamp),
            'user' => $user !== '' ? $user : 'Système',
            'action' => $action,
            'timestamp_sort' => $timestamp,
        ];
    }

    return $events;
}

function buildRechargeRecentEvents(PDO $pdo, int $limit = 5, ?string $deviceId = null): array
{
    ensureRechargeHistoryStatsTable($pdo);

    $sql = "
        SELECT username, profile_name, mode, effect_summary, amount_value, created_at, device_id
        FROM recharge_history
    ";
    $params = [];
    if ($deviceId !== null && trim($deviceId) !== '') {
        $sql .= " WHERE device_id = ?";
        $params[] = trim($deviceId);
    }
    $sql .= " ORDER BY created_at DESC, id DESC LIMIT " . (int)$limit;
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    $events = [];
    foreach (($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) as $row) {
        $timestamp = strtotime((string)($row['created_at'] ?? '')) ?: time();
        $user = trim((string)($row['username'] ?? '')) ?: 'Utilisateur';
        $summary = trim((string)($row['effect_summary'] ?? ''));
        $mode = trim((string)($row['mode'] ?? ''));
        $action = $summary !== '' ? $summary : ($mode !== '' ? $mode : 'Recharge');
        $amount = (float)($row['amount_value'] ?? 0);
        if ($amount > 0) {
            $action = sprintf('%s (%s)', $action, formatCommercialAmount($amount));
        }

        $events[] = [
            'time' => date('H:i:s', $timestamp),
            'user' => $user,
            'action' => $action,
            'timestamp_sort' => $timestamp,
        ];
    }

    return $events;
}

function deduplicateRecentEvents(array $events, int $limit = 10): array
{
    usort($events, static function (array $a, array $b): int {
        return (int)($b['timestamp_sort'] ?? 0) <=> (int)($a['timestamp_sort'] ?? 0);
    });

    $deduplicated = [];
    $seen = [];

    foreach ($events as $event) {
        $time = trim((string)($event['time'] ?? ''));
        $user = trim((string)($event['user'] ?? ''));
        $action = trim((string)($event['action'] ?? ''));
        $signature = strtolower($time . '|' . $user . '|' . $action);

        if ($signature === '||' || isset($seen[$signature])) {
            continue;
        }

        $seen[$signature] = true;
        unset($event['timestamp_sort']);
        $deduplicated[] = $event;

        if (count($deduplicated) >= $limit) {
            break;
        }
    }

    return $deduplicated;
}

function normalizeOpnsenseStatusLabel($status): string
{
    $raw = trim((string)$status);

    return match ($raw) {
        '-1' => 'ERROR',
        '0' => 'WARNING',
        '1' => 'NOTICE',
        '2' => 'OK',
        default => $raw !== '' ? strtoupper($raw) : 'UNKNOWN',
    };
}

function buildOpnsenseDashboardStatus(array $statusResponseData): array
{
    $metadata = is_array($statusResponseData['metadata'] ?? null) ? $statusResponseData['metadata'] : [];
    $system = is_array($metadata['system'] ?? null) ? $metadata['system'] : [];
    $subsystems = is_array($statusResponseData['subsystems'] ?? null) ? $statusResponseData['subsystems'] : [];

    $status = trim((string)($system['status'] ?? 'UNKNOWN'));
    $message = trim((string)($system['message'] ?? ''));

    foreach ($subsystems as $subsystem) {
        if (!is_array($subsystem)) {
            continue;
        }

        $subsystemStatus = strtoupper(trim((string)($subsystem['status'] ?? '')));
        if ($subsystemStatus !== 'WARNING') {
            continue;
        }

        $title = trim((string)($subsystem['title'] ?? 'Sous-systeme'));
        $subsystemMessage = trim((string)($subsystem['message'] ?? ''));

        return [
            'status' => normalizeOpnsenseStatusLabel($status !== '' ? $status : 'WARNING'),
            'message' => $subsystemMessage !== ''
                ? $title . ' : ' . $subsystemMessage
                : ($title !== '' ? $title : ($message !== '' ? $message : 'Avertissement systeme detecte.')),
        ];
    }

    return [
        'status' => normalizeOpnsenseStatusLabel($status !== '' ? $status : 'UNKNOWN'),
        'message' => $message !== '' ? $message : 'Aucun message systeme detaille.',
    ];
}

function parseMikrotikLogTimestamp(?string $raw): array
{
    $value = trim((string)$raw);
    if ($value === '') {
        return ['--:--:--', 0];
    }

    if (preg_match('/^(\\d{4}-\\d{2}-\\d{2})[ T](\\d{2}:\\d{2}:\\d{2})$/', $value, $matches)) {
        $timestamp = strtotime($matches[1] . ' ' . $matches[2]);
        return [$matches[2], $timestamp ?: 0];
    }

    if (preg_match('/^(?<mon>[a-zA-Z]{3})\\/?(?<day>\\d{1,2})(?:\\/(?<year>\\d{2,4}))?\\s+(?<time>\\d{2}:\\d{2}:\\d{2})$/', $value, $matches)) {
        $monthKey = strtolower($matches['mon']);
        $monthMap = [
            'jan' => 1, 'feb' => 2, 'mar' => 3, 'apr' => 4, 'may' => 5, 'jun' => 6,
            'jul' => 7, 'aug' => 8, 'sep' => 9, 'oct' => 10, 'nov' => 11, 'dec' => 12,
            'fev' => 2, 'avr' => 4, 'mai' => 5, 'aou' => 8,
        ];
        if (isset($monthMap[$monthKey])) {
            $year = (int)($matches['year'] ?? date('Y'));
            if ($year > 0 && $year < 100) {
                $year += 2000;
            }
            $date = sprintf('%04d-%02d-%02d', $year, $monthMap[$monthKey], (int)$matches['day']);
            $timestamp = strtotime($date . ' ' . $matches['time']);
            return [$matches['time'], $timestamp ?: 0];
        }
    }

    if (preg_match('/^(\\d{2}:\\d{2}:\\d{2})$/', $value, $matches)) {
        $timestamp = strtotime(date('Y-m-d') . ' ' . $matches[1]);
        return [$matches[1], $timestamp ?: 0];
    }

    return [$value, 0];
}

function buildMikrotikRecentEvents(int $limit = 5, ?RouterosAPI $existingApi = null): array
{
    $events = [];
    $api = $existingApi ?: connectToActiveMikrotikApi();
    $ownsConnection = $existingApi === null;
    $rows = [];
    try {
        $rows = $api->comm('/log/print', [
            '.proplist' => 'time,message,topics',
            '?topics~' => 'hotspot',
        ]);
        if (!is_array($rows) || $rows === []) {
            $rows = $api->comm('/log/print', [
                '.proplist' => 'time,message,topics',
            ]);
        }
    } finally {
        if ($ownsConnection) {
            $api->disconnect();
        }
    }

    if (!is_array($rows) || $rows === []) {
        return [];
    }
    $rows = array_slice($rows, -80);

    $keywords = [
        'trying to log in',
        'logged in',
        'logged out',
        'login failed',
        'keepalive timeout',
        'user request',
        'idle timeout',
        'session timeout',
    ];

    $seen = [];
    foreach (array_reverse($rows) as $row) {
        if (!is_array($row)) {
            continue;
        }

        $message = trim((string)($row['message'] ?? ''));
        if ($message === '') {
            continue;
        }

        $topics = strtolower(trim((string)($row['topics'] ?? '')));
        if (!str_contains($topics, 'hotspot')) {
            continue;
        }

        $lowerMessage = strtolower($message);
        $isUseful = false;
        foreach ($keywords as $keyword) {
            if (str_contains($lowerMessage, $keyword)) {
                $isUseful = true;
                break;
            }
        }
        if (!$isUseful) {
            continue;
        }

        $clean = trim((string)preg_replace('/^\-\>\:\s*/', '', $message));
        [$timeLabel] = parseMikrotikLogTimestamp((string)($row['time'] ?? ''));
        if (preg_match('/^(?<user>[^:]+?)\s*:\s*(?<action>.+)$/', $clean, $matches)) {
            $userLabel = trim((string)$matches['user']);
            $action = trim((string)$matches['action']);
        } else {
            $userLabel = '-';
            $action = trim($clean);
        }

        if ($action === '') {
            continue;
        }

        $signature = strtolower(($timeLabel !== '' ? $timeLabel : '--') . '|' . $userLabel . '|' . $action);
        if (isset($seen[$signature])) {
            continue;
        }
        $seen[$signature] = true;

        $events[] = [
            'time' => $timeLabel !== '' ? $timeLabel : '--',
            'user' => $userLabel !== '' ? $userLabel : '-',
            'action' => $action !== '' ? preg_replace('/\s+/', ' ', $action) : '-',
        ];

        if (count($events) >= $limit) {
            break;
        }
    }

    return $events;
}

function ensureRechargeHistoryStatsTable(PDO $pdo): void
{
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS recharge_history (
            id INT(11) NOT NULL AUTO_INCREMENT,
            device_id VARCHAR(100) DEFAULT NULL,
            device_type VARCHAR(30) DEFAULT NULL,
            username VARCHAR(100) NOT NULL,
            profile_name VARCHAR(100) NOT NULL,
            mode VARCHAR(30) NOT NULL,
            operator_username VARCHAR(100) DEFAULT NULL,
            effect_summary VARCHAR(255) DEFAULT NULL,
            amount_value DECIMAL(10,2) DEFAULT NULL,
            amount_label VARCHAR(50) DEFAULT NULL,
            current_profile VARCHAR(100) DEFAULT NULL,
            current_time_limit VARCHAR(100) DEFAULT NULL,
            current_data_limit VARCHAR(100) DEFAULT NULL,
            current_expiration VARCHAR(50) DEFAULT NULL,
            projected_profile VARCHAR(100) DEFAULT NULL,
            projected_time_limit VARCHAR(100) DEFAULT NULL,
            projected_data_limit VARCHAR(100) DEFAULT NULL,
            projected_expiration VARCHAR(50) DEFAULT NULL,
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_recharge_history_username (username),
            KEY idx_recharge_history_device (device_id),
            KEY idx_recharge_history_created_at (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
    );

    $columns = [];
    $stmt = $pdo->query("SHOW COLUMNS FROM recharge_history");
    if ($stmt instanceof PDOStatement) {
        $columns = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
    }

    if (!in_array('amount_value', $columns, true)) {
        $pdo->exec("ALTER TABLE recharge_history ADD COLUMN amount_value DECIMAL(10,2) DEFAULT NULL AFTER effect_summary");
    }

    if (!in_array('amount_label', $columns, true)) {
        $pdo->exec("ALTER TABLE recharge_history ADD COLUMN amount_label VARCHAR(50) DEFAULT NULL AFTER amount_value");
    }
}

function loadCommercialSummary(PDO $pdo): array
{
    static $schemaReady = false;
    if (!$schemaReady) {
        ensureRechargeHistoryStatsTable($pdo);
        ensureOperationHistoryTable($pdo);
        ensureVouchersTable($pdo);
        ensureProfilesExtendedSchema($pdo);
        $schemaReady = true;
    }

    return commercialReportSummary($pdo);
}

function formatCommercialAmount(float $amount): string
{
    if ($amount <= 0) {
        return '0';
    }

    return rtrim(rtrim(number_format($amount, 2, '.', ' '), '0'), '.');
}

function commercialEntriesUnionSql(): string
{
    return commercialReportEntriesUnionSql();
}

function countUsersForActiveDevice(PDO $pdo, array $device): int
{
    $deviceType = deviceTypeLabelForApiResponse($device);
    if ($deviceType === null) {
        return 0;
    }

    if ($deviceType === 'mikrotik') {
        return 0;
    }

    $sql = "SELECT COUNT(*) FROM users u LEFT JOIN nas n ON n.id = u.nas_id";
    $params = [];

    if ($deviceType === 'opnsense') {
        $sql .= " WHERE LOWER(COALESCE(n.type, '')) = :device_type";
        $params[':device_type'] = 'opnsense';
    } elseif ($deviceType === 'radius') {
        $sql .= " WHERE LOWER(COALESCE(n.type, '')) IN ('radius', 'freeradius')";
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return (int)$stmt->fetchColumn();
}

try {
    $device = requireActiveDevice();
    $totalUsers = countUsersForActiveDevice($pdo, $device);
    $activeHotspotUsersDb = (int)$pdo->query("SELECT COUNT(*) FROM radacct WHERE acctstoptime IS NULL")->fetchColumn();
    $commercialSummary = loadCommercialSummary($pdo);
    $salesTodayValue = formatCommercialAmount((float)$commercialSummary['today_amount']);
    $salesMonthlyValue = formatCommercialAmount((float)$commercialSummary['month_amount']);
    $salesDailyTrend = $commercialSummary['trend'];

    if (($device['type'] ?? '') === 'mikrotik') {
        $api = connectToMikrotikApiByDevice($device);
        try {
            ensureMikrotikHotspotLogging($api);
            $resource = mikrotikFirstRow($api->comm('/system/resource/print'));
            $routerboard = mikrotikFirstRow($api->comm('/system/routerboard/print'));
            $interfaces = $api->comm('/interface/print');
            $selectedInterface = selectMikrotikDashboardInterface(is_array($interfaces) ? $interfaces : []);
            $memoryTotal = (float)($resource['total-memory'] ?? 0);
            $memoryFree = (float)($resource['free-memory'] ?? 0);
            $memoryUsedPercent = $memoryTotal > 0
                ? round((($memoryTotal - $memoryFree) / $memoryTotal) * 100, 2)
                : 0;
            $activeHotspotUsers = (int)$api->comm('/ip/hotspot/active/print', ['count-only' => '']);
            $totalHotspotUsers = (int)$api->comm('/ip/hotspot/user/print', ['count-only' => '']);
            $version = trim((string)($resource['version'] ?? ''));
            $boardName = trim((string)($resource['board-name'] ?? ''));
            $model = trim((string)($routerboard['model'] ?? ''));
            $deviceName = (string)($device['name'] ?? '');
            if ($deviceName === '') {
                $deviceName = $boardName !== '' ? $boardName : ($model !== '' ? $model : 'MikroTik');
            }
            $recentEvents = buildMikrotikRecentEvents(20, $api);
        } finally {
            $api->disconnect();
        }

        echo json_encode([
            'active_hotspot_users' => $activeHotspotUsers,
            'total_users' => $totalHotspotUsers,
            'sales_today' => $salesTodayValue,
            'sales_monthly' => $salesMonthlyValue,
            'sales_today_count' => (int)$commercialSummary['today_count'],
            'sales_monthly_count' => (int)$commercialSummary['month_count'],
            'sales_today_amount' => (float)$commercialSummary['today_amount'],
            'sales_monthly_amount' => (float)$commercialSummary['month_amount'],
            'sales_daily_trend' => $salesDailyTrend,
            'memory_used_percent' => $memoryUsedPercent,
            'device_name' => $deviceName,
            'device_type' => 'mikrotik',
            'business_source' => resolveDeviceBusinessSource('mikrotik'),
            'device_host' => (string)($device['host'] ?? ''),
            'device_ip' => (string)($device['ip'] ?? ''),
            'device_backend_driver' => deviceBackendDriverForApiResponse($device),
            'device_model' => $model !== '' ? $model : $boardName,
            'device_status' => 'CONNECTED',
            'device_message' => 'Telemetrie du device chargee via le backend actif.',
            'device_version' => $version !== '' ? $version : 'Version inconnue',
            'device_zones' => $selectedInterface ? [(string)($selectedInterface['name'] ?? '')] : [],
            'device_zone_count' => $selectedInterface ? 1 : 0,
            'telemetry_supported' => true,
            'last_update' => date('H:i:s'),
            'recent_events' => $recentEvents,
            'opnsense_name' => $deviceName,
            'opnsense_version' => $version !== '' ? $version : 'Version inconnue',
            'opnsense_status' => 'CONNECTED',
            'opnsense_message' => 'Dashboard alimente via le backend actif.',
            'opnsense_zones' => $selectedInterface ? [(string)($selectedInterface['name'] ?? '')] : [],
            'opnsense_zone_count' => $selectedInterface ? 1 : 0,
        ]);
        exit;
    }

    if (($device['type'] ?? '') !== 'opnsense') {
        $ready = canProbeDevice($device);

        echo json_encode([
            'active_hotspot_users' => $activeHotspotUsersDb,
            'total_users' => $totalUsers,
            'sales_today' => $salesTodayValue,
            'sales_monthly' => $salesMonthlyValue,
            'sales_today_count' => (int)$commercialSummary['today_count'],
            'sales_monthly_count' => (int)$commercialSummary['month_count'],
            'sales_today_amount' => (float)$commercialSummary['today_amount'],
            'sales_monthly_amount' => (float)$commercialSummary['month_amount'],
            'sales_daily_trend' => $salesDailyTrend,
            'memory_used_percent' => 0,
            'device_name' => (string)($device['name'] ?? 'Device'),
            'device_type' => deviceTypeLabelForApiResponse($device),
            'business_source' => deviceBusinessSourceForApiResponse(deviceTypeLabelForApiResponse($device)),
            'device_host' => (string)($device['host'] ?? ''),
            'device_ip' => (string)($device['ip'] ?? ''),
            'device_backend_driver' => deviceBackendDriverForApiResponse($device),
            'device_model' => '',
            'device_status' => $ready ? 'READY' : 'UNSUPPORTED',
            'device_message' => $ready
                ? 'Driver ' . (deviceBackendDriverForApiResponse($device) ?? '') . ' actif.'
                : 'Aucune telemetrie temps reel disponible pour ce type de device.',
            'device_version' => 'N/A',
            'device_zones' => [],
            'device_zone_count' => 0,
            'telemetry_supported' => false,
            'last_update' => date('H:i:s'),
            'recent_events' => [],
            'opnsense_name' => (string)($device['name'] ?? 'Device'),
            'opnsense_version' => 'N/A',
            'opnsense_status' => $ready ? 'READY' : 'UNSUPPORTED',
            'opnsense_message' => 'Aucune telemetrie temps reel disponible pour le device actif.',
            'opnsense_zones' => [],
            'opnsense_zone_count' => 0,
        ]);
        exit;
    }

    $statusResponse = opnsenseApiRequest($device, '/api/core/system/status');
    $infoResponse = opnsenseApiRequest($device, '/api/diagnostics/system/system_information');
    $resourcesResponse = opnsenseApiRequest($device, '/api/diagnostics/system/system_resources');
    $zonesResponse = opnsenseApiRequest($device, '/api/captiveportal/settings/searchZones');
    $sessionsResponse = opnsenseApiRequest($device, '/api/captiveportal/session/search');

    if (
        !($statusResponse['success'] ?? false) ||
        !($infoResponse['success'] ?? false) ||
        !($resourcesResponse['success'] ?? false)
    ) {
        $errors = [];
        foreach ([
            'Core status' => $statusResponse,
            'System info' => $infoResponse,
            'Resources' => $resourcesResponse,
        ] as $label => $response) {
            if (!($response['success'] ?? false)) {
                $errors[] = $label . ': ' . (string)($response['message'] ?? 'Erreur OPNsense');
            }
        }

        $recentEvents = deduplicateRecentEvents(array_merge(
            buildOperationRecentEvents($pdo, 10, (string)($device['id'] ?? '')),
            buildRechargeRecentEvents($pdo, 10, (string)($device['id'] ?? ''))
        ), 10);

        echo json_encode(buildOpnsenseUnavailablePayload(
            $device,
            $totalUsers,
            $activeHotspotUsersDb,
            $commercialSummary,
            $salesDailyTrend,
            $errors !== [] ? implode(' | ', $errors) : 'Diagnostics OPNsense indisponibles.',
            $recentEvents
        ));
        exit;
    }

    $systemStatus = $statusResponse['data']['metadata']['system'] ?? [];
    $dashboardStatus = buildOpnsenseDashboardStatus($statusResponse['data'] ?? []);
    $systemInfo = $infoResponse['data'];
    $resources = $resourcesResponse['data']['memory'] ?? [];
    $zones = ($zonesResponse['success'] ?? false) ? normalizeZones($zonesResponse['data'] ?? []) : [];
    $activeHotspotUsers = ($sessionsResponse['success'] ?? false) ? (int)(($sessionsResponse['data']['total'] ?? 0)) : $activeHotspotUsersDb;

    $memoryTotalMb = (int)($resources['total_frmt'] ?? 0);
    $memoryUsedMb = (int)($resources['used_frmt'] ?? 0);
    $memoryUsedPercent = $memoryTotalMb > 0 ? round(($memoryUsedMb / $memoryTotalMb) * 100, 2) : 0;

    $recentEvents = [];
    $userLogsResponse = opnsenseApiRequest($device, '/api/diagnostics/log/core/portalauth', 'POST', [
        'rowCount' => 100,
        'current' => 1,
        'validFrom' => time() - 86400,
    ]);

    if (($userLogsResponse['success'] ?? false)) {
        $recentEvents = extractRecentUserEvents($userLogsResponse['data']['rows'] ?? []);
    }

    if ($recentEvents === []) {
        $recentEvents = deduplicateRecentEvents(array_merge(
            buildOperationRecentEvents($pdo, 10, (string)($device['id'] ?? '')),
            buildRechargeRecentEvents($pdo, 10, (string)($device['id'] ?? ''))
        ), 10);
    }

    $configuredDeviceName = trim((string)($device['name'] ?? '')) ?: 'Device';
    $opnsenseSystemName = trim((string)($systemInfo['name'] ?? ''));

    echo json_encode([
        'active_hotspot_users' => $activeHotspotUsers,
        'total_users' => $totalUsers,
        'sales_today' => $salesTodayValue,
        'sales_monthly' => $salesMonthlyValue,
        'sales_today_count' => (int)$commercialSummary['today_count'],
        'sales_monthly_count' => (int)$commercialSummary['month_count'],
        'sales_today_amount' => (float)$commercialSummary['today_amount'],
        'sales_monthly_amount' => (float)$commercialSummary['month_amount'],
        'sales_daily_trend' => $salesDailyTrend,
        'memory_used_percent' => $memoryUsedPercent,
        'device_name' => $configuredDeviceName,
        'device_type' => deviceTypeLabelForApiResponse($device),
        'business_source' => deviceBusinessSourceForApiResponse(deviceTypeLabelForApiResponse($device)),
        'device_host' => (string)($device['host'] ?? ''),
        'device_ip' => (string)($device['ip'] ?? ''),
        'device_backend_driver' => deviceBackendDriverForApiResponse($device),
        'device_model' => $opnsenseSystemName,
        'device_status' => (string)($dashboardStatus['status'] ?? 'UNKNOWN'),
        'device_message' => (string)($dashboardStatus['message'] ?? ''),
        'device_version' => (string)(($systemInfo['versions'][0] ?? 'Version inconnue')),
        'device_zones' => $zones,
        'device_zone_count' => count($zones),
        'telemetry_supported' => true,
        'opnsense_name' => $opnsenseSystemName !== '' ? $opnsenseSystemName : $configuredDeviceName,
        'opnsense_version' => (string)(($systemInfo['versions'][0] ?? 'Version inconnue')),
        'opnsense_status' => (string)($dashboardStatus['status'] ?? 'UNKNOWN'),
        'opnsense_message' => (string)($dashboardStatus['message'] ?? ''),
        'opnsense_zones' => $zones,
        'opnsense_zone_count' => count($zones),
        'last_update' => date('H:i:s'),
        'recent_events' => $recentEvents,
    ]);
} catch (Exception $e) {
    if (isset($device) && is_array($device) && (($device['type'] ?? '') === 'opnsense')) {
        echo json_encode(buildOpnsenseUnavailablePayload(
            $device,
            $totalUsers ?? 0,
            $activeHotspotUsersDb ?? 0,
            $commercialSummary ?? ['today_count' => 0, 'today_amount' => 0, 'month_count' => 0, 'month_amount' => 0],
            $salesDailyTrend ?? [],
            $e->getMessage(),
            []
        ));
        exit;
    }

    http_response_code(500);
    echo json_encode([
        'error' => $e->getMessage(),
    ]);
}
