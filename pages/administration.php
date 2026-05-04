<?php
session_start();

require_once '../includes/message.php';
require_once '../config/db.php';
require_once '../includes/local_admins.php';
require_once '../includes/auth.php';
require_once '../includes/operation_history.php';
require_once '../includes/portal_hotspot.php';
require_once '../includes/portal_template_injector.php';
require_once '../includes/device_manager.php';
require_once '../includes/nas_resolver.php';

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    set_message('Veuillez vous connecter pour accéder à cette page.', 'danger');
    header('Location: ../index.php');
    exit();
}

requireAdministratorAccess('Seul l administrateur peut gerer les utilisateurs locaux.');

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

ensureLocalAdminTable($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $csrf = trim((string)($_POST['csrf_token'] ?? ''));
        if ($csrf === '' || !hash_equals($_SESSION['csrf_token'] ?? '', $csrf)) {
            throw new RuntimeException('CSRF invalide');
        }

        $action = trim((string)($_POST['admin_action'] ?? ''));

        if ($action === 'portal_captive_deploy') {
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
                'summary' => 'Portail captif : enregistrement local du template (aucun déploiement device)',
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

            $msg = 'Portail captif : template enregistré sur ce serveur. '
                . $compat['summary'] . ' — ' . $compat['detail']
                . ' Aucun déploiement automatique vers le device n’est effectué. '
                . 'Importez manuellement l’archive sur le portail du routeur/pare-feu.';

            set_message($msg, $compat['level'] === 'warn' ? 'warning' : 'success');
        } elseif ($action === 'create') {
            $targetUsername = (string)($_POST['username'] ?? '');
            $targetRole = (string)($_POST['role'] ?? 'administrator');
            createLocalAdmin(
                $pdo,
                $targetUsername,
                (string)($_POST['password'] ?? ''),
                $targetRole
            );
            recordOperationHistory($pdo, [
                'operation_scope' => 'admin',
                'operation_type' => 'local_admin_create',
                'actor_username' => (string)($_SESSION['username'] ?? ''),
                'actor_role' => (string)($_SESSION['user_role'] ?? 'administrator'),
                'target_type' => 'local_user',
                'target_name' => $targetUsername,
                'summary' => 'Utilisateur local créé',
                'details_json' => ['role' => $targetRole],
            ]);
            set_message('Utilisateur local créé.', 'success');
        } elseif ($action === 'update') {
            $targetId = (int)($_POST['admin_id'] ?? 0);
            $targetUsername = (string)($_POST['username'] ?? '');
            $targetRole = (string)($_POST['role'] ?? 'administrator');
            $targetActive = ((string)($_POST['is_active'] ?? '1')) === '1';
            updateLocalAdmin(
                $pdo,
                $targetId,
                $targetUsername,
                trim((string)($_POST['password'] ?? '')) !== '' ? (string)$_POST['password'] : null,
                $targetActive,
                $targetRole
            );
            recordOperationHistory($pdo, [
                'operation_scope' => 'admin',
                'operation_type' => 'local_admin_update',
                'actor_username' => (string)($_SESSION['username'] ?? ''),
                'actor_role' => (string)($_SESSION['user_role'] ?? 'administrator'),
                'target_type' => 'local_user',
                'target_name' => $targetUsername,
                'target_ref' => (string)$targetId,
                'summary' => 'Utilisateur local mis à jour',
                'details_json' => ['role' => $targetRole, 'is_active' => $targetActive],
            ]);
            set_message('Utilisateur local mis à jour.', 'success');
        } elseif ($action === 'delete') {
            $targetId = (int)($_POST['admin_id'] ?? 0);
            $targetStmt = $pdo->prepare('SELECT username, role FROM local_admin_users WHERE id = ? LIMIT 1');
            $targetStmt->execute([$targetId]);
            $targetRow = $targetStmt->fetch(PDO::FETCH_ASSOC) ?: [];
            deleteLocalAdmin($pdo, $targetId, (string)($_SESSION['username'] ?? ''));
            recordOperationHistory($pdo, [
                'operation_scope' => 'admin',
                'operation_type' => 'local_admin_delete',
                'actor_username' => (string)($_SESSION['username'] ?? ''),
                'actor_role' => (string)($_SESSION['user_role'] ?? 'administrator'),
                'target_type' => 'local_user',
                'target_name' => (string)($targetRow['username'] ?? ''),
                'target_ref' => (string)$targetId,
                'summary' => 'Utilisateur local supprimé',
                'details_json' => ['role' => (string)($targetRow['role'] ?? '')],
            ]);
            set_message('Utilisateur local supprimé.', 'success');
        }
    } catch (Throwable $e) {
        set_message($e->getMessage(), 'danger');
    }

    header('Location: /pages/administration.php');
    exit();
}

$admins = listLocalAdmins($pdo);
$currentLocalAdminId = (int)($_SESSION['local_admin_id'] ?? 0);
$portalHotspotConfig = loadPortalHotspotConfig();
$deviceStore = loadDeviceStore();
$portalLogoFiles = portalListCaptiveLogoFiles();
$lastPortalCompat = is_array($portalHotspotConfig['last_compat'] ?? null) ? $portalHotspotConfig['last_compat'] : null;

