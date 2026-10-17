<?php
session_start();

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    http_response_code(403);
    exit('Unauthorized');
}
if (!isSuperAdministrator()) {
    http_response_code(403);
    exit('Accès réservé au superadmin.');
}

$dbHost = $host ?? 'localhost';
$dbName = $dbname ?? 'radius_manager';
$dbUser = $user ?? 'radius_app';
$dbPass = $pass ?? '';
$filename = 'radius_manager_' . gmdate('Ymd_His') . '.sql';
$tmpFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $filename;

// Tables de telemetrie trafic : volumineuses (jusqu'a plusieurs Go), sans valeur metier pour une restauration.
// Exclues des donnees de l'export, leur structure est tout de meme incluse (--no-data) pour garder un export
// restaurable a l'identique. Les memes tables ont une retention 120 jours (api/mikrotik_traffic_listener.php,
// includes/mikrotik_wan_usage_collector.php) : un export complet n'a plus besoin de les transporter aussi.
$heavyTelemetryTables = [
    'mikrotik_traffic_samples',
    'mikrotik_traffic_user_usage_samples',
    'mikrotik_traffic_dns_signature_samples',
    'mikrotik_traffic_dns_client_signature_samples',
];
$ignoreTableFlags = '';
foreach ($heavyTelemetryTables as $heavyTable) {
    $ignoreTableFlags .= ' --ignore-table=' . escapeshellarg($dbName . '.' . $heavyTable);
}

$command = sprintf(
    '/usr/bin/mysqldump --single-transaction --skip-lock-tables%s -h %s -u %s -p%s %s > %s 2>/dev/null',
    $ignoreTableFlags,
    escapeshellarg($dbHost),
    escapeshellarg($dbUser),
    escapeshellarg($dbPass),
    escapeshellarg($dbName),
    escapeshellarg($tmpFile)
);

exec($command, $output, $status);

if ($status !== 0 || !is_file($tmpFile)) {
    http_response_code(500);
    exit('Export SQL impossible');
}

$structureCommand = sprintf(
    '/usr/bin/mysqldump --no-data --skip-lock-tables -h %s -u %s -p%s %s %s >> %s 2>/dev/null',
    escapeshellarg($dbHost),
    escapeshellarg($dbUser),
    escapeshellarg($dbPass),
    escapeshellarg($dbName),
    implode(' ', array_map('escapeshellarg', $heavyTelemetryTables)),
    escapeshellarg($tmpFile)
);
exec($structureCommand, $structureOutput, $structureStatus);
// Non bloquant : si cette passe echoue, l'export principal reste valide, seules ces 4 structures manqueront.

header('Content-Type: application/sql');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . filesize($tmpFile));
readfile($tmpFile);
@unlink($tmpFile);
