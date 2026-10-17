<?php
session_start(); // Démarre la session pour gérer l'état de connexion
require_once '../includes/auth.php';
require_once '../includes/device_manager.php';
require_once '../includes/mikrotik_backend.php';
require_once '../includes/lan_inventory.php';
require_once '../includes/opnsense_dhcp_leases.php';

// Inclure la fonction de message
require_once '../includes/message.php'; // Chemin correct depuis le dossier 'dashboard'

// Vérifie si l'utilisateur est connecté. Si non, redirige vers la page de connexion.
// La variable de session 'logged_in' est maintenant cohérente avec index.php
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    set_message('Veuillez vous connecter pour accéder à cette page.', 'danger'); // Utilisation de set_message
    header('Location: ../index.php'); // Redirige vers la page de connexion racine
    exit();
}

$store = loadDeviceStore();
$activeDevice = getActiveDeviceRecord($store);
$activeType = strtolower((string)($activeDevice['type'] ?? ''));
$isMikrotik = $activeType === 'mikrotik';
$isOpnsense = $activeType === 'opnsense';
$leases = [];
$leasesError = null;

$inventory = null;
$canManageInventory = isAdministrator();

if ($isMikrotik) {
    // Lecture du dernier instantané SQL uniquement : RouterOS n'est interrogé que par le bouton « Mettre à jour ».
    try {
        global $pdo;
        $activeDeviceId = (string)($activeDevice['id'] ?? '');
        $snapshot = lanInventoryLatestSnapshot($pdo, $activeDeviceId);
        if ($snapshot !== null) {
            $inventory = lanInventoryBuild($snapshot);
            $leases = $inventory['rows'];
        }
    } catch (Throwable $e) {
        $leasesError = $e->getMessage();
    }
} elseif ($isOpnsense && is_array($activeDevice)) {
    try {
        $leases = listOpnsenseDhcpLeases($activeDevice, 300);
    } catch (Throwable $e) {
        $leasesError = $e->getMessage();
    }
}

?>

<?php
$pageTitle = 'Baux DHCP';
$extraCss = array (
    0 => '../css/dhcp_leases.css?v=20260929portalbuttons2',
);
require_once '../includes/layout_header.php';
?>

