<?php
ob_start();
require '../../config/db.php';
require_once '../../includes/recharge_history_store.php';
require_once '../../includes/RechargeService.php';
require_once '../../includes/recharge_preview_service.php';
require_once '../../includes/device_manager.php';
require_once '../../includes/mikrotik_backend.php';
require_once '../../includes/operation_history.php';
require_once '../../includes/nas_resolver.php';
require_once '../../includes/radius_credit_runtime.php';
require_once '../../includes/radius_sync.php';
require_once '../../includes/user_schema.php';

session_start();

header('Content-Type: application/json');

register_shutdown_function(static function (): void {
    $error = error_get_last();
    if ($error === null) {
        return;
    }

    $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
    if (!in_array($error['type'] ?? 0, $fatalTypes, true)) {
        return;
    }

    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'message' => 'Erreur PHP: ' . ($error['message'] ?? 'Fatal error'),
    ]);
});

function post_string_or_null(string $key): ?string
{
    $value = trim((string)($_POST[$key] ?? ''));
    return $value === '' ? null : $value;
}

function require_valid_csrf(): void
{
    $token = trim((string)($_POST['csrf_token'] ?? ''));
    if ($token === '' || !hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        throw new RuntimeException('CSRF invalide');
    }
}

function resolveRechargeAmount(array $device, string $profileName, ?RouterosAPI $api = null): array
{
    if (($device['type'] ?? '') !== 'mikrotik') {
        return [
            'value' => null,
            'label' => '',
        ];
    }

    $ownsConnection = $api === null;
    $api = $api ?? connectToMikrotikApiByDevice($device);

    try {
        $profile = findMikrotikProfileByName($api, $profileName);
        if (!$profile) {
            return [
                'value' => null,
                'label' => '',
            ];
        }

        $metadata = parseMikrotikOnLoginMetadata((string)($profile['on-login'] ?? ''));
        $candidates = [
            trim((string)($metadata['selling_price'] ?? '')),
            trim((string)($metadata['price'] ?? '')),
        ];

        foreach ($candidates as $candidate) {
            if ($candidate === '' || $candidate === '-' || !is_numeric($candidate)) {
                continue;
            }

            return [
                'value' => round((float)$candidate, 2),
                'label' => rtrim(rtrim(number_format((float)$candidate, 2, '.', ''), '0'), '.'),
            ];
        }

        return [
            'value' => null,
            'label' => '',
        ];
    } finally {
        disconnectMikrotikApiIfOwned($api, $ownsConnection);
    }
}

function parsePreviewExpirationDate(?string $value): ?DateTimeImmutable
{
    $raw = trim((string)$value);
    if ($raw === '') {
        return null;
    }

    if (preg_match('/^\d{4}-\d{2}-\d{2}/', $raw, $matches)) {
        $date = DateTimeImmutable::createFromFormat('Y-m-d', $matches[0], new DateTimeZone('UTC'));
        return $date instanceof DateTimeImmutable ? $date : null;
    }

    return null;
}

function formatPreviewExpirationDate(?DateTimeImmutable $date): string
{
    return $date instanceof DateTimeImmutable ? $date->format('Y-m-d') : '-';
}

function addPreviewSeconds(DateTimeImmutable $date, int $seconds): DateTimeImmutable
{
    if ($seconds <= 0) {
        return $date;
    }

    return $date->modify('+' . $seconds . ' seconds');
}

function resolveNasIdByDeviceAddress(PDO $pdo, array $device): int
{
    $address = extractDeviceAddress((string)($device['host'] ?? ''));
    if ($address === '') {
        return 0;
    }

    $stmt = $pdo->prepare('SELECT id FROM nas WHERE nasname = ? LIMIT 1');
    $stmt->execute([$address]);
    $exact = $stmt->fetchColumn();
    if ($exact !== false) {
        return (int)$exact;
    }

    $stmt = $pdo->prepare('SELECT id FROM nas WHERE nasname LIKE ? LIMIT 1');
    $stmt->execute(['%' . $address . '%']);
    $like = $stmt->fetchColumn();

    return $like !== false ? (int)$like : 0;
}

function normalizeExpirationValue(?DateTimeImmutable $expiration): ?string
{
    return $expiration instanceof DateTimeImmutable ? $expiration->format('Y-m-d') : null;
}

