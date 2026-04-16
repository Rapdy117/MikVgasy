<?php

function portalHotspotConfigFilePath(): string
{
    return __DIR__ . '/../config/portal.json';
}

function portalHotspotManagedStatusFiles(): array
{
    return [
        __DIR__ . '/../docs/hotspot_copie_extracted/hotspot - Copie/status.html',
    ];
}

function defaultPortalHotspotConfig(): array
{
    return [
        'api_base_url' => 'http://10.10.10.2',
        'captive_device_id' => null,
        'template_relative_path' => null,
        'logo_relative_path' => null,
        'last_compat' => null,
    ];
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
    foreach (['templates', 'logos'] as $sub) {
        $dir = $root . '/' . $sub;
        if (is_dir($dir)) {
            continue;
        }
        if (!@mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new RuntimeException('Impossible de creer le repertoire de stockage portail captif.');
        }
    }
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
            'summary' => 'Compatible',
            'detail' => 'Pages web detectees : structure utilisable pour un portail de type hotspot / MikroTik.',
        ];
    }

    if ($deviceType === 'opnsense') {
        return [
            'level' => 'ok',
            'summary' => 'Compatible',
            'detail' => 'Pages web detectees : structure utilisable pour un portail de type OPNsense.',
        ];
    }

    return [
        'level' => 'ok',
        'summary' => 'Controle basique OK',
        'detail' => 'Pages web detectees dans l archive.',
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

    $lastCompat = $defaults['last_compat'];
    if (array_key_exists('last_compat', $payload) && (is_array($payload['last_compat']) || $payload['last_compat'] === null)) {
        $lastCompat = $payload['last_compat'];
    }

    return [
        'api_base_url' => $apiBase,
        'captive_device_id' => $captiveDeviceId,
        'template_relative_path' => $templateRel,
        'logo_relative_path' => $logoRel,
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
    if (array_key_exists('last_compat', $config)) {
        $next['last_compat'] = $config['last_compat'];
    }

    $payload = [
        'api_base_url' => $next['api_base_url'],
        'captive_device_id' => $next['captive_device_id'],
        'template_relative_path' => $next['template_relative_path'],
        'logo_relative_path' => $next['logo_relative_path'],
        'last_compat' => $next['last_compat'],
    ];

    file_put_contents(
        portalHotspotConfigFilePath(),
        json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
        LOCK_EX
    );
}

function applyPortalApiBaseUrlToHotspotSources(string $apiBaseUrl): void
{
    $normalized = normalizePortalApiBaseUrl($apiBaseUrl);

    foreach (portalHotspotManagedStatusFiles() as $file) {
        if (!is_file($file)) {
            throw new RuntimeException('Fichier hotspot introuvable : ' . $file);
        }

        $content = (string)file_get_contents($file);
        $updated = preg_replace(
            "/const projectApiBase = params\\.get\\('project_api_base'\\) \\|\\| '.*?';/",
            "const projectApiBase = params.get('project_api_base') || '" . addslashes($normalized) . "';",
            $content,
            1
        );

        if (!is_string($updated) || $updated === $content) {
            throw new RuntimeException('Impossible de mettre à jour l URL API dans : ' . $file);
        }

        file_put_contents($file, $updated, LOCK_EX);
    }
}
