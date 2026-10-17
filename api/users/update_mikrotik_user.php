<?php
require '../../config/db.php';
require_once '../../includes/auth.php';
require_once '../../includes/mikrotik_backend.php';
require_once '../../includes/operation_history.php';
require_once '../../includes/hotspot_mac_labels.php';

session_start();

header('Content-Type: application/json');

function post_string_or_null(string $key): ?string
{
    $value = trim((string)($_POST[$key] ?? ''));
    return $value === '' ? null : $value;
}

function require_valid_csrf(): void
{
    $token = trim((string)($_POST['csrf_token'] ?? ''));
    if ($token === '' || !hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        throw new RuntimeException('CSRF invalide');
    }
}

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'message' => 'Unauthorized',
    ]);
    exit;
}
if (!isAdministrator()) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'message' => 'Accès réservé à l administrateur',
    ]);
    exit;
}

try {
    require_valid_csrf();

    $oldUsername = post_string_or_null('old_username');
    $username = post_string_or_null('username');
    $password = post_string_or_null('password');
    $deviceId = post_string_or_null('device_id');
    $status = strtolower(trim((string)($_POST['status'] ?? '')));
    $profileName = post_string_or_null('profile_name');
    $hasExpirationDate = array_key_exists('expiration_date', $_POST);
    $expirationDate = $hasExpirationDate ? trim((string)($_POST['expiration_date'] ?? '')) : null;

    if ($deviceId === null) {
        throw new RuntimeException('Serveur requis.');
    }

    $deviceStore = loadDeviceStore();
    $device = requireAccessibleDevice($deviceStore, $deviceId);
    if (!is_array($device) || normalizeDeviceType((string)($device['type'] ?? '')) !== 'mikrotik') {
        throw new RuntimeException('Serveur MikroTik introuvable.');
    }

    requireDeviceLicensed($device);

    $nasContext = [
        'device' => $device,
        'device_type' => 'mikrotik',
        'business_source' => 'mikrotik_local',
        'backend_driver' => 'mikrotik_api',
    ];

    if ($oldUsername === null || $username === null) {
        throw new RuntimeException('Nom utilisateur manquant.');
    }

    if ($status !== '' && !in_array($status, ['active', 'disabled', 'expired'], true)) {
        throw new RuntimeException('Statut invalide.');
    }

    $api = connectToMikrotikApiForNasContext($nasContext);
    try {
        $existing = findMikrotikUserByName($api, $oldUsername);
        if (!$existing || !isset($existing['.id'])) {
            throw new RuntimeException('Utilisateur introuvable sur MikroTik.');
        }

        $targetProfile = null;
        if ($profileName !== null) {
            $targetProfile = findMikrotikProfileByName($api, $profileName);
            if (!is_array($targetProfile)) {
                throw new RuntimeException('Profil MikroTik introuvable.');
            }
        }

        if ($status === 'active' && $hasExpirationDate && $expirationDate !== '') {
            $expiration = parseMikrotikExpirationCommentDateTime($expirationDate);
            if ($expiration instanceof DateTimeImmutable && $expiration <= new DateTimeImmutable('now', new DateTimeZone('UTC'))) {
                throw new RuntimeException('Impossible d activer un compte avec une expiration deja passee.');
            }
        }

        // Type de compte, comme sur la page de création : "mac" (nom = MAC, sans mot de passe)
        // ou "password" (identifiant + mot de passe, MAC optionnelle en restriction).
        // Non fourni (ancien client) : on garde le type actuel, déduit du nom existant.
        $macRegex = '/^([0-9A-F]{2}:){5}[0-9A-F]{2}$/i';
        $oldIsMacAccount = preg_match($macRegex, $oldUsername) === 1;
        $requestedLoginMode = post_string_or_null('login_mode');
        $isMacTarget = $requestedLoginMode === 'mac' ? true : ($requestedLoginMode === 'password' ? false : $oldIsMacAccount);
        $modeChanged = $isMacTarget !== $oldIsMacAccount;

        $macAddress = post_string_or_null('mac');

        if ($isMacTarget) {
            // Compte en login par MAC : le nom RouterOS doit être la MAC elle-même, aucun mot de passe.
            $candidateMac = strtoupper(trim($macAddress ?? $username ?? ''));
            if (preg_match($macRegex, $candidateMac) !== 1) {
                throw new RuntimeException('Adresse MAC invalide pour un compte en login par MAC.');
            }
            $targetUsername = $candidateMac;
            $macAddress = $candidateMac;
            $password = null; // jamais envoyé pour un compte MAC, comme à la création
        } else {
            // Compte avec identifiant + mot de passe : la MAC, si fournie, reste une restriction facultative.
            if ($username === null || trim($username) === '') {
                throw new RuntimeException('Nom utilisateur manquant.');
            }
            $targetUsername = $username;
            if ($modeChanged && ($password === null || trim($password) === '')) {
                throw new RuntimeException('Mot de passe requis pour un compte identifiant + mot de passe.');
            }
        }

        $payload = [
            '.id' => (string)$existing['.id'],
            'name' => $targetUsername,
        ];
        if ($macAddress !== null && trim($macAddress) !== '') {
            $payload['mac-address'] = strtoupper(trim($macAddress));
        }
        if ($password !== null) {
            $payload['password'] = trim($password);
        }
        if ($status !== '') {
            $payload['disabled'] = ($status === 'disabled') ? 'yes' : 'no';
            if ($status === 'active') {
                $targetProfileName = $profileName ?? trim((string)($existing['profile'] ?? ''));
                $profile = $targetProfile ?? ($targetProfileName !== '' ? findMikrotikProfileByName($api, $targetProfileName) : null);
                if (is_array($profile)) {
                    $profileTimeLimit = mikrotikProfileOfferTimeSeconds($profile);
                    $payload['limit-uptime'] = $profileTimeLimit > 0 ? mikrotikIntervalFromSeconds($profileTimeLimit) : '0';
                } else {
                    $payload['limit-uptime'] = '0';
                }
            } elseif ($status === 'expired') {
                $payload['limit-uptime'] = '1s';
            }
        }
        if ($profileName !== null) {
            $payload['profile'] = $profileName;
        }
        if ($hasExpirationDate) {
            // MikroTik stores the business expiration projection in comment.
            $payload['comment'] = $expirationDate;
        }

        $response = $api->comm('/ip/hotspot/user/set', $payload);
        mikrotikAssertNoTrap($response, 'Le routeur MikroTik a refuse la mise a jour utilisateur.');
    } finally {
        $api->disconnect();
    }

    // Libellé d'affichage d'un compte MAC : donnée locale uniquement, enregistrée après succès sur le routeur.
    if ($isMacTarget && array_key_exists('label', $_POST)) {
        saveHotspotMacLabel($pdo, $deviceId, $targetUsername, trim((string)$_POST['label']));
    }

    recordOperationHistory($pdo, [
        'operation_scope' => 'admin',
        'operation_type' => 'user_update',
        'actor_username' => (string)($_SESSION['username'] ?? ''),
        'actor_role' => (string)($_SESSION['user_role'] ?? 'administrator'),
        'target_type' => 'user',
        'target_name' => $targetUsername,
        'summary' => 'Utilisateur MikroTik mis à jour',
        'details_json' => [
            'backend_driver' => 'mikrotik_api',
            'device_id' => $deviceId,
            'old_username' => $oldUsername,
            'login_mode' => $isMacTarget ? 'mac' : 'password',
            'login_mode_changed' => $modeChanged,
            'password_changed' => $password !== null && $password !== '',
            'status' => $status !== '' ? $status : null,
            'profile_name' => $profileName,
            'expiration_date' => $expirationDate,
        ],
    ]);

    echo json_encode([
        'success' => true,
        'message' => 'Utilisateur MikroTik mis a jour.',
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
    ]);
}
