<?php

require_once __DIR__ . '/../config/db.php';

function ensureTenancySchema(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }

    $table = $pdo->query("SHOW TABLES LIKE 'schema_migrations'")->fetchColumn();
    if (!$table) {
        throw new RuntimeException('Base non initialisee: executez tools/install.php.');
    }
    $stmt = $pdo->prepare('SELECT 1 FROM schema_migrations WHERE version = ?');
    $stmt->execute(['001_multi_tenant_foundation']);
    if (!$stmt->fetchColumn()) {
        throw new RuntimeException('Migration multi-tenant manquante: executez tools/install.php.');
    }

    $done = true;
}

function normalizeTenantRole(?string $role): string
{
    $role = strtolower(trim((string)$role));
    if ($role === 'administrator') $role = 'admin';
    if (!in_array($role, ['superadmin', 'admin', 'reseller'], true)) {
        throw new InvalidArgumentException('Role utilisateur invalide.');
    }
    return $role;
}

function currentTenantUserId(): int
{
    return max(0, (int)($_SESSION['local_admin_id'] ?? 0));
}

function currentTenantOwnerAdminId(): int
{
    return normalizeTenantRole((string)($_SESSION['user_role'] ?? 'admin')) === 'reseller'
        ? max(0, (int)($_SESSION['owner_admin_id'] ?? 0))
        : currentTenantUserId();
}

function tenantCryptoKey(): string
{
    $secret = trim((string)getenv('RADIUS_MANAGER_CRYPTO_KEY'));
    if ($secret === '') throw new RuntimeException('RADIUS_MANAGER_CRYPTO_KEY est obligatoire.');
    return hash('sha256', $secret, true);
}

function tenantEncryptSecret(string $value): string
{
    if ($value === '' || str_starts_with($value, 'encvps:')) return $value;
    $iv = random_bytes(12);
    $tag = '';
    $cipher = openssl_encrypt($value, 'aes-256-gcm', tenantCryptoKey(), OPENSSL_RAW_DATA, $iv, $tag);
    if ($cipher === false) throw new RuntimeException('Echec du chiffrement.');
    return 'encvps:' . base64_encode($iv . $tag . $cipher);
}

function tenantDecryptSecret(string $value): string
{
    if ($value === '') return '';
    if (!str_starts_with($value, 'encvps:')) throw new RuntimeException('Secret routeur non migre.');
    $raw = base64_decode(substr($value, 7), true);
    if ($raw === false || strlen($raw) < 29) throw new RuntimeException('Secret chiffre invalide.');
    $plain = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', tenantCryptoKey(), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
    if ($plain === false) throw new RuntimeException('Echec du dechiffrement.');
    return $plain;
}

function tenantCanAccessDevice(PDO $pdo, string $deviceId): bool
{
    ensureTenancySchema($pdo);
    $uid = currentTenantUserId();
    if ($uid <= 0 || $deviceId === '') return false;
    if (normalizeTenantRole((string)($_SESSION['user_role'] ?? 'admin')) === 'superadmin') {
        $stmt = $pdo->prepare('SELECT 1 FROM network_devices WHERE id = ?');
        $stmt->execute([$deviceId]);
        return (bool)$stmt->fetchColumn();
    }
    $stmt = $pdo->prepare('SELECT 1 FROM network_devices d LEFT JOIN management_user_devices a ON a.device_id=d.id AND a.user_id=? WHERE d.id=? AND (d.owner_admin_id=? OR a.user_id IS NOT NULL)');
    $stmt->execute([$uid, $deviceId, currentTenantOwnerAdminId()]);
    return (bool)$stmt->fetchColumn();
}

function requireTenantDeviceAccess(PDO $pdo, string $deviceId): void
{
    if (!tenantCanAccessDevice($pdo, trim($deviceId))) {
        http_response_code(403);
        throw new RuntimeException('Routeur non autorise.');
    }
}

function recordHotspotResourceOwnership(PDO $pdo, string $deviceId, string $type, string $key): void
{
    requireTenantDeviceAccess($pdo, $deviceId);
    $stmt = $pdo->prepare('INSERT INTO hotspot_resource_ownership (device_id,resource_type,resource_key,owner_admin_id,created_by_user_id) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE owner_admin_id=VALUES(owner_admin_id),created_by_user_id=VALUES(created_by_user_id)');
    $stmt->execute([$deviceId, $type, $key, currentTenantOwnerAdminId(), currentTenantUserId()]);
}
