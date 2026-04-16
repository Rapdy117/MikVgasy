<?php
session_start();

require_once '../includes/message.php';
require_once '../config/db.php';
require_once '../includes/local_admins.php';
require_once '../includes/auth.php';
require_once '../includes/operation_history.php';
require_once '../includes/portal_hotspot.php';
require_once '../includes/device_manager.php';

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
            ];

            savePortalHotspotConfig([
                'api_base_url' => $portalApiBaseUrl,
                'captive_device_id' => $deviceId,
                'template_relative_path' => $templateRel,
                'logo_relative_path' => $logoRel,
                'last_compat' => $lastCompat,
            ]);

            applyPortalApiBaseUrlToHotspotSources($portalApiBaseUrl);

            recordOperationHistory($pdo, [
                'operation_scope' => 'admin',
                'operation_type' => 'portal_captive_deploy',
                'actor_username' => (string)($_SESSION['username'] ?? ''),
                'actor_role' => (string)($_SESSION['user_role'] ?? 'administrator'),
                'target_type' => 'portal_captive',
                'target_name' => (string)($device['name'] ?? $device['host'] ?? $deviceId),
                'target_ref' => $deviceId,
                'summary' => 'Portail captif : enregistrement template et configuration',
                'details_json' => [
                    'api_base_url' => $portalApiBaseUrl,
                    'template_relative_path' => $templateRel,
                    'logo_relative_path' => $logoRel,
                    'compat_level' => $compat['level'],
                ],
            ]);

            $msg = 'Portail captif : configuration enregistree. ' . $compat['summary'] . ' — ' . $compat['detail'] . ' ' . $lastCompat['deploy_note'];
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
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<title>Administration</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="../css/theme.css">
<link rel="stylesheet" href="../css/reports.css?v=20260403b">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
<style>
    .administration-search-group {
        max-width: 320px;
    }

    .administration-card .card-header {
        background-color: var(--theme-card-soft) !important;
        color: var(--theme-primary) !important;
        border-bottom: 1px solid rgba(255, 255, 255, 0.08) !important;
    }

    .administration-search-group .input-group-text {
        background: rgba(59, 130, 246, 0.12);
        border-color: rgba(148, 163, 184, 0.18);
        color: var(--theme-text);
    }

    .administration-search-group .form-control {
        background: rgba(12, 20, 34, 0.82);
        border-color: rgba(148, 163, 184, 0.18);
        color: var(--theme-text);
    }

    .administration-search-group .form-control::placeholder {
        color: rgba(226, 232, 240, 0.55);
    }

    .administration-search-group .form-control:focus {
        border-color: rgba(59, 130, 246, 0.45);
        box-shadow: 0 0 0 0.2rem rgba(59, 130, 246, 0.12);
    }

    .administration-table thead th {
        font-size: 14px;
        text-align: center;
        vertical-align: middle;
        color: var(--theme-text);
    }

    .administration-table tbody td {
        font-size: 14px;
        vertical-align: middle;
    }

    .administration-table tbody td:nth-child(2),
    .administration-table tbody td:nth-child(3),
    .administration-table tbody td:nth-child(4),
    .administration-table tbody td:nth-child(5) {
        text-align: left;
    }

    .administration-table tbody td:not(:nth-child(2)):not(:nth-child(3)):not(:nth-child(4)):not(:nth-child(5)) {
        text-align: center;
    }

    .administration-status-badge {
        min-width: 88px;
        font-size: 11px;
    }

    .administration-note {
        line-height: 1.55;
    }

    .administration-password-group .form-control {
        border-right: 0;
    }

    .administration-password-group .btn {
        min-width: 44px;
    }

    .administration-import-row {
        gap: 10px;
    }

    .administration-file-name {
        color: rgba(255, 255, 255, 0.65);
        font-size: 12px;
    }

    .administration-opnsense-status {
        min-height: 22px;
        font-size: 12px;
        color: rgba(226, 232, 240, 0.78);
        white-space: pre-line;
    }

    .administration-tabs-card .card-body {
        padding-top: 1rem;
    }

    @media (max-width: 991.98px) {
        .administration-tabs-header.reports-tabs-header {
            flex-wrap: wrap;
            gap: 10px;
        }

        .administration-tabs-header .reports-tabs {
            position: static;
            transform: none;
            width: 100%;
            justify-content: flex-start;
        }
    }
</style>
</head>
<body>
<div class="d-flex" id="wrapper">
<?php include '../includes/sidebar.php'; ?>

<div id="page-content-wrapper">
<div class="container-fluid py-3">
<?php display_message(); ?>

