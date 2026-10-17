<?php
header('Content-Type: application/json');

require_once __DIR__ . '/../includes/device_probe.php';

$device = [
    'type' => trim((string)($_POST['type'] ?? '')),
    'host' => trim((string)($_POST['host'] ?? '')),
    'api_key' => trim((string)($_POST['api_key'] ?? '')),
    'api_secret' => trim((string)($_POST['api_secret'] ?? '')),
    'secret' => trim((string)($_POST['api_secret'] ?? '')),
    'verify_ssl' => (($_POST['verify_ssl'] ?? 'false') === 'true'),
];

echo json_encode(probeDeviceConnection($device, isset($_POST['status_only'])));
