<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/device_manager.php';
require_once __DIR__ . '/../includes/mikrotik_backend.php';

session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

function portalDiagnosticReply(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function portalDiagnosticRows(mixed $rows): array
{
    return is_array($rows) && !isset($rows['!trap']) ? array_values(array_filter($rows, 'is_array')) : [];
}

function portalDiagnosticIpInNetwork(string $ip, string $network): bool
{
    $parts = explode('/', trim($network), 2);
    if (count($parts) !== 2 || !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) return false;
    $base = trim($parts[0]);
    $prefix = filter_var($parts[1], FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 32]]);
    if (!filter_var($base, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) || $prefix === false) return false;
    $ipLong = ip2long($ip);
    $baseLong = ip2long($base);
    if ($ipLong === false || $baseLong === false) return false;
    $mask = $prefix === 0 ? 0 : ((0xFFFFFFFF << (32 - $prefix)) & 0xFFFFFFFF);
    return (($ipLong & $mask) === ($baseLong & $mask));
}

function portalDiagnosticRelevantNat(array $rows): array
{
    $result = [];
    foreach ($rows as $index => $row) {
        $chain = strtolower(trim((string)($row['chain'] ?? '')));
        $action = strtolower(trim((string)($row['action'] ?? '')));
        $comment = strtolower(trim((string)($row['comment'] ?? '')));
        if ($chain !== 'dstnat' && !str_starts_with($chain, 'hotspot')) continue;
        if (!in_array($action, ['redirect', 'dst-nat', 'jump'], true) && !str_contains($comment, 'hotspot')) continue;
        $result[] = [
            'order' => $index + 1,
            'chain' => $chain,
            'action' => $action,
            'protocol' => (string)($row['protocol'] ?? ''),
            'dst_port' => (string)($row['dst-port'] ?? ''),
            'to_ports' => (string)($row['to-ports'] ?? ''),
            'jump_target' => (string)($row['jump-target'] ?? ''),
            'in_interface' => (string)($row['in-interface'] ?? ''),
            'disabled' => mikrotikApiBoolean($row['disabled'] ?? false),
            'dynamic' => mikrotikApiBoolean($row['dynamic'] ?? false),
            'comment' => (string)($row['comment'] ?? ''),
        ];
    }
    return array_slice($result, 0, 80);
}

function portalDiagnosticRelevantFilter(array $rows): array
{
    $result = [];
    foreach ($rows as $index => $row) {
        $chain = strtolower(trim((string)($row['chain'] ?? '')));
        $comment = strtolower(trim((string)($row['comment'] ?? '')));
        if (!str_starts_with($chain, 'hotspot') && !str_contains($comment, 'hotspot')) continue;
        $result[] = [
            'order' => $index + 1,
            'chain' => $chain,
            'action' => (string)($row['action'] ?? ''),
            'protocol' => (string)($row['protocol'] ?? ''),
            'dst_port' => (string)($row['dst-port'] ?? ''),
            'in_interface' => (string)($row['in-interface'] ?? ''),
            'disabled' => mikrotikApiBoolean($row['disabled'] ?? false),
            'dynamic' => mikrotikApiBoolean($row['dynamic'] ?? false),
            'comment' => (string)($row['comment'] ?? ''),
        ];
    }
    return array_slice($result, 0, 80);
}

function portalDiagnosticAddress(string $value): string
{
    return trim(explode('/', trim($value), 2)[0]);
}

function portalDiagnosticMatchesClient(array $row, string $ip, string $mac, bool $includeToAddress = false): bool
{
    $rowIp = portalDiagnosticAddress((string)($row['address'] ?? ''));
    $rowMac = strtoupper(trim((string)($row['mac-address'] ?? '')));
    if ($rowIp === $ip || ($includeToAddress && portalDiagnosticAddress((string)($row['to-address'] ?? '')) === $ip)) return true;
    return $mac !== '' && $rowMac === $mac;
}

function portalDiagnosticListValues(array $rows, string $field): string
{
    $values = [];
    foreach ($rows as $row) {
        if (!array_key_exists($field, $row) || $row[$field] === '' || $row[$field] === null) continue;
        $value = $row[$field];
        if (is_bool($value)) $value = $value ? 'oui' : 'non';
        $values[(string)$value] = true;
    }
    return implode(' · ', array_keys($values));
}

