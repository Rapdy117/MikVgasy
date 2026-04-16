<?php
session_start();

require_once '../config/db.php';
require_once '../includes/message.php';
require_once '../includes/vouchers.php';
require_once '../includes/voucher_ticket_helpers.php';

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: ../index.php');
    exit();
}

ensureVouchersTable($pdo);

$items = [];
$sessionRenderPayload = is_array($_SESSION['last_printed_voucher_render_payload'] ?? null)
    ? $_SESSION['last_printed_voucher_render_payload']
    : [];
$sessionTicketOptions = is_array($sessionRenderPayload['ticket_options'] ?? null)
    ? $sessionRenderPayload['ticket_options']
    : ($_SESSION['last_printed_voucher_ticket_options'] ?? []);
$sessionProfileDefaults = is_array($_SESSION['last_printed_voucher_profile_defaults'] ?? null)
    ? $_SESSION['last_printed_voucher_profile_defaults']
    : [];
$sessionProfileName = trim((string)($_SESSION['last_printed_voucher_profile_name'] ?? ''));
$sessionFormat = trim((string)($sessionTicketOptions['format'] ?? 'small'));
$requestedFormat = trim((string)($_GET['format'] ?? ''));
$format = in_array($requestedFormat, ['small', 'wide'], true)
    ? $requestedFormat
    : (in_array($sessionFormat, ['small', 'wide'], true) ? $sessionFormat : 'small');
$ticketOptions = normalizeVoucherTicketOptions(array_merge(
    is_array($sessionTicketOptions) ? $sessionTicketOptions : [],
    ['format' => $format]
));
$hotspotName = trim((string)($ticketOptions['ssid'] ?? ''));
if ($hotspotName === '') {
    $hotspotName = 'Hotspot';
}

if (is_array($sessionRenderPayload['items'] ?? null) && $sessionRenderPayload['items'] !== []) {
    $items = $sessionRenderPayload['items'];
} else {
    $voucherIds = $_SESSION['last_printed_voucher_ids'] ?? [];
    if (!is_array($voucherIds)) {
        $voucherIds = [];
    }
    $voucherIds = array_values(array_filter(array_map('intval', $voucherIds), static function ($id): bool {
        return (int)$id > 0;
    }));

    if ($voucherIds === []) {
        set_message('Aucun voucher a imprimer.', 'warning');
        header('Location: /pages/generate.php');
        exit;
    }

    $placeholders = implode(',', array_fill(0, count($voucherIds), '?'));
    $stmt = $pdo->prepare("
        SELECT
            v.id,
            v.code,
            v.username,
            v.password,
            v.printed_by,
            v.created_at,
            p.name AS profile_name,
            p.rate_limit,
            p.validity_time,
            p.session_timeout,
            p.data_quota_mb,
            p.price,
            p.selling_price
        FROM vouchers v
        LEFT JOIN profiles p ON v.profile_id = p.id
        WHERE v.id IN ($placeholders)
        ORDER BY v.id ASC
    ");
    $stmt->execute($voucherIds);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

if ($items !== []) {
    $fallbackKeys = ['rate_limit', 'validity_time', 'session_timeout', 'data_quota_mb', 'price', 'selling_price'];
    foreach ($items as &$item) {
        foreach ($fallbackKeys as $key) {
            $value = $item[$key] ?? null;
            $isMissing = $value === null || (is_string($value) && trim($value) === '');
            if ($isMissing && array_key_exists($key, $sessionProfileDefaults) && $sessionProfileDefaults[$key] !== null && $sessionProfileDefaults[$key] !== '') {
                $item[$key] = $sessionProfileDefaults[$key];
            }
        }

        $profileNameValue = trim((string)($item['profile_name'] ?? ''));
        if ($profileNameValue === '' && $sessionProfileName !== '') {
            $item['profile_name'] = $sessionProfileName;
        }
    }
    unset($item);
} else {
    set_message('Aucun voucher a imprimer.', 'warning');
    header('Location: /pages/generate.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Impression vouchers</title>
<style>
    body {
        margin: 0;
        padding: 0;
        background: #ffffff;
        color: #111827;
        font-family: Arial, Helvetica, sans-serif;
        font-size: 12px;
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
    }

    .print-toolbar {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 12px;
    }

    .print-toolbar button {
        border: 1px solid #1f2937;
        background: #ffffff;
        color: #111827;
        padding: 7px 12px;
        font-size: 12px;
        cursor: pointer;
    }

    .voucher-grid {
        display: grid;
        gap: 0;
        align-content: start;
        justify-items: center;
        align-items: center;
    }

    body.format-small .voucher-grid {
        grid-template-columns: repeat(4, 50mm);
        grid-auto-rows: 28.5mm;
    }

    @media print {
        .print-toolbar {
            display: none;
        }

        @page {
            size: auto;
            margin-left: 7mm;
            margin-right: 3mm;
            margin-top: 9mm;
            margin-bottom: 3mm;
        }

        table {
            page-break-after: auto;
        }

        tr,
        td {
            page-break-inside: avoid;
            page-break-after: auto;
        }
    }
</style>
<link rel="stylesheet" href="../css/voucher_ticket_shared.css?v=20260410b">
</head>
<body class="format-<?= htmlspecialchars((string)$ticketOptions['format']) ?>">
<div class="print-toolbar">
    <strong>Impression vouchers - format <?= htmlspecialchars((string)$ticketOptions['format']) ?></strong>
    <button type="button" onclick="window.print()">Imprimer</button>
</div>

<div class="voucher-grid">
    <?php foreach ($items as $index => $item): ?>
        <?= renderVoucherTicketCard($item, $index, $hotspotName, $ticketOptions) ?>
    <?php endforeach; ?>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.voucher-qr[data-qr-text]').forEach(function (node) {
        const payload = String(node.getAttribute('data-qr-text') || '').trim();
        if (!payload || typeof QRCode === 'undefined') {
            return;
        }

        node.innerHTML = '';
        new QRCode(node, {
            text: payload,
            width: 80,
            height: 80
        });
    });

    const shouldAutoPrint = new URLSearchParams(window.location.search).get('autoprint') === '1';
    if (shouldAutoPrint) {
        window.setTimeout(function () {
            window.print();
        }, 180);
    }
});
</script>
</body>
</html>
