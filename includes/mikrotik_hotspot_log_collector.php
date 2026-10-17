<?php

require_once __DIR__ . '/mikrotik_backend.php';

/* Le buffer /log de RouterOS est volatile (memory-lines=1000, mais tout topic confondu il se
   reecrit en ~2h sur ce reseau) : on archive donc les lignes hotspot dans une table locale a
   chaque passage cron, pour conserver un historique plus long que ce que le routeur garde
   lui-meme. Retention 48h (cron toutes les 2h) : estimee a ~6-7 Mo, negligeable. */
function mikrotikHotspotLogEnsureTable(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS mikrotik_hotspot_log_events (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        device_id VARCHAR(64) NOT NULL,
        logged_at DATETIME NOT NULL,
        username VARCHAR(128) NOT NULL DEFAULT '',
        address VARCHAR(64) NOT NULL DEFAULT '',
        action VARCHAR(191) NOT NULL DEFAULT '',
        status VARCHAR(32) NOT NULL DEFAULT '',
        raw_message VARCHAR(255) NOT NULL DEFAULT '',
        message_hash CHAR(32) NOT NULL DEFAULT '',
        PRIMARY KEY (id),
        UNIQUE KEY idx_hotspot_log_dedup (device_id, logged_at, message_hash),
        KEY idx_hotspot_log_device_time (device_id, logged_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    /* Migration douce : si la table existait deja avec l'ancienne cle (prefixe du message tronque
       a 100 car.), on bascule sur un hash MD5 du message complet - dedup plus sur, insensible a la
       longueur du message. */
    $column = $pdo->query("SHOW COLUMNS FROM mikrotik_hotspot_log_events LIKE 'message_hash'")->fetch(PDO::FETCH_ASSOC);
    if (!$column) {
        $pdo->exec('ALTER TABLE mikrotik_hotspot_log_events ADD COLUMN message_hash CHAR(32) NOT NULL DEFAULT \'\' AFTER raw_message');
        $pdo->exec('UPDATE mikrotik_hotspot_log_events SET message_hash = MD5(raw_message) WHERE message_hash = \'\'');
    }
    $indexes = $pdo->query('SHOW INDEX FROM mikrotik_hotspot_log_events WHERE Key_name = \'idx_hotspot_log_dedup\'')->fetchAll(PDO::FETCH_ASSOC);
    $usesOldKey = false;
    foreach ($indexes as $idx) {
        if (($idx['Column_name'] ?? '') === 'raw_message') {
            $usesOldKey = true;
            break;
        }
    }
    if ($usesOldKey) {
        $pdo->exec('UPDATE mikrotik_hotspot_log_events SET message_hash = MD5(raw_message) WHERE message_hash = \'\'');
        $pdo->exec('ALTER TABLE mikrotik_hotspot_log_events DROP INDEX idx_hotspot_log_dedup');
        $pdo->exec('ALTER TABLE mikrotik_hotspot_log_events ADD UNIQUE KEY idx_hotspot_log_dedup (device_id, logged_at, message_hash)');
    }
}

function mikrotikHotspotLogCollect(PDO $pdo, array $device, RouterosAPI $api): int
{
    mikrotikHotspotLogEnsureTable($pdo);

    $deviceId = trim((string)(($device['id'] ?? '') ?: ($device['host'] ?? '')));
    if ($deviceId === '') {
        return 0;
    }

    $rows = $api->comm('/log/print', ['.proplist' => 'time,message,topics']);
    if (!is_array($rows)) {
        return 0;
    }

    $insertStmt = $pdo->prepare(
        'INSERT IGNORE INTO mikrotik_hotspot_log_events
            (device_id, logged_at, username, address, action, status, raw_message, message_hash)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    );

    $inserted = 0;
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $topics = strtolower(trim((string)($row['topics'] ?? '')));
        if (!str_contains($topics, 'hotspot')) {
            continue;
        }
        $parsed = parseMikrotikHotspotLogMessage($row);
        if ($parsed === null) {
            continue;
        }
        $loggedAt = trim((string)($row['time'] ?? ''));
        if ($loggedAt === '' || strtotime($loggedAt) === false) {
            continue;
        }

        $rawMessage = mb_substr((string)$parsed['raw_message'], 0, 255);
        $insertStmt->execute([
            $deviceId,
            date('Y-m-d H:i:s', strtotime($loggedAt)),
            mb_substr((string)$parsed['user'], 0, 128),
            mb_substr((string)$parsed['address'], 0, 64),
            mb_substr((string)$parsed['action'], 0, 191),
            mb_substr((string)$parsed['status'], 0, 32),
            $rawMessage,
            md5($rawMessage),
        ]);
        if ($insertStmt->rowCount() > 0) {
            $inserted++;
        }
    }

    $pdo->exec('DELETE FROM mikrotik_hotspot_log_events WHERE logged_at < DATE_SUB(NOW(), INTERVAL 48 HOUR)');

    return $inserted;
}

function mikrotikHotspotLogFetch(PDO $pdo, string $deviceId, int $limit = 2000): array
{
    mikrotikHotspotLogEnsureTable($pdo);

    $stmt = $pdo->prepare(
        'SELECT logged_at, username, address, action, status, raw_message
         FROM mikrotik_hotspot_log_events
         WHERE device_id = ?
         ORDER BY logged_at DESC
         LIMIT ' . (int)$limit
    );
    $stmt->execute([$deviceId]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function mikrotikHotspotLogLastTimestamp(PDO $pdo, string $deviceId): ?string
{
    mikrotikHotspotLogEnsureTable($pdo);
    $stmt = $pdo->prepare('SELECT MAX(logged_at) FROM mikrotik_hotspot_log_events WHERE device_id = ?');
    $stmt->execute([$deviceId]);
    $value = $stmt->fetchColumn();
    return $value !== false && $value !== null ? (string)$value : null;
}

/* Comble l'ecart entre le dernier passage cron (toutes les 2h) et l'instant present : lecture live
   du buffer RouterOS, mais seulement les lignes posterieures a $afterDatetime (deja archivees
   sinon). Echoue silencieusement (page utilisable meme routeur injoignable) - l'appelant decide
   quoi afficher en cas d'echec. */
function mikrotikHotspotLogFetchLiveGap(array $device, ?string $afterDatetime): array
{
    $api = connectToMikrotikApiByDevice($device);
    try {
        $rows = $api->comm('/log/print', ['.proplist' => 'time,message,topics']);
    } finally {
        $api->disconnect();
    }
    if (!is_array($rows)) {
        return [];
    }

    $afterTs = $afterDatetime !== null ? strtotime($afterDatetime) : null;
    $events = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $topics = strtolower(trim((string)($row['topics'] ?? '')));
        if (!str_contains($topics, 'hotspot')) {
            continue;
        }
        $loggedAt = trim((string)($row['time'] ?? ''));
        $ts = $loggedAt !== '' ? strtotime($loggedAt) : false;
        if ($ts === false) {
            continue;
        }
        if ($afterTs !== null && $ts <= $afterTs) {
            continue;
        }
        $parsed = parseMikrotikHotspotLogMessage($row);
        if ($parsed === null) {
            continue;
        }
        $events[] = [
            'time' => date('Y-m-d H:i:s', $ts),
            'user' => (string)$parsed['user'],
            'address' => (string)$parsed['address'],
            'action' => (string)$parsed['action'],
            'status' => (string)$parsed['status'],
            'raw_message' => (string)$parsed['raw_message'],
        ];
    }

    return $events;
}

/* Reconstruit des sessions (debut/fin/duree) en appariant les evenements logged-in/logged-out de
   l'archive locale, pour les comptes MikroTik local qui n'ont pas de donnees RADIUS (radacct vide).
   Pas de nouvelle collecte : on relit mikrotik_hotspot_log_events, deja alimentee pour user_logs.php. */
function mikrotikHotspotLogReconstructSessions(PDO $pdo, string $deviceId, string $username, int $limit = 50): array
{
    mikrotikHotspotLogEnsureTable($pdo);

    $stmt = $pdo->prepare(
        'SELECT logged_at, address, status
         FROM mikrotik_hotspot_log_events
         WHERE device_id = ? AND username = ? AND status IN (\'login\', \'logout\')
         ORDER BY logged_at ASC'
    );
    $stmt->execute([$deviceId, $username]);
    $events = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    /* Reconnexions rapprochees (DHCP renew, roaming Wi-Fi, keepalive...) emettent plusieurs
       "login" en quelques secondes sans logout entre les deux : sous ce seuil, on les traite comme
       la continuation de la meme session plutot que de creer des sessions fantomes de 1-2s. */
    $mergeGapSeconds = 30;

    $sessions = [];
    $openStart = null;
    $openAddress = '';
    $lastEventTs = null;
    $lastSeenKey = '';

    foreach ($events as $event) {
        $loggedAt = (string)($event['logged_at'] ?? '');
        $status = (string)($event['status'] ?? '');
        $address = (string)($event['address'] ?? '');
        $ts = strtotime($loggedAt);
        if ($ts === false) {
            continue;
        }

        /* Plusieurs lignes de log (ex. "log in by http-chap" + "logged in") decrivent le meme
           evenement reel a la meme seconde : on ne garde que la premiere occurrence. */
        $dedupeKey = $loggedAt . '|' . $status;
        if ($dedupeKey === $lastSeenKey) {
            continue;
        }
        $lastSeenKey = $dedupeKey;

        if ($status === 'login') {
            if ($openStart !== null && $lastEventTs !== null && ($ts - $lastEventTs) <= $mergeGapSeconds) {
                /* Continuation de la session ouverte : on ignore ce login redondant. */
                $lastEventTs = $ts;
                continue;
            }
            if ($openStart !== null) {
                /* Nouvelle connexion sans deconnexion journalisee entre les deux : on cloture la
                   precedente a l'instant de la nouvelle (approximation, signalee dans le libelle). */
                $sessions[] = [
                    'start' => $openStart,
                    'stop' => $loggedAt,
                    'approx_stop' => true,
                    'ip' => $openAddress,
                ];
            }
            $openStart = $loggedAt;
            $openAddress = $address;
        } elseif ($status === 'logout' && $openStart !== null) {
            $sessions[] = [
                'start' => $openStart,
                'stop' => $loggedAt,
                'approx_stop' => false,
                'ip' => $openAddress,
            ];
            $openStart = null;
            $openAddress = '';
        }
        $lastEventTs = $ts;
    }

    /* Derniere session encore ouverte (pas de logout dans la fenetre archivee) : laissee de cote,
       la session en cours est deja fournie separement via /ip/hotspot/active si l'utilisateur est
       en ligne. */

    $sessions = array_reverse($sessions);
    return array_slice($sessions, 0, max(0, $limit));
}
