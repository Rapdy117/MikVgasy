<?php

function portalTemplateCreateTempDir(): string
{
    $base = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR);
    $dir = $base . DIRECTORY_SEPARATOR . 'portal_tpl_' . bin2hex(random_bytes(8));
    if (!@mkdir($dir, 0700, true) && !is_dir($dir)) {
        throw new RuntimeException('Impossible de creer le repertoire temporaire du template portail.');
    }

    return $dir;
}

function portalTemplateRemoveDir(string $dir): void
{
    if ($dir === '' || !is_dir($dir)) {
        return;
    }

    $items = scandir($dir);
    if ($items === false) {
        return;
    }

    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }

        $path = $dir . DIRECTORY_SEPARATOR . $item;
        if (is_dir($path) && !is_link($path)) {
            portalTemplateRemoveDir($path);
            @rmdir($path);
            continue;
        }

        @unlink($path);
    }

    @rmdir($dir);
}

/**
 * @return list<string> chemins relatifs
 */
function portalTemplateFindWebEntries(string $rootDir): array
{
    $result = [];

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($rootDir, FilesystemIterator::SKIP_DOTS)
    );

    foreach ($iterator as $file) {
        if (!$file->isFile()) {
            continue;
        }

        $fullPath = $file->getPathname();
        $relative = substr($fullPath, strlen(rtrim($rootDir, DIRECTORY_SEPARATOR)) + 1);
        $relative = str_replace('\\', '/', (string)$relative);
        $ext = strtolower(pathinfo($relative, PATHINFO_EXTENSION));

        if (in_array($ext, ['html', 'htm', 'php'], true)) {
            $result[] = $relative;
        }
    }

    sort($result);

    return $result;
}

function portalTemplateSelectPrimaryEntry(array $webEntries): string
{
    $preferredBases = [
        'index.html',
        'index.htm',
        'index.php',
        'status.html',
        'status.htm',
        'status.php',
    ];

    foreach ($preferredBases as $baseName) {
        foreach ($webEntries as $entry) {
            if (strtolower(basename($entry)) === $baseName) {
                return $entry;
            }
        }
    }

    if (!isset($webEntries[0])) {
        throw new RuntimeException('Aucune page web exploitable trouvee dans le template.');
    }

    return (string)$webEntries[0];
}

function portalTemplateBuildRuntimeScript(array $runtime): string
{
    $payload = [
        'apiBaseUrl' => (string)($runtime['api_base_url'] ?? ''),
        'statusApiUrl' => (string)($runtime['status_api_url'] ?? ''),
        'deviceId' => (string)($runtime['device_id'] ?? ''),
        'deviceType' => (string)($runtime['device_type'] ?? ''),
        'routerHost' => (string)($runtime['router_host'] ?? ''),
    ];

    return '<script id="portal-runtime-config">window.PORTAL_RUNTIME_CONFIG = '
        . json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        . ';</script>';
}

function portalTemplateInjectRuntimeScript(string $content, array $runtime): string
{
    $script = portalTemplateBuildRuntimeScript($runtime);

    $pattern = '#<script id="portal-runtime-config">.*?</script>#si';
    if (preg_match($pattern, $content) === 1) {
        return (string)preg_replace($pattern, $script, $content, 1);
    }

    if (stripos($content, '</head>') !== false) {
        return (string)preg_replace('#</head>#i', $script . "\n</head>", $content, 1);
    }

    return $script . "\n" . $content;
}

