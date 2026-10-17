<?php

function multiWanConfigKey(array $device): string
{
    return md5(trim((string)($device['id'] ?? $device['host'] ?? 'active')) ?: 'active');
}

function multiWanConfigPath(array $device): string
{
    $dir = __DIR__ . '/../config/multi_wan';
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
    return $dir . '/radius_manager_multi_wan_' . multiWanConfigKey($device) . '.json';
}

function multiWanLegacyConfigPath(array $device): string
{
    return sys_get_temp_dir() . '/radius_manager_multi_wan_' . multiWanConfigKey($device) . '.json';
}

function multiWanDefaultSources(): array
{
    return [
        'wan1' => ['label' => 'Starlink 1 · principale', 'interface' => 'ether1', 'weight' => 20, 'enabled' => true, 'daily_target_gb' => 0, 'monthly_target_gb' => 0],
        'wan2' => ['label' => 'Starlink 2 · secondaire', 'interface' => 'ether2', 'weight' => 80, 'enabled' => true, 'daily_target_gb' => 0, 'monthly_target_gb' => 0],
        'wan3' => ['label' => 'Réserve 3', 'interface' => 'ether3', 'weight' => 0, 'enabled' => false, 'daily_target_gb' => 0, 'monthly_target_gb' => 0],
        'wan4' => ['label' => 'Réserve 4', 'interface' => 'ether4', 'weight' => 0, 'enabled' => false, 'daily_target_gb' => 0, 'monthly_target_gb' => 0],
    ];
}

function multiWanLoadConfig(array $device): array
{
    $defaults = ['engine_mode' => 'server_cron', 'monthly_target_gb' => 4096, 'sources' => multiWanDefaultSources()];
    $path = multiWanConfigPath($device);
    $legacyPath = multiWanLegacyConfigPath($device);
    if (!is_file($path) && is_file($legacyPath)) {
        @copy($legacyPath, $path);
    }
    if (!is_file($path)) return $defaults;
    $raw = json_decode((string)file_get_contents($path), true);
    if (!is_array($raw)) return $defaults;
    $merged = array_replace_recursive($defaults, $raw);
    foreach (multiWanDefaultSources() as $slot => $source) {
        if (!isset($merged['sources'][$slot]) || !is_array($merged['sources'][$slot])) {
            $merged['sources'][$slot] = $source;
        }
    }
    $merged['engine_mode'] = multiWanNormalizeEngineMode($merged['engine_mode'] ?? 'server_cron');
    $merged['monthly_target_gb'] = multiWanNormalizeCapacity($merged['monthly_target_gb'] ?? 4096, 1000000);
    return $merged;
}

