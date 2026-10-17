<?php
// Machine d'états WAN (P2). Fonctions pures : aucune écriture sur le routeur.
// Source unique : l'état natif RouterOS (interface, DHCP, route). PHP ne fait que calculer la transition.

const WAN_STATE_NORMAL = 'NORMAL';
const WAN_STATE_DEGRADED = 'DEGRADED';
const WAN_STATE_DOWN = 'DOWN';
const WAN_STATE_RECOVERING = 'RECOVERING';
const WAN_STABLE_CYCLES = 3;

/**
 * Un WAN est utilisable si son interface est active et non désactivée, son client DHCP est bound,
 * et sa route par défaut est active. Les trois valeurs viennent du routeur.
 */
function wanStateIsUsable(bool $running, bool $disabled, bool $dhcpBound, bool $routeActive): bool
{
    return $running && !$disabled && $dhcpBound && $routeActive;
}

/**
 * Transition de la machine d'états à partir du nombre de WAN utilisables.
 * Retourne [état, compteur de stabilité]. Retour à NORMAL seulement après WAN_STABLE_CYCLES cycles stables.
 */
function wanStateNext(string $current, int $usable, int $total, int $stableCycles): array
{
    if ($total <= 0) {
        return [WAN_STATE_DOWN, 0];
    }
    if ($usable <= 0) {
        return [WAN_STATE_DOWN, 0];
    }
    if ($usable < $total) {
        return [WAN_STATE_DEGRADED, 0];
    }
    // Tous les WAN sont utilisables.
    if ($current === WAN_STATE_NORMAL) {
        return [WAN_STATE_NORMAL, 0];
    }
    $stable = $stableCycles + 1;
    if ($stable >= WAN_STABLE_CYCLES) {
        return [WAN_STATE_NORMAL, 0];
    }
    return [WAN_STATE_RECOVERING, $stable];
}

/**
 * Libellé de cause pour le journal : distingue une panne d'un quota (coupure volontaire).
 */
function wanStateCause(bool $disabledByQuota, bool $running, bool $dhcpBound, bool $routeActive): string
{
    if ($disabledByQuota) return 'quota';
    if (!$running) return 'interface';
    if (!$dhcpBound) return 'dhcp';
    if (!$routeActive) return 'route';
    return 'ok';
}

/**
 * Lit l'état natif des WAN sur le routeur (lecture seule).
 * $wans : slot => interface, ex. ['WAN1' => 'ether1', 'WAN2' => 'ether2'].
 * Retourne ['usable' => n, 'total' => n, 'detail' => [slot => ['usable' => bool, 'cause' => string]]].
 */
function wanStateReadRouter(RouterosAPI $api, array $wans): array
{
    $interfaces = $api->comm('/interface/print', ['.proplist' => 'name,running,disabled']);
    $dhcp = $api->comm('/ip/dhcp-client/print', ['.proplist' => 'interface,status,disabled']);
    $routes = $api->comm('/ip/route/print', ['.proplist' => 'dst-address,gateway,active,disabled']);
    $detail = [];
    $usable = 0;
    foreach ($wans as $slot => $if) {
        $iface = current(array_filter($interfaces, static fn($x) => ($x['name'] ?? '') === $if)) ?: [];
        $client = current(array_filter($dhcp, static fn($x) => ($x['interface'] ?? '') === $if)) ?: [];
        $route = false;
        foreach ($routes as $r) {
            if (($r['dst-address'] ?? '') === '0.0.0.0/0' && str_contains((string)($r['gateway'] ?? ''), '%' . $if)
                && ($r['active'] ?? '') === 'true' && ($r['disabled'] ?? '') !== 'true') {
                $route = true;
            }
        }
        $running = ($iface['running'] ?? '') === 'true';
        $disabled = ($iface['disabled'] ?? '') === 'true';
        $bound = ($client['status'] ?? '') === 'bound';
        $ok = wanStateIsUsable($running, $disabled, $bound, $route);
        $usable += $ok ? 1 : 0;
        $detail[$slot] = ['usable' => $ok, 'cause' => wanStateCause(false, $running, $bound, $route)];
    }
    return ['usable' => $usable, 'total' => count($wans), 'detail' => $detail];
}

