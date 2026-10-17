<?php

function portalHotspotConfigFilePath(): string
{
    return __DIR__ . '/../config/portal.json';
}

function defaultPortalHotspotConfig(): array
{
    return [
        'api_base_url' => 'http://10.10.10.2',
        'captive_device_id' => null,
        'template_relative_path' => null,
        'logo_relative_path' => null,
        'advertisement_relative_paths' => [],
        'advertisement_captions' => [],
        'advertisement_captions_visible' => true,
        'advertisement_target_url' => null,
        'active_hotspot_profile' => null,
        'last_deployment' => null,
        'last_compat' => null,
    ];
}

/** @param list<mixed> $captions @return list<string> */
function normalizePortalAdvertisementCaptions(array $captions, int $expectedCount): array
{
    // La legende est facultative : une banniere sans legende s'affiche simplement sans texte.
    $normalized = array_map(static fn($value): string => trim((string)$value), array_values($captions));
    if (count($normalized) !== $expectedCount || $expectedCount > 8) {
        throw new RuntimeException('Chaque banniere active doit correspondre a une legende (vide ou non).');
    }
    foreach ($normalized as $caption) {
        if (strlen($caption) > 180) {
            throw new RuntimeException('Legende trop longue (180 caracteres maximum).');
        }
    }
    return $normalized;
}

/** Taille max du fichier ZIP uploadé (octets). */
function portalCaptiveZipUploadMaxBytes(): int
{
    return 25 * 1024 * 1024;
}

/** Plafond somme tailles décompressées (protection zip bomb). */
function portalCaptiveUncompressedMaxBytes(): int
{
    return 48 * 1024 * 1024;
}

function portalCaptiveFilesystemRoot(): string
{
    return __DIR__ . '/../uploads/portal_captive';
}

function portalEnsureCaptiveDirectories(): void
{
    $root = portalCaptiveFilesystemRoot();
    foreach (['templates', 'logos', 'advertisements'] as $sub) {
        $dir = $root . '/' . $sub;
        if (is_dir($dir)) {
            continue;
        }
        if (!@mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new RuntimeException('Impossible de creer le repertoire de stockage portail captif.');
        }
    }
}

/** @return list<string> */
function portalListCaptiveAdvertisementFiles(): array
{
    portalEnsureCaptiveDirectories();
    $dir = portalCaptiveFilesystemRoot() . '/advertisements';
    $out = [];
    foreach (scandir($dir) ?: [] as $name) {
        if ($name === '.' || $name === '..' || !preg_match('/\.(png|jpe?g|gif|webp)$/i', $name)) {
            continue;
        }
        if (is_file($dir . '/' . $name)) {
            $out[] = $name;
        }
    }
    sort($out);

    return $out;
}

function portalDefaultTemplateNameForDeviceType(string $deviceType): ?string
{
    return match (strtolower(trim($deviceType))) {
        'mikrotik' => 'default_mikrotik.zip',
        'opnsense' => 'default_opnsense.zip',
        default => null,
    };
}

function portalDefaultTemplatePathForDeviceType(string $deviceType): ?string
{
    $name = portalDefaultTemplateNameForDeviceType($deviceType);
    if ($name === null) {
        return null;
    }

    $path = portalCaptiveFilesystemRoot() . '/templates/' . $name;
    return is_file($path) ? $path : null;
}

/**
 * @return list<string> noms de fichiers dans uploads/portal_captive/logos/
 */
function portalListCaptiveLogoFiles(): array
{
    portalEnsureCaptiveDirectories();
    $dir = portalCaptiveFilesystemRoot() . '/logos';
    $out = [];
    foreach (scandir($dir) ?: [] as $name) {
        if ($name === '.' || $name === '..') {
            continue;
        }
        if (!preg_match('/\.(png|jpe?g|gif|webp)$/i', $name)) {
            continue;
        }
        if (is_file($dir . '/' . $name)) {
            $out[] = $name;
        }
    }
    sort($out);

    return $out;
}

/**
 * Analyse un ZIP sur disque (fichier temporaire ou définitif).
 *
 * @return array{file_count:int,total_uncompressed:int,has_web_asset:bool}
 */