function multiWanSaveConfig(array $device, array $config): void
{
    $path = multiWanConfigPath($device);
    $encoded = json_encode($config, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    if ($encoded === false || file_put_contents($path, $encoded, LOCK_EX) === false) {
        throw new RuntimeException('Sauvegarde de la configuration multi-WAN impossible.');
    }
}

function multiWanNormalizeCapacity(mixed $value, int $max = 100000): int
{
    $capacity = filter_var($value, FILTER_VALIDATE_INT);
    return $capacity === false ? 0 : max(0, min($max, (int)$capacity));
}

function multiWanNormalizeEngineMode(mixed $value): string
{
    $mode = trim((string)$value);
    return in_array($mode, ['server_cron', 'routeros'], true) ? $mode : 'server_cron';
}

function multiWanConfigInterfaces(array $config): array
{
    $interfaces = [];
    foreach (($config['sources'] ?? []) as $slot => $source) {
        if (!is_array($source)) continue;
        $interfaces[$slot] = trim((string)($source['interface'] ?? ''));
    }
    return $interfaces;
}

function multiWanConfigWeights(array $config): array
{
    $weights = [];
    foreach (($config['sources'] ?? []) as $slot => $source) {
        if (!is_array($source)) continue;
        $enabled = array_key_exists('enabled', $source) ? (bool)$source['enabled'] : true;
        $weight = filter_var($source['weight'] ?? 0, FILTER_VALIDATE_INT);
        $weights[$slot] = $enabled && $weight !== false ? max(0, min(100, (int)$weight)) : 0;
        // Nom d'interface RouterOS : utilisé par la règle de connexion pour exclure un WAN HS. Vide si invalide (WAN exclu).
        $interface = trim((string)($source['interface'] ?? ''));
        $weights[$slot . '_interface'] = preg_match('/^[A-Za-z0-9_.-]+$/', $interface) ? $interface : '';
    }
    return $weights;
}

function multiWanConfigWanOptions(array $config): array
{
    $options = [];
    foreach (['wan1' => 'WAN1', 'wan2' => 'WAN2', 'wan3' => 'WAN3', 'wan4' => 'WAN4'] as $slot => $wanId) {
        $source = is_array($config['sources'][$slot] ?? null) ? $config['sources'][$slot] : [];
        $enabled = array_key_exists('enabled', $source) ? (bool)$source['enabled'] : true;
        $weight = filter_var($source['weight'] ?? 0, FILTER_VALIDATE_INT);
        $interface = trim((string)($source['interface'] ?? ''));
        $selectable = $enabled && $weight !== false && (int)$weight > 0 && $interface !== '';
        $options[] = [
            'wan_id' => $wanId,
            'slot' => $slot,
            'label' => trim((string)($source['label'] ?? $wanId)) ?: $wanId,
            'interface' => $interface,
            'weight' => $weight === false ? 0 : max(0, min(100, (int)$weight)),
            'selectable' => $selectable,
            'reason' => $selectable ? '' : 'Source WAN inactive',
        ];
    }
    return $options;
}

function multiWanRouterosBoolean(bool $value): string
{
    return $value ? 'true' : 'false';
}

function multiWanRouterosQuote(string $value): string
{
    return '"' . str_replace(['\\', '"'], ['\\\\', '\"'], $value) . '"';
}

function multiWanRouterosScriptString(string $value): string
{
    return trim($value);
}

function multiWanParseRouterosAssignScript(string $source, array $fallback = []): array
{
    $config = array_replace_recursive(['engine_mode' => 'routeros', 'monthly_target_gb' => 4096, 'sources' => multiWanDefaultSources()], $fallback ?: []);
    $config['engine_mode'] = 'routeros';
    foreach (['wan1', 'wan2', 'wan3', 'wan4'] as $slot) {
        $number = substr($slot, 3, 1);
        if (preg_match('/:\s*local\s+wan' . $number . 'Enabled\s+(true|false)\s*;/i', $source, $m)) {
            $config['sources'][$slot]['enabled'] = strtolower($m[1]) === 'true';
        }
        if (preg_match('/:\s*local\s+wan' . $number . 'Weight\s+([0-9]+)\s*;/i', $source, $m)) {
            $config['sources'][$slot]['weight'] = max(0, min(100, (int)$m[1]));
        }
        foreach ([
            'Interface' => 'interface',
            'Label' => 'label',
        ] as $routerKey => $configKey) {
            $pattern = '/:\s*local\s+wan' . $number . $routerKey . '\s+"([^"]*)"\s*;/i';
            if (preg_match($pattern, $source, $m)) {
                $config['sources'][$slot][$configKey] = stripcslashes($m[1]);
            }
        }
    }
    return $config;
}

function multiWanBuildRouterosAssignScript(array $config, array $userOverrides = []): string
{
    $weights = multiWanConfigWeights($config);
    $sources = is_array($config['sources'] ?? null) ? $config['sources'] : [];
    $wan1Weight = (int)($weights['wan1'] ?? 0);
    $wan2Weight = (int)($weights['wan2'] ?? 0);
    $wan3Weight = (int)($weights['wan3'] ?? 0);
    $wan4Weight = (int)($weights['wan4'] ?? 0);
    $wan1Enabled = !empty($sources['wan1']['enabled']) && $wan1Weight > 0;
    $wan2Enabled = !empty($sources['wan2']['enabled']) && $wan2Weight > 0;
    $wan3Enabled = !empty($sources['wan3']['enabled']) && $wan3Weight > 0;
    $wan4Enabled = !empty($sources['wan4']['enabled']) && $wan4Weight > 0;
    $overrideLines = [];
    foreach ($userOverrides as $username => $override) {
        $username = trim((string)$username);
        $wanId = strtoupper(trim((string)($override['wan_id'] ?? '')));
        if ($username === '' || !in_array($wanId, ['WAN1', 'WAN2', 'WAN3', 'WAN4'], true)) continue;
        $wanNumber = substr($wanId, 3, 1);
        $overrideLines[] = ':if (($user = ' . multiWanRouterosQuote($username) . ') && ($wan' . $wanNumber . 'Enabled = true)) do={ :set targetList $wan' . $wanNumber . 'List; };';
    }

    return implode(' ', [
        ':local wan1List "WAN1_CLIENTS";',
        ':local wan2List "WAN2_CLIENTS";',
        ':local wan3List "WAN3_CLIENTS";',
        ':local wan4List "WAN4_CLIENTS";',
        ':local managedComment "RM-NWAN";',
        ':local wan1Interface ' . multiWanRouterosQuote((string)($sources['wan1']['interface'] ?? 'ether1')) . ';',
        ':local wan2Interface ' . multiWanRouterosQuote((string)($sources['wan2']['interface'] ?? 'ether2')) . ';',
        ':local wan3Interface ' . multiWanRouterosQuote((string)($sources['wan3']['interface'] ?? 'ether3')) . ';',
        ':local wan4Interface ' . multiWanRouterosQuote((string)($sources['wan4']['interface'] ?? 'ether4')) . ';',
        ':local wan1Label ' . multiWanRouterosQuote((string)($sources['wan1']['label'] ?? 'WAN1')) . ';',
        ':local wan2Label ' . multiWanRouterosQuote((string)($sources['wan2']['label'] ?? 'WAN2')) . ';',
        ':local wan3Label ' . multiWanRouterosQuote((string)($sources['wan3']['label'] ?? 'WAN3')) . ';',
        ':local wan4Label ' . multiWanRouterosQuote((string)($sources['wan4']['label'] ?? 'WAN4')) . ';',
        ':local wan1Enabled ' . multiWanRouterosBoolean($wan1Enabled) . ';',
        ':local wan2Enabled ' . multiWanRouterosBoolean($wan2Enabled) . ';',
        ':local wan3Enabled ' . multiWanRouterosBoolean($wan3Enabled) . ';',
        ':local wan4Enabled ' . multiWanRouterosBoolean($wan4Enabled) . ';',
        ':local wan1Weight ' . $wan1Weight . ';',
        ':local wan2Weight ' . $wan2Weight . ';',
        ':local wan3Weight ' . $wan3Weight . ';',
        ':local wan4Weight ' . $wan4Weight . ';',
        ':global rmNwanW1 ' . $wan1Weight . '; :global rmNwanW2 ' . $wan2Weight . ';',
        ':local onlyIp "";',
        ':global rmNwanOnlyIp;',
        ':if ([:typeof $rmNwanOnlyIp] = "str") do={ :set onlyIp $rmNwanOnlyIp; };',
        '/ip firewall address-list; :foreach e in=[find] do={',
        ':local list [get $e list];',
        ':if ((($list = $wan1List) || ($list = $wan2List) || ($list = $wan3List) || ($list = $wan4List)) && ($onlyIp = "")) do={',
        ':local ip [get $e address];',
        ':local active false;',
        ':foreach s in=[/ip hotspot active find] do={ :if ([/ip hotspot active get $s address] = $ip) do={ :set active true; }; };',
        ':if ($active = false) do={ remove $e; };',
        '};',
        '};',
        ':local wan1Count 0;',
        ':local wan2Count 0;',
        ':local wan3Count 0;',
        ':local wan4Count 0;',
        '/ip firewall address-list; :foreach e in=[find where list=$wan1List] do={ :set wan1Count ($wan1Count + 1); };',
        '/ip firewall address-list; :foreach e in=[find where list=$wan2List] do={ :set wan2Count ($wan2Count + 1); };',
        '/ip firewall address-list; :foreach e in=[find where list=$wan3List] do={ :set wan3Count ($wan3Count + 1); };',
        '/ip firewall address-list; :foreach e in=[find where list=$wan4List] do={ :set wan4Count ($wan4Count + 1); };',
        ':foreach s in=[/ip hotspot active find] do={',
        ':local ip [/ip hotspot active get $s address];',
        ':local user [/ip hotspot active get $s user];',
        ':if (([:len $ip] > 0) && (($onlyIp = "") || ($ip = $onlyIp))) do={',
        ':local targetList "";',
        ...$overrideLines,
        ':if ($targetList = "") do={',
        '/ip firewall address-list; :if ([:len [find where list=$wan1List address=$ip]] > 0) do={ :set targetList $wan1List; };',
        '/ip firewall address-list; :if ([:len [find where list=$wan2List address=$ip]] > 0) do={ :set targetList $wan2List; };',
        '/ip firewall address-list; :if ([:len [find where list=$wan3List address=$ip]] > 0) do={ :set targetList $wan3List; };',
        '/ip firewall address-list; :if ([:len [find where list=$wan4List address=$ip]] > 0) do={ :set targetList $wan4List; };',
        '};',
        ':if ($targetList = "") do={',
        ':local bestScore 2147483647;',
        ':if (($wan1Enabled = true) && ($wan1Weight > 0)) do={ :local s (($wan1Count * 1000) / $wan1Weight); :if ($s < $bestScore) do={ :set bestScore $s; :set targetList $wan1List; }; };',
        ':if (($wan2Enabled = true) && ($wan2Weight > 0)) do={ :local s (($wan2Count * 1000) / $wan2Weight); :if ($s < $bestScore) do={ :set bestScore $s; :set targetList $wan2List; }; };',
        ':if (($wan3Enabled = true) && ($wan3Weight > 0)) do={ :local s (($wan3Count * 1000) / $wan3Weight); :if ($s < $bestScore) do={ :set bestScore $s; :set targetList $wan3List; }; };',
        ':if (($wan4Enabled = true) && ($wan4Weight > 0)) do={ :local s (($wan4Count * 1000) / $wan4Weight); :if ($s < $bestScore) do={ :set bestScore $s; :set targetList $wan4List; }; };',
        '};',
        ':if ($targetList != "") do={',
        '/ip firewall address-list; :foreach e in=[find where address=$ip] do={',
        ':local l [/ip firewall address-list get $e list];',
        ':if ((($l = $wan1List) || ($l = $wan2List) || ($l = $wan3List) || ($l = $wan4List)) && ($l != $targetList)) do={ remove $e; };',
        '};',
        '/ip firewall address-list; :if ([:len [find where list=$targetList address=$ip]] = 0) do={',
        'add list=$targetList address=$ip comment=$managedComment;',
        ':if ($targetList = $wan1List) do={ :set wan1Count ($wan1Count + 1); };',
        ':if ($targetList = $wan2List) do={ :set wan2Count ($wan2Count + 1); };',
        ':if ($targetList = $wan3List) do={ :set wan3Count ($wan3Count + 1); };',
        ':if ($targetList = $wan4List) do={ :set wan4Count ($wan4Count + 1); };',
        '} else={ :foreach e in=[find where list=$targetList address=$ip] do={ remove $e; }; add list=$targetList address=$ip comment=$managedComment; };',
        '};',
        '};',
        '};',
    ]);
}
