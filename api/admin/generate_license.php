<?php
session_start();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/license.php';
require_once __DIR__ . '/../../includes/device_manager.php';

header('Content-Type: application/json; charset=UTF-8');
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || !isSuperAdministrator()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Accès réservé au superadmin.']);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Méthode non autorisée.']);
    exit;
}
$csrf = trim((string)($_POST['csrf_token'] ?? ''));
if ($csrf === '' || !hash_equals((string)($_SESSION['csrf_token'] ?? ''), $csrf)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'CSRF invalide.']);
    exit;
}
$deviceId = trim((string)($_POST['device_id'] ?? ''));
$store = loadDeviceStore();
$device = requireAccessibleDevice($store, $deviceId);
$licenseType = trim((string)($_POST['license_type'] ?? 'lifetime'));
$holderName = trim((string)($_POST['holder_name'] ?? ''));
$holderContact = trim((string)($_POST['holder_contact'] ?? ''));
$holderEmail = trim((string)($_POST['holder_email'] ?? ''));
if (!in_array($licenseType, ['lifetime', 'annual', 'test'], true)) {
    http_response_code(422); echo json_encode(['success' => false, 'message' => 'Type de licence invalide.']); exit;
}
if ($holderName === '' || !filter_var($holderEmail, FILTER_VALIDATE_EMAIL) || $holderContact === '') {
    http_response_code(422); echo json_encode(['success' => false, 'message' => 'Nom, contact et e-mail du détenteur requis.']); exit;
}
$expiry = match ($licenseType) {
    'annual' => (new DateTimeImmutable('today'))->modify('+1 year')->format('Y-m-d'),
    'test' => (new DateTimeImmutable('today'))->modify('+31 days')->format('Y-m-d'),
    default => 'never',
};
if ($deviceId === '' || !preg_match('/^[A-Za-z0-9_.:-]{3,191}$/', $deviceId)) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Device ID invalide.']);
    exit;
}
if ($expiry !== 'never' && strtotime($expiry) === false) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Date d’expiration invalide.']);
    exit;
}
$fingerprint = trim((string)($device['device_fingerprint'] ?? ''));
if ($fingerprint === '') {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Testez la connexion du Device avant de générer sa licence.']);
    exit;
}
$key = generateLicenseKey($fingerprint, $expiry);
require_once __DIR__ . '/../../config/db.php';
registerGeneratedLicense($pdo, $deviceId, $key, $fingerprint, $expiry, $licenseType, $holderName, $holderContact, $holderEmail);
echo json_encode(['success' => true, 'device_id' => $deviceId, 'expiry' => $expiry, 'license_key' => $key, 'license_type' => $licenseType, 'holder_name' => $holderName, 'holder_contact' => $holderContact, 'holder_email' => $holderEmail], JSON_UNESCAPED_UNICODE);
