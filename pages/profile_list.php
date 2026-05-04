<?php
session_start();

require __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/app_context.php';
require_once __DIR__ . '/../includes/formatters.php';
require_once __DIR__ . '/../includes/page_helpers.php';
require_once __DIR__ . '/../includes/profile_catalog.php';
require_once __DIR__ . '/../includes/profile_schema.php';

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: ../index.php');
    exit();
}
requireAdministratorAccess();
ensureProfilesExtendedSchema($pdo);

$csrfToken = ensureCsrfToken();

$context = ['app' => buildAppContext()];
$activeDevice = $context['app']['device'] ?? null;
$deviceStore = loadDeviceStore();
if (is_array($activeDevice) && trim((string)($activeDevice['id'] ?? '')) !== '') {
    $fullActiveDevice = findDeviceById($deviceStore, (string)$activeDevice['id']);
    if (is_array($fullActiveDevice)) {
        $activeDevice = $fullActiveDevice;
    }
}
$isActiveMikrotik = false;
$mikrotikProfileLoadError = null;
$profiles = [];
$profileStorageLabel = 'Profils locaux';
$profileStorageDescription = 'Les profils affiches proviennent du stockage local.';

try {
    $isActiveMikrotik = is_array($activeDevice) && (($activeDevice['type'] ?? '') === 'mikrotik');
    $activeDeviceType = strtolower(trim((string)($activeDevice['type'] ?? 'other')));

    if ($isActiveMikrotik) {
        $profileStorageLabel = 'Profils MikroTik';
        $profileStorageDescription = 'Les profils sont lus directement sur le routeur MikroTik actif.';
    } elseif ($activeDeviceType === 'opnsense') {
        $profileStorageLabel = 'Profils locaux synchronises vers RADIUS';
        $profileStorageDescription = 'Pour OPNsense, la liste provient du stockage local. Les debits, quotas et validites sont appliques via FreeRADIUS.';
    } else {
        $profileStorageLabel = 'Profils locaux synchronises vers RADIUS';
        $profileStorageDescription = 'La liste provient du stockage local puis est synchronisee vers le backend FreeRADIUS du serveur actif.';
    }

    if (is_array($activeDevice)) {
        $catalog = loadProfileCatalogForDevice($pdo, $activeDevice, [
            'sort' => $isActiveMikrotik ? 'none' : 'id_desc',
        ]);
        $profiles = $catalog['profiles'];
    }
} catch (Throwable $e) {
    $mikrotikProfileLoadError = trim((string)$e->getMessage());

    if (!$isActiveMikrotik) {
        throw $e;
    }
}
?>

<?php
$pageTitle = 'Liste des Profils';
$htmlClass = 'profile-list-page';
$bodyClass = 'profile-list-page';
$contentClass = 'profile-list-shell';
$extraCss = [
    '../css/profile_liste.css?v=20260417c',
];
require_once '../includes/layout_header.php';
?>

<div class="row profiles-layout-row" id="profilesLayoutRow">
<div class="col-12 profiles-list-column users-list-column" id="profilesListColumn">

<?php if ($mikrotikProfileLoadError !== null): ?>
<div class="alert alert-warning py-2 px-3 small mb-3">
    Certains champs MikroTik n ont pas pu etre charges: <?= htmlspecialchars($mikrotikProfileLoadError) ?>
</div>
<?php endif; ?>

<div
    class="d-none"
    id="profilesFlowExplanation"
    data-toast-title="<?= htmlspecialchars($profileStorageLabel, ENT_QUOTES) ?>"
    data-toast-message="<?= htmlspecialchars($profileStorageDescription, ENT_QUOTES) ?>"
></div>

