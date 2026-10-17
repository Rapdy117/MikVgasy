<?php

require_once __DIR__ . '/mikrotik_backend.php';
require_once __DIR__ . '/network_security_monitor.php';

const NETWORK_QUALITY_RETENTION_HOURS = 48;
const NETWORK_QUALITY_UPLOAD_CAPACITY_BPS = 65000000;

function ensureNetworkQualityTables(PDO $pdo): void
{
    static $done = false;
    if ($done) return;

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS network_quality_samples (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            device_id VARCHAR(120) NOT NULL,
            sampled_at DATETIME NOT NULL,
            router_reachable TINYINT(1) NOT NULL DEFAULT 0,
            router_api_ms INT UNSIGNED DEFAULT NULL,
            wan_dhcp_bound TINYINT(1) NOT NULL DEFAULT 0,
            default_route_active TINYINT(1) NOT NULL DEFAULT 0,
            gateway_loss DECIMAL(5,2) DEFAULT NULL,
            cloudflare_loss DECIMAL(5,2) DEFAULT NULL,
            google_loss DECIMAL(5,2) DEFAULT NULL,
            cloudflare_latency_ms DECIMAL(8,2) DEFAULT NULL,
            google_latency_ms DECIMAL(8,2) DEFAULT NULL,
            https_ok TINYINT(1) NOT NULL DEFAULT 0,
            https_total_ms INT UNSIGNED DEFAULT NULL,
            https_dns_ms INT UNSIGNED DEFAULT NULL,
            cpu_load TINYINT UNSIGNED DEFAULT NULL,
            conntrack_entries INT UNSIGNED DEFAULT NULL,
            conntrack_capacity INT UNSIGNED DEFAULT NULL,
            wan_rx_bps BIGINT UNSIGNED DEFAULT NULL,
            wan_tx_bps BIGINT UNSIGNED DEFAULT NULL,
            wan_error_delta INT UNSIGNED NOT NULL DEFAULT 0,
            wan_drop_delta INT UNSIGNED NOT NULL DEFAULT 0,
            lan_error_delta INT UNSIGNED NOT NULL DEFAULT 0,
            lan_drop_delta INT UNSIGNED NOT NULL DEFAULT 0,
            queue_drop_delta INT UNSIGNED NOT NULL DEFAULT 0,
            hotspot_keepalive_timeouts INT UNSIGNED NOT NULL DEFAULT 0,
            dhcp_deassignments INT UNSIGNED NOT NULL DEFAULT 0,
            hotspot_active INT UNSIGNED DEFAULT NULL,
            dhcp_bound INT UNSIGNED DEFAULT NULL,
            router_rebooted TINYINT(1) NOT NULL DEFAULT 0,
            severity VARCHAR(16) NOT NULL DEFAULT 'unknown',
            diagnosis VARCHAR(40) NOT NULL DEFAULT 'insufficient_data',
            details_json LONGTEXT DEFAULT NULL,
            PRIMARY KEY (id),
            KEY idx_network_quality_device_time (device_id, sampled_at),
            KEY idx_network_quality_diagnosis (diagnosis, sampled_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
    );
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS network_quality_monitor_state (
            device_id VARCHAR(120) NOT NULL,
            sampled_at DATETIME DEFAULT NULL,
            last_log_id VARCHAR(40) DEFAULT NULL,
            uptime_seconds INT UNSIGNED DEFAULT NULL,
            wan_rx_bytes BIGINT UNSIGNED DEFAULT NULL,
            wan_tx_bytes BIGINT UNSIGNED DEFAULT NULL,
            wan_rx_errors BIGINT UNSIGNED DEFAULT NULL,
            wan_tx_errors BIGINT UNSIGNED DEFAULT NULL,
            wan_rx_drops BIGINT UNSIGNED DEFAULT NULL,
            wan_tx_drops BIGINT UNSIGNED DEFAULT NULL,
            lan_rx_errors BIGINT UNSIGNED DEFAULT NULL,
            lan_tx_errors BIGINT UNSIGNED DEFAULT NULL,
            lan_rx_drops BIGINT UNSIGNED DEFAULT NULL,
            lan_tx_drops BIGINT UNSIGNED DEFAULT NULL,
            queue_drops BIGINT UNSIGNED DEFAULT NULL,
            PRIMARY KEY (device_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
    );
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS network_quality_queue_state (
            device_id VARCHAR(120) NOT NULL,
            queue_name VARCHAR(180) NOT NULL,
            dropped_total BIGINT UNSIGNED NOT NULL DEFAULT 0,
            packets_total BIGINT UNSIGNED NOT NULL DEFAULT 0,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (device_id, queue_name),
            KEY idx_network_quality_queue_updated (updated_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
    );
    $done = true;
}

function networkQualityBool($value): bool
{
    return is_bool($value) ? $value : in_array(strtolower(trim((string)$value)), ['true', 'yes', '1', 'on'], true);
}

function networkQualityRows(RouterosAPI $api, string $path, array $parameters = []): array
{
    $rows = $api->comm($path, $parameters);
    if (isset($rows['!trap'])) throw new RuntimeException('RouterOS a refusé ' . $path . '.');
    return is_array($rows) ? array_values(array_filter($rows, 'is_array')) : [];
}

function networkQualityDurationMs(string $value): ?float
{
    $raw = strtolower(trim($value));
    if ($raw === '') return null;
    if (preg_match('/^(\d+):([0-5]?\d):([0-5]?\d(?:\.\d+)?)$/', $raw, $match)) {
        return (((int)$match[1] * 3600) + ((int)$match[2] * 60) + (float)$match[3]) * 1000;
    }
    preg_match_all('/([0-9.]+)(ms|us|ns|s)/', $raw, $matches, PREG_SET_ORDER);
    if (!$matches) return null;
    $milliseconds = 0.0;
    foreach ($matches as $match) {
        $milliseconds += (float)$match[1] * match ($match[2]) {
            's' => 1000,
            'ms' => 1,
            'us' => 0.001,
            'ns' => 0.000001,
        };
    }
    return $milliseconds;
}

function networkQualityPing(RouterosAPI $api, string $target, int $count = 5): array
{
    $rows = networkQualityRows($api, '/ping', [
        'address' => $target,
        'count' => (string)$count,
        'interval' => '250ms',
        'size' => '56',
    ]);
    $latencies = [];
    $received = 0;
    foreach ($rows as $row) {
        $latency = networkQualityDurationMs((string)($row['time'] ?? ''));
        if ($latency === null) continue;
        $received++;
        $latencies[] = $latency;
    }
    $sent = max($count, count($rows));
    return [
        'target' => $target,
        'sent' => $sent,
        'received' => $received,
        'loss' => $sent > 0 ? round(($sent - $received) * 100 / $sent, 2) : null,
        'latency_ms' => $latencies ? round(array_sum($latencies) / count($latencies), 2) : null,
        'latency_max_ms' => $latencies ? round(max($latencies), 2) : null,
    ];
}

function networkQualityHttpsProbe(): array
{
    if (!function_exists('curl_init')) {
        return ['ok' => false, 'error' => 'Extension cURL indisponible'];
    }
    $curl = curl_init('https://www.gstatic.com/generate_204');
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_TIMEOUT => 8,
        CURLOPT_USERAGENT => 'RadiusManager-NetworkQuality/1.0',
    ]);
    $body = curl_exec($curl);
    $info = curl_getinfo($curl);
    $error = curl_error($curl);
    $errno = curl_errno($curl);
    curl_close($curl);
    $status = (int)($info['http_code'] ?? 0);
    return [
        'ok' => $errno === 0 && $status >= 200 && $status < 400,
        'status' => $status,
        'dns_ms' => (int)round(((float)($info['namelookup_time'] ?? 0)) * 1000),
        'connect_ms' => (int)round(((float)($info['connect_time'] ?? 0)) * 1000),
        'tls_ms' => (int)round(((float)($info['appconnect_time'] ?? 0)) * 1000),
        'total_ms' => (int)round(((float)($info['total_time'] ?? 0)) * 1000),
        'error' => $error,
        'response_bytes' => is_string($body) ? strlen($body) : 0,
    ];
}

function networkQualityCounterDelta($current, $previous): int
{
    if ($previous === null || $previous === '') return 0;
    return max(0, (int)$current - (int)$previous);
}

function networkQualityPairTotal(string $value): int
{
    return array_sum(array_map('intval', explode('/', $value)));
}

function networkQualityQueueState(PDO $pdo, string $deviceId): array
{
    $stmt = $pdo->prepare('SELECT queue_name,dropped_total,packets_total FROM network_quality_queue_state WHERE device_id=?');
    $stmt->execute([$deviceId]);
    $state = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) $state[(string)$row['queue_name']] = $row;
    return $state;
}

