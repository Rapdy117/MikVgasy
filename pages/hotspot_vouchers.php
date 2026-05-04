<?php
session_start();

require_once '../includes/message.php';
require_once '../config/db.php';
require_once '../includes/vouchers.php';

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    set_message('Veuillez vous connecter pour accéder à cette page.', 'danger');
    header('Location: ../index.php');
    exit();
}

ensureVouchersTable($pdo);
$voucherSyncResult = syncVoucherUsage($pdo);

$stmt = $pdo->query("
    SELECT v.id, v.code, v.username, v.password, v.printed_by, v.used, v.used_by, v.used_at, v.created_at, p.name AS profile_name
    FROM vouchers v
    LEFT JOIN profiles p ON v.profile_id = p.id
    ORDER BY v.created_at DESC, v.id DESC
    LIMIT 200
");
$items = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
$totalVouchers = count($items);
$usedVouchers = count(array_filter($items, static fn(array $item): bool => !empty($item['used'])));
$availableVouchers = max(0, $totalVouchers - $usedVouchers);
$profileFilterOptions = [];
$printedByFilterOptions = [];
foreach ($items as $item) {
    $profileValue = trim((string)($item['profile_name'] ?? ''));
    $printedByValue = trim((string)($item['printed_by'] ?? ''));
    if ($profileValue !== '') {
        $profileFilterOptions[$profileValue] = true;
    }
    if ($printedByValue !== '') {
        $printedByFilterOptions[$printedByValue] = true;
    }
}
krsort($profileFilterOptions);
krsort($printedByFilterOptions);
?>

<?php
$pageTitle = 'Hotspot Vouchers';
require_once '../includes/layout_header.php';
?>
<style>
.voucher-page-title {
            font-size: calc(0.875rem + 2px);
        }

        .voucher-search-group {
            max-width: 320px;
        }

        .voucher-filter-select {
            max-width: 220px;
            background: rgba(12, 20, 34, 0.82);
            border-color: rgba(148, 163, 184, 0.18);
            color: var(--theme-text);
        }

        .voucher-filter-select:focus {
            border-color: rgba(59, 130, 246, 0.45);
            box-shadow: 0 0 0 0.2rem rgba(59, 130, 246, 0.12);
        }

        .voucher-search-group .input-group-text {
            background: rgba(59, 130, 246, 0.12);
            border-color: rgba(148, 163, 184, 0.18);
            color: var(--theme-text);
        }

        .voucher-search-group .form-control {
            background: rgba(12, 20, 34, 0.82);
            border-color: rgba(148, 163, 184, 0.18);
            color: var(--theme-text);
        }

        .voucher-search-group .form-control::placeholder {
            color: rgba(226, 232, 240, 0.55);
        }

        .voucher-search-group .form-control:focus {
            border-color: rgba(59, 130, 246, 0.45);
            box-shadow: 0 0 0 0.2rem rgba(59, 130, 246, 0.12);
        }

        .vouchers-table thead th {
            text-align: center;
            font-size: 14px;
        }

        .vouchers-table tbody td {
            font-size: 14px;
            vertical-align: middle;
        }

        .vouchers-table tbody td:nth-child(2),
        .vouchers-table tbody td:nth-child(3),
        .vouchers-table tbody td:nth-child(4),
        .vouchers-table tbody td:nth-child(6),
        .vouchers-table tbody td:nth-child(7),
        .vouchers-table tbody td:nth-child(8),
        .vouchers-table tbody td:nth-child(9) {
            text-align: left;
        }

        .vouchers-table-wrap {
            max-height: 68vh;
            overflow-y: hidden;
        }

        .vouchers-table-wrap.is-scrollable {
            overflow-y: auto;
        }

        .vouchers-table-wrap thead th {
            position: sticky;
            top: 0;
            z-index: 2;
            backdrop-filter: blur(2px);
            background: rgba(14, 22, 36, 0.82);
        }

        .voucher-head-inline {
            display: flex;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
        }

        .voucher-head-stats {
            display: grid;
            grid-template-columns: repeat(4, minmax(110px, 1fr));
            gap: 8px;
            flex: 1 1 auto;
            margin-left: auto;
        }

        .voucher-mini-card {
            border: 1px solid rgba(148, 163, 184, 0.18);
            border-radius: 10px;
            background: rgba(12, 20, 34, 0.72);
            padding: 6px 8px;
            text-align: center;
        }

        .voucher-mini-label {
            color: rgba(226, 232, 240, 0.7);
            font-size: 11px;
            line-height: 1.1;
            display: block;
        }

        .voucher-mini-value {
            color: #fff;
            font-size: 14px;
            font-weight: 700;
            display: block;
            margin-top: 2px;
        }
</style>

<?php if (($voucherSyncResult['updated'] ?? 0) > 0): ?>
                <div class="alert alert-info">
                    <?= (int)$voucherSyncResult['updated'] ?> voucher(s) synchronisé(s) sur le premier login détecté.
                </div>
            <?php endif; ?>

            <div class="card shadow-sm mb-3">
                <div class="card-body py-3">
                    <div class="voucher-head-inline">
                        <div class="d-flex align-items-center text-white voucher-page-title">
                            <i class="fas fa-ticket-alt me-2"></i>
                            <span class="small fw-semibold">Hotspot Vouchers</span>
                        </div>
                        <div class="voucher-head-stats">
                            <div class="voucher-mini-card">
                                <span class="voucher-mini-label">Vouchers</span>
                                <span class="voucher-mini-value"><?= $totalVouchers ?></span>
                            </div>
                            <div class="voucher-mini-card">
                                <span class="voucher-mini-label">Disponibles</span>
                                <span class="voucher-mini-value"><?= $availableVouchers ?></span>
                            </div>
                            <div class="voucher-mini-card">
                                <span class="voucher-mini-label">Utilisés</span>
                                <span class="voucher-mini-value"><?= $usedVouchers ?></span>
                            </div>
                            <div class="voucher-mini-card">
                                <span class="voucher-mini-label">Synchronisés</span>
                                <span class="voucher-mini-value"><?= (int)($voucherSyncResult['updated'] ?? 0) ?></span>
                            </div>
                        </div>
                        <a href="/pages/generate.php" class="btn btn-save">
                            <i class="fa fa-plus me-1"></i> Nouveau
                        </a>
                    </div>
                </div>
            </div>

            <div class="card mb-3">
                <div class="card-body py-2">
                    <div class="d-flex align-items-center gap-2 flex-wrap">
                        <div class="input-group voucher-search-group">
                            <span class="input-group-text"><i class="fa fa-search"></i></span>
                            <input type="text" class="form-control" id="voucherSearchInput" placeholder="Rechercher un voucher...">
                        </div>
                        <select class="form-select voucher-filter-select" id="voucherProfileFilter">
                            <option value="">Tous les profils</option>
                            <?php foreach (array_keys($profileFilterOptions) as $profileName): ?>
                                <option value="<?= htmlspecialchars($profileName) ?>"><?= htmlspecialchars($profileName) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <select class="form-select voucher-filter-select" id="voucherPrintedByFilter">
                            <option value="">Tous les imprimés par</option>
                            <?php foreach (array_keys($printedByFilterOptions) as $printedBy): ?>
                                <option value="<?= htmlspecialchars($printedBy) ?>"><?= htmlspecialchars($printedBy) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </div>

            <div class="card shadow-sm">
                <div class="card-body p-0">
                    <div class="table-responsive vouchers-table-wrap" id="vouchersTableWrap">
                        <table class="table table-dark table-hover table-striped mb-0 vouchers-table table-standard" data-sort-table="1" data-default-sort-key="date" data-default-sort-direction="desc">
                            <thead>
                                <tr>
                                    <th data-sort-key="id" data-sort-type="number">ID</th>
                                        <th data-sort-key="username" data-sort-type="text">Username</th>
                                        <th data-sort-key="password" data-sort-type="text">Password</th>
                                        <th data-sort-key="profile" data-sort-type="text">Profil</th>
                                    <th data-sort-key="printed_by" data-sort-type="text">Imprimé par</th>
                                    <th data-sort-key="status" data-sort-type="text">Statut</th>
                                    <th data-sort-key="used_by" data-sort-type="text">Utilisé par</th>
                                    <th data-sort-key="used_at" data-sort-type="date">Premier login</th>
                                    <th data-sort-key="date" data-sort-type="date">Créé le</th>
                                </tr>
                            </thead>
                            <tbody id="voucherTableBody">
                                <?php if (!$items): ?>
                                    <tr data-sort-disabled="1">
                                        <td colspan="9" class="text-center">Aucun voucher généré</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($items as $item): ?>
                                        <?php
                                            $rowId = (int)($item['id'] ?? 0);
                                            $rowUsername = (string)($item['username'] ?? $item['code'] ?? '-');
                                            $rowPassword = (string)($item['password'] ?? '-');
                                            $rowProfile = (string)($item['profile_name'] ?? '-');
                                            $rowPrintedBy = (string)($item['printed_by'] ?? '-');
                                            $rowStatus = !empty($item['used']) ? 'utilise' : 'disponible';
                                            $rowUsedBy = (string)($item['used_by'] ?? '-');
                                            $rowUsedAt = (string)($item['used_at'] ?? '-');
                                            $rowCreatedAt = (string)($item['created_at'] ?? '-');
                                        ?>
                                        <tr
                                            data-id="<?= htmlspecialchars((string)$rowId, ENT_QUOTES) ?>"
                                            data-username="<?= htmlspecialchars($rowUsername, ENT_QUOTES) ?>"
                                            data-password="<?= htmlspecialchars($rowPassword, ENT_QUOTES) ?>"
                                            data-profile="<?= htmlspecialchars($rowProfile, ENT_QUOTES) ?>"
                                            data-printed_by="<?= htmlspecialchars($rowPrintedBy, ENT_QUOTES) ?>"
                                            data-status="<?= htmlspecialchars($rowStatus, ENT_QUOTES) ?>"
                                            data-used_by="<?= htmlspecialchars($rowUsedBy, ENT_QUOTES) ?>"
                                            data-used_at="<?= htmlspecialchars($rowUsedAt, ENT_QUOTES) ?>"
                                            data-date="<?= htmlspecialchars($rowCreatedAt, ENT_QUOTES) ?>"
                                        >
                                            <td><?= $rowId ?></td>
                                            <td><?= htmlspecialchars($rowUsername) ?></td>
                                            <td><?= htmlspecialchars($rowPassword) ?></td>
                                            <td><?= htmlspecialchars($rowProfile) ?></td>
                                            <td><?= htmlspecialchars($rowPrintedBy) ?></td>
                                            <td><?= !empty($item['used']) ? '<span class="badge bg-warning">Utilisé</span>' : '<span class="badge bg-success">Disponible</span>' ?></td>
                                            <td><?= htmlspecialchars($rowUsedBy) ?></td>
                                            <td><?= htmlspecialchars($rowUsedAt) ?></td>
                                            <td><?= htmlspecialchars($rowCreatedAt) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>


<?php
$extraJs = array (
  0 => '../js/table_sort.js',
);
require_once '../includes/layout_footer.php';
?>
