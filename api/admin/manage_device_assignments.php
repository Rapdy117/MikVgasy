<?php
declare(strict_types=1);
session_start(); require_once __DIR__ . '/../../config/db.php'; require_once __DIR__ . '/../../includes/auth.php';
header('Content-Type: application/json; charset=utf-8');
if (empty($_SESSION['logged_in']) || !isAdministrator()) { http_response_code(403); echo json_encode(['success'=>false,'message'=>'Accès refusé']); exit; }
$target=(int)($_REQUEST['admin_id']??0); if($target<=0){http_response_code(422);echo json_encode(['success'=>false,'message'=>'Utilisateur invalide']);exit;}
$current=(int)($_SESSION['local_admin_id']??0); if(!isSuperAdministrator() && $target!==$current){http_response_code(403);echo json_encode(['success'=>false,'message'=>'Périmètre administrateur invalide']);exit;}
if($_SERVER['REQUEST_METHOD']==='GET'){ $q=$pdo->prepare('SELECT d.id,d.name,d.type,CASE WHEN a.device_id IS NULL THEN 0 ELSE 1 END assigned FROM network_devices d LEFT JOIN admin_device_assignments a ON a.device_id=d.id AND a.admin_id=? WHERE d.is_active=1 ORDER BY d.name,d.id'); $q->execute([$target]); echo json_encode(['success'=>true,'devices'=>$q->fetchAll(PDO::FETCH_ASSOC)]); exit; }
if(!hash_equals((string)($_SESSION['csrf_token']??''),(string)($_POST['csrf_token']??''))){http_response_code(403);echo json_encode(['success'=>false,'message'=>'CSRF invalide']);exit;}
$device=trim((string)($_POST['device_id']??'')); requireAccessibleDevice(loadDeviceStore(), $device); $action=(string)($_POST['assignment_action']??''); if($device===''||!in_array($action,['add','remove'],true)){http_response_code(422);echo json_encode(['success'=>false,'message'=>'Attribution invalide']);exit;}
if($action==='add'){ $q=$pdo->prepare('INSERT IGNORE INTO admin_device_assignments(admin_id,device_id,created_by_user_id) SELECT ?,id,? FROM network_devices WHERE id=? AND is_active=1'); $q->execute([$target,$current,$device]); } else { $q=$pdo->prepare('DELETE FROM admin_device_assignments WHERE admin_id=? AND device_id=?'); $q->execute([$target,$device]); } echo json_encode(['success'=>true]);
