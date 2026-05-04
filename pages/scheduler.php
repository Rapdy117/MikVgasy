<?php
session_start();

require_once '../includes/auth.php';
require_once '../includes/device_manager.php';
require_once '../includes/mikrotik_backend.php';

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: ../index.php');
    exit();
}

$store = loadDeviceStore();
$activeDevice = getActiveDeviceRecord($store);
$activeDeviceType = (string)($activeDevice['type'] ?? 'other');
$activeDeviceName = trim((string)($activeDevice['name'] ?? ''));
$activeDeviceAddress = trim((string)($activeDevice['ip'] ?? ''));
$isMikrotik = is_array($activeDevice) && ($activeDeviceType === 'mikrotik');
$items = [];
$loadError = '';

function schedulerSourceLabel(?array $device): string
{
    if (!is_array($device)) {
        return 'Aucun device actif';
    }

    $type = strtolower(trim((string)($device['type'] ?? 'other')));

    return match ($type) {
        'mikrotik' => 'MikroTik / scheduler natif',
        'opnsense' => 'OPNsense / module non applicable',
        default => 'RADIUS / module non applicable',
    };
}

if ($isMikrotik) {
    try {
        $items = getMikrotikSchedulers(300);
    } catch (Throwable $e) {
        $loadError = $e->getMessage();
    }
}
?>

<?php
$pageTitle = 'Scheduler';
require_once '../includes/layout_header.php';
?>
<style>
.scheduler-search-group {
        max-width: 320px;
    }

    .scheduler-search-group .input-group-text {
        background: rgba(59, 130, 246, 0.12);
        border-color: rgba(148, 163, 184, 0.18);
        color: var(--theme-text);
    }

    .scheduler-search-group .form-control {
        background: rgba(12, 20, 34, 0.82);
        border-color: rgba(148, 163, 184, 0.18);
        color: var(--theme-text);
    }

    .scheduler-search-group .form-control::placeholder {
        color: rgba(226, 232, 240, 0.55);
    }

    .scheduler-search-group .form-control:focus {
        border-color: rgba(59, 130, 246, 0.45);
        box-shadow: 0 0 0 0.2rem rgba(59, 130, 246, 0.12);
    }

    .scheduler-table thead th {
        text-align: center;
        font-size: 14px;
    }

    .scheduler-table thead th:last-child {
        text-align: center !important;
    }

    .scheduler-table tbody td {
        font-size: 14px;
        vertical-align: middle;
    }

    .scheduler-table tbody td:nth-child(2),
    .scheduler-table tbody td:nth-child(3),
    .scheduler-table tbody td:nth-child(4),
    .scheduler-table tbody td:nth-child(5),
    .scheduler-table tbody td:nth-child(7) {
        text-align: left;
    }

    .scheduler-table tbody td:not(:nth-child(2)):not(:nth-child(3)):not(:nth-child(4)):not(:nth-child(5)):not(:nth-child(7)) {
        text-align: center;
    }

    .scheduler-table .action-cell {
        text-align: center;
    }

    .scheduler-row {
        cursor: pointer;
    }

    .scheduler-action-btn {
        width: 29px !important;
        min-width: 29px !important;
        height: 29px !important;
        min-height: 29px !important;
        padding: 0 !important;
        line-height: 1 !important;
        display: inline-flex !important;
        align-items: center !important;
        justify-content: center !important;
    }

    .scheduler-capability-card {
        border: 1px solid rgba(148, 163, 184, 0.2);
        border-radius: 12px;
        background: rgba(12, 20, 34, 0.78);
        padding: 12px;
        height: 100%;
    }

    .scheduler-capability-title {
        color: #e2e8f0;
        font-weight: 700;
        margin-bottom: 6px;
        font-size: 13px;
    }

    .scheduler-capability-text {
        color: rgba(226, 232, 240, 0.78);
        font-size: 12px;
        line-height: 1.4;
    }
</style>

