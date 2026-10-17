<?php

require_once __DIR__ . '/mikrotik_backend.php';
require_once __DIR__ . '/multi_wan_config.php';
require_once __DIR__ . '/admin_notifications.php';

function ensureWanCapStateTable(PDO $pdo): void
{
    static $done = false;
    if ($done) return;
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS wan_cap_state (
            device_id VARCHAR(64) NOT NULL,
            wan_slot VARCHAR(16) NOT NULL,
            cap_date DATE NOT NULL,
            alert_90_sent TINYINT(1) NOT NULL DEFAULT 0,
            cutoff_applied TINYINT(1) NOT NULL DEFAULT 0,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (device_id, wan_slot)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $done = true;
}

/**
 * Coupe reellement l interface WAN quand son day_bytes atteint le plafond daily_target_gb du jour
 * (demande explicite admin, 2026-10-03 : alerte a 90%, coupure franche a 100%, remise en service
 * automatique au changement de jour calendaire plutot qu une bascule N-WAN). Pas de seuil pour
 * daily_target_gb<=0 (objectif non defini = pas de surveillance stricte sur ce WAN).
 */
function enforceWanDailyCaps(PDO $pdo, RouterosAPI $api, array $device, array $usageSummary): void
{
    ensureWanCapStateTable($pdo);
    $deviceId = trim((string)($device['id'] ?? ''));
    if ($deviceId === '') return;

    $config = multiWanLoadConfig($device);
    $sources = $config['sources'] ?? [];
    $today = date('Y-m-d');

    foreach ($sources as $wanSlot => $source) {
        if (!is_array($source) || empty($source['enabled'])) continue;
        $interfaceName = trim((string)($source['interface'] ?? ''));
        $targetGb = (float)($source['daily_target_gb'] ?? 0);
        if ($interfaceName === '' || $targetGb <= 0) continue;

        $stmt = $pdo->prepare('SELECT cap_date, alert_90_sent, cutoff_applied FROM wan_cap_state WHERE device_id=? AND wan_slot=?');
        $stmt->execute([$deviceId, $wanSlot]);
        $state = $stmt->fetch(PDO::FETCH_ASSOC);

        $wasCutoff = (bool)($state['cutoff_applied'] ?? false);
        $isNewDay = !$state || (string)$state['cap_date'] !== $today;

        if ($isNewDay) {
            if ($wasCutoff) {
                mikrotikSetInterfaceDisabledByName($api, $interfaceName, false);
                $sourceRef = $deviceId . '_' . $wanSlot . '_' . $today . '_restored';
                if (!adminNotificationExists($pdo, 'wan_cap_cutoff', $sourceRef)) {
                    createAdminNotification($pdo, [
                        'severity' => 'info',
                        'category' => 'wan_cap',
                        'source_type' => 'wan_cap_cutoff',
                        'source_ref' => $sourceRef,
                        'title' => 'WAN remis en service',
                        'message' => "Interface $interfaceName (" . ($source['label'] ?? $wanSlot) . ") remise en service au changement de jour.",
                    ]);
                }
            }
            $pdo->prepare('INSERT INTO wan_cap_state (device_id, wan_slot, cap_date, alert_90_sent, cutoff_applied) VALUES (?,?,?,0,0)
                ON DUPLICATE KEY UPDATE cap_date=VALUES(cap_date), alert_90_sent=0, cutoff_applied=0')
                ->execute([$deviceId, $wanSlot, $today]);
            $state = ['cap_date' => $today, 'alert_90_sent' => 0, 'cutoff_applied' => 0];
        }

        if ((bool)$state['cutoff_applied']) continue;

        $dayBytes = (int)($usageSummary[$interfaceName]['day_bytes'] ?? 0);
        $targetBytes = (int)round($targetGb * 1_000_000_000);
        $label = (string)($source['label'] ?? $wanSlot);
        $usedGb = round($dayBytes / 1_000_000_000, 1);

        if ($dayBytes >= $targetBytes) {
            mikrotikSetInterfaceDisabledByName($api, $interfaceName, true);
            $sourceRef = $deviceId . '_' . $wanSlot . '_' . $today . '_cutoff';
            if (!adminNotificationExists($pdo, 'wan_cap_cutoff', $sourceRef)) {
                createAdminNotification($pdo, [
                    'severity' => 'critical',
                    'category' => 'wan_cap',
                    'source_type' => 'wan_cap_cutoff',
                    'source_ref' => $sourceRef,
                    'title' => 'WAN coupe - plafond journalier atteint',
                    'message' => "$label ($interfaceName) coupee : {$usedGb} Go utilises pour un plafond de {$targetGb} Go/jour. Remise en service automatique demain.",
                ]);
            }
            $pdo->prepare('UPDATE wan_cap_state SET alert_90_sent=1, cutoff_applied=1 WHERE device_id=? AND wan_slot=?')->execute([$deviceId, $wanSlot]);
        } elseif (!$state['alert_90_sent'] && $dayBytes >= $targetBytes * 0.9) {
            $sourceRef = $deviceId . '_' . $wanSlot . '_' . $today . '_alert90';
            if (!adminNotificationExists($pdo, 'wan_cap_alert', $sourceRef)) {
                createAdminNotification($pdo, [
                    'severity' => 'warning',
                    'category' => 'wan_cap',
                    'source_type' => 'wan_cap_alert',
                    'source_ref' => $sourceRef,
                    'title' => 'WAN proche du plafond journalier',
                    'message' => "$label ($interfaceName) a consomme {$usedGb} Go sur un plafond de {$targetGb} Go/jour (90% atteint). Coupure automatique si le plafond est depasse.",
                ]);
            }
            $pdo->prepare('UPDATE wan_cap_state SET alert_90_sent=1 WHERE device_id=? AND wan_slot=?')->execute([$deviceId, $wanSlot]);
        }
    }
}
