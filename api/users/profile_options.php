<?php
header('Content-Type: application/json');

require_once '../../config/db.php';
require_once '../../includes/device_manager.php';
require_once '../../includes/mikrotik_backend.php';
require_once '../../includes/nas_resolver.php';
require_once '../../includes/auth.php';

session_start();

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'message' => 'Unauthorized',
    ]);
    exit;
}

try {
    $isAdminUser = isAdministrator();
    $deviceId = trim((string)($_GET['device_id'] ?? ''));

    if ($deviceId === '') {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Device requis',
        ]);
        exit;
    }

    $deviceStore = loadDeviceStore();
    $device = findDeviceById($deviceStore, $deviceId);
    if (!$device) {
        throw new Exception('Device introuvable');
    }

    $nasContext = resolveNasContextFromInputs($pdo, null, (string)($device['id'] ?? ''));
    $contextDevice = is_array($nasContext['device'] ?? null) ? $nasContext['device'] : $device;
    $businessSource = nasContextRequireBusinessSource($nasContext);
    $backendDriver = nasContextRequireBackendDriver($nasContext);

    $isVisibleProfile = static function (string $profileName) use ($isAdminUser): bool {
        if ($isAdminUser) {
            return true;
        }
        return strtolower(trim($profileName)) !== 'default';
    };

    if ($businessSource === 'mikrotik_local') {
        $profiles = [];
        foreach (loadMikrotikHotspotProfilesCached($contextDevice, 60) as $routerProfile) {
            $name = trim((string)($routerProfile['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            if (!$isVisibleProfile($name)) {
                continue;
            }

            $profiles[] = [
                'id' => 0,
                'name' => $name,
                'router_id' => (string)($routerProfile['id'] ?? ''),
                'service_type' => 'hotspot',
                'rate_limit' => (string)($routerProfile['rate_limit'] ?? ''),
                'session_timeout' => isset($routerProfile['session_timeout']) ? (int)$routerProfile['session_timeout'] : 0,
                'idle_timeout' => 0,
                'validity_time' => isset($routerProfile['validity_time']) ? (int)$routerProfile['validity_time'] : 0,
                'data_quota_mb' => isset($routerProfile['data_quota_mb']) ? (int)$routerProfile['data_quota_mb'] : 0,
                'simultaneous_use' => isset($routerProfile['simultaneous_use']) ? (int)$routerProfile['simultaneous_use'] : 0,
                'expired_mode' => (string)($routerProfile['expired_mode'] ?? ''),
                'price' => isset($routerProfile['price']) && $routerProfile['price'] !== null ? (string)$routerProfile['price'] : '',
                'selling_price' => isset($routerProfile['selling_price']) && $routerProfile['selling_price'] !== null ? (string)$routerProfile['selling_price'] : '',
                'ip_pool' => (string)($routerProfile['ip_pool'] ?? ''),
                'account_type' => 'hotspot',
            ];
        }

        usort($profiles, static fn(array $a, array $b): int => strcasecmp($a['name'], $b['name']));

        echo json_encode([
            'success' => true,
            'source' => 'mikrotik',
            'business_source' => $businessSource,
            'backend_driver' => $backendDriver,
            'profiles' => $profiles,
        ]);
        exit;
    }

    $localProfilesStmt = $pdo->query('
        SELECT
            id,
            name,
            service_type,
            rate_limit,
            session_timeout,
            idle_timeout,
            validity_time,
            data_quota_mb,
            simultaneous_use,
            expired_mode,
            price,
            selling_price,
            ip_pool,
            account_type
        FROM profiles
        ORDER BY name ASC
    ');
    $localProfiles = $localProfilesStmt->fetchAll(PDO::FETCH_ASSOC);

    $visibleLocalProfiles = array_values(array_filter($localProfiles, static function (array $profile) use ($isVisibleProfile): bool {
        return $isVisibleProfile((string)($profile['name'] ?? ''));
    }));

    echo json_encode([
        'success' => true,
        'source' => 'local',
        'business_source' => $businessSource !== '' ? $businessSource : 'radius',
        'backend_driver' => $backendDriver,
        'profiles' => array_map(static function (array $profile): array {
            return [
                'id' => (int)$profile['id'],
                'name' => (string)$profile['name'],
                'router_id' => '',
                'service_type' => (string)($profile['service_type'] ?? ''),
                'rate_limit' => (string)($profile['rate_limit'] ?? ''),
                'session_timeout' => isset($profile['session_timeout']) ? (int)$profile['session_timeout'] : 0,
                'idle_timeout' => isset($profile['idle_timeout']) ? (int)$profile['idle_timeout'] : 0,
                'validity_time' => isset($profile['validity_time']) ? (int)$profile['validity_time'] : 0,
                'data_quota_mb' => isset($profile['data_quota_mb']) ? (int)$profile['data_quota_mb'] : 0,
                'simultaneous_use' => isset($profile['simultaneous_use']) ? (int)$profile['simultaneous_use'] : 0,
                'expired_mode' => (string)($profile['expired_mode'] ?? ''),
                'price' => isset($profile['price']) && $profile['price'] !== null ? (string)$profile['price'] : '',
                'selling_price' => isset($profile['selling_price']) && $profile['selling_price'] !== null ? (string)$profile['selling_price'] : '',
                'ip_pool' => (string)($profile['ip_pool'] ?? ''),
                'account_type' => (string)($profile['account_type'] ?? ''),
            ];
        }, $visibleLocalProfiles),
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => trim((string)$e->getMessage()) !== '' ? trim((string)$e->getMessage()) : 'Chargement des profils impossible.',
    ]);
}
