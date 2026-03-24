<?php
require '../../config/db.php';

session_start();

if (!isset($_SESSION['logged_in'])) {
    http_response_code(403);
    exit("Unauthorized");
}

/* =========================
   INPUT
========================= */
$username = $_GET['username'] ?? null;

if (!$username) {
    exit("Username manquant");
}

try {

    /* =========================
       1. GET SESSIONS
    ========================= */
    $stmt = $pdo->prepare("
        SELECT 
            acctstarttime,
            acctstoptime,
            acctsessiontime,
            acctinputoctets,
            acctoutputoctets,
            framedipaddress,
            callingstationid,
            nasipaddress
        FROM radacct
        WHERE username = ?
        ORDER BY acctstarttime DESC
        LIMIT 50
    ");

    $stmt->execute([$username]);
    $sessions = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $result = [];
    $online = false;
    $current_ip = null;
    $current_mac = null;
    $current_nas = null;

    foreach ($sessions as $s) {

        /* =========================
           DATA CALCUL
        ========================= */
        $total_bytes = ($s['acctinputoctets'] ?? 0) + ($s['acctoutputoctets'] ?? 0);
        $total_mb = round($total_bytes / 1024 / 1024, 2);

        /* =========================
           ONLINE DETECTION
        ========================= */
        if (empty($s['acctstoptime'])) {
            $online = true;
            $current_ip = $s['framedipaddress'];
            $current_mac = $s['callingstationid'];
            $current_nas = $s['nasipaddress'];
        }

        $result[] = [
            "start" => $s['acctstarttime'],
            "stop" => $s['acctstoptime'],
            "duration" => $s['acctsessiontime'],
            "data_mb" => $total_mb,
            "ip" => $s['framedipaddress'],
            "mac" => $s['callingstationid'],
            "nas" => $s['nasipaddress']
        ];
    }

    /* =========================
       RESPONSE
    ========================= */
    echo json_encode([
        "success" => true,
        "online" => $online,
        "ip" => $current_ip,
        "mac" => $current_mac,
        "nas" => $current_nas,
        "sessions" => $result
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo $e->getMessage();
}