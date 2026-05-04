<?php
require '../../config/db.php';
require_once '../../includes/recharge_history_store.php';

session_start();

header('Content-Type: application/json');

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'message' => 'Unauthorized',
    ]);
    exit;
}

try {
    ensureRechargeHistoryTable($pdo);

    $deviceId = trim((string)($_GET['device_id'] ?? ''));
    $username = trim((string)($_GET['username'] ?? ''));
    $limit = (int)($_GET['limit'] ?? 4);
    if ($limit < 1) {
        $limit = 4;
    }
    if ($limit > 20) {
        $limit = 20;
    }

    $where = [];
    $params = [];

    if ($deviceId !== '') {
        $where[] = 'device_id = :device_id';
        $params[':device_id'] = $deviceId;
    }

    if ($username !== '') {
        $where[] = 'username = :username';
        $params[':username'] = $username;
    }

    $sql = 'SELECT created_at, username, profile_name, mode, operator_username, effect_summary
            FROM recharge_history';
    if ($where) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }
    $sql .= ' ORDER BY created_at DESC, id DESC LIMIT ' . $limit;

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $items = array_map(static function (array $row): array {
        $modeLabel = match ((string)($row['mode'] ?? '')) {
            'replace_offer' => 'Remplacer',
            'extend_offer' => 'Rajout',
            'accumulate_offer' => 'Cumuler',
            default => (string)($row['mode'] ?? '-'),
        };

        return [
            'date' => (string)($row['created_at'] ?? '-'),
            'username' => (string)($row['username'] ?? '-'),
            'profile' => (string)($row['profile_name'] ?? '-'),
            'mode' => $modeLabel,
            'operator' => (string)($row['operator_username'] ?? '-'),
            'effect' => (string)($row['effect_summary'] ?? '-'),
        ];
    }, $rows);

    echo json_encode([
        'success' => true,
        'items' => $items,
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
    ]);
}