$allDevices = is_array($deviceStore['devices'] ?? null) ? $deviceStore['devices'] : [];
$mikrotikDevices = [];
$radiusStandardDevices = [];
$radiusStandardSkippedDevices = [];
foreach ($allDevices as $deviceRow) {
    $deviceType = null;
    try {
        $deviceType = deriveDeviceType($deviceRow);
        if ($deviceType === 'mikrotik') {
            $mikrotikDevices[] = $deviceRow;
        }
        if (in_array($deviceType, ['opnsense', 'radius'], true)) {
            resolveNasContextFromInputs($pdo, null, (string)($deviceRow['id'] ?? ''));
            $radiusStandardDevices[] = $deviceRow;
        }
    } catch (Throwable $e) {
        if (in_array($deviceType, ['opnsense', 'radius'], true)) {
            $radiusStandardSkippedDevices[] = [
                'device' => $deviceRow,
                'reason' => $e->getMessage(),
            ];
        }
        continue;
    }
}

$selectedMikrotikDeviceId = '';
$selectedRadiusStandardDeviceId = '';
$activeStoreDeviceId = trim((string)($deviceStore['active_device_id'] ?? ''));
if ($activeStoreDeviceId !== '') {
    $activeStoreDevice = findDeviceById($deviceStore, $activeStoreDeviceId);
    if (is_array($activeStoreDevice)) {
        try {
            $activeStoreDeviceType = deriveDeviceType($activeStoreDevice);
            if ($activeStoreDeviceType === 'mikrotik') {
                $selectedMikrotikDeviceId = $activeStoreDeviceId;
            }
            if (in_array($activeStoreDeviceType, ['opnsense', 'radius'], true)) {
                $selectedRadiusStandardDeviceId = $activeStoreDeviceId;
            }
        } catch (Throwable $e) {
            // ignore
        }
    }
}

if ($selectedMikrotikDeviceId === '' && isset($mikrotikDevices[0]['id'])) {
    $selectedMikrotikDeviceId = (string)$mikrotikDevices[0]['id'];
}
if ($selectedRadiusStandardDeviceId === '' && isset($radiusStandardDevices[0]['id'])) {
    $selectedRadiusStandardDeviceId = (string)$radiusStandardDevices[0]['id'];
}

$formatAdministrationDeviceLabel = static function (array $device): string {
    $typeLabel = strtoupper((string)($device['type'] ?? ''));
    $nameLabel = trim((string)($device['name'] ?? ''));
    $hostLabel = trim((string)($device['host'] ?? ''));

    if ($nameLabel !== '') {
        return $nameLabel . ' (' . $typeLabel . ')';
    }

    return $hostLabel !== '' ? ($hostLabel . ' (' . $typeLabel . ')') : ('Device (' . $typeLabel . ')');
};

$selectedMikrotikDevice = $selectedMikrotikDeviceId !== ''
    ? findDeviceById($deviceStore, $selectedMikrotikDeviceId)
    : null;
$selectedMikrotikDeviceLabel = is_array($selectedMikrotikDevice)
    ? $formatAdministrationDeviceLabel($selectedMikrotikDevice)
    : '';
$selectedRadiusStandardDevice = $selectedRadiusStandardDeviceId !== ''
    ? findDeviceById($deviceStore, $selectedRadiusStandardDeviceId)
    : null;
$selectedRadiusStandardDeviceLabel = is_array($selectedRadiusStandardDevice)
    ? $formatAdministrationDeviceLabel($selectedRadiusStandardDevice)
    : '';
?>

<?php
$pageTitle = 'Administration';
$bodyClass = 'administration-page';
$bodyAttributes = [
    'data-administration-csrf-token' => (string)$_SESSION['csrf_token'],
];
$extraCss = [
    '../css/reports.css?v=20260403b',
    '../css/administration.css?v=20260420d',
];
require_once '../includes/layout_header.php';
?>


<ul class="nav nav-tabs reports-tabs justify-content-center mb-3 administration-tabs-header" id="administrationTabs" role="tablist">
    <li class="nav-item" role="presentation">
        <button class="nav-link active" id="admin-accounts-tab" data-bs-toggle="tab" data-bs-target="#administration-accounts" type="button" role="tab" aria-controls="administration-accounts" aria-selected="true">
            <i class="fa fa-users-cog me-1"></i> Comptes utilisateur
        </button>
    </li>
    <li class="nav-item" role="presentation">
        <button class="nav-link" id="admin-system-tab" data-bs-toggle="tab" data-bs-target="#administration-system" type="button" role="tab" aria-controls="administration-system" aria-selected="false">
            <i class="fa fa-sliders me-1"></i> Portail et maintenance
        </button>
    </li>
</ul>

<div class="tab-content">
            <div class="tab-pane fade show active" id="administration-accounts" role="tabpanel" aria-labelledby="admin-accounts-tab">
