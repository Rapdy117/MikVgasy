<?php

require_once __DIR__ . '/nas_resolver.php';
require_once __DIR__ . '/profile_schema.php';
require_once __DIR__ . '/user_schema.php';
require_once __DIR__ . '/radius_sync.php';

function radiusStandardNormalizeImportMode(?string $raw): string
{
    return strtolower(trim((string)$raw)) === 'replace' ? 'replace' : 'skip';
}

function radiusStandardNormalizeSensitiveImport(?string $raw): bool
{
    return in_array(strtolower(trim((string)$raw)), ['1', 'true', 'yes', 'on'], true);
}

function radiusStandardIsSensitiveUsername(string $username): bool
{
    return strtolower(trim($username)) === 'admin';
}

function radiusStandardIsMaskedPassword($password): bool
{
    return trim((string)$password) === '****';
}

function radiusStandardNormalizeDate(?string $raw): ?string
{
    $value = trim((string)$raw);
    if ($value === '') {
        return null;
    }

    return preg_match('/^\d{4}-\d{2}-\d{2}/', $value, $matches) ? $matches[0] : null;
}

function radiusStandardBytesToMegabytes($bytes): ?int
{
    $value = (float)$bytes;
    if ($value <= 0) {
        return null;
    }

    return (int)round($value / 1024 / 1024);
}

function radiusStandardMegabytesToBytes($megabytes): ?int
{
    $value = (int)$megabytes;
    if ($value <= 0) {
        return null;
    }

    return $value * 1024 * 1024;
}

function radiusStandardResolveTarget(PDO $pdo, string $deviceId): array
{
    $deviceId = trim($deviceId);
    if ($deviceId === '') {
        throw new RuntimeException('Device OPNsense / RADIUS obligatoire.');
    }

    $nasContext = resolveNasContextFromInputs($pdo, null, $deviceId);
    $nasType = normalizeNasType((string)($nasContext['nas_type'] ?? ''));
    $businessSource = nasContextRequireBusinessSource($nasContext);
    if ($businessSource !== 'radius' || !in_array($nasType, ['opnsense', 'radius'], true)) {
        throw new RuntimeException('Le device cible ne correspond pas au backend OPNsense / RADIUS.');
    }

    $device = $nasContext['device'] ?? null;
    if (!is_array($device)) {
        throw new RuntimeException('Device cible introuvable.');
    }

    return [
        'device' => $device,
        'nas_context' => $nasContext,
        'nas_type' => $nasType,
        'source_backend' => $nasType === 'opnsense' ? 'opnsense' : 'radius',
    ];
}

function radiusStandardFetchRows(PDO $pdo, string $sql, array $params = []): array
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function radiusStandardGroupReplyRows(PDO $pdo, array $groupnames = []): array
{
    if ($groupnames === []) {
        return radiusStandardFetchRows(
            $pdo,
            'SELECT groupname, attribute, op, value FROM radgroupreply ORDER BY groupname, attribute, id'
        );
    }

    $placeholders = implode(',', array_fill(0, count($groupnames), '?'));

    return radiusStandardFetchRows(
        $pdo,
        "SELECT groupname, attribute, op, value FROM radgroupreply WHERE groupname IN ($placeholders) ORDER BY groupname, attribute, id",
        $groupnames
    );
}

function radiusStandardUserRadiusRows(PDO $pdo, array $usernames = []): array
{
    $tables = [
        'radcheck' => 'SELECT username, attribute, op, value FROM radcheck',
        'radreply' => 'SELECT username, attribute, op, value FROM radreply',
        'radusergroup' => 'SELECT username, groupname, priority FROM radusergroup',
    ];

    $result = [];
    foreach ($tables as $name => $baseSql) {
        if ($usernames === []) {
            $result[$name] = radiusStandardFetchRows($pdo, $baseSql . ' ORDER BY username, id');
            continue;
        }

        $placeholders = implode(',', array_fill(0, count($usernames), '?'));
        $orderColumn = $name === 'radusergroup' ? 'priority' : 'id';
        $result[$name] = radiusStandardFetchRows(
            $pdo,
            $baseSql . " WHERE username IN ($placeholders) ORDER BY username, $orderColumn",
            $usernames
        );
    }

    return $result;
}

function radiusStandardIndexAttributes(array $rows, string $ownerKey): array
{
    $indexed = [];
    foreach ($rows as $row) {
        $owner = trim((string)($row[$ownerKey] ?? ''));
        $attribute = trim((string)($row['attribute'] ?? ''));
        if ($owner === '' || $attribute === '') {
            continue;
        }

        $indexed[strtolower($owner)][$attribute] = (string)($row['value'] ?? '');
    }

    return $indexed;
}

function radiusStandardRateLimitFromAttributes(array $attributes): string
{
    $up = trim((string)($attributes['WISPr-Bandwidth-Max-Up'] ?? ''));
    $down = trim((string)($attributes['WISPr-Bandwidth-Max-Down'] ?? ''));
    if ($up === '' || $down === '') {
        return '';
    }

    return $up . '/' . $down;
}

