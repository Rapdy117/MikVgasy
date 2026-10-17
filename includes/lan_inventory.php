<?php
/**
 * Inventaire LAN MikroTik par instantané.
 *
 * - La lecture RouterOS n'a lieu que sur demande explicite (bouton « Mettre à jour »).
 * - L'instantané brut est stocké en SQL par device_id ; la page reconstruit une liste plate depuis SQL.
 * - Pas de notion de parent/rattachement : chaque ligne est indépendante, triée capteur/CPE → routeur
 *   → client. Les incohérences RouterOS détectées (ARP/Hotspot/DHCP, MAC partagée) restent de simples
 *   anomalies corrigeables ligne par ligne via `api/dhcp_conformity_action.php`.
 */

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/mikrotik_backend.php';

const LAN_INVENTORY_KEEP_SNAPSHOTS = 10;

function lanInventoryEnsureTables(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS lan_inventory_snapshots (
        id INT AUTO_INCREMENT PRIMARY KEY,
        device_id VARCHAR(128) NOT NULL,
        captured_at DATETIME NOT NULL,
        captured_by VARCHAR(190) NOT NULL DEFAULT '',
        payload_json LONGTEXT NOT NULL,
        KEY idx_device_captured (device_id, id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function lanInventoryIp(string $value): string
{
    $ip = trim(explode('/', trim($value), 2)[0]);
    return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) ? $ip : '';
}

function lanInventoryMac(string $value): string
{
    $mac = strtoupper(trim($value));
    return preg_match('/^[0-9A-F]{2}(?::[0-9A-F]{2}){5}$/', $mac) ? $mac : '';
}

function lanInventoryIpToLong(string $ip): ?int
{
    $long = ip2long($ip);
    return $long === false ? null : (int)sprintf('%u', $long);
}

function lanInventoryIpInCidr(string $ip, string $cidr): bool
{
    $parts = explode('/', $cidr, 2);
    if (count($parts) !== 2) return false;
    $base = lanInventoryIp($parts[0]);
    $prefix = filter_var($parts[1], FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 32]]);
    if ($base === '' || $prefix === false) return false;
    $ipLong = lanInventoryIpToLong($ip);
    $baseLong = lanInventoryIpToLong($base);
    if ($ipLong === null || $baseLong === null) return false;
    $mask = $prefix === 0 ? 0 : ((0xFFFFFFFF << (32 - $prefix)) & 0xFFFFFFFF);
    return ($ipLong & $mask) === ($baseLong & $mask);
}

function lanInventoryIpInLanNetworks(string $ip, array $lanNetworks): bool
{
    if (lanInventoryIp($ip) === '') return false;
    foreach ($lanNetworks as $cidr) {
        if (lanInventoryIpInCidr($ip, $cidr)) return true;
    }
    return false;
}

function lanInventoryInterfaceInScope(string $value, array $interfaceSet): bool
{
    foreach (preg_split('/\s*,\s*/', trim($value)) ?: [] as $interface) {
        if ($interface !== '' && isset($interfaceSet[$interface])) return true;
    }
    return false;
}

function lanInventoryOutsideDhcpPools(string $ip, array $servers, array $pools): bool
{
    $value = lanInventoryIpToLong($ip);
    if ($value === null || $servers === []) return false;
    $byName = [];
    foreach ($pools as $pool) $byName[(string)($pool['name'] ?? '')] = $pool;
    $pending = [];
    foreach ($servers as $server) {
        if (mikrotikApiBoolean($server['disabled'] ?? false) || mikrotikApiBoolean($server['invalid'] ?? false)) continue;
        $name = trim((string)($server['address-pool'] ?? ''));
        if ($name === '') return false;
        if ($name !== 'static-only') $pending[] = $name;
    }
    $seen = [];
    while ($pending !== []) {
        $name = array_pop($pending);
        if (isset($seen[$name])) continue;
        $seen[$name] = true;
        if (!isset($byName[$name])) return false;
        foreach (explode(',', (string)($byName[$name]['ranges'] ?? '')) as $range) {
            $bounds = explode('-', trim($range), 2);
            $start = lanInventoryIpToLong(trim($bounds[0]));
            $end = lanInventoryIpToLong(trim($bounds[1] ?? $bounds[0]));
            if ($start === null || $end === null || $end < $start) return false;
            if ($value >= $start && $value <= $end) return false;
        }
        $next = trim((string)($byName[$name]['next-pool'] ?? ''));
        if ($next !== '' && $next !== 'none') $pending[] = $next;
    }
    return true;
}

