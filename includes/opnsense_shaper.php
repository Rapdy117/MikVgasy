<?php

require_once __DIR__ . '/device_manager.php';
require_once __DIR__ . '/operation_history.php';
require_once __DIR__ . '/admin_notifications.php';

function opnsenseApiRequest(array $device, string $path, string $method = 'GET', array $payload = []): array
{
    $url = rtrim((string)($device['host'] ?? ''), '/') . $path;
    $httpMethod = strtoupper(trim($method));
    $ch = curl_init();

    $options = [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
        CURLOPT_USERPWD => (string)($device['api_key'] ?? '') . ':' . (string)($device['api_secret'] ?? ''),
        CURLOPT_SSL_VERIFYPEER => (bool)($device['verify_ssl'] ?? false),
        CURLOPT_SSL_VERIFYHOST => !empty($device['verify_ssl']) ? 2 : 0,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
        ],
    ];

    if ($httpMethod === 'POST') {
        $options[CURLOPT_POST] = true;
        if ($payload !== []) {
            $options[CURLOPT_POSTFIELDS] = http_build_query($payload);
            $options[CURLOPT_HTTPHEADER][] = 'Content-Type: application/x-www-form-urlencoded';
        }
    }

    curl_setopt_array($ch, $options);

    $raw = curl_exec($ch);
    $error = curl_error($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($raw === false || $error !== '') {
        return [
            'success' => false,
            'message' => $error !== '' ? $error : 'Erreur cURL inconnue',
            'http_code' => $httpCode,
        ];
    }

    $decoded = json_decode((string)$raw, true);
    if ($httpCode < 200 || $httpCode >= 300 || !is_array($decoded)) {
        return [
            'success' => false,
            'message' => 'Reponse OPNsense invalide sur ' . $path,
            'http_code' => $httpCode,
            'raw' => $raw,
        ];
    }

    return [
        'success' => true,
        'data' => $decoded,
        'http_code' => $httpCode,
    ];
}

function requireActiveOpnsenseDevice(): array
{
    $device = requireActiveDevice();

    if (($device['type'] ?? '') !== 'opnsense') {
        throw new RuntimeException('Le device actif doit etre un OPNsense.');
    }

    return $device;
}

function normalizeShaperDescriptionToken(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return 'UNKNOWN';
    }

    $value = preg_replace('/[^A-Za-z0-9_-]+/', '-', $value) ?? $value;
    $value = trim($value, '-_');

    return strtoupper($value !== '' ? $value : 'UNKNOWN');
}

function parseRateLimitComponentForShaper(string $rate): ?array
{
    $normalized = strtoupper(trim($rate));
    if ($normalized === '') {
        return null;
    }

    if (!preg_match('/^([0-9]+(?:\.[0-9]+)?)\s*([KMG])?$/', $normalized, $matches)) {
        return null;
    }

    $value = (float)$matches[1];
    $unit = $matches[2] ?? '';

    return match ($unit) {
        'G' => ['bandwidth' => (string)(int)round($value), 'metric' => 'Gbit'],
        'M' => ['bandwidth' => (string)(int)round($value), 'metric' => 'Mbit'],
        'K' => ['bandwidth' => (string)(int)round($value), 'metric' => 'Kbit'],
        default => ['bandwidth' => (string)(int)round($value), 'metric' => 'bit'],
    };
}

function parseRateLimitForOpnsenseShaper(string $rateLimit): array
{
    $rateLimit = trim($rateLimit);
    if ($rateLimit === '' || strpos($rateLimit, '/') === false) {
        throw new RuntimeException('Rate limit profil invalide pour le shaper OPNsense.');
    }

    [$uploadRaw, $downloadRaw] = array_map('trim', explode('/', $rateLimit, 2));
    $upload = parseRateLimitComponentForShaper($uploadRaw);
    $download = parseRateLimitComponentForShaper($downloadRaw);

    if ($upload === null || $download === null) {
        throw new RuntimeException('Rate limit profil invalide pour le shaper OPNsense.');
    }

    return [
        'upload' => $upload,
        'download' => $download,
    ];
}

