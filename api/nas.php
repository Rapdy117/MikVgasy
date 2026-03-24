<?php
header('Content-Type: application/json');

require_once '../config/db.php';

try {

    $stmt = $pdo->query("
        SELECT id, nasname, shortname, type 
        FROM nas 
        ORDER BY id ASC
    ");

    $nas = $stmt->fetchAll(PDO::FETCH_ASSOC);

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