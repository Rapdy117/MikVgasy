<?php
session_start();

require_once __DIR__ . '/../../includes/auth.php';

header('Content-Type: application/json');

if (!isset($_SESSION['logged_in']) || !isAdministrator()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Non autorisé']);
    exit;
}

$csrf = trim((string)($_POST['csrf_token'] ?? ''));
if ($csrf === '' || !hash_equals($_SESSION['csrf_token'] ?? '', $csrf)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'CSRF invalide']);
    exit;
}

$rootDir = dirname(__DIR__, 2);
$exePath = $rootDir . DIRECTORY_SEPARATOR . 'tools' . DIRECTORY_SEPARATOR
         . 'editor-license' . DIRECTORY_SEPARATOR . 'license-generator.exe';

if (!is_file($exePath)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'license-generator.exe introuvable : ' . $exePath]);
    exit;
}

$action = trim((string)($_POST['action'] ?? 'generate'));

if (!in_array($action, ['generate', 'keypair'], true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Action inconnue.']);
    exit;
}

try {
    if ($action === 'keypair') {
        $raw    = runLicenseExe($exePath, ['new-keypair'], [], 10);
        $result = parseExeOutput($raw);
        echo json_encode([
            'success'     => true,
            'private_key' => (string)($result['data']['private_key'] ?? ''),
            'public_key'  => (string)($result['data']['public_key']  ?? ''),
        ]);
        exit;
    }

    /* action = generate */
    $customerID = trim((string)($_POST['customer_id'] ?? ''));
    $deviceID   = trim((string)($_POST['device_id']   ?? ''));
    $nasType    = trim((string)($_POST['nas_type']     ?? 'mikrotik'));
    $edition    = trim((string)($_POST['edition']      ?? 'standard'));
    $expiresAt  = trim((string)($_POST['expires_at']   ?? 'never'));
    $features   = trim((string)($_POST['features']     ?? ''));
    $privateKey = trim((string)($_POST['private_key']  ?? ''));

    if ($customerID === '') throw new RuntimeException('Customer ID requis.');
    if ($deviceID === '')   throw new RuntimeException('Device ID requis.');
    if ($nasType === '')    throw new RuntimeException('Type NAS requis.');
    if ($privateKey === '') throw new RuntimeException('Clé privée requise.');

    $args = [
        'generate',
        '--customer',  $customerID,
        '--device-id', $deviceID,
        '--nas-type',  $nasType,
        '--edition',   $edition,
        '--expires',   $expiresAt,
    ];
    if ($features !== '') {
        array_push($args, '--features', $features);
    }

    $raw    = runLicenseExe($exePath, $args, ['RM_LICENSE_PRIVATE_KEY' => $privateKey], 10);
    $result = parseExeOutput($raw);

    echo json_encode([
        'success'      => true,
        'license_key'  => (string)($result['data']['license_key'] ?? ''),
        'license_json' => json_encode($result['data']['license'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
    ]);

} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}

/* ── Helpers ── */

function runLicenseExe(string $exe, array $args, array $extraEnv, int $timeout): string
{
    $cmd = escapeshellarg($exe);
    foreach ($args as $arg) {
        $cmd .= ' ' . escapeshellarg((string)$arg);
    }

    $env = array_merge(getenv() ?: [], $_ENV, $extraEnv);

    $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    set_time_limit($timeout + 5);
    $process = proc_open($cmd, $descriptors, $pipes, null, $env);
    if (!is_resource($process)) {
        throw new RuntimeException('Impossible de lancer license-generator.exe');
    }

    fclose($pipes[0]);
    stream_set_timeout($pipes[1], $timeout);
    stream_set_timeout($pipes[2], $timeout);
    $stdout = (string)stream_get_contents($pipes[1]);
    $stderr = (string)stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);

    if ($stdout === '' || $exitCode !== 0) {
        if ($stderr !== '') {
            error_log('license-generator stderr: ' . $stderr);
        }
        throw new RuntimeException(
            $stdout === ''
                ? 'Aucune sortie du générateur (code ' . $exitCode . ').'
                : 'license-generator.exe a échoué (code ' . $exitCode . ').'
        );
    }

    return $stdout;
}

function parseExeOutput(string $raw): array
{
    $result = json_decode(trim($raw), true);
    if (!is_array($result)) {
        throw new RuntimeException('Réponse invalide du générateur : ' . substr($raw, 0, 200));
    }
    if (!($result['success'] ?? false)) {
        throw new RuntimeException($result['message'] ?? $result['code'] ?? 'Erreur inconnue');
    }
    return $result;
}
