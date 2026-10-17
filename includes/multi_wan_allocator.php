<?php

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/lib/routeros_api.class.php';
require_once __DIR__ . '/mikrotik_backend.php';

function multiWanEnsureAssignmentTable(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS wan_client_assignments (
        id INT AUTO_INCREMENT PRIMARY KEY,
        device_id VARCHAR(128) NOT NULL,
        client_ip VARCHAR(45) NOT NULL,
        wan_id VARCHAR(16) NOT NULL,
        interface_name VARCHAR(64) NOT NULL,
        assigned_at DATETIME NOT NULL,
        last_seen DATETIME NOT NULL,
        status VARCHAR(32) NOT NULL DEFAULT 'active',
        meta_json JSON NULL,
        UNIQUE KEY uniq_device_client (device_id, client_ip),
        KEY idx_device_wan (device_id, wan_id),
        KEY idx_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function multiWanEnsureUserOverrideTable(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS wan_client_user_overrides (
        id INT AUTO_INCREMENT PRIMARY KEY,
        device_id VARCHAR(128) NOT NULL,
        username VARCHAR(190) NOT NULL,
        wan_id VARCHAR(16) NOT NULL,
        interface_name VARCHAR(64) NOT NULL,
        enabled TINYINT(1) NOT NULL DEFAULT 1,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        comment VARCHAR(255) DEFAULT NULL,
        UNIQUE KEY uniq_device_username (device_id, username),
        KEY idx_device_wan_enabled (device_id, wan_id, enabled)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function multiWanLoadUserOverrides(PDO $pdo, string $deviceId): array
{
    multiWanEnsureUserOverrideTable($pdo);
    $stmt = $pdo->prepare("SELECT username, wan_id, interface_name FROM wan_client_user_overrides WHERE device_id = ? AND enabled = 1 AND wan_id IN ('WAN1','WAN2','WAN3','WAN4')");
    $stmt->execute([$deviceId]);
    $overrides = [];
    foreach ($stmt as $row) {
        $username = trim((string)($row['username'] ?? ''));
        $wanId = trim((string)($row['wan_id'] ?? ''));
        $interface = trim((string)($row['interface_name'] ?? ''));
        if ($username !== '' && $wanId !== '' && $interface !== '') {
            $overrides[$username] = ['wan_id' => $wanId, 'interface_name' => $interface];
        }
    }
    return $overrides;
}


function multiWanManagedClientLists(): array
{
    return ['WAN1_CLIENTS', 'WAN2_CLIENTS', 'WAN3_CLIENTS', 'WAN4_CLIENTS'];
}

function multiWanManagedForceLists(): array
{
    return ['WAN1_FORCE', 'WAN2_FORCE', 'WAN3_FORCE', 'WAN4_FORCE'];
}

function multiWanNormalizeWanId(string $wanId): string
{
    $wanId = strtoupper(trim($wanId));
    if (!preg_match('/^WAN[1-4]$/', $wanId)) {
        throw new InvalidArgumentException('WAN invalide.');
    }
    return $wanId;
}

function multiWanSaveUserOverride(PDO $pdo, string $deviceId, string $username, string $wanId, string $interface, string $comment = ''): void
{
    multiWanEnsureUserOverrideTable($pdo);
    $username = trim($username);
    $wanId = multiWanNormalizeWanId($wanId);
    $interface = trim($interface);
    if ($deviceId === '' || $username === '' || $interface === '') {
        throw new InvalidArgumentException('Exception WAN incomplète.');
    }
    $stmt = $pdo->prepare('INSERT INTO wan_client_user_overrides (device_id, username, wan_id, interface_name, enabled, created_at, updated_at, comment) VALUES (?, ?, ?, ?, 1, NOW(), NOW(), ?) ON DUPLICATE KEY UPDATE wan_id = VALUES(wan_id), interface_name = VALUES(interface_name), enabled = 1, updated_at = VALUES(updated_at), comment = VALUES(comment)');
    $stmt->execute([$deviceId, $username, $wanId, $interface, $comment !== '' ? $comment : null]);
}

function multiWanDeleteUserOverride(PDO $pdo, string $deviceId, string $username): void
{
    multiWanEnsureUserOverrideTable($pdo);
    $stmt = $pdo->prepare('DELETE FROM wan_client_user_overrides WHERE device_id = ? AND username = ?');
    $stmt->execute([$deviceId, trim($username)]);
}

function multiWanRows(RouterosAPI $api, string $path, array $params = []): array
{
    $out = $api->comm($path, $params);
    return is_array($out) ? array_values(array_filter($out, 'is_array')) : [];
}

function multiWanActiveUserIps(RouterosAPI $api, string $username): array
{
    $username = trim($username);
    if ($username === '') return [];
    $ips = [];
    foreach (multiWanRows($api, '/ip/hotspot/active/print', ['?user' => $username, '.proplist' => 'address,user']) as $row) {
        $ip = trim((string)($row['address'] ?? ''));
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $ips[$ip] = true;
        }
    }
    return array_keys($ips);
}

function multiWanRemoveIpsFromManagedLists(RouterosAPI $api, array $ips): array
{
    $wanted = [];
    foreach ($ips as $ip) {
        $ip = trim((string)$ip);
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $wanted[$ip] = true;
        }
    }
    if ($wanted === []) return ['removed' => 0];
    $managedLists = array_merge(multiWanManagedClientLists(), multiWanManagedForceLists());
    $removed = 0;
    foreach (multiWanRows($api, '/ip/firewall/address-list/print', ['.proplist' => '.id,list,address']) as $row) {
        $list = (string)($row['list'] ?? '');
        $ip = (string)($row['address'] ?? '');
        if (!isset($wanted[$ip]) || !in_array($list, $managedLists, true)) continue;
        multiWanAssertOk($api->comm('/ip/firewall/address-list/remove', ['.id' => (string)$row['.id']]), 'Suppression address-list gérée refusée');
        $removed++;
    }
    return ['removed' => $removed];
}

function multiWanHasTrap($result): bool
{
    if (!is_array($result)) return false;
    foreach ($result as $row) {
        if (is_array($row) && isset($row['!trap'])) return true;
    }
    return false;
}

function multiWanAssertOk($result, string $message): void
{
    if (multiWanHasTrap($result)) {
        throw new RuntimeException($message . ': ' . json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}
