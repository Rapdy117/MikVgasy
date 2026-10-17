<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/device_manager.php';
require_once __DIR__ . '/../includes/lan_inventory.php';

session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

function lanInventoryReply(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') lanInventoryReply(['success' => false, 'message' => 'Méthode non autorisée.'], 405);
    if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) lanInventoryReply(['success' => false, 'message' => 'Unauthorized'], 403);
    if (!isAdministrator()) lanInventoryReply(['success' => false, 'message' => 'Accès réservé à l administrateur.'], 403);

    $payload = json_decode((string)file_get_contents('php://input'), true);
    if (!is_array($payload)) throw new InvalidArgumentException('Payload invalide.');
    $token = trim((string)($payload['csrf_token'] ?? ''));
    if ($token === '' || !hash_equals((string)($_SESSION['csrf_token'] ?? ''), $token)) {
        lanInventoryReply(['success' => false, 'message' => 'CSRF invalide.'], 403);
    }

    $device = requireActiveDevice();
    if (normalizeDeviceType((string)($device['type'] ?? '')) !== 'mikrotik') {
        throw new InvalidArgumentException('Inventaire disponible seulement pour MikroTik.');
    }
    $deviceId = (string)($device['id'] ?? '');
    $username = currentLocalUsername();
    session_write_close();

    global $pdo;
    $action = trim((string)($payload['action'] ?? ''));

    if ($action === 'refresh') {
        $snapshot = lanInventoryCollect($device);
        lanInventorySaveSnapshot($pdo, $deviceId, $username, $snapshot);
        $summary = lanInventoryBuild($snapshot)['summary'];
        lanInventoryReply(['success' => true, 'message' => 'Inventaire mis à jour.', 'summary' => $summary]);
    }

    throw new InvalidArgumentException('Action non supportée.');
} catch (Throwable $e) {
    lanInventoryReply(['success' => false, 'message' => trim((string)$e->getMessage()) ?: 'Inventaire impossible.'], $e instanceof InvalidArgumentException ? 422 : 500);
}
