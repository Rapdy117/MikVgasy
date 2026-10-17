<?php
// Source unique des événements Hotspot affichés sur le tableau de bord (page et flux live).
require_once __DIR__ . '/hotspot_mac_labels.php';

/**
 * Transforme les lignes du journal RouterOS en événements lisibles.
 * - une MAC est reconnue avant le découpage « utilisateur : action » (elle contient des deux-points) ;
 * - les tentatives de login par MAC qui échouent sont masquées ;
 * - une MAC connue est affichée avec son libellé ($macLabels : MAC => libellé).
 * $timeLabelOf : fonction qui transforme l'horodatage RouterOS en heure affichée.
 */
function hotspotLogEventsFromRows(array $rows, int $limit, array $macLabels, callable $timeLabelOf): array
{
    $keywords = ['trying to log in', 'logged in', 'logged out', 'login failed', 'keepalive timeout', 'user request', 'idle timeout', 'session timeout'];
    $events = [];
    $seen = [];
    foreach (array_reverse($rows) as $row) {
        if (!is_array($row)) {
            continue;
        }
        $message = trim((string)($row['message'] ?? ''));
        if ($message === '') {
            continue;
        }
        $topics = strtolower(trim((string)($row['topics'] ?? '')));
        if (!str_contains($topics, 'hotspot')) {
            continue;
        }
        $lowerMessage = strtolower($message);
        $isUseful = false;
        foreach ($keywords as $keyword) {
            if (str_contains($lowerMessage, $keyword)) {
                $isUseful = true;
                break;
            }
        }
        if (!$isUseful) {
            continue;
        }

        $clean = trim((string)preg_replace('/^\-\>\:\s*/', '', $message));
        $timeLabel = (string)$timeLabelOf((string)($row['time'] ?? ''));
        if (preg_match('/^(?<mac>(?:[0-9A-Fa-f]{2}:){5}[0-9A-Fa-f]{2})\s*\([^)]*\)\s*:\s*(?<action>.+)$/', $clean, $macMatches)) {
            $macAddress = strtoupper($macMatches['mac']);
            $action = trim((string)$macMatches['action']);
            if (str_starts_with(strtolower($action), 'trying to log in') || str_starts_with(strtolower($action), 'login failed')) {
                continue;
            }
            $userLabel = $macLabels[$macAddress] ?? $macAddress;
        } elseif (preg_match('/^(?<user>[^:]+?)\s*:\s*(?<action>.+)$/', $clean, $matches)) {
            $userLabel = trim((string)$matches['user']);
            $action = trim((string)$matches['action']);
        } else {
            $userLabel = '-';
            $action = trim($clean);
        }
        if ($action === '') {
            continue;
        }

        $signature = strtolower(($timeLabel !== '' ? $timeLabel : '--') . '|' . $userLabel . '|' . $action);
        if (isset($seen[$signature])) {
            continue;
        }
        $seen[$signature] = true;

        $events[] = [
            'time' => $timeLabel !== '' ? $timeLabel : '--',
            'user' => $userLabel !== '' ? $userLabel : '-',
            'action' => preg_replace('/\s+/', ' ', $action),
        ];
        if (count($events) >= $limit) {
            break;
        }
    }
    return $events;
}

/** Libellés MAC du device actif (vide si indisponible). */
function hotspotActiveMacLabels(): array
{
    $activeDevice = getActiveDeviceContext()['device'] ?? null;
    if (!is_array($activeDevice) || empty($activeDevice['id']) || !isset($GLOBALS['pdo'])) {
        return [];
    }
    try {
        return getHotspotMacLabels($GLOBALS['pdo'], (string)$activeDevice['id']);
    } catch (Throwable $e) {
        return [];
    }
}