<div class="row">
    <div class="col-lg-7 mb-3">
        <div class="card shadow-sm administration-card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span class="administration-section-title"><i class="fa fa-users-cog me-2"></i> Utilisateurs locaux</span>
                <div class="input-group administration-search-group">
                    <span class="input-group-text"><i class="fa fa-search"></i></span>
                    <input type="text" class="form-control" id="adminSearchInput" placeholder="Rechercher un utilisateur...">
                </div>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-dark table-hover table-striped mb-0 administration-table table-standard" data-sort-table="1" data-default-sort-key="username" data-default-sort-direction="asc">
                        <thead>
                            <tr>
                                <th data-sort-key="id" data-sort-type="number">ID</th>
                                <th data-sort-key="username" data-sort-type="text">Utilisateur</th>
                                <th data-sort-key="role" data-sort-type="text">Rôle</th>
                                <th data-sort-key="session" data-sort-type="text">Session</th>
                                <th data-sort-key="created" data-sort-type="text">Créé le</th>
                                <th data-sort-key="updated" data-sort-type="text">Mis à jour</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody id="adminTableBody">
                            <?php foreach ($admins as $admin): ?>
                            <tr>
                                <td><?= (int)$admin['id'] ?></td>
                                <td><?= htmlspecialchars((string)$admin['username']) ?></td>
                                <td><?= htmlspecialchars(localAdminRoleLabel((string)($admin['role'] ?? 'administrator'))) ?></td>
                                <td class="administration-compact-cell">
                                    <?php if ((int)$admin['id'] === $currentLocalAdminId): ?>
                                        <span class="badge bg-info administration-status-badge"><i class="fa fa-user-check me-1"></i>Connecté</span>
                                    <?php else: ?>
                                        <span class="text-white-50"></span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-nowrap"><?= date('Y-m-d', strtotime($admin['created_at'])) ?></td>
                                <td class="text-nowrap"><?= date('Y-m-d', strtotime($admin['updated_at'])) ?></td>
                                <td class="action-cell">
                                    <button
                                        type="button"
                                        class="btn btn-test btn-sm"
                                        onclick="fillAdminForm('<?= (int)$admin['id'] ?>','<?= htmlspecialchars((string)$admin['username'], ENT_QUOTES) ?>','<?= (int)$admin['is_active'] ?>','<?= htmlspecialchars((string)($admin['role'] ?? 'administrator'), ENT_QUOTES) ?>')">
                                        <i class="fa fa-pen"></i>
                                    </button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <div class="col-lg-5 mb-3">
        <div class="card shadow-sm mb-3 administration-card">
            <div class="card-header">
                <i class="fa fa-user-plus me-2"></i> Gestion utilisateur local
            </div>
            <div class="card-body">
                <form method="POST" class="network-device-form administration-form" id="adminForm" autocomplete="off">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                    <input type="hidden" name="admin_action" id="adminActionInput" value="create">
                    <input type="hidden" name="admin_id" id="adminIdInput" value="0">

                    <div class="input-group">
                        <span class="input-group-text">Utilisateur</span>
                        <input type="text" class="form-control" name="username" id="adminUsernameInput" required>
                    </div>

                    <div class="input-group administration-password-group">
                        <span class="input-group-text">Mot de passe</span>
                        <input type="password" class="form-control" name="password" id="adminPasswordInput" placeholder="Laisser vide pour conserver">
                        <button type="button" class="btn btn-test" id="toggleAdminPasswordBtn" title="Afficher / masquer">
                            <i class="fa fa-eye"></i>
                        </button>
                    </div>
                    <div class="small text-white-50 mb-3 administration-form-note">
                        Le mot de passe enregistré n'est pas lisible en clair car il est stocké de façon sécurisée.
                        Le champ reste masqué par défaut et peut être affiché pendant la saisie.
                    </div>

                    <div class="input-group">
                        <span class="input-group-text">Statut</span>
                        <select class="form-select" name="is_active" id="adminStatusInput">
                            <option value="1">Actif</option>
                            <option value="0">Désactivé</option>
                        </select>
                    </div>

                    <div class="input-group">
                        <span class="input-group-text">Rôle</span>
                        <select class="form-select" name="role" id="adminRoleInput">
                            <option value="administrator">Administrateur</option>
                            <option value="reseller">Revendeur</option>
                        </select>
                    </div>

                    <div class="d-flex justify-content-between mt-3">
                        <button type="button" class="btn btn-save" onclick="resetAdminForm()">
                            <i class="fa fa-plus me-1"></i> Nouveau
                        </button>
                        <div class="d-flex gap-2">
                            <button type="submit" class="btn btn-save">
                                <i class="fa fa-save me-1"></i> Enregistrer
                            </button>
                            <button type="submit" class="btn btn-delete" id="deleteAdminBtn" onclick="return confirmDeleteAdmin();">
                                <i class="fa fa-trash me-1"></i> Supprimer
                            </button>
                        </div>
                    </div>
                </form>

                <div class="mt-3 pt-3 border-top administration-opnsense-tools">
                    <div class="small text-white-50 mb-2">
                        Après un import ou une mise à jour des utilisateurs OPNsense / Radius,
                        relancez la synchronisation pour mettre à jour les utilisateurs et les sessions.
                    </div>

                    <div class="d-flex justify-content-between align-items-center gap-2 flex-wrap">
                        <button type="button" class="btn btn-test" id="syncOpnsenseSessionsBtn">
                            <i class="fa fa-rotate-right me-1"></i> Relancer la synchro
                        </button>

                        <button type="button" class="btn btn-save" id="installOpnsenseCronBtn">
                            <i class="fa fa-clock me-1"></i> Installer le cron
                        </button>
                    </div>

                    <div class="administration-opnsense-status mt-2" id="opnsenseMaintenanceStatus"></div>
                </div>
            </div>
        </div>
    </div>
