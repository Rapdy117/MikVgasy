<?php
/**
 * Chiffrement AES-256-CBC des données sensibles.
 * Clé dérivée du secret HMAC de la licence — même racine, usage différent.
 *
 * Format chiffré : "enc:<base64(iv_hex:ciphertext_base64)>"
 * Valeurs non préfixées "enc:" = texte clair → compatibilité migration.
 */

require_once __DIR__ . '/license.php';

const CRYPTO_PREFIX = 'enc:';

/**
 * Dérive la clé AES-256 depuis le secret licence.
 * 32 octets = AES-256.
 */
function getCryptoKey(): string
{
    return hash('sha256', getLicenseSecret() . '|AES256|CRYPT', true);
}

/**
 * Chiffre une valeur. Retourne la valeur telle quelle si vide.
 */
function encryptField(string $value): string
{
    if ($value === '' || str_starts_with($value, CRYPTO_PREFIX)) {
        return $value; // déjà chiffré ou vide
    }

    $key = getCryptoKey();
    $iv  = random_bytes(16);

    $ciphertext = openssl_encrypt($value, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
    if ($ciphertext === false) {
        throw new RuntimeException('Erreur de chiffrement AES-256-CBC');
    }

    return CRYPTO_PREFIX . base64_encode(bin2hex($iv) . ':' . base64_encode($ciphertext));
}

/**
 * Déchiffre une valeur.
 * Si non préfixée "enc:" → retourne telle quelle (migration transparente).
 */
function decryptField(string $value): string
{
    if ($value === '' || !str_starts_with($value, CRYPTO_PREFIX)) {
        return $value; // texte clair ou vide → compatibilité
    }

    $raw   = base64_decode(substr($value, strlen(CRYPTO_PREFIX)));
    $colon = strpos($raw, ':');

    if ($colon === false) {
        return $value; // format inconnu → retourne tel quel
    }

    $iv         = hex2bin(substr($raw, 0, $colon));
    $ciphertext = base64_decode(substr($raw, $colon + 1));
    $key        = getCryptoKey();

    $plaintext = openssl_decrypt($ciphertext, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);

    return $plaintext !== false ? $plaintext : $value;
}

/**
 * Chiffre un tableau associatif de champs sensibles.
 */
function encryptFields(array $data, array $fields): array
{
    foreach ($fields as $field) {
        if (isset($data[$field]) && $data[$field] !== '') {
            $data[$field] = encryptField((string)$data[$field]);
        }
    }
    return $data;
}

/**
 * Déchiffre un tableau associatif de champs sensibles.
 */
function decryptFields(array $data, array $fields): array
{
    foreach ($fields as $field) {
        if (isset($data[$field]) && $data[$field] !== '') {
            $data[$field] = decryptField((string)$data[$field]);
        }
    }
    return $data;
}

/**
 * Vérifie que la valeur est chiffrée.
 */
function isEncrypted(string $value): bool
{
    return str_starts_with($value, CRYPTO_PREFIX);
}