<div class="card shadow-sm profiles-table-card">
<div class="card-header profiles-list-card-header users-list-card-header d-flex align-items-center justify-content-between flex-wrap gap-2">
    <div class="d-flex align-items-center profiles-list-card-title users-list-card-title text-truncate">
        <i class="fa fa-pie-chart me-2 flex-shrink-0"></i>
        <span>Gestion des profils</span>
    </div>
    <div class="d-flex align-items-center gap-2 flex-shrink-0 profiles-table-actions users-table-actions">
        <div class="profiles-search-box">
            <div class="input-group input-group-sm profiles-search-group">
                <span class="input-group-text" id="profilesSearchAddon">
                    <i class="fa fa-search" aria-hidden="true"></i>
                </span>
                <input type="search" class="form-control profiles-search-input" id="profilesSearchInput" placeholder="Rechercher sur tous les critères..." aria-label="Rechercher un profil sur tous les critères" aria-describedby="profilesSearchAddon" autocomplete="off" spellcheck="false">
                <button type="button" class="btn btn-test d-none" id="profilesSearchClear" aria-label="Effacer la recherche" title="Effacer la recherche">
                    <i class="fa fa-xmark" aria-hidden="true"></i>
                </button>
            </div>
        </div>

        <div class="dropdown">
            <button
                class="btn btn-test dropdown-toggle"
                type="button"
                id="profileColumnsToggle"
                data-bs-toggle="dropdown"
                data-bs-auto-close="outside"
                aria-expanded="false"
            >
                <i class="fa fa-table-columns me-1"></i> Colonnes
            </button>
            <div class="dropdown-menu dropdown-menu-end profile-columns-menu p-2" aria-labelledby="profileColumnsToggle">
                <label class="dropdown-item-text profile-column-option">
                    <input class="form-check-input mt-0 me-2 js-profile-column-toggle" type="checkbox" data-column-key="id" checked>
                    <span>ID</span>
                </label>
                <label class="dropdown-item-text profile-column-option">
                    <input class="form-check-input mt-0 me-2 js-profile-column-toggle" type="checkbox" data-column-key="name" checked>
                    <span>Nom</span>
                </label>
                <label class="dropdown-item-text profile-column-option">
                    <input class="form-check-input mt-0 me-2 js-profile-column-toggle" type="checkbox" data-column-key="server" checked>
                    <span>Serveur</span>
                </label>
                <label class="dropdown-item-text profile-column-option">
                    <input class="form-check-input mt-0 me-2 js-profile-column-toggle" type="checkbox" data-column-key="rate_limit" checked>
                    <span>Rate Limit</span>
                </label>
                <label class="dropdown-item-text profile-column-option">
                    <input class="form-check-input mt-0 me-2 js-profile-column-toggle" type="checkbox" data-column-key="shared_users" checked>
                    <span>Partagé</span>
                </label>
                <label class="dropdown-item-text profile-column-option">
                    <input class="form-check-input mt-0 me-2 js-profile-column-toggle" type="checkbox" data-column-key="time_limit" checked>
                    <span>Limite temps</span>
                </label>
                <label class="dropdown-item-text profile-column-option">
                    <input class="form-check-input mt-0 me-2 js-profile-column-toggle" type="checkbox" data-column-key="validity" checked>
                    <span>Validité profil</span>
                </label>
                <label class="dropdown-item-text profile-column-option">
                    <input class="form-check-input mt-0 me-2 js-profile-column-toggle" type="checkbox" data-column-key="data_limit" checked>
                    <span>Limite de données</span>
                </label>
                <label class="dropdown-item-text profile-column-option">
                    <input class="form-check-input mt-0 me-2 js-profile-column-toggle" type="checkbox" data-column-key="grace_period" checked>
                    <span>Période de grâce</span>
                </label>
                <label class="dropdown-item-text profile-column-option">
                    <input class="form-check-input mt-0 me-2 js-profile-column-toggle" type="checkbox" data-column-key="expired_mode" checked>
                    <span>Mode d'expiration</span>
                </label>
                <label class="dropdown-item-text profile-column-option">
                    <input class="form-check-input mt-0 me-2 js-profile-column-toggle" type="checkbox" data-column-key="price" checked>
                    <span>Prix de base</span>
                </label>
                <label class="dropdown-item-text profile-column-option">
                    <input class="form-check-input mt-0 me-2 js-profile-column-toggle" type="checkbox" data-column-key="selling_price" checked>
                    <span>Prix de vente</span>
                </label>
                <label class="dropdown-item-text profile-column-option">
                    <input class="form-check-input mt-0 me-2 js-profile-column-toggle" type="checkbox" data-column-key="address_pool" checked>
                    <span>Pool d'adresses</span>
                </label>
                <label class="dropdown-item-text profile-column-option">
                    <input class="form-check-input mt-0 me-2 js-profile-column-toggle" type="checkbox" data-column-key="lock_user" checked>
                    <span>Verrouiller</span>
                </label>
                <label class="dropdown-item-text profile-column-option">
                    <input class="form-check-input mt-0 me-2 js-profile-column-toggle" type="checkbox" data-column-key="parent_queue" checked>
                    <span>File parente</span>
                </label>
                <label class="dropdown-item-text profile-column-option">
                    <input class="form-check-input mt-0 me-2 js-profile-column-toggle" type="checkbox" data-column-key="action" checked>
                    <span>Action</span>
                </label>
            </div>
        </div>

        <a href="add_profile.php" class="btn btn-save">
            <i class="fa fa-plus me-1"></i> Nouveau
        </a>
    </div>
</div>
<div class="card-body p-0">

