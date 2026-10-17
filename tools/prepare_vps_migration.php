<?php
declare(strict_types=1);

/**
 * Prépare un paquet de migration vers le VPS.
 * Usage : php tools/prepare_vps_migration.php [répertoire-sortie]
 * CLI uniquement : les secrets ne sont jamais envoyés par HTTP.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI uniquement.\n");
}

$dbConfig = json_decode((string)file_get_contents(__DIR__ . '/../config/db.json'), true) ?? [];
$host = (string)($dbConfig['host'] ?? 'localhost');
$user = (string)($dbConfig['user'] ?? 'radius_app');
$dbname = (string)($dbConfig['dbname'] ?? 'radius_manager');
$pass = (string)($dbConfig['pass'] ?? '');
if (str_starts_with($pass, 'enc:')) {
    $secret = hash('sha256', 'RadiusMgr|X9f2K1mP|Qz7N3wRt2026', true);
    $key = hash('sha256', $secret . '|AES256|CRYPT', true);
    $raw = base64_decode(substr($pass, 4), true);
    $separator = is_string($raw) ? strpos($raw, ':') : false;
    if ($separator === false) throw new RuntimeException('Mot de passe DB chiffré invalide.');
    $iv = hex2bin(substr($raw, 0, $separator));
    $ciphertext = base64_decode(substr($raw, $separator + 1), true);
    $decrypted = is_string($ciphertext) ? openssl_decrypt($ciphertext, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv) : false;
    if (!is_string($decrypted) || $decrypted === '') throw new RuntimeException('Déchiffrement DB impossible.');
    $pass = $decrypted;
}

$root = realpath(__DIR__ . '/..');
if ($root === false) {
    throw new RuntimeException('Racine applicative introuvable.');
}
$target = $argv[1] ?? '/var/backups/radius-manager/migrations';
$target = rtrim($target, DIRECTORY_SEPARATOR);
if (!is_dir($target) && !mkdir($target, 0700, true) && !is_dir($target)) {
    throw new RuntimeException('Répertoire de sortie indisponible.');
}
chmod($target, 0700);

$stamp = gmdate('Ymd_His');
$work = sys_get_temp_dir() . '/radius_migration_' . bin2hex(random_bytes(8));
if (!mkdir($work, 0700, true)) {
    throw new RuntimeException('Répertoire temporaire indisponible.');
}

$cleanup = static function (string $path): void {
    if (!is_dir($path)) return;
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $file) {
        $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
    }
    @rmdir($path);
};

try {
    $sql = $work . '/radius_manager.sql';
    $defaults = $work . '/mysql.cnf';
    file_put_contents($defaults, "[client]\nhost=" . $host . "\nuser=" . $user . "\npassword=" . $pass . "\n", LOCK_EX);
    chmod($defaults, 0600);

    $dump = '/usr/bin/mysqldump';
    if (!is_executable($dump)) {
        throw new RuntimeException('mysqldump absent : installez mariadb-client.');
    }
    $command = escapeshellarg($dump)
        . ' --defaults-extra-file=' . escapeshellarg($defaults)
        . ' --single-transaction --skip-lock-tables --routines --triggers '
        . escapeshellarg((string)$dbname)
        . ' > ' . escapeshellarg($sql) . ' 2>/dev/null';
    exec($command, $output, $status);
    if ($status !== 0 || !is_file($sql) || filesize($sql) === 0) {
        throw new RuntimeException('Export SQL complet impossible.');
    }

    $runtime = $work . '/application';
    if (!mkdir($runtime, 0700, true)) {
        throw new RuntimeException('Préparation des fichiers impossible.');
    }
    $copy = [
        'config/db.json',
        'config/radius.json',
        'config/radius.php',
        'config/integrity.json',
        'uploads',
        'tools/migrations',
    ];
    foreach ($copy as $relative) {
        $source = $root . '/' . $relative;
        if (!file_exists($source)) continue;
        $destination = $runtime . '/' . $relative;
        if (is_dir($source)) {
            $cmd = 'cp -a ' . escapeshellarg($source) . ' ' . escapeshellarg(dirname($destination));
        } else {
            if (!is_dir(dirname($destination))) mkdir(dirname($destination), 0700, true);
            $cmd = 'cp -p ' . escapeshellarg($source) . ' ' . escapeshellarg($destination);
        }
        exec($cmd, $ignored, $copyStatus);
        if (($copyStatus ?? 0) !== 0) throw new RuntimeException('Copie impossible : ' . $relative);
    }

    $manifest = [
        'created_at_utc' => gmdate('c'),
        'application_root' => $root,
        'database' => (string)$dbname,
        'php' => PHP_VERSION,
        'radius_config_included' => is_file($runtime . '/config/radius.json') || is_file($runtime . '/config/radius.php'),
        'sql_file' => 'radius_manager.sql',
        'runtime_directory' => 'application',
        'restore_notes' => 'Copier application/config dans le runtime VPS, importer radius_manager.sql, puis vérifier les secrets d environnement.',
    ];
    file_put_contents($work . '/MANIFEST.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n", LOCK_EX);

    $archive = $target . '/radius_manager_migration_' . $stamp . '.tar.gz';
    $tar = 'tar -czf ' . escapeshellarg($archive)
        . ' -C ' . escapeshellarg($work) . ' radius_manager.sql application MANIFEST.json';
    exec($tar, $ignored, $tarStatus);
    if ($tarStatus !== 0 || !is_file($archive)) {
        throw new RuntimeException('Création de l’archive de migration impossible.');
    }
    chmod($archive, 0600);
    fwrite(STDOUT, $archive . PHP_EOL);
} finally {
    $cleanup($work);
}