<div class="card mb-3">
    <div class="card-body py-3">
        <div class="d-flex flex-wrap gap-3 justify-content-between align-items-center">
            <h4 class="text-white mb-0">
                <i class="fa fa-clock me-2"></i> Scheduler
            </h4>

            <div class="d-flex flex-wrap gap-2 align-items-center ms-auto">
                <span class="badge rounded-pill text-bg-dark border border-secondary-subtle px-3 py-2">
                    Source : <?= htmlspecialchars(schedulerSourceLabel($activeDevice)) ?>
                </span>
                <button type="button" class="btn btn-test" id="schedulerRefreshBtn">
                    <i class="fa fa-rotate-right me-1"></i> Actualiser
                </button>
                <?php if ($isMikrotik): ?>
                <a class="btn btn-save" href="add_scheduler.php">
                    <i class="fa fa-plus me-1"></i> Ajouter
                </a>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php if (!$isMikrotik): ?>
    <div class="card">
        <div class="card-body p-4">
            <div class="text-white fw-semibold mb-3">Automatisation standard (OPNsense / RADIUS)</div>
            <div class="row g-3">
                <div class="col-lg-4">
                    <div class="scheduler-capability-card">
                        <div class="scheduler-capability-title"><i class="fa fa-server me-2"></i>Device actif</div>
                        <div class="scheduler-capability-text">
                            <?= htmlspecialchars($activeDeviceName !== '' ? $activeDeviceName : 'inconnu') ?>
                            <?= $activeDeviceAddress !== '' ? ' (' . htmlspecialchars($activeDeviceAddress) . ')' : '' ?>
                            <br>
                            Type: <?= htmlspecialchars(strtoupper($activeDeviceType)) ?>
                        </div>
                    </div>
                </div>
                <div class="col-lg-4">
                    <div class="scheduler-capability-card">
                        <div class="scheduler-capability-title"><i class="fa fa-list-check me-2"></i>Fonctions exploitées</div>
                        <div class="scheduler-capability-text">
                            Profils, expiration, quotas, accounting et synchronisations backend.
                        </div>
                    </div>
                </div>
                <div class="col-lg-4">
                    <div class="scheduler-capability-card">
                        <div class="scheduler-capability-title"><i class="fa fa-gear me-2"></i>Équivalent scheduler</div>
                        <div class="scheduler-capability-text">
                            Les automatismes ne passent pas par un scheduler natif ici: ils sont gérés par les APIs et tâches backend.
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
<?php elseif ($loadError !== ''): ?>
    <div class="card">
        <div class="card-body">
            <div class="alert alert-danger mb-0"><?= htmlspecialchars($loadError) ?></div>
        </div>
    </div>
<?php else: ?>
<div class="card mb-3">
    <div class="card-body py-2">
        <div class="input-group scheduler-search-group">
            <span class="input-group-text"><i class="fa fa-search"></i></span>
            <input type="text" class="form-control" id="schedulerSearchInput" placeholder="Rechercher un scheduler...">
        </div>
    </div>
</div>

<div class="card">
<div class="card-body p-0">

<div class="table-responsive">
<table class="table table-dark table-hover table-striped mb-0 scheduler-table table-standard" data-sort-table="1" data-default-sort-key="name" data-default-sort-direction="asc">

<thead>
<tr>
    <th data-sort-key="id" data-sort-type="text">ID</th>
    <th data-sort-key="name" data-sort-type="text">Nom</th>
    <th data-sort-key="event" data-sort-type="text">Tache</th>
    <th data-sort-key="interval" data-sort-type="text">Frequence</th>
    <th data-sort-key="next" data-sort-type="text">Prochaine execution</th>
    <th data-sort-key="status" data-sort-type="text">Statut</th>
    <th data-sort-key="comment" data-sort-type="text">Commentaire</th>
    <th>Action</th>
</tr>
</thead>

<tbody id="schedulerTableBody">
<?php if (!$items): ?>
<tr data-sort-disabled="1">
    <td colspan="8" class="text-center">Aucun scheduler present</td>
</tr>
<?php else: ?>
<?php foreach ($items as $item): ?>
<tr class="scheduler-row"
    data-id="<?= htmlspecialchars((string)$item['id'], ENT_QUOTES) ?>"
    data-name="<?= htmlspecialchars((string)$item['name'], ENT_QUOTES) ?>"
    data-event="<?= htmlspecialchars((string)$item['on_event'], ENT_QUOTES) ?>"
    data-interval="<?= htmlspecialchars((string)$item['interval'], ENT_QUOTES) ?>"
    data-next="<?= htmlspecialchars(trim((string)$item['start_date'] . ' ' . (string)$item['next_run']), ENT_QUOTES) ?>"
    data-status="<?= htmlspecialchars($item['disabled'] ? 'disabled' : 'active', ENT_QUOTES) ?>"
    data-comment="<?= htmlspecialchars((string)$item['comment'], ENT_QUOTES) ?>"
>
    <td><?= htmlspecialchars((string)$item['id']) ?></td>
    <td><?= htmlspecialchars((string)$item['name'] ?: '-') ?></td>
    <td><?= htmlspecialchars((string)$item['on_event'] ?: '-') ?></td>
    <td><?= htmlspecialchars((string)$item['interval'] ?: '-') ?></td>
    <td><?= htmlspecialchars(trim(((string)$item['start_date'] ?: '-') . ' ' . ((string)$item['next_run'] ?: ''))) ?></td>
    <td><?= $item['disabled'] ? '<span class="badge bg-secondary">Desactive</span>' : '<span class="badge bg-success">Actif</span>' ?></td>
    <td><?= htmlspecialchars((string)$item['comment'] ?: '-') ?></td>
    <td class="action-cell">
        <button type="button" class="btn btn-test btn-sm scheduler-action-btn js-edit-scheduler" data-scheduler-id="<?= htmlspecialchars((string)$item['id'], ENT_QUOTES) ?>">
            <i class="fa fa-pen"></i>
        </button>
    </td>
</tr>
<?php endforeach; ?>
<?php endif; ?>
</tbody>

</table>
</div>

</div>
</div>
<?php endif; ?>

</div>
</div>
</div>


<?php
$extraJs = array (
  0 => '../js/table_sort.js',
  1 => '../js/scheduler.js',
);
require_once '../includes/layout_footer.php';
?>
