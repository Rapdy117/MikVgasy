<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/device_manager.php';
require_once __DIR__ . '/../includes/mikrotik_backend.php';

session_start();
header('Content-Type: application/json; charset=utf-8');

function conformityActionReply(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function conformityActionIp(string $value): string
{
    $ip = trim(explode('/', trim($value), 2)[0]);
    return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) ? $ip : '';
}

function conformityActionMac(string $value): string
{
    $mac = strtoupper(trim($value));
    return preg_match('/^[0-9A-F]{2}(?::[0-9A-F]{2}){5}$/', $mac) ? $mac : '';
}

// Filtre côté RouterOS par .id (une ligne renvoyée) plutôt que de rapatrier toute la table pour la
// filtrer en PHP : plus rapide et surtout indépendant de la taille de la table ARP/Hotspot host.
function conformityFindRouterOsRow($api, string $path, string $id, array $proplist): array
{
    $rows = $api->comm($path . '/print', ['?.id' => $id, '.proplist' => implode(',', $proplist)]);
    if (!is_array($rows) || isset($rows['!trap']) || !isset($rows[0]) || !is_array($rows[0])) return [];
    return $rows[0];
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') conformityActionReply(['success' => false, 'message' => 'Méthode non autorisée.'], 405);
    if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) conformityActionReply(['success' => false, 'message' => 'Unauthorized'], 403);
    if (!isAdministrator()) conformityActionReply(['success' => false, 'message' => 'Accès réservé à l administrateur.'], 403);

    $payload = json_decode((string)file_get_contents('php://input'), true);
    if (!is_array($payload)) throw new InvalidArgumentException('Payload invalide.');
    $token = trim((string)($payload['csrf_token'] ?? ''));
    if ($token === '' || !hash_equals((string)($_SESSION['csrf_token'] ?? ''), $token)) {
        conformityActionReply(['success' => false, 'message' => 'CSRF invalide.'], 403);
    }

    $scope = trim((string)($payload['scope'] ?? ''));
    $id = trim((string)($payload['id'] ?? ''));
    if ($id === '' || !preg_match('/^[*A-Za-z0-9_.:-]+$/', $id)) throw new InvalidArgumentException('ID RouterOS invalide.');

    $device = requireActiveDevice();
    if (normalizeDeviceType((string)($device['type'] ?? '')) !== 'mikrotik') throw new InvalidArgumentException('Action disponible seulement pour MikroTik.');

    $api = connectToMikrotikApiByDevice($device);
    try {
        // L'action part d'un instantané : l'entrée RouterOS doit encore correspondre à l'IP/MAC affichées.
        $expectedIp = conformityActionIp((string)($payload['ip'] ?? ''));
        $expectedMac = conformityActionMac((string)($payload['mac'] ?? ''));
        $assertStillMatches = static function (array $row, string $label) use ($expectedIp, $expectedMac): void {
            if ($expectedIp === '' || $expectedMac === ''
                || conformityActionIp((string)($row['address'] ?? '')) !== $expectedIp
                || conformityActionMac((string)($row['mac-address'] ?? '')) !== $expectedMac) {
                throw new RuntimeException('Refus : ' . $label . ' ne correspond plus à l’anomalie affichée. Mettez à jour l’inventaire.');
            }
        };

        if ($scope === 'arp') {
            $row = conformityFindRouterOsRow($api, '/ip/arp', $id, ['.id', 'address', 'mac-address', 'dynamic']);
            if ($row === []) throw new RuntimeException('Entrée ARP introuvable.');
            if (!mikrotikApiBoolean($row['dynamic'] ?? false)) throw new RuntimeException('Refus : ARP non dynamique.');
            $assertStillMatches($row, 'l’entrée ARP');
            mikrotikAssertNoTrap($api->comm('/ip/arp/remove', ['.id' => $id]), 'Suppression ARP refusée.');
            conformityActionReply(['success' => true, 'message' => 'Entrée ARP dynamique supprimée.']);
        }

        if ($scope === 'hotspot_host') {
            $row = conformityFindRouterOsRow($api, '/ip/hotspot/host', $id, ['.id', 'address', 'mac-address']);
            if ($row === []) throw new RuntimeException('Host Hotspot introuvable.');
            $assertStillMatches($row, 'le Host Hotspot');
            mikrotikAssertNoTrap($api->comm('/ip/hotspot/host/remove', ['.id' => $id]), 'Suppression Host Hotspot refusée.');
            conformityActionReply(['success' => true, 'message' => 'Host Hotspot supprimé.']);
        }

        if ($scope === 'ip_cleanup') {
            $ip = conformityActionIp((string)($payload['ip'] ?? $id));
            $wrongMac = conformityActionMac((string)($payload['mac'] ?? ''));
            $arpMac = conformityActionMac((string)($payload['arp_mac'] ?? '')) ?: $wrongMac;
            $hostMac = conformityActionMac((string)($payload['hotspot_host_mac'] ?? '')) ?: $wrongMac;
            $arpId = trim((string)($payload['arp_id'] ?? ''));
            $hostId = trim((string)($payload['hotspot_host_id'] ?? ''));
            if ($ip === '' || $arpMac === '' || $hostMac === '' || $arpId === '' || $hostId === '') {
                throw new InvalidArgumentException('Correction IP incomplète.');
            }
            if (!preg_match('/^[*A-Za-z0-9_.:-]+$/', $arpId) || !preg_match('/^[*A-Za-z0-9_.:-]+$/', $hostId)) {
                throw new InvalidArgumentException('ID RouterOS invalide.');
            }

            $arpRow = conformityFindRouterOsRow($api, '/ip/arp', $arpId, ['.id', 'address', 'mac-address', 'dynamic']);
            if ($arpRow === []) throw new RuntimeException('Entrée ARP introuvable.');
            if (!mikrotikApiBoolean($arpRow['dynamic'] ?? false)) throw new RuntimeException('Refus : ARP non dynamique.');
            if (conformityActionIp((string)($arpRow['address'] ?? '')) !== $ip || conformityActionMac((string)($arpRow['mac-address'] ?? '')) !== $arpMac) {
                throw new RuntimeException('Refus : l’entrée ARP ne correspond plus à l’incohérence affichée.');
            }

            $hostRow = conformityFindRouterOsRow($api, '/ip/hotspot/host', $hostId, ['.id', 'address', 'mac-address']);
            if ($hostRow === []) throw new RuntimeException('Host Hotspot introuvable.');
            if (conformityActionIp((string)($hostRow['address'] ?? '')) !== $ip || conformityActionMac((string)($hostRow['mac-address'] ?? '')) !== $hostMac) {
                throw new RuntimeException('Refus : le Host Hotspot ne correspond plus à l’incohérence affichée.');
            }

            mikrotikAssertNoTrap($api->comm('/ip/hotspot/host/remove', ['.id' => $hostId]), 'Suppression Host Hotspot refusée.');
            mikrotikAssertNoTrap($api->comm('/ip/arp/remove', ['.id' => $arpId]), 'Suppression ARP refusée.');
            conformityActionReply(['success' => true, 'message' => 'ARP dynamique et Host Hotspot supprimés pour cette IP.']);
        }
    } finally {
        $api->disconnect();
    }

    throw new InvalidArgumentException('Action non supportée.');
} catch (Throwable $e) {
    conformityActionReply(['success' => false, 'message' => trim((string)$e->getMessage()) ?: 'Action impossible.'], $e instanceof InvalidArgumentException ? 422 : 500);
}
