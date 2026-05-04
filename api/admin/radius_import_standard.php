<?php

session_start();

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/operation_history.php';
require_once __DIR__ . '/../../includes/radius_standard_io.php';
require_once __DIR__ . '/../../includes/profile_schema.php';
require_once __DIR__ . '/../../includes/user_schema.php';
require_once __DIR__ . '/../../includes/backend_agent.php';

header('Content-Type: application/json; charset=UTF-8');

function radiusImportStandardFail(int $statusCode, string $message): void
{
    http_response_code($statusCode);
    echo json_encode([
        'success' => false,
        'message' => $message,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    radiusImportStandardFail(403, 'Unauthorized');
}

if (!isAdministrator()) {
    radiusImportStandardFail(403, 'Accès réservé à l administrateur');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    radiusImportStandardFail(405, 'Methode non autorisee.');
}

try {
    $csrf = trim((string)($_POST['csrf_token'] ?? ''));
    if ($csrf === '' || !hash_equals($_SESSION['csrf_token'] ?? '', $csrf)) {
        throw new RuntimeException('CSRF invalide');
    }

    $target = radiusStandardResolveTarget($pdo, (string)($_POST['device_id'] ?? ''));
    $device = $target['device'];
    $nasContext = $target['nas_context'];
    $nasType = (string)$target['nas_type'];
    $mode = radiusStandardNormalizeImportMode((string)($_POST['mode'] ?? 'skip'));
    $includeSensitive = radiusStandardNormalizeSensitiveImport((string)($_POST['include_sensitive'] ?? '0'));

    backendAgentAuthorizeDeviceAction($device, 'standard-import', [
        'source' => 'radius_standard',
        'target_nas_id' => (int)($nasContext['nas_id'] ?? 0),
        'target_nas_type' => $nasType,
        'mode' => $mode,
        'include_sensitive' => $includeSensitive,
    ]);

    $upload = $_FILES['standard_file'] ?? null;
    if (!is_array($upload) || (int)($upload['error'] ?? 0) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Fichier JSON standard requis.');
    }

    $raw = (string)file_get_contents((string)($upload['tmp_name'] ?? ''));
    $payload = json_decode($raw, true);
    if (!is_array($payload)) {
        throw new RuntimeException('JSON standard invalide.');
    }

    $document = radiusStandardParseImportDocument($payload);
    ensureOperationHistoryTable($pdo);
    if ($nasType === 'opnsense') {
        ensureUsersExtendedSchema($pdo);
        ensureProfilesExtendedSchema($pdo);
    }

    $pdo->beginTransaction();
    $result = importRadiusStandardDocument($pdo, $document, $nasContext, $mode, $includeSensitive);

    $profileSummary = $result['profiles'] ?? [];
    $userSummary = $result['users'] ?? [];
    $createdOrUpdated = (int)($profileSummary['created'] ?? 0)
        + (int)($profileSummary['updated'] ?? 0)
        + (int)($userSummary['created'] ?? 0)
        + (int)($userSummary['updated'] ?? 0);
    $errorCount = count($profileSummary['errors'] ?? []) + count($userSummary['errors'] ?? []);
    if ($createdOrUpdated === 0 && $errorCount > 0) {
        $firstError = ($profileSummary['errors'][0] ?? $userSummary['errors'][0] ?? 'Erreur interne inconnue.');
        throw new RuntimeException('Aucun element importe. Premiere erreur: ' . $firstError);
    }

    $deviceId = trim((string)($device['id'] ?? ''));
    recordOperationHistory($pdo, [
        'operation_scope' => 'admin',
        'operation_type' => 'standard_import_radius_v2',
        'actor_username' => (string)($_SESSION['username'] ?? ''),
        'actor_role' => (string)($_SESSION['user_role'] ?? 'administrator'),
        'target_type' => 'device',
        'target_name' => (string)($device['name'] ?? $device['host'] ?? $deviceId),
        'target_ref' => $deviceId,
        'device_id' => $deviceId,
        'summary' => 'Import standard OPNsense / RADIUS v2',
        'details_json' => [
            'source_backend' => (string)($document['source_backend'] ?? ''),
            'target_nas_id' => (int)($nasContext['nas_id'] ?? 0),
            'target_nas_type' => $nasType,
            'mode' => $mode,
            'include_sensitive' => $includeSensitive,
            'profiles' => $profileSummary,
            'users' => $userSummary,
        ],
    ]);

    if (!$pdo->inTransaction()) {
        throw new RuntimeException('Transaction import interrompue avant validation finale.');
    }
    $pdo->commit();

    echo json_encode([
        'success' => true,
        'message' => 'Import standard OPNsense / RADIUS termine.',
        'device_id' => $deviceId,
        'resolved_nas_id' => (int)($nasContext['nas_id'] ?? 0),
        'resolved_nas_type' => $nasType,
        'resolved_business_source' => (string)($nasContext['business_source'] ?? ''),
        'source_backend' => (string)($document['source_backend'] ?? ''),
        'profiles' => $profileSummary,
        'users' => $userSummary,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
} catch (Throwable $exception) {
    if ($pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    radiusImportStandardFail(500, $exception->getMessage());
}
