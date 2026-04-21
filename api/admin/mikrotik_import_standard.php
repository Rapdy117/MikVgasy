<?php

session_start();

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/device_manager.php';
require_once __DIR__ . '/../../includes/mikrotik_standard_io.php';
require_once __DIR__ . '/../../includes/user_schema.php';
require_once __DIR__ . '/../../includes/radius_sync.php';
require_once __DIR__ . '/../../includes/recharge_preview_service.php';
require_once __DIR__ . '/../../includes/operation_history.php';
require_once __DIR__ . '/../../includes/mikrotik_standard_import_radius.php';
require_once __DIR__ . '/../../includes/admin_mikrotik_standard_runtime.php';

header('Content-Type: application/json; charset=UTF-8');

function mikrotikImportStandardFail(int $statusCode, string $message): void
{
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode([
        'success' => false,
        'message' => $message,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function require_valid_csrf(): void
{
    $token = trim((string)($_POST['csrf_token'] ?? ''));
    if ($token === '' || !hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        throw new RuntimeException('CSRF invalide');
    }
}

function findLocalProfileByName(PDO $pdo, string $name): ?array
{
    $stmt = $pdo->prepare('
        SELECT *
        FROM profiles
        WHERE LOWER(name) = LOWER(?)
        LIMIT 1
    ');
    $stmt->execute([$name]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return is_array($row) ? $row : null;
}

function upsertImportedProfile(PDO $pdo, array $profile, array $nasContext, string $mode): array
{
    $existing = findLocalProfileByName($pdo, (string)$profile['name']);
    if ($existing && $mode === 'skip') {
        return [
            'action' => 'skipped',
            'profile_id' => (int)$existing['id'],
            'profile_name' => (string)$existing['name'],
        ];
    }

    if ($existing) {
        $stmt = $pdo->prepare('
            UPDATE profiles
            SET
                service_type = ?,
                rate_limit = ?,
                session_timeout = ?,
                idle_timeout = ?,
                validity_time = ?,
                data_quota_mb = ?,
                expired_mode = ?,
                price = ?,
                selling_price = ?,
                lock_user = ?,
                parent_queue = ?,
                validity_routeros = ?,
                simultaneous_use = ?,
                ip_pool = ?,
                account_type = ?
            WHERE id = ?
        ');
        $stmt->execute([
            $profile['service_type'],
            $profile['rate_limit'],
            $profile['session_timeout'],
            $profile['idle_timeout'],
            $profile['validity_time'],
            $profile['data_quota_mb'],
            $profile['expired_mode'],
            $profile['price'],
            $profile['selling_price'],
            $profile['lock_user'],
            $profile['parent_queue'],
            $profile['validity_routeros'],
            $profile['simultaneous_use'],
            $profile['ip_pool'],
            $profile['account_type'],
            (int)$existing['id'],
        ]);

        updateProfileToNasBackend($pdo, array_merge($profile, [
            'old_name' => (string)$existing['name'],
        ]), $nasContext);

        return [
            'action' => 'updated',
            'profile_id' => (int)$existing['id'],
            'profile_name' => (string)$profile['name'],
        ];
    }

    $stmt = $pdo->prepare('
        INSERT INTO profiles (
            name,
            service_type,
            rate_limit,
            session_timeout,
            idle_timeout,
            validity_time,
            data_quota_mb,
            expired_mode,
            price,
            selling_price,
            lock_user,
            parent_queue,
            validity_routeros,
            simultaneous_use,
            ip_pool,
            account_type
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ');
    $stmt->execute([
        $profile['name'],
        $profile['service_type'],
        $profile['rate_limit'],
        $profile['session_timeout'],
        $profile['idle_timeout'],
        $profile['validity_time'],
        $profile['data_quota_mb'],
        $profile['expired_mode'],
        $profile['price'],
        $profile['selling_price'],
        $profile['lock_user'],
        $profile['parent_queue'],
        $profile['validity_routeros'],
        $profile['simultaneous_use'],
        $profile['ip_pool'],
        $profile['account_type'],
    ]);

    syncProfileToNasBackend($pdo, $profile, $nasContext);

    return [
        'action' => 'created',
        'profile_id' => (int)$pdo->lastInsertId(),
        'profile_name' => (string)$profile['name'],
    ];
}

function findLocalUserByUsername(PDO $pdo, string $username): ?array
{
    $stmt = $pdo->prepare('
        SELECT *
        FROM users
        WHERE username = ?
        LIMIT 1
    ');
    $stmt->execute([$username]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return is_array($row) ? $row : null;
}

function buildImportedRadiusUserPayload(PDO $pdo, array $user, int $profileId, int $nasId): array
{
    $profileStmt = $pdo->prepare('
        SELECT name, rate_limit, idle_timeout, simultaneous_use
        FROM profiles
        WHERE id = ?
        LIMIT 1
    ');
    $profileStmt->execute([$profileId]);
    $profile = $profileStmt->fetch(PDO::FETCH_ASSOC);
    if (!$profile) {
        throw new RuntimeException('Profil cible introuvable.');
    }

    return [
        'db' => [
            'username' => $user['username'],
            'password' => $user['password'],
            'nas_id' => $nasId,
            'profile_id' => $profileId,
            'session_timeout' => $user['remaining_seconds'],
            'data_limit' => $user['remaining_megabytes'],
            'current_credit_time' => $user['remaining_seconds'] ?? 0,
            'current_credit_data' => $user['remaining_bytes'] ?? 0,
            'imported_session_total_seconds' => $user['imported_session_total_seconds'],
            'imported_data_consumed_bytes' => $user['imported_data_consumed_bytes'],
            'status' => $user['status'],
            'expiration_date' => $user['expiration_date'],
        ],
        'sync' => [
            'groupname' => (string)$profile['name'],
            'payload' => [
                'username' => $user['username'],
                'password' => $user['password'],
                'status' => $user['status'],
                'rate_limit' => trim((string)($profile['rate_limit'] ?? '')) ?: null,
                'session_timeout' => $user['remaining_seconds'],
                'simultaneous_use' => max(0, (int)($profile['simultaneous_use'] ?? 0)),
                'idle_timeout' => max(0, (int)($profile['idle_timeout'] ?? 0)),
                'data_limit' => $user['remaining_megabytes'],
                'expiration_date' => $user['expiration_date'],
            ],
        ],
    ];
}

function insertImportedUser(PDO $pdo, array $db): int
{
    $stmt = $pdo->prepare('
        INSERT INTO users (
            username,
            password,
            nas_id,
            profile_id,
            session_timeout,
            data_limit,
            current_credit_time,
            current_credit_data,
            imported_session_total_seconds,
            imported_data_consumed_bytes,
            status,
            expiration_date
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ');
    $stmt->execute([
        $db['username'],
        $db['password'],
        $db['nas_id'],
        $db['profile_id'],
        $db['session_timeout'],
        $db['data_limit'],
        $db['current_credit_time'],
        $db['current_credit_data'],
        $db['imported_session_total_seconds'],
        $db['imported_data_consumed_bytes'],
        $db['status'],
        $db['expiration_date'],
    ]);

    return (int)$pdo->lastInsertId();
}

function updateImportedUser(PDO $pdo, int $userId, array $db): void
{
    $stmt = $pdo->prepare('
        UPDATE users
        SET
            password = ?,
            nas_id = ?,
            profile_id = ?,
            session_timeout = ?,
            data_limit = ?,
            current_credit_time = ?,
            current_credit_data = ?,
            imported_session_total_seconds = ?,
            imported_data_consumed_bytes = ?,
            status = ?,
            expiration_date = ?
        WHERE id = ?
    ');
    $stmt->execute([
        $db['password'],
        $db['nas_id'],
        $db['profile_id'],
        $db['session_timeout'],
        $db['data_limit'],
        $db['current_credit_time'],
        $db['current_credit_data'],
        $db['imported_session_total_seconds'],
        $db['imported_data_consumed_bytes'],
        $db['status'],
        $db['expiration_date'],
        $userId,
    ]);
}

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    mikrotikImportStandardFail(403, 'Unauthorized');
}

if (!isAdministrator()) {
    mikrotikImportStandardFail(403, 'Accès réservé à l administrateur');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    mikrotikImportStandardFail(405, 'Methode non autorisee.');
}

try {
    require_valid_csrf();
    ensureUsersExtendedSchema($pdo);
    ensureUserCounterBaselinesSchema($pdo);

    $deviceId = trim((string)($_POST['device_id'] ?? ''));
    $mode = mikrotikStandardNormalizeImportMode((string)($_POST['mode'] ?? 'skip'));
    $includeSensitive = mikrotikStandardNormalizeSensitiveImport((string)($_POST['include_sensitive'] ?? '0'));

    if ($deviceId === '') {
        throw new RuntimeException('Serveur cible requis.');
    }

    $upload = $_FILES['standard_file'] ?? null;
    if (!is_array($upload) || (int)($upload['error'] ?? 0) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Fichier JSON standard requis.');
    }

    $store = loadDeviceStore();
    $device = findDeviceById($store, $deviceId);
    if (!is_array($device)) {
        throw new RuntimeException('Serveur cible introuvable.');
    }

    $resolvedDeviceType = normalizeDeviceType((string)($device['type'] ?? ''));
    $backendContext = resolveRechargeBackendContext($pdo, $device);
    $businessSource = (string)($backendContext['business_source'] ?? '');
    $nasContext = $backendContext['nas_context'] ?? null;
    $nasId = (int)($nasContext['nas_id'] ?? 0);
    $resolvedNasType = strtolower(trim((string)($nasContext['nas_type'] ?? '')));
    $resolvedDeviceId = trim((string)($device['id'] ?? ''));

    if ($resolvedDeviceId === '' || $resolvedDeviceId !== $deviceId) {
        throw new RuntimeException('Incoherence de resolution du serveur cible.');
    }

    if ($resolvedDeviceType === 'mikrotik' && $businessSource !== 'mikrotik_local') {
        throw new RuntimeException(
            'Le device cible est de type MikroTik, mais le business_source resolu est "'
            . $businessSource
            . '" au lieu de "mikrotik_local".'
        );
    }

    if ($businessSource === 'mikrotik_local') {
        $adminMikrotikContext = adminMikrotikStandardContextFromDevice($device);

        $raw = (string)file_get_contents((string)($upload['tmp_name'] ?? ''));
        $payload = json_decode($raw, true);
        if (!is_array($payload)) {
            throw new RuntimeException('JSON standard invalide.');
        }

        $document = mikrotikStandardParseImportDocument($payload);

        $profileSummary = [
            'created' => 0,
            'updated' => 0,
            'skipped' => 0,
            'protected' => 0,
            'errors' => [],
        ];

        $api = adminMikrotikStandardConnectDevice($device);
        try {
            foreach ($document['profiles'] as $profileRow) {
                try {
                    if (!is_array($profileRow)) {
                        throw new RuntimeException('Profil standard invalide.');
                    }

                    $profileName = trim((string)($profileRow['name'] ?? ''));
                    if ($profileName === '') {
                        throw new RuntimeException('Profil sans nom.');
                    }

                    if (mikrotikStandardIsProtectedProfile($profileName)) {
                        $profileSummary['protected']++;
                        continue;
                    }

                    $backendSpecificProfileRow = mikrotikStandardFindBackendSpecificProfileRow($payload, $profileName);
                    $normalizedProfile = adminMikrotikStandardBuildProfilePayload($profileRow, $backendSpecificProfileRow);
                    $action = adminMikrotikStandardUpsertProfile($api, array_merge($normalizedProfile, [
                        'old_name' => $profileName,
                    ]), $mode, $device);

                    if ($action === 'created') {
                        $profileSummary['created']++;
                    } elseif ($action === 'updated') {
                        $profileSummary['updated']++;
                    } else {
                        $profileSummary['skipped']++;
                    }
                } catch (Throwable $e) {
                    $profileSummary['errors'][] = $e->getMessage();
                }
            }

            $userSummary = [
                'created' => 0,
                'updated' => 0,
                'skipped' => 0,
                'sensitive_skipped' => 0,
                'invalid_skipped' => 0,
                'errors' => [],
            ];

            foreach ($document['users'] as $userRow) {
                try {
                    if (!is_array($userRow)) {
                        throw new RuntimeException('Utilisateur standard invalide.');
                    }

                    $username = trim((string)($userRow['username'] ?? ''));
                    if ($username === '') {
                        throw new RuntimeException('Utilisateur sans username.');
                    }

                    if (mikrotikStandardIsImplicitTrialUsername($username)) {
                        $userSummary['skipped']++;
                        continue;
                    }

                    if (!$includeSensitive && mikrotikStandardIsSensitiveUsername($username)) {
                        $userSummary['sensitive_skipped']++;
                        continue;
                    }

                    if (trim((string)($userRow['profile'] ?? '')) === '') {
                        $userSummary['invalid_skipped']++;
                        continue;
                    }

                    $backendSpecificUserRow = adminMikrotikStandardFindBackendSpecificUserRow($payload, $username);
                    $normalizedUser = adminMikrotikStandardBuildUserPayload($userRow, $backendSpecificUserRow);
                    $action = adminMikrotikStandardUpsertUser($api, $normalizedUser, $mode);
                    upsertUserCounterBaseline(
                        $pdo,
                        $deviceId,
                        $normalizedUser['username'],
                        $normalizedUser['imported_session_total_seconds'],
                        $normalizedUser['imported_data_consumed_bytes']
                    );

                    if ($action === 'created') {
                        $userSummary['created']++;
                    } elseif ($action === 'updated') {
                        $userSummary['updated']++;
                    } else {
                        $userSummary['skipped']++;
                    }
                } catch (Throwable $e) {
                    $userSummary['errors'][] = $e->getMessage();
                }
            }
        } finally {
            $api->disconnect();
        }

        $createdOrUpdated = $profileSummary['created'] + $profileSummary['updated'] + $userSummary['created'] + $userSummary['updated'];
        if ($createdOrUpdated === 0) {
            throw new RuntimeException(adminMikrotikStandardNoWriteMessage($profileSummary, $userSummary));
        }

        recordOperationHistory($pdo, [
            'operation_scope' => 'admin',
            'operation_type' => 'standard_import_mikrotik_local_from_mikrotik',
            'actor_username' => (string)($_SESSION['username'] ?? ''),
            'actor_role' => (string)($_SESSION['user_role'] ?? 'administrator'),
            'target_type' => 'device',
            'target_name' => (string)($device['name'] ?? $deviceId),
            'target_ref' => $deviceId,
            'device_id' => $deviceId,
            'summary' => 'Import standard MikroTik vers cible MikroTik locale',
            'details_json' => [
                'mode' => $mode,
                'include_sensitive' => $includeSensitive,
                'resolved_device_type' => $resolvedDeviceType,
                'resolved_business_source' => $businessSource,
                'resolved_target' => [
                    'device_id' => $deviceId,
                    'host' => (string)($device['host'] ?? ''),
                    'nas_id' => (int)($adminMikrotikContext['nas_id'] ?? 0),
                ],
                'profiles' => $profileSummary,
                'users' => $userSummary,
            ],
        ]);

        echo json_encode([
            'success' => true,
            'message' => 'Import standard termine sur le device MikroTik cible.',
            'device_id' => $deviceId,
            'resolved_device_type' => $resolvedDeviceType,
            'resolved_business_source' => $businessSource,
            'profiles' => $profileSummary,
            'users' => $userSummary,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    if (!is_array($nasContext) || $nasId <= 0) {
        throw new RuntimeException('Le device cible ne resolve aucun NAS valide.');
    }

    if ($businessSource !== 'radius') {
        throw new RuntimeException('Le device cible ne passe pas par le backend RADIUS/OPNsense.');
    }

    if ($resolvedNasType === '') {
        throw new RuntimeException('Type NAS cible introuvable pour ce device.');
    }

    $raw = (string)file_get_contents((string)($upload['tmp_name'] ?? ''));
    $payload = json_decode($raw, true);
    if (!is_array($payload)) {
        throw new RuntimeException('JSON standard invalide.');
    }

    $document = mikrotikStandardParseRadiusImportDocumentV2($payload);

    $profileSummary = [
        'created' => 0,
        'updated' => 0,
        'skipped' => 0,
        'protected' => 0,
        'errors' => [],
    ];

    $profileIdsByName = [];

    $pdo->beginTransaction();

    foreach ($document['profiles'] as $profileRow) {
        try {
            if (!is_array($profileRow)) {
                throw new RuntimeException('Profil standard invalide.');
            }

            $profileName = trim((string)($profileRow['name'] ?? ''));
            if ($profileName === '') {
                throw new RuntimeException('Profil sans nom.');
            }

            if (mikrotikStandardIsProtectedProfile($profileName)) {
                $existing = findLocalProfileByName($pdo, $profileName);
                if ($existing) {
                    $profileIdsByName[strtolower($profileName)] = (int)$existing['id'];
                }
                $profileSummary['protected']++;
                continue;
            }

            $backendSpecificRow = mikrotikStandardFindBackendSpecificProfileRow($document['payload'], $profileName);
            $normalizedProfile = mikrotikRadiusImportNormalizeProfileRow($profileRow, $backendSpecificRow);
            $result = upsertImportedProfile($pdo, $normalizedProfile, $nasContext, $mode);
            $profileIdsByName[strtolower($result['profile_name'])] = (int)$result['profile_id'];

            if ($result['action'] === 'created') {
                $profileSummary['created']++;
            } elseif ($result['action'] === 'updated') {
                $profileSummary['updated']++;
            } else {
                $profileSummary['skipped']++;
            }
        } catch (Throwable $e) {
            $profileSummary['errors'][] = $e->getMessage();
        }
    }

    foreach ($document['profiles'] as $profileRow) {
        if (!is_array($profileRow)) {
            continue;
        }
        $profileName = trim((string)($profileRow['name'] ?? ''));
        if ($profileName === '') {
            continue;
        }
        if (!isset($profileIdsByName[strtolower($profileName)])) {
            $existing = findLocalProfileByName($pdo, $profileName);
            if ($existing) {
                $profileIdsByName[strtolower($profileName)] = (int)$existing['id'];
            }
        }
    }

    $userSummary = [
        'created' => 0,
        'updated' => 0,
        'skipped' => 0,
        'sensitive_skipped' => 0,
        'invalid_skipped' => 0,
        'errors' => [],
    ];

    foreach ($document['users'] as $userRow) {
        try {
            if (!is_array($userRow)) {
                throw new RuntimeException('Utilisateur standard invalide.');
            }

            $normalizedUser = mikrotikRadiusImportNormalizeUserRow($userRow);

            if (mikrotikStandardIsImplicitTrialUsername($normalizedUser['username'])) {
                $userSummary['skipped']++;
                continue;
            }

            if (!$includeSensitive && mikrotikStandardIsSensitiveUsername($normalizedUser['username'])) {
                $userSummary['sensitive_skipped']++;
                continue;
            }

            $profileKey = strtolower($normalizedUser['profile']);
            if (!isset($profileIdsByName[$profileKey])) {
                $userSummary['invalid_skipped']++;
                continue;
            }

            $profileId = (int)$profileIdsByName[$profileKey];
            $payloads = buildImportedRadiusUserPayload($pdo, $normalizedUser, $profileId, $nasId);
            $existingUser = findLocalUserByUsername($pdo, $normalizedUser['username']);

            if ($existingUser && $mode === 'skip') {
                $userSummary['skipped']++;
                continue;
            }

            if ($existingUser) {
                updateImportedUser($pdo, (int)$existingUser['id'], $payloads['db']);
                updateUserToNasBackend($pdo, array_merge($payloads['sync']['payload'], [
                    'old_username' => (string)$existingUser['username'],
                ]), $payloads['sync']['groupname'], $nasContext);
                $userSummary['updated']++;
            } else {
                insertImportedUser($pdo, $payloads['db']);
                syncUserToNasBackend($pdo, $payloads['sync']['payload'], $payloads['sync']['groupname'], $nasContext);
                $userSummary['created']++;
            }
        } catch (Throwable $e) {
            $userSummary['errors'][] = $e->getMessage();
        }
    }

    $pdo->commit();

    $createdOrUpdated = $profileSummary['created'] + $profileSummary['updated'] + $userSummary['created'] + $userSummary['updated'];
    $errorCount = count($profileSummary['errors']) + count($userSummary['errors']);
    if ($createdOrUpdated === 0 && $errorCount > 0) {
        $firstError = $profileSummary['errors'][0] ?? $userSummary['errors'][0] ?? 'Erreur interne inconnue.';
        throw new RuntimeException('Aucun element importe. Premier erreur: ' . $firstError);
    }

    recordOperationHistory($pdo, [
        'operation_scope' => 'admin',
        'operation_type' => 'standard_import_radius_from_mikrotik',
        'actor_username' => (string)($_SESSION['username'] ?? ''),
        'actor_role' => (string)($_SESSION['user_role'] ?? 'administrator'),
        'target_type' => 'device',
        'target_name' => (string)($device['name'] ?? $deviceId),
        'target_ref' => $deviceId,
        'device_id' => $deviceId,
        'summary' => 'Import standard MikroTik vers backend RADIUS/OPNsense',
        'details_json' => [
            'resolved_device_type' => $resolvedDeviceType,
            'resolved_business_source' => $businessSource,
            'resolved_nas_type' => $resolvedNasType,
            'resolved_nas_id' => $nasId,
            'mode' => $mode,
            'include_sensitive' => $includeSensitive,
            'profiles' => $profileSummary,
            'users' => $userSummary,
        ],
    ]);

    echo json_encode([
        'success' => true,
        'message' => 'Import standard termine sur le device cible.',
        'device_id' => $deviceId,
        'resolved_nas_id' => $nasId,
        'resolved_nas_type' => $resolvedNasType,
        'resolved_business_source' => $businessSource,
        'profiles' => $profileSummary,
        'users' => $userSummary,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
} catch (Throwable $e) {
    if ($pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    mikrotikImportStandardFail(500, $e->getMessage());
}
