<?php

header('Content-Type: application/json');

$configFile = __DIR__ . '/../config/radius.json';

if (!file_exists($configFile)) {
    echo json_encode([
        'success' => false,
        'log' => 'Config introuvable'
    ]);
    exit;
}

$config = json_decode(file_get_contents($configFile), true);

$host   = escapeshellarg($config['host'] ?? '');
$secret = escapeshellarg($config['secret'] ?? '');
$port   = (int)($config['auth_port'] ?? 1812);

// INPUT JSON
$host   = escapeshellarg($_POST['host'] ?? '');
$secret = escapeshellarg($_POST['secret'] ?? '');
$port   = (int)($_POST['auth_port'] ?? 1812);

$user = escapeshellarg($_POST['user'] ?? '');
$pass = escapeshellarg($_POST['pass'] ?? '');

$user = escapeshellarg($input['user'] ?? '');
$pass = escapeshellarg($input['pass'] ?? '');

if (!$user || !$pass) {
    echo json_encode([
        'success' => false,
        'log' => 'User ou Password vide'
    ]);
    exit;
}

// commande radclient
$userRaw = $_POST['test_user'] ?? '';
$passRaw = $_POST['test_pass'] ?? '';

if (trim($userRaw) === '' || trim($passRaw) === '') {
    echo json_encode([
        'success' => false,
        'log' => 'User ou Password vide'
    ]);
    exit;
}

$cmd = "echo \"User-Name=$userRaw,User-Password=$passRaw\" | radclient -x $host:$port auth $secret 2>&1";

$output = shell_exec($cmd);

// LOGS DETAILLES
$log  = "=== RADIUS DEBUG ===\n";
$log .= "Server : " . trim($host, "'") . ":$port\n";
$log .= "User   : " . trim($user, "'") . "\n\n";
$log .= $output;

// LOG RESULTAT
$log .= "\n=== RESULT ===\n";
$log .= $success ? "ACCESS ACCEPT\n" : "ACCESS REJECT\n";
if (strpos($output, 'Received Access-Accept') !== false) {
    $success = true;
} elseif (strpos($output, 'Received Access-Reject') !== false) {
    $success = false;
} else {
    $success = false;
}

echo json_encode([
    'success' => $success,
    'log' => $log
]);