<?php
session_start();

require '../config/db.php';
require_once '../includes/device_manager.php';
require_once '../includes/message.php';
require_once '../includes/vouchers.php';
require_once '../includes/voucher_batch_service.php';
require_once '../includes/voucher_ticket_helpers.php';

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: ../index.php');
    exit();
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

ensureVouchersTable($pdo);
$deviceStore = loadDeviceStore();
$pendingVoucherBatch = $_SESSION['pending_voucher_batch'] ?? null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $csrf = trim((string)($_POST['csrf_token'] ?? ''));
        if ($csrf === '' || !hash_equals($_SESSION['csrf_token'] ?? '', $csrf)) {
            throw new RuntimeException('CSRF invalide');
        }

        $action = trim((string)($_POST['action'] ?? 'prepare'));
        if ($action === 'cancel_pending') {
            unset(
                $_SESSION['pending_voucher_batch'],
                $_SESSION['last_printed_voucher_ids'],
                $_SESSION['last_printed_voucher_ticket_options'],
                $_SESSION['last_printed_voucher_profile_defaults'],
                $_SESSION['last_printed_voucher_profile_name'],
                $_SESSION['last_printed_voucher_render_payload']
            );
            set_message('Preparation de vouchers annulee.', 'success');
            header('Location: /pages/generate.php');
            exit();
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

        $profile = resolveVoucherBatchProfile($pdo, $deviceStore, $profileId, $profileName, $deviceId);

        if (!$profile) {
            throw new RuntimeException('Profil introuvable');
        }

        $_SESSION['pending_voucher_batch'] = buildPendingVoucherBatch(
            $profile,
            $deviceId,
            $quantity,
            $prefix,
            $length,
            $ticketOptions
        );
        unset(
            $_SESSION['last_printed_voucher_ids'],
            $_SESSION['last_printed_voucher_ticket_options'],
            $_SESSION['last_printed_voucher_profile_defaults'],
            $_SESSION['last_printed_voucher_profile_name'],
            $_SESSION['last_printed_voucher_render_payload']
        );

        set_message(count($_SESSION['pending_voucher_batch']['entries'] ?? []) . ' voucher(s) préparé(s) pour le profil ' . $profile['name'] . '.', 'success');
        header('Location: /pages/generate.php');
        exit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        set_message($e->getMessage(), 'danger');
        header('Location: /pages/generate.php');
        exit();
    }
}

$devices = $deviceStore['devices'] ?? [];
$activeDeviceId = (string)($deviceStore['active_device_id'] ?? '');
$hasPendingBatch = is_array($pendingVoucherBatch) && !empty($pendingVoucherBatch['entries']);
$pendingTicketOptions = normalizeVoucherTicketOptions(
    is_array($pendingVoucherBatch['ticket_options'] ?? null) ? $pendingVoucherBatch['ticket_options'] : []
);
$defaultTicketFormat = (string)($pendingTicketOptions['format'] ?? 'small');
$defaultSsid = (string)($pendingTicketOptions['ssid'] ?? '');
$defaultDns = (string)($pendingTicketOptions['dns'] ?? '');
$defaultShowQr = !empty($pendingTicketOptions['show_qr']);
$defaultShowLogo = !empty($pendingTicketOptions['show_logo']);
$defaultLogoText = (string)($pendingTicketOptions['logo_text'] ?? '');
$defaultLogoUrl = (string)($pendingTicketOptions['logo_url'] ?? '');
// Phase 2 : plus de liste SQL des profils — chargement via API JS (profile_options.php)
?>

<?php
$pageTitle = 'Générer Vouchers';
$bodyClass = 'generate-page';
$contentClass = 'generate-shell';
$extraCss = [
    '../css/generate.css',
    '../css/voucher_ticket_shared.css?v=20260410b',
];
require_once '../includes/layout_header.php';
?>

<div class="card shadow-sm mb-3">
<div class="card-body py-3">
    <div class="d-flex align-items-center text-white generate-page-title">
        <i class="fa fa-ticket me-2"></i>
        <span class="small fw-semibold">Générer Vouchers</span>
    </div>
</div>
</div>

<form id="generateForm" class="network-device-form" method="POST" autocomplete="off" data-has-pending="<?= $hasPendingBatch ? '1' : '0' ?>">
<input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
<input type="hidden" name="profile_name" id="profileNameInput" value="">
<input type="hidden" name="action" value="prepare">
<fieldset id="generateFieldset" <?= $hasPendingBatch ? 'disabled' : '' ?>>
<div class="row g-2 generate-main-grid">
<div class="col-xl-6 col-lg-6 col-md-12 generate-col generate-col-left">
<div class="card mb-2 generate-card">
<div class="card-header">
    <i class="fa fa-ticket me-2"></i> Génération
</div>

