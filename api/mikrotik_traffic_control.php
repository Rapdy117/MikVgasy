<?php

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/device_manager.php';
require_once __DIR__ . '/../includes/mikrotik_backend.php';
require_once __DIR__ . '/../includes/multi_wan_config.php';
require_once __DIR__ . '/../includes/multi_wan_allocator.php';
require_once __DIR__ . '/../includes/mikrotik_wan_usage_collector.php';
require_once __DIR__ . '/../includes/operation_history.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');

function trafficControlReply(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}


function trafficControlWanSlot(string $slot): string
{
    $slot = strtolower(trim($slot));
    if (!preg_match('/^wan[1-4]$/', $slot)) {
        throw new InvalidArgumentException('Slot WAN invalide.');
    }
    return $slot;
}

function trafficControlWanInterfaceName(string $value): string
{
    $name = trim($value);
    if ($name === '' || !preg_match('/^[A-Za-z0-9_.:-]+$/', $name)) {
        throw new InvalidArgumentException('Interface WAN invalide.');
    }
    return $name;
}

function trafficControlWanWeight(mixed $value): int
{
    $weight = filter_var($value, FILTER_VALIDATE_INT);
    if ($weight === false || $weight < 0 || $weight > 100) {
        throw new InvalidArgumentException('Poids WAN invalide.');
    }
    return (int)$weight;
}

function trafficControlWanLabel(mixed $value): string
{
    $label = trim((string)$value);
    if ($label === '' || mb_strlen($label) > 60) {
        throw new InvalidArgumentException('Titre WAN invalide.');
    }
    return $label;
}

function trafficControlInterfaceRowByName(RouterosAPI $api, string $interface): array
{
    $rows = trafficControlRows($api, '/interface/print', ['?name' => $interface, '.proplist' => '.id,name,type,disabled']);
    if ($rows === []) throw new InvalidArgumentException('Interface introuvable sur RouterOS.');
    $row = $rows[0];
    if ((string)($row['type'] ?? '') !== 'ether') throw new InvalidArgumentException('Seules les interfaces Ethernet physiques peuvent être pilotées ici.');
    return $row;
}

function trafficControlAssertSafeWanInterface(RouterosAPI $api, string $interface): void
{
    $state = trafficControlMultiWanInterfaces($api, [$interface => $interface]);
    $match = null;
    foreach (($state['interfaces'] ?? []) as $row) {
        if (($row['interface'] ?? '') === $interface) { $match = $row; break; }
    }
    if ($match === null) throw new InvalidArgumentException('Interface WAN inconnue.');
    if (($match['bridge'] ?? '') !== '' || ($match['hotspot_servers'] ?? []) !== [] || ($match['dhcp_servers'] ?? []) !== []) {
        throw new InvalidArgumentException('Refus : cette interface est utilisée côté LAN/Hotspot/DHCP.');
    }
}

function trafficControlSetRouterInterfaceEnabled(RouterosAPI $api, string $interface, bool $enabled): void
{
    trafficControlAssertSafeWanInterface($api, $interface);
    $row = trafficControlInterfaceRowByName($api, $interface);
    mikrotikAssertNoTrap($api->comm('/interface/set', [
        '.id' => (string)$row['.id'],
        'disabled' => $enabled ? 'no' : 'yes',
    ]), 'RouterOS a refusé le changement d état de l interface.');
}

function trafficControlNwanEngineState(RouterosAPI $api): array
{
    $scripts = trafficControlRows($api, '/system/script/print', [
        '?name' => 'RM-NWAN-ASSIGN',
        '.proplist' => 'name,run-count,last-started,invalid',
    ]);
    $schedulers = trafficControlRows($api, '/system/scheduler/print', [
        '?name' => 'RM-NWAN-ASSIGN-SCHED',
        '.proplist' => 'name,disabled,run-count,next-run',
    ]);
    $bootSchedulers = trafficControlRows($api, '/system/scheduler/print', [
        '?name' => 'RM-NWAN-ASSIGN-BOOT',
        '.proplist' => 'name,disabled,run-count,next-run',
    ]);
    return [
        'script_present' => $scripts !== [],
        'script_run_count' => isset($scripts[0]['run-count']) ? (int)$scripts[0]['run-count'] : 0,
        'script_last_started' => (string)($scripts[0]['last-started'] ?? ''),
        'script_invalid' => isset($scripts[0]) ? mikrotikApiBoolean($scripts[0]['invalid'] ?? false) : null,
        'scheduler_present' => $schedulers !== [],
        'scheduler_disabled' => isset($schedulers[0]) ? mikrotikApiBoolean($schedulers[0]['disabled'] ?? true) : null,
        'scheduler_run_count' => isset($schedulers[0]['run-count']) ? (int)$schedulers[0]['run-count'] : 0,
        'scheduler_next_run' => (string)($schedulers[0]['next-run'] ?? ''),
        'boot_scheduler_present' => $bootSchedulers !== [],
        'boot_scheduler_disabled' => isset($bootSchedulers[0]) ? mikrotikApiBoolean($bootSchedulers[0]['disabled'] ?? true) : null,
        'boot_scheduler_run_count' => isset($bootSchedulers[0]['run-count']) ? (int)$bootSchedulers[0]['run-count'] : 0,
    ];
}

function trafficControlEnsureNwanSchedulers(RouterosAPI $api): void
{
    $definitions = [
        'RM-NWAN-ASSIGN-BOOT' => ['interval' => '0s', 'start-time' => 'startup'],
    ];
    $periodicSchedulers = trafficControlRows($api, '/system/scheduler/print', [
        '?name' => 'RM-NWAN-ASSIGN-SCHED',
        '.proplist' => '.id',
    ]);
    foreach ($periodicSchedulers as $scheduler) {
        mikrotikAssertNoTrap($api->comm('/system/scheduler/remove', [
            '.id' => (string)$scheduler['.id'],
        ]), 'RouterOS a refusé la suppression du scheduler N-WAN périodique.');
    }
    foreach ($definitions as $name => $timing) {
        $rows = trafficControlRows($api, '/system/scheduler/print', [
            '?name' => $name,
            '.proplist' => '.id,name',
        ]);
        $settings = [
            'name' => $name,
            'interval' => $timing['interval'],
            'on-event' => 'RM-NWAN-ASSIGN',
            'disabled' => 'no',
        ];
        if (isset($timing['start-time'])) $settings['start-time'] = $timing['start-time'];
        if ($rows === []) {
            mikrotikAssertNoTrap($api->comm('/system/scheduler/add', $settings + [
                'comment' => 'Radius Manager N-WAN assignment engine',
            ]), 'RouterOS a refusé la création du scheduler N-WAN.');
        } else {
            unset($settings['name']);
            mikrotikAssertNoTrap($api->comm('/system/scheduler/set', ['.id' => (string)$rows[0]['.id']] + $settings), 'RouterOS a refusé l activation du scheduler N-WAN.');
        }
    }
    mikrotikAssertNoTrap($api->comm('/system/script/run', [
        'number' => 'RM-NWAN-ASSIGN',
    ]), 'RouterOS a refusé l’exécution immédiate du moteur N-WAN.');
}

function trafficControlEnsureNwanLoginHooks(RouterosAPI $api, array $config): void
{
    mikrotikEnsureNwanProfileHooks($api, multiWanConfigWeights($config));
}

function trafficControlReadRouterosNwanConfig(RouterosAPI $api, array $device): array
{
    $fallback = multiWanLoadConfig($device);
    $rows = trafficControlRows($api, '/system/script/print', [
        '?name' => 'RM-NWAN-ASSIGN',
        '.proplist' => 'name,source',
    ]);
    $source = (string)($rows[0]['source'] ?? '');
    $config = $source !== '' ? multiWanParseRouterosAssignScript($source, $fallback) : $fallback;
    $interfaceRows = trafficControlRows($api, '/interface/print', [
        '.proplist' => 'name,disabled',
    ]);
    $disabledByInterface = [];
    foreach ($interfaceRows as $row) {
        $name = trim((string)($row['name'] ?? ''));
        if ($name !== '') $disabledByInterface[$name] = mikrotikApiBoolean($row['disabled'] ?? false);
    }
    if (!isset($config['sources']) || !is_array($config['sources'])) $config['sources'] = [];
    foreach ($config['sources'] as $slot => $sourceConfig) {
        if (!is_array($sourceConfig)) continue;
        $interface = trim((string)($sourceConfig['interface'] ?? ''));
        if ($interface !== '' && array_key_exists($interface, $disabledByInterface)) {
            $routerDisabled = $disabledByInterface[$interface];
            $config['sources'][$slot]['enabled'] = !$routerDisabled;
            $config['sources'][$slot]['router_interface_disabled'] = $routerDisabled;
        }
    }
    $config['engine_mode'] = 'routeros';
    return $config;
}

function trafficControlWriteRouterosNwanConfig(RouterosAPI $api, array $config, array $userOverrides = []): array
{
    $source = multiWanBuildRouterosAssignScript($config, $userOverrides);
    $scriptRows = trafficControlRows($api, '/system/script/print', [
        '?name' => 'RM-NWAN-ASSIGN',
        '.proplist' => '.id,name',
    ]);
    if ($scriptRows === []) {
        mikrotikAssertNoTrap($api->comm('/system/script/add', [
            'name' => 'RM-NWAN-ASSIGN',
            'source' => $source,
            'policy' => 'read,write,policy,test',
        ]), 'RouterOS a refusé la création du moteur N-WAN.');
    } else {
        mikrotikAssertNoTrap($api->comm('/system/script/set', [
            '.id' => (string)$scriptRows[0]['.id'],
            'source' => $source,
        ]), 'RouterOS a refusé la mise à jour du moteur N-WAN.');
    }
    trafficControlEnsureNwanSchedulers($api);
    trafficControlEnsureNwanLoginHooks($api, $config);
    return [
        'source_length' => strlen($source),
        'source_sha256' => hash('sha256', $source),
    ];
}

function trafficControlApplyNwanEngineConfig(RouterosAPI $api, array $config, array $userOverrides = []): array
{
    $weights = multiWanConfigWeights($config);
    $sources = is_array($config['sources'] ?? null) ? $config['sources'] : [];
    $activeInterfaces = [];
    $activeWeightTotal = 0;
    foreach (['wan1', 'wan2', 'wan3', 'wan4'] as $slot) {
        $source = is_array($sources[$slot] ?? null) ? $sources[$slot] : [];
        $weight = (int)($weights[$slot] ?? 0);
        if ($weight <= 0) continue;
        $interface = trafficControlWanInterfaceName((string)($source['interface'] ?? ''));
        if (in_array($interface, $activeInterfaces, true)) {
            throw new InvalidArgumentException('Interface WAN dupliquée dans les paramètres locaux : ' . $interface);
        }
        trafficControlAssertSafeWanInterface($api, $interface);
        $interfaceRow = trafficControlInterfaceRowByName($api, $interface);
        if (mikrotikApiBoolean($interfaceRow['disabled'] ?? false)) {
            throw new InvalidArgumentException('Source active impossible : l interface RouterOS ' . $interface . ' est désactivée.');
        }
        $activeInterfaces[] = $interface;
        $activeWeightTotal += $weight;
    }
    if ($activeInterfaces === []) {
        throw new InvalidArgumentException('Aucune source WAN active à appliquer.');
    }

    $source = multiWanBuildRouterosAssignScript($config, $userOverrides);
    $scriptRows = trafficControlRows($api, '/system/script/print', [
        '?name' => 'RM-NWAN-ASSIGN',
        '.proplist' => '.id,name',
    ]);
    if ($scriptRows === []) {
        mikrotikAssertNoTrap($api->comm('/system/script/add', [
            'name' => 'RM-NWAN-ASSIGN',
            'source' => $source,
            'policy' => 'read,write,policy,test',
        ]), 'RouterOS a refusé la création du moteur N-WAN.');
    } else {
        mikrotikAssertNoTrap($api->comm('/system/script/set', [
            '.id' => (string)$scriptRows[0]['.id'],
            'source' => $source,
        ]), 'RouterOS a refusé la mise à jour du moteur N-WAN.');
    }

    trafficControlEnsureNwanSchedulers($api);
    trafficControlEnsureNwanLoginHooks($api, $config);

    return [
        'weights' => $weights,
        'active_interfaces' => $activeInterfaces,
        'active_weight_total' => $activeWeightTotal,
        'source_length' => strlen($source),
        'source_sha256' => hash('sha256', $source),
    ];
}

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || !isAdministrator()) {
    trafficControlReply(['success' => false, 'message' => 'Accès réservé à l administrateur.'], 403);
}

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$input = $method === 'POST'
    ? json_decode((string)file_get_contents('php://input'), true)
    : $_GET;