function radiusStandardCommonProfileFromBusiness(array $profile): array
{
    $dataQuotaMb = isset($profile['data_quota_mb']) && (int)$profile['data_quota_mb'] > 0
        ? (int)$profile['data_quota_mb']
        : null;

    return [
        'name' => trim((string)($profile['name'] ?? '')),
        'rate_limit' => trim((string)($profile['rate_limit'] ?? '')),
        'shared_users' => max(0, (int)($profile['simultaneous_use'] ?? 0)),
        'session_timeout' => isset($profile['session_timeout']) && (int)$profile['session_timeout'] > 0 ? (int)$profile['session_timeout'] : null,
        'validity' => trim((string)($profile['validity_routeros'] ?? '')),
        'validity_seconds' => isset($profile['validity_time']) && (int)$profile['validity_time'] > 0 ? (int)$profile['validity_time'] : null,
        'data_quota_mb' => $dataQuotaMb,
        'expired_mode' => trim((string)($profile['expired_mode'] ?? 'none')) ?: 'none',
        'price' => isset($profile['price']) && $profile['price'] !== null ? trim((string)$profile['price']) : '',
        'selling_price' => isset($profile['selling_price']) && $profile['selling_price'] !== null ? trim((string)$profile['selling_price']) : '',
    ];
}

function radiusStandardCommonProfileFromRadius(string $groupname, array $attributes): array
{
    return [
        'name' => $groupname,
        'rate_limit' => radiusStandardRateLimitFromAttributes($attributes),
        'shared_users' => isset($attributes['Simultaneous-Use']) ? max(0, (int)$attributes['Simultaneous-Use']) : 0,
        'session_timeout' => isset($attributes['Session-Timeout']) ? max(0, (int)$attributes['Session-Timeout']) : null,
        'validity' => '',
        'validity_seconds' => null,
        'data_quota_mb' => isset($attributes['Max-Octets']) ? radiusStandardBytesToMegabytes($attributes['Max-Octets']) : null,
        'expired_mode' => 'none',
        'price' => '',
        'selling_price' => '',
    ];
}

function radiusStandardCommonUserFromBusiness(array $user): array
{
    $dataBytes = isset($user['current_credit_data'])
        ? max(0, (int)$user['current_credit_data'])
        : radiusStandardMegabytesToBytes($user['data_limit'] ?? 0);

    return [
        'username' => trim((string)($user['username'] ?? '')),
        'password' => (string)($user['password'] ?? ''),
        'profile' => trim((string)($user['profile_name'] ?? '')),
        'status_effective' => trim((string)($user['status'] ?? 'active')) ?: 'active',
        'expiration_date' => radiusStandardNormalizeDate((string)($user['expiration_date'] ?? '')),
        'session_timeout' => isset($user['current_credit_time']) && (int)$user['current_credit_time'] > 0 ? (int)$user['current_credit_time'] : null,
        'data_limit' => $dataBytes,
        'session_total_seconds' => max(0, (int)($user['imported_session_total_seconds'] ?? 0)),
        'data_consumed_bytes' => max(0, (int)($user['imported_data_consumed_bytes'] ?? 0)),
    ];
}

function radiusStandardCommonUserFromRadius(string $username, array $groupRow, array $checkAttributes, array $replyAttributes): array
{
    $status = strtolower(trim((string)($checkAttributes['Auth-Type'] ?? ''))) === 'reject' ? 'disabled' : 'active';

    return [
        'username' => $username,
        'password' => (string)($checkAttributes['Cleartext-Password'] ?? ''),
        'profile' => trim((string)($groupRow['groupname'] ?? '')),
        'status_effective' => $status,
        'expiration_date' => radiusStandardNormalizeDate((string)($replyAttributes['Expiration'] ?? '')),
        'session_timeout' => isset($replyAttributes['Session-Timeout']) ? max(0, (int)$replyAttributes['Session-Timeout']) : null,
        'data_limit' => isset($replyAttributes['Max-Octets']) ? max(0, (int)$replyAttributes['Max-Octets']) : null,
        'session_total_seconds' => 0,
        'data_consumed_bytes' => 0,
    ];
}

function buildRadiusStandardExportDocument(PDO $pdo, array $nasContext): array
{
    $nasType = normalizeNasType((string)($nasContext['nas_type'] ?? ''));
    if (!in_array($nasType, ['opnsense', 'radius'], true)) {
        throw new RuntimeException('Type NAS non supporte pour export standard RADIUS.');
    }

    return $nasType === 'opnsense'
        ? buildOpnsenseStandardExportDocument($pdo, $nasContext)
        : buildPureRadiusStandardExportDocument($pdo, $nasContext);
}

function buildOpnsenseStandardExportDocument(PDO $pdo, array $nasContext): array
{
    ensureUsersExtendedSchema($pdo);
    ensureProfilesExtendedSchema($pdo);

    $nasId = (int)($nasContext['nas_id'] ?? 0);
    if ($nasId <= 0) {
        throw new RuntimeException('NAS cible introuvable pour export OPNsense.');
    }

    $profileRows = radiusStandardFetchRows($pdo, 'SELECT * FROM profiles ORDER BY name');
    $userRows = radiusStandardFetchRows(
        $pdo,
        'SELECT u.*, p.name AS profile_name FROM users u LEFT JOIN profiles p ON p.id = u.profile_id WHERE u.nas_id = ? ORDER BY u.username',
        [$nasId]
    );
    $profileNames = [];
    foreach ($profileRows as $profileRow) {
        $name = trim((string)($profileRow['name'] ?? ''));
        if ($name !== '') {
            $profileNames[] = $name;
        }
    }
    $usernames = [];
    foreach ($userRows as $userRow) {
        $username = trim((string)($userRow['username'] ?? ''));
        if ($username !== '') {
            $usernames[] = $username;
        }
    }

    $commonProfiles = array_values(array_filter(array_map('radiusStandardCommonProfileFromBusiness', $profileRows), static function (array $row): bool {
        return trim((string)($row['name'] ?? '')) !== '';
    }));
    $commonUsers = array_values(array_filter(array_map('radiusStandardCommonUserFromBusiness', $userRows), static function (array $row): bool {
        return trim((string)($row['username'] ?? '')) !== '';
    }));

    return [
        'format' => 'radius-manager-standard',
        'version' => 2,
        'source_backend' => 'opnsense',
        'exported_at' => gmdate('Y-m-d\TH:i:s\Z'),
        'profiles' => $commonProfiles,
        'users' => $commonUsers,
        'backend_specific' => [
            'opnsense' => [
                'profiles' => $profileRows,
                'users' => $userRows,
            ],
            'radius' => [
                'radgroupreply' => radiusStandardGroupReplyRows($pdo, array_values(array_unique($profileNames))),
                'users' => radiusStandardUserRadiusRows($pdo, array_values(array_unique($usernames))),
            ],
        ],
    ];
}

