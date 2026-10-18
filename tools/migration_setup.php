<?php
declare(strict_types=1);

session_start();
header('Content-Type: text/html; charset=UTF-8');
header('Cache-Control: no-store');
require_once __DIR__ . '/../config/db.php';

function installEscape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function installationExists(PDO $pdo): bool
{
    if (!$pdo->query("SHOW TABLES LIKE 'system_installation'")->fetchColumn()) {
        return false;
    }
    return (int)$pdo->query('SELECT COUNT(*) FROM system_installation WHERE id = 1')->fetchColumn() > 0;
}

$installed = installationExists($pdo);

/* =========================================================
   Mode previsualisation (?preview=1&step=N) : montre l'interface de
   l'assistant telle quelle, sans effectuer AUCUNE ecriture (ni config,
   ni base, ni fichier), meme en cas de soumission de formulaire - pour
   consulter le rendu reel sans risquer d'ecraser un environnement deja
   en production. Place AVANT le controle $installed expres : sur un
   serveur deja installe (cas courant), c'est la seule facon d'atteindre
   cette vue. Reserve au superadmin (login requis).
========================================================= */
if (isset($_GET['preview'])) {
    require_once __DIR__ . '/../includes/auth.php';
    if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || !isSuperAdministrator()) {
        http_response_code(403);
        exit('Accès réservé au superadmin.');
    }
    $step = max(1, min(5, (int)($_GET['step'] ?? 1)));
    $error = isset($_GET['err']) ? "Exemple d'erreur : connexion à la base refusée (identifiants invalides)." : '';
    $_SESSION['install_csrf'] = $_SESSION['install_csrf'] ?? bin2hex(random_bytes(32));
    $checks = $step === 5 ? [
        ['ok' => true, 'label' => 'Connexion base de données'],
        ['ok' => true, 'label' => 'Compte superadmin créé (1)'],
        ['ok' => true, 'label' => 'Crons de collecte installés'],
        ['ok' => false, 'label' => 'Aucun routeur enregistré (étape 4 ignorée)'],
        ['ok' => true, 'label' => 'Marquage installation confirmé'],
    ] : [];
    define('MIGRATION_PREVIEW_VIEW', true);
    require __DIR__ . '/_migration_preview_view.php';
    exit;
}

/* Outils systeme (post-installation), comportement inchange. */
if ($installed) {
    require_once __DIR__ . '/../includes/auth.php';
    if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || !isSuperAdministrator()) {
        http_response_code(403);
        exit('Accès réservé au superadmin.');
    }
    require __DIR__ . '/system_tools_page.php';
    exit;
}

/* =========================================================
   Assistant d'installation (pas encore installe) - 5 etapes.
   Etat de progression garde en session (pas de login possible
   avant la creation du superadmin a l'etape 2).
========================================================= */
if (!isset($_SESSION['install_wizard']) || !is_array($_SESSION['install_wizard'])) {
    $_SESSION['install_wizard'] = ['step' => 1];
}
$wizard = &$_SESSION['install_wizard'];
if (empty($_SESSION['install_csrf'])) {
    $_SESSION['install_csrf'] = bin2hex(random_bytes(32));
}

$step = max(1, min(5, (int)($wizard['step'] ?? 1)));
$error = '';
$notice = '';

function installCheckCsrf(): void
{
    if (!hash_equals((string)($_SESSION['install_csrf'] ?? ''), (string)($_POST['csrf_token'] ?? ''))) {
        throw new RuntimeException('Jeton de sécurité invalide, rechargez la page.');
    }
}

