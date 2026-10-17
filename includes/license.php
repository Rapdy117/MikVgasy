<?php
/**
 * Système de licence par routeur (Device ID)
 * 1 routeur physique = 1 clé de licence
 */

/* ── Secret HMAC (NE PAS MODIFIER après déploiement) ── */
define('_RM_L1', 'RadiusMgr');
define('_RM_L2', 'X9f2K1mP');
define('_RM_L3', 'Qz7N3wRt2026');

function ensureLicenseRegistry(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS device_licenses (
        id INT(11) NOT NULL AUTO_INCREMENT,
        device_id VARCHAR(191) NOT NULL,
        license_key TEXT NOT NULL,
        license_fingerprint VARCHAR(128) DEFAULT NULL,
        expiry VARCHAR(64) DEFAULT NULL,
        status VARCHAR(24) NOT NULL DEFAULT 'generated',
        generated_by_user_id INT(11) DEFAULT NULL,
        generated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        activated_at DATETIME DEFAULT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY idx_device_license_key (device_id, license_key(191)),
        KEY idx_device_license_status (device_id, status),
        KEY idx_device_license_expiry (expiry)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
    foreach ([
        'license_type' => "VARCHAR(16) NOT NULL DEFAULT 'lifetime'",
        'holder_name' => 'VARCHAR(160) DEFAULT NULL',
        'holder_contact' => 'VARCHAR(80) DEFAULT NULL',
        'holder_email' => 'VARCHAR(190) DEFAULT NULL',
    ] as $column => $definition) {
        $check = $pdo->prepare("SHOW COLUMNS FROM device_licenses LIKE ?");
        $check->execute([$column]);
        if (!$check->fetchColumn()) {
            $pdo->exec("ALTER TABLE device_licenses ADD COLUMN `{$column}` {$definition}");
        }
    }
    $pdo->exec("INSERT INTO device_licenses (device_id, license_key, license_fingerprint, expiry, status, generated_at, activated_at)
        SELECT d.id, d.license_key, d.device_fingerprint, NULLIF(d.license_expiry, ''),
               CASE WHEN d.license_status = 'active' THEN 'active' ELSE 'generated' END,
               COALESCE(d.created_at, NOW()),
               CASE WHEN d.license_status = 'active' THEN COALESCE(d.updated_at, NOW()) ELSE NULL END
        FROM network_devices d
        WHERE COALESCE(d.license_key, '') <> ''
          AND NOT EXISTS (SELECT 1 FROM device_licenses l WHERE l.device_id=d.id AND l.license_key=d.license_key)");
}

function registerGeneratedLicense(PDO $pdo, string $deviceId, string $licenseKey, string $fingerprint, string $expiry, string $licenseType = 'lifetime', string $holderName = '', string $holderContact = '', string $holderEmail = ''): void
{
    ensureLicenseRegistry($pdo);
    $stmt = $pdo->prepare("INSERT INTO device_licenses (device_id,license_key,license_fingerprint,expiry,license_type,holder_name,holder_contact,holder_email,status,generated_by_user_id) VALUES (?,?,?,?,?,?,?,?, 'generated', ?) ON DUPLICATE KEY UPDATE expiry=VALUES(expiry),license_type=VALUES(license_type),holder_name=VALUES(holder_name),holder_contact=VALUES(holder_contact),holder_email=VALUES(holder_email),status='generated',generated_by_user_id=VALUES(generated_by_user_id),generated_at=CURRENT_TIMESTAMP");
    $stmt->execute([$deviceId, $licenseKey, $fingerprint, $expiry, $licenseType, $holderName ?: null, $holderContact ?: null, $holderEmail ?: null, max(0,(int)($_SESSION['local_admin_id'] ?? 0)) ?: null]);
}

function markLicenseActivated(PDO $pdo, string $deviceId, string $licenseKey, string $expiry): void
{
    ensureLicenseRegistry($pdo);
    $stmt=$pdo->prepare("UPDATE device_licenses SET status='active',expiry=?,activated_at=CURRENT_TIMESTAMP WHERE device_id=? AND license_key=?");
    $stmt->execute([$expiry,$deviceId,$licenseKey]);
}

function getLicenseSecret(): string
{
    $configured = trim((string)(getenv('RADIUS_MANAGER_LICENSE_SECRET') ?: ($_ENV['RADIUS_MANAGER_LICENSE_SECRET'] ?? $_SERVER['RADIUS_MANAGER_LICENSE_SECRET'] ?? '')));
    return hash('sha256', $configured !== '' ? $configured : (_RM_L1 . '|' . _RM_L2 . '|' . _RM_L3), true);
}

/* ── Préfixes lisibles par type de routeur ── */
function getDevicePrefix(string $type): string
{
    return match (strtolower(trim($type))) {
        'mikrotik'  => 'MK',
        'opnsense'  => 'OPN',
        'radius'    => 'RAD',
        default     => 'DEV',
    };
}

/**
 * Calcule le fingerprint SHA-256 depuis les infos hardware du routeur.
 * Pour MikroTik : serial-number + board-name
 * Pour OPNsense : product_id + hostname
 */
function computeDeviceFingerprint(array $hardwareInfo): string
{
    $parts = array_filter([
        trim((string)($hardwareInfo['serial']    ?? '')),
        trim((string)($hardwareInfo['board']     ?? '')),
        trim((string)($hardwareInfo['product']   ?? '')),
        trim((string)($hardwareInfo['hostname']  ?? '')),
    ]);

    if (empty($parts)) {
        throw new RuntimeException('Hardware info insuffisant pour générer un fingerprint.');
    }

    return hash('sha256', implode('|', $parts));
}

/**
 * Formate le Device ID lisible : TYPE-XXXX-XXXX-XXXX
 */
function formatDeviceId(string $fingerprint, string $deviceType): string
{
    $prefix = getDevicePrefix($deviceType);
    $hex    = strtoupper(substr($fingerprint, 0, 12));
    return sprintf('%s-%s-%s-%s', $prefix, substr($hex, 0, 4), substr($hex, 4, 4), substr($hex, 8, 4));
}

/**
 * Génère une clé de licence pour un fingerprint donné.
 * Génération via l’API superadmin appelée depuis tools/install.php
 */
function generateLicenseKey(string $fingerprint, string $expiry = 'never'): string
{
    $payload = json_encode([
        'fp'  => substr(strtolower($fingerprint), 0, 12),
        'exp' => $expiry,
        'iss' => date('Y-m-d'),
    ], JSON_UNESCAPED_SLASHES);

    $payloadB64 = rtrim(strtr(base64_encode($payload), '+/', '-_'), '=');
    $signature  = hash_hmac('sha256', $payloadB64, getLicenseSecret());
    $sigShort   = strtoupper(substr($signature, 0, 32));

    /* Format : LIC-XXXX-XXXX-XXXX-XXXX-XXXX-XXXX-XXXX-XXXX */
    $chunks = str_split($sigShort, 4);
    return 'LIC-' . $payloadB64 . '.' . implode('-', $chunks);
}

/**
 * Valide une clé de licence contre un fingerprint de routeur.
 * Retourne : ['valid' => bool, 'expired' => bool, 'expiry' => string]
 */
function validateLicenseKey(string $licenseKey, string $fingerprint): array
{
    $invalid = ['valid' => false, 'expired' => false, 'expiry' => null];

    if (trim($licenseKey) === '' || !str_starts_with($licenseKey, 'LIC-')) {
        return $invalid;
    }

    /* Sépare payload.signature */
    $body = substr($licenseKey, 4); // retire "LIC-"
    $dotPos = strpos($body, '.');
    if ($dotPos === false) {
        return $invalid;
    }

    $payloadB64 = substr($body, 0, $dotPos);
    $sigPart    = str_replace('-', '', substr($body, $dotPos + 1));

    /* Vérifie la signature */
    $expectedSig = strtoupper(substr(hash_hmac('sha256', $payloadB64, getLicenseSecret()), 0, 32));
    if (!hash_equals($expectedSig, strtoupper($sigPart))) {
        return $invalid;
    }

    /* Décode le payload */
    $json = base64_decode(strtr($payloadB64, '-_', '+/'));
    $data = json_decode($json, true);
    if (!is_array($data)) {
        return $invalid;
    }

    /* Vérifie le fingerprint — compare les 12 premiers hex (= suffixe du Device ID) */
    if (strtolower((string)($data['fp'] ?? '')) !== substr(strtolower($fingerprint), 0, 12)) {
        return $invalid;
    }

    /* Vérifie l'expiration */
    $expiry  = (string)($data['exp'] ?? 'never');
    $expired = false;
    if ($expiry !== 'never' && $expiry !== '') {
        $expired = strtotime($expiry) < time();
    }

    return [
        'valid'   => !$expired,
        'expired' => $expired,
        'expiry'  => $expiry,
        'issued'  => $data['iss'] ?? null,
    ];
}

/**
 * Retourne le statut de licence d'un device enregistré.
 */
function synchronizeLicenseStatus(PDO $pdo, array $device): array
{
    ensureLicenseRegistry($pdo);
    $key = trim((string)($device['license_key'] ?? ''));
    $fp = trim((string)($device['device_fingerprint'] ?? ''));
    if ($key === '' || $fp === '') return ['status' => 'unlicensed', 'valid' => false, 'expired' => false];
    $status = validateLicenseKey($key, $fp);
    $next = $status['valid'] ? 'active' : ($status['expired'] ? 'expired' : 'invalid');
    $stmt = $pdo->prepare('UPDATE device_licenses SET status=?, expiry=? WHERE device_id=? AND license_key=?');
    $stmt->execute([$next, $status['expiry'] ?? null, (string)$device['id'], $key]);
    return $status + ['status' => $next];
}

function getDeviceLicenseStatus(array $device): array
{
    $fingerprint  = trim((string)($device['device_fingerprint'] ?? ''));
    $licenseKey   = trim((string)($device['license_key'] ?? ''));
    $deviceType   = trim((string)($device['type'] ?? 'dev'));
    $deviceId     = $fingerprint !== '' ? formatDeviceId($fingerprint, $deviceType) : null;
    $hwInfo       = is_array($device['hardware_info'] ?? null) ? $device['hardware_info'] : [];

    /* Infos hardware normalisées pour le client
       MikroTik  : serial = serial-number, model = board-name
       OPNsense  : serial = hostname (pas de SN physique), model = product_id
       RADIUS    : serial = host IP, model = 'FreeRADIUS' */
    $serialNumber = trim((string)($hwInfo['serial']   ?? $hwInfo['hostname'] ?? $hwInfo['host'] ?? ''));
    $model        = trim((string)($hwInfo['board']    ?? $hwInfo['product']  ?? ''));
    $typeLabel    = match (strtolower($deviceType)) {
        'mikrotik' => 'MikroTik',
        'opnsense' => 'OPNsense',
        'radius'   => 'RADIUS',
        default    => strtoupper($deviceType),
    };

    $hwSummary = [
        'serial' => $serialNumber,
        'model'  => $model,
        'type'   => $typeLabel,
    ];

    if ($fingerprint === '') {
        return [
            'status'    => 'no_fingerprint',
            'device_id' => null,
            'valid'     => false,
            'label'     => 'Non identifié',
            'hw'        => $hwSummary,
        ];
    }

    if ($licenseKey === '') {
        return [
            'status'    => 'unlicensed',
            'device_id' => $deviceId,
            'valid'     => false,
            'label'     => 'Sans licence',
            'hw'        => $hwSummary,
        ];
    }

    $result = validateLicenseKey($licenseKey, $fingerprint);

    if ($result['expired']) {
        return [
            'status'    => 'expired',
            'device_id' => $deviceId,
            'valid'     => false,
            'expiry'    => $result['expiry'],
            'label'     => 'Licence expirée',
            'hw'        => $hwSummary,
        ];
    }

    if (!$result['valid']) {
        return [
            'status'    => 'invalid',
            'device_id' => $deviceId,
            'valid'     => false,
            'label'     => 'Clé invalide',
            'hw'        => $hwSummary,
        ];
    }

    return [
        'status'    => 'active',
        'device_id' => $deviceId,
        'valid'     => true,
        'expiry'    => $result['expiry'],
        'issued'    => $result['issued'],
        'label'     => 'Licencié ✓',
        'hw'        => $hwSummary,
    ];
}

/**
 * Vérifie que le device est licencié avant toute opération backend.
 * Vérifie aussi l'intégrité des fichiers critiques.
 * Lève une exception métier si non licencié ou fichier corrompu.
 */
function requireDeviceLicensed(array $device): void
{
    /* Intégrité des fichiers critiques */
    require_once __DIR__ . '/integrity_check.php';
    verifyFilesIntegrity();
    $fingerprint = trim((string)($device['device_fingerprint'] ?? ''));

    /* Pas encore identifié (pas de fingerprint) → bloqué */
    if ($fingerprint === '') {
        $name = trim((string)($device['name'] ?? 'ce routeur'));
        throw new RuntimeException(
            "🔒 Licence requise — {$name} n'est pas encore identifié. " .
            "Testez la connexion dans Network Devices pour obtenir le Device ID."
        );
    }

    $status = getDeviceLicenseStatus($device);

    if ($status['valid']) {
        return; /* Licencié → OK */
    }

    $deviceId = $status['device_id'] ?? '—';
    $label    = $status['label']     ?? 'Non licencié';

    $messages = [
        'unlicensed' => "🔒 Licence requise — Device ID : {$deviceId}. " .
                        "Contactez l'administrateur pour obtenir votre clé de licence.",
        'expired'    => "⏱ Licence expirée — Device ID : {$deviceId}. " .
                        "Contactez l'administrateur pour renouveler votre licence.",
        'invalid'    => "❌ Clé de licence invalide — Device ID : {$deviceId}. " .
                        "Vérifiez la clé ou contactez l'administrateur.",
    ];

    throw new RuntimeException(
        $messages[$status['status']] ??
        "🔒 {$label} — Device ID : {$deviceId}. Contactez l'administrateur."
    );
}
