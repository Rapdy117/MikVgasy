<?php

require_once __DIR__ . '/device_manager.php';
require_once __DIR__ . '/nas_resolver.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/lib/routeros_api.class.php';

/**
 * Entrées NAS (audit chantier) : résolution via loadNasContextByDeviceId / resolveNasContextFromInputs
 * avec enrichNasContextWithDevice (device + NAS alignés). Aucun contexte NAS avec nas_id <= 0 sur les flux nominaux.
 * connectToMikrotikApiForNasContext préfère $nasContext['device'] (MikroTik) ; le repli sur le device actif
 * couvre uniquement d’anciens appels sans device explicite.
 */
function buildMikrotikContextFromDevice(array $device): array
{
    global $pdo;

    if (normalizeDeviceType((string)($device['type'] ?? '')) !== 'mikrotik') {
        throw new RuntimeException('Le device cible n est pas de type MikroTik.');
    }

    $deviceId = trim((string)($device['id'] ?? ''));
    if ($deviceId === '') {
        throw new RuntimeException('ID du device MikroTik manquant.');
    }

    if (!($pdo instanceof PDO)) {
        throw new RuntimeException('Connexion PDO indisponible pour resoudre le contexte MikroTik.');
    }

    return loadNasContextByDeviceId($pdo, $deviceId);
}

function requireMikrotikNasContextForActiveDevice(): array
{
    $device = requireActiveDeviceType('mikrotik');
    return buildMikrotikContextFromDevice($device);
}

function mikrotikIntervalFromSeconds(int $seconds): string
{
    if ($seconds <= 0) {
        return '0s';
    }

    return $seconds . 's';
}

function parseRouterosIntervalToSeconds(?string $value): int
{
    $duration = strtolower(trim((string)$value));
    if ($duration === '') {
        return 0;
    }

    if (ctype_digit($duration)) {
        return (int)$duration;
    }

    if (preg_match('/^(\d+)d\s*(\d{1,2}):(\d{2}):(\d{2})$/', $duration, $matches)) {
        $days = (int)$matches[1];
        $hours = (int)$matches[2];
        $minutes = (int)$matches[3];
        $seconds = (int)$matches[4];
        return ($days * 86400) + ($hours * 3600) + ($minutes * 60) + $seconds;
    }

    if (preg_match('/^(\d{1,2}):(\d{2}):(\d{2})$/', $duration, $matches)) {
        $hours = (int)$matches[1];
        $minutes = (int)$matches[2];
        $seconds = (int)$matches[3];
        return ($hours * 3600) + ($minutes * 60) + $seconds;
    }

    if (preg_match('/^(\d{1,2}):(\d{2})$/', $duration, $matches)) {
        $hours = (int)$matches[1];
        $minutes = (int)$matches[2];
        return ($hours * 3600) + ($minutes * 60);
    }

    preg_match_all('/(\d+)([wdhms])/', $duration, $matches, PREG_SET_ORDER);
    if (!$matches) {
        return 0;
    }

    $unitMap = ['w' => 604800, 'd' => 86400, 'h' => 3600, 'm' => 60, 's' => 1];
    $total = 0;

    foreach ($matches as $match) {
        $total += ((int)$match[1]) * ($unitMap[$match[2]] ?? 0);
    }

    return $total;
}

function parseRechargeExpirationDate(?string $value): ?DateTimeImmutable
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

function formatRechargeExpirationDate(?DateTimeImmutable $date): string
{
    return $date instanceof DateTimeImmutable ? $date->format('Y-m-d') : '';
}

function addSecondsToDate(DateTimeImmutable $date, int $seconds): DateTimeImmutable
{
    if ($seconds <= 0) {
        return $date;
    }

    return $date->modify('+' . $seconds . ' seconds');
}

function mikrotikBytesFromMegabytes(int $megabytes): int
{
    if ($megabytes <= 0) {
        return 0;
    }

    return $megabytes * 1024 * 1024;
}

function mikrotikProfileDataQuotaMb(RouterosAPI $api, string $profileName): int
{
    $profile = findMikrotikProfileByName($api, $profileName);
    if (!$profile) {
        return 0;
    }

    $limit = trim((string)($profile['limit-bytes-total'] ?? ''));
    if ($limit !== '') {
        $bytes = (float)$limit;
        if ($bytes > 0) {
            return (int)round($bytes / 1024 / 1024);
        }
    }

    $metadata = parseMikrotikOnLoginMetadata((string)($profile['on-login'] ?? ''));
    return max(0, (int)($metadata['data_quota_mb'] ?? 0));
}

function mikrotikApiParamsFromDevice(array $device): array
{
    $host = trim((string)($device['host'] ?? ''));
    if ($host === '') {
        throw new RuntimeException('Host MikroTik manquant sur le device actif.');
    }

    $parsedHost = parse_url($host);
    $routerHost = is_array($parsedHost) && !empty($parsedHost['host']) ? (string)$parsedHost['host'] : preg_replace('#^https?://#', '', $host);
    $routerPort = is_array($parsedHost) && !empty($parsedHost['port'])
        ? (int)$parsedHost['port']
        : ((is_array($parsedHost) && (($parsedHost['scheme'] ?? '') === 'https')) ? 8729 : 8728);
    $routerSsl = $routerPort === 8729 || (is_array($parsedHost) && (($parsedHost['scheme'] ?? '') === 'https'));

    return [
        'host' => $routerHost,
        'port' => $routerPort,
        'ssl' => $routerSsl,
        'username' => (string)($device['api_key'] ?? ''),
        'password' => (string)($device['api_secret'] ?? ''),
    ];
}

function connectToMikrotikApiByDevice(array $device): RouterosAPI
{
    if (($device['type'] ?? '') !== 'mikrotik') {
        throw new RuntimeException('Le device fourni n est pas de type MikroTik.');
    }

    $params = mikrotikApiParamsFromDevice($device);

    if ($params['username'] === '' || $params['password'] === '') {
        throw new RuntimeException('Les identifiants MikroTik du device actif sont incomplets.');
    }

    $api = new RouterosAPI();
    $api->port = $params['port'];
    $api->ssl = $params['ssl'];
    $api->timeout = 5;
    $api->attempts = 1;
    $api->delay = 0;

    if (!$api->connect($params['host'], $params['username'], $params['password'])) {
        $error = trim((string)($api->error_str ?? 'Connexion MikroTik impossible'));
        throw new RuntimeException('Connexion Mikhmon/API MikroTik impossible: ' . $error);
    }

    return $api;
}

/**
 * Connexion au routeur MikroTik désigné comme device actif (store).
 * À réserver aux lectures globales (listes hotspot, stats agrégées) sans cible utilisateur/NAS ;
 * pour provisioning ou sync alignée NAS, utiliser {@see connectToMikrotikApiForNasContext}.
 */
function connectToActiveMikrotikApi(): RouterosAPI
{
    $device = requireActiveDeviceType('mikrotik');
    return connectToMikrotikApiByDevice($device);
}

/**
 * Connexion API RouterOS pour un contexte NAS.
 * Si le contexte inclut un device MikroTik (via enrichNasContextWithDevice / resolveNasContextFromInputs),
 * on s’y connecte ; sinon repli sur le device MikroTik actif (chemins historiques sans device explicite).
 * Les flux admin doivent toujours passer un NAS résolu avec device pour éviter tout décalage device/NAS.
 */
function connectToMikrotikApiForNasContext(array $nasContext): RouterosAPI
{
    $device = $nasContext['device'] ?? null;

    if (is_array($device) && ($device['type'] ?? '') === 'mikrotik') {
        return connectToMikrotikApiByDevice($device);
    }

    if (trim((string)($nasContext['business_source'] ?? '')) === 'mikrotik_local') {
        throw new RuntimeException('Contexte MikroTik incomplet: device requis pour ce NAS');
    }

    return connectToActiveMikrotikApi();
}

function disconnectMikrotikApiIfOwned(?RouterosAPI $api, bool $ownsConnection): void
{
    if (!$ownsConnection || $api === null) {
        return;
    }

    try {
        $api->disconnect();
    } catch (Throwable $e) {
        // best effort
    }
}

function findMikrotikProfileByName(RouterosAPI $api, string $profileName): ?array
{
    $result = $api->comm('/ip/hotspot/user/profile/print', [
        '?name' => $profileName,
        '.proplist' => '.id,name,rate-limit,shared-users,limit-bytes-total,on-login,session-timeout,address-pool,parent-queue',
    ]);

    if (!(is_array($result) && isset($result[0]) && is_array($result[0]))) {
        $result = $api->comm('/ip/hotspot/user/profile/print', [
            '?name' => $profileName,
        ]);
    }

    if (is_array($result) && isset($result[0]) && is_array($result[0])) {
        return $result[0];
    }

    $profiles = $api->comm('/ip/hotspot/user/profile/print', [
        '.proplist' => '.id,name,rate-limit,shared-users,limit-bytes-total,on-login,session-timeout,address-pool,parent-queue',
    ]);

    foreach (is_array($profiles) ? $profiles : [] as $profile) {
        if (is_array($profile) && (string)($profile['name'] ?? '') === $profileName) {
            return $profile;
        }
    }

    return null;
}

function findMikrotikProfileById(RouterosAPI $api, string $profileId): ?array
{
    $profileId = trim($profileId);
    if ($profileId === '') {
        return null;
    }

    $result = $api->comm('/ip/hotspot/user/profile/print', [
        '?.id' => $profileId,
    ]);

    return is_array($result) && isset($result[0]) && is_array($result[0]) ? $result[0] : null;
}

function mikrotikResponseHasTrap($response): bool
{
    if (!is_array($response)) {
        return false;
    }

    if (isset($response['!trap']) || isset($response['!fatal'])) {
        return true;
    }

    foreach ($response as $row) {
        if (is_array($row) && (($row['!trap'] ?? null) !== null || (($row['ret'] ?? null) === ''))) {
            if (array_key_exists('message', $row) || array_key_exists('category', $row) || array_key_exists('!trap', $row)) {
                return true;
            }
        }
    }

    return false;
}

function mikrotikTrapMessage($response, string $fallbackMessage): string
{
    if (!is_array($response)) {
        return $fallbackMessage;
    }

    foreach (['!trap', '!fatal'] as $trapKey) {
        if (!isset($response[$trapKey])) {
            continue;
        }

        foreach ((array)$response[$trapKey] as $row) {
            if (!is_array($row)) {
                continue;
            }

            $message = trim((string)($row['message'] ?? ''));
            if ($message !== '') {
                return $message;
            }
        }
    }

    foreach ($response as $row) {
        if (!is_array($row)) {
            continue;
        }

        $message = trim((string)($row['message'] ?? ''));
        if ($message !== '') {
            return $message;
        }
    }

    return $fallbackMessage;
}

function mikrotikAssertNoTrap($response, string $fallbackMessage): void
{
    if (mikrotikResponseHasTrap($response)) {
        throw new RuntimeException(mikrotikTrapMessage($response, $fallbackMessage));
    }
}

function disconnectMikrotikHotspotActiveSession(string $sessionId, ?array $nasContext = null): void
{
    $sessionId = trim($sessionId);
    if ($sessionId === '') {
        throw new RuntimeException('ID de session MikroTik manquant.');
    }

    $api = $nasContext !== null
        ? connectToMikrotikApiForNasContext($nasContext)
        : connectToActiveMikrotikApi();

    try {
        $response = $api->comm('/ip/hotspot/active/remove', [
            '.id' => $sessionId,
        ]);

        mikrotikAssertNoTrap(
            $response,
            'La deconnexion a echoue sur le routeur MikroTik.'
        );
    } finally {
        $api->disconnect();
    }
}

function parseMikrotikOnLoginMetadata(?string $onLogin): array
{
    $result = [
        'expired_mode' => '-',
        'validity' => '-',
        'price' => '-',
        'selling_price' => '-',
        'lock_user' => '-',
        'data_quota_mb' => 0,
    ];

    $script = trim((string)$onLogin);
    if ($script === '') {
        return $result;
    }

    $matches = [];
    $matched = preg_match('/\:put\s*\(\s*"([^"]*)"\s*\)/i', $script, $matches);
    if (!$matched) {
        $matched = preg_match("/\:put\s*\(\s*'([^']*)'\s*\)/i", $script, $matches);
    }
    if (!$matched) {
        return $result;
    }

    $parts = explode(',', $matches[1]);
    $modeCode = trim((string)($parts[1] ?? ''));
    $price = trim((string)($parts[2] ?? ''));
    $validity = trim((string)($parts[3] ?? ''));
    $sellingPrice = trim((string)($parts[4] ?? ''));
    $lockUser = trim((string)($parts[6] ?? ''));
    $dataQuotaMb = trim((string)($parts[7] ?? ''));

    $result['expired_mode'] = match ($modeCode) {
        'rem' => 'Remove',
        'ntf' => 'Notice',
        'remc' => 'Remove & Record',
        'ntfc' => 'Notice & Record',
        default => (($parts[5] ?? '') === 'noexp' ? 'None' : 'None'),
    };

    $result['validity'] = $validity !== '' ? $validity : '-';
    $result['price'] = ($price !== '' && $price !== '0') ? $price : '-';
    $result['selling_price'] = ($sellingPrice !== '' && $sellingPrice !== '0') ? $sellingPrice : '-';
    $result['lock_user'] = $lockUser !== '' ? $lockUser : 'Disable';
    $result['data_quota_mb'] = ctype_digit($dataQuotaMb) ? (int)$dataQuotaMb : 0;

    return $result;
}