<div class="table-responsive profiles-table-wrap" id="profilesTableWrap">
<table class="table table-striped table-hover table-dark mb-0 profiles-table align-middle small text-nowrap table-standard" data-csrf-token="<?= htmlspecialchars($csrfToken, ENT_QUOTES) ?>" data-sort-table="1" data-default-sort-key="id" data-default-sort-direction="desc">

<thead>
<tr>
    <th data-column-key="id" data-sort-key="id" data-sort-type="text">ID</th>
    <th data-column-key="name" class="profiles-col-left" data-sort-key="name" data-sort-type="text">Nom</th>
    <th data-column-key="server" class="profiles-col-left" data-sort-key="server" data-sort-type="text">Serveur</th>
    <th data-column-key="rate_limit" data-sort-key="rate_limit" data-sort-type="text">Rate Limit</th>
    <th data-column-key="shared_users" data-sort-key="shared_users" data-sort-type="number">Partagé</th>
    <th data-column-key="time_limit" data-sort-key="time_limit" data-sort-type="text">Limite temps</th>
    <th data-column-key="validity" data-sort-key="validity" data-sort-type="text">Validité profil</th>
    <th data-column-key="data_limit" data-sort-key="data_limit" data-sort-type="number">Limite de données</th>
    <th data-column-key="grace_period" data-sort-key="grace_period" data-sort-type="text">Période de grâce</th>
    <th data-column-key="expired_mode" class="profiles-col-left" data-sort-key="expired_mode" data-sort-type="text">Mode d'expiration</th>
    <th data-column-key="price" data-sort-key="price" data-sort-type="number">Prix de base</th>
    <th data-column-key="selling_price" data-sort-key="selling_price" data-sort-type="number">Prix de vente</th>
    <th data-column-key="address_pool" data-sort-key="address_pool" data-sort-type="text">Pool d'adresses</th>
    <th data-column-key="lock_user" data-sort-key="lock_user" data-sort-type="text">Verrouiller</th>
    <th data-column-key="parent_queue" data-sort-key="parent_queue" data-sort-type="text">File parente</th>
    <th data-column-key="action">Action</th>
</tr>
</thead>

<tbody id="profilesTableBody">
<?php if (!$profiles): ?>
<tr>
    <td colspan="16" class="text-center py-4">Aucun profil disponible</td>
</tr>
<?php else: ?>
<?php foreach ($profiles as $profile): ?>
<?php
    $name = (string)$profile['name'];
    $serverLabel = $activeDevice['name'] ?? '-';
    $rowIdentifier = $isActiveMikrotik
        ? (string)($profile['router_id'] ?? '-')
        : (string)((int)($profile['id'] ?? 0));
    $validityLabel = $isActiveMikrotik
        ? formatMikrotikValidity($profile['validity'] ?? null)
        : formatDurationOrUnlimited($profile['validity_time'] !== null ? (int)$profile['validity_time'] : null);
    $sessionTimeoutLabel = $isActiveMikrotik
        ? formatDurationOrUnlimited($profile['session_timeout'] !== null ? (int)$profile['session_timeout'] : null)
        : formatDurationOrUnlimited($profile['session_timeout'] !== null ? (int)$profile['session_timeout'] : null);
    $gracePeriodLabel = $isActiveMikrotik
        ? '-'
        : formatDurationOrUnlimited($profile['grace_period'] !== null ? (int)$profile['grace_period'] : null);
    $dataLimitLabel = formatProfileDataQuotaLabel($profile['data_quota_mb'] ?? null);
    $priceLabel = formatProfileMoneyLabel($profile['price'] ?? null);
    $sellingPriceLabel = formatProfileMoneyLabel($profile['selling_price'] ?? null);
    $addressPool = (($profile['ip_pool'] ?? '') !== '' ? $profile['ip_pool'] : '-');
    $rateLimitLabel = trim((string)($profile['rate_limit'] ?? ''));
    if ($rateLimitLabel === '') {
        $rateLimitLabel = '-';
    }
    $expiredModeLabel = trim((string)($profile['expired_mode'] ?? ''));
    if ($expiredModeLabel === '') {
        $expiredModeLabel = '-';
    }
    $lockUserRaw = trim((string)($profile['lock_user'] ?? ''));
    if ($lockUserRaw === '') {
        $lockUserLabel = '-';
    } else {
        $lockUserLabel = ($lockUserRaw === 'Enable' || (int)$lockUserRaw === 1) ? 'Activer' : 'Desactiver';
    }
    $parentQueueLabel = trim((string)($profile['parent_queue'] ?? ''));
    if ($parentQueueLabel === '') {
        $parentQueueLabel = '-';
    }
    $sessionTimeoutForEdit = (int)($profile['session_timeout'] ?? 0);
    $editHref = $isActiveMikrotik
        ? 'add_profile.php?profile_name=' . rawurlencode($name)
            . '&device_id=' . rawurlencode((string)($activeDevice['id'] ?? ''))
            . '&data_quota_mb=' . rawurlencode((string)((int)($profile['data_quota_mb'] ?? 0)))
            . '&session_timeout=' . rawurlencode((string)$sessionTimeoutForEdit)
        : 'add_profile.php?profile_id=' . (int)$profile['id'];

    $searchTokens = [
        $rowIdentifier,
        $name,
        $serverLabel,
        $rateLimitLabel,
        (string)((int)($profile['simultaneous_use'] ?? 0)),
        $sessionTimeoutLabel,
        (string)($profile['session_timeout'] ?? ''),
        $validityLabel,
        (string)($profile['validity'] ?? ''),
        (string)($profile['validity_time'] ?? ''),
        $dataLimitLabel,
        (string)($profile['data_quota_mb'] ?? ''),
        $gracePeriodLabel,
        (string)($profile['grace_period'] ?? ''),
        $expiredModeLabel,
        $priceLabel,
        (string)($profile['price'] ?? ''),
        $sellingPriceLabel,
        (string)($profile['selling_price'] ?? ''),
        $addressPool,
        $lockUserLabel,
        $lockUserRaw,
        $parentQueueLabel,
    ];
    $profileSearchParts = [];
    foreach ($searchTokens as $searchToken) {
        $searchToken = trim((string)$searchToken);
        if ($searchToken !== '' && $searchToken !== '-') {
            $profileSearchParts[] = $searchToken;
        }
    }
    $profileSearchIndex = implode(' ', $profileSearchParts);
