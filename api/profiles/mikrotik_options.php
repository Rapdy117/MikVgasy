<?php
header('Content-Type: application/json');

require_once '../../includes/auth.php';
require_once '../../includes/device_manager.php';
require_once '../../includes/mikrotik_backend.php';

session_start();

const MIKROTIK_OPTIONS_CACHE_TTL = 45;

function uniqueSortedNames(array $items): array
{
    $unique = [];

    foreach ($items as $item) {
        $name = trim((string)($item['name'] ?? ''));
        if ($name === '') {
            continue;
        }

        $unique[$name] = true;
    }

    $names = array_keys($unique);
    natcasesort($names);

    return array_values($names);
}

try {
    if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
        throw new RuntimeException('Unauthorized');
    }
    if (!isAdministrator()) {
        throw new RuntimeException('Accès réservé à l administrateur');
    }

    $deviceId = trim((string)($_GET['device_id'] ?? ''));
    if ($deviceId === '') {
        throw new RuntimeException('Device introuvable');
    }

    $store = loadDeviceStore();
    $device = findDeviceById($store, $deviceId);

    if (!$device) {
        throw new RuntimeException('Device introuvable');
    }

    if (($device['type'] ?? '') !== 'mikrotik') {
        echo json_encode([
            'success' => true,
            'address_pools' => [],
            'parent_queues' => [],
        ]);
        exit;
    }

    $cacheKey = 'mikrotik_options_' . $deviceId;
    $cached = $_SESSION[$cacheKey] ?? null;
    if (is_array($cached)) {
        $cachedAt = (int)($cached['ts'] ?? 0);
        if ($cachedAt > 0 && (time() - $cachedAt) <= MIKROTIK_OPTIONS_CACHE_TTL) {
            echo json_encode([
                'success' => true,
                'address_pools' => is_array($cached['address_pools'] ?? null) ? $cached['address_pools'] : [],
                'parent_queues' => is_array($cached['parent_queues'] ?? null) ? $cached['parent_queues'] : [],
            ]);
            exit;
        }
    }

    $api = connectToMikrotikApiByDevice($device);

    try {
        $pools = $api->comm('/ip/pool/print');
        $simpleQueues = $api->comm('/queue/simple/print');
        $treeQueues = $api->comm('/queue/tree/print');
    } finally {
        $api->disconnect();
    }

    $payload = [
        'success' => true,
        'address_pools' => uniqueSortedNames(is_array($pools) ? $pools : []),
        'parent_queues' => uniqueSortedNames(array_merge(
            is_array($simpleQueues) ? $simpleQueues : [],
            is_array($treeQueues) ? $treeQueues : []
        )),
    ];
    $_SESSION[$cacheKey] = [
        'ts' => time(),
        'address_pools' => $payload['address_pools'],
        'parent_queues' => $payload['parent_queues'],
    ];

    echo json_encode($payload);
} catch (Throwable $e) {
    $message = trim((string)$e->getMessage());

    if (str_starts_with($message, 'Connexion Mikhmon/API MikroTik impossible')) {
        $message = 'Connexion au serveur MikroTik impossible.';
    } elseif ($message === 'Device introuvable') {
        $message = 'Le serveur choisi est introuvable.';
    } elseif ($message === 'Unauthorized') {
        $message = 'Unauthorized';
    } else {
        $message = 'Chargement des options MikroTik impossible.';
    }

    echo json_encode([
        'success' => false,
        'message' => $message,
    ]);
}
