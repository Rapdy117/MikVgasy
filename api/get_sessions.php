<?php
require '../config/db.php';
require_once '../includes/device_manager.php';

if (session_status() === PHP_SESSION_NONE) { session_start(); }
header('Content-Type: application/json');
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$username = trim((string)($_GET['username'] ?? ''));
$deviceId = trim((string)($_GET['device_id'] ?? ''));

if ($username === '' || $deviceId === '') {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Username et device_id requis'
    ]);
    exit;
}

$store = loadDeviceStore();
$device = requireAccessibleDevice($store, $deviceId);
$deviceAddress = trim((string)($device['host'] ?? ($device['ip'] ?? '')));
$stmt = $pdo->prepare("
    SELECT acctstarttime, acctstoptime, acctsessiontime,
           acctinputoctets, acctoutputoctets
    FROM radacct
    WHERE username = ? AND nasipaddress = ?
    ORDER BY radacctid DESC
    LIMIT 10
");

$stmt->execute([$username, $deviceAddress]);
echo json_encode([
    'success' => true,
    'sessions' => $stmt->fetchAll(PDO::FETCH_ASSOC)
]);