?>
<tr data-profile-search="<?= htmlspecialchars($profileSearchIndex, ENT_QUOTES, 'UTF-8') ?>">
    <td data-column-key="id"><?= htmlspecialchars($rowIdentifier !== '' ? $rowIdentifier : '-') ?></td>
    <td data-column-key="name" class="profiles-col-left"><?= htmlspecialchars($name) ?></td>
    <td data-column-key="server" class="profiles-col-left"><?= htmlspecialchars($serverLabel !== '' ? $serverLabel : '-') ?></td>
    <td data-column-key="rate_limit"><?= htmlspecialchars($rateLimitLabel) ?></td>
    <td data-column-key="shared_users" class="text-end"><?= (int)($profile['simultaneous_use'] ?? 0) ?></td>
    <td data-column-key="time_limit"><?= htmlspecialchars($sessionTimeoutLabel) ?></td>
    <td data-column-key="validity"><?= htmlspecialchars($validityLabel) ?></td>
    <td data-column-key="data_limit" class="text-end"><?= htmlspecialchars($dataLimitLabel) ?></td>
    <td data-column-key="grace_period"><?= htmlspecialchars($gracePeriodLabel) ?></td>
    <td data-column-key="expired_mode" class="profiles-col-left"><?= htmlspecialchars($expiredModeLabel) ?></td>
    <td data-column-key="price" class="text-end"><?= htmlspecialchars($priceLabel) ?></td>
    <td data-column-key="selling_price" class="text-end"><?= htmlspecialchars($sellingPriceLabel) ?></td>
    <td data-column-key="address_pool"><?= htmlspecialchars($addressPool) ?></td>
    <td data-column-key="lock_user"><?= htmlspecialchars($lockUserLabel) ?></td>
    <td data-column-key="parent_queue"><?= htmlspecialchars($parentQueueLabel) ?></td>
    <td data-column-key="action" class="action-cell">
        <a href="<?= htmlspecialchars($editHref) ?>" class="btn btn-test btn-sm profile-action-btn" title="Modifier ce profil">
            <i class="fas fa-pen"></i>
        </a>
        <button
            type="button"
            class="btn btn-delete btn-sm profile-action-btn js-delete-profile"
            title="Supprimer ce profil"
            data-profile-id="<?= (int)($profile['id'] ?? 0) ?>"
            data-router-id="<?= htmlspecialchars((string)($profile['router_id'] ?? ''), ENT_QUOTES) ?>"
            data-profile-name="<?= htmlspecialchars($name, ENT_QUOTES) ?>"
        >
            <i class="fas fa-trash"></i>
        </button>
    </td>
</tr>
<?php endforeach; ?>
<tr id="profilesSearchEmptyRow" class="d-none">
    <td colspan="16" class="text-center py-4">Aucun profil ne correspond à la recherche</td>
</tr>
<?php endif; ?>
</tbody>

</table>
</div>

</div>
</div>

</div>
</div>

</div>
</div>
</div>


<?php
$extraJs = array (
  0 => '../js/table_sort.js',
  1 => '../js/profile_list.js',
);
require_once '../includes/layout_footer.php';
?>
