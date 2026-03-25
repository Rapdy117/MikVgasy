<?php
require '../config/db.php';

header('Content-Type: application/json');

$username = trim((string)($_GET['username'] ?? ''));

if ($username === '') {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Username manquant'
    ]);
    exit;
}

$stmt = $pdo->prepare("
    SELECT acctstarttime, acctstoptime, acctsessiontime,
           acctinputoctets, acctoutputoctets
    FROM radacct
    WHERE username = ?
    ORDER BY radacctid DESC
    LIMIT 10
");

$stmt->execute([$username]);
echo json_encode([
    'success' => true,
    'sessions' => $stmt->fetchAll(PDO::FETCH_ASSOC)
]);
