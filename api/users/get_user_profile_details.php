<?php
require '../../config/db.php';
require_once '../../includes/device_manager.php';
require_once '../../includes/formatters.php';
require_once '../../includes/mikrotik_backend.php';
require_once '../../includes/profile_schema.php';
require_once '../../includes/user_schema.php';

session_start();

header('Content-Type: application/json');

function get_query_string_or_null(string $key): ?string
{
    $value = trim((string)($_GET[$key] ?? ''));
    return $value === '' ? null : $value;
}

function get_query_int_or_null(string $key): ?int
{
    $value = trim((string)($_GET[$key] ?? ''));
    if ($value === '') {
        return null;
    }

    if (filter_var($value, FILTER_VALIDATE_INT) === false) {
        return null;
    }

    return (int)$value;
}

function formatSecondsLabel(?int $seconds): string
{
    $value = (int)($seconds ?? 0);
    if ($value <= 0) {
        return '-';
    }

    if ($value % 2592000 === 0) {
        return (string)($value / 2592000) . ' mois';
    }

    if ($value % 86400 === 0) {
        return (string)($value / 86400) . ' j';
    }

    if ($value % 3600 === 0) {
        return (string)($value / 3600) . ' h';
    }

    if ($value % 60 === 0) {
        return (string)($value / 60) . ' min';
    }

    return (string)$value . ' s';
}

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'message' => 'Unauthorized',
    ]);
    exit;
}

$username = get_query_string_or_null('username');
$profileId = get_query_int_or_null('profile_id');
$profileName = get_query_string_or_null('profile_name');

if ($username === null && $profileId === null && $profileName === null) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Parametres manquants',
    ]);
    exit;
}