<div id="messageArea" style="display: none;"></div>
                <input type="hidden" id="dhcpCsrfToken" value="<?= htmlspecialchars((string)$_SESSION['csrf_token']) ?>">

                <div class="card shadow-sm mb-4">
                    <div class="card-header standard-card-header d-flex align-items-center justify-content-between flex-wrap gap-2">
                        <div class="d-flex align-items-center text-truncate">
                            <i class="fa fa-network-wired me-2 flex-shrink-0"></i>
                            <span>Baux DHCP et équipements observés</span>
                        </div>

                        <?php if ($isMikrotik || ($isOpnsense && $leasesError === null && !empty($leases))): ?>
                        <div class="leases-toolbar">
                            <div class="input-group users-search-group leases-search-group mb-0">
                                <span class="input-group-text">
                                    <i class="fa fa-search"></i>
                                </span>
                                <input
                                    type="search"
                                    class="form-control"
                                    id="leasesSearchInput"
                                    placeholder="Rechercher une adresse, un MAC, un nom d hote ou un serveur"
                                    autocomplete="off"
                                >
                            </div>
                            <?php if ($isMikrotik): ?>
                                <div class="leases-actions">
                                    <?php if ($canManageInventory): ?>
                                    <button type="button" class="btn btn-test" id="refreshLanInventoryBtn" title="Relire RouterOS et enregistrer un nouvel instantané">
                                        <i class="fa fa-rotate me-1"></i> Mettre à jour
                                    </button>
                                    <?php endif; ?>
                                    <div class="leases-conformity-counter" id="leasesConformityCounter" title="<?= htmlspecialchars($inventory !== null ? 'Instantané du ' . $inventory['summary']['captured_at'] . ($inventory['summary']['captured_by'] !== '' ? ' par ' . $inventory['summary']['captured_by'] : '') : 'Aucun instantané enregistré', ENT_QUOTES) ?>">
                                        <button type="button" class="leases-conformity-filter-btn" id="leasesFilterAnomaliesBtn" aria-pressed="false" title="N afficher que les lignes avec anomalie">
                                            <b><?= $inventory !== null ? (int)$inventory['summary']['anomalies'] : '--' ?></b> anomalies
                                        </button>
                                        <button type="button" class="leases-conformity-filter-btn" id="leasesFilterActionsBtn" aria-pressed="false" title="N afficher que les lignes avec correction proposée">
                                            <b><?= $inventory !== null ? (int)$inventory['summary']['actions'] : '--' ?></b> actions
                                        </button>
                                    </div>
                                    <a href="add_dhcp_lease.php" class="btn btn-save">
                                        <i class="fa fa-plus me-1"></i> Nouveau
                                    </a>
                                    <button type="button" class="btn btn-test leases-action-control" id="openSelectedPortalDiagnosticBtn" aria-label="Diagnostiquer les baux cochés" title="Diagnostiquer les baux cochés" disabled>
                                        <i class="fa fa-stethoscope me-1"></i> Diagnostic
                                    </button>
                                    <button type="button" class="btn btn-delete" id="deleteLeaseBtn" disabled>
                                        <i class="fa fa-trash me-1"></i> Supprimer
                                    </button>
                                </div>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                    <div class="card-body">


                        <?php if (!$isMikrotik && !$isOpnsense): ?>
                            <div class="p-4 text-center text-white-50">
                                <?php if ($activeType === 'radius' || $activeType === 'freeradius'): ?>
                                    Ce module n'est pas disponible pour FreeRADIUS, car les baux DHCP sont geres par l'equipement reseau et non par le serveur d'authentification.
                                <?php else: ?>
                                    Cette page sera alimentee quand le device actif est de type MikroTik.
                                <?php endif; ?>
                            </div>
                        <?php elseif ($leasesError !== null): ?>
                            <div class="p-4 text-center text-danger">
                                <?= htmlspecialchars($leasesError) ?>
                            </div>
                        <?php elseif (empty($leases) && !$isMikrotik): ?>
                            <div class="p-4 text-center text-white-50">
                                <?= $isOpnsense ? 'Aucun bail DHCP detecte sur l instance OPNsense active.' : 'Aucun bail DHCP detecte sur le routeur actif.' ?>
                            </div>
                        <?php elseif ($isMikrotik && $inventory === null): ?>
                            <div class="p-4 text-center text-white-50">
                                Aucun instantané enregistré pour ce routeur. <?= $canManageInventory ? 'Cliquez sur « Mettre à jour » pour lire RouterOS une fois et enregistrer l inventaire.' : 'Un administrateur doit lancer la mise à jour.' ?>
                            </div>
                        <?php elseif ($isMikrotik): ?>
                            <div class="table-responsive">
                                <table class="table table-striped table-hover table-dark mb-0 align-middle leases-table small table-standard" data-sort-table="1" data-default-sort-key="order_rank" data-default-sort-direction="asc">
                                    <thead>
                                        <tr>
                                            <th class="leases-select-col">
                                                <input type="checkbox" class="form-check-input mt-0" id="selectAllLeases" aria-label="Tout sélectionner">
                                            </th>
                                            <th class="leases-col-left" data-sort-key="address" data-sort-type="text">Adresse</th>
                                            <th class="leases-col-left" data-sort-key="mac" data-sort-type="text">MAC</th>
                                            <th class="leases-col-left" data-sort-key="host_name" data-sort-type="text">Nom d hote</th>
                                            <th class="leases-col-left" data-sort-key="role" data-sort-type="text">Rôle</th>
                                            <th class="leases-col-left" data-sort-key="server" data-sort-type="text">Serveur</th>
                                            <th data-sort-key="lease_type" data-sort-type="text">Type</th>
                                            <th class="leases-col-left" data-sort-key="comment" data-sort-type="text">Commentaire</th>
                                            <th data-sort-key="status" data-sort-type="text">Statut</th>
                                            <th class="leases-col-left" data-sort-key="anomaly" data-sort-type="text">Anomalies</th>
                                            <th data-sort-key="expires_after" data-sort-type="duration">Expiration</th>
                                            <th data-sort-key="last_seen" data-sort-type="duration">Vu</th>
                                        </tr>
                                    </thead>
                                    <tbody id="leasesTableBody">
                                        <?php foreach ($inventory['rows'] as $row): ?>
                                        <tr
                                            <?= $row['lease_id'] !== '' ? 'data-lease-id="' . htmlspecialchars($row['lease_id'], ENT_QUOTES) . '" class="lease-row-clickable"' : '' ?>
                                            data-order_rank="<?= htmlspecialchars($row['order_rank'], ENT_QUOTES) ?>"
                                            data-address="<?= htmlspecialchars($row['ip'], ENT_QUOTES) ?>"
                                            data-mac="<?= htmlspecialchars($row['mac'], ENT_QUOTES) ?>"
                                            data-host_name="<?= htmlspecialchars($row['host_name'], ENT_QUOTES) ?>"
                                            data-server="<?= htmlspecialchars($row['server'], ENT_QUOTES) ?>"
                                            data-lease_type="<?= htmlspecialchars($row['type'], ENT_QUOTES) ?>"
                                            data-comment="<?= htmlspecialchars($row['comment'], ENT_QUOTES) ?>"
                                            data-disabled="<?= $row['disabled'] ? '1' : '0' ?>"
                                            data-anomaly="<?= htmlspecialchars(implode(' ; ', $row['anomalies']), ENT_QUOTES) ?>"
                                            data-has-action="<?= $canManageInventory && $row['actions'] !== [] ? '1' : '0' ?>"
                                        >
                                            <td class="leases-select-col">
                                                <input type="checkbox" class="form-check-input lease-select" value="<?= htmlspecialchars($row['lease_id'], ENT_QUOTES) ?>" aria-label="Sélectionner cette ligne">
                                            </td>
                                            <td class="leases-col-left">
                                                <?php if ($row['http']): ?>
                                                <a href="http://<?= htmlspecialchars($row['ip'], ENT_QUOTES) ?>/" target="_blank" rel="noopener noreferrer" title="Ouvrir la gestion dans une nouvelle fenêtre"><?= htmlspecialchars($row['ip']) ?></a>
                                                <?php else: ?>
                                                <?= htmlspecialchars($row['ip'] !== '' ? $row['ip'] : '-') ?>
                                                <?php endif; ?>
                                            </td>
                                            <td class="leases-col-left" title="<?= htmlspecialchars(implode(' / ', $row['macs']), ENT_QUOTES) ?>"><?= htmlspecialchars($row['mac'] !== '' ? $row['mac'] : '-') ?></td>
                                            <td class="leases-col-left"><?= htmlspecialchars($row['host_name'] !== '' ? $row['host_name'] : '-') ?></td>
                                            <td class="leases-col-left"><?= htmlspecialchars($row['role']) ?></td>
                                            <td class="leases-col-left"><?= htmlspecialchars($row['server'] !== '' ? $row['server'] : '-') ?></td>
                                            <td class="leases-type-cell">
                                                <?php if ($row['type'] !== '-'): ?>
                                                <span class="leases-type-badge leases-type-<?= strtolower($row['type']) ?>" title="<?= $row['lease_id'] !== '' ? ($row['type'] === 'D' ? 'Bail dynamique RouterOS' : 'Bail statique RouterOS') : 'Statique probable — hors pool DHCP' ?>"><?= $row['type'] ?></span>
                                                <?php else: ?>-<?php endif; ?>
                                            </td>
                                            <td class="leases-col-left leases-comment-cell" title="<?= htmlspecialchars($row['comment'] !== '' ? $row['comment'] : '-') ?>"><?= htmlspecialchars($row['comment'] !== '' ? $row['comment'] : '-') ?></td>
                                            <td class="leases-status-cell"><?= htmlspecialchars($row['status']) ?></td>
                                            <td class="leases-col-left leases-anomaly-cell">
                                                <?php if ($row['anomalies'] === []): ?>-<?php else: ?>
                                                <button type="button" class="leases-anomaly-badge" data-filter-anomaly="1" title="<?= htmlspecialchars(implode(' ; ', $row['anomalies']), ENT_QUOTES) ?>">
                                                    <i class="fa fa-triangle-exclamation"></i> <?= count($row['anomalies']) ?>
                                                </button>
                                                <?php foreach ($canManageInventory ? $row['actions'] : [] as $action): ?>
                                                <button type="button" class="btn btn-delete btn-sm leases-conformity-action" data-conformity-action="1"
                                                    data-scope="<?= htmlspecialchars($action['scope'], ENT_QUOTES) ?>" data-id="<?= htmlspecialchars($action['id'], ENT_QUOTES) ?>"
                                                    data-label="<?= htmlspecialchars($action['label'], ENT_QUOTES) ?>" data-ip="<?= htmlspecialchars($action['ip'], ENT_QUOTES) ?>" data-mac="<?= htmlspecialchars($action['mac'], ENT_QUOTES) ?>"
                                                    data-arp-id="<?= htmlspecialchars($action['arp_id'] ?? '', ENT_QUOTES) ?>" data-hotspot-host-id="<?= htmlspecialchars($action['hotspot_host_id'] ?? '', ENT_QUOTES) ?>"
                                                    data-arp-mac="<?= htmlspecialchars($action['arp_mac'] ?? '', ENT_QUOTES) ?>" data-hotspot-host-mac="<?= htmlspecialchars($action['hotspot_host_mac'] ?? '', ENT_QUOTES) ?>"><?= htmlspecialchars($action['label']) ?></button>
                                                <?php endforeach; ?>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-end"><?= htmlspecialchars($row['expires_after'] !== '' ? $row['expires_after'] : '-') ?></td>
                                            <td class="text-end"><?= htmlspecialchars($row['last_seen'] !== '' ? $row['last_seen'] : '-') ?></td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-striped table-hover table-dark mb-0 align-middle leases-table small table-standard" data-sort-table="1" data-default-sort-key="address" data-default-sort-direction="asc">
                                    <thead>
                                        <tr>
                                            <th class="leases-col-left" data-sort-key="address" data-sort-type="text">Adresse</th>
                                            <th class="leases-col-left" data-sort-key="mac" data-sort-type="text">MAC</th>
                                            <th class="leases-col-left" data-sort-key="host_name" data-sort-type="text">Nom d hote</th>
                                            <th class="leases-col-left" data-sort-key="server" data-sort-type="text">Serveur</th>
                                            <th class="leases-col-left" data-sort-key="comment" data-sort-type="text">Commentaire</th>
                                            <th data-sort-key="status" data-sort-type="text">Statut</th>
                                            <th data-sort-key="expires_after" data-sort-type="duration">Expiration</th>
                                            <th data-sort-key="last_seen" data-sort-type="duration">Vu</th>
                                        </tr>
                                    </thead>
                                    <tbody id="leasesTableBody">
                                        <?php foreach ($leases as $lease): ?>
                                        <?php $status = $lease['disabled'] ? 'Desactive' : ($lease['blocked'] ? 'Bloque' : ($lease['status'] !== '' ? ucfirst($lease['status']) : '-')); ?>
                                        <tr
                                            data-lease-id="<?= htmlspecialchars((string)($lease['id'] ?? ''), ENT_QUOTES) ?>"
                                        >
                                            <td class="leases-col-left"><?= htmlspecialchars($lease['address']) ?></td>
                                            <td class="leases-col-left"><?= htmlspecialchars($lease['mac']) ?></td>
                                            <td class="leases-col-left"><?= htmlspecialchars($lease['host_name'] !== '' ? $lease['host_name'] : '-') ?></td>
                                            <td class="leases-col-left"><?= htmlspecialchars($lease['server'] !== '' ? $lease['server'] : '-') ?></td>
                                            <td class="leases-col-left leases-comment-cell" title="<?= htmlspecialchars($lease['comment'] !== '' ? $lease['comment'] : '-') ?>"><?= htmlspecialchars($lease['comment'] !== '' ? $lease['comment'] : '-') ?></td>
                                            <td class="leases-status-cell"><?= htmlspecialchars($status) ?></td>
                                            <td class="text-end"><?= htmlspecialchars($lease['expires_after'] !== '' ? $lease['expires_after'] : '-') ?></td>
                                            <td class="text-end"><?= htmlspecialchars($lease['last_seen'] !== '' ? $lease['last_seen'] : '-') ?></td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

            </div>
        </div>
        </div>

