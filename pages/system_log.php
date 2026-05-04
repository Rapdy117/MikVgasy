<?php
session_start(); // Démarre la session pour gérer l'état de connexion
require_once '../includes/auth.php';
require_once '../includes/device_manager.php';
require_once '../includes/mikrotik_backend.php';
require_once '../includes/opnsense_shaper.php';

// Inclure la fonction de message
require_once '../includes/message.php'; // Chemin correct depuis le dossier 'dashboard'

// Vérifie si l'utilisateur est connecté. Si non, redirige vers la page de connexion.
// La variable de session 'logged_in' est maintenant cohérente avec index.php
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    set_message('Veuillez vous connecter pour accéder à cette page.', 'danger'); // Utilisation de set_message
    header('Location: ../index.php'); // Redirige vers la page de connexion racine
    exit();
}

$store = loadDeviceStore();
$activeDevice = getActiveDeviceRecord($store);
$isMikrotik = is_array($activeDevice) && (($activeDevice['type'] ?? '') === 'mikrotik');
$isOpnsense = is_array($activeDevice) && (($activeDevice['type'] ?? '') === 'opnsense');
$logs = [];
$logsError = null;
$topicFilter = strtolower(trim((string)($_GET['topic'] ?? '')));
$sourceFilter = strtolower(trim((string)($_GET['source'] ?? '')));

function extractFirstIpv4(string $message): string
{
    if (preg_match('/\b(?:\d{1,3}\.){3}\d{1,3}\b/', $message, $matches)) {
        return $matches[0];
    }

    return '-';
}

function formatLogTimestamp(string $timestamp): string
{
    $timestamp = trim($timestamp);
    if ($timestamp === '') {
        return '-';
    }

    $unix = strtotime($timestamp);
    if ($unix === false) {
        return $timestamp;
    }

    return date('Y-m-d H:i:s', $unix);
}

function normalizeOpnsenseEventText(string $message): string
{
    $message = trim($message);
    if ($message === '') {
        return '-';
    }

    $message = preg_replace('/^\([^)]+\)\s*/', '', $message) ?? $message;
    $message = preg_replace('/^\[[a-f0-9-]+\]\s*/i', '', $message) ?? $message;

    return trim($message) !== '' ? trim($message) : '-';
}

function parseEmbeddedSyslogMessage(string $message): ?array
{
    if (!preg_match('/^\s*<\d+>\[\d+\]\s+(\S+)\s+(\S+)\s+(\S+)\s+(\S+)\s+\S+\s+\S+\s+(.*)$/', $message, $matches)) {
        return null;
    }

    $event = normalizeOpnsenseEventText((string)$matches[5]);

    return [
        'embedded_timestamp' => formatLogTimestamp((string)$matches[1]),
        'host' => trim((string)$matches[2]) !== '' ? trim((string)$matches[2]) : '-',
        'service' => trim((string)$matches[3]) !== '' ? trim((string)$matches[3]) : '-',
        'pid' => trim((string)$matches[4]) !== '' ? trim((string)$matches[4]) : '-',
        'event' => $event,
        'address' => extractFirstIpv4($event),
    ];
}

