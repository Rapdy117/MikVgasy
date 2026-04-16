<?php
session_start();
require '../config/db.php';
require_once '../includes/auth.php';
require_once '../includes/app_context.php';
require_once '../includes/device_manager.php';
require_once '../includes/formatters.php';
require_once '../includes/mikrotik_backend.php';
require_once '../includes/page_helpers.php';
require_once '../includes/profile_schema.php';

/* SECURITY */
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: ../index.php');
    exit();
}
requireAdministratorAccess();
ensureProfilesExtendedSchema($pdo);

/* CSRF */
$csrfToken = ensureCsrfToken();

$input = [
    'profile_id' => isset($_GET['profile_id']) ? (int)$_GET['profile_id'] : 0,
    'profile_name' => trim((string)($_GET['profile_name'] ?? '')),
    'device_id' => trim((string)($_GET['device_id'] ?? '')),
    'data_quota_mb' => isset($_GET['data_quota_mb']) ? (int)$_GET['data_quota_mb'] : 0,
    'session_timeout' => isset($_GET['session_timeout']) ? (int)$_GET['session_timeout'] : 0,
];
$hasDataQuotaInQuery = array_key_exists('data_quota_mb', $_GET);
$hasSessionTimeoutInQuery = array_key_exists('session_timeout', $_GET);
$context = [
    'app' => buildAppContext(),
];
$deviceStore = loadDeviceStore();

$isEditMode = false;
$profileId = $input['profile_id'];
$profileNameFromQuery = $input['profile_name'];
$deviceIdFromQuery = $input['device_id'];
$dataQuotaFromQuery = $input['data_quota_mb'];
$sessionTimeoutFromQuery = $input['session_timeout'];
$formTitle = 'Ajouter Profil';
$submitLabel = 'Enregistrer';
$activeDevice = $context['app']['device'] ?? null;
if (is_array($activeDevice) && trim((string)($activeDevice['id'] ?? '')) !== '') {
    $fullActiveDevice = findDeviceById($deviceStore, (string)$activeDevice['id']);
    if (is_array($fullActiveDevice)) {
        $activeDevice = $fullActiveDevice;
    }
}
$activeDeviceType = strtolower(trim((string)($activeDevice['type'] ?? 'other')));
$profileFlowLabel = 'Profil local';
$profileFlowDescription = 'Le profil est stocke localement et sera applique selon le backend du serveur selectionne.';
$profileAdvancedTitle = 'Options avancees';
$profileAdvancedDescription = 'Ces options complementaires dependent du type de serveur selectionne.';
$showMikrotikHints = false;
$mikrotikAdvancedDisabled = 'disabled';
$profileFormData = [
    'profile_id' => 0,
    'old_profile_name' => '',
    'device_id' => '',
    'profile_name' => '',
    'rate_upload_value' => '',
    'rate_upload_unit' => 'M',
    'rate_download_value' => '',
    'rate_download_unit' => 'M',
    'simultaneous_use' => '1',
    'session_timeout_value' => '',
    'session_timeout_unit' => 'hours',
    'validity_value' => '',
    'validity_unit' => 'hours',
    'data_quota_mb' => '',
    'expired_mode' => 'none',
    'grace_period_value' => '',
    'grace_period_unit' => 'minutes',
    'price' => '',
    'selling_price' => '',
    'address_pool' => '',
    'lock_user' => '0',
    'parent_queue' => '',
];

if ($deviceIdFromQuery !== '') {
    $profileFormData['device_id'] = $deviceIdFromQuery;
}
if ($hasDataQuotaInQuery && $dataQuotaFromQuery >= 0) {
    $profileFormData['data_quota_mb'] = (string)$dataQuotaFromQuery;
}
if ($hasSessionTimeoutInQuery && $sessionTimeoutFromQuery >= 0) {
    $sessionTimeoutQueryParts = splitSecondsToDurationParts($sessionTimeoutFromQuery);
    $profileFormData['session_timeout_value'] = $sessionTimeoutFromQuery === 0
        ? '0'
        : (string)($sessionTimeoutQueryParts['value'] ?? '');
    $profileFormData['session_timeout_unit'] = (string)($sessionTimeoutQueryParts['unit'] ?? 'hours');
}

