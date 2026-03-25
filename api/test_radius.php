<?php

header('Content-Type: application/json');

$configFile = __DIR__ . '/../config/radius.json';

session_start();

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

if (!file_exists($configFile)) {
    echo json_encode([
        'success' => false,
        'log' => 'Config introuvable'
    ]);
    exit;
}

$config = json_decode(file_get_contents($configFile), true);

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

if ($userRaw === null || $passRaw === null) {
    echo json_encode([
        'success' => false,
        'log' => 'User ou Password vide'
    ]);
    exit;
}

$host = escapeshellarg($hostRaw);
$secret = escapeshellarg($secretRaw);
$user = escapeshellarg($userRaw);
$pass = escapeshellarg($passRaw);
$success = false;

$cmd = "echo \"User-Name=$userRaw,User-Password=$passRaw\" | radclient -x $host:$port auth $secret 2>&1";

$output = shell_exec($cmd);

// LOGS DETAILLES
$log  = "=== RADIUS DEBUG ===\n";
$log .= "Server : " . trim($host, "'") . ":$port\n";
$log .= "User   : " . trim($user, "'") . "\n\n";
$log .= $output;

// LOG RESULTAT
if (strpos($output, 'Received Access-Accept') !== false) {
    $success = true;
} elseif (strpos($output, 'Received Access-Reject') !== false) {
    $success = false;
} else {
    $success = false;
}

$log .= "\n=== RESULT ===\n";
$log .= $success ? "ACCESS ACCEPT\n" : "ACCESS REJECT\n";

echo json_encode([
    'success' => $success,
    'log' => $log
]);