function mikrotikProfilePriceString(array $profile, string $key): string
{
    $rawKey = $key . '_raw';
    $raw = trim((string)($profile[$rawKey] ?? ''));
    if ($raw !== '') {
        return $raw;
    }

    $value = $profile[$key] ?? null;
    if ($value === null || $value === '') {
        return '0';
    }

    if (is_numeric($value)) {
        $formatted = rtrim(rtrim(number_format((float)$value, 2, '.', ''), '0'), '.');
        return $formatted === '' ? '0' : $formatted;
    }

    return trim((string)$value) !== '' ? trim((string)$value) : '0';
}

function mikrotikProfileExpiryCode(string $expiredMode): string
{
    $mode = strtolower(trim($expiredMode));

    return match ($mode) {
        'remove', 'rem' => 'rem',
        'notice', 'ntf' => 'ntf',
        'remove_record', 'remove & record', 'remc' => 'remc',
        'notice_record', 'notice & record', 'ntfc' => 'ntfc',
        default => '0',
    };
}

function mikrotikProfileLockLabel(bool $lockUser): string
{
    return $lockUser ? 'Enable' : 'Disable';
}

function mikrotikBuildProfileLockScript(bool $lockUser): string
{
    if (!$lockUser) {
        return '';
    }

    return '; [:local mac $"mac-address"; /ip hotspot user set mac-address=$mac [find where name=$user]]';
}

function mikrotikBuildProfileRecordScript(array $profile, string $validityRouteros, string $price): string
{
    $profileName = (string)($profile['name'] ?? '');

    return '; :local mac $"mac-address"; :local time [/system clock get time ]; /system script add name="$date-|-$time-|-$user-|-'
        . $price
        . '-|-$address-|-$mac-|-' . $validityRouteros . '-|-'
        . $profileName
        . '-|-$comment" owner="$month$year" source="$date" comment="mikhmon"';
}

function mikrotikBuildProfileOnLogin(array $profile): string
{
    $expiredModeCode = mikrotikProfileExpiryCode((string)($profile['expired_mode'] ?? 'none'));
    $price = mikrotikProfilePriceString($profile, 'price');
    $sellingPrice = mikrotikProfilePriceString($profile, 'selling_price');
    $validityRouteros = trim((string)($profile['validity_routeros'] ?? ''));
    $lockUser = (bool)($profile['lock_user'] ?? false);
    $lockLabel = mikrotikProfileLockLabel($lockUser);
    $lockScript = mikrotikBuildProfileLockScript($lockUser);
    $dataQuotaMb = max(0, (int)($profile['data_quota_mb'] ?? 0));

    if ($expiredModeCode === '0') {
        return ':put (",,'
            . $price
            . ',,'
            . $sellingPrice
            . ',noexp,'
            . $lockLabel
            . ','
            . $dataQuotaMb
            . '")' . $lockScript;
    }

    if ($validityRouteros === '') {
        throw new RuntimeException('Validite MikroTik requise pour ce mode d expiration.');
    }

    $recordScript = '';
    if (in_array($expiredModeCode, ['remc', 'ntfc'], true)) {
        $recordScript = mikrotikBuildProfileRecordScript($profile, $validityRouteros, $price);
    }

    $onLogin = ':put (",'
        . $expiredModeCode
        . ','
        . $price
        . ','
        . $validityRouteros
        . ','
        . $sellingPrice
        . ',,'
        . $lockLabel
        . ','
        . $dataQuotaMb
        . '"); {:local comment [ /ip hotspot user get [/ip hotspot user find where name="$user"] comment]; :local ucode [:pick $comment 0 2]; :if ($ucode = "vc" or $ucode = "up" or $comment = "") do={ :local date [ /system clock get date ];:local year [ :pick $date 0 4 ];:local month [ :pick $date 5 7 ]; /sys sch add name="$user" disable=no start-date=$date interval="'
        . $validityRouteros
        . '"; :delay 5s; :local exp [ /sys sch get [ /sys sch find where name="$user" ] next-run]; :local getxp [len $exp]; :if ($getxp = 15) do={ :local d [:pick $exp 0 6]; :local t [:pick $exp 7 16]; :local s ("/"); :local exp ("$d$s$year $t"); /ip hotspot user set comment="$exp" [find where name="$user"];}; :if ($getxp = 8) do={ /ip hotspot user set comment="$date $exp" [find where name="$user"];}; :if ($getxp > 15) do={ /ip hotspot user set comment="$exp" [find where name="$user"];};:delay 5s; /sys sch remove [find where name="$user"]';

    return $onLogin . $recordScript . $lockScript . '}}';
}

function mikrotikProfileSchedulerMode(array $profile): ?string
{
    $expiredModeCode = mikrotikProfileExpiryCode((string)($profile['expired_mode'] ?? 'none'));

    return match ($expiredModeCode) {
        'rem', 'remc' => 'remove',
        'ntf', 'ntfc' => 'set limit-uptime=1s',
        default => null,
    };
}

function mikrotikBuildProfileMonitorScript(array $profile): ?string
{
    $mode = mikrotikProfileSchedulerMode($profile);
    if ($mode === null) {
        return null;
    }

    $profileName = (string)($profile['name'] ?? '');
    $userAction = $mode === 'remove'
        ? '/ip hotspot user remove $i'
        : '/ip hotspot user set $i limit-uptime=1s';

    return ':local dateint do={:local montharray ( "01","02","03","04","05","06","07","08","09","10","11","12" );:local days [ :pick $d 8 10 ];:local month [ :pick $d 5 7 ];:local year [ :pick $d 0 4 ];:local monthint ([ :find $montharray $month]);:local month ($monthint + 1);:if ( [len $month] = 1) do={:local zero ("0");:return [:tonum ("$year$zero$month$days")];} else={:return [:tonum ("$year$month$days")];}}; :local timeint do={ :local hours [ :pick $t 0 2 ]; :local minutes [ :pick $t 3 5 ]; :return (($hours * 60) + $minutes) ; }; :local date [ /system clock get date ]; :local time [ /system clock get time ]; :local today [$dateint d=$date] ; :local curtime [$timeint t=$time] ; :foreach i in=[/ip hotspot user find where profile="'
        . str_replace(['\\', '"'], ['\\\\', '\\"'], $profileName)
        . '" ] do={ :local comment [/ip hotspot user get $i comment]; :local name [/ip hotspot user get $i name]; :if ([:len $comment] >= 19) do={ :local gettime [:pick $comment 11 8]; :if ([:pick $comment 4 1] = "-" and [:pick $comment 7 1] = "-") do={:local expd [$dateint d=$comment] ; :local expt [$timeint t=$gettime] ; :if ($expd < $today or ($expd = $today and $expt < $curtime)) do={ '
        . $userAction
        . ' ; /ip hotspot active remove [find where user=$name]; :log info ("profile-monitor " . $name); }}}}}';
}

function ensureMikrotikProfileScheduler(RouterosAPI $api, array $profile, ?string $lookupName = null): void
{
    $profileName = trim((string)($profile['name'] ?? ''));
    if ($profileName === '') {
        return;
    }

    $schedulerLookupName = trim((string)($lookupName ?? $profileName));
    $existing = $api->comm('/system/scheduler/print', [
        '?name' => $schedulerLookupName,
    ]);
    $scheduler = is_array($existing) && isset($existing[0]) && is_array($existing[0]) ? $existing[0] : null;

    $monitorScript = mikrotikBuildProfileMonitorScript($profile);
    if ($monitorScript === null) {
        if ($scheduler && isset($scheduler['.id'])) {
            $api->comm('/system/scheduler/remove', [
                '.id' => (string)$scheduler['.id'],
            ]);
        }
        return;
    }

    $startTime = '0' . (string)random_int(1, 5) . ':' . str_pad((string)random_int(10, 59), 2, '0', STR_PAD_LEFT) . ':' . str_pad((string)random_int(10, 59), 2, '0', STR_PAD_LEFT);
    $interval = '00:02:' . str_pad((string)random_int(10, 59), 2, '0', STR_PAD_LEFT);

    $payload = [
        'name' => $profileName,
        'start-time' => $startTime,
        'interval' => $interval,
        'on-event' => $monitorScript,
        'disabled' => 'no',
        'comment' => 'Monitor Profile ' . $profileName,
    ];

    if ($scheduler && isset($scheduler['.id'])) {
        $payload['.id'] = (string)$scheduler['.id'];
        $api->comm('/system/scheduler/set', $payload);
        return;
    }

    $api->comm('/system/scheduler/add', $payload);
}

function ensureMikrotikProfile(
    string $profileName,
    ?string $rateLimit = null,
    int $sharedUsers = 1,
    ?array $profileOptions = null,
    ?array $nasContext = null,
    ?string $lookupName = null
): array
{
    $api = $nasContext !== null
        ? connectToMikrotikApiForNasContext($nasContext)
        : connectToActiveMikrotikApi();

    try {
        $existingLookupName = $lookupName !== null && trim($lookupName) !== '' ? trim($lookupName) : $profileName;
        $existing = findMikrotikProfileByName($api, $existingLookupName);
        if (!$existing && $existingLookupName !== $profileName) {
            $existing = findMikrotikProfileByName($api, $profileName);
        }
        $payload = [
            'name' => $profileName,
            'shared-users' => (string)max(1, $sharedUsers),
            'status-autorefresh' => '1m',
            'on-login' => mikrotikBuildProfileOnLogin($profileOptions ?? ['name' => $profileName]),
        ];

        if ($rateLimit !== null && trim($rateLimit) !== '') {
            $payload['rate-limit'] = trim($rateLimit);
        }

        $addressPool = trim((string)($profileOptions['ip_pool'] ?? $profileOptions['address_pool'] ?? ''));
        if ($addressPool !== '') {
            $payload['address-pool'] = $addressPool;
        }

        $parentQueue = trim((string)($profileOptions['parent_queue'] ?? ''));
        if ($parentQueue !== '') {
            $payload['parent-queue'] = $parentQueue;
        }

        if (is_array($profileOptions) && array_key_exists('session_timeout', $profileOptions)) {
            $sessionTimeoutSeconds = max(0, (int)($profileOptions['session_timeout'] ?? 0));
            if ($sessionTimeoutSeconds > 0) {
                $payload['session-timeout'] = mikrotikIntervalFromSeconds($sessionTimeoutSeconds);
            }
        }

        if (is_array($profileOptions)) {
            $dataQuotaMb = max(0, (int)($profileOptions['data_quota_mb'] ?? 0));
            $payload['limit-bytes-total'] = $dataQuotaMb > 0
                ? (string)mikrotikBytesFromMegabytes($dataQuotaMb)
                : '0';
        }

        if ($existing) {
            $payload['.id'] = (string)$existing['.id'];
            $response = $api->comm('/ip/hotspot/user/profile/set', $payload);
            if (mikrotikResponseHasTrap($response)) {
                throw new RuntimeException('Le routeur MikroTik a refuse la mise a jour du profil.');
            }
            invalidateMikrotikHotspotProfilesCache($nasContext['device'] ?? null);
            ensureMikrotikProfileScheduler($api, $profileOptions ?? ['name' => $profileName], $lookupName);
            return confirmMikrotikProfileWrite($api, $profileName);
        }

        $response = $api->comm('/ip/hotspot/user/profile/add', $payload);
        if (mikrotikResponseHasTrap($response)) {
            throw new RuntimeException('Le routeur MikroTik a refuse la creation du profil.');
        }
        invalidateMikrotikHotspotProfilesCache($nasContext['device'] ?? null);
        ensureMikrotikProfileScheduler($api, $profileOptions ?? ['name' => $profileName], $lookupName);
        return confirmMikrotikProfileWrite($api, $profileName);
    } finally {
        $api->disconnect();
    }
}

function syncProfileToMikrotik(array $profile, ?array $nasContext = null): array
{
    $nasContext = requireExplicitMikrotikNasContext($nasContext);

    return ensureMikrotikProfile(
        (string)$profile['name'],
        isset($profile['rate_limit']) ? (string)$profile['rate_limit'] : null,
        (int)($profile['simultaneous_use'] ?? 1),
        $profile,
        $nasContext
    );
}

