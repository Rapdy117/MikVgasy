<?php
header('Content-Type: application/json');

require_once '../../config/db.php';
require_once '../../includes/device_manager.php';
require_once '../../includes/nas_resolver.php';
require_once '../../includes/radius_sync.php';
require_once '../../includes/profile_schema.php';

session_start();

function post_string_or_null(string $key): ?string
{
    $value = trim((string)($_POST[$key] ?? ''));
    return $value === '' ? null : $value;
}

function post_int_or_default(string $key, int $default = 0): ?int
{
    $value = trim((string)($_POST[$key] ?? ''));
    if ($value === '') {
        return $default;
    }

    if (filter_var($value, FILTER_VALIDATE_INT) === false) {
        return null;
    }

    return (int)$value;
}

function post_float_or_default(string $key, float $default = 0.0): ?float
{
    $value = trim((string)($_POST[$key] ?? ''));
    if ($value === '') {
        return $default;
    }

    if (!is_numeric($value)) {
        return null;
    }

    return (float)$value;
}

function convertDurationToSeconds(int $value, string $unit): int
{
    if ($value <= 0) {
        return 0;
    }

    return match ($unit) {
        'minutes' => $value * 60,
        'hours' => $value * 3600,
        'days' => $value * 86400,
        'months' => $value * 2592000,
        default => 0,
    };
}

function buildRouterOsDurationFromSeconds(int $seconds): ?string
{
    if ($seconds <= 0) {
        return null;
    }

    $units = [
        'w' => 604800,
        'd' => 86400,
        'h' => 3600,
        'm' => 60,
        's' => 1,
    ];

    $remaining = $seconds;
    $parts = [];

    foreach ($units as $suffix => $unitSeconds) {
        if ($remaining < $unitSeconds) {
            continue;
        }

        $value = intdiv($remaining, $unitSeconds);
        if ($value <= 0) {
            continue;
        }

        $parts[] = $value . $suffix;
        $remaining -= $value * $unitSeconds;
    }

    if ($parts === []) {
        return null;
    }

    return implode('', $parts);
}

function convertDataValueToMegabytes(float $value, string $unit): int
{
    if ($value <= 0) {
        return 0;
    }

    return match (strtoupper(trim($unit))) {
        'KB' => (int)round($value / 1024),
        'GB' => (int)round($value * 1024),
        default => (int)round($value),
    };
}

function buildRateLimitFromParts(?string $uploadValue, ?string $uploadUnit, ?string $downloadValue, ?string $downloadUnit): ?string
{
    $up = trim((string)$uploadValue);
    $down = trim((string)$downloadValue);
    if ($up === '' || $down === '') {
        return null;
    }

    if (!is_numeric($up) || !is_numeric($down)) {
        return null;
    }

    $upNum = (float)$up;
    $downNum = (float)$down;
    if ($upNum <= 0 || $downNum <= 0) {
        return null;
    }

    $upUnit = strtoupper(trim((string)$uploadUnit ?: 'M'));
    $downUnit = strtoupper(trim((string)$downloadUnit ?: 'M'));
    if (!in_array($upUnit, ['K', 'M'], true)) {
        $upUnit = 'M';
    }
    if (!in_array($downUnit, ['K', 'M'], true)) {
        $downUnit = 'M';
    }

    $formatRateValue = static function (float $value): string {
        if (floor($value) === $value) {
            return (string)(int)$value;
        }
        return rtrim(rtrim(number_format($value, 6, '.', ''), '0'), '.');
    };

    return $formatRateValue($upNum) . $upUnit . '/'
        . $formatRateValue($downNum) . $downUnit;
}

function require_valid_csrf(): void
{
    $token = trim((string)($_POST['csrf_token'] ?? ''));

    if ($token === '' || !hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        throw new Exception("CSRF invalide");
    }
}

function buildProfileTargetContext(PDO $pdo, string $deviceId, ?int $nasId = null): array
{
    $deviceStore = loadDeviceStore();
    $device = findDeviceById($deviceStore, $deviceId);
    if (!is_array($device)) {
        throw new Exception('Device introuvable');
    }

    $deviceType = normalizeDeviceType((string)($device['type'] ?? ''));
    $businessSource = resolveDeviceBusinessSource($deviceType);

    if ($businessSource === 'mikrotik_local') {
        return [
            'device' => $device,
            'business_source' => $businessSource,
            'nas_context' => [
                'device' => $device,
                'device_type' => $deviceType,
                'backend_driver' => resolveDeviceBackend($deviceType),
                'business_source' => $businessSource,
                'nas_type' => $deviceType,
                'capabilities' => resolveNasCapabilities($deviceType),
            ],
        ];
    }

    $resolvedNasId = ($nasId !== null && $nasId > 0) ? $nasId : null;
    $nasContext = resolveNasContextFromInputs($pdo, $resolvedNasId, $deviceId);

    return [
        'device' => $device,
        'business_source' => nasContextRequireBusinessSource($nasContext),
        'nas_context' => $nasContext,
    ];
}

