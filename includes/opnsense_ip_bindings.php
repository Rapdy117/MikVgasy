<?php

require_once __DIR__ . '/opnsense_shaper.php';

function normalizeMacAddress(string $macAddress): string
{
    $normalized = strtoupper(trim($macAddress));
    $normalized = preg_replace('/[^0-9A-F]/', '', $normalized) ?? $normalized;

    if (strlen($normalized) !== 12) {
        return strtoupper(trim($macAddress));
    }

    return implode(':', str_split($normalized, 2));
}

function isValidMacAddress(string $macAddress): bool
{
    return (bool)preg_match('/^[0-9A-F]{2}(?::[0-9A-F]{2}){5}$/', normalizeMacAddress($macAddress));
}

function normalizeOpnsenseAllowedAddress(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return '';
    }

    if (strpos($value, '/') !== false) {
        [$ip, $prefix] = array_pad(explode('/', $value, 2), 2, '');
        $ip = trim($ip);
        $prefix = trim($prefix);
        if ($ip === '' || $prefix === '' || !ctype_digit($prefix)) {
            return $value;
        }

        return normalizeOpnsenseAllowedAddress($ip) . '/' . $prefix;
    }

    $packed = @inet_pton($value);
    if ($packed === false) {
        return $value;
    }

    return inet_ntop($packed) ?: $value;
}

function isValidOpnsenseAllowedAddress(string $value): bool
{
    $value = trim($value);
    if ($value === '') {
        return false;
    }

    if (strpos($value, '/') !== false) {
        [$ip, $prefix] = array_pad(explode('/', $value, 2), 2, '');
        $ip = trim($ip);
        $prefix = trim($prefix);
        if ($ip === '' || $prefix === '' || !ctype_digit($prefix)) {
            return false;
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return (int)$prefix >= 0 && (int)$prefix <= 32;
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            return (int)$prefix >= 0 && (int)$prefix <= 128;
        }

        return false;
    }

    return filter_var($value, FILTER_VALIDATE_IP) !== false;
}

function detectOpnsenseBindingKind(string $value): string
{
    if (isValidMacAddress($value)) {
        return 'mac';
    }

    if (isValidOpnsenseAllowedAddress($value)) {
        return 'address';
    }

    return '';
}

function opnsenseCaptivePortalReconfigure(array $device): void
{
    $response = opnsenseApiRequest($device, '/api/captiveportal/service/reconfigure', 'POST');
    if (!($response['success'] ?? false)) {
        throw new RuntimeException((string)($response['message'] ?? 'Reconfiguration captive portal impossible.'));
    }
}

function listOpnsenseCaptivePortalZones(array $device): array
{
    $response = opnsenseApiRequest($device, '/api/captiveportal/settings/searchZones');
    if (!($response['success'] ?? false)) {
        throw new RuntimeException((string)($response['message'] ?? 'Lecture des zones captive portal impossible.'));
    }

    $rows = $response['data']['rows'] ?? [];
    if (!is_array($rows)) {
        return [];
    }

    $zones = [];
    foreach ($rows as $row) {
        $uuid = trim((string)($row['uuid'] ?? ''));
        if ($uuid === '') {
            continue;
        }

        $zones[] = [
            'uuid' => $uuid,
            'zoneid' => (string)($row['zoneid'] ?? ''),
            'description' => trim((string)($row['description'] ?? '')) ?: 'Zone ' . (string)($row['zoneid'] ?? ''),
            'interfaces' => trim((string)($row['interfaces'] ?? '')),
            'enabled' => (string)($row['enabled'] ?? '1') === '1',
            'raw' => $row,
        ];
    }

    return $zones;
}

function getOpnsenseCaptivePortalZone(string $zoneUuid, array $device): array
{
    $response = opnsenseApiRequest($device, '/api/captiveportal/settings/getZone/' . rawurlencode($zoneUuid));
    if (!($response['success'] ?? false)) {
        throw new RuntimeException((string)($response['message'] ?? 'Lecture de la zone captive portal impossible.'));
    }

    $zone = $response['data']['zone'] ?? null;
    if (!is_array($zone)) {
        throw new RuntimeException('Zone captive portal introuvable.');
    }

    return $zone;
}

function getOpnsenseCaptivePortalZoneSummary(string $zoneUuid, array $device): array
{
    foreach (listOpnsenseCaptivePortalZones($device) as $zone) {
        if ((string)($zone['uuid'] ?? '') === $zoneUuid) {
            return $zone;
        }
    }

    throw new RuntimeException('Zone captive portal introuvable.');
}

function extractOpnsenseListValues($field): array
{
    if (is_string($field)) {
        $parts = preg_split('/[\s,;]+/', $field) ?: [];
        return array_values(array_filter(array_map('trim', $parts), static fn($value) => $value !== ''));
    }

    if (!is_array($field)) {
        return [];
    }

    $values = [];
    foreach ($field as $key => $entry) {
        if (is_array($entry)) {
            $selected = (string)($entry['selected'] ?? '1');
            $value = trim((string)($entry['value'] ?? (is_string($key) ? $key : '')));
            if ($value !== '' && $selected !== '0') {
                $values[] = $value;
            }
            continue;
        }

        $value = trim((string)$entry);
        if ($value !== '') {
            $values[] = $value;
        }
    }

    return array_values(array_unique($values));
}

