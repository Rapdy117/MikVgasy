<?php

require_once __DIR__ . '/admin_notifications.php';

function ensureOperationHistoryTable(PDO $pdo): void
{
    static $done = false;

    if ($done) {
        return;
    }

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS operation_history (
            id INT(11) NOT NULL AUTO_INCREMENT,
            operation_scope VARCHAR(30) NOT NULL DEFAULT 'admin',
            operation_type VARCHAR(50) NOT NULL,
            actor_username VARCHAR(100) DEFAULT NULL,
            actor_role VARCHAR(30) DEFAULT NULL,
            target_type VARCHAR(50) DEFAULT NULL,
            target_name VARCHAR(150) DEFAULT NULL,
            target_ref VARCHAR(100) DEFAULT NULL,
            device_id VARCHAR(100) DEFAULT NULL,
            profile_name VARCHAR(100) DEFAULT NULL,
            quantity INT(11) DEFAULT NULL,
            amount_value DECIMAL(10,2) DEFAULT NULL,
            summary VARCHAR(255) DEFAULT NULL,
            details_json LONGTEXT DEFAULT NULL,
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_operation_history_scope (operation_scope),
            KEY idx_operation_history_type (operation_type),
            KEY idx_operation_history_actor (actor_username),
            KEY idx_operation_history_created_at (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
    );

    $done = true;
}

function recordOperationHistory(PDO $pdo, array $payload): void
{
    ensureOperationHistoryTable($pdo);

    $stmt = $pdo->prepare("
        INSERT INTO operation_history (
            operation_scope,
            operation_type,
            actor_username,
            actor_role,
            target_type,
            target_name,
            target_ref,
            device_id,
            profile_name,
            quantity,
            amount_value,
            summary,
            details_json
        ) VALUES (
            :operation_scope,
            :operation_type,
            :actor_username,
            :actor_role,
            :target_type,
            :target_name,
            :target_ref,
            :device_id,
            :profile_name,
            :quantity,
            :amount_value,
            :summary,
            :details_json
        )
    ");

    $details = $payload['details_json'] ?? null;
    if (is_array($details)) {
        $details = json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    $stmt->execute([
        ':operation_scope' => trim((string)($payload['operation_scope'] ?? 'admin')) ?: 'admin',
        ':operation_type' => trim((string)($payload['operation_type'] ?? 'unknown')) ?: 'unknown',
        ':actor_username' => trim((string)($payload['actor_username'] ?? '')) ?: null,
        ':actor_role' => trim((string)($payload['actor_role'] ?? '')) ?: null,
        ':target_type' => trim((string)($payload['target_type'] ?? '')) ?: null,
        ':target_name' => trim((string)($payload['target_name'] ?? '')) ?: null,
        ':target_ref' => trim((string)($payload['target_ref'] ?? '')) ?: null,
        ':device_id' => trim((string)($payload['device_id'] ?? '')) ?: null,
        ':profile_name' => trim((string)($payload['profile_name'] ?? '')) ?: null,
        ':quantity' => isset($payload['quantity']) ? (int)$payload['quantity'] : null,
        ':amount_value' => isset($payload['amount_value']) ? (float)$payload['amount_value'] : null,
        ':summary' => trim((string)($payload['summary'] ?? '')) ?: null,
        ':details_json' => trim((string)($details ?? '')) ?: null,
    ]);

    $operationType = trim((string)($payload['operation_type'] ?? ''));
    if (in_array($operationType, ['user_create', 'user_update', 'user_delete'], true)) {
        $targetName = trim((string)($payload['target_name'] ?? '')) ?: 'Utilisateur';
        $actor = trim((string)($payload['actor_username'] ?? '')) ?: 'Systeme';
        $profileName = trim((string)($payload['profile_name'] ?? ''));

        $severity = $operationType === 'user_delete' ? 'warning' : 'info';
        $title = match ($operationType) {
            'user_create' => 'Utilisateur créé',
            'user_update' => 'Utilisateur modifié',
            'user_delete' => 'Utilisateur supprimé',
            default => 'Utilisateur',
        };

        $profileText = $profileName !== '' ? sprintf(' (Profil: %s)', $profileName) : '';
        $message = match ($operationType) {
            'user_create' => sprintf('Utilisateur %s créé par %s%s.', $targetName, $actor, $profileText),
            'user_update' => sprintf('Utilisateur %s modifié par %s%s.', $targetName, $actor, $profileText),
            'user_delete' => sprintf('Utilisateur %s supprimé par %s%s.', $targetName, $actor, $profileText),
            default => sprintf('Utilisateur %s.', $targetName),
        };

        createAdminNotification($pdo, [
            'severity' => $severity,
            'category' => 'user',
            'source_type' => 'user',
            'source_ref' => $targetName,
            'title' => $title,
            'message' => $message,
            'details_json' => [
                'operation_type' => $operationType,
                'actor' => $actor,
                'profile_name' => $profileName,
            ],
        ]);
    }
}

function operationTypeLabel(string $type): string
{
    return match ($type) {
        'session_disconnect' => 'Déconnexion session',
        'recharge' => 'Recharge',
        'user_notice' => 'Expiration avec conservation',
        'user_notice_record' => 'Expiration conservée et comptabilisée',
        'user_remove_record' => 'Suppression auto sur quota',
        'user_expire_remove' => 'Suppression auto à expiration',
        'voucher_batch' => 'Lot voucher',
        'user_create' => 'Création utilisateur',
        'user_update' => 'Modification utilisateur',
        'user_delete' => 'Suppression utilisateur',
        'profile_create' => 'Création profil',
        'profile_update' => 'Modification profil',
        'profile_delete' => 'Suppression profil',
        'local_admin_create' => 'Création utilisateur local',
        'local_admin_update' => 'Modification utilisateur local',
        'local_admin_delete' => 'Suppression utilisateur local',
        default => $type !== '' ? $type : '-',
    };
}
