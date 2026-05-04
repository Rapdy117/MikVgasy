#!/usr/bin/env php
<?php

declare(strict_types=1);

chdir(dirname(__DIR__));

require_once 'config/db.php';
require_once 'includes/device_manager.php';
require_once 'includes/opnsense_shaper.php';
require_once 'includes/admin_notifications.php';

$interface = null;

foreach (array_slice($argv, 1) as $argument) {
    if (str_starts_with($argument, '--interface=')) {
        $value = trim((string)substr($argument, strlen('--interface=')));
        $interface = $value !== '' ? $value : null;
    }
}

try {
    $activeDevice = requireActiveDevice();
    if (($activeDevice['type'] ?? '') !== 'opnsense') {
        echo json_encode([
            'success' => true,
            'skipped' => true,
            'message' => 'Synchro OPNsense ignoree: device actif non OPNsense.',
            'device_type' => deviceTypeLabelForApiResponse($activeDevice),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
        exit(0);
    }

    $result = reconcileOpnsenseActiveSessions($pdo, $interface);

    echo json_encode([
        'success' => true,
        'device_id' => (string)($result['device']['id'] ?? ''),
        'interface' => (string)($result['interface'] ?? ''),
        'sessions' => (int)($result['sessions'] ?? 0),
        'removed' => $result['removed'] ?? [],
        'synced' => $result['synced'] ?? [],
        'skipped' => $result['skipped'] ?? [],
        'errors' => $result['errors'] ?? [],
        'deleted_rules' => $result['deleted_rules'] ?? [],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(0);
} catch (Throwable $e) {
    createAdminNotification($pdo, [
        'severity' => 'critical',
        'category' => 'automation',
        'source_type' => 'cron',
        'source_ref' => 'opnsense_session_sync',
        'title' => 'Échec de la synchro OPNsense',
        'message' => $e->getMessage(),
        'details_json' => [
            'script' => 'sync_opnsense_sessions.php',
            'interface' => $interface,
        ],
    ]);
    fwrite(STDERR, json_encode([
        'success' => false,
        'message' => $e->getMessage(),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    exit(1);
}