function updateProfileInMikrotik(array $profile, ?array $nasContext = null): array
{
    $nasContext = requireExplicitMikrotikNasContext($nasContext);

    $lookupName = trim((string)($profile['old_name'] ?? ''));
    if ($lookupName === '') {
        $lookupName = (string)$profile['name'];
    }

    return ensureMikrotikProfile(
        (string)$profile['name'],
        isset($profile['rate_limit']) ? (string)$profile['rate_limit'] : null,
        (int)($profile['simultaneous_use'] ?? 1),
        $profile,
        $nasContext,
        $lookupName
    );
}

function countMikrotikUsersByProfile(RouterosAPI $api, string $profileName): int
{
    $result = $api->comm('/ip/hotspot/user/print', [
        '?profile' => $profileName,
    ]);

    return is_array($result) ? count($result) : 0;
}

function deleteProfileFromMikrotik(string $profileName, ?array $nasContext = null, ?string $profileId = null): void
{
    $profileName = trim($profileName);
    $profileId = trim((string)$profileId);
    if ($profileName === '' && $profileId === '') {
        throw new RuntimeException('Nom de profil MikroTik manquant.');
    }

    $api = $nasContext !== null
        ? connectToMikrotikApiForNasContext($nasContext)
        : connectToActiveMikrotikApi();

    try {
        $existing = null;
        if ($profileId !== '') {
            $existing = findMikrotikProfileById($api, $profileId);
        }
        if (!$existing && $profileName !== '') {
            $existing = findMikrotikProfileByName($api, $profileName);
        }
        if (!$existing) {
            return;
        }

        if ((string)($existing['default'] ?? 'false') === 'true') {
            throw new RuntimeException('Le profil par defaut MikroTik ne peut pas etre supprime.');
        }

        $usersCount = countMikrotikUsersByProfile($api, $profileName);
        if ($usersCount > 0) {
            throw new RuntimeException('Ce profil MikroTik est encore utilise par un ou plusieurs utilisateurs.');
        }

        $schedulerRows = $api->comm('/system/scheduler/print', [
            '?name' => $profileName,
        ]);
        if (is_array($schedulerRows)) {
            foreach ($schedulerRows as $schedulerRow) {
                if (is_array($schedulerRow) && isset($schedulerRow['.id'])) {
                    $api->comm('/system/scheduler/remove', [
                        '.id' => (string)$schedulerRow['.id'],
                    ]);
                }
            }
        }

        $response = $api->comm('/ip/hotspot/user/profile/remove', [
            '.id' => (string)$existing['.id'],
        ]);

        if (mikrotikResponseHasTrap($response)) {
            throw new RuntimeException('Le routeur MikroTik a refuse la suppression du profil.');
        }

        $stillExisting = findMikrotikProfileById($api, (string)$existing['.id']);
        if ($stillExisting) {
            throw new RuntimeException('Le profil existe encore sur MikroTik apres la suppression.');
        }

        invalidateMikrotikHotspotProfilesCache($nasContext['device'] ?? null);
    } finally {
        $api->disconnect();
    }
}

function findMikrotikUserByName(RouterosAPI $api, string $username): ?array
{
    $result = $api->comm('/ip/hotspot/user/print', [
        '?name' => $username,
    ]);

    return is_array($result) && isset($result[0]) && is_array($result[0]) ? $result[0] : null;
}

function recordRechargeInMikrotik(
    string $username,
    string $profileName,
    string $mode,
    string $operator,
    string $effectSummary,
    ?array $nasContext = null,
    int $maxEntries = 100,
    ?RouterosAPI $api = null
): void {
    $username = trim($username);
    $profileName = trim($profileName);
    $mode = trim($mode);
    $operator = trim($operator) !== '' ? trim($operator) : 'system';
    $effectSummary = trim($effectSummary);

    if ($username === '' || $profileName === '' || $mode === '') {
        return;
    }

    $ownsConnection = $api === null;
    $api = $api ?? (
        $nasContext !== null
            ? connectToMikrotikApiForNasContext($nasContext)
            : connectToActiveMikrotikApi()
    );

    try {
        $timestamp = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $isoTimestamp = $timestamp->format('Y-m-d H:i:s');
        $owner = $timestamp->format('Ym');
        $safeUser = preg_replace('/[^A-Za-z0-9_.-]/', '-', $username);
        $safeProfile = preg_replace('/[^A-Za-z0-9_.-]/', '-', $profileName);
        $safeMode = preg_replace('/[^A-Za-z0-9_.-]/', '-', $mode);
        $name = sprintf(
            '%s-|-recharge-|-user-%s-|-profile-%s-|-mode-%s',
            $timestamp->format('YmdHis'),
            $safeUser,
            $safeProfile,
            $safeMode
        );

        $source = sprintf(
            'date=%s;user=%s;profile=%s;mode=%s;operator=%s;effect=%s',
            $isoTimestamp,
            $username,
            $profileName,
            $mode,
            $operator,
            $effectSummary
        );

        $response = $api->comm('/system/script/add', [
            'name' => $name,
            'owner' => $owner,
            'source' => $source,
            'comment' => 'radius-manager-recharge',
        ]);
        mikrotikAssertNoTrap(
            $response,
            'Le routeur MikroTik a refuse l enregistrement de l historique de recharge.'
        );

        purgeMikrotikRechargeHistory($api, $maxEntries);
    } finally {
        disconnectMikrotikApiIfOwned($api, $ownsConnection);
    }
}

function purgeMikrotikRechargeHistory(RouterosAPI $api, int $maxEntries = 100): void
{
    if ($maxEntries < 1) {
        $maxEntries = 1;
    }

    $rows = $api->comm('/system/script/print', [
        '?comment' => 'radius-manager-recharge',
    ]);

    if (!is_array($rows) || count($rows) <= $maxEntries) {
        return;
    }

    usort($rows, static function (array $a, array $b): int {
        $sourceA = (string)($a['source'] ?? '');
        $sourceB = (string)($b['source'] ?? '');

        preg_match('/date=([0-9:-]{19})/', $sourceA, $matchA);
        preg_match('/date=([0-9:-]{19})/', $sourceB, $matchB);

        $dateA = $matchA[1] ?? '';
        $dateB = $matchB[1] ?? '';

        return strcmp($dateA, $dateB);
    });

    $toDelete = array_slice($rows, 0, count($rows) - $maxEntries);
    foreach ($toDelete as $row) {
        if (is_array($row) && isset($row['.id'])) {
            $response = $api->comm('/system/script/remove', [
                '.id' => (string)$row['.id'],
            ]);
            mikrotikAssertNoTrap(
                $response,
                'Le routeur MikroTik a refuse la purge de l historique de recharge.'
            );
        }
    }
}

function buildMikrotikUserPayload(array $user, string $groupname): array
{
    $status = (string)($user['status'] ?? 'active');
    $payload = [
        'server' => 'all',
        'name' => (string)$user['username'],
        'password' => (string)$user['password'],
        'profile' => $groupname,
        'disabled' => $status === 'disabled' ? 'yes' : 'no',
    ];

    $sessionTimeout = (int)($user['session_timeout'] ?? 0);
    if ($sessionTimeout > 0) {
        $payload['limit-uptime'] = mikrotikIntervalFromSeconds($sessionTimeout);
    }

    $dataLimit = (int)($user['data_limit'] ?? 0);
    if ($dataLimit > 0) {
        $payload['limit-bytes-total'] = (string)mikrotikBytesFromMegabytes($dataLimit);
    }

    if (!empty($user['expiration_date'])) {
        $payload['comment'] = (string)$user['expiration_date'];
    }

    return $payload;
}

function syncUserToMikrotik(array $user, string $groupname, ?array $nasContext = null): void
{
    $api = $nasContext !== null
        ? connectToMikrotikApiForNasContext($nasContext)
        : connectToActiveMikrotikApi();

    try {
        $existing = findMikrotikUserByName($api, (string)$user['username']);
        if ($existing) {
            throw new RuntimeException('Utilisateur deja present sur MikroTik.');
        }

        $response = $api->comm('/ip/hotspot/user/add', buildMikrotikUserPayload($user, $groupname));
        mikrotikAssertNoTrap($response, 'Le routeur MikroTik a refuse la creation de l utilisateur.');

        $created = findMikrotikUserByName($api, (string)$user['username']);
        if (!$created) {
            throw new RuntimeException('Utilisateur introuvable sur MikroTik apres creation.');
        }

        invalidateMikrotikHotspotUsersCache($nasContext['device'] ?? null);
    } finally {
        $api->disconnect();
    }
}

function updateUserInMikrotik(array $user, string $groupname, ?array $nasContext = null): void
{
    $api = $nasContext !== null
        ? connectToMikrotikApiForNasContext($nasContext)
        : connectToActiveMikrotikApi();

    try {
        $existing = findMikrotikUserByName($api, (string)($user['old_username'] ?? $user['username']));
        if (!$existing) {
            throw new RuntimeException('Utilisateur introuvable sur MikroTik.');
        }

        $payload = buildMikrotikUserPayload($user, $groupname);
        $payload['.id'] = (string)$existing['.id'];

        $response = $api->comm('/ip/hotspot/user/set', $payload);
        mikrotikAssertNoTrap($response, 'Le routeur MikroTik a refuse la mise a jour de l utilisateur.');
        invalidateMikrotikHotspotUsersCache($nasContext['device'] ?? null);
    } finally {
        $api->disconnect();
    }
}

function updateMikrotikUserCredentials(string $oldUsername, string $newUsername, ?string $password = null, ?array $nasContext = null): void
{
    $oldUsername = trim($oldUsername);
    $newUsername = trim($newUsername);

    if ($oldUsername === '' || $newUsername === '') {
        throw new RuntimeException('Utilisateur MikroTik manquant.');
    }

    $api = $nasContext !== null
        ? connectToMikrotikApiForNasContext($nasContext)
        : connectToActiveMikrotikApi();

    try {
        $existing = findMikrotikUserByName($api, $oldUsername);
        if (!$existing || !isset($existing['.id'])) {
            throw new RuntimeException('Utilisateur introuvable sur MikroTik.');
        }

        $payload = [
            '.id' => (string)$existing['.id'],
            'name' => $newUsername,
        ];

        if ($password !== null && trim($password) !== '') {
            $payload['password'] = trim($password);
        }

        $response = $api->comm('/ip/hotspot/user/set', $payload);
        mikrotikAssertNoTrap($response, 'Le routeur MikroTik a refuse la mise a jour de l utilisateur.');
        invalidateMikrotikHotspotUsersCache($nasContext['device'] ?? null);
    } finally {
        $api->disconnect();
    }
}

function deleteUserFromMikrotik(string $username, ?array $nasContext = null): void
{
    $username = trim($username);
    if ($username === '') {
        throw new RuntimeException('Utilisateur MikroTik manquant.');
    }

    $api = $nasContext !== null
        ? connectToMikrotikApiForNasContext($nasContext)
        : connectToActiveMikrotikApi();

    try {
        $existing = findMikrotikUserByName($api, $username);
        if (!$existing || !isset($existing['.id'])) {
            return;
        }

        removeMikrotikUserScheduler($api, $username);
        $response = $api->comm('/ip/hotspot/user/remove', [
            '.id' => (string)$existing['.id'],
        ]);
        mikrotikAssertNoTrap($response, 'Le routeur MikroTik a refuse la suppression de l utilisateur.');
        invalidateMikrotikHotspotUsersCache($nasContext['device'] ?? null);
    } finally {
        $api->disconnect();
    }
}

function removeMikrotikUserScheduler(RouterosAPI $api, string $username): void
{
    $username = trim($username);
    if ($username === '') {
        return;
    }

    $rows = $api->comm('/system/scheduler/print', [
        '?name' => $username,
    ]);

    if (!is_array($rows)) {
        return;
    }

    foreach ($rows as $row) {
        if (is_array($row) && isset($row['.id'])) {
            $api->comm('/system/scheduler/remove', [
                '.id' => (string)$row['.id'],
            ]);
        }
    }
}