function portalAnalyzeCaptiveZip(string $zipAbsolutePath): array
{
    if (!is_file($zipAbsolutePath) || !is_readable($zipAbsolutePath)) {
        throw new RuntimeException('Fichier ZIP inaccessible.');
    }

    if (!class_exists(ZipArchive::class)) {
        throw new RuntimeException('Extension PHP ZipArchive indisponible.');
    }

    $zip = new ZipArchive();
    if ($zip->open($zipAbsolutePath) !== true) {
        throw new RuntimeException('Impossible d ouvrir l archive ZIP.');
    }

    $num = $zip->numFiles;
    if ($num < 1) {
        $zip->close();
        throw new RuntimeException('Archive ZIP vide.');
    }
    if ($num > 800) {
        $zip->close();
        throw new RuntimeException('Trop de fichiers dans l archive (max 800).');
    }

    $totalUncompressed = 0;
    $hasWebAsset = false;

    for ($i = 0; $i < $num; $i++) {
        $stat = $zip->statIndex($i);
        if (!is_array($stat)) {
            continue;
        }
        $name = (string)($stat['name'] ?? '');
        if ($name === '' || str_contains($name, '..')) {
            $zip->close();
            throw new RuntimeException('Chemins interdits dans l archive ZIP.');
        }
        $first = $name[0] ?? '';
        if ($first === '/' || $first === '\\') {
            $zip->close();
            throw new RuntimeException('Chemins absolus interdits dans l archive ZIP.');
        }

        $size = (int)($stat['size'] ?? 0);
        $totalUncompressed += $size;
        if ($totalUncompressed > portalCaptiveUncompressedMaxBytes()) {
            $zip->close();
            throw new RuntimeException('Taille de decompression excessive (protection zip bomb).');
        }

        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (in_array($ext, ['html', 'htm', 'php'], true)) {
            $hasWebAsset = true;
        }
    }

    $zip->close();

    if (!$hasWebAsset) {
        throw new RuntimeException('Aucune page web detectee (.html / .htm / .php) dans le ZIP.');
    }

    return [
        'file_count' => $num,
        'total_uncompressed' => $totalUncompressed,
        'has_web_asset' => true,
    ];
}

/**
 * @param array{file_count:int,total_uncompressed:int,has_web_asset:bool} $zipAnalysis
 * @return array{level:string,summary:string,detail:string}
 */
function portalCompatMessageForDevice(string $deviceType, array $zipAnalysis): array
{
    $deviceType = strtolower(trim($deviceType));
    unset($zipAnalysis);

    if ($deviceType === 'radius') {
        return [
            'level' => 'warn',
            'summary' => 'Avertissement',
            'detail' => 'Un serveur RADIUS n heberge en general pas les pages du portail captif. Verifiez que ce device correspond a votre architecture.',
        ];
    }

    if ($deviceType === 'mikrotik') {
        return [
            'level' => 'ok',
            'summary' => 'Archive valide',
            'detail' => 'Structure detectee compatible MikroTik et prete au deploiement FTP.',
        ];
    }

    if ($deviceType === 'opnsense') {
        return [
            'level' => 'ok',
            'summary' => 'Archive valide',
            'detail' => 'Structure détectée compatible OPNsense. Injection locale uniquement, aucun push vers le device.',
        ];
    }

    return [
        'level' => 'ok',
        'summary' => 'Archive valide',
        'detail' => 'Pages web détectées. Aucun déploiement automatique.',
    ];
}

/**
 * Enregistre un logo uploadé ; retourne le chemin relatif web (uploads/portal_captive/logos/...).
 */