</div>
            </div>

            <div class="tab-pane fade" id="administration-system" role="tabpanel" aria-labelledby="admin-system-tab">
                <div class="row g-3">
                    <div class="col-12 col-lg-4 mb-3 mb-lg-0">
                        <div class="card shadow-sm mb-3 administration-card h-100">
                            <div class="card-header">
                                <span class="administration-section-title"><i class="fa fa-globe me-2"></i> Portail captif</span>
                            </div>
                            <div class="card-body">
                                <form method="POST" class="network-device-form administration-form administration-panel-form" id="portalCaptiveForm" enctype="multipart/form-data" autocomplete="off">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                                    <input type="hidden" name="admin_action" value="portal_captive_deploy">

                                    <div class="mb-3">
                                        <label class="form-label small text-white-50 mb-1" for="portalDeviceSelect">Device</label>
                                        <select class="form-select" name="portal_device_id" id="portalDeviceSelect" required <?= count($deviceStore['devices'] ?? []) < 1 ? 'disabled' : '' ?>>
                                            <option value="">— Choisir un device —</option>
                                            <?php foreach ($deviceStore['devices'] as $devRow): ?>
                                                <?php
                                                $devId = (string)($devRow['id'] ?? '');
                                                $typeLabel = (string)($devRow['type'] ?? '');
                                                $nameLabel = trim((string)($devRow['name'] ?? ''));
                                                if ($nameLabel !== '') {
                                                    $devLabel = $nameLabel . ' (' . $typeLabel . ')';
                                                } else {
                                                    $devLabel = trim((string)($devRow['host'] ?? '')) . ' (' . $typeLabel . ')';
                                                }
                                                $sel = ((string)($portalHotspotConfig['captive_device_id'] ?? '') === $devId) ? ' selected' : '';
                                                ?>
                                                <option value="<?= htmlspecialchars($devId) ?>"<?= $sel ?>><?= htmlspecialchars($devLabel) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                        <?php if (count($deviceStore['devices'] ?? []) < 1): ?>
                                            <div class="small text-warning mt-1">Aucun device configure. Ajoutez un NAS dans la section equipements.</div>
                                        <?php endif; ?>
                                    </div>

                                    <div class="mb-3">
                                        <label class="form-label small text-white-50 mb-1" for="portalTemplateZip">Template (ZIP)</label>
                                        <input
                                            type="file"
                                            class="form-control"
                                            name="portal_template_zip"
                                            id="portalTemplateZip"
                                            accept=".zip,application/zip"
                                            required
                                        >
                                        <div class="small text-white-50 mt-1">Archive obligatoire ; max <?= (int)(portalCaptiveZipUploadMaxBytes() / 1024 / 1024) ?> Mo.</div>
                                    </div>

                                    <div class="mb-3 administration-info-box" id="portalCompatBox">
                                        <div class="small text-white-50 mb-1 administration-info-box-title">Compatibilite (apres enregistrement)</div>
                                        <?php if ($lastPortalCompat !== null): ?>
                                            <?php
                                            $lc = (string)($lastPortalCompat['level'] ?? 'ok');
                                            $badgeClass = $lc === 'warn' ? 'text-warning' : 'text-success';
                                            ?>
                                            <div class="small <?= htmlspecialchars($badgeClass) ?>">
                                                <strong><?= htmlspecialchars((string)($lastPortalCompat['summary'] ?? '')) ?></strong>
                                                — <?= htmlspecialchars((string)($lastPortalCompat['detail'] ?? '')) ?>
                                                <?php if (isset($lastPortalCompat['zip_files'])): ?>
                                                    <span class="text-white-50"> (<?= (int)$lastPortalCompat['zip_files'] ?> fichiers dans le ZIP)</span>
                                                <?php endif; ?>
                                            </div>
                                        <?php else: ?>
                                            <div class="small text-white-50" id="portalCompatPlaceholder">Selectionnez un device et un ZIP, puis enregistrez pour verifier la compatibilite.</div>
                                        <?php endif; ?>
                                    </div>

                                    <div class="input-group mb-3">
                                        <span class="input-group-text">Adresse serveur API</span>
                                        <input
                                            type="text"
                                            class="form-control"
                                            name="portal_api_base_url"
                                            value="<?= htmlspecialchars((string)($portalHotspotConfig['api_base_url'] ?? 'http://10.10.10.2')) ?>"
                                            placeholder="https://votre-serveur"
                                            required
                                        >
                                    </div>

                                    <div class="mb-3">
                                        <label class="form-label small text-white-50 mb-1" for="portalLogoFile">Logo (optionnel)</label>
                                        <input type="file" class="form-control" name="portal_logo" id="portalLogoFile" accept="image/png,image/jpeg,image/gif,image/webp">
                                        <?php if (count($portalLogoFiles) > 0): ?>
                                            <div class="mt-2">
                                                <label class="form-label small text-white-50 mb-1" for="portalLogoExisting">Ou logo deja depose</label>
                                                <select class="form-select" name="portal_logo_existing" id="portalLogoExisting">
                                                    <option value="">— Aucun —</option>
                                                    <?php
                                                    $currentLogoBase = '';
                                                    $lr = (string)($portalHotspotConfig['logo_relative_path'] ?? '');
                                                    if ($lr !== '' && preg_match('#/([^/]+)$#', $lr, $m)) {
                                                        $currentLogoBase = $m[1];
                                                    }
                                                    ?>
                                                    <?php foreach ($portalLogoFiles as $lf): ?>
                                                        <option value="<?= htmlspecialchars($lf) ?>"<?= ($currentLogoBase === $lf) ? ' selected' : '' ?>><?= htmlspecialchars($lf) ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                        <?php endif; ?>
                                    </div>

                                    <?php if (!empty($portalHotspotConfig['template_relative_path'])): ?>
                                        <div class="small text-white-50 mb-3">
                                            Dernier template stocke : <code class="user-select-all"><?= htmlspecialchars((string)$portalHotspotConfig['template_relative_path']) ?></code>
                                        </div>
                                    <?php endif; ?>

                                    <div class="d-grid">
                                        <button type="submit" class="btn btn-save" <?= count($deviceStore['devices'] ?? []) < 1 ? 'disabled' : '' ?>>
                                            <i class="fa fa-cloud-arrow-up me-1"></i> Mettre en place le portail sur le device choisi
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>

                    <div class="col-12 col-lg-4 mb-3 mb-lg-0">
                        <div class="card shadow-sm mb-3 administration-card h-100">
                            <div class="card-header d-flex align-items-center justify-content-between">
                                <span class="administration-section-title">
                                    <i class="fa fa-network-wired me-2"></i> MikroTik — Import / export
                                </span>
                                <span
                                    class="text-info"
                                    title="Export : sauvegarde JSON standard du routeur selectionne. Import : analyse du JSON, validation du routeur, puis ecriture apres confirmation."
                                    aria-label="Export MikroTik en JSON standard ; import avec analyse, validation et confirmation."
                                >
                                    <i class="fa fa-circle-info"></i>
                                </span>
                            </div>
                            <div class="card-body administration-inline-form">
                                <div class="administration-card-intro mb-3">
                                    Export standard cree une sauvegarde JSON du routeur MikroTik selectionne.
                                    Import standard lit un JSON, lance la pre-analyse, valide le routeur cible, puis ecrit apres confirmation.
                                </div>

                                <div class="mb-3">
                                    <label class="form-label small text-white-50 mb-1" for="mikrotikIoDeviceSelect">Device MikroTik</label>
                                    <select
                                        class="form-select"
                                        id="mikrotikIoDeviceSelect"
                                        <?= count($mikrotikDevices) < 1 ? 'disabled' : '' ?>
                                    >
                                        <option value="">— Choisir un device MikroTik —</option>
                                        <?php foreach ($mikrotikDevices as $mikrotikDevice): ?>
                                            <?php
                                            $mikrotikDeviceId = (string)($mikrotikDevice['id'] ?? '');
                                            $selectedAttr = $mikrotikDeviceId === $selectedMikrotikDeviceId ? ' selected' : '';
                                            ?>
                                            <option value="<?= htmlspecialchars($mikrotikDeviceId) ?>"<?= $selectedAttr ?>>
                                                <?= htmlspecialchars($formatAdministrationDeviceLabel($mikrotikDevice)) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <?php if (count($mikrotikDevices) < 1): ?>
                                        <div class="small text-warning mt-1">Aucun device MikroTik configure.</div>
                                    <?php else: ?>
                                        <div class="small text-white-50 mt-1">
                                            Device initial : <?= htmlspecialchars($selectedMikrotikDeviceLabel) ?>.
                                        </div>
                                    <?php endif; ?>
                                </div>

                                <div class="mb-3">
                                    <label class="form-label small text-white-50 mb-1" for="mikrotikImportMode">Mode d import</label>
                                    <select class="form-select" id="mikrotikImportMode" <?= count($mikrotikDevices) < 1 ? 'disabled' : '' ?>>
                                        <option value="skip" selected>Ignorer les doublons</option>
                                        <option value="replace">Remplacer les existants</option>
                                    </select>
                                </div>

                                <input
                                    type="file"
                                    class="d-none"
                                    id="mikrotikStandardFileInput"
                                    accept=".json,application/json"
                                >

                                <div class="d-flex align-items-center justify-content-between administration-import-row mb-3 administration-file-picker">
                                    <div class="d-flex align-items-center gap-2 flex-wrap">
                                        <button
                                            type="button"
                                            class="btn btn-test"
                                            id="chooseMikrotikStandardFileBtn"
                                            <?= count($mikrotikDevices) < 1 ? 'disabled' : '' ?>
                                        >
                                            <i class="fa fa-folder-open me-1"></i> Choisir un JSON
                                        </button>
                                        <span class="administration-file-name" id="selectedMikrotikStandardFileName">Aucun fichier</span>
                                    </div>
                                </div>

                                <div class="administration-io-actions mb-2">
                                    <button
                                        type="button"
                                        class="btn btn-import"
                                        id="importMikrotikStandardBtn"
                                        disabled
                                        aria-disabled="true"
                                    >
                                        <i class="fa fa-file-import me-1"></i> Import standard
                                    </button>
                                    <button
                                        type="button"
                                        class="btn btn-test"
                                        id="exportMikrotikStandardBtn"
                                        disabled
                                        aria-disabled="true"
                                    >
                                        <i class="fa fa-file-export me-1"></i> Export standard
                                    </button>
                                </div>

                                <div class="administration-io-status mt-2" id="mikrotikIoStatus">
                                    <?php if (count($mikrotikDevices) < 1): ?>
                                        Aucun device MikroTik disponible pour initialiser le flux standard.
                                    <?php else: ?>
                                        Device pret : <?= htmlspecialchars($selectedMikrotikDeviceLabel) ?>.
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-12 col-lg-4">
                        <div class="card shadow-sm administration-card h-100">
                            <div class="card-header d-flex align-items-center justify-content-between">
                                <span class="administration-section-title">
                                    <i class="fa fa-shield-halved me-2"></i>
                                    <i class="fa fa-server me-2"></i> OPNsense / RADIUS — Import / export
                                </span>
                                <span
                                    class="text-info"
                                    title="OPNsense exporte la base metier plus la projection RADIUS. RADIUS pur exporte uniquement les tables FreeRADIUS."
                                    aria-label="Import export standard OPNsense et RADIUS selon la source canonique du NAS."
                                >
                                    <i class="fa fa-circle-info"></i>
                                </span>
                            </div>
                            <div class="card-body administration-inline-form">
                                <div class="administration-card-intro mb-3">
                                    OPNsense sauvegarde la base metier (<code>profiles</code>, <code>users</code>) avec sa projection RADIUS.
                                    RADIUS pur manipule uniquement les tables FreeRADIUS, sans base metier applicative.
                                </div>

                                <div class="mb-3">
                                    <label class="form-label small text-white-50 mb-1" for="radiusIoDeviceSelect">Device OPNsense / RADIUS</label>
                                    <select
                                        class="form-select"
                                        id="radiusIoDeviceSelect"
                                        <?= count($radiusStandardDevices) < 1 ? 'disabled' : '' ?>
                                    >
                                        <option value="">— Choisir un device OPNsense / RADIUS —</option>
                                        <?php foreach ($radiusStandardDevices as $radiusStandardDevice): ?>
                                            <?php
                                            $radiusStandardDeviceId = (string)($radiusStandardDevice['id'] ?? '');
                                            $selectedAttr = $radiusStandardDeviceId === $selectedRadiusStandardDeviceId ? ' selected' : '';
                                            ?>
                                            <option value="<?= htmlspecialchars($radiusStandardDeviceId) ?>"<?= $selectedAttr ?>>
                                                <?= htmlspecialchars($formatAdministrationDeviceLabel($radiusStandardDevice)) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <?php if (count($radiusStandardDevices) < 1): ?>
                                        <div class="small text-warning mt-1">Aucun device OPNsense / RADIUS importable.</div>
                                    <?php else: ?>
                                        <div class="small text-white-50 mt-1">
                                            Device initial : <?= htmlspecialchars($selectedRadiusStandardDeviceLabel) ?>.
                                        </div>
                                    <?php endif; ?>
                                    <?php if (count($radiusStandardSkippedDevices) > 0): ?>
                                        <div class="small text-warning mt-1">
                                            <?= count($radiusStandardSkippedDevices) ?> device(s) OPNsense / RADIUS ignoré(s) car non relié(s) à une ligne NAS cible.
                                        </div>
                                    <?php endif; ?>
                                </div>

                                <div class="mb-3">
                                    <label class="form-label small text-white-50 mb-1" for="radiusImportMode">Mode d import</label>
                                    <select class="form-select" id="radiusImportMode" <?= count($radiusStandardDevices) < 1 ? 'disabled' : '' ?>>
                                        <option value="skip" selected>Ignorer les doublons</option>
                                        <option value="replace">Remplacer les existants</option>
                                    </select>
                                </div>

                                <div class="form-check form-switch mb-3">
                                    <input
                                        class="form-check-input"
                                        type="checkbox"
                                        role="switch"
                                        id="radiusIncludeSensitive"
                                        <?= count($radiusStandardDevices) < 1 ? 'disabled' : '' ?>
                                    >
                                    <label class="form-check-label small text-white-50" for="radiusIncludeSensitive">
                                        Inclure les comptes sensibles (admin)
                                    </label>
                                </div>

                                <input
                                    type="file"
                                    class="d-none"
                                    id="radiusStandardFileInput"
                                    accept=".json,application/json"
                                >

                                <div class="d-flex align-items-center justify-content-between administration-import-row mb-3 administration-file-picker">
                                    <div class="d-flex align-items-center gap-2 flex-wrap">
                                        <button
                                            type="button"
                                            class="btn btn-test"
                                            id="chooseRadiusStandardFileBtn"
                                            <?= count($radiusStandardDevices) < 1 ? 'disabled' : '' ?>
                                        >
                                            <i class="fa fa-folder-open me-1"></i> Choisir un JSON
                                        </button>
                                        <span class="administration-file-name" id="selectedRadiusStandardFileName">Aucun fichier</span>
                                    </div>
                                </div>

                                <div class="administration-io-actions mb-2">
                                    <button
                                        type="button"
                                        class="btn btn-import"
                                        id="importRadiusStandardBtn"
                                        disabled
                                        aria-disabled="true"
                                    >
                                        <i class="fa fa-file-import me-1"></i> Import standard
                                    </button>
                                    <button
                                        type="button"
                                        class="btn btn-test"
                                        id="exportRadiusStandardBtn"
                                        disabled
                                        aria-disabled="true"
                                    >
                                        <i class="fa fa-file-export me-1"></i> Export standard
                                    </button>
                                </div>

                                <div class="administration-opnsense-status administration-io-status mt-2 mb-3" id="radiusIoStatus">
                                    <?php if (count($radiusStandardDevices) < 1): ?>
                                        Aucun device OPNsense / RADIUS importable pour initialiser le flux standard.
                                    <?php else: ?>
                                        Device pret : <?= htmlspecialchars($selectedRadiusStandardDeviceLabel) ?>.
                                    <?php endif; ?>
                                </div>

                                <hr class="border-secondary-subtle my-3">
                                <div class="fw-semibold text-white mb-2">Base MySQL (application)</div>
                                <div class="d-grid gap-2">
                                    <a class="btn btn-save" href="/api/admin/export_database.php">
                                        <i class="fa fa-download me-1"></i> Exporter la base MySQL (application)
                                    </a>
                                    <form action="/api/admin/import_database.php" method="POST" enctype="multipart/form-data" class="network-device-form administration-inline-form mb-0">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                                        <input type="file" class="d-none" name="sql_file" id="adminSqlFileInput" accept=".sql" required>
                                        <div class="d-flex align-items-center justify-content-between administration-import-row">
                                            <div class="d-flex align-items-center gap-2">
                                                <button type="button" class="btn btn-test" id="chooseSqlFileBtn">
                                                    <i class="fa fa-folder-open me-1"></i> Choisir un fichier
                                                </button>
                                                <span class="administration-file-name" id="selectedSqlFileName">Aucun fichier</span>
                                            </div>
                                            <button type="submit" class="btn btn-save">
                                                <i class="fa fa-upload me-1"></i> Importer
                                            </button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div> <!-- tab-content -->