function lanInventoryRouterRows(mixed $rows): array
{
    mikrotikAssertNoTrap($rows, 'Lecture de l’inventaire RouterOS refusée.');
    if (!is_array($rows)) throw new RuntimeException('Réponse inventaire invalide.');
    return array_values(array_filter($rows, 'is_array'));
}

function lanInventoryLanScope(array $dhcpServerRows, array $hotspotRows, array $bridgePortRows): array
{
    $dhcpServers = [];
    $hotspotServers = [];
    $interfaces = [];
    $bridgePorts = [];
    $portBridges = [];

    foreach ($bridgePortRows as $row) {
        if (mikrotikApiBoolean($row['disabled'] ?? false)) continue;
        $bridge = trim((string)($row['bridge'] ?? ''));
        $port = trim((string)($row['interface'] ?? ''));
        if ($bridge === '' || $port === '') continue;
        $bridgePorts[$bridge][$port] = true;
        $portBridges[$port] = $bridge;
    }
    $collect = static function (array $serverRows) use (&$interfaces): array {
        $names = [];
        foreach ($serverRows as $row) {
            if (mikrotikApiBoolean($row['disabled'] ?? false) || mikrotikApiBoolean($row['invalid'] ?? false)) continue;
            $name = trim((string)($row['name'] ?? ''));
            $interface = trim((string)($row['interface'] ?? ''));
            if ($name !== '') $names[$name] = true;
            if ($interface !== '') $interfaces[$interface] = true;
        }
        return $names;
    };
    $dhcpServers = $collect($dhcpServerRows);
    $hotspotServers = $collect($hotspotRows);
    foreach (array_keys($interfaces) as $interface) {
        if (isset($bridgePorts[$interface])) {
            foreach (array_keys($bridgePorts[$interface]) as $port) $interfaces[$port] = true;
        }
        if (isset($portBridges[$interface])) $interfaces[$portBridges[$interface]] = true;
    }

    return ['dhcp_servers' => $dhcpServers, 'hotspot_servers' => $hotspotServers, 'interfaces' => $interfaces];
}

/** Sonde TCP/80 courte, exécutée uniquement pendant la mise à jour de l'instantané. */
function lanInventoryManagementPorts(array $ips): array
{
    $open = [];
    foreach (array_chunk(array_values(array_unique($ips)), 32) as $batch) {
        $pending = [];
        foreach ($batch as $ip) {
            if (lanInventoryIp($ip) !== $ip) continue;
            $socket = @stream_socket_client('tcp://' . $ip . ':80', $errno, $error, 0,
                STREAM_CLIENT_CONNECT | STREAM_CLIENT_ASYNC_CONNECT);
            if ($socket !== false) $pending[(int)$socket] = [$socket, $ip];
        }
        $deadline = microtime(true) + 0.8;
        while ($pending !== [] && microtime(true) < $deadline) {
            $write = array_column($pending, 0);
            $read = null;
            $except = $write;
            $remaining = max(1, (int)(($deadline - microtime(true)) * 1000000));
            if (@stream_select($read, $write, $except, 0, $remaining) === false) break;
            foreach (array_merge($write, $except) as $socket) {
                $key = (int)$socket;
                if (!isset($pending[$key])) continue;
                if (@stream_socket_get_name($socket, true) !== false) $open[$pending[$key][1]] = true;
                unset($pending[$key]);
                fclose($socket);
            }
        }
        foreach ($pending as [$socket]) fclose($socket);
    }
    return $open;
}

