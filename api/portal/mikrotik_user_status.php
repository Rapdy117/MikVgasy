<?php
header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/formatters.php';
require_once __DIR__ . '/../../includes/mikrotik_backend.php';
require_once __DIR__ . '/../../includes/portal_hotspot.php';

function portal_query_string(string $key): ?string
{
    $value = trim((string)($_GET[$key] ?? ''));
    return $value === '' ? null : $value;
}

function portal_match_active_session(array $sessions, string $ip): ?array
{
    foreach ($sessions as $session) {
        if (trim((string)($session['address'] ?? '')) === $ip) {
            return $session;
        }
    }

    return null;
}

function portal_status_from_mikrotik_rows(array $user, array $active): array
{
    $limitSeconds = parseRouterosIntervalToSeconds((string)($user['limit-uptime'] ?? ''));
    $usedSeconds = parseRouterosIntervalToSeconds((string)($user['uptime'] ?? ''));
    $limitBytes = max(0, (int)round((float)($user['limit-bytes-total'] ?? 0)));
    $consumedBytes = max(0, (int)round((float)($user['bytes-in'] ?? 0) + (float)($user['bytes-out'] ?? 0)));
    $remainingBytes = $limitBytes > 0 ? max(0, $limitBytes - $consumedBytes) : null;

    return [
        'success' => true,
        'device_type' => 'mikrotik',
        'business_source' => 'mikrotik_local',
        'backend_driver' => 'mikrotik_api',
        'username' => (string)($user['name'] ?? ''),
        'ip' => (string)($active['address'] ?? ''),
        'mac' => (string)($active['mac-address'] ?? ''),
        'plan' => (string)($user['profile'] ?? '-'),
        'time_limit_label' => $limitSeconds > 0 ? formatDurationCompactLabel($limitSeconds) : 'Illimitée',
        'session_total_label' => $usedSeconds > 0 ? formatConsumedDurationLabel($usedSeconds) : '0 min',
        'data_limit_label' => $limitBytes > 0 ? formatMikrotikBytesLimit($limitBytes) : 'Illimitée',
        'data_consumed_label' => formatMikrotikBytesLimit($consumedBytes),
        'data_remaining_bytes' => $remainingBytes,
        'data_remaining_label' => $remainingBytes === null ? 'Illimitées' : formatMikrotikBytesLimit($remainingBytes),
        'expiration' => formatMikrotikExpiration((string)($user['comment'] ?? '')),
        'online' => true,
    ];
}

$username = portal_query_string('username');
$deviceId = portal_query_string('device_id');
$clientIp = portal_query_string('ip');

if ($username === null || $deviceId === null || $clientIp === null) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Parametres username, device_id et ip requis.',
    ]);
    exit;
}

try {
    $device = portalResolveConfiguredMikrotikDevice($pdo, $deviceId);
    $snapshot = getMikrotikPortalUserSnapshot($device, $username);
    if ($snapshot === null) {
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'message' => 'Utilisateur introuvable sur le routeur configure.',
        ]);
        exit;
    }

    $active = portal_match_active_session((array)$snapshot['active_sessions'], $clientIp);
    if ($active === null) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'message' => 'Session Hotspot active non reconnue.',
        ]);
        exit;
    }

    echo json_encode(
        portal_status_from_mikrotik_rows((array)$snapshot['user'], $active),
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
    );
} catch (Throwable $e) {
    $message = trim($e->getMessage());
    $isBackendUnavailable = str_contains($message, 'Connexion Mikhmon/API MikroTik impossible');
    $isForbidden = str_contains($message, 'non autorise');
    $isNotFound = str_contains($message, 'introuvable ou inactif');
    http_response_code($isBackendUnavailable ? 503 : ($isForbidden ? 403 : ($isNotFound ? 404 : 500)));
    echo json_encode([
        'success' => false,
        'degraded' => $isBackendUnavailable,
        'message' => $message !== '' ? $message : 'Statut portail indisponible.',
    ], JSON_UNESCAPED_UNICODE);
}