function loadRadiusAccountingTotals(PDO $pdo, string $username): array
{
    $stmt = $pdo->prepare('
        SELECT
            COALESCE(SUM(acctsessiontime), 0) AS total_time,
            COALESCE(SUM(acctinputoctets), 0) + COALESCE(SUM(acctoutputoctets), 0) AS total_bytes
        FROM radacct
        WHERE username = ?
    ');
    $stmt->execute([$username]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    return [
        'session_seconds' => max(0, (int)($row['total_time'] ?? 0)),
        'data_bytes' => max(0, (int)($row['total_bytes'] ?? 0)),
    ];
}

function applyRadiusLikeRecharge(PDO $pdo, array $device, string $username, string $profileValue, string $mode): array
{
    ensureUsersExtendedSchema($pdo);

    $userStmt = $pdo->prepare('
        SELECT id, username, password, nas_id, profile_id, status, expiration_date, session_timeout, data_limit, current_credit_time, current_credit_data, imported_session_total_seconds, imported_data_consumed_bytes
        FROM users
        WHERE username = ?
        LIMIT 1
    ');
    $userStmt->execute([$username]);
    $userRow = $userStmt->fetch(PDO::FETCH_ASSOC);
    if (!$userRow) {
        throw new RuntimeException('Utilisateur introuvable');
    }

    $offerProfileId = (int)$profileValue;
    if ($offerProfileId <= 0) {
        throw new RuntimeException('Profil invalide');
    }

    $profileStmt = $pdo->prepare('
        SELECT id, name, session_timeout, validity_time, data_quota_mb, rate_limit, simultaneous_use, idle_timeout
        FROM profiles
        WHERE id = ?
        LIMIT 1
    ');
    $profileStmt->execute([$offerProfileId]);
    $offerProfile = $profileStmt->fetch(PDO::FETCH_ASSOC);
    if (!$offerProfile) {
        throw new RuntimeException('Profil introuvable');
    }

    $currentProfileId = (int)($userRow['profile_id'] ?? 0);
    $currentProfileStmt = $pdo->prepare('
        SELECT id, name, session_timeout, validity_time, data_quota_mb
        FROM profiles
        WHERE id = ?
        LIMIT 1
    ');
    $currentProfileStmt->execute([$currentProfileId]);
    $currentProfile = $currentProfileStmt->fetch(PDO::FETCH_ASSOC) ?: null;

    $offerSessionSeconds = max(0, (int)($offerProfile['session_timeout'] ?? 0));
    $offerValiditySeconds = max(0, (int)($offerProfile['validity_time'] ?? 0));
    $offerMegabytes = max(0, (int)($offerProfile['data_quota_mb'] ?? 0));
    $baselineScopeId = resolveUserCounterBaselineScopeId((string)($device['business_source'] ?? 'radius'), (string)($device['id'] ?? ''));
    $accountingTotals = loadRadiusAccountingTotals($pdo, $username);
    $counterBaseline = loadUserCounterBaseline($pdo, $baselineScopeId, $username);
    $runtimeState = buildRadiusRuntimeState($userRow, $accountingTotals, $counterBaseline, $currentProfile ?: null);
    $currentSeconds = $runtimeState['remaining_session_seconds'];
    $currentMegabytes = $runtimeState['remaining_data_megabytes'];

    $today = new DateTimeImmutable('today', new DateTimeZone('UTC'));
    $currentExpiration = parsePreviewExpirationDate((string)($userRow['expiration_date'] ?? ''));

    $projectedProfileId = $offerProfileId;
    $projectedSeconds = $offerSessionSeconds;
    $projectedMegabytes = $offerMegabytes;
    $projectedExpiration = null;
    if ($mode !== 'replace_offer' && $currentExpiration instanceof DateTimeImmutable && $currentExpiration >= $today) {
        $projectedExpiration = addPreviewSeconds($currentExpiration, $offerValiditySeconds);
    }

    if ($mode === 'extend_offer') {
        if (!$currentExpiration instanceof DateTimeImmutable || $currentExpiration < $today) {
            throw new RuntimeException('Le rechargement est disponible uniquement pour un compte non expire.');
        }
        $projectedProfileId = $currentProfileId > 0 ? $currentProfileId : $offerProfileId;
        $projectedSeconds = $currentSeconds + $offerSessionSeconds;
        $projectedMegabytes = $currentMegabytes + $offerMegabytes;
        if ($currentExpiration instanceof DateTimeImmutable && $currentExpiration >= $today) {
            $projectedExpiration = addPreviewSeconds($currentExpiration, $offerValiditySeconds);
        } else {
            $projectedExpiration = null;
        }
    } elseif ($mode === 'accumulate_offer') {
        $currentProfileName = trim((string)($currentProfile['name'] ?? ''));
        $offerProfileName = trim((string)($offerProfile['name'] ?? ''));
        if ($currentProfileName === '' || $currentProfileName !== $offerProfileName) {
            throw new RuntimeException('Le cumul n est autorise que sur le meme profil.');
        }
        $projectedProfileId = $currentProfileId > 0 ? $currentProfileId : $offerProfileId;
        $projectedSeconds = $currentSeconds + $offerSessionSeconds;
        $projectedMegabytes = $currentMegabytes + $offerMegabytes;
        if ($currentExpiration instanceof DateTimeImmutable && $currentExpiration >= $today) {
            $projectedExpiration = addPreviewSeconds($currentExpiration, $offerValiditySeconds);
        } else {
            $projectedExpiration = null;
        }
    } elseif ($mode !== 'replace_offer') {
        throw new RuntimeException('Mode de recharge non supporte.');
    }

    $resolvedNasId = max(0, (int)($userRow['nas_id'] ?? 0));
    if ($resolvedNasId <= 0) {
        $resolvedNasId = resolveNasIdByDeviceAddress($pdo, $device);
    }
    if ($resolvedNasId <= 0) {
        throw new RuntimeException('NAS introuvable pour cet utilisateur.');
    }

    $nasContext = loadNasContext($pdo, $resolvedNasId);
    $effectiveProfileId = $projectedProfileId > 0 ? $projectedProfileId : $offerProfileId;
    $effectiveProfileStmt = $pdo->prepare('
        SELECT id, name, rate_limit, simultaneous_use, idle_timeout
        FROM profiles
        WHERE id = ?
        LIMIT 1
    ');
    $effectiveProfileStmt->execute([$effectiveProfileId]);
    $effectiveProfile = $effectiveProfileStmt->fetch(PDO::FETCH_ASSOC);
    if (!$effectiveProfile) {
        throw new RuntimeException('Profil applique introuvable.');
    }

    $expirationValue = normalizeExpirationValue($projectedExpiration);
    $updateUserStmt = $pdo->prepare('
        UPDATE users
        SET profile_id = ?, nas_id = ?, session_timeout = ?, data_limit = ?, current_credit_time = ?, current_credit_data = ?, expiration_date = ?
        WHERE id = ?
    ');
    $updateUserStmt->execute([
        $effectiveProfileId,
        $resolvedNasId,
        $projectedSeconds > 0 ? $projectedSeconds : null,
        $projectedMegabytes > 0 ? $projectedMegabytes : null,
        $projectedSeconds,
        $projectedMegabytes > 0 ? ($projectedMegabytes * 1024 * 1024) : 0,
        $expirationValue,
        (int)$userRow['id'],
    ]);

    upsertUserCounterBaseline(
        $pdo,
        $baselineScopeId,
        $username,
        (int)$runtimeState['accounting_session_seconds'],
        (int)$runtimeState['accounting_data_bytes']
    );

    updateUserToNasBackend($pdo, [
        'username' => (string)$userRow['username'],
        'old_username' => (string)$userRow['username'],
        'password' => (string)($userRow['password'] ?? ''),
        'status' => trim((string)($userRow['status'] ?? 'active')) ?: 'active',
        'rate_limit' => trim((string)($effectiveProfile['rate_limit'] ?? '')) ?: null,
        'session_timeout' => $projectedSeconds > 0 ? $projectedSeconds : null,
        'simultaneous_use' => max(0, (int)($effectiveProfile['simultaneous_use'] ?? 0)),
        'idle_timeout' => max(0, (int)($effectiveProfile['idle_timeout'] ?? 0)),
        'data_limit' => $projectedMegabytes > 0 ? $projectedMegabytes : null,
        'expiration_date' => $expirationValue,
        'allow_user_max_octets' => true,
        'force_user_reply_attributes' => ['Session-Timeout', 'Max-Octets'],
    ], (string)$effectiveProfile['name'], $nasContext);

    return [
        'profile_name' => (string)$effectiveProfile['name'],
        'projected' => [
            'profile' => (string)$effectiveProfile['name'],
            'time_limit' => (string)$projectedSeconds,
            'data_limit' => (string)$projectedMegabytes,
            'expiration' => formatPreviewExpirationDate($projectedExpiration),
        ],
    ];
}

function recordRechargeOperationHistory(PDO $pdo, array $device, string $username, string $profileValue, string $mode, string $operator, array $preview, array $amount): void
{
    recordOperationHistory($pdo, [
        'operation_scope' => 'commercial',
        'operation_type' => 'recharge',
        'actor_username' => $operator,
        'actor_role' => (string)($_SESSION['user_role'] ?? 'administrator'),
        'target_type' => 'user',
        'target_name' => $username,
        'device_id' => (string)($device['id'] ?? ''),
        'profile_name' => $profileValue,
        'amount_value' => $amount['value'] ?? null,
        'summary' => effect_summary_from_mode($mode),
        'details_json' => [
            'mode' => $mode,
            'current' => $preview['current'] ?? [],
            'projected' => $preview['projected'] ?? [],
        ],
    ]);
}

function invalidateRechargeOptionsCache(string $deviceId): void
{
    $dir = sys_get_temp_dir() . '/mikhmon_recharge_options_cache';
    if (!is_dir($dir)) {
        return;
    }

    foreach (['users', 'profiles'] as $action) {
        $file = $dir . '/' . md5($deviceId . '|' . $action) . '.json';
        if (is_file($file)) {
            @unlink($file);
        }
    }
}

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

try {
    require_valid_csrf();

    $deviceId = post_string_or_null('device_id');
    $username = post_string_or_null('username');
    $profileId = post_string_or_null('profile_id');
    $profileName = post_string_or_null('profile_name');
    $mode = post_string_or_null('mode');

    if ($deviceId === null || $username === null || $mode === null) {
        throw new RuntimeException('Champs obligatoires manquants');
    }

    if (!in_array($mode, ['replace_offer', 'extend_offer', 'accumulate_offer'], true)) {
        throw new RuntimeException('Mode invalide');
    }

    $store = loadDeviceStore();
    $device = findDeviceById($store, $deviceId);
    if (!$device) {
        throw new RuntimeException('Device introuvable');
    }

    $backendContext = resolveRechargeBackendContext($pdo, $device);
    $deviceContext = $backendContext['nas_context'];
    $contextDevice = $backendContext['context_device'];
    $businessSource = $backendContext['business_source'];
    $isMikrotikBackend = ($businessSource === 'mikrotik_local');

    if ($isMikrotikBackend) {
        if ($profileName === null) {
            throw new RuntimeException('Profil manquant');
        }
        $profileValue = $profileName;
    } else {
        if ($profileId === null) {
            throw new RuntimeException('Profil manquant');
        }
        $profileValue = $profileId;
    }

    $operator = trim((string)($_SESSION['username'] ?? 'Utilisateur'));
    $preview = RechargeService::simulate(
        $pdo,
        $device,
        $username,
        $profileValue,
        $mode,
        $profileId,
        $profileName
    );
    $historyPreview = [
        'current' => $preview['current'] ?? [],
        'projected' => $preview['projected'] ?? [],
    ];

    ensureRechargeHistoryTable($pdo);
    ensureOperationHistoryTable($pdo);
    if ($isMikrotikBackend) {
        $mikrotikNasContext = is_array($deviceContext) ? $deviceContext : [
            'device' => $contextDevice,
            'device_type' => (string)($contextDevice['type'] ?? 'mikrotik'),
            'backend_driver' => (string)($contextDevice['backend_driver'] ?? 'mikrotik_api'),
            'business_source' => $businessSource,
        ];
        $mikrotikApi = connectToMikrotikApiByDevice($contextDevice);

        try {
            $amount = resolveRechargeAmount($contextDevice, $profileValue, $mikrotikApi);

            if ($mode === 'replace_offer') {
                replaceUserOfferInMikrotik($username, $profileValue, $mikrotikNasContext, $mikrotikApi);
                recordRechargeInMikrotik($username, $profileValue, $mode, $operator, effect_summary_from_mode($mode), $mikrotikNasContext, 100, $mikrotikApi);
                invalidateRechargeOptionsCache((string)$deviceId);
                try {
                    saveRechargeHistory($pdo, $device, $username, $profileValue, $mode, $operator, $historyPreview, $amount);
                } catch (Throwable $e) {
                    error_log('RechargeHistory failed: ' . $e->getMessage());
                }
                try {
                    recordRechargeOperationHistory($pdo, $device, $username, $profileValue, $mode, $operator, $historyPreview, $amount);
                } catch (Throwable $e) {
                    error_log('OperationHistory failed: ' . $e->getMessage());
                }
                echo json_encode([
                    'success' => true,
                    'message' => 'Recharge appliquée sur MikroTik.',
                ]);
                while (ob_get_level() > 0) {
                    ob_end_flush();
                }
                disconnectMikrotikApiIfOwned($mikrotikApi, true);
                exit;
            }

            if ($mode === 'extend_offer') {
                extendUserOfferInMikrotik($username, $profileValue, $mikrotikNasContext, $mikrotikApi);
                recordRechargeInMikrotik($username, $profileValue, $mode, $operator, effect_summary_from_mode($mode), $mikrotikNasContext, 100, $mikrotikApi);
                invalidateRechargeOptionsCache((string)$deviceId);
                try {
                    saveRechargeHistory($pdo, $device, $username, $profileValue, $mode, $operator, $historyPreview, $amount);
                } catch (Throwable $e) {
                    error_log('RechargeHistory failed: ' . $e->getMessage());
                }
                try {
                    recordRechargeOperationHistory($pdo, $device, $username, $profileValue, $mode, $operator, $historyPreview, $amount);
                } catch (Throwable $e) {
                    error_log('OperationHistory failed: ' . $e->getMessage());
                }
                echo json_encode([
                    'success' => true,
                    'message' => 'Rajout appliqué sur MikroTik.',
                ]);
                while (ob_get_level() > 0) {
                    ob_end_flush();
                }
                disconnectMikrotikApiIfOwned($mikrotikApi, true);
                exit;
            }

            if ($mode === 'accumulate_offer') {
                accumulateUserOfferInMikrotik($username, $profileValue, $mikrotikNasContext, $mikrotikApi);
                recordRechargeInMikrotik($username, $profileValue, $mode, $operator, effect_summary_from_mode($mode), $mikrotikNasContext, 100, $mikrotikApi);
                invalidateRechargeOptionsCache((string)$deviceId);
                try {
                    saveRechargeHistory($pdo, $device, $username, $profileValue, $mode, $operator, $historyPreview, $amount);
                } catch (Throwable $e) {
                    error_log('RechargeHistory failed: ' . $e->getMessage());
                }
                try {
                    recordRechargeOperationHistory($pdo, $device, $username, $profileValue, $mode, $operator, $historyPreview, $amount);
                } catch (Throwable $e) {
                    error_log('OperationHistory failed: ' . $e->getMessage());
                }
                echo json_encode([
                    'success' => true,
                    'message' => 'Cumul appliqué sur MikroTik.',
                ]);
                while (ob_get_level() > 0) {
                    ob_end_flush();
                }
                disconnectMikrotikApiIfOwned($mikrotikApi, true);
                exit;
            }

            throw new RuntimeException('Seuls les modes "Remplacer l offre", "Rajout d offre" et "Cumuler l offre" sont disponibles en application reelle pour le moment.');
        } finally {
            disconnectMikrotikApiIfOwned($mikrotikApi, true);
        }
    }

    ensureUsersExtendedSchema($pdo);
    $pdo->beginTransaction();
    $result = applyRadiusLikeRecharge($pdo, $device, $username, $profileValue, $mode);
    $amount = [
        'value' => null,
        'label' => '',
    ];
    $saveProfileValue = (string)($result['profile_name'] ?? $profileValue);
    $historyPreview['projected']['profile'] = (string)($result['projected']['profile'] ?? ($historyPreview['projected']['profile'] ?? ''));
    try {
        saveRechargeHistory($pdo, $device, $username, $saveProfileValue, $mode, $operator, $historyPreview, $amount);
    } catch (Throwable $e) {
        error_log('RechargeHistory failed: ' . $e->getMessage());
    }
    try {
        recordRechargeOperationHistory($pdo, $device, $username, $saveProfileValue, $mode, $operator, $historyPreview, $amount);
    } catch (Throwable $e) {
        error_log('OperationHistory failed: ' . $e->getMessage());
    }
    $pdo->commit();
    echo json_encode([
        'success' => true,
        'message' => 'Recharge appliquée sur backend RADIUS/OPNsense.',
    ]);
    while (ob_get_level() > 0) {
        ob_end_flush();
    }
    exit;
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
    ]);
}
