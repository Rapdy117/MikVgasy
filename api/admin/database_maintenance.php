<?php
declare(strict_types=1);

session_start();
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';

header('Content-Type: application/json; charset=UTF-8');

if (empty($_SESSION['logged_in']) || !isSuperAdministrator()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Accès réservé au superadmin.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Méthode non autorisée.']);
    exit;
}

$csrf = (string)($_POST['csrf_token'] ?? '');
if ($csrf === '' || !hash_equals((string)($_SESSION['csrf_token'] ?? ''), $csrf)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'CSRF invalide.']);
    exit;
}

$trafficControlRetentionTables = [
    'mikrotik_traffic_samples' => 'sampled_at',
    'mikrotik_traffic_user_usage_samples' => 'sampled_at',
    'mikrotik_traffic_dns_signature_samples' => 'sampled_at',
    'mikrotik_traffic_dns_client_signature_samples' => 'sampled_at',
];
$dateTables = [
    'users' => 'created_at',
    'profiles' => 'created_at',
    'vouchers' => 'created_at',
    'recharge_history' => 'created_at',
    'operation_history' => 'created_at',
    'admin_notifications' => 'created_at',
    'recouvrement_invoices' => 'created_at',
    'radacct' => 'acctstarttime',
    'radpostauth' => 'authdate',
];
$dateTables += $trafficControlRetentionTables;
$trafficControlTables = array_merge(array_keys($trafficControlRetentionTables), [
    'mikrotik_traffic_monitor_state',
    'mikrotik_traffic_usage_counter_state',
]);
$sensitiveTables = ['users', 'profiles', 'vouchers', 'recharge_history', 'recouvrement_invoices', 'radcheck', 'radreply', 'radusergroup', 'radacct'];

function maintenanceTableExists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare('SHOW TABLES LIKE ?');
    $stmt->execute([$table]);
    return (bool)$stmt->fetchColumn();
}

function maintenanceColumnExists(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare('SHOW COLUMNS FROM `' . $table . '` LIKE ?');
    $stmt->execute([$column]);
    return (bool)$stmt->fetchColumn();
}

function maintenanceTableSize(PDO $pdo, string $table): int
{
    $stmt = $pdo->prepare('SELECT COALESCE(DATA_LENGTH,0) + COALESCE(INDEX_LENGTH,0) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1');
    $stmt->execute([$table]);
    return max(0, (int)($stmt->fetchColumn() ?: 0));
}

function maintenanceFormatBytes(int $bytes): string
{
    $units = ['o', 'Ko', 'Mo', 'Go', 'To'];
    $size = (float)max(0, $bytes);
    $unit = 0;
    while ($size >= 1024 && $unit < count($units) - 1) {
        $size /= 1024;
        $unit++;
    }
    return number_format($size, $unit === 0 ? 0 : 1, ',', ' ') . ' ' . $units[$unit];
}

