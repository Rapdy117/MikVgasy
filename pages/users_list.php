<?php
session_start();

require '../config/db.php';
require_once '../includes/auth.php';
require_once '../includes/app_context.php';
require_once '../includes/formatters.php';
require_once '../includes/mikrotik_backend.php';
require_once '../includes/page_helpers.php';
require_once '../includes/user_schema.php';

/* =========================
   SECURITY
========================= */
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: ../index.php');
    exit();
}

requireCurrentPageAccess();

$csrfToken = ensureCsrfToken();

$canManageUsersFromList = isAdministrator();
ensureUsersExtendedSchema($pdo);

$input = [
    'users_limit' => 100,
];
$context = [
    'app' => buildAppContext(),
];
$result = [];
$view = [];

$context['device'] = $context['app']['device'] ?? null;
$context['device_type'] = strtolower(trim((string)($context['device']['type'] ?? '')));
$context['is_mikrotik'] = (bool)($context['app']['capabilities']['is_mikrotik'] ?? false);

$view['users_flow_label'] = $context['is_mikrotik'] ? 'Utilisateurs MikroTik' : 'Utilisateurs locaux synchronises vers RADIUS';
$view['users_flow_description'] = $context['is_mikrotik']
    ? 'La liste et le detail sont lus depuis le routeur MikroTik actif.'
    : ($context['device_type'] === 'opnsense'
        ? 'Pour OPNsense, seuls les utilisateurs OPNsense sont affiches. Les sessions passent par FreeRADIUS.'
        : 'La liste provient du stockage local et les sessions passent par le backend FreeRADIUS.');

$activeDeviceType = $context['device_type'];
$isMikrotikUsers = $context['is_mikrotik'];
$usersFlowLabel = $view['users_flow_label'];
$usersFlowDescription = $view['users_flow_description'];

