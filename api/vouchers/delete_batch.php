<?php
require_once '../../config/db.php';
require_once '../../includes/vouchers.php';
require_once '../../includes/tenancy.php';
session_start();
header('Content-Type: application/json; charset=utf-8');
if (empty($_SESSION['logged_in'])) { http_response_code(403); echo json_encode(['success'=>false,'message'=>'Non autorisé']); exit; }
$role = strtolower(trim((string)($_SESSION['user_role'] ?? '')));
if (!in_array($role, ['admin','superadmin'], true)) { http_response_code(403); echo json_encode(['success'=>false,'message'=>'Suppression réservée à l’administration']); exit; }
$payload = json_decode((string)file_get_contents('php://input'), true); if (!is_array($payload)) $payload=$_POST;
$csrf=trim((string)($payload['csrf_token']??'')); if ($csrf==='' || !hash_equals((string)($_SESSION['csrf_token']??''),$csrf)) { http_response_code(400); echo json_encode(['success'=>false,'message'=>'CSRF invalide']); exit; }
$ids=array_values(array_unique(array_filter(array_map('intval',(array)($payload['ids']??[])),static fn(int $id):bool=>$id>0))); if (!$ids) { http_response_code(400); echo json_encode(['success'=>false,'message'=>'Aucun voucher sélectionné']); exit; }
ensureVouchersTable($pdo);
$marks=implode(',',array_fill(0,count($ids),'?')); $params=$ids;
$where="id IN ($marks)";
if ($role!=='superadmin') { $owner=max(0,(int)($_SESSION['local_admin_id']??0)); $where.=' AND owner_admin_id = ?'; $params[]=$owner; }
$stmt=$pdo->prepare("DELETE FROM vouchers WHERE $where"); $stmt->execute($params);
echo json_encode(['success'=>true,'deleted'=>$stmt->rowCount()]);
