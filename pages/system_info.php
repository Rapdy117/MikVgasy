<?php
session_start();

require_once '../includes/message.php';
require_once '../includes/auth.php';
require_once '../config/db.php';
require_once '../includes/device_manager.php';
require_once '../includes/mikrotik_backend.php';
require_once '../includes/opnsense_shaper.php';

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    set_message('Veuillez vous connecter pour accéder à cette page.', 'danger');
    header('Location: ../index.php');
    exit();
}

$store = loadDeviceStore();
$activeDevice = getActiveDeviceRecord($store);
$activeType = strtolower((string)($activeDevice['type'] ?? 'other'));
$isMikrotik = is_array($activeDevice) && ($activeType === 'mikrotik');
$isOpnsense = is_array($activeDevice) && ($activeType === 'opnsense');
$isRadius = is_array($activeDevice) && in_array($activeType, ['radius', 'freeradius', 'other'], true);

$resource = [];
$clock = [];
$routerboard = [];
$opnsenseStatus = [];
$opnsenseInfo = [];
$opnsenseResources = [];
$opnsenseTime = [];
$opnsenseDisk = [];
$opnsenseSwap = [];
$opnsenseCpuType = '';
$radiusSummary = [];
$infoError = '';
$opnsenseErrors = [];

if ($isMikrotik) {
    try {
        $resource = getMikrotikSystemResource();
        $clock = getMikrotikSystemClock();
        $routerboard = getMikrotikRouterboardInfo();
    } catch (Throwable $e) {
        $infoError = $e->getMessage();
    }
}

if ($isOpnsense && is_array($activeDevice)) {
    $fetchOpnsense = static function (array $device, string $path, array &$errors, string $label): array {
        $resp = opnsenseApiRequest($device, $path);
        if (!($resp['success'] ?? false)) {
            $errors[] = $label . ': ' . (string)($resp['message'] ?? 'Erreur OPNsense');
            return [];
        }

        return is_array($resp['data'] ?? null) ? $resp['data'] : [];
    };

    $opnsenseStatus = $fetchOpnsense($activeDevice, '/api/diagnostics/system/system_information', $opnsenseErrors, 'System info');
    $opnsenseInfo = $fetchOpnsense($activeDevice, '/api/core/system/status', $opnsenseErrors, 'Core status');
    $opnsenseTime = $fetchOpnsense($activeDevice, '/api/diagnostics/system/system_time', $opnsenseErrors, 'System time');
    $opnsenseResources = $fetchOpnsense($activeDevice, '/api/diagnostics/system/system_resources', $opnsenseErrors, 'Resources');
    $opnsenseDisk = $fetchOpnsense($activeDevice, '/api/diagnostics/system/system_disk', $opnsenseErrors, 'Disk');
    $opnsenseSwap = $fetchOpnsense($activeDevice, '/api/diagnostics/system/system_swap', $opnsenseErrors, 'Swap');
    $cpuTypeResp = opnsenseApiRequest($activeDevice, '/api/diagnostics/cpu_usage/getcputype');
    if (($cpuTypeResp['success'] ?? false) && isset($cpuTypeResp['data'])) {
        $opnsenseCpuType = formatOpnsenseScalar($cpuTypeResp['data']);
    } else {
        $opnsenseErrors[] = 'CPU type: ' . (string)($cpuTypeResp['message'] ?? 'Erreur OPNsense');
    }

    if ($opnsenseErrors !== [] && $opnsenseStatus === [] && $opnsenseTime === [] && $opnsenseResources === [] && $opnsenseInfo === []) {
        $infoError = implode(' | ', $opnsenseErrors);
    }
}