/** Lecture RouterOS en lecture seule du routeur exact (device_id). */
function lanInventoryCollect(array $device): array
{
    $api = connectToMikrotikApiByDevice($device);
    try {
        $tables = [
            'dhcp_servers' => lanInventoryRouterRows($api->comm('/ip/dhcp-server/print', ['.proplist' => 'name,interface,disabled,invalid,address-pool'])),
            'hotspot_servers' => lanInventoryRouterRows($api->comm('/ip/hotspot/print', ['.proplist' => 'name,interface,disabled,invalid'])),
            'bridge_ports' => lanInventoryRouterRows($api->comm('/interface/bridge/port/print', ['.proplist' => 'interface,bridge,disabled'])),
            'addresses' => lanInventoryRouterRows($api->comm('/ip/address/print', ['.proplist' => 'address,interface,disabled,invalid'])),
            'pools' => lanInventoryRouterRows($api->comm('/ip/pool/print', ['.proplist' => 'name,ranges,next-pool'])),
            'dns_static' => lanInventoryRouterRows($api->comm('/ip/dns/static/print', ['.proplist' => 'name,address,disabled'])),
            'neighbors' => lanInventoryRouterRows($api->comm('/ip/neighbor/print', ['.proplist' => 'address,mac-address,interface,identity,platform,version,board'])),
            'leases' => lanInventoryRouterRows($api->comm('/ip/dhcp-server/lease/print', ['.proplist' => '.id,address,mac-address,host-name,server,status,dynamic,disabled,blocked,comment,expires-after,last-seen'])),
            'arp' => lanInventoryRouterRows($api->comm('/ip/arp/print', ['.proplist' => '.id,address,mac-address,interface,dynamic,disabled,complete,status'])),
            'hotspot_hosts' => lanInventoryRouterRows($api->comm('/ip/hotspot/host/print', ['.proplist' => '.id,address,to-address,mac-address,server,authorized,bypassed,dynamic'])),
            'hotspot_active' => lanInventoryRouterRows($api->comm('/ip/hotspot/active/print', ['.proplist' => 'address,mac-address,user,server'])),
            'bridge_hosts' => lanInventoryRouterRows($api->comm('/interface/bridge/host/print', ['.proplist' => 'mac-address,on-interface,bridge,local'])),
        ];
    } finally {
        $api->disconnect();
    }

    $scope = lanInventoryLanScope($tables['dhcp_servers'], $tables['hotspot_servers'], $tables['bridge_ports']);
    $lanNetworks = lanInventoryLanNetworks($tables['addresses'], $scope['interfaces']);
    $probeIps = [];
    foreach (array_merge($tables['leases'], $tables['arp']) as $row) {
        $ip = lanInventoryIp((string)($row['address'] ?? ''));
        if ($ip !== '' && lanInventoryIpInLanNetworks($ip, $lanNetworks)) $probeIps[] = $ip;
    }

    return [
        'captured_at' => date('Y-m-d H:i:s'),
        'device' => ['id' => (string)($device['id'] ?? ''), 'name' => (string)($device['name'] ?? ''), 'host' => (string)($device['host'] ?? '')],
        'tables' => $tables,
        'management_http' => array_keys(lanInventoryManagementPorts($probeIps)),
    ];
}

function lanInventoryLanNetworks(array $addressRows, array $lanInterfaces): array
{
    $networks = [];
    foreach ($addressRows as $row) {
        if (mikrotikApiBoolean($row['disabled'] ?? false) || mikrotikApiBoolean($row['invalid'] ?? false)) continue;
        $interface = trim((string)($row['interface'] ?? ''));
        $address = trim((string)($row['address'] ?? ''));
        if ($interface !== '' && $address !== '' && lanInventoryInterfaceInScope($interface, $lanInterfaces)) $networks[] = $address;
    }
    return array_values(array_unique($networks));
}

function lanInventorySaveSnapshot(PDO $pdo, string $deviceId, string $username, array $snapshot): void
{
    lanInventoryEnsureTables($pdo);
    $json = json_encode($snapshot, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json === false) throw new RuntimeException('Instantané inventaire non sérialisable.');
    $stmt = $pdo->prepare('INSERT INTO lan_inventory_snapshots (device_id, captured_at, captured_by, payload_json) VALUES (?, ?, ?, ?)');
    $stmt->execute([$deviceId, $snapshot['captured_at'], $username, $json]);
    $keep = $pdo->prepare('SELECT id FROM lan_inventory_snapshots WHERE device_id = ? ORDER BY id DESC LIMIT ' . LAN_INVENTORY_KEEP_SNAPSHOTS);
    $keep->execute([$deviceId]);
    $ids = array_map('intval', $keep->fetchAll(PDO::FETCH_COLUMN));
    if ($ids !== []) {
        $prune = $pdo->prepare('DELETE FROM lan_inventory_snapshots WHERE device_id = ? AND id < ?');
        $prune->execute([$deviceId, min($ids)]);
    }
}

