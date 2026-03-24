<?php
require '../config/db.php';

$username = $_GET['username'] ?? '';

$stmt = $pdo->prepare("
    SELECT acctstarttime, acctstoptime, acctsessiontime,
           acctinputoctets, acctoutputoctets
    FROM radacct
    WHERE username = ?
    ORDER BY radacctid DESC
    LIMIT 10
");

$stmt->execute([$username]);
echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));