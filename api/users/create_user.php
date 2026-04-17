<?php
require '../../config/db.php'; // ta connexion PDO
require_once '../../includes/auth.php';
require_once '../../includes/user_provisioning.php';
require_once '../../includes/opnsense_shaper.php';
require_once '../../includes/operation_history.php';
require_once '../../includes/user_schema.php';

session_start();

header('Content-Type: application/json');

function publicCreateUserErrorMessage(Throwable $error): string
{
    $message = trim((string)$error->getMessage());

    if ($error instanceof PDOException) {
        $sqlState = (string)($error->getCode() ?? '');
        $driverCode = (string)($error->errorInfo[1] ?? '');

        if ($sqlState === '23000' || $driverCode === '1062') {
            return 'Ce nom d utilisateur existe deja. Choisissez-en un autre.';
        }
    }

    return match ($message) {
        'Profil introuvable' => 'Le profil choisi est introuvable.',
        'Profil MikroTik introuvable sur le routeur.' => 'Le profil choisi est introuvable sur le routeur.',
        'Profil MikroTik introuvable sur le routeur. Nom requis.' => 'Le profil choisi est introuvable sur le routeur.',
        'Device introuvable' => 'Le serveur choisi est introuvable.',
        'Aucun NAS correspondant au device selectionne' => 'Le serveur choisi n est pas encore relie au systeme.',
        'NAS introuvable' => 'Le serveur choisi est introuvable.',
        'NAS incoherent avec le serveur choisi' => 'Le NAS ne correspond pas au serveur choisi.',
        'Serveur incoherent avec le NAS choisi' => 'Le serveur ne correspond pas au NAS choisi.',
        'Utilisateur deja present sur MikroTik.' => 'Cet utilisateur existe deja sur le serveur MikroTik.',
        default => 'La creation a echoue. Verifiez les informations saisies puis reessayez.',
    };
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

function post_int_or_null(string $key): ?int
{
    $value = trim((string)($_POST[$key] ?? ''));
    if ($value === '') {
        return null;
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
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'message' => 'CSRF invalide'
        ]);
        exit;
    }
}

/* =========================
   SECURITY
========================= */
if (!isset($_SESSION['logged_in'])) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'message' => 'Unauthorized'
    ]);
    exit;
}
if (!isAdministrator()) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'message' => 'Accès réservé à l administrateur'
    ]);
    exit;
}

require_valid_csrf();

/* =========================
   INPUT
========================= */
$username = post_string_or_null('username');
$password = post_string_or_null('password');
$profile_id = post_int_or_default('profile_id', 0);
$profile_name = post_string_or_null('profile_name');
$device_id = post_string_or_null('device_id');

/* RADIUS */
$session_timeout = post_int_or_null('session_timeout');
$data_limit = post_int_or_null('data_limit');
$nas_id = post_int_or_default('nas_id', 0);
$isMikrotikDevice = false;
if ($device_id !== null && $device_id !== '') {
    try {
        $deviceStore = loadDeviceStore();
        $device = findDeviceById($deviceStore, $device_id);
        $isMikrotikDevice = is_array($device)
            && resolveDeviceBusinessSource((string)($device['type'] ?? '')) === 'mikrotik_local';
    } catch (Throwable $e) {
        $isMikrotikDevice = false;
    }
}

/* =========================
   VALIDATION
========================= */
if ($username === null || $password === null) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Le nom d utilisateur et le mot de passe sont obligatoires.'
    ]);
    exit;
}

if ($profile_id === null || $nas_id === null) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Une valeur numerique saisie est invalide.'
    ]);
    exit;
}

if ($profile_id <= 0 && $profile_name === null) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Veuillez choisir un profil.'
    ]);
    exit;
}

if ($device_id === null || $device_id === '') {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Serveur requis',
    ]);
    exit;
}

if ($nas_id <= 0 && !$isMikrotikDevice) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'NAS requis',
    ]);
    exit;
}

if (($session_timeout !== null && $session_timeout < 0) || ($data_limit !== null && $data_limit < 0)) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Les limites ne peuvent pas etre negatives.'
    ]);
    exit;
}

try {
    ensureOperationHistoryTable($pdo);
    ensureAdminNotificationsTable($pdo);
    $pdo->beginTransaction();
    $result = provisionUserWithProfile($pdo, [
        'username' => $username,
        'password' => $password,
        'profile_id' => $profile_id,
        'profile_name' => $profile_name,
        'device_id' => $device_id,
        'nas_id' => $nas_id,
        'session_timeout' => $session_timeout,
        'data_limit' => $data_limit,
    ]);

    $nc = $result['nas_context'];
    if ((int)($nc['nas_id'] ?? 0) !== (int)$nas_id) {
        throw new RuntimeException('NAS incoherent avec le serveur choisi');
    }
    $resolvedDeviceId = trim((string)($nc['device']['id'] ?? ''));
    if ($resolvedDeviceId !== '' && $resolvedDeviceId !== trim((string)$device_id)) {
        throw new RuntimeException('Serveur incoherent avec le NAS choisi');
    }

    $createdBusinessSource = nasContextRequireBusinessSource($result['nas_context']);

    recordOperationHistory($pdo, [
        'operation_scope' => 'admin',
        'operation_type' => 'user_create',
        'actor_username' => (string)($_SESSION['username'] ?? ''),
        'actor_role' => (string)($_SESSION['user_role'] ?? 'administrator'),
        'target_type' => 'user',
        'target_name' => $username,
        'target_ref' => isset($result['user_id']) ? (string)$result['user_id'] : null,
        'device_id' => trim((string)($device_id ?? '')) ?: null,
        'profile_name' => trim((string)($result['profile']['name'] ?? $profile_name ?? '')) ?: null,
        'amount_value' => $result['profile']['commercial_amount'] ?? null,
        'summary' => 'Utilisateur créé et synchronisé',
        'details_json' => [
            'business_source' => $createdBusinessSource,
            'backend_driver' => nasContextRequireBackendDriver($result['nas_context']),
            'nas_type' => (string)($result['nas_context']['nas_type'] ?? ''),
            'storage_scope' => (string)($result['storage_scope'] ?? 'local_database'),
            'session_timeout' => $session_timeout,
            'data_limit' => $data_limit,
            'profile_price' => $result['profile']['price'] ?? null,
            'profile_selling_price' => $result['profile']['selling_price'] ?? null,
            'commercial_amount' => $result['profile']['commercial_amount'] ?? null,
        ],
    ]);

    if ($pdo->inTransaction()) {
        $pdo->commit();
    } else {
        error_log('[create_user] Transaction lost before commit.');
    }

    $shaperSync = null;
    if (($result['nas_context']['nas_type'] ?? '') === 'opnsense') {
        $shaperSync = trySyncOpnsenseUserShaper($pdo, $username);
    }

    $createdMessage = ($createdBusinessSource === 'mikrotik_local')
        ? 'Utilisateur cree sur le routeur MikroTik'
        : 'Utilisateur créé + synchronisé';

    echo json_encode([
        "success" => true,
        "message" => $createdMessage,
        "business_source" => $createdBusinessSource,
        "backend_driver" => nasContextRequireBackendDriver($result['nas_context']),
        "nas_type" => (string)($result['nas_context']['nas_type'] ?? ''),
        "storage_scope" => $result['storage_scope'] ?? 'local_database',
        "shaper_sync" => $shaperSync,
    ]);

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log('[create_user] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => publicCreateUserErrorMessage($e)
    ]);
}
