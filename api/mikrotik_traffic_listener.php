<?php

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/device_manager.php';
require_once __DIR__ . '/../includes/mikrotik_backend.php';
require_once __DIR__ . '/../includes/auth.php';

$trafficListenerIsEndpoint = realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__;

if ($trafficListenerIsEndpoint) {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    header('Content-Type: application/json; charset=utf-8');

    if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Accès non autorisé.']);
        exit;
    }

    if (!isAdministrator()) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Accès réservé à l administrateur.']);
        exit;
    }

    session_write_close();
}

function trafficListenerEndpoint(string $value): array
{
    $raw = trim($value);
    if (preg_match('/^((?:\d{1,3}\.){3}\d{1,3})(?::(\d+))?$/', $raw, $matches)) {
        return [
            'ip' => $matches[1],
            'port' => isset($matches[2]) ? (int)$matches[2] : 0,
        ];
    }

    return ['ip' => $raw, 'port' => 0];
}

function trafficListenerBytes(array $row): int
{
    return max(0, (int)($row['orig-bytes'] ?? 0)) + max(0, (int)($row['repl-bytes'] ?? 0));
}

function trafficListenerRate(array $row): int
{
    return max(0, (int)($row['orig-rate'] ?? 0)) + max(0, (int)($row['repl-rate'] ?? 0));
}

function trafficListenerRouterosBytes(array $row): ?int
{
    $in = $row['bytes-in'] ?? $row['bytes-in-total'] ?? null;
    $out = $row['bytes-out'] ?? $row['bytes-out-total'] ?? null;
    if ($in === null && $out === null) {
        return null;
    }
    return max(0, (int)$in) + max(0, (int)$out);
}

function trafficListenerIsPrivateIp(string $ip): bool
{
    return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false
        && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
}

function trafficListenerIpClassification(string $ip): string
{
    $value = trim($ip);
    if (filter_var($value, FILTER_VALIDATE_IP) === false) {
        return 'invalide';
    }
    if (filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false) {
        return 'publique plausible';
    }
    if (trafficListenerIsPrivateIp($value)) {
        return 'privée';
    }
    return 'réservée / non routable';
}

function trafficListenerFirewallAnalysis(RouterosAPI $api): array
{
    $protocols = [];
    $actions = [];
    $chains = [];
    $blockedPackets = 0;
    $blockedBytes = 0;
    $totalPackets = 0;
    $totalBytes = 0;
    $rules = [];
    foreach (['raw' => '/ip/firewall/raw/print', 'filter' => '/ip/firewall/filter/print'] as $table => $path) {
        $rows = $api->comm($path, [
            '.proplist' => '.id,chain,action,protocol,src-address,dst-address,dst-port,in-interface,out-interface,comment,disabled,packets,bytes',
        ]);
        if (isset($rows['!trap'])) {
            throw new RuntimeException('RouterOS a refusé la lecture des règles ' . $table . '.');
        }
        foreach (is_array($rows) ? $rows : [] as $row) {
        if (!is_array($row)) continue;
        $packets = max(0, (int)($row['packets'] ?? 0));
        $bytes = max(0, (int)($row['bytes'] ?? 0));
        $action = strtolower(trim((string)($row['action'] ?? 'unknown')));
        $protocol = strtolower(trim((string)($row['protocol'] ?? 'all')) ?: 'all');
        $chain = strtolower(trim((string)($row['chain'] ?? 'unknown')) ?: 'unknown');
        $disabled = mikrotikApiBoolean($row['disabled'] ?? false);
        if (!$disabled) {
            $totalPackets += $packets;
            $totalBytes += $bytes;
            foreach ([&$protocols[$protocol], &$actions[$action], &$chains[$chain]] as &$stat) {
                $stat['packets'] = ($stat['packets'] ?? 0) + $packets;
                $stat['bytes'] = ($stat['bytes'] ?? 0) + $bytes;
            }
            unset($stat);
            if (in_array($action, ['drop', 'reject'], true)) {
                $blockedPackets += $packets;
                $blockedBytes += $bytes;
            }
        }
        $rules[] = [
            'table' => $table,
            'chain' => $chain,
            'action' => $action,
            'protocol' => $protocol,
            'comment' => trim((string)($row['comment'] ?? '')),
            'disabled' => $disabled,
            'packets' => $packets,
            'bytes' => $bytes,
        ];
        }
    }
    usort($rules, static fn(array $left, array $right): int => $right['bytes'] <=> $left['bytes']);
    $sortStats = static function (array $stats): array {
        uasort($stats, static fn(array $left, array $right): int => ($right['bytes'] <=> $left['bytes']) ?: ($right['packets'] <=> $left['packets']));
        return $stats;
    };
    return [
        'rules_total' => count($rules),
        'active_rules' => count(array_filter($rules, static fn(array $rule): bool => !$rule['disabled'])),
        'total_packets' => $totalPackets,
        'total_bytes' => $totalBytes,
        'blocked_packets' => $blockedPackets,
        'blocked_bytes' => $blockedBytes,
        'protocols' => $sortStats($protocols),
        'actions' => $sortStats($actions),
        'chains' => $sortStats($chains),
        'top_rules' => array_slice($rules, 0, 10),
        'note' => 'Compteurs cumulés des règles actives raw et filter. Une même connexion peut compter dans plusieurs règles et les compteurs ne représentent pas un volume unique.',
    ];
}

function trafficListenerService(string $protocol, int $port): string
{
    $known = [
        'tcp:80' => 'HTTP',
        'tcp:443' => 'HTTPS',
        'udp:443' => 'QUIC / HTTPS',
        'tcp:53' => 'DNS',
        'udp:53' => 'DNS',
        'udp:3478' => 'STUN audio/vidéo',
        'tcp:1194' => 'OpenVPN possible',
        'udp:1194' => 'OpenVPN possible',
    ];
    $key = strtolower($protocol) . ':' . $port;
    if (isset($known[$key])) {
        return $known[$key];
    }
    if ($port >= 6881 && $port <= 6999) {
        return 'Port P2P courant';
    }

    return $port > 0 ? strtoupper($protocol) . '/' . $port : strtoupper($protocol);
}

function trafficListenerPlatformSignals(array $dnsNames): array
{
    $families = [];
    foreach ($dnsNames as $dnsName) {
        $name = strtolower((string)$dnsName);
        if (preg_match('/(^|\.)(windowsupdate\.com|microsoft\.com|msftconnecttest\.com)$/', $name)) {
            $families['Windows / Microsoft'] = true;
        }
        if (preg_match('/(^|\.)(apple\.com|icloud\.com)$/', $name)) {
            $families['Apple'] = true;
        }
        if (preg_match('/(^|\.)(android\.com|googleapis\.com|gvt1\.com|google\.com)$/', $name)) {
            $families['Android / Google'] = true;
        }
    }
    return array_keys($families);
}


function trafficListenerDnsSignatureDefinitions(): array
{
    return [
        'connectivity_probe' => [
            'label' => 'Présence connexion',
            'action' => 'local_reply',
            'domains' => ['connectivitycheck.gstatic.com', 'clients3.google.com', 'www.msftconnecttest.com', 'dns.msftncsi.com', 'captive.apple.com', 'www.apple.com'],
            'description' => 'Tests de présence/captive portal. Candidat à une réponse locale 10.10.10.2 si la réponse HTTP attendue est servie correctement.',
        ],
        'windows_update' => [
            'label' => 'Mises à jour Windows',
            'action' => 'block',
            'domains' => ['windowsupdate.com', 'update.microsoft.com', 'delivery.mp.microsoft.com', 'prod.do.dsp.mp.microsoft.com'],
            'description' => 'Ne doit plus apparaître en sortie WAN lorsque le blocage Windows Update est actif.',
        ],
        'apple_update' => [
            'label' => 'Mises à jour Apple',
            'action' => 'block',
            'domains' => ['mesu.apple.com', 'updates-http.cdn-apple.com'],
            'description' => 'Ne doit plus apparaître en sortie WAN lorsque le blocage Apple Update est actif.',
        ],
        'android_update' => [
            'label' => 'Mises à jour Android',
            'action' => 'block',
            'domains' => ['update.googleapis.com'],
            'description' => 'Ne doit plus apparaître en sortie WAN lorsque le blocage Android Update est actif.',
        ],
        'google_background' => [
            'label' => 'Google arrière-plan',
            'action' => 'watch',
            'domains' => ['federatedml-pa.googleapis.com', 'safebrowsing.googleapis.com', 'policies.google.com', 'optimizationguide-pa.googleapis.com'],
            'description' => 'Bruit Google à surveiller séparément des mises à jour et de Google Play.',
        ],
        'google_play_services' => [
            'label' => 'Google Play Services',
            'action' => 'watch',
            'domains' => ['play.googleapis.com', 'playstoregatewayadapter-pa.googleapis.com', 'prod-lt-playstoregatewayadapter-pa.googleapis.com', 'spot-pa.googleapis.com', 'play.google.com', 'android.l.google.com', 'mobile-gtalk.l.google.com'],
            'description' => 'Service Android courant. À surveiller, pas à bloquer globalement sans décision explicite.',
        ],
        'streaming_google' => [
            'label' => 'YouTube / Google vidéo',
            'action' => 'watch',
            'domains' => ['youtube.com', 'googlevideo.com', 'youtube-ui.l.google.com', 'ytimg.com', 'ggpht.com'],
            'description' => 'Trafic contenu avec débit possible, distinct des mises à jour système.',
        ],
        'meta_social' => [
            'label' => 'Meta / Facebook',
            'action' => 'watch',
            'domains' => ['facebook.com', 'fbcdn.net', 'cdninstagram.com', 'messenger.com'],
            'description' => 'Signature sociale/CDN fréquente, à contrôler par activité active et destinations.',
        ],
        'tiktok_bytedance' => [
            'label' => 'TikTok / ByteDance',
            'action' => 'watch',
            'domains' => ['tiktokcdn.com', 'byteoversea.net', 'byteglb.com', 'bytedance.map.fastly.net'],
            'description' => 'Signature à surveiller strictement, surtout UDP/443 et connexions actives.',
        ],
        'whatsapp' => [
            'label' => 'WhatsApp',
            'action' => 'watch',
            'domains' => ['whatsapp.net'],
            'description' => 'Messagerie/CDN, souvent légitime mais visible en WAN.',
        ],
        'local_service' => [
            'label' => 'Service local',
            'action' => 'local',
            'domains' => ['androndra.wifi.mg'],
            'description' => 'Doit rester local et ne pas créer de dépendance WAN.',
        ],
    ];
}

