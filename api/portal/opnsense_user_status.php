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
require_once __DIR__ . '/../../includes/device_manager.php';
require_once __DIR__ . '/../../includes/formatters.php';

function portal_query_string(string $key): ?string
{
    $value = trim((string)($_GET[$key] ?? ''));
    return $value === '' ? null : $value;
}

function portal_opnsense_status_payload(
    array $userRow,
    ?array $radacctOpen,
    string $fallbackIp,
    string $fallbackMac
): array {
    $username = (string)($userRow['username'] ?? '');
    $profileName = trim((string)($userRow['profile_name'] ?? ''));
    if ($profileName === '') {
        $profileName = '-';
    }

    $sessionTimeout = (int)($userRow['session_timeout'] ?? 0);
    $timeLimitLabel = $sessionTimeout > 0
        ? formatDurationCompactLabel($sessionTimeout)
        : '-';

    $dataLimitMb = (int)($userRow['data_limit'] ?? 0);
    $dataLimitLabel = $dataLimitMb > 0
        ? formatQuotaMbLabel($dataLimitMb)
        : 'Illimité';

    $acctTime = 0;
    $inBytes = 0.0;
    $outBytes = 0.0;
    $framedIp = $fallbackIp;
    $mac = $fallbackMac;

    if (is_array($radacctOpen)) {
        $acctTime = (int)($radacctOpen['acctsessiontime'] ?? 0);
        $inBytes = (float)($radacctOpen['acctinputoctets'] ?? 0);
        $outBytes = (float)($radacctOpen['acctoutputoctets'] ?? 0);
        $fi = trim((string)($radacctOpen['framedipaddress'] ?? ''));
        $cm = trim((string)($radacctOpen['callingstationid'] ?? ''));
        if ($fi !== '') {
            $framedIp = $fi;
        }
        if ($cm !== '') {
            $mac = $cm;
        }
    }

    $sessionTotalLabel = $acctTime > 0
        ? formatConsumedDurationLabel($acctTime)
        : 'N/D';

    $consumedBytes = $inBytes + $outBytes;
    $dataConsumedLabel = $consumedBytes > 0
        ? formatMikrotikBytesLimit($consumedBytes)
        : '-';

    $expRaw = trim((string)($userRow['expiration_date'] ?? ''));
    $expiration = $expRaw !== '' ? $expRaw : '-';

    $online = is_array($radacctOpen);

    return [
        'success' => true,
        'device_type' => 'opnsense',
        'business_source' => 'radius',
        'backend_driver' => 'opnsense_api',
        'username' => $username,
        'ip' => $framedIp,
        'mac' => $mac,
        'plan' => $profileName,
        'time_limit_label' => $timeLimitLabel,
        'session_total_label' => $sessionTotalLabel,
        'data_limit_label' => $dataLimitLabel,
        'data_consumed_label' => $dataConsumedLabel,
        'expiration' => $expiration,
        'online' => $online,
    ];
}

$username = portal_query_string('username');
$deviceId = portal_query_string('device_id');
$routerHost = portal_query_string('router_host') ?? portal_query_string('firewall_host');
$fallbackIp = portal_query_string('ip') ?? '';
$fallbackMac = portal_query_string('mac') ?? '';

if ($username === null) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Paramètre username requis.',
    ]);
    exit;
}

if ($deviceId === null && $routerHost === null) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Paramètres device_id ou router_host (firewall_host) requis.',
    ]);
    exit;
}

try {
    $store = loadDeviceStore();
    $device = null;
    if ($deviceId !== null) {
        $candidate = findDeviceById($store, $deviceId);
        if ($candidate && normalizeDeviceType((string)($candidate['type'] ?? '')) === 'opnsense') {
            $device = $candidate;
        }
    } else {
        $device = findDeviceByAddress($store, $routerHost ?? '', 'opnsense');
    }

    if (!$device) {
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'message' => 'Pare-feu OPNsense introuvable pour cette adresse ou cet identifiant.',
        ]);
        exit;
    }

    $deviceAddr = extractDeviceAddress((string)($device['host'] ?? ''));
    if ($deviceAddr === '') {
        $deviceAddr = strtolower(trim((string)($device['ip'] ?? '')));
    } else {
        $deviceAddr = strtolower($deviceAddr);
    }

    $stmt = $pdo->prepare('
        SELECT
            u.id,
            u.username,
            u.session_timeout,
            u.data_limit,
            u.current_credit_data,
            u.expiration_date,
            u.nas_id,
            p.name AS profile_name
        FROM users u
        LEFT JOIN profiles p ON p.id = u.profile_id
        WHERE u.username = ?
        LIMIT 1
    ');
    $stmt->execute([$username]);
    $userRow = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!is_array($userRow)) {
        $stmtRg = $pdo->prepare('SELECT groupname FROM radusergroup WHERE username = ? LIMIT 1');
        $stmtRg->execute([$username]);
        $groupName = trim((string)($stmtRg->fetchColumn()));
        if ($groupName === '') {
            http_response_code(404);
            echo json_encode([
                'success' => false,
                'message' => 'Utilisateur introuvable.',
            ]);
            exit;
        }
        $userRow = [
            'username' => $username,
            'session_timeout' => 0,
            'data_limit' => 0,
            'current_credit_data' => 0,
            'expiration_date' => null,
            'nas_id' => null,
            'profile_name' => $groupName,
        ];
    }

    $nasIdRaw = $userRow['nas_id'] ?? null;
    $nasId = ($nasIdRaw !== null && $nasIdRaw !== '') ? (int)$nasIdRaw : null;
    if ($nasId !== null && $nasId > 0 && $deviceAddr !== '') {
        $stmtNas = $pdo->prepare('SELECT nasname FROM nas WHERE id = ? LIMIT 1');
        $stmtNas->execute([$nasId]);
        $nasName = strtolower(trim((string)($stmtNas->fetchColumn())));
        if ($nasName !== '' && $nasName !== $deviceAddr) {
            http_response_code(404);
            echo json_encode([
                'success' => false,
                'message' => 'Cet utilisateur n est pas rattache a ce pare-feu.',
            ]);
            exit;
        }
    }

    $stmtAcct = $pdo->prepare('
        SELECT
            acctsessiontime,
            acctinputoctets,
            acctoutputoctets,
            framedipaddress,
            callingstationid
        FROM radacct
        WHERE username = ? AND acctstoptime IS NULL
        ORDER BY acctstarttime DESC
        LIMIT 1
    ');
    $stmtAcct->execute([$username]);
    $radOpen = $stmtAcct->fetch(PDO::FETCH_ASSOC) ?: null;

    echo json_encode(
        portal_opnsense_status_payload(
            $userRow,
            is_array($radOpen) ? $radOpen : null,
            $fallbackIp,
            $fallbackMac
        ),
        JSON_UNESCAPED_SLASHES
    );
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
    ]);
}