<div class="card shadow-sm mb-3 reports-tabs-card administration-tabs-card">
    <div class="card-header standard-card-header reports-tabs-header administration-tabs-header">
        <div class="reports-tabs-title">
            <i class="fa fa-user-shield me-2"></i>
            <span class="small fw-semibold">Administration</span>
        </div>
        <ul class="nav nav-tabs reports-tabs" id="administrationTabs" role="tablist">
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
        <div class="d-flex align-items-center reports-export-row reports-tab-actions" aria-hidden="true"></div>
    </div>
    <div class="card-body">
        <div class="tab-content">
            <div class="tab-pane fade show active" id="administration-accounts" role="tabpanel" aria-labelledby="admin-accounts-tab">
<div class="row">
    <div class="col-lg-7 mb-3">
        <div class="card shadow-sm administration-card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="fa fa-users-cog me-2"></i> Utilisateurs locaux</span>
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
                                <th data-sort-key="status" data-sort-type="text">Statut</th>
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
                                <td><?= (int)$admin['is_active'] === 1 ? '<span class="badge bg-success administration-status-badge">Actif</span>' : '<span class="badge bg-secondary administration-status-badge">Désactivé</span>' ?></td>
                                <td>
                                    <?= (int)$admin['id'] === $currentLocalAdminId ? '<span class="badge bg-info administration-status-badge">Connecté</span>' : '<span class="text-white-50">-</span>' ?>
                                </td>
                                <td><?= htmlspecialchars((string)$admin['created_at']) ?></td>
                                <td><?= htmlspecialchars((string)$admin['updated_at']) ?></td>
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
                <form method="POST" class="network-device-form" id="adminForm" autocomplete="off">
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
                    <div class="small text-white-50 mb-3">
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
                                <i class="fa fa-globe me-2"></i> Portail captif
                            </div>
                            <div class="card-body">
                                <form method="POST" class="network-device-form" id="portalCaptiveForm" enctype="multipart/form-data" autocomplete="off">
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

                                    <div class="mb-3" id="portalCompatBox">
                                        <div class="small text-white-50 mb-1">Compatibilite (apres enregistrement)</div>
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
                            <div class="card-header">
                                <i class="fa fa-network-wired me-2"></i> MikroTik — Import / export
                            </div>
                            <div class="card-body">
                                <div class="d-grid gap-2">
                                    <button type="button" class="btn btn-test" disabled aria-disabled="true">
                                        <i class="fa fa-file-import me-1"></i> Import standard
                                    </button>
                                    <button type="button" class="btn btn-test" disabled aria-disabled="true">
                                        <i class="fa fa-file-export me-1"></i> Export standard
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-12 col-lg-4">
                        <div class="card shadow-sm administration-card h-100">
                            <div class="card-header">
                                <i class="fa fa-shield-halved me-2"></i> OPNsense — Import / export
                            </div>
                            <div class="card-body">
                                <div class="d-grid gap-2 mb-3">
                                    <button type="button" class="btn btn-test" disabled aria-disabled="true">
                                        <i class="fa fa-file-import me-1"></i> Import standard
                                    </button>
                                    <button type="button" class="btn btn-test" disabled aria-disabled="true">
                                        <i class="fa fa-file-export me-1"></i> Export standard
                                    </button>
                                </div>
                                <div class="d-grid gap-2">
                                    <button type="button" class="btn btn-test" id="syncOpnsenseSessionsBtn">
                                        <i class="fa fa-rotate-right me-1"></i> Relancer la synchro OPNsense
                                    </button>
                                    <button type="button" class="btn btn-save" id="installOpnsenseCronBtn">
                                        <i class="fa fa-clock me-1"></i> Installer le cron OPNsense
                                    </button>
                                </div>
                                <div class="administration-opnsense-status mt-2" id="opnsenseMaintenanceStatus">
                                    Synchronisation manuelle et installation du cron OPNsense.
                                </div>
                                <hr class="border-secondary-subtle my-3">
                                <div class="fw-semibold text-white mb-2">Base MySQL (application)</div>
                                <div class="d-grid gap-2">
                                    <a class="btn btn-save" href="/api/admin/export_database.php">
                                        <i class="fa fa-download me-1"></i> Exporter la base MySQL (application)
                                    </a>
                                    <form action="/api/admin/import_database.php" method="POST" enctype="multipart/form-data" class="network-device-form mb-0">
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
        </div>
    </div>
</div>

</div>
</div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="../js/sidebar.js?v=20260402a"></script>
<script src="../js/table_sort.js"></script>
<script>
const administrationCsrfToken = '<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES) ?>';

function resetAdminForm() {
    document.getElementById('adminActionInput').value = 'create';
    document.getElementById('adminIdInput').value = '0';
    document.getElementById('adminUsernameInput').value = '';
    document.getElementById('adminPasswordInput').value = '';
    document.getElementById('adminStatusInput').value = '1';
    document.getElementById('adminRoleInput').value = 'administrator';
}

function fillAdminForm(id, username, isActive, role) {
    document.getElementById('adminActionInput').value = 'update';
    document.getElementById('adminIdInput').value = id;
    document.getElementById('adminUsernameInput').value = username;
    document.getElementById('adminPasswordInput').value = '';
    document.getElementById('adminStatusInput').value = isActive;
    document.getElementById('adminRoleInput').value = role || 'administrator';
}

