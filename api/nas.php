<?php
header('Content-Type: application/json');

require_once '../config/db.php';
require_once '../includes/nas_resolver.php';
require_once '../includes/device_manager.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'message' => 'Unauthorized',
    ]);
    exit;
}

try {
    $stmt = $pdo->query("
        SELECT id, nasname, shortname, type
        FROM nas
        ORDER BY id ASC
    ");

    $nasRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $nasByAddress = [];

    foreach ($nasRows as $row) {
        $address = extractDeviceAddress((string)($row['nasname'] ?? ''));
        if ($address !== '') {
            $nasByAddress[$address] = $row;
        }
    }

    $deviceStore = loadDeviceStore();
    $items = [];
    $seenDevices = [];

    foreach (($deviceStore['devices'] ?? []) as $device) {
        $deviceId = (string)($device['id'] ?? '');
        if ($deviceId === '' || isset($seenDevices[$deviceId])) {
            continue;
        }

        $deviceType = normalizeDeviceType((string)($device['type'] ?? ''));
        $deviceBusinessSource = resolveDeviceBusinessSource($deviceType);
        $address = extractDeviceAddress((string)($device['host'] ?? ''));
        if ($address === '') {
            $address = trim((string)($device['ip'] ?? ''));
        }

        $matchedNas = null;
        if ($address !== '' && isset($nasByAddress[$address])) {
            $candidateNas = $nasByAddress[$address];
            $candidateNasType = normalizeNasType((string)($candidateNas['type'] ?? ''));
            $candidateBusinessSource = resolveNasBusinessSource($candidateNasType);
            if ($candidateBusinessSource === $deviceBusinessSource) {
                $matchedNas = $candidateNas;
            }
        }

        $nasId = is_array($matchedNas) ? (int)($matchedNas['id'] ?? 0) : 0;
        $nasType = is_array($matchedNas)
            ? normalizeNasType((string)($matchedNas['type'] ?? ''))
            : $deviceType;

        $label = trim((string)($device['name'] ?? '')) !== ''
            ? (string)$device['name']
            : (
                is_array($matchedNas) && (string)($matchedNas['shortname'] ?? '') !== ''
                    ? (string)$matchedNas['shortname']
                    : ($address !== '' ? $address : ucfirst($deviceType))
            );

        $items[] = [
            'device_id' => $deviceId,
            'nas_id' => $nasId,
            'label' => $label,
            'nasname' => is_array($matchedNas)
                ? (string)($matchedNas['nasname'] ?? $address)
                : $address,
            'shortname' => $label,
            'device_type' => $deviceType,
            'nas_type' => $nasType,
            'business_source' => $deviceBusinessSource,
            'backend_driver' => resolveDeviceBackend($deviceType),
            'capabilities' => resolveNasCapabilities($nasType),
            'has_nas_mapping' => $nasId > 0,
        ];
        $seenDevices[$deviceId] = true;
    }

    echo json_encode([
        'success' => true,
        'data' => $items
    ]);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);

}
