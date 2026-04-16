<?php
header('Content-Type: application/json');

require_once __DIR__ . '/../includes/device_manager.php';

session_start();

function post_string_or_null(string $key): ?string
{
    $value = trim((string)($_POST[$key] ?? ''));
    return $value === '' ? null : $value;
}

function stripDeviceSecretsForPublicApi(?array $device): ?array
{
    if ($device === null) {
        return null;
    }

    $out = $device;
    unset($out['api_key'], $out['api_secret'], $out['secret']);

    return $out;
}

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'message' => 'Non autorisé',
    ]);
    exit;
}

// =========================
$data = loadDeviceStore();

// =========================
// POST ACTIONS
// =========================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $action = trim((string)($_POST['action'] ?? 'save'));

    // =========================
    // DELETE
    // =========================
    if ($action === 'delete') {

        $id = post_string_or_null('id');

        if ($id === null) {
            echo json_encode([
                'success' => false,
                'message' => 'Identifiant manquant',
            ]);
            exit;
        }

        $data['devices'] = array_values(array_filter($data['devices'], function ($d) use ($id) {
            return ($d['id'] ?? '') !== $id;
        }));

        if (($data['active_device_id'] ?? null) === $id) {
            $data['active_device_id'] = null;
            unset($_SESSION['active_device_id']);
        }

        saveDeviceStore($data);

        echo json_encode(['success' => true]);
        exit;
    }

    if ($action === 'set_active') {
        $id = post_string_or_null('id');

        if ($id === null) {
            echo json_encode([
                'success' => false,
                'message' => 'Identifiant manquant',
            ]);
            exit;
        }

        $activeDevice = setActiveDeviceId($id);

        if (!$activeDevice) {
            echo json_encode([
                'success' => false,
                'message' => 'Device introuvable'
            ]);
            exit;
        }

        echo json_encode([
            'success' => true,
            'active_device_id' => $activeDevice['id'],
            'active_device' => stripDeviceSecretsForPublicApi($activeDevice),
            'connection_state' => buildDeviceConnectionState($activeDevice),
        ]);
        exit;
    }

    // =========================
    // SAVE / UPDATE
    // =========================
    $id = post_string_or_null('id');
    $rawType = post_string_or_null('type');
    $name = post_string_or_null('device_name');
    $host = post_string_or_null('host');
    $api_key = post_string_or_null('api_key');
    $api_secret = post_string_or_null('api_secret');
    $verify_ssl = ($_POST['verify_ssl'] ?? 'false') === 'true';
    $setActive = ($_POST['is_active'] ?? '0') === '1';

    if ($rawType === null) {
        http_response_code(422);
        echo json_encode([
            'success' => false,
            'message' => 'Type de device manquant',
        ]);
        exit;
    }

    try {
        $type = normalizeDeviceType($rawType);
    } catch (InvalidArgumentException $e) {
        http_response_code(422);
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage(),
        ]);
        exit;
    }

    $requiresApiCredentials = in_array($type, ['opnsense', 'mikrotik'], true);
    $hasSecret = $api_secret !== null;
    $hasApiKey = $api_key !== null;

    if ($name === null || $host === null || ($requiresApiCredentials && (!$hasApiKey || !$hasSecret))) {
        http_response_code(422);
        echo json_encode([
            'success' => false,
            'message' => 'Champs obligatoires manquants (nom, hôte et identifiants API si requis).',
        ]);
        exit;
    }

    // generate ID if not exists
    if ($id === null) {
        $id = 'dev_' . time();
    }

    $found = false;

    foreach ($data['devices'] as &$device) {
        if (($device['id'] ?? '') === $id) {

            $device = normalizeDeviceRecord([
                'id' => $id,
                'name' => $name,
                'type' => $type,
                'host' => $host,
                'api_key' => $requiresApiCredentials ? ($api_key ?? '') : '',
                'api_secret' => $api_secret ?? '',
                'secret' => $api_secret ?? '',
                'verify_ssl' => $verify_ssl,
                'port' => $device['port'] ?? null,
                'vendor' => $device['vendor'] ?? null,
                'created_at' => $device['created_at'] ?? null,
                'updated_at' => date('Y-m-d H:i:s')
            ]);

            $found = true;
            break;
        }
    }

    if (!$found) {
        $data['devices'][] = normalizeDeviceRecord([
            'id' => $id,
            'name' => $name,
            'type' => $type,
            'host' => $host,
            'api_key' => $requiresApiCredentials ? ($api_key ?? '') : '',
            'api_secret' => $api_secret ?? '',
            'secret' => $api_secret ?? '',
            'verify_ssl' => $verify_ssl,
            'created_at' => date('Y-m-d H:i:s')
        ]);
    }

    if ($setActive || empty($data['active_device_id'])) {
        $data['active_device_id'] = $id;
        $_SESSION['active_device_id'] = $id;
    }

    saveDeviceStore($data);

    $activeAfterSave = findDeviceById($data, (string)($data['active_device_id'] ?? ''));
    echo json_encode([
        'success' => true,
        'id' => $id,
        'active_device_id' => $data['active_device_id'] ?? null,
        'active_device' => stripDeviceSecretsForPublicApi($activeAfterSave),
        'connection_state' => buildDeviceConnectionState($activeAfterSave),
    ]);
    exit;
}

// =========================
// GET DEVICES
// =========================
$activeDevice = getActiveDeviceRecord($data);
$data['active_device_id'] = $activeDevice['id'] ?? ($data['active_device_id'] ?? null);
$data['active_device'] = stripDeviceSecretsForPublicApi($activeDevice);
$data['devices'] = array_map(static function (array $device): array {
    return stripDeviceSecretsForPublicApi($device) ?? $device;
}, $data['devices'] ?? []);
$data['connection_state'] = buildDeviceConnectionState($activeDevice);
echo json_encode($data);