if (!is_array($input)) {
    trafficControlReply(['success' => false, 'message' => 'Requête JSON invalide.'], 400);
}

if ($method === 'POST') {
    $token = trim((string)($input['csrf_token'] ?? ''));
    if ($token === '' || !hash_equals((string)($_SESSION['csrf_token'] ?? ''), $token)) {
        trafficControlReply(['success' => false, 'message' => 'Jeton CSRF invalide.'], 403);
    }
}

session_write_close();

$deviceId = trim((string)($input['device_id'] ?? ''));
if ($deviceId === '') {
    trafficControlReply(['success' => false, 'message' => 'Device MikroTik manquant.'], 422);
}

try {
    $device = requireAccessibleDevice(loadDeviceStore(), $deviceId);
    if (normalizeDeviceType((string)($device['type'] ?? '')) !== 'mikrotik') {
        throw new RuntimeException('Le device sélectionné n est pas un MikroTik.');
    }
} catch (Throwable $e) {
    trafficControlReply(['success' => false, 'message' => $e->getMessage()], 409);
}

function trafficControlState(RouterosAPI $api, ?PDO $statePdo = null, ?array $stateDevice = null): array
{
    $tracking = mikrotikFirstRow($api->comm('/ip/firewall/connection/tracking/print'));
    $dns = mikrotikFirstRow($api->comm('/ip/dns/print'));
    $adlists = $api->comm('/ip/dns/adlist/print', [
        '.proplist' => '.id,url,file,ssl-verify,match-count,name-count,disabled,comment',
    ]);
    $staticRows = $api->comm('/ip/dns/static/print', [
        '.proplist' => '.id,name,type,address,forward-to,match-subdomain,disabled,comment',
    ]);
    $natRows = $api->comm('/ip/firewall/nat/print', [
        '.proplist' => '.id,chain,action,src-address,protocol,dst-port,to-ports,comment,disabled,packets,bytes',
    ]);
    $rawRows = $api->comm('/ip/firewall/raw/print', [
        '.proplist' => '.id,chain,action,src-address,protocol,dst-port,in-interface,dst-address-type,comment,disabled,packets,bytes',
    ]);
    $filterRows = $api->comm('/ip/firewall/filter/print', [
        '.proplist' => '.id,chain,action,protocol,dst-port,comment,disabled',
    ]);
    $interfaceRows = trafficControlRows($api, '/interface/print', [
        '.proplist' => '.id,name,type,running,disabled',
    ]);
    $simpleQueueRows = trafficControlRows($api, '/queue/simple/print', [
        '.proplist' => '.id,name,target,max-limit,priority,disabled,invalid,dynamic,comment',
    ]);
    $hotspotProfileRows = trafficControlRows($api, '/ip/hotspot/user/profile/print', [
        '.proplist' => '.id,name,rate-limit,parent-queue',
    ]);
    $hotspotRows = trafficControlRows($api, '/ip/hotspot/print', [
        '.proplist' => 'name,interface,disabled,invalid',
    ]);
    $bridgePortRows = trafficControlRows($api, '/interface/bridge/port/print', [
        '.proplist' => 'interface,bridge,disabled',
    ]);

    $whitelist = [];
    $dnsBlocks = [];
    foreach (is_array($staticRows) ? $staticRows : [] as $row) {
        if (!is_array($row)) {
            continue;
        }
        $type = strtoupper((string)($row['type'] ?? ''));
        if ($type === 'FWD' && (string)($row['comment'] ?? '') === 'Radius Manager - liste blanche trafic') {
            $whitelist[] = $row;
        } elseif ($type === 'NXDOMAIN' && trafficControlIsManagedDnsBlock($row)) {
            $dnsBlocks[] = $row;
        }
    }

    $adlistRows = array_values(array_filter(is_array($adlists) ? $adlists : [], 'is_array'));
    foreach ($adlistRows as &$adlistRow) {
        $adlistRow['managed'] = (string)($adlistRow['comment'] ?? '') === 'Radius Manager - Adlist';
    }
    unset($adlistRow);
    $natRows = array_values(array_filter(is_array($natRows) ? $natRows : [], 'is_array'));
    $rawRows = array_values(array_filter(is_array($rawRows) ? $rawRows : [], 'is_array'));
    $filterRows = array_values(array_filter(is_array($filterRows) ? $filterRows : [], 'is_array'));

    $interfaces = [];
    $interfaceTypes = [];
    foreach ($interfaceRows as $row) {
        $name = trim((string)($row['name'] ?? ''));
        if ($name !== '') $interfaceTypes[$name] = (string)($row['type'] ?? '');
        if ($name === '' || (string)($row['type'] ?? '') === 'loopback' || mikrotikApiBoolean($row['disabled'] ?? false)) continue;
        $interfaces[] = ['name' => $name, 'type' => (string)($row['type'] ?? ''), 'running' => mikrotikApiBoolean($row['running'] ?? false)];
    }
    $portBridges = [];
    foreach ($bridgePortRows as $row) {
        if (mikrotikApiBoolean($row['disabled'] ?? false)) continue;
        $portBridges[(string)($row['interface'] ?? '')] = (string)($row['bridge'] ?? '');
    }
    $hotspotBridges = [];
    foreach ($hotspotRows as $row) {
        if (mikrotikApiBoolean($row['disabled'] ?? false) || mikrotikApiBoolean($row['invalid'] ?? false)) continue;
        $hotspotInterface = trim((string)($row['interface'] ?? ''));
        $bridge = ($interfaceTypes[$hotspotInterface] ?? '') === 'bridge' ? $hotspotInterface : ($portBridges[$hotspotInterface] ?? '');
        if ($bridge === '') continue;
        if (!isset($hotspotBridges[$bridge])) $hotspotBridges[$bridge] = ['name' => $bridge, 'hotspot_servers' => []];
        $hotspotBridges[$bridge]['hotspot_servers'][] = (string)($row['name'] ?? '');
    }
    $firstDynamicIndex = null;
    $managedQueues = [];
    $dynamicInvalidCount = count(array_filter($simpleQueueRows, static fn(array $row): bool =>
        mikrotikApiBoolean($row['dynamic'] ?? false) && mikrotikApiBoolean($row['invalid'] ?? false)
    ));
    $limitedHotspotProfiles = array_values(array_filter($hotspotProfileRows, static fn(array $row): bool => trim((string)($row['rate-limit'] ?? '')) !== ''));
    foreach ($simpleQueueRows as $index => $row) {
        $dynamic = mikrotikApiBoolean($row['dynamic'] ?? false);
        if ($dynamic && $firstDynamicIndex === null) $firstDynamicIndex = $index;
        if (!str_starts_with((string)($row['comment'] ?? ''), 'Radius Manager - interface queue:')) continue;
        $row['before_hotspot_dynamic'] = $firstDynamicIndex === null || $index < $firstDynamicIndex;
        $queueName = (string)($row['name'] ?? '');
        $parentProfiles = array_values(array_filter($limitedHotspotProfiles, static fn(array $profile): bool => (string)($profile['parent-queue'] ?? '') === $queueName));
        $row['hotspot_parent_profiles'] = count($parentProfiles);
        $row['hotspot_limited_profiles'] = count($limitedHotspotProfiles);
        $row['hotspot_parent_complete'] = $limitedHotspotProfiles !== [] && count($parentProfiles) === count($limitedHotspotProfiles);
        $row['healthy'] = !mikrotikApiBoolean($row['disabled'] ?? false)
            && !mikrotikApiBoolean($row['invalid'] ?? false)
            && $row['hotspot_parent_complete']
            && $dynamicInvalidCount === 0;
        $managedQueues[] = $row;
    }

    $multiWanConfig = $stateDevice ? trafficControlReadRouterosNwanConfig($api, $stateDevice) : ['sources' => []];
    $multiWanInterfaces = trafficControlMultiWanInterfaces($api, trafficControlWanSlotAssignments($_REQUEST['wan_slots'] ?? null));
    $wanInterfaceRows = [];
    foreach (($multiWanInterfaces['interfaces'] ?? []) as $row) {
        $name = trim((string)($row['interface'] ?? ''));
        if ($name !== '') $wanInterfaceRows[$name] = $row;
    }
    foreach (($multiWanConfig['sources'] ?? []) as $slot => $source) {
        if (!is_array($source)) continue;
        $interfaceName = trim((string)($source['interface'] ?? ''));
        $row = $interfaceName !== '' ? ($wanInterfaceRows[$interfaceName] ?? null) : null;
        $usableWan = is_array($row) && ($row['status'] ?? '') === 'valid_wan' && !empty($row['selectable']);
        $multiWanConfig['sources'][$slot]['enabled'] = $usableWan;
        if (is_array($row)) {
            $multiWanConfig['sources'][$slot]['router_interface_disabled'] = ($row['link_state'] ?? '') === 'disabled';
        }
    }
    $activeWanInterfaces = [];
    foreach (($multiWanConfig['sources'] ?? []) as $source) {
        if (!is_array($source) || empty($source['enabled'])) continue;
        $interfaceName = trim((string)($source['interface'] ?? ''));
        if ($interfaceName !== '') $activeWanInterfaces[$interfaceName] = true;
    }
    $wanUsageInterfaces = array_values(array_filter($multiWanInterfaces['interfaces'] ?? [], static function (array $row) use ($activeWanInterfaces): bool {
        $interfaceName = trim((string)($row['interface'] ?? ''));
        return $interfaceName !== ''
            && isset($activeWanInterfaces[$interfaceName])
            && ($row['status'] ?? '') === 'valid_wan'
            && !empty($row['selectable']);
    }));
    $wanUsageInterfaceNames = array_values(array_map(static fn(array $row): string => (string)$row['interface'], $wanUsageInterfaces));
    $deviceId = trim((string)(($stateDevice['id'] ?? '') ?: ($stateDevice['host'] ?? '')));
    $multiWanUsage = $statePdo ? trafficControlWanUsageSummary(
        $statePdo,
        $deviceId,
        $wanUsageInterfaceNames,
        trim((string)($_REQUEST['usage_day'] ?? '')) ?: null
    ) : [];

    return [
        'tracking' => [
            'tcp_established_timeout' => (string)($tracking['tcp-established-timeout'] ?? ''),
            'udp_timeout' => (string)($tracking['udp-timeout'] ?? ''),
            'udp_stream_timeout' => (string)($tracking['udp-stream-timeout'] ?? ''),
            'generic_timeout' => (string)($tracking['generic-timeout'] ?? ''),
            'max_entries' => (int)($tracking['max-entries'] ?? 0),
            'total_entries' => (int)($tracking['total-entries'] ?? 0),
            'active_ipv4' => mikrotikApiBoolean($tracking['active-ipv4'] ?? false),
            'active_ipv6' => mikrotikApiBoolean($tracking['active-ipv6'] ?? false),
        ],
        'dns' => [
            'allow_remote_requests' => mikrotikApiBoolean($dns['allow-remote-requests'] ?? false),
            'servers' => (string)($dns['servers'] ?? ''),
            'dynamic_servers' => (string)($dns['dynamic-servers'] ?? ''),
            'use_doh_server' => (string)($dns['use-doh-server'] ?? ''),
            'verify_doh_cert' => mikrotikApiBoolean($dns['verify-doh-cert'] ?? false),
            'cache_size' => (string)($dns['cache-size'] ?? ''),
            'cache_used' => (string)($dns['cache-used'] ?? ''),
            'cache_max_ttl' => (string)($dns['cache-max-ttl'] ?? ''),
            'max_concurrent_queries' => isset($dns['max-concurrent-queries'])
                ? (int)$dns['max-concurrent-queries']
                : null,
            'max_concurrent_tcp_sessions' => isset($dns['max-concurrent-tcp-sessions'])
                ? (int)$dns['max-concurrent-tcp-sessions']
                : null,
            'query_server_timeout' => (string)($dns['query-server-timeout'] ?? ''),
            'query_total_timeout' => (string)($dns['query-total-timeout'] ?? ''),
        ],
        'adlist_catalog' => array_values(array_map(
            static fn(array $definition): array => ['label' => $definition['label'], 'url' => $definition['url']],
            array_filter(trafficControlPolicyDefinitions(), static fn(array $definition): bool => isset($definition['url']))
        )),
        'adlists' => $adlistRows,
        'whitelist' => $whitelist,
        'dns_blocks' => $dnsBlocks,
        'policies' => trafficControlPolicyState($adlistRows, $staticRows, $natRows, $rawRows, $filterRows),
        'interface_queues' => [
            'interfaces' => $interfaces,
            'hotspot_bridges' => array_values($hotspotBridges),
            'managed' => $managedQueues,
            'dynamic_hotspot_count' => count(array_filter($simpleQueueRows, static fn(array $row): bool => mikrotikApiBoolean($row['dynamic'] ?? false))),
            'dynamic_invalid_count' => $dynamicInvalidCount,
        ],
        'multi_wan_interfaces' => $multiWanInterfaces,
        'multi_wan_usage' => $multiWanUsage,
        'nwan_engine' => trafficControlNwanEngineState($api),
        'multi_wan_config' => $multiWanConfig,
    ];
}

