<?php
require '../../config/db.php';
require_once '../../includes/nas_resolver.php';
require_once '../../includes/radius_sync.php';

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
$session_timeout = post_int_or_default('session_timeout', 0);
$simultaneous_use = post_int_or_default('simultaneous_use', 0);
$idle_timeout = post_int_or_default('idle_timeout', 0);
$data_limit = post_int_or_default('data_limit', 0);
$nas_id = post_int_or_default('nas_id', 0);

if ($id === null || $profile_id === null || $balance === null || $auto_renewal === null || $session_timeout === null || $simultaneous_use === null || $idle_timeout === null || $data_limit === null || $nas_id === null) {
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

if ($profile_id <= 0) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Profil manquant'
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

if (!in_array($status, ['active', 'disabled', 'expired'], true)) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Statut invalide'
    ]);
    exit;
}

if ($session_timeout < 0 || $simultaneous_use < 0 || $idle_timeout < 0 || $data_limit < 0) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Valeurs negatives non autorisees'
    ]);
    exit;
}

try {
    $pdo->beginTransaction();

    $nasContext = loadNasContext($pdo, $nas_id);

    $stmt = $pdo->prepare("SELECT username, password FROM users WHERE id = ?");
    $stmt->execute([$id]);
    $existingUser = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$existingUser) {
        throw new Exception("Utilisateur introuvable");
    }

    $oldUsername = $existingUser['username'];

    if ($password === null) {
        $password = $existingUser['password'];
    }

    /* =========================
       1. UPDATE USERS
    ========================= */
    $stmt = $pdo->prepare("
        UPDATE users SET
            username = ?,
            password = ?,
            profile_id = ?,
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
        $profile_id,
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
    $stmt = $pdo->prepare("SELECT name FROM profiles WHERE id = ?");
    $stmt->execute([$profile_id]);
    $profile = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$profile) {
        throw new Exception("Profil introuvable");
    }

    $groupname = $profile['name'];

    updateUserToNasBackend($pdo, [
        'username' => $username,
        'old_username' => $oldUsername,
        'password' => $password,
        'rate_limit' => $rate_limit,
        'session_timeout' => $session_timeout,
        'simultaneous_use' => $simultaneous_use,
        'idle_timeout' => $idle_timeout,
        'data_limit' => $data_limit,
        'expiration_date' => $expiration_date,
    ], $groupname, $nasContext);

    $pdo->commit();

    echo json_encode([
        "success" => true,
        "message" => "Utilisateur mis à jour + sync OK",
        "nas_backend" => $nasContext['backend'],
        "nas_type" => $nasContext['nas_type']
    ]);

} catch (Exception $e) {
    $pdo->rollBack();
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