function replaceUserOfferInMikrotik(string $username, string $profileName, ?array $nasContext = null, ?RouterosAPI $api = null): void
{
    $username = trim($username);
    $profileName = trim($profileName);

    if ($username === '' || $profileName === '') {
        throw new RuntimeException('Utilisateur ou profil MikroTik manquant.');
    }

    $ownsConnection = $api === null;
    $api = $api ?? (
        $nasContext !== null
            ? connectToMikrotikApiForNasContext($nasContext)
            : connectToActiveMikrotikApi()
    );

    try {
        $existingUser = findMikrotikUserByName($api, $username);
        if (!$existingUser || !isset($existingUser['.id'])) {
            throw new RuntimeException('Utilisateur introuvable sur MikroTik.');
        }

        $profile = findMikrotikProfileByName($api, $profileName);
        if (!$profile) {
            throw new RuntimeException('Profil MikroTik introuvable.');
        }

        $payload = [
            '.id' => (string)$existingUser['.id'],
            'profile' => $profileName,
            'comment' => '',
        ];

        /* Quota temps user = session-timeout du profil uniquement (jamais validity on-login). */
        $offerTimeSec = mikrotikProfileOfferTimeSeconds($profile);
        $payload['limit-uptime'] = $offerTimeSec > 0 ? mikrotikIntervalFromSeconds($offerTimeSec) : '0';

        $profileQuotaMb = mikrotikProfileDataQuotaMb($api, $profileName);
        $payload['limit-bytes-total'] = $profileQuotaMb > 0
            ? (string)mikrotikBytesFromMegabytes($profileQuotaMb)
            : '0';

        $response = $api->comm('/ip/hotspot/user/set', $payload);
        mikrotikAssertNoTrap(
            $response,
            'Le routeur MikroTik a refuse la mise a jour utilisateur lors du changement d offre.'
        );

        $response = $api->comm('/ip/hotspot/user/reset-counters', [
            '.id' => (string)$existingUser['.id'],
        ]);
        mikrotikAssertNoTrap(
            $response,
            'Le routeur MikroTik a refuse la reinitialisation des compteurs utilisateur.'
        );

        removeMikrotikUserScheduler($api, $username);
        invalidateMikrotikHotspotUsersCache($nasContext['device'] ?? null);
    } finally {
        disconnectMikrotikApiIfOwned($api, $ownsConnection);
    }
}

/**
 * Secondes de quota temps (Time Limit) pour limit-uptime / recharge : uniquement session-timeout du profil Hotspot.
 * Ne pas utiliser metadata validity (validite commerciale) — risque d ecrire 1j a la place de 1h.
 */
function mikrotikProfileOfferTimeSeconds(array $profile): int
{
    return parseRouterosIntervalToSeconds(trim((string)($profile['session-timeout'] ?? '')));
}

function mikrotikProfileValiditySeconds(array $profile): int
{
    $metadata = parseMikrotikOnLoginMetadata((string)($profile['on-login'] ?? ''));
    $validityRaw = trim((string)($metadata['validity'] ?? ''));
    $validitySeconds = parseRouterosIntervalToSeconds($validityRaw);

    if ($validitySeconds <= 0 && preg_match('/^\s*(\d+)\s*s?\s*$/i', $validityRaw, $matches)) {
        $validitySeconds = (int)$matches[1];
    }

    return max(0, $validitySeconds);
}

/**
 * Restants reels depuis une ligne /ip/hotspot/user (consommation cumulee), pas la session active.
 *
 * @return array{time_remaining_sec: int, data_remaining_bytes: int|null, limit_uptime_sec: int, limit_bytes_total: float}
 */
function mikrotikUserRemainingFromHotspotUserRow(array $userRow): array
{
    $limitUptimeSec = parseRouterosIntervalToSeconds(trim((string)($userRow['limit-uptime'] ?? '')));
    $usedUptimeSec = parseRouterosIntervalToSeconds(trim((string)($userRow['uptime'] ?? '')));
    $timeRemainingSec = $limitUptimeSec > 0 ? max(0, $limitUptimeSec - $usedUptimeSec) : 0;

    $limitBytes = (float)($userRow['limit-bytes-total'] ?? 0);
    $consumedBytes = (float)($userRow['bytes-in'] ?? 0) + (float)($userRow['bytes-out'] ?? 0);
    $dataRemainingBytes = $limitBytes > 0 ? max(0, $limitBytes - $consumedBytes) : null;

    return [
        'time_remaining_sec' => $timeRemainingSec,
        'data_remaining_bytes' => $dataRemainingBytes,
        'limit_uptime_sec' => $limitUptimeSec,
        'limit_bytes_total' => $limitBytes,
    ];
}

function extendUserOfferInMikrotik(string $username, string $profileName, ?array $nasContext = null, ?RouterosAPI $api = null): void
{
    $username = trim($username);
    $profileName = trim($profileName);

    if ($username === '' || $profileName === '') {
        throw new RuntimeException('Utilisateur ou profil MikroTik manquant.');
    }

    $ownsConnection = $api === null;
    $api = $api ?? (
        $nasContext !== null
            ? connectToMikrotikApiForNasContext($nasContext)
            : connectToActiveMikrotikApi()
    );

    try {
        $existingUser = findMikrotikUserByName($api, $username);
        if (!$existingUser || !isset($existingUser['.id'])) {
            throw new RuntimeException('Utilisateur introuvable sur MikroTik.');
        }

        $profile = findMikrotikProfileByName($api, $profileName);
        if (!$profile) {
            throw new RuntimeException('Profil MikroTik introuvable.');
        }

        $offerSeconds = mikrotikProfileOfferTimeSeconds($profile);
        $offerValiditySeconds = mikrotikProfileValiditySeconds($profile);

        $rem = mikrotikUserRemainingFromHotspotUserRow($existingUser);
        $newTimeSeconds = $rem['time_remaining_sec'] + $offerSeconds;

        $payload = [
            '.id' => (string)$existingUser['.id'],
        ];

        $payload['limit-uptime'] = $newTimeSeconds > 0 ? mikrotikIntervalFromSeconds($newTimeSeconds) : '0';

        $profileQuotaMb = mikrotikProfileDataQuotaMb($api, $profileName);
        $offerDataBytes = $profileQuotaMb > 0 ? mikrotikBytesFromMegabytes($profileQuotaMb) : 0;
        $dataRem = $rem['data_remaining_bytes'];
        $newDataBytes = $rem['limit_bytes_total'] > 0 && $dataRem !== null
            ? max(0, (int)round($dataRem + $offerDataBytes))
            : max(0, $offerDataBytes);
        $payload['limit-bytes-total'] = (string)$newDataBytes;

        $today = new DateTimeImmutable('today', new DateTimeZone('UTC'));
        $currentExpiration = parseRechargeExpirationDate((string)($existingUser['comment'] ?? ''));
        if (!$currentExpiration instanceof DateTimeImmutable || $currentExpiration < $today) {
            throw new RuntimeException('Le rechargement est disponible uniquement pour un compte non expire.');
        }
        $nextExpiration = $offerValiditySeconds > 0
            ? addSecondsToDate($currentExpiration, $offerValiditySeconds)
            : $currentExpiration;
        $payload['comment'] = formatRechargeExpirationDate($nextExpiration);

        $response = $api->comm('/ip/hotspot/user/set', $payload);
        mikrotikAssertNoTrap(
            $response,
            'Le routeur MikroTik a refuse la mise a jour utilisateur lors du rechargement.'
        );

        $response = $api->comm('/ip/hotspot/user/reset-counters', [
            '.id' => (string)$existingUser['.id'],
        ]);
        mikrotikAssertNoTrap(
            $response,
            'Le routeur MikroTik a refuse la reinitialisation des compteurs utilisateur.'
        );
        invalidateMikrotikHotspotUsersCache($nasContext['device'] ?? null);
    } finally {
        disconnectMikrotikApiIfOwned($api, $ownsConnection);
    }
}

function accumulateUserOfferInMikrotik(string $username, string $profileName, ?array $nasContext = null, ?RouterosAPI $api = null): void
{
    $username = trim($username);
    $profileName = trim($profileName);

    if ($username === '' || $profileName === '') {
        throw new RuntimeException('Utilisateur ou profil MikroTik manquant.');
    }

    $ownsConnection = $api === null;
    $api = $api ?? (
        $nasContext !== null
            ? connectToMikrotikApiForNasContext($nasContext)
            : connectToActiveMikrotikApi()
    );

    try {
        $existingUser = findMikrotikUserByName($api, $username);
        if (!$existingUser || !isset($existingUser['.id'])) {
            throw new RuntimeException('Utilisateur introuvable sur MikroTik.');
        }

        $currentProfile = trim((string)($existingUser['profile'] ?? ''));
        if ($currentProfile !== $profileName) {
            throw new RuntimeException('Le cumul n est autorise que sur le meme profil.');
        }

        $currentExpiration = parseRechargeExpirationDate((string)($existingUser['comment'] ?? ''));
        $today = new DateTimeImmutable('today', new DateTimeZone('UTC'));

        $profile = findMikrotikProfileByName($api, $profileName);
        if (!$profile) {
            throw new RuntimeException('Profil MikroTik introuvable.');
        }

        $offerSeconds = mikrotikProfileOfferTimeSeconds($profile);
        $offerValiditySeconds = mikrotikProfileValiditySeconds($profile);

        $rem = mikrotikUserRemainingFromHotspotUserRow($existingUser);
        $newTimeSeconds = $rem['time_remaining_sec'] + $offerSeconds;

        $profileQuotaMb = mikrotikProfileDataQuotaMb($api, $profileName);
        $offerDataBytes = $profileQuotaMb > 0 ? mikrotikBytesFromMegabytes($profileQuotaMb) : 0;
        $dataRem = $rem['data_remaining_bytes'];
        $newDataBytes = $rem['limit_bytes_total'] > 0 && $dataRem !== null
            ? max(0, (int)round($dataRem + $offerDataBytes))
            : max(0, $offerDataBytes);

        $payload = [
            '.id' => (string)$existingUser['.id'],
            'limit-uptime' => $newTimeSeconds > 0 ? mikrotikIntervalFromSeconds($newTimeSeconds) : '0',
            'limit-bytes-total' => (string)$newDataBytes,
        ];

        $nextExpiration = null;
        if ($currentExpiration instanceof DateTimeImmutable && $currentExpiration >= $today) {
            $nextExpiration = $offerValiditySeconds > 0 ? addSecondsToDate($currentExpiration, $offerValiditySeconds) : $currentExpiration;
        }
        $payload['comment'] = formatRechargeExpirationDate($nextExpiration);

        $response = $api->comm('/ip/hotspot/user/set', $payload);
        mikrotikAssertNoTrap(
            $response,
            'Le routeur MikroTik a refuse la mise a jour utilisateur lors du cumul.'
        );

        $response = $api->comm('/ip/hotspot/user/reset-counters', [
            '.id' => (string)$existingUser['.id'],
        ]);
        mikrotikAssertNoTrap(
            $response,
            'Le routeur MikroTik a refuse la reinitialisation des compteurs utilisateur.'
        );
        invalidateMikrotikHotspotUsersCache($nasContext['device'] ?? null);
    } finally {
        disconnectMikrotikApiIfOwned($api, $ownsConnection);
    }
}

function deleteUserFromActiveMikrotik(string $username): void
{
    $api = connectToActiveMikrotikApi();

    try {
        $existing = findMikrotikUserByName($api, $username);
        if (!$existing) {
            return;
        }

        $api->comm('/ip/hotspot/user/remove', [
            '.id' => (string)$existing['.id'],
        ]);
    } finally {
        $api->disconnect();
    }
}

function mikrotikFirstRow($response): array
{
    return is_array($response) && isset($response[0]) && is_array($response[0]) ? $response[0] : [];
}

function getMikrotikSystemClock(): array
{
    $api = connectToActiveMikrotikApi();

    try {
        return mikrotikFirstRow($api->comm('/system/clock/print'));
    } finally {
        $api->disconnect();
    }
}

function getMikrotikSystemResource(): array
{
    $api = connectToActiveMikrotikApi();

    try {
        return mikrotikFirstRow($api->comm('/system/resource/print'));
    } finally {
        $api->disconnect();
    }
}

function getMikrotikRouterboardInfo(): array
{
    $api = connectToActiveMikrotikApi();

    try {
        return mikrotikFirstRow($api->comm('/system/routerboard/print'));
    } finally {
        $api->disconnect();
    }
}

function getMikrotikSchedulers(int $limit = 200): array
{
    $api = connectToActiveMikrotikApi();

    try {
        $rows = $api->comm('/system/scheduler/print');
        if (!is_array($rows)) {
            return [];
        }

        $items = array_map(static function (array $row): array {
            return [
                'id' => (string)($row['.id'] ?? ''),
                'name' => (string)($row['name'] ?? ''),
                'on_event' => (string)($row['on-event'] ?? ''),
                'interval' => (string)($row['interval'] ?? ''),
                'next_run' => (string)($row['next-run'] ?? ''),
                'start_date' => (string)($row['start-date'] ?? ''),
                'start_time' => (string)($row['start-time'] ?? ''),
                'disabled' => strtolower((string)($row['disabled'] ?? 'false')) === 'true',
                'comment' => (string)($row['comment'] ?? ''),
            ];
        }, $rows);

        usort($items, static function (array $a, array $b): int {
            return strcasecmp((string)($a['name'] ?? ''), (string)($b['name'] ?? ''));
        });

        return $limit > 0 ? array_slice($items, 0, $limit) : $items;
    } finally {
        $api->disconnect();
    }
}

