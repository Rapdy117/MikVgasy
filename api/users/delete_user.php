<?php
require '../../config/db.php';
require_once '../../includes/auth.php';
require_once '../../includes/operation_history.php';
require_once '../../includes/admin_notifications.php';
require_once '../../includes/nas_resolver.php';
require_once '../../includes/backend_agent.php';

session_start();
header('Content-Type: application/json');

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
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'message' => 'La session du formulaire a expire. Rechargez la page puis reessayez.',
        ]);
        exit;
    }
}

if (!isset($_SESSION['logged_in'])) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'message' => 'Votre session a expire. Reconnectez-vous puis reessayez.',
    ]);
    exit;
}
if (!isAdministrator()) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'message' => 'Accès réservé à l administrateur',
    ]);
    exit;
}

require_valid_csrf();

/* =========================
   INPUT
========================= */
$id = post_int_or_default('id', 0);

if ($id === null || $id <= 0) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'ID manquant',
    ]);
    exit;
}

try {
    ensureOperationHistoryTable($pdo);
    ensureAdminNotificationsTable($pdo);
    $pdo->beginTransaction();

    /* =========================
       1. GET USERNAME
    ========================= */
    $stmt = $pdo->prepare("SELECT username, nas_id FROM users WHERE id = ?");
    $stmt->execute([$id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        throw new Exception("Utilisateur introuvable");
    }

    $username = $user['username'];
    $nasId = (int)($user['nas_id'] ?? 0);
    if ($nasId <= 0) {
        throw new Exception('NAS introuvable pour cet utilisateur');
    }
    $nasContext = resolveNasContextFromInputs($pdo, $nasId, null);
    backendAgentAuthorizeDeviceAction($nasContext['device'] ?? [], 'user-delete', [
        'user_id' => $id,
        'username' => $username,
        'nas_id' => $nasId,
    ]);

    /* =========================
       2. DELETE RADIUS
    ========================= */
    $stmt = $pdo->prepare("DELETE FROM radcheck WHERE username = ?");
    $stmt->execute([$username]);

    $stmt = $pdo->prepare("DELETE FROM radreply WHERE username = ?");
    $stmt->execute([$username]);

    $stmt = $pdo->prepare("DELETE FROM radusergroup WHERE username = ?");
    $stmt->execute([$username]);

    /* =========================
       3. OPTIONAL radacct
    ========================= */

    // ⚠️ DECOMMENTER si tu veux supprimer l'historique
    /*
    $stmt = $pdo->prepare("DELETE FROM radacct WHERE username = ?");
    $stmt->execute([$username]);
    */

    /* =========================
       4. DELETE USER
    ========================= */
    $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
    $stmt->execute([$id]);

    recordOperationHistory($pdo, [
        'operation_scope' => 'admin',
        'operation_type' => 'user_delete',
        'actor_username' => (string)($_SESSION['username'] ?? ''),
        'actor_role' => (string)($_SESSION['user_role'] ?? 'administrator'),
        'target_type' => 'user',
        'target_name' => $username,
        'target_ref' => (string)$id,
        'summary' => 'Utilisateur supprimé',
        'details_json' => [
            'business_source' => 'radius',
            'backend_driver' => 'radius',
            'radacct_deleted' => false,
        ],
    ]);

    $pdo->commit();

    echo json_encode([
        "success" => true,
        "message" => "Utilisateur supprime avec succes."
    ]);

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(trim((string)$e->getMessage()) === 'Utilisateur introuvable' ? 404 : 500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
    ]);
}
