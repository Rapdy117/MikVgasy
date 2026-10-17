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
if (!$installed && isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true) {
    require_once __DIR__ . '/../includes/auth.php';
}
if (!$installed && isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true) {
    if (!isSuperAdministrator()) {
        http_response_code(403);
        exit('Accès réservé au superadmin. Reconnectez-vous après une modification de rôle.');
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        require __DIR__ . '/system_tools_page.php';
        exit;
    }
}
if ($installed) {
    require_once __DIR__ . '/../includes/auth.php';
    if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || !isSuperAdministrator()) {
        http_response_code(403);
        exit('Accès réservé au superadmin.');
    }
    require __DIR__ . '/system_tools_page.php';
    exit;
}

    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
$message = '';
if (!isset($_SESSION['install_csrf'])) {
    $_SESSION['install_csrf'] = bin2hex(random_bytes(32));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if ($installed) {
            throw new RuntimeException('Installation déjà initialisée.');
        }
        if (!hash_equals((string)$_SESSION['install_csrf'], (string)($_POST['csrf_token'] ?? ''))) {
            throw new RuntimeException('CSRF invalide.');
        }

        $username = trim((string)($_POST['username'] ?? ''));
        $password = (string)($_POST['password'] ?? '');
        $confirmation = (string)($_POST['password_confirmation'] ?? '');
        $promote = trim((string)($_POST['promote_existing'] ?? ''));
        if (!preg_match('/^[A-Za-z0-9_.@-]{3,100}$/', $username)) {
            throw new RuntimeException('Nom du superadmin invalide.');
        }
        if (strlen($password) < 12 || !hash_equals($password, $confirmation)) {
            throw new RuntimeException('Mot de passe invalide ou confirmation différente.');
        }
        if ($promote !== '' && !preg_match('/^[A-Za-z0-9_.@-]{3,100}$/', $promote)) {
            throw new RuntimeException('Compte à promouvoir invalide.');
        }

        $command = [PHP_BINARY, __DIR__ . '/install.php', '--username=' . $username, '--password-env=RADIUS_MANAGER_INITIAL_PASSWORD'];
        if ($promote !== '') {
            $command[] = '--promote-existing=' . $promote;
        }
        $pipes = [];
        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, __DIR__, array_merge($_ENV, [
            'RADIUS_MANAGER_INITIAL_PASSWORD' => $password,
        ]));
        if (!is_resource($process)) {
            throw new RuntimeException('Moteur d’installation indisponible.');
        }
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        if (proc_close($process) !== 0) {
            throw new RuntimeException(trim((string)$error) ?: 'Installation échouée.');
        }
        $message = trim((string)$output);
        $installed = true;
        unset($_SESSION['install_csrf']);
    } catch (Throwable $exception) {
        $message = $exception->getMessage();
    }
}
?>
<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Installation et outils système</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
<link rel="stylesheet" href="/css/theme.css"></head>
<body class="administration-page text-white" data-csrf-token="<?= installEscape((string)($_SESSION['csrf_token'] ?? '')) ?>">
<div class="bg-scene"></div>
<main class="container py-4">
<div class="card shadow-sm administration-card mb-3">
<div class="card-header standard-card-header"><i class="fa fa-screwdriver-wrench me-2"></i>Installation et outils système</div>
<div class="card-body text-white-50">Initialisation multi-tenant, migrations et maintenance réservées au superadmin.</div>
</div>
<?php if ($message !== ''): ?><p role="alert"><?= nl2br(installEscape($message)) ?></p><?php endif; ?>
<?php if ($installed): ?>
<p><a href="/pages/dashboard.php">Retour à l’application</a></p>
<section>
<h2>Synchronisation et cron</h2>
<p>
<button type="button" id="syncOpnsenseSessionsBtn">Relancer la synchronisation</button>
<button type="button" id="installOpnsenseCronBtn">Installer le cron</button>
</p>
<p id="systemToolsStatus" role="status"></p>
</section>
<section>
<h2>Base SQL</h2>
<p><a href="/api/admin/export_database.php">Exporter la base SQL</a></p>
<form action="/api/admin/import_database.php" method="post" enctype="multipart/form-data">
<input type="hidden" name="csrf_token" value="<?= installEscape((string)$_SESSION['csrf_token']) ?>">
<input type="file" name="sql_file" accept=".sql" required>
<button type="submit">Importer la base SQL</button>
</form>
</section>
<script src="/tools/system_tools.js"></script>
<?php else: ?>
<form method="post" autocomplete="off">
<input type="hidden" name="csrf_token" value="<?= installEscape((string)$_SESSION['install_csrf']) ?>">
<p><label>Superadmin<br><input name="username" value="superadmin" minlength="3" maxlength="100" required></label></p>
<p><label>Compte existant à promouvoir (facultatif)<br><input name="promote_existing" maxlength="100"></label></p>
<p><label>Mot de passe initial<br><input type="password" name="password" minlength="12" required></label></p>
<p><label>Confirmation<br><input type="password" name="password_confirmation" minlength="12" required></label></p>
<button type="submit">Installer et créer le superadmin</button>
</form>
<?php endif; ?>
</main>
</body>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</html>
