<?php

function ensureUsersExtendedSchema(PDO $pdo): void
{
    static $done = false;

    if ($done) {
        return;
    }

    $existing = [];
    $stmt = $pdo->query('SHOW COLUMNS FROM users');
    foreach (($stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : []) as $column) {
        $existing[(string)($column['Field'] ?? '')] = true;
    }

    $alterStatements = [];

    if (!isset($existing['session_timeout'])) {
        $alterStatements[] = 'ADD COLUMN session_timeout INT(11) DEFAULT NULL AFTER profile_id';
    }

    if (!isset($existing['data_limit'])) {
        $alterStatements[] = 'ADD COLUMN data_limit INT(11) DEFAULT NULL AFTER session_timeout';
    }

    if (!isset($existing['current_credit_time'])) {
        $alterStatements[] = 'ADD COLUMN current_credit_time INT(11) NOT NULL DEFAULT 0 AFTER data_limit';
    }

    if (!isset($existing['current_credit_data'])) {
        $alterStatements[] = 'ADD COLUMN current_credit_data BIGINT(20) NOT NULL DEFAULT 0 AFTER current_credit_time';
    }

    if ($alterStatements !== []) {
        $pdo->exec('ALTER TABLE users ' . implode(', ', $alterStatements));
    }

    $done = true;
}
