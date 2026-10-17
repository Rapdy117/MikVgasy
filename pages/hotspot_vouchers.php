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
if (empty($_SESSION['csrf_token'])) { $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); }

ensureVouchersTable($pdo);
$voucherSyncResult = syncVoucherUsage($pdo);
purgeVoucherOverflow($pdo, 5000);
$role = strtolower(trim((string)($_SESSION['user_role'] ?? 'admin')));
$tenantUserId = max(0, (int)($_SESSION['local_admin_id'] ?? 0));
$ownerAdminId = $role === 'reseller' ? max(0, (int)($_SESSION['owner_admin_id'] ?? 0)) : $tenantUserId;
$deviceStore = loadDeviceStore();
$devices = is_array($deviceStore['devices'] ?? null) ? $deviceStore['devices'] : [];
$deviceById = [];
foreach ($devices as $device) $deviceById[(string)($device['id'] ?? '')] = $device;
$deviceId = trim((string)($_GET['device_id'] ?? ''));
$status = trim((string)($_GET['status'] ?? ''));
$printedBy = trim((string)($_GET['printed_by'] ?? ''));
$profileFilter = trim((string)($_GET['profile'] ?? ''));
$search = trim((string)($_GET['q'] ?? ''));
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 25;
$where = [];
$params = [];
if ($role === 'reseller') { $where[] = 'v.owner_admin_id = :owner_admin_id AND v.created_by_user_id = :created_by_user_id'; $params[':owner_admin_id']=$ownerAdminId; $params[':created_by_user_id']=$tenantUserId; }
elseif ($role !== 'superadmin') { $where[] = 'v.owner_admin_id = :owner_admin_id'; $params[':owner_admin_id']=$ownerAdminId; }
if ($deviceId !== '') { if (!isset($deviceById[$deviceId])) { http_response_code(403); exit('Device non autorise'); } $where[]='v.device_id = :device_id'; $params[':device_id']=$deviceId; }
if ($status === 'used') $where[]='v.used=1'; elseif ($status === 'available') $where[]='(v.used=0 OR v.used IS NULL)';
if ($printedBy !== '') { $where[]='v.printed_by = :printed_by'; $params[':printed_by']=$printedBy; }
if ($profileFilter !== '') { $where[]='v.profile_name = :profile_name'; $params[':profile_name']=$profileFilter; }
if ($search !== '') { $where[]='(v.code LIKE :search OR v.username LIKE :search OR v.used_by LIKE :search)'; $params[':search']='%'.$search.'%'; }
$scopeSql = $where ? ' WHERE '.implode(' AND ', $where) : '';
$countStmt=$pdo->prepare('SELECT COUNT(*) FROM vouchers v'.$scopeSql); $countStmt->execute($params); $totalRows=min(5000,(int)$countStmt->fetchColumn());
$totalPages=max(1,(int)ceil($totalRows/$perPage)); $page=min($page,$totalPages); $offset=($page-1)*$perPage;
$listStmt=$pdo->prepare("SELECT v.id,v.code,v.username,v.password,v.printed_by,v.used,v.used_by,v.used_at,v.used_device_id,v.device_id,v.created_at,v.profile_name,p.name AS joined_profile_name FROM vouchers v LEFT JOIN profiles p ON v.profile_id=p.id {$scopeSql} ORDER BY v.created_at DESC,v.id DESC LIMIT {$perPage} OFFSET {$offset}"); $listStmt->execute($params); $items=$listStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
$usedVouchers=0; foreach($items as $item) if(!empty($item['used'])) $usedVouchers++; $totalVouchers=$totalRows; $availableVouchers=max(0,$totalVouchers-$usedVouchers);
$optionWhere = $where; $optionParams=$params;
$profileFilterOptions=[]; $printedByFilterOptions=[];
$optStmt=$pdo->prepare('SELECT DISTINCT v.profile_name,v.printed_by FROM vouchers v'.$scopeSql.' ORDER BY v.profile_name,v.printed_by LIMIT 5000'); $optStmt->execute($optionParams); foreach($optStmt->fetchAll(PDO::FETCH_ASSOC) as $opt){ if(trim((string)$opt['profile_name'])!=='')$profileFilterOptions[(string)$opt['profile_name']]=true; if(trim((string)$opt['printed_by'])!=='')$printedByFilterOptions[(string)$opt['printed_by']]=true; }
krsort($profileFilterOptions); ksort($printedByFilterOptions);
?>