function portalDiagnosticBuildRecord(
    array $record,
    array $leases,
    array $arpRows,
    array $bridgeHosts,
    array $hotspotHosts,
    array $activeSessions,
    array $dhcpNetworks
): array {
    $ip = (string)$record['ip'];
    $mac = (string)$record['mac'];
    $matchingLeases = array_values(array_filter($leases, static fn(array $row): bool => portalDiagnosticMatchesClient($row, $ip, $mac)));
    $matchingArp = array_values(array_filter($arpRows, static fn(array $row): bool => portalDiagnosticMatchesClient($row, $ip, $mac)));
    $matchingBridgeHosts = array_values(array_filter($bridgeHosts, static fn(array $row): bool => strtoupper(trim((string)($row['mac-address'] ?? ''))) === $mac && $mac !== ''));
    $matchingHotspotHosts = array_values(array_filter($hotspotHosts, static fn(array $row): bool => portalDiagnosticMatchesClient($row, $ip, $mac, true)));
    $matchingActiveSessions = array_values(array_filter($activeSessions, static fn(array $row): bool => portalDiagnosticMatchesClient($row, $ip, $mac)));
    $matchingNetworks = array_values(array_filter($dhcpNetworks, static fn(array $row): bool => portalDiagnosticIpInNetwork($ip, (string)($row['address'] ?? ''))));

    return [
        'ip' => $ip,
        'mac' => $mac,
        'lease_count' => count($matchingLeases),
        'lease_state' => portalDiagnosticListValues($matchingLeases, 'status'),
        'lease_servers' => portalDiagnosticListValues($matchingLeases, 'server'),
        'arp_count' => count($matchingArp),
        'arp_macs' => portalDiagnosticListValues($matchingArp, 'mac-address'),
        'arp_interfaces' => portalDiagnosticListValues($matchingArp, 'interface'),
        'arp_complete' => portalDiagnosticListValues($matchingArp, 'complete'),
        'bridge_ports' => portalDiagnosticListValues($matchingBridgeHosts, 'on-interface'),
        'hotspot_host_count' => count($matchingHotspotHosts),
        'hotspot_host_state' => portalDiagnosticListValues($matchingHotspotHosts, 'authorized'),
        'hotspot_host_servers' => portalDiagnosticListValues($matchingHotspotHosts, 'server'),
        'hotspot_active' => portalDiagnosticListValues($matchingActiveSessions, 'user'),
        'active_count' => count($matchingActiveSessions),
        'dhcp_networks' => portalDiagnosticListValues($matchingNetworks, 'address'),
        'gateways' => portalDiagnosticListValues($matchingNetworks, 'gateway'),
        'dhcp_dns' => portalDiagnosticListValues($matchingNetworks, 'dns-server'),
    ];
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') portalDiagnosticReply(['success' => false, 'message' => 'Méthode non autorisée.'], 405);
    if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) portalDiagnosticReply(['success' => false, 'message' => 'Unauthorized'], 403);
    if (!isAdministrator()) portalDiagnosticReply(['success' => false, 'message' => 'Accès réservé à l administrateur.'], 403);

    $payload = json_decode((string)file_get_contents('php://input'), true);
    if (!is_array($payload)) throw new InvalidArgumentException('Payload JSON invalide.');
    $csrfToken = trim((string)($payload['csrf_token'] ?? ''));
    if ($csrfToken === '' || !hash_equals((string)($_SESSION['csrf_token'] ?? ''), $csrfToken)) {
        portalDiagnosticReply(['success' => false, 'message' => 'CSRF invalide.'], 403);
    }

    $requestedRecords = $payload['records'] ?? null;
    if (!is_array($requestedRecords) || $requestedRecords === [] || count($requestedRecords) > 30) {
        throw new InvalidArgumentException('Sélectionnez entre 1 et 30 baux à diagnostiquer.');
    }
    $recordsByKey = [];
    foreach ($requestedRecords as $requestedRecord) {
        if (!is_array($requestedRecord)) continue;
        $ip = trim((string)($requestedRecord['ip'] ?? ''));
        $mac = strtoupper(trim((string)($requestedRecord['mac'] ?? '')));
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) continue;
        if ($mac !== '' && !preg_match('/^[0-9A-F]{2}(?::[0-9A-F]{2}){5}$/', $mac)) continue;
        $recordsByKey[$ip . '|' . $mac] = ['ip' => $ip, 'mac' => $mac];
    }
    if ($recordsByKey === []) throw new InvalidArgumentException('Aucun bail avec une adresse IPv4 exploitable.');
    $records = array_values($recordsByKey);

    $device = requireActiveDevice();
    if (normalizeDeviceType((string)($device['type'] ?? '')) !== 'mikrotik') {
        portalDiagnosticReply(['success' => false, 'message' => 'Diagnostic disponible seulement pour MikroTik.'], 422);
    }

    $api = connectToMikrotikApiByDevice($device);
    try {
        $leases = portalDiagnosticRows($api->comm('/ip/dhcp-server/lease/print', [
            '.proplist' => '.id,address,mac-address,host-name,server,status,dynamic,disabled,expires-after,comment',
        ]));
        $arp = portalDiagnosticRows($api->comm('/ip/arp/print', [
            '.proplist' => '.id,address,mac-address,interface,dynamic,disabled,complete,comment',
        ]));
        $bridgeHosts = portalDiagnosticRows($api->comm('/interface/bridge/host/print', [
            '.proplist' => 'mac-address,on-interface,bridge,local,external,age',
        ]));
        $hotspotHosts = portalDiagnosticRows($api->comm('/ip/hotspot/host/print', [
            '.proplist' => '.id,address,to-address,mac-address,server,authorized,bypassed,dynamic,dhcp,comment',
        ]));
        $activeSessions = portalDiagnosticRows($api->comm('/ip/hotspot/active/print', [
            '.proplist' => '.id,address,mac-address,user,server,login-by,uptime,session-time-left',
        ]));
        $dhcpNetworks = portalDiagnosticRows($api->comm('/ip/dhcp-server/network/print', [
            '.proplist' => '.id,address,gateway,dns-server,domain,ntp-server,comment',
        ]));
        $hotspotServers = portalDiagnosticRows($api->comm('/ip/hotspot/print', [
            '.proplist' => '.id,name,interface,profile,address-pool,addresses-per-mac,disabled,invalid',
        ]));
        $hotspotProfiles = portalDiagnosticRows($api->comm('/ip/hotspot/profile/print', [
            '.proplist' => '.id,name,hotspot-address,dns-name,html-directory,login-by,use-radius,http-cookie-lifetime',
        ]));
        $dnsRows = portalDiagnosticRows($api->comm('/ip/dns/print', [
            '.proplist' => 'servers,dynamic-servers,allow-remote-requests,use-doh-server',
        ]));
        $natRows = portalDiagnosticRows($api->comm('/ip/firewall/nat/print', [
            '.proplist' => '.id,chain,action,protocol,dst-port,to-ports,jump-target,in-interface,disabled,dynamic,comment',
        ]));
        $filterRows = portalDiagnosticRows($api->comm('/ip/firewall/filter/print', [
            '.proplist' => '.id,chain,action,protocol,dst-port,in-interface,disabled,dynamic,comment',
        ]));
    } finally {
        $api->disconnect();
    }

    $diagnostics = array_map(static fn(array $record): array => portalDiagnosticBuildRecord(
        $record,
        $leases,
        $arp,
        $bridgeHosts,
        $hotspotHosts,
        $activeSessions,
        $dhcpNetworks
    ), $records);

    portalDiagnosticReply([
        'success' => true,
        'device' => [
            'id' => (string)($device['id'] ?? ''),
            'name' => (string)($device['name'] ?? ''),
            'host' => (string)($device['host'] ?? ($device['ip'] ?? '')),
        ],
        'checked_at' => date('Y-m-d H:i:s'),
        'diagnostics' => $diagnostics,
        'configuration' => [
            'hotspot_servers' => $hotspotServers,
            'hotspot_profiles' => $hotspotProfiles,
            'dns' => $dnsRows[0] ?? [],
            'nat_rules' => portalDiagnosticRelevantNat($natRows),
            'filter_rules' => portalDiagnosticRelevantFilter($filterRows),
        ],
        'notice' => 'Instantané RouterOS en lecture seule. Il décrit l’état observé au moment du contrôle et ne prouve pas ce que le navigateur client a affiché.',
    ]);
} catch (Throwable $e) {
    portalDiagnosticReply(['success' => false, 'message' => trim((string)$e->getMessage()) ?: 'Diagnostic portail impossible.'], $e instanceof InvalidArgumentException ? 422 : 500);
}