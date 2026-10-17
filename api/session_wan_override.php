<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/device_manager.php';
require_once __DIR__ . '/../includes/mikrotik_backend.php';
require_once __DIR__ . '/../includes/multi_wan_config.php';
require_once __DIR__ . '/../includes/multi_wan_allocator.php';
require_once __DIR__ . '/../includes/operation_history.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');

function sessionWanOverrideReply(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function sessionWanOverridePayload(): array
{
    $payload = json_decode((string)file_get_contents('php://input'), true);
    if (!is_array($payload)) {
        throw new InvalidArgumentException('Payload JSON invalide.');
    }
    return $payload;
}

function sessionWanOverrideInstallRouterosEngine(RouterosAPI $api, array $config, array $overrides): void
{
    $source = multiWanBuildRouterosAssignScript($config, $overrides);
    $rows = multiWanRows($api, '/system/script/print', [
        '?name' => 'RM-NWAN-ASSIGN',
        '.proplist' => '.id,name',
    ]);
    if ($rows === []) {
        multiWanAssertOk($api->comm('/system/script/add', [
            'name' => 'RM-NWAN-ASSIGN',
            'source' => $source,
            'policy' => 'read,write,policy,test',
        ]), 'Création du moteur N-WAN refusée');
    } else {
        multiWanAssertOk($api->comm('/system/script/set', [
            '.id' => (string)$rows[0]['.id'],
            'source' => $source,
        ]), 'Mise à jour du moteur N-WAN refusée');
    }
    mikrotikEnsureNwanProfileHooks($api, multiWanConfigWeights($config));
    multiWanAssertOk($api->comm('/system/script/run', [
        'number' => 'RM-NWAN-ASSIGN',
    ]), 'Exécution du moteur N-WAN refusée');
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        sessionWanOverrideReply(['status' => 'error', 'message' => 'Méthode non autorisée.'], 405);
    }
    if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
        sessionWanOverrideReply(['status' => 'error', 'message' => 'Unauthorized'], 403);
    }
    if (!function_exists('isAdministrator') || !isAdministrator()) {
        sessionWanOverrideReply(['status' => 'error', 'message' => 'Accès réservé à l administrateur.'], 403);
    }

    $payload = sessionWanOverridePayload();
    $token = trim((string)($payload['csrf_token'] ?? ''));
    if ($token === '' || !hash_equals((string)($_SESSION['csrf_token'] ?? ''), $token)) {
        sessionWanOverrideReply(['status' => 'error', 'message' => 'CSRF invalide.'], 403);
    }

    $deviceId = trim((string)($payload['device_id'] ?? ''));
    $username = trim((string)($payload['username'] ?? ''));
    $address = trim((string)($payload['address'] ?? ''));
    $target = strtoupper(trim((string)($payload['wan'] ?? '')));

    if ($deviceId === '') throw new InvalidArgumentException('Device manquant.');
    if ($username === '' || $username === '-') throw new InvalidArgumentException('Utilisateur Hotspot manquant.');
    if ($target === '') throw new InvalidArgumentException('Choix WAN manquant.');
    if ($address !== '' && $address !== '-' && !filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        throw new InvalidArgumentException('Adresse IP active invalide.');
    }

    $device = requireAccessibleDevice(loadDeviceStore(), $deviceId);
    if (normalizeDeviceType((string)($device['type'] ?? '')) !== 'mikrotik') {
        throw new InvalidArgumentException('Les exceptions WAN sont disponibles seulement pour MikroTik.');
    }

    $config = multiWanLoadConfig($device);
    $deviceKey = trim((string)($device['id'] ?? $device['host'] ?? $deviceId));
    $api = connectToMikrotikApiByDevice($device);

    try {
        $scriptRows = multiWanRows($api, '/system/script/print', [
            '?name' => 'RM-NWAN-ASSIGN',
            '.proplist' => 'source',
        ]);
        $scriptSource = (string)($scriptRows[0]['source'] ?? '');
        if ($scriptSource !== '') {
            $config = multiWanParseRouterosAssignScript($scriptSource, $config);
        }
        $options = [];
        foreach (multiWanConfigWanOptions($config) as $option) {
            $options[(string)$option['wan_id']] = $option;
        }
        $activeIps = multiWanActiveUserIps($api, $username);
        if ($target === 'AUTO') {
            multiWanDeleteUserOverride($pdo, $deviceKey, $username);
            $cleanup = multiWanRemoveIpsFromManagedLists($api, $activeIps);
            sessionWanOverrideInstallRouterosEngine($api, $config, multiWanLoadUserOverrides($pdo, $deviceKey));
            $apply = ['mode' => 'username', 'username' => $username, 'active_ips' => $activeIps, 'cleanup' => $cleanup, 'target_wan' => null];
            $summary = 'Exception WAN supprimée pour ' . $username;
            $message = 'Exception supprimée. Le moteur RouterOS réaffectera cette session automatiquement.';
        } else {
            $target = multiWanNormalizeWanId($target);
            if (empty($options[$target]['selectable'])) {
                throw new InvalidArgumentException(($options[$target]['label'] ?? $target) . ' n est pas actif.');
            }
            $interface = (string)$options[$target]['interface'];
            multiWanSaveUserOverride($pdo, $deviceKey, $username, $target, $interface, 'Exception depuis sessions_list.php');
            $cleanup = multiWanRemoveIpsFromManagedLists($api, $activeIps);
            sessionWanOverrideInstallRouterosEngine($api, $config, multiWanLoadUserOverrides($pdo, $deviceKey));
            $apply = ['mode' => 'username', 'username' => $username, 'active_ips' => $activeIps, 'cleanup' => $cleanup, 'target_wan' => $target];
            $summary = 'Exception WAN ' . $target . ' pour ' . $username;
            $message = $username . ' forcé sur ' . $target . '.';
        }
    } finally {
        disconnectMikrotikApiIfOwned($api, true);
    }

    recordOperationHistory($pdo, [
        'operation_scope' => 'admin',
        'operation_type' => 'session_wan_override',
        'actor_username' => (string)($_SESSION['username'] ?? ''),
        'actor_role' => (string)($_SESSION['user_role'] ?? 'administrator'),
        'target_type' => 'hotspot_user',
        'target_name' => $username,
        'target_ref' => $address !== '' && $address !== '-' ? $address : null,
        'device_id' => (string)($device['id'] ?? ''),
        'summary' => $summary,
        'details_json' => [
            'wan' => $target,
            'address' => $address,
            'apply' => $apply,
        ],
    ]);

    sessionWanOverrideReply([
        'status' => 'success',
        'message' => $message,
        'apply' => $apply,
    ]);
} catch (Throwable $e) {
    sessionWanOverrideReply([
        'status' => 'error',
        'message' => trim((string)$e->getMessage()) ?: 'Exception WAN impossible.',
    ], $e instanceof InvalidArgumentException ? 422 : 500);
}
