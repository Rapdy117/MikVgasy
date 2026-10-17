<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { exit("CLI uniquement.\n"); }
require_once __DIR__ . '/../config/db.php';
$devices = (int)$pdo->query('SELECT COUNT(*) FROM network_devices')->fetchColumn();
$assignments = (int)$pdo->query('SELECT COUNT(*) FROM admin_device_assignments')->fetchColumn();
$unassigned = (int)$pdo->query('SELECT COUNT(*) FROM network_devices d LEFT JOIN admin_device_assignments a ON a.device_id=d.id WHERE a.device_id IS NULL')->fetchColumn();
$duplicates = (int)$pdo->query('SELECT COUNT(*) FROM (SELECT device_id FROM admin_device_assignments GROUP BY device_id HAVING COUNT(*) > 1) x')->fetchColumn();
printf("Devices SQL: %d\nAttributions: %d\nNon attribues: %d\nDevices multi-attribues: %d\n", $devices, $assignments, $unassigned, $duplicates);
if ($devices === 0 || $unassigned > 0 || $duplicates > 0) exit(1);