try {
    if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
        throw new Exception("Unauthorized");
    }

    require_valid_csrf();
    ensureProfilesExtendedSchema($pdo);

    // 🔹 1. récupérer POST
    $name = post_string_or_null('profile_name');
    $rateFromParts = buildRateLimitFromParts(
        post_string_or_null('rate_upload_value'),
        post_string_or_null('rate_upload_unit'),
        post_string_or_null('rate_download_value'),
        post_string_or_null('rate_download_unit')
    );
    $rate = $rateFromParts ?: post_string_or_null('rate_limit');

    $session = post_int_or_default('session_timeout', 0);
    $sessionValue = post_int_or_default('session_timeout_value', 0);
    $sessionUnit = post_string_or_null('session_timeout_unit') ?? 'hours';
    if ($sessionValue !== null && $sessionValue > 0) {
        $session = convertDurationToSeconds($sessionValue, $sessionUnit);
    }

    $idle = post_int_or_default('idle_timeout', 0);
    $data = post_int_or_default('data_limit', 0);
    $dataQuota = post_int_or_default('data_quota_mb', 0);
    $dataQuotaValue = post_float_or_default('data_quota_value', 0.0);
    $dataQuotaUnit = strtoupper(post_string_or_null('data_quota_unit') ?? 'GB');
    if (!in_array($dataQuotaUnit, ['KB', 'MB', 'GB'], true)) {
        throw new Exception("Unite de quota invalide");
    }
    if ($dataQuotaValue === null) {
        throw new Exception("Valeur de quota invalide");
    }
    if ($dataQuota !== null && $dataQuota > 0) {
        $data = $dataQuota;
    }
    if ($dataQuotaValue > 0) {
        $data = convertDataValueToMegabytes($dataQuotaValue, $dataQuotaUnit);
    }
    $simu = post_int_or_default('simultaneous_use', 1);
    $device_id = post_string_or_null('device_id');
    $nas_id = post_int_or_default('nas_id', 0);
    $profileId = post_int_or_default('profile_id', 0);
    $oldProfileName = post_string_or_null('old_profile_name');
    $validityValue = post_int_or_default('validity_value', 0);
    $validityUnit = post_string_or_null('validity_unit') ?? 'hours';
    $expiredMode = post_string_or_null('expired_mode') ?? 'none';
    $graceValue = post_int_or_default('grace_period_value', 0);
    $graceUnit = post_string_or_null('grace_period_unit') ?? 'minutes';
    $price = post_string_or_null('price');
    $sellingPrice = post_string_or_null('selling_price');
    $lockUser = post_int_or_default('lock_user', 0);
    $addressPool = post_string_or_null('address_pool');
    $parentQueue = post_string_or_null('parent_queue');

    if (!in_array($expiredMode, ['none', 'remove', 'notice', 'remove_record', 'notice_record'], true)) {
        throw new Exception("Mode d'expiration invalide");
    }

    if (!in_array($validityUnit, ['hours', 'days', 'months'], true)) {
        throw new Exception("Unite de validite invalide");
    }

    if ($session === null || $idle === null || $data === null || $simu === null || $validityValue === null || $profileId === null || $graceValue === null) {
        throw new Exception("Types numeriques invalides");
    }

    if ($name === null) {
        throw new Exception("Champs obligatoires manquants");
    }

    if ($device_id === null || trim($device_id) === '') {
        throw new Exception('Serveur requis');
    }

    if ($session < 0 || $idle < 0 || $data < 0 || $simu < 0 || $validityValue < 0 || $profileId < 0 || $graceValue < 0) {
        throw new Exception("Valeurs negatives non autorisees");
    }

    $validityTime = convertDurationToSeconds($validityValue, $validityUnit);
    $validityRouteros = buildRouterOsDurationFromSeconds($validityTime);
    $gracePeriod = $graceValue > 0 ? convertDurationToSeconds($graceValue, $graceUnit) : 0;

    // 🔹 2. charger le device cible puis determiner le backend
    $targetContext = buildProfileTargetContext($pdo, $device_id, $nas_id);
    $device = $targetContext['device'];
    $nasContext = $targetContext['nas_context'];
    $businessSource = $targetContext['business_source'];

    $hasOldName = $oldProfileName !== null && trim($oldProfileName) !== '';
    $isUpdate = $profileId > 0 || $hasOldName;

    if ($businessSource === 'mikrotik_local' && $expiredMode !== 'none' && $validityTime <= 0) {
        throw new Exception("Validite profil requise pour ce mode d'expiration MikroTik");
    }

    if ($businessSource !== 'mikrotik_local') {
        $stmt = $pdo->prepare('SELECT id FROM profiles WHERE LOWER(name) = LOWER(?) LIMIT 1');
        $stmt->execute([$name]);
        $duplicateId = (int)($stmt->fetchColumn() ?: 0);
        if ($duplicateId > 0 && $duplicateId !== $profileId) {
            throw new Exception('Un profil portant ce nom existe deja. Choisissez un nom unique.');
        }
    }

    if ($businessSource === 'mikrotik_local') {
        $payload = [
            'name' => $name,
            'old_name' => $hasOldName ? $oldProfileName : $name,
            'rate_limit' => $rate,
            'session_timeout' => $session,
            'idle_timeout' => $idle,
            'data_quota_mb' => $data,
            'simultaneous_use' => $simu,
            'validity_time' => $validityTime > 0 ? $validityTime : null,
            'expired_mode' => $expiredMode,
            'grace_period' => $gracePeriod > 0 ? $gracePeriod : null,
            'price' => $price,
            'selling_price' => $sellingPrice,
            'lock_user' => $lockUser,
            'ip_pool' => $addressPool,
            'parent_queue' => $parentQueue,
            'validity_routeros' => $validityRouteros,
        ];

        $confirmedProfile = $isUpdate
            ? updateProfileToNasBackend($pdo, $payload, $nasContext)
            : syncProfileToNasBackend($pdo, $payload, $nasContext);
        $confirmedProfileName = is_array($confirmedProfile) && trim((string)($confirmedProfile['name'] ?? '')) !== ''
            ? (string)$confirmedProfile['name']
            : $name;

        echo json_encode([
            'success' => true,
            'message' => $isUpdate ? 'Profil mis a jour sur le routeur MikroTik' : 'Profil créé sur le routeur MikroTik',
            'profile_name' => $confirmedProfileName,
            'device_id' => (string)($device['id'] ?? ''),
            'business_source' => nasContextRequireBusinessSource($nasContext),
            'backend_driver' => nasContextRequireBackendDriver($nasContext),
            'nas_type' => (string)($nasContext['nas_type'] ?? ''),
        ]);
        exit;
    }

    // 🔹 3. enregistrer profil (RADIUS/OPNsense)
    $pdo->beginTransaction();

    if ($profileId <= 0 && $hasOldName) {
        $lookup = $pdo->prepare("SELECT id FROM profiles WHERE name = ? LIMIT 1");
        $lookup->execute([$oldProfileName]);
        $profileId = (int)($lookup->fetchColumn() ?: 0);
        $isUpdate = $profileId > 0;
    }

    if ($profileId > 0) {
        $stmt = $pdo->prepare("
            UPDATE profiles
            SET name = ?,
                rate_limit = ?,
                session_timeout = ?,
                idle_timeout = ?,
                validity_time = ?,
                data_quota_mb = ?,
                simultaneous_use = ?,
                expired_mode = ?,
                grace_period = ?,
                price = ?,
                selling_price = ?,
                lock_user = ?,
                ip_pool = ?,
                parent_queue = ?
            WHERE id = ?
        ");
        $stmt->execute([
            $name,
            $rate,
            $session,
            $idle,
            $validityTime > 0 ? $validityTime : null,
            $data,
            $simu,
            $expiredMode,
            $gracePeriod > 0 ? $gracePeriod : null,
            $price,
            $sellingPrice,
            $lockUser,
            $addressPool,
            $parentQueue,
            $profileId
        ]);
    } else {
        $stmt = $pdo->prepare("
            INSERT INTO profiles
            (name, rate_limit, session_timeout, idle_timeout, validity_time, data_quota_mb, simultaneous_use, expired_mode, grace_period, price, selling_price, lock_user, ip_pool, parent_queue)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        $stmt->execute([
            $name,
            $rate,
            $session,
            $idle,
            $validityTime > 0 ? $validityTime : null,
            $data,
            $simu,
            $expiredMode,
            $gracePeriod > 0 ? $gracePeriod : null,
            $price,
            $sellingPrice,
            $lockUser,
            $addressPool,
            $parentQueue
        ]);
    }

    // 🔹 4. sync vers backend NAS resolu
    $syncPayload = [
        'name' => $name,
        'old_name' => $hasOldName ? $oldProfileName : $name,
        'rate_limit' => $rate,
        'session_timeout' => $session,
        'idle_timeout' => $idle,
        'data_quota_mb' => $data,
        'simultaneous_use' => $simu
    ];

    if ($profileId > 0) {
        updateProfileToNasBackend($pdo, $syncPayload, $nasContext);
    } else {
        syncProfileToNasBackend($pdo, $syncPayload, $nasContext);
    }

    $pdo->commit();

    echo json_encode([
        'success' => true,
        'message' => $profileId > 0 ? 'Profil mis a jour avec succes' : 'Profil créé avec succès',
        'device_id' => (string)($device['id'] ?? ''),
        'business_source' => nasContextRequireBusinessSource($nasContext),
        'backend_driver' => nasContextRequireBackendDriver($nasContext),
        'nas_type' => (string)($nasContext['nas_type'] ?? ''),
    ]);

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);

}
