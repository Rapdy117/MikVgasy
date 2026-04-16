<?php
header('Content-Type: application/json');

require_once '../../config/db.php';
require_once '../../includes/auth.php';
require_once '../../includes/radius_sync.php';
require_once '../../includes/device_manager.php';
require_once '../../includes/mikrotik_backend.php';
require_once '../../includes/nas_resolver.php';
require_once '../../includes/operation_history.php';

session_start();
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Votre session a expire. Reconnectez-vous puis reessayez.']);
    exit;
}
if (!isAdministrator()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Accès réservé à l administrateur']);
    exit;
}

function post_string_or_null(string $key): ?string
{
    $value = trim((string)($_POST[$key] ?? ''));
    return $value === '' ? null : $value;
}

function post_int_or_default(string $key, int $default = 0): ?int
{
    $value = trim((string)($_POST[$key] ?? ''));
    if ($value === '') {
        return $default;
    }

    if (filter_var($value, FILTER_VALIDATE_INT) === false) {
        return null;
    }

    return (int)$value;
}

function require_valid_csrf(): void
{
    $token = trim((string)($_POST['csrf_token'] ?? ''));

    if ($token === '' || !hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        throw new Exception('La session du formulaire a expire. Rechargez la page puis reessayez.');
    }
}

function profileUsageCounts(PDO $pdo, int $profileId, string $profileName): array
{
    $counts = [
        'users' => 0,
        'vouchers' => 0,
        'opnsense_profiles' => 0,
        'radusergroup' => 0,
    ];

    if ($profileId > 0) {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM users WHERE profile_id = ?');
        $stmt->execute([$profileId]);
        $counts['users'] = (int)$stmt->fetchColumn();

        $stmt = $pdo->prepare('SELECT COUNT(*) FROM vouchers WHERE profile_id = ?');
        $stmt->execute([$profileId]);
        $counts['vouchers'] = (int)$stmt->fetchColumn();

        $stmt = $pdo->prepare('SELECT COUNT(*) FROM opnsense_profiles WHERE profile_id = ?');
        $stmt->execute([$profileId]);
        $counts['opnsense_profiles'] = (int)$stmt->fetchColumn();
    }

    if ($profileName !== '') {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM radusergroup WHERE groupname = ?');
        $stmt->execute([$profileName]);
        $counts['radusergroup'] = (int)$stmt->fetchColumn();
    }

    return $counts;
}

function ensureProfileNameUnique(PDO $pdo, int $profileId, string $profileName): void
{
    if ($profileName === '') {
        return;
    }

    $stmt = $pdo->prepare('SELECT COUNT(*) FROM profiles WHERE name = ?');
    $stmt->execute([$profileName]);
    $count = (int)$stmt->fetchColumn();
    if ($count > 1) {
        throw new Exception('Ce profil partage le meme nom qu un autre profil. Renommez-le avant suppression.');
    }
}

function ensureProfileNotUsed(array $counts, bool $isActiveMikrotik): void
{
    if (!$isActiveMikrotik && ($counts['users'] ?? 0) > 0) {
        throw new Exception('Ce profil est encore utilise par un ou plusieurs utilisateurs.');
    }

    if (($counts['vouchers'] ?? 0) > 0) {
        throw new Exception('Ce profil est encore lie a un ou plusieurs vouchers.');
    }

    if (($counts['opnsense_profiles'] ?? 0) > 0) {
        throw new Exception('Ce profil est encore utilise dans la correspondance OPNsense.');
    }

    if (!$isActiveMikrotik && ($counts['radusergroup'] ?? 0) > 0) {
        throw new Exception('Ce profil est encore associe a des utilisateurs RADIUS.');
    }
}