$profileOptionsStmt = $pdo->query("
    SELECT
        id,
        name,
        service_type,
        rate_limit,
        session_timeout,
        idle_timeout,
        simultaneous_use,
        data_quota_mb,
        account_type
    FROM profiles
    ORDER BY name ASC
");
$profileOptions = $profileOptionsStmt->fetchAll(PDO::FETCH_ASSOC);
$availableStatusOptions = [];
$availableProfileOptions = [];
$profileDefaultsByName = [];
foreach ($profileOptions as $profileOption) {
    $profileNameKey = strtolower(trim((string)($profileOption['name'] ?? '')));
    if ($profileNameKey !== '') {
        $profileDefaultsByName[$profileNameKey] = $profileOption;
    }
}

if ($isMikrotikUsers) {
    $result['users'] = getMikrotikHotspotUsers(0);
} else {
    $usersSql = "
    SELECT
        u.id,
        u.username,
        u.nas_id,
        u.profile_id,
        u.fullname,
        u.phone,
        u.address,
        u.email,
        u.balance,
        u.status,
        u.created_at,
        u.last_login,
        u.auto_renewal,
        u.expiration_date,
        u.session_timeout AS user_session_timeout,
        u.data_limit AS user_data_limit,
        u.current_credit_time,
        u.current_credit_data,
        n.shortname AS nas_shortname,
        n.nasname AS nas_name,
        n.type AS nas_type,
        p.name AS plan,
        p.service_type,
        p.rate_limit,
        p.session_timeout,
        p.idle_timeout,
        p.validity_time,
        p.simultaneous_use,
        p.data_quota_mb,
        p.ip_pool,
        p.account_type,
        p.expired_mode,
        p.price,
        p.selling_price
    FROM users u
    LEFT JOIN nas n ON u.nas_id = n.id
    LEFT JOIN profiles p ON u.profile_id = p.id
    ";

    $usersParams = [];

    if ($activeDeviceType === 'opnsense') {
        $usersSql .= "
    WHERE LOWER(COALESCE(n.type, '')) = :nas_type
        ";
        $usersParams[':nas_type'] = 'opnsense';
    } elseif (in_array($activeDeviceType, ['radius', 'freeradius'], true)) {
        $usersSql .= "
    WHERE LOWER(COALESCE(n.type, '')) IN ('radius', 'freeradius')
        ";
    }

    $usersSql .= "
    ORDER BY u.id DESC
    LIMIT " . (int)$input['users_limit'] . "
    ";

    $stmt = $pdo->prepare($usersSql);
    $stmt->execute($usersParams);
    $result['users'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $usernames = [];
    foreach ($result['users'] as $userRow) {
        $username = trim((string)($userRow['username'] ?? ''));
        if ($username !== '') {
            $usernames[] = $username;
        }
    }

    $radacctStats = [];
    if ($usernames !== []) {
        $placeholders = implode(',', array_fill(0, count($usernames), '?'));
        $radacctSql = "
            SELECT
                username,
                MIN(CASE WHEN acctstarttime IS NOT NULL THEN acctstarttime END) AS first_login,
                SUM(
                    CASE
                        WHEN COALESCE(acctsessiontime, 0) > 0 THEN acctsessiontime
                        WHEN acctstarttime IS NULL THEN 0
                        ELSE TIMESTAMPDIFF(SECOND, acctstarttime, COALESCE(acctstoptime, UTC_TIMESTAMP()))
                    END
                ) AS session_total_seconds,
                SUM(COALESCE(acctinputoctets, 0) + COALESCE(acctoutputoctets, 0)) AS data_consumed_bytes
            FROM radacct
            WHERE username IN ($placeholders)
            GROUP BY username
        ";
        $radacctStmt = $pdo->prepare($radacctSql);
        $radacctStmt->execute($usernames);
        foreach ($radacctStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $radacctStats[(string)($row['username'] ?? '')] = $row;
        }
    }

    foreach ($result['users'] as $index => $userRow) {
        $username = (string)($userRow['username'] ?? '');
        $stats = $radacctStats[$username] ?? null;
        $result['users'][$index]['first_login'] = $stats['first_login'] ?? null;
        $result['users'][$index]['session_total_seconds'] = isset($stats['session_total_seconds'])
            ? (int)$stats['session_total_seconds']
            : 0;
        $result['users'][$index]['data_consumed_bytes'] = isset($stats['data_consumed_bytes'])
            ? (float)$stats['data_consumed_bytes']
            : 0.0;
    }
}

$users = $result['users'] ?? [];

$mikrotikCreationByUser = [];
if ($isMikrotikUsers && $users !== []) {
    $usernames = [];
    foreach ($users as $userRow) {
        $uname = trim((string)($userRow['username'] ?? ''));
        if ($uname !== '') {
            $usernames[] = $uname;
        }
    }

    $usernames = array_values(array_unique($usernames));
    $chunkSize = 200;
    for ($offset = 0; $offset < count($usernames); $offset += $chunkSize) {
        $chunk = array_slice($usernames, $offset, $chunkSize);
        $placeholders = implode(',', array_fill(0, count($chunk), '?'));

        $voucherStmt = $pdo->prepare("
            SELECT username, MIN(created_at) AS created_at
            FROM vouchers
            WHERE username IN ($placeholders)
            GROUP BY username
        ");
        $voucherStmt->execute($chunk);
        foreach ($voucherStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $name = (string)($row['username'] ?? '');
            $created = (string)($row['created_at'] ?? '');
            if ($name !== '' && $created !== '') {
                $mikrotikCreationByUser[$name] = $created;
            }
        }

        $historyStmt = $pdo->prepare("
            SELECT target_name, MIN(created_at) AS created_at
            FROM operation_history
            WHERE operation_type = 'user_create'
              AND target_name IN ($placeholders)
            GROUP BY target_name
        ");
        $historyStmt->execute($chunk);
        foreach ($historyStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $name = (string)($row['target_name'] ?? '');
            $created = (string)($row['created_at'] ?? '');
            if ($name === '' || $created === '') {
                continue;
            }
            if (!isset($mikrotikCreationByUser[$name]) || strcmp($created, $mikrotikCreationByUser[$name]) < 0) {
                $mikrotikCreationByUser[$name] = $created;
            }
        }
    }
}

foreach ($users as $userRow) {
    $statusOption = trim((string)($isMikrotikUsers ? (($userRow['disabled'] ?? false) ? 'disabled' : 'active') : ($userRow['status'] ?? '')));
    if ($statusOption !== '') {
        $availableStatusOptions[$statusOption] = true;
    }

    $profileOption = trim((string)($userRow['plan'] ?? ($userRow['profile'] ?? '')));
    if ($profileOption !== '') {
        $availableProfileOptions[$profileOption] = true;
    }
}

ksort($availableStatusOptions);
ksort($availableProfileOptions);
?>

<?php
$pageTitle = 'Users';
require_once '../includes/layout_header.php';
?>

<div class="alert alert-info py-2 px-3 small mb-3 page-flow-explanation" id="usersFlowExplanation">
    <div class="fw-semibold"><?= htmlspecialchars($usersFlowLabel) ?></div>
    <div><?= htmlspecialchars($usersFlowDescription) ?></div>
</div>

<div class="row users-layout-row" id="usersLayoutRow">

<!-- =========================
     LEFT : USERS LIST
========================= -->
<div class="col-12 mb-3 users-list-column" id="usersListColumn">
<div class="card shadow-sm">
<div class="card-header users-list-card-header d-flex align-items-center justify-content-between flex-wrap gap-2">
    <div class="d-flex align-items-center users-list-card-title text-truncate">
        <i class="fa fa-users me-2 flex-shrink-0"></i>
        <span>Gestion des utilisateurs</span>
    </div>
    <div class="d-flex align-items-center gap-2 flex-shrink-0 users-table-actions">
    <button type="button" class="btn btn-test" id="usersRefreshBtn" title="Actualiser la page">
        <i class="fa fa-rotate-right me-1"></i> Actualiser
    </button>
    <button type="button" class="btn btn-test" id="usersViewModeToggle" title="Basculer entre la liste et les details">
        <i class="fa fa-list me-1"></i> Mode liste
    </button>
    <div class="dropdown">
        <button
            class="btn btn-test dropdown-toggle"
            type="button"
            id="usersColumnsToggle"
            data-bs-toggle="dropdown"
            data-bs-auto-close="outside"
            aria-expanded="false"
        >
            <i class="fa fa-table-columns me-1"></i> Colonnes
        </button>
        <div class="dropdown-menu dropdown-menu-end profile-columns-menu p-2" aria-labelledby="usersColumnsToggle">
                <label class="dropdown-item-text profile-column-option">
                    <input class="form-check-input mt-0 me-2 js-users-column-toggle" type="checkbox" data-column-key="id" checked>
                    <span>ID</span>
                </label>
                <label class="dropdown-item-text profile-column-option">
                    <input class="form-check-input mt-0 me-2 js-users-column-toggle" type="checkbox" data-column-key="server" checked>
                    <span>Serveur</span>
                </label>
                <label class="dropdown-item-text profile-column-option">
                    <input class="form-check-input mt-0 me-2 js-users-column-toggle" type="checkbox" data-column-key="username" checked>
                    <span>Nom d'utilisateur</span>
                </label>
                <label class="dropdown-item-text profile-column-option">
                    <input class="form-check-input mt-0 me-2 js-users-column-toggle" type="checkbox" data-column-key="plan" checked>
                    <span>Profil</span>
                </label>
                <label class="dropdown-item-text profile-column-option">
                    <input class="form-check-input mt-0 me-2 js-users-column-toggle" type="checkbox" data-column-key="rate_limit" checked>
                    <span>Limite de débit</span>
                </label>
                <label class="dropdown-item-text profile-column-option">
                    <input class="form-check-input mt-0 me-2 js-users-column-toggle" type="checkbox" data-column-key="shared_users" checked>
                    <span>Partagés</span>
                </label>
                <label class="dropdown-item-text profile-column-option">
                    <input class="form-check-input mt-0 me-2 js-users-column-toggle" type="checkbox" data-column-key="time_limit" checked>
                    <span>Limite de temps</span>
                </label>
                <label class="dropdown-item-text profile-column-option">
                    <input class="form-check-input mt-0 me-2 js-users-column-toggle" type="checkbox" data-column-key="session_total" checked>
                    <span>Durée cumulée</span>
                </label>
                <label class="dropdown-item-text profile-column-option">
                    <input class="form-check-input mt-0 me-2 js-users-column-toggle" type="checkbox" data-column-key="data_limit" checked>
                    <span>Limite de données</span>
                </label>
                <label class="dropdown-item-text profile-column-option">
                    <input class="form-check-input mt-0 me-2 js-users-column-toggle" type="checkbox" data-column-key="data_consumed" checked>
                    <span>Data consommée</span>
                </label>
                <label class="dropdown-item-text profile-column-option">
                    <input class="form-check-input mt-0 me-2 js-users-column-toggle" type="checkbox" data-column-key="validity_profile" checked>
                    <span>Validité</span>
                </label>
                <label class="dropdown-item-text profile-column-option">
                    <input class="form-check-input mt-0 me-2 js-users-column-toggle" type="checkbox" data-column-key="expired_mode" checked>
                    <span>Mode d'expiration</span>
                </label>
                <label class="dropdown-item-text profile-column-option">
                    <input class="form-check-input mt-0 me-2 js-users-column-toggle" type="checkbox" data-column-key="expiration" checked>
                    <span>Expiration</span>
                </label>
                <label class="dropdown-item-text profile-column-option">
                    <input class="form-check-input mt-0 me-2 js-users-column-toggle" type="checkbox" data-column-key="status" checked>
                    <span>Statut</span>
                </label>
                <label class="dropdown-item-text profile-column-option">
                    <input class="form-check-input mt-0 me-2 js-users-column-toggle" type="checkbox" data-column-key="action" checked>
                    <span>Action</span>
                </label>
        </div>
    </div>
    </div>
</div>
<div class="card-body">

<div class="d-flex flex-wrap align-items-end users-header-row mb-3">
    <div class="users-filters-inline w-100 network-device-form users-list-filters">
        <div class="row g-2 align-items-end">
            <div class="col-md-4">
                <div class="users-filter-field users-filter-field-search">
                    <label for="usersSearchInput" class="form-label visually-hidden">Recherche</label>
                    <div class="input-group">
                        <span class="input-group-text">
                            <i class="fa fa-search me-2"></i>Recherche
                        </span>
                        <input
                            type="search"
                            class="form-control"
                            id="usersSearchInput"
                            placeholder="Nom d'utilisateur..."
                            autocomplete="off"
                        >
                    </div>
                    <div class="users-search-suggestions d-none" id="usersSearchSuggestions"></div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="users-filter-field">
                    <label for="usersProfileFilter" class="form-label visually-hidden">Profil</label>
                    <div class="input-group">
                        <span class="input-group-text">Profil</span>
                        <select class="form-select" id="usersProfileFilter">
                        <option value="">Tous</option>
                        <?php foreach (array_keys($availableProfileOptions) as $profileOption): ?>
                        <option value="<?= htmlspecialchars(mb_strtolower($profileOption)) ?>"><?= htmlspecialchars($profileOption) ?></option>
                        <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="users-filter-field">
                    <label for="usersStatusFilter" class="form-label visually-hidden">Statut</label>
                    <div class="input-group">
                        <span class="input-group-text">Statut</span>
                        <select class="form-select" id="usersStatusFilter">
                        <option value="">Tous</option>
                        <?php foreach (array_keys($availableStatusOptions) as $statusOption): ?>
                        <option value="<?= htmlspecialchars(mb_strtolower($statusOption)) ?>"><?= htmlspecialchars($statusOption) ?></option>
                        <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="users-table-panel">
<div class="table-responsive users-table-scroll">
<table class="table table-striped table-hover table-dark mb-0 users-table align-middle small text-nowrap table-standard" data-sort-table="1" data-default-sort-key="id" data-default-sort-direction="desc">

<thead>
<tr>
    <th class="users-sortable<?= $isMikrotikUsers ? ' d-none' : '' ?>" data-column-key="id" data-sort-key="id" data-sort-type="number">ID</th>
    <th class="users-col-left users-sortable" data-column-key="server" data-sort-key="server_display" data-sort-type="text">Serveur</th>
    <th class="users-sortable users-col-left" data-column-key="username" data-sort-key="username" data-sort-type="text"><?= $isMikrotikUsers ? 'Nom' : 'Nom d\'utilisateur' ?></th>
    <th class="users-sortable" data-column-key="plan" data-sort-key="plan" data-sort-type="text">Profil</th>
    <th class="users-sortable" data-column-key="rate_limit" data-sort-key="rate_limit" data-sort-type="text">Limite de débit</th>
    <th class="users-sortable" data-column-key="shared_users" data-sort-key="simultaneous_use" data-sort-type="number">Partagés</th>
    <th class="users-sortable" data-column-key="time_limit" data-sort-key="session_timeout" data-sort-type="duration">Limite de temps</th>
    <th class="users-sortable" data-column-key="session_total" data-sort-key="session_total_seconds" data-sort-type="duration">Durée cumulée</th>
    <th class="users-sortable" data-column-key="data_limit" data-sort-key="data_limit" data-sort-type="number">Limite de données</th>
    <th class="users-sortable" data-column-key="data_consumed" data-sort-key="data_consumed_bytes" data-sort-type="number">Data consommée</th>
    <th class="users-sortable" data-column-key="validity_profile" data-sort-key="validity" data-sort-type="text">Validité</th>
    <th class="users-sortable" data-column-key="expired_mode" data-sort-key="expired_mode" data-sort-type="text">Mode d'expiration</th>
    <th class="users-sortable" data-column-key="expiration" data-sort-key="expiration" data-sort-type="date">Expiration</th>
    <th class="users-sortable" data-column-key="status" data-sort-key="status" data-sort-type="text">Statut</th>
    <th data-column-key="action">Action</th>
</tr>
</thead>


<tbody>

<tr class="users-details-row">
    <td colspan="15">
        <div id="usersDetailsInlineSlot"></div>
    </td>
</tr>

<?php foreach ($users as $u): ?>
<?php
    $mikrotikServer = (string)($u['active_server'] ?? $u['server'] ?? '-');
    $mikrotikProfile = (string)($u['profile'] ?? '-');
    $mikrotikExpiration = formatMikrotikExpiration((string)($u['comment'] ?? ''));
    /* Limite de temps = quota user (limit-uptime routeur). Si absent, repli sur session-timeout profil (Time Limit), jamais sur la validite commerciale. */
    $mikrotikTimeLimit = trim((string)($u['limit_uptime'] ?? '')) !== '' ? (string)$u['limit_uptime'] : '-';
    $profileSessionTimeoutSeconds = (int)($u['profile_session_timeout_seconds'] ?? 0);
    $mikrotikProfileTimeLimitLabel = $profileSessionTimeoutSeconds > 0
        ? formatDurationCompactLabel($profileSessionTimeoutSeconds)
        : '-';
    if ($mikrotikTimeLimit === '-' && $profileSessionTimeoutSeconds > 0) {
        $mikrotikTimeLimit = $mikrotikProfileTimeLimitLabel;
    }
    $profileValiditySeconds = (int)($u['profile_validity_seconds'] ?? 0);
    $mikrotikValidityLabel = $profileValiditySeconds > 0
        ? formatProfileDurationLabel($profileValiditySeconds)
        : '-';
    /* MikroTik : quota brut depuis USER (limit-bytes-total), distinct de la consommation USER. */
    $mikrotikLimitBytes = (float)($u['limit_bytes_total'] ?? 0);
    $mikrotikDataConsumedBytes = (float)($u['user_bytes_total'] ?? 0);

    $mikrotikDataConsumedLabel = formatMikrotikBytesLimit($mikrotikDataConsumedBytes);

    $mikrotikRemainingBytes = $mikrotikLimitBytes > 0
        ? max(0, $mikrotikLimitBytes - $mikrotikDataConsumedBytes)
        : null;

    $mikrotikRemainingLabel = $mikrotikRemainingBytes === null
        ? 'Illimité'
        : formatMikrotikBytesLimit($mikrotikRemainingBytes);

    /* Limite de données = quota brut USER, pas le restant. */
    $mikrotikDataLimit = $mikrotikLimitBytes > 0
        ? formatMikrotikBytesLimit($mikrotikLimitBytes)
        : 'Illimité';
    if ($isMikrotikUsers) {
        $mikrotikRateLimit = trim((string)($u['rate_limit'] ?? '')) !== '' ? (string)$u['rate_limit'] : '-';
        $mikrotikSharedUsers = array_key_exists('shared_users', $u) && $u['shared_users'] !== null
            ? (string)$u['shared_users']
            : '-';
        /* Durée cumulée : user_session_time_seconds depuis /ip/hotspot/user (champ uptime routeur), pas active_uptime */
        $mikrotikSessionTotalSeconds = (int)($u['user_session_time_seconds'] ?? 0);
        $mikrotikSessionTotalLabel = $mikrotikSessionTotalSeconds > 0
            ? formatConsumedDurationLabel($mikrotikSessionTotalSeconds)
            : 'N/D';
        /* Uptime session courante (/ip/hotspot/active), distinct du temps utilisateur ci-dessus */
        $mikrotikCurrentUptimeLabel = !empty($u['online']) && trim((string)($u['active_uptime'] ?? '')) !== ''
            ? (string)$u['active_uptime']
            : '-';
    }
    $radiusServer = trim((string)($u['nas_shortname'] ?? '')) !== ''
        ? (string)$u['nas_shortname']
        : (trim((string)($u['nas_name'] ?? '')) !== '' ? (string)$u['nas_name'] : '-');
    $radiusValidity = formatProfileDurationLabel($u['validity_time'] ?? null);
    $radiusSessionTotalSeconds = (int)($u['session_total_seconds'] ?? 0);
    $radiusSessionTotal = formatConsumedDurationLabel($radiusSessionTotalSeconds);
    $radiusDataConsumedBytes = (float)($u['data_consumed_bytes'] ?? 0);
    $radiusDataConsumed = formatMikrotikBytesLimit($radiusDataConsumedBytes);
    $radiusConsumedMegabytes = (int)round($radiusDataConsumedBytes / 1024 / 1024);
    $radiusCreditSeconds = max(0, (int)($u['current_credit_time'] ?? 0));
    $radiusCreditMegabytes = normalizeStoredCreditDataToMegabytes((int)($u['current_credit_data'] ?? 0));
    $radiusRemainingSeconds = max(0, $radiusCreditSeconds - $radiusSessionTotalSeconds);
    $radiusRemainingMegabytes = max(0, $radiusCreditMegabytes - $radiusConsumedMegabytes);
    $radiusTimeLimit = formatConsumedDurationLabel($radiusRemainingSeconds);
    $radiusDataLimit = formatQuotaMbLabel($radiusRemainingMegabytes);
    $rowExpiredMode = trim((string)($u['expired_mode'] ?? '')) !== '' ? (string)$u['expired_mode'] : '-';
    $priceLabel = formatMoneyLabel($u['price'] ?? null);
    $sellingPriceLabel = formatMoneyLabel($u['selling_price'] ?? null);
    $nowUtc = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $isExpired = false;
    $computedExpirationDate = null;
    if (!$isMikrotikUsers) {
        $explicitExpiration = trim((string)($u['expiration_date'] ?? ''));
        if ($explicitExpiration !== '') {
            $computedExpirationDate = $explicitExpiration;
            try {
                $isExpired = new DateTimeImmutable($computedExpirationDate, new DateTimeZone('UTC')) < $nowUtc;
            } catch (Throwable $e) {
                $computedExpirationDate = null;
                $isExpired = false;
            }
        }

    } else {
        $mikrotikExpirationRaw = trim((string)$mikrotikExpiration);
        if ($mikrotikExpirationRaw !== '' && $mikrotikExpirationRaw !== '-') {
            try {
                $mikrotikExpirationDate = new DateTimeImmutable($mikrotikExpirationRaw, new DateTimeZone('UTC'));
                $isExpired = $mikrotikExpirationDate < $nowUtc;
            } catch (Throwable $e) {
                $isExpired = false;
            }
        }
    }
    $isDisabled = $isMikrotikUsers ? (bool)($u['disabled'] ?? false) : ((string)($u['status'] ?? '') === 'disabled');
    $statusValue = $isDisabled ? 'disabled' : ($isExpired ? 'expired' : 'active');
    $statusLabel = $statusValue === 'active' ? 'ACTIVE' : ($statusValue === 'expired' ? 'EXPIRE' : 'DESACTIVE');
    $statusBadgeClass = $statusValue === 'active' ? 'bg-success' : ($statusValue === 'expired' ? 'bg-warning' : 'bg-secondary');
?>

<tr class="user-row"

    data-id="<?= htmlspecialchars((string)($u['id'] ?? '')) ?>"
    data-username="<?= htmlspecialchars($u['username']) ?>"
    data-password="<?= htmlspecialchars((string)($u['password'] ?? '')) ?>"
    data-profile_id="<?= $isMikrotikUsers ? '' : (int)$u['profile_id'] ?>"
    data-nas_id="<?= $isMikrotikUsers ? '' : (int)($u['nas_id'] ?? 0) ?>"
    data-account_type="<?= htmlspecialchars($isMikrotikUsers ? 'MikroTik' : ($u['account_type'] ?? '')) ?>"
    data-fullname="<?= htmlspecialchars($u['fullname'] ?? ($u['comment'] ?? '')) ?>"
    data-phone="<?= htmlspecialchars($u['phone'] ?? '') ?>"
    data-address="<?= htmlspecialchars($u['address'] ?? '') ?>"
    data-email="<?= htmlspecialchars($u['email'] ?? '') ?>"
    data-service="<?= htmlspecialchars($u['service_type'] ?? ($u['server'] ?? '')) ?>"
    data-server_display="<?= htmlspecialchars($isMikrotikUsers ? $mikrotikServer : $radiusServer) ?>"
    data-plan="<?= htmlspecialchars($isMikrotikUsers ? $mikrotikProfile : ($u['plan'] ?? ($u['profile'] ?? ''))) ?>"
    data-rate_limit="<?= htmlspecialchars($isMikrotikUsers ? $mikrotikRateLimit : ($u['rate_limit'] ?? '')) ?>"
    data-session_timeout="<?= htmlspecialchars((string)($isMikrotikUsers ? (($u['limit_uptime'] ?? '') ?: ($profileSessionTimeoutSeconds > 0 ? $profileSessionTimeoutSeconds : '')) : $radiusRemainingSeconds)) ?>"
    data-idle_timeout="<?= htmlspecialchars((string)($u['idle_timeout'] ?? '')) ?>"
    data-simultaneous_use="<?= htmlspecialchars((string)($isMikrotikUsers ? $mikrotikSharedUsers : ($u['simultaneous_use'] ?? ''))) ?>"
    data-data_limit="<?= htmlspecialchars((string)($isMikrotikUsers ? ($mikrotikRemainingBytes ?? '') : $radiusRemainingMegabytes)) ?>"

    data-validity="<?= htmlspecialchars((string)($u['validity_time'] ?? '')) ?>"
    data-rate_limit_label="<?= htmlspecialchars($isMikrotikUsers ? $mikrotikRateLimit : ($u['rate_limit'] ?? '-')) ?>"
    data-shared_users_label="<?= htmlspecialchars($isMikrotikUsers ? $mikrotikSharedUsers : ((string)($u['simultaneous_use'] ?? '-'))) ?>"
    data-time_limit_label="<?= htmlspecialchars($isMikrotikUsers ? $mikrotikTimeLimit : $radiusTimeLimit) ?>"
    data-profile_time_limit_label="<?= htmlspecialchars($isMikrotikUsers ? $mikrotikProfileTimeLimitLabel : '-') ?>"
    data-session_total_label="<?= htmlspecialchars($isMikrotikUsers ? $mikrotikSessionTotalLabel : $radiusSessionTotal) ?>"
    data-current_session_uptime_label="<?= htmlspecialchars($isMikrotikUsers ? $mikrotikCurrentUptimeLabel : '') ?>"
    data-validity_label="<?= htmlspecialchars($isMikrotikUsers ? $mikrotikValidityLabel : $radiusValidity) ?>"
    data-data_limit_label="<?= htmlspecialchars($isMikrotikUsers ? $mikrotikDataLimit : $radiusDataLimit) ?>"
    data-data_consumed_label="<?= htmlspecialchars($isMikrotikUsers ? $mikrotikDataConsumedLabel : $radiusDataConsumed) ?>"
    data-expired_mode="<?= htmlspecialchars($rowExpiredMode) ?>"
    data-price="<?= htmlspecialchars((string)($u['price'] ?? '')) ?>"
    data-price_label="<?= htmlspecialchars($priceLabel) ?>"
    data-selling_price="<?= htmlspecialchars((string)($u['selling_price'] ?? '')) ?>"
    data-selling_price_label="<?= htmlspecialchars($sellingPriceLabel) ?>"
    data-expiration="<?= htmlspecialchars((string)($isMikrotikUsers ? $mikrotikExpiration : ($computedExpirationDate ?? ''))) ?>"
    data-status="<?= htmlspecialchars($statusValue) ?>"
    data-balance="<?= htmlspecialchars((string)($u['balance'] ?? '0')) ?>"
    data-created_at="<?= htmlspecialchars((string)(
        $isMikrotikUsers
            ? ($mikrotikCreationByUser[$u['username']] ?? '')
            : ($u['created_at'] ?? '')
    )) ?>"
    data-last_login="<?= htmlspecialchars((string)($u['last_login'] ?? (($u['online'] ?? false) ? 'En ligne' : 'Hors ligne'))) ?>"
    data-online="<?= htmlspecialchars((string)(($u['online'] ?? false) ? '1' : '0')) ?>"
    data-ip="<?= htmlspecialchars((string)($u['active_address'] ?? $u['framedipaddress'] ?? '')) ?>"
    data-mac="<?= htmlspecialchars((string)($u['active_mac'] ?? $u['callingstationid'] ?? '')) ?>"
    data-nas="<?= htmlspecialchars((string)($isMikrotikUsers ? ($u['active_server'] ?? $u['server'] ?? '') : ($u['nas_name'] ?? ''))) ?>"
    data-auto_renewal="<?= htmlspecialchars((string)($u['auto_renewal'] ?? '0')) ?>"
    data-readonly="<?= $isMikrotikUsers ? '1' : '0' ?>"
    data-session_total_seconds="<?= htmlspecialchars((string)($isMikrotikUsers ? ($mikrotikSessionTotalSeconds ?? 0) : $radiusSessionTotalSeconds)) ?>"
    data-data_consumed_bytes="<?= htmlspecialchars((string)($isMikrotikUsers ? $mikrotikDataConsumedBytes : $radiusDataConsumedBytes)) ?>"
>
    <td data-column-key="id"<?= $isMikrotikUsers ? ' class="d-none"' : '' ?>><?= htmlspecialchars((string)($u['id'] ?? '')) ?></td>
    <td class="users-col-left" data-column-key="server"><?= htmlspecialchars($isMikrotikUsers ? ($mikrotikServer !== '' ? $mikrotikServer : '-') : $radiusServer) ?></td>
    <td class="users-col-left" data-column-key="username"><?= htmlspecialchars($u['username']) ?></td>
    <td data-column-key="plan"><?= htmlspecialchars($isMikrotikUsers ? ($mikrotikProfile !== '' ? $mikrotikProfile : '-') : ($u['plan'] ?? ($u['profile'] ?? '-'))) ?></td>
    <td data-column-key="rate_limit"><?= htmlspecialchars($isMikrotikUsers ? $mikrotikRateLimit : ($u['rate_limit'] ?? '-')) ?></td>
    <td data-column-key="shared_users"><?= htmlspecialchars($isMikrotikUsers ? $mikrotikSharedUsers : ((string)($u['simultaneous_use'] ?? '-'))) ?></td>
    <td class="text-end" data-column-key="time_limit"><?= htmlspecialchars($isMikrotikUsers ? $mikrotikTimeLimit : $radiusTimeLimit) ?></td>
    <td class="text-end" data-column-key="session_total"><?= htmlspecialchars($isMikrotikUsers ? $mikrotikSessionTotalLabel : $radiusSessionTotal) ?></td>
    <td class="text-end" data-column-key="data_limit"><?= htmlspecialchars($isMikrotikUsers ? $mikrotikDataLimit : $radiusDataLimit) ?></td>
    <td class="text-end" data-column-key="data_consumed"><?= htmlspecialchars($isMikrotikUsers ? $mikrotikDataConsumedLabel : $radiusDataConsumed) ?></td>
    <td data-column-key="validity_profile"><?= htmlspecialchars($isMikrotikUsers ? $mikrotikValidityLabel : $radiusValidity) ?></td>
    <td data-column-key="expired_mode"><?= htmlspecialchars($isMikrotikUsers ? '-' : $rowExpiredMode) ?></td>
    <td data-column-key="expiration">
        <?= htmlspecialchars($isMikrotikUsers ? $mikrotikExpiration : ($computedExpirationDate ? date('Y-m-d', strtotime($computedExpirationDate)) : '-')) ?>
    </td>
    <td data-column-key="status">
        <span class="badge <?= $statusBadgeClass ?>">
            <?= htmlspecialchars($statusLabel) ?>
        </span>
    </td>
    <td class="action-cell" data-column-key="action">
        <button type="button" class="btn btn-test btn-sm user-action-btn" title="Voir le detail">
            <i class="fa fa-eye"></i>
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
</div>

<!-- =========================
     RIGHT : USER DETAILS
========================= -->
<div class="col-12 col-lg-6 mb-3 d-none users-details-column" id="userDetailsColumn">
<div class="card shadow-sm" id="userDetailsCard">
<div class="card-body">

<div class="text-center text-muted mb-3" id="emptyState">
    <i class="fa fa-user fa-2x mb-2"></i>
    <p>Selectionner un utilisateur</p>
</div>

<?php if (!$canManageUsersFromList): ?>
<div class="alert alert-info py-2 px-3 mb-3 small" role="alert">
    Consultation uniquement. Les actions de modification, recharge et suppression sont réservées à l administrateur.
</div>
<?php endif; ?>

<form id="userContent">
<input type="hidden" id="user_id" name="id">
<input type="hidden" id="nas_id" name="nas_id">
<input type="hidden" id="fullname" name="fullname">
<input type="hidden" id="phone" name="phone">
<input type="hidden" id="address" name="address">
<input type="hidden" id="email" name="email">
<input type="hidden" id="balance" name="balance" value="0">
<input type="hidden" id="auto_renewal" name="auto_renewal" value="0">
<input type="hidden" id="rate_limit" name="rate_limit">
<input type="hidden" id="session_timeout" name="session_timeout">
<input type="hidden" id="idle_timeout" name="idle_timeout">
<input type="hidden" id="simultaneous_use" name="simultaneous_use">
<input type="hidden" id="data_limit" name="data_limit">
<input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">

<!-- HEADER ACTIONS -->
<div class="d-flex justify-content-between align-items-center mb-3 user-detail-sticky-header">
    <h6 class="text-white mt-0 mb-0">
    <i class="fa fa-id-card me-2"></i> Compte utilisateur <span class="text-white-50" id="selectedUsernameLabel">-</span>
    </h6>

    <div class="d-flex gap-2<?= $canManageUsersFromList ? '' : ' d-none' ?>" id="userActionBar">
        <button type="button" class="btn btn-test" id="reloadBtn">
            <i class="fa fa-rotate-right me-1"></i> Recharger
        </button>
        <button type="button" class="btn btn-test" id="editBtn">
            <i class="fa fa-edit me-1"></i> Modifier
        </button>
        <button type="button" class="btn btn-delete" id="deleteBtn">
            <i class="fa fa-trash me-1"></i> Supprimer
        </button>

        <button type="button" class="btn btn-save d-none" id="saveBtn">
            <i class="fa fa-save me-1"></i> Enregistrer
        </button>

        <button type="button" class="btn btn-test d-none" id="cancelBtn">
            <i class="fa fa-times me-1"></i> Annuler
        </button>
    </div>


</div>

    <div class="users-details-layout row g-3" id="usersDetailsContent">
    <div class="col-12 col-lg-6 users-details-left">
        <div class="users-summary-grid users-summary-grid-compact mb-3">
            <div class="users-summary-card"><span class="users-summary-label">Date de création</span><span class="users-summary-value" id="summary_created_at">-</span></div>
            <div class="users-summary-card"><span class="users-summary-label">Profil</span><span class="users-summary-value" id="summary_profile">-</span></div>
            <div class="users-summary-card"><span class="users-summary-label">Limite de temps</span><span class="users-summary-value" id="summary_time">-</span></div>
            <div class="users-summary-card"><span class="users-summary-label">Limite de données</span><span class="users-summary-value" id="summary_data_limit">-</span></div>
            <div class="users-summary-card"><span class="users-summary-label">Durée cumulée</span><span class="users-summary-value" id="summary_session_total">-</span></div>
            <div class="users-summary-card"><span class="users-summary-label">Data consommée</span><span class="users-summary-value" id="summary_data">-</span></div>
            <div class="users-summary-card"><span class="users-summary-label">Expiration</span><span class="users-summary-value" id="summary_expiration">-</span></div>
            <div class="users-summary-card"><span class="users-summary-label">Statut</span><span class="users-summary-value" id="summary_status">-</span></div>
        </div>

        <div class="row g-3 users-details-fields">
            <div class="col-12 col-md-6">
                <div class="card user-detail-block h-100">
                    <div class="card-body py-3">
                        <div class="input-group mb-2">
                            <span class="input-group-text">Serveur</span>
                            <input type="text" class="form-control" id="server" disabled>
                        </div>
                        <div class="input-group mb-2">
                            <span class="input-group-text">Nom</span>
                            <input type="text" class="form-control editable-only" id="username" name="username" disabled>
                        </div>
                        <div class="input-group mb-2">
                            <span class="input-group-text">Mot de passe</span>
                            <input type="text" class="form-control editable-only" id="password" name="password" disabled>
                        </div>
                        <div class="input-group mb-2">
                            <span class="input-group-text">Date de création</span>
                            <input type="text" class="form-control" id="created_at_display" disabled>
                        </div>
                        <div class="input-group mb-2">
                            <span class="input-group-text">Profil</span>
                            <select class="form-select" id="profile_id" name="profile_id" disabled>
                                <option value="">-- Choisir un profil --</option>
                                <?php foreach ($profileOptions as $profileOption): ?>
                                <option
                                    value="<?= (int)$profileOption['id'] ?>"
                                    data-plan="<?= htmlspecialchars($profileOption['name']) ?>"
                                    data-service="<?= htmlspecialchars($profileOption['service_type'] ?? '') ?>"
                                    data-rate_limit="<?= htmlspecialchars($profileOption['rate_limit'] ?? '') ?>"
                                    data-session_timeout="<?= htmlspecialchars((string)($profileOption['session_timeout'] ?? '')) ?>"
                                    data-idle_timeout="<?= htmlspecialchars((string)($profileOption['idle_timeout'] ?? '')) ?>"
                                    data-simultaneous_use="<?= htmlspecialchars((string)($profileOption['simultaneous_use'] ?? '')) ?>"
                                    data-data_limit="<?= htmlspecialchars((string)($profileOption['data_quota_mb'] ?? '')) ?>"
                                    data-account_type="<?= htmlspecialchars($profileOption['account_type'] ?? '') ?>"
                                >
                                    <?= htmlspecialchars($profileOption['name']) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="input-group mb-0">
                            <span class="input-group-text">Statut</span>
                            <select class="form-select editable-only" id="status" name="status" disabled>
                                <option value="">-</option>
                                <option value="active">ACTIVE</option>
                                <option value="expired" disabled>EXPIRE</option>
                                <option value="disabled">DESACTIVE</option>
                            </select>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-12 col-md-6">
                <div class="card user-detail-block h-100">
                    <div class="card-body py-3">
                        <div class="input-group mb-2">
                            <span class="input-group-text">Expiration</span>
                            <input type="text" class="form-control" id="expiration" name="expiration_date" readonly>
                        </div>
                        <div class="input-group mb-2">
                            <span class="input-group-text">Limite de temps</span>
                            <input type="text" class="form-control" id="time_limit_display" disabled>
                        </div>
                        <div class="input-group mb-2">
                            <span class="input-group-text">Limite de données</span>
                            <input type="text" class="form-control" id="data_limit_display" disabled>
                        </div>
                        <div class="input-group mb-2">
                            <span class="input-group-text">Durée cumulée</span>
                            <input type="text" class="form-control" id="session_total_display" disabled>
                        </div>
                        <div class="input-group mb-2">
                            <span class="input-group-text">Data consommée</span>
                            <input type="text" class="form-control" id="data_consumed_display" disabled>
                        </div>
                        <div class="input-group mb-2">
                            <span class="input-group-text">Limite de débit</span>
                            <input type="text" class="form-control" id="rate_limit_display" disabled>
                        </div>
                        <div class="input-group mb-2">
                            <span class="input-group-text">Partagés</span>
                            <input type="text" class="form-control" id="shared_users_display" disabled>
                        </div>
                        <div class="input-group mb-2<?= $isMikrotikUsers ? '' : ' d-none' ?>" id="priceDisplayWrap">
                            <span class="input-group-text">Prix</span>
                            <input type="text" class="form-control" id="price_display" disabled>
                        </div>
                        <div class="input-group mb-0<?= $isMikrotikUsers ? '' : ' d-none' ?>" id="sellingPriceDisplayWrap">
                            <span class="input-group-text">Prix de vente</span>
                            <input type="text" class="form-control" id="selling_price_display" disabled>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="col-12 col-lg-6 users-details-right">
        <div class="card user-detail-block h-100">
            <div class="card-header py-2">
                <i class="fa fa-clock me-2"></i> Sessions
            </div>
            <div class="card-body py-3">
                <div class="table-responsive mt-0">
                    <table class="table users-table table-hover align-middle small text-nowrap">
                        <thead>
                        <tr>
                            <th>Début</th>
                            <th>Fin</th>
                            <th>Durée</th>
                            <th>Données</th>
                            <th>IP</th>
                        </tr>
                        </thead>
                        <tbody id="sessionsTable">
                        <tr>
                            <td colspan="5" class="text-center">Aucune session</td>
                        </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<input type="hidden" id="account_type">
<input type="hidden" id="service">
<input type="hidden" id="plan">
<input type="hidden" id="created_at">
<input type="hidden" id="last_login">
<input type="hidden" id="online">
<input type="hidden" id="uptime_display">
<input type="hidden" id="ip">
<input type="hidden" id="mac">
<input type="hidden" id="data_usage">
<input type="hidden" id="nas">
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


<?php
$extraJs = array (
  0 => '../js/table_sort.js',
  1 => '../js/users_list.js?v=20260404i',
);
require_once '../includes/layout_footer.php';
?>
