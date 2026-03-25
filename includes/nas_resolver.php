<?php

function resolveNasBackend(string $nasType): string
{
    $type = strtolower(trim($nasType));

    // Tant que l'API projet OPNsense n'est pas implemente en provisionnement,
    // tous les NAS actuels continuent de passer par le backend RADIUS standard.
    return match ($type) {
        'mikrotik', 'ubiquiti', 'tplink', 'tenda', 'opnsense', 'freeradius', 'other' => 'radius',
        default => 'radius',
    };
}

function resolveNasCapabilities(string $nasType): array
{
    $type = strtolower(trim($nasType));

    $baseCapabilities = [
        'Session-Timeout',
        'Idle-Timeout',
        'Simultaneous-Use',
        'Max-Octets',
    ];

    return match ($type) {
        'mikrotik' => array_merge($baseCapabilities, [
            'Mikrotik-Rate-Limit',
        ]),
        'ubiquiti', 'tplink', 'tenda', 'opnsense', 'freeradius', 'other' => array_merge($baseCapabilities, [
            'WISPr-Bandwidth-Max-Down',
            'WISPr-Bandwidth-Max-Up',
        ]),
        default => array_merge($baseCapabilities, [
            'WISPr-Bandwidth-Max-Down',
            'WISPr-Bandwidth-Max-Up',
        ]),
    };
}

function loadNasContext(PDO $pdo, int $nasId): array
{
    $stmt = $pdo->prepare("
        SELECT id, nasname, shortname, type, secret, server, community, description
        FROM nas
        WHERE id = ?
    ");
    $stmt->execute([$nasId]);

    $nas = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$nas) {
        throw new Exception("NAS introuvable");
    }

    $nasType = (string)($nas['type'] ?? 'other');

    return [
        'nas_id' => (int)$nas['id'],
        'nasname' => $nas['nasname'],
        'shortname' => $nas['shortname'],
        'nas_type' => $nasType,
        'backend' => resolveNasBackend($nasType),
        'capabilities' => resolveNasCapabilities($nasType),
        'connection' => [
            'secret' => $nas['secret'],
            'server' => $nas['server'],
            'community' => $nas['community'],
            'description' => $nas['description'],
        ],
    ];
}