function portalTemplatePatchOpnsenseEntry(string $content): string
{
    // Nettoyage des duplications déjà injectées
    $content = (string)preg_replace('/^\s*const\s+PORTAL_RUNTIME_CONFIG\s*=\s*window\.PORTAL_RUNTIME_CONFIG\s*\|\|\s*\{\};\s*$/mi', '', $content);
    $content = (string)preg_replace('/^\s*const\s+STATUS_DEVICE_ID\s*=\s*String\(PORTAL_RUNTIME_CONFIG\.deviceId\s*\|\|\s*\'\'\)\.trim\(\);\s*$/mi', '', $content);

    $statusConstPattern = '/const\s+STATUS_API_URL\s*=\s*.*?;/';
    $statusConstReplacement = "const PORTAL_RUNTIME_CONFIG = window.PORTAL_RUNTIME_CONFIG || {};\n"
        . "    const STATUS_API_URL = String(PORTAL_RUNTIME_CONFIG.statusApiUrl || '').trim();\n"
        . "    const STATUS_DEVICE_ID = String(PORTAL_RUNTIME_CONFIG.deviceId || '').trim();";

    $countStatusConst = 0;
    $content = (string)preg_replace(
        $statusConstPattern,
        $statusConstReplacement,
        $content,
        1,
        $countStatusConst
    );

    if ($countStatusConst !== 1) {
        throw new RuntimeException('Injection portail : constante STATUS_API_URL introuvable dans le template.');
    }

    // Nettoyage d'un éventuel bloc device_id déjà présent
    $content = (string)preg_replace(
        '/\s*if\s*\(STATUS_DEVICE_ID\)\s*\{\s*url\.searchParams\.set\(\'device_id\',\s*STATUS_DEVICE_ID\);\s*\}\s*/s',
        "\n",
        $content
    );

    $deviceIdBlockPattern = "/if\\s*\\(payload\\.authType\\)\\s*\\{\\s*url\\.searchParams\\.set\\('auth_type',\\s*payload\\.authType\\);\\s*\\}/s";
    $deviceIdBlockReplacement = "if (payload.authType) {\n"
        . "                url.searchParams.set('auth_type', payload.authType);\n"
        . "            }\n"
        . "            if (STATUS_DEVICE_ID) {\n"
        . "                url.searchParams.set('device_id', STATUS_DEVICE_ID);\n"
        . "            }";

    $countDeviceIdBlock = 0;
    $content = (string)preg_replace(
        $deviceIdBlockPattern,
        $deviceIdBlockReplacement,
        $content,
        1,
        $countDeviceIdBlock
    );

    if ($countDeviceIdBlock !== 1) {
        throw new RuntimeException('Injection portail : bloc enrichStatus() introuvable pour ajouter device_id.');
    }

    return $content;
}

function portalTemplatePatchMikrotikEntry(string $content): string
{
    $replacement = <<<'HTML'
<script id="portal-status-runtime">
(function () {
    const runtime = window.PORTAL_RUNTIME_CONFIG || {};

    const API_BASE_URL = String(runtime.apiBaseUrl || '').trim();
    const DEVICE_ID = String(runtime.deviceId || '').trim();

    if (!API_BASE_URL || !DEVICE_ID) {
        throw new Error('CONFIG PORTAIL INVALIDE');
    }

    const username = "$(username)";
    const clientIp = "$(ip)";
    const clientMac = "$(mac)";

    function setText(id, value) {
        const element = document.getElementById(id);
        if (!element) {
            return;
        }

        const text = String(value ?? '').trim();
        element.textContent = text !== '' ? text : '-';
    }

    function buildStatusUrl() {
        const base = API_BASE_URL.replace(/\/+$/, '');
        const query = new URLSearchParams({
            username: username,
            device_id: DEVICE_ID,
            ip: clientIp,
            mac: clientMac
        });

        return base + '/api/portal/mikrotik_user_status.php?' + query.toString();
    }

    fetch(buildStatusUrl(), { method: 'GET' })
        .then(function (response) {
            return response.json();
        })
        .then(function (data) {
            if (!data || data.success !== true) {
                throw new Error('STATUT PORTAIL INDISPONIBLE');
            }

            setText('status_username', data.username || username);
            setText('status_ip', data.ip || clientIp);
            setText('status_mac', data.mac || clientMac);
            setText('status_plan', data.plan);
            setText('status_time_limit', data.time_limit_label);
            setText('status_session_total', data.session_total_label);
            setText('status_data_limit', data.data_limit_label);
            setText('status_data_consumed', data.data_consumed_label);
            setText('status_data_remaining', data.data_remaining_label);
            setText('status_expiration', data.expiration);
        })
        .catch(function () {
            setText('status_username', username);
            setText('status_ip', clientIp);
            setText('status_mac', clientMac);
            setText('status_plan', 'Statut indisponible');
            setText('status_time_limit', '-');
            setText('status_session_total', '-');
            setText('status_data_limit', '-');
            setText('status_data_consumed', '-');
            setText('status_data_remaining', '-');
            setText('status_expiration', '-');
        });
})();
</script>
HTML;

    $content = (string)preg_replace(
        '#<script\b[^>]*>(?:(?!</script>).)*status_data_consumed(?:(?!</script>).)*</script>#si',
        '',
        $content
    );
    $count = 0;
    $content = (string)preg_replace('#</body>#i', $replacement . "\n</body>", $content, 1, $count);
    if ($count !== 1) {
        throw new RuntimeException('Injection MikroTik : balise body introuvable dans status.html.');
    }

    return $content;
}

function portalTemplateResolveMikrotikContentRoot(string $extractDir): string
{
    $items = array_values(array_filter(scandir($extractDir) ?: [], static fn(string $item): bool => $item !== '.' && $item !== '..'));
    if (count($items) === 1 && is_dir($extractDir . DIRECTORY_SEPARATOR . $items[0])) {
        return $extractDir . DIRECTORY_SEPARATOR . $items[0];
    }

    return $extractDir;
}

