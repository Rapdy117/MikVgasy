<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/license.php';
header('Content-Type: application/json; charset=UTF-8');
if (empty($_SESSION['logged_in']) || !isSuperAdministrator()) { http_response_code(403); echo json_encode(['success'=>false,'message'=>'Accès réservé au superadmin.']); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !hash_equals((string)($_SESSION['csrf_token'] ?? ''), (string)($_POST['csrf_token'] ?? ''))) { http_response_code(403); echo json_encode(['success'=>false,'message'=>'CSRF invalide.']); exit; }
try {
    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) throw new RuntimeException('Licence invalide.');
    ensureLicenseRegistry($pdo);
    $stmt = $pdo->prepare('SELECT device_id,license_key FROM device_licenses WHERE id=? LIMIT 1'); $stmt->execute([$id]);
    $license = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$license) throw new RuntimeException('Licence introuvable.');
    $pdo->beginTransaction();
    $delete = $pdo->prepare('DELETE FROM device_licenses WHERE id=?'); $delete->execute([$id]);
    $clear = $pdo->prepare("UPDATE network_devices SET license_key=NULL,license_status='unlicensed',license_expiry=NULL,license_issued=NULL,updated_at=NOW() WHERE id=? AND license_key=?");
    $clear->execute([(string)$license['device_id'], (string)$license['license_key']]);
    $pdo->commit();
    echo json_encode(['success'=>true,'message'=>'Licence supprimée.']);
} catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); http_response_code(422); echo json_encode(['success'=>false,'message'=>$e->getMessage()]); }
