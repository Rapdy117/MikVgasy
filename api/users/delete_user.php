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
$id = $_POST['id'] ?? null;

if (!$id) {
    exit("ID manquant");
}

try {
    $pdo->beginTransaction();

    /* =========================
       1. GET USERNAME
    ========================= */
    $stmt = $pdo->prepare("SELECT username FROM users WHERE id = ?");
    $stmt->execute([$id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        throw new Exception("Utilisateur introuvable");
    }

    $username = $user['username'];

    /* =========================
       2. DELETE RADIUS
    ========================= */

    // radcheck
    $stmt = $pdo->prepare("DELETE FROM radcheck WHERE username = ?");
    $stmt->execute([$username]);

    // radreply
    $stmt = $pdo->prepare("DELETE FROM radreply WHERE username = ?");
    $stmt->execute([$username]);

    // radusergroup
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

    $pdo->commit();

    echo json_encode([
        "success" => true,
        "message" => "Utilisateur supprimé + sync OK"
    ]);

} catch (Exception $e) {
    $pdo->rollBack();
    http_response_code(500);
    echo $e->getMessage();
}