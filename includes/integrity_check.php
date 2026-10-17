<?php
/**
 * Vérification d'intégrité des fichiers critiques.
 * Les signatures sont générées par tools/generate_signatures.php (privé).
 * Si un fichier protégé a été modifié, toute opération backend est bloquée.
 */

require_once __DIR__ . '/license.php';

const INTEGRITY_FILE = __DIR__ . '/../config/integrity.json';

/* Fichiers protégés — chemins relatifs à la racine du projet */
const PROTECTED_FILES = [
    'includes/nas_resolver.php',
    'includes/license.php',
    'includes/integrity_check.php',
    'api/license/activate_license.php',
    'api/license/request_license.php',
];

/**
 * Calcule la signature HMAC d'un fichier.
 * signature = HMAC-SHA256( SHA256(contenu_fichier), secret_licence )
 */
function computeFileSignature(string $absolutePath): string
{
    if (!is_file($absolutePath)) {
        throw new RuntimeException("Fichier introuvable : " . basename($absolutePath));
    }
    $hash = hash_file('sha256', $absolutePath);
    return hash_hmac('sha256', $hash, getLicenseSecret());
}

/**
 * Charge le manifeste d'intégrité.
 */
function loadIntegrityManifest(): array
{
    if (!is_file(INTEGRITY_FILE)) {
        return [];
    }
    $data = json_decode((string)file_get_contents(INTEGRITY_FILE), true);
    return is_array($data) ? ($data['files'] ?? []) : [];
}

/**
 * Vérifie l'intégrité de tous les fichiers protégés.
 * Lève une exception si un fichier a été altéré.
 * Résultat mis en cache pour la durée de la requête.
 */
function verifyFilesIntegrity(): void
{
    static $verified = false;
    if ($verified) {
        return;
    }

    $manifest = loadIntegrityManifest();

    /* Pas encore de manifeste → on ne bloque pas (première installation) */
    if (empty($manifest)) {
        $verified = true;
        return;
    }

    $root = realpath(__DIR__ . '/../') . DIRECTORY_SEPARATOR;

    foreach (PROTECTED_FILES as $relativePath) {
        $normalizedPath = str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
        $absolutePath   = $root . $normalizedPath;

        /* Fichier non présent dans le manifeste → ignoré */
        if (!isset($manifest[$relativePath])) {
            continue;
        }

        $expectedSig = (string)$manifest[$relativePath];
        $actualSig   = computeFileSignature($absolutePath);

        if (!hash_equals($expectedSig, $actualSig)) {
            throw new RuntimeException(
                "⛔ Fichier corrompu détecté : {$relativePath}\n" .
                "Ce fichier a été modifié. L'accès au backend est bloqué.\n" .
                "Réinstallez l'application ou contactez l'administrateur."
            );
        }
    }

    $verified = true;
}
