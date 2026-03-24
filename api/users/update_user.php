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
$id = $_POST['id'];
$username = trim($_POST['username']);
$password = trim($_POST['password']);
$profile_id = $_POST['profile_id'];
$status = $_POST['status'];

$fullname = $_POST['fullname'] ?? null;
$phone = $_POST['phone'] ?? null;
$email = $_POST['email'] ?? null;
$address = $_POST['address'] ?? null;
$balance = $_POST['balance'] ?? 0;
$expiration_date = $_POST['expiration_date'] ?? null;
$auto_renewal = $_POST['auto_renewal'] ?? 0;

/* RADIUS */
$rate_limit = $_POST['rate_limit'] ?? null;
$simultaneous_use = $_POST['simultaneous_use'] ?? null;
$idle_timeout = $_POST['idle_timeout'] ?? null;
$data_limit = $_POST['data_limit'] ?? null;

if (!$id || !$username) {
    exit("ID ou username manquant");
}

try {
    $pdo->beginTransaction();

    /* =========================
       1. UPDATE USERS
    ========================= */
    $stmt = $pdo->prepare("
        UPDATE users SET
            username = ?,
            password = ?,
            profile_id = ?,
            status = ?,
            fullname = ?,
            phone = ?,
            email = ?,
            address = ?,
            balance = ?,
            expiration_date = ?,
            auto_renewal = ?
        WHERE id = ?
    ");

    $stmt->execute([
        $username,
        $password,
        $profile_id,
        $status,
        $fullname,
        $phone,
        $email,
        $address,
        $balance,
        $expiration_date,
        $auto_renewal,
        $id
    ]);

    /* =========================
       2. PROFILE NAME
    ========================= */
    $stmt = $pdo->prepare("SELECT name FROM profiles WHERE id = ?");
    $stmt->execute([$profile_id]);
    $profile = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$profile) {
        throw new Exception("Profil introuvable");
    }

    $groupname = $profile['name'];

    /* =========================
       3. UPDATE RADCHECK
    ========================= */
    $stmt = $pdo->prepare("
        UPDATE radcheck 
        SET value = ?
        WHERE username = ? AND attribute = 'Cleartext-Password'
    ");
    $stmt->execute([$password, $username]);

    /* =========================
       4. UPDATE RADUSERGROUP
    ========================= */
    $stmt = $pdo->prepare("
        UPDATE radusergroup 
        SET groupname = ?
        WHERE username = ?
    ");
    $stmt->execute([$groupname, $username]);

    /* =========================
       5. RESET RADREPLY
    ========================= */
    $stmt = $pdo->prepare("DELETE FROM radreply WHERE username = ?");
    $stmt->execute([$username]);

    $insertReply = $pdo->prepare("
        INSERT INTO radreply (username, attribute, op, value)
        VALUES (?, ?, ':=', ?)
    ");

    if ($rate_limit) {
        $insertReply->execute([$username, 'Mikrotik-Rate-Limit', $rate_limit]);
    }

    if ($simultaneous_use) {
        $insertReply->execute([$username, 'Simultaneous-Use', $simultaneous_use]);
    }

    if ($idle_timeout) {
        $insertReply->execute([$username, 'Idle-Timeout', $idle_timeout]);
    }

    if ($data_limit) {
        $insertReply->execute([$username, 'Max-Data', $data_limit]);
    }

    if ($expiration_date) {
        $insertReply->execute([$username, 'Expiration', $expiration_date]);
    }

    $pdo->commit();

    echo json_encode([
        "success" => true,
        "message" => "Utilisateur mis à jour + sync OK"
    ]);

} catch (Exception $e) {
    $pdo->rollBack();
    http_response_code(500);
    echo $e->getMessage();
}