function addMikrotikScheduler(array $data): void
{
    $name = trim((string)($data['name'] ?? ''));
    $onEvent = trim((string)($data['on_event'] ?? ''));

    if ($name === '') {
        throw new RuntimeException('Nom du scheduler obligatoire');
    }

    if ($onEvent === '') {
        throw new RuntimeException('Tache obligatoire');
    }

    $api = connectToActiveMikrotikApi();

    try {
        $payload = [
            'name' => $name,
            'on-event' => $onEvent,
            'disabled' => !empty($data['disabled']) ? 'true' : 'false',
        ];

        $interval = trim((string)($data['interval'] ?? ''));
        $startDate = trim((string)($data['start_date'] ?? ''));
        $startTime = trim((string)($data['start_time'] ?? ''));
        $comment = trim((string)($data['comment'] ?? ''));

        if ($interval !== '') {
            $payload['interval'] = $interval;
        }
        if ($startDate !== '') {
            $payload['start-date'] = $startDate;
        }
        if ($startTime !== '') {
            $payload['start-time'] = $startTime;
        }
        if ($comment !== '') {
            $payload['comment'] = $comment;
        }

        $api->comm('/system/scheduler/add', $payload);
    } finally {
        $api->disconnect();
    }
}

function updateMikrotikScheduler(string $schedulerId, array $data): void
{
    $schedulerId = trim($schedulerId);
    $name = trim((string)($data['name'] ?? ''));
    $onEvent = trim((string)($data['on_event'] ?? ''));

    if ($schedulerId === '') {
        throw new RuntimeException('Scheduler invalide');
    }

    if ($name === '') {
        throw new RuntimeException('Nom du scheduler obligatoire');
    }

    if ($onEvent === '') {
        throw new RuntimeException('Tache obligatoire');
    }

    $api = connectToActiveMikrotikApi();

    try {
        $payload = [
            '.id' => $schedulerId,
            'name' => $name,
            'on-event' => $onEvent,
            'disabled' => !empty($data['disabled']) ? 'true' : 'false',
            'interval' => trim((string)($data['interval'] ?? '')),
            'start-date' => trim((string)($data['start_date'] ?? '')),
            'start-time' => trim((string)($data['start_time'] ?? '')),
            'comment' => trim((string)($data['comment'] ?? '')),
        ];

        $api->comm('/system/scheduler/set', $payload);
    } finally {
        $api->disconnect();
    }
}

function removeMikrotikScheduler(string $schedulerId): void
{
    $schedulerId = trim($schedulerId);
    if ($schedulerId === '') {
        throw new RuntimeException('Scheduler invalide');
    }

    $api = connectToActiveMikrotikApi();

    try {
        $api->comm('/system/scheduler/remove', [
            '.id' => $schedulerId,
        ]);
    } finally {
        $api->disconnect();
    }
}

function getMikrotikHotspotUsersCount(): int
{
    $api = connectToActiveMikrotikApi();

    try {
        return (int)$api->comm('/ip/hotspot/user/print', [
            'count-only' => '',
        ]);
    } finally {
        $api->disconnect();
    }
}

function getMikrotikHotspotActiveCount(): int
{
    $api = connectToActiveMikrotikApi();

    try {
        return (int)$api->comm('/ip/hotspot/active/print', [
            'count-only' => '',
        ]);
    } finally {
        $api->disconnect();
    }
}

function getMikrotikHotspotActiveUsers(int $limit = 5): array
{
    $api = connectToActiveMikrotikApi();

    try {
        $rows = $api->comm('/ip/hotspot/active/print');
        if (!is_array($rows)) {
            return [];
        }

        $users = $api->comm('/ip/hotspot/user/print');
        $profilesByUser = [];
        if (is_array($users)) {
            foreach ($users as $userRow) {
                if (!is_array($userRow)) {
                    continue;
                }

                $username = trim((string)($userRow['name'] ?? ''));
                if ($username === '') {
                    continue;
                }

                $profilesByUser[$username] = (string)($userRow['profile'] ?? '');
            }
        }

        $rows = array_slice($rows, 0, max(0, $limit));

        return array_map(static function (array $row) use ($profilesByUser): array {
            $username = (string)($row['user'] ?? $row['name'] ?? 'Utilisateur');

            return [
                'id' => (string)($row['.id'] ?? ''),
                'user' => $username,
                'profile' => (string)($profilesByUser[$username] ?? ''),
                'address' => (string)($row['address'] ?? ''),
                'mac' => (string)($row['mac-address'] ?? ''),
                'uptime' => (string)($row['uptime'] ?? ''),
                'server' => (string)($row['server'] ?? ''),
                'login_by' => (string)($row['login-by'] ?? ''),
                'session_time_left' => (string)($row['session-time-left'] ?? ''),
                'idle_time' => (string)($row['idle-time'] ?? ''),
                'bytes_in' => (float)($row['bytes-in'] ?? 0),
                'bytes_out' => (float)($row['bytes-out'] ?? 0),
            ];
        }, $rows);
    } finally {
        $api->disconnect();
    }
}

function getMikrotikHotspotUsers(int $limit = 100, ?array $deviceOverride = null): array
{
    $cacheTtlSeconds = 60;
    $activeDevice = $deviceOverride ?? (getActiveDeviceContext()['device'] ?? null);
    $cacheKey = $activeDevice['id'] ?? ($activeDevice['host'] ?? 'active');
    $cacheFile = sys_get_temp_dir() . '/mikrotik_hotspot_users_' . md5((string)$cacheKey . '|' . (string)$limit) . '.json';

    if (is_file($cacheFile) && (time() - filemtime($cacheFile) <= $cacheTtlSeconds)) {
        $cached = json_decode((string)file_get_contents($cacheFile), true);
        if (is_array($cached)) {
            return $cached;
        }
    }

    try {
        $api = is_array($activeDevice) && ($activeDevice['type'] ?? '') === 'mikrotik'
            ? connectToMikrotikApiByDevice($activeDevice)
            : connectToActiveMikrotikApi();
    } catch (Throwable $e) {
        if (is_file($cacheFile)) {
            $cached = json_decode((string)file_get_contents($cacheFile), true);
            if (is_array($cached)) {
                return $cached;
            }
        }
        throw $e;
    }

    try {
        $profileMetaByName = [];
        $profileCacheFile = sys_get_temp_dir() . '/mikrotik_hotspot_profiles_' . md5((string)$cacheKey) . '.json';
        if (is_file($profileCacheFile) && (time() - filemtime($profileCacheFile) <= $cacheTtlSeconds)) {
            $cachedProfiles = json_decode((string)file_get_contents($profileCacheFile), true);
            if (is_array($cachedProfiles)) {
                $profileMetaByName = $cachedProfiles;
            }
        }
        if ($profileMetaByName === []) {
            $profiles = $api->comm('/ip/hotspot/user/profile/print', [
                '.proplist' => '.id,name,rate-limit,shared-users,limit-bytes-total,on-login,session-timeout,address-pool',
            ]);
            if (is_array($profiles)) {
                foreach ($profiles as $profileRow) {
                    if (!is_array($profileRow)) {
                        continue;
                    }
                    $name = trim((string)($profileRow['name'] ?? ''));
                    if ($name === '') {
                        continue;
                    }
                    $metadata = parseMikrotikOnLoginMetadata((string)($profileRow['on-login'] ?? ''));
                    $validitySeconds = parseRouterosIntervalToSeconds((string)($metadata['validity'] ?? ''));
                    $sessionTimeoutSeconds = parseRouterosIntervalToSeconds((string)($profileRow['session-timeout'] ?? ''));
                    $profileMetaByName[$name] = [
                        'rate_limit' => trim((string)($profileRow['rate-limit'] ?? '')),
                        'shared_users' => (int)($profileRow['shared-users'] ?? 0),
                        'validity_seconds' => $validitySeconds,
                        'data_limit_bytes' => (string)($profileRow['limit-bytes-total'] ?? ''),
                        'session_timeout_seconds' => $sessionTimeoutSeconds,
                    ];
                }
                file_put_contents($profileCacheFile, json_encode($profileMetaByName, JSON_UNESCAPED_SLASHES));
            }
        }

        $users = $api->comm('/ip/hotspot/user/print', [
            '.proplist' => '.id,name,password,profile,server,mac-address,comment,limit-uptime,limit-bytes-total,bytes-in,bytes-out,disabled,uptime',
        ]);
        $activeRows = $api->comm('/ip/hotspot/active/print', [
            '.proplist' => '.id,user,name,address,mac-address,uptime,server,login-by,bytes-in,bytes-out',
        ]);

        if (!is_array($users)) {
            return [];
        }

        /* Cumuls / quota par utilisateur : /ip/hotspot/user (separe du live /ip/hotspot/active) */
        $userStatsByName = [];
        foreach ($users as $userRow) {
            if (!is_array($userRow)) {
                continue;
            }
            $uname = trim((string)($userRow['name'] ?? ''));
            if ($uname === '') {
                continue;
            }
            $ubIn = (float)($userRow['bytes-in'] ?? 0);
            $ubOut = (float)($userRow['bytes-out'] ?? 0);
            $userStatsByName[$uname] = [
                'user_bytes_in' => $ubIn,
                'user_bytes_out' => $ubOut,
                'user_bytes_total' => $ubIn + $ubOut,
                'limit_bytes_total' => (float)($userRow['limit-bytes-total'] ?? 0),
                /* Temps comptabilise cote entree utilisateur (RouterOS), distinct du uptime session active */
                'user_session_time_seconds' => parseRouterosIntervalToSeconds((string)($userRow['uptime'] ?? '')),
            ];
        }

        $activeByUser = [];
        if (is_array($activeRows)) {
            foreach ($activeRows as $activeRow) {
                if (!is_array($activeRow)) {
                    continue;
                }

                $username = trim((string)($activeRow['user'] ?? $activeRow['name'] ?? ''));
                if ($username === '') {
                    continue;
                }

                $activeByUser[$username] = [
                    'id' => (string)($activeRow['.id'] ?? ''),
                    'address' => (string)($activeRow['address'] ?? ''),
                    'mac' => (string)($activeRow['mac-address'] ?? ''),
                    'uptime' => (string)($activeRow['uptime'] ?? ''),
                    'server' => (string)($activeRow['server'] ?? ''),
                    'login_by' => (string)($activeRow['login-by'] ?? ''),
                    'bytes_in' => (float)($activeRow['bytes-in'] ?? 0),
                    'bytes_out' => (float)($activeRow['bytes-out'] ?? 0),
                ];
            }
        }

        $rows = array_map(static function (array $row) use ($activeByUser, $profileMetaByName, $userStatsByName): array {
            $username = trim((string)($row['name'] ?? ''));
            $userStats = $username !== '' ? ($userStatsByName[$username] ?? null) : null;
            $defaultStats = [
                'user_bytes_in' => 0.0,
                'user_bytes_out' => 0.0,
                'user_bytes_total' => 0.0,
                'limit_bytes_total' => 0.0,
                'user_session_time_seconds' => 0,
            ];
            $userStats = $userStats ?? $defaultStats;

            $userBytesTotal = (float)($userStats['user_bytes_total'] ?? 0);
            $limitBytesTotal = (float)($userStats['limit_bytes_total'] ?? 0);

            /* 0 = illimite (RouterOS) */
            $remainingBytes = $limitBytesTotal > 0
                ? max(0, $limitBytesTotal - $userBytesTotal)
                : null;

            $active = $username !== '' ? ($activeByUser[$username] ?? null) : null;
            $profileName = (string)($row['profile'] ?? '');
            $profileMeta = $profileMetaByName[$profileName] ?? [];
            $profileRateLimit = trim((string)($profileMeta['rate_limit'] ?? ''));

            return [
                'id' => (string)($row['.id'] ?? ''),
                'username' => $username !== '' ? $username : 'Utilisateur',
                'password' => (string)($row['password'] ?? ''),
                'profile' => $profileName,
                'server' => (string)($row['server'] ?? ''),
                'mac' => (string)($row['mac-address'] ?? ''),
                'comment' => (string)($row['comment'] ?? ''),
                'limit_uptime' => (string)($row['limit-uptime'] ?? ''),
                'data_limit' => (string)($row['limit-bytes-total'] ?? ''),
                'profile_data_limit' => (string)($profileMeta['data_limit_bytes'] ?? ''),
                'profile_validity_seconds' => (int)($profileMeta['validity_seconds'] ?? 0),
                'profile_session_timeout_seconds' => (int)($profileMeta['session_timeout_seconds'] ?? 0),
                'rate_limit' => $profileRateLimit,
                'shared_users' => isset($profileMeta['shared_users']) ? (int)$profileMeta['shared_users'] : null,
                'disabled' => strtolower((string)($row['disabled'] ?? 'false')) === 'true',
                'active_session_id' => $active !== null ? (string)($active['id'] ?? '') : '',
                'active_address' => $active !== null ? (string)($active['address'] ?? '') : '',
                'active_mac' => $active !== null ? (string)($active['mac'] ?? '') : '',
                'active_uptime' => $active !== null ? (string)($active['uptime'] ?? '') : '',
                'active_server' => $active !== null ? (string)($active['server'] ?? '') : '',
                'login_by' => $active !== null ? (string)($active['login_by'] ?? '') : '',
                /* /ip/hotspot/active : session en cours uniquement (ne pas confondre avec les cumuls user) */
                'bytes_in' => $active !== null ? (float)($active['bytes_in'] ?? 0) : 0.0,
                'bytes_out' => $active !== null ? (float)($active['bytes_out'] ?? 0) : 0.0,
                /* Injecte depuis userStatsByName (source /ip/hotspot/user) */
                'user_bytes_in' => (float)($userStats['user_bytes_in'] ?? 0),
                'user_bytes_out' => (float)($userStats['user_bytes_out'] ?? 0),
                'user_bytes_total' => $userBytesTotal,
                'limit_bytes_total' => $limitBytesTotal,
                'user_session_time_seconds' => (int)($userStats['user_session_time_seconds'] ?? 0),
                'remaining_bytes' => $remainingBytes,
                'online' => $active !== null,
            ];
        }, $users);

        usort($rows, static function (array $a, array $b): int {
            if (($a['online'] ?? false) !== ($b['online'] ?? false)) {
                return ($a['online'] ?? false) ? -1 : 1;
            }

            return strcasecmp((string)($a['username'] ?? ''), (string)($b['username'] ?? ''));
        });

        $result = $limit > 0 ? array_slice($rows, 0, $limit) : $rows;
        file_put_contents($cacheFile, json_encode($result, JSON_UNESCAPED_SLASHES));
        return $result;
    } finally {
        $api->disconnect();
    }
}

