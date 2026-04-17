<?php
require '../../config/db.php';
require_once '../../includes/device_manager.php';
require_once '../../includes/profile_catalog.php';

session_start();

header('Content-Type: application/json');

function rechargeOptionsCacheDir(): string
{
    $dir = sys_get_temp_dir() . '/mikhmon_recharge_options_cache';
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }

    return $dir;
}

function rechargeOptionsCacheFile(string $deviceId, string $action): string
{
    return rechargeOptionsCacheDir() . '/' . md5($deviceId . '|' . $action) . '.json';
}

function rechargeOptionsReadCache(string $deviceId, string $action, int $ttlSeconds): ?array
{
    $file = rechargeOptionsCacheFile($deviceId, $action);
    if (!is_file($file)) {
        return null;
    }

    if ((time() - filemtime($file)) > $ttlSeconds) {
        return null;
    }

    $data = json_decode((string)file_get_contents($file), true);
    return is_array($data) ? $data : null;
}

function rechargeOptionsWriteCache(string $deviceId, string $action, array $items): void
{
    file_put_contents(rechargeOptionsCacheFile($deviceId, $action), json_encode($items, JSON_UNESCAPED_SLASHES));
}

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$action = trim((string)($_GET['action'] ?? ''));
$deviceId = trim((string)($_GET['device_id'] ?? ''));

if ($action === '' || $deviceId === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Parametres manquants']);
    exit;
}

try {
    $store = loadDeviceStore();
    $device = findDeviceById($store, $deviceId);
    if (!$device) {
        throw new RuntimeException('Device introuvable');
    }

    $businessSource = resolveDeviceBusinessSource((string)($device['type'] ?? ''));
    $contextDevice = $device;
    $isMikrotikBackend = ($businessSource === 'mikrotik_local');

    if ($action === 'users') {
        if ($isMikrotikBackend) {
            $cachedItems = rechargeOptionsReadCache($deviceId, 'users', 20);
            if ($cachedItems !== null) {
                echo json_encode(['success' => true, 'items' => $cachedItems]);
                exit;
            }

            $api = connectToMikrotikApiByDevice($contextDevice);

            try {
                $rows = $api->comm('/ip/hotspot/user/print');
            } finally {
                $api->disconnect();
            }

            $items = [];
            foreach ((array)$rows as $row) {
                if (!is_array($row)) {
                    continue;
                }

                $username = trim((string)($row['name'] ?? ''));
                if ($username === '') {
                    continue;
                }

                $items[] = [
                    'value' => $username,
                    'label' => $username,
                    'current_profile' => trim((string)($row['profile'] ?? '')),
                    'current_profile_label' => trim((string)($row['profile'] ?? '')),
                ];
            }

            usort($items, static fn(array $a, array $b): int => strcasecmp($a['label'], $b['label']));
            rechargeOptionsWriteCache($deviceId, 'users', $items);

            echo json_encode(['success' => true, 'items' => $items]);
            exit;
        }

        $stmt = $pdo->query("
            SELECT u.username, u.profile_id, p.name AS profile_name
            FROM users u
            LEFT JOIN profiles p ON u.profile_id = p.id
            ORDER BY u.username ASC
        ");

        $items = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $username = trim((string)($row['username'] ?? ''));
            if ($username === '') {
                continue;
            }

            $items[] = [
                'value' => $username,
                'label' => $username,
                'current_profile' => trim((string)($row['profile_id'] ?? '')),
                'current_profile_label' => trim((string)($row['profile_name'] ?? '')),
            ];
        }

        echo json_encode(['success' => true, 'items' => $items]);
        exit;
    }

    if ($action === 'profiles') {
        $cachedItems = rechargeOptionsReadCache($deviceId, 'profiles', $isMikrotikBackend ? 30 : 10);
        if ($cachedItems !== null) {
            echo json_encode(['success' => true, 'items' => $cachedItems]);
            exit;
        }

        $catalog = loadProfileCatalogForDevice($pdo, $contextDevice, ['sort' => 'name_asc']);
        $items = [];
        foreach ($catalog['profiles'] as $row) {
            $name = trim((string)($row['name'] ?? ''));
            if ($name === '') {
                continue;
            }

            $profileId = (int)($row['id'] ?? 0);
            $items[] = [
                'value' => $isMikrotikBackend ? $name : (string)$profileId,
                'label' => $name,
                'profile_id' => $isMikrotikBackend ? null : (string)$profileId,
                'profile_name' => $name,
            ];
        }

        rechargeOptionsWriteCache($deviceId, 'profiles', $items);
        echo json_encode(['success' => true, 'items' => $items]);
        exit;
    }

    throw new RuntimeException('Action inconnue');
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
    ]);
}
