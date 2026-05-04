<?php

require_once '../../config/db.php';
require_once '../../includes/device_manager.php';
require_once '../../includes/voucher_batch_service.php';
require_once '../../includes/voucher_ticket_helpers.php';

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

    $profileId = (int)($_POST['profile_id'] ?? 0);
    $profileName = trim((string)($_POST['profile_name'] ?? ''));
    $deviceId = trim((string)($_POST['device_id'] ?? ''));
    $quantity = max(1, min(500, (int)($_POST['quantity'] ?? 1)));
    $prefix = trim((string)($_POST['prefix'] ?? ''));
    $length = max(4, min(24, (int)($_POST['length'] ?? 6)));
    $ticketOptions = normalizePostedVoucherTicketOptions($_POST);

    if ($profileId <= 0 && $profileName === '') {
        throw new RuntimeException('Choisissez un profil');
    }

    $deviceStore = loadDeviceStore();
    $profile = resolveVoucherBatchProfile($pdo, $deviceStore, $profileId, $profileName, $deviceId);

    if (!$profile) {
        throw new RuntimeException('Profil introuvable');
    }

    $batch = buildPendingVoucherBatch(
        $profile,
        $deviceId,
        $quantity,
        $prefix,
        $length,
        $ticketOptions
    );

    $_SESSION['pending_voucher_batch'] = $batch;
    unset($_SESSION['last_printed_voucher_ids'], $_SESSION['last_printed_voucher_ticket_options']);

    $profileDefaults = is_array($batch['profile_defaults'] ?? null) ? $batch['profile_defaults'] : [];
    $ticketOptionsNormalized = normalizeVoucherTicketOptions($batch['ticket_options'] ?? null);
    $previewHotspotName = trim((string)($ticketOptionsNormalized['ssid'] ?? ''));
    if ($previewHotspotName === '') {
        $previewHotspotName = 'Hotspot';
    }
    $previewEntry = $batch['entries'][0] ?? [];
    $previewItem = array_merge([
        'profile_name' => (string)($batch['profile_name'] ?? ''),
    ], $profileDefaults, $previewEntry);

    ob_start();
    ?>
    <div class="generate-preview-stage">
        <div class="generate-preview-stage-toolbar">
            <div class="generate-preview-stage-title">
                <strong><?= (int)($batch['quantity'] ?? 0) ?></strong> ticket(s) prêt(s)
            </div>
            <div class="generate-preview-stage-meta">
                Profil : <strong><?= htmlspecialchars((string)($batch['profile_name'] ?? '-')) ?></strong>
            </div>
        </div>
        <div class="generate-ticket-preview-wrap">
            <?= renderVoucherTicketCard($previewItem, 0, $previewHotspotName, $ticketOptionsNormalized) ?>
        </div>
    </div>
    <div class="generate-note-muted mt-2">
        L’aperçu ci-dessus reflète le ticket imprimé.
    </div>
    <?php
    $previewBlockHtml = (string)ob_get_clean();

    echo json_encode([
        'success' => true,
        'message' => count($batch['entries'] ?? []) . ' voucher(s) préparé(s) pour le profil ' . (string)$profile['name'] . '.',
        'preview_block_html' => $previewBlockHtml,
    ]);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => trim((string)$e->getMessage()) !== '' ? trim((string)$e->getMessage()) : 'Préparation impossible.',
    ]);
}