<?php if ($isMikrotik): ?>
<div class="leases-conformity-modal" id="portalDiagnosticModal" hidden aria-hidden="true">
    <div class="leases-conformity-backdrop" data-portal-diagnostic-close></div>
    <section class="leases-conformity-dialog leases-inventory-dialog" role="dialog" aria-modal="true" aria-labelledby="portalDiagnosticTitle">
        <div class="leases-conformity-head">
            <div>
                <strong id="portalDiagnosticTitle"><i class="fa fa-stethoscope me-1"></i> Diagnostic du portail captif</strong>
                <small id="portalDiagnosticCheckedAt">Lecture en attente</small>
            </div>
            <div class="leases-conformity-head-actions">
                <span class="leases-conformity-state" id="portalDiagnosticState">--</span>
                <button type="button" class="leases-conformity-close" data-portal-diagnostic-close aria-label="Fermer"><i class="fa fa-xmark"></i></button>
            </div>
        </div>
        <p class="leases-conformity-intro" id="portalDiagnosticNotice">Instantané RouterOS en lecture seule.</p>
        <div class="leases-conformity-grid" id="portalDiagnosticSummary"></div>
        <div class="leases-conformity-results" id="portalDiagnosticResults"></div>
    </section>
</div>
<?php endif; ?>
    
<?php
$extraJs = array (
  0 => '../js/table_sort.js',
    1 => '../js/dhcp_leases.js?v=20261002laninventory',
);
require_once '../includes/layout_footer.php';
?>
