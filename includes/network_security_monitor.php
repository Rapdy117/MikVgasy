<?php

const NETWORK_SECURITY_RETENTION_HOURS = 48;

function ensureNetworkSecurityTables(PDO $pdo): void
{
    static $done = false;
    if ($done) return;

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS network_security_samples (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            device_id VARCHAR(120) NOT NULL,
            sampled_at DATETIME NOT NULL,
            collection_ms INT UNSIGNED NOT NULL DEFAULT 0,
            dot_connections INT UNSIGNED NOT NULL DEFAULT 0,
            doq_connections INT UNSIGNED NOT NULL DEFAULT 0,
            doh_suspected_connections INT UNSIGNED NOT NULL DEFAULT 0,
            observed_clients INT UNSIGNED NOT NULL DEFAULT 0,
            observed_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
            forced_dns_udp_delta BIGINT UNSIGNED NOT NULL DEFAULT 0,
            forced_dns_tcp_delta BIGINT UNSIGNED NOT NULL DEFAULT 0,
            starlink_udp_drop_packets_delta BIGINT UNSIGNED NOT NULL DEFAULT 0,
            starlink_udp_drop_bytes_delta BIGINT UNSIGNED NOT NULL DEFAULT 0,
            wan_drop_packets_delta BIGINT UNSIGNED NOT NULL DEFAULT 0,
            details_json LONGTEXT DEFAULT NULL,
            PRIMARY KEY (id),
            KEY idx_network_security_device_time (device_id, sampled_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
    );
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS network_security_client_samples (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            device_id VARCHAR(120) NOT NULL,
            sampled_at DATETIME NOT NULL,
            client_ip VARCHAR(45) NOT NULL,
            username VARCHAR(120) DEFAULT NULL,
            signal_type VARCHAR(24) NOT NULL,
            connections INT UNSIGNED NOT NULL DEFAULT 0,
            bytes_total BIGINT UNSIGNED NOT NULL DEFAULT 0,
            destinations_json LONGTEXT DEFAULT NULL,
            PRIMARY KEY (id),
            KEY idx_network_security_client_time (device_id, sampled_at),
            KEY idx_network_security_client_signal (device_id, signal_type, sampled_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
    );
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS network_security_monitor_state (
            device_id VARCHAR(120) NOT NULL,
            counters_json LONGTEXT DEFAULT NULL,
            sampled_at DATETIME DEFAULT NULL,
            PRIMARY KEY (device_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
    );
    $done = true;
}

function networkSecurityRows(RouterosAPI $api, string $path, array $parameters = []): array
{
    $rows = $api->comm($path, $parameters);
    if (is_array($rows) && isset($rows['!trap'])) {
        throw new RuntimeException('RouterOS a refusé la lecture sécurité ' . $path . '.');
    }
    return is_array($rows) ? array_values(array_filter($rows, 'is_array')) : [];
}

function networkSecurityClientIp(string $value): string
{
    $value = trim($value);
    if (filter_var($value, FILTER_VALIDATE_IP)) return $value;
    if (preg_match('/^\[([^]]+)](?::\d+)?$/', $value, $match)) return $match[1];
    if (preg_match('/^((?:\d{1,3}\.){3}\d{1,3})(?::\d+)?$/', $value, $match)) return $match[1];
    return '';
}

function networkSecurityIsManagedClient(string $ip): bool
{
    return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false
        && ip2long($ip) >= ip2long('10.10.10.0')
        && ip2long($ip) <= ip2long('10.10.11.255');
}

function networkSecurityCounterDelta(int $current, ?int $previous): int
{
    return $previous === null ? 0 : max(0, $current - $previous);
}

function networkSecurityRuleKey(string $table, array $row): string
{
    return $table . ':' . strtolower(trim((string)($row['comment'] ?? '')));
}

function collectNetworkSecuritySample(PDO $pdo, RouterosAPI $api, array $device, string $sampledAt): array
{
    ensureNetworkSecurityTables($pdo);
    $started = microtime(true);
    $deviceId = trim((string)($device['id'] ?? ''));
    if ($deviceId === '') throw new RuntimeException('Device MikroTik sans identifiant pour la sécurité réseau.');

    $activeUsers = [];
    foreach (networkSecurityRows($api, '/ip/hotspot/active/print', ['.proplist' => 'address,user']) as $row) {
        $ip = networkSecurityClientIp((string)($row['address'] ?? ''));
        if ($ip !== '') $activeUsers[$ip] = trim((string)($row['user'] ?? ''));
    }

    $connectionSets = [
        'dot' => networkSecurityRows($api, '/ip/firewall/connection/print', [
            '?protocol' => 'tcp', '?dst-port' => '853',
            '.proplist' => 'src-address,dst-address,protocol,dst-port,orig-bytes,repl-bytes',
        ]),
        'doq' => networkSecurityRows($api, '/ip/firewall/connection/print', [
            '?protocol' => 'udp', '?dst-port' => '853',
            '.proplist' => 'src-address,dst-address,protocol,dst-port,orig-bytes,repl-bytes',
        ]),
    ];

    $knownDohHosts = ['cloudflare-dns.com', 'dns.google', 'dns.quad9.net', 'dns.nextdns.io', 'doh.opendns.com'];
    $knownDohAddresses = [];
    foreach ($knownDohHosts as $host) {
        foreach (networkSecurityRows($api, '/ip/dns/cache/print', ['?name' => $host, '.proplist' => 'name,data,type']) as $row) {
            $address = trim((string)($row['data'] ?? ''));
            if (filter_var($address, FILTER_VALIDATE_IP)) $knownDohAddresses[$address] = $host;
        }
    }
    $connectionSets['doh_suspected'] = [];
    foreach ($knownDohAddresses as $address => $host) {
        foreach (['tcp', 'udp'] as $protocol) {
            $rows = networkSecurityRows($api, '/ip/firewall/connection/print', [
                '?protocol' => $protocol, '?dst-port' => '443', '?dst-address' => $address,
                '.proplist' => 'src-address,dst-address,protocol,dst-port,orig-bytes,repl-bytes',
            ]);
            foreach ($rows as $row) {
                $row['known-doh-host'] = $host;
                $connectionSets['doh_suspected'][] = $row;
            }
        }
    }

    $clients = [];
    $signalCounts = ['dot' => 0, 'doq' => 0, 'doh_suspected' => 0];
    $observedBytes = 0;
    foreach ($connectionSets as $signal => $rows) {
        foreach ($rows as $row) {
            $clientIp = networkSecurityClientIp((string)($row['src-address'] ?? ''));
            if (!networkSecurityIsManagedClient($clientIp)) continue;
            $destination = networkSecurityClientIp((string)($row['dst-address'] ?? ''));
            $bytes = max(0, (int)($row['orig-bytes'] ?? 0)) + max(0, (int)($row['repl-bytes'] ?? 0));
            $key = $clientIp . '|' . $signal;
            if (!isset($clients[$key])) {
                $clients[$key] = [
                    'client_ip' => $clientIp,
                    'username' => $activeUsers[$clientIp] ?? '',
                    'signal_type' => $signal,
                    'connections' => 0,
                    'bytes_total' => 0,
                    'destinations' => [],
                ];
            }
            $clients[$key]['connections']++;
            $clients[$key]['bytes_total'] += $bytes;
            if ($destination !== '') {
                $destinationLabel = $destination;
                if (!empty($row['known-doh-host'])) $destinationLabel .= ' (' . $row['known-doh-host'] . ')';
                $clients[$key]['destinations'][$destinationLabel] = true;
            }
            $signalCounts[$signal]++;
            $observedBytes += $bytes;
        }
    }

    $currentCounters = [];
    $wanDropPackets = 0;
    $wanRuleRows = [];
    $starlinkUdpPackets = 0;
    $starlinkUdpBytes = 0;
    $forcedDnsUdp = 0;
    $forcedDnsTcp = 0;
    foreach (['filter', 'nat'] as $table) {
        foreach (networkSecurityRows($api, '/ip/firewall/' . $table . '/print', [
            '.proplist' => '.id,chain,action,protocol,dst-port,in-interface,in-interface-list,comment,disabled,packets,bytes',
        ]) as $rule) {
            if (networkQualityBool($rule['disabled'] ?? false)) continue;
            $key = networkSecurityRuleKey($table, $rule);
            $packets = max(0, (int)($rule['packets'] ?? 0));
            $bytes = max(0, (int)($rule['bytes'] ?? 0));
            $currentCounters[$key] = ['packets' => $packets, 'bytes' => $bytes];
            $comment = strtolower((string)($rule['comment'] ?? ''));
            if ($table === 'filter'
                && in_array(strtolower((string)($rule['action'] ?? '')), ['drop', 'reject', 'tarpit'], true)
                && (strtolower((string)($rule['in-interface'] ?? '')) === 'ether1' || strtoupper((string)($rule['in-interface-list'] ?? '')) === 'WAN')) {
                $wanDropPackets += $packets;
                $wanRuleRows[$key] = [
                    'chain' => (string)($rule['chain'] ?? ''),
                    'action' => (string)($rule['action'] ?? ''),
                    'protocol' => (string)($rule['protocol'] ?? ''),
                    'dst_port' => (string)($rule['dst-port'] ?? ''),
                    'comment' => (string)($rule['comment'] ?? ''),
                    'packets' => $packets,
                    'bytes' => $bytes,
                ];
            }
            if ($table === 'filter' && str_contains($comment, 'starlink-shield: bloquer sessions udp excessives')) {
                $starlinkUdpPackets = $packets;
                $starlinkUdpBytes = $bytes;
            }
            if ($table === 'nat' && str_contains($comment, 'starlink-shield: force dns local')) {
                if (strtolower((string)($rule['protocol'] ?? '')) === 'udp') $forcedDnsUdp = $packets;
                if (strtolower((string)($rule['protocol'] ?? '')) === 'tcp') $forcedDnsTcp = $packets;
            }
        }
    }

    $stateStmt = $pdo->prepare('SELECT counters_json FROM network_security_monitor_state WHERE device_id=? LIMIT 1');
    $stateStmt->execute([$deviceId]);
    $previousCounters = json_decode((string)($stateStmt->fetchColumn() ?: ''), true);
    $previousCounters = is_array($previousCounters) ? $previousCounters : [];
    $previousValue = static function (array $state, string $key, string $field): ?int {
        return isset($state[$key][$field]) ? (int)$state[$key][$field] : null;
    };
    $counterByComment = static function (array $counters, string $needle, string $field): ?int {
        foreach ($counters as $key => $values) if (str_contains($key, $needle)) return (int)($values[$field] ?? 0);
        return null;
    };
    $previousForcedUdp = $counterByComment($previousCounters, 'starlink-shield: force dns local (udp)', 'packets');
    $previousForcedTcp = $counterByComment($previousCounters, 'starlink-shield: force dns local (tcp)', 'packets');
    $previousStarlinkPackets = $counterByComment($previousCounters, 'starlink-shield: bloquer sessions udp excessives', 'packets');
    $previousStarlinkBytes = $counterByComment($previousCounters, 'starlink-shield: bloquer sessions udp excessives', 'bytes');
    $previousWanDrops = isset($previousCounters['__wan_drop_total']['packets']) ? (int)$previousCounters['__wan_drop_total']['packets'] : null;
    $currentCounters['__wan_drop_total'] = ['packets' => $wanDropPackets, 'bytes' => 0];
    $wanRuleDeltas = [];
    foreach ($wanRuleRows as $key => $rule) {
        $packetDelta = networkSecurityCounterDelta($rule['packets'], $previousValue($previousCounters, $key, 'packets'));
        $byteDelta = networkSecurityCounterDelta($rule['bytes'], $previousValue($previousCounters, $key, 'bytes'));
        if ($packetDelta === 0 && $byteDelta === 0) continue;
        $wanRuleDeltas[] = $rule + ['packet_delta' => $packetDelta, 'byte_delta' => $byteDelta];
    }
    usort($wanRuleDeltas, static fn(array $left, array $right): int => $right['packet_delta'] <=> $left['packet_delta']);

    $sample = [
        'device_id' => $deviceId,
        'sampled_at' => $sampledAt,
        'collection_ms' => (int)round((microtime(true) - $started) * 1000),
        'dot_connections' => $signalCounts['dot'],
        'doq_connections' => $signalCounts['doq'],
        'doh_suspected_connections' => $signalCounts['doh_suspected'],
        'observed_clients' => count(array_unique(array_column($clients, 'client_ip'))),
        'observed_bytes' => $observedBytes,
        'forced_dns_udp_delta' => networkSecurityCounterDelta($forcedDnsUdp, $previousForcedUdp),
        'forced_dns_tcp_delta' => networkSecurityCounterDelta($forcedDnsTcp, $previousForcedTcp),
        'starlink_udp_drop_packets_delta' => networkSecurityCounterDelta($starlinkUdpPackets, $previousStarlinkPackets),
        'starlink_udp_drop_bytes_delta' => networkSecurityCounterDelta($starlinkUdpBytes, $previousStarlinkBytes),
        'wan_drop_packets_delta' => networkSecurityCounterDelta($wanDropPackets, $previousWanDrops),
        'details_json' => json_encode([
            'known_doh_addresses' => $knownDohAddresses,
            'proof_levels' => ['dot' => 'port_dedicated', 'doq' => 'port_probable', 'doh_suspected' => 'known_endpoint_only'],
            'wan_rule_deltas' => $wanRuleDeltas,
            'wan_source_limit' => 'Les compteurs des règles bloquantes prouvent le port et le volume, mais pas l adresse source sans journalisation pare-feu supplémentaire.',
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
    ];

    $columns = array_keys($sample);
    $stmt = $pdo->prepare('INSERT INTO network_security_samples (' . implode(',', $columns) . ') VALUES (:' . implode(',:', $columns) . ')');
    $stmt->execute(array_combine(array_map(static fn(string $column): string => ':' . $column, $columns), array_values($sample)));

    $clientStmt = $pdo->prepare('INSERT INTO network_security_client_samples (device_id,sampled_at,client_ip,username,signal_type,connections,bytes_total,destinations_json) VALUES (?,?,?,?,?,?,?,?)');
    foreach ($clients as $client) {
        $clientStmt->execute([
            $deviceId, $sampledAt, $client['client_ip'], $client['username'] ?: null, $client['signal_type'],
            $client['connections'], $client['bytes_total'],
            json_encode(array_keys($client['destinations']), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ]);
    }

    $stateSave = $pdo->prepare('INSERT INTO network_security_monitor_state (device_id,counters_json,sampled_at) VALUES (?,?,?) ON DUPLICATE KEY UPDATE counters_json=VALUES(counters_json),sampled_at=VALUES(sampled_at)');
    $stateSave->execute([$deviceId, json_encode($currentCounters, JSON_UNESCAPED_SLASHES), $sampledAt]);
    $pdo->exec('DELETE FROM network_security_samples WHERE sampled_at < DATE_SUB(NOW(), INTERVAL ' . NETWORK_SECURITY_RETENTION_HOURS . ' HOUR)');
    $pdo->exec('DELETE FROM network_security_client_samples WHERE sampled_at < DATE_SUB(NOW(), INTERVAL ' . NETWORK_SECURITY_RETENTION_HOURS . ' HOUR)');
    return $sample;
}

function networkSecurityReport(PDO $pdo, string $deviceId, int $hours): array
{
    ensureNetworkSecurityTables($pdo);
    $hours = in_array($hours, [6, 24, 48], true) ? $hours : 24;
    $params = [':device_id' => $deviceId];
    $where = 'device_id=:device_id AND sampled_at >= DATE_SUB(NOW(), INTERVAL ' . $hours . ' HOUR)';
    $summaryStmt = $pdo->prepare(
        "SELECT COUNT(*) sample_count, MAX(collection_ms) max_collection_ms,
            SUM(dot_connections) dot_observations, SUM(doq_connections) doq_observations,
            SUM(doh_suspected_connections) doh_suspected_observations,
            SUM(forced_dns_udp_delta) forced_dns_udp, SUM(forced_dns_tcp_delta) forced_dns_tcp,
            SUM(starlink_udp_drop_packets_delta) starlink_udp_drop_packets,
            SUM(starlink_udp_drop_bytes_delta) starlink_udp_drop_bytes,
            SUM(wan_drop_packets_delta) wan_drop_packets
         FROM network_security_samples WHERE $where"
    );
    $summaryStmt->execute($params);
    $summary = $summaryStmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $clientsStmt = $pdo->prepare(
        "SELECT client_ip, MAX(NULLIF(username,'')) username, signal_type,
            SUM(connections) observations, MAX(connections) max_connections,
            MAX(bytes_total) max_snapshot_bytes, MAX(sampled_at) last_seen
         FROM network_security_client_samples WHERE $where
         GROUP BY client_ip,signal_type ORDER BY observations DESC,last_seen DESC LIMIT 100"
    );
    $clientsStmt->execute($params);

    $timelineStmt = $pdo->prepare(
        "SELECT DATE_FORMAT(sampled_at,'%Y-%m-%d %H:00:00') period,
            SUM(dot_connections) dot_observations, SUM(doq_connections) doq_observations,
            SUM(doh_suspected_connections) doh_suspected_observations,
            SUM(starlink_udp_drop_packets_delta) starlink_udp_drop_packets,
            SUM(wan_drop_packets_delta) wan_drop_packets
         FROM network_security_samples WHERE $where GROUP BY period ORDER BY period DESC LIMIT 48"
    );
    $timelineStmt->execute($params);

    $wanEvidenceStmt = $pdo->prepare("SELECT sampled_at,details_json FROM network_security_samples WHERE $where AND wan_drop_packets_delta>0 ORDER BY sampled_at DESC LIMIT 50");
    $wanEvidenceStmt->execute($params);
    $wanEvidence = [];
    foreach ($wanEvidenceStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $details = json_decode((string)($row['details_json'] ?? ''), true);
        foreach ((array)($details['wan_rule_deltas'] ?? []) as $rule) {
            $wanEvidence[] = ['sampled_at' => $row['sampled_at']] + $rule;
        }
    }

    return [
        'retention_hours' => NETWORK_SECURITY_RETENTION_HOURS,
        'summary' => array_map(static fn($value) => $value === null ? 0 : (int)$value, $summary),
        'clients' => $clientsStmt->fetchAll(PDO::FETCH_ASSOC),
        'timeline' => $timelineStmt->fetchAll(PDO::FETCH_ASSOC),
        'wan_evidence' => array_slice($wanEvidence, 0, 100),
        'interpretation' => [
            'dot' => 'Preuve par port dédié TCP/853.',
            'doq' => 'Signal probable par UDP/853.',
            'doh_suspected' => 'Suspicion limitée aux destinations DoH connues présentes dans le cache MikroTik.',
            'forced_dns' => 'Compteur de redirection DNS locale ; il ne prouve pas à lui seul un contournement.',
        ],
    ];
}
