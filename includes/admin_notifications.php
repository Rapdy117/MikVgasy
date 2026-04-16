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
            'message' => sprintf('Echecs de connexion repetes pour %s: %d en %d min.', $username, (int)($row['fail_count'] ?? 0), $repeatWindowMinutes),
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
            'message' => sprintf('Tentatives de connexion pour un utilisateur inconnu: %s (%d essai%s).', $username, (int)($row['fail_count'] ?? 0), ((int)($row['fail_count'] ?? 0) > 1 ? 's' : '')),
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
