<?php

function localConnectivityHost(string $host): string
{
    $host = strtolower(trim(explode(':', $host, 2)[0] ?? ''));
    return rtrim($host, '.');
}

function localConnectivityPath(string $uri): string
{
    $path = parse_url($uri, PHP_URL_PATH);
    return strtolower((string)($path ?: '/'));
}

function localConnectivitySend(int $status, string $body = '', string $contentType = 'text/plain; charset=utf-8'): void
{
    http_response_code($status);
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('X-Radius-Manager-Local-Reply: connectivity');
    if ($status !== 204) {
        header('Content-Type: ' . $contentType);
        header('Content-Length: ' . strlen($body));
        echo $body;
    }
    exit;
}

const LOCAL_CONNECTIVITY_AUTHORIZED_CACHE_TTL = 30;

/**
 * N'autorise la réponse « Succès » que pour une IP déjà authentifiée sur le Hotspot : un appareil
 * pas encore connecté doit continuer à voir l'échec de détection de connectivité, sinon son système
 * n'ouvre plus automatiquement la page de connexion du portail captif.
 * Cache court (30 s) pour ne pas ouvrir une connexion API MikroTik à chaque sonde de chaque client.
 */
function localConnectivityClientIsAuthorized(string $clientIp): bool
{
    if ($clientIp === '') {
        return false;
    }

    $cacheFile = sys_get_temp_dir() . '/local_connectivity_authorized_ips.json';
    $addresses = null;
    if (is_file($cacheFile) && (time() - filemtime($cacheFile)) <= LOCAL_CONNECTIVITY_AUTHORIZED_CACHE_TTL) {
        $cached = json_decode((string)file_get_contents($cacheFile), true);
        if (is_array($cached)) {
            $addresses = $cached;
        }
    }

    if ($addresses === null) {
        $addresses = [];
        try {
            global $pdo;
            require_once __DIR__ . '/../config/db.php';
            require_once __DIR__ . '/crypto.php';
            require_once __DIR__ . '/device_manager.php';
            require_once __DIR__ . '/mikrotik_backend.php';
            $device = $pdo->query("SELECT * FROM network_devices WHERE is_active = 1 AND type = 'mikrotik' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
            if (is_array($device)) {
                $device['verify_ssl'] = (bool)($device['verify_ssl'] ?? false);
                $device['hardware_info'] = [];
                $device = decryptFields(normalizeDeviceRecord($device), DEVICE_SENSITIVE_FIELDS);
                $api = connectToMikrotikApiByDevice($device);
                try {
                    $rows = $api->comm('/ip/hotspot/active/print', ['.proplist' => 'address']);
                    if (is_array($rows)) {
                        foreach ($rows as $row) {
                            $address = trim((string)($row['address'] ?? ''));
                            if ($address !== '') {
                                $addresses[] = $address;
                            }
                        }
                    }
                } finally {
                    $api->disconnect();
                }
            }
        } catch (Throwable) {
            // Échec de lecture RouterOS : on ne répond pas « Succès » par prudence (voir l'appelant).
            return false;
        }
        file_put_contents($cacheFile, json_encode(array_values(array_unique($addresses))));
    }

    return in_array($clientIp, $addresses, true);
}

/** Adresse Hotspot RouterOS lue en base (pas de valeur figée si le routeur change un jour). */
function localConnectivityHotspotAddress(): string
{
    $fallback = '10.10.10.1';
    try {
        global $pdo;
        require_once __DIR__ . '/../config/db.php';
        $host = $pdo->query("SELECT host FROM network_devices WHERE is_active = 1 AND type = 'mikrotik' LIMIT 1")->fetchColumn();
        $host = trim((string)$host);
        return $host !== '' ? $host : $fallback;
    } catch (Throwable) {
        return $fallback;
    }
}

function localConnectivityRedirectToHotspot(): void
{
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Location: http://' . localConnectivityHotspotAddress() . '/');
    http_response_code(302);
    exit;
}

function localConnectivityResponderMaybeHandle(): void
{
    $host = localConnectivityHost((string)($_SERVER['HTTP_HOST'] ?? ''));
    $path = localConnectivityPath((string)($_SERVER['REQUEST_URI'] ?? '/'));

    $androidHosts = ['connectivitycheck.gstatic.com', 'clients3.google.com', 'clients4.google.com', 'www.google.com'];
    $windowsHosts = ['www.msftconnecttest.com', 'msftconnecttest.com', 'dns.msftncsi.com', 'www.msftncsi.com'];
    $appleHosts = ['captive.apple.com', 'www.apple.com', 'www.airport.us'];

    // Un domaine connu suffit à déclencher ce traitement, quel que soit le chemin exact : Windows a
    // au moins deux chemins de sonde (/connecttest.txt, /redirect), Apple en a plusieurs aussi selon
    // la version d'iOS/macOS. Une liste de chemins figée laisse passer les cas non prévus droit vers
    // la page de connexion admin — exactement le bug signalé sur /redirect.
    $isKnownHost = in_array($host, $androidHosts, true) || in_array($host, $windowsHosts, true) || in_array($host, $appleHosts, true);
    if (!$isKnownHost) {
        return;
    }

    // Client pas encore authentifié : ne jamais répondre nous-mêmes. `10.10.10.2` est exempté du
    // portail (walled garden, nécessaire pour charger la vraie page de connexion) — sans ce renvoi
    // explicite vers le Hotspot, une sonde de connectivité qui résout ici atterrirait directement
    // sur la page de connexion admin au lieu du portail client. Le Hotspot RouterOS doit toujours
    // être la première chose qu'un appareil non authentifié rencontre.
    if (!localConnectivityClientIsAuthorized((string)($_SERVER['REMOTE_ADDR'] ?? ''))) {
        localConnectivityRedirectToHotspot();
    }

    if (in_array($host, $androidHosts, true) && in_array($path, ['/generate_204', '/gen_204'], true)) {
        localConnectivitySend(204);
    }

    if (in_array($host, $windowsHosts, true) && in_array($path, ['/connecttest.txt', '/ncsi.txt', '/redirect'], true)) {
        localConnectivitySend(200, $path === '/ncsi.txt' ? 'Microsoft NCSI' : 'Microsoft Connect Test');
    }

    if (in_array($host, $appleHosts, true) && in_array($path, ['/hotspot-detect.html', '/library/test/success.html'], true)) {
        localConnectivitySend(200, '<HTML><HEAD><TITLE>Success</TITLE></HEAD><BODY>Success</BODY></HTML>', 'text/html; charset=utf-8');
    }

    // Chemin reconnu comme appartenant à l'hôte mais pas à la liste de sondes connues : on ne sait
    // pas quoi répondre de précis, mais le client est authentifié, donc répondre par un succès neutre
    // reste plus sûr que de laisser passer vers la page de connexion admin.
    localConnectivitySend(204);
}
