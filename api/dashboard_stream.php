<?php
require_once __DIR__ . '/../includes/hotspot_log_events.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/device_manager.php';
require_once __DIR__ . '/../includes/mikrotik_backend.php';
require_once __DIR__ . '/../includes/multi_wan_config.php';
require_once __DIR__ . '/../includes/operation_history.php';
require_once __DIR__ . '/../includes/vouchers.php';
require_once __DIR__ . '/../includes/profile_schema.php';
require_once __DIR__ . '/../includes/commercial_report_source.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

session_write_close();

function emitSse(array $payload): void
{
    echo 'event: message' . "\n";
    echo 'data: ' . json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n\n";
    @ob_flush();
    @flush();
}

ignore_user_abort(true);
set_time_limit(0);

while (ob_get_level() > 0) {
    ob_end_flush();
}
ob_implicit_flush(true);

header('Content-Type: text/event-stream');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Connection: keep-alive');
header('X-Accel-Buffering: no');


function dashboardMultiWanSources(array $device): array
{
    $sources = [];
    if (function_exists('multiWanLoadConfig')) {
        $config = multiWanLoadConfig($device);
        foreach (($config['sources'] ?? []) as $slot => $source) {
            if (!is_array($source)) continue;
            if (array_key_exists('enabled', $source) && !$source['enabled']) continue;
            $iface = trim((string)($source['interface'] ?? ''));
            $weight = filter_var($source['weight'] ?? 0, FILTER_VALIDATE_INT);
            if ($iface === '' || $weight === false || (int)$weight <= 0) continue;
            $sources[$iface] = [
                'interface' => $iface,
                'label' => trim((string)($source['label'] ?? '')) ?: strtoupper((string)$slot),
                'slot' => (string)$slot,
            ];
        }
    }
    return array_values($sources);
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

function parseMikrotikLogTimeLabel(?string $raw): array
{
    $value = trim((string)$raw);
    if ($value === '') {
        return ['--:--:--', 0];
    }

    if (preg_match('/^(\\d{2}:\\d{2}:\\d{2})$/', $value, $matches)) {
        $timestamp = strtotime(date('Y-m-d') . ' ' . $matches[1]);
        return [$matches[1], $timestamp ?: 0];
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

    return [$value, 0];
}

function buildMikrotikRecentEventsForStream(RouterosAPI $api, int $limit = 20): array
{
    $rows = $api->comm('/log/print', [
        '.proplist' => 'time,message,topics',
        '?topics~' => 'hotspot',
    ]);
    if (!is_array($rows) || $rows === []) {
        $rows = $api->comm('/log/print', [
            '.proplist' => 'time,message,topics',
        ]);
    }

    if (!is_array($rows) || $rows === []) {
        return [];
    }
    $rows = array_slice($rows, -80);

    return hotspotLogEventsFromRows($rows, $limit, hotspotActiveMacLabels(), static fn(string $t) => parseMikrotikLogTimeLabel($t)[0] ?? '');
}

$device = requireActiveDevice();
$deviceType = normalizeDeviceType((string)$device['type']);

if ($deviceType !== 'mikrotik') {
    emitSse([
        'error' => 'Flux dashboard indisponible pour le device actif ' . getDeviceDisplayLabel($device),
        'supported' => false,
        'device_type' => deviceTypeLabelForApiResponse($device),
        'business_source' => deviceBusinessSourceForApiResponse(deviceTypeLabelForApiResponse($device)),
        'backend_driver' => deviceBackendDriverForApiResponse($device),
    ]);
    exit;
}

$api = null;
$selectedInterface = '';
$lastInterfaceCheck = 0;
$lastCountsCheck = 0;
$countsCache = ['active' => 0, 'total' => 0];
$lastEventsCheck = 0;
$eventsCache = [];
$lastSalesCheck = 0;
$salesCache = [
    'today' => '0',
    'month' => '0',
    'trend' => [],
    'today_count' => 0,
    'month_count' => 0,
];
$lastQueueLimitsCheck = 0;
$queueLimitsCache = ['download_mbps' => null, 'upload_mbps' => null];

while (!connection_aborted()) {
    try {
        if (!$api) {
            $api = connectToMikrotikApiByDevice($device);
        }

        $resource = mikrotikFirstRow($api->comm('/system/resource/print'));
        $cpuLoad = (float)($resource['cpu-load'] ?? 0);
        $memoryTotal = (float)($resource['total-memory'] ?? 0);
        $memoryFree = (float)($resource['free-memory'] ?? 0);
        $memoryUsedPercent = $memoryTotal > 0
            ? round((($memoryTotal - $memoryFree) / $memoryTotal) * 100, 2)
            : 0;
        $version = trim((string)($resource['version'] ?? ''));
        $boardName = trim((string)($resource['board-name'] ?? ''));

        $now = time();
        if ($selectedInterface === '' || ($now - $lastInterfaceCheck) >= 60) {
            $interfaces = $api->comm('/interface/print');
            $selected = selectMikrotikDashboardInterface(is_array($interfaces) ? $interfaces : []);
            $selectedInterface = (string)($selected['name'] ?? '');
            $lastInterfaceCheck = $now;
        }

        $trafficInterfaces = [];
        $trafficInterfaceNames = [];
        $totalRxBps = 0.0;
        $totalTxBps = 0.0;
        foreach (dashboardMultiWanSources($device) as $wanSource) {
            $iface = (string)$wanSource['interface'];
            if ($iface === '') continue;
            $traffic = mikrotikFirstRow($api->comm('/interface/monitor-traffic', [
                'interface' => $iface,
                'once' => '',
            ]));
            $totalRxBps += (float)($traffic['rx-bits-per-second'] ?? 0);
            $totalTxBps += (float)($traffic['tx-bits-per-second'] ?? 0);
            $trafficInterfaceNames[] = $iface;
        }
        if ($trafficInterfaceNames !== []) {
            $trafficInterfaces['total_wan'] = [
                'name' => 'Total WAN',
                'label' => 'Total WAN',
                'slot' => 'total',
                'inbytes' => $totalRxBps,
                'outbytes' => $totalTxBps,
            ];
        }

        if (($now - $lastCountsCheck) >= 5) {
            $countsCache = [
                'active' => (int)$api->comm('/ip/hotspot/active/print', ['count-only' => '']),
                'total' => (int)$api->comm('/ip/hotspot/user/print', ['count-only' => '']),
            ];
            $lastCountsCheck = $now;
        }

        if (($now - $lastEventsCheck) >= 15) {
            $eventsCache = buildMikrotikRecentEventsForStream($api, 20);
            $lastEventsCheck = $now;
        }

        if (($now - $lastSalesCheck) >= 30) {
            $summary = loadCommercialSummary($pdo);
            $salesCache = [
                'today' => formatCommercialAmount((float)($summary['today_amount'] ?? 0)),
                'month' => formatCommercialAmount((float)($summary['month_amount'] ?? 0)),
                'trend' => $summary['trend'] ?? [],
                'today_count' => (int)($summary['today_count'] ?? 0),
                'month_count' => (int)($summary['month_count'] ?? 0),
            ];
            $lastSalesCheck = $now;
        }

        if (($now - $lastQueueLimitsCheck) >= 120) {
            $queueLimitsCache = ['download_mbps' => null, 'upload_mbps' => null];
            $queueRows = $api->comm('/queue/simple/print', ['.proplist' => 'comment,max-limit,disabled']);
            foreach ((is_array($queueRows) ? $queueRows : []) as $queueRow) {
                $comment = (string)($queueRow['comment'] ?? '');
                if (!str_starts_with($comment, 'Radius Manager - interface queue:') || mikrotikApiBoolean($queueRow['disabled'] ?? false)) {
                    continue;
                }
                $parts = explode('/', trim((string)($queueRow['max-limit'] ?? '')));
                $toMbps = static function (string $part): ?float {
                    $part = trim($part);
                    if (!preg_match('/^([0-9.]+)([kKmMgG]?)$/', $part, $m)) return null;
                    $multiplier = ['k' => 1e3, 'm' => 1e6, 'g' => 1e9][strtolower($m[2])] ?? 1;
                    return ((float)$m[1] * $multiplier) / 1e6;
                };
                $queueLimitsCache = [
                    'upload_mbps' => $toMbps($parts[0] ?? ''),
                    'download_mbps' => $toMbps($parts[1] ?? ''),
                ];
                break;
            }
            $lastQueueLimitsCheck = $now;
        }

        emitSse([
            'hotspot_queue_limits' => $queueLimitsCache,
            'device' => [
                'id' => (string)($device['id'] ?? ''),
                'name' => trim((string)($device['name'] ?? '')) ?: ($boardName !== '' ? $boardName : 'MikroTik'),
                'type' => 'mikrotik',
                'business_source' => resolveDeviceBusinessSource('mikrotik'),
                'host' => (string)($device['host'] ?? ''),
                'ip' => (string)($device['ip'] ?? ''),
                'backend_driver' => deviceBackendDriverForApiResponse($device),
                'model' => $boardName,
                'status' => 'CONNECTED',
                'message' => 'Telemetrie du device chargee via le backend actif.',
                'version' => $version !== '' ? $version : 'Version inconnue',
                'zones' => $trafficInterfaceNames,
                'zone_count' => count($trafficInterfaceNames),
                'telemetry_supported' => true,
                'last_update' => date('H:i:s'),
            ],
            'traffic_live' => $trafficInterfaces !== [] ? [
                'time' => microtime(true),
                'metric_mode' => 'rate',
                'interfaces' => $trafficInterfaces,
                'interface_label' => implode(', ', $trafficInterfaceNames),
                'selected_interface' => $trafficInterfaceNames[0] ?? '',
                'selected_interfaces' => $trafficInterfaceNames,
                'last_update' => date('H:i:s'),
            ] : [
                'supported' => false,
                'message' => 'Aucune source multi-WAN active configurée pour le dashboard.',
                'interfaces' => [],
                'last_update' => date('H:i:s'),
            ],
            'resource_usage' => [
                'cpu' => [
                    'total' => round($cpuLoad, 2),
                    'user' => round($cpuLoad, 2),
                    'system' => 0,
                    'idle' => round(max(0, 100 - $cpuLoad), 2),
                    'last_update' => date('H:i:s'),
                    'supported' => true,
                    'device_type' => 'mikrotik',
                ],
                'memory' => [
                    'used_percent' => $memoryUsedPercent,
                    'total_bytes' => $memoryTotal,
                    'free_bytes' => $memoryFree,
                    'last_update' => date('H:i:s'),
                ],
            ],
            'hotspot' => [
                'active_sessions' => $countsCache['active'],
                'total_users' => $countsCache['total'],
                'last_update' => date('H:i:s'),
            ],
            'commercial_summary' => [
                'today_amount' => $salesCache['today'],
                'month_amount' => $salesCache['month'],
                'today_count' => $salesCache['today_count'],
                'month_count' => $salesCache['month_count'],
                'daily_trend' => $salesCache['trend'],
                'last_update' => date('H:i:s'),
            ],
            'recent_events' => $eventsCache,
        ]);
    } catch (Throwable $e) {
        emitSse([
            'error' => $e->getMessage(),
            'supported' => false,
            'device_type' => 'mikrotik',
            'business_source' => resolveDeviceBusinessSource('mikrotik'),
            'backend_driver' => deviceBackendDriverForApiResponse($device),
        ]);
        if ($api) {
            $api->disconnect();
        }
        exit;
    }

    sleep(2);
}

if ($api) {
    $api->disconnect();
}
