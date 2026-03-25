<?php
header('Content-Type: application/json');

require_once '../../config/db.php';
require_once '../../includes/nas_resolver.php';
require_once '../../includes/radius_sync.php';

session_start();

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

function convertValidityToSeconds(int $value, string $unit): int
{
    if ($value <= 0) {
        return 0;
    }

    return match ($unit) {
        'hours' => $value * 3600,
        'days' => $value * 86400,
        'months' => $value * 2592000,
        default => 0,
    };
}

function require_valid_csrf(): void
{
    $token = trim((string)($_POST['csrf_token'] ?? ''));

    if ($token === '' || !hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        throw new Exception("CSRF invalide");
    }
}

try {
    if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
        throw new Exception("Unauthorized");
    }

    require_valid_csrf();

    // 🔹 1. récupérer POST
    $name = post_string_or_null('profile_name');
    $rate = post_string_or_null('rate_limit');
    $session = post_int_or_default('session_timeout', 0);
    $idle = post_int_or_default('idle_timeout', 0);
    $data = post_int_or_default('data_limit', 0);
    $simu = post_int_or_default('simultaneous_use', 1);
    $nas_id = post_int_or_default('nas_id', 0);
    $validityValue = post_int_or_default('validity_value', 0);
    $validityUnit = post_string_or_null('validity_unit') ?? 'hours';

    if (!in_array($validityUnit, ['hours', 'days', 'months'], true)) {
        throw new Exception("Unite de validite invalide");
    }

    if ($session === null || $idle === null || $data === null || $simu === null || $nas_id === null || $validityValue === null) {
        throw new Exception("Types numeriques invalides");
    }

    if ($name === null || $nas_id <= 0) {
        throw new Exception("Champs obligatoires manquants");
    }

    if ($session < 0 || $idle < 0 || $data < 0 || $simu < 0 || $validityValue < 0) {
        throw new Exception("Valeurs negatives non autorisees");
    }

    $validityTime = convertValidityToSeconds($validityValue, $validityUnit);

    // 🔹 2. construire le contexte NAS
    $nasContext = loadNasContext($pdo, $nas_id);

    // 🔹 3. enregistrer profil
    $pdo->beginTransaction();

    $stmt = $pdo->prepare("
        INSERT INTO profiles 
        (name, rate_limit, session_timeout, idle_timeout, validity_time, data_quota_mb, simultaneous_use)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");

    $stmt->execute([
        $name,
        $rate,
        $session,
        $idle,
        $validityTime > 0 ? $validityTime : null,
        $data,
        $simu
    ]);

    // 🔹 4. sync vers backend NAS resolu
    syncProfileToNasBackend($pdo, [
        'name' => $name,
        'rate_limit' => $rate,
        'session_timeout' => $session,
        'idle_timeout' => $idle,
        'data_quota_mb' => $data,
        'simultaneous_use' => $simu
    ], $nasContext);

    $pdo->commit();

    echo json_encode([
        'success' => true,
        'message' => 'Profil créé avec succès',
        'nas_backend' => $nasContext['backend'],
        'nas_type' => $nasContext['nas_type']
    ]);

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);

}
