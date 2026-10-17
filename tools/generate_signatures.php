#!/usr/bin/env php
<?php
/**
 * Générateur de signatures d'intégrité — PRIVÉ
 * À exécuter après chaque modification des fichiers protégés,
 * avant de packager la distribution client.
 *
 * Usage : php tools/generate_signatures.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI uniquement.');
}

require_once __DIR__ . '/../includes/integrity_check.php';

$root     = realpath(__DIR__ . '/../') . DIRECTORY_SEPARATOR;
$manifest = [];
$errors   = [];

$border = str_repeat('─', 56);
echo "\n{$border}\n";
echo " 🔐  GÉNÉRATEUR DE SIGNATURES D'INTÉGRITÉ\n";
echo "{$border}\n\n";

foreach (PROTECTED_FILES as $relativePath) {
    $normalizedPath = str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
    $absolutePath   = $root . $normalizedPath;

    if (!is_file($absolutePath)) {
        echo " ⚠  Introuvable : {$relativePath}\n";
        $errors[] = $relativePath;
        continue;
    }

    $sig = computeFileSignature($absolutePath);
    $manifest[$relativePath] = $sig;
    echo " ✔  Signé : {$relativePath}\n";
}

if (!empty($errors)) {
    echo "\n ⚠  {$errors[0]} fichier(s) manquant(s) — ignorés.\n";
}

$output = [
    'generated_at' => date('Y-m-d H:i:s'),
    'file_count'   => count($manifest),
    'files'        => $manifest,
];

$targetFile = $root . 'config' . DIRECTORY_SEPARATOR . 'integrity.json';
file_put_contents($targetFile, json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);

echo "\n{$border}\n";
echo " ✅  Manifeste écrit dans config/integrity.json\n";
echo " 📦  " . count($manifest) . " fichier(s) protégé(s)\n";
echo " 🕐  " . $output['generated_at'] . "\n";
echo "{$border}\n\n";
echo " ⚠   Lancez ce script après chaque mise à jour des fichiers\n";
echo "     protégés, avant de distribuer le package client.\n\n";