function listOpnsenseIpBindings(array $device): array
{
    $zones = listOpnsenseCaptivePortalZones($device);
    $bindings = [];

    foreach ($zones as $zoneMeta) {
        $zone = getOpnsenseCaptivePortalZone($zoneMeta['uuid'], $device);
        $allowedAddresses = array_map('normalizeOpnsenseAllowedAddress', extractOpnsenseListValues($zone['allowedAddresses'] ?? []));
        $allowedMacs = array_map('normalizeMacAddress', extractOpnsenseListValues($zone['allowedMACAddresses'] ?? []));

        foreach ($allowedAddresses as $address) {
            $bindings[] = [
                'id' => $zoneMeta['uuid'] . '|address|' . $address,
                'address' => $address,
                'mac' => '',
                'type' => 'bypassed',
                'to_address' => '',
                'server' => $zoneMeta['description'],
                'comment' => 'Bypass portail OPNsense',
                'disabled' => !$zoneMeta['enabled'],
                'status' => $zoneMeta['enabled'] ? 'Actif' : 'Zone desactivee',
                'zone_uuid' => $zoneMeta['uuid'],
                'zone_name' => $zoneMeta['description'],
                'binding_kind' => 'address',
                'binding_value' => $address,
            ];
        }

        foreach ($allowedMacs as $macAddress) {
            $bindings[] = [
                'id' => $zoneMeta['uuid'] . '|mac|' . $macAddress,
                'address' => '',
                'mac' => $macAddress,
                'type' => 'bypassed',
                'to_address' => '',
                'server' => $zoneMeta['description'],
                'comment' => 'Bypass portail OPNsense',
                'disabled' => !$zoneMeta['enabled'],
                'status' => $zoneMeta['enabled'] ? 'Actif' : 'Zone desactivee',
                'zone_uuid' => $zoneMeta['uuid'],
                'zone_name' => $zoneMeta['description'],
                'binding_kind' => 'mac',
                'binding_value' => $macAddress,
            ];
        }
    }

    usort($bindings, static function (array $a, array $b): int {
        $zoneCompare = strcasecmp((string)($a['zone_name'] ?? ''), (string)($b['zone_name'] ?? ''));
        if ($zoneCompare !== 0) {
            return $zoneCompare;
        }

        $left = (string)(($a['address'] ?? '') !== '' ? $a['address'] : ($a['mac'] ?? ''));
        $right = (string)(($b['address'] ?? '') !== '' ? $b['address'] : ($b['mac'] ?? ''));
        return strcasecmp($left, $right);
    });

    return $bindings;
}

function persistOpnsenseZoneBindingLists(array $device, string $zoneUuid, array $zonePayload, array $allowedAddresses, array $allowedMacs): void
{
    unset($zonePayload['uuid'], $zonePayload['%interfaces'], $zonePayload['%authservers'], $zonePayload['%template']);
    $zonePayload['allowedAddresses'] = implode(',', array_values(array_unique(array_filter(array_map('normalizeOpnsenseAllowedAddress', $allowedAddresses), static fn($value) => $value !== ''))));
    $zonePayload['allowedMACAddresses'] = implode(',', array_values(array_unique(array_filter(array_map('normalizeMacAddress', $allowedMacs), static fn($value) => $value !== ''))));

    $response = opnsenseApiRequest(
        $device,
        '/api/captiveportal/settings/setZone/' . rawurlencode($zoneUuid),
        'POST',
        [
            'zone' => $zonePayload,
        ]
    );

    if (!($response['success'] ?? false) || (($response['data']['result'] ?? 'failed') !== 'saved')) {
        throw new RuntimeException((string)($response['message'] ?? 'Enregistrement du bypass OPNsense impossible.'));
    }

    opnsenseCaptivePortalReconfigure($device);
}

