<?php
declare(strict_types=1);
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/../includes/auth.php';
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || !isSuperAdministrator()) { http_response_code(403); exit('Accès réservé au superadmin.'); }
if (empty($_SESSION['csrf_token'])) { $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); }
$pageTitle = 'Outils système';
$bodyClass = 'administration-page system-tools-compact';
$contentClass = 'system-tools-layout';
$bodyAttributes = ['data-csrf-token' => (string)$_SESSION['csrf_token']];
$extraCss = ['../css/administration.css?v=20260722-tools6'];
$extraJs = ['../tools/system_tools.js'];
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/layout_header.php';
?>
<?php $systemInstallationReady = (bool)$pdo->query("SHOW TABLES LIKE 'system_installation'")->fetchColumn()
    && (int)$pdo->query('SELECT COUNT(*) FROM system_installation WHERE id = 1')->fetchColumn() > 0;
?>

<div class="row g-3 system-tools-grid">
  <div class="col-12 col-lg-6">
    <section class="card shadow-sm administration-card h-100">
      <div class="card-header standard-card-header"><i class="fa fa-file-zipper me-2"></i>Sauvegarde des fichiers</div>
      <div class="card-body">
        <p class="small text-white-50 mb-3">PHP, HTML, CSS, JavaScript, configuration et ressources. L’archive ZIP sert aussi aux mises à jour ; les utilisateurs RADIUS restent dans SQL.</p>
        <div class="d-flex flex-wrap gap-2 mb-2"><a class="btn btn-save btn-sm" href="/api/admin/export_files_zip.php"><i class="fa fa-download me-1"></i>Exporter ZIP</a></div>
        <form action="/api/admin/import_files_zip.php" method="post" enctype="multipart/form-data" class="network-device-form"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string)$_SESSION['csrf_token'], ENT_QUOTES) ?>"><div class="system-tools-import"><input class="form-control" type="file" name="files_zip" accept=".zip" required><button class="btn btn-test btn-sm" type="submit"><i class="fa fa-upload me-1"></i>Importer</button></div></form>
      </div>
    </section>
  </div>
  <div class="col-12 col-lg-6">
    <section class="card shadow-sm administration-card h-100">
      <div class="card-header standard-card-header"><i class="fa fa-database me-2"></i>Gestion de la base SQL</div>
      <div class="card-body">
        <div class="d-flex flex-wrap gap-2 mb-2"><a class="btn btn-save btn-sm" href="/api/admin/export_database.php"><i class="fa fa-download me-1"></i>Export complet</a><a class="btn btn-test btn-sm" href="/api/admin/export_skeleton_database.php"><i class="fa fa-cube me-1"></i>Base seule</a></div>
        <p class="small text-white-50 mb-2">Export complet : structure et données, Traffic Control inclus. Base seule : structure sans utilisateurs RADIUS, listes, historiques ni notifications.</p>
        <form action="/api/admin/import_database.php" method="post" enctype="multipart/form-data" class="network-device-form"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string)$_SESSION['csrf_token'], ENT_QUOTES) ?>"><div class="system-tools-import"><input class="form-control" type="file" name="sql_file" accept=".sql" required><button class="btn btn-test btn-sm" type="submit"><i class="fa fa-upload me-1"></i>Importer</button></div></form>
      </div>
    </section>
  </div>
  <div class="col-12 col-lg-6">
    <section class="card shadow-sm administration-card h-100"><div class="card-header standard-card-header"><i class="fa fa-screwdriver-wrench me-2"></i>Outils système</div><div class="card-body"><p class="small text-white-50">Actions réservées au superadmin.</p><div class="d-flex flex-wrap gap-2"><button type="button" class="btn btn-test btn-sm" id="syncOpnsenseSessionsBtn"><i class="fa fa-rotate-right me-1"></i>Synchroniser</button><button type="button" class="btn btn-save btn-sm" id="installOpnsenseCronBtn"><i class="fa fa-clock me-1"></i>Installer le cron</button></div><p id="systemToolsStatus" class="small text-white-50 mt-2 mb-0" role="status"></p></div></section>
  </div>
  <div class="col-12 col-lg-6">
    <section class="card shadow-sm administration-card h-100"><div class="card-header standard-card-header"><i class="fa fa-key me-2"></i>Licences devices</div><div class="card-body"><p class="small text-white-50">Génération et activation dans la page dédiée.</p><a class="btn btn-save btn-sm" href="/pages/device_licenses.php"><i class="fa fa-key me-1"></i>Gérer les licences</a></div></section>
  </div>
  <div class="col-12">
    <section class="card shadow-sm administration-card h-100"><div class="card-header standard-card-header"><i class="fa fa-screwdriver-wrench me-2"></i>Maintenance SQL</div><div class="card-body"><p class="small text-white-50 mb-2">État, export, archivage et nettoyage Traffic Control par rétention.</p><div class="system-maintenance-panel"><div class="system-maintenance-row system-maintenance-row-general"><label class="small text-white-50" for="maintenanceTable">Table<select id="maintenanceTable" class="form-select form-select-sm"><option value="operation_history">Historique opérations</option><option value="recharge_history">Historique recharges</option><option value="admin_notifications">Notifications</option><option value="vouchers">Vouchers</option><option value="radacct">Sessions RADIUS</option><option value="mikrotik_traffic_user_usage_samples">Traffic Control · consommation</option><option value="mikrotik_traffic_samples">Traffic Control · analyses</option><option value="users">Utilisateurs SQL</option><option value="profiles">Profils SQL</option></select></label><button type="button" class="btn btn-test btn-sm" id="maintenanceStatusBtn">État SQL</button><button type="button" class="btn btn-save btn-sm" id="maintenanceSensitiveBackupBtn">Sauvegarde sensible</button></div><div class="system-maintenance-row system-maintenance-row-traffic"><label class="small text-white-50" for="trafficRetentionMonths">Traffic avant<span class="system-maintenance-inline-control"><input id="trafficRetentionMonths" class="form-control form-control-sm" type="number" min="1" max="60" value="6"><span>mois</span></span></label><button type="button" class="btn btn-save btn-sm" id="maintenanceTrafficBackupBtn">Export Traffic Control</button><button type="button" class="btn btn-save btn-sm" id="maintenanceTrafficArchiveBtn">Archiver Traffic</button><button type="button" class="btn btn-test btn-sm" id="maintenanceTrafficCleanBtn">Nettoyer Traffic</button></div></div><pre id="maintenanceStatus" class="small text-white-50 mt-2 mb-0" aria-live="polite"></pre></div></section>
  </div>
<?php if (!$systemInstallationReady): ?><div class="col-12 col-lg-6"><div class="card shadow-sm administration-card h-100"><div class="card-header standard-card-header"><i class="fa fa-user-shield me-2"></i>Initialisation du superadmin</div><div class="card-body"><form method="post" action="/tools/install.php" class="network-device-form" autocomplete="off"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string)($_SESSION['install_csrf'] ?? ($_SESSION['install_csrf'] = bin2hex(random_bytes(32)))), ENT_QUOTES) ?>"><div class="row g-2"><div class="col-md-4"><label class="form-label">Nom</label><input class="form-control" name="username" value="<?= htmlspecialchars((string)($_SESSION['username'] ?? 'superadmin'), ENT_QUOTES) ?>" required></div><div class="col-md-4"><label class="form-label">Mot de passe</label><input class="form-control" type="password" name="password" minlength="12" required></div><div class="col-md-4"><label class="form-label">Confirmation</label><input class="form-control" type="password" name="password_confirmation" minlength="12" required></div></div><button class="btn btn-save mt-3" type="submit"><i class="fa fa-check me-1"></i>Initialiser</button></form></div></div></div><?php endif; ?></div>
<?php require_once __DIR__ . '/../includes/layout_footer.php'; ?>
