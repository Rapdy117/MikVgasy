<?php

require_once __DIR__ . '/mikrotik_backend.php';
require_once __DIR__ . '/multi_wan_config.php';

function trafficControlWanUsageNow(): DateTimeImmutable
{
    return new DateTimeImmutable('now');
}

function trafficControlCounterDelta(int $current, int $previous): int
{
    // Compteur cumulatif : s'il diminue, il a été remis à zéro et le trafic depuis repart de la valeur courante.
    return $current >= $previous ? $current - $previous : $current;
}

function trafficControlInsertWanUsageSample(PDOStatement $insertStmt, string $deviceId, string $interface, int $rxTotal, int $txTotal, int $bytesDelta, int $sampleTs, string $sampleKind = 'measured'): void
{
    $sampledAt = date('Y-m-d H:i:s', $sampleTs);
    $usageDay = date('Y-m-d', $sampleTs);
    $sampleKind = in_array($sampleKind, ['measured', 'gap_reset'], true) ? $sampleKind : 'measured';
    $insertStmt->execute([$deviceId, $sampledAt, $usageDay, $interface, $rxTotal, $txTotal, max(0, $bytesDelta), $sampleKind]);
}

function trafficControlColumnExists(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
    $stmt->execute([$table, $column]);
    return (int)$stmt->fetchColumn() > 0;
}