function buildPureRadiusStandardExportDocument(PDO $pdo, array $nasContext): array
{
    $groupRows = radiusStandardGroupReplyRows($pdo);
    $radiusUsers = radiusStandardUserRadiusRows($pdo);
    $groupAttributes = radiusStandardIndexAttributes($groupRows, 'groupname');
    foreach (($radiusUsers['radusergroup'] ?? []) as $groupRow) {
        $groupname = trim((string)($groupRow['groupname'] ?? ''));
        if ($groupname !== '' && !isset($groupAttributes[strtolower($groupname)])) {
            $groupAttributes[strtolower($groupname)] = [];
        }
    }

    $commonProfiles = [];
    foreach ($groupAttributes as $groupKey => $attributes) {
        $groupname = '';
        foreach ($groupRows as $row) {
            if (strtolower(trim((string)($row['groupname'] ?? ''))) === $groupKey) {
                $groupname = trim((string)$row['groupname']);
                break;
            }
        }
        if ($groupname === '') {
            foreach (($radiusUsers['radusergroup'] ?? []) as $row) {
                if (strtolower(trim((string)($row['groupname'] ?? ''))) === $groupKey) {
                    $groupname = trim((string)$row['groupname']);
                    break;
                }
            }
        }
        if ($groupname !== '') {
            $commonProfiles[] = radiusStandardCommonProfileFromRadius($groupname, $attributes);
        }
    }

    $checkAttributes = radiusStandardIndexAttributes($radiusUsers['radcheck'] ?? [], 'username');
    $replyAttributes = radiusStandardIndexAttributes($radiusUsers['radreply'] ?? [], 'username');
    $commonUsers = [];
    foreach (($radiusUsers['radusergroup'] ?? []) as $groupRow) {
        $username = trim((string)($groupRow['username'] ?? ''));
        if ($username === '') {
            continue;
        }

        $userKey = strtolower($username);
        $commonUsers[] = radiusStandardCommonUserFromRadius(
            $username,
            $groupRow,
            $checkAttributes[$userKey] ?? [],
            $replyAttributes[$userKey] ?? []
        );
    }

    usort($commonProfiles, static fn(array $left, array $right): int => strcasecmp($left['name'], $right['name']));
    usort($commonUsers, static fn(array $left, array $right): int => strcasecmp($left['username'], $right['username']));

    return [
        'format' => 'radius-manager-standard',
        'version' => 2,
        'source_backend' => 'radius',
        'exported_at' => gmdate('Y-m-d\TH:i:s\Z'),
        'profiles' => $commonProfiles,
        'users' => $commonUsers,
        'backend_specific' => [
            'radius' => [
                'radgroupreply' => $groupRows,
                'users' => $radiusUsers,
            ],
        ],
    ];
}

function radiusStandardParseImportDocument(array $payload): array
{
    $format = trim((string)($payload['format'] ?? ''));
    $version = (int)($payload['version'] ?? 0);
    if ($format !== 'radius-manager-standard' || $version !== 2) {
        throw new RuntimeException('Seul le format standard v2 est accepte.');
    }

    $sourceBackend = strtolower(trim((string)($payload['source_backend'] ?? ($payload['backend'] ?? ''))));
    if (!in_array($sourceBackend, ['opnsense', 'radius', 'mikrotik'], true)) {
        throw new RuntimeException('Source standard non supportee.');
    }

    $profiles = $payload['profiles'] ?? [];
    $users = $payload['users'] ?? [];
    if (!is_array($profiles) || !is_array($users)) {
        throw new RuntimeException('Document standard invalide : profils ou utilisateurs manquants.');
    }

    return [
        'source_backend' => $sourceBackend,
        'profiles' => $profiles,
        'users' => $users,
        'backend_specific' => is_array($payload['backend_specific'] ?? null) ? $payload['backend_specific'] : [],
    ];
}

