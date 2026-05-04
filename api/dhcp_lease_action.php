<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/device_manager.php';
require_once __DIR__ . '/../includes/mikrotik_backend.php';
require_once __DIR__ . '/../includes/opnsense_dhcp_leases.php';

session_start();

header('Content-Type: application/json');

function post_string(string $key): string
{
    return trim((string)($_POST[$key] ?? ''));
}

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'message' => 'Unauthorized',
    ]);
    exit;
}

$csrfToken = post_string('csrf_token');
if ($csrfToken === '' || !hash_equals((string)($_SESSION['csrf_token'] ?? ''), $csrfToken)) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'CSRF invalide',
    ]);
    exit;
}

$action = post_string('action');
$leaseIds = $_POST['lease_ids'] ?? [];
if (!is_array($leaseIds)) {
    $leaseIds = [$leaseIds];
}
$leaseIds = array_values(array_filter(array_map(static fn($value) => trim((string)$value), $leaseIds)));

if ($action === '' || $leaseIds === []) {
    http_response_code(422);
    echo json_encode([
        'success' => false,
        'message' => 'Action ou sélection manquante',
    ]);
    exit;
}

try {
    $device = requireActiveDevice();
    $activeType = strtolower((string)($device['type'] ?? ''));
    foreach ($leaseIds as $leaseId) {
        if ($activeType === 'mikrotik') {
            if ($action === 'enable') {
                setMikrotikDhcpLeaseDisabled($leaseId, false);
                continue;
            }
            if ($action === 'disable') {
                setMikrotikDhcpLeaseDisabled($leaseId, true);
                continue;
            }
            if ($action === 'delete') {
                removeMikrotikDhcpLease($leaseId);
                continue;
            }
        } elseif ($activeType === 'opnsense') {
            if ($action === 'delete') {
                removeOpnsenseDhcpLease($device, $leaseId);
            }
            throw new RuntimeException('Action DHCP non supportée sur OPNsense.');
        }

        throw new RuntimeException('Action DHCP non supportée.');
    }

    $message = match ($action) {
        'enable' => 'Baux activés.',
        'disable' => 'Baux désactivés.',
        'delete' => 'Baux supprimés.',
        default => 'Action effectuée.',
    };

    echo json_encode([
        'success' => true,
        'message' => $message,
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
    ]);
}
