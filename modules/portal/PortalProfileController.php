<?php

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/message.php';
require_once __DIR__ . '/../../includes/portal_hotspot.php';
require_once __DIR__ . '/../../includes/portal_template_injector.php';
require_once __DIR__ . '/../../includes/device_manager.php';
require_once __DIR__ . '/../../includes/mikrotik_backend.php';
require_once __DIR__ . '/../../includes/operation_history.php';

/**
 * @return array{
 *   csrf_token:string,
 *   portal_config:array,
 *   captive_device_label:string,
 *   stored_templates:list<array{name:string,path:string,mtime:int,size:int,is_active:bool}>,
 *   devices:list<array>,
 *   logos:list<string>,
 *   advertisements:list<string>
 * }
 */
function portalHandleProfilesRequest(): array
{
    global $pdo;

    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    if ($_SERVER['REQUEST_METHOD'] === 'GET' && trim((string)($_GET['portal_action'] ?? '')) === 'active_hotspot_profiles') {
        portalProfilesRenderActiveHotspotProfiles();
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        try {
            portalProfilesAssertCsrf();
            $action = trim((string)($_POST['portal_action'] ?? ''));

            if ($action === 'deploy_template') {
                portalProfilesDeployTemplate($pdo);
            } elseif ($action === 'delete_template') {
                portalProfilesDeleteTemplate($pdo);
            } elseif ($action === 'add_advertisement') {
                portalProfilesAddAdvertisement($pdo);
            } elseif ($action === 'delete_advertisement') {
                portalProfilesDeleteAdvertisement($pdo);
            }
        } catch (Throwable $e) {
            set_message($e->getMessage(), 'danger');
        }

        header('Location: /pages/portal_profiles.php');
        exit();
    }

    $config = loadPortalHotspotConfig();
    $store = loadDeviceStore();
    $deviceId = trim((string)($config['captive_device_id'] ?? ''));

    return [
        'csrf_token' => (string)$_SESSION['csrf_token'],
        'portal_config' => $config,
        'captive_device_label' => portalProfilesResolveDeviceLabel($store, $deviceId),
        'stored_templates' => portalProfilesListStoredZipTemplates((string)($config['template_relative_path'] ?? '')),
        'devices' => is_array($store['devices'] ?? null) ? $store['devices'] : [],
        'logos' => portalListCaptiveLogoFiles(),
        'advertisements' => portalListCaptiveAdvertisementFiles(),
    ];
}