function lanInventoryLatestSnapshot(PDO $pdo, string $deviceId): ?array
{
    lanInventoryEnsureTables($pdo);
    $stmt = $pdo->prepare('SELECT captured_by, payload_json FROM lan_inventory_snapshots WHERE device_id = ? ORDER BY id DESC LIMIT 1');
    $stmt->execute([$deviceId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) return null;
    $snapshot = json_decode((string)$row['payload_json'], true);
    if (!is_array($snapshot)) return null;
    $snapshot['captured_by'] = (string)$row['captured_by'];
    return $snapshot;
}

function lanInventoryDeviceKey(array $neighbor, string $mac): string
{
    $identity = trim((string)($neighbor['identity'] ?? ''));
    if (strtolower(trim((string)($neighbor['platform'] ?? ''))) === 'mikrotik' && $identity !== '') return 'mikrotik:' . strtolower($identity);
    return 'mac:' . $mac;
}

/** Correspondance DHCP lease / ARP / Hotspot host par IP (ancien « Contrôle global »). */
function lanInventoryConformity(array $leaseRows, array $arpRows, array $hostRows): array
{
    $byIp = [];
    $macUseCount = [];
    foreach ($leaseRows as $row) {
        if (mikrotikApiBoolean($row['disabled'] ?? false)) continue;
        $ip = lanInventoryIp((string)($row['address'] ?? ''));
        if ($ip !== '') $byIp[$ip]['dhcp'][] = ['mac' => lanInventoryMac((string)($row['mac-address'] ?? '')), 'row' => $row];
    }
    foreach ($arpRows as $row) {
        if (mikrotikApiBoolean($row['disabled'] ?? false)) continue;
        $ip = lanInventoryIp((string)($row['address'] ?? ''));
        $mac = lanInventoryMac((string)($row['mac-address'] ?? ''));
        if ($ip === '' || $mac === '') continue;
        $byIp[$ip]['arp'][] = ['mac' => $mac, 'row' => $row];
        $macUseCount[$mac] = ($macUseCount[$mac] ?? 0) + 1;
    }
    foreach ($hostRows as $row) {
        $mac = lanInventoryMac((string)($row['mac-address'] ?? ''));
        foreach (['address', 'to-address'] as $field) {
            $ip = lanInventoryIp((string)($row[$field] ?? ''));
            if ($ip !== '') $byIp[$ip]['hotspot_host'][] = ['mac' => $mac, 'row' => $row];
        }
        if ($mac !== '') $macUseCount[$mac] = ($macUseCount[$mac] ?? 0) + 1;
    }

    $result = [];
    foreach ($byIp as $ip => $cells) {
        $first = static fn(string $scope): array => $cells[$scope][0] ?? ['mac' => '', 'row' => []];
        $referenceMac = $first('dhcp')['mac'] ?: ($first('hotspot_host')['mac'] ?: $first('arp')['mac']);
        $reasons = [];
        $actions = [];
        foreach (['dhcp' => 'DHCP', 'arp' => 'ARP', 'hotspot_host' => 'HOTSPOT_HOST'] as $scope => $label) {
            $macs = array_unique(array_filter(array_column($cells[$scope] ?? [], 'mac')));
            if (count($macs) > 1) $reasons[] = $label . ' voit plusieurs MAC pour la même IP';
        }
        // Une IP vue avec plusieurs MAC différentes est toujours un conflit réel (deux équipements se
        // disputent la même IP) : contrairement à un pont où UNE MAC porte PLUSIEURS IP (normal, pas une
        // anomalie), ce sens-ci (UNE IP, PLUSIEURS MAC) reste signalé même si l'une des MAC est aussi un
        // porteur de pont connu ailleurs — seule la proposition de correction automatique tient compte
        // de cette popularité pour ne pas suggérer de supprimer une entrée de pont légitime.
        $macsByScope = [];
        foreach (['dhcp' => 'DHCP', 'arp' => 'ARP', 'hotspot_host' => 'HOTSPOT'] as $scope => $label) {
            foreach (array_unique(array_filter(array_column($cells[$scope] ?? [], 'mac'))) as $mac) {
                $macsByScope[$mac][] = $label;
            }
        }
        if (count($macsByScope) > 1) {
            $detail = [];
            foreach ($macsByScope as $mac => $sources) $detail[] = $mac . ' (' . implode('+', $sources) . ')';
            $reasons[] = 'IP associée à ' . count($macsByScope) . ' MAC différentes : ' . implode(' / ', $detail);
        }
        foreach (['arp' => 'ARP', 'hotspot_host' => 'HOTSPOT_HOST'] as $scope => $label) {
            $cell = $first($scope);
            if ($cell['row'] === [] || $cell['mac'] === '' || $referenceMac === '' || $cell['mac'] === $referenceMac) continue;
            if (($macUseCount[$cell['mac']] ?? 0) >= 3) continue; // porteur de pont connu : pas de correction auto
            $id = (string)($cell['row']['.id'] ?? '');
            if ($scope === 'arp' && mikrotikApiBoolean($cell['row']['dynamic'] ?? false)) {
                $actions[] = ['scope' => 'arp', 'id' => $id, 'ip' => $ip, 'mac' => $cell['mac'], 'label' => 'Supprimer ARP'];
            }
            if ($scope === 'hotspot_host') {
                $actions[] = ['scope' => 'hotspot_host', 'id' => $id, 'ip' => $ip, 'mac' => $cell['mac'], 'label' => 'Supprimer Host'];
            }
        }
        $arpAction = current(array_filter($actions, static fn(array $a): bool => $a['scope'] === 'arp')) ?: null;
        $hostAction = current(array_filter($actions, static fn(array $a): bool => $a['scope'] === 'hotspot_host')) ?: null;
        if ($arpAction !== null && $hostAction !== null) {
            $actions = [[
                'scope' => 'ip_cleanup', 'id' => $ip, 'ip' => $ip, 'mac' => $arpAction['mac'],
                'arp_id' => $arpAction['id'], 'arp_mac' => $arpAction['mac'],
                'hotspot_host_id' => $hostAction['id'], 'hotspot_host_mac' => $hostAction['mac'],
                'label' => 'Corriger IP',
            ]];
        }
        if ($reasons !== []) $result[$ip] = ['reasons' => $reasons, 'actions' => array_slice($actions, 0, 1)];
    }
    return $result;
}

/** Construit la vue du tableau principal depuis un instantané SQL (aucun appel RouterOS). */
function lanInventoryBuild(array $snapshot): array
{
    $t = $snapshot['tables'] ?? [];
    $scope = lanInventoryLanScope($t['dhcp_servers'] ?? [], $t['hotspot_servers'] ?? [], $t['bridge_ports'] ?? []);
    $lanInterfaces = $scope['interfaces'];
    $lanNetworks = lanInventoryLanNetworks($t['addresses'] ?? [], $lanInterfaces);
    $inServer = static fn(array $row, array $set): bool => isset($set[trim((string)($row['server'] ?? ''))]);

    $leases = array_values(array_filter($t['leases'] ?? [], static fn(array $r): bool => $inServer($r, $scope['dhcp_servers'])));
    $arps = array_values(array_filter($t['arp'] ?? [], static fn(array $r): bool => lanInventoryInterfaceInScope((string)($r['interface'] ?? ''), $lanInterfaces)));
    $hosts = array_values(array_filter($t['hotspot_hosts'] ?? [], static fn(array $r): bool => $inServer($r, $scope['hotspot_servers'])));
    $active = array_values(array_filter($t['hotspot_active'] ?? [], static fn(array $r): bool => $inServer($r, $scope['hotspot_servers'])));
    $neighbors = array_values(array_filter($t['neighbors'] ?? [], static fn(array $r): bool => lanInventoryInterfaceInScope((string)($r['interface'] ?? ''), $lanInterfaces)));
    $httpOpen = array_fill_keys($snapshot['management_http'] ?? [], true);

    $conformity = lanInventoryConformity($leases, $arps, $hosts);

    $bridgePortByMac = [];
    foreach ($t['bridge_hosts'] ?? [] as $row) {
        if (mikrotikApiBoolean($row['local'] ?? false)) continue;
        $mac = lanInventoryMac((string)($row['mac-address'] ?? ''));
        $port = trim((string)($row['on-interface'] ?? ''));
        if ($mac !== '' && $port !== '' && lanInventoryInterfaceInScope($port, $lanInterfaces)) $bridgePortByMac[$mac] = $port;
    }
    $dnsByIp = [];
    foreach ($t['dns_static'] ?? [] as $row) {
        if (mikrotikApiBoolean($row['disabled'] ?? false)) continue;
        $ip = lanInventoryIp((string)($row['address'] ?? ''));
        if ($ip !== '' && trim((string)($row['name'] ?? '')) !== '') $dnsByIp[$ip][] = trim((string)$row['name']);
    }

    // Équipements d'infrastructure : voisins RouterOS (identité, plateforme), repérés par IP.
    $devices = [];
    $deviceByIp = [];
    foreach ($neighbors as $row) {
        $mac = lanInventoryMac((string)($row['mac-address'] ?? ''));
        if ($mac === '') continue;
        $key = lanInventoryDeviceKey($row, $mac);
        $ip = lanInventoryIp((string)($row['address'] ?? ''));
        $devices[$key] ??= [
            'key' => $key,
            'identity' => trim((string)($row['identity'] ?? '')),
            'platform' => trim((string)($row['platform'] ?? '')),
            'board' => trim((string)($row['board'] ?? '')),
            'version' => trim((string)($row['version'] ?? '')),
            'ips' => [],
            'macs' => [],
        ];
        $devices[$key]['macs'][$mac] = true;
        if ($ip !== '') {
            $devices[$key]['ips'][$ip] = true;
            if (lanInventoryIpInLanNetworks($ip, $lanNetworks)) $deviceByIp[$ip] = $key;
        }
        foreach (['board', 'version'] as $field) {
            if ($devices[$key][$field] === '') $devices[$key][$field] = trim((string)($row[$field] ?? ''));
        }
    }
    foreach ($devices as $key => $device) {
        $ips = array_keys($device['ips']);
        usort($ips, static fn(string $a, string $b): int => (int)lanInventoryIpInLanNetworks($b, $lanNetworks) <=> (int)lanInventoryIpInLanNetworks($a, $lanNetworks) ?: strnatcmp($a, $b));
        $devices[$key]['ips'] = $ips;
        $devices[$key]['macs'] = array_keys($device['macs']);
        $devices[$key]['label'] = ($device['identity'] !== '' ? $device['identity'] : $key) . ($ips !== [] ? ' (' . $ips[0] . ')' : '');
    }

    // Preuve de pont : une même MAC portée par plusieurs IP (traduction MAC d'un CPE ou d'une station).
    $ipsByMac = [];
    foreach ($hosts as $row) {
        $mac = lanInventoryMac((string)($row['mac-address'] ?? ''));
        $ip = lanInventoryIp((string)($row['address'] ?? ''));
        if ($mac !== '' && $ip !== '') $ipsByMac[$mac][$ip] = true;
    }
    foreach ($arps as $row) {
        $mac = lanInventoryMac((string)($row['mac-address'] ?? ''));
        $ip = lanInventoryIp((string)($row['address'] ?? ''));
        if ($mac !== '' && $ip !== '' && !mikrotikApiBoolean($row['disabled'] ?? false)) $ipsByMac[$mac][$ip] = true;
    }
    $bridges = [];
    foreach ($ipsByMac as $mac => $ipSet) {
        if (count($ipSet) < 2) continue;
        $members = [];
        foreach (array_keys($ipSet) as $ip) {
            if (isset($deviceByIp[$ip])) $members[$deviceByIp[$ip]] = true;
        }
        $memberKeys = array_keys($members);
        usort($memberKeys, static fn(string $a, string $b): int =>
            (int)(strtolower($devices[$a]['platform']) === 'mikrotik') <=> (int)(strtolower($devices[$b]['platform']) === 'mikrotik')
            ?: strnatcmp($devices[$a]['ips'][0] ?? '', $devices[$b]['ips'][0] ?? ''));
        $bridges[$mac] = ['mac' => $mac, 'ip_count' => count($ipSet), 'devices' => $memberKeys];
    }

    // Lignes : une par IP observée (bail, ARP, Hotspot host), plus les voisins sans IP observée.
    $rows = [];
    $ensureRow = static function (string $key, string $ip) use (&$rows): void {
        $rows[$key] ??= ['key' => $key, 'ip' => $ip, 'mac' => '', 'lease' => null, 'sources' => [], 'macs' => [], 'users' => [], 'arp_status' => '', 'host_authorized' => null];
    };
    foreach ($leases as $row) {
        $ip = lanInventoryIp((string)($row['address'] ?? ''));
        if ($ip === '') continue;
        $ensureRow($ip, $ip);
        $rows[$ip]['lease'] ??= $row;
        $rows[$ip]['sources']['DHCP'] = true;
        $mac = lanInventoryMac((string)($row['mac-address'] ?? ''));
        if ($mac !== '') $rows[$ip]['macs'][$mac] = true;
    }
    foreach ($arps as $row) {
        $ip = lanInventoryIp((string)($row['address'] ?? ''));
        $mac = lanInventoryMac((string)($row['mac-address'] ?? ''));
        if ($ip === '' || $mac === '' || mikrotikApiBoolean($row['disabled'] ?? false)) continue;
        $ensureRow($ip, $ip);
        $rows[$ip]['sources']['ARP'] = true;
        $rows[$ip]['macs'][$mac] = true;
        if ($rows[$ip]['arp_status'] === '') $rows[$ip]['arp_status'] = (string)($row['status'] ?? '');
    }
    foreach ($hosts as $row) {
        $ip = lanInventoryIp((string)($row['address'] ?? ''));
        $mac = lanInventoryMac((string)($row['mac-address'] ?? ''));
        if ($ip === '') continue;
        $ensureRow($ip, $ip);
        $rows[$ip]['sources']['Hotspot'] = true;
        if ($mac !== '') $rows[$ip]['macs'][$mac] = true;
        $rows[$ip]['host_authorized'] = mikrotikApiBoolean($row['authorized'] ?? false) || mikrotikApiBoolean($row['bypassed'] ?? false);
    }
    foreach ($active as $row) {
        $ip = lanInventoryIp((string)($row['address'] ?? ''));
        $user = trim((string)($row['user'] ?? ''));
        if ($ip !== '' && $user !== '' && isset($rows[$ip])) $rows[$ip]['users'][$user] = true;
    }
    foreach ($devices as $key => $device) {
        $listed = false;
        foreach ($device['ips'] as $ip) $listed = $listed || isset($rows[$ip]);
        if ($listed) continue;
        $ip = $device['ips'][0] ?? '';
        $rowKey = $ip . '|' . $key;
        $ensureRow($rowKey, $ip);
        $rows[$rowKey]['sources']['Voisin'] = true;
        foreach ($device['macs'] as $mac) $rows[$rowKey]['macs'][$mac] = true;
        $rows[$rowKey]['device_key'] = $key;
    }

    $result = [];
    $anomalyCount = 0;
    $actionCount = 0;
    foreach ($rows as $row) {
        $ip = $row['ip'];
        $lease = $row['lease'];
        $leaseMac = $lease !== null ? lanInventoryMac((string)($lease['mac-address'] ?? '')) : '';
        $macs = array_keys($row['macs']);
        $mac = $leaseMac !== '' ? $leaseMac : ($macs[0] ?? '');
        $deviceKey = $row['device_key'] ?? ($deviceByIp[$ip] ?? '');
        $device = $deviceKey !== '' ? $devices[$deviceKey] : null;
        $inLan = $ip !== '' && lanInventoryIpInLanNetworks($ip, $lanNetworks);

        $bridge = null;
        foreach ($macs as $candidate) {
            if (isset($bridges[$candidate])) { $bridge = $bridges[$candidate]; break; }
        }
        $port = '';
        foreach ($macs as $candidate) {
            if (isset($bridgePortByMac[$candidate])) { $port = $bridgePortByMac[$candidate]; break; }
        }

        $anomalies = [];
        $actions = [];
        if ($ip !== '' && isset($conformity[$ip])) {
            $anomalies = $conformity[$ip]['reasons'];
            $actions = $conformity[$ip]['actions'];
        }
        if ($ip !== '' && !$inLan) $anomalies[] = 'IP hors réseau LAN ' . implode(', ', $lanNetworks);
        if ($ip === '') $anomalies[] = 'Aucune IP de gestion publiée';
        if ($bridge !== null && $bridge['devices'] === []) $anomalies[] = 'MAC partagée par ' . $bridge['ip_count'] . ' IP sans équipement identifié';
        if ($anomalies !== []) $anomalyCount++;
        $actionCount += count($actions);

        // Le champ board est publié par MNDP (MikroTik) ; sur un voisin non MikroTik il peut provenir
        // d'un autre équipement partageant la MAC traduite, il n'est donc pas affiché.
        $isMikrotikDevice = $device !== null && strtolower($device['platform']) === 'mikrotik';
        $role = $device !== null
            ? trim(($device['platform'] ?: 'Équipement') . ($isMikrotikDevice && $device['board'] !== '' ? ' · ' . $device['board'] : ''))
            : 'Client';
        $hostName = $lease !== null ? trim((string)($lease['host-name'] ?? '')) : '';
        if ($hostName === '' && $device !== null) $hostName = $device['identity'];
        if ($hostName === '' && $ip !== '' && isset($dnsByIp[$ip])) $hostName = implode(', ', $dnsByIp[$ip]);

        $type = '-';
        if ($lease !== null) $type = mikrotikApiBoolean($lease['dynamic'] ?? false) ? 'D' : 'S';
        elseif ($inLan && lanInventoryOutsideDhcpPools($ip, $t['dhcp_servers'] ?? [], $t['pools'] ?? [])) $type = 'S';

        if ($lease !== null) {
            $status = mikrotikApiBoolean($lease['disabled'] ?? false) ? 'Desactive'
                : (mikrotikApiBoolean($lease['blocked'] ?? false) ? 'Bloque' : ucfirst((string)($lease['status'] ?? '')));
        } else {
            $status = $row['arp_status'] !== '' ? ucfirst($row['arp_status']) : implode(' + ', array_keys($row['sources']));
        }

        $result[] = [
            'key' => $row['key'],
            'ip' => $ip,
            'mac' => $mac,
            'macs' => $macs,
            'lease_id' => $lease !== null ? (string)($lease['.id'] ?? '') : '',
            'host_name' => $hostName,
            'server' => $lease !== null ? (string)($lease['server'] ?? '') : '',
            'type' => $type,
            'comment' => $lease !== null ? (string)($lease['comment'] ?? '') : '',
            'disabled' => $lease !== null && mikrotikApiBoolean($lease['disabled'] ?? false),
            'status' => $status !== '' ? $status : '-',
            'expires_after' => $lease !== null ? (string)($lease['expires-after'] ?? '') : '',
            'last_seen' => $lease !== null ? (string)($lease['last-seen'] ?? '') : '',
            'sources' => array_keys($row['sources']),
            'users' => array_keys($row['users']),
            'role' => $role,
            'is_device' => $device !== null,
            'device_key' => $deviceKey,
            'device_version' => $device['version'] ?? '',
            'device_platform' => $device['platform'] ?? '',
            'port' => $port,
            'bridge_mac' => $bridge['mac'] ?? '',
            'http' => $ip !== '' && isset($httpOpen[$ip]),
            'anomalies' => $anomalies,
            'actions' => $actions,
            // Ordre d'affichage par défaut : capteur/CPE, puis routeur MikroTik, puis clients ; dans
            // chaque groupe, tri par IP (texte zéro-paddé pour un tri numérique correct des octets).
            'order_rank' => sprintf(
                '%d_%s',
                $device === null ? 2 : (strtolower($device['platform']) === 'mikrotik' ? 1 : 0),
                implode('.', array_map(static fn(string $octet): string => str_pad($octet, 3, '0', STR_PAD_LEFT), explode('.', $ip ?: '255.255.255.255')))
            ),
        ];
    }
    usort($result, static fn(array $a, array $b): int => strcmp($a['order_rank'], $b['order_rank']) ?: strcmp($a['key'], $b['key']));

    return [
        'summary' => [
            'captured_at' => (string)($snapshot['captured_at'] ?? ''),
            'captured_by' => (string)($snapshot['captured_by'] ?? ''),
            'rows' => count($result),
            'devices' => count($devices),
            'bridges' => count($bridges),
            'anomalies' => $anomalyCount,
            'actions' => $actionCount,
            'lan_networks' => $lanNetworks,
        ],
        'rows' => $result,
    ];
}
