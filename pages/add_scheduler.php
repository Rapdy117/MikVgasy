<?php
session_start();

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/device_manager.php';
require_once __DIR__ . '/../includes/mikrotik_backend.php';

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: ../index.php');
    exit();
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$store = loadDeviceStore();
$activeDevice = getActiveDeviceRecord($store);
$activeDeviceType = (string)($activeDevice['type'] ?? 'other');
$activeDeviceName = trim((string)($activeDevice['name'] ?? ''));
$activeDeviceAddress = trim((string)($activeDevice['ip'] ?? ''));
$isMikrotik = is_array($activeDevice) && ($activeDeviceType === 'mikrotik');
$items = [];
$loadError = null;
$selectedSchedulerId = trim((string)($_GET['scheduler_id'] ?? ''));
$selectedScheduler = null;

function schedulerEditorSourceLabel(?array $device): string
{
    if (!is_array($device)) {
        return 'Aucun device actif';
    }

    $type = strtolower(trim((string)($device['type'] ?? 'other')));

    return match ($type) {
        'mikrotik' => 'MikroTik / édition native',
        'opnsense' => 'OPNsense / non applicable',
        default => 'RADIUS / non applicable',
    };
}

if ($isMikrotik) {
    try {
        $items = getMikrotikSchedulers(300);
        if ($selectedSchedulerId !== '') {
            foreach ($items as $item) {
                if ((string)($item['id'] ?? '') === $selectedSchedulerId) {
                    $selectedScheduler = $item;
                    break;
                }
            }
        }
    } catch (Throwable $e) {
        $loadError = trim((string)$e->getMessage());
    }
}
?>
<?php
$pageTitle = $selectedScheduler ? 'Modifier Scheduler' : 'Ajouter Scheduler';
$extraCss = array (
  0 => '../css/network_devices.css',
);
require_once __DIR__ . '/../includes/layout_header.php';
?>

<div class="card shadow-sm mb-3">
    <div class="card-body py-3">
        <div class="d-flex flex-wrap gap-3 justify-content-between align-items-center">
            <div class="d-flex align-items-center text-white" style="font-size: calc(0.875rem + 2px);">
                <i class="fa fa-clock me-2"></i>
                <span class="small fw-semibold"><?= $selectedScheduler ? 'Modifier Scheduler' : 'Ajouter Scheduler' ?></span>
            </div>
            <div class="d-flex flex-wrap gap-2 align-items-center ms-auto">
                <span class="badge rounded-pill bg-dark border border-white-10 px-3 py-2 small opacity-75">
                    Source : <?= htmlspecialchars(schedulerEditorSourceLabel($activeDevice)) ?>
                </span>
                <a href="scheduler.php" class="btn btn-test">
                    <i class="fa fa-arrow-left me-1"></i> Retour
                </a>
            </div>
        </div>
    </div>
</div>

<div class="row">
<div class="col-lg-6 mb-3">
<div class="card shadow-sm h-100">
<div class="card-header standard-card-header">
    <i class="fa fa-list me-2"></i> Schedulers existants
</div>
<div class="card-body">

<div class="d-flex justify-content-end mb-3">
    <a href="scheduler.php" class="btn btn-test">
        <i class="fa fa-arrow-left me-1"></i> Retour
    </a>
</div>

<?php if (!$isMikrotik): ?>
    <div class="p-5 text-center text-white-50 opacity-50">
        <i class="fa fa-info-circle fa-2x mb-3"></i>
        <p class="mb-0">Ce module n’est pas disponible pour le backend actif.</p>
    </div>
<?php elseif ($loadError !== null): ?>
    <div class="p-5 text-center text-danger">
        <i class="fa fa-exclamation-triangle fa-2x mb-3"></i>
        <p class="mb-0"><?= htmlspecialchars($loadError) ?></p>
    </div>
<?php else: ?>
<div class="table-responsive">
<table class="table table-dark table-hover table-striped mb-0 table-standard">
    <thead>
        <tr>
            <th>Nom</th>
            <th>Tâche</th>
            <th>Fréquence</th>
            <th>Prochaine</th>
            <th>Statut</th>
        </tr>
    </thead>
    <tbody>
        <?php if (!$items): ?>
        <tr>
            <td colspan="5" class="text-center py-4 text-white-50 opacity-50">Aucun scheduler existant</td>
        </tr>
        <?php else: ?>
        <?php foreach ($items as $item): ?>
        <tr>
            <td class="fw-semibold"><?= htmlspecialchars((string)(($item['name'] ?? '') !== '' ? $item['name'] : '-')) ?></td>
            <td class="small text-white-50"><?= htmlspecialchars((string)(($item['on_event'] ?? '') !== '' ? $item['on_event'] : '-')) ?></td>
            <td><?= htmlspecialchars((string)(($item['interval'] ?? '') !== '' ? $item['interval'] : '-')) ?></td>
            <td><?= htmlspecialchars(trim((string)(($item['start_date'] ?? '') . ' ' . ($item['next_run'] ?? ''))) ?: '-') ?></td>
            <td>
                <?php if (!empty($item['disabled'])): ?>
                    <span class="badge bg-secondary opacity-50">Désactivé</span>
                <?php else: ?>
                    <span class="badge bg-success">Actif</span>
                <?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
        <?php endif; ?>
    </tbody>