function normalizeOpnsenseLogRows(array $rows, string $source): array
{
    $normalized = [];

    foreach ($rows as $row) {
        $timestamp = formatLogTimestamp((string)($row['timestamp'] ?? $row['date'] ?? $row['time'] ?? ''));
        $severity = trim((string)($row['severity'] ?? $row['level'] ?? '-')) ?: '-';
        $process = trim((string)($row['program'] ?? $row['process'] ?? $row['facility'] ?? '-')) ?: '-';
        $message = trim((string)($row['line'] ?? $row['message'] ?? $row['msg'] ?? ''));

        if ($timestamp === '-' && $message === '') {
            continue;
        }

        if ($source === 'portalauth') {
            $event = '-';
            $username = '-';
            $address = '-';
            $zone = '-';

            if (preg_match('/\b(AUTH|LOGOUT|DENY)\s+(.+?)\s+\(([^)]+)\)\s+zone\s+(.+)$/i', $message, $matches)) {
                $event = strtoupper(trim($matches[1]));
                $username = trim($matches[2]) !== '' ? trim($matches[2]) : '-';
                $address = trim($matches[3]) !== '' ? trim($matches[3]) : '-';
                $zone = trim($matches[4]) !== '' ? trim($matches[4]) : '-';
            }

            $normalized[] = [
                'timestamp' => $timestamp,
                'event' => $event,
                'username' => $username,
                'address' => $address,
                'zone' => $zone,
                'message' => $message !== '' ? $message : '-',
            ];
            continue;
        }

        if ($source === 'lighttpd') {
            $address = extractFirstIpv4($message);
            $event = normalizeOpnsenseEventText($message);

            $normalized[] = [
                'timestamp' => $timestamp,
                'process' => $process,
                'address' => $address,
                'event' => $event !== '' ? $event : '-',
            ];
            continue;
        }

        if ($source === 'firewall') {
            $normalized[] = [
                'timestamp' => $timestamp,
                'service' => $process,
                'address' => extractFirstIpv4($message),
                'event' => normalizeOpnsenseEventText($message),
            ];
            continue;
        }

        if ($source === 'configd') {
            $normalized[] = [
                'timestamp' => $timestamp,
                'service' => $process,
                'event' => normalizeOpnsenseEventText($message),
            ];
            continue;
        }

        if ($source === 'system' || $source === 'boot') {
            $embedded = parseEmbeddedSyslogMessage($message);
            if ($embedded !== null) {
                $normalized[] = [
                    'timestamp' => $embedded['embedded_timestamp'] !== '-' ? $embedded['embedded_timestamp'] : $timestamp,
                    'service' => $embedded['service'],
                    'address' => $embedded['address'],
                    'event' => $embedded['event'],
                ];
            } else {
                $normalized[] = [
                    'timestamp' => $timestamp,
                    'service' => $process,
                    'address' => extractFirstIpv4($message),
                    'event' => normalizeOpnsenseEventText($message),
                ];
            }
            continue;
        }

        $normalized[] = [
            'timestamp' => $timestamp,
            'severity' => $severity,
            'process' => $process,
            'message' => $message !== '' ? $message : '-',
        ];
    }

    return $normalized;
}

function opnsenseLogColumns(string $source): array
{
    return match ($source) {
        'portalauth' => [
            'timestamp' => 'Heure',
            'event' => 'Type',
            'username' => 'Utilisateur',
            'address' => 'Adresse',
            'zone' => 'Zone',
            'message' => 'Message',
        ],
        'lighttpd' => [
            'timestamp' => 'Heure',
            'process' => 'Service',
            'address' => 'Adresse',
            'event' => 'Evenement',
        ],
        'firewall' => [
            'timestamp' => 'Heure',
            'service' => 'Service',
            'address' => 'Adresse',
            'event' => 'Evenement',
        ],
        'configd' => [
            'timestamp' => 'Heure',
            'service' => 'Service',
            'event' => 'Evenement',
        ],
        'system', 'boot' => [
            'timestamp' => 'Heure',
            'service' => 'Service',
            'address' => 'Adresse',
            'event' => 'Evenement',
        ],
        default => [
            'timestamp' => 'Heure',
            'severity' => 'Severite',
            'process' => 'Process',
            'message' => 'Message',
        ],
    };
}

if ($isMikrotik) {
    try {
        $logs = getMikrotikSystemLogs(300);
        if ($topicFilter !== '') {
            $logs = array_values(array_filter($logs, static function (array $row) use ($topicFilter): bool {
                return str_contains(strtolower((string)($row['topics'] ?? '')), $topicFilter)
                    || str_contains(strtolower((string)($row['message'] ?? '')), $topicFilter);
            }));
        }
    } catch (Throwable $e) {
        $logsError = $e->getMessage();
    }
} elseif ($isOpnsense) {
    $opnsenseSources = [
        'system' => 'Système',
        'configd' => 'Backend / configd',
        'firewall' => 'Firewall',
        'portalauth' => 'Portail captif',
        'lighttpd' => 'Web GUI',
        'boot' => 'Boot',
    ];

    if ($sourceFilter === '' || !isset($opnsenseSources[$sourceFilter])) {
        $sourceFilter = 'system';
    }

    try {
        $response = opnsenseApiRequest(
            $activeDevice,
            '/api/diagnostics/log/core/' . rawurlencode($sourceFilter) . '/search',
            'POST',
            [
                'rowCount' => 300,
                'current' => 1,
                'searchPhrase' => '',
                'validFrom' => 0,
            ]
        );

        if (!($response['success'] ?? false)) {
            throw new RuntimeException((string)($response['message'] ?? 'Lecture des logs OPNsense impossible.'));
        }

        $logs = normalizeOpnsenseLogRows($response['data']['rows'] ?? [], $sourceFilter);
    } catch (Throwable $e) {
        $logsError = $e->getMessage();
    }
}

