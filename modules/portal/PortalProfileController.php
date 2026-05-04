<?php

/**
 * Page « Profils portail » : synthèse de la configuration portail captif
 * (déploiement détaillé dans administration.php, onglet Portail et maintenance).
 */

require_once __DIR__ . '/../../includes/message.php';
require_once __DIR__ . '/../../includes/portal_hotspot.php';
require_once __DIR__ . '/../../includes/device_manager.php';

/**
 * @return array{
 *   csrf_token:string,
 *   portal_config:array,
 *   captive_device_label:string,
 *   stored_templates:list<array{name:string,path:string,mtime:int}>
 * }
 */
function portalHandleProfilesRequest(): array
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    $config = loadPortalHotspotConfig();
    $store = loadDeviceStore();
    $deviceId = trim((string)($config['captive_device_id'] ?? ''));
    $deviceLabel = '';
    if ($deviceId !== '') {
        $dev = findDeviceById($store, $deviceId);
        if ($dev !== null) {
            $nameLabel = trim((string)($dev['name'] ?? ''));
            $typeLabel = (string)($dev['type'] ?? '');
            $hostLabel = trim((string)($dev['host'] ?? ''));
            $deviceLabel = $nameLabel !== '' ? $nameLabel . ' (' . $typeLabel . ')' : ($hostLabel !== '' ? $hostLabel . ' (' . $typeLabel . ')' : $typeLabel);
        } else {
            $deviceLabel = 'Device inconnu ou supprimé (ID : ' . $deviceId . ')';
        }
    }

    return [
        'csrf_token' => (string)$_SESSION['csrf_token'],
        'portal_config' => $config,
        'captive_device_label' => $deviceLabel,
        'stored_templates' => portalProfilesListStoredZipTemplates(),
    ];
}

/**
 * @return list<array{name:string,path:string,mtime:int}>
 */
function portalProfilesListStoredZipTemplates(): array
{
    try {
        portalEnsureCaptiveDirectories();
    } catch (Throwable $e) {
        return [];
    }

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
        ];
    }

    usort($out, static function (array $a, array $b): int {
        return ($b['mtime'] ?? 0) <=> ($a['mtime'] ?? 0);
    });

    return $out;
}

/**
 * @param array{
 *   csrf_token:string,
 *   portal_config:array,
 *   captive_device_label:string,
 *   stored_templates:list<array{name:string,path:string,mtime:int}>
 * } $data
 */