/**
 * Dernière transition écrite dans le journal RouterOS : ['state' => string, 'at' => 'Y-m-d H:i:s'] ou null.
 * Format de ligne : « RM-WAN-STATE: ETAT usable=n/m ».
 */
function wanStateLastJournal(RouterosAPI $api): ?array
{
    $last = null;
    foreach ($api->comm('/log/print', ['.proplist' => 'time,message']) as $line) {
        $msg = (string)($line['message'] ?? '');
        if (preg_match('/RM-WAN-STATE: (NORMAL|DEGRADED|DOWN|RECOVERING) usable=\d+\/\d+/', $msg, $m)) {
            $last = ['state' => $m[1], 'at' => (string)($line['time'] ?? '')];
        }
    }
    return $last;
}

/**
 * Écrit une transition seulement si l'état calculé change. Retourne le message écrit, ou null si aucun changement.
 * Utilise le script RM-WAN-STATE-JOURNAL (écriture journal uniquement, aucune action sur le trafic).
 */
function wanStateJournalCycle(RouterosAPI $api, array $wans, ?PDO $pdo = null, string $deviceId = ''): ?string
{
    $now = wanStateReadRouter($api, $wans);
    $last = wanStateLastJournal($api);
    $current = $last['state'] ?? WAN_STATE_NORMAL;
    // Cycles stables = minutes écoulées depuis la dernière transition (un cycle = une minute).
    $stableCycles = 0;
    if ($last !== null && $current === WAN_STATE_RECOVERING && $last['at'] !== '') {
        $elapsed = (time() - (strtotime($last['at']) ?: time())) / 60;
        $stableCycles = max(0, (int)floor($elapsed) - 1);
    }
    [$next] = wanStateNext($current, $now['usable'], $now['total'], $stableCycles);
    if ($next === $current) {
        return null;
    }
    $causes = [];
    foreach ($now['detail'] as $slot => $d) {
        $causes[] = $slot . '=' . ($d['usable'] ? 'ok' : $d['cause']);
    }
    $message = 'RM-WAN-STATE: ' . $next . ' usable=' . $now['usable'] . '/' . $now['total'] . ' (' . implode(', ', $causes) . ')';
    $name = 'RM-WAN-STATE-JOURNAL';
    $scripts = $api->comm('/system/script/print', ['?name' => $name, '.proplist' => '.id']);
    if (!$scripts) {
        $api->comm('/system/script/add', ['name' => $name, 'source' => ':log info "' . $message . '"', 'comment' => 'Radius Manager P2 - journal de transitions WAN (aucune action)']);
    } else {
        $api->comm('/system/script/set', ['.id' => $scripts[0]['.id'], 'source' => ':log info "' . $message . '"']);
    }
    $api->comm('/system/script/run', ['number' => $name]);
    // Bascule des débits : 25 Mb/s et débits dégradés en DEGRADED, restauration en NORMAL.
    if ($pdo !== null && in_array($next, [WAN_STATE_DEGRADED, WAN_STATE_NORMAL], true)) {
        try {
            $rates = wanStateApplyRates($api, $pdo, $deviceId, $next);
            error_log('[wan_state] débits : ' . json_encode($rates));
        } catch (Throwable $rateError) {
            error_log('[wan_state] bascule des débits échouée : ' . $rateError->getMessage());
        }
    }
    // Alerte administrateur visible (bas-droite + admin_notifications), une par transition.
    if ($pdo !== null) {
        $severity = match ($next) {
            WAN_STATE_DOWN => 'critical',
            WAN_STATE_DEGRADED => 'warning',
            default => 'info',
        };
        $sourceRef = $deviceId . '_' . $next . '_' . date('YmdHis');
        if (!adminNotificationExists($pdo, 'wan_state', $sourceRef)) {
            createAdminNotification($pdo, [
                'severity' => $severity,
                'category' => 'wan_state',
                'source_type' => 'wan_state',
                'source_ref' => $sourceRef,
                'title' => 'Etat WAN : ' . $next,
                'message' => 'WAN utilisables ' . $now['usable'] . '/' . $now['total'] . ' (' . implode(', ', $causes) . ').',
            ]);
        }
    }
    return $message;
}