/* ---- Etape 1 : base de donnees + RADIUS ---- */
if ($step === 1 && $_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        installCheckCsrf();
        $dbHost = trim((string)($_POST['db_host'] ?? ''));
        $dbUser = trim((string)($_POST['db_user'] ?? ''));
        $dbPass = (string)($_POST['db_pass'] ?? '');
        $dbName = trim((string)($_POST['db_name'] ?? ''));
        if ($dbHost === '' || $dbUser === '' || $dbName === '') {
            throw new RuntimeException('Hôte, utilisateur et nom de base MySQL sont obligatoires.');
        }

        /* Connexion de test independante (le $pdo global utilise encore l'ancienne config). */
        $testPdo = new PDO(
            "mysql:host={$dbHost};dbname={$dbName};charset=utf8",
            $dbUser,
            $dbPass,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5]
        );
        $testPdo = null;

        $radiusHost = trim((string)($_POST['radius_host'] ?? ''));
        $radiusSecret = (string)($_POST['radius_secret'] ?? '');
        $radiusTestUser = trim((string)($_POST['radius_test_user'] ?? 'radius'));
        $radiusTestPass = (string)($_POST['radius_test_pass'] ?? '');
        $radiusAuthPort = (int)($_POST['radius_auth_port'] ?? 1812);
        $radiusAcctPort = (int)($_POST['radius_acct_port'] ?? 1813);

        require_once __DIR__ . '/../includes/crypto.php';
        $dbJson = json_encode([
            'host' => $dbHost, 'user' => $dbUser, 'pass' => $dbPass, 'dbname' => $dbName,
        ], JSON_PRETTY_PRINT);
        if (file_put_contents(__DIR__ . '/../config/db.json', $dbJson) === false) {
            throw new RuntimeException('Écriture de config/db.json impossible (vérifiez les permissions).');
        }

        if ($radiusHost !== '') {
            $radiusJson = json_encode([
                'test_user' => $radiusTestUser,
                'test_pass' => $radiusTestPass !== '' ? encryptField($radiusTestPass) : '',
                'host' => $radiusHost,
                'auth_port' => $radiusAuthPort ?: 1812,
                'acct_port' => $radiusAcctPort ?: 1813,
                'secret' => $radiusSecret !== '' ? encryptField($radiusSecret) : '',
                'timeout' => 3,
            ], JSON_PRETTY_PRINT);
            if (file_put_contents(__DIR__ . '/../config/radius.json', $radiusJson) === false) {
                throw new RuntimeException('Écriture de config/radius.json impossible.');
            }
        }

        $wizard['step'] = 2;
        header('Location: migration_setup.php');
        exit;
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

/* ---- Etape 2 : compte superadmin (reserve a l'installateur, pas au client) ---- */
if ($step === 2 && $_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        installCheckCsrf();
        $username = trim((string)($_POST['username'] ?? ''));
        $password = (string)($_POST['password'] ?? '');
        $confirmation = (string)($_POST['password_confirmation'] ?? '');
        if (!preg_match('/^[A-Za-z0-9_.@-]{3,100}$/', $username)) {
            throw new RuntimeException('Nom du superadmin invalide.');
        }
        if (strlen($password) < 12 || !hash_equals($password, $confirmation)) {
            throw new RuntimeException('Mot de passe invalide (12 caractères min.) ou confirmation différente.');
        }

        $command = [PHP_BINARY, __DIR__ . '/install.php', '--username=' . $username, '--password-env=RADIUS_MANAGER_INITIAL_PASSWORD'];
        $pipes = [];
        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, __DIR__, array_merge($_ENV, [
            'RADIUS_MANAGER_INITIAL_PASSWORD' => $password,
        ]));
        if (!is_resource($process)) {
            throw new RuntimeException('Moteur d’installation indisponible.');
        }
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        $cliError = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        if (proc_close($process) !== 0) {
            throw new RuntimeException(trim((string)$cliError) ?: 'Installation échouée.');
        }

        /* Reconnexion avec la config fraichement ecrite a l'etape 1. */
        require_once __DIR__ . '/../config/db.php';
        $superadminId = (int)$pdo->query("SELECT id FROM local_admin_users WHERE username = " . $pdo->quote($username) . " LIMIT 1")->fetchColumn();

        $wizard['superadmin_username'] = $username;
        $wizard['superadmin_id'] = $superadminId;
        $wizard['step'] = 3;
        header('Location: migration_setup.php');
        exit;
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

/* ---- Etape 3 : scripts & cron (ecrits par install_vps.sh en root, ici simple verification) ---- */
if ($step === 3 && $_SERVER['REQUEST_METHOD'] === 'POST') {
    installCheckCsrf();
    $wizard['step'] = 4;
    header('Location: migration_setup.php');
    exit;
}