try {
    $activeDevice = getActiveDeviceContext()['device'] ?? null;
    $isMikrotik = (($activeDevice['type'] ?? '') === 'mikrotik');

    $response = [
        'success' => true,
        'profile_backend' => $isMikrotik ? 'mikrotik' : 'radius',
        'rate_limit' => '-',
        'shared_users' => '-',
        'user_time_limit' => '-',
        'user_data_limit' => '-',
        'time_limit' => '-',
        'validity' => '-',
        'expired_mode' => '-',
        'address_pool' => '-',
        'parent_queue' => '-',
        'price' => '-',
        'selling_price' => '-',
        'lock_user' => '-',
    ];

    if ($isMikrotik) {
        $matchedUser = null;

        if ($username !== null) {
            foreach (getMikrotikHotspotUsers(500) as $userRow) {
                if ((string)($userRow['username'] ?? '') === $username) {
                    $matchedUser = $userRow;
                    break;
                }
            }
        }

        if ($profileName === null && is_array($matchedUser)) {
            $profileName = trim((string)($matchedUser['profile'] ?? ''));
        }

        if ($profileName !== null && $profileName !== '') {
            $api = connectToActiveMikrotikApi();

            try {
                $routerProfile = findMikrotikProfileByName($api, $profileName);
            } finally {
                $api->disconnect();
            }

            if (is_array($routerProfile)) {
                $metadata = parseMikrotikOnLoginMetadata((string)($routerProfile['on-login'] ?? ''));

                $response['rate_limit'] = trim((string)($routerProfile['rate-limit'] ?? '')) !== '' ? trim((string)$routerProfile['rate-limit']) : '-';
                $response['shared_users'] = (string)((int)($routerProfile['shared-users'] ?? 0));
                /* Time Limit profil = session-timeout uniquement. Validite = on-login, distinct. */
                $offerSec = mikrotikProfileOfferTimeSeconds($routerProfile);
                $response['time_limit'] = $offerSec > 0 ? formatSecondsLabel($offerSec) : '-';
                $response['validity'] = formatMikrotikValidity((string)($metadata['validity'] ?? ''));
                $response['expired_mode'] = (string)($metadata['expired_mode'] ?? '-');
                $response['address_pool'] = trim((string)($routerProfile['address-pool'] ?? '')) !== '' ? trim((string)$routerProfile['address-pool']) : '-';
                $response['parent_queue'] = trim((string)($routerProfile['parent-queue'] ?? '')) !== '' ? trim((string)$routerProfile['parent-queue']) : '-';
                $response['price'] = (string)($metadata['price'] ?? '-');
                $response['selling_price'] = (string)($metadata['selling_price'] ?? '-');
                $response['lock_user'] = (string)($metadata['lock_user'] ?? '-');
            }
        }

        if (is_array($matchedUser)) {
            $userLimitSec = parseRouterosIntervalToSeconds(trim((string)($matchedUser['limit_uptime'] ?? '')));
            $response['user_time_limit'] = $userLimitSec > 0 ? formatSecondsLabel($userLimitSec) : '-';
        }

        echo json_encode($response);
        exit;
    }

    ensureProfilesExtendedSchema($pdo);
    ensureUsersExtendedSchema($pdo);

    $profile = null;
    if ($profileId !== null && $profileId > 0) {
        $stmt = $pdo->prepare('SELECT name, rate_limit, session_timeout, validity_time, simultaneous_use, ip_pool, expired_mode, price, selling_price, lock_user, parent_queue FROM profiles WHERE id = ? LIMIT 1');
        $stmt->execute([$profileId]);
        $profile = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    } elseif ($profileName !== null) {
        $stmt = $pdo->prepare('SELECT name, rate_limit, session_timeout, validity_time, simultaneous_use, ip_pool, expired_mode, price, selling_price, lock_user, parent_queue FROM profiles WHERE name = ? LIMIT 1');
        $stmt->execute([$profileName]);
        $profile = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    } elseif ($username !== null) {
        $stmt = $pdo->prepare('
            SELECT p.name, p.rate_limit, p.session_timeout, p.validity_time, p.simultaneous_use, p.ip_pool, p.expired_mode, p.price, p.selling_price, p.lock_user, p.parent_queue
            FROM users u
            LEFT JOIN profiles p ON u.profile_id = p.id
            WHERE u.username = ?
            LIMIT 1
        ');
        $stmt->execute([$username]);
        $profile = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    if ($profile) {
        $response['rate_limit'] = trim((string)($profile['rate_limit'] ?? '')) !== '' ? trim((string)$profile['rate_limit']) : '-';
        $response['shared_users'] = (string)((int)($profile['simultaneous_use'] ?? 0));
        $response['time_limit'] = formatSecondsLabel(isset($profile['session_timeout']) ? (int)$profile['session_timeout'] : null);

        $validityTime = (int)($profile['validity_time'] ?? 0);
        if ($validityTime > 0) {
            if ($validityTime % 2592000 === 0) {
                $response['validity'] = (string)($validityTime / 2592000) . ' mois';
            } elseif ($validityTime % 86400 === 0) {
                $response['validity'] = (string)($validityTime / 86400) . ' j';
            } elseif ($validityTime % 3600 === 0) {
                $response['validity'] = (string)($validityTime / 3600) . ' h';
            } else {
                $response['validity'] = (string)$validityTime . ' s';
            }
        }

        $response['address_pool'] = trim((string)($profile['ip_pool'] ?? '')) !== '' ? trim((string)$profile['ip_pool']) : '-';
        $response['expired_mode'] = trim((string)($profile['expired_mode'] ?? '')) !== '' ? trim((string)$profile['expired_mode']) : '-';
        $response['price'] = ($profile['price'] ?? null) !== null && (string)$profile['price'] !== '' ? (string)$profile['price'] : '-';
        $response['selling_price'] = ($profile['selling_price'] ?? null) !== null && (string)$profile['selling_price'] !== '' ? (string)$profile['selling_price'] : '-';
        $response['lock_user'] = isset($profile['lock_user']) ? ((int)$profile['lock_user'] === 1 ? 'Enable' : 'Disable') : '-';
        $response['parent_queue'] = trim((string)($profile['parent_queue'] ?? '')) !== '' ? trim((string)$profile['parent_queue']) : '-';
    }

    if ($username !== null) {
        $stmt = $pdo->prepare('SELECT session_timeout, data_limit FROM users WHERE username = ? LIMIT 1');
        $stmt->execute([$username]);
        $userLimits = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

        if (is_array($userLimits)) {
            $effectiveSessionTimeout = isset($userLimits['session_timeout']) && $userLimits['session_timeout'] !== null
                ? (int)$userLimits['session_timeout']
                : null;
            $effectiveDataLimit = isset($userLimits['data_limit']) && $userLimits['data_limit'] !== null
                ? (int)$userLimits['data_limit']
                : null;

            $response['user_time_limit'] = formatSecondsLabel($effectiveSessionTimeout);

            if ($effectiveDataLimit !== null && $effectiveDataLimit > 0) {
                if ($effectiveDataLimit >= 1024 && $effectiveDataLimit % 1024 === 0) {
                    $response['user_data_limit'] = rtrim(rtrim(number_format($effectiveDataLimit / 1024, 2, '.', ''), '0'), '.') . ' Go';
                } else {
                    $response['user_data_limit'] = rtrim(rtrim(number_format($effectiveDataLimit, 2, '.', ''), '0'), '.') . ' Mo';
                }
            }
        }
    }

    echo json_encode($response);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
    ]);
}