function portalStoreCaptiveLogoUpload(array $file): string
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Erreur lors de l envoi du logo.');
    }

    $max = 2 * 1024 * 1024;
    $size = (int)($file['size'] ?? 0);
    if ($size < 1 || $size > $max) {
        throw new RuntimeException('Logo : taille invalide (max 2 Mo).');
    }

    $tmp = (string)($file['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        throw new RuntimeException('Fichier logo invalide.');
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($tmp) ?: '';
    $ext = match ($mime) {
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
        default => '',
    };
    if ($ext === '') {
        throw new RuntimeException('Logo : format non supporte (PNG, JPEG, GIF, WebP).');
    }

    portalEnsureCaptiveDirectories();
    $destName = 'logo_' . bin2hex(random_bytes(8)) . '.' . $ext;
    $destAbs = portalCaptiveFilesystemRoot() . '/logos/' . $destName;
    if (!move_uploaded_file($tmp, $destAbs)) {
        throw new RuntimeException('Impossible d enregistrer le logo.');
    }

    return 'uploads/portal_captive/logos/' . $destName;
}

function portalStoreCaptiveAdvertisementUpload(array $file): string
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Erreur lors de l envoi de la banniere.');
    }

    $max = 5 * 1024 * 1024;
    $size = (int)($file['size'] ?? 0);
    if ($size < 1 || $size > $max) {
        throw new RuntimeException('Banniere : taille invalide (max 5 Mo).');
    }

    $tmp = (string)($file['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        throw new RuntimeException('Fichier banniere invalide.');
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($tmp) ?: '';
    $ext = match ($mime) {
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
        default => '',
    };
    if ($ext === '') {
        throw new RuntimeException('Banniere : format non supporte (PNG, JPEG, GIF, WebP).');
    }

    portalEnsureCaptiveDirectories();
    $destName = 'advertisement_' . bin2hex(random_bytes(8)) . '.webp';
    $destAbs = portalCaptiveFilesystemRoot() . '/advertisements/' . $destName;
    portalOptimizeCaptiveAdvertisement($tmp, $destAbs);

    return 'uploads/portal_captive/advertisements/' . $destName;
}

function portalOptimizeCaptiveAdvertisement(string $source, string $destination): void
{
    if (!extension_loaded('gd') || !function_exists('imagewebp')) {
        throw new RuntimeException('Optimisation des bannieres indisponible sur ce serveur.');
    }

    $dimensions = @getimagesize($source);
    $sourceWidth = (int)($dimensions[0] ?? 0);
    $sourceHeight = (int)($dimensions[1] ?? 0);
    if ($sourceWidth < 1 || $sourceHeight < 1 || ($sourceWidth * $sourceHeight) > 40000000) {
        throw new RuntimeException('Dimensions de banniere invalides ou trop grandes.');
    }

    $sourceBytes = file_get_contents($source);
    $sourceImage = $sourceBytes !== false ? @imagecreatefromstring($sourceBytes) : false;
    if ($sourceImage === false) {
        throw new RuntimeException('Impossible de decoder la banniere.');
    }

    $scale = min(1, 640 / $sourceWidth, 360 / $sourceHeight);
    $targetWidth = max(1, (int)round($sourceWidth * $scale));
    $targetHeight = max(1, (int)round($sourceHeight * $scale));
    $targetImage = imagecreatetruecolor($targetWidth, $targetHeight);
    if ($targetImage === false) {
        imagedestroy($sourceImage);
        throw new RuntimeException('Impossible de preparer la banniere optimisee.');
    }

    imagealphablending($targetImage, false);
    imagesavealpha($targetImage, true);
    $transparent = imagecolorallocatealpha($targetImage, 0, 0, 0, 127);
    imagefilledrectangle($targetImage, 0, 0, $targetWidth, $targetHeight, $transparent);
    imagecopyresampled(
        $targetImage,
        $sourceImage,
        0,
        0,
        0,
        0,
        $targetWidth,
        $targetHeight,
        $sourceWidth,
        $sourceHeight
    );

    $saved = imagewebp($targetImage, $destination, 68);
    imagedestroy($targetImage);
    imagedestroy($sourceImage);
    if (!$saved) {
        throw new RuntimeException('Impossible d enregistrer la banniere optimisee.');
    }
}

/** @return list<string> */
function portalStoreCaptiveAdvertisementUploads(array $files): array
{
    $names = is_array($files['name'] ?? null) ? $files['name'] : [];
    if (count($names) > 8) {
        throw new RuntimeException('Bannieres : maximum 8 images par envoi.');
    }

    $stored = [];
    foreach (array_keys($names) as $index) {
        $error = (int)($files['error'][$index] ?? UPLOAD_ERR_NO_FILE);
        if ($error === UPLOAD_ERR_NO_FILE) {
            continue;
        }
        $stored[] = portalStoreCaptiveAdvertisementUpload([
            'name' => $files['name'][$index] ?? '',
            'type' => $files['type'][$index] ?? '',
            'tmp_name' => $files['tmp_name'][$index] ?? '',
            'error' => $error,
            'size' => $files['size'][$index] ?? 0,
        ]);
    }

    return $stored;
}

function normalizePortalAdvertisementTargetUrl(?string $value): ?string
{
    $url = trim((string)$value);
    if ($url === '') {
        return null;
    }
    if (filter_var($url, FILTER_VALIDATE_URL) === false || !preg_match('#^https?://#i', $url)) {
        throw new RuntimeException('Lien de banniere invalide : utilisez une URL HTTP ou HTTPS complete.');
    }

    return $url;
}

function portalResolveConfiguredMikrotikDevice(PDO $pdo, string $requestedDeviceId): array
{
    $configuredDeviceId = trim((string)(loadPortalHotspotConfig()['captive_device_id'] ?? ''));
    if ($configuredDeviceId === '' || !hash_equals($configuredDeviceId, trim($requestedDeviceId))) {
        throw new RuntimeException('Device portail non autorise.');
    }

    require_once __DIR__ . '/device_manager.php';
    $stmt = $pdo->prepare('SELECT id,owner_admin_id,name,type,host,backend_driver,api_key,api_secret,secret,verify_ssl,port,vendor,device_fingerprint,hardware_info,license_key,license_status,license_expiry,license_issued,is_active FROM network_devices WHERE id = ? AND is_active = 1 LIMIT 1');
    $stmt->execute([$configuredDeviceId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    if (!is_array($row)) {
        throw new RuntimeException('Device portail introuvable ou inactif.');
    }

    $device = decryptFields(normalizeDeviceRecord($row), DEVICE_SENSITIVE_FIELDS);
    if (normalizeDeviceType((string)($device['type'] ?? '')) !== 'mikrotik') {
        throw new RuntimeException('Le device portail configure n est pas un MikroTik.');
    }

    return $device;
}

function normalizePortalApiBaseUrl(string $value): string
{
    $url = trim($value);
    if ($url === '') {
        throw new RuntimeException('URL API portail manquante.');
    }

    if (!preg_match('#^https?://#i', $url)) {
        $url = 'http://' . $url;
    }

    $url = rtrim($url, '/');

    if (filter_var($url, FILTER_VALIDATE_URL) === false) {
        throw new RuntimeException('URL API portail invalide.');
    }

    return $url;
}

function loadPortalHotspotConfig(): array
{
    $defaults = defaultPortalHotspotConfig();
    $file = portalHotspotConfigFilePath();

    if (!is_file($file)) {
        return $defaults;
    }

    $payload = json_decode((string)file_get_contents($file), true);
    if (!is_array($payload)) {
        return $defaults;
    }

    $apiBase = $defaults['api_base_url'];
    if (isset($payload['api_base_url']) && trim((string)$payload['api_base_url']) !== '') {
        try {
            $apiBase = normalizePortalApiBaseUrl((string)$payload['api_base_url']);
        } catch (RuntimeException $e) {
            $apiBase = $defaults['api_base_url'];
        }
    }

    $captiveDeviceId = $defaults['captive_device_id'];
    if (array_key_exists('captive_device_id', $payload)) {
        $raw = $payload['captive_device_id'];
        $captiveDeviceId = ($raw === null || $raw === '') ? null : (string)$raw;
    }

    $templateRel = $defaults['template_relative_path'];
    if (array_key_exists('template_relative_path', $payload)) {
        $raw = $payload['template_relative_path'];
        $templateRel = ($raw === null || $raw === '') ? null : (string)$raw;
    }

    $logoRel = $defaults['logo_relative_path'];
    if (array_key_exists('logo_relative_path', $payload)) {
        $raw = $payload['logo_relative_path'];
        $logoRel = ($raw === null || $raw === '') ? null : (string)$raw;
    }

    $advertisementRels = [];
    if (isset($payload['advertisement_relative_paths']) && is_array($payload['advertisement_relative_paths'])) {
        foreach ($payload['advertisement_relative_paths'] as $raw) {
            $path = trim((string)$raw);
            if ($path !== '') {
                $advertisementRels[] = $path;
            }
        }
        $advertisementRels = array_slice(array_values(array_unique($advertisementRels)), 0, 8);
    }

    $advertisementTargetUrl = normalizePortalAdvertisementTargetUrl(
        array_key_exists('advertisement_target_url', $payload) ? (string)$payload['advertisement_target_url'] : null
    );
    $advertisementCaptions = [];
    if (isset($payload['advertisement_captions']) && is_array($payload['advertisement_captions'])) {
        foreach (array_slice(array_values($payload['advertisement_captions']), 0, 8) as $rawCaption) {
            $advertisementCaptions[] = trim((string)$rawCaption);
        }
    }
    $advertisementCaptionsVisible = !array_key_exists('advertisement_captions_visible', $payload)
        || (bool)$payload['advertisement_captions_visible'];
    $activeHotspotProfile = array_key_exists('active_hotspot_profile', $payload)
        ? (trim((string)$payload['active_hotspot_profile']) ?: null)
        : null;
    $lastDeployment = (isset($payload['last_deployment']) && is_array($payload['last_deployment']))
        ? $payload['last_deployment']
        : null;

    $lastCompat = $defaults['last_compat'];
    if (array_key_exists('last_compat', $payload) && (is_array($payload['last_compat']) || $payload['last_compat'] === null)) {
        $lastCompat = $payload['last_compat'];
    }

    return [
        'api_base_url' => $apiBase,
        'captive_device_id' => $captiveDeviceId,
        'template_relative_path' => $templateRel,
        'logo_relative_path' => $logoRel,
        'advertisement_relative_paths' => $advertisementRels,
        'advertisement_captions' => $advertisementCaptions,
        'advertisement_captions_visible' => $advertisementCaptionsVisible,
        'advertisement_target_url' => $advertisementTargetUrl,
        'active_hotspot_profile' => $activeHotspotProfile,
        'last_deployment' => $lastDeployment,
        'last_compat' => $lastCompat,
    ];
}

/**
 * Fusionne avec la configuration existante. Seules les cles fournies sont ecrasees.
 *
 * @param array<string, mixed> $config
 */
function savePortalHotspotConfig(array $config): void
{
    $previous = loadPortalHotspotConfig();
    $next = $previous;

    if (array_key_exists('api_base_url', $config)) {
        $next['api_base_url'] = normalizePortalApiBaseUrl((string)$config['api_base_url']);
    }
    if (array_key_exists('captive_device_id', $config)) {
        $v = $config['captive_device_id'];
        $next['captive_device_id'] = ($v === null || $v === '') ? null : (string)$v;
    }
    if (array_key_exists('template_relative_path', $config)) {
        $v = $config['template_relative_path'];
        $next['template_relative_path'] = ($v === null || $v === '') ? null : (string)$v;
    }
    if (array_key_exists('logo_relative_path', $config)) {
        $v = $config['logo_relative_path'];
        $next['logo_relative_path'] = ($v === null || $v === '') ? null : (string)$v;
    }
    if (array_key_exists('advertisement_relative_paths', $config)) {
        if (!is_array($config['advertisement_relative_paths'])) {
            throw new RuntimeException('Liste de bannieres invalide.');
        }
        $paths = array_values(array_unique(array_filter(
            array_map(static fn($value): string => trim((string)$value), $config['advertisement_relative_paths']),
            static fn(string $value): bool => $value !== ''
        )));
        if (count($paths) > 8) {
            throw new RuntimeException('Bannieres : maximum 8 images actives.');
        }
        $next['advertisement_relative_paths'] = $paths;
    }
    if (array_key_exists('advertisement_captions', $config)) {
        if (!is_array($config['advertisement_captions'])) {
            throw new RuntimeException('Liste de legendes invalide.');
        }
        $next['advertisement_captions'] = normalizePortalAdvertisementCaptions(
            $config['advertisement_captions'],
            count($next['advertisement_relative_paths'])
        );
    }
    if (array_key_exists('advertisement_captions_visible', $config)) {
        $next['advertisement_captions_visible'] = (bool)$config['advertisement_captions_visible'];
    }
    if (array_key_exists('advertisement_target_url', $config)) {
        $next['advertisement_target_url'] = normalizePortalAdvertisementTargetUrl(
            $config['advertisement_target_url'] === null ? null : (string)$config['advertisement_target_url']
        );
    }
    if (array_key_exists('active_hotspot_profile', $config)) {
        $v = trim((string)$config['active_hotspot_profile']);
        $next['active_hotspot_profile'] = $v !== '' ? $v : null;
    }
    if (array_key_exists('last_deployment', $config)) {
        $next['last_deployment'] = is_array($config['last_deployment']) ? $config['last_deployment'] : null;
    }
    if (array_key_exists('last_compat', $config)) {
        $next['last_compat'] = $config['last_compat'];
    }
    if (count($next['advertisement_relative_paths']) !== count($next['advertisement_captions'])) {
        throw new RuntimeException('Chaque banniere active doit avoir exactement une legende.');
    }

    $payload = [
        'api_base_url' => $next['api_base_url'],
        'captive_device_id' => $next['captive_device_id'],
        'template_relative_path' => $next['template_relative_path'],
        'logo_relative_path' => $next['logo_relative_path'],
        'advertisement_relative_paths' => $next['advertisement_relative_paths'],
        'advertisement_captions' => $next['advertisement_captions'],
        'advertisement_captions_visible' => $next['advertisement_captions_visible'],
        'advertisement_target_url' => $next['advertisement_target_url'],
        'active_hotspot_profile' => $next['active_hotspot_profile'],
        'last_deployment' => $next['last_deployment'],
        'last_compat' => $next['last_compat'],
    ];

    file_put_contents(
        portalHotspotConfigFilePath(),
        json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
        LOCK_EX
    );
}
