#!/usr/bin/env php
<?php
/**
 * Migration : chiffre les valeurs sensibles dans les fichiers de config JSON.
 * À exécuter une seule fois après la mise à jour, ou après un reset de config.
 *
 * Usage : php tools/encrypt_configs.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI uniquement.');
}

require_once __DIR__ . '/../includes/crypto.php';

$border = str_repeat('─', 56);
echo "\n{$border}\n";
echo " 🔐  CHIFFREMENT DES CONFIGURATIONS\n";
echo "{$border}\n\n";

$root = realpath(__DIR__ . '/../') . DIRECTORY_SEPARATOR;

/* ── Fichiers et champs à chiffrer ── */
$targets = [
    'config/radius.json' => ['test_pass', 'secret'],
    'config/db.json'     => ['pass'],
];

foreach ($targets as $relativePath => $fields) {
    $absolutePath = $root . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);

    if (!is_file($absolutePath)) {
        echo " ⚠  Introuvable : {$relativePath}\n";
        continue;
    }

    $data = json_decode(file_get_contents($absolutePath), true);
    if (!is_array($data)) {
        echo " ⚠  JSON invalide : {$relativePath}\n";
        continue;
    }

    $changed = false;
    foreach ($fields as $field) {
        if (!isset($data[$field]) || $data[$field] === '') {
            continue;
        }
        if (isEncrypted((string)$data[$field])) {
            continue; // déjà chiffré
        }
        $data[$field] = encryptField((string)$data[$field]);
        $changed = true;
    }

    if ($changed) {
        file_put_contents($absolutePath, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
        echo " ✔  Chiffré : {$relativePath} (" . implode(', ', $fields) . ")\n";
    } else {
        echo " ✔  Déjà chiffré : {$relativePath}\n";
    }
}

/* ── Device store (opnsense.json) ── */
$deviceFile = $root . 'config' . DIRECTORY_SEPARATOR . 'opnsense.json';
if (is_file($deviceFile)) {
    $data = json_decode(file_get_contents($deviceFile), true);
    if (is_array($data) && isset($data['devices'])) {
        $changed = false;
        foreach ($data['devices'] as &$device) {
            foreach (['api_key', 'api_secret', 'secret'] as $field) {
                if (!empty($device[$field]) && !isEncrypted((string)$device[$field])) {
                    $device[$field] = encryptField((string)$device[$field]);
                    $changed = true;
                }
            }
        }
        unset($device);
        if ($changed) {
            file_put_contents($deviceFile, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
            echo " ✔  Chiffré : config/opnsense.json (api_key, api_secret, secret)\n";
        } else {
            echo " ✔  Déjà chiffré : config/opnsense.json\n";
        }
    }
}

/* ── Mots de passe utilisateurs en base de données ── */
echo "\n Migration des mots de passe utilisateurs (DB)...\n";
try {
    require_once $root . 'config' . DIRECTORY_SEPARATOR . 'db.php';
    $stmt  = $pdo->query("SELECT id, password FROM users WHERE password != '' AND password IS NOT NULL");
    $rows  = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $count = 0;
    $upd   = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
    foreach ($rows as $row) {
        $pwd = (string)($row['password'] ?? '');
        if ($pwd === '' || isEncrypted($pwd)) {
            continue; // déjà chiffré ou vide
        }
        $upd->execute([encryptField($pwd), $row['id']]);
        $count++;
    }
    echo " ✔  " . count($rows) . " utilisateur(s) trouvé(s), {$count} mot(s) de passe chiffré(s).\n";
} catch (\Throwable $e) {
    echo " ⚠  DB: " . $e->getMessage() . "\n";
}

echo "\n{$border}\n";
echo " ✅  Chiffrement terminé.\n";
echo " ℹ️   Les valeurs sont déchiffrées automatiquement à l'exécution.\n";
echo "{$border}\n\n";