function maintenanceEnsureLog(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS system_maintenance_runs (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        action VARCHAR(40) NOT NULL,
        table_name VARCHAR(64) DEFAULT NULL,
        cutoff_year SMALLINT DEFAULT NULL,
        affected_rows INT UNSIGNED NOT NULL DEFAULT 0,
        archive_path VARCHAR(255) DEFAULT NULL,
        actor_user_id INT DEFAULT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id), KEY idx_maintenance_created (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function maintenanceDump(PDO $pdo, array $tables, string $suffix, ?string $where = null): string
{
    global $host, $dbname, $user, $pass;
    $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'radius-manager-maintenance';
    if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
        throw new RuntimeException('Répertoire de sauvegarde indisponible.');
    }
    $base = $dir . DIRECTORY_SEPARATOR . 'radius_manager_' . gmdate('Ymd_His') . '_' . $suffix . '.sql';
    $parts = [];
    foreach ($tables as $table) {
        if (maintenanceTableExists($pdo, $table)) {
            $parts[] = $table;
        }
    }
    if ($parts === []) {
        throw new RuntimeException('Aucune table disponible.');
    }
    $command = '/usr/bin/mysqldump --single-transaction --skip-lock-tables -h ' . escapeshellarg((string)$host)
        . ' -u ' . escapeshellarg((string)$user) . ' -p' . escapeshellarg((string)$pass)
        . ' ' . escapeshellarg((string)$dbname) . ' ' . implode(' ', array_map('escapeshellarg', $parts))
        . ($where !== null ? ' --where=' . escapeshellarg($where) : '')
        . ' > ' . escapeshellarg($base) . ' 2>/dev/null';
    exec($command, $output, $status);
    if ($status !== 0 || !is_file($base)) {
        @unlink($base);
        throw new RuntimeException('Export SQL impossible.');
    }
    return $base;
}

try {
    maintenanceEnsureLog($pdo);
    $action = trim((string)($_POST['action'] ?? 'status'));
    if ($action === 'status') {
        $tables = [];
        foreach (array_unique(array_merge(array_keys($dateTables), $sensitiveTables, $trafficControlTables)) as $table) {
            if (!maintenanceTableExists($pdo, $table)) continue;
            $dateColumn = $dateTables[$table] ?? null;
            if ($dateColumn === null || !maintenanceColumnExists($pdo, $table, $dateColumn)) {
                $row = $pdo->query('SELECT COUNT(*) AS total, NULL AS oldest, NULL AS newest FROM `' . $table . '`')->fetch(PDO::FETCH_ASSOC) ?: [];
            } else {
                $row = $pdo->query('SELECT COUNT(*) AS total, MIN(`' . $dateColumn . '`) AS oldest, MAX(`' . $dateColumn . '`) AS newest FROM `' . $table . '`')->fetch(PDO::FETCH_ASSOC) ?: [];
            }
            $bytes = maintenanceTableSize($pdo, $table);
            $row['bytes'] = $bytes;
            $row['size'] = maintenanceFormatBytes($bytes);
            $tables[$table] = $row;
        }
        echo json_encode(['success' => true, 'tables' => $tables], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'backup_sensitive') {
        $path = maintenanceDump($pdo, $sensitiveTables, 'sensitive');
        $stmt = $pdo->prepare('INSERT INTO system_maintenance_runs (action, affected_rows, archive_path, actor_user_id) VALUES (?,?,?,?)');
        $stmt->execute(['backup_sensitive', 0, $path, (int)($_SESSION['local_admin_id'] ?? 0) ?: null]);
        echo json_encode(['success' => true, 'message' => 'Sauvegarde sensible créée.', 'path' => $path], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'backup_traffic') {
        $path = maintenanceDump($pdo, $trafficControlTables, 'traffic_control');
        $stmt = $pdo->prepare('INSERT INTO system_maintenance_runs (action, affected_rows, archive_path, actor_user_id) VALUES (?,?,?,?)');
        $stmt->execute(['backup_traffic', 0, $path, (int)($_SESSION['local_admin_id'] ?? 0) ?: null]);
        echo json_encode(['success' => true, 'message' => 'Export Traffic Control créé.', 'path' => $path], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if (in_array($action, ['archive_traffic_retention', 'clean_traffic_retention'], true)) {
        $months = max(1, min(60, (int)($_POST['months'] ?? 6)));
        $cutoff = (new DateTimeImmutable('now'))->modify('-' . $months . ' months')->format('Y-m-d H:i:s');
        $existingTables = [];
        $totalRows = 0;
        foreach ($trafficControlRetentionTables as $tableName => $columnName) {
            if (!maintenanceTableExists($pdo, $tableName) || !maintenanceColumnExists($pdo, $tableName, $columnName)) continue;
            $existingTables[] = $tableName;
            $countStmt = $pdo->prepare('SELECT COUNT(*) FROM `' . $tableName . '` WHERE `' . $columnName . '` < ?');
            $countStmt->execute([$cutoff]);
            $totalRows += (int)$countStmt->fetchColumn();
        }
        if ($existingTables === []) throw new RuntimeException('Aucune table Traffic Control disponible.');
        if ($action === 'archive_traffic_retention') {
            $path = maintenanceDump($pdo, $existingTables, 'traffic_control_avant_' . $months . '_mois', '`sampled_at` < ' . $pdo->quote($cutoff));
            $stmt = $pdo->prepare('INSERT INTO system_maintenance_runs (action,table_name,affected_rows,archive_path,actor_user_id) VALUES (?,?,?,?,?)');
            $stmt->execute(['archive_traffic_retention', 'traffic_control', $totalRows, $path, (int)($_SESSION['local_admin_id'] ?? 0) ?: null]);
            echo json_encode(['success' => true, 'message' => 'Archive Traffic Control créée. Aucune donnée supprimée.', 'rows' => $totalRows, 'path' => $path], JSON_UNESCAPED_UNICODE);
            exit;
        }
        if ((string)($_POST['confirm'] ?? '') !== '1') throw new RuntimeException('Confirmation explicite requise.');
        $pdo->beginTransaction();
        $deleted = 0;
        foreach ($trafficControlRetentionTables as $tableName => $columnName) {
            if (!maintenanceTableExists($pdo, $tableName) || !maintenanceColumnExists($pdo, $tableName, $columnName)) continue;
            $delete = $pdo->prepare('DELETE FROM `' . $tableName . '` WHERE `' . $columnName . '` < ?');
            $delete->execute([$cutoff]);
            $deleted += $delete->rowCount();
        }
        $pdo->commit();
        $stmt = $pdo->prepare('INSERT INTO system_maintenance_runs (action,table_name,affected_rows,actor_user_id) VALUES (?,?,?,?)');
        $stmt->execute(['clean_traffic_retention', 'traffic_control', $deleted, (int)($_SESSION['local_admin_id'] ?? 0) ?: null]);
        echo json_encode(['success' => true, 'message' => 'Nettoyage Traffic Control terminé.', 'rows' => $deleted], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if (!isset($dateTables[$_POST['table'] ?? ''])) throw new RuntimeException('Table non autorisée.');
    $table = (string)$_POST['table'];
    if (!maintenanceTableExists($pdo, $table)) throw new RuntimeException('Table indisponible.');
    $year = (int)($_POST['year'] ?? 0);
    if ($year < 2000 || $year > ((int)gmdate('Y') - 1)) throw new RuntimeException('Année de nettoyage invalide.');
    $column = $dateTables[$table];
    if (!maintenanceColumnExists($pdo, $table, $column)) throw new RuntimeException('La table ne possède pas de colonne de date compatible.');
    $cutoff = sprintf('%04d-01-01 00:00:00', $year);
    $countStmt = $pdo->prepare('SELECT COUNT(*) FROM `' . $table . '` WHERE `' . $column . '` < ?');
    $countStmt->execute([$cutoff]);
    $count = (int)$countStmt->fetchColumn();
    if ($action === 'archive') {
        $path = maintenanceDump($pdo, [$table], 'archive_' . $table . '_' . $year, '`' . $column . '` < ' . $pdo->quote($cutoff));
        $stmt = $pdo->prepare('INSERT INTO system_maintenance_runs (action,table_name,cutoff_year,affected_rows,archive_path,actor_user_id) VALUES (?,?,?,?,?,?)');
        $stmt->execute(['archive', $table, $year, $count, $path, (int)($_SESSION['local_admin_id'] ?? 0) ?: null]);
        echo json_encode(['success' => true, 'message' => 'Archivage créé. Aucune donnée supprimée.', 'rows' => $count, 'path' => $path], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($action !== 'clean' || (string)($_POST['confirm'] ?? '') !== '1') throw new RuntimeException('Confirmation explicite requise.');
    $pdo->beginTransaction();
    $delete = $pdo->prepare('DELETE FROM `' . $table . '` WHERE `' . $column . '` < ?');
    $delete->execute([$cutoff]);
    $deleted = $delete->rowCount();
    $pdo->commit();
    $stmt = $pdo->prepare('INSERT INTO system_maintenance_runs (action,table_name,cutoff_year,affected_rows,actor_user_id) VALUES (?,?,?,?,?)');
    $stmt->execute(['clean', $table, $year, $deleted, (int)($_SESSION['local_admin_id'] ?? 0) ?: null]);
    echo json_encode(['success' => true, 'message' => 'Nettoyage terminé.', 'rows' => $deleted], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