/** @return array<string,string> */
function portalTemplateRequireMikrotikPages(string $rootDir): array
{
    $pages = [];
    foreach (['login.html', 'status.html', 'logout.html'] as $required) {
        $absolute = $rootDir . DIRECTORY_SEPARATOR . $required;
        if (!is_file($absolute)) {
            throw new RuntimeException('Template MikroTik incompatible : page ' . $required . ' manquante.');
        }
        $pages[$required] = $absolute;
    }
    $errorPage = $rootDir . DIRECTORY_SEPARATOR . 'error.html';
    if (is_file($errorPage)) {
        $pages['error.html'] = $errorPage;
    }

    return $pages;
}

/** @param list<string> $imageUrls @param list<string> $captions */
function portalTemplateAdvertisementHtml(array $imageUrls, array $captions, ?string $targetUrl, bool $captionsVisible = true): string
{
    if ($imageUrls === [] || count($imageUrls) !== count($captions)) {
        throw new RuntimeException('Injection MikroTik : aucune banniere active.');
    }

    $slides = [];
    foreach (array_values($imageUrls) as $index => $image) {
        $caption = trim((string)$captions[$index]);
        $tag = '<img src="' . htmlspecialchars($image, ENT_QUOTES, 'UTF-8') . '" alt="' . htmlspecialchars($caption, ENT_QUOTES, 'UTF-8') . '" width="640" height="360" decoding="async" loading="' . ($index === 0 ? 'eager' : 'lazy') . '">';
        if ($captionsVisible) {
            $tag .= '<span class="portal-ad-caption">' . htmlspecialchars($caption, ENT_QUOTES, 'UTF-8') . '</span>';
        }
        if ($targetUrl !== null) {
            $tag = '<a href="' . htmlspecialchars($targetUrl, ENT_QUOTES, 'UTF-8') . '" target="_blank" rel="noopener noreferrer">' . $tag . '</a>';
        } else {
            $tag = '<div class="portal-ad-content">' . $tag . '</div>';
        }
        $slides[] = '<div class="portal-ad-slide' . ($index === 0 ? ' is-active' : '') . '">' . $tag . '</div>';
    }

    $script = <<<'HTML'
<script id="portal-ad-carousel">
(function () {
    var scripts = document.getElementsByTagName('script');
    var script = scripts[scripts.length - 1];
    var root = script.previousSibling;
    while (root && root.nodeType !== 1) root = root.previousSibling;
    if (!root) return;
    var slides = root.getElementsByClassName('portal-ad-slide');
    if (slides.length < 2) return;
    var current = 0;
    function rotate() {
        var previous = current;
        current = (current + 1) % slides.length;
        slides[previous].className = 'portal-ad-slide is-leaving';
        slides[current].className = 'portal-ad-slide is-active';
        window.setTimeout(function () {
            if (previous !== current) slides[previous].className = 'portal-ad-slide';
        }, 750);
    }
    window.setInterval(rotate, 4500);
})();
</script>
HTML;

    return "<!-- PORTAL_ADVERTISEMENT_START -->\n<div class=\"portal-advertisement\"><div class=\"portal-ad-viewport\">"
        . implode('', $slides)
        . '</div></div>'
        . $script . "\n<!-- PORTAL_ADVERTISEMENT_END -->";
}

function portalTemplateReplaceAdvertisement(string $content, string $pageName, string $advertisementHtml): string
{
    $markerPattern = '#<!-- PORTAL_ADVERTISEMENT_START -->.*?<!-- PORTAL_ADVERTISEMENT_END -->#si';
    if (preg_match($markerPattern, $content) !== 1) {
        throw new RuntimeException('Template MikroTik incompatible : marqueur publicitaire introuvable dans ' . $pageName . '.');
    }

    return (string)preg_replace($markerPattern, $advertisementHtml, $content, 1);
}

function portalTemplateEnsureDataRemainingRow(string $content): string
{
    $content = (string)preg_replace('/>\s*Data consomm(?:e|é)e\s*</iu', '>Données consommées<', $content);
    $content = (string)preg_replace('/>\s*Donnees restantes\s*</iu', '>Données restantes<', $content);
    if (str_contains($content, 'id="status_data_remaining"')) {
        return $content;
    }

    $pattern = '#(<div class="row">\s*<div>Données consommées</div>\s*<div id="status_data_consumed">.*?</div>\s*</div>)#si';
    $replacement = '$1' . "\n    <div class=\"row\">\n        <div>Données restantes</div>\n        <div id=\"status_data_remaining\">-</div>\n    </div>";
    $count = 0;
    $content = (string)preg_replace($pattern, $replacement, $content, 1, $count);
    if ($count !== 1) {
        throw new RuntimeException('Template MikroTik incompatible : ligne de consommation introuvable dans status.html.');
    }

    return $content;
}

