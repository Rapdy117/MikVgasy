<?php

error_reporting(0);
ini_set('display_errors', 0);

header('Content-Type: application/json');

$file = __DIR__ . '/radius.json';

require_once __DIR__ . '/../includes/crypto.php';

session_start();

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'message' => 'Unauthorized'
    ]);
    exit;
}

// =========================
// SAVE — chiffre les champs sensibles
// =========================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $data = [
        'host'      => $_POST['host']      ?? '',
        'auth_port' => (int)($_POST['auth_port'] ?? 1812),
        'acct_port' => (int)($_POST['acct_port'] ?? 1813),
        'secret'    => encryptField((string)($_POST['secret']    ?? '')),
        'test_user' => $_POST['test_user'] ?? '',
        'test_pass' => encryptField((string)($_POST['test_pass'] ?? '')),
        'timeout'   => (int)($_POST['timeout'] ?? 3),
    ];

    file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT), LOCK_EX);

    /* Retourne les valeurs déchiffrées au frontend */
    $data['secret']    = decryptField($data['secret']);
    $data['test_pass'] = decryptField($data['test_pass']);
    echo json_encode($data);
    exit;
}

// =========================
// LOAD SAFE
// =========================
if (!file_exists($file)) {
    echo json_encode([
        'host' => '',
        'auth_port' => 1812,
        'acct_port' => 1813,
        'secret' => '',
        'timeout' => 3
    ]);
    exit;
}

$content = file_get_contents($file);

if (!$content || trim($content) === '') {
    echo json_encode([
        'host' => '',
        'auth_port' => 1812,
        'acct_port' => 1813,
        'secret' => '',
        'timeout' => 3
    ]);
    exit;
}

// sécurité JSON
$data = json_decode($content, true);

if (!$data) {
    echo json_encode([
        'host' => '',
        'auth_port' => 1812,
        'acct_port' => 1813,
        'secret' => '',
        'timeout' => 3,
        'test_user' => '',
        'test_pass' => ''
    ]);
    exit;
}

/* Déchiffre les champs sensibles avant envoi au frontend */
$data['secret']    = decryptField((string)($data['secret']    ?? ''));
$data['test_pass'] = decryptField((string)($data['test_pass'] ?? ''));

echo json_encode(array_merge([
    'test_user' => '',
    'test_pass' => '',
], $data));
