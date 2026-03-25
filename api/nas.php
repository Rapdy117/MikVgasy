<?php
header('Content-Type: application/json');

require_once '../config/db.php';
require_once '../includes/nas_resolver.php';

try {

    $stmt = $pdo->query("
        SELECT id, nasname, shortname, type 
        FROM nas 
        ORDER BY id ASC
    ");

    $nas = array_map(function (array $item): array {
        $type = (string)($item['type'] ?? 'other');

        $item['backend'] = resolveNasBackend($type);
        $item['capabilities'] = resolveNasCapabilities($type);

        return $item;
    }, $stmt->fetchAll(PDO::FETCH_ASSOC));

    echo json_encode([
        'success' => true,
        'data' => $nas
    ]);

} catch (Exception $e) {

    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);

}