if ($isRadius) {
    try {
        $radiusSummary = [
            'users' => (int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn(),
            'profiles' => (int)$pdo->query("SELECT COUNT(*) FROM profiles")->fetchColumn(),
            'active_sessions' => (int)$pdo->query("SELECT COUNT(*) FROM radacct WHERE acctstoptime IS NULL")->fetchColumn(),
        ];
    } catch (Throwable $e) {
        $infoError = $e->getMessage();
    }
}

function formatBytesLabel(float $bytes): string
{
    if ($bytes <= 0) {
        return '0 KB';
    }

    $kilobytes = $bytes / 1024;
    if ($kilobytes < 1000) {
        return number_format($kilobytes, 2, '.', ' ') . ' KB';
    }

    $megabytes = $bytes / 1024 / 1024;
    if ($megabytes < 1000) {
        return number_format($megabytes, 2, '.', ' ') . ' MB';
    }

    return number_format($megabytes / 1024, 2, '.', ' ') . ' GB';
}

function formatNumberWithThousands(float $value, int $decimals = 2): string
{
    $formatted = number_format($value, $decimals, '.', ' ');
    if ($decimals > 0) {
        $formatted = rtrim(rtrim($formatted, '0'), '.');
    }

    return $formatted;
}

function formatOpnsenseScalar($value, string $fallback = '-'): string
{
    if ($value === null) {
        return $fallback;
    }

    if (is_array($value)) {
        if (isset($value['product_version']) && is_scalar($value['product_version'])) {
            return trim((string)$value['product_version']);
        }
        if (isset($value['version']) && is_scalar($value['version'])) {
            return trim((string)$value['version']);
        }

        $parts = [];
        foreach ($value as $key => $item) {
            if (is_scalar($item)) {
                $itemValue = trim((string)$item);
                if ($itemValue !== '') {
                    $parts[] = $itemValue;
                }
            }
        }

        return $parts !== [] ? implode(' | ', $parts) : $fallback;
    }

    if (is_scalar($value)) {
        $stringValue = trim((string)$value);
        return $stringValue !== '' ? $stringValue : $fallback;
    }

    return $fallback;
}

function formatUptimeLabel(string $raw): string
{
    $raw = trim($raw);
    return $raw !== '' ? $raw : '-';
}

$boardName = trim((string)($routerboard['model'] ?? $resource['board-name'] ?? 'MikroTik'));
$version = trim((string)($resource['version'] ?? '-'));
$cpu = trim((string)($resource['cpu'] ?? '-'));
$cpuCount = trim((string)($resource['cpu-count'] ?? '-'));
$cpuFreq = trim((string)($resource['cpu-frequency'] ?? ''));
$freeMemory = formatBytesLabel((float)($resource['free-memory'] ?? 0));
$totalMemory = formatBytesLabel((float)($resource['total-memory'] ?? 0));
$freeHdd = formatBytesLabel((float)($resource['free-hdd-space'] ?? 0));
$totalHdd = formatBytesLabel((float)($resource['total-hdd-space'] ?? 0));
$uptime = formatUptimeLabel((string)($resource['uptime'] ?? ''));
$buildTime = trim((string)($resource['build-time'] ?? '-'));
$factoryFirmware = trim((string)($routerboard['factory-firmware'] ?? '-'));
$currentFirmware = trim((string)($routerboard['current-firmware'] ?? '-'));
$upgradeFirmware = trim((string)($routerboard['upgrade-firmware'] ?? '-'));
$clockDate = trim((string)($clock['date'] ?? '-'));
$clockTime = trim((string)($clock['time'] ?? '-'));

$opnsenseName = formatOpnsenseScalar($opnsenseStatus['name'] ?? $opnsenseInfo['hostname'] ?? null);
$opnsenseVersions = formatOpnsenseScalar($opnsenseStatus['versions'] ?? $opnsenseInfo['product_version'] ?? null);
$opnsenseUptime = trim((string)($opnsenseTime['uptime'] ?? $opnsenseResources['uptime'] ?? '-'));
$opnsenseLoad = trim((string)($opnsenseTime['loadavg'] ?? '-'));
$opnsenseDatetime = trim((string)($opnsenseTime['datetime'] ?? '-'));
$opnsenseConfig = trim((string)($opnsenseTime['config'] ?? '-'));
$opnsenseCpuLabel = $opnsenseCpuType !== '' ? $opnsenseCpuType : formatOpnsenseScalar(
    $opnsenseInfo['cpu'] ?? $opnsenseInfo['cpu_type'] ?? $opnsenseInfo['hw_model'] ?? $opnsenseInfo['hardware']
    ?? $opnsenseStatus['cpu'] ?? $opnsenseStatus['cpu_type'] ?? $opnsenseStatus['hw_model'] ?? $opnsenseStatus['hardware']
    ?? $opnsenseResources['cpu'] ?? $opnsenseResources['cpu_type'] ?? null
);

$opnsenseMemory = is_array($opnsenseResources['memory'] ?? null) ? $opnsenseResources['memory'] : [];
$opnsenseMemTotal = (float)($opnsenseMemory['total_frmt'] ?? $opnsenseMemory['total'] ?? 0);
$opnsenseMemUsed = (float)($opnsenseMemory['used_frmt'] ?? $opnsenseMemory['used'] ?? 0);
$opnsenseMemArc = (float)($opnsenseMemory['arc_frmt'] ?? $opnsenseMemory['arc'] ?? 0);
$opnsenseMemLabel = $opnsenseMemTotal > 0
    ? formatBytesLabel($opnsenseMemUsed * 1024 * 1024) . ' / ' . formatBytesLabel($opnsenseMemTotal * 1024 * 1024)
    : '-';
$opnsenseMemArcLabel = $opnsenseMemArc > 0 ? formatBytesLabel($opnsenseMemArc * 1024 * 1024) : '-';

$swapDevices = is_array($opnsenseSwap['swap'] ?? null) ? $opnsenseSwap['swap'] : [];
$swapTotal = 0.0;
$swapUsed = 0.0;
foreach ($swapDevices as $swapDevice) {
    if (!is_array($swapDevice)) {
        continue;
    }
    $swapTotal += (float)($swapDevice['total'] ?? 0);
    $swapUsed += (float)($swapDevice['used'] ?? 0);
}
$swapLabel = $swapTotal > 0
    ? formatBytesLabel($swapUsed * 1024 * 1024) . ' / ' . formatBytesLabel($swapTotal * 1024 * 1024)
    : '-';

$diskDevices = is_array($opnsenseDisk['devices'] ?? null) ? $opnsenseDisk['devices'] : [];
$rootDiskLabel = '-';
foreach ($diskDevices as $diskDevice) {
    if (!is_array($diskDevice)) {
        continue;
    }
    if (trim((string)($diskDevice['mountpoint'] ?? '')) === '/') {
        $used = trim((string)($diskDevice['used'] ?? ''));
        $total = trim((string)($diskDevice['blocks'] ?? ''));
        $pct = trim((string)($diskDevice['used_pct'] ?? ''));
        $rootDiskLabel = $used !== '' && $total !== ''
            ? $used . ' / ' . $total . ($pct !== '' ? ' (' . $pct . '%)' : '')
            : ($pct !== '' ? $pct . '%' : '-');
        break;
    }
}

?>

<?php
$pageTitle = 'Informations Système';
require_once '../includes/layout_header.php';
?>

<div class="card shadow-sm mb-3">
                <div class="card-body py-3">
                    <div class="d-flex align-items-center text-white" style="font-size: calc(0.875rem + 2px);">
                        <i class="fas fa-server me-2"></i>
                        <span class="small fw-semibold">Informations Système</span>
                    </div>
                </div>
            </div>

            <?php if ($infoError !== ''): ?>
                <div class="card shadow-sm">
                    <div class="card-body">
                        <div class="alert alert-danger mb-0"><?= htmlspecialchars($infoError) ?></div>
                    </div>
                </div>
            <?php elseif ($isMikrotik): ?>
                <div class="row">
                    <div class="col-lg-6 mb-3">
                        <div class="card shadow-sm h-100">
                            <div class="card-header standard-card-header">
                                <i class="fa fa-microchip me-2"></i> Systeme
                            </div>
                            <div class="card-body">
                                <div class="network-device-form mb-0">
                                    <div class="input-group"><span class="input-group-text">Mikrotik</span><input class="form-control" value="<?= htmlspecialchars($boardName) ?>" readonly></div>
                                    <div class="input-group"><span class="input-group-text">Version</span><input class="form-control" value="<?= htmlspecialchars($version) ?>" readonly></div>
                                    <div class="input-group"><span class="input-group-text">CPU</span><input class="form-control" value="<?= htmlspecialchars(trim($cpu . ($cpuFreq !== '' ? ' | ' . $cpuFreq . ' MHz' : '') . ($cpuCount !== '' ? ' | ' . $cpuCount . ' core(s)' : ''))) ?>" readonly></div>
                                    <div class="input-group"><span class="input-group-text">Uptime</span><input class="form-control" value="<?= htmlspecialchars($uptime) ?>" readonly></div>
                                    <div class="input-group"><span class="input-group-text">Date</span><input class="form-control" value="<?= htmlspecialchars(trim($clockDate . ' ' . $clockTime)) ?>" readonly></div>
                                    <div class="input-group mb-0"><span class="input-group-text">Build</span><input class="form-control" value="<?= htmlspecialchars($buildTime) ?>" readonly></div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-lg-6 mb-3">
                        <div class="card shadow-sm h-100">
                            <div class="card-header standard-card-header">
                                <i class="fa fa-memory me-2"></i> Ressources
                            </div>
                            <div class="card-body">
                                <div class="network-device-form mb-0">
                                    <div class="input-group"><span class="input-group-text">RAM Libre</span><input class="form-control" value="<?= htmlspecialchars($freeMemory) ?>" readonly></div>
                                    <div class="input-group"><span class="input-group-text">RAM Totale</span><input class="form-control" value="<?= htmlspecialchars($totalMemory) ?>" readonly></div>
                                    <div class="input-group"><span class="input-group-text">Disque Libre</span><input class="form-control" value="<?= htmlspecialchars($freeHdd) ?>" readonly></div>
                                    <div class="input-group"><span class="input-group-text">Disque Total</span><input class="form-control" value="<?= htmlspecialchars($totalHdd) ?>" readonly></div>
                                    <div class="input-group"><span class="input-group-text">FW Actuel</span><input class="form-control" value="<?= htmlspecialchars($currentFirmware) ?>" readonly></div>
                                    <div class="input-group"><span class="input-group-text">FW Upgrade</span><input class="form-control" value="<?= htmlspecialchars($upgradeFirmware) ?>" readonly></div>
                                    <div class="input-group mb-0"><span class="input-group-text">FW Factory</span><input class="form-control" value="<?= htmlspecialchars($factoryFirmware) ?>" readonly></div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-12">
                        <div class="alert alert-secondary py-2 px-3 mb-0 small">Source MVC: View `pages/system_info.php`, Controller `includes/mikrotik_backend.php`, Model RouterOS API.</div>
                    </div>
                </div>
            <?php elseif ($isOpnsense): ?>
                <div class="card shadow-sm">
                    <div class="card-body">
                        <?php if ($opnsenseErrors !== []): ?>
                            <div class="alert alert-warning py-2 px-3 small mb-3">
                                <?= htmlspecialchars(implode(' | ', $opnsenseErrors)) ?>
                            </div>
                        <?php endif; ?>
                        <div class="row g-3">
                            <div class="col-lg-4">
                                <div class="dashboard-summary-card"><div class="dashboard-summary-label">Nom</div><div class="dashboard-summary-value"><?= htmlspecialchars($opnsenseName !== '' ? $opnsenseName : '-') ?></div></div>
                            </div>
                            <div class="col-lg-4">
                                <div class="dashboard-summary-card"><div class="dashboard-summary-label">Uptime</div><div class="dashboard-summary-value"><?= htmlspecialchars($opnsenseUptime !== '' ? $opnsenseUptime : '-') ?></div></div>
                            </div>
                            <div class="col-lg-4">
                                <div class="dashboard-summary-card"><div class="dashboard-summary-label">Load avg</div><div class="dashboard-summary-value"><?= htmlspecialchars($opnsenseLoad !== '' ? $opnsenseLoad : '-') ?></div></div>
                            </div>
                            <div class="col-lg-4">
                                <div class="dashboard-summary-card"><div class="dashboard-summary-label">Date/Heure</div><div class="dashboard-summary-value"><?= htmlspecialchars($opnsenseDatetime !== '' ? $opnsenseDatetime : '-') ?></div></div>
                            </div>
                            <div class="col-lg-4">
                                <div class="dashboard-summary-card"><div class="dashboard-summary-label">Disque /</div><div class="dashboard-summary-value"><?= htmlspecialchars($rootDiskLabel) ?></div></div>
                            </div>
                            <div class="col-lg-4">
                                <div class="dashboard-summary-card"><div class="dashboard-summary-label">Config</div><div class="dashboard-summary-value"><?= htmlspecialchars($opnsenseConfig !== '' ? $opnsenseConfig : '-') ?></div></div>
                            </div>
                            <div class="col-lg-4">
                                <div class="dashboard-summary-card"><div class="dashboard-summary-label">Version OPNsense</div><div class="dashboard-summary-value"><?= htmlspecialchars($opnsenseVersions !== '' ? $opnsenseVersions : '-') ?></div></div>
                            </div>
                        </div>

                        <div class="row g-3 mt-1">
                            <div class="col-lg-6">
                                <div class="card shadow-sm h-100">
                                    <div class="card-header standard-card-header">
                                        <i class="fa fa-microchip me-2"></i> Systeme OPNsense
                                    </div>
                                    <div class="card-body">
                                        <div class="network-device-form mb-0">
                                            <div class="input-group"><span class="input-group-text">Nom</span><input class="form-control" value="<?= htmlspecialchars($opnsenseName !== '' ? $opnsenseName : '-') ?>" readonly></div>
                                            <div class="input-group"><span class="input-group-text">Version</span><input class="form-control" value="<?= htmlspecialchars($opnsenseVersions !== '' ? $opnsenseVersions : '-') ?>" readonly></div>
                                            <div class="input-group"><span class="input-group-text">CPU</span><input class="form-control" value="<?= htmlspecialchars($opnsenseCpuLabel !== '' ? $opnsenseCpuLabel : '-') ?>" readonly></div>
                                            <div class="input-group"><span class="input-group-text">Config</span><input class="form-control" value="<?= htmlspecialchars($opnsenseConfig !== '' ? $opnsenseConfig : '-') ?>" readonly></div>
                                            <div class="input-group mb-0"><span class="input-group-text">Uptime</span><input class="form-control" value="<?= htmlspecialchars($opnsenseUptime !== '' ? $opnsenseUptime : '-') ?>" readonly></div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="col-lg-6">
                                <div class="card shadow-sm h-100">
                                    <div class="card-header standard-card-header">
                                        <i class="fa fa-memory me-2"></i> Ressources OPNsense
                                    </div>
                                    <div class="card-body">
                                        <div class="network-device-form mb-0">
                                            <div class="input-group"><span class="input-group-text">Memoire</span><input class="form-control" value="<?= htmlspecialchars($opnsenseMemLabel) ?>" readonly></div>
                                            <div class="input-group"><span class="input-group-text">ARC</span><input class="form-control" value="<?= htmlspecialchars($opnsenseMemArcLabel) ?>" readonly></div>
                                            <div class="input-group"><span class="input-group-text">Swap</span><input class="form-control" value="<?= htmlspecialchars($swapLabel) ?>" readonly></div>
                                            <div class="input-group mb-0"><span class="input-group-text">Disque /</span><input class="form-control" value="<?= htmlspecialchars($rootDiskLabel) ?>" readonly></div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                    </div>
                </div>
            <?php elseif ($isRadius): ?>
                <div class="card shadow-sm">
                    <div class="card-body">
                        <div class="row g-3">
                            <div class="col-lg-4">
                                <div class="dashboard-summary-card"><div class="dashboard-summary-label">Utilisateurs</div><div class="dashboard-summary-value"><?= (int)($radiusSummary['users'] ?? 0) ?></div></div>
                            </div>
                            <div class="col-lg-4">
                                <div class="dashboard-summary-card"><div class="dashboard-summary-label">Profils</div><div class="dashboard-summary-value"><?= (int)($radiusSummary['profiles'] ?? 0) ?></div></div>
                            </div>
                            <div class="col-lg-4">
                                <div class="dashboard-summary-card"><div class="dashboard-summary-label">Sessions actives</div><div class="dashboard-summary-value"><?= (int)($radiusSummary['active_sessions'] ?? 0) ?></div></div>
                            </div>
                        </div>
                        <div class="alert alert-secondary py-2 px-3 mt-3 mb-0 small">Source MVC: View `pages/system_info.php`, Controller SQL local, Model tables `users`, `profiles`, `radacct`.</div>
                    </div>
                </div>
            <?php else: ?>
                <div class="card shadow-sm">
                    <div class="card-body">
                        <div class="text-white-50">Informations standards indisponibles pour ce type de device.</div>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>


<?php
require_once '../includes/layout_footer.php';
?>
