<?php

require_once __DIR__ . '/opnsense_shaper.php';

function normalizeOpnsenseQueryRows(array $rows): array
{
    $normalized = [];

    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }

        $normalized[] = [
            'time' => trim((string)($row['time'] ?? '')),
            'client' => trim((string)($row['client'] ?? '')),
            'family' => trim((string)($row['family'] ?? '')),
            'type' => trim((string)($row['type'] ?? '')),
            'domain' => trim((string)($row['domain'] ?? '')),
            'action' => trim((string)($row['action'] ?? '')),
            'source' => trim((string)($row['source'] ?? '')),
            'rcode' => trim((string)($row['rcode'] ?? '')),
            'resolve_time_ms' => trim((string)($row['resolve_time_ms'] ?? '')),
            'ttl' => trim((string)($row['ttl'] ?? '')),
            'blocklist' => trim((string)($row['blocklist'] ?? '')),
            'status' => isset($row['status']) ? (int)$row['status'] : 0,
        ];
    }

    return $normalized;
}

function fetchOpnsenseDomainQueries(array $device, int $limit = 100): array
{
    $limit = max(10, min($limit, 250));
    $path = '/api/unbound/overview/searchQueries?current=1&rowCount=' . $limit;
    $response = opnsenseApiRequest($device, $path);

    if (!($response['success'] ?? false)) {
        throw new RuntimeException((string)($response['message'] ?? 'Lecture des requêtes DNS OPNsense impossible.'));
    }

    $rows = $response['data']['rows'] ?? [];

    return normalizeOpnsenseQueryRows(is_array($rows) ? $rows : []);
}

function fetchOpnsenseDomainTotals(array $device, int $maximum = 10): array
{
    $maximum = max(5, min($maximum, 100));
    $response = opnsenseApiRequest($device, '/api/unbound/overview/totals/' . $maximum);

    if (!($response['success'] ?? false)) {
        throw new RuntimeException((string)($response['message'] ?? 'Lecture des totaux DNS OPNsense impossible.'));
    }

    $data = $response['data'] ?? [];

    return [
        'total_queries' => (int)($data['total_queries'] ?? 0),
        'total_blocked' => (int)($data['total_blocked'] ?? 0),
        'blocklist_size' => (int)($data['blocklist_size'] ?? 0),
        'top' => is_array($data['top'] ?? null) ? $data['top'] : [],
        'top_blocked' => is_array($data['top_blocked'] ?? null) ? $data['top_blocked'] : [],
    ];
}
