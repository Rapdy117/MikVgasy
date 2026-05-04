<?php
session_start();
require_once '../includes/auth.php';

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: ../index.php');
    exit();
}
requireAdministratorAccess();
?>

<?php
$pageTitle = 'Configuration FreeRADIUS';
$extraCss = array (
  0 => '../css/freeradius.css',
);
require_once '../includes/layout_header.php';
?>

<div class="card shadow-sm mb-3">
    <div class="card-body py-3">
        <div class="d-flex align-items-center text-white" style="font-size: calc(0.875rem + 2px);">
            <i class="fa fa-shield-alt me-2"></i>
            <span class="small fw-semibold">Configuration FreeRADIUS</span>
        </div>
    </div>
</div>

<div class="row">

<div class="col-lg-6 mb-3">
<div class="card shadow-sm h-100 radius-card">
<div class="card-header standard-card-header">
    <i class="fa fa-server me-2"></i> Paramètres du serveur
</div>
<div class="card-body">

<form id="radiusConfigForm" class="radius-form" method="POST" autocomplete="off">
<div class="input-group">
    <span class="input-group-text">Serveur</span>
    <input type="text" class="form-control" name="host" value="">
</div>

<div class="input-group">
    <span class="input-group-text">Port auth</span>
    <input type="number" class="form-control" name="auth_port" value="1812">
</div>

<div class="input-group">
    <span class="input-group-text">Port acct</span>
    <input type="number" class="form-control" name="acct_port" value="1813">
</div>

<div class="input-group">
    <span class="input-group-text">Secret</span>
    <input type="password" class="form-control" name="secret" value="">
</div>

<div class="input-group">
    <span class="input-group-text">Timeout</span>
    <input type="number" class="form-control" name="timeout" value="3">
</div>

<div class="d-flex justify-content-between mt-3">
    <div class="d-flex gap-2">
        <button type="submit" class="btn btn-save">
            <i class="fa fa-save me-1"></i> Sauvegarder
        </button>

        <button type="button" id="loadBtn" class="btn btn-save">
            <i class="fa fa-folder-open me-1"></i> Charger
        </button>
    </div>

    <button type="button" id="testRadiusServer" class="btn btn-test">
        <i class="fa fa-plug me-1"></i> Tester
    </button>
</div>

</form>

<hr class="my-4 text-white-50 opacity-10">

<h6 class="text-white small mb-3">
    <i class="fa fa-user-check me-2"></i> Test d'authentification
</h6>

<form id="radiusUserTestForm" class="radius-form" method="POST" autocomplete="off">
<div class="input-group">
    <span class="input-group-text">Utilisateur</span>
    <input type="text" class="form-control" id="testUser" name="test_user">
</div>

<div class="input-group">
    <span class="input-group-text">Mot de passe</span>
    <input type="password" class="form-control" id="testPass" name="test_pass">
</div>
</form>

<div class="d-flex justify-content-end mt-3">
    <button type="button" id="testRadiusUser" class="btn btn-test">
        <i class="fa fa-user-shield me-1"></i> Tester l'utilisateur
    </button>
</div>

</div>
</div>
</div>

<div class="col-lg-6 mb-3">
<div class="card shadow-sm h-100 radius-card">
<div class="card-header standard-card-header">
    <i class="fa fa-wave-square me-2"></i> Statut RADIUS
</div>
<div class="card-body">

<div class="radius-status-box mb-4" id="radiusStatusBox">
    <div class="radius-status-line"><strong>Serveur :</strong> <span id="radiusStatusHost">-</span></div>
    <div class="radius-status-line"><strong>Port auth :</strong> <span id="radiusStatusAuthPort">1812</span></div>
    <div class="radius-status-line"><strong>Port acct :</strong> <span id="radiusStatusAcctPort">1813</span></div>
    <div class="radius-status-line"><strong>Timeout :</strong> <span id="radiusStatusTimeout">3 s</span></div>
    <div class="radius-status-line"><strong>Secret :</strong> <span id="radiusStatusSecret">Non défini</span></div>
</div>

<h6 class="text-white small mb-3">
    <i class="fa fa-terminal me-2"></i> Sortie du test
</h6>

<pre id="testResult" class="radius-output-box small p-3 bg-dark rounded border border-white-10 text-white-50" style="min-height: 200px;">Aucun test effectué.</pre>

</div>
</div>
</div>

</div>

<?php
$extraJs = array (
  0 => '../js/freeradius.js',
);
require_once '../includes/layout_footer.php';
?>