function portalProfilesRenderActiveHotspotProfiles(): never
{
    header('Content-Type: application/json; charset=UTF-8');
    try {
        $store = loadDeviceStore();
        $deviceId = trim((string)($_GET['device_id'] ?? ''));
        $device = requireAccessibleDevice($store, $deviceId);
        if (deriveDeviceType($device) !== 'mikrotik') {
            throw new RuntimeException('Le device selectionne n est pas un MikroTik.');
        }
        echo json_encode([
            'success' => true,
            'profiles' => portalMikrotikActiveHotspotProfiles($device),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    } catch (Throwable $e) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

function portalProfilesAssertCsrf(): void
{
    $csrf = trim((string)($_POST['csrf_token'] ?? ''));
    if ($csrf === '' || !hash_equals((string)($_SESSION['csrf_token'] ?? ''), $csrf)) {
        throw new RuntimeException('CSRF invalide.');
    }
}

function portalProfilesDeployTemplate(PDO $pdo): void
{
    $store = loadDeviceStore();
    $deviceId = trim((string)($_POST['portal_device_id'] ?? ''));
    $device = findDeviceById($store, $deviceId);
    if ($device === null) {
        throw new RuntimeException('Choisissez un device valide.');
    }

    try {
        $deviceType = deriveDeviceType($device);
    } catch (InvalidArgumentException $e) {
        throw new RuntimeException('Type de device inconnu pour ce NAS.');
    }

    $portalApiBaseUrl = normalizePortalApiBaseUrl((string)($_POST['portal_api_base_url'] ?? ''));
    $zipFile = $_FILES['portal_template_zip'] ?? null;
    $hasUploadedZip = is_array($zipFile) && (int)($zipFile['error'] ?? 0) === UPLOAD_ERR_OK;
    $tmpZip = '';
    $sourceTemplateName = null;

    if ($hasUploadedZip) {
        $zipMax = portalCaptiveZipUploadMaxBytes();
        if ((int)($zipFile['size'] ?? 0) > $zipMax) {
            throw new RuntimeException('ZIP : taille max ' . (int)($zipMax / 1024 / 1024) . ' Mo.');
        }

        $zipBaseName = (string)($zipFile['name'] ?? '');
        if (strtolower(pathinfo($zipBaseName, PATHINFO_EXTENSION)) !== 'zip') {
            throw new RuntimeException('Le modele doit etre une archive .zip.');
        }

        $tmpZip = (string)($zipFile['tmp_name'] ?? '');
        $sourceTemplateName = $zipBaseName;
    } else {
        $err = (int)($zipFile['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) {
            throw new RuntimeException('ZIP trop volumineux.');
        }

        $defaultZip = portalDefaultTemplatePathForDeviceType($deviceType);
        if ($defaultZip === null) {
            throw new RuntimeException('Aucun modele par defaut disponible pour ce type de device.');
        }
        $tmpZip = $defaultZip;
        $sourceTemplateName = basename($defaultZip);
    }

    $analysis = portalAnalyzeCaptiveZip($tmpZip);
    $compat = portalCompatMessageForDevice($deviceType, $analysis);

    portalEnsureCaptiveDirectories();
    $safeId = preg_replace('/[^a-zA-Z0-9_-]+/', '_', $deviceId);
    $destName = 'captive_' . $safeId . '_' . date('Ymd_His') . '.zip';
    $destAbs = portalCaptiveFilesystemRoot() . '/templates/' . $destName;
    $stored = $hasUploadedZip ? move_uploaded_file($tmpZip, $destAbs) : copy($tmpZip, $destAbs);
    if (!$stored) {
        throw new RuntimeException('Impossible d enregistrer l archive sur le serveur.');
    }

    $prev = loadPortalHotspotConfig();
    $knownAdvertisements = portalListCaptiveAdvertisementFiles();
    $advertisementRels = [];
    $advertisementCaptions = [];
    $postedExistingCaptions = is_array($_POST['portal_advertisement_existing_captions'] ?? null)
        ? $_POST['portal_advertisement_existing_captions']
        : [];
    foreach (is_array($_POST['portal_advertisement_existing'] ?? null) ? $_POST['portal_advertisement_existing'] : [] as $existingAdvertisement) {
        $existingAdvertisement = basename(trim((string)$existingAdvertisement));
        if ($existingAdvertisement !== '') {
            if (!in_array($existingAdvertisement, $knownAdvertisements, true)) {
                throw new RuntimeException('Banniere existante invalide.');
            }
            $advertisementRels[] = 'uploads/portal_captive/advertisements/' . $existingAdvertisement;
            $advertisementCaptions[] = trim((string)($postedExistingCaptions[sha1($existingAdvertisement)] ?? ''));
        }
    }
    // L'ajout de nouvelles images se fait desormais a part (action add_advertisement, une image a la fois),
    // pas au moment du deploiement du template : on ne lit plus de fichiers ici.
    $advertisementRels = array_values(array_unique($advertisementRels));
    if (count($advertisementRels) > 8) {
        throw new RuntimeException('Bannieres : maximum 8 images actives.');
    }
    $advertisementCaptions = normalizePortalAdvertisementCaptions($advertisementCaptions, count($advertisementRels));
    $advertisementTargetUrl = normalizePortalAdvertisementTargetUrl($_POST['portal_advertisement_target_url'] ?? null);

    $injectionMeta = null;
    $deploymentMeta = null;
    if ($deviceType === 'opnsense') {
        $statusApiUrl = rtrim($portalApiBaseUrl, '/') . '/api/portal/opnsense_user_status.php';
        $injectionMeta = portalInjectOpnsenseTemplateArchive($destAbs, [
            'api_base_url' => $portalApiBaseUrl,
            'status_api_url' => $statusApiUrl,
            'device_id' => $deviceId,
            'device_type' => $deviceType,
        ]);
    } elseif ($deviceType === 'mikrotik') {
        if ($advertisementRels === []) {
            throw new RuntimeException('Choisissez au moins une banniere avant le deploiement MikroTik.');
        }
        $advertisementUrls = array_map(
            static fn(string $path): string => rtrim($portalApiBaseUrl, '/') . '/' . ltrim($path, '/'),
            $advertisementRels
        );
        $routerHost = extractDeviceAddress((string)($device['host'] ?? ''));
        if ($routerHost === '') {
            $routerHost = trim((string)($device['ip'] ?? ''));
        }
        if ($routerHost === '') {
            throw new RuntimeException('Adresse routeur MikroTik introuvable pour injection.');
        }

        $injectionMeta = portalInjectMikrotikTemplateArchive($destAbs, [
            'api_base_url' => $portalApiBaseUrl,
            'device_id' => $deviceId,
            'router_host' => $routerHost,
            'device_type' => $deviceType,
            'advertisement_urls' => $advertisementUrls,
            'advertisement_captions' => $advertisementCaptions,
            'advertisement_captions_visible' => (bool)($prev['advertisement_captions_visible'] ?? true),
            'advertisement_target_url' => $advertisementTargetUrl,
        ]);

        $activeProfiles = portalMikrotikActiveHotspotProfiles($device);
        if ($activeProfiles === []) {
            throw new RuntimeException('Aucun profil utilise par un serveur Hotspot actif.');
        }
        $requestedProfile = trim((string)($_POST['portal_hotspot_profile'] ?? ''));
        if (count($activeProfiles) === 1 && $requestedProfile === '') {
            $requestedProfile = (string)$activeProfiles[0]['name'];
        }
        $activeProfileNames = array_column($activeProfiles, 'name');
        if (!in_array($requestedProfile, $activeProfileNames, true)) {
            throw new RuntimeException('Selectionnez un profil Hotspot actif valide.');
        }
        $deploymentMeta = portalDeployMikrotikTemplateArchive($device, $destAbs, $requestedProfile);
    }

    $templateRel = 'uploads/portal_captive/templates/' . $destName;
    $logoRel = $prev['logo_relative_path'] ?? null;

    $logoUpload = $_FILES['portal_logo'] ?? null;
    if (is_array($logoUpload) && (int)($logoUpload['error'] ?? 0) === UPLOAD_ERR_OK && (int)($logoUpload['size'] ?? 0) > 0) {
        $logoRel = portalStoreCaptiveLogoUpload($logoUpload);
    } else {
        $existingLogo = trim((string)($_POST['portal_logo_existing'] ?? ''));
        if ($existingLogo !== '') {
            $pick = basename($existingLogo);
            if (in_array($pick, portalListCaptiveLogoFiles(), true)) {
                $logoRel = 'uploads/portal_captive/logos/' . $pick;
            }
        }
    }

    $lastCompat = [
        'level' => $compat['level'],
        'summary' => $compat['summary'],
        'detail' => $compat['detail'],
        'device_type' => $deviceType,
        'zip_files' => $analysis['file_count'],
        'deploy_note' => is_array($deploymentMeta) ? 'Template transfere et active sur MikroTik.' : 'Archive stockee sur ce serveur.',
        'injected_entry_file' => is_array($injectionMeta) ? (string)($injectionMeta['entry_file'] ?? '') : null,
        'status_api_url' => is_array($injectionMeta) ? (string)($injectionMeta['status_api_url'] ?? '') : null,
        'injected_device_id' => is_array($injectionMeta) ? (string)($injectionMeta['device_id'] ?? '') : null,
        'injected_router_host' => is_array($injectionMeta) ? (string)($injectionMeta['router_host'] ?? '') : null,
        'injection_verified' => is_array($injectionMeta),
        'source_template' => $sourceTemplateName,
        'active_hotspot_profile' => is_array($deploymentMeta) ? (string)$deploymentMeta['profile'] : null,
        'remote_directory' => is_array($deploymentMeta) ? (string)$deploymentMeta['remote_directory'] : null,
    ];

    savePortalHotspotConfig([
        'api_base_url' => $portalApiBaseUrl,
        'captive_device_id' => $deviceId,
        'template_relative_path' => $templateRel,
        'logo_relative_path' => $logoRel,
        'advertisement_relative_paths' => $advertisementRels,
        'advertisement_captions' => $advertisementCaptions,
        'advertisement_captions_visible' => (bool)($prev['advertisement_captions_visible'] ?? true),
        'advertisement_target_url' => $advertisementTargetUrl,
        'active_hotspot_profile' => is_array($deploymentMeta) ? $deploymentMeta['profile'] : null,
        'last_deployment' => $deploymentMeta,
        'last_compat' => $lastCompat,
    ]);

    recordOperationHistory($pdo, [
        'operation_scope' => 'admin',
        'operation_type' => 'portal_captive_deploy',
        'actor_username' => (string)($_SESSION['username'] ?? ''),
        'actor_role' => (string)($_SESSION['user_role'] ?? 'administrator'),
        'target_type' => 'portal_captive',
        'target_name' => (string)($device['name'] ?? $device['host'] ?? $deviceId),
        'target_ref' => $deviceId,
        'summary' => is_array($deploymentMeta)
            ? 'Portail captif deploye sur le profil Hotspot actif'
            : 'Portail captif enregistre localement',
        'details_json' => [
            'api_base_url' => $portalApiBaseUrl,
            'template_relative_path' => $templateRel,
            'logo_relative_path' => $logoRel,
            'advertisement_relative_paths' => $advertisementRels,
            'advertisement_captions' => $advertisementCaptions,
            'advertisement_target_url' => $advertisementTargetUrl,
            'compat_level' => $compat['level'],
            'injected_entry_file' => is_array($injectionMeta) ? (string)($injectionMeta['entry_file'] ?? '') : null,
            'status_api_url' => is_array($injectionMeta) ? (string)($injectionMeta['status_api_url'] ?? '') : null,
            'injected_device_id' => is_array($injectionMeta) ? (string)($injectionMeta['device_id'] ?? '') : null,
            'injected_router_host' => is_array($injectionMeta) ? (string)($injectionMeta['router_host'] ?? '') : null,
            'injection_verified' => is_array($injectionMeta),
            'source_template' => $sourceTemplateName,
            'deployment' => $deploymentMeta,
        ],
    ]);

    set_message(
        'Portail captif : template modifie' . (is_array($deploymentMeta) ? ', transfere et active sur MikroTik. ' : ' et enregistre sur ce serveur. ')
        . $compat['summary'] . ' - ' . $compat['detail'],
        $compat['level'] === 'warn' ? 'warning' : 'success'
    );
}

function portalProfilesDeleteTemplate(PDO $pdo): void
{
    portalEnsureCaptiveDirectories();

    $templateName = basename(trim((string)($_POST['template_name'] ?? '')));
    if ($templateName === '' || strtolower(pathinfo($templateName, PATHINFO_EXTENSION)) !== 'zip') {
        throw new RuntimeException('Archive invalide.');
    }
    if (in_array($templateName, ['default_mikrotik.zip', 'default_opnsense.zip'], true)) {
        throw new RuntimeException('Un modele par defaut ne peut pas etre supprime.');
    }

    $templates = portalProfilesListStoredZipTemplates();
    $known = false;
    foreach ($templates as $row) {
        if (($row['name'] ?? '') === $templateName) {
            $known = true;
            break;
        }
    }
    if (!$known) {
        throw new RuntimeException('Archive introuvable sur le serveur.');
    }

    $abs = portalCaptiveFilesystemRoot() . '/templates/' . $templateName;
    if (!is_file($abs) || !@unlink($abs)) {
        throw new RuntimeException('Impossible de supprimer cette archive.');
    }

    $config = loadPortalHotspotConfig();
    $activeRel = trim((string)($config['template_relative_path'] ?? ''));
    if ($activeRel !== '' && basename($activeRel) === $templateName) {
        savePortalHotspotConfig([
            'template_relative_path' => null,
            'last_compat' => null,
        ]);
    }

    recordOperationHistory($pdo, [
        'operation_scope' => 'admin',
        'operation_type' => 'portal_captive_template_delete',
        'actor_username' => (string)($_SESSION['username'] ?? ''),
        'actor_role' => (string)($_SESSION['user_role'] ?? 'administrator'),
        'target_type' => 'portal_captive_template',
        'target_name' => $templateName,
        'summary' => 'Archive portail captif supprimee du serveur',
    ]);

    set_message('Archive portail supprimee du serveur.', 'success');
}

function portalProfilesAddAdvertisement(PDO $pdo): void
{
    portalEnsureCaptiveDirectories();

    $uploads = $_FILES['portal_advertisements'] ?? null;
    if (!is_array($uploads) || empty(array_filter((array)($uploads['name'] ?? [])))) {
        throw new RuntimeException('Choisissez au moins une image.');
    }

    $newRels = portalStoreCaptiveAdvertisementUploads($uploads);
    if ($newRels === []) {
        throw new RuntimeException('Aucune image valide a ajouter.');
    }

    // Legende facultative : une ligne manquante ou en trop est completee/ignoree, pas bloquante.
    $rawCaptions = trim((string)($_POST['portal_advertisement_upload_captions'] ?? ''));
    $newCaptions = $rawCaptions === ''
        ? []
        : array_map('trim', preg_split('/\R/u', $rawCaptions) ?: []);
    $newCaptions = array_pad(array_slice($newCaptions, 0, count($newRels)), count($newRels), '');

    $config = loadPortalHotspotConfig();
    $paths = is_array($config['advertisement_relative_paths'] ?? null) ? array_values($config['advertisement_relative_paths']) : [];
    $captions = is_array($config['advertisement_captions'] ?? null) ? array_values($config['advertisement_captions']) : [];
    // Noms de fichiers generes uniques par portalStoreCaptiveAdvertisementUploads : pas de doublon a gerer ici.
    $paths = array_merge($paths, $newRels);
    $captions = array_merge($captions, $newCaptions);
    if (count($paths) > 8) {
        throw new RuntimeException('Bannieres : maximum 8 images actives. Supprimez-en une avant d en ajouter.');
    }

    savePortalHotspotConfig([
        'advertisement_relative_paths' => $paths,
        'advertisement_captions' => $captions,
    ]);

    recordOperationHistory($pdo, [
        'operation_scope' => 'admin',
        'operation_type' => 'portal_captive_advertisement_add',
        'actor_username' => (string)($_SESSION['username'] ?? ''),
        'actor_role' => (string)($_SESSION['user_role'] ?? 'administrator'),
        'target_type' => 'portal_captive_advertisement',
        'target_name' => implode(', ', array_map('basename', $newRels)),
        'summary' => 'Banniere(s) portail captif ajoutee(s)',
        'details_json' => ['added' => $newRels],
    ]);

    set_message(count($newRels) > 1 ? 'Images ajoutees.' : 'Image ajoutee.', 'success');
}

function portalProfilesDeleteAdvertisement(PDO $pdo): void
{
    portalEnsureCaptiveDirectories();

    $advertisementName = basename(trim((string)($_POST['advertisement_name'] ?? '')));
    if ($advertisementName === '' || !preg_match('/\.(png|jpe?g|gif|webp)$/i', $advertisementName)) {
        throw new RuntimeException('Image invalide.');
    }
    if (!in_array($advertisementName, portalListCaptiveAdvertisementFiles(), true)) {
        throw new RuntimeException('Image introuvable sur le serveur.');
    }

    $abs = portalCaptiveFilesystemRoot() . '/advertisements/' . $advertisementName;
    if (!is_file($abs) || !@unlink($abs)) {
        throw new RuntimeException('Impossible de supprimer cette image.');
    }

    // Retire aussi l'image de la banniere active, si elle y figurait (chemin et legende au meme index).
    $config = loadPortalHotspotConfig();
    $paths = is_array($config['advertisement_relative_paths'] ?? null) ? array_values($config['advertisement_relative_paths']) : [];
    $captions = is_array($config['advertisement_captions'] ?? null) ? array_values($config['advertisement_captions']) : [];
    $keptPaths = [];
    $keptCaptions = [];
    foreach ($paths as $index => $path) {
        if (basename((string)$path) === $advertisementName) {
            continue;
        }
        $keptPaths[] = $path;
        $keptCaptions[] = $captions[$index] ?? '';
    }
    if (count($keptPaths) !== count($paths)) {
        savePortalHotspotConfig([
            'advertisement_relative_paths' => $keptPaths,
            'advertisement_captions' => $keptCaptions,
        ]);
    }

    recordOperationHistory($pdo, [
        'operation_scope' => 'admin',
        'operation_type' => 'portal_captive_advertisement_delete',
        'actor_username' => (string)($_SESSION['username'] ?? ''),
        'actor_role' => (string)($_SESSION['user_role'] ?? 'administrator'),
        'target_type' => 'portal_captive_advertisement',
        'target_name' => $advertisementName,
        'summary' => 'Banniere portail captif supprimee du serveur',
    ]);

    set_message('Image supprimee du serveur.', 'success');
}

function portalProfilesResolveDeviceLabel(array $store, string $deviceId): string
{
    if ($deviceId === '') {
        return '';
    }

    $device = findDeviceById($store, $deviceId);
    if ($device === null) {
        return 'Device inconnu ou supprime (ID : ' . $deviceId . ')';
    }

    return portalProfilesDeviceLabel($device);
}

function portalProfilesDeviceLabel(array $device): string
{
    $typeLabel = strtoupper((string)($device['type'] ?? ''));
    $nameLabel = trim((string)($device['name'] ?? ''));
    $hostLabel = trim((string)($device['host'] ?? ''));

    if ($nameLabel !== '') {
        return $nameLabel . ' (' . $typeLabel . ')';
    }

    return $hostLabel !== '' ? ($hostLabel . ' (' . $typeLabel . ')') : ('Device (' . $typeLabel . ')');
}

/**
 * @return list<array{name:string,path:string,mtime:int,size:int,is_active:bool}>
 */
function portalProfilesListStoredZipTemplates(string $activeTemplateRel = ''): array
{
    try {
        portalEnsureCaptiveDirectories();
    } catch (Throwable $e) {
        return [];
    }

    $activeBase = $activeTemplateRel !== '' ? basename($activeTemplateRel) : '';
    $dir = portalCaptiveFilesystemRoot() . '/templates';
    $out = [];
    foreach (scandir($dir) ?: [] as $name) {
        if ($name === '.' || $name === '..') {
            continue;
        }
        if (!str_ends_with(strtolower($name), '.zip')) {
            continue;
        }
        $abs = $dir . '/' . $name;
        if (!is_file($abs)) {
            continue;
        }
        $out[] = [
            'name' => $name,
            'path' => 'uploads/portal_captive/templates/' . $name,
            'mtime' => (int)@filemtime($abs),
            'size' => (int)@filesize($abs),
            'is_active' => $activeBase !== '' && $activeBase === $name,
            'is_default' => in_array($name, ['default_mikrotik.zip', 'default_opnsense.zip'], true),
        ];
    }

    usort($out, static function (array $a, array $b): int {
        return ($b['mtime'] ?? 0) <=> ($a['mtime'] ?? 0);
    });

    return $out;
}

function portalProfilesHumanBytes(int $bytes): string
{
    if ($bytes >= 1048576) {
        return number_format($bytes / 1048576, 1, ',', ' ') . ' Mo';
    }

    if ($bytes >= 1024) {
        return number_format($bytes / 1024, 1, ',', ' ') . ' Ko';
    }

    return $bytes . ' o';
}

function portalProfilesTemplateDownloadUrl(string $name): string
{
    return '/uploads/portal_captive/templates/' . rawurlencode(basename($name));
}

/**
 * @param array{
 *   csrf_token:string,
 *   portal_config:array,
 *   captive_device_label:string,
 *   stored_templates:list<array{name:string,path:string,mtime:int,size:int,is_active:bool}>,
 *   devices:list<array>,
 *   logos:list<string>
 * } $data
 */
function renderPortalProfilesPage(array $data): void
{
    global $pdo;

    $config = $data['portal_config'];
    $apiBase = (string)($config['api_base_url'] ?? 'http://10.10.10.2');
    $templateRel = trim((string)($config['template_relative_path'] ?? ''));
    $logoRel = trim((string)($config['logo_relative_path'] ?? ''));
    $lastCompat = $config['last_compat'] ?? null;
    $deviceLabel = (string)($data['captive_device_label'] ?? '');
    $templates = $data['stored_templates'] ?? [];
    $devices = $data['devices'] ?? [];
    $logos = $data['logos'] ?? [];
    $advertisements = $data['advertisements'] ?? [];
    $currentLogoBase = $logoRel !== '' ? basename($logoRel) : '';
    $advertisementRels = is_array($config['advertisement_relative_paths'] ?? null)
        ? array_values($config['advertisement_relative_paths'])
        : [];
    $advertisementCaptions = is_array($config['advertisement_captions'] ?? null)
        ? array_values($config['advertisement_captions'])
        : [];
    $advertisementCaptionByBase = [];
    foreach ($advertisementRels as $index => $advertisementRel) {
        $advertisementCaptionByBase[basename((string)$advertisementRel)] = (string)($advertisementCaptions[$index] ?? '');
    }
    $currentAdvertisementBases = array_map('basename', $advertisementRels);
    $advertisementTargetUrl = (string)($config['advertisement_target_url'] ?? '');
    $activeHotspotProfile = (string)($config['active_hotspot_profile'] ?? '');
    $activeTemplateName = $templateRel !== '' ? basename($templateRel) : '';
    $username = htmlspecialchars((string)($_SESSION['username'] ?? ''), ENT_QUOTES, 'UTF-8');

    $pageTitle = 'Profils portail captif';
    $bodyClass = 'portal-profiles-page';
    $extraCss = [
        '../css/portal_profiles.css?v=20261009d',
    ];
    require_once __DIR__ . '/../../includes/layout_header.php';
    ?>

    <p class="portal-page-subtitle">Préparez l'archive du portail captif, gérez ses bannières et déployez-la sur un appareil, sans quitter cette page.</p>

    <div class="row g-3 portal-layout">
        <div class="col-xl-7">
            <div class="card shadow-sm">
                <div class="card-header standard-card-header">
                    <span class="portal-card-title"><i class="fa fa-wand-magic-sparkles me-2"></i> Mettre en place un template</span>
                </div>
                <div class="card-body">
                    <form method="POST" class="network-device-form portal-form" enctype="multipart/form-data" autocomplete="off">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string)$data['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
                        <input type="hidden" name="portal_action" value="deploy_template">

                        <div class="portal-form-section">
                        <div class="portal-form-section-title"><i class="fa fa-bullseye"></i> Cible et connexion</div>
                        <div class="portal-field-grid">
                        <div class="portal-field-item">
                            <label class="form-label small text-white-50 mb-1" for="portalDeviceSelect"><i class="fa fa-server" aria-hidden="true"></i> Device cible</label>
                            <select class="form-select" name="portal_device_id" id="portalDeviceSelect" required <?= count($devices) < 1 ? 'disabled' : '' ?>>
                                <option value="">— Choisir un device —</option>
                                <?php foreach ($devices as $device): ?>
                                    <?php $deviceId = (string)($device['id'] ?? ''); ?>
                                    <?php $optionType = strtolower(trim((string)($device['type'] ?? ''))); ?>
                                    <option value="<?= htmlspecialchars($deviceId, ENT_QUOTES, 'UTF-8') ?>" data-device-type="<?= htmlspecialchars($optionType, ENT_QUOTES, 'UTF-8') ?>"<?= ((string)($config['captive_device_id'] ?? '') === $deviceId) ? ' selected' : '' ?>>
                                        <?= htmlspecialchars(portalProfilesDeviceLabel($device), ENT_QUOTES, 'UTF-8') ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <?php if (count($devices) < 1): ?>
                                <div class="small text-warning mt-1">Aucun device configure. Ajoutez un NAS dans Network Devices.</div>
                            <?php endif; ?>
                        </div>

                        <div class="portal-field-item" id="portalHotspotProfileGroup">
                            <label class="form-label small text-white-50 mb-1" for="portalHotspotProfile"><i class="fa fa-sliders" aria-hidden="true"></i> Profil Hotspot actif</label>
                            <select class="form-select" name="portal_hotspot_profile" id="portalHotspotProfile" data-current-profile="<?= htmlspecialchars($activeHotspotProfile, ENT_QUOTES, 'UTF-8') ?>" disabled>
                                <option value="">— Selectionnez d abord un MikroTik —</option>
                            </select>
                            <div class="small text-white-50 mt-1" id="portalHotspotProfileHint">Seuls les profils utilises par un serveur Hotspot actif sont proposes.</div>
                        </div>

                        <div class="portal-field-item portal-field-span2">
                            <label class="form-label small text-white-50 mb-1" for="portalApiBaseUrl"><i class="fa fa-plug" aria-hidden="true"></i> Adresse serveur API</label>
                            <input
                                type="text"
                                class="form-control"
                                name="portal_api_base_url"
                                id="portalApiBaseUrl"
                                value="<?= htmlspecialchars($apiBase, ENT_QUOTES, 'UTF-8') ?>"
                                placeholder="https://votre-serveur"
                                required
                            >
                        </div>
                        </div>
                        </div>

                        <div class="portal-form-section">
                        <div class="portal-form-section-title"><i class="fa fa-file-zipper"></i> Modele</div>
                        <div class="portal-field-grid">
                        <div class="portal-field-item">
                            <label class="form-label small text-white-50 mb-1" for="portalTemplateZip"><i class="fa fa-file-zipper" aria-hidden="true"></i> Archive template (.zip)</label>
                            <div class="portal-upload-control">
                                <input type="file" class="portal-upload-input" name="portal_template_zip" id="portalTemplateZip" accept=".zip,application/zip" data-file-label="portalTemplateZipName">
                                <label class="portal-upload-button" for="portalTemplateZip">
                                    <i class="fa fa-file-zipper me-1"></i> Choisir le ZIP
                                </label>
                                <span class="portal-upload-name" id="portalTemplateZipName">Modele par defaut selon le type</span>
                            </div>
                            <div class="small text-white-50 mt-1" id="portalDefaultTemplateHint">Sans ZIP manuel, le modele par defaut du type selectionne sera utilise.</div>
                        </div>

                        <div class="portal-field-item">
                            <label class="form-label small text-white-50 mb-1" for="portalLogoFile"><i class="fa fa-image" aria-hidden="true"></i> Logo optionnel</label>
                            <div class="portal-upload-control">
                                <input type="file" class="portal-upload-input" name="portal_logo" id="portalLogoFile" accept="image/png,image/jpeg,image/gif,image/webp" data-file-label="portalLogoFileName">
                                <label class="portal-upload-button" for="portalLogoFile">
                                    <i class="fa fa-image me-1"></i> Choisir le logo
                                </label>
                                <span class="portal-upload-name" id="portalLogoFileName">Aucun fichier choisi</span>
                            </div>
                            <?php if (count($logos) > 0): ?>
                                <div class="mt-2">
                                    <label class="form-label small text-white-50 mb-1" for="portalLogoExisting">Ou logo deja depose</label>
                                    <select class="form-select" name="portal_logo_existing" id="portalLogoExisting">
                                        <option value="">— Aucun —</option>
                                        <?php foreach ($logos as $logoName): ?>
                                            <option value="<?= htmlspecialchars($logoName, ENT_QUOTES, 'UTF-8') ?>"<?= ($currentLogoBase === $logoName) ? ' selected' : '' ?>>
                                                <?= htmlspecialchars($logoName, ENT_QUOTES, 'UTF-8') ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            <?php endif; ?>
                        </div>
                        </div>
                        </div>

                        <div class="portal-form-section">
                        <div class="portal-form-section-title"><i class="fa fa-rectangle-ad"></i> Bannieres publicitaires</div>
                        <div class="mb-3">
                            <label class="form-label small text-white-50 mb-1" for="portalAdvertisementFile">Ajouter des bannieres (une par une ou en groupe, hors deploiement)</label>
                            <div class="portal-upload-control">
                                <input type="file" class="portal-upload-input" name="portal_advertisements[]" id="portalAdvertisementFile" accept="image/png,image/jpeg,image/gif,image/webp" data-file-label="portalAdvertisementFileName" multiple>
                                <label class="portal-upload-button" for="portalAdvertisementFile">
                                    <i class="fa fa-rectangle-ad me-1"></i> Choisir l image
                                </label>
                                <span class="portal-upload-name" id="portalAdvertisementFileName">Ajouter jusqu a 8 images</span>
                            </div>
                            <div class="portal-upload-preview" id="portalAdvertisementPreview" aria-label="Apercu des nouvelles images"></div>
                            <div class="mt-2">
                                <label class="form-label small text-white-50 mb-1" for="portalAdvertisementUploadCaptions">Legendes des nouvelles images</label>
                                <textarea class="form-control" name="portal_advertisement_upload_captions" id="portalAdvertisementUploadCaptions" rows="3" placeholder="Une legende par ligne, dans l ordre des fichiers (facultatif)"></textarea>
                            </div>
                            <button type="button" class="btn btn-sm btn-save mt-2" id="portalAdvertisementAddBtn" disabled>
                                <i class="fa fa-plus me-1" aria-hidden="true"></i> Ajouter ces images
                            </button>
                            <?php if (count($advertisements) > 0): ?>
                                <div class="mt-2" id="portalBannerList">
                                    <span class="form-label small text-white-50 mb-1 d-block">Images actives existantes (ordre = ordre d'affichage)</span>
                                    <?php foreach ($advertisements as $advertisementName): ?>
                                        <div class="portal-banner-item">
                                            <img class="portal-banner-thumb" src="../uploads/portal_captive/advertisements/<?= htmlspecialchars($advertisementName, ENT_QUOTES, 'UTF-8') ?>" alt="" loading="lazy">
                                            <div class="portal-banner-fields">
                                                <label class="d-flex align-items-center gap-2 small text-white-50 mb-1">
                                                    <input type="checkbox" name="portal_advertisement_existing[]" value="<?= htmlspecialchars($advertisementName, ENT_QUOTES, 'UTF-8') ?>"<?= in_array($advertisementName, $currentAdvertisementBases, true) ? ' checked' : '' ?>>
                                                    <span><?= htmlspecialchars($advertisementName, ENT_QUOTES, 'UTF-8') ?></span>
                                                </label>
                                                <input type="text" class="form-control form-control-sm" name="portal_advertisement_existing_captions[<?= sha1($advertisementName) ?>]" value="<?= htmlspecialchars((string)($advertisementCaptionByBase[$advertisementName] ?? ''), ENT_QUOTES, 'UTF-8') ?>" maxlength="180" placeholder="Legende de cette image (facultatif)">
                                            </div>
                                            <div class="portal-banner-move">
                                                <button type="button" class="btn btn-sm" data-banner-move="up" title="Monter" aria-label="Monter cette image"><i class="fa fa-chevron-up"></i></button>
                                                <button type="button" class="btn btn-sm" data-banner-move="down" title="Descendre" aria-label="Descendre cette image"><i class="fa fa-chevron-down"></i></button>
                                                <button type="button" class="btn btn-sm btn-danger" data-delete-advertisement="<?= htmlspecialchars($advertisementName, ENT_QUOTES, 'UTF-8') ?>" title="Supprimer cette image du serveur" aria-label="Supprimer cette image du serveur"><i class="fa fa-trash"></i></button>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                            <div class="small text-warning mt-2">Les images restent sur le serveur applicatif. Autorisez son adresse dans le Walled Garden MikroTik pour les afficher avant la connexion.</div>
                            <div class="mt-2">
                                <label class="form-label small text-white-50 mb-1" for="portalAdvertisementTargetUrl">Lien optionnel de la banniere</label>
                                <input type="url" class="form-control" name="portal_advertisement_target_url" id="portalAdvertisementTargetUrl" value="<?= htmlspecialchars($advertisementTargetUrl, ENT_QUOTES, 'UTF-8') ?>" placeholder="https://exemple.com">
                            </div>
                        </div>
                        </div>

                        <button type="submit" class="btn btn-save w-100" <?= count($devices) < 1 ? 'disabled' : '' ?>>
                            <i class="fa fa-cloud-arrow-up me-1"></i> Preparer et deployer le template
                        </button>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-xl-5">
            <div class="card shadow-sm">
                <div class="card-header standard-card-header d-flex align-items-center justify-content-between gap-2">
                    <span class="portal-card-title"><i class="fa fa-sliders-h me-2"></i> Configuration active</span>
                </div>
                <div class="card-body">
                    <div class="portal-summary-grid">
                        <div class="portal-summary-item">
                            <span>Device cible</span>
                            <strong><?= $deviceLabel !== '' ? htmlspecialchars($deviceLabel, ENT_QUOTES, 'UTF-8') : 'Non defini' ?></strong>
                        </div>
                        <div class="portal-summary-item">
                            <span>Profil Hotspot actif</span>
                            <strong><?= $activeHotspotProfile !== '' ? htmlspecialchars($activeHotspotProfile, ENT_QUOTES, 'UTF-8') : '—' ?></strong>
                        </div>
                        <div class="portal-summary-item">
                            <span>Banniere</span>
                            <strong><?= count($advertisementRels) ?> image<?= count($advertisementRels) > 1 ? 's' : '' ?></strong>
                        </div>
                        <div class="portal-summary-item">
                            <span>URL base API</span>
                            <code><?= htmlspecialchars($apiBase, ENT_QUOTES, 'UTF-8') ?></code>
                        </div>
                        <div class="portal-summary-item">
                            <span>Template actif</span>
                            <code><?= $templateRel !== '' ? htmlspecialchars($templateRel, ENT_QUOTES, 'UTF-8') : '—' ?></code>
                        </div>
                        <div class="portal-summary-item">
                            <span>Logo</span>
                            <code><?= $logoRel !== '' ? htmlspecialchars($logoRel, ENT_QUOTES, 'UTF-8') : '—' ?></code>
                        </div>
                    </div>

                    <?php if (is_array($lastCompat)): ?>
                        <?php $level = (string)($lastCompat['level'] ?? 'ok'); ?>
                        <div class="portal-status <?= $level === 'warn' ? 'is-warning' : 'is-ok' ?>">
                            <div>
                                <span class="portal-status-label"><i class="fa <?= $level === 'warn' ? 'fa-triangle-exclamation' : 'fa-circle-check' ?> portal-status-icon" aria-hidden="true"></i>Derniere analyse du template actif</span>
                                <strong><?= htmlspecialchars((string)($lastCompat['summary'] ?? ''), ENT_QUOTES, 'UTF-8') ?></strong>
                                <p><?= htmlspecialchars((string)($lastCompat['detail'] ?? ''), ENT_QUOTES, 'UTF-8') ?></p>
                            </div>
                            <?php if (isset($lastCompat['zip_files'])): ?>
                                <span class="portal-status-count"><?= (int)$lastCompat['zip_files'] ?> fichiers dans le ZIP</span>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>

                    <p class="small text-white-50 mt-3 mb-0">
                        Connecte en tant que <strong class="text-white"><?= $username !== '' ? $username : '—' ?></strong>.
                    </p>
                </div>
            </div>
        </div>
    </div>

    <div class="card shadow-sm mt-3">
        <div class="card-header standard-card-header d-flex align-items-center justify-content-between gap-2">
            <span class="portal-card-title"><i class="fa fa-file-archive me-2"></i> Archives enregistrees sur le serveur</span>
            <span class="portal-count-pill"><?= count($templates) ?> archive<?= count($templates) > 1 ? 's' : '' ?></span>
        </div>
        <div class="card-body p-0">
            <?php if (count($templates) < 1): ?>
                <p class="small text-white-50 p-3 mb-0">Aucune archive <code>.zip</code> dans <code>uploads/portal_captive/templates/</code>.</p>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-dark table-hover table-striped mb-0 table-standard portal-archive-table">
                        <thead>
                            <tr>
                                <th>Archive</th>
                                <th>Chemin relatif</th>
                                <th>Taille</th>
                                <th>Modifie</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($templates as $row): ?>
                                <tr<?= !empty($row['is_active']) ? ' class="portal-archive-row-active"' : '' ?>>
                                    <td>
                                        <i class="fa fa-file-zipper portal-archive-icon" aria-hidden="true"></i>
                                        <span class="portal-template-name"><?= htmlspecialchars($row['name'], ENT_QUOTES, 'UTF-8') ?></span>
                                        <?php if (!empty($row['is_active'])): ?>
                                            <span class="portal-active-badge">Actif</span>
                                        <?php endif; ?>
                                        <?php if (!empty($row['is_default'])): ?>
                                            <span class="portal-active-badge">Defaut</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <code class="user-select-all small"><?= htmlspecialchars($row['path'], ENT_QUOTES, 'UTF-8') ?></code>
                                        <button type="button" class="btn btn-sm portal-copy-btn" data-copy-text="<?= htmlspecialchars($row['path'], ENT_QUOTES, 'UTF-8') ?>" title="Copier le chemin" aria-label="Copier le chemin">
                                            <i class="fa fa-copy" aria-hidden="true"></i>
                                        </button>
                                    </td>
                                    <td class="text-nowrap small text-white-50"><?= portalProfilesHumanBytes((int)($row['size'] ?? 0)) ?></td>
                                    <td class="text-nowrap small text-white-50">
                                        <?= ((int)($row['mtime'] ?? 0)) > 0 ? htmlspecialchars(date('Y-m-d H:i', (int)$row['mtime']), ENT_QUOTES, 'UTF-8') : '—' ?>
                                    </td>
                                    <td class="text-end">
                                        <div class="portal-actions">
                                            <a class="btn btn-sm btn-test" href="<?= htmlspecialchars(portalProfilesTemplateDownloadUrl((string)$row['name']), ENT_QUOTES, 'UTF-8') ?>" download>
                                                <i class="fa fa-download"></i>
                                            </a>
                                            <?php if (empty($row['is_default'])): ?>
                                                <form method="POST" onsubmit="return confirm('Supprimer cette archive du serveur ?');">
                                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string)$data['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
                                                    <input type="hidden" name="portal_action" value="delete_template">
                                                    <input type="hidden" name="template_name" value="<?= htmlspecialchars((string)$row['name'], ENT_QUOTES, 'UTF-8') ?>">
                                                    <button type="submit" class="btn btn-sm btn-danger">
                                                        <i class="fa fa-trash"></i>
                                                    </button>
                                                </form>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
    <script>
    const portalDefaultTemplateByType = {
        mikrotik: 'default_mikrotik.zip',
        opnsense: 'default_opnsense.zip'
    };
    const portalDeviceSelect = document.getElementById('portalDeviceSelect');
    const portalDefaultTemplateHint = document.getElementById('portalDefaultTemplateHint');
    const portalTemplateZipName = document.getElementById('portalTemplateZipName');
    const portalHotspotProfile = document.getElementById('portalHotspotProfile');
    const portalHotspotProfileHint = document.getElementById('portalHotspotProfileHint');

    async function refreshPortalHotspotProfiles() {
        if (!portalDeviceSelect || !portalHotspotProfile || !portalHotspotProfileHint) return;
        const selected = portalDeviceSelect.options[portalDeviceSelect.selectedIndex];
        const type = selected ? String(selected.dataset.deviceType || '').toLowerCase() : '';
        const deviceId = selected ? String(selected.value || '') : '';
        portalHotspotProfile.innerHTML = '<option value="">— Chargement —</option>';
        portalHotspotProfile.disabled = true;
        portalHotspotProfile.required = type === 'mikrotik';
        if (type !== 'mikrotik' || deviceId === '') {
            portalHotspotProfile.innerHTML = '<option value="">— Aucun profil MikroTik requis —</option>';
            portalHotspotProfileHint.textContent = 'Le deploiement direct est disponible uniquement pour MikroTik.';
            return;
        }
        try {
            const url = new URL(window.location.href);
            url.search = '';
            url.searchParams.set('portal_action', 'active_hotspot_profiles');
            url.searchParams.set('device_id', deviceId);
            const response = await fetch(url.toString(), { headers: { Accept: 'application/json' } });
            const payload = await response.json();
            if (!response.ok || payload.success !== true) throw new Error(payload.message || 'Lecture impossible');
            const profiles = Array.isArray(payload.profiles) ? payload.profiles : [];
            portalHotspotProfile.innerHTML = '<option value="">— Choisir un profil actif —</option>';
            profiles.forEach((profile) => {
                const option = document.createElement('option');
                option.value = String(profile.name || '');
                option.textContent = option.value + (Array.isArray(profile.servers) && profile.servers.length ? ' (' + profile.servers.join(', ') + ')' : '');
                if (option.value === portalHotspotProfile.dataset.currentProfile) option.selected = true;
                portalHotspotProfile.appendChild(option);
            });
            if (profiles.length === 1) portalHotspotProfile.value = String(profiles[0].name || '');
            portalHotspotProfile.disabled = profiles.length < 1;
            portalHotspotProfileHint.textContent = profiles.length
                ? profiles.length + ' profil(s) utilise(s) par un serveur Hotspot actif.'
                : 'Aucun serveur Hotspot actif trouve.';
        } catch (error) {
            portalHotspotProfile.innerHTML = '<option value="">— Profils indisponibles —</option>';
            portalHotspotProfileHint.textContent = error instanceof Error ? error.message : 'Profils indisponibles.';
        }
    }

    function refreshPortalDefaultTemplateHint() {
        if (!portalDeviceSelect || !portalDefaultTemplateHint || !portalTemplateZipName) {
            return;
        }
        const selected = portalDeviceSelect.options[portalDeviceSelect.selectedIndex];
        const type = selected ? String(selected.dataset.deviceType || '').toLowerCase() : '';
        const template = portalDefaultTemplateByType[type] || '';
        if (template === '') {
            portalDefaultTemplateHint.textContent = 'Aucun modele par defaut pour ce type : choisissez un ZIP manuel.';
            if (!document.getElementById('portalTemplateZip')?.files?.length) {
                portalTemplateZipName.textContent = 'Aucun fichier choisi';
            }
            return;
        }
        portalDefaultTemplateHint.textContent = 'Modele par defaut charge automatiquement : ' + template;
        if (!document.getElementById('portalTemplateZip')?.files?.length) {
            portalTemplateZipName.textContent = template;
        }
    }

    portalDeviceSelect?.addEventListener('change', () => {
        refreshPortalDefaultTemplateHint();
        refreshPortalHotspotProfiles();
    });
    refreshPortalDefaultTemplateHint();
    refreshPortalHotspotProfiles();

    document.querySelectorAll('.portal-upload-input[data-file-label]').forEach((input) => {
        input.addEventListener('change', () => {
            const label = document.getElementById(input.dataset.fileLabel || '');
            if (!label) {
                return;
            }
            label.textContent = input.files && input.files.length > 0
                ? (input.multiple ? input.files.length + ' image(s) selectionnee(s)' : input.files[0].name)
                : 'Aucun fichier choisi';
            if (input.id === 'portalTemplateZip' && (!input.files || input.files.length < 1)) {
                refreshPortalDefaultTemplateHint();
            }
        });
    });

    // Apercu local des nouvelles images avant envoi (aucun upload, juste une lecture du fichier choisi).
    const portalAdvertisementFile = document.getElementById('portalAdvertisementFile');
    const portalAdvertisementPreview = document.getElementById('portalAdvertisementPreview');
    const portalAdvertisementAddBtn = document.getElementById('portalAdvertisementAddBtn');
    portalAdvertisementFile?.addEventListener('change', () => {
        if (portalAdvertisementAddBtn) {
            portalAdvertisementAddBtn.disabled = !(portalAdvertisementFile.files && portalAdvertisementFile.files.length > 0);
        }
        if (!portalAdvertisementPreview) return;
        portalAdvertisementPreview.innerHTML = '';
        Array.from(portalAdvertisementFile.files || []).forEach((file) => {
            if (!file.type.startsWith('image/')) return;
            const reader = new FileReader();
            reader.onload = () => {
                const img = document.createElement('img');
                img.src = String(reader.result || '');
                img.alt = file.name;
                img.title = file.name;
                portalAdvertisementPreview.appendChild(img);
            };
            reader.readAsDataURL(file);
        });
    });

    // Ajout de nouvelles bannieres : requete separee, independante du deploiement du template.
    portalAdvertisementAddBtn?.addEventListener('click', async () => {
        const files = portalAdvertisementFile?.files;
        if (!files || files.length < 1) return;
        portalAdvertisementAddBtn.disabled = true;
        try {
            const formData = new FormData();
            formData.set('portal_action', 'add_advertisement');
            formData.set('csrf_token', <?= json_encode((string)$data['csrf_token']) ?>);
            Array.from(files).forEach((file) => formData.append('portal_advertisements[]', file));
            const captions = document.getElementById('portalAdvertisementUploadCaptions');
            formData.set('portal_advertisement_upload_captions', captions ? captions.value : '');
            await fetch(window.location.pathname, { method: 'POST', body: formData });
            window.location.reload();
        } catch (_) {
            portalAdvertisementAddBtn.disabled = false;
            window.AppToast?.flash('Ajout impossible.', 'danger');
        }
    });

    // Reordonnancement des bannieres existantes : l'ordre des lignes devient l'ordre d'affichage a l'enregistrement.
    document.getElementById('portalBannerList')?.addEventListener('click', (event) => {
        const button = event.target.closest('[data-banner-move]');
        if (!button) return;
        const item = button.closest('.portal-banner-item');
        if (!item) return;
        if (button.dataset.bannerMove === 'up' && item.previousElementSibling) {
            item.parentNode.insertBefore(item, item.previousElementSibling);
        } else if (button.dataset.bannerMove === 'down' && item.nextElementSibling) {
            item.parentNode.insertBefore(item.nextElementSibling, item);
        }
    });

    // Suppression d'une image de banniere (fichier serveur). Hors du formulaire principal pour ne pas
    // soumettre les autres champs (ZIP, logo...) en meme temps : requete separee puis rechargement.
    document.querySelectorAll('[data-delete-advertisement]').forEach((button) => {
        button.addEventListener('click', async () => {
            const name = button.dataset.deleteAdvertisement || '';
            if (!name || !window.confirm('Supprimer definitivement cette image du serveur ?\n\n' + name)) {
                return;
            }
            button.disabled = true;
            try {
                const body = new URLSearchParams({
                    portal_action: 'delete_advertisement',
                    advertisement_name: name,
                    csrf_token: <?= json_encode((string)$data['csrf_token']) ?>,
                });
                await fetch(window.location.pathname, { method: 'POST', body });
                window.location.reload();
            } catch (_) {
                button.disabled = false;
                window.AppToast?.flash('Suppression impossible.', 'danger');
            }
        });
    });

    // Copie du chemin d'une archive dans le presse-papier.
    document.querySelectorAll('.portal-copy-btn[data-copy-text]').forEach((button) => {
        button.addEventListener('click', () => {
            const text = button.dataset.copyText || '';
            const copy = (navigator.clipboard && window.isSecureContext)
                ? navigator.clipboard.writeText(text)
                : (() => {
                    const ta = document.createElement('textarea');
                    ta.value = text;
                    ta.style.cssText = 'position:absolute;left:-9999px;';
                    document.body.appendChild(ta);
                    ta.select();
                    let ok = false;
                    try { ok = document.execCommand('copy'); } catch (_) {}
                    document.body.removeChild(ta);
                    return ok ? Promise.resolve() : Promise.reject(new Error('copy_failed'));
                })();
            copy.then(() => window.AppToast?.flash('Chemin copie.', 'success'))
                .catch(() => window.AppToast?.flash('Copie impossible.', 'danger'));
        });
    });
    </script>
    <?php
    require_once __DIR__ . '/../../includes/layout_footer.php';
}
