<?php
session_start();

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/device_manager.php';
require_once __DIR__ . '/../includes/mikrotik_backend.php';
require_once __DIR__ . '/../includes/message.php';

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: ../index.php');
    exit();
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$store = loadDeviceStore();
$activeDevice = getActiveDeviceRecord($store);
$activeType = strtolower((string)($activeDevice['type'] ?? ''));
$isMikrotik = $activeType === 'mikrotik';
$hosts = [];
$hostsError = null;

if ($isMikrotik) {
    try {
        $hosts = getMikrotikHotspotHosts(500);
    } catch (Throwable $e) {
        $hostsError = trim((string)$e->getMessage());
    }
}

function formatHostTraffic(float $bytes): string
{
    if ($bytes <= 0) {
        return '-';
    }

    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $power = min((int)floor(log($bytes, 1024)), count($units) - 1);
    $value = $bytes / (1024 ** $power);

    return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.') . ' ' . $units[$power];
}

function buildHostStatusLabel(array $host): string
{
    if (!empty($host['bypassed'])) {
        return 'Bypass';
    }

    if (!empty($host['authorized'])) {
        return 'Autorise';
    }

    return 'En attente';
}

function buildHostStateDetails(array $host): string
{
    $flags = [];

    if (!empty($host['authorized'])) {
        $flags[] = 'A';
    }
    if (!empty($host['dhcp'])) {
        $flags[] = 'H';
    }
    if (!empty($host['dynamic'])) {
        $flags[] = 'D';
    }
    if (!empty($host['bypassed'])) {
        $flags[] = 'P';
    }

    return $flags !== [] ? implode(' ', $flags) : '-';
}

$authorizedCount = 0;
$bypassedCount = 0;
$pendingCount = 0;

foreach ($hosts as $host) {
    if (!empty($host['bypassed'])) {
        $bypassedCount++;
    } elseif (!empty($host['authorized'])) {
        $authorizedCount++;
    } else {
        $pendingCount++;
    }
}
?>
<!DOCTYPE html>
<html lang="fr" class="hosts-page<?= ($isMikrotik && $hostsError === null) ? ' hosts-page--table' : '' ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Hosts</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Roboto:wght@300;400;500;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
<link rel="stylesheet" href="../css/theme.css">
<link rel="stylesheet" href="../css/hosts.css">
</head>
<body class="hosts-page<?= ($isMikrotik && $hostsError === null) ? ' hosts-page--table' : '' ?>">