try {
    if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
        throw new Exception('Votre session a expire. Reconnectez-vous puis reessayez.');
    }

    require_valid_csrf();

    $profileId = post_int_or_default('profile_id', 0);
    $routerProfileId = post_string_or_null('router_profile_id') ?? '';
    $profileName = post_string_or_null('profile_name') ?? '';
    $deviceId = post_string_or_null('device_id') ?? '';

    if ($profileId === null || $profileId < 0) {
        throw new Exception('Identifiant de profil invalide');
    }

    if ($profileId <= 0 && $routerProfileId === '' && $profileName === '') {
        throw new Exception('Profil introuvable');
    }

    $deviceStore = loadDeviceStore();
    $requestedDevice = null;
    if ($deviceId !== '') {
        $requestedDevice = findDeviceById($deviceStore, $deviceId);
        if (!is_array($requestedDevice)) {
            throw new Exception('Serveur introuvable');
        }
    }

    $activeDevice = getActiveDeviceRecord($deviceStore);
    $targetDevice = is_array($requestedDevice) ? $requestedDevice : $activeDevice;
    $isTargetMikrotik = is_array($targetDevice) && (($targetDevice['type'] ?? '') === 'mikrotik');

    $localProfile = null;
    if (!$isTargetMikrotik) {
        if ($profileId > 0) {
            $stmt = $pdo->prepare('SELECT id, name FROM profiles WHERE id = ? LIMIT 1');
            $stmt->execute([$profileId]);
            $localProfile = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        }

        if ($localProfile === null && $profileName !== '') {
            $stmt = $pdo->prepare('SELECT id, name FROM profiles WHERE name = ? LIMIT 1');
            $stmt->execute([$profileName]);
            $localProfile = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        }
    }

    $effectiveProfileId = $isTargetMikrotik ? 0 : (int)($localProfile['id'] ?? 0);
    $effectiveProfileName = $isTargetMikrotik
        ? trim((string)$profileName)
        : trim((string)($localProfile['name'] ?? $profileName));

    ensureProfileNameUnique($pdo, $effectiveProfileId, $effectiveProfileName);
    ensureProfileNotUsed(
        profileUsageCounts($pdo, $effectiveProfileId, $effectiveProfileName),
        $isTargetMikrotik
    );

    ensureOperationHistoryTable($pdo);
    $pdo->beginTransaction();

    if (!$isTargetMikrotik && $effectiveProfileId > 0) {
        $stmt = $pdo->prepare('DELETE FROM profiles WHERE id = ?');
        $stmt->execute([$effectiveProfileId]);
    }

    if ($isTargetMikrotik && ($effectiveProfileName !== '' || $routerProfileId !== '')) {
        if (!is_array($targetDevice)) {
            throw new Exception('Serveur introuvable');
        }

        $targetDeviceType = normalizeDeviceType((string)($targetDevice['type'] ?? 'mikrotik'));
        $mikrotikContext = [
            'device' => $targetDevice,
            'device_type' => $targetDeviceType,
            'backend_driver' => (string)($targetDevice['backend_driver'] ?? resolveDeviceBackend($targetDeviceType)),
            'business_source' => trim((string)($targetDevice['business_source'] ?? '')) ?: resolveDeviceBusinessSource($targetDeviceType),
            'nas_type' => $targetDeviceType,
            'capabilities' => resolveNasCapabilities($targetDeviceType),
        ];

        deleteProfileFromMikrotik($effectiveProfileName, $mikrotikContext, $routerProfileId);
    } elseif ($effectiveProfileName !== '') {
        deleteProfileFromRadius($pdo, $effectiveProfileName);
    }

    recordOperationHistory($pdo, [
        'operation_scope' => 'admin',
        'operation_type' => 'profile_delete',
        'actor_username' => (string)($_SESSION['username'] ?? ''),
        'actor_role' => (string)($_SESSION['user_role'] ?? 'administrator'),
        'target_type' => 'profile',
        'target_name' => $effectiveProfileName,
        'target_ref' => $effectiveProfileId > 0 ? (string)$effectiveProfileId : null,
        'profile_name' => $effectiveProfileName,
        'summary' => 'Profil supprimé',
        'details_json' => [
            'router_profile_id' => $routerProfileId,
            'device_id' => is_array($targetDevice) ? (string)($targetDevice['id'] ?? '') : '',
            'counts' => profileUsageCounts($pdo, $effectiveProfileId, $effectiveProfileName),
        ],
    ]);

    $pdo->commit();

    echo json_encode([
        'success' => true,
        'message' => 'Profil supprime avec succes.',
    ]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    $message = trim((string)$e->getMessage());
    $status = 500;
    if (
        str_contains($message, 'encore utilise')
        || str_contains($message, 'encore lie')
        || str_contains($message, 'encore associe')
    ) {
        $status = 409;
    } elseif ($message === 'Profil introuvable') {
        $status = 404;
    } elseif (str_contains($message, 'session du formulaire')) {
        $status = 403;
    }

    http_response_code($status);
    echo json_encode([
        'success' => false,
        'message' => $message !== '' ? $message : 'Suppression impossible.',
    ]);
}