$topicOptions = [
    '' => 'Tous',
    'hotspot' => 'Hotspot',
    'system' => 'System',
    'info' => 'Info',
    'warning' => 'Warning',
    'error' => 'Error',
    'debug' => 'Debug',
];
$opnsenseSourceOptions = [
    'system' => 'Système',
    'configd' => 'Backend / configd',
    'firewall' => 'Firewall',
    'portalauth' => 'Portail captif',
    'lighttpd' => 'Web GUI',
    'boot' => 'Boot',
];
$opnsenseActiveColumns = $isOpnsense ? opnsenseLogColumns($sourceFilter) : [];

$systemLogTableView = ($isMikrotik || $isOpnsense) && $logsError === null && !empty($logs);
?>

<?php
$pageTitle = 'System Log';
require_once '../includes/layout_header.php';
?>
<style>
    .system-log-search-group {
        min-width: 0;
        max-width: 100%;
    }

    .system-log-search-group .input-group-text {
        background: rgba(59, 130, 246, 0.12);
        border-color: rgba(148, 163, 184, 0.18);
        color: var(--theme-text);
    }

    .system-log-search-group .form-control {
        min-width: 0;
        background: rgba(12, 20, 34, 0.82);
        border-color: rgba(148, 163, 184, 0.18);
        color: var(--theme-text);
    }

    .system-log-search-group .form-control::placeholder {
        color: rgba(226, 232, 240, 0.55);
    }

    .system-log-search-group .form-control:focus,
    .system-log-filter-select:focus {
        border-color: rgba(59, 130, 246, 0.45);
        box-shadow: 0 0 0 0.2rem rgba(59, 130, 246, 0.12);
        background: rgba(12, 20, 34, 0.9);
        color: var(--theme-text);
    }

    .system-log-filter-select {
        background: rgba(12, 20, 34, 0.82);
        border-color: rgba(148, 163, 184, 0.18);
        color: var(--theme-text);
    }

    .system-log-header-row {
        gap: 0.75rem;
        width: 100%;
        min-width: 0;
    }

    .system-log-header-row > h5 {
        flex: 0 0 auto;
        min-width: 0;
    }

    .system-log-filters-inline {
        flex: 1 1 auto;
        min-width: 0;
        max-width: 100%;
        width: 100%;
    }

    @media (min-width: 992px) {
        .system-log-filters-inline--beside-title {
            width: auto;
            max-width: min(100%, 100rem);
        }
    }

    .system-log-actions-inline {
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        justify-content: flex-start;
        gap: 0.35rem;
    }

    @media (min-width: 768px) {
        .text-md-end.system-log-actions-inline {
            justify-content: flex-end;
        }
    }

    .system-log-filters-inline .row {
        --bs-gutter-x: 0.5rem;
        min-width: 0;
    }

    .system-log-filters-inline .row > [class*="col-"] {
        min-width: 0;
    }

    /* Hauteur tableau = reste fenêtre (si lignes) ; sinon pas de --table sur html/body */
    html.system-log-page.system-log-page--table {
        height: 100dvh;
        max-height: 100dvh;
        overflow: hidden;
    }

    html.system-log-page body.system-log-page--table {
        height: 100%;
        min-height: 0;
        margin: 0;
        display: flex;
        flex-direction: column;
        overflow: hidden;
    }

    html.system-log-page body.system-log-page--table #wrapper {
        flex: 1 1 auto;
        min-height: 0;
        overflow: hidden;
        display: flex;
        flex-direction: row;
        align-items: stretch;
    }

    html.system-log-page body.system-log-page--table #page-content-wrapper {
        flex: 1 1 auto;
        min-width: 0;
        min-height: 0;
        display: flex;
        flex-direction: column;
        overflow: hidden;
    }

    html.system-log-page body.system-log-page--table #page-content-wrapper > .container-fluid {
        flex: 1 1 auto;
        min-height: 0;
        display: flex;
        flex-direction: column;
        overflow: hidden;
        padding-bottom: 0 !important;
    }

    html.system-log-page body.system-log-page--table #page-content-wrapper > .container-fluid > .card.shadow-sm {
        flex: 1 1 auto;
        min-height: 0;
        display: flex;
        flex-direction: column;
        overflow: hidden;
        margin-bottom: 0 !important;
    }

    html.system-log-page body.system-log-page--table #page-content-wrapper > .container-fluid > .card > .card-body:not(.p-0) {
        flex-shrink: 0;
    }

    html.system-log-page body.system-log-page--table #page-content-wrapper > .container-fluid > .card > .card-body.p-0 {
        flex: 1 1 auto;
        min-height: 0;
        overflow: hidden;
        display: flex;
        flex-direction: column;
    }

    /* En-tête de tableau figé (scroll dans ce bloc uniquement) */
    html.system-log-page.system-log-page--table .system-log-table-scroll {
        flex: 1 1 auto;
        min-height: 0;
        max-height: none;
        overflow-x: auto;
        overflow-y: auto;
        position: relative;
        overscroll-behavior: contain;
        scrollbar-width: thin;
        scrollbar-color: var(--theme-scrollbar-thumb) var(--theme-scrollbar-track);
    }

    html.system-log-page .system-log-table-scroll::-webkit-scrollbar {
        width: var(--theme-scrollbar-size);
        height: var(--theme-scrollbar-size);
    }

    html.system-log-page .system-log-table-scroll::-webkit-scrollbar-track {
        background: var(--theme-scrollbar-track);
        border-radius: 999px;
    }

    html.system-log-page .system-log-table-scroll::-webkit-scrollbar-thumb {
        background-image:
            linear-gradient(180deg, rgba(148, 163, 184, 0.55), rgba(94, 234, 212, 0.45)),
            radial-gradient(circle at 50% 90%, var(--theme-scrollbar-glow), transparent 60%);
        border-radius: 999px;
        border: 2px solid transparent;
        background-clip: padding-box;
        box-shadow: 0 0 6px rgba(78, 220, 255, 0.35);
    }

    html.system-log-page .system-log-table-scroll table {
        border-collapse: separate;
        border-spacing: 0;
    }

    html.system-log-page .system-log-table-scroll thead {
        position: sticky;
        top: 0;
        z-index: 6;
    }

    html.system-log-page .system-log-table-scroll thead th {
        position: sticky;
        top: 0;
        z-index: 7;
        font-size: calc(0.78rem + 1px);
        font-weight: 600;
        background-color: rgba(18, 24, 41, 0.58) !important;
        backdrop-filter: blur(2px);
        -webkit-backdrop-filter: blur(2px);
        border-bottom: 1px solid var(--theme-border) !important;
        color: var(--theme-primary) !important;
    }