<div class="card-body">
<div class="input-group">
<span class="input-group-text">Serveur</span>
<select class="form-select" name="device_id" required>
    <option value="">-- Choisir un serveur --</option>
    <?php foreach ($devices as $device): ?>
    <option value="<?= htmlspecialchars((string)$device['id']) ?>" <?= (($device['id'] ?? '') === $activeDeviceId) ? 'selected' : '' ?>>
        <?= htmlspecialchars((string)($device['name'] ?? 'Device')) ?>
    </option>
    <?php endforeach; ?>
</select>
</div>

<div class="input-group">
<span class="input-group-text">Profil</span>
<select class="form-select" name="profile_id" id="profileSelect" required>
    <option value="">-- Choisir un profil --</option>
</select>
</div>

<div class="input-group">
<span class="input-group-text">SSID</span>
<input type="text" class="form-control" name="ssid" id="ssidInput" value="<?= htmlspecialchars($defaultSsid) ?>" placeholder="Nom du point d'accès">
</div>

<div class="input-group">
<span class="input-group-text">DNS</span>
<input type="text" class="form-control" name="dns" id="dnsInput" value="<?= htmlspecialchars($defaultDns) ?>" placeholder="Ex : http://hotspot.wifi ou http://10.10.10.1">
</div>

<div class="input-group">
<span class="input-group-text">Quantité</span>
<input type="number" class="form-control" name="quantity" value="1" min="1" max="500">
</div>

<div class="input-group">
<span class="input-group-text">Préfixe</span>
<input type="text" class="form-control" name="prefix" placeholder="ex: USER">
</div>

<div class="input-group mb-0">
<span class="input-group-text">Longueur</span>
<input type="number" class="form-control" name="length" value="6" min="4" max="24">
</div>
</div>
</div>

<div class="card mt-2 generate-card">
    <div class="card-header">
        <i class="fa fa-layer-group me-2"></i> Hérité du profil
    </div>
    <div class="card-body">
        <div class="generate-profile-grid">
            <div class="generate-profile-col">
                <div class="input-group">
                    <span class="input-group-text">Rate limite</span>
                    <input type="text" class="form-control" id="profileFieldRateLimit" readonly value="">
                </div>
                <div class="input-group">
                    <span class="input-group-text">Limite de temps</span>
                    <input type="text" class="form-control" id="profileFieldTimeLimit" readonly value="">
                </div>
                <div class="input-group">
                    <span class="input-group-text">Limite de données</span>
                    <input type="text" class="form-control" id="profileFieldDataLimit" readonly value="">
                </div>
                <div class="input-group mb-0">
                    <span class="input-group-text">Validité</span>
                    <input type="text" class="form-control" id="profileFieldValidityTime" readonly value="">
                </div>
            </div>
            <div class="generate-profile-col">
                <div class="input-group">
                    <span class="input-group-text">Mode d'expiration</span>
                    <input type="text" class="form-control" id="profileFieldExpiredMode" readonly value="">
                </div>
                <div class="input-group">
                    <span class="input-group-text">Prix de base</span>
                    <input type="text" class="form-control" id="profileFieldPrice" readonly value="">
                </div>
                <div class="input-group mb-0">
                    <span class="input-group-text">Prix de vente</span>
                    <input type="text" class="form-control" id="profileFieldSellingPrice" readonly value="">
                </div>
            </div>
        </div>
    </div>
</div>
</div>

<div class="col-xl-6 col-lg-6 col-md-12 generate-col generate-col-right">
<div class="card mb-2 generate-card generate-card-ticket-config">
<div class="card-header">
    <i class="fa fa-sliders me-2"></i> Configuration ticket
</div>
<div class="card-body">
    <div class="generate-ticket-options generate-ticket-options-modern">
        <div class="generate-ticket-control-grid">
            <label class="generate-ticket-control">
                <span class="generate-ticket-control-label">Format</span>
                <select class="form-select" name="ticket_format" id="ticketFormatSelect">
                    <option value="small" <?= $defaultTicketFormat === 'small' ? 'selected' : '' ?>>Small</option>
                    <option value="wide" <?= $defaultTicketFormat === 'wide' ? 'selected' : '' ?>>Wide</option>
                </select>
            </label>
            <label class="generate-ticket-control">
                <span class="generate-ticket-control-label">QR code</span>
                <select class="form-select" id="ticketQrSelect">
                    <option value="off" <?= $defaultShowQr ? '' : 'selected' ?>>Masqué</option>
                    <option value="on" <?= $defaultShowQr ? 'selected' : '' ?>>Affiché</option>
                </select>
            </label>
            <label class="generate-ticket-control">
                <span class="generate-ticket-control-label">Logo</span>
                <select class="form-select" id="ticketLogoSelect">
                    <option value="off" <?= $defaultShowLogo ? '' : 'selected' ?>>Masqué</option>
                    <option value="on" <?= $defaultShowLogo ? 'selected' : '' ?>>Affiché</option>
                </select>
            </label>
        </div>

        <div class="generate-logo-uploader">
            <input type="hidden" name="logo_text" value="<?= htmlspecialchars($defaultLogoText) ?>">
            <input type="hidden" class="form-control" name="logo_url" id="logoUrlInput" value="<?= htmlspecialchars($defaultLogoUrl) ?>">
            <input type="file" id="logoFileInput" class="d-none" accept="image/png,image/jpeg,image/webp,image/svg+xml">
            <div class="generate-logo-upload-actions">
                <button type="button" class="btn btn-test" id="logoUploadBtn">
                    <i class="fa fa-upload me-1"></i> Upload logo
                </button>
                <button type="button" class="btn btn-save" id="logoEditBtn" disabled>
                    <i class="fa fa-pen me-1"></i> Modifier
                </button>
            </div>
            <div class="generate-logo-upload-meta" id="logoFileMeta">Aucun logo sélectionné</div>
        </div>

        <div class="generate-ticket-hidden-flags">
            <input type="checkbox" name="show_profile_name" checked hidden>
            <input type="checkbox" name="show_rate_limit" checked hidden>
            <input type="checkbox" name="show_price" checked hidden>
            <input type="checkbox" name="show_data_limit" checked hidden>
            <input type="checkbox" name="show_time_limit" checked hidden>
            <input type="checkbox" name="show_qr" id="showQrCheckbox" <?= $defaultShowQr ? 'checked' : '' ?> hidden>
            <input type="checkbox" name="show_logo" id="showLogoCheckbox" <?= $defaultShowLogo ? 'checked' : '' ?> hidden>
        </div>
    </div>