function trafficControlEnsureWanUsageTables(PDO $pdo): void
{
    static $done = false;
    if ($done) return;
    $pdo->exec("CREATE TABLE IF NOT EXISTS mikrotik_wan_usage_samples (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        device_id VARCHAR(120) NOT NULL,
        sampled_at DATETIME NOT NULL,
        usage_day DATE NOT NULL,
        interface_name VARCHAR(64) NOT NULL,
        rx_total BIGINT UNSIGNED NOT NULL DEFAULT 0,
        tx_total BIGINT UNSIGNED NOT NULL DEFAULT 0,
        bytes_delta BIGINT UNSIGNED NOT NULL DEFAULT 0,
        sample_kind VARCHAR(16) NOT NULL DEFAULT 'measured',
        PRIMARY KEY (id),
        KEY idx_mikrotik_wan_usage_device_day (device_id, usage_day, interface_name),
        KEY idx_mikrotik_wan_usage_device_time (device_id, sampled_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
    if (!trafficControlColumnExists($pdo, 'mikrotik_wan_usage_samples', 'sample_kind')) {
        $pdo->exec("ALTER TABLE mikrotik_wan_usage_samples ADD sample_kind VARCHAR(16) NOT NULL DEFAULT 'measured' AFTER bytes_delta, ADD KEY idx_mikrotik_wan_usage_kind (device_id, usage_day, interface_name, sample_kind)");
    }
    $pdo->exec("CREATE TABLE IF NOT EXISTS mikrotik_wan_usage_state (
        device_id VARCHAR(120) NOT NULL,
        interface_name VARCHAR(64) NOT NULL,
        rx_total BIGINT UNSIGNED NOT NULL DEFAULT 0,
        tx_total BIGINT UNSIGNED NOT NULL DEFAULT 0,
        updated_at DATETIME NOT NULL,
        PRIMARY KEY (device_id, interface_name)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
    $done = true;
}

function trafficControlRecordWanUsage(PDO $pdo, string $deviceId, array $interfaces, ?array $summaryInterfaces = null): array
{
    if ($deviceId === '') return [];
    trafficControlEnsureWanUsageTables($pdo);
    $now = trafficControlWanUsageNow();
    $sampledAt = $now->format('Y-m-d H:i:s');
    $stateStmt = $pdo->prepare('SELECT rx_total,tx_total,updated_at FROM mikrotik_wan_usage_state WHERE device_id=? AND interface_name=? LIMIT 1');
    $insertStmt = $pdo->prepare('INSERT INTO mikrotik_wan_usage_samples (device_id,sampled_at,usage_day,interface_name,rx_total,tx_total,bytes_delta,sample_kind) VALUES (?,?,?,?,?,?,?,?)');
    $upsertStmt = $pdo->prepare('INSERT INTO mikrotik_wan_usage_state (device_id,interface_name,rx_total,tx_total,updated_at) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE rx_total=VALUES(rx_total),tx_total=VALUES(tx_total),updated_at=VALUES(updated_at)');

    foreach ($interfaces as $row) {
        if (!is_array($row)) continue;
        $interface = trim((string)($row['interface'] ?? ''));
        if ($interface === '') continue;
        $rxTotal = max(0, (int)($row['rx_total'] ?? 0));
        $txTotal = max(0, (int)($row['tx_total'] ?? 0));
        $stateStmt->execute([$deviceId, $interface]);
        $previous = $stateStmt->fetch(PDO::FETCH_ASSOC);
        $bytesDelta = 0;
        $currentTs = strtotime($sampledAt) ?: time();
        if ($previous) {
            $previousAt = trim((string)($previous['updated_at'] ?? ''));
            $previousTs = $previousAt !== '' ? strtotime($previousAt) : false;
            $bytesDelta = trafficControlCounterDelta($rxTotal, (int)($previous['rx_total'] ?? 0))
                + trafficControlCounterDelta($txTotal, (int)($previous['tx_total'] ?? 0));
        }
        trafficControlInsertWanUsageSample($insertStmt, $deviceId, $interface, $rxTotal, $txTotal, $previous ? $bytesDelta : 0, $currentTs);
        $upsertStmt->execute([$deviceId, $interface, $rxTotal, $txTotal, $sampledAt]);
    }
    $pdo->exec('DELETE FROM mikrotik_wan_usage_samples WHERE sampled_at < DATE_SUB(NOW(), INTERVAL 120 DAY)');
    return trafficControlWanUsageSummary($pdo, $deviceId, $summaryInterfaces);
}

function trafficControlWanUsageSummary(PDO $pdo, string $deviceId, ?array $interfaces = null, ?string $usageDay = null): array
{
    if ($deviceId === '') return [];
    trafficControlEnsureWanUsageTables($pdo);
    $interfaces = $interfaces !== null ? array_values(array_unique(array_filter(array_map(static fn($value): string => trim((string)$value), $interfaces), static fn(string $value): bool => $value !== ''))) : null;
    if ($interfaces !== null && $interfaces === []) return [];
    $interfaceWhere = '';
    $interfaceParams = [];
    if ($interfaces !== null) {
        $placeholders = implode(',', array_fill(0, count($interfaces), '?'));
        $interfaceWhere = ' AND interface_name IN (' . $placeholders . ')';
        $interfaceParams = $interfaces;
    }
    $now = trafficControlWanUsageNow();
    $today = $now->format('Y-m-d');
    $requestedDay = trim((string)$usageDay);
    $requestedDate = $requestedDay !== ''
        ? DateTimeImmutable::createFromFormat('!Y-m-d', $requestedDay, $now->getTimezone())
        : null;
    $selectedDay = $requestedDate instanceof DateTimeImmutable
        && $requestedDate->format('Y-m-d') === $requestedDay
        && $requestedDay <= $today
        ? $requestedDay
        : $today;
    $selectedDayEnd = (new DateTimeImmutable($selectedDay . ' 00:00:00', $now->getTimezone()))->modify('+1 day')->format('Y-m-d');
    $monthStart = $now->modify('first day of this month')->format('Y-m-d');
    $nextMonth = $now->modify('first day of next month')->format('Y-m-d');
    $summary = [];

    $hourStmt = $pdo->prepare("SELECT interface_name, DATE_FORMAT(sampled_at,'%Y-%m-%d %H') AS hour_key, COALESCE(SUM(bytes_delta),0) AS bytes FROM mikrotik_wan_usage_samples WHERE device_id=? AND sampled_at>=? AND sampled_at<? AND sample_kind='measured' AND sampled_at <= NOW()" . $interfaceWhere . " GROUP BY interface_name,hour_key");
    $hourStmt->execute(array_merge([$deviceId, $selectedDay . ' 00:00:00', $selectedDayEnd . ' 00:00:00'], $interfaceParams));
    foreach ($hourStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $iface = (string)$row['interface_name'];
        $summary[$iface]['hourly'][(string)$row['hour_key']] = (int)$row['bytes'];
        $summary[$iface]['day_bytes'] = (int)($summary[$iface]['day_bytes'] ?? 0) + (int)$row['bytes'];
    }

    $dayStmt = $pdo->prepare("SELECT interface_name, DATE(sampled_at) AS usage_day, COALESCE(SUM(bytes_delta),0) AS bytes FROM mikrotik_wan_usage_samples WHERE device_id=? AND sampled_at>=? AND sampled_at<? AND sample_kind='measured' AND sampled_at <= NOW()" . $interfaceWhere . " GROUP BY interface_name,usage_day");
    $dayStmt->execute(array_merge([$deviceId, $monthStart . ' 00:00:00', $nextMonth . ' 00:00:00'], $interfaceParams));
    foreach ($dayStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $iface = (string)$row['interface_name'];
        $day = (string)$row['usage_day'];
        $bytes = (int)$row['bytes'];
        $summary[$iface]['daily'][$day] = $bytes;
        if ($day === $selectedDay) $summary[$iface]['day_bytes'] = $bytes;
        $summary[$iface]['month_bytes'] = (int)($summary[$iface]['month_bytes'] ?? 0) + $bytes;
        $summary[$iface]['cycle_bytes'] = (int)($summary[$iface]['cycle_bytes'] ?? 0) + $bytes;
    }

    $stateStmt = $pdo->prepare('SELECT interface_name,rx_total,tx_total,updated_at FROM mikrotik_wan_usage_state WHERE device_id=?' . $interfaceWhere);
    $stateStmt->execute(array_merge([$deviceId], $interfaceParams));
    foreach ($stateStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $iface = (string)$row['interface_name'];
        $summary[$iface]['last_rx_total'] = (int)($row['rx_total'] ?? 0);
        $summary[$iface]['last_tx_total'] = (int)($row['tx_total'] ?? 0);
        $summary[$iface]['last_seen_at'] = (string)($row['updated_at'] ?? '');
        $summary[$iface]['day_key'] = $selectedDay;
        $summary[$iface]['month_key'] = $now->format('Y-m');
        $summary[$iface]['cycle_key'] = $monthStart;
        $summary[$iface]['day_bytes'] = (int)($summary[$iface]['day_bytes'] ?? 0);
        $summary[$iface]['month_bytes'] = (int)($summary[$iface]['month_bytes'] ?? 0);
        $summary[$iface]['cycle_bytes'] = (int)($summary[$iface]['cycle_bytes'] ?? 0);
        $summary[$iface]['hourly'] = $summary[$iface]['hourly'] ?? [];
        $summary[$iface]['daily'] = $summary[$iface]['daily'] ?? [];
    }
    return $summary;
}

function trafficControlCollectWanUsage(PDO $pdo, RouterosAPI $api, array $device): array
{
    $deviceId = trim((string)(($device['id'] ?? '') ?: ($device['host'] ?? '')));
    if ($deviceId === '') return [];

    $config = multiWanLoadConfig($device);
    $scriptRows = $api->comm('/system/script/print', ['?name' => 'RM-NWAN-ASSIGN', '.proplist' => 'source']);
    $scriptSource = is_array($scriptRows) ? (string)($scriptRows[0]['source'] ?? '') : '';
    if ($scriptSource !== '' && function_exists('multiWanParseRouterosAssignScript')) {
        $config = multiWanParseRouterosAssignScript($scriptSource, $config);
    }

    $rows = $api->comm('/interface/print', ['.proplist' => 'name,rx-byte,tx-byte,disabled']);
    if (!is_array($rows) || isset($rows['!trap'])) {
        throw new RuntimeException('RouterOS a refusé la lecture des compteurs WAN.');
    }
    $dhcpRows = $api->comm('/ip/dhcp-client/print', ['.proplist' => 'interface,status,disabled,invalid']);
    $listRows = $api->comm('/interface/list/member/print', ['.proplist' => 'interface,list,disabled']);

    $interfaceRowsByName = [];
    foreach ($rows as $row) {
        if (!is_array($row)) continue;
        $name = trim((string)($row['name'] ?? ''));
        if ($name !== '') $interfaceRowsByName[$name] = $row;
    }
    $wanEligible = [];
    foreach (is_array($dhcpRows) ? $dhcpRows : [] as $row) {
        if (!is_array($row) || mikrotikApiBoolean($row['disabled'] ?? false) || mikrotikApiBoolean($row['invalid'] ?? false)) continue;
        $interface = trim((string)($row['interface'] ?? ''));
        if ($interface !== '' && strtolower((string)($row['status'] ?? '')) === 'bound') $wanEligible[$interface] = true;
    }
    foreach (is_array($listRows) ? $listRows : [] as $row) {
        if (!is_array($row) || mikrotikApiBoolean($row['disabled'] ?? false)) continue;
        $interface = trim((string)($row['interface'] ?? ''));
        if ($interface !== '' && strtoupper(trim((string)($row['list'] ?? ''))) === 'WAN') $wanEligible[$interface] = true;
    }

    $wanted = [];
    foreach (($config['sources'] ?? []) as $source) {
        if (!is_array($source)) continue;
        $interface = trim((string)($source['interface'] ?? ''));
        if ($interface === '' || !isset($interfaceRowsByName[$interface]) || !isset($wanEligible[$interface])) continue;
        if (mikrotikApiBoolean($interfaceRowsByName[$interface]['disabled'] ?? false)) continue;
        $wanted[$interface] = true;
    }
    if ($wanted === []) return trafficControlWanUsageSummary($pdo, $deviceId, []);

    $interfaces = [];
    foreach ($wanted as $name => $_) {
        $row = $interfaceRowsByName[$name];
        $interfaces[] = [
            'interface' => $name,
            'rx_total' => (int)($row['rx-byte'] ?? 0),
            'tx_total' => (int)($row['tx-byte'] ?? 0),
        ];
    }

    return trafficControlRecordWanUsage($pdo, $deviceId, $interfaces, array_keys($wanted));
}
