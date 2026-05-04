<?php

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/message.php';
require_once __DIR__ . '/../../includes/portal_hotspot.php';
require_once __DIR__ . '/../../includes/portal_template_injector.php';
require_once __DIR__ . '/../../includes/device_manager.php';
require_once __DIR__ . '/../../includes/operation_history.php';

/**
 * @return array{
 *   csrf_token:string,
 *   portal_config:array,
 *   captive_device_label:string,
 *   stored_templates:list<array{name:string,path:string,mtime:int,size:int,is_active:bool}>,
 *   devices:list<array>,
 *   logos:list<string>
 * }
 */
function portalHandleProfilesRequest(): array
{
    global $pdo;

    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        try {
            portalProfilesAssertCsrf();
            $action = trim((string)($_POST['portal_action'] ?? ''));

            if ($action === 'deploy_template') {
                portalProfilesDeployTemplate($pdo);
            } elseif ($action === 'delete_template') {
                portalProfilesDeleteTemplate($pdo);
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
    ];
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
    if (!is_array($zipFile) || (int)($zipFile['error'] ?? 0) !== UPLOAD_ERR_OK) {
        $err = (int)($zipFile['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) {
            throw new RuntimeException('ZIP trop volumineux.');
        }
        throw new RuntimeException('Fichier ZIP du modele obligatoire.');
    }

    $zipMax = portalCaptiveZipUploadMaxBytes();
    if ((int)($zipFile['size'] ?? 0) > $zipMax) {
        throw new RuntimeException('ZIP : taille max ' . (int)($zipMax / 1024 / 1024) . ' Mo.');
    }

    $zipBaseName = (string)($zipFile['name'] ?? '');
    if (strtolower(pathinfo($zipBaseName, PATHINFO_EXTENSION)) !== 'zip') {
        throw new RuntimeException('Le modele doit etre une archive .zip.');
    }

    $tmpZip = (string)($zipFile['tmp_name'] ?? '');
    $analysis = portalAnalyzeCaptiveZip($tmpZip);
    $compat = portalCompatMessageForDevice($deviceType, $analysis);

    portalEnsureCaptiveDirectories();
    $safeId = preg_replace('/[^a-zA-Z0-9_-]+/', '_', $deviceId);
    $destName = 'captive_' . $safeId . '_' . date('Ymd_His') . '.zip';
    $destAbs = portalCaptiveFilesystemRoot() . '/templates/' . $destName;
    if (!move_uploaded_file($tmpZip, $destAbs)) {
        throw new RuntimeException('Impossible d enregistrer l archive sur le serveur.');
    }

    $injectionMeta = null;
    if ($deviceType === 'opnsense') {
        $statusApiUrl = rtrim($portalApiBaseUrl, '/') . '/api/portal/opnsense_user_status.php';
        $injectionMeta = portalInjectOpnsenseTemplateArchive($destAbs, [
            'api_base_url' => $portalApiBaseUrl,
            'status_api_url' => $statusApiUrl,
            'device_id' => $deviceId,
            'device_type' => $deviceType,
        ]);
    } elseif ($deviceType === 'mikrotik') {
        $routerHost = extractDeviceAddress((string)($device['host'] ?? ''));
        if ($routerHost === '') {
            $routerHost = trim((string)($device['ip'] ?? ''));
        }
        if ($routerHost === '') {
            throw new RuntimeException('Adresse routeur MikroTik introuvable pour injection.');
        }

        $injectionMeta = portalInjectMikrotikTemplateArchive($destAbs, [
            'api_base_url' => $portalApiBaseUrl,
            'router_host' => $routerHost,
            'device_type' => $deviceType,
        ]);
    }

    $templateRel = 'uploads/portal_captive/templates/' . $destName;
    $prev = loadPortalHotspotConfig();
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
        'deploy_note' => 'Deploiement automatique vers l equipement non disponible pour le moment. L archive est stockee sur ce serveur.',
        'injected_entry_file' => is_array($injectionMeta) ? (string)($injectionMeta['entry_file'] ?? '') : null,
        'status_api_url' => is_array($injectionMeta) ? (string)($injectionMeta['status_api_url'] ?? '') : null,
        'injected_device_id' => is_array($injectionMeta) ? (string)($injectionMeta['device_id'] ?? '') : null,
        'injected_router_host' => is_array($injectionMeta) ? (string)($injectionMeta['router_host'] ?? '') : null,
        'injection_verified' => is_array($injectionMeta),
    ];

    savePortalHotspotConfig([
        'api_base_url' => $portalApiBaseUrl,
        'captive_device_id' => $deviceId,
        'template_relative_path' => $templateRel,
        'logo_relative_path' => $logoRel,
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
        'summary' => 'Portail captif : enregistrement local du template (aucun deploiement device)',
        'details_json' => [
            'api_base_url' => $portalApiBaseUrl,
            'template_relative_path' => $templateRel,
            'logo_relative_path' => $logoRel,
            'compat_level' => $compat['level'],
            'injected_entry_file' => is_array($injectionMeta) ? (string)($injectionMeta['entry_file'] ?? '') : null,
            'status_api_url' => is_array($injectionMeta) ? (string)($injectionMeta['status_api_url'] ?? '') : null,
            'injected_device_id' => is_array($injectionMeta) ? (string)($injectionMeta['device_id'] ?? '') : null,
            'injected_router_host' => is_array($injectionMeta) ? (string)($injectionMeta['router_host'] ?? '') : null,
            'injection_verified' => is_array($injectionMeta),
        ],
    ]);

    set_message(
        'Portail captif : template modifie et enregistre sur ce serveur. '
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
    $currentLogoBase = $logoRel !== '' ? basename($logoRel) : '';
    $activeTemplateName = $templateRel !== '' ? basename($templateRel) : '';
    $username = htmlspecialchars((string)($_SESSION['username'] ?? ''), ENT_QUOTES, 'UTF-8');

    $pageTitle = 'Profils portail captif';
    $bodyClass = 'portal-profiles-page';
    $extraCss = [
        '../css/portal_profiles.css?v=20260503a',
    ];
    require_once __DIR__ . '/../../includes/layout_header.php';
    ?>

    <div class="row g-3 portal-layout">
        <div class="col-xl-5">
            <div class="card shadow-sm h-100">
                <div class="card-header standard-card-header">
                    <span class="portal-card-title"><i class="fa fa-wand-magic-sparkles me-2"></i> Mettre en place un template</span>
                </div>
                <div class="card-body">
                    <form method="POST" class="network-device-form portal-form" enctype="multipart/form-data" autocomplete="off">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string)$data['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
                        <input type="hidden" name="portal_action" value="deploy_template">

                        <div class="mb-3">
                            <label class="form-label small text-white-50 mb-1" for="portalDeviceSelect">Device cible</label>
                            <select class="form-select" name="portal_device_id" id="portalDeviceSelect" required <?= count($devices) < 1 ? 'disabled' : '' ?>>
                                <option value="">— Choisir un device —</option>
                                <?php foreach ($devices as $device): ?>
                                    <?php $deviceId = (string)($device['id'] ?? ''); ?>
                                    <option value="<?= htmlspecialchars($deviceId, ENT_QUOTES, 'UTF-8') ?>"<?= ((string)($config['captive_device_id'] ?? '') === $deviceId) ? ' selected' : '' ?>>
                                        <?= htmlspecialchars(portalProfilesDeviceLabel($device), ENT_QUOTES, 'UTF-8') ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <?php if (count($devices) < 1): ?>
                                <div class="small text-warning mt-1">Aucun device configure. Ajoutez un NAS dans Network Devices.</div>
                            <?php endif; ?>
                        </div>

                        <div class="mb-3">
                            <label class="form-label small text-white-50 mb-1" for="portalTemplateZip">Archive template (.zip)</label>
                            <div class="portal-upload-control">
                                <input type="file" class="portal-upload-input" name="portal_template_zip" id="portalTemplateZip" accept=".zip,application/zip" required data-file-label="portalTemplateZipName">
                                <label class="portal-upload-button" for="portalTemplateZip">
                                    <i class="fa fa-file-zipper me-1"></i> Choisir le ZIP
                                </label>
                                <span class="portal-upload-name" id="portalTemplateZipName">Aucun fichier choisi</span>
                            </div>
                            <div class="small text-white-50 mt-1">Archive obligatoire ; max <?= (int)(portalCaptiveZipUploadMaxBytes() / 1024 / 1024) ?> Mo.</div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label small text-white-50 mb-1" for="portalApiBaseUrl">Adresse serveur API</label>
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

                        <div class="mb-3">
                            <label class="form-label small text-white-50 mb-1" for="portalLogoFile">Logo optionnel</label>
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

                        <button type="submit" class="btn btn-save w-100" <?= count($devices) < 1 ? 'disabled' : '' ?>>
                            <i class="fa fa-cloud-arrow-up me-1"></i> Enregistrer et modifier le template
                        </button>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-xl-7">
            <div class="card shadow-sm h-100">
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
                                <span class="portal-status-label">Derniere analyse du template actif</span>
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
                                <tr>
                                    <td>
                                        <span class="portal-template-name"><?= htmlspecialchars($row['name'], ENT_QUOTES, 'UTF-8') ?></span>
                                        <?php if (!empty($row['is_active'])): ?>
                                            <span class="portal-active-badge">Actif</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><code class="user-select-all small"><?= htmlspecialchars($row['path'], ENT_QUOTES, 'UTF-8') ?></code></td>
                                    <td class="text-nowrap small text-white-50"><?= portalProfilesHumanBytes((int)($row['size'] ?? 0)) ?></td>
                                    <td class="text-nowrap small text-white-50">
                                        <?= ((int)($row['mtime'] ?? 0)) > 0 ? htmlspecialchars(date('Y-m-d H:i', (int)$row['mtime']), ENT_QUOTES, 'UTF-8') : '—' ?>
                                    </td>
                                    <td class="text-end">
                                        <div class="portal-actions">
                                            <a class="btn btn-sm btn-test" href="<?= htmlspecialchars(portalProfilesTemplateDownloadUrl((string)$row['name']), ENT_QUOTES, 'UTF-8') ?>" download>
                                                <i class="fa fa-download"></i>
                                            </a>
                                            <form method="POST" onsubmit="return confirm('Supprimer cette archive du serveur ?');">
                                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string)$data['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
                                                <input type="hidden" name="portal_action" value="delete_template">
                                                <input type="hidden" name="template_name" value="<?= htmlspecialchars((string)$row['name'], ENT_QUOTES, 'UTF-8') ?>">
                                                <button type="submit" class="btn btn-sm btn-danger">
                                                    <i class="fa fa-trash"></i>
                                                </button>
                                            </form>
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
    document.querySelectorAll('.portal-upload-input[data-file-label]').forEach((input) => {
        input.addEventListener('change', () => {
            const label = document.getElementById(input.dataset.fileLabel || '');
            if (!label) {
                return;
            }
            label.textContent = input.files && input.files.length > 0
                ? input.files[0].name
                : 'Aucun fichier choisi';
        });
    });
    </script>
    <?php
    require_once __DIR__ . '/../../includes/layout_footer.php';
}
