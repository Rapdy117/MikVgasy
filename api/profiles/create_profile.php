<?php
header('Content-Type: application/json');

require_once '../../config/db.php';
require_once '../../includes/radius_sync.php';

try {

    // 🔹 1. récupérer POST
    $name  = $_POST['profile_name'] ?? null;
    $rate  = $_POST['rate_limit'] ?? null;
    $session = $_POST['session_timeout'] ?? null;
    $idle = $_POST['idle_timeout'] ?? null;
    $data = $_POST['data_limit'] ?? null;
    $simu = $_POST['simultaneous_use'] ?? 1;
    $nas_id = $_POST['nas_id'] ?? null;

    if (!$name || !$nas_id) {
        throw new Exception("Champs obligatoires manquants");
    }

    // 🔹 2. récupérer type NAS
    $stmt = $pdo->prepare("SELECT type FROM nas WHERE id = ?");
    $stmt->execute([$nas_id]);
    $nas = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$nas) {
        throw new Exception("NAS introuvable");
    }

    $nasType = $nas['type'];

    // 🔹 3. enregistrer profil
    $stmt = $pdo->prepare("
        INSERT INTO profiles 
        (name, rate_limit, session_timeout, idle_timeout, data_quota_mb, simultaneous_use)
        VALUES (?, ?, ?, ?, ?, ?)
    ");

    $stmt->execute([
        $name,
        $rate,
        $session,
        $idle,
        $data,
        $simu
    ]);

    // 🔹 4. sync vers RADIUS
    syncProfileToRadius($pdo, [
        'name' => $name,
        'rate_limit' => $rate,
        'session_timeout' => $session,
        'idle_timeout' => $idle,
        'data_quota_mb' => $data,
        'simultaneous_use' => $simu
    ], $nasType);

    echo json_encode([
        'success' => true,
        'message' => 'Profil créé avec succès'
    ]);

} catch (Exception $e) {

    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);

}