</div>
</div>

<div class="card mt-2 generate-card generate-card-ticket-preview">
<div class="card-header">
    <i class="fa fa-print me-2"></i> Aperçu et impression ticket
</div>
<div class="card-body">
    <?php if ($hasPendingBatch): ?>
        <?php
            $profileDefaults = is_array($pendingVoucherBatch['profile_defaults'] ?? null) ? $pendingVoucherBatch['profile_defaults'] : [];
            $ticketOptions = normalizeVoucherTicketOptions($pendingVoucherBatch['ticket_options'] ?? null);
            $previewEntry = $pendingVoucherBatch['entries'][0] ?? [];
            $previewItem = array_merge([
                'profile_name' => (string)($pendingVoucherBatch['profile_name'] ?? ''),
            ], $profileDefaults, $previewEntry);
            $previewHotspotName = trim((string)($ticketOptions['ssid'] ?? ''));
            if ($previewHotspotName === '') {
                $previewHotspotName = 'Hotspot';
            }
        ?>
        <div class="generate-preview-stage">
            <div class="generate-preview-stage-toolbar">
                <div class="generate-preview-stage-title">
                    <strong><?= (int)($pendingVoucherBatch['quantity'] ?? 0) ?></strong> ticket(s) prêt(s)
                </div>
                <div class="generate-preview-stage-meta">
                    Profil : <strong><?= htmlspecialchars((string)($pendingVoucherBatch['profile_name'] ?? '-')) ?></strong>
                </div>
            </div>
            <div class="generate-ticket-preview-wrap">
                <?= renderVoucherTicketCard($previewItem, 0, $previewHotspotName, $ticketOptions) ?>
            </div>
        </div>
        <div class="generate-note-muted mt-2">
            L’aperçu ci-dessus reflète le ticket imprimé.
        </div>
    <?php else: ?>
        <div class="generate-preview-stage generate-preview-stage-empty">
            <div class="generate-ticket-preview-empty">
                <div class="generate-note mb-2">Prépare un lot pour afficher l’aperçu exact du ticket.</div>
                <div class="generate-note-muted">L’aperçu reprendra automatiquement le format, les champs visibles, le logo et le QR code choisis.</div>
            </div>
        </div>
    <?php endif; ?>
</div>
</div>
</div>
</div>
</fieldset>

<div class="mt-2 d-flex justify-content-end generate-actions">
<button type="submit" id="prepareBtn" class="btn btn-test me-2<?= $hasPendingBatch ? ' d-none' : '' ?>">
<i class="fa fa-eye me-1"></i> Préparer
</button>
<button type="button" id="cancelPendingBtn" class="btn btn-delete<?= $hasPendingBatch ? '' : ' d-none' ?>">
<i class="fa fa-times me-1"></i> Annuler
</button>
<button type="button" id="applyAndPrintBtn" class="btn btn-success<?= $hasPendingBatch ? '' : ' d-none' ?>">
    <i class="fa fa-print me-1"></i> Appliquer &amp; Imprimer
</button>
<button type="button" id="printDisabledBtn" class="btn btn-test<?= $hasPendingBatch ? ' d-none' : '' ?>" disabled>
<i class="fa fa-print me-1"></i> Imprimer
</button>
</div>
</form>

</div>
</div>
</div>

<?php
$extraJs = array (
  0 => 'https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js',
  1 => '../js/profile_options_loader.js?v=20260417a',
  2 => '../js/generate.js',
);
require_once '../includes/layout_footer.php';
?>