<?php
$pageTitle = 'Hotspot Vouchers';
$contentClass = 'hotspot-vouchers-page';
require_once '../includes/layout_header.php';
?>
<style>
.voucher-page-title {
            font-size: calc(0.875rem + 2px);
        }

        .voucher-search-group {
            width: 190px;
            max-width: 190px;
            flex: 0 0 190px;
        }

        .voucher-filter-select {
            width: 140px;
            max-width: 140px;
            flex: 0 0 140px;
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
            position: relative;
            max-height: 68vh;
            overflow-y: auto;
        }

        .hotspot-vouchers-page .vouchers-table tbody tr:hover,
        .hotspot-vouchers-page .vouchers-table tbody tr:hover > * {
            background-color: inherit !important;
            color: inherit;
        }


        .vouchers-table-wrap thead th {
            position: sticky;
            top: 0;
            z-index: 3;
            backdrop-filter: blur(6px);
            -webkit-backdrop-filter: blur(6px);
            background: rgba(14, 22, 36, 0.94);
            box-shadow: 0 1px 0 rgba(23, 162, 184, 0.28);
        }

        .hotspot-vouchers-page {
            min-height: 0 !important;
            padding-bottom: 0 !important;
        }

        .hotspot-vouchers-page .pagination .page-link {
            background: transparent;
            border-color: var(--theme-border);
            color: var(--theme-text);
            transition: background-color .3s ease, border-color .3s ease, color .3s ease, transform .3s ease, box-shadow .3s ease;
        }

        .hotspot-vouchers-page .pagination .page-link:hover,
        .hotspot-vouchers-page .pagination .page-link:focus-visible {
            background: var(--theme-primary-hover);
            border-color: var(--theme-primary);
            color: var(--theme-primary);
            transform: translateY(-1px);
            box-shadow: 0 3px 8px rgba(23, 162, 184, 0.16);
        }

        .hotspot-vouchers-page .pagination .page-item.active .page-link {
            background: var(--theme-primary-soft);
            border-color: var(--theme-primary);
            color: var(--theme-primary);
        }

        .hotspot-vouchers-page .pagination .page-item.disabled .page-link {
            background: transparent;
            border-color: var(--theme-border);
            color: var(--theme-text-muted);
            opacity: .65;
        }

        .hotspot-vouchers-page > .card {
            background-color: transparent !important;
            transition: background-color .3s ease, border-color .3s ease, box-shadow .3s ease, transform .3s ease;
        }

        .hotspot-vouchers-page > .card:hover {
            background-color: rgba(23, 162, 184, 0.04) !important;
            border-color: var(--theme-primary) !important;
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(23, 162, 184, 0.16);
        }

        .voucher-table-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            flex-wrap: wrap;
        }

        .voucher-filters-inline {
            display: flex;
            align-items: center;
            justify-content: flex-end;
            gap: 8px;
            flex-wrap: wrap;
            margin-left: auto;
        }

        .voucher-filters-inline form {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: nowrap;
            margin: 0;
        }

        @media (max-width: 1200px) {
            .voucher-filters-inline,
            .voucher-filters-inline form {
                flex-wrap: wrap;
            }
        }


        .voucher-head-stats {
            display: grid;
            grid-template-columns: repeat(4, minmax(110px, 1fr));
            gap: 8px;
            flex: 1 1 auto;
            margin-left: auto;
        }

        .voucher-summary-stats {
            flex: 0 0 auto;
            width: auto;
            margin-left: 0;
            justify-content: start;
            grid-template-columns: repeat(4, minmax(76px, 84px));
            gap: 6px;
        }

        .voucher-mini-card {
            border: 1px solid rgba(148, 163, 184, 0.18);
            border-radius: 10px;
            background: rgba(12, 20, 34, 0.72);
            padding: 4px 6px;
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
            font-size: 13px;
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

            <div class="card shadow-sm">
                <div class="card-header voucher-table-header">
                    <div class="d-flex align-items-center text-white voucher-page-title">
                        <i class="fas fa-ticket-alt me-2"></i>
                        <span class="small fw-semibold">Hotspot Vouchers</span>
                    </div>
                    <div class="voucher-filters-inline">
                        <form id="voucherFiltersForm" class="d-flex align-items-center gap-2 flex-wrap" method="get">
                        <div class="input-group voucher-search-group">
                            <span class="input-group-text"><i class="fa fa-search"></i></span>
                            <input type="text" class="form-control" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="Rechercher un voucher...">
                        </div>
                        <select class="form-select voucher-filter-select" name="profile">
                            <option value="">Tous les profils</option>
                            <?php foreach (array_keys($profileFilterOptions) as $profileName): ?>
                                <option value="<?= htmlspecialchars($profileName) ?>" <?= $profileFilter===$profileName?'selected':'' ?>><?= htmlspecialchars($profileName) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <select class="form-select voucher-filter-select" name="printed_by">
                            <option value="">Tous les imprimés par</option>
                            <?php foreach (array_keys($printedByFilterOptions) as $printedByOption): ?>
                                <option value="<?= htmlspecialchars($printedByOption) ?>" <?= $printedBy===$printedByOption?'selected':'' ?>><?= htmlspecialchars($printedByOption) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <select class="form-select voucher-filter-select" name="device_id"><option value="">Tous les Devices</option><?php foreach($devices as $d): ?><option value="<?= htmlspecialchars((string)$d['id']) ?>" <?= $deviceId===(string)$d['id']?'selected':'' ?>><?= htmlspecialchars((string)($d['name']??$d['id'])) ?></option><?php endforeach; ?></select>
                        <select class="form-select voucher-filter-select" name="status"><option value="">Tous les statuts</option><option value="available" <?= $status==='available'?'selected':'' ?>>Disponible</option><option value="used" <?= $status==='used'?'selected':'' ?>>Utilisé</option></select>
                        </form>
                        <?php if (in_array($role, ['admin', 'superadmin'], true)): ?><button type="button" class="btn btn-delete btn-sm" id="deleteSelectedVouchers" disabled><i class="fa fa-trash"></i> Supprimer</button><?php endif; ?>
                    </div>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive vouchers-table-wrap" id="vouchersTableWrap">
                        <table class="table table-dark table-hover table-striped mb-0 vouchers-table table-standard" data-sort-table="1" data-default-sort-key="date" data-default-sort-direction="desc">
                            <thead>
                                <tr>
                                    <th><input type="checkbox" id="voucherSelectAll"></th><th data-sort-key="id" data-sort-type="number">ID</th>
                                        <th data-sort-key="username" data-sort-type="text">Utilisateur</th>
                                        <th data-sort-key="password" data-sort-type="text">Password</th>
                                        <th data-sort-key="profile" data-sort-type="text">Profil</th>
                                    <th data-sort-key="printed_by" data-sort-type="text">Imprimé par</th>
                                    <th data-sort-key="status" data-sort-type="text">Statut</th>
                                    <th data-sort-key="used_by" data-sort-type="text">Utilisé par</th>
                                    <th data-sort-key="used_at" data-sort-type="date">Premier login</th>
                                    <th data-sort-key="date" data-sort-type="date">Créé le</th><th>Device</th>
                                </tr>
                            </thead>
                            <tbody id="voucherTableBody">
                                <?php if (!$items): ?>
                                    <tr data-sort-disabled="1">
                                        <td colspan="10" class="text-center">Aucun voucher généré</td>
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
                                            <td><input type="checkbox" class="voucher-select" value="<?= $rowId ?>"></td><td><?= $rowId ?></td>
                                            <td><?= htmlspecialchars($rowUsername) ?></td>
                                            <td><?= htmlspecialchars($rowPassword) ?></td>
                                            <td><?= htmlspecialchars($rowProfile) ?></td>
                                            <td><?= htmlspecialchars($rowPrintedBy) ?></td>
                                            <td><?= !empty($item['used']) ? '<span class="badge bg-warning">Utilisé</span>' : '<span class="badge bg-success">Disponible</span>' ?></td>
                                            <td><?= htmlspecialchars($rowUsedBy) ?></td>
                                            <td><?= htmlspecialchars($rowUsedAt) ?></td>
                                            <td><?= htmlspecialchars($rowCreatedAt) ?></td><td><?= htmlspecialchars((string)($deviceById[(string)($item['device_id'] ?? '')]['name'] ?? $item['device_id'] ?? '-')) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="card-footer d-flex align-items-center justify-content-between flex-wrap gap-2">
                    <div class="voucher-head-stats voucher-summary-stats">
                        <div class="voucher-mini-card"><span class="voucher-mini-label">Vouchers</span><span class="voucher-mini-value"><?= $totalVouchers ?></span></div>
                        <div class="voucher-mini-card"><span class="voucher-mini-label">Disponibles</span><span class="voucher-mini-value"><?= $availableVouchers ?></span></div>
                        <div class="voucher-mini-card"><span class="voucher-mini-label">Utilisés</span><span class="voucher-mini-value"><?= $usedVouchers ?></span></div>
                        <div class="voucher-mini-card"><span class="voucher-mini-label">Synchronisés</span><span class="voucher-mini-value"><?= (int)($voucherSyncResult['updated'] ?? 0) ?></span></div>
                    </div>
                    <div class="d-flex justify-content-end align-items-center flex-wrap gap-2 ms-auto">
                        <nav aria-label="Pagination vouchers"><ul class="pagination pagination-sm mb-0">
                        <?php for ($pageNumber = 1; $pageNumber <= $totalPages; $pageNumber++): ?>
                            <?php if ($pageNumber === 1 || $pageNumber === $totalPages || abs($pageNumber - $page) <= 2): ?>
                                <li class="page-item <?= $pageNumber === $page ? 'active' : '' ?>"><a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $pageNumber])) ?>"><?= $pageNumber ?></a></li>
                            <?php elseif ($pageNumber === 2 || $pageNumber === $totalPages - 1): ?>
                                <li class="page-item disabled"><span class="page-link">…</span></li>
                            <?php endif; ?>
                        <?php endfor; ?>
                        </ul></nav>
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
?>
<script>document.addEventListener('DOMContentLoaded',()=>{const all=document.getElementById('voucherSelectAll');const boxes=()=>Array.from(document.querySelectorAll('.voucher-select'));const del=document.getElementById('deleteSelectedVouchers');const sync=()=>{const selected=boxes().filter(b=>b.checked).length;if(del)del.disabled=selected===0;if(all)all.checked=boxes().length>0&&selected===boxes().length;};boxes().forEach(b=>b.addEventListener('change',sync));if(all)all.addEventListener('change',()=>{boxes().forEach(b=>b.checked=all.checked);sync();});sync();if(del)del.addEventListener('click',async()=>{const ids=boxes().filter(b=>b.checked).map(b=>b.value);if(!ids.length||!confirm('Supprimer les vouchers sélectionnés ?'))return;const r=await fetch('../api/vouchers/delete_batch.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({csrf_token:'<?= htmlspecialchars($_SESSION['csrf_token']??'',ENT_QUOTES) ?>',ids})});const j=await r.json();if(j.success)location.reload();else alert(j.message||'Suppression impossible');});const form=document.getElementById('voucherFiltersForm');let timer=null;form?.querySelectorAll('select').forEach(el=>el.addEventListener('change',()=>form.submit()));const search=form?.querySelector('input[name=q]');search?.addEventListener('input',()=>{clearTimeout(timer);timer=setTimeout(()=>form.submit(),350);});});</script>
<?php
require_once '../includes/layout_footer.php';
?>
