<?php
// Libellés des comptes Hotspot en login par MAC (affichage uniquement).
// Le compte RouterOS reste la seule source pour le login, l'expiration et les limites.

function ensureHotspotMacLabelsTable(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS hotspot_mac_labels (
        device_id VARCHAR(64) NOT NULL,
        mac VARCHAR(17) NOT NULL,
        label VARCHAR(120) NOT NULL,
        updated_at DATETIME NOT NULL,
        PRIMARY KEY (device_id, mac)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function saveHotspotMacLabel(PDO $pdo, string $deviceId, string $mac, string $label): void
{
    ensureHotspotMacLabelsTable($pdo);
    $stmt = $pdo->prepare('INSERT INTO hotspot_mac_labels (device_id, mac, label, updated_at) VALUES (?, ?, ?, NOW())
        ON DUPLICATE KEY UPDATE label = VALUES(label), updated_at = NOW()');
    $stmt->execute([$deviceId, strtoupper($mac), $label]);
}

function getHotspotMacLabels(PDO $pdo, string $deviceId): array
{
    static $cache = [];
    if (isset($cache[$deviceId])) {
        return $cache[$deviceId];
    }
    ensureHotspotMacLabelsTable($pdo);
    $stmt = $pdo->prepare('SELECT mac, label FROM hotspot_mac_labels WHERE device_id = ?');
    $stmt->execute([$deviceId]);
    $map = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $map[strtoupper((string)$row['mac'])] = (string)$row['label'];
    }
    $cache[$deviceId] = $map;
    return $map;
}