function trafficListenerDomainMatches(string $domain, string $pattern): bool
{
    $domain = strtolower(rtrim(trim($domain), '.'));
    $pattern = strtolower(rtrim(trim($pattern), '.'));
    if ($domain === '' || $pattern === '') return false;
    return $domain === $pattern || str_ends_with($domain, '.' . $pattern);
}

function trafficListenerDnsSignature(string $domain): array
{
    foreach (trafficListenerDnsSignatureDefinitions() as $key => $definition) {
        foreach ($definition['domains'] as $pattern) {
            if (trafficListenerDomainMatches($domain, $pattern)) {
                return ['key' => $key, 'label' => $definition['label'], 'action' => $definition['action'], 'description' => $definition['description']];
            }
        }
    }
    return ['key' => 'unclassified', 'label' => 'Non classé', 'action' => 'watch', 'description' => 'Domaine corrélé au cache DNS, sans famille reconnue.'];
}

function trafficListenerUpdatePolicyStatus(RouterosAPI $api): array
{
    $groups = array_intersect_key(trafficListenerDnsSignatureDefinitions(), array_flip(['windows_update', 'apple_update', 'android_update']));
    $staticRows = $api->comm('/ip/dns/static/print', ['.proplist' => 'name,type,match-subdomain,disabled,comment']);
    $staticRows = array_values(array_filter(is_array($staticRows) ? $staticRows : [], 'is_array'));
    $result = [];
    foreach ($groups as $key => $definition) {
        $enabled = 0;
        foreach ($definition['domains'] as $domain) {
            foreach ($staticRows as $row) {
                if (strtoupper((string)($row['type'] ?? '')) !== 'NXDOMAIN' || mikrotikApiBoolean($row['disabled'] ?? false)) continue;
                if (trafficListenerDomainMatches($domain, (string)($row['name'] ?? ''))) {
                    $enabled++;
                    break;
                }
            }
        }
        $expected = count($definition['domains']);
        $result[$key] = ['label' => $definition['label'], 'enabled' => $expected > 0 && $enabled === $expected, 'configured_items' => $enabled, 'expected_items' => $expected];
    }
    return $result;
}

function trafficListenerDnsSignatureAnalysis(array $domainRows, array $updatePolicies): array
{
    $families = [];
    $updateLeaks = [];
    $activeStrict = [];
    foreach ($domainRows as $domainRow) {
        $domain = (string)($domainRow['domain'] ?? '');
        if ($domain === '') continue;
        $signature = $domainRow['signature'] ?? trafficListenerDnsSignature($domain);
        $key = $signature['key'];
        if (!isset($families[$key])) {
            $families[$key] = $signature + ['domains' => 0, 'devices' => 0, 'connections' => 0, 'active_connections' => 0, 'idle_connections' => 0, 'top_domains' => []];
        }
        $families[$key]['domains']++;
        $families[$key]['devices'] += max(0, (int)($domainRow['devices'] ?? 0));
        $families[$key]['connections'] += max(0, (int)($domainRow['connections'] ?? 0));
        $families[$key]['active_connections'] += max(0, (int)($domainRow['active_connections'] ?? 0));
        $families[$key]['idle_connections'] += max(0, (int)($domainRow['idle_connections'] ?? 0));
        if (count($families[$key]['top_domains']) < 5) {
            $families[$key]['top_domains'][] = ['domain' => $domain, 'devices' => max(0, (int)($domainRow['devices'] ?? 0)), 'connections' => max(0, (int)($domainRow['connections'] ?? 0)), 'active_connections' => max(0, (int)($domainRow['active_connections'] ?? 0)), 'idle_connections' => max(0, (int)($domainRow['idle_connections'] ?? 0))];
        }
        if (in_array($key, ['windows_update', 'apple_update', 'android_update'], true) && ($updatePolicies[$key]['enabled'] ?? false)) {
            $updateLeaks[] = ['domain' => $domain, 'family' => $signature['label'], 'devices' => max(0, (int)($domainRow['devices'] ?? 0)), 'connections' => max(0, (int)($domainRow['connections'] ?? 0)), 'active_connections' => max(0, (int)($domainRow['active_connections'] ?? 0))];
        }
        if ((int)($domainRow['active_connections'] ?? 0) > 0 && in_array($signature['action'], ['block', 'local_reply'], true)) {
            $activeStrict[] = ['domain' => $domain, 'family' => $signature['label'], 'action' => $signature['action'], 'active_connections' => max(0, (int)($domainRow['active_connections'] ?? 0))];
        }
    }
    usort($families, static fn(array $left, array $right): int => ($right['active_connections'] <=> $left['active_connections']) ?: ($right['connections'] <=> $left['connections']));
    return [
        'families' => array_values($families),
        'update_policies' => $updatePolicies,
        'update_leaks' => $updateLeaks,
        'active_strict' => $activeStrict,
        'update_blocking_ok' => $updateLeaks === [] && count(array_filter($updatePolicies, static fn(array $policy): bool => !($policy['enabled'] ?? false))) === 0,
        'note' => 'Analyse issue du cache DNS MikroTik corrélé à conntrack ; une fuite de mise à jour signifie un domaine de mise à jour encore observé malgré la politique NXDOMAIN active.',
    ];
}

function trafficListenerRows(RouterosAPI $api, array $parameters): array
{
    $rows = $api->comm('/ip/firewall/connection/print', $parameters + [
        '.proplist' => '.id,protocol,src-address,dst-address,dst-port,reply-src-address,reply-src-port,tcp-state,orig-bytes,repl-bytes,orig-rate,repl-rate',
    ]);
    if (isset($rows['!trap'])) {
        throw new RuntimeException('RouterOS a refusé la lecture des connexions.');
    }

    return is_array($rows) ? $rows : [];
}

function trafficListenerAdlistStats(RouterosAPI $api): array
{
    $rows = $api->comm('/ip/dns/adlist/print', [
        '.proplist' => 'disabled,name-count,match-count,url',
    ]);
    if (!is_array($rows) || isset($rows['!trap'])) {
        return [
            'available' => false,
            'total' => 0,
            'active' => 0,
            'domains' => 0,
            'matches' => 0,
        ];
    }

    $stats = [
        'available' => true,
        'total' => 0,
        'active' => 0,
        'domains' => 0,
        'matches' => 0,
    ];
    foreach ($rows as $row) {
        if (!is_array($row)) continue;
        $stats['total']++;
        if (!mikrotikApiBoolean($row['disabled'] ?? false)) $stats['active']++;
        $stats['domains'] += max(0, (int)($row['name-count'] ?? 0));
        $stats['matches'] += max(0, (int)($row['match-count'] ?? 0));
    }
    return $stats;
}

function trafficListenerRouterClock(RouterosAPI $api): ?array
{
    $rows = $api->comm('/system/clock/print', [
        '.proplist' => 'time,date,time-zone-name,time-zone-autodetect,gmt-offset',
    ]);
    if (!is_array($rows) || isset($rows['!trap'])) {
        return null;
    }
    $row = $rows[0] ?? null;
    if (!is_array($row)) {
        return null;
    }
    $time = trim((string)($row['time'] ?? ''));
    $date = trim((string)($row['date'] ?? ''));
    return [
        'time' => $time,
        'date' => $date,
        'timezone' => trim((string)($row['time-zone-name'] ?? '')),
        'gmt_offset' => trim((string)($row['gmt-offset'] ?? '')),
        'display' => trim($date . ' ' . $time),
    ];
}