function radiusStandardNormalizeProfileForBusiness(array $profileRow): array
{
    $name = trim((string)($profileRow['name'] ?? ''));
    if ($name === '') {
        throw new RuntimeException('Profil sans nom.');
    }

    $validityTime = null;
    if (array_key_exists('validity_time', $profileRow) && $profileRow['validity_time'] !== null && $profileRow['validity_time'] !== '') {
        $validityTime = max(0, (int)$profileRow['validity_time']);
    } elseif (array_key_exists('validity_seconds', $profileRow) && $profileRow['validity_seconds'] !== null && $profileRow['validity_seconds'] !== '') {
        $validityTime = max(0, (int)$profileRow['validity_seconds']);
    }

    $sharedUsers = $profileRow['simultaneous_use'] ?? ($profileRow['shared_users'] ?? 1);

    return [
        'name' => $name,
        'service_type' => trim((string)($profileRow['service_type'] ?? 'hotspot')) ?: 'hotspot',
        'rate_limit' => trim((string)($profileRow['rate_limit'] ?? '')) ?: null,
        'session_timeout' => isset($profileRow['session_timeout']) ? max(0, (int)$profileRow['session_timeout']) : null,
        'idle_timeout' => isset($profileRow['idle_timeout']) ? max(0, (int)$profileRow['idle_timeout']) : null,
        'validity_time' => $validityTime,
        'data_quota_mb' => isset($profileRow['data_quota_mb']) ? max(0, (int)$profileRow['data_quota_mb']) : null,
        'expired_mode' => trim((string)($profileRow['expired_mode'] ?? 'none')) ?: 'none',
        'price' => trim((string)($profileRow['price'] ?? '')) !== '' ? (float)$profileRow['price'] : null,
        'selling_price' => trim((string)($profileRow['selling_price'] ?? '')) !== '' ? (float)$profileRow['selling_price'] : null,
        'lock_user' => isset($profileRow['lock_user']) ? (int)!empty($profileRow['lock_user']) : 0,
        'parent_queue' => trim((string)($profileRow['parent_queue'] ?? '')) ?: null,
        'validity_routeros' => trim((string)($profileRow['validity'] ?? ($profileRow['validity_routeros'] ?? ''))) ?: null,
        'simultaneous_use' => max(1, (int)$sharedUsers),
        'ip_pool' => trim((string)($profileRow['ip_pool'] ?? '')) ?: null,
        'account_type' => trim((string)($profileRow['account_type'] ?? 'standard')) ?: 'standard',
    ];
}

function radiusStandardRowsByName(array $rows, string $nameKey): array
{
    $indexed = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }

        $name = strtolower(trim((string)($row[$nameKey] ?? '')));
        if ($name !== '') {
            $indexed[$name] = $row;
        }
    }

    return $indexed;
}

function radiusStandardBusinessProfileRows(array $document): array
{
    $sourceBackend = (string)($document['source_backend'] ?? '');
    $commonProfiles = is_array($document['profiles'] ?? null) ? $document['profiles'] : [];
    $commonByName = radiusStandardRowsByName($commonProfiles, 'name');

    if ($sourceBackend === 'opnsense') {
        $opnsenseProfiles = $document['backend_specific']['opnsense']['profiles'] ?? [];
        if (is_array($opnsenseProfiles) && $opnsenseProfiles !== []) {
            $rows = [];
            foreach ($opnsenseProfiles as $profileRow) {
                if (!is_array($profileRow)) {
                    continue;
                }

                $name = strtolower(trim((string)($profileRow['name'] ?? '')));
                if ($name === '') {
                    continue;
                }

                $rows[] = array_merge($commonByName[$name] ?? [], $profileRow);
            }

            return $rows;
        }
    }

    if ($sourceBackend === 'mikrotik') {
        $mikrotikProfiles = $document['backend_specific']['mikrotik']['profiles'] ?? [];
        $mikrotikByName = is_array($mikrotikProfiles) ? radiusStandardRowsByName($mikrotikProfiles, 'name') : [];
        $rows = [];
        foreach ($commonProfiles as $profileRow) {
            if (!is_array($profileRow)) {
                continue;
            }

            $name = strtolower(trim((string)($profileRow['name'] ?? '')));
            $rows[] = $name !== '' && isset($mikrotikByName[$name])
                ? array_merge($profileRow, $mikrotikByName[$name])
                : $profileRow;
        }

        return $rows;
    }

    return $commonProfiles;
}

function radiusStandardBusinessUserRows(array $document): array
{
    $sourceBackend = (string)($document['source_backend'] ?? '');
    $commonUsers = is_array($document['users'] ?? null) ? $document['users'] : [];
    $commonByUsername = radiusStandardRowsByName($commonUsers, 'username');

    if ($sourceBackend === 'opnsense') {
        $opnsenseUsers = $document['backend_specific']['opnsense']['users'] ?? [];
        if (is_array($opnsenseUsers) && $opnsenseUsers !== []) {
            $rows = [];
            foreach ($opnsenseUsers as $userRow) {
                if (!is_array($userRow)) {
                    continue;
                }

                $username = strtolower(trim((string)($userRow['username'] ?? '')));
                if ($username === '') {
                    continue;
                }

                $rows[] = array_merge($commonByUsername[$username] ?? [], $userRow);
            }

            return $rows;
        }
    }

    return $commonUsers;
}

function radiusStandardFindProfileByName(PDO $pdo, string $name): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM profiles WHERE LOWER(name) = LOWER(?) LIMIT 1');
    $stmt->execute([$name]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return is_array($row) ? $row : null;
}

