<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/device_manager.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json');

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    http_response_code(403);
    echo json_encode([
        'error' => 'Unauthorized',
    ]);
    exit;
}

function opnsenseGet(array $device, string $path): array
{
    $ch = curl_init();

    curl_setopt_array($ch, [
        CURLOPT_URL => $device['host'] . $path,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
        CURLOPT_USERPWD => $device['api_key'] . ':' . $device['api_secret'],
        CURLOPT_SSL_VERIFYPEER => (bool)$device['verify_ssl'],
        CURLOPT_SSL_VERIFYHOST => !empty($device['verify_ssl']) ? 2 : 0,
        CURLOPT_TIMEOUT => 5,
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
    ]);

    $raw = curl_exec($ch);
    $error = curl_error($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);

    curl_close($ch);

    if ($raw === false || $error !== '') {
        return ['success' => false, 'error' => $error !== '' ? $error : 'Erreur cURL inconnue'];
    }

    $decoded = json_decode($raw, true);
    if ($httpCode < 200 || $httpCode >= 300 || $decoded === null) {
        return ['success' => false, 'error' => 'Reponse OPNsense invalide sur ' . $path];
    }

    return ['success' => true, 'data' => $decoded];
}

try {
    $device = loadActiveOpnSenseDevice();
    $trafficResponse = opnsenseGet($device, '/api/diagnostics/traffic/interface');

    if (!$trafficResponse['success']) {
        throw new Exception($trafficResponse['error']);
    }

    $interfaces = $trafficResponse['data']['interfaces'] ?? [];
    $timestamp = (float)($trafficResponse['data']['time'] ?? microtime(true));
    $filteredInterfaces = [];

    foreach ($interfaces as $key => $stats) {
        $label = strtolower((string)($stats['name'] ?? $key));
        if ($label === 'loopback' || $key === 'lo0') {
            continue;
        }
        $filteredInterfaces[(string)$key] = $stats;
    }

    if ($filteredInterfaces === []) {
        $filteredInterfaces = $interfaces;
    }

    echo json_encode([
        'time' => $timestamp,
        'interval' => 2000,
        'interfaces' => $filteredInterfaces,
        'last_update' => date('H:i:s'),
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'error' => $e->getMessage(),
    ]);
}