function trafficListenerEnsureHistoryTables(PDO $pdo): void
{
    static $done = false;
    if ($done) return;

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS mikrotik_traffic_samples (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            device_id VARCHAR(120) NOT NULL,
            sampled_at DATETIME NOT NULL,
            firewall_total_packets BIGINT UNSIGNED NOT NULL DEFAULT 0,
            firewall_blocked_packets BIGINT UNSIGNED NOT NULL DEFAULT 0,
            firewall_accepted_packets BIGINT UNSIGNED NOT NULL DEFAULT 0,
            firewall_total_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
            firewall_blocked_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
            firewall_total_delta BIGINT UNSIGNED NOT NULL DEFAULT 0,
            firewall_blocked_delta BIGINT UNSIGNED NOT NULL DEFAULT 0,
            firewall_accepted_delta BIGINT UNSIGNED NOT NULL DEFAULT 0,
            adlist_match_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
            adlist_match_delta BIGINT UNSIGNED NOT NULL DEFAULT 0,
            adlist_domain_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
            adlist_active INT UNSIGNED NOT NULL DEFAULT 0,
            adlist_total INT UNSIGNED NOT NULL DEFAULT 0,
            conntrack_tracked INT UNSIGNED NOT NULL DEFAULT 0,
            conntrack_observed INT UNSIGNED NOT NULL DEFAULT 0,
            active_connections INT UNSIGNED NOT NULL DEFAULT 0,
            idle_connections INT UNSIGNED NOT NULL DEFAULT 0,
            active_hotspot INT UNSIGNED NOT NULL DEFAULT 0,
            local_users INT UNSIGNED NOT NULL DEFAULT 0,
            dns_resolved_connections INT UNSIGNED NOT NULL DEFAULT 0,
            dns_cache_available TINYINT(1) NOT NULL DEFAULT 0,
            review_users INT UNSIGNED NOT NULL DEFAULT 0,
            p2p_users INT UNSIGNED NOT NULL DEFAULT 0,
            suspicious_inbound_sources INT UNSIGNED NOT NULL DEFAULT 0,
            details_json LONGTEXT DEFAULT NULL,
            PRIMARY KEY (id),
            KEY idx_mikrotik_traffic_samples_device_time (device_id, sampled_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
    );
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS mikrotik_traffic_monitor_state (
            device_id VARCHAR(120) NOT NULL,
            sampled_at DATETIME DEFAULT NULL,
            firewall_total_packets BIGINT UNSIGNED NOT NULL DEFAULT 0,
            firewall_blocked_packets BIGINT UNSIGNED NOT NULL DEFAULT 0,
            firewall_accepted_packets BIGINT UNSIGNED NOT NULL DEFAULT 0,
            adlist_match_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (device_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
    );
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS mikrotik_traffic_user_usage_samples (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            device_id VARCHAR(120) NOT NULL,
            sampled_at DATETIME NOT NULL,
            usage_day DATE NOT NULL,
            source_type VARCHAR(24) NOT NULL,
            user_key VARCHAR(191) NOT NULL,
            counter_key VARCHAR(191) NOT NULL DEFAULT '',
            label VARCHAR(191) NOT NULL,
            local_ip VARCHAR(64) NOT NULL DEFAULT '',
            mac VARCHAR(64) NOT NULL DEFAULT '',
            bytes_total BIGINT UNSIGNED NOT NULL DEFAULT 0,
            bytes_delta BIGINT UNSIGNED NOT NULL DEFAULT 0,
            connections INT UNSIGNED NOT NULL DEFAULT 0,
            active_connections INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (id),
            KEY idx_mikrotik_usage_device_day (device_id, usage_day, source_type, bytes_delta),
            KEY idx_mikrotik_usage_device_time (device_id, sampled_at),
            KEY idx_mikrotik_usage_counter (device_id, counter_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
    );
    $pdo->exec(
        // Synthese journaliere par client (2026-10-11) : alimentee en meme temps que la table brute
        // ci-dessus, pour que le classement mensuel n'ait plus besoin de rescanner des millions de
        // lignes minute par minute. Volume : au plus (jours x clients) lignes, negligeable face au brut.
        "CREATE TABLE IF NOT EXISTS mikrotik_traffic_user_usage_daily (
            device_id VARCHAR(120) NOT NULL,
            usage_day DATE NOT NULL,
            source_type VARCHAR(24) NOT NULL,
            user_key VARCHAR(191) NOT NULL,
            label VARCHAR(191) NOT NULL DEFAULT '',
            local_ip VARCHAR(64) NOT NULL DEFAULT '',
            mac VARCHAR(64) NOT NULL DEFAULT '',
            bytes_total BIGINT UNSIGNED NOT NULL DEFAULT 0,
            connections INT UNSIGNED NOT NULL DEFAULT 0,
            active_connections INT UNSIGNED NOT NULL DEFAULT 0,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (device_id, usage_day, source_type, user_key),
            KEY idx_mikrotik_usage_daily_month (device_id, usage_day)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
    );
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS mikrotik_traffic_usage_counter_state (
            device_id VARCHAR(120) NOT NULL,
            counter_key VARCHAR(191) NOT NULL,
            bytes_total BIGINT UNSIGNED NOT NULL DEFAULT 0,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (device_id, counter_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
    );
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS mikrotik_traffic_dns_signature_samples (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            device_id VARCHAR(120) NOT NULL,
            sampled_at DATETIME NOT NULL,
            domain VARCHAR(253) NOT NULL,
            signature_key VARCHAR(64) NOT NULL,
            recommended_action VARCHAR(32) NOT NULL,
            devices INT UNSIGNED NOT NULL DEFAULT 0,
            connections INT UNSIGNED NOT NULL DEFAULT 0,
            active_connections INT UNSIGNED NOT NULL DEFAULT 0,
            idle_connections INT UNSIGNED NOT NULL DEFAULT 0,
            update_policy_enabled TINYINT(1) NOT NULL DEFAULT 0,
            PRIMARY KEY (id),
            KEY idx_mikrotik_dns_signature_device_time (device_id, sampled_at),
            KEY idx_mikrotik_dns_signature_domain (device_id, domain)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
    );
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS mikrotik_traffic_dns_client_signature_samples (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            device_id VARCHAR(120) NOT NULL,
            sampled_at DATETIME NOT NULL,
            domain VARCHAR(253) NOT NULL,
            signature_key VARCHAR(64) NOT NULL,
            recommended_action VARCHAR(32) NOT NULL,
            local_ip VARCHAR(64) NOT NULL DEFAULT '',
            username VARCHAR(191) NOT NULL DEFAULT '',
            mac VARCHAR(64) NOT NULL DEFAULT '',
            binding_comment VARCHAR(191) NOT NULL DEFAULT '',
            connections INT UNSIGNED NOT NULL DEFAULT 0,
            active_connections INT UNSIGNED NOT NULL DEFAULT 0,
            idle_connections INT UNSIGNED NOT NULL DEFAULT 0,
            bytes_total BIGINT UNSIGNED NOT NULL DEFAULT 0,
            update_policy_enabled TINYINT(1) NOT NULL DEFAULT 0,
            PRIMARY KEY (id),
            KEY idx_mikrotik_dns_client_sig_time (device_id, sampled_at),
            KEY idx_mikrotik_dns_client_sig_domain (device_id, domain),
            KEY idx_mikrotik_dns_client_sig_client (device_id, local_ip, signature_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
    );
    if (!trafficListenerColumnExists($pdo, 'mikrotik_traffic_user_usage_samples', 'counter_key')) {
        $pdo->exec("ALTER TABLE mikrotik_traffic_user_usage_samples ADD counter_key VARCHAR(191) NOT NULL DEFAULT '' AFTER user_key, ADD KEY idx_mikrotik_usage_counter (device_id, counter_key)");
    }
    if (!trafficListenerIndexExists($pdo, 'mikrotik_traffic_user_usage_samples', 'idx_mikrotik_usage_device_day_user')) {
        $pdo->exec("ALTER TABLE mikrotik_traffic_user_usage_samples ADD KEY idx_mikrotik_usage_device_day_user (device_id, usage_day, source_type, user_key, sampled_at)");
    }
    $done = true;
}

function trafficListenerCounterDelta(int $current, int $previous): int
{
    return $current >= $previous ? $current - $previous : 0;
}

function trafficListenerColumnExists(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
    $stmt->execute([$table, $column]);
    return (int)$stmt->fetchColumn() > 0;
}

function trafficListenerIndexExists(PDO $pdo, string $table, string $index): bool
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?');
    $stmt->execute([$table, $index]);
    return (int)$stmt->fetchColumn() > 0;
}


function trafficListenerSampleDay(string $sampledAt, ?array $routerClock = null): string
{
    $serverTs = strtotime($sampledAt) ?: time();
    return date('Y-m-d', $serverTs);
}

function trafficListenerUsageSource(array $user): array
{
    $username = trim((string)($user['username'] ?? ''));
    $localIp = trim((string)($user['local_ip'] ?? ''));
    $mac = trim((string)($user['mac'] ?? ''));
    $binding = trim((string)(($user['binding_comment'] ?? '') ?: ($user['binding_type'] ?? '')));
    $rawCounterKey = trim((string)($user['usage_counter_key'] ?? ''));
    if ($username !== '') {
        $counterIdentity = $rawCounterKey !== '' ? $rawCounterKey : implode('|', array_filter([$username, $mac, $localIp]));
        return [
            'type' => 'hotspot',
            'key' => 'hotspot:' . strtolower($username),
            'counter_key' => 'hotspot_session:' . strtolower($counterIdentity),
            'label' => $username,
        ];
    }
    if ($binding !== '' || $mac !== '') {
        $identity = $mac !== '' ? $mac : ($localIp !== '' ? $localIp : $binding);
        $counterIdentity = $rawCounterKey !== '' ? $rawCounterKey : $identity;
        return [
            'type' => 'ip_binding',
            'key' => 'ip_binding:' . strtolower($identity),
            'counter_key' => 'ip_binding_counter:' . strtolower($counterIdentity),
            'label' => $binding !== '' ? $binding : $identity,
        ];
    }
    $identity = $localIp !== '' ? $localIp : 'inconnu';
    return ['type' => 'observed', 'key' => 'observed:' . strtolower($identity), 'counter_key' => 'observed:' . strtolower($identity), 'label' => $identity];
}

function trafficListenerRecordUserUsage(PDO $pdo, string $deviceId, string $sampledAt, array $users, ?array $routerClock = null): void
{
    if ($deviceId === '' || !$users) return;
    $usageDay = trafficListenerSampleDay($sampledAt, $routerClock);
    $currentCounters = [];
    foreach ($users as $user) {
        if (!is_array($user)) continue;
        if (!array_key_exists('usage_bytes_total', $user) || $user['usage_bytes_total'] === null || $user['usage_bytes_total'] === '') {
            continue;
        }
        $source = trafficListenerUsageSource($user);
        if (!in_array($source['type'], ['hotspot', 'ip_binding'], true)) {
            continue;
        }
        $counterKey = trim((string)($source['counter_key'] ?? $source['key']));
        if ($counterKey === '') {
            continue;
        }
        if (!isset($currentCounters[$counterKey])) {
            $currentCounters[$counterKey] = [
                'source_type' => $source['type'],
                'user_key' => $source['key'],
                'counter_key' => $counterKey,
                'label' => $source['label'],
                'local_ip' => trim((string)($user['local_ip'] ?? '')),
                'mac' => trim((string)($user['mac'] ?? '')),
                'bytes_total' => 0,
                'connections' => 0,
                'active_connections' => 0,
            ];
        }
        $currentCounters[$counterKey]['bytes_total'] += max(0, (int)$user['usage_bytes_total']);
        $currentCounters[$counterKey]['connections'] += max(0, (int)($user['connections'] ?? 0));
        $currentCounters[$counterKey]['active_connections'] += max(0, (int)($user['active_connections'] ?? 0));
        if ($currentCounters[$counterKey]['local_ip'] === '' && trim((string)($user['local_ip'] ?? '')) !== '') {
            $currentCounters[$counterKey]['local_ip'] = trim((string)$user['local_ip']);
        }
        if ($currentCounters[$counterKey]['mac'] === '' && trim((string)($user['mac'] ?? '')) !== '') {
            $currentCounters[$counterKey]['mac'] = trim((string)$user['mac']);
        }
    }
    if (!$currentCounters) return;

    $stateStmt = $pdo->prepare('SELECT bytes_total FROM mikrotik_traffic_usage_counter_state WHERE device_id=? AND counter_key=? LIMIT 1');
    $insertStmt = $pdo->prepare('INSERT INTO mikrotik_traffic_user_usage_samples (device_id,sampled_at,usage_day,source_type,user_key,counter_key,label,local_ip,mac,bytes_total,bytes_delta,connections,active_connections) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)');
    $stateUpsert = $pdo->prepare('INSERT INTO mikrotik_traffic_usage_counter_state (device_id,counter_key,bytes_total,updated_at) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE bytes_total=VALUES(bytes_total),updated_at=VALUES(updated_at)');
    foreach ($currentCounters as $row) {
        $bytesTotal = max(0, (int)$row['bytes_total']);
        $stateStmt->execute([$deviceId, $row['counter_key']]);
        $previous = $stateStmt->fetch(PDO::FETCH_ASSOC);
        $bytesDelta = $previous ? trafficListenerCounterDelta($bytesTotal, (int)($previous['bytes_total'] ?? 0)) : 0;
        $insertStmt->execute([
            $deviceId,
            $sampledAt,
            $usageDay,
            $row['source_type'],
            $row['user_key'],
            substr((string)$row['counter_key'], 0, 191),
            substr((string)$row['label'], 0, 191),
            substr((string)$row['local_ip'], 0, 64),
            substr((string)$row['mac'], 0, 64),
            $bytesTotal,
            $bytesDelta,
            max(0, (int)$row['connections']),
            max(0, (int)$row['active_connections']),
        ]);
        $stateUpsert->execute([$deviceId, $row['counter_key'], $bytesTotal, $sampledAt]);
    }
}

/**
 * Agrege une journee entiere de mikrotik_traffic_user_usage_samples (brute, minute par minute) vers
 * mikrotik_traffic_user_usage_daily (une ligne par client et par jour). Appelee une fois par jour
 * (voir trafficListenerRecordSample), pas a chaque cycle : un seul passage d'agregation au lieu de
 * 1440 ecritures/jour/client sur la meme ligne. Idempotent (ON DUPLICATE KEY UPDATE), donc sans risque
 * si le cron la relance plusieurs fois dans la fenetre de declenchement.
 */
function trafficListenerAggregateDailyUsage(PDO $pdo, string $deviceId, string $day): int
{
    if ($deviceId === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) return 0;
    $stmt = $pdo->prepare(
        'INSERT INTO mikrotik_traffic_user_usage_daily (device_id,usage_day,source_type,user_key,label,local_ip,mac,bytes_total,connections,active_connections,updated_at)
         SELECT device_id,usage_day,source_type,user_key,MAX(label),MAX(local_ip),MAX(mac),COALESCE(SUM(bytes_delta),0),MAX(connections),MAX(active_connections),NOW()
         FROM mikrotik_traffic_user_usage_samples
         WHERE device_id=? AND usage_day=?
         GROUP BY device_id,usage_day,source_type,user_key
         ON DUPLICATE KEY UPDATE bytes_total=VALUES(bytes_total),connections=VALUES(connections),active_connections=VALUES(active_connections),label=VALUES(label),local_ip=VALUES(local_ip),mac=VALUES(mac),updated_at=VALUES(updated_at)'
    );
    $stmt->execute([$deviceId, $day]);
    return $stmt->rowCount();
}

function trafficListenerRecordDnsSignatures(PDO $pdo, string $deviceId, string $sampledAt, array $domains, array $analysis, array $clientDomains = []): void
{
    if ($deviceId === '') return;
    $policyEnabled = [];
    foreach (($analysis['update_policies'] ?? []) as $key => $policy) {
        $policyEnabled[$key] = !empty($policy['enabled']);
    }
    if ($domains) {
        $stmt = $pdo->prepare('INSERT INTO mikrotik_traffic_dns_signature_samples (device_id,sampled_at,domain,signature_key,recommended_action,devices,connections,active_connections,idle_connections,update_policy_enabled) VALUES (?,?,?,?,?,?,?,?,?,?)');
        foreach ($domains as $domainRow) {
            $domain = trim((string)($domainRow['domain'] ?? ''));
            if ($domain === '') continue;
            $signature = $domainRow['signature'] ?? trafficListenerDnsSignature($domain);
            $key = (string)($signature['key'] ?? 'unclassified');
            $stmt->execute([
                $deviceId,
                $sampledAt,
                substr($domain, 0, 253),
                substr($key, 0, 64),
                substr((string)($signature['action'] ?? 'watch'), 0, 32),
                max(0, (int)($domainRow['devices'] ?? 0)),
                max(0, (int)($domainRow['connections'] ?? 0)),
                max(0, (int)($domainRow['active_connections'] ?? 0)),
                max(0, (int)($domainRow['idle_connections'] ?? 0)),
                !empty($policyEnabled[$key]) ? 1 : 0,
            ]);
        }
    }
    if ($clientDomains) {
        $clientStmt = $pdo->prepare('INSERT INTO mikrotik_traffic_dns_client_signature_samples (device_id,sampled_at,domain,signature_key,recommended_action,local_ip,username,mac,binding_comment,connections,active_connections,idle_connections,bytes_total,update_policy_enabled) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
        foreach ($clientDomains as $row) {
            $domain = trim((string)($row['domain'] ?? ''));
            if ($domain === '') continue;
            $signature = $row['signature'] ?? trafficListenerDnsSignature($domain);
            $key = (string)($signature['key'] ?? 'unclassified');
            $clientStmt->execute([
                $deviceId,
                $sampledAt,
                substr($domain, 0, 253),
                substr($key, 0, 64),
                substr((string)($signature['action'] ?? 'watch'), 0, 32),
                substr((string)($row['local_ip'] ?? ''), 0, 64),
                substr((string)($row['username'] ?? ''), 0, 191),
                substr((string)($row['mac'] ?? ''), 0, 64),
                substr((string)($row['binding_comment'] ?? ''), 0, 191),
                max(0, (int)($row['connections'] ?? 0)),
                max(0, (int)($row['active_connections'] ?? 0)),
                max(0, (int)($row['idle_connections'] ?? 0)),
                max(0, (int)($row['bytes'] ?? 0)),
                !empty($policyEnabled[$key]) ? 1 : 0,
            ]);
        }
    }
}

function trafficListenerRecordSample(PDO $pdo, array $payload): void
{
    trafficListenerEnsureHistoryTables($pdo);
    $deviceId = trim((string)($payload['device']['id'] ?? ''));
    if ($deviceId === '') return;

    $summary = $payload['summary'] ?? [];
    $firewall = $payload['firewall'] ?? [];
    $adlist = $summary['adlists'] ?? [];
    $acceptedPackets = max(0, (int)($firewall['actions']['accept']['packets'] ?? 0));
    $totalPackets = max(0, (int)($firewall['total_packets'] ?? 0));
    $blockedPackets = max(0, (int)($firewall['blocked_packets'] ?? 0));
    $adlistMatches = max(0, (int)($adlist['matches'] ?? 0));

    $stateStmt = $pdo->prepare('SELECT * FROM mikrotik_traffic_monitor_state WHERE device_id=? LIMIT 1');
    $stateStmt->execute([$deviceId]);
    $previous = $stateStmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $sampledAt = date('Y-m-d H:i:s', strtotime((string)($payload['captured_at'] ?? 'now')) ?: time());
    if (!empty($previous['sampled_at']) && time() - strtotime((string)$previous['sampled_at']) < 50) {
        return;
    }
    $params = [
        ':device_id' => $deviceId,
        ':sampled_at' => $sampledAt,
        ':firewall_total_packets' => $totalPackets,
        ':firewall_blocked_packets' => $blockedPackets,
        ':firewall_accepted_packets' => $acceptedPackets,
        ':firewall_total_bytes' => max(0, (int)($firewall['total_bytes'] ?? 0)),
        ':firewall_blocked_bytes' => max(0, (int)($firewall['blocked_bytes'] ?? 0)),
        ':firewall_total_delta' => trafficListenerCounterDelta($totalPackets, (int)($previous['firewall_total_packets'] ?? $totalPackets)),
        ':firewall_blocked_delta' => trafficListenerCounterDelta($blockedPackets, (int)($previous['firewall_blocked_packets'] ?? $blockedPackets)),
        ':firewall_accepted_delta' => trafficListenerCounterDelta($acceptedPackets, (int)($previous['firewall_accepted_packets'] ?? $acceptedPackets)),
        ':adlist_match_count' => $adlistMatches,
        ':adlist_match_delta' => trafficListenerCounterDelta($adlistMatches, (int)($previous['adlist_match_count'] ?? $adlistMatches)),
        ':adlist_domain_count' => max(0, (int)($adlist['domains'] ?? 0)),
        ':adlist_active' => max(0, (int)($adlist['active'] ?? 0)),
        ':adlist_total' => max(0, (int)($adlist['total'] ?? 0)),
        ':conntrack_tracked' => max(0, (int)($summary['tracked'] ?? 0)),
        ':conntrack_observed' => max(0, (int)($summary['observed'] ?? 0)),
        ':active_connections' => max(0, (int)($summary['active_now'] ?? 0)),
        ':idle_connections' => max(0, (int)($summary['idle_now'] ?? 0)),
        ':active_hotspot' => max(0, (int)($summary['active_hotspot'] ?? 0)),
        ':local_users' => max(0, (int)($summary['local_users'] ?? 0)),
        ':dns_resolved_connections' => max(0, (int)($summary['dns_resolved_connections'] ?? 0)),
        ':dns_cache_available' => !empty($summary['dns_cache_available']) ? 1 : 0,
        ':review_users' => max(0, (int)($summary['review_users'] ?? 0)),
        ':p2p_users' => max(0, (int)($summary['p2p_users'] ?? 0)),
        ':suspicious_inbound_sources' => max(0, (int)($summary['suspicious_inbound_sources'] ?? 0)),
        // details_json desactive (2026-10-11) : verifie, jamais relu (ni par ce fichier, ni par
        // js/traffic_listener.js, ni par tools/system_tools_page.php). 78 % du poids de cette table
        // (313 Mo sur 400 Mo) pour une colonne ecrite a chaque cycle et jamais consultee. Les memes
        // donnees (top_users/top_domains/top_rules) restent utilisees en direct dans la reponse API,
        // depuis $payload/$firewall ci-dessus : rien ne change pour l'affichage.
        ':details_json' => null,
    ];
    $columns = array_map(static fn(string $key): string => substr($key, 1), array_keys($params));
    $pdo->prepare('INSERT INTO mikrotik_traffic_samples (' . implode(',', $columns) . ') VALUES (' . implode(',', array_keys($params)) . ')')->execute($params);
    $pdo->prepare(
        'INSERT INTO mikrotik_traffic_monitor_state (device_id,sampled_at,firewall_total_packets,firewall_blocked_packets,firewall_accepted_packets,adlist_match_count)
         VALUES (?,?,?,?,?,?) ON DUPLICATE KEY UPDATE sampled_at=VALUES(sampled_at),firewall_total_packets=VALUES(firewall_total_packets),firewall_blocked_packets=VALUES(firewall_blocked_packets),firewall_accepted_packets=VALUES(firewall_accepted_packets),adlist_match_count=VALUES(adlist_match_count)'
    )->execute([$deviceId, $sampledAt, $totalPackets, $blockedPackets, $acceptedPackets, $adlistMatches]);
    trafficListenerRecordUserUsage($pdo, $deviceId, $sampledAt, $payload['usage_users'] ?? [], is_array($payload['router_clock'] ?? null) ? $payload['router_clock'] : null);
    // Enregistrement DNS desactive (2026-10-11) : mikrotik_traffic_dns_signature_samples et
    // mikrotik_traffic_dns_client_signature_samples n'etaient lues par aucune page (verifie : aucun SELECT
    // dans tout le code sur ces deux tables). L'onglet "Signatures DNS" de traffic_control.php est calcule
    // en direct depuis $payload['dns_signature_analysis'], pas depuis ces tables. Fonction conservee
    // (trafficListenerRecordDnsSignatures) si un vrai besoin d'historique apparait un jour, sous une forme plus legere.

    // Retention 120 jours (2026-10-11), meme duree et meme principe que mikrotik_wan_usage_samples
    // (includes/mikrotik_wan_usage_collector.php) : purge a chaque cycle, requete indexee sur sampled_at.
    $pdo->exec('DELETE FROM mikrotik_traffic_samples WHERE sampled_at < DATE_SUB(NOW(), INTERVAL 120 DAY)');
    $pdo->exec('DELETE FROM mikrotik_traffic_user_usage_samples WHERE sampled_at < DATE_SUB(NOW(), INTERVAL 120 DAY)');
    $pdo->exec('DELETE FROM mikrotik_traffic_user_usage_daily WHERE usage_day < DATE_SUB(CURDATE(), INTERVAL 120 DAY)');

    // Synthese journaliere : un seul passage d'agregation pour la journee ecoulee, dans les 10 premieres
    // minutes apres minuit (fenetre large pour couvrir un cron en retard ou manque une fois). Idempotent :
    // si elle tourne plusieurs fois dans la fenetre, le resultat est identique (ON DUPLICATE KEY UPDATE).
    if (date('H:i', strtotime($sampledAt)) < '00:10') {
        $yesterday = date('Y-m-d', strtotime($sampledAt . ' -1 day'));
        trafficListenerAggregateDailyUsage($pdo, $deviceId, $yesterday);
    }
}

function trafficListenerDailyUsage(PDO $pdo, string $deviceId, ?string $day = null, ?array $routerClock = null, int $cycleStartDay = 1, string $usageMode = 'day'): array
{
    trafficListenerEnsureHistoryTables($pdo);
    $systemToday = date('Y-m-d');
    $targetDay = $day && preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) ? $day : $systemToday;
    $nextDay = date('Y-m-d', strtotime($targetDay . ' +1 day') ?: time());
    $cycleStartDay = max(1, min(28, $cycleStartDay));
    $usageMode = $usageMode === 'month' ? 'month' : 'day';
    $targetTs = strtotime($targetDay) ?: time();
    $cycleMonthStart = date('Y-m-01', $targetTs);
    $monthStart = date('Y-m-d', strtotime($cycleMonthStart . ' +' . ($cycleStartDay - 1) . ' days') ?: $targetTs);
    if ((int)date('j', $targetTs) < $cycleStartDay) {
        $previousCycleMonth = date('Y-m-01', strtotime($cycleMonthStart . ' -1 month') ?: $targetTs);
        $monthStart = date('Y-m-d', strtotime($previousCycleMonth . ' +' . ($cycleStartDay - 1) . ' days') ?: $targetTs);
    }
    $monthEnd = date('Y-m-d', strtotime($monthStart . ' +1 month') ?: time());
    $empty = [
        'day' => $targetDay,
        'start' => $targetDay . ' 00:00:00',
        'end' => $nextDay . ' 00:00:00',
        'month_start' => $monthStart,
        'month_end' => $monthEnd,
        'cycle_start_day' => $cycleStartDay,
        'cycle_total' => 0,
        'cycle_totals' => ['all' => 0, 'hotspot' => 0, 'ip_binding' => 0, 'observed' => 0],
        'time_basis' => 'system',
        'router_offset_seconds' => 0,
        'sample_count' => 0,
        'totals' => ['all' => 0, 'hotspot' => 0, 'ip_binding' => 0, 'observed' => 0],
        'all_users' => [],
        'top_hotspot' => [],
        'top_ip_binding' => [],
        'hourly' => [],
        'monthly' => ['days' => []],
    ];
    if ($deviceId === '') return $empty;

    $summaryStmt = $pdo->prepare('SELECT source_type, COUNT(DISTINCT sampled_at) AS samples, COALESCE(SUM(bytes_delta),0) AS bytes FROM mikrotik_traffic_user_usage_samples WHERE device_id=? AND sampled_at>=? AND sampled_at<? AND sampled_at <= NOW() GROUP BY source_type');
    $summaryStmt->execute([$deviceId, $targetDay . ' 00:00:00', $nextDay . ' 00:00:00']);
    foreach ($summaryStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $type = (string)($row['source_type'] ?? '');
        $bytes = (int)($row['bytes'] ?? 0);
        $empty['sample_count'] = max($empty['sample_count'], (int)($row['samples'] ?? 0));
        $empty['totals']['all'] += $bytes;
        if (array_key_exists($type, $empty['totals'])) {
            $empty['totals'][$type] = $bytes;
        }
    }

    $mapUsageRow = static function (array $row): array {
        $bytes = (int)($row['bytes'] ?? 0);
        return [
            'source_type' => (string)($row['source_type'] ?? ''),
            'label' => (string)($row['label'] ?? ''),
            'local_ip' => (string)($row['local_ip'] ?? ''),
            'mac' => (string)($row['mac'] ?? ''),
            'bytes' => $bytes,
            'counter_count' => (int)($row['counter_count'] ?? 0),
            'sample_hits' => (int)($row['sample_hits'] ?? 0),
            'connections' => (int)($row['connections'] ?? 0),
            'active_connections' => (int)($row['active_connections'] ?? 0),
        ];
    };

    if ($usageMode === 'day') {
        $allStmt = $pdo->prepare('SELECT source_type,user_key,MAX(label) AS label,MAX(local_ip) AS local_ip,MAX(mac) AS mac,COALESCE(SUM(bytes_delta),0) AS bytes,COUNT(DISTINCT NULLIF(counter_key, \'\')) AS counter_count,SUM(CASE WHEN bytes_delta > 0 THEN 1 ELSE 0 END) AS sample_hits,MAX(connections) AS connections,MAX(active_connections) AS active_connections FROM mikrotik_traffic_user_usage_samples WHERE device_id=? AND sampled_at>=? AND sampled_at<? AND sampled_at <= NOW() GROUP BY source_type,user_key ORDER BY bytes DESC,label ASC LIMIT 300');
        $allStmt->execute([$deviceId, $targetDay . ' 00:00:00', $nextDay . ' 00:00:00']);
        $empty['all_users'] = array_map($mapUsageRow, $allStmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }
    $empty['top_hotspot'] = array_values(array_slice(array_filter($empty['all_users'], static fn(array $row): bool => $row['source_type'] === 'hotspot'), 0, 10));
    $empty['top_ip_binding'] = array_values(array_slice(array_filter($empty['all_users'], static fn(array $row): bool => $row['source_type'] === 'ip_binding'), 0, 10));

    $hourStmt = $pdo->prepare('SELECT HOUR(sampled_at) AS hour,COALESCE(SUM(bytes_delta),0) AS bytes FROM mikrotik_traffic_user_usage_samples WHERE device_id=? AND sampled_at>=? AND sampled_at<? AND sampled_at <= NOW() GROUP BY HOUR(sampled_at) ORDER BY hour');
    $hourStmt->execute([$deviceId, $targetDay . ' 00:00:00', $nextDay . ' 00:00:00']);
    $hourly = array_fill(0, 24, 0);
    foreach ($hourStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $hour = max(0, min(23, (int)($row['hour'] ?? 0)));
        $hourly[$hour] = (int)($row['bytes'] ?? 0);
    }
    $empty['hourly'] = array_map(static fn(int $hour, int $bytes): array => ['hour' => sprintf('%02d:00', $hour), 'bytes' => $bytes], array_keys($hourly), $hourly);

    // Vue mensuelle : lue depuis la synthese journaliere (mikrotik_traffic_user_usage_daily), pas depuis
    // la table brute. Un mois sur la table brute scanne ~1 million de lignes (verifie par EXPLAIN,
    // 2026-10-11) ; la synthese journaliere ne contient qu'au plus (jours x clients) lignes.
    if ($usageMode === 'month') {
        $cycleTypeStmt = $pdo->prepare('SELECT source_type,COALESCE(SUM(bytes_total),0) AS bytes FROM mikrotik_traffic_user_usage_daily WHERE device_id=? AND usage_day>=? AND usage_day<? GROUP BY source_type');
        $cycleTypeStmt->execute([$deviceId, $monthStart, $monthEnd]);
        foreach ($cycleTypeStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $type = (string)($row['source_type'] ?? '');
            if (array_key_exists($type, $empty['cycle_totals'])) {
                $empty['cycle_totals'][$type] = (int)($row['bytes'] ?? 0);
            }
        }

        // Classement mensuel par client : projection des sommes journalieres sur le mois selectionne.
        $monthAllStmt = $pdo->prepare('SELECT source_type,user_key,MAX(label) AS label,MAX(local_ip) AS local_ip,MAX(mac) AS mac,COALESCE(SUM(bytes_total),0) AS bytes,MAX(connections) AS connections,MAX(active_connections) AS active_connections FROM mikrotik_traffic_user_usage_daily WHERE device_id=? AND usage_day>=? AND usage_day<? GROUP BY source_type,user_key ORDER BY bytes DESC,label ASC LIMIT 300');
        $monthAllStmt->execute([$deviceId, $monthStart, $monthEnd]);
        $empty['all_users'] = array_map($mapUsageRow, $monthAllStmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
        $empty['top_hotspot'] = array_values(array_slice(array_filter($empty['all_users'], static fn(array $row): bool => $row['source_type'] === 'hotspot'), 0, 10));
        $empty['top_ip_binding'] = array_values(array_slice(array_filter($empty['all_users'], static fn(array $row): bool => $row['source_type'] === 'ip_binding'), 0, 10));
    }

    $byDay = [];
    if ($usageMode === 'month') {
        $monthStmt = $pdo->prepare('SELECT usage_day,COALESCE(SUM(bytes_total),0) AS bytes FROM mikrotik_traffic_user_usage_daily WHERE device_id=? AND usage_day>=? AND usage_day<? GROUP BY usage_day ORDER BY usage_day');
        $monthStmt->execute([$deviceId, $monthStart, $monthEnd]);
        foreach ($monthStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $byDay[(string)$row['usage_day']] = (int)($row['bytes'] ?? 0);
        }
    }
    $days = [];
    $cycleTotal = 0;
    for ($cursor = strtotime($monthStart), $endTime = strtotime($monthEnd); $cursor !== false && $endTime !== false && $cursor < $endTime; $cursor = strtotime('+1 day', $cursor)) {
        $label = date('Y-m-d', $cursor);
        $bytes = (int)($byDay[$label] ?? 0);
        $cycleTotal += $bytes;
        $days[] = ['day' => $label, 'label' => date('d/m', $cursor), 'bytes' => $bytes];
    }
    $empty['cycle_total'] = $cycleTotal;
    $empty['cycle_totals']['all'] = $cycleTotal;
    $empty['monthly'] = ['days' => $days];
    return $empty;
}

function trafficListenerHistory(PDO $pdo, string $deviceId, int $hours = 24): array
{
    trafficListenerEnsureHistoryTables($pdo);
    $hours = max(1, min(48, $hours));
    $stmt = $pdo->prepare('SELECT * FROM mikrotik_traffic_samples WHERE device_id=? AND sampled_at >= DATE_SUB(NOW(), INTERVAL ' . $hours . ' HOUR) ORDER BY sampled_at ASC LIMIT 2880');
    $stmt->execute([$deviceId]);
    $samples = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $latest = $samples ? $samples[array_key_last($samples)] : null;
    $totals = [
        'firewall_total_delta' => 0,
        'firewall_blocked_delta' => 0,
        'firewall_accepted_delta' => 0,
        'adlist_match_delta' => 0,
    ];
    foreach ($samples as $row) {
        foreach ($totals as $key => $value) $totals[$key] += (int)($row[$key] ?? 0);
    }
    return [
        'hours' => $hours,
        'sample_count' => count($samples),
        'latest' => $latest,
        'totals' => $totals,
        'samples' => $samples,
    ];
}

function trafficListenerBuild(array $device): array
{
    $api = connectToMikrotikApiByDevice($device);

    try {
        $totalTracked = (int)$api->comm('/ip/firewall/connection/print', ['count-only' => '']);
        $activeRows = $api->comm('/ip/hotspot/active/print', [
            '.proplist' => '.id,user,address,mac-address,bytes-in,bytes-out,uptime,session-id',
        ]);
        $bindingRows = $api->comm('/ip/hotspot/ip-binding/print', [
            '.proplist' => 'address,mac-address,type,comment,disabled',
        ]);
        $hostRows = $api->comm('/ip/hotspot/host/print', [
            '.proplist' => 'address,mac-address,bytes-in,bytes-out',
        ]);
        if (!is_array($hostRows) || isset($hostRows['!trap'])) {
            $hostRows = [];
        }
        $dnsCacheRows = $api->comm('/ip/dns/cache/print', [
            '.proplist' => 'name,type,data',
        ]);
        $dnsCacheAvailable = is_array($dnsCacheRows) && !isset($dnsCacheRows['!trap']);
        if (!$dnsCacheAvailable) {
            $dnsCacheRows = [];
        }
        $firewallAnalysis = trafficListenerFirewallAnalysis($api);
        $adlistStats = trafficListenerAdlistStats($api);
        $routerClock = trafficListenerRouterClock($api);
        $updatePolicyStatus = trafficListenerUpdatePolicyStatus($api);

    $hostUsageByIp = [];
    foreach (is_array($hostRows) ? $hostRows : [] as $hostRow) {
        if (!is_array($hostRow)) {
            continue;
        }
        $address = trim((string)($hostRow['address'] ?? ''));
        if ($address === '') {
            continue;
        }
        $bytes = trafficListenerRouterosBytes($hostRow);
        if ($bytes !== null) {
            $hostUsageByIp[$address] = [
                'bytes_total' => $bytes,
                'mac' => trim((string)($hostRow['mac-address'] ?? '')),
            ];
        }
    }

    $activeUsers = [];
    foreach (is_array($activeRows) ? $activeRows : [] as $activeRow) {
        if (!is_array($activeRow)) {
            continue;
        }
        $address = trim((string)($activeRow['address'] ?? ''));
        if ($address === '') {
            continue;
        }
        $activeBytes = trafficListenerRouterosBytes($activeRow);
        $sessionId = trim((string)(($activeRow['session-id'] ?? '') ?: ($activeRow['.id'] ?? '')));
        $activeUsers[$address] = [
            'username' => trim((string)($activeRow['user'] ?? '')),
            'mac' => trim((string)($activeRow['mac-address'] ?? '')),
            'usage_bytes_total' => $activeBytes,
            'usage_counter_source' => $activeBytes !== null ? 'hotspot_active' : '',
            'usage_counter_key' => $sessionId !== '' ? $sessionId : implode('|', array_filter([trim((string)($activeRow['user'] ?? '')), trim((string)($activeRow['mac-address'] ?? '')), $address])),
        ];
    }

    $ipBindings = [];
    foreach (is_array($bindingRows) ? $bindingRows : [] as $bindingRow) {
        if (!is_array($bindingRow)) {
            continue;
        }
        if (mikrotikApiBoolean($bindingRow['disabled'] ?? false)) {
            continue;
        }
        $address = trim((string)($bindingRow['address'] ?? ''));
        if ($address === '') {
            continue;
        }
        $hostUsage = $hostUsageByIp[$address] ?? [];
        $ipBindings[$address] = [
            'comment' => trim((string)($bindingRow['comment'] ?? '')),
            'type' => trim((string)($bindingRow['type'] ?? '')),
            'mac' => trim((string)($bindingRow['mac-address'] ?? ($hostUsage['mac'] ?? ''))),
            'usage_bytes_total' => array_key_exists('bytes_total', $hostUsage) ? (int)$hostUsage['bytes_total'] : null,
            'usage_counter_source' => array_key_exists('bytes_total', $hostUsage) ? 'hotspot_host' : '',
            'usage_counter_key' => implode('|', array_filter([trim((string)($bindingRow['mac-address'] ?? ($hostUsage['mac'] ?? ''))), $address])),
        ];
    }

    $dnsByIp = [];
    foreach (is_array($dnsCacheRows) ? $dnsCacheRows : [] as $dnsRow) {
        if (!is_array($dnsRow)) {
            continue;
        }
        $address = trim((string)($dnsRow['data'] ?? ''));
        $name = strtolower(rtrim(trim((string)($dnsRow['name'] ?? '')), '.'));
        if ($name === '' || filter_var($address, FILTER_VALIDATE_IP) === false) {
            continue;
        }
        $dnsByIp[$address][$name] = true;
    }
    unset($activeRows, $bindingRows, $hostRows, $dnsCacheRows);

    $flows = [];
    $seen = [];
    $summary = [
        'tracked' => $totalTracked,
        'observed' => 0,
        'active_hotspot' => count($activeUsers),
        'outbound' => 0,
        'inbound' => 0,
        'redirected' => 0,
        'active_now' => 0,
        'idle_now' => 0,
        'tcp' => 0,
        'udp' => 0,
        'https' => 0,
        'tcp_states' => [],
        'dns_cache_addresses' => count($dnsByIp),
        'dns_cache_available' => $dnsCacheAvailable,
        'dns_resolved_connections' => 0,
        'review_users' => 0,
        'ip_classes' => [],
        'suspicious_inbound_sources' => 0,
        'adlists' => $adlistStats,
    ];

    $appendRows = static function (array &$rows, string $direction, bool $rememberIds = false) use (&$flows, &$seen, &$summary, $activeUsers, $ipBindings, $dnsByIp): void {
        foreach ($rows as $rowIndex => $row) {
            unset($rows[$rowIndex]);
            if (!is_array($row)) {
                continue;
            }

            $rowId = trim((string)($row['.id'] ?? ''));
            $signature = $rowId;
            if ($rowId !== '' && isset($seen[$signature])) {
                continue;
            }
            if ($rememberIds && $rowId !== '') {
                $seen[$signature] = true;
            }

            if ($direction === 'sortant') {
                $local = trafficListenerEndpoint((string)($row['src-address'] ?? ''));
                $remote = trafficListenerEndpoint((string)($row['dst-address'] ?? ''));
                $servicePort = max(0, (int)($row['dst-port'] ?? $remote['port']));
                $directionLabel = 'sortant';
            } else {
                $source = trafficListenerEndpoint((string)($row['src-address'] ?? ''));
                if (trafficListenerIsPrivateIp($source['ip'])) {
                    $local = $source;
                    $remote = trafficListenerEndpoint((string)($row['reply-src-address'] ?? $row['dst-address'] ?? ''));
                    $servicePort = max(0, (int)($row['reply-src-port'] ?? $row['dst-port'] ?? $remote['port']));
                    $directionLabel = 'redirige';
                } else {
                    $local = trafficListenerEndpoint((string)($row['reply-src-address'] ?? ''));
                    $remote = $source;
                    $servicePort = max(0, (int)($row['reply-src-port'] ?? $row['dst-port'] ?? $local['port']));
                    $directionLabel = 'entrant';
                }
            }

            if ($local['ip'] === '' || $remote['ip'] === '') {
                continue;
            }

            $protocol = strtolower(trim((string)($row['protocol'] ?? 'other')));
            $rate = trafficListenerRate($row);
            $isActive = $rate > 0;
            $tcpState = strtolower(trim((string)($row['tcp-state'] ?? '')));
            $identity = $activeUsers[$local['ip']] ?? ['username' => '', 'mac' => '', 'usage_bytes_total' => null, 'usage_counter_source' => '', 'usage_counter_key' => ''];
            $binding = $ipBindings[$local['ip']] ?? ['comment' => '', 'type' => '', 'mac' => '', 'usage_bytes_total' => null, 'usage_counter_source' => '', 'usage_counter_key' => ''];
            $flowKey = implode('|', [$directionLabel, $local['ip'], $remote['ip'], $protocol, $servicePort]);
            if (!isset($flows[$flowKey])) {
                $flows[$flowKey] = [
                    'direction' => $directionLabel,
                    'local_ip' => $local['ip'],
                    'username' => $identity['username'],
                    'mac' => $identity['mac'] !== '' ? $identity['mac'] : $binding['mac'],
                    'binding_comment' => $binding['comment'],
                    'binding_type' => $binding['type'],
                    'remote_ip' => $remote['ip'],
                    'protocol' => $protocol,
                    'port' => $servicePort,
                    'service' => trafficListenerService($protocol, $servicePort),
                    'dns_names' => array_slice(array_keys($dnsByIp[$remote['ip']] ?? []), 0, 3),
                    'connections' => 0,
                    'active_connections' => 0,
                    'idle_connections' => 0,
                    'rate_bps' => 0,
                    'bytes' => 0,
                    'usage_bytes_total' => $identity['usage_bytes_total'] !== null ? $identity['usage_bytes_total'] : ($binding['usage_bytes_total'] ?? null),
                    'usage_counter_source' => $identity['usage_counter_source'] !== '' ? $identity['usage_counter_source'] : ($binding['usage_counter_source'] ?? ''),
                    'usage_counter_key' => $identity['usage_counter_key'] !== '' ? $identity['usage_counter_key'] : ($binding['usage_counter_key'] ?? ''),
                    'local_ip_class' => trafficListenerIpClassification($local['ip']),
                    'remote_ip_class' => trafficListenerIpClassification($remote['ip']),
                ];
            }

            $flows[$flowKey]['connections']++;
            $flows[$flowKey][$isActive ? 'active_connections' : 'idle_connections']++;
            $flows[$flowKey]['rate_bps'] += $rate;
            $flows[$flowKey]['bytes'] += trafficListenerBytes($row);
            $summary['observed']++;
            $remoteClass = trafficListenerIpClassification($remote['ip']);
            $summary['ip_classes'][$remoteClass] = ($summary['ip_classes'][$remoteClass] ?? 0) + 1;
            if ($directionLabel === 'entrant' && $remoteClass !== 'publique plausible') {
                $summary['suspicious_inbound_sources']++;
            }
            $summary[$directionLabel === 'sortant' ? 'outbound' : ($directionLabel === 'entrant' ? 'inbound' : 'redirected')]++;
            $summary[$isActive ? 'active_now' : 'idle_now']++;
            if ($protocol === 'tcp' || $protocol === 'udp') {
                $summary[$protocol]++;
            }
            if ($protocol === 'tcp' && $tcpState !== '') {
                $summary['tcp_states'][$tcpState] = ($summary['tcp_states'][$tcpState] ?? 0) + 1;
            }
            if ($servicePort === 443) {
                $summary['https']++;
            }
            if (!empty($flows[$flowKey]['dns_names'])) {
                $summary['dns_resolved_connections']++;
            }
        }
    };

    $inboundRows = trafficListenerRows($api, ['?dstnat' => 'true']);
    $appendRows($inboundRows, 'entrant', true);
    unset($inboundRows);

    $outboundRows = trafficListenerRows($api, ['?srcnat' => 'true']);
    $appendRows($outboundRows, 'sortant');
    unset($outboundRows);

    $flowRows = array_values($flows);
    $idleAnalysis = [
        'total_idle' => 0,
        'local_redirected' => 0,
        'local_reply' => 0,
        'known_system' => 0,
        'watched_service' => 0,
        'unresolved_wan' => 0,
        'unclassified_wan' => 0,
        'review_idle' => 0,
    ];
    foreach ($flowRows as &$flowRow) {
        $signatureKeys = [];
        foreach ($flowRow['dns_names'] as $dnsName) {
            $signature = trafficListenerDnsSignature((string)$dnsName);
            $signatureKeys[$signature['key']] = $signature;
        }
        $flowRow['signature_keys'] = array_keys($signatureKeys);
        $flowRow['signature_labels'] = array_values(array_unique(array_map(static fn(array $signature): string => (string)$signature['label'], $signatureKeys)));
        $idleCount = max(0, (int)($flowRow['idle_connections'] ?? 0));
        if ($idleCount > 0) {
            $idleAnalysis['total_idle'] += $idleCount;
            $remoteClass = (string)($flowRow['remote_ip_class'] ?? '');
            $isLocalReplyPath = (string)($flowRow['remote_ip'] ?? '') === '10.10.10.2' || isset($signatureKeys['connectivity_probe']);
            $isLocalPath = $flowRow['direction'] === 'redirige' || $remoteClass !== 'publique plausible';
            if ($isLocalReplyPath) {
                $idleAnalysis['local_reply'] += $idleCount;
            } elseif ($isLocalPath) {
                $idleAnalysis['local_redirected'] += $idleCount;
            } elseif (array_intersect(array_keys($signatureKeys), ['windows_update', 'apple_update', 'android_update', 'google_background', 'google_play_services'])) {
                $idleAnalysis['known_system'] += $idleCount;
            } elseif (array_intersect(array_keys($signatureKeys), ['streaming_google', 'meta_social', 'tiktok_bytedance', 'whatsapp', 'local_service'])) {
                $idleAnalysis['watched_service'] += $idleCount;
            } elseif (empty($flowRow['dns_names'])) {
                $idleAnalysis['unresolved_wan'] += $idleCount;
                $idleAnalysis['review_idle'] += $idleCount;
            } else {
                $idleAnalysis['unclassified_wan'] += $idleCount;
                $idleAnalysis['review_idle'] += $idleCount;
            }
        }
        $flowRow['review_required'] = $flowRow['service'] === 'Port P2P courant'
            || ((int)($flowRow['active_connections'] ?? 0) === 0 && $idleCount >= 120 && empty($flowRow['dns_names']) && (string)($flowRow['remote_ip_class'] ?? '') === 'publique plausible')
            || ((int)($flowRow['active_connections'] ?? 0) === 0 && $idleCount >= 250 && in_array('unclassified', $flowRow['signature_keys'], true));
        $flowRow['diagnostic'] = $flowRow['review_required']
            ? 'review'
            : ($flowRow['active_connections'] > 0 ? 'active' : 'retained');
    }
    unset($flowRow);
    $summary['idle_analysis'] = $idleAnalysis;
    usort($flowRows, static function (array $left, array $right): int {
        return ($right['review_required'] <=> $left['review_required'])
            ?: ($right['active_connections'] <=> $left['active_connections'])
            ?: ($right['connections'] <=> $left['connections'])
            ?: ($right['bytes'] <=> $left['bytes']);
    });

    $users = [];
    foreach ($flowRows as $flow) {
        $key = $flow['local_ip'];
        if (!isset($users[$key])) {
            $users[$key] = [
                'local_ip' => $flow['local_ip'],
                'username' => $flow['username'],
                'mac' => $flow['mac'],
                'binding_comment' => $flow['binding_comment'],
                'binding_type' => $flow['binding_type'],
                'connections' => 0,
                'active_connections' => 0,
                'idle_connections' => 0,
                'tcp' => 0,
                'udp' => 0,
                'bytes' => 0,
                'usage_bytes_total' => $flow['usage_bytes_total'],
                'usage_counter_source' => $flow['usage_counter_source'],
                'usage_counter_key' => $flow['usage_counter_key'],
                'p2p_connections' => 0,
                '_destinations' => [],
                '_domain_connections' => [],
                'idle_outbound_connections' => 0,
                'ip_class' => $flow['local_ip_class'],
            ];
        }
        $users[$key]['connections'] += $flow['connections'];
        $users[$key]['active_connections'] += $flow['active_connections'];
        $users[$key]['idle_connections'] += $flow['idle_connections'];
        $users[$key]['bytes'] += $flow['bytes'];
        if ($users[$key]['usage_bytes_total'] === null && $flow['usage_bytes_total'] !== null) {
            $users[$key]['usage_bytes_total'] = $flow['usage_bytes_total'];
            $users[$key]['usage_counter_source'] = $flow['usage_counter_source'];
            $users[$key]['usage_counter_key'] = $flow['usage_counter_key'];
        }
        if ($flow['direction'] === 'sortant') {
            $users[$key]['idle_outbound_connections'] += $flow['idle_connections'];
        }
        if ($flow['protocol'] === 'tcp' || $flow['protocol'] === 'udp') {
            $users[$key][$flow['protocol']] += $flow['connections'];
        }
        if ($flow['review_required']) {
            $users[$key]['p2p_connections'] += $flow['connections'];
        }
        $users[$key]['_destinations'][$flow['remote_ip']] = true;
        foreach ($flow['dns_names'] as $dnsName) {
            $users[$key]['_domain_connections'][$dnsName] = ($users[$key]['_domain_connections'][$dnsName] ?? 0) + $flow['connections'];
        }
    }

    foreach ($users as &$user) {
        $user['destinations'] = count($user['_destinations']);
        arsort($user['_domain_connections']);
        $user['top_domains'] = array_slice(array_keys($user['_domain_connections']), 0, 3);
        $user['top_domain_counts'] = array_map(
            static fn(string $domain): array => ['name' => $domain, 'connections' => $user['_domain_connections'][$domain]],
            $user['top_domains']
        );
        $user['platform_signals'] = trafficListenerPlatformSignals(array_keys($user['_domain_connections']));
        $hasIdentity = $user['username'] !== '' || $user['binding_comment'] !== '' || $user['binding_type'] !== '';
        $user['review_required'] = $user['p2p_connections'] > 0
            || ($user['connections'] >= 1000 && $user['active_connections'] === 0 && !$hasIdentity);
        $user['diagnostic'] = $user['review_required']
            ? 'review'
            : ($user['active_connections'] > 0 ? 'active' : 'retained');
        if ($user['review_required']) {
            $summary['review_users']++;
        }
        unset($user['_destinations']);
        unset($user['_domain_connections']);
    }
    unset($user);

    $userRows = array_values($users);
    usort($userRows, static fn(array $left, array $right): int => $right['connections'] <=> $left['connections']);
    $summary['local_users'] = count($userRows);
    $summary['p2p_users'] = count(array_filter($userRows, static fn(array $row): bool => (int)($row['p2p_connections'] ?? 0) > 0));

    $usageRows = $userRows;
    $knownUsageCounters = [];
    foreach ($usageRows as $usageRow) {
        $knownKey = trim((string)($usageRow['usage_counter_key'] ?? ''));
        if ($knownKey !== '') {
            $knownUsageCounters[$knownKey] = true;
        }
    }
    foreach ($activeUsers as $address => $identity) {
        $counterKey = trim((string)($identity['usage_counter_key'] ?? ''));
        if ($counterKey === '' || isset($knownUsageCounters[$counterKey]) || ($identity['usage_bytes_total'] ?? null) === null) {
            continue;
        }
        $usageRows[] = [
            'local_ip' => $address,
            'username' => (string)($identity['username'] ?? ''),
            'mac' => (string)($identity['mac'] ?? ''),
            'binding_comment' => '',
            'binding_type' => '',
            'connections' => 0,
            'active_connections' => 0,
            'usage_bytes_total' => $identity['usage_bytes_total'],
            'usage_counter_source' => (string)($identity['usage_counter_source'] ?? ''),
            'usage_counter_key' => $counterKey,
        ];
        $knownUsageCounters[$counterKey] = true;
    }
    foreach ($ipBindings as $address => $binding) {
        if (isset($activeUsers[$address])) {
            continue;
        }
        $counterKey = trim((string)($binding['usage_counter_key'] ?? ''));
        if ($counterKey === '' || isset($knownUsageCounters[$counterKey]) || ($binding['usage_bytes_total'] ?? null) === null) {
            continue;
        }
        $usageRows[] = [
            'local_ip' => $address,
            'username' => '',
            'mac' => (string)($binding['mac'] ?? ''),
            'binding_comment' => (string)($binding['comment'] ?? ''),
            'binding_type' => (string)($binding['type'] ?? ''),
            'connections' => 0,
            'active_connections' => 0,
            'usage_bytes_total' => $binding['usage_bytes_total'],
            'usage_counter_source' => (string)($binding['usage_counter_source'] ?? ''),
            'usage_counter_key' => $counterKey,
        ];
        $knownUsageCounters[$counterKey] = true;
    }

    $domainActivity = [];
    $clientDomainActivity = [];
    foreach ($flowRows as $flow) {
        if ($flow['direction'] !== 'sortant') continue;
        foreach ($flow['dns_names'] as $dnsName) {
            if (!isset($domainActivity[$dnsName])) {
                $domainActivity[$dnsName] = [
                    'domain' => $dnsName,
                    'connections' => 0,
                    'active_connections' => 0,
                    'idle_connections' => 0,
                    '_devices' => [],
                ];
            }
            $domainActivity[$dnsName]['connections'] += $flow['connections'];
            $domainActivity[$dnsName]['active_connections'] += $flow['active_connections'];
            $domainActivity[$dnsName]['idle_connections'] += $flow['idle_connections'];
            $domainActivity[$dnsName]['_devices'][$flow['local_ip']] = true;

            $clientDomainKey = implode('|', [$flow['local_ip'], $dnsName]);
            if (!isset($clientDomainActivity[$clientDomainKey])) {
                $clientDomainActivity[$clientDomainKey] = [
                    'domain' => $dnsName,
                    'local_ip' => $flow['local_ip'],
                    'username' => $flow['username'],
                    'mac' => $flow['mac'],
                    'binding_comment' => $flow['binding_comment'],
                    'connections' => 0,
                    'active_connections' => 0,
                    'idle_connections' => 0,
                    'bytes' => 0,
                ];
            }
            $clientDomainActivity[$clientDomainKey]['connections'] += $flow['connections'];
            $clientDomainActivity[$clientDomainKey]['active_connections'] += $flow['active_connections'];
            $clientDomainActivity[$clientDomainKey]['idle_connections'] += $flow['idle_connections'];
            $clientDomainActivity[$clientDomainKey]['bytes'] += $flow['bytes'];
        }
    }
    foreach ($domainActivity as &$domainRow) {
        $domainRow['devices'] = count($domainRow['_devices']);
        $domainRow['platform_signals'] = trafficListenerPlatformSignals([$domainRow['domain']]);
        $domainRow['signature'] = trafficListenerDnsSignature((string)$domainRow['domain']);
        unset($domainRow['_devices']);
    }
    unset($domainRow);
    $domainRows = array_values($domainActivity);
    usort($domainRows, static fn(array $left, array $right): int =>
        ($right['idle_connections'] <=> $left['idle_connections'])
        ?: ($right['connections'] <=> $left['connections'])
    );
    $clientDomainRows = array_values($clientDomainActivity);
    foreach ($clientDomainRows as &$clientDomainRow) {
        $clientDomainRow['signature'] = trafficListenerDnsSignature((string)$clientDomainRow['domain']);
    }
    unset($clientDomainRow);
    usort($clientDomainRows, static fn(array $left, array $right): int =>
        ($right['active_connections'] <=> $left['active_connections'])
        ?: ($right['connections'] <=> $left['connections'])
        ?: ($right['bytes'] <=> $left['bytes'])
    );
    $dnsSignatureAnalysis = trafficListenerDnsSignatureAnalysis($domainRows, $updatePolicyStatus);

    $payload = [
        'success' => true,
        'router_clock' => $routerClock,
        'device' => [
            'id' => (string)($device['id'] ?? ''),
            'name' => getDeviceDisplayLabel($device),
        ],
        'summary' => $summary,
        'firewall' => $firewallAnalysis,
        'users' => array_slice($userRows, 0, 100),
        'usage_users' => array_values($usageRows),
        'flows' => array_slice($flowRows, 0, 250),
        'domains' => array_slice($domainRows, 0, 100),
        'dns_signature_analysis' => $dnsSignatureAnalysis,
        'dns_client_signatures' => array_slice($clientDomainRows, 0, 500),
        'captured_at' => date(DATE_ATOM),
        'cache_seconds' => 60,
        'cache_age_seconds' => 0,
        'served_from_cache' => false,
        'diagnostic_threshold' => 1000,
        'note' => 'Lecture de la table conntrack NAT, sans capture de contenu.',
    ];
    $api->disconnect();
    return $payload;
    } catch (Throwable $e) {
        $api->disconnect();
        throw $e;
    }
}

function trafficListenerCachedPayload(string $cacheFile): ?array
{
    $payload = json_decode((string)file_get_contents($cacheFile), true);
    if (!is_array($payload) || empty($payload['success'])) {
        return null;
    }
    $payload['served_from_cache'] = true;
    $payload['cache_age_seconds'] = max(0, time() - (int)filemtime($cacheFile));
    return $payload;
}

if ($trafficListenerIsEndpoint) {
try {
    $deviceId = trim((string)($_GET['device_id'] ?? ''));
    if ($deviceId === '') {
        throw new RuntimeException('Device MikroTik manquant.');
    }
    $device = requireAccessibleDevice(loadDeviceStore(), $deviceId);
    if (normalizeDeviceType((string)($device['type'] ?? '')) !== 'mikrotik') {
        throw new RuntimeException('Le device sélectionné n est pas un MikroTik.');
    }
    $deviceKey = trim((string)($device['id'] ?? $device['host'] ?? 'active'));
    if (($_GET['usage_only'] ?? '') === '1') {
        $usage = trafficListenerDailyUsage(
            $pdo,
            $deviceKey,
            trim((string)($_GET['day'] ?? '')) ?: null,
            null,
            max(1, min(28, (int)($_GET['cycle_start'] ?? 1))),
            trim((string)($_GET['usage_mode'] ?? 'day'))
        );
        echo json_encode(['success' => true, 'usage_daily' => $usage], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }
    $historyHours = max(1, min(48, (int)($_GET['hours'] ?? 24)));
    $cycleStartDay = max(1, min(28, (int)($_GET['cycle_start'] ?? 1)));
    $cacheFile = sys_get_temp_dir() . '/mikrotik_traffic_listener_v11_' . md5($deviceKey) . '.json';
    $lockFile = $cacheFile . '.lock';
    $cacheTtl = 60;

    if (is_file($cacheFile) && (time() - (int)filemtime($cacheFile)) < $cacheTtl) {
        $cached = trafficListenerCachedPayload($cacheFile);
        if ($cached !== null) {
            if (!isset($cached['summary']['idle_analysis']) || !is_array($cached['summary']['idle_analysis'])) {
                $cached = null;
            } else {
                $cached['history'] = trafficListenerHistory($pdo, $deviceKey, $historyHours);
                $cached['usage_daily'] = trafficListenerDailyUsage($pdo, $deviceKey, trim((string)($_GET['day'] ?? '')) ?: null, is_array($cached['router_clock'] ?? null) ? $cached['router_clock'] : null, $cycleStartDay, trim((string)($_GET['usage_mode'] ?? 'day')));
                echo json_encode($cached, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                exit;
            }
        }
    }

    $lock = fopen($lockFile, 'c');
    if ($lock === false || !flock($lock, LOCK_EX)) {
        throw new RuntimeException('Collecteur momentanément indisponible.');
    }

    clearstatcache(true, $cacheFile);
    if (is_file($cacheFile) && (time() - (int)filemtime($cacheFile)) < $cacheTtl) {
        $cached = trafficListenerCachedPayload($cacheFile);
        if ($cached !== null) {
            if (!isset($cached['summary']['idle_analysis']) || !is_array($cached['summary']['idle_analysis'])) {
                $cached = null;
            } else {
                flock($lock, LOCK_UN);
                fclose($lock);
                $cached['history'] = trafficListenerHistory($pdo, $deviceKey, $historyHours);
                $cached['usage_daily'] = trafficListenerDailyUsage($pdo, $deviceKey, trim((string)($_GET['day'] ?? '')) ?: null, is_array($cached['router_clock'] ?? null) ? $cached['router_clock'] : null, $cycleStartDay, trim((string)($_GET['usage_mode'] ?? 'day')));
                echo json_encode($cached, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                exit;
            }
        }
    }

    $payload = trafficListenerBuild($device);
    trafficListenerRecordSample($pdo, $payload);
    $payload['history'] = trafficListenerHistory($pdo, $deviceKey, $historyHours);
    $payload['usage_daily'] = trafficListenerDailyUsage($pdo, $deviceKey, trim((string)($_GET['day'] ?? '')) ?: null, is_array($payload['router_clock'] ?? null) ? $payload['router_clock'] : null, $cycleStartDay, trim((string)($_GET['usage_mode'] ?? 'day')));
    $encoded = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($encoded === false) {
        throw new RuntimeException('Encodage du rapport impossible.');
    }
    file_put_contents($cacheFile, $encoded, LOCK_EX);
    flock($lock, LOCK_UN);
    fclose($lock);
    echo $encoded;
} catch (Throwable $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
        'captured_at' => date(DATE_ATOM),
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}
}