/* ---- Etape 4 : routeur MikroTik (minimal, guide - a affiner) ---- */
if ($step === 4 && $_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        installCheckCsrf();
        $action = (string)($_POST['action'] ?? '');
        if ($action === 'skip') {
            $wizard['step'] = 5;
            header('Location: migration_setup.php');
            exit;
        }

        $devHost = trim((string)($_POST['device_host'] ?? ''));
        $devUser = trim((string)($_POST['device_api_key'] ?? ''));
        $devPass = (string)($_POST['device_api_secret'] ?? '');
        $devName = trim((string)($_POST['device_name'] ?? 'Routeur principal'));
        $wanCount = max(1, min(4, (int)($_POST['wan_count'] ?? 2)));
        if ($devHost === '' || $devUser === '' || $devPass === '') {
            throw new RuntimeException('Adresse, identifiant API et secret du routeur sont obligatoires.');
        }

        require_once __DIR__ . '/../config/db.php';
        require_once __DIR__ . '/../includes/crypto.php';
        require_once __DIR__ . '/../includes/device_manager.php';

        $superadminId = (int)($wizard['superadmin_id'] ?? 0);
        if ($superadminId <= 0) {
            throw new RuntimeException('Compte superadmin introuvable (étape 2 non terminée).');
        }

        $deviceId = 'dev_' . time();
        $record = normalizeDeviceRecord([
            'id' => $deviceId,
            'name' => $devName,
            'type' => 'mikrotik',
            'host' => $devHost,
            'api_key' => $devUser,
            'api_secret' => $devPass,
            'secret' => $devPass,
        ]);
        $enc = encryptFields($record, DEVICE_SENSITIVE_FIELDS);
        $now = date('Y-m-d H:i:s');
        $stmt = $pdo->prepare('INSERT INTO network_devices (id,owner_admin_id,name,type,host,backend_driver,api_key,api_secret,secret,verify_ssl,port,vendor,device_fingerprint,hardware_info,license_key,license_status,license_expiry,license_issued,created_at,updated_at,is_active) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,1)');
        $stmt->execute([
            $enc['id'], $superadminId, $enc['name'], $enc['type'], $enc['host'], $enc['backend_driver'],
            $enc['api_key'], $enc['api_secret'], $enc['secret'], 0, $enc['port'], $enc['vendor'],
            $enc['device_fingerprint'], json_encode($enc['hardware_info'] ?: []), $enc['license_key'],
            $enc['license_status'], $enc['license_expiry'], $enc['license_issued'], $now, $now,
        ]);

        $wizard['device_id'] = $deviceId;
        $wizard['wan_count'] = $wanCount;
        $wizard['step'] = 5;
        header('Location: migration_setup.php');
        exit;
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

/* ---- Etape 5 : resume + verificateur ---- */
$checks = [];
if ($step === 5) {
    require_once __DIR__ . '/../config/db.php';
    try {
        $pdo->query('SELECT 1');
        $checks[] = ['ok' => true, 'label' => 'Connexion base de données'];
    } catch (Throwable $e) {
        $checks[] = ['ok' => false, 'label' => 'Connexion base de données : ' . $e->getMessage()];
    }
    $superadminCount = (int)$pdo->query("SELECT COUNT(*) FROM local_admin_users WHERE role='superadmin' AND is_active=1")->fetchColumn();
    $checks[] = ['ok' => $superadminCount > 0, 'label' => $superadminCount > 0 ? "Compte superadmin créé ({$superadminCount})" : 'Aucun compte superadmin actif'];

    $cronFile = '/etc/cron.d/radius-manager-network-quality';
    $checks[] = ['ok' => is_file($cronFile), 'label' => is_file($cronFile) ? 'Crons de collecte installés' : "Crons absents ({$cronFile} introuvable - relancer install_vps.sh)"];

    $deviceCount = (int)$pdo->query('SELECT COUNT(*) FROM network_devices WHERE is_active=1')->fetchColumn();
    $checks[] = ['ok' => $deviceCount > 0, 'label' => $deviceCount > 0 ? "Routeur(s) enregistré(s) ({$deviceCount})" : "Aucun routeur enregistré (étape 4 ignorée)"];

    $installedNow = installationExists($pdo);
    $checks[] = ['ok' => $installedNow, 'label' => $installedNow ? 'Marquage installation confirmé' : 'Marquage installation absent'];
}

define('MIGRATION_SETUP_VIEW', true);
require __DIR__ . '/_migration_setup_view.php';
