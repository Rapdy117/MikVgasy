<?php

require_once __DIR__ . '/opnsense_shaper.php';

function formatOpnsenseLeaseRemainingTime($expire): string
{
    if ($expire === null || $expire === '') {
        return '-';
    }

    $timestamp = (int)$expire;
    if ($timestamp <= 0) {
        return '-';
    }

    $remaining = $timestamp - time();
    if ($remaining <= 0) {
        return 'Expire';
    }

    $days = intdiv($remaining, 86400);
    $remaining %= 86400;
    $hours = intdiv($remaining, 3600);
    $remaining %= 3600;
    $minutes = intdiv($remaining, 60);
    $seconds = $remaining % 60;

    $parts = [];
    if ($days > 0) {
        $parts[] = $days . 'j';
    }
    if ($hours > 0) {
        $parts[] = $hours . 'h';
    }
    if ($minutes > 0) {
        $parts[] = $minutes . 'm';
    }
    if ($parts === []) {
        $parts[] = $seconds . 's';
    }

    return implode(' ', array_slice($parts, 0, 2));
}

function listOpnsenseDhcpLeases(array $device, int $limit = 300): array
{
    $response = opnsenseApiRequest($device, '/api/dnsmasq/leases/search');
    if (!($response['success'] ?? false)) {
        throw new RuntimeException((string)($response['message'] ?? 'Lecture des baux DHCP OPNsense impossible.'));
    }

    $rows = $response['data']['rows'] ?? [];
    if (!is_array($rows)) {
        return [];
    }

    $items = array_map(static function (array $row): array {
        $isReserved = trim((string)($row['is_reserved'] ?? '0')) === '1';
        $status = $isReserved ? 'Statique' : 'Dynamique';
        $hostName = trim((string)($row['hostname'] ?? ''));

        return [
            'id' => trim((string)($row['address'] ?? '')),
            'address' => trim((string)($row['address'] ?? '')),
            'mac' => trim((string)($row['hwaddr'] ?? '')),
            'host_name' => $hostName !== '' ? $hostName : '-',
            'server' => trim((string)($row['if_descr'] ?? '')) !== '' ? trim((string)($row['if_descr'] ?? '')) : '-',
            'comment' => '-',
            'status' => $status,
            'expires_after' => formatOpnsenseLeaseRemainingTime($row['expire'] ?? null),
            'last_seen' => '-',
            'dynamic' => !$isReserved,
            'blocked' => false,
            'disabled' => false,
        ];
    }, $rows);

    usort($items, static function (array $a, array $b): int {
        return strcasecmp((string)($a['address'] ?? ''), (string)($b['address'] ?? ''));
    });

    return $limit > 0 ? array_slice($items, 0, $limit) : $items;
}

function removeOpnsenseDhcpLease(array $device, string $address): void
{
    $leaseAddress = trim($address);
    if ($leaseAddress === '') {
        throw new RuntimeException('Adresse DHCP manquante pour suppression OPNsense.');
    }

    throw new RuntimeException('Suppression DHCP OPNsense non supportée par l API (seulement /api/dnsmasq/leases/search est documenté).');
}