function confirmDeleteAdmin() {
    const actionInput = document.getElementById('adminActionInput');
    if (actionInput.value !== 'update') {
        return false;
    }
    actionInput.value = 'delete';
    return confirm('Supprimer cet utilisateur local ?');
}

async function postAdministrationAction(url, payload) {
    const response = await fetch(url, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
            'Accept': 'application/json',
        },
        body: new URLSearchParams({
            csrf_token: administrationCsrfToken,
            ...payload,
        }),
    });

    let data = null;
    try {
        data = await response.json();
    } catch (error) {
        data = null;
    }

    if (!response.ok || !data || data.success !== true) {
        throw new Error(data && data.message ? data.message : 'Action administrative impossible.');
    }

    return data;
}

document.addEventListener('DOMContentLoaded', () => {
    const searchInput = document.getElementById('adminSearchInput');
    const tableBody = document.getElementById('adminTableBody');
    const passwordInput = document.getElementById('adminPasswordInput');
    const togglePasswordBtn = document.getElementById('toggleAdminPasswordBtn');
    const sqlFileInput = document.getElementById('adminSqlFileInput');
    const chooseSqlFileBtn = document.getElementById('chooseSqlFileBtn');
    const selectedSqlFileName = document.getElementById('selectedSqlFileName');
    const syncOpnsenseSessionsBtn = document.getElementById('syncOpnsenseSessionsBtn');
    const installOpnsenseCronBtn = document.getElementById('installOpnsenseCronBtn');
    const opnsenseMaintenanceStatus = document.getElementById('opnsenseMaintenanceStatus');
    if (!searchInput || !tableBody) {
        // continue for the password toggle below
    } else {
        searchInput.addEventListener('input', () => {
            const query = searchInput.value.trim().toLowerCase();
            tableBody.querySelectorAll('tr').forEach((row) => {
                row.style.display = query === '' || row.textContent.toLowerCase().includes(query) ? '' : 'none';
            });
        });
    }

    if (passwordInput && togglePasswordBtn) {
        togglePasswordBtn.addEventListener('click', () => {
            const nextType = passwordInput.type === 'password' ? 'text' : 'password';
            passwordInput.type = nextType;
            togglePasswordBtn.innerHTML = nextType === 'text'
                ? '<i class="fa fa-eye-slash"></i>'
                : '<i class="fa fa-eye"></i>';
        });
    }

    if (sqlFileInput && chooseSqlFileBtn && selectedSqlFileName) {
        chooseSqlFileBtn.addEventListener('click', () => {
            sqlFileInput.click();
        });

        sqlFileInput.addEventListener('change', () => {
            selectedSqlFileName.textContent = sqlFileInput.files && sqlFileInput.files[0]
                ? sqlFileInput.files[0].name
                : 'Aucun fichier';
        });
    }

    if (syncOpnsenseSessionsBtn && opnsenseMaintenanceStatus) {
        syncOpnsenseSessionsBtn.addEventListener('click', async () => {
            syncOpnsenseSessionsBtn.disabled = true;
            opnsenseMaintenanceStatus.textContent = 'Synchronisation OPNsense en cours...';

            try {
                const result = await postAdministrationAction('/api/admin/opnsense_sync_sessions.php', {});
                const synced = Array.isArray(result.synced) && result.synced.length > 0
                    ? result.synced.join(', ')
                    : 'aucun';
                const deletedRules = Array.isArray(result.deleted_rules) && result.deleted_rules.length > 0
                    ? result.deleted_rules.length
                    : 0;

                opnsenseMaintenanceStatus.textContent =
                    'Synchro OPNsense OK. Sessions: ' + String(result.sessions || 0) +
                    ' | Utilisateurs synchronises: ' + synced +
                    ' | Rules nettoyees: ' + String(deletedRules);
            } catch (error) {
                opnsenseMaintenanceStatus.textContent = error.message || 'Synchronisation OPNsense impossible.';
            } finally {
                syncOpnsenseSessionsBtn.disabled = false;
            }
        });
    }

    if (installOpnsenseCronBtn && opnsenseMaintenanceStatus) {
        installOpnsenseCronBtn.addEventListener('click', async () => {
            installOpnsenseCronBtn.disabled = true;
            opnsenseMaintenanceStatus.textContent = 'Installation du cron OPNsense en cours...';

            try {
                const result = await postAdministrationAction('/api/admin/install_opnsense_cron.php', {});
                opnsenseMaintenanceStatus.textContent =
                    'Cron OPNsense installe pour l utilisateur OS ' + String(result.os_user || '-') +
                    '. Script: ' + String(result.script_path || '-');
            } catch (error) {
                opnsenseMaintenanceStatus.textContent = error.message || 'Installation cron impossible.';
            } finally {
                installOpnsenseCronBtn.disabled = false;
            }
        });
    }
});
</script>
</body>
</html>