function invalidateMikrotikHotspotUsersCache(?array $device = null): void
{
    foreach (glob(sys_get_temp_dir() . '/mikrotik_hotspot_users_*.json') ?: [] as $cacheFile) {
        if (is_file($cacheFile)) {
            @unlink($cacheFile);
        }
    }
}

function normalizeMikrotikExpiredModeLabel(string $label): string
{
    return match (trim($label)) {
        'Remove' => 'remove',
        'Notice' => 'notice',
        'Remove & Record' => 'remove_record',
        'Notice & Record' => 'notice_record',
        default => 'none',
    };
}

function mikrotikNumericOrNull(string $value): ?float
{
    $clean = trim($value);
    if ($clean === '' || $clean === '-') {
        return null;
    }

    return is_numeric($clean) ? (float)$clean : null;
}

function mapMikrotikHotspotProfileRow(array $profileRow): ?array
{
    $name = trim((string)($profileRow['name'] ?? ''));
    if ($name === '') {
        return null;
    }

    $metadata = parseMikrotikOnLoginMetadata((string)($profileRow['on-login'] ?? ''));
    $limitBytes = trim((string)($profileRow['limit-bytes-total'] ?? ''));
    $limitBytesValue = $limitBytes !== '' ? (float)$limitBytes : 0;
    $dataQuotaMb = $limitBytesValue > 0
        ? (int)round($limitBytesValue / 1024 / 1024)
        : (int)($metadata['data_quota_mb'] ?? 0);
    $sessionTimeoutRaw = trim((string)($profileRow['session-timeout'] ?? ''));
    $sessionTimeoutSeconds = parseRouterosIntervalToSeconds($sessionTimeoutRaw);
    $validityRaw = trim((string)($metadata['validity'] ?? ''));
    $validitySeconds = parseRouterosIntervalToSeconds($validityRaw);

    return [
        'id' => (string)($profileRow['.id'] ?? $profileRow['id'] ?? ''),
        'name' => $name,
        'rate_limit' => trim((string)($profileRow['rate-limit'] ?? $profileRow['rate_limit'] ?? '')),
        'session_timeout' => $sessionTimeoutSeconds > 0 ? $sessionTimeoutSeconds : null,
        'validity_time' => $validitySeconds > 0 ? $validitySeconds : null,
        'validity' => $validityRaw,
        'data_quota_mb' => $dataQuotaMb > 0 ? $dataQuotaMb : null,
        'simultaneous_use' => (int)($profileRow['shared-users'] ?? $profileRow['simultaneous_use'] ?? 0),
        'expired_mode' => normalizeMikrotikExpiredModeLabel((string)($metadata['expired_mode'] ?? '')),
        'price' => mikrotikNumericOrNull((string)($metadata['price'] ?? '')),
        'selling_price' => mikrotikNumericOrNull((string)($metadata['selling_price'] ?? '')),
        'lock_user' => (string)($metadata['lock_user'] ?? 'Disable') === 'Enable' ? 1 : 0,
        'ip_pool' => trim((string)($profileRow['address-pool'] ?? $profileRow['ip_pool'] ?? '')),
        'parent_queue' => trim((string)($profileRow['parent-queue'] ?? $profileRow['parent_queue'] ?? '')),
    ];
}

function readMikrotikProfileState(RouterosAPI $api, string $profileName): ?array
{
    $profileRow = findMikrotikProfileByName($api, $profileName);
    return is_array($profileRow) ? mapMikrotikHotspotProfileRow($profileRow) : null;
}

function confirmMikrotikProfileWrite(RouterosAPI $api, string $profileName): array
{
    $actual = readMikrotikProfileState($api, $profileName);
    if (!is_array($actual)) {
        return [
            'id' => '',
            'name' => $profileName,
        ];
    }

    return $actual;
}

function requireExplicitMikrotikNasContext(?array $nasContext): array
{
    if (!is_array($nasContext)) {
        throw new RuntimeException('Contexte NAS MikroTik requis pour ce flux.');
    }

    if (trim((string)($nasContext['business_source'] ?? '')) !== 'mikrotik_local') {
        throw new RuntimeException('Le contexte NAS fourni n est pas MikroTik.');
    }

    if (!isset($nasContext['device']) || !is_array($nasContext['device'])) {
        throw new RuntimeException('Le device MikroTik cible est requis pour ce flux.');
    }

    return $nasContext;
}

function loadMikrotikHotspotProfilesCached(?array $device = null, int $ttlSeconds = 60): array
{
    $deviceKey = (string)($device['id'] ?? ($device['host'] ?? 'active'));
    $cacheFile = sys_get_temp_dir() . '/mikrotik_hotspot_profile_rows_' . md5($deviceKey) . '.json';

    if (is_file($cacheFile) && (time() - filemtime($cacheFile) <= $ttlSeconds)) {
        $cached = json_decode((string)file_get_contents($cacheFile), true);
        if (is_array($cached)) {
            return $cached;
        }
    }

    $api = $device !== null
        ? connectToMikrotikApiByDevice($device)
        : connectToActiveMikrotikApi();

    try {
        $profiles = $api->comm('/ip/hotspot/user/profile/print', [
            '.proplist' => '.id,name,rate-limit,shared-users,limit-bytes-total,session-timeout,on-login,address-pool,parent-queue',
        ]);
    } finally {
        $api->disconnect();
    }

    $rows = [];
    foreach ((array)$profiles as $profileRow) {
        if (!is_array($profileRow)) {
            continue;
        }

        $mappedRow = mapMikrotikHotspotProfileRow($profileRow);
        if ($mappedRow === null) {
            continue;
        }

        $rows[] = $mappedRow;
    }

    file_put_contents($cacheFile, json_encode($rows, JSON_UNESCAPED_SLASHES));

    return $rows;
}

function invalidateMikrotikHotspotProfilesCache(?array $device = null): void
{
    $deviceKey = (string)($device['id'] ?? ($device['host'] ?? 'active'));
    $cacheFile = sys_get_temp_dir() . '/mikrotik_hotspot_profile_rows_' . md5($deviceKey) . '.json';

    if (is_file($cacheFile)) {
        @unlink($cacheFile);
    }
}

function syncMikrotikProfilesToLocal(PDO $pdo, ?array $device = null, int $ttlSeconds = 120): array
{
    return [
        'inserted' => 0,
        'updated' => 0,
        'skipped' => 0,
    ];
}

function getMikrotikHotspotHosts(int $limit = 200): array
{
    $api = connectToActiveMikrotikApi();

    try {
        $rows = $api->comm('/ip/hotspot/host/print');
        if (!is_array($rows)) {
            return [];
        }

        $items = array_map(static function (array $row): array {
            $authorized = strtolower((string)($row['authorized'] ?? 'false')) === 'true';
            $bypassed = strtolower((string)($row['bypassed'] ?? 'false')) === 'true';
            $dynamic = strtolower((string)($row['dynamic'] ?? 'false')) === 'true';
            $dhcp = strtolower((string)($row['dhcp'] ?? ($row['DHCP'] ?? 'false'))) === 'true';

            return [
                'id' => (string)($row['.id'] ?? ''),
                'address' => (string)($row['address'] ?? ''),
                'to_address' => (string)($row['to-address'] ?? ''),
                'mac' => (string)($row['mac-address'] ?? ''),
                'server' => (string)($row['server'] ?? ''),
                'uptime' => (string)($row['uptime'] ?? ''),
                'idle_time' => (string)($row['idle-time'] ?? ''),
                'found_by' => (string)($row['found-by'] ?? ''),
                'comment' => (string)($row['comment'] ?? ''),
                'authorized' => $authorized,
                'bypassed' => $bypassed,
                'dynamic' => $dynamic,
                'dhcp' => $dhcp,
                'bytes_in' => (float)($row['bytes-in'] ?? 0),
                'bytes_out' => (float)($row['bytes-out'] ?? 0),
            ];
        }, $rows);

        usort($items, static function (array $a, array $b): int {
            return strcasecmp((string)($a['address'] ?? ''), (string)($b['address'] ?? ''));
        });

        return $limit > 0 ? array_slice($items, 0, $limit) : $items;
    } finally {
        $api->disconnect();
    }
}

function removeMikrotikHost(string $hostId): void
{
    $hostId = trim($hostId);
    if ($hostId === '') {
        throw new RuntimeException('Host MikroTik introuvable.');
    }

    $api = connectToActiveMikrotikApi();

    try {
        $host = $api->comm('/ip/hotspot/host/print', [
            '?.id' => $hostId,
        ]);
        $hostRow = mikrotikFirstRow($host);

        if ($hostRow === []) {
            throw new RuntimeException('Host MikroTik introuvable.');
        }

        $response = $api->comm('/ip/hotspot/host/remove', [
            '.id' => $hostId,
        ]);

        mikrotikAssertNoTrap(
            $response,
            'Le routeur MikroTik a refuse la suppression du host.'
        );
    } finally {
        $api->disconnect();
    }
}

function getMikrotikIpBindings(int $limit = 200): array
{
    $api = connectToActiveMikrotikApi();

    try {
        $rows = $api->comm('/ip/hotspot/ip-binding/print');
        if (!is_array($rows)) {
            return [];
        }

        $items = array_map(static function (array $row): array {
            return [
                'id' => (string)($row['.id'] ?? ''),
                'address' => (string)($row['address'] ?? ''),
                'mac' => (string)($row['mac-address'] ?? ''),
                'to_address' => (string)($row['to-address'] ?? ''),
                'type' => (string)($row['type'] ?? ''),
                'server' => (string)($row['server'] ?? ''),
                'comment' => (string)($row['comment'] ?? ''),
                'disabled' => strtolower((string)($row['disabled'] ?? 'false')) === 'true',
            ];
        }, $rows);

        usort($items, static function (array $a, array $b): int {
            return strcasecmp((string)($a['address'] ?? ''), (string)($b['address'] ?? ''));
        });

        return $limit > 0 ? array_slice($items, 0, $limit) : $items;
    } finally {
        $api->disconnect();
    }
}

