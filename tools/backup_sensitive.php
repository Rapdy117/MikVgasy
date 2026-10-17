<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI uniquement.\n"); }
require_once __DIR__ . '/../config/db.php';

$root = realpath(__DIR__ . '/..');
$backupDir = '/var/backups/radius-manager';
if (!$root || (!is_dir($backupDir) && !mkdir($backupDir, 0750, true) && !is_dir($backupDir))) {
    throw new RuntimeException('Répertoire de sauvegarde indisponible.');
}
$stamp = gmdate('Ymd_His');
$work = sys_get_temp_dir() . '/radius_sensitive_' . bin2hex(random_bytes(6));
if (!mkdir($work, 0700, true)) throw new RuntimeException('Création du dossier temporaire impossible.');
try {
    $sql = $work . '/radius_manager_sensitive.sql';
    $tables = ['users','profiles','vouchers','recharge_history','recouvrement_invoices','radcheck','radreply','radusergroup','radacct'];
    $parts = [];
    foreach ($tables as $table) {
        $check = $pdo->prepare('SHOW TABLES LIKE ?'); $check->execute([$table]);
        if ($check->fetchColumn()) $parts[] = $table;
    }
    if ($parts === []) throw new RuntimeException('Aucune table sensible disponible.');
    $command = '/usr/bin/mysqldump --single-transaction --skip-lock-tables -h ' . escapeshellarg((string)$host)
        . ' -u ' . escapeshellarg((string)$user) . ' -p' . escapeshellarg((string)$pass)
        . ' ' . escapeshellarg((string)$dbname) . ' ' . implode(' ', array_map('escapeshellarg', $parts))
        . ' > ' . escapeshellarg($sql) . ' 2>/dev/null';
    exec($command, $out, $status);
    if ($status !== 0 || !is_file($sql)) throw new RuntimeException('Export SQL sensible impossible.');
    $cfg = $work . '/radius-config'; mkdir($cfg, 0700, true);
    foreach (['config/radius.json','config/radius.php'] as $relative) {
        $source = $root . '/' . $relative;
        if (is_file($source)) { @mkdir(dirname($cfg . '/' . $relative), 0700, true); copy($source, $cfg . '/' . $relative); }
    }
    $archive = $backupDir . '/radius_manager_sensitive_' . $stamp . '.tar.gz';
    $tar = 'tar -czf ' . escapeshellarg($archive) . ' -C ' . escapeshellarg($work) . ' radius_manager_sensitive.sql radius-config';
    exec($tar, $out, $status);
    if ($status !== 0 || !is_file($archive)) throw new RuntimeException('Création de l archive sensible impossible.');
    chmod($archive, 0600);
    fwrite(STDOUT, $archive . PHP_EOL);
} finally {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($work, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($iterator as $file) { $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname()); }
    @rmdir($work);
}