<div class="d-flex" id="wrapper">
    <?php include_once __DIR__ . '/../includes/sidebar.php'; ?>

    <div id="page-content-wrapper">
        <div class="container-fluid py-3">
            <?php display_message(); ?>
            <div id="messageArea" style="display:none;"></div>
            <input type="hidden" id="hostsCsrfToken" value="<?= htmlspecialchars((string)$_SESSION['csrf_token'], ENT_QUOTES) ?>">

            <div class="card shadow-sm mb-4">
                <div class="card-body">
                    <div class="d-flex flex-wrap align-items-center gap-3 hosts-header-row mb-3">
                        <h5 class="text-white mb-0">
                            <i class="fa fa-laptop me-2"></i> Hosts
                        </h5>

                        <div class="hosts-toolbar ms-auto">
                            <div class="input-group users-search-group hosts-search-group">
                                <span class="input-group-text">
                                    <i class="fa fa-search"></i>
                                </span>
                                <input
                                    type="search"
                                    class="form-control"
                                    id="hostsSearchInput"
                                    placeholder="Rechercher une IP, un MAC, un serveur ou une detection"
                                    autocomplete="off"
                                >
                            </div>

                            <div class="hosts-filter-field">
                                <label class="hosts-filter-label text-white-50" for="hostsStatusFilter">Statut</label>
                                <select class="form-select hosts-filter-select" id="hostsStatusFilter">
                                    <option value="">Tous</option>
                                    <option value="autorise">Autorise</option>
                                    <option value="bypass">Bypass</option>
                                    <option value="en attente">En attente</option>
                                </select>
                            </div>

                            <button type="button" class="btn btn-test" id="refreshHostsBtn">
                                <i class="fa fa-rotate-right me-1"></i> Actualiser
                            </button>

                            <?php if ($isMikrotik && $hostsError === null): ?>
                                <button type="button" class="btn btn-delete" id="deleteHostsBtn" disabled>
                                    <i class="fa fa-trash me-1"></i> Supprimer
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>

                    <?php if (!$isMikrotik): ?>
                        <div class="hosts-empty-state text-center">
                            <div class="hosts-empty-icon mb-3">
                                <i class="fa fa-diagram-project"></i>
                            </div>
                            <h6 class="text-white mb-2">Module MikroTik natif</h6>
                            <p class="text-white-50 mb-3">
                                Les <strong>Hosts</strong> correspondent aux clients vus par le hotspot RouterOS.
                                Cette page n'a pas d'equivalent metier commun pour OPNsense ou FreeRADIUS.
                            </p>
                            <div class="d-flex justify-content-center gap-2 flex-wrap">
                                <a href="/pages/sessions_list.php" class="btn btn-save">
                                    <i class="fa fa-list me-1"></i> Sessions actives
                                </a>
                                <a href="/pages/dhcp_leases.php" class="btn btn-test">
                                    <i class="fa fa-network-wired me-1"></i> Baux DHCP
                                </a>
                            </div>
                        </div>
                    <?php elseif ($hostsError !== null): ?>
                        <div class="p-4 text-center text-danger">
                            <?= htmlspecialchars($hostsError) ?>
                        </div>
                    <?php else: ?>
                        <div class="hosts-meta-row mb-3">
                            <div class="hosts-meta-pill">
                                <span class="hosts-meta-label">Total</span>
                                <span class="hosts-meta-value"><?= count($hosts) ?></span>
                            </div>
                            <div class="hosts-meta-pill">
                                <span class="hosts-meta-label">Autorises</span>
                                <span class="hosts-meta-value"><?= $authorizedCount ?></span>
                            </div>
                            <div class="hosts-meta-pill">
                                <span class="hosts-meta-label">Bypass</span>
                                <span class="hosts-meta-value"><?= $bypassedCount ?></span>
                            </div>
                            <div class="hosts-meta-pill">
                                <span class="hosts-meta-label">En attente</span>
                                <span class="hosts-meta-value"><?= $pendingCount ?></span>
                            </div>
                        </div>

                        <div class="table-responsive hosts-table-scroll">
                            <table
                                class="table table-striped table-hover table-dark mb-0 align-middle hosts-table users-table small table-standard"
                                data-sort-table="1"
                                data-default-sort-key="address"
                                data-default-sort-direction="asc"
                            >
                                <thead>
                                    <tr>
                                        <th class="hosts-select-col">
                                            <input type="checkbox" class="form-check-input mt-0" id="selectAllHosts" aria-label="Tout selectionner">
                                        </th>
                                        <th data-sort-key="mac" data-sort-type="text">MAC</th>
                                        <th data-sort-key="address" data-sort-type="text">Adresse</th>
                                        <th data-sort-key="to_address" data-sort-type="text">To Address</th>
                                        <th data-sort-key="server" data-sort-type="text">Serveur</th>
                                        <th data-sort-key="status" data-sort-type="text">Statut</th>
                                        <th data-sort-key="state_flags" data-sort-type="text">Etat</th>
                                        <th data-sort-key="uptime" data-sort-type="duration">Uptime</th>
                                        <th data-sort-key="idle_time" data-sort-type="duration">Idle</th>
                                        <th data-sort-key="bytes_in_raw" data-sort-type="number">DL</th>
                                        <th data-sort-key="bytes_out_raw" data-sort-type="number">UL</th>
                                        <th data-sort-key="found_by" data-sort-type="text">Detection</th>
                                        <th>Action</th>
                                    </tr>
                                </thead>
                                <tbody id="hostsTableBody">
                                    <?php if ($hosts === []): ?>
                                        <tr data-sort-disabled="1">
                                            <td colspan="13" class="text-center py-4 text-white-50">
                                                Aucun host hotspot detecte sur le routeur actif.
                                            </td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($hosts as $host): ?>
                                            <?php
                                            $statusLabel = buildHostStatusLabel($host);
                                            $stateFlags = buildHostStateDetails($host);
                                            $rowId = 'host-row-' . md5((string)($host['id'] ?? '') . '|' . (string)($host['address'] ?? ''));
                                            ?>
                                            <tr
                                                id="<?= htmlspecialchars($rowId, ENT_QUOTES) ?>"
                                                data-host-id="<?= htmlspecialchars((string)($host['id'] ?? ''), ENT_QUOTES) ?>"
                                                data-mac="<?= htmlspecialchars((string)($host['mac'] ?? ''), ENT_QUOTES) ?>"
                                                data-address="<?= htmlspecialchars((string)($host['address'] ?? ''), ENT_QUOTES) ?>"
                                                data-to_address="<?= htmlspecialchars((string)($host['to_address'] ?? ''), ENT_QUOTES) ?>"
                                                data-server="<?= htmlspecialchars((string)($host['server'] ?? ''), ENT_QUOTES) ?>"
                                                data-filter-status="<?= htmlspecialchars(strtolower($statusLabel), ENT_QUOTES) ?>"
                                                data-status="<?= htmlspecialchars($statusLabel, ENT_QUOTES) ?>"
                                                data-state_flags="<?= htmlspecialchars($stateFlags, ENT_QUOTES) ?>"
                                                data-uptime="<?= htmlspecialchars((string)($host['uptime'] ?? ''), ENT_QUOTES) ?>"
                                                data-idle_time="<?= htmlspecialchars((string)($host['idle_time'] ?? ''), ENT_QUOTES) ?>"
                                                data-bytes_in_raw="<?= htmlspecialchars((string)($host['bytes_in'] ?? 0), ENT_QUOTES) ?>"
                                                data-bytes_out_raw="<?= htmlspecialchars((string)($host['bytes_out'] ?? 0), ENT_QUOTES) ?>"
                                                data-found_by="<?= htmlspecialchars((string)($host['found_by'] ?? ''), ENT_QUOTES) ?>"
                                            >
                                                <td class="hosts-select-col">
                                                    <input type="checkbox" class="form-check-input host-select" value="<?= htmlspecialchars((string)($host['id'] ?? ''), ENT_QUOTES) ?>" aria-label="Selectionner ce host">
                                                </td>
                                                <td><?= htmlspecialchars((string)(($host['mac'] ?? '') !== '' ? $host['mac'] : '-')) ?></td>
                                                <td><?= htmlspecialchars((string)(($host['address'] ?? '') !== '' ? $host['address'] : '-')) ?></td>
                                                <td><?= htmlspecialchars((string)(($host['to_address'] ?? '') !== '' ? $host['to_address'] : '-')) ?></td>
                                                <td><?= htmlspecialchars((string)(($host['server'] ?? '') !== '' ? $host['server'] : '-')) ?></td>
                                                <td><?= htmlspecialchars($statusLabel) ?></td>
                                                <td><?= htmlspecialchars($stateFlags) ?></td>
                                                <td class="text-end"><?= htmlspecialchars((string)(($host['uptime'] ?? '') !== '' ? $host['uptime'] : '-')) ?></td>
                                                <td class="text-end"><?= htmlspecialchars((string)(($host['idle_time'] ?? '') !== '' ? $host['idle_time'] : '-')) ?></td>
                                                <td class="text-end"><?= htmlspecialchars(formatHostTraffic((float)($host['bytes_in'] ?? 0))) ?></td>
                                                <td class="text-end"><?= htmlspecialchars(formatHostTraffic((float)($host['bytes_out'] ?? 0))) ?></td>
                                                <td><?= htmlspecialchars((string)(($host['found_by'] ?? '') !== '' ? $host['found_by'] : '-')) ?></td>
                                                <td class="action-cell">
                                                    <button
                                                        type="button"
                                                        class="btn btn-delete btn-sm profile-action-btn js-delete-host"
                                                        title="Supprimer ce host"
                                                        data-host-id="<?= htmlspecialchars((string)($host['id'] ?? ''), ENT_QUOTES) ?>"
                                                        data-row-id="<?= htmlspecialchars($rowId, ENT_QUOTES) ?>"
                                                        <?= (($host['id'] ?? '') !== '') ? '' : 'disabled' ?>
                                                    >
                                                        <i class="fas fa-trash"></i>
                                                    </button>
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
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="../js/sidebar.js?v=20260402a"></script>
<script src="../js/table_sort.js"></script>
<script src="../js/hosts.js?v=20260330a"></script>

</body>
</html>
