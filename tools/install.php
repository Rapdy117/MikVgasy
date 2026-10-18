<?php

/* install.php reste le moteur CLI (appele via proc_open par migration_setup.php) ; toute requete
   web est redirigee vers le vrai point d'entree, qui porte un nom reflete son role reel (collecte
   des donnees de configuration pour construire la migration/installation). */
if (PHP_SAPI !== 'cli') {
    header('Location: /tools/migration_setup.php');
    exit;
}

$options = getopt('', ['username::', 'password-env::', 'promote-existing::', 'database::']);
$username = trim((string)($options['username'] ?? 'superadmin'));
$passwordEnv = trim((string)($options['password-env'] ?? 'RADIUS_MANAGER_INITIAL_PASSWORD'));
$promoteExisting = trim((string)($options['promote-existing'] ?? ''));
$database = trim((string)($options['database'] ?? ''));

if ($username === '' || !preg_match('/^[A-Za-z0-9_.@-]{3,100}$/', $username)) {
    fwrite(STDERR, "Nom du superadmin invalide.\n");
    exit(1);
}

$password = (string)getenv($passwordEnv);
if ($password === '') {
    if (!function_exists('readline')) {
        fwrite(STDERR, "Definissez la variable {$passwordEnv}.\n");
        exit(1);
    }
    $password = (string)readline('Mot de passe initial du superadmin: ');
}

if (strlen($password) < 12) {
    fwrite(STDERR, "Le mot de passe initial doit contenir au moins 12 caracteres.\n");
    exit(1);
}

require_once __DIR__ . '/../config/db.php';

if ($database !== '') {
    if (!preg_match('/^[A-Za-z0-9_]+$/', $database)) {
        fwrite(STDERR, "Nom de base invalide.\n");
        exit(1);
    }
require_once __DIR__ . '/../includes/local_admins.php';
ensureLocalAdminTable($pdo);

    $pdo->exec('USE `' . $database . '`');
}

$migrationFile = __DIR__ . '/migrations/001_multi_tenant_foundation.sql';
$migrationSql = file_get_contents($migrationFile);
if ($migrationSql === false || trim($migrationSql) === '') {
    fwrite(STDERR, "Migration SQL introuvable.\n");
    exit(1);
}

try {
    $pdo->exec($migrationSql);

    $installed = (int)$pdo->query('SELECT COUNT(*) FROM system_installation WHERE id = 1')->fetchColumn();
    if ($installed > 0) {
        throw new RuntimeException('Installation deja initialisee.');
    }

    $pdo->beginTransaction();

    $targetUsername = $promoteExisting !== '' ? $promoteExisting : $username;
    $find = $pdo->prepare('SELECT id FROM local_admin_users WHERE username = ? LIMIT 1 FOR UPDATE');
    $find->execute([$targetUsername]);
    $superadminId = (int)($find->fetchColumn() ?: 0);
    $passwordHash = password_hash($password, PASSWORD_DEFAULT);

    if ($superadminId > 0) {
        $update = $pdo->prepare("UPDATE local_admin_users
            SET username = ?, password_hash = ?, role = 'superadmin',
                owner_admin_id = NULL, must_change_password = 0, is_active = 1
            WHERE id = ?");
        $update->execute([$username, $passwordHash, $superadminId]);
    } else {
        $insert = $pdo->prepare("INSERT INTO local_admin_users
            (username, password_hash, role, owner_admin_id, must_change_password, is_active)
            VALUES (?, ?, 'superadmin', NULL, 0, 1)");
        $insert->execute([$username, $passwordHash]);
        $superadminId = (int)$pdo->lastInsertId();
    }

    if ($superadminId <= 0) {
        throw new RuntimeException('Creation du superadmin impossible.');
    }

    $pdo->prepare("UPDATE local_admin_users
        SET role = 'admin', owner_admin_id = NULL
        WHERE id <> ? AND role NOT IN ('admin', 'reseller')")
        ->execute([$superadminId]);

    foreach ([
        'users',
        'profiles',
        'vouchers',
        'recharge_history',
        'operation_history',
        'recouvrement_invoices',
    ] as $table) {
        $pdo->exec(sprintf(
            'UPDATE %s SET owner_admin_id = %d WHERE owner_admin_id IS NULL',
            $table,
            $superadminId
        ));
    }

    $uuidHex = bin2hex(random_bytes(16));
    $uuid = substr($uuidHex, 0, 8) . '-' . substr($uuidHex, 8, 4) . '-4'
        . substr($uuidHex, 13, 3) . '-a' . substr($uuidHex, 17, 3)
        . '-' . substr($uuidHex, 20, 12);
    $installation = $pdo->prepare("INSERT INTO system_installation
        (id, installation_uuid, installed_by_user_id, schema_version)
        VALUES (1, ?, ?, '001_multi_tenant_foundation')");
    $installation->execute([$uuid, $superadminId]);

    $pdo->commit();
    fwrite(STDOUT, "Installation terminee. Superadmin: {$username}\n");
    fwrite(STDOUT, "Changez ensuite le mot de passe et retirez la variable {$passwordEnv}.\n");
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, "Installation annulee: {$e->getMessage()}\n");
    exit(1);
}