</div>
</div>
</div>

<!-- Modal pour la pré-analyse et le processus d'import OPNsense / RADIUS -->
<div class="modal fade" id="radiusImportModal" tabindex="-1" aria-labelledby="radiusImportModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content administration-modal-content">
            <div class="modal-header administration-modal-header">
                <h5 class="modal-title" id="radiusImportModalLabel">
                    <i class="fa fa-file-import me-2"></i>Import OPNsense / RADIUS Standard
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Fermer"></button>
            </div>
            <div class="modal-body">
                <div id="radiusImportPreAnalysis" class="mb-4">
                    <h6 class="administration-modal-section-title mb-3">
                        <i class="fa fa-search me-2"></i>Pré-analyse du fichier
                    </h6>
                    <div class="administration-risk-box mb-3" id="radiusRiskBox">
                        <div class="administration-risk-empty text-white-50" id="radiusRiskEmpty">
                            Chargez un fichier JSON standard pour analyser la source, les blocages et les warnings avant l'import.
                        </div>
                        <div class="administration-risk-groups d-none" id="radiusRiskGroups">
                            <div class="administration-risk-group">
                                <div class="administration-risk-group-label text-info">Source</div>
                                <div class="small text-white-50" id="radiusRiskMeta"></div>
                            </div>
                            <div class="administration-risk-group mt-2">
                                <div class="administration-risk-group-label text-danger">Blocants</div>
                                <ul class="administration-risk-list administration-risk-list-danger" id="radiusRiskBlockers"></ul>
                            </div>
                            <div class="administration-risk-group">
                                <div class="administration-risk-group-label text-warning">Warnings</div>
                                <ul class="administration-risk-list administration-risk-list-warning" id="radiusRiskWarnings"></ul>
                            </div>
                            <div class="form-check mt-2">
                                <input class="form-check-input" type="checkbox" value="1" id="radiusWarningsConfirm" disabled>
                                <label class="form-check-label small" for="radiusWarningsConfirm">
                                    J'ai vérifié les warnings et j'autorise l'import
                                </label>
                            </div>
                        </div>
                    </div>
                </div>

                <div id="radiusImportProcess" class="mb-4 d-none">
                    <h6 class="administration-modal-section-title mb-3">
                        <i class="fa fa-cog fa-spin me-2"></i>Processus d'import
                    </h6>
                    <div class="progress mb-3">
                        <div class="progress-bar progress-bar-striped progress-bar-animated"
                             role="progressbar" style="width: 0%" id="radiusImportProgress"></div>
                    </div>
                    <div id="radiusImportStatus" class="alert alert-primary">
                        Initialisation...
                    </div>
                    <div class="small text-white-50 mb-2" id="radiusImportEta">
                        Estimation en attente...
                    </div>
                    <ul class="small administration-modal-summary-text mb-0" id="radiusImportSteps"></ul>
                </div>

                <div id="radiusImportSummary" class="d-none">
                    <h6 class="administration-modal-section-title mb-3">
                        <i class="fa fa-check-circle me-2"></i>Résumé de l'import
                    </h6>
                    <div id="radiusImportResult" class="alert alert-success">
                        Import terminé avec succès.
                    </div>
                    <div class="row g-3 administration-modal-summary-grid">
                        <div class="col-md-6">
                            <div class="administration-modal-summary-title">
                                <i class="fa fa-layer-group me-2"></i>Profils
                            </div>
                            <div id="radiusImportProfilesSummary" class="small administration-modal-summary-text">
                                Créés: 0<br>
                                Mis à jour: 0<br>
                                Protégés: 0<br>
                                Erreurs: 0
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="administration-modal-summary-title">
                                <i class="fa fa-user me-2"></i>Utilisateurs
                            </div>
                            <div id="radiusImportUsersSummary" class="small administration-modal-summary-text">
                                Créés: 0<br>
                                Mis à jour: 0<br>
                                Sensibles ignorés: 0<br>
                                Invalides ignorés: 0<br>
                                Erreurs: 0
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer administration-modal-footer">
                <button type="button" class="btn btn-test" data-bs-dismiss="modal">
                    <i class="fa fa-times me-1"></i>Fermer
                </button>
                <button type="button" class="btn btn-save d-none" id="radiusImportStartBtn">
                    <i class="fa fa-play me-1"></i>Démarrer l'import
                </button>
                <button type="button" class="btn btn-save d-none" id="radiusImportConfirmBtn">
                    <i class="fa fa-check me-1"></i>Confirmer
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Modal pour la pré-analyse et le processus d'import MikroTik -->
<div class="modal fade" id="mikrotikImportModal" tabindex="-1" aria-labelledby="mikrotikImportModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content administration-modal-content">
            <div class="modal-header administration-modal-header">
                <h5 class="modal-title" id="mikrotikImportModalLabel">
                    <i class="fa fa-file-import me-2"></i>Import MikroTik Standard
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Fermer"></button>
            </div>
            <div class="modal-body">
                <!-- Section pré-analyse -->
                <div id="mikrotikImportPreAnalysis" class="mb-4">
                    <h6 class="administration-modal-section-title mb-3">
                        <i class="fa fa-search me-2"></i>Pré-analyse du fichier
                    </h6>
                    <div class="administration-risk-box mb-3" id="mikrotikRiskBox">
                        <div class="administration-risk-empty text-white-50" id="mikrotikRiskEmpty">
                            Chargez un fichier JSON standard pour analyser les blocages et warnings avant l'import.
                        </div>
                        <div class="administration-risk-groups d-none" id="mikrotikRiskGroups">
                            <div class="administration-risk-group">
                                <div class="administration-risk-group-label text-danger">Blocants</div>
                                <ul class="administration-risk-list administration-risk-list-danger" id="mikrotikRiskBlockers"></ul>
                            </div>
                            <div class="administration-risk-group">
                                <div class="administration-risk-group-label text-warning">Warnings</div>
                                <ul class="administration-risk-list administration-risk-list-warning" id="mikrotikRiskWarnings"></ul>
                            </div>
                            <div class="form-check mt-2">
                                <input class="form-check-input" type="checkbox" value="1" id="mikrotikWarningsConfirm" disabled>
                                <label class="form-check-label small" for="mikrotikWarningsConfirm">
                                    J'ai vérifié les warnings et j'autorise l'import
                                </label>
                            </div>
                            <div class="form-check mt-2">
                                <input class="form-check-input" type="checkbox" value="1" id="mikrotikIncludeSensitive">
                                <label class="form-check-label small" for="mikrotikIncludeSensitive">
                                    Inclure les comptes sensibles (ex: admin)
                                </label>
                            </div>
                        </div>
                    </div>
                    <div class="administration-risk-box mb-3 d-none" id="mikrotikPoolMapBox">
                        <div class="administration-risk-group-label text-warning">Alignement address-pool</div>
                        <div class="small text-white-50 mb-2" id="mikrotikPoolMapIntro">
                            Certains profils utilisent des address-pool absents du routeur cible. Alignez chaque pool source vers un pool existant sur la cible.
                        </div>
                        <div id="mikrotikPoolMapRows"></div>
                        <div class="form-check mt-2">
                            <input class="form-check-input" type="checkbox" value="1" id="mikrotikPoolMapConfirm">
                            <label class="form-check-label small" for="mikrotikPoolMapConfirm">
                                J'ai vérifié l'alignement des address-pool avant import
                            </label>
                        </div>
                    </div>
                </div>

                <!-- Section processus d'import -->
                <div id="mikrotikImportProcess" class="mb-4 d-none">
                    <h6 class="administration-modal-section-title mb-3">
                        <i class="fa fa-cog fa-spin me-2"></i>Processus d'import
                    </h6>
                    <div class="progress mb-3">
                        <div class="progress-bar progress-bar-striped progress-bar-animated"
                             role="progressbar" style="width: 0%" id="mikrotikImportProgress"></div>
                    </div>
                    <div id="mikrotikImportStatus" class="alert alert-primary">
                        Initialisation...
                    </div>
                    <div class="small text-white-50 mb-2" id="mikrotikImportEta">
                        Estimation en attente...
                    </div>
                    <ul class="small administration-modal-summary-text mb-0" id="mikrotikImportSteps"></ul>
                </div>

                <!-- Section résumé final -->
                <div id="mikrotikImportSummary" class="d-none">
                    <h6 class="administration-modal-section-title mb-3">
                        <i class="fa fa-check-circle me-2"></i>Résumé de l'import
                    </h6>
                    <div id="mikrotikImportResult" class="alert alert-success">
                        Import terminé avec succès.
                    </div>
                    <div class="row g-3 administration-modal-summary-grid">
                        <div class="col-md-6">
                            <div class="administration-modal-summary-title">
                                <i class="fa fa-users me-2"></i>Profils
                            </div>
                            <div id="mikrotikImportProfilesSummary" class="small administration-modal-summary-text">
                                Créés: 0<br>
                                Mis à jour: 0<br>
                                Protégés: 0<br>
                                Erreurs: 0
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="administration-modal-summary-title">
                                <i class="fa fa-user me-2"></i>Utilisateurs
                            </div>
                            <div id="mikrotikImportUsersSummary" class="small administration-modal-summary-text">
                                Créés: 0<br>
                                Mis à jour: 0<br>
                                Sensibles ignorés: 0<br>
                                Invalides ignorés: 0<br>
                                Erreurs: 0
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer administration-modal-footer">
                <button type="button" class="btn btn-test" data-bs-dismiss="modal">
                    <i class="fa fa-times me-1"></i>Fermer
                </button>
                <button type="button" class="btn btn-save d-none" id="mikrotikImportStartBtn">
                    <i class="fa fa-play me-1"></i>Démarrer l'import
                </button>
                <button type="button" class="btn btn-save d-none" id="mikrotikImportConfirmBtn">
                    <i class="fa fa-check me-1"></i>Confirmer
                </button>
            </div>
        </div>
    </div>
</div>

<?php
$extraJs = [
    '../js/table_sort.js',
    '../js/administration.js?v=20260420c',
];
require_once '../includes/layout_footer.php';
?>
