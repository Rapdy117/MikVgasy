<?php
session_start(); // Démarre la session pour gérer l'état de connexion
require_once '../includes/auth.php';
require_once '../includes/device_manager.php';
require_once '../includes/mikrotik_backend.php';
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

if ($isMikrotik) {
    try {
        $leases = getMikrotikDhcpLeases(300);
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
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Baux DHCP</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@300;400;500;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link rel="stylesheet" href="../css/theme.css">
    <link rel="stylesheet" href="../css/dhcp_leases.css">
</head>

<body class="dhcp-leases-page" data-active-device-type="<?= htmlspecialchars($activeType, ENT_QUOTES) ?>">

    <div class="d-flex" id="wrapper">
        <?php include '../includes/sidebar.php'; ?>

        <div id="page-content-wrapper">
            <div class="container-fluid py-3">
                <?php display_message(); ?>
                <div id="messageArea" style="display: none;"></div>
                <input type="hidden" id="dhcpCsrfToken" value="<?= htmlspecialchars((string)$_SESSION['csrf_token']) ?>">

                <div class="card shadow-sm mb-4">
                    <div class="card-header standard-card-header d-flex align-items-center justify-content-between flex-wrap gap-2">
                        <div class="d-flex align-items-center text-truncate">
                            <i class="fa fa-network-wired me-2 flex-shrink-0"></i>
                            <span>Baux DHCP</span>
                        </div>

                        <?php if (($isMikrotik || $isOpnsense) && $leasesError === null && !empty($leases)): ?>
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
                                    <a href="add_dhcp_lease.php" class="btn btn-save">
                                        <i class="fa fa-plus me-1"></i> Nouveau
                                    </a>
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
                        <?php elseif (empty($leases)): ?>
                            <div class="p-4 text-center text-white-50">
                                <?= $isOpnsense ? 'Aucun bail DHCP detecte sur l instance OPNsense active.' : 'Aucun bail DHCP detecte sur le routeur actif.' ?>
                            </div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-striped table-hover table-dark mb-0 align-middle leases-table small table-standard" data-sort-table="1" data-default-sort-key="address" data-default-sort-direction="asc">
                                    <thead>
                                        <tr>
                                            <?php if ($isMikrotik): ?>
                                            <th class="leases-select-col">
                                                <input type="checkbox" class="form-check-input mt-0" id="selectAllLeases" aria-label="Tout sélectionner">
                                            </th>
                                            <?php endif; ?>
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
                                            <?= $isMikrotik ? 'class="lease-row-clickable"' : '' ?>
                                            <?= $isMikrotik ? 'data-address="' . htmlspecialchars((string)($lease['address'] ?? ''), ENT_QUOTES) . '"' : '' ?>
                                            <?= $isMikrotik ? 'data-mac="' . htmlspecialchars((string)($lease['mac'] ?? ''), ENT_QUOTES) . '"' : '' ?>
                                            <?= $isMikrotik ? 'data-host_name="' . htmlspecialchars((string)($lease['host_name'] ?? ''), ENT_QUOTES) . '"' : '' ?>
                                            <?= $isMikrotik ? 'data-server="' . htmlspecialchars((string)($lease['server'] ?? ''), ENT_QUOTES) . '"' : '' ?>
                                            <?= $isMikrotik ? 'data-comment="' . htmlspecialchars((string)($lease['comment'] ?? ''), ENT_QUOTES) . '"' : '' ?>
                                            <?= $isMikrotik ? 'data-disabled="' . (!empty($lease['disabled']) ? '1' : '0') . '"' : '' ?>
                                        >
                                            <?php if ($isMikrotik): ?>
                                            <td class="leases-select-col">
                                                <input type="checkbox" class="form-check-input lease-select" value="<?= htmlspecialchars((string)($lease['id'] ?? ''), ENT_QUOTES) ?>" aria-label="Sélectionner ce bail">
                                            </td>
                                            <?php endif; ?>
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
    <div id="spinner-overlay">
        <div class="spinner"></div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="../js/sidebar.js?v=20260402a"></script>
    <script src="../js/table_sort.js"></script>
    <script src="../js/dhcp_leases.js"></script>
</body>
</html>
