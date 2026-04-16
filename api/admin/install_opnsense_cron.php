<?php

require '../../config/db.php';
require_once '../../includes/auth.php';
require_once '../../includes/operation_history.php';

session_start();

header('Content-Type: application/json');

function require_valid_csrf(): void
{
    $token = trim((string)($_POST['csrf_token'] ?? ''));
    if ($token === '' || !hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'message' => 'CSRF invalide',
        ]);
        exit;
    }
}

function current_os_username(): string
{
    if (function_exists('posix_geteuid') && function_exists('posix_getpwuid')) {
        $info = posix_getpwuid(posix_geteuid());
        if (is_array($info) && !empty($info['name'])) {
            return (string)$info['name'];
        }
    }

    $output = [];
    $code = 0;
    @exec('whoami 2>/dev/null', $output, $code);
    if ($code === 0 && !empty($output[0])) {
        return trim((string)$output[0]);
    }

    return 'unknown';
}

function install_opnsense_sync_cron(): array
{
    $scriptPath = realpath(__DIR__ . '/../../scripts/run_opnsense_session_sync.sh');
    if ($scriptPath === false || !is_file($scriptPath)) {
        throw new RuntimeException('Script cron introuvable.');
    }

    $beginMarker = '# BEGIN OPNsense session sync';
    $endMarker = '# END OPNsense session sync';
    $cronBlock = implode(PHP_EOL, [
        $beginMarker,
        '* * * * * ' . $scriptPath . ' >/dev/null 2>&1',
        '* * * * * sleep 30; ' . $scriptPath . ' >/dev/null 2>&1',
        $endMarker,
    ]);

    $existing = [];
    $code = 0;
    @exec('crontab -l 2>/dev/null', $existing, $code);
    $current = trim(implode(PHP_EOL, $existing));

    $pattern = '/' . preg_quote($beginMarker, '/') . '.*?' . preg_quote($endMarker, '/') . '\s*/s';
    $cleaned = trim((string)preg_replace($pattern, '', $current));
    $newContent = trim($cleaned . PHP_EOL . PHP_EOL . $cronBlock) . PHP_EOL;

    $tmpFile = tempnam(sys_get_temp_dir(), 'opnsense-cron-');
    if ($tmpFile === false) {
        throw new RuntimeException('Impossible de preparer le fichier temporaire du cron.');
    }

    try {
        if (file_put_contents($tmpFile, $newContent) === false) {
            throw new RuntimeException('Impossible d ecrire le fichier temporaire du cron.');
        }

        $output = [];
        $installCode = 0;
        exec('crontab ' . escapeshellarg($tmpFile) . ' 2>&1', $output, $installCode);
        if ($installCode !== 0) {
            throw new RuntimeException('Installation cron echouee: ' . trim(implode(' ', $output)));
        }
    } finally {
        @unlink($tmpFile);
    }

    return [
        'script_path' => $scriptPath,
        'os_user' => current_os_username(),
        'cron_block' => $cronBlock,
    ];
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
        'message' => 'Acces reserve a l administrateur',
    ]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'message' => 'Methode non autorisee',
    ]);
    exit;
}

require_valid_csrf();

try {
    $result = install_opnsense_sync_cron();

    recordOperationHistory($pdo, [
        'operation_scope' => 'admin',
        'operation_type' => 'system_update',
        'actor_username' => (string)($_SESSION['username'] ?? ''),
        'actor_role' => (string)($_SESSION['user_role'] ?? 'administrator'),
        'target_type' => 'system',
        'target_name' => 'opnsense_shaper_cron',
        'summary' => 'Cron OPNsense installe',
        'details_json' => [
            'os_user' => (string)($result['os_user'] ?? ''),
            'script_path' => (string)($result['script_path'] ?? ''),
        ],
    ]);

    echo json_encode([
        'success' => true,
        'message' => 'Cron OPNsense installe',
        'os_user' => (string)($result['os_user'] ?? ''),
        'script_path' => (string)($result['script_path'] ?? ''),
        'cron_block' => (string)($result['cron_block'] ?? ''),
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
    ]);
}