function getCaptivePortalPrimaryInterface(array $device): ?string
{
    $response = opnsenseApiRequest($device, '/api/captiveportal/settings/searchZones');
    if (!($response['success'] ?? false)) {
        return null;
    }

    $rows = $response['data']['rows'] ?? [];
    if (!is_array($rows)) {
        return null;
    }

    foreach ($rows as $row) {
        $interfaces = trim((string)($row['interfaces'] ?? ''));
        if ($interfaces !== '') {
            $parts = array_filter(array_map('trim', explode(',', $interfaces)));
            if ($parts !== []) {
                return (string)array_values($parts)[0];
            }
        }
    }

    return null;
}

function findCaptivePortalSessionByUsername(array $device, string $username): ?array
{
    $response = opnsenseApiRequest($device, '/api/captiveportal/session/search');
    if (!($response['success'] ?? false)) {
        throw new RuntimeException((string)($response['message'] ?? 'Impossible de lire les sessions captive portal.'));
    }

    $rows = $response['data']['rows'] ?? [];
    if (!is_array($rows)) {
        return null;
    }

    foreach ($rows as $row) {
        if (trim((string)($row['userName'] ?? '')) === $username) {
            return $row;
        }
    }

    return null;
}

function findCaptivePortalSessions(array $device): array
{
    $response = opnsenseApiRequest($device, '/api/captiveportal/session/search');
    if (!($response['success'] ?? false)) {
        throw new RuntimeException((string)($response['message'] ?? 'Impossible de lire les sessions captive portal.'));
    }

    $rows = $response['data']['rows'] ?? [];
    return is_array($rows) ? $rows : [];
}

function opnsenseShaperSearch(string $type, array $device): array
{
    $path = match ($type) {
        'pipes' => '/api/trafficshaper/settings/search_pipes',
        'rules' => '/api/trafficshaper/settings/search_rules',
        default => throw new InvalidArgumentException('Type de shaper invalide.'),
    };

    $response = opnsenseApiRequest($device, $path);
    if (!($response['success'] ?? false)) {
        throw new RuntimeException((string)($response['message'] ?? 'Lecture du traffic shaper impossible.'));
    }

    $rows = $response['data']['rows'] ?? [];
    return is_array($rows) ? $rows : [];
}

function findShaperItemByDescription(array $rows, string $description): ?array
{
    foreach ($rows as $row) {
        if (trim((string)($row['description'] ?? '')) === $description) {
            return $row;
        }
    }

    return null;
}

function deleteOpnsenseRule(array $device, string $uuid): void
{
    $response = opnsenseApiRequest(
        $device,
        '/api/trafficshaper/settings/del_rule/' . rawurlencode($uuid),
        'POST'
    );

    if (!($response['success'] ?? false) || ($response['data']['result'] ?? '') !== 'deleted') {
        throw new RuntimeException('Impossible de supprimer une ancienne regle de session OPNsense.');
    }
}

function extractSessionRuleToken(string $description): ?string
{
    if (preg_match('/^SESSION:([A-Z0-9_-]+):(DOWN|UP)$/', trim($description), $matches)) {
        return $matches[1];
    }

    return null;
}

function cleanupStaleSessionRules(array $device, string $currentUsername, string $clientIp): void
{
    $rules = opnsenseShaperSearch('rules', $device);
    $currentToken = normalizeShaperDescriptionToken($currentUsername);

    foreach ($rules as $rule) {
        $description = trim((string)($rule['description'] ?? ''));
        $token = extractSessionRuleToken($description);
        if ($token === null || $token === $currentToken) {
            continue;
        }

        $direction = trim((string)($rule['direction'] ?? ''));
        $source = trim((string)($rule['source'] ?? ''));
        $destination = trim((string)($rule['destination'] ?? ''));

        $matchesCurrentIp = ($direction === 'out' && $destination === $clientIp)
            || ($direction === 'in' && $source === $clientIp);

        if ($matchesCurrentIp) {
            deleteOpnsenseRule($device, (string)($rule['uuid'] ?? ''));
        }
    }
}

function deleteSessionRulesForUsername(array $device, string $username): int
{
    $rules = opnsenseShaperSearch('rules', $device);
    $token = normalizeShaperDescriptionToken($username);
    $deleted = 0;

    foreach ($rules as $rule) {
        $description = trim((string)($rule['description'] ?? ''));
        if (extractSessionRuleToken($description) !== $token) {
            continue;
        }

        deleteOpnsenseRule($device, (string)($rule['uuid'] ?? ''));
        $deleted++;
    }

    return $deleted;
}

