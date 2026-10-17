<?php
session_start();
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || !isSuperAdministrator()) {
    http_response_code(403);
    exit('Accès réservé au superadmin.');
}

$dbHost = $host ?? 'localhost';
$dbName = $dbname ?? 'radius_manager';
$dbUser = $user ?? 'radius_app';
$dbPass = $pass ?? '';
$filename = 'radius_manager_squelette_' . gmdate('Ymd_His') . '.sql';
$tmpFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $filename;
$command = sprintf(
    '/usr/bin/mysqldump --no-data --skip-lock-tables -h %s -u %s -p%s %s > %s 2>/dev/null',
    escapeshellarg($dbHost),
    escapeshellarg($dbUser),
    escapeshellarg($dbPass),
    escapeshellarg($dbName),
    escapeshellarg($tmpFile)
);
exec($command, $output, $status);
if ($status !== 0 || !is_file($tmpFile)) {
    http_response_code(500);
    exit('Export squelette SQL impossible.');
}
header('Content-Type: application/sql');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . filesize($tmpFile));
readfile($tmpFile);
@unlink($tmpFile);
