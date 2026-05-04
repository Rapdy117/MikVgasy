<?php
require '../../config/db.php';
require_once '../../includes/auth.php';
require_once '../../includes/crypto.php';
require_once '../../includes/nas_resolver.php';
require_once '../../includes/opnsense_shaper.php';
require_once '../../includes/radius_sync.php';
require_once '../../includes/operation_history.php';
require_once '../../includes/user_schema.php';
require_once '../../includes/backend_agent.php';

session_start();

header('Content-Type: application/json');

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

function post_float_or_default(string $key, float $default = 0.0): ?float
{
    $value = trim((string)($_POST[$key] ?? ''));
    if ($value === '') {
        return $default;
    }

    if (!is_numeric($value)) {
        return null;
    }

    return (float)$value;
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

function post_bool_int_or_default(string $key, int $default = 0): ?int
{
    $value = trim((string)($_POST[$key] ?? ''));
    if ($value === '') {
        return $default;
    }

    if ($value === '0' || $value === '1') {
        return (int)$value;
    }

    return null;
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
$id = post_int_or_default('id', 0);
$username = post_string_or_null('username');
$password = post_string_or_null('password');
$profile_id = post_int_or_default('profile_id', 0);
$status = post_string_or_null('status') ?? 'active';

$fullname = post_string_or_null('fullname');
$phone = post_string_or_null('phone');
$email = post_string_or_null('email');
$address = post_string_or_null('address');
$balance = post_float_or_default('balance', 0.0);
$expiration_date = post_string_or_null('expiration_date');
$auto_renewal = post_bool_int_or_default('auto_renewal', 0);

/* RADIUS */
$rate_limit = post_string_or_null('rate_limit');
$session_timeout = post_int_or_null('session_timeout');
$simultaneous_use = post_int_or_default('simultaneous_use', 0);
$idle_timeout = post_int_or_default('idle_timeout', 0);
$data_limit = post_int_or_null('data_limit');
$nas_id = post_int_or_default('nas_id', 0);
$device_id = post_string_or_null('device_id');

if ($id === null || $profile_id === null || $balance === null || $auto_renewal === null || $simultaneous_use === null || $idle_timeout === null || $nas_id === null) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Invalid numeric input'
    ]);
    exit;
}

if ($id <= 0 || $username === null) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'ID ou username manquant'
    ]);
    exit;
}

if ($nas_id <= 0) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'NAS manquant'
    ]);
    exit;
}

if ($device_id === null || trim($device_id) === '') {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Serveur requis',
    ]);
    exit;
}

if (!in_array($status, ['active', 'disabled', 'expired'], true)) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Statut invalide'
    ]);
    exit;
}

if (($session_timeout !== null && $session_timeout < 0) || $simultaneous_use < 0 || $idle_timeout < 0 || ($data_limit !== null && $data_limit < 0)) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Valeurs negatives non autorisees'
    ]);
    exit;
}

