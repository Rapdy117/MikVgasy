<?php

require_once __DIR__ . '/device_manager.php';

function normalizeNasType(string $nasType): string
{
    $type = strtolower(trim($nasType));

    return match ($type) {
        'mikrotik' => 'mikrotik',
        'opnsense' => 'opnsense',
        'radius', 'freeradius' => 'radius',
        default => throw new InvalidArgumentException(sprintf(
            'Type NAS invalide: %s',
            $nasType !== '' ? $nasType : '(vide)'
        )),
    };
}

function resolveNasBusinessSource(string $nasType): string
{
    return match (normalizeNasType($nasType)) {
        'mikrotik' => 'mikrotik_local',
        'opnsense', 'radius' => 'radius',
    };
}

function resolveNasDriverFromDeviceType(string $deviceType): string
{
    return match (normalizeDeviceType($deviceType)) {
        'mikrotik' => 'mikrotik_api',
        'opnsense' => 'opnsense_api',
        'radius' => 'radius',
    };
}

function resolveNasCapabilities(string $nasType): array
{
    $type = normalizeNasType($nasType);

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
        'opnsense', 'radius' => array_merge($baseCapabilities, [
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

    $nasType = normalizeNasType((string)($nas['type'] ?? ''));
    $businessSource = resolveNasBusinessSource($nasType);

    return [
        'nas_id' => (int)$nas['id'],
        'nasname' => $nas['nasname'],
        'shortname' => $nas['shortname'],
        'nas_type' => $nasType,
        'business_source' => $businessSource,
        'backend_driver' => resolveNasDriverFromDeviceType($nasType),
        'capabilities' => resolveNasCapabilities($nasType),
        'connection' => [
            'secret' => $nas['secret'],
            'server' => $nas['server'],
            'community' => $nas['community'],
            'description' => $nas['description'],
        ],
    ];
}

/**
 * Associe un device à un contexte NAS déjà chargé (même source métier, driver selon device.type).
 */
function enrichNasContextWithDevice(array $context, array $device): array
{
    $deviceType = normalizeDeviceType((string)($device['type'] ?? ''));
    $deviceBusinessSource = resolveDeviceBusinessSource($deviceType);
    $nasBusinessSource = resolveNasBusinessSource((string)($context['nas_type'] ?? ''));

    if ($deviceBusinessSource !== $nasBusinessSource) {
        throw new RuntimeException('Le device selectionne ne correspond pas a la source metier du NAS');
    }

    $context['device'] = $device;
    $context['device_type'] = $deviceType;
    $context['backend_driver'] = resolveNasDriverFromDeviceType($deviceType);
    $context['business_source'] = $deviceBusinessSource;

    return $context;
}

function nasContextRequireBusinessSource(array $nasContext): string
{
    $v = trim((string)($nasContext['business_source'] ?? ''));
    if ($v === '') {
        throw new RuntimeException('nas_context incomplet: business_source requis');
    }

    return $v;
}

function nasContextRequireBackendDriver(array $nasContext): string
{
    $v = trim((string)($nasContext['backend_driver'] ?? ''));
    if ($v === '') {
        throw new RuntimeException('nas_context incomplet: backend_driver requis');
    }

    return $v;
}

function loadNasContextByDeviceId(PDO $pdo, string $deviceId): array
{
    $deviceId = trim($deviceId);
    if ($deviceId === '') {
        throw new RuntimeException('Device introuvable');
    }

    $store = loadDeviceStore();
    $device = findDeviceById($store, $deviceId);
    if (!$device) {
        throw new RuntimeException('Device introuvable');
    }

    $address = extractDeviceAddress((string)($device['host'] ?? ''));
    if ($address === '') {
        $address = trim((string)($device['ip'] ?? ''));
    }
    if ($address === '') {
        throw new RuntimeException('NAS introuvable');
    }

    $stmt = $pdo->prepare('SELECT id FROM nas WHERE nasname = ? LIMIT 1');
    $stmt->execute([$address]);
    $nasId = (int)($stmt->fetchColumn() ?: 0);
    if ($nasId <= 0) {
        throw new RuntimeException('Aucun NAS correspondant au device selectionne');
    }

    $context = loadNasContext($pdo, $nasId);

    return enrichNasContextWithDevice($context, $device);
}

/**
 * Résout un contexte NAS + device alignés.
 *
 * - Si $deviceId est non vide et $nasId est null ou <= 0 : le NAS est dérivé du device
 *   (adresse device ↔ nas.nasname) via {@see loadNasContextByDeviceId}, puis enrichissement.
 * - Si $deviceId et un $nasId > 0 sont fournis : chargement du NAS par id + contrôle d’alignement métier avec le device.
 * - Si seul $nasId > 0 est fourni (sans device) : {@see loadNasContext} (sans device embarqué).
 * - Sinon : exception « Serveur requis ».
 *
 * Ne pas confondre avec un nas_id stocké à 0 en base : ici l’absence d’identifiant NAS explicite
 * signifie « résoudre depuis le device ».
 */
function resolveNasContextFromInputs(PDO $pdo, ?int $nasId = null, ?string $deviceId = null): array
{
    $resolvedDeviceId = trim((string)($deviceId ?? ''));
    $explicitNas = $nasId !== null && (int)$nasId > 0;

    if ($resolvedDeviceId !== '') {
        $store = loadDeviceStore();
        $device = findDeviceById($store, $resolvedDeviceId);
        if (!$device) {
            throw new RuntimeException('Device introuvable');
        }

        if ($explicitNas) {
            $context = loadNasContext($pdo, (int)$nasId);

            return enrichNasContextWithDevice($context, $device);
        }

        return loadNasContextByDeviceId($pdo, $resolvedDeviceId);
    }

    if ($explicitNas) {
        return loadNasContext($pdo, (int)$nasId);
    }

    throw new RuntimeException('Serveur requis');
}