function radiusStandardUpsertBusinessProfile(PDO $pdo, array $profile, array $nasContext, string $mode): array
{
    $existing = radiusStandardFindProfileByName($pdo, (string)$profile['name']);
    if ($existing && $mode === 'skip') {
        return ['action' => 'skipped', 'profile_id' => (int)$existing['id'], 'profile_name' => (string)$existing['name']];
    }

    if ($existing) {
        $stmt = $pdo->prepare('
            UPDATE profiles
            SET service_type = ?, rate_limit = ?, session_timeout = ?, idle_timeout = ?, validity_time = ?,
                data_quota_mb = ?, expired_mode = ?, price = ?, selling_price = ?, lock_user = ?,
                parent_queue = ?, validity_routeros = ?, simultaneous_use = ?, ip_pool = ?, account_type = ?
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
        updateProfileToNasBackend($pdo, array_merge($profile, ['old_name' => (string)$existing['name']]), $nasContext);

        return ['action' => 'updated', 'profile_id' => (int)$existing['id'], 'profile_name' => (string)$profile['name']];
    }

    $stmt = $pdo->prepare('
        INSERT INTO profiles (
            name, service_type, rate_limit, session_timeout, idle_timeout, validity_time, data_quota_mb,
            expired_mode, price, selling_price, lock_user, parent_queue, validity_routeros,
            simultaneous_use, ip_pool, account_type
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

    return ['action' => 'created', 'profile_id' => (int)$pdo->lastInsertId(), 'profile_name' => (string)$profile['name']];
}

function radiusStandardFindUserByUsername(PDO $pdo, string $username): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM users WHERE username = ? LIMIT 1');
    $stmt->execute([$username]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return is_array($row) ? $row : null;
}

function radiusStandardNormalizeStatus(?string $raw): string
{
    $status = strtolower(trim((string)$raw));

    return in_array($status, ['active', 'disabled', 'expired'], true) ? $status : 'active';
}

function radiusStandardUpsertBusinessUser(PDO $pdo, array $userRow, int $profileId, int $nasId, array $nasContext, string $mode): string
{
    $username = trim((string)($userRow['username'] ?? ''));
    if ($username === '') {
        throw new RuntimeException('Utilisateur sans username.');
    }

    $profileStmt = $pdo->prepare('SELECT name, rate_limit, idle_timeout, simultaneous_use FROM profiles WHERE id = ? LIMIT 1');
    $profileStmt->execute([$profileId]);
    $profile = $profileStmt->fetch(PDO::FETCH_ASSOC);
    if (!is_array($profile)) {
        throw new RuntimeException('Profil cible introuvable.');
    }

    $existing = radiusStandardFindUserByUsername($pdo, $username);
    if ($existing && $mode === 'skip') {
        return 'skipped';
    }

    if (array_key_exists('current_credit_time', $userRow) && $userRow['current_credit_time'] !== null && $userRow['current_credit_time'] !== '') {
        $sessionTimeout = max(0, (int)$userRow['current_credit_time']);
    } elseif (array_key_exists('session_timeout', $userRow) && $userRow['session_timeout'] !== null && $userRow['session_timeout'] !== '') {
        $sessionTimeout = max(0, (int)$userRow['session_timeout']);
    } else {
        $sessionTimeout = null;
    }

    if (array_key_exists('current_credit_data', $userRow) && $userRow['current_credit_data'] !== null && $userRow['current_credit_data'] !== '') {
        $dataBytes = max(0, (int)$userRow['current_credit_data']);
    } else {
        $dataBytes = isset($userRow['data_limit']) ? max(0, (int)$userRow['data_limit']) : 0;
    }

    $dataMegabytes = $dataBytes > 0 ? radiusStandardBytesToMegabytes($dataBytes) : null;
    $status = radiusStandardNormalizeStatus((string)($userRow['status_effective'] ?? ($userRow['status'] ?? 'active')));
    $expirationDate = radiusStandardNormalizeDate((string)($userRow['expiration_date'] ?? ''));
    $password = (string)($userRow['password'] ?? '');
    if (radiusStandardIsMaskedPassword($password)) {
        if (!$existing) {
            throw new RuntimeException('Mot de passe masque pour nouvel utilisateur: ' . $username);
        }
        $password = (string)($existing['password'] ?? '');
    }

    $dbPayload = [
        $password,
        $nasId,
        $profileId,
        $sessionTimeout,
        $dataMegabytes,
        $sessionTimeout ?? 0,
        $dataBytes,
        max(0, (int)($userRow['session_total_seconds'] ?? 0)),
        max(0, (int)($userRow['data_consumed_bytes'] ?? 0)),
        $status,
        $expirationDate,
    ];

    if ($existing) {
        $stmt = $pdo->prepare('
            UPDATE users
            SET password = ?, nas_id = ?, profile_id = ?, session_timeout = ?, data_limit = ?,
                current_credit_time = ?, current_credit_data = ?, imported_session_total_seconds = ?,
                imported_data_consumed_bytes = ?, status = ?, expiration_date = ?
            WHERE id = ?
        ');
        $stmt->execute(array_merge($dbPayload, [(int)$existing['id']]));
        $action = 'updated';
    } else {
        $stmt = $pdo->prepare('
            INSERT INTO users (
                username, password, nas_id, profile_id, session_timeout, data_limit, current_credit_time,
                current_credit_data, imported_session_total_seconds, imported_data_consumed_bytes, status, expiration_date
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ');
        $stmt->execute(array_merge([$username], $dbPayload));
        $action = 'created';
    }

    $syncPayload = [
        'username' => $username,
        'password' => $password,
        'status' => $status,
        'rate_limit' => trim((string)($profile['rate_limit'] ?? '')) ?: null,
        'session_timeout' => $sessionTimeout,
        'simultaneous_use' => max(0, (int)($profile['simultaneous_use'] ?? 0)),
        'idle_timeout' => max(0, (int)($profile['idle_timeout'] ?? 0)),
        'data_limit' => $dataMegabytes,
        'expiration_date' => $expirationDate,
    ];
    if ($existing) {
        $syncPayload['old_username'] = (string)$existing['username'];
        updateUserToNasBackend($pdo, $syncPayload, (string)$profile['name'], $nasContext);
    } else {
        syncUserToNasBackend($pdo, $syncPayload, (string)$profile['name'], $nasContext);
    }

    return $action;
}

function radiusStandardImportOpnsense(PDO $pdo, array $document, array $nasContext, string $mode, bool $includeSensitive): array
{
    $nasId = (int)($nasContext['nas_id'] ?? 0);
    if ($nasId <= 0) {
        throw new RuntimeException('NAS cible introuvable pour import OPNsense.');
    }

    $profileSummary = ['created' => 0, 'updated' => 0, 'skipped' => 0, 'existing_skipped' => 0, 'errors' => []];
    $userSummary = ['created' => 0, 'updated' => 0, 'skipped' => 0, 'existing_skipped' => 0, 'sensitive_skipped' => 0, 'invalid_skipped' => 0, 'invalid_missing_profile' => 0, 'errors' => []];
    $profileIdsByName = [];
    if (($document['source_backend'] ?? '') === 'opnsense') {
        $opnsenseSpecific = $document['backend_specific']['opnsense'] ?? null;
        if (
            !is_array($opnsenseSpecific)
            || !is_array($opnsenseSpecific['profiles'] ?? null)
            || !is_array($opnsenseSpecific['users'] ?? null)
        ) {
            throw new RuntimeException('Export OPNsense invalide : base metier SQL manquante.');
        }
    }

    $profileRows = radiusStandardBusinessProfileRows($document);
    $userRows = radiusStandardBusinessUserRows($document);

    foreach ($profileRows as $profileRow) {
        try {
            if (!is_array($profileRow)) {
                throw new RuntimeException('Profil standard invalide.');
            }

            $profile = radiusStandardNormalizeProfileForBusiness($profileRow);
            $result = radiusStandardUpsertBusinessProfile($pdo, $profile, $nasContext, $mode);
            $profileIdsByName[strtolower($result['profile_name'])] = (int)$result['profile_id'];
            $profileSummary[$result['action']]++;
            if ($result['action'] === 'skipped') {
                $profileSummary['existing_skipped']++;
            }
        } catch (Throwable $exception) {
            $profileSummary['errors'][] = $exception->getMessage();
        }
    }

    foreach ($profileRows as $profileRow) {
        if (!is_array($profileRow)) {
            continue;
        }
        $profileName = trim((string)($profileRow['name'] ?? ''));
        if ($profileName === '' || isset($profileIdsByName[strtolower($profileName)])) {
            continue;
        }
        $existing = radiusStandardFindProfileByName($pdo, $profileName);
        if ($existing) {
            $profileIdsByName[strtolower($profileName)] = (int)$existing['id'];
        }
    }

    foreach ($userRows as $userRow) {
        try {
            if (!is_array($userRow)) {
                throw new RuntimeException('Utilisateur standard invalide.');
            }

            $username = trim((string)($userRow['username'] ?? ''));
            if ($username === '') {
                throw new RuntimeException('Utilisateur sans username.');
            }

            if (!$includeSensitive && radiusStandardIsSensitiveUsername($username)) {
                $userSummary['sensitive_skipped']++;
                continue;
            }

            $profileName = trim((string)($userRow['profile'] ?? ($userRow['profile_name'] ?? '')));
            $profileKey = strtolower($profileName);
            if ($profileName === '' || !isset($profileIdsByName[$profileKey])) {
                $userSummary['invalid_skipped']++;
                $userSummary['invalid_missing_profile']++;
                continue;
            }

            $userRow['profile'] = $profileName;

            $action = radiusStandardUpsertBusinessUser($pdo, $userRow, (int)$profileIdsByName[$profileKey], $nasId, $nasContext, $mode);
            $userSummary[$action]++;
            if ($action === 'skipped') {
                $userSummary['existing_skipped']++;
            }
        } catch (Throwable $exception) {
            $userSummary['errors'][] = $exception->getMessage();
        }
    }

    return ['profiles' => $profileSummary, 'users' => $userSummary];
}

function radiusStandardImportPureRadius(PDO $pdo, array $document, string $mode, bool $includeSensitive): array
{
    $profileSummary = ['created' => 0, 'updated' => 0, 'skipped' => 0, 'existing_skipped' => 0, 'errors' => []];
    $userSummary = ['created' => 0, 'updated' => 0, 'skipped' => 0, 'existing_skipped' => 0, 'sensitive_skipped' => 0, 'invalid_skipped' => 0, 'invalid_masked_password' => 0, 'errors' => []];
    $radiusSpecific = is_array($document['backend_specific']['radius'] ?? null) ? $document['backend_specific']['radius'] : [];
    $rawGroupRows = is_array($radiusSpecific['radgroupreply'] ?? null) ? $radiusSpecific['radgroupreply'] : [];
    $rawUserRows = is_array($radiusSpecific['users'] ?? null) ? $radiusSpecific['users'] : [];
    $hasRadiusProjection = $rawGroupRows !== [] || $rawUserRows !== [];
    $commonProfilesByName = radiusStandardRowsByName(is_array($document['profiles'] ?? null) ? $document['profiles'] : [], 'name');
    $commonUsersByName = radiusStandardRowsByName(is_array($document['users'] ?? null) ? $document['users'] : [], 'username');
    $profileRows = $hasRadiusProjection
        ? radiusStandardProfileRowsFromRadiusProjection($rawGroupRows, $commonProfilesByName)
        : (is_array($document['profiles'] ?? null) ? $document['profiles'] : []);
    $userRows = $hasRadiusProjection
        ? radiusStandardUserRowsFromRadiusProjection($rawUserRows, $commonUsersByName)
        : (is_array($document['users'] ?? null) ? $document['users'] : []);

    foreach ($profileRows as $profileRow) {
        try {
            if (!is_array($profileRow)) {
                throw new RuntimeException('Profil standard invalide.');
            }

            $groupname = trim((string)($profileRow['name'] ?? ''));
            if ($groupname === '') {
                throw new RuntimeException('Profil sans nom.');
            }

            $existsStmt = $pdo->prepare('SELECT COUNT(*) FROM radgroupreply WHERE groupname = ?');
            $existsStmt->execute([$groupname]);
            $exists = (int)$existsStmt->fetchColumn() > 0;
            if ($exists && $mode === 'skip') {
                $profileSummary['skipped']++;
                $profileSummary['existing_skipped']++;
                continue;
            }

            $pdo->prepare('DELETE FROM radgroupreply WHERE groupname = ?')->execute([$groupname]);
            $matchingRawRows = array_values(array_filter($rawGroupRows, static function ($row) use ($groupname): bool {
                return is_array($row) && trim((string)($row['groupname'] ?? '')) === $groupname;
            }));
            if ($matchingRawRows === []) {
                radiusStandardWriteCommonProfileToRadius($pdo, $profileRow);
            } else {
                foreach ($matchingRawRows as $row) {
                    radiusStandardInsertGroupReplyRow($pdo, $groupname, $row);
                }
            }

            $profileSummary[$exists ? 'updated' : 'created']++;
        } catch (Throwable $exception) {
            $profileSummary['errors'][] = $exception->getMessage();
        }
    }

    foreach ($userRows as $userRow) {
        try {
            if (!is_array($userRow)) {
                throw new RuntimeException('Utilisateur standard invalide.');
            }

            $username = trim((string)($userRow['username'] ?? ''));
            if ($username === '') {
                throw new RuntimeException('Utilisateur sans username.');
            }

            if (!$includeSensitive && radiusStandardIsSensitiveUsername($username)) {
                $userSummary['sensitive_skipped']++;
                continue;
            }

            if (radiusStandardIsMaskedPassword($userRow['password'] ?? '')) {
                $existingPasswordStmt = $pdo->prepare("SELECT value FROM radcheck WHERE username = ? AND attribute = 'Cleartext-Password' ORDER BY id DESC LIMIT 1");
                $existingPasswordStmt->execute([$username]);
                $existingPassword = $existingPasswordStmt->fetchColumn();
                if ($existingPassword === false || trim((string)$existingPassword) === '') {
                    $userSummary['invalid_skipped']++;
                    $userSummary['invalid_masked_password']++;
                    continue;
                }
                $userRow['password'] = (string)$existingPassword;
            }

            $existsStmt = $pdo->prepare('SELECT COUNT(*) FROM radusergroup WHERE username = ?');
            $existsStmt->execute([$username]);
            $exists = (int)$existsStmt->fetchColumn() > 0;
            if ($exists && $mode === 'skip') {
                $userSummary['skipped']++;
                $userSummary['existing_skipped']++;
                continue;
            }

            $pdo->prepare('DELETE FROM radcheck WHERE username = ?')->execute([$username]);
            $pdo->prepare('DELETE FROM radreply WHERE username = ?')->execute([$username]);
            $pdo->prepare('DELETE FROM radusergroup WHERE username = ?')->execute([$username]);
            radiusStandardWriteUserToRadius($pdo, $userRow, $rawUserRows);
            $userSummary[$exists ? 'updated' : 'created']++;
        } catch (Throwable $exception) {
            $userSummary['errors'][] = $exception->getMessage();
        }
    }

    return ['profiles' => $profileSummary, 'users' => $userSummary];
}

function radiusStandardProfileRowsFromRadiusProjection(array $rawGroupRows, array $commonProfilesByName): array
{
    $rows = [];
    foreach ($rawGroupRows as $rawRow) {
        if (!is_array($rawRow)) {
            continue;
        }

        $groupname = trim((string)($rawRow['groupname'] ?? ''));
        if ($groupname === '') {
            continue;
        }

        $key = strtolower($groupname);
        if (isset($rows[$key])) {
            continue;
        }

        $rows[$key] = array_merge($commonProfilesByName[$key] ?? [], ['name' => $groupname]);
    }

    return array_values($rows);
}

function radiusStandardUserRowsFromRadiusProjection(array $rawUserRows, array $commonUsersByName): array
{
    $rows = [];
    $radusergroup = is_array($rawUserRows['radusergroup'] ?? null) ? $rawUserRows['radusergroup'] : [];
    $radcheck = is_array($rawUserRows['radcheck'] ?? null) ? $rawUserRows['radcheck'] : [];
    $radreply = is_array($rawUserRows['radreply'] ?? null) ? $rawUserRows['radreply'] : [];

    foreach ($radusergroup as $rawRow) {
        if (!is_array($rawRow)) {
            continue;
        }

        $username = trim((string)($rawRow['username'] ?? ''));
        if ($username === '') {
            continue;
        }

        $key = strtolower($username);
        $rows[$key] = array_merge($commonUsersByName[$key] ?? [], [
            'username' => $username,
            'profile' => trim((string)($rawRow['groupname'] ?? '')),
        ]);
    }

    foreach ([$radcheck, $radreply] as $rawRows) {
        foreach ($rawRows as $rawRow) {
            if (!is_array($rawRow)) {
                continue;
            }

            $username = trim((string)($rawRow['username'] ?? ''));
            if ($username === '') {
                continue;
            }

            $key = strtolower($username);
            $rows[$key] = array_merge($commonUsersByName[$key] ?? [], $rows[$key] ?? [], ['username' => $username]);
        }
    }

    return array_values($rows);
}

function radiusStandardInsertGroupReplyRow(PDO $pdo, string $groupname, array $row): void
{
    $attribute = trim((string)($row['attribute'] ?? ''));
    if ($groupname === '' || $attribute === '') {
        return;
    }

    $stmt = $pdo->prepare('INSERT INTO radgroupreply (groupname, attribute, op, value) VALUES (?, ?, ?, ?)');
    $stmt->execute([
        $groupname,
        $attribute,
        trim((string)($row['op'] ?? ':=')) ?: ':=',
        (string)($row['value'] ?? ''),
    ]);
}

function radiusStandardWriteCommonProfileToRadius(PDO $pdo, array $profileRow): void
{
    $groupname = trim((string)($profileRow['name'] ?? ''));
    if ($groupname === '') {
        return;
    }

    if (($profileRow['session_timeout'] ?? 0) > 0) {
        insertReply($pdo, $groupname, 'Session-Timeout', (int)$profileRow['session_timeout']);
    }
    if (($profileRow['shared_users'] ?? 0) > 0) {
        insertReply($pdo, $groupname, 'Simultaneous-Use', (int)$profileRow['shared_users']);
    }
    if (($profileRow['data_quota_mb'] ?? 0) > 0) {
        insertReply($pdo, $groupname, 'Max-Octets', convertMegabytesToBytes((int)$profileRow['data_quota_mb']));
    }
    applyRateLimit($pdo, $groupname, trim((string)($profileRow['rate_limit'] ?? '')), 'radius', resolveNasCapabilities('radius'));
}

function radiusStandardWriteUserToRadius(PDO $pdo, array $userRow, array $rawUserRows): void
{
    $username = trim((string)($userRow['username'] ?? ''));
    $profile = trim((string)($userRow['profile'] ?? ''));
    if ($username === '' || $profile === '') {
        throw new RuntimeException('Utilisateur standard sans username ou profil.');
    }

    $rawChecks = is_array($rawUserRows['radcheck'] ?? null) ? $rawUserRows['radcheck'] : [];
    $rawReplies = is_array($rawUserRows['radreply'] ?? null) ? $rawUserRows['radreply'] : [];
    $rawGroups = is_array($rawUserRows['radusergroup'] ?? null) ? $rawUserRows['radusergroup'] : [];
    $hasRaw = false;
    $hasRawGroup = false;

    foreach ($rawChecks as $row) {
        if (is_array($row) && trim((string)($row['username'] ?? '')) === $username) {
            $attribute = trim((string)$row['attribute']);
            $value = (string)($row['value'] ?? '');
            if ($attribute === 'Cleartext-Password' && radiusStandardIsMaskedPassword($value)) {
                $value = (string)($userRow['password'] ?? '');
            }
            $stmt = $pdo->prepare('INSERT INTO radcheck (username, attribute, op, value) VALUES (?, ?, ?, ?)');
            $stmt->execute([$username, $attribute, trim((string)($row['op'] ?? ':=')) ?: ':=', $value]);
            $hasRaw = true;
        }
    }
    foreach ($rawReplies as $row) {
        if (is_array($row) && trim((string)($row['username'] ?? '')) === $username) {
            $stmt = $pdo->prepare('INSERT INTO radreply (username, attribute, op, value) VALUES (?, ?, ?, ?)');
            $stmt->execute([$username, trim((string)$row['attribute']), trim((string)($row['op'] ?? ':=')) ?: ':=', (string)($row['value'] ?? '')]);
            $hasRaw = true;
        }
    }
    foreach ($rawGroups as $row) {
        if (is_array($row) && trim((string)($row['username'] ?? '')) === $username) {
            $stmt = $pdo->prepare('INSERT INTO radusergroup (username, groupname, priority) VALUES (?, ?, ?)');
            $stmt->execute([$username, trim((string)($row['groupname'] ?? $profile)) ?: $profile, max(1, (int)($row['priority'] ?? 1))]);
            $hasRaw = true;
            $hasRawGroup = true;
        }
    }

    if ($hasRaw) {
        if (!$hasRawGroup) {
            $stmt = $pdo->prepare('INSERT INTO radusergroup (username, groupname, priority) VALUES (?, ?, 1)');
            $stmt->execute([$username, $profile]);
        }
        return;
    }

    $stmt = $pdo->prepare("INSERT INTO radcheck (username, attribute, op, value) VALUES (?, 'Cleartext-Password', ':=', ?)");
    $stmt->execute([$username, (string)($userRow['password'] ?? '')]);
    if (radiusStandardNormalizeStatus((string)($userRow['status_effective'] ?? 'active')) !== 'active') {
        $stmt = $pdo->prepare("INSERT INTO radcheck (username, attribute, op, value) VALUES (?, 'Auth-Type', ':=', 'Reject')");
        $stmt->execute([$username]);
    }
    $stmt = $pdo->prepare('INSERT INTO radusergroup (username, groupname, priority) VALUES (?, ?, 1)');
    $stmt->execute([$username, $profile]);
    if (($userRow['session_timeout'] ?? 0) > 0) {
        insertUserReply($pdo, $username, 'Session-Timeout', (int)$userRow['session_timeout']);
    }
    if (($userRow['data_limit'] ?? 0) > 0) {
        insertUserReply($pdo, $username, 'Max-Octets', (int)$userRow['data_limit']);
    }
    $expirationDate = radiusStandardNormalizeDate((string)($userRow['expiration_date'] ?? ''));
    if ($expirationDate !== null) {
        insertUserReply($pdo, $username, 'Expiration', $expirationDate);
    }
}

function importRadiusStandardDocument(PDO $pdo, array $document, array $nasContext, string $mode, bool $includeSensitive): array
{
    $nasType = normalizeNasType((string)($nasContext['nas_type'] ?? ''));
    if ($nasType === 'opnsense') {
        return radiusStandardImportOpnsense($pdo, $document, $nasContext, $mode, $includeSensitive);
    }
    if ($nasType === 'radius') {
        return radiusStandardImportPureRadius($pdo, $document, $mode, $includeSensitive);
    }

    throw new RuntimeException('Type NAS non supporte pour import standard RADIUS.');
}
