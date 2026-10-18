<?php
if (!defined('MIGRATION_PREVIEW_VIEW')) {
    http_response_code(403);
    exit('Accès direct interdit.');
}

$pageTitle = 'Aperçu assistant d\'installation';
$bodyClass = 'administration-page system-tools-compact';
$contentClass = 'system-tools-layout';
$extraCss = ['../css/traffic_control.css?v=20260915idle2'];
require_once __DIR__ . '/../includes/layout_header.php';

$stepIcons = [1 => 'fa-database', 2 => 'fa-user-shield', 3 => 'fa-clock-rotate-left', 4 => 'fa-router', 5 => 'fa-circle-check'];
$stepLabels = [1 => 'Base & RADIUS', 2 => 'Superadmin', 3 => 'Scripts & cron', 4 => 'Routeur MikroTik', 5 => 'Résumé'];
?>
<div class="row traffic-workspace">
  <div class="col-12 mb-2">
    <div class="card shadow-sm">
      <div class="card-header standard-card-header d-flex justify-content-between align-items-center gap-2 flex-wrap">
        <span><i class="fa fa-eye me-2"></i>Aperçu — <?= installEscape($stepLabels[$step]) ?></span>
        <span class="badge text-bg-secondary">Étape <?= $step ?> / 5</span>
      </div>
      <div class="card-body">

        <p class="traffic-section-help">Rendu réel de l'assistant d'installation (<code>/tools/migration_setup.php</code>) : aucune soumission de formulaire sur cette page n'écrit en base, en fichier ou en configuration.</p>

        <div class="d-flex flex-wrap gap-2 mb-3">
          <?php foreach ($stepLabels as $n => $label): ?>
            <a class="btn btn-sm <?= $n === $step ? 'btn-save' : 'btn-outline-primary' ?>" href="?preview=1&step=<?= $n ?>"><i class="fa <?= $stepIcons[$n] ?> me-1"></i><?= $n ?>. <?= installEscape($label) ?></a>
          <?php endforeach; ?>
        </div>

        <?php if ($error !== ''): ?><div class="alert alert-danger"><?= installEscape($error) ?></div><?php endif; ?>

        <?php if ($step === 1): ?>
          <p class="traffic-section-help">Identifiants MySQL/MariaDB de l'application, et connexion FreeRADIUS (facultative à ce stade, modifiable plus tard).</p>
          <form class="network-device-form row g-3 align-items-end" onsubmit="return false;">
            <div class="col-lg-3 col-md-6"><label class="form-label">Hôte MySQL</label><input class="form-control" value="localhost" readonly></div>
            <div class="col-lg-3 col-md-6"><label class="form-label">Nom de la base</label><input class="form-control" value="radius_manager" readonly></div>
            <div class="col-lg-3 col-md-6"><label class="form-label">Utilisateur</label><input class="form-control" placeholder="ex. radius_manager" readonly></div>
            <div class="col-lg-3 col-md-6"><label class="form-label">Mot de passe</label><input class="form-control" type="password" value="xxxxxxxx" readonly></div>
            <div class="col-lg-3 col-md-6"><label class="form-label">Hôte RADIUS</label><input class="form-control" placeholder="ex. 127.0.0.1" readonly></div>
            <div class="col-lg-3 col-md-6"><label class="form-label">Secret partagé</label><input class="form-control" type="password" readonly></div>
            <div class="col-lg-3 d-flex"><button class="btn btn-primary btn-sm" type="submit" disabled><i class="fa fa-check me-1"></i>Tester et continuer</button></div>
          </form>

        <?php elseif ($step === 2): ?>
          <p class="traffic-section-help">Ce compte <strong>superadmin</strong> est réservé à l'installateur (vous) — il gère l'ensemble de l'instance. Les comptes destinés aux clients se créent après, en rôle « admin », depuis l'application.</p>
          <form class="network-device-form row g-3 align-items-end" onsubmit="return false;">
            <div class="col-lg-4 col-md-6"><label class="form-label">Nom d'utilisateur superadmin</label><input class="form-control" value="superadmin" readonly></div>
            <div class="col-lg-4 col-md-6"><label class="form-label">Mot de passe initial</label><input class="form-control" type="password" value="xxxxxxxxxxxx" readonly></div>
            <div class="col-lg-4 col-md-6"><label class="form-label">Confirmation</label><input class="form-control" type="password" value="xxxxxxxxxxxx" readonly></div>
            <div class="col-lg-3 d-flex"><button class="btn btn-primary btn-sm" type="submit" disabled><i class="fa fa-check me-1"></i>Créer le superadmin</button></div>
          </form>

        <?php elseif ($step === 3): ?>
          <p class="traffic-section-help">Les tâches planifiées (collecte trafic, WAN, qualité réseau, logs hotspot) sont installées par <code>install_vps.sh</code>, exécuté en root avant cette page — la page web ne peut pas écrire dans <code>/etc/cron.d/</code>.</p>
          <?php $cronFile = '/etc/cron.d/radius-manager-network-quality'; ?>
          <p><span class="badge <?= is_file($cronFile) ? 'text-bg-success' : 'text-bg-danger' ?>"><i class="fa <?= is_file($cronFile) ? 'fa-circle-check' : 'fa-circle-xmark' ?> me-1"></i><?= is_file($cronFile) ? 'Cron détecté' : 'Cron introuvable' ?></span>
          <span class="traffic-card-label ms-2"><?= installEscape($cronFile) ?></span></p>
          <button class="btn btn-primary btn-sm mt-2" type="button" disabled><i class="fa fa-check me-1"></i>Continuer</button>

        <?php elseif ($step === 4): ?>
          <p class="traffic-section-help">Enregistrement du routeur MikroTik principal (optionnel ici, possible plus tard depuis l'application). Le nombre de liens WAN sert uniquement à préparer le moteur N-WAN — la configuration réseau détaillée du routeur neuf reste à faire séparément pour l'instant.</p>
          <form class="network-device-form row g-3 align-items-end" onsubmit="return false;">
            <div class="col-lg-4 col-md-6"><label class="form-label">Nom du routeur</label><input class="form-control" value="Routeur principal" readonly></div>
            <div class="col-lg-4 col-md-6"><label class="form-label">Adresse (IP ou host:port)</label><input class="form-control" placeholder="ex. 10.10.10.1" readonly></div>
            <div class="col-lg-4 col-md-6"><label class="form-label">Identifiant API</label><input class="form-control" readonly></div>
            <div class="col-lg-4 col-md-6"><label class="form-label">Secret API</label><input class="form-control" type="password" readonly></div>
            <div class="col-lg-4 col-md-6"><label class="form-label">Nombre de liens WAN</label><select class="form-select" disabled><option>1</option><option selected>2</option><option>3</option><option>4</option></select></div>
            <div class="col-lg-4 d-flex gap-2"><button class="btn btn-primary btn-sm" type="submit" disabled><i class="fa fa-check me-1"></i>Enregistrer</button><button class="btn btn-outline-info btn-sm" type="button" disabled>Passer</button></div>
          </form>

        <?php elseif ($step === 5): ?>
          <p class="traffic-section-help">Vérification finale avant mise en service.</p>
          <?php foreach ($checks as $check): ?>
            <p><span class="badge <?= $check['ok'] ? 'text-bg-success' : 'text-bg-danger' ?>"><i class="fa <?= $check['ok'] ? 'fa-circle-check' : 'fa-circle-xmark' ?> me-1"></i><?= installEscape($check['label']) ?></span></p>
          <?php endforeach; ?>
          <a class="btn btn-primary btn-sm mt-2" href="/pages/dashboard.php"><i class="fa fa-arrow-right me-1"></i>Aller à l'application</a>
        <?php endif; ?>

      </div>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/../includes/layout_footer.php'; ?>