function networkQualitySaveQueueState(PDO $pdo, string $deviceId, array $queues, string $sampledAt): void
{
    $stmt = $pdo->prepare(
        'INSERT INTO network_quality_queue_state (device_id,queue_name,dropped_total,packets_total,updated_at)
         VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE dropped_total=VALUES(dropped_total),packets_total=VALUES(packets_total),updated_at=VALUES(updated_at)'
    );
    foreach ($queues as $queue) {
        $stmt->execute([$deviceId, $queue['name'], $queue['dropped_total'], $queue['packets_total'], $sampledAt]);
    }
    $pdo->prepare('DELETE FROM network_quality_queue_state WHERE device_id=? AND updated_at<?')->execute([$deviceId, $sampledAt]);
}

function networkQualityInterfaceMap(array $rows): array
{
    $map = [];
    foreach ($rows as $row) {
        $name = trim((string)($row['name'] ?? ''));
        if ($name !== '') $map[$name] = $row;
    }
    return $map;
}

function networkQualityNewLogs(array $logs, string $lastLogId): array
{
    if ($lastLogId === '') return [];
    $found = false;
    $newRows = [];
    foreach ($logs as $row) {
        $id = (string)($row['.id'] ?? '');
        if ($found) $newRows[] = $row;
        if ($id === $lastLogId) $found = true;
    }
    return $found ? $newRows : [];
}