function isMissingCaptiveSessionException(Throwable $e): bool
{
    $message = trim((string)$e->getMessage());

    return in_array($message, [
        'Aucune session captive active pour cet utilisateur.',
        'IP de session captive introuvable.',
    ], true);
}

function isSkippableOpnsenseShaperException(Throwable $e): bool
{
    $message = trim((string)$e->getMessage());

    if (isMissingCaptiveSessionException($e)) {
        return true;
    }

    return in_array($message, [
        'Utilisateur OPNsense introuvable dans la base metier.',
        'Le profil ne contient aucun rate limit.',
    ], true);
}

function trySyncOpnsenseUserShaper(PDO $pdo, string $username, ?string $interface = null): array
{
    try {
        $result = syncOpnsenseUserShaper($pdo, $username, $interface);

        return [
            'success' => true,
            'synced' => true,
            'result' => $result,
        ];
    } catch (Throwable $e) {
        if (isSkippableOpnsenseShaperException($e)) {
            return [
                'success' => true,
                'synced' => false,
                'message' => $e->getMessage(),
            ];
        }

        return [
            'success' => false,
            'synced' => false,
            'message' => $e->getMessage(),
        ];
    }
}

function reconcileOpnsenseActiveSessions(PDO $pdo, ?string $interface = null): array
{
    $device = requireActiveOpnsenseDevice();
    $resolvedInterface = trim((string)($interface ?? ''));

    if ($resolvedInterface === '') {
        $resolvedInterface = trim((string)(getCaptivePortalPrimaryInterface($device) ?? ''));
    }
    if ($resolvedInterface === '') {
        $resolvedInterface = 'lan';
    }

    $removed = enforceOpnsenseExpiredModePolicies($pdo, $device, $resolvedInterface);
    $sessions = findCaptivePortalSessions($device);
    $synced = [];
    $skipped = [];
    $errors = [];
    $activeTokens = [];

    foreach ($sessions as $session) {
        $username = trim((string)($session['userName'] ?? ''));
        if ($username === '') {
            continue;
        }

        $activeTokens[normalizeShaperDescriptionToken($username)] = true;

        $result = trySyncOpnsenseUserShaper($pdo, $username, $resolvedInterface);
        if ($result['success'] && $result['synced']) {
            $synced[] = $username;
            continue;
        }

        if ($result['success']) {
            $skipped[] = [
                'username' => $username,
                'message' => (string)($result['message'] ?? ''),
            ];
            continue;
        }

        $errors[] = [
            'username' => $username,
            'message' => (string)($result['message'] ?? ''),
        ];
    }

    $rules = opnsenseShaperSearch('rules', $device);
    $deletedRules = [];

    foreach ($rules as $rule) {
        $description = trim((string)($rule['description'] ?? ''));
        $token = extractSessionRuleToken($description);
        if ($token === null || isset($activeTokens[$token])) {
            continue;
        }

        deleteOpnsenseRule($device, (string)($rule['uuid'] ?? ''));
        $deletedRules[] = $description;
    }

    if ($deletedRules !== []) {
        reconfigureOpnsenseShaper($device);
    }

    return [
        'device' => $device,
        'interface' => $resolvedInterface,
        'sessions' => count($sessions),
        'removed' => $removed,
        'synced' => $synced,
        'skipped' => $skipped,
        'errors' => $errors,
        'deleted_rules' => $deletedRules,
    ];
}

function upsertOpnsensePipe(array $device, ?array $existingPipe, string $description, array $rate): array
{
    $payload = [
        'pipe[enabled]' => '1',
        'pipe[bandwidth]' => (string)$rate['bandwidth'],
        'pipe[bandwidthMetric]' => (string)$rate['metric'],
        'pipe[mask]' => 'none',
        'pipe[description]' => $description,
    ];

    if ($existingPipe === null) {
        $response = opnsenseApiRequest($device, '/api/trafficshaper/settings/add_pipe', 'POST', $payload);
    } else {
        $response = opnsenseApiRequest(
            $device,
            '/api/trafficshaper/settings/set_pipe/' . rawurlencode((string)$existingPipe['uuid']),
            'POST',
            $payload
        );
    }

    if (!($response['success'] ?? false) || ($response['data']['result'] ?? '') !== 'saved') {
        throw new RuntimeException('Impossible de synchroniser le pipe OPNsense ' . $description . '.');
    }

    $rows = opnsenseShaperSearch('pipes', $device);
    $pipe = findShaperItemByDescription($rows, $description);
    if ($pipe === null) {
        throw new RuntimeException('Pipe OPNsense introuvable apres synchronisation: ' . $description . '.');
    }

    return $pipe;
}