</table>
</div>
<?php endif; ?>

</div>
</div>
</div>

<div class="col-lg-6 mb-3">
<div class="card shadow-sm h-100">
<div class="card-header standard-card-header">
    <i class="fa fa-plus-circle me-2"></i> <?= $selectedScheduler ? 'Édition du scheduler' : 'Nouveau scheduler' ?>
</div>
<div class="card-body">

<?php if (!$isMikrotik): ?>
    <div class="text-center text-white-50 py-5 opacity-50">
        <i class="fa fa-clock fa-3x mb-3"></i>
        <p class="mb-2">
            Le device actif
            <strong class="text-white"><?= htmlspecialchars($activeDeviceName !== '' ? $activeDeviceName : 'inconnu') ?></strong>
            ne supporte pas ce module dans notre interface.
        </p>
        <p class="mb-0">Passez sur un NAS MikroTik pour gérer les schedulers.</p>
    </div>
<?php else: ?>
<form id="addSchedulerForm" class="network-device-form" method="POST" autocomplete="off">
<input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string)$_SESSION['csrf_token']) ?>">
<input type="hidden" name="scheduler_id" value="<?= htmlspecialchars((string)($selectedScheduler['id'] ?? '')) ?>">

<div class="d-flex justify-content-between align-items-center mb-4">
    <h6 class="text-white small mb-0">
        <i class="fa fa-info-circle me-2"></i> Paramètres
    </h6>

    <div class="d-flex gap-2">
        <button type="submit" class="btn btn-save" id="saveBtn">
            <i class="fa fa-save me-1"></i> Enregistrer
        </button>

        <?php if ($selectedScheduler): ?>
        <button type="button" class="btn btn-delete" id="deleteBtn">
            <i class="fa fa-trash me-1"></i> Supprimer
        </button>
        <?php endif; ?>

        <a href="scheduler.php" class="btn btn-test">
            <i class="fa fa-times me-1"></i> Annuler
        </a>
    </div>
</div>

<div class="input-group mb-2">
    <span class="input-group-text">Nom</span>
    <input type="text" class="form-control" name="name" required value="<?= htmlspecialchars((string)($selectedScheduler['name'] ?? '')) ?>">
</div>

<div class="input-group mb-2">
    <span class="input-group-text">Tâche</span>
    <textarea class="form-control" name="on_event" rows="4" required><?= htmlspecialchars((string)($selectedScheduler['on_event'] ?? '')) ?></textarea>
</div>

<div class="input-group mb-2">
    <span class="input-group-text">Fréquence</span>
    <input type="text" class="form-control" name="interval" placeholder="Ex: 1d 00:00:00" value="<?= htmlspecialchars((string)($selectedScheduler['interval'] ?? '')) ?>">
</div>

<div class="input-group mb-2">
    <span class="input-group-text">Date début</span>
    <input type="text" class="form-control" name="start_date" placeholder="Ex: mar/28/2026" value="<?= htmlspecialchars((string)($selectedScheduler['start_date'] ?? '')) ?>">
</div>

<div class="input-group mb-2">
    <span class="input-group-text">Heure début</span>
    <input type="text" class="form-control" name="start_time" placeholder="Ex: 10:00:00" value="<?= htmlspecialchars((string)($selectedScheduler['start_time'] ?? '')) ?>">
</div>

<div class="input-group mb-2">
    <span class="input-group-text">Commentaire</span>
    <input type="text" class="form-control" name="comment" value="<?= htmlspecialchars((string)($selectedScheduler['comment'] ?? '')) ?>">
</div>

<div class="form-check form-switch text-white mt-3">
    <input class="form-check-input" type="checkbox" role="switch" id="disabled" name="disabled" value="1" <?= !empty($selectedScheduler['disabled']) ? 'checked' : '' ?>>
    <label class="form-check-label" for="disabled">Désactivé</label>
</div>
</form>
<?php endif; ?>

</div>
</div>
</div>
</div>

<?php
$extraScript = "
window.schedulerEditorConfig = {
    mode: " . json_encode($selectedScheduler ? 'edit' : 'create') . "
};
";
$extraJs = array (
  0 => '../js/add_scheduler.js',
);
require_once __DIR__ . '/../includes/layout_header.php';
// Note: layout_header already includes sidebar.php if $noSidebar is not set.
// Wait, I should use layout_footer.php at the end.
require_once __DIR__ . '/../includes/layout_footer.php';
?>