function trafficControlWanSlotAssignments(mixed $raw): array
{
    if (!is_string($raw) || trim($raw) === '') return [];
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) return [];
    $slots = [];
    foreach ($decoded as $slot => $interface) {
        $slotName = preg_replace('/[^a-zA-Z0-9_-]/', '', (string)$slot);
        $interfaceName = trim((string)$interface);
        if ($slotName !== '' && $interfaceName !== '') {
            $slots[$slotName] = $interfaceName;
        }
    }
    return $slots;
}

function trafficControlIpv4FromAddress(string $address): string
{
    $ip = explode('/', $address, 2)[0] ?? '';
    return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) ? $ip : '';
}

function trafficControlWanRouteMatchesInterface(array $row, string $interface): bool
{
    if (mikrotikApiBoolean($row['disabled'] ?? false)) return false;
    $dst = trim((string)($row['dst-address'] ?? ''));
    if ($dst !== '' && $dst !== '0.0.0.0/0') return false;
    $gateway = (string)($row['gateway'] ?? '');
    $immediate = (string)($row['immediate-gw'] ?? '');
    return str_contains($gateway, '%' . $interface)
        || str_contains($immediate, '%' . $interface)
        || trim($gateway) === $interface
        || trim($immediate) === $interface;
}

function trafficControlMultiWanInterfaces(RouterosAPI $api, array $slotAssignments = []): array
{
    $interfaceRows = trafficControlRows($api, '/interface/print', [
        '.proplist' => '.id,name,type,running,disabled,actual-mtu,mtu,rx-byte,tx-byte',
    ]);
    $bridgePortRows = trafficControlRows($api, '/interface/bridge/port/print', [
        '.proplist' => 'interface,bridge,disabled',
    ]);
    $hotspotRows = trafficControlRows($api, '/ip/hotspot/print', [
        '.proplist' => 'name,interface,disabled,invalid',
    ]);
    $dhcpServerRows = trafficControlRows($api, '/ip/dhcp-server/print', [
        '.proplist' => 'name,interface,disabled,invalid',
    ]);
    $dhcpClientRows = trafficControlRows($api, '/ip/dhcp-client/print', [
        '.proplist' => 'interface,status,address,gateway,disabled,invalid,add-default-route',
    ]);
    $addressRows = trafficControlRows($api, '/ip/address/print', [
        '.proplist' => 'interface,address,network,disabled,dynamic',
    ]);
    $routeRows = trafficControlRows($api, '/ip/route/print', [
        '.proplist' => 'dst-address,gateway,immediate-gw,active,disabled,dynamic,routing-table',
    ]);
    $listMemberRows = trafficControlRows($api, '/interface/list/member/print', [
        '.proplist' => 'interface,list,disabled',
    ]);

    $bridgeByInterface = [];
    foreach ($bridgePortRows as $row) {
        if (mikrotikApiBoolean($row['disabled'] ?? false)) continue;
        $interface = trim((string)($row['interface'] ?? ''));
        if ($interface !== '') $bridgeByInterface[$interface] = trim((string)($row['bridge'] ?? ''));
    }

    $hotspotByInterface = [];
    foreach ($hotspotRows as $row) {
        if (mikrotikApiBoolean($row['disabled'] ?? false) || mikrotikApiBoolean($row['invalid'] ?? false)) continue;
        $interface = trim((string)($row['interface'] ?? ''));
        if ($interface !== '') $hotspotByInterface[$interface][] = trim((string)($row['name'] ?? 'hotspot'));
    }

    $dhcpServerByInterface = [];
    foreach ($dhcpServerRows as $row) {
        if (mikrotikApiBoolean($row['disabled'] ?? false) || mikrotikApiBoolean($row['invalid'] ?? false)) continue;
        $interface = trim((string)($row['interface'] ?? ''));
        if ($interface !== '') $dhcpServerByInterface[$interface][] = trim((string)($row['name'] ?? 'dhcp-server'));
    }

    $dhcpClientByInterface = [];
    foreach ($dhcpClientRows as $row) {
        $interface = trim((string)($row['interface'] ?? ''));
        if ($interface !== '') $dhcpClientByInterface[$interface] = $row;
    }

    $addressesByInterface = [];
    foreach ($addressRows as $row) {
        if (mikrotikApiBoolean($row['disabled'] ?? false)) continue;
        $interface = trim((string)($row['interface'] ?? ''));
        $address = trim((string)($row['address'] ?? ''));
        if ($interface !== '' && trafficControlIpv4FromAddress($address) !== '') {
            $addressesByInterface[$interface][] = $address;
        }
    }

    $wanListMembers = [];
    foreach ($listMemberRows as $row) {
        if (mikrotikApiBoolean($row['disabled'] ?? false)) continue;
        if (strtoupper(trim((string)($row['list'] ?? ''))) !== 'WAN') continue;
        $interface = trim((string)($row['interface'] ?? ''));
        if ($interface !== '') $wanListMembers[$interface] = true;
    }

    $assignedSlotsByInterface = [];
    foreach ($slotAssignments as $slot => $interface) {
        $assignedSlotsByInterface[$interface][] = $slot;
    }

    $interfaces = [];
    foreach ($interfaceRows as $row) {
        $name = trim((string)($row['name'] ?? ''));
        $type = trim((string)($row['type'] ?? ''));
        if ($name === '' || $type !== 'ether') continue;

        $disabled = mikrotikApiBoolean($row['disabled'] ?? false);
        $running = mikrotikApiBoolean($row['running'] ?? false);
        $bridge = $bridgeByInterface[$name] ?? '';
        $hotspotServers = array_merge($hotspotByInterface[$name] ?? [], $bridge !== '' ? ($hotspotByInterface[$bridge] ?? []) : []);
        $dhcpServers = array_merge($dhcpServerByInterface[$name] ?? [], $bridge !== '' ? ($dhcpServerByInterface[$bridge] ?? []) : []);
        $dhcpClient = $dhcpClientByInterface[$name] ?? null;
        $dhcpClientDisabled = $dhcpClient ? mikrotikApiBoolean($dhcpClient['disabled'] ?? false) : false;
        $dhcpClientStatus = $dhcpClient && !$dhcpClientDisabled ? trim((string)($dhcpClient['status'] ?? '')) : '';
        $dhcpAddress = $dhcpClient && !$dhcpClientDisabled ? trim((string)($dhcpClient['address'] ?? '')) : '';
        $dhcpGateway = $dhcpClient && !$dhcpClientDisabled ? trim((string)($dhcpClient['gateway'] ?? '')) : '';
        $addresses = $addressesByInterface[$name] ?? [];
        $ipAddress = $dhcpAddress !== '' ? $dhcpAddress : ($addresses[0] ?? '');
        $gateway = $dhcpGateway;
        if ($gateway === '') {
            foreach ($routeRows as $routeRow) {
                if (trafficControlWanRouteMatchesInterface($routeRow, $name)) {
                    $gateway = trim((string)($routeRow['gateway'] ?? $routeRow['immediate-gw'] ?? ''));
                    break;
                }
            }
        }

        $hasIp = trafficControlIpv4FromAddress($ipAddress) !== '';
        $dhcpBound = strtolower($dhcpClientStatus) === 'bound' && $hasIp && $gateway !== '';
        $staticWan = $dhcpClient === null && $hasIp && $gateway !== '';
        $hasLanService = $bridge !== '' || $hotspotServers !== [] || $dhcpServers !== [];
        $assignedSlots = $assignedSlotsByInterface[$name] ?? [];
        $wanHealth = 'offline';

        $status = 'available_unconfigured';
        $reason = 'WAN non configuré';
        $selectable = false;
        if ($disabled || !$running) {
            $status = 'inactive';
            $reason = $disabled ? 'Interface désactivée' : 'Lien inactif';
        } elseif ($bridge !== '') {
            $status = 'lan_bridge_member';
            $reason = 'LAN / ' . $bridge;
        } elseif ($hotspotServers !== [] || $dhcpServers !== []) {
            $status = 'lan_service_in_use';
            $reason = trim(($hotspotServers !== [] ? 'Hotspot ' . implode(', ', $hotspotServers) : '') . ($dhcpServers !== [] ? ' DHCP LAN ' . implode(', ', $dhcpServers) : ''));
        } elseif ($dhcpBound || $staticWan) {
            $status = 'valid_wan';
            $reason = 'WAN valide';
            $selectable = true;
            $wanHealth = 'online';
        } elseif ($dhcpClient !== null) {
            $status = 'misconfigured_wan';
            $reason = 'DHCP WAN ' . ($dhcpClientDisabled ? 'désactivé' : ($dhcpClientStatus !== '' ? $dhcpClientStatus : 'non bound'));
            $wanHealth = $hasIp ? 'degraded' : 'offline';
        }

        $interfaces[] = [
            'interface' => $name,
            'status' => $status,
            'selectable' => $selectable,
            'reason' => $reason,
            'link_state' => $disabled ? 'disabled' : ($running ? 'running' : 'down'),
            'bridge' => $bridge,
            'dhcp_client_state' => $dhcpClientStatus,
            'ip_address' => $ipAddress,
            'gateway' => $gateway,
            'assigned_wan_slot' => $assignedSlots[0] ?? null,
            'assigned_wan_slots' => $assignedSlots,
            'in_wan_list' => isset($wanListMembers[$name]),
            'hotspot_servers' => $hotspotServers,
            'dhcp_servers' => $dhcpServers,
            'wan_health' => $wanHealth,
            'rx_total' => (int)($row['rx-byte'] ?? 0),
            'tx_total' => (int)($row['tx-byte'] ?? 0),
            'latency' => null,
            'packet_loss' => null,
            'mtu' => (string)($row['mtu'] ?? ''),
            'actual_mtu' => (string)($row['actual-mtu'] ?? ''),
        ];
    }

    return [
        'interfaces' => $interfaces,
        'statuses' => ['valid_wan', 'used_by_other_wan', 'lan_bridge_member', 'lan_service_in_use', 'misconfigured_wan', 'inactive', 'available_unconfigured'],
        'slot_assignments' => $slotAssignments,
    ];
}

