<?php

header('Content-Type: application/json');

session_start();

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Non autorisé']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Méthode non autorisée']);
    exit;
}

try {
    $csrf = trim((string)($_POST['csrf_token'] ?? ''));
    if ($csrf === '' || !hash_equals($_SESSION['csrf_token'] ?? '', $csrf)) {
        throw new RuntimeException('CSRF invalide');
    }

    unset(
        $_SESSION['pending_voucher_batch'],
        $_SESSION['last_printed_voucher_ids'],
        $_SESSION['last_printed_voucher_ticket_options']
    );

    ob_start();
    ?>
    <div class="generate-preview-stage generate-preview-stage-empty">
        <div class="generate-ticket-preview-empty">
            <div class="generate-note mb-2">Prépare un lot pour afficher l’aperçu exact du ticket.</div>
            <div class="generate-note-muted">L’aperçu reprendra automatiquement le format, les champs visibles, le logo et le QR code choisis.</div>
        </div>
    </div>
    <?php
    $previewBlockHtml = (string)ob_get_clean();

    echo json_encode([
        'success' => true,
        'message' => 'Préparation annulée.',
        'preview_block_html' => $previewBlockHtml,
    ]);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => trim((string)$e->getMessage()) !== '' ? trim((string)$e->getMessage()) : 'Annulation impossible.',
    ]);
}
