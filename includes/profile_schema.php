<?php

function ensureProfilesExtendedSchema(PDO $pdo): void
{
    static $done = false;
    $schemaVersion = 'profiles_extended_schema_v2';

    if ($done) {
        return;
    }

    if (function_exists('apcu_fetch')) {
        $apcuOk = apcu_fetch($schemaVersion, $apcuSuccess);
        if ($apcuSuccess && $apcuOk === true) {
            $done = true;
            if (session_status() === PHP_SESSION_ACTIVE) {
                $_SESSION[$schemaVersion] = true;
            }
            return;
        }
    }

    if (session_status() === PHP_SESSION_ACTIVE && !empty($_SESSION[$schemaVersion])) {
        $done = true;
        return;
    }

    $existing = [];
    $stmt = $pdo->query('SHOW COLUMNS FROM profiles');
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $column) {
        $existing[(string)($column['Field'] ?? '')] = true;
    }

    $alterStatements = [];

    if (!isset($existing['expired_mode'])) {
        $alterStatements[] = "ADD COLUMN expired_mode VARCHAR(32) DEFAULT 'none' AFTER data_quota_mb";
    }
    if (!isset($existing['grace_period'])) {
        $alterStatements[] = "ADD COLUMN grace_period INT(11) DEFAULT NULL AFTER expired_mode";
    }
    if (!isset($existing['price'])) {
        $alterStatements[] = "ADD COLUMN price DECIMAL(10,2) DEFAULT NULL AFTER grace_period";
    }
    if (!isset($existing['selling_price'])) {
        $alterStatements[] = "ADD COLUMN selling_price DECIMAL(10,2) DEFAULT NULL AFTER price";
    }
    if (!isset($existing['lock_user'])) {
        $alterStatements[] = "ADD COLUMN lock_user TINYINT(1) NOT NULL DEFAULT 0 AFTER selling_price";
    }
    if (!isset($existing['parent_queue'])) {
        $alterStatements[] = "ADD COLUMN parent_queue VARCHAR(100) DEFAULT NULL AFTER lock_user";
    }
    if (!isset($existing['validity_routeros'])) {
        $alterStatements[] = "ADD COLUMN validity_routeros VARCHAR(32) DEFAULT NULL AFTER parent_queue";
    }
    if (!isset($existing['grace_period_routeros'])) {
        $alterStatements[] = "ADD COLUMN grace_period_routeros VARCHAR(32) DEFAULT NULL AFTER validity_routeros";
    }

    if ($alterStatements !== []) {
        $pdo->exec('ALTER TABLE profiles ' . implode(', ', $alterStatements));
    }

    if (session_status() === PHP_SESSION_ACTIVE) {
        $_SESSION[$schemaVersion] = true;
    }
    if (function_exists('apcu_store')) {
        apcu_store($schemaVersion, true, 3600);
    }

    $done = true;
}