$requestedDevice = null;
if ($deviceIdFromQuery !== '') {
    $requestedDevice = findDeviceById($deviceStore, $deviceIdFromQuery);
}
$editDevice = is_array($requestedDevice) ? $requestedDevice : $activeDevice;
$editDeviceType = strtolower(trim((string)($editDevice['type'] ?? $activeDeviceType)));

if ($profileId > 0 || $profileNameFromQuery !== '') {
    $profileRow = null;

    if ($editDeviceType === 'mikrotik' && $profileNameFromQuery !== '') {
        $profileRow = [
            'id' => 0,
            'name' => $profileNameFromQuery,
        ];
    } elseif ($profileId > 0) {
        $stmt = $pdo->prepare("
            SELECT id, name, rate_limit, session_timeout, validity_time, data_quota_mb, simultaneous_use, ip_pool, expired_mode, grace_period, price, selling_price, lock_user, parent_queue, validity_routeros, grace_period_routeros
            FROM profiles
            WHERE id = ?
            LIMIT 1
        ");
        $stmt->execute([$profileId]);
        $profileRow = $stmt->fetch(PDO::FETCH_ASSOC);
    } elseif ($profileNameFromQuery !== '') {
        $stmt = $pdo->prepare("
            SELECT id, name, rate_limit, session_timeout, validity_time, data_quota_mb, simultaneous_use, ip_pool, expired_mode, grace_period, price, selling_price, lock_user, parent_queue, validity_routeros, grace_period_routeros
            FROM profiles
            WHERE name = ?
            LIMIT 1
        ");
        $stmt->execute([$profileNameFromQuery]);
        $profileRow = $stmt->fetch(PDO::FETCH_ASSOC) ?: [
            'id' => 0,
            'name' => $profileNameFromQuery,
            'rate_limit' => null,
            'session_timeout' => null,
            'validity_time' => null,
            'data_quota_mb' => null,
            'simultaneous_use' => 1,
            'ip_pool' => null,
            'expired_mode' => 'none',
            'grace_period' => null,
            'price' => null,
            'selling_price' => null,
            'lock_user' => 0,
            'parent_queue' => null,
            'validity_routeros' => null,
            'grace_period_routeros' => null,
        ];
    }

    if ($profileRow) {
        $isEditMode = true;
        $formTitle = 'Modifier Profil';
        $submitLabel = 'Mettre a jour';

        $rateParts = splitRateLimit((string)($profileRow['rate_limit'] ?? ''));
        $sessionTimeoutParts = splitSecondsToDurationParts(isset($profileRow['session_timeout']) ? (int)$profileRow['session_timeout'] : null);
        $validityParts = splitSecondsToDurationParts(isset($profileRow['validity_time']) ? (int)$profileRow['validity_time'] : null);

        $profileFormData['profile_id'] = (int)($profileRow['id'] ?? 0);
        $profileFormData['old_profile_name'] = (string)$profileRow['name'];
        $profileFormData['profile_name'] = (string)$profileRow['name'];
        $profileFormData['rate_upload_value'] = $rateParts['upload_value'];
        $profileFormData['rate_upload_unit'] = $rateParts['upload_unit'];
        $profileFormData['rate_download_value'] = $rateParts['download_value'];
        $profileFormData['rate_download_unit'] = $rateParts['download_unit'];
        $profileFormData['simultaneous_use'] = (string)((int)($profileRow['simultaneous_use'] ?? 1));
        if (isset($profileRow['session_timeout']) && (int)$profileRow['session_timeout'] > 0) {
            $profileFormData['session_timeout_value'] = $sessionTimeoutParts['value'];
            $profileFormData['session_timeout_unit'] = $sessionTimeoutParts['unit'];
        }
        $profileFormData['validity_value'] = $validityParts['value'];
        $profileFormData['validity_unit'] = $validityParts['unit'];
        if (isset($profileRow['data_quota_mb']) && (int)$profileRow['data_quota_mb'] > 0) {
            $profileFormData['data_quota_mb'] = (string)((int)$profileRow['data_quota_mb']);
        }
        $profileFormData['address_pool'] = (string)($profileRow['ip_pool'] ?? '');
        $profileFormData['expired_mode'] = trim((string)($profileRow['expired_mode'] ?? '')) !== '' ? (string)$profileRow['expired_mode'] : 'none';
        $profileFormData['price'] = isset($profileRow['price']) && $profileRow['price'] !== null ? (string)$profileRow['price'] : '';
        $profileFormData['selling_price'] = isset($profileRow['selling_price']) && $profileRow['selling_price'] !== null ? (string)$profileRow['selling_price'] : '';
        $profileFormData['lock_user'] = (string)((int)($profileRow['lock_user'] ?? 0));
        $profileFormData['parent_queue'] = (string)($profileRow['parent_queue'] ?? '');

        $gracePeriodParts = splitSecondsToDurationParts(isset($profileRow['grace_period']) ? (int)$profileRow['grace_period'] : null);
        $profileFormData['grace_period_value'] = $gracePeriodParts['value'];
        $profileFormData['grace_period_unit'] = match ($gracePeriodParts['unit']) {
            'months' => 'days',
            default => $gracePeriodParts['unit'],
        };

    }
}

try {
    if ($profileFormData['device_id'] !== '') {
        $requestedDevice = findDeviceById($deviceStore, (string)$profileFormData['device_id']);
        if (is_array($requestedDevice)) {
            $activeDevice = $requestedDevice;
        }
    }

    if (is_array($activeDevice)) {
        $activeDeviceType = strtolower(trim((string)($activeDevice['type'] ?? 'other')));
        if ($profileFormData['device_id'] === '') {
            $profileFormData['device_id'] = (string)($activeDevice['id'] ?? '');
        }
    }

    if ($activeDeviceType === 'mikrotik' && $profileFormData['profile_name'] !== '') {
        $routerProfile = null;
        foreach (loadMikrotikHotspotProfilesCached($activeDevice, 60) as $candidateProfile) {
            if ((string)($candidateProfile['name'] ?? '') === (string)$profileFormData['profile_name']) {
                $routerProfile = $candidateProfile;
                break;
            }
        }

        if (is_array($routerProfile)) {
            $profileFormData['expired_mode'] = trim((string)($routerProfile['expired_mode'] ?? '')) !== '' ? (string)$routerProfile['expired_mode'] : $profileFormData['expired_mode'];
            $profileFormData['price'] = isset($routerProfile['price']) && $routerProfile['price'] !== null ? (string)$routerProfile['price'] : '';
            $profileFormData['selling_price'] = isset($routerProfile['selling_price']) && $routerProfile['selling_price'] !== null ? (string)$routerProfile['selling_price'] : '';
            if (($routerProfile['lock_user'] ?? null) !== null && (string)$routerProfile['lock_user'] !== '') {
                $profileFormData['lock_user'] = ((string)$routerProfile['lock_user'] === 'Enable' || (int)$routerProfile['lock_user'] === 1) ? '1' : '0';
            }
            $profileFormData['address_pool'] = trim((string)($routerProfile['ip_pool'] ?? ''));
            $profileFormData['parent_queue'] = trim((string)($routerProfile['parent_queue'] ?? ''));

            $routerRateParts = splitRateLimit((string)($routerProfile['rate_limit'] ?? ''));
            $profileFormData['rate_upload_value'] = $routerRateParts['upload_value'];
            $profileFormData['rate_upload_unit'] = $routerRateParts['upload_unit'];
            $profileFormData['rate_download_value'] = $routerRateParts['download_value'];
            $profileFormData['rate_download_unit'] = $routerRateParts['download_unit'];

            $sessionTimeoutSeconds = (int)($routerProfile['session_timeout'] ?? 0);
            if ($sessionTimeoutSeconds > 0) {
                $sessionTimeoutParts = splitSecondsToDurationParts($sessionTimeoutSeconds);
                $profileFormData['session_timeout_value'] = $sessionTimeoutParts['value'];
                $profileFormData['session_timeout_unit'] = $sessionTimeoutParts['unit'];
            } else {
                $profileFormData['session_timeout_value'] = '0';
                $profileFormData['session_timeout_unit'] = 'hours';
            }

            $validitySeconds = (int)($routerProfile['validity_time'] ?? 0);
            if ($validitySeconds > 0) {
                $validityParts = splitSecondsToDurationParts($validitySeconds);
                $profileFormData['validity_value'] = $validityParts['value'];
                $profileFormData['validity_unit'] = $validityParts['unit'];
            } else {
                $profileFormData['validity_value'] = '';
                $profileFormData['validity_unit'] = 'hours';
            }

            $dataQuotaMb = (int)($routerProfile['data_quota_mb'] ?? 0);
            if ($dataQuotaMb > 0) {
                $profileFormData['data_quota_mb'] = (string)$dataQuotaMb;
            } else {
                $profileFormData['data_quota_mb'] = '0';
            }
        }
    }
} catch (Throwable $e) {
    if ($activeDeviceType !== 'mikrotik') {
        // pour les flux SQL, on conserve le formulaire local
    }
}

if ($activeDeviceType === 'mikrotik') {
    $profileFlowLabel = 'Profil MikroTik';
    $profileFlowDescription = 'Le profil est gere localement puis applique directement au routeur MikroTik actif.';
    $profileAdvancedTitle = 'Options avancees MikroTik';
    $profileAdvancedDescription = 'Ces options sont appliquees directement sur le routeur MikroTik.';
    $showMikrotikHints = true;
    $mikrotikAdvancedDisabled = '';
} elseif ($activeDeviceType === 'opnsense') {
    $profileFlowLabel = 'Profil local synchronise vers RADIUS';
    $profileFlowDescription = 'Le profil est stocke localement, puis synchronise dans FreeRADIUS pour le serveur OPNsense selectionne.';
    $profileAdvancedTitle = 'Options avancees RADIUS';
    $profileAdvancedDescription = 'Pour OPNsense, les debits, quotas et validites passent par la synchronisation FreeRADIUS.';
} elseif (in_array($activeDeviceType, ['radius', 'freeradius', 'other', 'ubiquiti', 'tplink', 'tenda'], true)) {
    $profileFlowLabel = 'Profil local synchronise vers RADIUS';
    $profileFlowDescription = 'Le profil est stocke localement puis synchronise vers le backend FreeRADIUS du serveur selectionne.';
    $profileAdvancedTitle = 'Options avancees RADIUS';
    $profileAdvancedDescription = 'Les attributs de debit, quota et validite sont appliques via la synchronisation FreeRADIUS.';
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<title><?= htmlspecialchars($formTitle) ?></title>

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="../css/theme.css">
<link rel="stylesheet" href="../css/add_profile.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">

</head>

<body
    data-active-device-id="<?= htmlspecialchars((string)($context['app']['device']['id'] ?? ''), ENT_QUOTES) ?>"
    data-active-device-type="<?= htmlspecialchars((string)($context['app']['device']['type'] ?? 'other'), ENT_QUOTES) ?>"
>

<div class="d-flex" id="wrapper">

<?php include_once '../includes/sidebar.php'; ?>

<div id="page-content-wrapper">
<div class="container-fluid py-3">

<form id="profileForm" class="network-device-form" method="POST" action="../api/profiles/create_profile.php" autocomplete="off" data-profile-id="<?= (int)$profileFormData['profile_id'] ?>" data-initial-device-id="<?= htmlspecialchars((string)$profileFormData['device_id'], ENT_QUOTES) ?>" data-initial-address-pool="<?= htmlspecialchars((string)$profileFormData['address_pool'], ENT_QUOTES) ?>" data-initial-parent-queue="<?= htmlspecialchars((string)$profileFormData['parent_queue'], ENT_QUOTES) ?>">

<input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
<input type="hidden" name="profile_id" value="<?= (int)$profileFormData['profile_id'] ?>">
<input type="hidden" name="old_profile_name" value="<?= htmlspecialchars((string)$profileFormData['old_profile_name']) ?>">
<input type="hidden" name="nas_id" id="nasIdInput" value="">

<div class="row align-items-stretch">
<div class="col-md-6 d-flex">
<div class="card h-100 w-100">
<div class="card-header">
    <i class="fa fa-pie-chart me-2"></i> <?= htmlspecialchars($formTitle) ?>
</div>

<div class="card-body">

<div class="alert alert-info profile-flow-alert py-2 px-3 mb-3" id="profileFlowAlert" data-active-device-type="<?= htmlspecialchars($activeDeviceType, ENT_QUOTES) ?>">
    <div class="small fw-semibold" id="profileFlowLabel"><?= htmlspecialchars($profileFlowLabel) ?></div>
    <div class="small mb-0" id="profileFlowDescription"><?= htmlspecialchars($profileFlowDescription) ?></div>
</div>

<div class="input-group">
<span class="input-group-text">Serveur</span>
<select class="form-select" name="device_id" id="nasSelect" required>
    <option value="">-- Choisir un serveur --</option>
</select>
</div>

<div class="input-group">
<span class="input-group-text">Nom</span>
<input type="text" class="form-control" name="profile_name" value="<?= htmlspecialchars((string)$profileFormData['profile_name']) ?>" required>
</div>

<div class="input-group">
<span class="input-group-text">Rate Limit</span>
<input type="hidden" name="rate_limit" id="profileRateLimitInput">
<span class="input-group-text rate-part-label">UPLOAD</span>
<input type="number" class="form-control profile-number-input rate-value-input" name="rate_upload_value" id="rateUploadValueInput" min="0" placeholder="0" value="<?= htmlspecialchars((string)$profileFormData['rate_upload_value']) ?>">
<select class="form-select profile-unit-select rate-unit-select" name="rate_upload_unit" id="rateUploadUnitSelect">
    <option value="K" <?= $profileFormData['rate_upload_unit'] === 'K' ? 'selected' : '' ?>>KB</option>
    <option value="M" <?= $profileFormData['rate_upload_unit'] === 'M' ? 'selected' : '' ?>>MB</option>
</select>
<span class="input-group-text rate-part-label">DOWNLOAD</span>
<input type="number" class="form-control profile-number-input rate-value-input" name="rate_download_value" id="rateDownloadValueInput" min="0" placeholder="0" value="<?= htmlspecialchars((string)$profileFormData['rate_download_value']) ?>">
<select class="form-select profile-unit-select rate-unit-select" name="rate_download_unit" id="rateDownloadUnitSelect">
    <option value="K" <?= $profileFormData['rate_download_unit'] === 'K' ? 'selected' : '' ?>>KB</option>
    <option value="M" <?= $profileFormData['rate_download_unit'] === 'M' ? 'selected' : '' ?>>MB</option>
</select>
</div>

<div class="input-group">
<span class="input-group-text">Partagé</span>
<input type="number" class="form-control profile-number-input" name="simultaneous_use" min="1" placeholder="1" value="<?= htmlspecialchars((string)$profileFormData['simultaneous_use']) ?>">
</div>

<div class="input-group">
<span class="input-group-text">Limite temps</span>
<input type="number" class="form-control profile-number-input" name="session_timeout_value" min="0" placeholder="0" value="<?= htmlspecialchars((string)$profileFormData['session_timeout_value']) ?>">
<select class="form-select profile-unit-select" name="session_timeout_unit">
    <option value="minutes" <?= $profileFormData['session_timeout_unit'] === 'minutes' ? 'selected' : '' ?>>Minutes</option>
    <option value="hours" <?= $profileFormData['session_timeout_unit'] === 'hours' ? 'selected' : '' ?>>Heures</option>
    <option value="days" <?= $profileFormData['session_timeout_unit'] === 'days' ? 'selected' : '' ?>>Jours</option>
    <option value="months" <?= $profileFormData['session_timeout_unit'] === 'months' ? 'selected' : '' ?>>Mois</option>
</select>
</div>

<div class="input-group">
<span class="input-group-text">Validité profil</span>
<input type="number" class="form-control profile-number-input" name="validity_value" min="0" placeholder="0" value="<?= htmlspecialchars((string)$profileFormData['validity_value']) ?>">
<select class="form-select profile-unit-select" name="validity_unit">
    <option value="hours" <?= $profileFormData['validity_unit'] === 'hours' ? 'selected' : '' ?>>Heures</option>
    <option value="days" <?= $profileFormData['validity_unit'] === 'days' ? 'selected' : '' ?>>Jours</option>
    <option value="months" <?= $profileFormData['validity_unit'] === 'months' ? 'selected' : '' ?>>Mois</option>
</select>
</div>

<div class="input-group">
<span class="input-group-text">Limite de données</span>
<input type="number" class="form-control profile-number-input" name="data_quota_mb" min="0" placeholder="0" value="<?= htmlspecialchars((string)$profileFormData['data_quota_mb']) ?>">
<span class="input-group-text">MB</span>
</div>

<div class="input-group">
<span class="input-group-text">Mode d'expiration</span>
<select class="form-select" name="expired_mode">
    <option value="none" <?= $profileFormData['expired_mode'] === 'none' ? 'selected' : '' ?>>None</option>
    <option value="remove" <?= $profileFormData['expired_mode'] === 'remove' ? 'selected' : '' ?>>Remove</option>
    <option value="notice" <?= $profileFormData['expired_mode'] === 'notice' ? 'selected' : '' ?>>Notice</option>
    <option value="remove_record" <?= $profileFormData['expired_mode'] === 'remove_record' ? 'selected' : '' ?>>Remove &amp; Record</option>
    <option value="notice_record" <?= $profileFormData['expired_mode'] === 'notice_record' ? 'selected' : '' ?>>Notice &amp; Record</option>
</select>
</div>

<div class="input-group">
<span class="input-group-text">Période de grâce</span>
<input type="number" class="form-control profile-number-input" name="grace_period_value" min="0" placeholder="0" value="<?= htmlspecialchars((string)$profileFormData['grace_period_value']) ?>">
<select class="form-select profile-unit-select" name="grace_period_unit">
    <option value="minutes" <?= $profileFormData['grace_period_unit'] === 'minutes' ? 'selected' : '' ?>>Minutes</option>
    <option value="hours" <?= $profileFormData['grace_period_unit'] === 'hours' ? 'selected' : '' ?>>Heures</option>
    <option value="days" <?= $profileFormData['grace_period_unit'] === 'days' ? 'selected' : '' ?>>Jours</option>
</select>
</div>

<div class="input-group">
<span class="input-group-text">Prix de base</span>
<input type="number" class="form-control profile-number-input" name="price" min="0" step="0.01" placeholder="0.00" value="<?= htmlspecialchars((string)$profileFormData['price']) ?>">
</div>

<div class="input-group">
<span class="input-group-text">Prix de vente</span>
<input type="number" class="form-control profile-number-input" name="selling_price" min="0" step="0.01" placeholder="0.00" value="<?= htmlspecialchars((string)$profileFormData['selling_price']) ?>">
</div>

<div class="card mt-3 profile-advanced-card<?= $showMikrotikHints ? '' : ' d-none' ?>" id="profileAdvancedCard">
<div class="card-header py-2">
    <i class="fa fa-cogs me-2"></i> <span id="profileAdvancedTitle"><?= htmlspecialchars($profileAdvancedTitle) ?></span>
</div>
<div class="card-body py-3">
<p class="small text-white-50 mb-3" id="profileAdvancedDescription"><?= htmlspecialchars($profileAdvancedDescription) ?></p>
<div class="input-group">
<span class="input-group-text">Pool d'adresses</span>
<select class="form-select" name="address_pool" id="addressPoolSelect" <?= $mikrotikAdvancedDisabled ?>>
    <option value="">-- Choisir --</option>
</select>
</div>

<div class="input-group">
<span class="input-group-text">Verrouiller</span>
<select class="form-select" name="lock_user">
    <option value="0" <?= $profileFormData['lock_user'] === '0' ? 'selected' : '' ?>>Desactiver</option>
    <option value="1" <?= $profileFormData['lock_user'] === '1' ? 'selected' : '' ?>>Activer</option>
</select>
</div>

<div class="input-group mb-0">
<span class="input-group-text">File parente</span>
<select class="form-select" name="parent_queue" id="parentQueueSelect" <?= $mikrotikAdvancedDisabled ?>>
    <option value="">-- Choisir --</option>
</select>
</div>
</div>
</div>

</div>
</div>
</div>

<div class="col-md-6 d-flex">
<div class="card shadow-sm border-info h-100 w-100 guide-content">
<div class="card-header bg-info text-white py-2">
    <i class="fa fa-info-circle me-2"></i> Guide de saisie
</div>

<div class="card-body small">
<h6 class="fw-bold text-primary mb-1">Informations</h6>
<ul class="mb-2 ps-3">
<li><b>Serveur :</b> Choisissez le routeur ou le serveur concerné.</li>
<li><b>Nom :</b> Donnez un nom clair a votre offre.</li>
<li><b>Rate Limit :</b> Debit maximum applique a l offre.</li>
<li><b>Partagé :</b> Nombre de connexions simultanees autorisees.</li>
</ul>

<h6 class="fw-bold text-primary mb-1">Expiration</h6>
<ul class="mb-2 ps-3">
<li><b>Limite temps :</b> duree maximale technique d une session.</li>
<li><b>Validité profil :</b> duree commerciale de l offre.</li>
<li><b>Limite de données :</b> quota data de l offre en MB.</li>
<li><b>Mode d'expiration :</b> Action a faire quand la validite commerciale est finie.</li>
<li><b>Période de grâce :</b> Petit delai supplementaire avant la coupure.</li>
</ul>

<h6 class="fw-bold text-primary mb-1">Commercial</h6>
<ul class="mb-2 ps-3">
<li><b>Prix de base :</b> Prix interne de reference.</li>
<li><b>Prix de vente :</b> Prix de vente au client.</li>
</ul>

<h6 class="fw-bold text-primary mb-1">Execution</h6>
<ul class="mb-0 ps-3">
<li><b>MikroTik :</b> application directe sur le routeur, avec options avancees disponibles.</li>
<li><b>OPNsense / RADIUS :</b> stockage local du profil puis synchronisation FreeRADIUS.</li>
<li><b>Pool d'adresses / Verrouillage / File parente :</b> disponibles uniquement pour MikroTik.</li>
</ul>
</div>
</div>
</div>
</div>

<div class="row mt-3">
<div class="col-md-6 text-end">
<button type="submit" class="btn btn-save">
    <i class="fa fa-save me-1"></i> <?= htmlspecialchars($submitLabel) ?>
</button>
</div>
</div>

</form>

</div>
</div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<script src="../js/sidebar.js?v=20260402a"></script>
<script src="../js/select_nas.js"></script>

</body>
</html>
