<?php

function ensureAdminNotificationsTable(PDO $pdo): void
{
    static $done = false;

    if ($done) {
        return;
    }

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS admin_notifications (
            id INT(11) NOT NULL AUTO_INCREMENT,
            severity VARCHAR(20) NOT NULL DEFAULT 'info',
            category VARCHAR(40) NOT NULL DEFAULT 'system',
            source_type VARCHAR(50) DEFAULT NULL,
            source_ref VARCHAR(120) DEFAULT NULL,
            title VARCHAR(180) NOT NULL,
            message TEXT DEFAULT NULL,
            details_json LONGTEXT DEFAULT NULL,
            is_read TINYINT(1) NOT NULL DEFAULT 0,
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            read_at TIMESTAMP NULL DEFAULT NULL,
            PRIMARY KEY (id),
            KEY idx_admin_notifications_read (is_read),
            KEY idx_admin_notifications_severity (severity),
            KEY idx_admin_notifications_category (category),
            KEY idx_admin_notifications_created_at (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
    );

    $done = true;
}

function ensureDeviceHealthMonitorTable(PDO $pdo): void
{
    static $done = false;

    if ($done) {
        return;
    }

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS device_health_monitor (
            device_id VARCHAR(120) NOT NULL,
            device_name VARCHAR(180) DEFAULT NULL,
            device_type VARCHAR(20) DEFAULT NULL,
            host VARCHAR(255) DEFAULT NULL,
            last_state VARCHAR(20) NOT NULL DEFAULT 'unknown',
            last_error_message TEXT DEFAULT NULL,
            last_checked_at DATETIME DEFAULT NULL,
            last_success_at DATETIME DEFAULT NULL,
            consecutive_failures INT(11) NOT NULL DEFAULT 0,
            PRIMARY KEY (device_id),
            KEY idx_device_health_monitor_state (last_state),
            KEY idx_device_health_monitor_checked (last_checked_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
    );

    $done = true;
}

function getDeviceHealthMonitorState(PDO $pdo, string $deviceId): ?array
{
    ensureDeviceHealthMonitorTable($pdo);

    $stmt = $pdo->prepare("
        SELECT
            device_id,
            device_name,
            device_type,
            host,
            last_state,
            last_error_message,
            last_checked_at,
            last_success_at,
            consecutive_failures
        FROM device_health_monitor
        WHERE device_id = ?
        LIMIT 1
    ");
    $stmt->execute([$deviceId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return is_array($row) ? $row : null;
}

function saveDeviceHealthMonitorState(PDO $pdo, array $payload): void
{
    ensureDeviceHealthMonitorTable($pdo);

    $stmt = $pdo->prepare("
        INSERT INTO device_health_monitor (
            device_id,
            device_name,
            device_type,
            host,
            last_state,
            last_error_message,
            last_checked_at,
            last_success_at,
            consecutive_failures
        ) VALUES (
            :device_id,
            :device_name,
            :device_type,
            :host,
            :last_state,
            :last_error_message,
            :last_checked_at,
            :last_success_at,
            :consecutive_failures
        )
        ON DUPLICATE KEY UPDATE
            device_name = VALUES(device_name),
            device_type = VALUES(device_type),
            host = VALUES(host),
            last_state = VALUES(last_state),
            last_error_message = VALUES(last_error_message),
            last_checked_at = VALUES(last_checked_at),
            last_success_at = VALUES(last_success_at),
            consecutive_failures = VALUES(consecutive_failures)
    ");

    $stmt->execute([
        ':device_id' => trim((string)($payload['device_id'] ?? '')),
        ':device_name' => trim((string)($payload['device_name'] ?? '')) ?: null,
        ':device_type' => trim((string)($payload['device_type'] ?? '')) ?: null,
        ':host' => trim((string)($payload['host'] ?? '')) ?: null,
        ':last_state' => trim((string)($payload['last_state'] ?? 'unknown')) ?: 'unknown',
        ':last_error_message' => trim((string)($payload['last_error_message'] ?? '')) ?: null,
        ':last_checked_at' => trim((string)($payload['last_checked_at'] ?? '')) ?: null,
        ':last_success_at' => trim((string)($payload['last_success_at'] ?? '')) ?: null,
        ':consecutive_failures' => max(0, (int)($payload['consecutive_failures'] ?? 0)),
    ]);
}

function createAdminNotification(PDO $pdo, array $payload): int
{
    ensureAdminNotificationsTable($pdo);

    $details = $payload['details_json'] ?? null;
    if (is_array($details)) {
        $details = json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    $stmt = $pdo->prepare("
        INSERT INTO admin_notifications (
            severity,
            category,
            source_type,
            source_ref,
            title,
            message,
            details_json
        ) VALUES (
            :severity,
            :category,
            :source_type,
            :source_ref,
            :title,
            :message,
            :details_json
        )
    ");

    $stmt->execute([
        ':severity' => trim((string)($payload['severity'] ?? 'info')) ?: 'info',
        ':category' => trim((string)($payload['category'] ?? 'system')) ?: 'system',
        ':source_type' => trim((string)($payload['source_type'] ?? '')) ?: null,
        ':source_ref' => trim((string)($payload['source_ref'] ?? '')) ?: null,
        ':title' => trim((string)($payload['title'] ?? 'Notification')) ?: 'Notification',
        ':message' => trim((string)($payload['message'] ?? '')) ?: null,
        ':details_json' => trim((string)($details ?? '')) ?: null,
    ]);

    clearAdminNotificationsUnreadCache();

    return (int)$pdo->lastInsertId();
}

function listAdminNotifications(PDO $pdo, int $limit = 100): array
{
    ensureAdminNotificationsTable($pdo);

    $stmt = $pdo->prepare("
        SELECT
            id,
            severity,
            category,
            source_type,
            source_ref,
            title,
            message,
            details_json,
            is_read,
            created_at,
            read_at
        FROM admin_notifications
        ORDER BY is_read ASC, id DESC
        LIMIT :limit
    ");
    $stmt->bindValue(':limit', max(1, $limit), PDO::PARAM_INT);
    $stmt->execute();

    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function adminNotificationExists(PDO $pdo, string $sourceType, string $sourceRef): bool
{
    ensureAdminNotificationsTable($pdo);
    $stmt = $pdo->prepare('SELECT 1 FROM admin_notifications WHERE source_type = ? AND source_ref = ? LIMIT 1');
    $stmt->execute([$sourceType, $sourceRef]);
    return (bool)$stmt->fetchColumn();
}

function syncInvalidPasswordNotifications(PDO $pdo, int $minFailures = 3, int $repeatWindowMinutes = 10, int $unknownWindowHours = 24): void
{
    ensureAdminNotificationsTable($pdo);

    $tableCheck = $pdo->query("SHOW TABLES LIKE 'radpostauth'");
    $hasRadPostAuth = $tableCheck && $tableCheck->fetchColumn();
    if (!$hasRadPostAuth) {
        return;
    }

    $repeatStmt = $pdo->prepare("
        SELECT username, COUNT(*) AS fail_count, MAX(authdate) AS last_auth
        FROM radpostauth
        WHERE reply LIKE 'Access-Reject%'
          AND authdate >= DATE_SUB(NOW(), INTERVAL :window MINUTE)
        GROUP BY username
        HAVING fail_count >= :min_failures
    ");
    $repeatStmt->execute([
        ':window' => max(1, $repeatWindowMinutes),
        ':min_failures' => max(2, $minFailures),
    ]);
    $repeatRows = $repeatStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    foreach ($repeatRows as $row) {
        $username = trim((string)($row['username'] ?? ''));
        if ($username === '') {
            continue;
        }
        $lastAuth = trim((string)($row['last_auth'] ?? ''));
        $dayKey = $lastAuth !== '' ? date('Y-m-d', strtotime($lastAuth)) : date('Y-m-d');
        $sourceRef = $username . '|' . $dayKey . '|repeat';

        if (adminNotificationExists($pdo, 'portal_invalid_password', $sourceRef)) {
            continue;
        }

        createAdminNotification($pdo, [
            'severity' => 'warning',
            'category' => 'portal',
            'source_type' => 'portal_invalid_password',
            'source_ref' => $sourceRef,
            'title' => 'Mot de passe invalide',
            'message' => sprintf('Échecs de connexion répétés pour « %s » : %d en %d min.', $username, (int)($row['fail_count'] ?? 0), $repeatWindowMinutes),
            'details_json' => [
                'username' => $username,
                'fail_count' => (int)($row['fail_count'] ?? 0),
                'window_minutes' => $repeatWindowMinutes,
                'last_auth' => $lastAuth,
            ],
        ]);
    }

    $unknownStmt = $pdo->prepare("
        SELECT rp.username, COUNT(*) AS fail_count, MAX(rp.authdate) AS last_auth
        FROM radpostauth rp
        LEFT JOIN users u ON u.username = rp.username
        WHERE rp.reply LIKE 'Access-Reject%'
          AND rp.authdate >= DATE_SUB(NOW(), INTERVAL :window HOUR)
          AND u.username IS NULL
        GROUP BY rp.username
    ");
    $unknownStmt->execute([
        ':window' => max(1, $unknownWindowHours),
    ]);
    $unknownRows = $unknownStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    foreach ($unknownRows as $row) {
        $username = trim((string)($row['username'] ?? ''));
        if ($username === '') {
            continue;
        }
        $lastAuth = trim((string)($row['last_auth'] ?? ''));
        $dayKey = $lastAuth !== '' ? date('Y-m-d', strtotime($lastAuth)) : date('Y-m-d');
        $sourceRef = $username . '|' . $dayKey . '|unknown';

        if (adminNotificationExists($pdo, 'portal_invalid_password', $sourceRef)) {
            continue;
        }

        createAdminNotification($pdo, [
            'severity' => 'warning',
            'category' => 'portal',
            'source_type' => 'portal_invalid_password',
            'source_ref' => $sourceRef,
            'title' => 'Mot de passe invalide',
            'message' => sprintf('Tentatives de connexion pour un utilisateur inconnu : « %s » (%d essai%s).', $username, (int)($row['fail_count'] ?? 0), ((int)($row['fail_count'] ?? 0) > 1 ? 's' : '')),
            'details_json' => [
                'username' => $username,
                'fail_count' => (int)($row['fail_count'] ?? 0),
                'last_auth' => $lastAuth,
            ],
        ]);
    }
}

function countUnreadAdminNotifications(PDO $pdo): int
{
    ensureAdminNotificationsTable($pdo);
    $count = $pdo->query("SELECT COUNT(*) FROM admin_notifications WHERE is_read = 0")->fetchColumn();

    return (int)($count ?: 0);
}

function clearAdminNotificationsUnreadCache(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return;
    }

    unset($_SESSION['admin_notifications_unread_cache']);
}

function countUnreadAdminNotificationsCached(PDO $pdo, int $ttlSeconds = 60): int
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return countUnreadAdminNotifications($pdo);
    }

    $cache = $_SESSION['admin_notifications_unread_cache'] ?? null;
    if (is_array($cache) && isset($cache['expires_at'], $cache['count'])) {
        if ((int)$cache['expires_at'] >= time()) {
            return max(0, (int)$cache['count']);
        }
    }

    $count = countUnreadAdminNotifications($pdo);
    $_SESSION['admin_notifications_unread_cache'] = [
        'count' => $count,
        'expires_at' => time() + max(1, $ttlSeconds),
    ];

    return $count;
}

function markAdminNotificationRead(PDO $pdo, int $notificationId): void
{
    ensureAdminNotificationsTable($pdo);
    $stmt = $pdo->prepare("
        UPDATE admin_notifications
        SET is_read = 1,
            read_at = CURRENT_TIMESTAMP
        WHERE id = ?
    ");
    $stmt->execute([$notificationId]);
    clearAdminNotificationsUnreadCache();
}

function markAllAdminNotificationsRead(PDO $pdo): void
{
    ensureAdminNotificationsTable($pdo);
    $pdo->exec("
        UPDATE admin_notifications
        SET is_read = 1,
            read_at = CURRENT_TIMESTAMP
        WHERE is_read = 0
    ");
    clearAdminNotificationsUnreadCache();
}

function adminNotificationSeverityLabel(string $severity): string
{
    return match (strtolower(trim($severity))) {
        'critical' => 'Critique',
        'warning' => 'Avertissement',
        'success' => 'Succès',
        default => 'Info',
    };
}

function adminNotificationSeverityBadgeClass(string $severity): string
{
    return match (strtolower(trim($severity))) {
        'critical' => 'bg-danger',
        'warning' => 'bg-warning text-dark',
        'success' => 'bg-success',
        default => 'bg-info text-dark',
    };
}

function adminNotificationCategoryLabel(string $category): string
{
    return match (strtolower(trim($category))) {
        'device' => 'Équipement',
        'user' => 'Utilisateur',
        'commercial' => 'Commercial',
        'portal' => 'Portail',
        'expiration' => 'Expiration',
        'automation' => 'Automatisation',
        'traffic' => 'Trafic',
        'sync' => 'Synchronisation',
        default => 'Système',
    };
}