try {
    ensureOperationHistoryTable($pdo);
    ensureAdminNotificationsTable($pdo);
    ensureUsersExtendedSchema($pdo);
    $pdo->beginTransaction();

    $nasContext = resolveNasContextFromInputs($pdo, $nas_id, $device_id);
    backendAgentAuthorizeDeviceAction($nasContext['device'] ?? [], 'user-update', [
        'user_id' => $id,
        'username' => $username,
        'profile_id' => $profile_id,
        'nas_id' => $nas_id,
        'status' => $status,
    ]);

    if ((int)($nasContext['nas_id'] ?? 0) !== (int)$nas_id) {
        throw new Exception('NAS incoherent avec le serveur choisi');
    }
    $resolvedDeviceId = trim((string)($nasContext['device']['id'] ?? ''));
    if ($resolvedDeviceId !== '' && $resolvedDeviceId !== trim((string)$device_id)) {
        throw new Exception('Serveur incoherent avec le NAS choisi');
    }

    $businessSource = nasContextRequireBusinessSource($nasContext);
    if ($businessSource !== 'radius') {
        throw new Exception("Le type de NAS selectionne ne passe pas par la base metier / FreeRADIUS");
    }

    $stmt = $pdo->prepare("SELECT username, password, profile_id FROM users WHERE id = ?");
    $stmt->execute([$id]);
    $existingUser = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$existingUser) {
        throw new Exception("Utilisateur introuvable");
    }

    $oldUsername = $existingUser['username'];
    if ($profile_id <= 0) {
        $profile_id = (int)($existingUser['profile_id'] ?? 0);
    }

    if ($profile_id <= 0) {
        throw new Exception('Profil manquant');
    }

    if ($password === null) {
        /* Garde le mot de passe existant (déjà chiffré en DB) */
        $password = $existingUser['password'];
    } else {
        /* Nouveau mot de passe → chiffre avant stockage */
        $password = encryptField($password);
    }

    /* =========================
       1. UPDATE USERS
    ========================= */
    $stmt = $pdo->prepare("
        UPDATE users SET
            username = ?,
            password = ?,
            nas_id = ?,
            profile_id = ?,
            session_timeout = ?,
            data_limit = ?,
            status = ?,
            fullname = ?,
            phone = ?,
            email = ?,
            address = ?,
            balance = ?,
            expiration_date = ?,
            auto_renewal = ?
        WHERE id = ?
    ");

    $stmt->execute([
        $username,
        $password,
        $nas_id,
        $profile_id,
        $session_timeout,
        $data_limit,
        $status,
        $fullname,
        $phone,
        $email,
        $address,
        $balance,
        $expiration_date,
        $auto_renewal,
        $id
    ]);

    /* =========================
       2. PROFILE NAME
    ========================= */
    $stmt = $pdo->prepare("
        SELECT name, price, selling_price, rate_limit, idle_timeout, simultaneous_use
        FROM profiles
        WHERE id = ?
    ");
    $stmt->execute([$profile_id]);
    $profile = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$profile) {
        throw new Exception("Profil introuvable");
    }

    $groupname = $profile['name'];
    $price = isset($profile['price']) && $profile['price'] !== null
        ? round((float)$profile['price'], 2)
        : null;
    $sellingPrice = isset($profile['selling_price']) && $profile['selling_price'] !== null
        ? round((float)$profile['selling_price'], 2)
        : null;
    $commercialAmount = $price;
    $profileRateLimit = trim((string)($profile['rate_limit'] ?? '')) ?: null;
    $profileIdleTimeout = max(0, (int)($profile['idle_timeout'] ?? 0));
    $profileSimultaneousUse = max(0, (int)($profile['simultaneous_use'] ?? 0));

    updateUserToNasBackend($pdo, [
        'username' => $username,
        'old_username' => $oldUsername,
        'password' => $password,
        'status' => $status,
        // Les attributs d'offre doivent venir du profil serveur, pas du formulaire UI.
        'rate_limit' => $profileRateLimit,
        'session_timeout' => $session_timeout,
        'simultaneous_use' => $profileSimultaneousUse,
        'idle_timeout' => $profileIdleTimeout,
        'data_limit' => $data_limit,
        'expiration_date' => $expiration_date,
    ], $groupname, $nasContext);

    recordOperationHistory($pdo, [
        'operation_scope' => 'admin',
        'operation_type' => 'user_update',
        'actor_username' => (string)($_SESSION['username'] ?? ''),
        'actor_role' => (string)($_SESSION['user_role'] ?? 'administrator'),
        'target_type' => 'user',
        'target_name' => $username,
        'target_ref' => (string)$id,
        'device_id' => trim((string)$device_id) ?: null,
        'profile_name' => $groupname,
        'amount_value' => $commercialAmount,
        'summary' => 'Utilisateur mis à jour et synchronisé',
        'details_json' => [
            'old_username' => $oldUsername,
            'device_id' => trim((string)$device_id),
            'nas_id' => $nas_id,
            'business_source' => nasContextRequireBusinessSource($nasContext),
            'backend_driver' => nasContextRequireBackendDriver($nasContext),
            'status' => $status,
            'session_timeout' => $session_timeout,
            'simultaneous_use' => $profileSimultaneousUse,
            'idle_timeout' => $profileIdleTimeout,
            'rate_limit' => $profileRateLimit,
            'data_limit' => $data_limit,
            'profile_price' => $price,
            'profile_selling_price' => $sellingPrice,
            'commercial_amount' => $commercialAmount,
        ],
    ]);

    $pdo->commit();

    $shaperSync = null;
    if (($nasContext['nas_type'] ?? '') === 'opnsense') {
        $shaperSync = trySyncOpnsenseUserShaper($pdo, $username);
    }

    echo json_encode([
        "success" => true,
        "message" => "Utilisateur mis à jour + sync OK",
        "business_source" => nasContextRequireBusinessSource($nasContext),
        "backend_driver" => nasContextRequireBackendDriver($nasContext),
        "nas_type" => (string)($nasContext['nas_type'] ?? ''),
        "shaper_sync" => $shaperSync,
    ]);

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
