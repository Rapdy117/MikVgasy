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
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<title><?= $selectedScheduler ? 'Modifier Scheduler' : 'Ajouter Scheduler' ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="../css/theme.css">
<link rel="stylesheet" href="../css/network_devices.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
</head>
<body>
<div class="d-flex" id="wrapper">
<?php include __DIR__ . '/../includes/sidebar.php'; ?>

<div id="page-content-wrapper">
<div class="container-fluid py-3">

<div id="messageArea" style="display:none;"></div>

<div class="card mb-3">
<div class="card-body py-3">
<div class="d-flex flex-wrap gap-3 justify-content-between align-items-center">
    <h5 class="text-white mb-0">
        <i class="fa fa-clock me-2"></i> <?= $selectedScheduler ? 'Modifier Scheduler' : 'Ajouter Scheduler' ?>
    </h5>
    <div class="d-flex flex-wrap gap-2 align-items-center ms-auto">
        <span class="badge rounded-pill text-bg-dark border border-secondary-subtle px-3 py-2">
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
<div class="card shadow-sm">
<div class="card-body">
<div class="d-flex justify-content-between align-items-center mb-3">
    <h6 class="text-white mb-0">
        <i class="fa fa-list me-2"></i> Schedulers existants
    </h6>
    <a href="scheduler.php" class="btn btn-test">
        <i class="fa fa-arrow-left me-1"></i> Retour
    </a>
</div>

<?php if (!$isMikrotik): ?>
    <div class="p-4 text-center text-white-50">
        Ce module n’est pas disponible pour le backend actif.
    </div>
<?php elseif ($loadError !== null): ?>
    <div class="p-4 text-center text-danger">
        <?= htmlspecialchars($loadError) ?>
    </div>
<?php else: ?>
<div class="table-responsive">
<table class="table network-device-table table-hover align-middle small text-nowrap">
    <thead>
        <tr>
            <th>Nom</th>
            <th>Tache</th>
            <th>Frequence</th>
            <th>Prochaine</th>
            <th>Statut</th>
        </tr>
    </thead>
    <tbody>
        <?php if (!$items): ?>
        <tr>
            <td colspan="5" class="text-center py-4 text-white-50">Aucun scheduler existant</td>
        </tr>
        <?php else: ?>
        <?php foreach ($items as $item): ?>
        <tr>
            <td><?= htmlspecialchars((string)(($item['name'] ?? '') !== '' ? $item['name'] : '-')) ?></td>
            <td><?= htmlspecialchars((string)(($item['on_event'] ?? '') !== '' ? $item['on_event'] : '-')) ?></td>
            <td><?= htmlspecialchars((string)(($item['interval'] ?? '') !== '' ? $item['interval'] : '-')) ?></td>
            <td><?= htmlspecialchars(trim((string)(($item['start_date'] ?? '') . ' ' . ($item['next_run'] ?? ''))) ?: '-') ?></td>
            <td><?= !empty($item['disabled']) ? 'Desactive' : 'Actif' ?></td>
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
<div class="card shadow-sm">
<div class="card-body">

<h6 class="text-white mb-3">
    <i class="fa fa-plus-circle me-2"></i> <?= $selectedScheduler ? 'Edition du scheduler' : 'Nouveau scheduler' ?>
</h6>

<?php if (!$isMikrotik): ?>
    <div class="text-center text-muted mb-3">
        <i class="fa fa-clock fa-2x mb-2"></i>
        <p class="mb-2">
            Le device actif
            <strong class="text-white"><?= htmlspecialchars($activeDeviceName !== '' ? $activeDeviceName : 'inconnu') ?></strong>
            <?= $activeDeviceAddress !== '' ? '(' . htmlspecialchars($activeDeviceAddress) . ')' : '' ?>
            ne supporte pas ce module dans notre interface.
        </p>
        <p class="mb-0">Passe sur un NAS MikroTik pour créer, modifier ou supprimer des schedulers.</p>
    </div>
<?php else: ?>
<form id="addSchedulerForm" class="network-device-form" method="POST" autocomplete="off">
<input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string)$_SESSION['csrf_token']) ?>">
<input type="hidden" name="scheduler_id" value="<?= htmlspecialchars((string)($selectedScheduler['id'] ?? '')) ?>">

<div class="d-flex justify-content-between align-items-center mb-3">
    <h6 class="text-white mt-3 mb-2">
        <i class="fa fa-info-circle me-2"></i> Parametres
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
    <span class="input-group-text">Tache</span>
    <textarea class="form-control" name="on_event" rows="4" required><?= htmlspecialchars((string)($selectedScheduler['on_event'] ?? '')) ?></textarea>
</div>

<div class="input-group mb-2">
    <span class="input-group-text">Frequence</span>
    <input type="text" class="form-control" name="interval" placeholder="Ex: 1d 00:00:00" value="<?= htmlspecialchars((string)($selectedScheduler['interval'] ?? '')) ?>">
</div>

<div class="input-group mb-2">
    <span class="input-group-text">Date debut</span>
    <input type="text" class="form-control" name="start_date" placeholder="Ex: mar/28/2026" value="<?= htmlspecialchars((string)($selectedScheduler['start_date'] ?? '')) ?>">
</div>

<div class="input-group mb-2">
    <span class="input-group-text">Heure debut</span>
    <input type="text" class="form-control" name="start_time" placeholder="Ex: 10:00:00" value="<?= htmlspecialchars((string)($selectedScheduler['start_time'] ?? '')) ?>">
</div>

<div class="input-group mb-2">
    <span class="input-group-text">Commentaire</span>
    <input type="text" class="form-control" name="comment" value="<?= htmlspecialchars((string)($selectedScheduler['comment'] ?? '')) ?>">
</div>

<div class="form-check form-switch text-white mt-2">
    <input class="form-check-input" type="checkbox" role="switch" id="disabled" name="disabled" value="1" <?= !empty($selectedScheduler['disabled']) ? 'checked' : '' ?>>
    <label class="form-check-label" for="disabled">Desactive</label>
</div>
</form>
<?php endif; ?>

</div>
</div>
</div>
</div>

</div>
</div>
</div>

<script src="../js/sidebar.js?v=20260402a"></script>
<script>
window.schedulerEditorConfig = {
    mode: <?= json_encode($selectedScheduler ? 'edit' : 'create') ?>
};
</script>
<script src="../js/add_scheduler.js"></script>
</body>
</html>