function trafficControlWhitelistComment(): string
{
    return 'Radius Manager - liste blanche trafic';
}

function trafficControlManagedBlockComments(): array
{
    $comments = [
        'Radius Manager - blocage Personnalisé',
        'Radius Manager - blocage Publicité',
        'Radius Manager - blocage Mise à jour',
        'Radius Manager - blocage Télémétrie',
    ];
    foreach (trafficControlPolicyDefinitions() as $definition) {
        if (isset($definition['domains'], $definition['comment'])) $comments[] = (string)$definition['comment'];
    }
    return array_values(array_unique($comments));
}

function trafficControlIsManagedDnsBlock(array $row): bool
{
    return strtoupper((string)($row['type'] ?? '')) === 'NXDOMAIN'
        && in_array((string)($row['comment'] ?? ''), trafficControlManagedBlockComments(), true);
}

function trafficControlFlushDnsCache(RouterosAPI $api): void
{
    mikrotikAssertNoTrap($api->comm('/ip/dns/cache/flush'), 'RouterOS a refusé de vider le cache DNS.');
}

function trafficControlReloadAdlists(RouterosAPI $api): void
{
    mikrotikAssertNoTrap($api->comm('/ip/dns/adlist/reload'), 'RouterOS a refusé la mise à jour des Adlists.');
}

function trafficControlDnsBoolean(mixed $value, string $label): string
{
    $raw = strtolower(trim((string)$value));
    if (!in_array($raw, ['yes', 'no'], true)) {
        throw new InvalidArgumentException($label . ' doit valoir yes ou no.');
    }
    return $raw;
}

function trafficControlDnsServers(mixed $value): string
{
    $raw = trim((string)$value);
    if ($raw === '') return '';
    $items = array_map('trim', explode(',', $raw));
    foreach ($items as $item) {
        $parts = explode('@', $item, 2);
        $ip = $parts[0] ?? '';
        if ($ip === '' || filter_var($ip, FILTER_VALIDATE_IP) === false || (isset($parts[1]) && trim($parts[1]) === '')) {
            throw new InvalidArgumentException('Serveurs DNS : utilisez des IP séparées par des virgules.');
        }
    }
    return implode(',', $items);
}

function trafficControlDohServer(mixed $value): string
{
    $url = trim((string)$value);
    if ($url === '') return '';
    $parts = parse_url($url);
    if (filter_var($url, FILTER_VALIDATE_URL) === false
        || ($parts['scheme'] ?? '') !== 'https'
        || trim((string)($parts['host'] ?? '')) === ''
        || isset($parts['user'], $parts['pass'], $parts['fragment'])
        || (isset($parts['port']) && (int)$parts['port'] !== 443)) {
        throw new InvalidArgumentException('URL DoH invalide : HTTPS public sans identifiants ni fragment requis.');
    }
    return $url;
}

function trafficControlDnsCacheSize(mixed $value): string
{
    $raw = trim((string)$value);
    if (!preg_match('/^([0-9]+)(?:\s*KiB)?$/i', $raw, $match)) {
        throw new InvalidArgumentException('Taille du cache DNS invalide.');
    }
    $size = (int)$match[1];
    if ($size < 64 || $size > 4294967295) {
        throw new InvalidArgumentException('La taille du cache DNS doit être comprise entre 64 et 4294967295 KiB.');
    }
    return (string)$size;
}

function trafficControlDnsInteger(mixed $value, string $label): string
{
    $integer = filter_var($value, FILTER_VALIDATE_INT);
    if ($integer === false || $integer < 1 || $integer > 4294967295) {
        throw new InvalidArgumentException($label . ' doit être un entier positif.');
    }
    return (string)$integer;
}

function trafficControlDnsTime(mixed $value, string $label): string
{
    $raw = trim((string)$value);
    if ($raw === '' || !preg_match('/^(?:\d+w)?(?:\d+d)?(?:\d+h)?(?:\d+m)?(?:\d+s)?$|^\d{1,2}:\d{2}:\d{2}$/', $raw)) {
        throw new InvalidArgumentException($label . ' doit utiliser un délai RouterOS valide, par exemple 2s, 10s ou 1d.');
    }
    return $raw;
}

function trafficControlDnsSettingsPayload(array $settings): array
{
    return [
        'servers' => trafficControlDnsServers($settings['servers'] ?? ''),
        'use-doh-server' => trafficControlDohServer($settings['use-doh-server'] ?? ''),
        'cache-size' => trafficControlDnsCacheSize($settings['cache-size'] ?? ''),
        'cache-max-ttl' => trafficControlDnsTime($settings['cache-max-ttl'] ?? '', 'TTL maximal'),
        'max-concurrent-queries' => trafficControlDnsInteger($settings['max-concurrent-queries'] ?? null, 'Requêtes simultanées'),
        'max-concurrent-tcp-sessions' => trafficControlDnsInteger($settings['max-concurrent-tcp-sessions'] ?? null, 'Sessions TCP simultanées'),
        'query-server-timeout' => trafficControlDnsTime($settings['query-server-timeout'] ?? '', 'Délai par serveur'),
        'query-total-timeout' => trafficControlDnsTime($settings['query-total-timeout'] ?? '', 'Délai total'),
        'allow-remote-requests' => trafficControlDnsBoolean($settings['allow-remote-requests'] ?? '', 'Service DNS pour les clients'),
        'verify-doh-cert' => trafficControlDnsBoolean($settings['verify-doh-cert'] ?? '', 'Vérification certificat DoH'),
    ];
}

function trafficControlInterfaceName(array $interfaces, string $requested): string
{
    foreach ($interfaces as $interface) {
        if ((string)($interface['name'] ?? '') === $requested) return $requested;
    }
    throw new InvalidArgumentException('Interface introuvable ou désactivée sur ce routeur.');
}

function trafficControlMbps(mixed $value, string $label): string
{
    $number = filter_var($value, FILTER_VALIDATE_FLOAT);
    if ($number === false || $number <= 0 || $number > 100000) {
        throw new InvalidArgumentException($label . ' doit être compris entre 0 et 100000 Mbit/s.');
    }
    return (string)(int)round($number * 1000000);
}

function trafficControlHotspotRateWithoutCommittedRate(string $rateLimit): string
{
    $parts = preg_split('/\s+/', trim($rateLimit)) ?: [];
    if ($parts === [] || count($parts) > 6) {
        throw new InvalidArgumentException('Format rate-limit Hotspot non pris en charge : ' . $rateLimit);
    }

    $parts += [1 => '0/0', 2 => '0/0', 3 => '0/0', 4 => '8', 5 => '0/0'];
    $parts[5] = '0/0';
    ksort($parts);

    return implode(' ', $parts);
}

function trafficControlEnsureQueueBeforeDynamic(RouterosAPI $api, string $queueId): void
{
    $rows = trafficControlRows($api, '/queue/simple/print', ['.proplist' => '.id,dynamic']);
    $firstDynamic = null;
    $queueIndex = null;
    foreach ($rows as $index => $row) {
        if ((string)($row['.id'] ?? '') === $queueId) $queueIndex = $index;
        if ($firstDynamic === null && mikrotikApiBoolean($row['dynamic'] ?? false)) $firstDynamic = ['id' => (string)($row['.id'] ?? ''), 'index' => $index];
    }
    if ($firstDynamic !== null && $queueIndex !== null && $queueIndex > $firstDynamic['index']) {
        mikrotikAssertNoTrap($api->comm('/queue/simple/move', ['numbers' => $queueId, 'destination' => $firstDynamic['id']]), 'RouterOS a refusé de placer la Simple Queue avant les files Hotspot.');
    }
}

function trafficControlPolicyDefinitions(): array
{
    return [
        'ads' => [
            'section' => 'lists',
            'label' => 'Publicités et trackers',
            'description' => 'Liste DNS StevenBlack appliquée à tous les clients utilisant le DNS MikroTik.',
            'url' => 'https://raw.githubusercontent.com/StevenBlack/hosts/master/hosts',
        ],
        'adult' => [
            'section' => 'lists',
            'label' => 'Contenus adultes',
            'description' => 'Liste StevenBlack adultes pour filtrer les domaines explicites via DNS MikroTik.',
            'url' => 'https://raw.githubusercontent.com/StevenBlack/hosts/master/alternates/porn-only/hosts',
        ],
        'force_dns' => [
            'section' => 'security',
            'label' => 'DNS MikroTik forcé',
            'description' => 'Redirige le DNS standard TCP/UDP 53 du sous-réseau Hotspot détecté vers le routeur.',
            'comments' => [
                'Radius Manager - forcer DNS clients UDP',
                'Radius Manager - forcer DNS clients TCP',
            ],
        ],
        'block_dot' => [
            'section' => 'security',
            'label' => 'DNS chiffré sortant bloqué',
            'description' => 'Bloque le DNS chiffré (TCP/UDP 853) sortant du routeur, qui contourne le DNS MikroTik et sort par le WAN du client.',
            'comments' => [
                'Radius Manager - bloquer DNS chiffre 853 TCP',
                'Radius Manager - bloquer DNS chiffre 853 UDP',
            ],
        ],
        'windows_update' => [
            'section' => 'services',
            'label' => 'Mises à jour Windows',
            'description' => 'Bloque les domaines dédiés à Windows Update. Microsoft Store peut être affecté.',
            'comment' => 'Radius Manager - blocage Windows Update',
            'domains' => [
                'windowsupdate.com',
                'update.microsoft.com',
                'delivery.mp.microsoft.com',
                'prod.do.dsp.mp.microsoft.com',
            ],
        ],
        'apple_update' => [
            'section' => 'services',
            'label' => 'Mises à jour Apple',
            'description' => 'Bloque uniquement les endpoints Apple de catalogue et téléchargement observés.',
            'comment' => 'Radius Manager - blocage Apple Update',
            'domains' => [
                'mesu.apple.com',
                'updates-http.cdn-apple.com',
            ],
        ],
        'android_update' => [
            'section' => 'services',
            'label' => 'Mises à jour Android',
            'description' => 'Bloque uniquement l endpoint Google Update observé, sans bloquer Google Play.',
            'comment' => 'Radius Manager - blocage Android Update',
            'domains' => ['update.googleapis.com'],
        ],
        'google_background' => [
            'section' => 'services',
            'label' => 'Google Federated ML',
            'description' => 'Bloque uniquement le service Google d apprentissage fédéré observé, sans bloquer Google Play.',
            'comment' => 'Radius Manager - blocage Google federated ML',
            'domains' => ['federatedml-pa.googleapis.com'],
        ],
        'connectivity_local_reply' => [
            'section' => 'services',
            'label' => 'Présence connexion locale',
            'description' => 'Répond localement via 10.10.10.2 aux tests de présence connexion Android, Windows et Apple, sans prendre le domaine Hotspot.',
            'comment' => 'Radius Manager - reponse locale presence connexion',
            'domains' => [
                'connectivitycheck.gstatic.com',
                'clients3.google.com',
                'clients4.google.com',
                'www.msftconnecttest.com',
                'dns.msftncsi.com',
                'www.msftncsi.com',
                'captive.apple.com',
            ],
            'local_reply_address' => '10.10.10.2',
        ],
        'client_admin_ports' => [
            'section' => 'security',
            'label' => 'SSH et Telnet sortants',
            'description' => 'Bloque TCP/22 et TCP/23 des clients vers Internet avant conntrack.',
            'comment' => 'Radius Manager - blocage clients TCP 22 23',
        ],
    ];
}

