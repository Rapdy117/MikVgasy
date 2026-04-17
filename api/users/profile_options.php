<?php
header('Content-Type: application/json');

require_once '../../config/db.php';
require_once '../../includes/device_manager.php';
require_once '../../includes/profile_catalog.php';
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

    $isVisibleProfile = static function (string $profileName) use ($isAdminUser): bool {
        if ($isAdminUser) {
            return true;
        }
        return strtolower(trim($profileName)) !== 'default';
    };

    $catalog = loadProfileCatalogForDevice($pdo, $device, ['sort' => 'name_asc']);
    $profiles = array_values(array_filter($catalog['profiles'], static function (array $profile) use ($isVisibleProfile): bool {
        return $isVisibleProfile((string)($profile['name'] ?? ''));
    }));

    echo json_encode([
        'success' => true,
        'source' => (string)$catalog['source'],
        'business_source' => (string)$catalog['business_source'],
        'backend_driver' => (string)$catalog['backend_driver'],
        'profiles' => $profiles,
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => trim((string)$e->getMessage()) !== '' ? trim((string)$e->getMessage()) : 'Chargement des profils impossible.',
    ]);
}