function upsertOpnsenseRule(array $device, ?array $existingRule, array $ruleData): array
{
    $payload = [
        'rule[enabled]' => '1',
        'rule[sequence]' => (string)$ruleData['sequence'],
        'rule[interface]' => (string)$ruleData['interface'],
        'rule[proto]' => 'ip',
        'rule[source]' => (string)$ruleData['source'],
        'rule[source_not]' => '0',
        'rule[src_port]' => 'any',
        'rule[destination]' => (string)$ruleData['destination'],
        'rule[destination_not]' => '0',
        'rule[dst_port]' => 'any',
        'rule[direction]' => (string)$ruleData['direction'],
        'rule[target]' => (string)$ruleData['target_uuid'],
        'rule[description]' => (string)$ruleData['description'],
    ];

    if ($existingRule === null) {
        $response = opnsenseApiRequest($device, '/api/trafficshaper/settings/add_rule', 'POST', $payload);
    } else {
        $response = opnsenseApiRequest(
            $device,
            '/api/trafficshaper/settings/set_rule/' . rawurlencode((string)$existingRule['uuid']),
            'POST',
            $payload
        );
    }

    if (!($response['success'] ?? false) || ($response['data']['result'] ?? '') !== 'saved') {
        throw new RuntimeException('Impossible de synchroniser la regle OPNsense ' . $ruleData['description'] . '.');
    }

    $rows = opnsenseShaperSearch('rules', $device);
    $rule = findShaperItemByDescription($rows, (string)$ruleData['description']);
    if ($rule === null) {
        throw new RuntimeException('Regle OPNsense introuvable apres synchronisation: ' . $ruleData['description'] . '.');
    }

    return $rule;
}

function reconfigureOpnsenseShaper(array $device): void
{
    $response = opnsenseApiRequest($device, '/api/trafficshaper/service/reconfigure', 'POST');
    if (!($response['success'] ?? false) || ($response['data']['status'] ?? '') !== 'ok') {
        throw new RuntimeException('Reconfiguration du traffic shaper OPNsense impossible.');
    }
}