function trafficControlDisabled(array $row): bool
{
    return mikrotikApiBoolean($row['disabled'] ?? false);
}

function trafficControlPolicyState(array $adlists, array $staticRows, array $natRows, array $rawRows, array $filterRows = []): array
{
    $definitions = trafficControlPolicyDefinitions();
    $result = [];
    foreach ($definitions as $key => $definition) {
        $matches = [];
        $expected = 1;
        if (isset($definition['url'])) {
            $matches = array_values(array_filter($adlists, static fn(array $row): bool =>
                trim((string)($row['url'] ?? '')) === $definition['url']
            ));
        } elseif ($key === 'force_dns') {
            $expected = count($definition['comments']);
            $matches = array_values(array_filter($natRows, static fn(array $row): bool =>
                in_array((string)($row['comment'] ?? ''), $definition['comments'], true)
            ));
        } elseif ($key === 'block_dot') {
            $expected = count($definition['comments']);
            $matches = array_values(array_filter($filterRows, static fn(array $row): bool =>
                in_array((string)($row['comment'] ?? ''), $definition['comments'], true)
            ));
        } elseif (isset($definition['local_reply_address'], $definition['domains'])) {
            $expected = count($definition['domains']);
            $matches = array_values(array_filter(is_array($staticRows) ? $staticRows : [], static fn($row): bool =>
                is_array($row)
                && strtoupper((string)($row['type'] ?? '')) === 'A'
                && (string)($row['comment'] ?? '') === $definition['comment']
                && (string)($row['address'] ?? '') === (string)$definition['local_reply_address']
                && in_array(strtolower((string)($row['name'] ?? '')), $definition['domains'], true)
            ));
        } elseif (isset($definition['domains'])) {
            $expected = count($definition['domains']);
            $matches = array_values(array_filter(is_array($staticRows) ? $staticRows : [], static fn($row): bool =>
                is_array($row)
                && strtoupper((string)($row['type'] ?? '')) === 'NXDOMAIN'
                && (string)($row['comment'] ?? '') === $definition['comment']
                && in_array(strtolower((string)($row['name'] ?? '')), $definition['domains'], true)
            ));
        } else {
            $matches = array_values(array_filter($rawRows, static fn(array $row): bool =>
                (string)($row['comment'] ?? '') === $definition['comment']
            ));
        }
        $enabledCount = count(array_filter($matches, static fn(array $row): bool => !trafficControlDisabled($row)));
        $result[$key] = [
            'key' => $key,
            'label' => $definition['label'],
            'section' => $definition['section'],
            'description' => $definition['description'],
            'configured' => count($matches) === $expected,
            'enabled' => count($matches) === $expected && $enabledCount === $expected,
            'partial' => count($matches) > 0 && ($enabledCount !== $expected || count($matches) !== $expected),
            'configured_items' => count($matches),
            'expected_items' => $expected,
            // Volontairement verrouillée après 3 régressions réelles constatées sur de vrais appareils
            // (page admin au lieu du portail, puis faux-positif « internet disponible » sous Windows) :
            // voir docs/KNOWN_ISSUES.md. Ne pas rendre modifiable sans test exhaustif sur un vrai poste
            // Windows au préalable.
            'mutable' => !isset($definition['url']) && $key !== 'connectivity_local_reply',
        ];
    }
    return $result;
}

function trafficControlDomain(string $value): string
{
    $domain = strtolower(rtrim(trim($value), '.'));
    if ($domain === '' || strlen($domain) > 253
        || !preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $domain)) {
        throw new InvalidArgumentException('Nom de domaine invalide.');
    }
    return $domain;
}

function trafficControlDnsRuleMatchesDomain(array $row, string $domain): bool
{
    $ruleDomain = strtolower(rtrim(trim((string)($row['name'] ?? '')), '.'));
    if ($ruleDomain === '') return false;
    return $domain === $ruleDomain
        || (mikrotikApiBoolean($row['match-subdomain'] ?? false) && str_ends_with($domain, '.' . $ruleDomain));
}

function trafficControlDnsRulesOverlap(array $left, array $right): bool
{
    $leftDomain = strtolower(rtrim(trim((string)($left['name'] ?? '')), '.'));
    $rightDomain = strtolower(rtrim(trim((string)($right['name'] ?? '')), '.'));
    if ($leftDomain === '' || $rightDomain === '') return false;
    return trafficControlDnsRuleMatchesDomain($left, $rightDomain)
        || trafficControlDnsRuleMatchesDomain($right, $leftDomain);
}

function trafficControlRecord(PDO $pdo, array $device, string $type, string $summary, array $details): void
{
    recordOperationHistory($pdo, [
        'operation_scope' => 'admin',
        'operation_type' => $type,
        'actor_username' => (string)($_SESSION['username'] ?? ''),
        'actor_role' => (string)($_SESSION['user_role'] ?? 'admin'),
        'target_type' => 'mikrotik_traffic_control',
        'target_name' => getDeviceDisplayLabel($device),
        'target_ref' => (string)($device['id'] ?? ''),
        'device_id' => (string)($device['id'] ?? ''),
        'summary' => $summary,
        'details_json' => $details,
    ]);
}

function trafficControlRows(RouterosAPI $api, string $path, array $parameters = []): array
{
    $rows = $api->comm($path, $parameters);
    mikrotikAssertNoTrap($rows, 'RouterOS a refusé la lecture ' . $path . '.');
    return array_values(array_filter(is_array($rows) ? $rows : [], 'is_array'));
}

function trafficControlSetDisabled(RouterosAPI $api, string $path, array $rows, bool $disabled): void
{
    foreach ($rows as $row) {
        $id = trim((string)($row['.id'] ?? ''));
        if ($id === '') continue;
        mikrotikAssertNoTrap($api->comm($path . '/set', [
            '.id' => $id,
            'disabled' => $disabled ? 'yes' : 'no',
        ]), 'RouterOS a refusé la modification de ' . $path . '.');
    }
}

function trafficControlEnsureDnsBlock(RouterosAPI $api, string $domain, string $comment, bool $enabled): void
{
    $rows = trafficControlRows($api, '/ip/dns/static/print', [
        '?name' => $domain,
        '?comment' => $comment,
        '.proplist' => '.id',
    ]);
    $payload = [
        'name' => $domain,
        'type' => 'NXDOMAIN',
        'match-subdomain' => 'yes',
        'ttl' => '1h',
        'disabled' => $enabled ? 'no' : 'yes',
        'comment' => $comment,
    ];
    if ($rows !== []) {
        $payload['.id'] = (string)$rows[0]['.id'];
        mikrotikAssertNoTrap($api->comm('/ip/dns/static/set', $payload), 'Modification DNS refusée pour ' . $domain . '.');
        return;
    }
    mikrotikAssertNoTrap($api->comm('/ip/dns/static/add', $payload), 'Ajout DNS refusé pour ' . $domain . '.');
}

function trafficControlEnsureDnsLocalReply(RouterosAPI $api, string $domain, string $address, string $comment, bool $enabled): void
{
    $rows = trafficControlRows($api, '/ip/dns/static/print', [
        '?name' => $domain,
        '?comment' => $comment,
        '.proplist' => '.id',
    ]);
    $payload = [
        'name' => $domain,
        'type' => 'A',
        'address' => $address,
        'match-subdomain' => 'no',
        'ttl' => '5m',
        'disabled' => $enabled ? 'no' : 'yes',
        'comment' => $comment,
    ];
    if ($rows !== []) {
        $payload['.id'] = (string)$rows[0]['.id'];
        mikrotikAssertNoTrap($api->comm('/ip/dns/static/set', $payload), 'Modification DNS locale refusée pour ' . $domain . '.');
        return;
    }
    if (!$enabled) return;
    mikrotikAssertNoTrap($api->comm('/ip/dns/static/add', $payload), 'Ajout DNS local refusé pour ' . $domain . '.');
}