function updateMikrotikIpBinding(string $bindingId, array $payload): void
{
    $bindingId = trim($bindingId);
    if ($bindingId === '') {
        throw new RuntimeException('IP binding introuvable.');
    }

    $address = trim((string)($payload['address'] ?? ''));
    $macAddress = function_exists('normalizeMacAddress')
        ? normalizeMacAddress((string)($payload['mac'] ?? ''))
        : trim((string)($payload['mac'] ?? ''));
    $toAddress = trim((string)($payload['to_address'] ?? ''));
    $type = trim((string)($payload['type'] ?? ''));
    $server = trim((string)($payload['server'] ?? ''));
    $comment = trim((string)($payload['comment'] ?? ''));
    $disabled = !empty($payload['disabled']);

    if ($address === '' && $macAddress === '') {
        throw new RuntimeException('Adresse ou MAC obligatoire.');
    }

    if ($type === '') {
        throw new RuntimeException('Type de binding manquant.');
    }

    $allowedTypes = ['regular', 'bypassed', 'blocked'];
    if (!in_array(strtolower($type), $allowedTypes, true)) {
        throw new RuntimeException('Type de binding invalide.');
    }

    $api = connectToActiveMikrotikApi();

    try {
        $existing = $api->comm('/ip/hotspot/ip-binding/print', [
            '?.id' => $bindingId,
        ]);
        $bindingRow = mikrotikFirstRow($existing);

        if ($bindingRow === []) {
            throw new RuntimeException('IP binding MikroTik introuvable.');
        }

        $command = [
            '.id' => $bindingId,
            'type' => strtolower($type),
            'disabled' => $disabled ? 'true' : 'false',
        ];

        $command['address'] = $address;
        $command['mac-address'] = $macAddress;
        $command['to-address'] = $toAddress;
        $command['server'] = $server;
        $command['comment'] = $comment;

        $api->comm('/ip/hotspot/ip-binding/set', $command);
    } finally {
        $api->disconnect();
    }
}

function addMikrotikIpBinding(array $payload): void
{
    $address = trim((string)($payload['address'] ?? ''));
    $macAddress = function_exists('normalizeMacAddress')
        ? normalizeMacAddress((string)($payload['mac'] ?? ''))
        : trim((string)($payload['mac'] ?? ''));
    $toAddress = trim((string)($payload['to_address'] ?? ''));
    $type = trim((string)($payload['type'] ?? ''));
    $server = trim((string)($payload['server'] ?? ''));
    $comment = trim((string)($payload['comment'] ?? ''));
    $disabled = !empty($payload['disabled']);

    if ($address === '' && $macAddress === '') {
        throw new RuntimeException('Adresse ou MAC obligatoire.');
    }

    if ($type === '') {
        throw new RuntimeException('Type de binding manquant.');
    }

    $allowedTypes = ['regular', 'bypassed', 'blocked'];
    if (!in_array(strtolower($type), $allowedTypes, true)) {
        throw new RuntimeException('Type de binding invalide.');
    }

    $api = connectToActiveMikrotikApi();

    try {
        $command = [
            'type' => strtolower($type),
            'disabled' => $disabled ? 'true' : 'false',
        ];

        if ($address !== '') {
            $command['address'] = $address;
        }

        if ($macAddress !== '') {
            $command['mac-address'] = $macAddress;
        }

        if ($toAddress !== '') {
            $command['to-address'] = $toAddress;
        }

        if ($server !== '') {
            $command['server'] = $server;
        }

        if ($comment !== '') {
            $command['comment'] = $comment;
        }

        $api->comm('/ip/hotspot/ip-binding/add', $command);
    } finally {
        $api->disconnect();
    }
}

function removeMikrotikIpBinding(string $bindingId): void
{
    $bindingId = trim($bindingId);
    if ($bindingId === '') {
        throw new RuntimeException('IP binding introuvable.');
    }

    $api = connectToActiveMikrotikApi();

    try {
        $existing = $api->comm('/ip/hotspot/ip-binding/print', [
            '?.id' => $bindingId,
        ]);
        $bindingRow = mikrotikFirstRow($existing);

        if ($bindingRow === []) {
            throw new RuntimeException('IP binding MikroTik introuvable.');
        }

        $api->comm('/ip/hotspot/ip-binding/remove', [
            '.id' => $bindingId,
        ]);
    } finally {
        $api->disconnect();
    }
}

function getMikrotikCookies(int $limit = 200): array
{
    $api = connectToActiveMikrotikApi();

    try {
        $rows = $api->comm('/ip/hotspot/cookie/print');
        if (!is_array($rows)) {
            return [];
        }

        $items = array_map(static function (array $row): array {
            return [
                'id' => (string)($row['.id'] ?? ''),
                'user' => (string)($row['user'] ?? ''),
                'mac' => (string)($row['mac-address'] ?? ''),
                'address' => (string)($row['address'] ?? ''),
                'server' => (string)($row['server'] ?? ''),
                'expires_in' => (string)($row['expires-in'] ?? ''),
                'uptime' => (string)($row['uptime'] ?? ''),
            ];
        }, $rows);

        usort($items, static function (array $a, array $b): int {
            return strcasecmp((string)($a['user'] ?? ''), (string)($b['user'] ?? ''));
        });

        return $limit > 0 ? array_slice($items, 0, $limit) : $items;
    } finally {
        $api->disconnect();
    }
}

function removeMikrotikCookie(string $cookieId): void
{
    $cookieId = trim($cookieId);
    if ($cookieId === '') {
        throw new RuntimeException('Cookie MikroTik introuvable.');
    }

    $api = connectToActiveMikrotikApi();

    try {
        $cookie = $api->comm('/ip/hotspot/cookie/print', [
            '?.id' => $cookieId,
        ]);
        $cookieRow = mikrotikFirstRow($cookie);

        if ($cookieRow === []) {
            throw new RuntimeException('Cookie MikroTik introuvable.');
        }

        $api->comm('/ip/hotspot/cookie/remove', [
            '.id' => $cookieId,
        ]);
    } finally {
        $api->disconnect();
    }
}

function getMikrotikDhcpLeases(int $limit = 200): array
{
    $api = connectToActiveMikrotikApi();

    try {
        $rows = $api->comm('/ip/dhcp-server/lease/print');
        if (!is_array($rows)) {
            return [];
        }

        $items = array_map(static function (array $row): array {
            return [
                'id' => (string)($row['.id'] ?? ''),
                'address' => (string)($row['address'] ?? ''),
                'mac' => (string)($row['mac-address'] ?? ''),
                'host_name' => (string)($row['host-name'] ?? ''),
                'server' => (string)($row['server'] ?? ''),
                'comment' => (string)($row['comment'] ?? ''),
                'status' => (string)($row['status'] ?? ''),
                'expires_after' => (string)($row['expires-after'] ?? ''),
                'last_seen' => (string)($row['last-seen'] ?? ''),
                'dynamic' => strtolower((string)($row['dynamic'] ?? 'false')) === 'true',
                'blocked' => strtolower((string)($row['blocked'] ?? 'false')) === 'true',
                'disabled' => strtolower((string)($row['disabled'] ?? 'false')) === 'true',
            ];
        }, $rows);

        usort($items, static function (array $a, array $b): int {
            return strcasecmp((string)($a['address'] ?? ''), (string)($b['address'] ?? ''));
        });

        return $limit > 0 ? array_slice($items, 0, $limit) : $items;
    } finally {
        $api->disconnect();
    }
}

function setMikrotikDhcpLeaseDisabled(string $leaseId, bool $disabled): void
{
    $leaseId = trim($leaseId);
    if ($leaseId === '') {
        throw new RuntimeException('Bail DHCP introuvable.');
    }

    $api = connectToActiveMikrotikApi();

    try {
        $existing = $api->comm('/ip/dhcp-server/lease/print', [
            '?.id' => $leaseId,
        ]);
        $leaseRow = mikrotikFirstRow($existing);

        if ($leaseRow === []) {
            throw new RuntimeException('Bail DHCP MikroTik introuvable.');
        }

        $command = $disabled ? '/ip/dhcp-server/lease/disable' : '/ip/dhcp-server/lease/enable';
        $api->comm($command, [
            '.id' => $leaseId,
        ]);
    } finally {
        $api->disconnect();
    }
}

function removeMikrotikDhcpLease(string $leaseId): void
{
    $leaseId = trim($leaseId);
    if ($leaseId === '') {
        throw new RuntimeException('Bail DHCP introuvable.');
    }

    $api = connectToActiveMikrotikApi();

    try {
        $existing = $api->comm('/ip/dhcp-server/lease/print', [
            '?.id' => $leaseId,
        ]);
        $leaseRow = mikrotikFirstRow($existing);

        if ($leaseRow === []) {
            throw new RuntimeException('Bail DHCP MikroTik introuvable.');
        }

        $api->comm('/ip/dhcp-server/lease/remove', [
            '.id' => $leaseId,
        ]);
    } finally {
        $api->disconnect();
    }
}

function updateMikrotikDhcpLease(string $leaseId, array $payload): void
{
    $leaseId = trim($leaseId);
    if ($leaseId === '') {
        throw new RuntimeException('Bail DHCP introuvable.');
    }

    $address = trim((string)($payload['address'] ?? ''));
    $macAddress = trim((string)($payload['mac'] ?? ''));

    if ($address === '' && $macAddress === '') {
        throw new RuntimeException('Adresse ou MAC obligatoire.');
    }

    $api = connectToActiveMikrotikApi();

    try {
        $existing = $api->comm('/ip/dhcp-server/lease/print', [
            '?.id' => $leaseId,
        ]);
        $leaseRow = mikrotikFirstRow($existing);

        if ($leaseRow === []) {
            throw new RuntimeException('Bail DHCP MikroTik introuvable.');
        }

        $data = [
            '.id' => $leaseId,
            'address' => $address,
            'mac-address' => $macAddress,
            'host-name' => trim((string)($payload['host_name'] ?? '')),
            'server' => trim((string)($payload['server'] ?? '')),
            'comment' => trim((string)($payload['comment'] ?? '')),
            'disabled' => !empty($payload['disabled']) ? 'yes' : 'no',
        ];

        $api->comm('/ip/dhcp-server/lease/set', $data);
    } finally {
        $api->disconnect();
    }
}

function addMikrotikDhcpLease(array $payload): void
{
    $address = trim((string)($payload['address'] ?? ''));
    $macAddress = trim((string)($payload['mac'] ?? ''));
    $hostName = trim((string)($payload['host_name'] ?? ''));
    $server = trim((string)($payload['server'] ?? ''));
    $comment = trim((string)($payload['comment'] ?? ''));
    $disabled = !empty($payload['disabled']);

    if ($address === '' && $macAddress === '') {
        throw new RuntimeException('Adresse ou MAC obligatoire.');
    }

    $api = connectToActiveMikrotikApi();

    try {
        $command = [
            'disabled' => $disabled ? 'true' : 'false',
        ];

        if ($address !== '') {
            $command['address'] = $address;
        }

        if ($macAddress !== '') {
            $command['mac-address'] = $macAddress;
        }

        if ($hostName !== '') {
            $command['host-name'] = $hostName;
        }

        if ($server !== '') {
            $command['server'] = $server;
        }

        if ($comment !== '') {
            $command['comment'] = $comment;
        }

        $api->comm('/ip/dhcp-server/lease/add', $command);
    } finally {
        $api->disconnect();
    }
}

function ensureMikrotikHotspotLogging(RouterosAPI $api): void
{
    $existing = $api->comm('/system/logging/print');

    $hasHotspotDisk = false;
    if (is_array($existing)) {
        foreach ($existing as $row) {
            if (!is_array($row)) {
                continue;
            }
            $topics = strtolower(trim((string)($row['topics'] ?? '')));
            $action = strtolower(trim((string)($row['action'] ?? '')));
            $prefix = trim((string)($row['prefix'] ?? ''));

            if ($prefix === '->' && $topics === 'hotspot,info,debug' && $action === 'disk') {
                $hasHotspotDisk = true;
                break;
            }
        }
    }

    if ($hasHotspotDisk) {
        return;
    }

    $api->comm('/system/logging/add', [
        'action' => 'disk',
        'prefix' => '->',
        'topics' => 'hotspot,info,debug',
    ]);
}