function syncOpnsenseUserShaper(PDO $pdo, string $username, ?string $interface = null): array
{
    $username = trim($username);
    if ($username === '') {
        throw new RuntimeException('Username manquant.');
    }

    $device = requireActiveOpnsenseDevice();

    $stmt = $pdo->prepare("
        SELECT
            u.id,
            u.username,
            u.nas_id,
            u.profile_id,
            p.name AS profile_name,
            p.rate_limit
        FROM users u
        INNER JOIN profiles p ON p.id = u.profile_id
        INNER JOIN nas n ON n.id = u.nas_id
        WHERE u.username = ?
          AND LOWER(COALESCE(n.type, '')) = 'opnsense'
        LIMIT 1
    ");
    $stmt->execute([$username]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

    if ($user === null) {
        throw new RuntimeException('Utilisateur OPNsense introuvable dans la base metier.');
    }

    $rateLimit = trim((string)($user['rate_limit'] ?? ''));
    if ($rateLimit === '') {
        throw new RuntimeException('Le profil ne contient aucun rate limit.');
    }

    $session = findCaptivePortalSessionByUsername($device, $username);
    if ($session === null) {
        throw new RuntimeException('Aucune session captive active pour cet utilisateur.');
    }

    $clientIp = trim((string)($session['ipAddress'] ?? ''));
    if ($clientIp === '') {
        throw new RuntimeException('IP de session captive introuvable.');
    }

    $resolvedInterface = trim((string)($interface ?? ''));
    if ($resolvedInterface === '') {
        $resolvedInterface = trim((string)(getCaptivePortalPrimaryInterface($device) ?? ''));
    }
    if ($resolvedInterface === '') {
        $resolvedInterface = 'lan';
    }

    $rates = parseRateLimitForOpnsenseShaper($rateLimit);
    $profileToken = normalizeShaperDescriptionToken((string)($user['profile_name'] ?? ''));
    $userToken = normalizeShaperDescriptionToken($username);

    $pipeDescriptions = [
        'down' => 'PROFILE:' . $profileToken . ':DOWN',
        'up' => 'PROFILE:' . $profileToken . ':UP',
    ];

    $ruleDescriptions = [
        'down' => 'SESSION:' . $userToken . ':DOWN',
        'up' => 'SESSION:' . $userToken . ':UP',
    ];

    $existingPipes = opnsenseShaperSearch('pipes', $device);
    $downPipe = upsertOpnsensePipe(
        $device,
        findShaperItemByDescription($existingPipes, $pipeDescriptions['down']),
        $pipeDescriptions['down'],
        $rates['download']
    );
    $upPipe = upsertOpnsensePipe(
        $device,
        findShaperItemByDescription($existingPipes, $pipeDescriptions['up']),
        $pipeDescriptions['up'],
        $rates['upload']
    );

    cleanupStaleSessionRules($device, $username, $clientIp);

    $existingRules = opnsenseShaperSearch('rules', $device);
    $downRule = upsertOpnsenseRule($device, findShaperItemByDescription($existingRules, $ruleDescriptions['down']), [
        'sequence' => 100,
        'interface' => $resolvedInterface,
        'source' => 'any',
        'destination' => $clientIp,
        'direction' => 'out',
        'target_uuid' => (string)$downPipe['uuid'],
        'description' => $ruleDescriptions['down'],
    ]);
    $upRule = upsertOpnsenseRule($device, findShaperItemByDescription($existingRules, $ruleDescriptions['up']), [
        'sequence' => 101,
        'interface' => $resolvedInterface,
        'source' => $clientIp,
        'destination' => 'any',
        'direction' => 'in',
        'target_uuid' => (string)$upPipe['uuid'],
        'description' => $ruleDescriptions['up'],
    ]);

    reconfigureOpnsenseShaper($device);

    return [
        'device' => $device,
        'user' => $user,
        'session' => $session,
        'interface' => $resolvedInterface,
        'rate_limit' => $rateLimit,
        'pipes' => [
            'down' => $downPipe,
            'up' => $upPipe,
        ],
        'rules' => [
            'down' => $downRule,
            'up' => $upRule,
        ],
    ];
}

function fetchRadiusReplyValue(PDO $pdo, string $sql, string $key): ?int
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$key, 'Max-Octets']);
    $value = $stmt->fetchColumn();
    if ($value === false || $value === null || trim((string)$value) === '') {
        return null;
    }

    return max(0, (int)$value);
}