function trafficControlHotspotScope(RouterosAPI $api): array
{
    $interfaceRows = trafficControlRows($api, '/interface/print', ['.proplist' => 'name,type,disabled']);
    $bridgePortRows = trafficControlRows($api, '/interface/bridge/port/print', ['.proplist' => 'interface,bridge,disabled']);
    $hotspotRows = trafficControlRows($api, '/ip/hotspot/print', ['.proplist' => 'interface,disabled,invalid']);

    $interfaceTypes = [];
    foreach ($interfaceRows as $row) {
        if (mikrotikApiBoolean($row['disabled'] ?? false)) continue;
        $name = trim((string)($row['name'] ?? ''));
        if ($name !== '') $interfaceTypes[$name] = (string)($row['type'] ?? '');
    }
    $portBridges = [];
    foreach ($bridgePortRows as $row) {
        if (mikrotikApiBoolean($row['disabled'] ?? false)) continue;
        $port = trim((string)($row['interface'] ?? ''));
        $bridge = trim((string)($row['bridge'] ?? ''));
        if ($port !== '' && $bridge !== '') $portBridges[$port] = $bridge;
    }
    $scopes = [];
    foreach ($hotspotRows as $row) {
        if (mikrotikApiBoolean($row['disabled'] ?? false) || mikrotikApiBoolean($row['invalid'] ?? false)) continue;
        $hotspotInterface = trim((string)($row['interface'] ?? ''));
        if ($hotspotInterface === '' || !isset($interfaceTypes[$hotspotInterface])) {
            throw new RuntimeException('Portée Hotspot invalide : interface active absente ou désactivée.');
        }
        $effectiveInterface = ($interfaceTypes[$hotspotInterface] ?? '') === 'bridge'
            ? $hotspotInterface
            : ($portBridges[$hotspotInterface] ?? $hotspotInterface);
        $scopes[$effectiveInterface] = true;
    }
    if (count($scopes) !== 1) {
        throw new RuntimeException('Portée Hotspot absente ou ambiguë : une unique interface Hotspot active est requise sur ce routeur.');
    }
    $interface = (string)array_key_first($scopes);
    $addressRows = trafficControlRows($api, '/ip/address/print', [
        '?interface' => $interface,
        '.proplist' => 'address,network,interface,disabled,invalid',
    ]);
    $subnets = [];
    foreach ($addressRows as $row) {
        if (mikrotikApiBoolean($row['disabled'] ?? false) || mikrotikApiBoolean($row['invalid'] ?? false)) continue;
        $address = trim((string)($row['address'] ?? ''));
        $network = trim((string)($row['network'] ?? ''));
        if (!preg_match('/^(?:\d{1,3}\.){3}\d{1,3}\/(\d|[12]\d|3[0-2])$/', $address, $match)) continue;
        if (filter_var($network, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) continue;
        $subnets[$network . '/' . $match[1]] = true;
    }
    if (count($subnets) !== 1) {
        throw new RuntimeException('Portée Hotspot absente ou ambiguë : une unique adresse IPv4 est requise sur l interface ' . $interface . '.');
    }

    return ['interface' => $interface, 'subnet' => (string)array_key_first($subnets)];
}

function trafficControlApplyPolicy(RouterosAPI $api, string $key, bool $enabled): void
{
    $definitions = trafficControlPolicyDefinitions();
    if (!isset($definitions[$key])) {
        throw new InvalidArgumentException('Politique inconnue.');
    }
    $definition = $definitions[$key];
    if (isset($definition['url'])) {
        throw new RuntimeException('Les Adlists externes sont en lecture seule dans Radius Manager.');
    }
    if ($key === 'force_dns') {
        $scope = trafficControlHotspotScope($api);
        foreach (['udp', 'tcp'] as $protocol) {
            $comment = 'Radius Manager - forcer DNS clients ' . strtoupper($protocol);
            $rows = trafficControlRows($api, '/ip/firewall/nat/print', [
                '?comment' => $comment,
                '.proplist' => '.id',
            ]);
            $payload = [
                'chain' => 'dstnat',
                'action' => 'redirect',
                'src-address' => $scope['subnet'],
                'protocol' => $protocol,
                'dst-port' => '53',
                'to-ports' => '53',
                'disabled' => $enabled ? 'no' : 'yes',
                'comment' => $comment,
            ];
            if ($rows === []) {
                if (!$enabled) continue;
                $allNat = trafficControlRows($api, '/ip/firewall/nat/print', ['.proplist' => '.id']);
                if (isset($allNat[0]['.id'])) $payload['place-before'] = (string)$allNat[0]['.id'];
                mikrotikAssertNoTrap($api->comm('/ip/firewall/nat/add', $payload), 'Ajout de la redirection DNS refusé.');
            } else {
                $payload['.id'] = (string)$rows[0]['.id'];
                mikrotikAssertNoTrap($api->comm('/ip/firewall/nat/set', $payload), 'Mise à jour de la redirection DNS refusée.');
            }
        }
        return;
    }
    if ($key === 'block_dot') {
        $anchor = trafficControlRows($api, '/ip/firewall/filter/print', [
            '?comment' => 'Block Direct Outbound Plain DNS (UDP)',
            '.proplist' => '.id',
        ]);
        foreach (['tcp' => 0, 'udp' => 1] as $protocol => $index) {
            $comment = $definition['comments'][$index];
            $rows = trafficControlRows($api, '/ip/firewall/filter/print', [
                '?comment' => $comment,
                '.proplist' => '.id',
            ]);
            $payload = [
                'chain' => 'forward',
                'action' => 'drop',
                'protocol' => $protocol,
                'dst-port' => '853',
                'disabled' => $enabled ? 'no' : 'yes',
                'comment' => $comment,
            ];
            if ($rows === []) {
                if (!$enabled) continue;
                if (isset($anchor[0]['.id'])) $payload['place-before'] = (string)$anchor[0]['.id'];
                mikrotikAssertNoTrap($api->comm('/ip/firewall/filter/add', $payload), 'Ajout du blocage DNS chiffré 853 refusé.');
            } else {
                $payload['.id'] = (string)$rows[0]['.id'];
                mikrotikAssertNoTrap($api->comm('/ip/firewall/filter/set', $payload), 'Mise à jour du blocage DNS chiffré 853 refusée.');
            }
        }
        return;
    }
    if (isset($definition['local_reply_address'], $definition['domains'])) {
        foreach ($definition['domains'] as $domain) {
            trafficControlEnsureDnsLocalReply($api, $domain, (string)$definition['local_reply_address'], $definition['comment'], $enabled);
        }
        trafficControlFlushDnsCache($api);
        return;
    }
    if (isset($definition['domains'])) {
        foreach ($definition['domains'] as $domain) {
            trafficControlEnsureDnsBlock($api, $domain, $definition['comment'], $enabled);
        }
        trafficControlFlushDnsCache($api);
        return;
    }
    $scope = trafficControlHotspotScope($api);
    $rows = trafficControlRows($api, '/ip/firewall/raw/print', [
        '?comment' => $definition['comment'],
        '.proplist' => '.id',
    ]);
    $payload = [
        'chain' => 'prerouting',
        'action' => 'drop',
        'in-interface' => $scope['interface'],
        'src-address' => $scope['subnet'],
        'dst-address-type' => '!local',
        'protocol' => 'tcp',
        'dst-port' => '22,23',
        'disabled' => $enabled ? 'no' : 'yes',
        'comment' => $definition['comment'],
    ];
    if ($rows === []) {
        if (!$enabled) return;
        mikrotikAssertNoTrap($api->comm('/ip/firewall/raw/add', $payload), 'Ajout du blocage TCP/22-23 refusé.');
    } else {
        $payload['.id'] = (string)$rows[0]['.id'];
        mikrotikAssertNoTrap($api->comm('/ip/firewall/raw/set', $payload), 'Mise à jour du blocage TCP/22-23 refusée.');
    }
}

$api = null;
try {
    $api = connectToMikrotikApiByDevice($device);
    $action = trim((string)($input['action'] ?? 'status'));

    if ($method === 'GET' || $action === 'status') {
        $state = trafficControlState($api, $pdo, $device);
        trafficControlReply(['success' => true, 'state' => $state]);
    }

    if ($action === 'check_domain') {
        $domain = trafficControlDomain((string)($input['domain'] ?? ''));
        $staticRows = trafficControlRows($api, '/ip/dns/static/print', [
            '.proplist' => '.id,name,type,match-subdomain,disabled,comment',
        ]);
        $allowedMatches = [];
        $blockedMatches = [];
        foreach ($staticRows as $row) {
            if (trafficControlDisabled($row)) continue;
            if (!trafficControlDnsRuleMatchesDomain($row, $domain)) continue;
            if (strtoupper((string)($row['type'] ?? '')) === 'FWD'
                && (string)($row['comment'] ?? '') === trafficControlWhitelistComment()) {
                $allowedMatches[] = $row;
            }
            if (trafficControlIsManagedDnsBlock($row)) $blockedMatches[] = $row;
        }
        $status = 'unmatched';
        $source = 'routeros';
        if ($allowedMatches !== [] && $blockedMatches !== []) {
            $status = 'conflict';
            $source = 'dns_static_managed';
        } elseif ($blockedMatches !== []) {
            $status = 'blocked';
            $source = 'dns_static_managed';
        } elseif ($allowedMatches !== []) {
            $status = 'allowed';
            $source = 'dns_static_managed';
        }
        $adlists = trafficControlRows($api, '/ip/dns/adlist/print', [
            '.proplist' => '.id,url,disabled,match-count,name-count',
        ]);
        if ($status === 'unmatched' && count(array_filter($adlists, static fn(array $row): bool => !trafficControlDisabled($row))) > 0) {
            $status = 'unknown';
            $source = 'adlist_not_observable';
        }
        trafficControlReply(['success' => true, 'result' => [
            'domain' => $domain,
            'status' => $status,
            'source' => $source,
            'details' => [
                'whitelist_matches' => count($allowedMatches),
                'block_matches' => count($blockedMatches),
                'enabled_adlists' => count(array_filter($adlists, static fn(array $row): bool => !trafficControlDisabled($row))),
                'adlist_domain_attribution' => 'not_observable',
            ],
        ]]);
    }

    if (($input['confirm'] ?? false) !== true) {
        throw new InvalidArgumentException('Confirmation explicite requise.');
    }

    if ($action === 'flush_dns_cache') {
        $before = trafficControlState($api)['dns'];
        trafficControlFlushDnsCache($api);
        $after = trafficControlState($api)['dns'];
        trafficControlRecord($pdo, $device, 'traffic_dns_cache_flush', 'Cache DNS MikroTik vidé', [
            'before_cache_used' => $before['cache_used'] ?? '',
            'after_cache_used' => $after['cache_used'] ?? '',
        ]);
        trafficControlReply([
            'success' => true,
            'message' => 'Cache DNS MikroTik vidé.',
            'state' => trafficControlState($api),
        ]);
    }

    if ($action === 'reload_adlists') {
        $before = trafficControlState($api)['adlists'];
        trafficControlReloadAdlists($api);
        $after = trafficControlState($api)['adlists'];
        trafficControlRecord($pdo, $device, 'traffic_adlist_reload', 'Mise à jour des Adlists demandée', [
            'before_count' => count($before),
            'after_count' => count($after),
        ]);
        trafficControlReply([
            'success' => true,
            'message' => 'Mise à jour RouterOS des Adlists demandée.',
            'state' => trafficControlState($api),
        ]);
    }

    if ($action === 'update_dns_settings') {
        $settings = is_array($input['settings'] ?? null) ? $input['settings'] : [];
        $payload = trafficControlDnsSettingsPayload($settings);
        $before = trafficControlState($api)['dns'];
        mikrotikAssertNoTrap(
            $api->comm('/ip/dns/set', $payload),
            'RouterOS a refusé la configuration DNS.'
        );
        $stateAfterSave = trafficControlState($api);
        foreach (['force_dns', 'block_dot'] as $firewallPolicy) {
            if (!empty($stateAfterSave['policies'][$firewallPolicy]['enabled'])) {
                trafficControlApplyPolicy($api, $firewallPolicy, true);
            }
        }
        $after = trafficControlState($api)['dns'];
        trafficControlRecord($pdo, $device, 'traffic_dns_settings_update', 'Configuration DNS MikroTik modifiée', [
            'before' => $before,
            'after' => $after,
        ]);
        trafficControlReply([
            'success' => true,
            'message' => 'Configuration DNS MikroTik enregistrée.',
            'state' => trafficControlState($api),
        ]);
    }

    if ($action === 'set_policy') {
        $policyKey = trim((string)($input['policy'] ?? ''));
        $enabled = !empty($input['enabled']);
        trafficControlApplyPolicy($api, $policyKey, $enabled);
        $definition = trafficControlPolicyDefinitions()[$policyKey] ?? null;
        if (!is_array($definition)) throw new InvalidArgumentException('Politique inconnue.');
        $summary = ($enabled ? 'Politique activée : ' : 'Politique désactivée : ') . $definition['label'];
        trafficControlRecord($pdo, $device, 'traffic_policy_update', $summary, [
            'policy' => $policyKey,
            'enabled' => $enabled,
        ]);
        trafficControlReply(['success' => true, 'message' => $summary . '.', 'state' => trafficControlState($api, $pdo, $device)]);
    }

    if ($action === 'update_tracking') {
        $allowed = [
            'tcp-established-timeout' => ['30m', '1h', '3h', '6h', '12h', '1d'],
            'udp-timeout' => ['10s', '15s', '30s', '1m'],
            'udp-stream-timeout' => ['30s', '1m', '3m', '5m'],
            'generic-timeout' => ['1m', '5m', '10m', '30m'],
        ];
        $requested = is_array($input['tracking'] ?? null) ? $input['tracking'] : [];
        $payload = [];
        foreach ($allowed as $field => $values) {
            $value = trim((string)($requested[$field] ?? ''));
            if (!in_array($value, $values, true)) {
                throw new InvalidArgumentException('Valeur non autorisée pour ' . $field . '.');
            }
            $payload[$field] = $value;
        }

        $before = trafficControlState($api)['tracking'];
        mikrotikAssertNoTrap(
            $api->comm('/ip/firewall/connection/tracking/set', $payload),
            'RouterOS a refusé les paramètres conntrack.'
        );
        $after = trafficControlState($api)['tracking'];
        trafficControlRecord($pdo, $device, 'traffic_tracking_update', 'Paramètres conntrack modifiés', [
            'before' => $before,
            'after' => $after,
        ]);
        trafficControlReply(['success' => true, 'message' => 'Paramètres conntrack appliqués.', 'state' => trafficControlState($api)]);
    }

    if ($action === 'update_multi_wan_source') {
        $slot = trafficControlWanSlot((string)($input['slot'] ?? ''));
        $interface = trafficControlWanInterfaceName((string)($input['interface'] ?? ''));
        $slotAssignments = trafficControlWanSlotAssignments($input['wan_slots'] ?? null);
        if (($slotAssignments[$slot] ?? '') !== $interface) {
            throw new InvalidArgumentException('Interface sélectionnée incohérente pour ' . strtoupper($slot) . '.');
        }
        $enabled = !empty($input['enabled']);
        trafficControlSetRouterInterfaceEnabled($api, $interface, $enabled);
        $config = trafficControlReadRouterosNwanConfig($api, $device);
        if (!isset($config['sources']) || !is_array($config['sources'])) $config['sources'] = [];
        $config['sources'][$slot]['enabled'] = $enabled;
        $config['sources'][$slot]['interface'] = $interface;
        $config['sources'][$slot]['router_interface_disabled'] = !$enabled;
        $config['sources'][$slot]['updated_at'] = date(DATE_ATOM);
        $applyResult = trafficControlWriteRouterosNwanConfig($api, $config, multiWanLoadUserOverrides($pdo, trim((string)($device['id'] ?? $device['host'] ?? 'active'))));
        $state = trafficControlState($api, $pdo, $device);
        trafficControlRecord($pdo, $device, 'multi_wan_source_toggle', 'Source ' . strtoupper($slot) . ' ' . ($enabled ? 'activée' : 'désactivée') . ' sur ' . $interface, ['slot' => $slot, 'interface' => $interface, 'enabled' => $enabled, 'router_interface_disabled' => !$enabled, 'nwan_apply' => $applyResult]);
        trafficControlReply(['success' => true, 'message' => 'Source ' . strtoupper($slot) . ' ' . ($enabled ? 'activée' : 'désactivée') . ' sur ' . $interface . '. Moteur N-WAN RouterOS mis à jour.', 'state' => $state, 'result' => $applyResult]);
    }

    if ($action === 'save_multi_wan_source') {
        $slot = trafficControlWanSlot((string)($input['slot'] ?? ''));
        $interface = trafficControlWanInterfaceName((string)($input['interface'] ?? ''));
        $label = trafficControlWanLabel($input['label'] ?? '');
        $weight = trafficControlWanWeight($input['weight'] ?? null);
        $slotAssignments = trafficControlWanSlotAssignments($input['wan_slots'] ?? null);
        if (($slotAssignments[$slot] ?? '') !== $interface) {
            throw new InvalidArgumentException('Interface sélectionnée incohérente pour ' . strtoupper($slot) . '.');
        }
        trafficControlAssertSafeWanInterface($api, $interface);
        $config = trafficControlReadRouterosNwanConfig($api, $device);
        if (!isset($config['sources']) || !is_array($config['sources'])) $config['sources'] = [];
        if (!isset($config['sources'][$slot]) || !is_array($config['sources'][$slot])) $config['sources'][$slot] = [];
        $config['sources'][$slot]['label'] = $label;
        $config['sources'][$slot]['interface'] = $interface;
        $config['sources'][$slot]['weight'] = $weight;
        $config['sources'][$slot]['updated_at'] = date(DATE_ATOM);
        $applyResult = trafficControlWriteRouterosNwanConfig($api, $config, multiWanLoadUserOverrides($pdo, trim((string)($device['id'] ?? $device['host'] ?? 'active'))));
        $state = trafficControlState($api, $pdo, $device);
        trafficControlRecord($pdo, $device, 'multi_wan_source_save', 'Paramètres ' . strtoupper($slot) . ' appliqués dans RouterOS', ['slot' => $slot, 'interface' => $interface, 'label' => $label, 'weight' => $weight, 'nwan_apply' => $applyResult]);
        trafficControlReply(['success' => true, 'message' => 'Poids ' . $weight . ' et paramètres de ' . strtoupper($slot) . ' appliqués dans RouterOS.', 'state' => $state, 'result' => $applyResult]);
    }

    if ($action === 'save_wan_daily_target') {
        $slot = trafficControlWanSlot((string)($input['slot'] ?? ''));
        $dailyTargetGb = filter_var($input['daily_target_gb'] ?? null, FILTER_VALIDATE_INT);
        if ($dailyTargetGb === false || $dailyTargetGb < 1 || $dailyTargetGb > 100000) {
            throw new InvalidArgumentException('Cible quotidienne WAN invalide.');
        }
        $monthlyTargetGb = filter_var($input['monthly_target_gb'] ?? null, FILTER_VALIDATE_INT);
        if ($monthlyTargetGb === false || $monthlyTargetGb < 1 || $monthlyTargetGb > 1000000) {
            throw new InvalidArgumentException('Cible mensuelle WAN invalide.');
        }
        $config = multiWanLoadConfig($device);
        if (!isset($config['sources']) || !is_array($config['sources'])) $config['sources'] = [];
        if (!isset($config['sources'][$slot]) || !is_array($config['sources'][$slot])) $config['sources'][$slot] = [];
        $config['sources'][$slot]['daily_target_gb'] = $dailyTargetGb;
        if ($monthlyTargetGb !== null) {
            $config['sources'][$slot]['monthly_target_gb'] = $monthlyTargetGb;
        }
        multiWanSaveConfig($device, $config);
        $state = trafficControlState($api, $pdo, $device);
        $message = 'Cible quotidienne de ' . strtoupper($slot) . ' fixée à ' . $dailyTargetGb . ' Go/jour.';
        if ($monthlyTargetGb !== null) {
            $message .= ' Cible mensuelle fixée à ' . $monthlyTargetGb . ' Go/mois.';
        }
        trafficControlRecord($pdo, $device, 'multi_wan_daily_target_save', 'Cibles ' . strtoupper($slot) . ' mises à jour', ['slot' => $slot, 'daily_target_gb' => $dailyTargetGb, 'monthly_target_gb' => $monthlyTargetGb]);
        trafficControlReply(['success' => true, 'message' => $message, 'state' => $state]);
    }

    if ($action === 'save_nwan_engine_mode') {
        $state = trafficControlState($api, $pdo, $device);
        trafficControlRecord($pdo, $device, 'multi_wan_engine_mode_readonly', 'Mode moteur N-WAN conservé côté RouterOS', ['engine' => $state['nwan_engine'] ?? []]);
        trafficControlReply(['success' => true, 'message' => 'Le moteur N-WAN est piloté côté RouterOS. Aucun état local PHP enregistré.', 'state' => $state]);
    }

    if ($action === 'apply_nwan_engine_config') {
        $config = trafficControlReadRouterosNwanConfig($api, $device);
        $config['engine_mode'] = 'routeros';
        $config['routeros_engine_applied_at'] = date(DATE_ATOM);
        $applyResult = trafficControlApplyNwanEngineConfig($api, $config, multiWanLoadUserOverrides($pdo, trim((string)($device['id'] ?? $device['host'] ?? 'active'))));
        $state = trafficControlState($api, $pdo, $device);
        trafficControlRecord($pdo, $device, 'multi_wan_engine_apply', 'Moteur N-WAN RouterOS appliqué', $applyResult);
        trafficControlReply([
            'success' => true,
            'message' => 'Moteur N-WAN appliqué sur RouterOS. Les listes clients existantes ne sont pas réinitialisées.',
            'result' => $applyResult,
            'state' => $state,
        ]);
    }

    if ($action === 'save_interface_queue') {
        $state = trafficControlState($api);
        $interface = trafficControlInterfaceName($state['interface_queues']['hotspot_bridges'] ?? [], trim((string)($input['interface'] ?? '')));
        $upload = trafficControlMbps($input['upload_mbps'] ?? null, 'Le débit montant');
        $download = trafficControlMbps($input['download_mbps'] ?? null, 'Le débit descendant');
        $priority = filter_var($input['priority'] ?? null, FILTER_VALIDATE_INT);
        if ($priority === false || $priority < 1 || $priority > 8) throw new InvalidArgumentException('La priorité RouterOS doit être comprise entre 1 et 8.');
        $comment = 'Radius Manager - interface queue:' . $interface;
        $rows = trafficControlRows($api, '/queue/simple/print', ['?comment' => $comment, '.proplist' => '.id,name,target']);
        $payload = ['name' => 'RM interface ' . $interface, 'target' => $interface, 'max-limit' => $upload . '/' . $download, 'priority' => $priority . '/' . $priority, 'disabled' => 'no', 'comment' => $comment];
        if ($rows === []) {
            foreach (trafficControlRows($api, '/queue/simple/print', ['.proplist' => '.id,dynamic']) as $row) {
                if (mikrotikApiBoolean($row['dynamic'] ?? false)) { $payload['place-before'] = (string)$row['.id']; break; }
            }
            $result = $api->comm('/queue/simple/add', $payload);
            mikrotikAssertNoTrap($result, 'RouterOS a refusé la Simple Queue d interface.');
            $queueId = trim((string)($result[0]['ret'] ?? ''));
        } else {
            $queueId = (string)$rows[0]['.id'];
            $existingName = trim((string)($rows[0]['name'] ?? ''));
            if ($existingName !== '') $payload['name'] = $existingName;
            $existingTarget = trim((string)($rows[0]['target'] ?? ''));
            if ($existingTarget !== '') $payload['target'] = $existingTarget;
            $payload['.id'] = $queueId;
            mikrotikAssertNoTrap($api->comm('/queue/simple/set', $payload), 'RouterOS a refusé la modification de la Simple Queue.');
        }
        if ($queueId === '') throw new RuntimeException('Identifiant de la Simple Queue non retourné par RouterOS.');
        trafficControlEnsureQueueBeforeDynamic($api, $queueId);
        foreach (trafficControlRows($api, '/ip/hotspot/user/profile/print', ['.proplist' => '.id,rate-limit,parent-queue']) as $profile) {
            $rateLimit = trim((string)($profile['rate-limit'] ?? ''));
            if ($rateLimit === '') continue;
            $rateLimit = trafficControlHotspotRateWithoutCommittedRate($rateLimit);
            if ((string)($profile['parent-queue'] ?? '') === $payload['name'] && (string)$profile['rate-limit'] === $rateLimit) continue;
            mikrotikAssertNoTrap($api->comm('/ip/hotspot/user/profile/set', [
                '.id' => (string)$profile['.id'],
                'parent-queue' => $payload['name'],
                'rate-limit' => $rateLimit,
            ]), 'RouterOS a refusé le rattachement d un profil Hotspot au parent.');
        }
        $after = trafficControlState($api);
        $saved = array_values(array_filter($after['interface_queues']['managed'] ?? [], static fn(array $row): bool => (string)($row['comment'] ?? '') === $comment));
        if ($saved === [] || mikrotikApiBoolean($saved[0]['invalid'] ?? false)) throw new RuntimeException('La Simple Queue existe mais RouterOS la signale invalide.');
        trafficControlRecord($pdo, $device, 'interface_queue_update', 'Contrôle de débit appliqué sur ' . $interface, ['interface' => $interface, 'upload_mbps' => (float)($input['upload_mbps'] ?? 0), 'download_mbps' => (float)($input['download_mbps'] ?? 0), 'priority' => $priority]);
        $message = 'Simple Queue appliquée sur le bridge Hotspot et profils rattachés.';
        if (($after['interface_queues']['dynamic_invalid_count'] ?? 0) > 0) $message .= ' Les sessions actives doivent se reconnecter.';
        trafficControlReply(['success' => true, 'message' => $message, 'state' => $after]);
    }

    if ($action === 'toggle_interface_queue') {
        $state = trafficControlState($api);
        $interface = trafficControlInterfaceName($state['interface_queues']['hotspot_bridges'] ?? [], trim((string)($input['interface'] ?? '')));
        $comment = 'Radius Manager - interface queue:' . $interface;
        $rows = trafficControlRows($api, '/queue/simple/print', ['?comment' => $comment, '.proplist' => '.id']);
        if ($rows === []) throw new InvalidArgumentException('Aucune Simple Queue gérée pour cette interface.');
        $enabled = !empty($input['enabled']);
        mikrotikAssertNoTrap($api->comm('/queue/simple/set', ['.id' => (string)$rows[0]['.id'], 'disabled' => $enabled ? 'no' : 'yes']), 'RouterOS a refusé le changement d état de la Simple Queue.');
        trafficControlRecord($pdo, $device, 'interface_queue_toggle', 'Contrôle de débit ' . ($enabled ? 'activé' : 'désactivé') . ' sur ' . $interface, ['interface' => $interface, 'enabled' => $enabled]);
        trafficControlReply(['success' => true, 'message' => 'Contrôle de débit ' . ($enabled ? 'activé.' : 'désactivé.'), 'state' => trafficControlState($api, $pdo, $device)]);
    }

    if ($action === 'add_adlist') {
        $url = trim((string)($input['url'] ?? ''));
        $parts = parse_url($url);
        $host = strtolower((string)($parts['host'] ?? ''));
        $approvedUrls = array_values(array_filter(array_map(
            static fn(array $definition): string => (string)($definition['url'] ?? ''),
            trafficControlPolicyDefinitions()
        )));
        if (filter_var($url, FILTER_VALIDATE_URL) === false
            || ($parts['scheme'] ?? '') !== 'https'
            || $host === ''
            || isset($parts['user'], $parts['pass'], $parts['fragment'])
            || (isset($parts['port']) && (int)$parts['port'] !== 443)
            || $host === 'localhost'
            || filter_var($host, FILTER_VALIDATE_IP) !== false) {
            throw new InvalidArgumentException('Une URL HTTPS publique sans identifiants ni port personnalisé est requise.');
        }
        if (!in_array($url, $approvedUrls, true)) {
            throw new InvalidArgumentException('Cette source ne figure pas dans le catalogue approuvé.');
        }
        foreach (trafficControlRows($api, '/ip/dns/adlist/print', ['.proplist' => 'url']) as $existingAdlist) {
            if ((string)($existingAdlist['url'] ?? '') === $url) throw new InvalidArgumentException('Cette Adlist existe déjà sur le routeur.');
        }
        mikrotikAssertNoTrap($api->comm('/ip/dns/adlist/add', [
            'url' => $url,
            'ssl-verify' => 'yes',
            'disabled' => 'no',
            'comment' => 'Radius Manager - Adlist',
        ]), 'RouterOS a refusé l ajout de l Adlist.');
        trafficControlFlushDnsCache($api);
        trafficControlRecord($pdo, $device, 'traffic_adlist_add', 'Adlist ajoutée', ['url' => $url]);
        trafficControlReply(['success' => true, 'message' => 'Adlist ajoutée.', 'state' => trafficControlState($api)]);
    }

    if (in_array($action, ['toggle_adlist', 'remove_adlist'], true)) {
        $id = trim((string)($input['id'] ?? ''));
        $rows = array_values(array_filter(
            trafficControlRows($api, '/ip/dns/adlist/print', ['.proplist' => '.id,url,comment']),
            static fn(array $row): bool => (string)($row['.id'] ?? '') === $id
        ));
        if (count($rows) !== 1 || (string)($rows[0]['comment'] ?? '') !== 'Radius Manager - Adlist') {
            throw new InvalidArgumentException('Cette Adlist externe est en lecture seule.');
        }
        if ($action === 'toggle_adlist') {
            $disabled = !empty($input['disabled']);
            mikrotikAssertNoTrap($api->comm('/ip/dns/adlist/set', ['.id' => $id, 'disabled' => $disabled ? 'yes' : 'no']), 'RouterOS a refusé la modification de l Adlist.');
            $message = $disabled ? 'Adlist désactivée.' : 'Adlist activée.';
        } else {
            mikrotikAssertNoTrap($api->comm('/ip/dns/adlist/remove', ['.id' => $id]), 'RouterOS a refusé la suppression de l Adlist.');
            $message = 'Adlist supprimée.';
        }
        trafficControlFlushDnsCache($api);
        trafficControlRecord($pdo, $device, 'traffic_adlist_update', $message, ['id' => $id, 'action' => $action]);
        trafficControlReply(['success' => true, 'message' => $message, 'state' => trafficControlState($api)]);
    }

    if ($action === 'add_whitelist') {
        $domain = trafficControlDomain((string)($input['domain'] ?? ''));
        $state = trafficControlState($api);
        $candidate = ['name' => $domain, 'match-subdomain' => true];
        foreach ($state['dns_blocks'] as $row) {
            if (!trafficControlDisabled($row) && trafficControlDnsRulesOverlap($candidate, $row)) {
                throw new InvalidArgumentException('Ce domaine est actuellement bloqué. Retirez d abord le blocage.');
            }
        }
        foreach ($state['whitelist'] as $row) {
            if (trafficControlDnsRulesOverlap($candidate, $row)) {
                throw new InvalidArgumentException('Ce domaine est déjà dans la liste blanche.');
            }
        }
        mikrotikAssertNoTrap($api->comm('/ip/dns/static/add', [
            'name' => $domain,
            'type' => 'FWD',
            'match-subdomain' => 'yes',
            'disabled' => 'no',
            'comment' => trafficControlWhitelistComment(),
        ]), 'RouterOS a refusé le domaine autorisé.');
        trafficControlFlushDnsCache($api);
        trafficControlRecord($pdo, $device, 'traffic_whitelist_add', 'Domaine ajouté à la liste blanche', ['domain' => $domain]);
        trafficControlReply(['success' => true, 'message' => 'Domaine ajouté à la liste blanche.', 'state' => trafficControlState($api)]);
    }

    if ($action === 'add_dns_block') {
        $domain = trafficControlDomain((string)($input['domain'] ?? ''));
        $category = trim((string)($input['category'] ?? 'Personnalisé'));
        $allowedCategories = ['Personnalisé', 'Publicité', 'Mise à jour', 'Télémétrie'];
        if (!in_array($category, $allowedCategories, true)) {
            throw new InvalidArgumentException('Catégorie DNS non autorisée.');
        }
        $state = trafficControlState($api);
        $candidate = ['name' => $domain, 'match-subdomain' => true];
        foreach ($state['whitelist'] as $row) {
            if (!trafficControlDisabled($row) && trafficControlDnsRulesOverlap($candidate, $row)) {
                throw new InvalidArgumentException('Ce domaine est actuellement autorisé. Retirez d abord l exception.');
            }
        }
        foreach ($state['dns_blocks'] as $row) {
            if (trafficControlDnsRulesOverlap($candidate, $row)) {
                throw new InvalidArgumentException('Ce domaine est déjà bloqué.');
            }
        }
        $comment = 'Radius Manager - blocage ' . $category;
        trafficControlEnsureDnsBlock($api, $domain, $comment, true);
        trafficControlFlushDnsCache($api);
        trafficControlRecord($pdo, $device, 'traffic_dns_block_add', 'Domaine DNS bloqué', [
            'domain' => $domain,
            'category' => $category,
        ]);
        trafficControlReply(['success' => true, 'message' => 'Domaine DNS bloqué.', 'state' => trafficControlState($api)]);
    }

    if (in_array($action, ['toggle_dns_block', 'remove_dns_block'], true)) {
        $id = trim((string)($input['id'] ?? ''));
        $block = null;
        foreach (trafficControlState($api)['dns_blocks'] as $row) {
            if ((string)($row['.id'] ?? '') === $id) {
                $block = $row;
                break;
            }
        }
        if ($block === null) throw new InvalidArgumentException('Blocage DNS géré introuvable.');
        $domain = (string)($block['name'] ?? '');
        if ($action === 'toggle_dns_block') {
            $disabled = !empty($input['disabled']);
            trafficControlSetDisabled($api, '/ip/dns/static', [$block], $disabled);
            $summary = $disabled ? 'Blocage DNS désactivé' : 'Blocage DNS réactivé';
        } else {
            mikrotikAssertNoTrap($api->comm('/ip/dns/static/remove', ['.id' => $id]), 'Suppression du blocage DNS refusée.');
            $summary = 'Blocage DNS supprimé';
        }
        trafficControlFlushDnsCache($api);
        trafficControlRecord($pdo, $device, 'traffic_dns_block_update', $summary, [
            'id' => $id,
            'domain' => $domain,
        ]);
        trafficControlReply(['success' => true, 'message' => $summary . '.', 'state' => trafficControlState($api, $pdo, $device)]);
    }

    if ($action === 'remove_whitelist') {
        $id = trim((string)($input['id'] ?? ''));
        if ($id === '') {
            throw new InvalidArgumentException('Identifiant du domaine manquant.');
        }
        $domain = '';
        foreach (trafficControlState($api)['whitelist'] as $row) {
            if ((string)($row['.id'] ?? '') === $id) {
                $domain = (string)($row['name'] ?? '');
                break;
            }
        }
        if ($domain === '') {
            throw new InvalidArgumentException('Domaine autorisé introuvable sur ce routeur.');
        }
        mikrotikAssertNoTrap($api->comm('/ip/dns/static/remove', ['.id' => $id]), 'RouterOS a refusé la suppression du domaine.');
        trafficControlFlushDnsCache($api);
        trafficControlRecord($pdo, $device, 'traffic_whitelist_remove', 'Domaine retiré de la liste blanche', ['id' => $id, 'domain' => $domain]);
        trafficControlReply(['success' => true, 'message' => 'Domaine retiré de la liste blanche.', 'state' => trafficControlState($api)]);
    }

    trafficControlReply(['success' => false, 'message' => 'Action inconnue.'], 400);
} catch (InvalidArgumentException $e) {
    trafficControlReply(['success' => false, 'message' => $e->getMessage()], 422);
} catch (Throwable $e) {
    trafficControlReply(['success' => false, 'message' => $e->getMessage()], 500);
} finally {
    if ($api instanceof RouterosAPI) {
        $api->disconnect();
    }
}