function portalTemplatePatchMikrotikStyles(string $rootDir): void
{
    $styleFile = $rootDir . DIRECTORY_SEPARATOR . 'css' . DIRECTORY_SEPARATOR . 'style.css';
    if (!is_file($styleFile)) {
        throw new RuntimeException('Template MikroTik incompatible : css/style.css manquant.');
    }

    $css = (string)file_get_contents($styleFile);
    $css = (string)preg_replace('#/\* PORTAL_ADVERTISEMENT_START \*/.*?/\* PORTAL_ADVERTISEMENT_END \*/#s', '', $css);
    $css .= <<<'CSS'

/* PORTAL_ADVERTISEMENT_START */
.portal-advertisement { width: 100%; max-width: 100%; min-width: 0; margin: 12px 0; overflow: hidden; border: 1px solid var(--border); border-radius: 10px; background: rgba(0,0,0,.24); box-sizing: border-box; }
.portal-ad-viewport { position: relative; width: 100%; max-width: 100%; min-width: 0; height: auto; aspect-ratio: 2 / 1; overflow: hidden; box-sizing: border-box; }
.portal-ad-slide { position: absolute; inset: 0; display: flex; min-width: 0; align-items: center; justify-content: center; overflow: hidden; opacity: 0; visibility: hidden; transform: translateX(10%) scale(.985); transition: opacity .65s ease, transform .75s cubic-bezier(.22,.61,.36,1), visibility 0s linear .75s; }
.portal-ad-slide.is-active { opacity: 1; visibility: visible; transform: translateX(0) scale(1); transition-delay: 0s; }
.portal-ad-slide.is-leaving { opacity: 0; visibility: visible; transform: translateX(-10%) scale(.985); }
.portal-ad-slide a, .portal-ad-content { position: relative; z-index: 1; display: flex; width: 100%; max-width: 100%; min-width: 0; height: 100%; align-items: center; justify-content: center; overflow: hidden; line-height: 0; }
.portal-ad-slide img { position: relative; z-index: 1; display: block; width: 100%; max-width: 100%; min-width: 0; height: 100%; object-fit: contain; object-position: center; }
.portal-ad-caption { position: absolute; z-index: 2; right: 9px; bottom: 8px; left: 9px; padding: 7px 10px; border: 1px solid rgba(255,255,255,.14); border-radius: 7px; background: rgba(6,13,24,.58); -webkit-backdrop-filter: blur(6px); backdrop-filter: blur(6px); color: #fff; font-size: .78rem; font-weight: 600; line-height: 1.25; text-align: center; text-shadow: 0 1px 2px rgba(0,0,0,.55); }
.portal-support { display: flex; align-items: center; justify-content: center; gap: 6px; margin-top: 16px; color: rgba(255,255,255,.60); text-align: center; }
.portal-support svg { width: 16px; height: 16px; fill: #25D366; flex-shrink: 0; }
.portal-support a { color: #25D366; font-weight: 600; text-decoration: none; }
@media (prefers-reduced-motion: reduce) { .portal-ad-slide { transition: none; } }
/* PORTAL_ADVERTISEMENT_END */
CSS;
    file_put_contents($styleFile, $css, LOCK_EX);
    $styleUrl = 'css/style.css?v=' . substr(hash('sha256', $css), 0, 16);
    foreach (portalTemplateRequireMikrotikPages($rootDir) as $pageFile) {
        $html = (string)file_get_contents($pageFile);
        $html = preg_replace('#(href=["\x27])css/style\.css(?:\?[^"\x27]*)?(["\x27])#', '${1}' . $styleUrl . '${2}', $html, -1, $count);
        if ($html === null || $count !== 1) {
            throw new RuntimeException('Template MikroTik incompatible : lien css/style.css unique requis.');
        }
        file_put_contents($pageFile, $html, LOCK_EX);
    }
}

function portalTemplateZipDirectory(string $sourceDir, string $destZip): void
{
    $zip = new ZipArchive();
    if ($zip->open($destZip, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('Impossible de recreer l archive portail injectee.');
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($sourceDir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($iterator as $item) {
        $fullPath = $item->getPathname();
        $relative = substr($fullPath, strlen(rtrim($sourceDir, DIRECTORY_SEPARATOR)) + 1);
        $relative = str_replace('\\', '/', (string)$relative);

        if ($item->isDir()) {
            $zip->addEmptyDir($relative);
            continue;
        }

        if (!$zip->addFile($fullPath, $relative)) {
            $zip->close();
            throw new RuntimeException('Impossible d ajouter un fichier au ZIP injecte : ' . $relative);
        }
    }

    $zip->close();
}

/**
 * @param array{api_base_url:string,status_api_url:string,device_id:string,device_type:string} $runtime
 * @return array{entry_file:string,status_api_url:string,device_id:string}
 */
function portalInjectOpnsenseTemplateArchive(string $zipAbsolutePath, array $runtime): array
{
    if (!is_file($zipAbsolutePath) || !is_readable($zipAbsolutePath)) {
        throw new RuntimeException('Archive template introuvable pour injection.');
    }

    $workDir = portalTemplateCreateTempDir();

    try {
        $extractDir = $workDir . DIRECTORY_SEPARATOR . 'src';
        if (!@mkdir($extractDir, 0700, true) && !is_dir($extractDir)) {
            throw new RuntimeException('Impossible de preparer le dossier d extraction du template.');
        }

        $zip = new ZipArchive();
        if ($zip->open($zipAbsolutePath) !== true) {
            throw new RuntimeException('Impossible d ouvrir le template pour injection.');
        }
        if (!$zip->extractTo($extractDir)) {
            $zip->close();
            throw new RuntimeException('Impossible d extraire le template pour injection.');
        }
        $zip->close();

        $webEntries = portalTemplateFindWebEntries($extractDir);
        $entryFile = portalTemplateSelectPrimaryEntry($webEntries);
        $entryAbs = $extractDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $entryFile);

        $content = (string)file_get_contents($entryAbs);
        $content = portalTemplateInjectRuntimeScript($content, $runtime);
        $content = portalTemplatePatchOpnsenseEntry($content);
        file_put_contents($entryAbs, $content, LOCK_EX);

        $verified = (string)file_get_contents($entryAbs);
        if (
            !str_contains($verified, 'window.PORTAL_RUNTIME_CONFIG') ||
            !str_contains($verified, (string)$runtime['status_api_url']) ||
            !str_contains($verified, (string)$runtime['device_id']) ||
            !str_contains($verified, "const STATUS_DEVICE_ID = String(PORTAL_RUNTIME_CONFIG.deviceId || '').trim();") ||
            !str_contains($verified, "url.searchParams.set('device_id', STATUS_DEVICE_ID);")
        ) {
            throw new RuntimeException('Verification injection portail echouee : les marqueurs attendus ne sont pas presents.');
        }

        $rebuiltZip = $workDir . DIRECTORY_SEPARATOR . 'rebuilt.zip';
        portalTemplateZipDirectory($extractDir, $rebuiltZip);

        if (!@rename($rebuiltZip, $zipAbsolutePath)) {
            if (!@copy($rebuiltZip, $zipAbsolutePath)) {
                throw new RuntimeException('Impossible de remplacer le ZIP par la version injectee.');
            }
            @unlink($rebuiltZip);
        }

        return [
            'entry_file' => $entryFile,
            'status_api_url' => (string)$runtime['status_api_url'],
            'device_id' => (string)$runtime['device_id'],
        ];
    } finally {
        portalTemplateRemoveDir($workDir);
    }
}

/**
 * @param array{api_base_url:string,router_host:string,device_type:string,status_api_url?:string,device_id?:string,advertisement_urls:list<string>,advertisement_captions:list<string>,advertisement_target_url?:string|null} $runtime
 * @return array{entry_file:string,status_api_url:string,router_host:string,device_id:string}
 */
function portalInjectMikrotikTemplateArchive(string $zipAbsolutePath, array $runtime): array
{
    if (!is_file($zipAbsolutePath) || !is_readable($zipAbsolutePath)) {
        throw new RuntimeException('Archive template MikroTik introuvable pour injection.');
    }

    $apiBaseUrl = trim((string)($runtime['api_base_url'] ?? ''));
    $routerHost = trim((string)($runtime['router_host'] ?? ''));
    $deviceType = trim((string)($runtime['device_type'] ?? 'mikrotik'));
    $statusApiUrl = rtrim($apiBaseUrl, '/') . '/api/portal/mikrotik_user_status.php';
    $deviceId = trim((string)($runtime['device_id'] ?? ''));
    $advertisementUrls = is_array($runtime['advertisement_urls'] ?? null)
        ? array_values($runtime['advertisement_urls'])
        : [];
    $advertisementCaptions = is_array($runtime['advertisement_captions'] ?? null)
        ? array_values($runtime['advertisement_captions'])
        : [];

    if ($apiBaseUrl === '' || $routerHost === '' || $deviceId === '') {
        throw new RuntimeException('Injection MikroTik : api_base_url, router_host et device_id sont requis.');
    }
    if ($advertisementUrls === [] || count($advertisementUrls) > 8 || count($advertisementUrls) !== count($advertisementCaptions)) {
        throw new RuntimeException('Injection MikroTik : choisissez entre 1 et 8 bannieres.');
    }
    foreach ($advertisementUrls as $index => $advertisementUrl) {
        $scheme = strtolower((string)parse_url((string)$advertisementUrl, PHP_URL_SCHEME));
        if (!in_array($scheme, ['http', 'https'], true) || trim((string)$advertisementCaptions[$index]) === '') {
            throw new RuntimeException('Injection MikroTik : URL ou legende de banniere invalide.');
        }
    }

    $workDir = portalTemplateCreateTempDir();

    try {
        $extractDir = $workDir . DIRECTORY_SEPARATOR . 'src';
        if (!@mkdir($extractDir, 0700, true) && !is_dir($extractDir)) {
            throw new RuntimeException('Impossible de preparer le dossier d extraction du template MikroTik.');
        }

        $zip = new ZipArchive();
        if ($zip->open($zipAbsolutePath) !== true) {
            throw new RuntimeException('Impossible d ouvrir le template MikroTik pour injection.');
        }
        if (!$zip->extractTo($extractDir)) {
            $zip->close();
            throw new RuntimeException('Impossible d extraire le template MikroTik pour injection.');
        }
        $zip->close();

        $contentRoot = portalTemplateResolveMikrotikContentRoot($extractDir);
        $pages = portalTemplateRequireMikrotikPages($contentRoot);
        $imageDir = $contentRoot . DIRECTORY_SEPARATOR . 'img';
        if (!is_dir($imageDir) && !@mkdir($imageDir, 0755, true) && !is_dir($imageDir)) {
            throw new RuntimeException('Impossible de preparer le dossier image du template.');
        }
        foreach (scandir($imageDir) ?: [] as $existingImage) {
            if (str_starts_with($existingImage, 'portal-advertisement.')) {
                @unlink($imageDir . DIRECTORY_SEPARATOR . $existingImage);
            }
            if (preg_match('/^portal-advertisement-\d+\./', $existingImage) === 1) {
                @unlink($imageDir . DIRECTORY_SEPARATOR . $existingImage);
            }
        }
        $advertisementHtml = portalTemplateAdvertisementHtml(
            $advertisementUrls,
            $advertisementCaptions,
            normalizePortalAdvertisementTargetUrl($runtime['advertisement_target_url'] ?? null),
            (bool)($runtime['advertisement_captions_visible'] ?? true)
        );

        foreach ($pages as $pageName => $pageAbs) {
            $pageContent = (string)file_get_contents($pageAbs);
            $pageContent = portalTemplateReplaceAdvertisement($pageContent, $pageName, $advertisementHtml);
            if ($pageName === 'status.html') {
                $pageContent = portalTemplateEnsureDataRemainingRow($pageContent);
            }
            file_put_contents($pageAbs, $pageContent, LOCK_EX);
        }
        portalTemplatePatchMikrotikStyles($contentRoot);

        $entryAbs = $pages['status.html'];
        $entryFile = str_replace('\\', '/', substr($entryAbs, strlen(rtrim($extractDir, DIRECTORY_SEPARATOR)) + 1));
        $content = (string)file_get_contents($entryAbs);
        $content = portalTemplateInjectRuntimeScript($content, [
            'api_base_url' => $apiBaseUrl,
            'status_api_url' => $statusApiUrl,
            'device_id' => $deviceId,
            'device_type' => $deviceType,
            'router_host' => $routerHost,
        ]);
        $content = portalTemplatePatchMikrotikEntry($content);
        file_put_contents($entryAbs, $content, LOCK_EX);

        $verified = (string)file_get_contents($entryAbs);
        if (
            !str_contains($verified, 'window.PORTAL_RUNTIME_CONFIG') ||
            !str_contains($verified, $apiBaseUrl) ||
            !str_contains($verified, $routerHost) ||
            !str_contains($verified, $deviceId) ||
            !str_contains($verified, 'status_data_remaining') ||
            !str_contains($verified, '/api/portal/mikrotik_user_status.php')
        ) {
            throw new RuntimeException('Verification injection MikroTik echouee : les marqueurs attendus ne sont pas presents.');
        }

        $rebuiltZip = $workDir . DIRECTORY_SEPARATOR . 'rebuilt.zip';
        portalTemplateZipDirectory($extractDir, $rebuiltZip);

        if (!@rename($rebuiltZip, $zipAbsolutePath)) {
            if (!@copy($rebuiltZip, $zipAbsolutePath)) {
                throw new RuntimeException('Impossible de remplacer le ZIP MikroTik par la version injectee.');
            }
            @unlink($rebuiltZip);
        }

        return [
            'entry_file' => $entryFile,
            'status_api_url' => $statusApiUrl,
            'router_host' => $routerHost,
            'device_id' => $deviceId,
        ];
    } finally {
        portalTemplateRemoveDir($workDir);
    }
}

/** @return list<array{name:string,html_directory:string,servers:list<string>}> */
function portalMikrotikActiveHotspotProfiles(array $device): array
{
    require_once __DIR__ . '/mikrotik_backend.php';
    $api = connectToMikrotikApiByDevice($device);
    try {
        $servers = $api->comm('/ip/hotspot/print', [
            '.proplist' => '.id,name,profile,disabled,invalid',
        ]);
        $byProfile = [];
        foreach (is_array($servers) ? $servers : [] as $server) {
            if (!is_array($server) || mikrotikApiBoolean($server['disabled'] ?? false) || mikrotikApiBoolean($server['invalid'] ?? false)) {
                continue;
            }
            $profileName = trim((string)($server['profile'] ?? ''));
            if ($profileName === '') {
                continue;
            }
            $byProfile[$profileName][] = trim((string)($server['name'] ?? ''));
        }

        $result = [];
        foreach ($byProfile as $profileName => $serverNames) {
            $rows = $api->comm('/ip/hotspot/profile/print', [
                '?name' => $profileName,
                '.proplist' => '.id,name,html-directory',
            ]);
            $profile = is_array($rows) && isset($rows[0]) && is_array($rows[0]) ? $rows[0] : null;
            if (!$profile) {
                throw new RuntimeException('Profil Hotspot actif introuvable : ' . $profileName);
            }
            $result[] = [
                'name' => $profileName,
                'html_directory' => trim((string)($profile['html-directory'] ?? '')),
                'servers' => array_values(array_filter($serverNames)),
            ];
        }
        usort($result, static fn(array $a, array $b): int => strcmp($a['name'], $b['name']));

        return $result;
    } finally {
        $api->disconnect();
    }
}

function portalFtpEnsureDirectory($ftp, string $directory): void
{
    $path = '';
    foreach (array_filter(explode('/', trim($directory, '/'))) as $part) {
        $path = $path === '' ? $part : $path . '/' . $part;
        if (@ftp_chdir($ftp, $path)) {
            @ftp_chdir($ftp, '/');
            continue;
        }
        if (@ftp_mkdir($ftp, $path) === false) {
            throw new RuntimeException('FTP MikroTik : impossible de creer le dossier ' . $path . '.');
        }
    }
}

function portalFtpUploadDirectory($ftp, string $localRoot, string $remoteRoot): int
{
    $uploaded = 0;
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($localRoot, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($iterator as $item) {
        $relative = str_replace('\\', '/', substr($item->getPathname(), strlen(rtrim($localRoot, DIRECTORY_SEPARATOR)) + 1));
        $remote = trim($remoteRoot, '/') . '/' . $relative;
        if ($item->isDir()) {
            portalFtpEnsureDirectory($ftp, $remote);
            continue;
        }
        portalFtpEnsureDirectory($ftp, dirname($remote));
        if (!@ftp_put($ftp, $remote, $item->getPathname(), FTP_BINARY)) {
            throw new RuntimeException('FTP MikroTik : echec du transfert de ' . $relative . '.');
        }
        $remoteSize = @ftp_size($ftp, $remote);
        if ($remoteSize < 0 || $remoteSize !== $item->getSize()) {
            throw new RuntimeException('FTP MikroTik : verification echouee pour ' . $relative . '.');
        }
        $uploaded++;
    }

    return $uploaded;
}

/** @return array{profile:string,previous_html_directory:string,remote_directory:string,files_uploaded:int,deployed_at:string} */
function portalDeployMikrotikTemplateArchive(array $device, string $zipAbsolutePath, string $profileName): array
{
    require_once __DIR__ . '/mikrotik_backend.php';
    if (!extension_loaded('ftp')) {
        throw new RuntimeException('Extension PHP FTP indisponible.');
    }
    $profileName = trim($profileName);
    if ($profileName === '') {
        throw new RuntimeException('Selectionnez un profil Hotspot actif.');
    }

    $workDir = portalTemplateCreateTempDir();
    $api = null;
    $ftp = null;
    $profileId = '';
    $previousDirectory = '';
    $profileSwitched = false;
    try {
        $extractDir = $workDir . DIRECTORY_SEPARATOR . 'src';
        if (!@mkdir($extractDir, 0700, true) && !is_dir($extractDir)) {
            throw new RuntimeException('Impossible de preparer le template pour FTP.');
        }
        $zip = new ZipArchive();
        if ($zip->open($zipAbsolutePath) !== true || !$zip->extractTo($extractDir)) {
            $zip->close();
            throw new RuntimeException('Impossible d extraire le template pour FTP.');
        }
        $zip->close();
        $contentRoot = portalTemplateResolveMikrotikContentRoot($extractDir);
        portalTemplateRequireMikrotikPages($contentRoot);

        $api = connectToMikrotikApiByDevice($device);
        $servers = $api->comm('/ip/hotspot/print', ['.proplist' => '.id,name,profile,disabled,invalid']);
        $profileIsActive = false;
        foreach (is_array($servers) ? $servers : [] as $server) {
            if (
                is_array($server)
                && !mikrotikApiBoolean($server['disabled'] ?? false)
                && !mikrotikApiBoolean($server['invalid'] ?? false)
                && trim((string)($server['profile'] ?? '')) === $profileName
            ) {
                $profileIsActive = true;
                break;
            }
        }
        if (!$profileIsActive) {
            throw new RuntimeException('Le profil choisi n est utilise par aucun serveur Hotspot actif.');
        }

        $profiles = $api->comm('/ip/hotspot/profile/print', [
            '?name' => $profileName,
            '.proplist' => '.id,name,html-directory',
        ]);
        $profile = is_array($profiles) && isset($profiles[0]) && is_array($profiles[0]) ? $profiles[0] : null;
        if (!$profile || trim((string)($profile['.id'] ?? '')) === '') {
            throw new RuntimeException('Profil Hotspot actif introuvable sur le routeur.');
        }

        $params = mikrotikApiParamsFromDevice($device);
        $ftp = @ftp_connect((string)$params['host'], 21, 8);
        if ($ftp === false || !@ftp_login($ftp, (string)$params['username'], (string)$params['password'])) {
            throw new RuntimeException('Connexion FTP MikroTik impossible avec les identifiants du device.');
        }
        if (!@ftp_pasv($ftp, true)) {
            throw new RuntimeException('Impossible d activer le mode FTP passif sur MikroTik.');
        }

        $remoteDirectory = 'hotspot_rm_' . gmdate('Ymd_His') . '_' . bin2hex(random_bytes(3));
        portalFtpEnsureDirectory($ftp, $remoteDirectory);
        $filesUploaded = portalFtpUploadDirectory($ftp, $contentRoot, $remoteDirectory);
        if ($filesUploaded < 1) {
            throw new RuntimeException('Aucun fichier n a ete transfere vers MikroTik.');
        }

        $profileId = (string)$profile['.id'];
        $previousDirectory = trim((string)($profile['html-directory'] ?? ''));
        $response = $api->comm('/ip/hotspot/profile/set', [
            '.id' => $profileId,
            'html-directory' => $remoteDirectory,
        ]);
        mikrotikAssertNoTrap($response, 'MikroTik a refuse la bascule du dossier HTML Hotspot.');
        $profileSwitched = true;

        $verified = $api->comm('/ip/hotspot/profile/print', [
            '?.id' => $profileId,
            '.proplist' => '.id,name,html-directory',
        ]);
        $verifiedDirectory = is_array($verified) && isset($verified[0])
            ? trim((string)($verified[0]['html-directory'] ?? ''))
            : '';
        if ($verifiedDirectory !== $remoteDirectory) {
            throw new RuntimeException('Verification de la bascule du profil Hotspot echouee.');
        }

        return [
            'profile' => $profileName,
            'previous_html_directory' => $previousDirectory,
            'remote_directory' => $remoteDirectory,
            'files_uploaded' => $filesUploaded,
            'deployed_at' => gmdate(DATE_ATOM),
        ];
    } catch (Throwable $e) {
        if ($profileSwitched && $api instanceof RouterosAPI && $profileId !== '') {
            try {
                $rollback = $api->comm('/ip/hotspot/profile/set', [
                    '.id' => $profileId,
                    'html-directory' => $previousDirectory,
                ]);
                mikrotikAssertNoTrap($rollback, 'Retour arriere du dossier HTML refuse.');
            } catch (Throwable $rollbackError) {
                throw new RuntimeException(
                    $e->getMessage() . ' Retour arriere impossible : ' . $rollbackError->getMessage(),
                    0,
                    $e
                );
            }
        }
        throw $e;
    } finally {
        if ($ftp !== null && $ftp !== false) {
            @ftp_close($ftp);
        }
        if ($api instanceof RouterosAPI) {
            $api->disconnect();
        }
        portalTemplateRemoveDir($workDir);
    }
}
