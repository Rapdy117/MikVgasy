<?php
if (!defined('MIGRATION_SETUP_VIEW')) {
    http_response_code(403);
    exit('Accès direct interdit.');
}
?>
<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Installation Radius Manager</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
<link rel="stylesheet" href="/css/theme.css">
<style>
.install-stepper{display:flex;align-items:flex-start;margin-bottom:1.75rem}
.install-stepper .step{flex:1;display:flex;flex-direction:column;align-items:center;position:relative;min-width:0}
.install-stepper .step-circle{width:2.2rem;height:2.2rem;border-radius:50%;display:flex;align-items:center;justify-content:center;background:rgba(255,255,255,.08);border:2px solid rgba(255,255,255,.16);color:rgba(255,255,255,.55);font-weight:700;font-size:.85rem;transition:all .2s ease;z-index:1}
.install-stepper .step-label{margin-top:.4rem;font-size:.68rem;color:rgba(255,255,255,.5);text-align:center;text-transform:uppercase;letter-spacing:.03em;max-width:90px}
.install-stepper .step::after{content:'';position:absolute;top:1.1rem;left:50%;width:100%;height:2px;background:rgba(255,255,255,.12);z-index:0}
.install-stepper .step:last-child::after{display:none}
.install-stepper .step.active .step-circle{background:var(--theme-primary,#17a2b8);border-color:var(--theme-primary,#17a2b8);color:#fff;box-shadow:0 0 0 4px color-mix(in srgb, var(--theme-primary,#17a2b8) 25%, transparent)}
.install-stepper .step.active .step-label{color:#fff;font-weight:700}
.install-stepper .step.done .step-circle{background:#22c55e;border-color:#22c55e;color:#fff}
.install-stepper .step.done .step-label{color:#bbf7d0}
.install-stepper .step.done::after{background:#22c55e}
.install-check{display:flex;align-items:center;gap:.5rem;padding:.4rem 0}
.install-section-title{font-size:.72rem;font-weight:800;text-transform:uppercase;letter-spacing:.05em;color:rgba(255,255,255,.55);margin:1.1rem 0 .5rem}
.install-section-title:first-child{margin-top:0}
.card-body label,.card-body p:not(.text-white-50){color:rgba(255,255,255,.85)}
.card-body label{font-weight:600;font-size:.85rem}
</style>
</head>
<body class="administration-page text-white">
<div class="bg-scene"></div>
<main class="container py-4" style="max-width:720px">
<div class="card shadow-sm administration-card mb-3">
<div class="card-header standard-card-header"><i class="fa fa-screwdriver-wrench me-2"></i>Installation Radius Manager</div>
<div class="card-body">

<?php
$stepIcons = [1 => 'fa-database', 2 => 'fa-user-shield', 3 => 'fa-clock-rotate-left', 4 => 'fa-router', 5 => 'fa-circle-check'];
$stepLabels = [1 => 'Base & RADIUS', 2 => 'Superadmin', 3 => 'Scripts & cron', 4 => 'Routeur MikroTik', 5 => 'Résumé'];
?>
<div class="install-stepper">
<?php foreach ($stepLabels as $n => $label): $state = $step === $n ? 'active' : ($step > $n ? 'done' : ''); ?>
<div class="step <?= $state ?>">
<div class="step-circle"><i class="fa <?= $state === 'done' ? 'fa-check' : $stepIcons[$n] ?>"></i></div>
<div class="step-label"><?= installEscape($label) ?></div>
</div>
<?php endforeach; ?>
</div>

<?php if ($error !== ''): ?><div class="alert alert-danger"><?= installEscape($error) ?></div><?php endif; ?>

<?php if ($step === 1): ?>
<p class="text-white-50">Identifiants MySQL/MariaDB de l'application, et connexion FreeRADIUS (facultative à ce stade, modifiable plus tard).</p>
<form method="post" autocomplete="off">
<input type="hidden" name="csrf_token" value="<?= installEscape((string)$_SESSION['install_csrf']) ?>">
<h2 class="install-section-title">Base de données</h2>
<p><label>Hôte MySQL<br><input class="form-control" name="db_host" value="localhost" required></label></p>
<p><label>Utilisateur<br><input class="form-control" name="db_user" required></label></p>
<p><label>Mot de passe<br><input class="form-control" type="password" name="db_pass"></label></p>
<p><label>Nom de la base<br><input class="form-control" name="db_name" value="radius_manager" required></label></p>
<h2 class="install-section-title">FreeRADIUS (facultatif)</h2>
<p><label>Hôte<br><input class="form-control" name="radius_host" placeholder="ex. 127.0.0.1"></label></p>
<p><label>Secret partagé<br><input class="form-control" type="password" name="radius_secret"></label></p>
<p><label>Utilisateur de test<br><input class="form-control" name="radius_test_user" value="radius"></label></p>
<p><label>Mot de passe de test<br><input class="form-control" type="password" name="radius_test_pass"></label></p>
<p><label>Port auth<br><input class="form-control" name="radius_auth_port" value="1812"></label>
<label class="ms-2">Port acct<br><input class="form-control" name="radius_acct_port" value="1813"></label></p>
<button class="btn btn-save" type="submit">Tester et continuer</button>
</form>

<?php elseif ($step === 2): ?>
<p class="text-white-50">Ce compte <strong>superadmin</strong> est réservé à l'installateur (vous) — il gère l'ensemble de l'instance. Les comptes destinés aux clients se créent après, en rôle « admin », depuis l'application.</p>
<form method="post" autocomplete="off">
<input type="hidden" name="csrf_token" value="<?= installEscape((string)$_SESSION['install_csrf']) ?>">
<p><label>Nom d'utilisateur superadmin<br><input class="form-control" name="username" value="superadmin" minlength="3" maxlength="100" required></label></p>
<p><label>Mot de passe initial<br><input class="form-control" type="password" name="password" minlength="12" required></label></p>
<p><label>Confirmation<br><input class="form-control" type="password" name="password_confirmation" minlength="12" required></label></p>
<button class="btn btn-save" type="submit">Créer le superadmin</button>
</form>

<?php elseif ($step === 3): ?>
<p class="text-white-50">Les tâches planifiées (collecte trafic, WAN, qualité réseau, logs hotspot) sont installées par <code>install_vps.sh</code>, exécuté en root avant cette page — la page web ne peut pas écrire dans <code>/etc/cron.d/</code>.</p>
<?php $cronFile = '/etc/cron.d/radius-manager-network-quality'; ?>
<p class="install-check"><i class="fa <?= is_file($cronFile) ? 'fa-circle-check text-success' : 'fa-circle-xmark text-danger' ?>"></i>
<?= is_file($cronFile) ? 'Fichier de cron détecté : ' . installEscape($cronFile) : 'Fichier de cron introuvable — relancez install_vps.sh puis revenez ici' ?></p>
<form method="post">
<input type="hidden" name="csrf_token" value="<?= installEscape((string)$_SESSION['install_csrf']) ?>">
<button class="btn btn-save" type="submit">Continuer</button>
</form>

<?php elseif ($step === 4): ?>
<p class="text-white-50">Enregistrement du routeur MikroTik principal (optionnel ici, possible plus tard depuis l'application). Le nombre de liens WAN sert uniquement à préparer le moteur N-WAN — la configuration réseau détaillée du routeur neuf (bridge, hotspot, pare-feu) reste à faire séparément pour l'instant.</p>
<form method="post" autocomplete="off">
<input type="hidden" name="csrf_token" value="<?= installEscape((string)$_SESSION['install_csrf']) ?>">
<input type="hidden" name="action" value="create">
<p><label>Nom du routeur<br><input class="form-control" name="device_name" value="Routeur principal"></label></p>
<p><label>Adresse (IP ou host:port)<br><input class="form-control" name="device_host" placeholder="ex. 10.10.10.1" required></label></p>
<p><label>Identifiant API<br><input class="form-control" name="device_api_key" required></label></p>
<p><label>Secret API<br><input class="form-control" type="password" name="device_api_secret" required></label></p>
<p><label>Nombre de liens WAN<br>
<select class="form-select" name="wan_count"><option>1</option><option selected>2</option><option>3</option><option>4</option></select></label></p>
<button class="btn btn-save" type="submit">Enregistrer et continuer</button>
</form>
<form method="post" class="mt-2">
<input type="hidden" name="csrf_token" value="<?= installEscape((string)$_SESSION['install_csrf']) ?>">
<input type="hidden" name="action" value="skip">
<button class="btn btn-outline-light btn-sm" type="submit">Passer cette étape</button>
</form>

<?php elseif ($step === 5): ?>
<p class="text-white-50">Vérification finale avant mise en service.</p>
<?php foreach ($checks as $check): ?>
<p class="install-check"><i class="fa <?= $check['ok'] ? 'fa-circle-check text-success' : 'fa-circle-xmark text-danger' ?>"></i><?= installEscape($check['label']) ?></p>
<?php endforeach; ?>
<p class="mt-3"><a class="btn btn-save" href="/pages/dashboard.php">Aller à l'application</a></p>
<?php endif; ?>

</div>
</div>
</main>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
