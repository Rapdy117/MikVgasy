<?php
session_start();

require_once '../../includes/auth.php';
require_once '../../includes/message.php';
require_once '../../config/db.php';
require_once '../../includes/recouvrement_invoices.php';

header('Content-Type: application/json; charset=UTF-8');

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Session invalide.']);
    exit();
}

try {
    requireAdministratorAccess('La creation de facture est reservee a l administrateur.');

    $csrf = trim((string)($_POST['csrf_token'] ?? ''));
    if ($csrf === '' || !hash_equals($_SESSION['csrf_token'] ?? '', $csrf)) {
        throw new RuntimeException('CSRF invalide.');
    }

    $operator = trim((string)($_POST['operator'] ?? ''));
    $dateFrom = trim((string)($_POST['date_from'] ?? ''));
    $dateTo = trim((string)($_POST['date_to'] ?? ''));
    $selectedLinesRaw = trim((string)($_POST['selected_lines'] ?? ''));

    if ($operator === '') {
        throw new RuntimeException('Revendeur manquant.');
    }

    $fromDate = DateTimeImmutable::createFromFormat('Y-m-d', $dateFrom) ?: false;
    $toDate = DateTimeImmutable::createFromFormat('Y-m-d', $dateTo) ?: false;
    if (!$fromDate || !$toDate) {
        throw new RuntimeException('Periode invalide.');
    }

    if ($fromDate > $toDate) {
        [$fromDate, $toDate] = [$toDate, $fromDate];
    }

    $selectedLines = [];
    if ($selectedLinesRaw !== '') {
        $decoded = base64_decode($selectedLinesRaw, true);
        if ($decoded === false) {
            throw new RuntimeException('Selection invalide.');
        }

        $parsed = json_decode($decoded, true);
        if (!is_array($parsed)) {
            throw new RuntimeException('Selection invalide.');
        }

        $selectedLines = normalizeRecouvrementSelectedLines($operator, $parsed);
    }

    $invoice = createRecouvrementInvoice(
        $pdo,
        $operator,
        $fromDate,
        $toDate,
        $selectedLines,
        trim((string)($_SESSION['username'] ?? ''))
    );

    echo json_encode([
        'success' => true,
        'invoice_id' => (int)$invoice['id'],
        'invoice_number' => (string)$invoice['invoice_number'],
        'print_url' => '/pages/print_recouvrement_invoice.php?invoice_id=' . (int)$invoice['id'],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage() !== '' ? $e->getMessage() : 'Creation impossible.',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
