<?php

header('Content-Type: application/json');

$configFile = __DIR__ . '/../config/radius.json';

session_start();
require_once __DIR__ . '/../includes/auth.php';

function post_string_or_null(string $key): ?string
{
    $value = trim((string)($_POST[$key] ?? ''));
    return $value === '' ? null : $value;
}

function post_int_or_default(string $key, int $default): ?int
{
    $value = trim((string)($_POST[$key] ?? ''));
    if ($value === '') {
        return $default;
    }

    if (filter_var($value, FILTER_VALIDATE_INT) === false) {
        return null;
    }

    return (int)$value;
}

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'log' => 'Unauthorized'
    ]);
    exit;
}
if (!isAdministrator()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'log' => 'Accès réservé à l administrateur']);
    exit;
}

if (!file_exists($configFile)) {
    echo json_encode([
        'success' => false,
        'log' => 'Config introuvable'
    ]);
    exit;
}

$config = json_decode(file_get_contents($configFile), true);
$testMode = post_string_or_null('test_mode') ?? 'user_auth';

$hostRaw = post_string_or_null('host') ?? trim((string)($config['host'] ?? ''));
$secretRaw = post_string_or_null('secret') ?? trim((string)($config['secret'] ?? ''));
$port = post_int_or_default('auth_port', (int)($config['auth_port'] ?? 1812));

if ($port === null) {
    echo json_encode([
        'success' => false,
        'log' => 'Port RADIUS invalide'
    ]);
    exit;
}

// commande radclient
$userRaw = post_string_or_null('test_user');
$passRaw = post_string_or_null('test_pass');

if ($userRaw === null) {
    $userRaw = post_string_or_null('user');
}

if ($passRaw === null) {
    $passRaw = post_string_or_null('pass');
}

if ($hostRaw === '' || $secretRaw === '') {
    echo json_encode([
        'success' => false,
        'log' => 'Configuration RADIUS incomplete'
    ]);
    exit;
}

$host = escapeshellarg($hostRaw);
$secret = escapeshellarg($secretRaw);
$success = false;
$log = '';
$resultCode = 'error';

if ($testMode === 'server') {
    $cmd = "echo \"NAS-Identifier=radius-manager,Message-Authenticator=0x00\" | radclient -x $host:$port status $secret 2>&1";
    $output = shell_exec($cmd);

    $log  = "=== DEBUG SERVEUR RADIUS ===\n";
    $log .= "Serveur : " . trim($host, "'") . ":$port\n";
    $log .= "Mode    : STATUS\n\n";
    $log .= $output;

    $success = strpos((string)$output, 'Received') !== false
        && strpos((string)$output, 'No reply from server') === false
        && strpos((string)$output, 'Failed parsing') === false;
    $resultCode = $success ? 'server_ok' : 'no_reply';

    $log .= "\n=== RESULTAT ===\n";
    $log .= $success ? "SERVEUR JOIGNABLE\n" : "TEST SERVEUR INVALIDE OU SANS REPONSE\n";

    echo json_encode([
        'success' => $success,
        'log' => $log,
        'result_code' => $resultCode,
    ]);
    exit;
}

if ($userRaw === null || $passRaw === null) {
    echo json_encode([
        'success' => false,
        'log' => 'Utilisateur ou mot de passe vide'
    ]);
    exit;
}

$user = escapeshellarg($userRaw);
$cmd = "echo \"User-Name=$userRaw,User-Password=$passRaw\" | radclient -x $host:$port auth $secret 2>&1";
$output = shell_exec($cmd);

$log  = "=== DEBUG UTILISATEUR RADIUS ===\n";
$log .= "Serveur     : " . trim($host, "'") . ":$port\n";
$log .= "Utilisateur : " . trim($user, "'") . "\n";
$log .= "Mode        : AUTH\n\n";
$log .= $output;

if (strpos((string)$output, 'Received Access-Accept') !== false) {
    $success = true;
    $resultCode = 'access_accept';
} elseif (strpos((string)$output, 'Received Access-Reject') !== false) {
    $success = false;
    $resultCode = 'access_reject';
} elseif (strpos((string)$output, 'No reply from server') !== false) {
    $success = false;
    $resultCode = 'no_reply';
} else {
    $success = false;
    $resultCode = 'unknown_error';
}

$log .= "\n=== RESULTAT ===\n";
$log .= match ($resultCode) {
    'access_accept' => "ACCESS ACCEPT\n",
    'access_reject' => "ACCESS REJECT\n",
    'no_reply' => "AUCUNE REPONSE DU SERVEUR\n",
    default => "ERREUR OU REPONSE INCONNUE\n",
};

echo json_encode([
    'success' => $success,
    'log' => $log,
    'result_code' => $resultCode,
]);
