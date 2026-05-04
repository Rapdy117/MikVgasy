<?php
header('Content-Type: application/json');

require_once __DIR__ . '/../includes/device_manager.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/db.php';

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

function shouldExposeDeviceSecretsForCurrentRequest(): bool
{
    if ((string)($_GET['include_secrets'] ?? '0') !== '1') {
        return false;
    }

    if (!isAdministrator()) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'message' => 'Accès réservé à l administrateur.',
        ]);
        exit;
    }

    return true;
}

function syncRadiusNasForDevice(PDO $pdo, array $device): ?int
{
    $type = normalizeDeviceType((string)($device['type'] ?? ''));
    if (!in_array($type, ['opnsense', 'radius'], true)) {
        return null;
    }

    $address = extractDeviceAddress((string)($device['host'] ?? ''));
    if ($address === '') {
        $address = trim((string)($device['ip'] ?? ''));
    }
    if ($address === '') {
        throw new RuntimeException('Adresse NAS introuvable pour le device OPNsense / RADIUS.');
    }

    $shortname = trim((string)($device['name'] ?? ''));
    if ($shortname === '') {
        $shortname = $address;
    }

    $stmt = $pdo->prepare('SELECT id FROM nas WHERE nasname = ? LIMIT 1');
    $stmt->execute([$address]);
    $nasId = (int)($stmt->fetchColumn() ?: 0);

    if ($nasId > 0) {
        $update = $pdo->prepare('
            UPDATE nas
            SET shortname = ?, type = ?, description = ?
            WHERE id = ?
        ');
        $update->execute([$shortname, $type, $shortname, $nasId]);

        return $nasId;
    }

    $insert = $pdo->prepare('
        INSERT INTO nas (nasname, shortname, type, description)
        VALUES (?, ?, ?, ?)
    ');
    $insert->execute([$address, $shortname, $type, $shortname]);

    return (int)$pdo->lastInsertId();
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
            echo json_encode(['success' => false, 'message' => 'Identifiant manquant']);
            exit;
        }

        /* ── Vérification licence avant activation ── */
        require_once __DIR__ . '/../includes/license.php';
        $candidateDevice = findDeviceById($data, $id);
        if (!$candidateDevice) {
            echo json_encode(['success' => false, 'message' => 'Device introuvable']);
            exit;
        }

        $licStatus = getDeviceLicenseStatus($candidateDevice);
        if (!$licStatus['valid']) {
            $deviceId = $licStatus['device_id'] ?? 'non identifié';
            http_response_code(403);
            echo json_encode([
                'success'          => false,
                'message'          => "🔒 Licence requise pour activer ce routeur.\nDevice ID : {$deviceId}\nContactez l'administrateur pour obtenir votre clé de licence.",
                'license_required' => true,
                'license'          => $licStatus,
            ]);
            exit;
        }

        $activeDevice = setActiveDeviceId($id);

        if (!$activeDevice) {
            echo json_encode(['success' => false, 'message' => 'Device introuvable']);
            exit;
        }

        echo json_encode([
            'success'          => true,
            'active_device_id' => $activeDevice['id'],
            'active_device'    => stripDeviceSecretsForPublicApi($activeDevice),
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
    $existingDevice = null;
    /* Fingerprint transmis depuis le test de connexion JS */
    $deviceFingerprint = trim((string)($_POST['device_fingerprint'] ?? ''));
    $hardwareInfoRaw   = trim((string)($_POST['hardware_info']      ?? ''));
    $hardwareInfo      = $hardwareInfoRaw !== '' ? (json_decode($hardwareInfoRaw, true) ?? []) : [];

    if ($id !== null) {
        foreach ($data['devices'] as $candidateDevice) {
            if (($candidateDevice['id'] ?? '') === $id) {
                $existingDevice = $candidateDevice;
                break;
            }
        }
    }

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
    $effectiveApiKey = $requiresApiCredentials
        ? ($api_key ?? (string)($existingDevice['api_key'] ?? ''))
        : '';
    $effectiveApiSecret = $api_secret ?? (string)($existingDevice['api_secret'] ?? ($existingDevice['secret'] ?? ''));
    $hasSecret = trim($effectiveApiSecret) !== '';
    $hasApiKey = trim($effectiveApiKey) !== '';

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
    $savedDevice = null;

    foreach ($data['devices'] as &$device) {
        if (($device['id'] ?? '') === $id) {
            $device = normalizeDeviceRecord([
                'id'                 => $id,
                'name'               => $name,
                'type'               => $type,
                'host'               => $host,
                'api_key'            => $requiresApiCredentials ? $effectiveApiKey : '',
                'api_secret'         => $effectiveApiSecret,
                'secret'             => $effectiveApiSecret,
                'verify_ssl'         => $verify_ssl,
                'port'               => $device['port']    ?? null,
                'vendor'             => $device['vendor']  ?? null,
                'created_at'         => $device['created_at'] ?? null,
                'updated_at'         => date('Y-m-d H:i:s'),
                'device_fingerprint' => $deviceFingerprint !== '' ? $deviceFingerprint : ($device['device_fingerprint'] ?? ''),
                'hardware_info'      => !empty($hardwareInfo) ? $hardwareInfo : ($device['hardware_info'] ?? []),
                'license_key'        => $device['license_key']    ?? '',
                'license_status'     => $device['license_status'] ?? '',
                'license_expiry'     => $device['license_expiry'] ?? '',
                'license_issued'     => $device['license_issued'] ?? '',
            ]);
            $savedDevice = $device;
            $found = true;
            break;
        }
    }
    unset($device);

    if (!$found) {
        $savedDevice = normalizeDeviceRecord([
            'id'                 => $id,
            'name'               => $name,
            'type'               => $type,
            'host'               => $host,
            'api_key'            => $requiresApiCredentials ? $effectiveApiKey : '',
            'api_secret'         => $effectiveApiSecret,
            'secret'             => $effectiveApiSecret,
            'verify_ssl'         => $verify_ssl,
            'created_at'         => date('Y-m-d H:i:s'),
            'device_fingerprint' => $deviceFingerprint,
            'hardware_info'      => $hardwareInfo,
        ]);
        $data['devices'][] = $savedDevice;
    }

    $syncedNasId = $savedDevice !== null ? syncRadiusNasForDevice($pdo, $savedDevice) : null;

    /* ── N'active automatiquement que si la licence est valide ── */
    require_once __DIR__ . '/../includes/license.php';
    $savedLicStatus = getDeviceLicenseStatus($savedDevice ?? []);

    if ($savedLicStatus['valid']) {
        /* Licencié → active automatiquement à chaque sauvegarde */
        $data['active_device_id'] = $id;
        $_SESSION['active_device_id'] = $id;
    } elseif (empty($data['active_device_id'])) {
        $data['active_device_id'] = null;
    }

    saveDeviceStore($data);

    $activeAfterSave = findDeviceById($data, (string)($data['active_device_id'] ?? ''));

    /* Pour RADIUS : générer un fingerprint depuis l'IP si absent */
    if ($savedDevice !== null && ($savedDevice['type'] ?? '') === 'radius'
        && trim((string)($savedDevice['device_fingerprint'] ?? '')) === '') {
        $radiusHost = trim((string)($savedDevice['ip'] ?? $savedDevice['host'] ?? ''));
        $radiusPort = (int)($savedDevice['port'] ?? 1812);
        if ($radiusHost !== '') {
            $radiusHwInfo = [
                'serial' => $radiusHost,
                'host'   => $radiusHost,
                'board'  => 'FreeRADIUS',
            ];
            try {
                $radiusFp = computeDeviceFingerprint($radiusHwInfo);
                $radiusDevId = formatDeviceId($radiusFp, 'radius');
                foreach ($data['devices'] as &$dRef) {
                    if (($dRef['id'] ?? '') === $id) {
                        $dRef['device_fingerprint'] = $radiusFp;
                        $dRef['hardware_info']      = $radiusHwInfo;
                        $savedDevice = $dRef;
                        break;
                    }
                }
                unset($dRef);
                saveDeviceStore($data);
            } catch (\Throwable $ignored) {}
        }
    }

    /* Recalcule avec les éventuelles données RADIUS générées */
    $licenseStatus = getDeviceLicenseStatus($savedDevice ?? []);

    echo json_encode([
        'success'                       => true,
        'id'                            => $id,
        'synced_nas_id'                 => $syncedNasId,
        'active_device_id'              => $data['active_device_id'] ?? null,
        'active_device'                 => stripDeviceSecretsForPublicApi($activeAfterSave),
        'connection_state'              => buildDeviceConnectionState($activeAfterSave),
        'saved_device_connection_state' => buildDeviceConnectionState($savedDevice),
        'license'                       => $licenseStatus,
    ]);
    exit;
}

// =========================
// GET DEVICES
// =========================
$activeDevice = getActiveDeviceRecord($data);
$includeSecrets = shouldExposeDeviceSecretsForCurrentRequest();
$data['active_device_id'] = $activeDevice['id'] ?? ($data['active_device_id'] ?? null);
$data['active_device'] = $includeSecrets
    ? $activeDevice
    : stripDeviceSecretsForPublicApi($activeDevice);
$data['devices'] = array_map(static function (array $device) use ($includeSecrets): array {
    return $includeSecrets
        ? $device
        : (stripDeviceSecretsForPublicApi($device) ?? $device);
}, $data['devices'] ?? []);
$data['connection_state'] = buildDeviceConnectionState($activeDevice);
echo json_encode($data);