function getMikrotikHotspotLogs(int $limit = 20): array
{
    $api = connectToActiveMikrotikApi();

    try {
        ensureMikrotikHotspotLogging($api);

        $rows = $api->comm('/log/print', [
            '.proplist' => 'time,message,topics',
        ]);

        if (!is_array($rows)) {
            return [];
        }

        $rows = array_values(array_filter($rows, static function ($row): bool {
            if (!is_array($row)) {
                return false;
            }

            $message = trim((string)($row['message'] ?? ''));
            $topics = strtolower(trim((string)($row['topics'] ?? '')));

            if ($message === '') {
                return false;
            }
            return str_contains($topics, 'hotspot') || str_starts_with($message, '->');
        }));

        $prefixedRows = array_values(array_filter($rows, static function ($row): bool {
            $message = trim((string)($row['message'] ?? ''));
            return $message !== '' && str_starts_with($message, '->');
        }));

        $rows = $prefixedRows !== [] ? $prefixedRows : array_values(array_filter($rows, static function ($row): bool {
            $topics = strtolower(trim((string)($row['topics'] ?? '')));
            return $topics !== '' && str_contains($topics, 'hotspot');
        }));

        $deduped = [];
        $seen = [];
        foreach ($rows as $row) {
            $time = strtolower(trim((string)($row['time'] ?? '')));
            $message = strtolower(trim((string)($row['message'] ?? '')));
            $key = $time . '|' . $message;
            if ($key === '|' || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $deduped[] = $row;
        }

        $rows = array_reverse($deduped);
        if ($limit > 0) {
            $rows = array_slice($rows, 0, $limit);
        }

        return $rows;
    } finally {
        $api->disconnect();
    }
}

function getMikrotikSystemLogs(int $limit = 200): array
{
    $api = connectToActiveMikrotikApi();

    try {
        $rows = $api->comm('/log/print');
        if (!is_array($rows)) {
            return [];
        }

        $items = array_map(static function (array $row): array {
            return [
                'id' => (string)($row['.id'] ?? ''),
                'time' => (string)($row['time'] ?? '--'),
                'topics' => (string)($row['topics'] ?? ''),
                'message' => (string)($row['message'] ?? ''),
            ];
        }, $rows);

        $items = array_values(array_filter($items, static function (array $item): bool {
            return trim((string)($item['message'] ?? '')) !== '';
        }));

        $items = array_reverse($items);

        return $limit > 0 ? array_slice($items, 0, $limit) : $items;
    } finally {
        $api->disconnect();
    }
}

function parseMikrotikUserLogScriptRow(array $row): ?array
{
    $name = trim((string)($row['name'] ?? ''));
    if ($name === '' || strpos($name, '-|-') === false) {
        return null;
    }

    $parts = explode('-|-', $name);
    if (count($parts) < 7) {
        return null;
    }

    $date = trim((string)($parts[0] ?? ''));
    $time = trim((string)($parts[1] ?? ''));
    $username = trim((string)($parts[2] ?? ''));
    $price = trim((string)($parts[3] ?? ''));
    $address = trim((string)($parts[4] ?? ''));
    $mac = trim((string)($parts[5] ?? ''));
    $validity = trim((string)($parts[6] ?? ''));
    $profile = trim((string)($parts[7] ?? ''));
    $comment = trim((string)($parts[8] ?? ''));

    if ($date === '' || $time === '' || $username === '') {
        return null;
    }

    return [
        'date' => $date,
        'time' => $time,
        'username' => $username,
        'address' => $address,
        'mac' => $mac,
        'validity' => $validity,
        'profile' => $profile,
        'comment' => $comment,
        'price' => $price,
        'owner' => (string)($row['owner'] ?? ''),
        'source' => (string)($row['source'] ?? ''),
        'raw_name' => $name,
    ];
}

function getMikrotikUserLogs(?string $day = null, ?string $monthOwner = null, int $limit = 500): array
{
    $api = connectToActiveMikrotikApi();

    try {
        $query = '/system/script/print';
        $params = [];

        $day = trim((string)$day);
        $monthOwner = trim((string)$monthOwner);

        if ($day !== '') {
            $params['?source'] = $day;
        } elseif ($monthOwner !== '') {
            $params['?owner'] = $monthOwner;
        } else {
            $params['?comment'] = 'mikhmon';
        }

        $rows = $api->comm($query, $params);
        if (!is_array($rows)) {
            return [];
        }

        $items = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            if ($day === '' && $monthOwner === '' && (string)($row['comment'] ?? '') !== 'mikhmon') {
                continue;
            }

            $parsed = parseMikrotikUserLogScriptRow($row);
            if ($parsed !== null) {
                $items[] = $parsed;
            }
        }

        usort($items, static function (array $a, array $b): int {
            $left = ($a['date'] ?? '') . ' ' . ($a['time'] ?? '');
            $right = ($b['date'] ?? '') . ' ' . ($b['time'] ?? '');
            return strcmp($right, $left);
        });

        return $limit > 0 ? array_slice($items, 0, $limit) : $items;
    } finally {
        $api->disconnect();
    }
}

function parseMikrotikHotspotLogMessage(array $row): ?array
{
    $message = trim((string)($row['message'] ?? ''));
    if ($message === '') {
        return null;
    }

    $normalizedMessage = str_starts_with($message, '->')
        ? trim(substr($message, 2))
        : $message;
    $normalizedMessage = ltrim($normalizedMessage, " :\t");

    $subject = '';
    $action = '';
    $user = '';
    $address = '';

    if (preg_match('/^(?<user>.+?)\s+\((?<address>[^)]+)\)\s*:\s*(?<action>.+)$/', $normalizedMessage, $matches)) {
        $user = trim((string)$matches['user']);
        $address = trim((string)$matches['address']);
        $subject = trim($user . ' (' . $address . ')');
        $action = trim((string)$matches['action']);
    } elseif (preg_match('/^(?<user>[^:]+?)\s*:\s*(?<action>.+)$/', $normalizedMessage, $matches)) {
        $user = trim((string)$matches['user']);
        $subject = $user;
        $action = trim((string)$matches['action']);
    } else {
        $parts = array_map('trim', explode(':', $normalizedMessage));

        if (count($parts) > 6) {
            $subject = implode(':', array_slice($parts, 0, 6));
            $action = trim(str_replace('trying to', '', implode(' ', array_slice($parts, 6))));
        } elseif (count($parts) > 1) {
            $subject = (string)($parts[0] ?? '');
            $action = trim(str_replace('trying to', '', implode(' ', array_slice($parts, 1))));
        } else {
            $action = $normalizedMessage;
        }

        $subject = preg_replace('/\s+/', ' ', trim($subject)) ?? '';
        $action = preg_replace('/\s+/', ' ', trim($action)) ?? '';

        if ($subject === '' && preg_match('/^(.*?)\s+(logged in|log in|logged out|log out|login failed|logout|failed|authorized|rejected)/i', $normalizedMessage, $matches)) {
            $subject = trim((string)$matches[1]);
        }

        $user = $subject;

        if (preg_match('/^(.*?)\s+\(([^)]+)\)$/', $subject, $matches)) {
            $user = trim((string)$matches[1]);
            $address = trim((string)$matches[2]);
        }

        if ($user === '' && preg_match('/user\\s+([^\\s(]+)/i', $normalizedMessage, $matches)) {
            $user = trim((string)$matches[1]);
        }

        if ($address === '' && preg_match('/\\(([^)]+)\\)/', $normalizedMessage, $matches)) {
            $address = trim((string)$matches[1]);
        }
    }

    $subject = preg_replace('/\s+/', ' ', trim($subject, " :\t")) ?? '';
    $user = preg_replace('/\s+/', ' ', trim($user, " :\t")) ?? '';
    $address = preg_replace('/\s+/', ' ', trim($address)) ?? '';
    $action = preg_replace('/\s+/', ' ', trim(str_replace('trying to', '', $action))) ?? '';

    if ($subject !== '' && $action !== '') {
        $quotedSubject = preg_quote($subject, '/');
        $action = preg_replace('/^' . $quotedSubject . '\s*/i', '', $action) ?? $action;
    }

    if ($user !== '' && $address !== '') {
        $displaySubject = trim($user . ' (' . $address . ')');
        $quotedDisplaySubject = preg_quote($displaySubject, '/');
        $action = preg_replace('/^' . $quotedDisplaySubject . '\s*/i', '', $action) ?? $action;
    }

    if ($address !== '') {
        $quotedAddress = preg_quote($address, '/');
        $action = preg_replace('/\(?\b' . $quotedAddress . '\b\)?/i', '', $action) ?? $action;
        $action = preg_replace('/\s{2,}/', ' ', trim((string)$action)) ?? trim((string)$action);
    }

    $action = ltrim($action, " :;-");
    if ($action === '') {
        $action = $normalizedMessage;
    }

    $normalizedAction = strtolower($action);
    $status = 'info';

    if (
        str_contains($normalizedAction, 'logged in') ||
        str_contains($normalizedAction, 'log in') ||
        str_contains($normalizedAction, 'login') ||
        str_contains($normalizedAction, 'authorized')
    ) {
        $status = 'login';
    } elseif (
        str_contains($normalizedAction, 'login failed') ||
        str_contains($normalizedAction, 'failed') ||
        str_contains($normalizedAction, 'invalid password') ||
        str_contains($normalizedAction, 'invalid username') ||
        str_contains($normalizedAction, 'no more sessions') ||
        str_contains($normalizedAction, 'rejected')
    ) {
        $status = 'fail';
    } elseif (
        str_contains($normalizedAction, 'logged out') ||
        str_contains($normalizedAction, 'logout')
    ) {
        $status = 'logout';
    } elseif (
        str_contains($normalizedAction, 'limit') ||
        str_contains($normalizedAction, 'traffic') ||
        str_contains($normalizedAction, 'rate')
    ) {
        $status = 'limit';
    }

    return [
        'time' => (string)($row['time'] ?? '--'),
        'user' => $user !== '' ? $user : 'Utilisateur',
        'address' => $address,
        'action' => $action !== '' ? $action : $normalizedMessage,
        'status' => $status,
        'raw_message' => $message,
    ];
}

function disconnectMikrotikActiveSession(string $activeSessionId): void
{
    $api = connectToActiveMikrotikApi();

    try {
        $session = $api->comm('/ip/hotspot/active/print', [
            '?.id' => $activeSessionId,
        ]);
        $sessionRow = mikrotikFirstRow($session);

        if ($sessionRow === []) {
            throw new RuntimeException('Session active MikroTik introuvable.');
        }

        $user = (string)($sessionRow['user'] ?? '');
        if ($user !== '') {
            $cookies = $api->comm('/ip/hotspot/cookie/print', [
                '?user' => $user,
            ]);

            if (is_array($cookies)) {
                foreach ($cookies as $cookie) {
                    $cookieId = (string)($cookie['.id'] ?? '');
                    if ($cookieId !== '') {
                        $response = $api->comm('/ip/hotspot/cookie/remove', [
                            '.id' => $cookieId,
                        ]);
                        mikrotikAssertNoTrap(
                            $response,
                            'Le routeur MikroTik a refuse la suppression du cookie de session.'
                        );
                    }
                }
            }
        }

        $response = $api->comm('/ip/hotspot/active/remove', [
            '.id' => $activeSessionId,
        ]);
        mikrotikAssertNoTrap(
            $response,
            'Le routeur MikroTik a refuse la deconnexion de la session active.'
        );
    } finally {
        $api->disconnect();
    }
}

function listMikrotikInterfaces(): array
{
    $api = connectToActiveMikrotikApi();

    try {
        $interfaces = $api->comm('/interface/print');
        return is_array($interfaces) ? $interfaces : [];
    } finally {
        $api->disconnect();
    }
}

function selectMikrotikDashboardInterface(array $interfaces): ?array
{
    if ($interfaces === []) {
        return null;
    }

    $preferredPatterns = ['wan', 'starlink', 'pppoe', 'ether1'];
    $fallback = null;

    foreach ($interfaces as $interface) {
        if (!is_array($interface)) {
            continue;
        }

        $name = strtolower(trim((string)($interface['name'] ?? '')));
        if ($name === '' || $name === 'lo' || $name === 'loopback') {
            continue;
        }

        if ($fallback === null) {
            $fallback = $interface;
        }

        $running = strtolower((string)($interface['running'] ?? 'false')) === 'true';
        if (!$running) {
            continue;
        }

        foreach ($preferredPatterns as $pattern) {
            if (str_contains($name, $pattern)) {
                return $interface;
            }
        }
    }

    foreach ($interfaces as $interface) {
        if (!is_array($interface)) {
            continue;
        }

        $running = strtolower((string)($interface['running'] ?? 'false')) === 'true';
        if ($running) {
            return $interface;
        }
    }

    return $fallback;
}

function getMikrotikTrafficSample(?string $interfaceName = null): array
{
    $api = connectToActiveMikrotikApi();

    try {
        if ($interfaceName === null || trim($interfaceName) === '') {
            $selected = selectMikrotikDashboardInterface($api->comm('/interface/print'));
            $interfaceName = (string)($selected['name'] ?? '');
        }

        if ($interfaceName === '') {
            throw new RuntimeException('Aucune interface MikroTik exploitable pour le trafic.');
        }

        $response = $api->comm('/interface/monitor-traffic', [
            'interface' => $interfaceName,
            'once' => '',
        ]);
        $traffic = mikrotikFirstRow($response);

        return [
            'interface' => $interfaceName,
            'rx_bps' => (float)($traffic['rx-bits-per-second'] ?? 0),
            'tx_bps' => (float)($traffic['tx-bits-per-second'] ?? 0),
        ];
    } finally {
        $api->disconnect();
    }
}
