<?php
session_start();

require_once '../includes/auth.php';
require_once '../config/db.php';
require_once '../includes/recouvrement_invoices.php';

requireAdministratorAccess('La facture de recouvrement est réservée à l administrateur.');

$invoiceId = (int)($_GET['invoice_id'] ?? 0);
$operator = trim((string)($_GET['operator'] ?? ''));
$dateFrom = trim((string)($_GET['date_from'] ?? gmdate('Y-m-01')));
$dateTo = trim((string)($_GET['date_to'] ?? gmdate('Y-m-d')));
$selectedLinesRaw = trim((string)($_GET['selected_lines'] ?? ''));
$invoiceNumber = '';
$invoiceStatus = 'pending';
$invoiceCreatedAt = gmdate('Y-m-d H:i:s');
$invoicePaidAt = null;
$invoicePaidBy = null;
$selectedLines = [];
$invoiceSummary = [
    'operator' => $operator,
    'period_from' => $dateFrom,
    'period_to' => $dateTo,
    'total_recharges' => 0,
    'total_amount' => 0.0,
    'users_count' => 0,
    'profiles_count' => 0,
    'voucher_batches' => 0,
    'voucher_total' => 0,
    'commercial_operations' => 0,
];
$movementItems = [];

try {
    if ($invoiceId > 0) {
        $invoice = getRecouvrementInvoice($pdo, $invoiceId);
        if (!$invoice) {
            http_response_code(404);
            echo 'Facture introuvable.';
            exit();
        }

        $invoiceNumber = trim((string)($invoice['invoice_number'] ?? ''));
        $invoiceStatus = trim((string)($invoice['status'] ?? 'pending')) ?: 'pending';
        $invoiceCreatedAt = trim((string)($invoice['created_at'] ?? '')) ?: gmdate('Y-m-d H:i:s');
        $invoicePaidAt = trim((string)($invoice['paid_at'] ?? '')) ?: null;
        $invoicePaidBy = trim((string)($invoice['paid_by'] ?? '')) ?: null;
        $operator = trim((string)($invoice['operator_username'] ?? ''));

        $summaryJson = json_decode((string)($invoice['summary_json'] ?? ''), true);
        if (is_array($summaryJson)) {
            $invoiceSummary = array_merge($invoiceSummary, $summaryJson);
        }

        $movementsJson = json_decode((string)($invoice['movements_json'] ?? ''), true);
        if (is_array($movementsJson)) {
            $movementItems = $movementsJson;
        }

        $selectedJson = json_decode((string)($invoice['selected_lines_json'] ?? ''), true);
        if (is_array($selectedJson)) {
            $selectedLines = $selectedJson;
        }
    } else {
        if ($operator === '') {
            http_response_code(400);
            echo 'Revendeur manquant.';
            exit();
        }

        $fromDate = DateTimeImmutable::createFromFormat('Y-m-d', $dateFrom) ?: false;
        $toDate = DateTimeImmutable::createFromFormat('Y-m-d', $dateTo) ?: false;
        if (!$fromDate || !$toDate) {
            http_response_code(400);
            echo 'Periode invalide.';
            exit();
        }

        if ($fromDate > $toDate) {
            [$fromDate, $toDate] = [$toDate, $fromDate];
        }

        if ($selectedLinesRaw !== '') {
            $decoded = base64_decode($selectedLinesRaw, true);
            if ($decoded !== false) {
                $parsed = json_decode($decoded, true);
                if (is_array($parsed)) {
                    $selectedLines = normalizeRecouvrementSelectedLines($operator, $parsed);
                }
            }
        }

        $snapshot = calculateRecouvrementInvoiceSnapshot($pdo, $operator, $fromDate, $toDate, $selectedLines);
        $invoiceSummary = $snapshot['summary'];
        $movementItems = $snapshot['movements'];
        $selectedLines = $snapshot['selected_lines'];
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo 'Impossible de generer la facture.';
    exit();
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Facture revendeur - <?= htmlspecialchars($invoiceNumber !== '' ? $invoiceNumber : $operator) ?></title>
<style>
    :root {
        color-scheme: light;
    }

    body {
        margin: 0;
        padding: 10mm;
        background: #ffffff;
        color: #111827;
        font-family: Arial, Helvetica, sans-serif;
        font-size: 12px;
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
    }

    .toolbar {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 14px;
    }

    .toolbar button {
        border: 1px solid #111827;
        background: #ffffff;
        color: #111827;
        padding: 8px 12px;
        font-size: 12px;
        cursor: pointer;
    }

    .invoice-shell {
        border: 2px solid #111827;
        padding: 16px;
    }

    .invoice-head {
        display: flex;
        justify-content: space-between;
        gap: 16px;
        border-bottom: 2px solid #111827;
        padding-bottom: 12px;
        margin-bottom: 12px;
    }

    .invoice-title {
        font-size: 22px;
        font-weight: 700;
        margin-bottom: 4px;
        text-transform: uppercase;
    }

    .invoice-subtitle,
    .invoice-meta {
        line-height: 1.5;
    }

    .summary-grid {
        display: grid;
        grid-template-columns: repeat(4, minmax(0, 1fr));
        gap: 8px;
        margin-bottom: 16px;
    }

    .summary-card {
        border: 1px solid #111827;
        padding: 10px;
    }

    .summary-label {
        font-size: 11px;
        text-transform: uppercase;
        letter-spacing: 0.04em;
        color: #4b5563;
        margin-bottom: 4px;
    }

    .summary-value {
        font-size: 18px;
        font-weight: 700;
    }

    .section-title {
        font-size: 14px;
        font-weight: 700;
        margin: 18px 0 8px;
        text-transform: uppercase;
    }

    table {
        width: 100%;
        border-collapse: collapse;
    }

    th,
    td {
        border: 1px solid #111827;
        padding: 7px 8px;
        vertical-align: middle;
    }

    th {
        background: #f3f4f6;
        text-align: left;
        font-size: 11px;
        text-transform: uppercase;
    }

    td.is-center,
    th.is-center {
        text-align: center;
    }

    td.is-right,
    th.is-right {
        text-align: right;
    }

    .empty-state {
        border: 1px solid #111827;
        padding: 10px;
    }

    .invoice-foot {
        margin-top: 18px;
        display: flex;
        justify-content: space-between;
        gap: 16px;
        align-items: flex-end;
    }

    .invoice-total-box {
        min-width: 260px;
        border: 2px solid #111827;
        padding: 10px 12px;
    }

    .invoice-total-label {
        font-size: 11px;
        text-transform: uppercase;
        color: #4b5563;
    }

    .invoice-total-value {
        font-size: 24px;
        font-weight: 700;
        margin-top: 4px;
    }

    @media print {
        .toolbar {
            display: none;
        }

        @page {
            size: auto;
            margin: 8mm;
        }
    }
</style>
</head>
<body>
<div class="toolbar">
    <strong>Facture revendeur - <?= htmlspecialchars($invoiceNumber !== '' ? $invoiceNumber : $operator) ?></strong>
    <button type="button" onclick="window.print()">Imprimer</button>
</div>

<div class="invoice-shell">
    <div class="invoice-head">
        <div>
            <div class="invoice-title">Facture Regroupement</div>
            <div class="invoice-subtitle">Tous les mouvements du revendeur sur la periode</div>
        </div>
        <div class="invoice-meta">
            <?php if ($invoiceNumber !== ''): ?>
            <div><strong>Facture :</strong> <?= htmlspecialchars($invoiceNumber) ?></div>
            <?php endif; ?>
            <div><strong>Revendeur :</strong> <?= htmlspecialchars($operator) ?></div>
            <div><strong>Du :</strong> <?= htmlspecialchars((string)$invoiceSummary['period_from']) ?></div>
            <div><strong>Au :</strong> <?= htmlspecialchars((string)$invoiceSummary['period_to']) ?></div>
            <div><strong>Edition :</strong> <?= htmlspecialchars($invoiceCreatedAt) ?></div>
            <div><strong>Statut :</strong> <?= htmlspecialchars($invoiceStatus === 'paid' ? 'Payee' : 'En attente') ?></div>
            <?php if ($invoicePaidAt): ?>
            <div><strong>Reglee le :</strong> <?= htmlspecialchars($invoicePaidAt) ?><?= $invoicePaidBy ? ' par ' . htmlspecialchars($invoicePaidBy) : '' ?></div>
            <?php endif; ?>
        </div>
    </div>

    <div class="summary-grid">
        <div class="summary-card">
            <div class="summary-label">Recharges</div>
            <div class="summary-value"><?= (int)$invoiceSummary['total_recharges'] ?></div>
        </div>
        <div class="summary-card">
            <div class="summary-label">Montant</div>
            <div class="summary-value"><?= htmlspecialchars(number_format((float)$invoiceSummary['total_amount'], 2, ',', ' ')) ?></div>
        </div>
        <div class="summary-card">
            <div class="summary-label">Lots vouchers</div>
            <div class="summary-value"><?= (int)$invoiceSummary['voucher_batches'] ?></div>
        </div>
        <div class="summary-card">
            <div class="summary-label">Vouchers</div>
            <div class="summary-value"><?= (int)$invoiceSummary['voucher_total'] ?></div>
        </div>
    </div>

    <div class="section-title">Mouvements chronologiques</div>
    <?php if (!$movementItems): ?>
        <div class="empty-state">Aucun mouvement commercial trouve sur cette periode.</div>
    <?php else: ?>
        <table>
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Type</th>
                    <th>Cible</th>
                    <th>Profil</th>
                    <th>Description</th>
                    <th class="is-center">Quantite</th>
                    <th class="is-right">Montant</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($movementItems as $item): ?>
                    <?php
                    $isRecharge = (string)($item['movement_type'] ?? '') === 'recharge';
                    $quantityLabel = $isRecharge ? '-' : (string)(int)($item['quantity'] ?? 0);
                    $amountLabel = $isRecharge ? number_format((float)($item['amount_value'] ?? 0), 2, ',', ' ') : '-';
                    $targetLabel = $isRecharge
                        ? (string)($item['target_name'] ?? '-')
                        : ((string)($item['first_username'] ?? '-') . ' -> ' . (string)($item['last_username'] ?? '-'));
                    $typeLabel = $isRecharge ? 'Recharge' : 'Lot vouchers';
                    ?>
                    <tr>
                        <td><?= htmlspecialchars((string)($item['created_at'] ?? '-')) ?></td>
                        <td><?= htmlspecialchars($typeLabel) ?></td>
                        <td><?= htmlspecialchars($targetLabel) ?></td>
                        <td><?= htmlspecialchars((string)($item['profile_name'] ?? '-')) ?></td>
                        <td><?= htmlspecialchars((string)($item['summary'] ?? '-')) ?></td>
                        <td class="is-center"><?= htmlspecialchars($quantityLabel) ?></td>
                        <td class="is-right"><?= htmlspecialchars($amountLabel) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

    <div class="invoice-foot">
        <div>
            <div><strong>Profils touches :</strong> <?= (int)$invoiceSummary['profiles_count'] ?></div>
            <div><strong>Utilisateurs touches :</strong> <?= (int)$invoiceSummary['users_count'] ?></div>
            <div><strong>Operations commerciales :</strong> <?= (int)$invoiceSummary['commercial_operations'] ?></div>
        </div>
        <div class="invoice-total-box">
            <div class="invoice-total-label">Total recouvrement periode</div>
            <div class="invoice-total-value"><?= htmlspecialchars(number_format((float)$invoiceSummary['total_amount'], 2, ',', ' ')) ?></div>
        </div>
    </div>
</div>
</body>
</html>
