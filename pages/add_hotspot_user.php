<?php
session_start();
require_once '../includes/auth.php';
require_once '../includes/app_context.php';
require_once '../includes/page_helpers.php';

/* SECURITY */
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: ../index.php');
    exit();
}
requireAdministratorAccess('Seul l administrateur peut créer un utilisateur simple.');

/* CSRF */
$csrfToken = ensureCsrfToken();

$appContext = buildAppContext();
$activeDevice = $appContext['device'] ?? null;
$activeDeviceType = strtolower(trim((string)($activeDevice['type'] ?? '')));
$hasActiveDevice = isset($activeDevice['id']) && trim((string) $activeDevice['id']) !== '';
// Sans device actif : pas de filtre métier côté JS.
$activeDeviceBusinessSource = $hasActiveDevice
    ? trim((string)($activeDevice['business_source'] ?? ''))
    : '';
$activeDeviceId = (string)($activeDevice['id'] ?? '');
?>

<?php
$pageTitle = 'Ajouter Utilisateur';
$extraCss = [
    '../css/add_hostpot_user.css',
];
$bodyAttributes = [
    'data-active-device-business-source' => $activeDeviceBusinessSource,
];
require_once '../includes/layout_header.php';
?>

<form id="userForm" class="network-device-form" method="POST" autocomplete="off">

<input type="hidden" name="id">
<input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
<input type="hidden" name="nas_id" id="nasIdInput" value="">
<input type="hidden" name="profile_id" id="profileIdInput" value="">
<input type="hidden" name="profile_name" id="profileNameInput" value="">

<div class="row align-items-stretch">
<div class="col-md-6 d-flex">
<div class="card h-100 w-100">
<div class="card-header standard-card-header">
    <i class="fa fa-user-plus me-2"></i> Ajouter Utilisateur Hotspot
</div>

<div class="card-body">

<div class="input-group" id="userNasField">
<span class="input-group-text">Serveur</span>
<select class="form-select" name="device_id" id="nasSelect" required>
    <option value="">-- Choisir un serveur --</option>
</select>
</div>

<div class="input-group">
<span class="input-group-text">Nom</span>
<input type="text" class="form-control" name="username" required>
</div>

<div class="input-group">
<span class="input-group-text">Mot de passe</span>
<input type="password" class="form-control" name="password" required>
</div>

<div class="input-group">
<span class="input-group-text">Profil</span>
<select class="form-select" id="profileSelect" required>
    <option value="">-- Choisir un profil --</option>
</select>
</div>

<div class="card mt-2 mb-2 add-hotspot-profile-card">
<div class="card-header standard-card-header py-2">
    <i class="fa fa-layer-group me-2"></i> Hérité du profil
</div>
<div class="card-body py-2">
<div class="input-group input-group-sm mb-2">
<span class="input-group-text">Rate Limit</span>
<input type="text" class="form-control" id="profileFieldRateLimit" readonly value="">
</div>
<div class="input-group input-group-sm mb-2">
<span class="input-group-text">Time Limit</span>
<input type="text" class="form-control" id="profileFieldTimeLimit" readonly value="">
</div>
<div class="input-group input-group-sm mb-2">
<span class="input-group-text">Data Limit</span>
<input type="text" class="form-control" id="profileFieldDataLimit" readonly value="">
</div>
<div class="input-group input-group-sm mb-2">
<span class="input-group-text">Validité</span>
<input type="text" class="form-control" id="profileFieldValidityTime" readonly value="">
</div>
<div class="input-group input-group-sm mb-2">
<span class="input-group-text">Expiration</span>
<input type="text" class="form-control" id="profileFieldExpiredMode" readonly value="">
</div>
<div class="input-group input-group-sm mb-0">
<span class="input-group-text">Prix vente</span>
<input type="text" class="form-control" id="profileFieldSellingPrice" readonly value="">
</div>
</div>
</div>

<div class="input-group input-group-sm mb-2" data-capability="Session-Timeout">
<span class="input-group-text">Time Limit</span>
<input type="hidden" name="session_timeout" id="sessionTimeoutInput">
<input type="number" class="form-control time-segment-input" name="session_days" id="sessionDaysInput" min="0" placeholder="0">
<span class="input-group-text">Jours</span>
<input type="number" class="form-control time-segment-input" name="session_hours" id="sessionHoursInput" min="0" max="23" placeholder="0">
<span class="input-group-text">Heures</span>
<input type="number" class="form-control time-segment-input" name="session_minutes" id="sessionMinutesInput" min="0" placeholder="0">
<span class="input-group-text">Minutes</span>
</div>

<div class="input-group input-group-sm mb-2" data-capability="Max-Octets">
<span class="input-group-text">Data Limit</span>
<input type="hidden" name="data_limit" id="dataLimitInput">
<input type="number" class="form-control" name="data_limit_value" id="dataLimitValueInput" min="0" placeholder="0">
<select class="form-select data-limit-unit" name="data_limit_unit" id="dataLimitUnitSelect">
    <option value="KB">KB</option>
    <option value="MB">MB</option>
    <option value="GB" selected>GB</option>
</select>
</div>

</div>
</div>
</div>

<div class="col-md-6 d-flex">
<div class="card shadow-sm border-info h-100 w-100 guide-content">
<div class="card-header standard-card-header py-2">
    <i class="fa fa-info-circle me-2"></i> Guide de saisie
</div>

<div class="card-body small">
<h6 class="fw-bold text-primary mb-1">Informations</h6>
<ul class="mb-2 ps-3">
    <li><b>Serveur :</b> Choisissez le routeur ou le serveur concerné</li>
    <li><b>Nom :</b> C'est le nom de connexion de l'utilisateur.</li>
    <li><b>Mot de passe :</b> c'est le mot de passe de connexion.</li>
    <li><b>Profil :</b> choisissez l'offre ou le forfait à appliquer.</li>
</ul>

<h6 class="fw-bold text-primary mb-1">Limites</h6>
<ul class="mb-0 ps-3">
    <li><b>Time Limit :</b> laissez vide si vous ne voulez pas fixer de duree.</li>
    <li><b>Data Limit utilisateur :</b> laissez vide pour reprendre automatiquement le quota du profil.</li>
    <li>Ces deux champs sont optionnels.</li>
</ul>
</div>
</div>
</div>
</div>

<div class="row mt-3">
<div class="col-md-6 text-end">
<button type="submit" class="btn btn-save">
<i class="fa fa-save me-1"></i> Enregistrer
</button>
</div>
</div>

</form>

</div>
</div>
</div>


<?php
$extraJs = array (
  0 => '../js/profile_options_loader.js?v=20260417a',
  1 => '../js/add_hotspot_user.js?v=20260417c',
);
require_once '../includes/layout_footer.php';
?>
