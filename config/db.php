<?php

/* ── Déchiffrement autonome (sans dépendance sur includes/) ── */
function _rm_cfg_decrypt(string $enc): string
{
    if ($enc === '' || strpos($enc, 'enc:') !== 0) {
        return $enc;
    }
    /* Clé dérivée des mêmes constantes que crypto.php/license.php */
    $_s  = hash('sha256', 'RadiusMgr|X9f2K1mP|Qz7N3wRt2026', true); /* getLicenseSecret() */
    $k   = hash('sha256', $_s . '|AES256|CRYPT', true);               /* getCryptoKey() */
    $raw = base64_decode(substr($enc, 4));
    $pos = strpos($raw, ':');
    if ($pos === false) { return $enc; }
    $iv = hex2bin(substr($raw, 0, $pos));
    $ct = base64_decode(substr($raw, $pos + 1));
    $pt = openssl_decrypt($ct, 'AES-256-CBC', $k, OPENSSL_RAW_DATA, $iv);
    return $pt !== false ? $pt : $enc;
}

/* ── Lecture depuis db.json (chiffré) avec fallback sur valeurs statiques ── */
$_dbCfg = [];
$_dbFile = __DIR__ . '/db.json';
if (is_file($_dbFile)) {
    $_dbCfg = json_decode((string)file_get_contents($_dbFile), true) ?? [];
}

$host    = (string)($_dbCfg['host']   ?? 'localhost');
$dbname  = (string)($_dbCfg['dbname'] ?? 'radius_manager');
$user    = (string)($_dbCfg['user']   ?? 'radius_app');
$pass    = _rm_cfg_decrypt((string)($_dbCfg['pass'] ?? 'StrongPass123!'));
$charset = 'utf8';

try {
    $pdo = new PDO(
        "mysql:host={$host};dbname={$dbname};charset={$charset}",
        $user,
        $pass,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
        ]
    );

    $pdo->exec("SET NAMES utf8");

} catch (PDOException $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Erreur DB: ' . $e->getMessage()
    ]);
    exit;
}
