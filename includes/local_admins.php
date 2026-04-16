<?php

function ensureLocalAdminTable(PDO $pdo): void
{
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS local_admin_users (
            id INT(11) NOT NULL AUTO_INCREMENT,
            username VARCHAR(100) NOT NULL,
            password_hash VARCHAR(255) NOT NULL,
            role ENUM('administrator','reseller') NOT NULL DEFAULT 'administrator',
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_local_admin_username (username)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
    );

    $count = (int)$pdo->query("SELECT COUNT(*) FROM local_admin_users")->fetchColumn();
    try {
        $columns = $pdo->query("SHOW COLUMNS FROM local_admin_users")->fetchAll(PDO::FETCH_COLUMN) ?: [];
        if (!in_array('role', $columns, true)) {
            $pdo->exec("ALTER TABLE local_admin_users ADD COLUMN role ENUM('administrator','reseller') NOT NULL DEFAULT 'administrator' AFTER password_hash");
        }
    } catch (Throwable $e) {
        // migration best effort
    }

    if ($count === 0) {
        $stmt = $pdo->prepare('INSERT INTO local_admin_users (username, password_hash, role, is_active) VALUES (?, ?, ?, 1)');
        $stmt->execute([
            'monutilisateur',
            password_hash('monmotdepasse', PASSWORD_DEFAULT),
            'administrator',
        ]);
    }
}

function verifyLocalAdminCredentials(PDO $pdo, string $username, string $password): ?array
{
    ensureLocalAdminTable($pdo);

    $stmt = $pdo->prepare('SELECT id, username, password_hash, role, is_active FROM local_admin_users WHERE username = ? LIMIT 1');
    $stmt->execute([trim($username)]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row || (int)($row['is_active'] ?? 0) !== 1) {
        return null;
    }

    if (!password_verify($password, (string)$row['password_hash'])) {
        return null;
    }

    return $row;
}

function listLocalAdmins(PDO $pdo): array
{
    ensureLocalAdminTable($pdo);
    $stmt = $pdo->query('SELECT id, username, role, is_active, created_at, updated_at FROM local_admin_users ORDER BY username ASC');
    return $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
}

function normalizeLocalAdminRole(?string $role): string
{
    return trim((string)$role) === 'reseller' ? 'reseller' : 'administrator';
}

function localAdminRoleLabel(?string $role): string
{
    return normalizeLocalAdminRole($role) === 'reseller' ? 'Revendeur' : 'Administrateur';
}

function createLocalAdmin(PDO $pdo, string $username, string $password, ?string $role = null): void
{
    ensureLocalAdminTable($pdo);

    $username = trim($username);
    if ($username === '' || $password === '') {
        throw new RuntimeException('Utilisateur ou mot de passe manquant');
    }

    $stmt = $pdo->prepare('INSERT INTO local_admin_users (username, password_hash, role, is_active) VALUES (?, ?, ?, 1)');
    $stmt->execute([$username, password_hash($password, PASSWORD_DEFAULT), normalizeLocalAdminRole($role)]);
}

function updateLocalAdmin(PDO $pdo, int $id, string $username, ?string $password, bool $isActive, ?string $role = null): void
{
    ensureLocalAdminTable($pdo);

    $username = trim($username);
    if ($id <= 0 || $username === '') {
        throw new RuntimeException('Identifiant ou utilisateur invalide');
    }

    $normalizedRole = normalizeLocalAdminRole($role);

    if ($password !== null && $password !== '') {
        $stmt = $pdo->prepare('UPDATE local_admin_users SET username = ?, password_hash = ?, role = ?, is_active = ? WHERE id = ?');
        $stmt->execute([$username, password_hash($password, PASSWORD_DEFAULT), $normalizedRole, $isActive ? 1 : 0, $id]);
        return;
    }

    $stmt = $pdo->prepare('UPDATE local_admin_users SET username = ?, role = ?, is_active = ? WHERE id = ?');
    $stmt->execute([$username, $normalizedRole, $isActive ? 1 : 0, $id]);
}

function deleteLocalAdmin(PDO $pdo, int $id, ?string $protectedUsername = null): void
{
    ensureLocalAdminTable($pdo);

    if ($id <= 0) {
        throw new RuntimeException('Identifiant invalide');
    }

    $stmt = $pdo->prepare('SELECT username FROM local_admin_users WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);
    $username = (string)($stmt->fetchColumn() ?: '');

    if ($username === '') {
        throw new RuntimeException('Utilisateur introuvable');
    }

    if ($protectedUsername !== null && $username === $protectedUsername) {
        throw new RuntimeException('Impossible de supprimer l utilisateur actuellement connecte');
    }

    $activeCount = (int)$pdo->query('SELECT COUNT(*) FROM local_admin_users WHERE is_active = 1')->fetchColumn();
    $isActiveStmt = $pdo->prepare('SELECT is_active FROM local_admin_users WHERE id = ? LIMIT 1');
    $isActiveStmt->execute([$id]);
    $isActive = (int)$isActiveStmt->fetchColumn() === 1;
    if ($isActive && $activeCount <= 1) {
        throw new RuntimeException('Impossible de supprimer le dernier compte actif');
    }

    $delete = $pdo->prepare('DELETE FROM local_admin_users WHERE id = ?');
    $delete->execute([$id]);
}