function addOpnsenseIpBinding(array $device, array $payload): void
{
    $zoneUuid = trim((string)($payload['zone_uuid'] ?? ''));
    $bindingValue = trim((string)($payload['binding_value'] ?? ($payload['mac'] ?? '')));
    $type = strtolower(trim((string)($payload['type'] ?? 'bypassed')));

    if ($zoneUuid === '') {
        throw new RuntimeException('Zone captive portal manquante.');
    }

    if ($type !== 'bypassed') {
        throw new RuntimeException('Seul le mode bypass portail est disponible pour OPNsense pour le moment.');
    }

    $bindingKind = detectOpnsenseBindingKind($bindingValue);
    if ($bindingKind === '') {
        throw new RuntimeException('Saisissez une adresse IP, un reseau CIDR ou une adresse MAC valide.');
    }

    $zoneSummary = getOpnsenseCaptivePortalZoneSummary($zoneUuid, $device);
    $zonePayload = $zoneSummary['raw'] ?? [];
    $allowedAddresses = array_map('normalizeOpnsenseAllowedAddress', extractOpnsenseListValues($zonePayload['allowedAddresses'] ?? ''));
    $allowedMacs = array_map('normalizeMacAddress', extractOpnsenseListValues($zonePayload['allowedMACAddresses'] ?? ''));

    if ($bindingKind === 'mac') {
        $macAddress = normalizeMacAddress($bindingValue);
        if (in_array($macAddress, $allowedMacs, true)) {
            throw new RuntimeException('Cette adresse MAC est deja autorisee sur la zone selectionnee.');
        }
        $allowedMacs[] = $macAddress;
    } else {
        $address = normalizeOpnsenseAllowedAddress($bindingValue);
        if (in_array($address, $allowedAddresses, true)) {
            throw new RuntimeException('Cette adresse IP ou ce reseau est deja autorise sur la zone selectionnee.');
        }
        $allowedAddresses[] = $address;
    }

    persistOpnsenseZoneBindingLists($device, $zoneUuid, $zonePayload, $allowedAddresses, $allowedMacs);
}

function updateOpnsenseIpBinding(array $device, string $originalZoneUuid, string $originalValue, array $payload, string $originalKind = ''): void
{
    $newZoneUuid = trim((string)($payload['zone_uuid'] ?? ''));
    $newValue = trim((string)($payload['binding_value'] ?? ($payload['mac'] ?? '')));
    $type = strtolower(trim((string)($payload['type'] ?? 'bypassed')));
    $originalZoneUuid = trim($originalZoneUuid);
    $originalValue = trim($originalValue);
    $originalKind = trim($originalKind) !== '' ? trim($originalKind) : detectOpnsenseBindingKind($originalValue);

    if ($newZoneUuid === '') {
        throw new RuntimeException('Zone captive portal manquante.');
    }

    if ($type !== 'bypassed') {
        throw new RuntimeException('Seul le mode bypass portail est disponible pour OPNsense pour le moment.');
    }

    $newKind = detectOpnsenseBindingKind($newValue);
    if ($newKind === '') {
        throw new RuntimeException('Saisissez une adresse IP, un reseau CIDR ou une adresse MAC valide.');
    }

    $normalizedOriginalValue = $originalKind === 'mac'
        ? normalizeMacAddress($originalValue)
        : normalizeOpnsenseAllowedAddress($originalValue);
    $normalizedNewValue = $newKind === 'mac'
        ? normalizeMacAddress($newValue)
        : normalizeOpnsenseAllowedAddress($newValue);

    if ($originalZoneUuid === $newZoneUuid && $originalKind === $newKind && $normalizedOriginalValue === $normalizedNewValue) {
        return;
    }

    removeOpnsenseIpBinding($device, $originalZoneUuid, $originalValue, $originalKind);
    addOpnsenseIpBinding($device, [
        'zone_uuid' => $newZoneUuid,
        'binding_value' => $newValue,
        'type' => $type,
    ]);
}

function removeOpnsenseIpBinding(array $device, string $zoneUuid, string $bindingValue, string $bindingKind = ''): void
{
    $zoneUuid = trim($zoneUuid);
    $bindingValue = trim($bindingValue);
    $bindingKind = trim($bindingKind) !== '' ? trim($bindingKind) : detectOpnsenseBindingKind($bindingValue);

    if ($zoneUuid === '' || $bindingKind === '') {
        throw new RuntimeException('Binding OPNsense invalide.');
    }

    $zoneSummary = getOpnsenseCaptivePortalZoneSummary($zoneUuid, $device);
    $zonePayload = $zoneSummary['raw'] ?? [];
    $allowedAddresses = array_map('normalizeOpnsenseAllowedAddress', extractOpnsenseListValues($zonePayload['allowedAddresses'] ?? ''));
    $allowedMacs = array_map('normalizeMacAddress', extractOpnsenseListValues($zonePayload['allowedMACAddresses'] ?? ''));

    if ($bindingKind === 'mac') {
        $macAddress = normalizeMacAddress($bindingValue);
        $filteredMacs = array_values(array_filter($allowedMacs, static fn($value) => $value !== $macAddress));
        if (count($filteredMacs) === count($allowedMacs)) {
            throw new RuntimeException('Binding OPNsense introuvable.');
        }
        persistOpnsenseZoneBindingLists($device, $zoneUuid, $zonePayload, $allowedAddresses, $filteredMacs);
        return;
    }

    $address = normalizeOpnsenseAllowedAddress($bindingValue);
    $filteredAddresses = array_values(array_filter($allowedAddresses, static fn($value) => $value !== $address));
    if (count($filteredAddresses) === count($allowedAddresses)) {
        throw new RuntimeException('Binding OPNsense introuvable.');
    }

    persistOpnsenseZoneBindingLists($device, $zoneUuid, $zonePayload, $filteredAddresses, $allowedMacs);
}
