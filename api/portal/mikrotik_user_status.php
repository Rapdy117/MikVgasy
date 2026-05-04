<?php
header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

require_once __DIR__ . '/../../includes/device_manager.php';
require_once __DIR__ . '/../../includes/formatters.php';
require_once __DIR__ . '/../../includes/mikrotik_backend.php';

function portal_query_string(string $key): ?string
{
    $value = trim((string)($_GET[$key] ?? ''));
    return $value === '' ? null : $value;
}

function portal_find_mikrotik_user(array $users, string $username): ?array
{
    foreach ($users as $user) {
        if ((string)($user['username'] ?? '') === $username) {
            return $user;
        }
    }

    return null;
}

function portal_status_from_mikrotik_row(array $user): array
{
    $mikrotikExpiration = formatMikrotikExpiration((string)($user['comment'] ?? ''));
    $mikrotikTimeLimit = trim((string)($user['limit_uptime'] ?? '')) !== '' ? (string)$user['limit_uptime'] : '-';

    $profileSessionTimeoutSeconds = (int)($user['profile_session_timeout_seconds'] ?? 0);
    $mikrotikProfileTimeLimitLabel = $profileSessionTimeoutSeconds > 0
        ? formatDurationCompactLabel($profileSessionTimeoutSeconds)
        : '-';
    if ($mikrotikTimeLimit === '-' && $profileSessionTimeoutSeconds > 0) {
        $mikrotikTimeLimit = $mikrotikProfileTimeLimitLabel;
    }

    $mikrotikLimitBytes = (float)($user['limit_bytes_total'] ?? 0);
    $mikrotikDataConsumedBytes = (float)($user['user_bytes_total'] ?? 0);
    $mikrotikDataConsumedLabel = formatMikrotikBytesLimit($mikrotikDataConsumedBytes);
    $mikrotikDataLimit = $mikrotikLimitBytes > 0
        ? formatMikrotikBytesLimit($mikrotikLimitBytes)
        : 'Illimité';

    $mikrotikSessionTotalSeconds = (int)($user['user_session_time_seconds'] ?? 0);
    $mikrotikSessionTotalLabel = $mikrotikSessionTotalSeconds > 0
        ? formatConsumedDurationLabel($mikrotikSessionTotalSeconds)
        : 'N/D';

    return [
        'success' => true,
        'device_type' => 'mikrotik',
        'business_source' => 'mikrotik_local',
        'backend_driver' => 'mikrotik_api',
        'username' => (string)($user['username'] ?? ''),
        'ip' => (string)($user['active_address'] ?? ''),
        'mac' => (string)($user['active_mac'] ?? ''),
        'plan' => (string)($user['profile'] ?? '-'),
        'time_limit_label' => $mikrotikTimeLimit,
        'session_total_label' => $mikrotikSessionTotalLabel,
        'data_limit_label' => $mikrotikDataLimit,
        'data_consumed_label' => $mikrotikDataConsumedLabel,
        'expiration' => $mikrotikExpiration,
        'online' => !empty($user['online']),
    ];
}

$username = portal_query_string('username');
$routerHost = portal_query_string('router_host');

if ($username === null || $routerHost === null) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Paramètres username et router_host requis.',
    ]);
    exit;
}

try {
    $store = loadDeviceStore();
    $device = findDeviceByAddress($store, $routerHost, 'mikrotik');

    if (!$device) {
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'message' => 'Routeur MikroTik introuvable pour cette adresse.',
        ]);
        exit;
    }

    $users = getMikrotikHotspotUsers(500, $device);
    $matched = portal_find_mikrotik_user($users, $username);

    if (!$matched) {
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'message' => 'Utilisateur introuvable sur le routeur courant.',
        ]);
        exit;
    }

    echo json_encode(portal_status_from_mikrotik_row($matched), JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
    ]);
}