/**
 * Plafond global et débits par profil selon l'état (P2).
 * NORMAL : plafond bridge inchangé et profils à leur débit normal.
 * DEGRADED : plafond bridge = 25 Mb/s en téléchargement, profils à leur débit dégradé (métadonnée on-login).
 * Les valeurs d'origine sont sauvegardées avant la bascule (table wan_rate_backup) et restaurées au retour.
 */
const WAN_DEGRADED_BRIDGE_DOWNLOAD = '25000000';

function wanStateEnsureRateBackupTable(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS wan_rate_backup (
        device_id VARCHAR(64) NOT NULL PRIMARY KEY,
        backup_json MEDIUMTEXT NOT NULL,
        saved_at DATETIME NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function wanStateApplyRates(RouterosAPI $api, PDO $pdo, string $deviceId, string $state): array
{
    wanStateEnsureRateBackupTable($pdo);
    $queueName = 'RM interface bridge1';
    $queues = $api->comm('/queue/simple/print', ['?name' => $queueName, '.proplist' => '.id,name,max-limit']);
    if (!$queues) {
        throw new RuntimeException('File parente « ' . $queueName . ' » introuvable : aucun changement appliqué.');
    }
    $queueId = (string)$queues[0]['.id'];
    $profiles = $api->comm('/ip/hotspot/profile/print', ['.proplist' => '.id,name']);
    $userProfiles = $api->comm('/ip/hotspot/user/profile/print', ['.proplist' => '.id,name,rate-limit,on-login']);

    $stmt = $pdo->prepare('SELECT backup_json FROM wan_rate_backup WHERE device_id = ?');
    $stmt->execute([$deviceId]);
    $existing = $stmt->fetchColumn();

    if ($state === WAN_STATE_DEGRADED) {
        if ($existing) {
            return ['action' => 'already_degraded'];
        }
        $backup = ['queue_id' => $queueId, 'queue_max_limit' => (string)$queues[0]['max-limit'], 'profiles' => []];
        $changes = [];
        foreach ($userProfiles as $p) {
            $degraded = $p['on-login'] ?? '';
            if (!preg_match('/:put \(",[^"]*,(\d+[KM]\/\d+[KM])"\)/', (string)$degraded, $m)) {
                continue;
            }
            $backup['profiles'][$p['.id']] = ['name' => $p['name'], 'rate_limit' => (string)$p['rate-limit']];
            $changes[] = ['id' => $p['.id'], 'rate' => $m[1]];
        }
        $pdo->prepare('INSERT INTO wan_rate_backup (device_id, backup_json, saved_at) VALUES (?, ?, NOW())')
            ->execute([$deviceId, json_encode($backup)]);
        $upload = explode('/', (string)$queues[0]['max-limit'])[0];
        $api->comm('/queue/simple/set', ['.id' => $queueId, 'max-limit' => $upload . '/' . WAN_DEGRADED_BRIDGE_DOWNLOAD]);
        foreach ($changes as $c) {
            $api->comm('/ip/hotspot/user/profile/set', ['.id' => $c['id'], 'rate-limit' => $c['rate']]);
        }
        return ['action' => 'degraded', 'profiles' => count($changes)];
    }

    if ($existing && $state === WAN_STATE_NORMAL) {
        $backup = json_decode((string)$existing, true);
        $api->comm('/queue/simple/set', ['.id' => $backup['queue_id'], 'max-limit' => $backup['queue_max_limit']]);
        foreach ($backup['profiles'] as $id => $p) {
            $api->comm('/ip/hotspot/user/profile/set', ['.id' => $id, 'rate-limit' => $p['rate_limit']]);
        }
        $pdo->prepare('DELETE FROM wan_rate_backup WHERE device_id = ?')->execute([$deviceId]);
        return ['action' => 'restored', 'profiles' => count($backup['profiles'])];
    }
    return ['action' => 'none'];
}
