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
<script>
(function () {
    const runtime = window.PORTAL_RUNTIME_CONFIG || {};

    const API_BASE_URL = String(runtime.apiBaseUrl || '').trim();
    const ROUTER_HOST = String(runtime.routerHost || '').trim();

    if (!API_BASE_URL || !ROUTER_HOST) {
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
            router_host: ROUTER_HOST,
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
            setText('status_expiration', data.expiration);
        })
        .catch(function () {
            setText('status_username', username);
            setText('status_ip', clientIp);
            setText('status_mac', clientMac);
            setText('status_plan', 'Erreur');
            setText('status_time_limit', 'Erreur');
            setText('status_session_total', 'Erreur');
            setText('status_data_limit', 'Erreur');
            setText('status_data_consumed', 'Erreur');
            setText('status_expiration', 'Erreur');
        });
})();
</script>
HTML;

    $count = 0;
    $content = (string)preg_replace(
        '#<script>\s*\(function\s*\(\)\s*\{.*?\}\)\(\);\s*</script>#si',
        $replacement,
        $content,
        1,
        $count
    );

    if ($count !== 1) {
        throw new RuntimeException('Injection MikroTik : bloc script principal introuvable.');
    }

    return $content;
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
 * @param array{api_base_url:string,router_host:string,device_type:string,status_api_url?:string,device_id?:string} $runtime
 * @return array{entry_file:string,status_api_url:string,router_host:string}
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

    if ($apiBaseUrl === '' || $routerHost === '') {
        throw new RuntimeException('Injection MikroTik : api_base_url et router_host sont requis.');
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

        $webEntries = portalTemplateFindWebEntries($extractDir);
        $entryFile = portalTemplateSelectPrimaryEntry($webEntries);
        $entryAbs = $extractDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $entryFile);

        $content = (string)file_get_contents($entryAbs);
        $content = portalTemplateInjectRuntimeScript($content, [
            'api_base_url' => $apiBaseUrl,
            'status_api_url' => $statusApiUrl,
            'device_id' => '',
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
        ];
    } finally {
        portalTemplateRemoveDir($workDir);
    }
}