</style>

<div id="messageArea" style="display: none;"></div>

                <div class="card shadow-sm mb-4">
                    <div class="card-body">
                        <div class="d-flex flex-column flex-lg-row flex-lg-nowrap align-items-stretch align-items-lg-end gap-2 gap-lg-3 system-log-header-row mb-3">
                            <h5 class="card-title text-white mb-0 flex-shrink-0"><i class="fas fa-file-lines me-2"></i> System Log</h5>
                            <?php if ($isMikrotik): ?>
                            <div class="system-log-filters-inline system-log-filters-inline--beside-title">
                                <div class="row g-2 align-items-end w-100 m-0">
                                    <form method="get" id="mikrotikLogTopicForm" class="col-12 col-md-4 col-lg-3 min-w-0">
                                        <label class="visually-hidden" for="mikrotikTopicFilter">Sujet</label>
                                        <select class="form-select system-log-filter-select" id="mikrotikTopicFilter" name="topic" aria-label="Filtrer par sujet">
                                            <?php foreach ($topicOptions as $value => $label): ?>
                                            <option value="<?= htmlspecialchars($value) ?>" <?= $topicFilter === $value ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </form>
                                    <div class="col-12 col-md-5 col-lg-6 min-w-0">
                                        <div class="input-group system-log-search-group" role="search">
                                            <span class="input-group-text" title="Filtre instantane">
                                                <i class="fa fa-search"></i>
                                            </span>
                                            <input
                                                type="search"
                                                class="form-control"
                                                id="mikrotikLogSearch"
                                                placeholder="Rechercher (heure, sujets, message&hellip;)"
                                                autocomplete="off"
                                                aria-label="Filtre instantane sur le tableau"
                                            >
                                        </div>
                                    </div>
                                    <div class="col-12 col-md-3 col-lg-3 text-md-end min-w-0 system-log-actions-inline">
                                        <button type="submit" class="btn btn-test" form="mikrotikLogTopicForm">
                                            <i class="fa fa-search me-1"></i> Filtrer
                                        </button>
                                        <a href="system_log.php" class="btn btn-save">
                                            <i class="fa fa-sync me-1"></i> Tout
                                        </a>
                                    </div>
                                </div>
                            </div>
                            <?php elseif ($isOpnsense): ?>
                            <div class="system-log-filters-inline system-log-filters-inline--beside-title">
                                <div class="row g-2 align-items-end w-100 m-0">
                                    <div class="col-12 col-md-6 min-w-0">
                                        <div class="input-group system-log-search-group" role="search">
                                            <span class="input-group-text" title="Filtre instantane">
                                                <i class="fa fa-search"></i>
                                            </span>
                                            <input
                                                type="search"
                                                class="form-control"
                                                id="opnsenseSearchFilter"
                                                placeholder="Rechercher"
                                                autocomplete="off"
                                                aria-label="Filtre instantane sur le tableau"
                                            >
                                        </div>
                                    </div>
                                    <form method="get" id="opnsenseLogSourceForm" class="col-12 col-md-6 min-w-0">
                                        <label class="visually-hidden" for="opnsenseSourceFilter">Source des logs</label>
                                        <select class="form-select system-log-filter-select" id="opnsenseSourceFilter" name="source" aria-label="Source des logs" onchange="this.form.submit()">
                                            <?php foreach ($opnsenseSourceOptions as $value => $label): ?>
                                            <option value="<?= htmlspecialchars($value) ?>" <?= $sourceFilter === $value ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </form>
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="card-body p-0">
                        <?php if (!$isMikrotik && !$isOpnsense): ?>
                            <div class="p-4 text-center text-white-50">
                                Cette page sera alimentee quand le device actif est de type MikroTik ou OPNsense.
                            </div>
                        <?php elseif ($logsError !== null): ?>
                            <div class="p-4 text-center text-danger">
                                <?= htmlspecialchars($logsError) ?>
                            </div>
                        <?php elseif (empty($logs)): ?>
                            <div class="p-4 text-center text-white-50">
                                Aucun log systeme detecte pour le filtre courant.
                            </div>
                        <?php else: ?>
                            <div class="table-responsive system-log-table-scroll">
                                <table class="table table-striped table-hover table-dark mb-0 align-middle users-table table-standard">
                                    <thead>
                                        <tr>
                                            <?php if ($isOpnsense): ?>
                                                <?php foreach ($opnsenseActiveColumns as $label): ?>
                                                <th><?= htmlspecialchars($label) ?></th>
                                                <?php endforeach; ?>
                                            <?php else: ?>
                                            <th>Heure</th>
                                            <th>Topics</th>
                                            <th>Message</th>
                                            <?php endif; ?>
                                        </tr>
                                    </thead>
                                    <tbody id="systemLogTableBody">
                                        <?php foreach ($logs as $log): ?>
                                        <tr>
                                            <?php if ($isOpnsense): ?>
                                                <?php foreach (array_keys($opnsenseActiveColumns) as $field): ?>
                                                <td><?= htmlspecialchars((string)($log[$field] ?? '-')) ?></td>
                                                <?php endforeach; ?>
                                            <?php else: ?>
                                            <td><?= htmlspecialchars($log['time']) ?></td>
                                            <td><?= htmlspecialchars($log['topics'] !== '' ? $log['topics'] : '-') ?></td>
                                            <td><?= htmlspecialchars($log['message']) ?></td>
                                            <?php endif; ?>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

            </div>
        </div>
        </div>
    
<?php
$extraJs = [
    '../js/system_log.js?v=20260425a',
];
require_once '../includes/layout_footer.php';
?>
