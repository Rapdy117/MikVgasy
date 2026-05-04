<?php
session_start();

require_once '../includes/message.php';
require_once '../includes/auth.php';
require_once '../config/db.php';
require_once '../includes/recouvrement_invoices.php';

requireAdministratorAccess('Le suivi facture est réservé à l administrateur.');

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$invoiceItems = [];

try {
    ensureRecouvrementInvoicesTable($pdo);

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $csrf = trim((string)($_POST['csrf_token'] ?? ''));
        if ($csrf === '' || !hash_equals($_SESSION['csrf_token'] ?? '', $csrf)) {
            throw new RuntimeException('CSRF invalide.');
        }

        $action = trim((string)($_POST['action'] ?? ''));
        if ($action === 'mark_invoice_paid') {
            $invoiceId = (int)($_POST['invoice_id'] ?? 0);
            if ($invoiceId <= 0) {
                throw new RuntimeException('Facture invalide.');
            }

            if (markRecouvrementInvoicePaid($pdo, $invoiceId, trim((string)($_SESSION['username'] ?? '')))) {
                set_message('Facture marquee comme payee.', 'success');
            } else {
                set_message('Aucune mise a jour effectuee pour cette facture.', 'warning');
            }

            header('Location: /pages/recouvrement_invoices.php');
            exit();
        }
    }

    $invoiceItems = listRecouvrementInvoices($pdo, 200);
} catch (Throwable $e) {
    $invoiceItems = [];
}
?>

<?php
$pageTitle = 'Suivi Facture';
$htmlClass = 'recouvrement-invoices-page';
$bodyClass = 'recouvrement-invoices-page';
$extraCss = [
    '../css/recouvrement_invoices.css',
];
require_once '../includes/layout_header.php';
?>

<div class="card shadow-sm administration-card recouvrement-invoices-main-card">
    <div class="card-header recouvrement-invoices-toolbar d-flex justify-content-between align-items-center">
        <span><i class="fa fa-file-invoice-dollar me-2"></i> Suivi paiement facture</span>
        <a href="/pages/recouvrement.php" class="btn btn-test btn-sm">
            <i class="fa fa-hand-holding-dollar me-1"></i> Retour recouvrement
        </a>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive recouvrement-invoices-table-scroll">
            <table class="table table-dark table-hover table-striped mb-0 administration-table recouvrement-invoice-table table-standard" data-sort-table="1" data-default-sort-key="created" data-default-sort-direction="desc">
                <thead>
                    <tr>
                        <th data-sort-key="number" data-sort-type="text">Facture</th>
                        <th data-sort-key="operator" data-sort-type="text">Revendeur</th>
                        <th data-sort-key="period_from" data-sort-type="date">Du</th>
                        <th data-sort-key="period_to" data-sort-type="date">Au</th>
                        <th data-sort-key="amount" data-sort-type="currency">Montant</th>
                        <th data-sort-key="status" data-sort-type="text">Statut</th>
                        <th data-sort-key="created" data-sort-type="date">Créée le</th>
                        <th data-sort-key="paid" data-sort-type="date">Réglée le</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!$invoiceItems): ?>
                        <tr><td colspan="9" class="text-center">Aucune facture enregistrée</td></tr>
                    <?php else: ?>
                        <?php foreach ($invoiceItems as $invoiceItem): ?>
                            <?php
                                $invoiceSummaryItem = json_decode((string)($invoiceItem['summary_json'] ?? ''), true);
                                $invoiceAmount = (float)($invoiceSummaryItem['total_amount'] ?? 0);
                                $invoiceStatus = trim((string)($invoiceItem['status'] ?? 'pending'));
                                $statusLabel = $invoiceStatus === 'paid' ? 'Payee' : 'En attente';
                                $statusClass = $invoiceStatus === 'paid' ? 'bg-success' : 'bg-warning text-dark';
                            ?>
                            <tr>
                                <td><?= htmlspecialchars((string)($invoiceItem['invoice_number'] ?? '-')) ?></td>
                                <td><?= htmlspecialchars((string)($invoiceItem['operator_username'] ?? '-')) ?></td>
                                <td><?= htmlspecialchars((string)($invoiceItem['period_from'] ?? '-')) ?></td>
                                <td><?= htmlspecialchars((string)($invoiceItem['period_to'] ?? '-')) ?></td>
                                <td><?= htmlspecialchars(number_format($invoiceAmount, 2, ',', ' ')) ?></td>
                                <td><span class="badge <?= $statusClass ?> administration-status-badge"><?= htmlspecialchars($statusLabel) ?></span></td>
                                <td><?= htmlspecialchars((string)($invoiceItem['created_at'] ?? '-')) ?></td>
                                <td><?= htmlspecialchars((string)($invoiceItem['paid_at'] ?? '-')) ?></td>
                                <td class="text-center">
                                    <a href="/pages/print_recouvrement_invoice.php?invoice_id=<?= (int)($invoiceItem['id'] ?? 0) ?>" target="_blank" class="btn btn-test btn-sm">
                                        <i class="fa fa-print"></i>
                                    </a>
                                    <?php if ($invoiceStatus !== 'paid'): ?>
                                    <form method="POST" class="d-inline">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string)$_SESSION['csrf_token']) ?>">
                                        <input type="hidden" name="action" value="mark_invoice_paid">
                                        <input type="hidden" name="invoice_id" value="<?= (int)($invoiceItem['id'] ?? 0) ?>">
                                        <button type="submit" class="btn btn-save btn-sm">
                                            <i class="fa fa-check"></i>
                                        </button>
                                    </form>
                                    <?php endif; ?>
                                </td>
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