function renderPortalProfilesPage(array $data): void
{
    $config = $data['portal_config'];
    $apiBase = htmlspecialchars((string)($config['api_base_url'] ?? ''), ENT_QUOTES, 'UTF-8');
    $templateRel = trim((string)($config['template_relative_path'] ?? ''));
    $logoRel = trim((string)($config['logo_relative_path'] ?? ''));
    $lastCompat = $config['last_compat'] ?? null;
    $deviceLabel = (string)($data['captive_device_label'] ?? '');
    $templates = $data['stored_templates'] ?? [];

    $username = htmlspecialchars((string)($_SESSION['username'] ?? ''), ENT_QUOTES, 'UTF-8');

    $pageTitle = 'Profils portail captif';
    require_once __DIR__ . '/../../includes/layout_header.php';
    ?>

    <div class="card shadow-sm mb-3">
        <div class="card-body py-3">
            <div class="d-flex align-items-center text-white" style="font-size: calc(0.875rem + 2px);">
                <i class="fa fa-globe me-2"></i>
                <span class="small fw-semibold">Profils portail captif</span>
            </div>
        </div>
    </div>

    <div class="card shadow-sm mb-3 reports-tabs-card administration-tabs-card">
        <div class="card-header standard-card-header administration-tabs-header">
            <div class="reports-tabs-title">
                <i class="fa fa-info-circle me-2"></i>
                <span class="fw-semibold">Synthèse de configuration</span>
            </div>
        </div>
        <div class="card-body">
            <p class="text-white-50 small mb-4">
                Cette page résume la configuration du portail captif stockée sur le serveur.
                Pour déployer un modèle ZIP, choisir un device et mettre à jour l’API, utilisez
                <a href="administration.php" class="link-info">Administration</a>
                → onglet <strong>Portail et maintenance</strong>.
            </p>

            <div class="row g-3">
                <div class="col-lg-6">
                    <div class="card h-100 shadow-sm">
                        <div class="card-header standard-card-header">
                            <i class="fa fa-sliders-h me-2"></i> Paramètres actifs
                        </div>
                        <div class="card-body">
                            <dl class="row small mb-0 text-white-50">
                                <dt class="col-sm-4">Device cible</dt>
                                <dd class="col-sm-8 text-white"><?= $deviceLabel !== '' ? htmlspecialchars($deviceLabel, ENT_QUOTES, 'UTF-8') : '<span class="text-white-50">— non défini —</span>' ?></dd>

                                <dt class="col-sm-4">URL base API</dt>
                                <dd class="col-sm-8"><code class="user-select-all"><?= $apiBase !== '' ? $apiBase : '—' ?></code></dd>

                                <dt class="col-sm-4">Template enregistré</dt>
                                <dd class="col-sm-8">
                                    <?php if ($templateRel !== ''): ?>
                                        <code class="user-select-all"><?= htmlspecialchars($templateRel, ENT_QUOTES, 'UTF-8') ?></code>
                                    <?php else: ?>
                                        <span class="text-white-50">—</span>
                                    <?php endif; ?>
                                </dd>

                                <dt class="col-sm-4">Logo</dt>
                                <dd class="col-sm-8">
                                    <?php if ($logoRel !== ''): ?>
                                        <code class="user-select-all"><?= htmlspecialchars($logoRel, ENT_QUOTES, 'UTF-8') ?></code>
                                    <?php else: ?>
                                        <span class="text-white-50">—</span>
                                    <?php endif; ?>
                                </dd>
                            </dl>

                            <?php if (is_array($lastCompat)): ?>
                                <?php
                                $lc = (string)($lastCompat['level'] ?? 'ok');
                                $badgeClass = $lc === 'warn' ? 'text-warning' : 'text-success';
                                ?>
                                <div class="mt-4 pt-3 border-top border-white-10">
                                    <div class="small text-white-50 mb-1">Dernière analyse de compatibilité</div>
                                    <div class="small <?= htmlspecialchars($badgeClass, ENT_QUOTES, 'UTF-8') ?>">
                                        <strong><?= htmlspecialchars((string)($lastCompat['summary'] ?? ''), ENT_QUOTES, 'UTF-8') ?></strong>
                                        — <?= htmlspecialchars((string)($lastCompat['detail'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <div class="col-lg-6">
                    <div class="card h-100 shadow-sm">
                        <div class="card-header standard-card-header">
                            <i class="fa fa-file-archive me-2"></i> Archives sur le serveur
                        </div>
                        <div class="card-body p-0">
                            <?php if (count($templates) < 1): ?>
                                <p class="small text-white-50 p-3 mb-0">Aucune archive <code>.zip</code> dans <code>uploads/portal_captive/templates/</code>.</p>
                            <?php else: ?>
                                <div class="table-responsive">
                                    <table class="table table-dark table-hover table-striped mb-0 table-standard">
                                        <thead>
                                            <tr>
                                                <th>Fichier</th>
                                                <th>Chemin relatif</th>
                                                <th class="text-nowrap">Modifié</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($templates as $row): ?>
                                                <tr>
                                                    <td><?= htmlspecialchars($row['name'], ENT_QUOTES, 'UTF-8') ?></td>
                                                    <td><code class="user-select-all small"><?= htmlspecialchars($row['path'], ENT_QUOTES, 'UTF-8') ?></code></td>
                                                    <td class="text-nowrap small text-white-50">
                                                        <?= $row['mtime'] > 0 ? htmlspecialchars(date('Y-m-d H:i', $row['mtime']), ENT_QUOTES, 'UTF-8') : '—' ?>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <p class="small text-white-50 mt-4 mb-0 border-top border-white-10 pt-3">
                Connecté en tant que <strong class="text-white"><?= $username !== '' ? $username : '—' ?></strong> (administrateur).
            </p>
        </div>
    </div>
    <?php
    require_once __DIR__ . '/../../includes/layout_footer.php';
}
