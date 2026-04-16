<?php
session_start();

require_once __DIR__ . '/../../includes/message.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../config/db.php';

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    set_message('Accès refusé.', 'danger');
    header('Location: /index.php');
    exit();
}
requireAdministratorAccess();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method Not Allowed');
}

try {
    $csrf = trim((string)($_POST['csrf_token'] ?? ''));
    if ($csrf === '' || !hash_equals($_SESSION['csrf_token'] ?? '', $csrf)) {
        throw new RuntimeException('CSRF invalide');
    }

    if (!isset($_FILES['sql_file']) || !is_array($_FILES['sql_file'])) {
        throw new RuntimeException('Fichier SQL manquant');
    }

    $upload = $_FILES['sql_file'];
    if ((int)($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Import SQL impossible');
    }

    $tmpPath = (string)($upload['tmp_name'] ?? '');
    $originalName = (string)($upload['name'] ?? '');
    if ($tmpPath === '' || !is_uploaded_file($tmpPath)) {
        throw new RuntimeException('Fichier import invalide');
    }

    $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    if ($extension !== 'sql') {
        throw new RuntimeException('Le fichier doit être au format .sql');
    }

    $dbHost = $host ?? 'localhost';
    $dbName = $dbname ?? 'radius_manager';
    $dbUser = $user ?? 'radius_app';
    $dbPass = $pass ?? '';

    $command = sprintf(
        '/usr/bin/mysql -h %s -u %s -p%s %s < %s 2>/dev/null',
        escapeshellarg($dbHost),
        escapeshellarg($dbUser),
        escapeshellarg($dbPass),
        escapeshellarg($dbName),
        escapeshellarg($tmpPath)
    );

    exec($command, $output, $status);
    if ($status !== 0) {
        throw new RuntimeException('Échec de l import SQL');
    }

    set_message('Base de données importée.', 'success');
} catch (Throwable $e) {
    set_message($e->getMessage(), 'danger');
}

header('Location: /pages/administration.php');
exit();
