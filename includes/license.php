<?php
/**
 * Système de licence par routeur (Device ID)
 * 1 routeur physique = 1 clé de licence
 */

function getLicenseSecret(): string
{
    $secret = trim((string)getenv('RM_APP_SECRET'));
    if ($secret === '') {
        $path = dirname(__DIR__) . '/config/license/app_secret.txt';
        if (is_file($path)) {
            $secret = trim((string)file_get_contents($path));
        }
    }

    if ($secret === '') {
        throw new RuntimeException('Secret applicatif manquant: definir RM_APP_SECRET ou config/license/app_secret.txt.');
    }

    return hash('sha256', $secret, true);
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
 * Retourne le statut de licence d'un device enregistré.
 */
function getDeviceLicenseStatus(array $device): array
{
    $fingerprint  = trim((string)($device['device_fingerprint'] ?? ''));
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

    try {
        require_once __DIR__ . '/backend_agent.php';
        backendAgentCheckLicense((string)$deviceId);
        return [
            'status'    => 'active',
            'device_id' => $deviceId,
            'valid'     => true,
            'label'     => 'Licencié ✓',
            'hw'        => $hwSummary,
        ];
    } catch (Throwable $e) {
        $message = $e->getMessage();
        $status = str_contains($message, 'LICENSE_EXPIRED') ? 'expired' : 'unlicensed';
        $label = str_contains($message, 'LICENSE_EXPIRED') ? 'Licence expirée' : 'Sans licence agent';
        return [
            'status'    => $status,
            'device_id' => $deviceId,
            'valid'     => false,
            'label'     => $label,
            'hw'        => $hwSummary,
            'agent_error' => $message,
        ];
    }
}

/**
 * Vérifie que le device est licencié avant toute opération backend.
 * Vérifie aussi l'intégrité des fichiers critiques.
 * Lève une exception métier si non licencié ou fichier corrompu.
 */
function requireDeviceLicensed(array $device): void
{
    $fingerprint = trim((string)($device['device_fingerprint'] ?? ''));

    if ($fingerprint === '') {
        $name = trim((string)($device['name'] ?? 'ce routeur'));
        throw new RuntimeException(
            "🔒 Licence requise — {$name} n'est pas encore identifié. " .
            "Testez la connexion dans Network Devices pour obtenir le Device ID."
        );
    }

    require_once __DIR__ . '/backend_agent.php';
    $deviceId = formatDeviceId($fingerprint, (string)($device['type'] ?? 'dev'));
    backendAgentCheckLicense($deviceId);
    backendAgentCheckIntegrity();
}