function networkQualityState(PDO $pdo, string $deviceId): array
{
    ensureNetworkQualityTables($pdo);
    $stmt = $pdo->prepare('SELECT * FROM network_quality_monitor_state WHERE device_id=? LIMIT 1');
    $stmt->execute([$deviceId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : [];
}

function networkQualitySaveState(PDO $pdo, string $deviceId, array $state): void
{
    $stmt = $pdo->prepare(
        'INSERT INTO network_quality_monitor_state
        (device_id,sampled_at,last_log_id,uptime_seconds,wan_rx_bytes,wan_tx_bytes,wan_rx_errors,wan_tx_errors,wan_rx_drops,wan_tx_drops,lan_rx_errors,lan_tx_errors,lan_rx_drops,lan_tx_drops,queue_drops)
        VALUES (:device_id,:sampled_at,:last_log_id,:uptime_seconds,:wan_rx_bytes,:wan_tx_bytes,:wan_rx_errors,:wan_tx_errors,:wan_rx_drops,:wan_tx_drops,:lan_rx_errors,:lan_tx_errors,:lan_rx_drops,:lan_tx_drops,:queue_drops)
        ON DUPLICATE KEY UPDATE sampled_at=VALUES(sampled_at),last_log_id=VALUES(last_log_id),uptime_seconds=VALUES(uptime_seconds),wan_rx_bytes=VALUES(wan_rx_bytes),wan_tx_bytes=VALUES(wan_tx_bytes),wan_rx_errors=VALUES(wan_rx_errors),wan_tx_errors=VALUES(wan_tx_errors),wan_rx_drops=VALUES(wan_rx_drops),wan_tx_drops=VALUES(wan_tx_drops),lan_rx_errors=VALUES(lan_rx_errors),lan_tx_errors=VALUES(lan_tx_errors),lan_rx_drops=VALUES(lan_rx_drops),lan_tx_drops=VALUES(lan_tx_drops),queue_drops=VALUES(queue_drops)'
    );
    $stmt->execute([':device_id' => $deviceId] + $state);
}

function networkQualityClassify(array $sample): array
{
    if (empty($sample['router_reachable'])) return ['critical', 'mikrotik_or_lan'];
    if (!empty($sample['router_rebooted'])) return ['critical', 'mikrotik_reboot'];
    $externalLoss = max((float)($sample['cloudflare_loss'] ?? 0), (float)($sample['google_loss'] ?? 0));
    $bothExternalDegraded = (float)($sample['cloudflare_loss'] ?? 0) > 0 && (float)($sample['google_loss'] ?? 0) > 0;
    $bothExternalFail = (float)($sample['cloudflare_loss'] ?? 0) >= 40 && (float)($sample['google_loss'] ?? 0) >= 40;
    if (empty($sample['wan_dhcp_bound']) || empty($sample['default_route_active']) || $bothExternalFail) return ['critical', 'starlink_or_upstream'];
    if (empty($sample['https_ok']) && $externalLoss < 40) return ['warning', 'dns_or_https'];
    if ($bothExternalDegraded) return ['warning', 'intermittent_wan'];
    if ((int)($sample['wan_error_delta'] ?? 0) > 0) return ['critical', 'wan_interface'];
    if ((int)($sample['lan_error_delta'] ?? 0) > 0 || (int)($sample['lan_drop_delta'] ?? 0) > 0) return ['critical', 'lan_interface'];
    if ((int)($sample['hotspot_keepalive_timeouts'] ?? 0) >= 1 || (int)($sample['dhcp_deassignments'] ?? 0) >= 5) return ['warning', 'lan_clients_or_wifi'];
    $conntrackRatio = (int)($sample['conntrack_capacity'] ?? 0) > 0
        ? (int)$sample['conntrack_entries'] / (int)$sample['conntrack_capacity']
        : 0;
    if ((int)($sample['cpu_load'] ?? 0) >= 85 || $conntrackRatio >= 0.8) return ['critical', 'mikrotik_pressure'];
    if ((int)($sample['wan_tx_bps'] ?? 0) >= (int)(NETWORK_QUALITY_UPLOAD_CAPACITY_BPS * 0.9)) return ['warning', 'upload_saturation'];
    if ($externalLoss > 0) return ['warning', 'single_probe_loss'];
    if ((int)($sample['queue_drop_delta'] ?? 0) > 0) return ['warning', 'hotspot_shaping'];
    return ['healthy', 'healthy'];
}

function networkQualityInsertSample(PDO $pdo, array $sample): int
{
    ensureNetworkQualityTables($pdo);
    [$severity, $diagnosis] = networkQualityClassify($sample);
    $sample['severity'] = $severity;
    $sample['diagnosis'] = $diagnosis;
    $columns = [
        'device_id','sampled_at','router_reachable','router_api_ms','wan_dhcp_bound','default_route_active',
        'gateway_loss','cloudflare_loss','google_loss','cloudflare_latency_ms','google_latency_ms','https_ok',
        'https_total_ms','https_dns_ms','cpu_load','conntrack_entries','conntrack_capacity','wan_rx_bps','wan_tx_bps',
        'wan_error_delta','wan_drop_delta','lan_error_delta','lan_drop_delta','queue_drop_delta',
        'hotspot_keepalive_timeouts','dhcp_deassignments','hotspot_active','dhcp_bound','router_rebooted',
        'severity','diagnosis','details_json',
    ];
    $defaults = [
        'router_reachable' => 0,
        'wan_dhcp_bound' => 0,
        'default_route_active' => 0,
        'https_ok' => 0,
        'wan_error_delta' => 0,
        'wan_drop_delta' => 0,
        'lan_error_delta' => 0,
        'lan_drop_delta' => 0,
        'queue_drop_delta' => 0,
        'hotspot_keepalive_timeouts' => 0,
        'dhcp_deassignments' => 0,
        'router_rebooted' => 0,
    ];
    $params = [];
    foreach ($columns as $column) $params[':' . $column] = $sample[$column] ?? ($defaults[$column] ?? null);
    $sql = 'INSERT INTO network_quality_samples (' . implode(',', $columns) . ') VALUES (' . implode(',', array_keys($params)) . ')';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return (int)$pdo->lastInsertId();
}

function collectNetworkQualitySample(PDO $pdo, array $device): array
{
    ensureNetworkQualityTables($pdo);
    $deviceId = trim((string)($device['id'] ?? ''));
    if ($deviceId === '') throw new RuntimeException('Device MikroTik sans identifiant.');
    $previous = networkQualityState($pdo, $deviceId);
    $startedAt = microtime(true);
    $api = connectToMikrotikApiByDevice($device);
    $routerApiMs = (int)round((microtime(true) - $startedAt) * 1000);
    try {
        $sampledAt = date('Y-m-d H:i:s');
        $resource = networkQualityRows($api, '/system/resource/print')[0] ?? [];
        $uptimeSeconds = parseRouterosIntervalToSeconds((string)($resource['uptime'] ?? ''));
        $routes = networkQualityRows($api, '/ip/route/print', ['.proplist' => 'dst-address,gateway,immediate-gw,active,disabled,unreachable,routing-table']);
        $defaultRoute = null;
        foreach ($routes as $route) {
            if (($route['dst-address'] ?? '') === '0.0.0.0/0' && networkQualityBool($route['active'] ?? false)) { $defaultRoute = $route; break; }
        }
        $immediateGateway = trim((string)($defaultRoute['immediate-gw'] ?? ''));
        $wanInterface = str_contains($immediateGateway, '%') ? substr(strrchr($immediateGateway, '%'), 1) : 'ether1';
        $gateway = trim((string)($defaultRoute['gateway'] ?? ''));
        $gateway = preg_replace('/%.*/', '', $gateway) ?? $gateway;
        $dhcpClients = networkQualityRows($api, '/ip/dhcp-client/print', ['.proplist' => 'interface,status,address,gateway,expires-after,disabled']);
        $wanDhcpBound = false;
        foreach ($dhcpClients as $client) {
            if (($client['interface'] ?? '') === $wanInterface && strtolower((string)($client['status'] ?? '')) === 'bound') $wanDhcpBound = true;
        }
        $interfaces = networkQualityInterfaceMap(networkQualityRows($api, '/interface/print', [
            '.proplist' => 'name,running,rx-byte,tx-byte,rx-drop,tx-drop,rx-error,tx-error,link-downs',
        ]));
        $wan = $interfaces[$wanInterface] ?? [];
        $lanInterface = 'bridge1';
        $lan = $interfaces[$lanInterface] ?? [];
        $tracking = networkQualityRows($api, '/ip/firewall/connection/tracking/print')[0] ?? [];
        $hotspotActive = (int)$api->comm('/ip/hotspot/active/print', ['count-only' => '']);
        $dhcpLeaseRows = networkQualityRows($api, '/ip/dhcp-server/lease/print', ['.proplist' => '.id,address,status']);
        $dhcpBound = count(array_filter($dhcpLeaseRows, static fn(array $lease): bool => strtolower((string)($lease['status'] ?? '')) === 'bound'));
        $queues = networkQualityRows($api, '/queue/simple/print', ['.proplist' => 'name,target,rate,dropped,packets']);
        $previousQueues = networkQualityQueueState($pdo, $deviceId);
        $queueDrops = 0;
        $queueDropDelta = 0;
        $queueStateRows = [];
        $topQueueDrops = [];
        foreach ($queues as $queue) {
            $queueName = (string)($queue['name'] ?? '');
            if ($queueName === '') continue;
            $droppedTotal = networkQualityPairTotal((string)($queue['dropped'] ?? '0/0'));
            $packetsTotal = networkQualityPairTotal((string)($queue['packets'] ?? '0/0'));
            $previousQueue = $previousQueues[$queueName] ?? [];
            $dropDelta = networkQualityCounterDelta($droppedTotal, $previousQueue['dropped_total'] ?? null);
            $packetDelta = networkQualityCounterDelta($packetsTotal, $previousQueue['packets_total'] ?? null);
            $queueDrops += $droppedTotal;
            $queueDropDelta += $dropDelta;
            $queueStateRows[] = ['name' => $queueName, 'dropped_total' => $droppedTotal, 'packets_total' => $packetsTotal];
            if ($dropDelta > 0) {
                $topQueueDrops[] = [
                    'name' => $queueName,
                    'target' => (string)($queue['target'] ?? ''),
                    'rate' => (string)($queue['rate'] ?? ''),
                    'dropped' => $dropDelta,
                    'packets' => $packetDelta,
                    'drop_percent' => $packetDelta > 0 ? round($dropDelta * 100 / $packetDelta, 2) : null,
                ];
            }
        }
        usort($topQueueDrops, static fn(array $left, array $right): int => $right['dropped'] <=> $left['dropped']);
        $logs = networkQualityRows($api, '/log/print', ['.proplist' => '.id,time,topics,message']);
        $lastLogId = $logs ? (string)($logs[array_key_last($logs)]['.id'] ?? '') : '';
        $newLogs = networkQualityNewLogs($logs, (string)($previous['last_log_id'] ?? ''));
        $keepaliveKeys = [];
        $dhcpDeassignments = 0;
        foreach ($newLogs as $log) {
            $message = (string)($log['message'] ?? '');
            if (stripos($message, 'logged out: keepalive timeout') !== false) {
                $normalizedMessage = preg_replace('/^->:\s*/', '', $message) ?? $message;
                $key = (string)($log['time'] ?? '') . '|' . $normalizedMessage;
                $keepaliveKeys[$key] = true;
            }
            if (stripos($message, ' deassigned ') !== false) $dhcpDeassignments++;
        }
        $elapsed = !empty($previous['sampled_at']) ? max(1, time() - strtotime((string)$previous['sampled_at'])) : 0;
        $wanRxBps = $elapsed > 0 ? (int)round(networkQualityCounterDelta($wan['rx-byte'] ?? 0, $previous['wan_rx_bytes'] ?? null) * 8 / $elapsed) : null;
        $wanTxBps = $elapsed > 0 ? (int)round(networkQualityCounterDelta($wan['tx-byte'] ?? 0, $previous['wan_tx_bytes'] ?? null) * 8 / $elapsed) : null;
        $gatewayProbe = filter_var($gateway, FILTER_VALIDATE_IP) ? networkQualityPing($api, $gateway) : ['loss' => null];
        $cloudflareProbe = networkQualityPing($api, '1.1.1.1');
        $googleProbe = networkQualityPing($api, '8.8.8.8');
        $phpServerProbe = networkQualityPing($api, '10.10.10.2', 3);
        $httpsProbe = networkQualityHttpsProbe();
        $sample = [
            'device_id' => $deviceId,
            'sampled_at' => $sampledAt,
            'router_reachable' => 1,
            'router_api_ms' => $routerApiMs,
            'wan_dhcp_bound' => $wanDhcpBound ? 1 : 0,
            'default_route_active' => $defaultRoute ? 1 : 0,
            'gateway_loss' => $gatewayProbe['loss'] ?? null,
            'cloudflare_loss' => $cloudflareProbe['loss'] ?? null,
            'google_loss' => $googleProbe['loss'] ?? null,
            'cloudflare_latency_ms' => $cloudflareProbe['latency_ms'] ?? null,
            'google_latency_ms' => $googleProbe['latency_ms'] ?? null,
            'https_ok' => !empty($httpsProbe['ok']) ? 1 : 0,
            'https_total_ms' => $httpsProbe['total_ms'] ?? null,
            'https_dns_ms' => $httpsProbe['dns_ms'] ?? null,
            'cpu_load' => isset($resource['cpu-load']) ? (int)$resource['cpu-load'] : null,
            'conntrack_entries' => isset($tracking['total-entries']) ? (int)$tracking['total-entries'] : null,
            'conntrack_capacity' => isset($tracking['max-entries']) ? (int)$tracking['max-entries'] : null,
            'wan_rx_bps' => $wanRxBps,
            'wan_tx_bps' => $wanTxBps,
            'wan_error_delta' => networkQualityCounterDelta(($wan['rx-error'] ?? 0) + ($wan['tx-error'] ?? 0), ($previous['wan_rx_errors'] ?? 0) + ($previous['wan_tx_errors'] ?? 0)),
            'wan_drop_delta' => networkQualityCounterDelta(($wan['rx-drop'] ?? 0) + ($wan['tx-drop'] ?? 0), ($previous['wan_rx_drops'] ?? 0) + ($previous['wan_tx_drops'] ?? 0)),
            'lan_error_delta' => networkQualityCounterDelta(($lan['rx-error'] ?? 0) + ($lan['tx-error'] ?? 0), ($previous['lan_rx_errors'] ?? 0) + ($previous['lan_tx_errors'] ?? 0)),
            'lan_drop_delta' => networkQualityCounterDelta(($lan['rx-drop'] ?? 0) + ($lan['tx-drop'] ?? 0), ($previous['lan_rx_drops'] ?? 0) + ($previous['lan_tx_drops'] ?? 0)),
            'queue_drop_delta' => $queueDropDelta,
            'hotspot_keepalive_timeouts' => count($keepaliveKeys),
            'dhcp_deassignments' => $dhcpDeassignments,
            'hotspot_active' => $hotspotActive,
            'dhcp_bound' => $dhcpBound,
            'router_rebooted' => !empty($previous['uptime_seconds']) && $uptimeSeconds < (int)$previous['uptime_seconds'] ? 1 : 0,
            'details_json' => json_encode([
                'wan_interface' => $wanInterface,
                'lan_interface' => $lanInterface,
                'gateway' => $gateway,
                'gateway_probe' => $gatewayProbe,
                'cloudflare_probe' => $cloudflareProbe,
                'google_probe' => $googleProbe,
                'php_server_probe' => $phpServerProbe,
                'https_probe' => $httpsProbe,
                'top_queue_drops' => array_slice($topQueueDrops, 0, 8),
                'wan_link_downs' => (int)($wan['link-downs'] ?? 0),
                'lan_link_downs' => (int)($lan['link-downs'] ?? 0),
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ];
        networkQualityInsertSample($pdo, $sample);
        $sample['network_security'] = collectNetworkSecuritySample($pdo, $api, $device, $sampledAt);
        networkQualitySaveQueueState($pdo, $deviceId, $queueStateRows, $sampledAt);
        networkQualitySaveState($pdo, $deviceId, [
            ':sampled_at' => $sampledAt,
            ':last_log_id' => $lastLogId ?: null,
            ':uptime_seconds' => $uptimeSeconds,
            ':wan_rx_bytes' => (int)($wan['rx-byte'] ?? 0),
            ':wan_tx_bytes' => (int)($wan['tx-byte'] ?? 0),
            ':wan_rx_errors' => (int)($wan['rx-error'] ?? 0),
            ':wan_tx_errors' => (int)($wan['tx-error'] ?? 0),
            ':wan_rx_drops' => (int)($wan['rx-drop'] ?? 0),
            ':wan_tx_drops' => (int)($wan['tx-drop'] ?? 0),
            ':lan_rx_errors' => (int)($lan['rx-error'] ?? 0),
            ':lan_tx_errors' => (int)($lan['tx-error'] ?? 0),
            ':lan_rx_drops' => (int)($lan['rx-drop'] ?? 0),
            ':lan_tx_drops' => (int)($lan['tx-drop'] ?? 0),
            ':queue_drops' => $queueDrops,
        ]);
        $pdo->exec('DELETE FROM network_quality_samples WHERE sampled_at < DATE_SUB(NOW(), INTERVAL ' . NETWORK_QUALITY_RETENTION_HOURS . ' HOUR)');
        [$sample['severity'], $sample['diagnosis']] = networkQualityClassify($sample);
        return $sample;
    } finally {
        $api->disconnect();
    }
}

function saveNetworkQualityFailure(PDO $pdo, array $device, Throwable $error): void
{
    networkQualityInsertSample($pdo, [
        'device_id' => (string)($device['id'] ?? ''),
        'sampled_at' => date('Y-m-d H:i:s'),
        'router_reachable' => 0,
        'severity' => 'critical',
        'diagnosis' => 'mikrotik_or_lan',
        'details_json' => json_encode(['collector_error' => $error->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ]);
}

function networkQualityReport(PDO $pdo, string $deviceId, int $hours): array
{
    ensureNetworkQualityTables($pdo);
    $hours = in_array($hours, [6, 24, 48], true) ? $hours : 24;
    $where = 'device_id=:device_id AND sampled_at >= DATE_SUB(NOW(), INTERVAL ' . $hours . ' HOUR)';
    $decodeRows = static function (array $rows): array {
        foreach ($rows as &$row) {
            $row['details'] = json_decode((string)($row['details_json'] ?? ''), true) ?: [];
            unset($row['details_json']);
        }
        unset($row);
        return $rows;
    };

    $summaryStmt = $pdo->prepare(
        'SELECT COUNT(*) AS sample_count,
            SUM(CASE WHEN severity<>\'healthy\' THEN 1 ELSE 0 END) AS incident_count,
            ROUND(AVG(https_ok)*100,2) AS https_availability,
            MAX(wan_rx_bps) AS max_wan_rx_bps,
            MAX(wan_tx_bps) AS max_wan_tx_bps,
            MAX(cpu_load) AS max_cpu_load,
            MAX(conntrack_entries) AS max_conntrack_entries,
            SUM(hotspot_keepalive_timeouts) AS hotspot_keepalive_timeouts,
            SUM(dhcp_deassignments) AS dhcp_deassignments,
            SUM(queue_drop_delta) AS queue_drops
        FROM network_quality_samples WHERE ' . $where
    );
    $summaryStmt->execute([':device_id' => $deviceId]);
    $aggregate = $summaryStmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $diagnosisStmt = $pdo->prepare('SELECT diagnosis,COUNT(*) AS total FROM network_quality_samples WHERE ' . $where . ' GROUP BY diagnosis ORDER BY total DESC');
    $diagnosisStmt->execute([':device_id' => $deviceId]);
    $diagnoses = [];
    foreach ($diagnosisStmt->fetchAll(PDO::FETCH_ASSOC) as $diagnosisRow) {
        $diagnoses[(string)$diagnosisRow['diagnosis']] = (int)$diagnosisRow['total'];
    }

    $latestStmt = $pdo->prepare('SELECT * FROM network_quality_samples WHERE ' . $where . ' ORDER BY sampled_at DESC LIMIT 1');
    $latestStmt->execute([':device_id' => $deviceId]);
    $latestRows = $decodeRows($latestStmt->fetchAll(PDO::FETCH_ASSOC));
    $latest = $latestRows[0] ?? null;

    $incidentStmt = $pdo->prepare('SELECT * FROM network_quality_samples WHERE ' . $where . ' AND severity<>\'healthy\' ORDER BY sampled_at DESC LIMIT 50');
    $incidentStmt->execute([':device_id' => $deviceId]);
    $incidentRows = $decodeRows($incidentStmt->fetchAll(PDO::FETCH_ASSOC));

    $recentStmt = $pdo->prepare('SELECT * FROM network_quality_samples WHERE ' . $where . ' ORDER BY sampled_at DESC LIMIT 120');
    $recentStmt->execute([':device_id' => $deviceId]);
    $recentRows = $decodeRows($recentStmt->fetchAll(PDO::FETCH_ASSOC));

    $sampleCount = (int)($aggregate['sample_count'] ?? 0);
    return [
        'hours' => $hours,
        'retention_hours' => NETWORK_QUALITY_RETENTION_HOURS,
        'sample_count' => $sampleCount,
        'latest' => $latest,
        'summary' => [
            'dominant_diagnosis' => array_key_first($diagnoses) ?: 'insufficient_data',
            'diagnosis_counts' => $diagnoses,
            'incident_count' => (int)($aggregate['incident_count'] ?? 0),
            'https_availability' => $sampleCount > 0 ? (float)$aggregate['https_availability'] : null,
            'max_wan_rx_bps' => isset($aggregate['max_wan_rx_bps']) ? (int)$aggregate['max_wan_rx_bps'] : null,
            'max_wan_tx_bps' => isset($aggregate['max_wan_tx_bps']) ? (int)$aggregate['max_wan_tx_bps'] : null,
            'max_cpu_load' => isset($aggregate['max_cpu_load']) ? (int)$aggregate['max_cpu_load'] : null,
            'max_conntrack_entries' => isset($aggregate['max_conntrack_entries']) ? (int)$aggregate['max_conntrack_entries'] : null,
            'hotspot_keepalive_timeouts' => (int)($aggregate['hotspot_keepalive_timeouts'] ?? 0),
            'dhcp_deassignments' => (int)($aggregate['dhcp_deassignments'] ?? 0),
            'queue_drops' => (int)($aggregate['queue_drops'] ?? 0),
        ],
        'incidents' => $incidentRows,
        'recent_samples' => $recentRows,
        'security' => networkSecurityReport($pdo, $deviceId, $hours),
    ];
}