function resolveUserQuotaBytes(PDO $pdo, array $user): int
{
    $username = trim((string)($user['username'] ?? ''));
    $profileName = trim((string)($user['profile_name'] ?? ''));

    $userReplyQuota = fetchRadiusReplyValue(
        $pdo,
        'SELECT value FROM radreply WHERE username = ? AND attribute = ? ORDER BY id DESC LIMIT 1',
        $username
    );
    if ($userReplyQuota !== null && $userReplyQuota > 0) {
        return $userReplyQuota;
    }

    $stmt = $pdo->prepare("
        SELECT details_json
        FROM operation_history
        WHERE target_type = 'user'
          AND target_name = ?
          AND operation_type IN ('user_create', 'user_update')
          AND details_json IS NOT NULL
          AND details_json <> ''
        ORDER BY id DESC
        LIMIT 1
    ");
    $stmt->execute([$username]);
    $detailsJson = $stmt->fetchColumn();
    if ($detailsJson !== false && trim((string)$detailsJson) !== '') {
        $details = json_decode((string)$detailsJson, true);
        $recordedQuotaMb = is_array($details) ? (int)($details['data_limit'] ?? 0) : 0;
        if ($recordedQuotaMb > 0) {
            return $recordedQuotaMb * 1024 * 1024;
        }
    }

    $groupReplyQuota = fetchRadiusReplyValue(
        $pdo,
        'SELECT value FROM radgroupreply WHERE groupname = ? AND attribute = ? ORDER BY id DESC LIMIT 1',
        $profileName
    );
    if ($groupReplyQuota !== null && $groupReplyQuota > 0) {
        return $groupReplyQuota;
    }

    $profileQuotaMb = (int)($user['profile_quota_mb'] ?? 0);
    if ($profileQuotaMb > 0) {
        return $profileQuotaMb * 1024 * 1024;
    }

    return 0;
}

function fetchUserAccountingUsage(PDO $pdo, string $username): array
{
    $stmt = $pdo->prepare("
        SELECT
            COALESCE(SUM(COALESCE(acctinputoctets, 0) + COALESCE(acctoutputoctets, 0)), 0) AS total_octets,
            COALESCE(SUM(CASE WHEN acctstoptime IS NULL THEN COALESCE(acctinputoctets, 0) + COALESCE(acctoutputoctets, 0) ELSE 0 END), 0) AS active_octets,
            MAX(COALESCE(acctinputoctets, 0) + COALESCE(acctoutputoctets, 0)) AS max_session_octets
        FROM radacct
        WHERE username = ?
    ");
    $stmt->execute([$username]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    return [
        'total_octets' => (int)($row['total_octets'] ?? 0),
        'active_octets' => (int)($row['active_octets'] ?? 0),
        'max_session_octets' => (int)($row['max_session_octets'] ?? 0),
    ];
}

function resolveUserExpiryTrigger(PDO $pdo, array $user): ?array
{
    $username = trim((string)($user['username'] ?? ''));
    if ($username === '') {
        return null;
    }

    $quotaBytes = resolveUserQuotaBytes($pdo, $user);
    $usage = fetchUserAccountingUsage($pdo, $username);
    if ($quotaBytes > 0 && (int)($usage['total_octets'] ?? 0) >= $quotaBytes) {
        return [
            'reason' => 'quota_exceeded',
            'quota_bytes' => $quotaBytes,
            'usage' => $usage,
        ];
    }

    $expirationDate = trim((string)($user['expiration_date'] ?? ''));
    if ($expirationDate !== '') {
        try {
            $expiration = new DateTimeImmutable($expirationDate, new DateTimeZone('UTC'));
            $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
            if ($expiration <= $now) {
                return [
                    'reason' => 'date_expired',
                    'quota_bytes' => $quotaBytes,
                    'usage' => $usage,
                ];
            }
        } catch (Throwable) {
        }
    }

    return null;
}

function resolveRemoveRecordActor(PDO $pdo, string $username): string
{
    $stmt = $pdo->prepare("
        SELECT actor_username
        FROM operation_history
        WHERE target_type = 'user'
          AND target_name = ?
          AND actor_username IS NOT NULL
          AND actor_username <> ''
        ORDER BY id DESC
        LIMIT 1
    ");
    $stmt->execute([$username]);
    $value = $stmt->fetchColumn();

    return trim((string)$value) !== '' ? trim((string)$value) : 'SYSTEM';
}

function getCommercialAmountForUser(array $user): ?float
{
    $amountValue = (float)($user['selling_price'] ?? 0);
    if ($amountValue <= 0) {
        $amountValue = (float)($user['price'] ?? 0);
    }

    return $amountValue > 0 ? $amountValue : null;
}

function disconnectCaptivePortalSessionIfActive(array $device, string $username): void
{
    $session = findCaptivePortalSessionByUsername($device, $username);
    if ($session === null) {
        return;
    }

    $sessionId = trim((string)($session['sessionId'] ?? ''));
    if ($sessionId === '') {
        return;
    }

    opnsenseApiRequest($device, '/api/captiveportal/session/disconnect', 'POST', [
        'sessionId' => $sessionId,
    ]);
}

function buildExpiredModeDetails(array $user, array $trigger, string $interface): array
{
    $usage = $trigger['usage'] ?? [];
    $quotaBytes = (int)($trigger['quota_bytes'] ?? 0);

    return [
        'integration' => 'opnsense_radius',
        'expired_mode' => (string)($user['expired_mode'] ?? 'none'),
        'trigger_reason' => (string)($trigger['reason'] ?? 'unknown'),
        'quota_bytes' => $quotaBytes,
        'quota_mb' => $quotaBytes > 0 ? round($quotaBytes / 1024 / 1024, 2) : null,
        'consumed_bytes' => (int)($usage['total_octets'] ?? 0),
        'consumed_mb' => round(((int)($usage['total_octets'] ?? 0)) / 1024 / 1024, 2),
        'expiration_date' => trim((string)($user['expiration_date'] ?? '')) ?: null,
        'interface' => $interface,
    ];
}

function deleteExpiredUser(PDO $pdo, array $device, array $user, array $trigger, string $interface, bool $recordCommercial): array
{
    $username = trim((string)($user['username'] ?? ''));
    $profileName = trim((string)($user['profile_name'] ?? '')) ?: '-';
    $userId = (int)($user['id'] ?? 0);
    $actor = resolveRemoveRecordActor($pdo, $username);
    $amountValue = getCommercialAmountForUser($user);

    disconnectCaptivePortalSessionIfActive($device, $username);
    $deletedRules = deleteSessionRulesForUsername($device, $username);
    if ($deletedRules > 0) {
        reconfigureOpnsenseShaper($device);
    }

    ensureOperationHistoryTable($pdo);
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('DELETE FROM radcheck WHERE username = ?');
        $stmt->execute([$username]);

        $stmt = $pdo->prepare('DELETE FROM radreply WHERE username = ?');
        $stmt->execute([$username]);

        $stmt = $pdo->prepare('DELETE FROM radusergroup WHERE username = ?');
        $stmt->execute([$username]);

        $stmt = $pdo->prepare('DELETE FROM users WHERE id = ?');
        $stmt->execute([$userId]);

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    recordOperationHistory($pdo, [
        'operation_scope' => $recordCommercial ? 'commercial' : 'admin',
        'operation_type' => $recordCommercial ? 'user_remove_record' : 'user_expire_remove',
        'actor_username' => $actor,
        'actor_role' => $actor === 'SYSTEM' ? 'system' : 'administrator',
        'target_type' => 'user',
        'target_name' => $username,
        'target_ref' => $userId > 0 ? (string)$userId : null,
        'device_id' => trim((string)($user['nas_id'] ?? '')) ?: null,
        'profile_name' => $profileName,
        'quantity' => 1,
        'amount_value' => $recordCommercial ? $amountValue : null,
        'summary' => ($trigger['reason'] ?? '') === 'date_expired'
            ? 'Compte supprimé automatiquement après expiration'
            : 'Compte supprimé après dépassement du quota data',
        'details_json' => buildExpiredModeDetails($user, $trigger, $interface),
    ]);

    createAdminNotification($pdo, [
        'severity' => $recordCommercial ? 'warning' : 'info',
        'category' => 'expiration',
        'source_type' => 'user',
        'source_ref' => $username,
        'title' => $recordCommercial ? 'Suppression auto sur quota' : 'Suppression auto à expiration',
        'message' => ($trigger['reason'] ?? '') === 'date_expired'
            ? sprintf('Le compte %s (%s) a été supprimé automatiquement après expiration.', $username, $profileName)
            : sprintf('Le compte %s (%s) a été supprimé automatiquement après dépassement du quota.', $username, $profileName),
        'details_json' => buildExpiredModeDetails($user, $trigger, $interface),
    ]);

    return [
        'username' => $username,
        'profile_name' => $profileName,
        'action' => $recordCommercial ? 'remove_record' : 'remove',
        'quota_bytes' => (int)($trigger['quota_bytes'] ?? 0),
        'consumed_bytes' => (int)(($trigger['usage']['total_octets'] ?? 0)),
        'actor_username' => $actor,
        'amount_value' => $amountValue,
    ];
}

function disableRadiusUserWithNotice(PDO $pdo, array $device, array $user, array $trigger, string $interface, bool $recordCommercial): array
{
    $username = trim((string)($user['username'] ?? ''));
    $profileName = trim((string)($user['profile_name'] ?? '')) ?: '-';
    $userId = (int)($user['id'] ?? 0);
    $actor = resolveRemoveRecordActor($pdo, $username);
    $amountValue = getCommercialAmountForUser($user);

    disconnectCaptivePortalSessionIfActive($device, $username);
    $deletedRules = deleteSessionRulesForUsername($device, $username);
    if ($deletedRules > 0) {
        reconfigureOpnsenseShaper($device);
    }

    ensureOperationHistoryTable($pdo);
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("DELETE FROM radcheck WHERE username = ? AND attribute = 'Auth-Type'");
        $stmt->execute([$username]);

        $stmt = $pdo->prepare("
            INSERT INTO radcheck (username, attribute, op, value)
            VALUES (?, 'Auth-Type', ':=', 'Reject')
        ");
        $stmt->execute([$username]);

        $stmt = $pdo->prepare("UPDATE users SET status = 'expired' WHERE id = ?");
        $stmt->execute([$userId]);

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    recordOperationHistory($pdo, [
        'operation_scope' => $recordCommercial ? 'commercial' : 'admin',
        'operation_type' => $recordCommercial ? 'user_notice_record' : 'user_notice',
        'actor_username' => $actor,
        'actor_role' => $actor === 'SYSTEM' ? 'system' : 'administrator',
        'target_type' => 'user',
        'target_name' => $username,
        'target_ref' => $userId > 0 ? (string)$userId : null,
        'device_id' => trim((string)($user['nas_id'] ?? '')) ?: null,
        'profile_name' => $profileName,
        'quantity' => 1,
        'amount_value' => $recordCommercial ? $amountValue : null,
        'summary' => ($trigger['reason'] ?? '') === 'date_expired'
            ? 'Compte expiré et conservé'
            : 'Compte bloqué après dépassement du quota data',
        'details_json' => buildExpiredModeDetails($user, $trigger, $interface),
    ]);

    createAdminNotification($pdo, [
        'severity' => 'warning',
        'category' => 'expiration',
        'source_type' => 'user',
        'source_ref' => $username,
        'title' => $recordCommercial ? 'Expiration conservée et comptabilisée' : 'Expiration avec conservation',
        'message' => ($trigger['reason'] ?? '') === 'date_expired'
            ? sprintf('Le compte %s (%s) a été bloqué après expiration.', $username, $profileName)
            : sprintf('Le compte %s (%s) a été bloqué après dépassement du quota.', $username, $profileName),
        'details_json' => buildExpiredModeDetails($user, $trigger, $interface),
    ]);

    return [
        'username' => $username,
        'profile_name' => $profileName,
        'action' => $recordCommercial ? 'notice_record' : 'notice',
        'quota_bytes' => (int)($trigger['quota_bytes'] ?? 0),
        'consumed_bytes' => (int)(($trigger['usage']['total_octets'] ?? 0)),
        'actor_username' => $actor,
        'amount_value' => $amountValue,
    ];
}

function enforceOpnsenseExpiredModePolicies(PDO $pdo, array $device, string $interface): array
{
    $stmt = $pdo->query("
        SELECT
            u.id,
            u.username,
            u.nas_id,
            u.status,
            u.expiration_date,
            p.name AS profile_name,
            p.data_quota_mb AS profile_quota_mb,
            p.expired_mode,
            p.price,
            p.selling_price
        FROM users u
        INNER JOIN profiles p ON p.id = u.profile_id
        INNER JOIN nas n ON n.id = u.nas_id
        WHERE LOWER(COALESCE(n.type, '')) = 'opnsense'
          AND LOWER(COALESCE(p.expired_mode, '')) IN ('remove', 'notice', 'remove_record', 'notice_record')
    ");
    $rows = $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
    $handled = [];

    foreach ($rows as $row) {
        $username = trim((string)($row['username'] ?? ''));
        if ($username === '') {
            continue;
        }

        $mode = strtolower(trim((string)($row['expired_mode'] ?? 'none')));
        if (($mode === 'notice' || $mode === 'notice_record') && strtolower(trim((string)($row['status'] ?? 'active'))) === 'expired') {
            continue;
        }

        $trigger = resolveUserExpiryTrigger($pdo, $row);
        if ($trigger === null) {
            continue;
        }

        $handled[] = match ($mode) {
            'remove' => deleteExpiredUser($pdo, $device, $row, $trigger, $interface, false),
            'notice' => disableRadiusUserWithNotice($pdo, $device, $row, $trigger, $interface, false),
            'notice_record' => disableRadiusUserWithNotice($pdo, $device, $row, $trigger, $interface, true),
            default => deleteExpiredUser($pdo, $device, $row, $trigger, $interface, true),
        };
    }

    return $handled;
}
