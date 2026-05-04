<?php
require_once __DIR__ . '/app_context.php';
require_once __DIR__ . '/navigation_index.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/admin_notifications.php';
require_once __DIR__ . '/../config/db.php';

$current = basename($_SERVER['PHP_SELF']);
$appContext = is_array($appContext ?? null) ? $appContext : buildAppContext();
$navbarDevice = $appContext['device'] ?? [
    'id' => null,
    'name' => 'Aucun device',
    'type' => null,
    'host' => '',
    'ip' => '',
    'backend_driver' => null,
];
$navbarDeviceType = strtolower(trim((string)($navbarDevice['type'] ?? '')));
$navbarLogoMap = [
    'mikrotik' => ['icon' => 'fa-router', 'label' => 'MikroTik', 'asset' => '../assets/images/brands/mikrotik.png'],
    'opnsense' => ['icon' => 'fa-shield-halved', 'label' => 'OPNsense', 'asset' => '../assets/images/brands/opnsense.svg'],
    'radius' => ['icon' => 'fa-server', 'label' => 'RADIUS', 'asset' => '../assets/images/brands/freeradius.svg'],
    'freeradius' => ['icon' => 'fa-server', 'label' => 'FreeRADIUS', 'asset' => '../assets/images/brands/freeradius.svg'],
];
$navbarLogo = $navbarLogoMap[$navbarDeviceType] ?? ['icon' => 'fa-network-wired', 'label' => 'Radius Manager', 'asset' => ''];
$navbarLogoAsset = (string)($navbarLogo['asset'] ?? '');
$navbarLogoAssetPath = $navbarLogoAsset !== '' ? realpath(__DIR__ . '/../assets/images/brands/' . basename($navbarLogoAsset)) : false;
$navbarHasLogoAsset = $navbarLogoAssetPath !== false && is_file($navbarLogoAssetPath);
$navbarDeviceAddress = trim((string)($navbarDevice['ip'] ?? $navbarDevice['host'] ?? ''));
$navbarHideTextTypes = ['mikrotik', 'opnsense'];
$navbarHideText = in_array($navbarDeviceType, $navbarHideTextTypes, true);
$navbarBrandImageClass = 'navbar-brand-image';
if ($navbarHasLogoAsset && $navbarDeviceType === 'mikrotik') {
    $navbarBrandImageClass .= ' navbar-brand-image-mikrotik';
}
$navbarSearchIndex = array_values(array_filter(
    getNavigationSearchIndex(),
    static function (array $entry): bool {
        $href = trim((string)($entry['href'] ?? ''));
        if ($href === '') {
            return false;
        }

        return canAccessApplicationPage(basename(parse_url($href, PHP_URL_PATH) ?: $href));
    }
));
$isAdminUser = isAdministrator();
$navbarUnreadNotifications = $isAdminUser ? countUnreadAdminNotificationsCached($pdo, 60) : 0;
?>

<nav class="navbar navbar-expand-lg navbar-dark fixed-top" id="main-navbar">
    <div class="container-fluid d-flex align-items-center p-0">

        <!-- LOGO (aligné sidebar) -->
        <div class="navbar-logo-block">
            <a href="dashboard.php" class="navbar-brand-dynamic navbar-clickable d-flex align-items-center justify-content-center h-100 text-decoration-none<?= $navbarHideText ? ' navbar-brand-logo-only' : '' ?>" data-device-type="<?= htmlspecialchars($navbarDeviceType) ?>">
                <?php if ($navbarHasLogoAsset): ?>
                <span class="navbar-brand-image-wrap">
                    <img
                        src="<?= htmlspecialchars($navbarLogoAsset) ?>"
                        alt="<?= htmlspecialchars($navbarLogo['label']) ?>"
                        class="<?= htmlspecialchars($navbarBrandImageClass, ENT_QUOTES) ?>"
                    >
                </span>
                <?php else: ?>
                <span class="navbar-brand-icon">
                    <i class="fas <?= htmlspecialchars($navbarLogo['icon']) ?>"></i>
                </span>
                <?php endif; ?>
                <?php if (!$navbarHideText): ?>
                <span class="navbar-brand-text"><?= htmlspecialchars($navbarLogo['label']) ?></span>
                <?php endif; ?>
            </a>
        </div>

        <!-- CONTENU NAVBAR -->
        <div class="d-flex align-items-center flex-grow-1 px-3">

            <!-- SEARCH -->
            <form class="d-flex mx-3 navbar-search" role="search" id="navbarSearchForm" autocomplete="off">
                <div class="input-group">
                    <input class="form-control bg-secondary text-white border-0"
                           id="navbarSearchInput"
                           type="search"
                           placeholder="Rechercher une page..."
                           aria-label="Search">
                    <button class="btn btn-outline-light" type="submit">
                        <i class="fas fa-search"></i>
                    </button>
                </div>
                <div class="navbar-search-results" id="navbarSearchResults" hidden></div>
            </form>
            <script id="navbarSearchIndex" type="application/json"><?= htmlspecialchars(json_encode($navbarSearchIndex, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ENT_NOQUOTES, 'UTF-8') ?></script>

            <!-- USER / INFOS -->
            <div class="d-flex align-items-center ms-auto">
                <button
                    type="button"
                    class="navbar-flow-explanation-toggle btn btn-link text-white-50 p-1 border-0 navbar-clickable me-2"
                    id="navbarFlowExplanationToggle"
                    title="Masquer les explications de page"
                    aria-label="Masquer ou afficher les explications de page"
                    aria-pressed="false"
                >
                    <i class="fa fa-eye-slash" aria-hidden="true"></i>
                </button>
                <span class="navbar-text text-white me-4 d-none d-md-block navbar-device-switch-wrap">
                    <i class="fas fa-network-wired me-2 navbar-device-icon" id="navbarDeviceIcon"></i>
                    <button
                        type="button"
                        class="btn btn-link p-0 border-0 text-decoration-none text-white navbar-device-switch-trigger navbar-clickable"
                        id="navbarDeviceSwitchBtn"
                        aria-expanded="false"
                    >
                    <span id="routerInfo" data-device-id="<?= htmlspecialchars((string)($navbarDevice['id'] ?? '')) ?>" data-device-type="<?= htmlspecialchars($navbarDevice['type']) ?>">
                        <span class="navbar-device-combined">
                            <?= htmlspecialchars($navbarDevice['name']) ?> : <?= htmlspecialchars($navbarDeviceAddress !== '' ? $navbarDeviceAddress : '-') ?>
                        </span>
                    </span>
                    </button>
                    <div class="navbar-device-switch-results" id="navbarDeviceSwitchResults" hidden></div>
                </span>

                <span class="navbar-text text-white me-4">
                    <i class="fas fa-user-circle me-1"></i>
                    Bienvenue,
                    <?php if ($isAdminUser): ?>
                    <a
                        href="/pages/administration.php"
                        class="navbar-user-shortcut navbar-clickable text-decoration-none"
                        id="authenticatedUsername"
                        title="Accéder à l administration"
                    >
                        <?php echo htmlspecialchars($_SESSION['username'] ?? 'Utilisateur'); ?>
                    </a>
                    <a
                        href="/pages/admin_notifications.php"
                        class="navbar-user-shortcut navbar-notification-shortcut navbar-clickable text-decoration-none ms-2"
                        title="Notifications"
                        aria-label="Notifications"
                    >
                        <i class="fa fa-bell"></i>
                        <?php if ($navbarUnreadNotifications > 0): ?>
                            <span class="navbar-notification-badge">
                                <?= $navbarUnreadNotifications ?>
                            </span>
                        <?php endif; ?>
                    </a>
                    <?php else: ?>
                    <span id="authenticatedUsername">
                        <?php echo htmlspecialchars($_SESSION['username'] ?? 'Utilisateur'); ?>
                    </span>
                    <?php endif; ?>
                </span>

                <a class="btn btn-danger navbar-clickable" href="../index.php?logout=true">
                    <i class="fas fa-sign-out-alt me-2"></i> Déconnexion
                </a>
            </div>

        </div>
    </div>
</nav>

<!-- SIDEBAR -->
<div class="border-right sidebar" id="sidebar-wrapper">
    <div class="list-group list-group-flush">

<!-- DASHBOARD -->
<a href="dashboard.php" class="list-group-item level-0 <?= ($current=='dashboard.php')?'active':'' ?>">
    <i class="fa fa-home"></i> Dashboard
</a>

<!-- =========================
     HOTSPOT (LEVEL 0)
========================= -->
<div class="menu-item">

    <div class="list-group-item dropdown-btn level-0">
        <span><i class="fa fa-wifi"></i> Hotspot</span>
        <i class="fa fa-caret-down"></i>
    </div>

    <div class="submenu-group">

        <!-- USERS -->
        <div class="menu-item">

            <div class="list-group-item dropdown-btn level-1">
                <span><i class="fa fa-users"></i> Users</span>
                <i class="fa fa-caret-down"></i>
            </div>

            <div class="submenu-group">
                <a href="users_list.php" class="list-group-item level-2 <?= ($current=='users_list.php')?'active':'' ?>">
                    <i class="fa fa-list"></i> List
                </a>

                <?php if ($isAdminUser): ?>
                <a href="add_hotspot_user.php" class="list-group-item level-2 <?= ($current=='add_hotspot_user.php')?'active':'' ?>">
                    <i class="fa fa-user-plus"></i> Add User
                </a>
                <?php endif; ?>

                <a href="/pages/generate.php" class="list-group-item level-2 <?= ($current=='generate.php')?'active':'' ?>">
                    <i class="fa fa-ticket"></i> Generate
                </a>

                <a href="/pages/hotspot_vouchers.php" class="list-group-item level-2 <?= ($current=='hotspot_vouchers.php')?'active':'' ?>">
                    <i class="fa fa-ticket-alt"></i> Vouchers
                </a>
            </div>

        </div>

        <!-- PROFILE -->
        <?php if ($isAdminUser): ?>
        <div class="menu-item">

            <div class="list-group-item dropdown-btn level-1">
                <span><i class="fa fa-pie-chart"></i> User Profile</span>
                <i class="fa fa-caret-down"></i>
            </div>

            <div class="submenu-group">
                <a href="/pages/profile_list.php" class="list-group-item level-2 <?= ($current=='profile_list.php')?'active':'' ?>"><i class="fa fa-list"></i> Profile List</a>
                <a href="/pages/add_profile.php" class="list-group-item level-2 <?= ($current=='add_profile.php')?'active':'' ?>"><i class="fa fa-plus-square"></i> Add Profile</a>
            </div>

        </div>
        <?php endif; ?>

        <!-- autres -->
        <a href="sessions_list.php" class="list-group-item level-1 <?= ($current=='sessions_list.php')?'active':'' ?>">
            <i class="fa fa-wifi"></i> Hotspot Active
        </a>
        <a href="/pages/hosts.php" class="list-group-item level-1 <?= ($current=='hosts.php')?'active':'' ?>"><i class="fa fa-laptop"></i> Hosts</a>
        <a href="/pages/ip_bindings.php" class="list-group-item level-1 <?= ($current=='ip_bindings.php' || $current=='add_ip_binding.php')?'active':'' ?>"><i class="fa fa-address-book"></i> IP Bindings</a>
        <a href="/pages/cookies.php" class="list-group-item level-1 <?= ($current=='cookies.php')?'active':'' ?>"><i class="fa fa-hourglass"></i> Cookies</a>

    </div>

</div>

<!-- =========================
     LOG
========================= -->
<div class="menu-item">

    <div class="list-group-item dropdown-btn level-0">
        <span><i class="fa fa-align-justify"></i> Log</span>
        <i class="fa fa-caret-down"></i>
    </div>

    <div class="submenu-group">
        <a href="system_log.php" class="list-group-item level-1 <?= ($current=='system_log.php')?'active':'' ?>">
            <i class="fa fa-clipboard-list"></i> System Log
        </a>

        <a href="/pages/user_logs.php" class="list-group-item level-1 <?= ($current=='user_logs.php')?'active':'' ?>">
            <i class="fa fa-users"></i> User Log
        </a>

        <?php if ($isAdminUser): ?>
        <a href="/pages/admin_notifications.php" class="list-group-item level-1 <?= ($current=='admin_notifications.php')?'active':'' ?>">
            <i class="fa fa-bell"></i> Notifications
        </a>
        <?php endif; ?>
    </div>

</div>

<!-- =========================
     SYSTEM
========================= -->
<div class="menu-item">

    <div class="list-group-item dropdown-btn level-0">
        <span><i class="fa fa-cog"></i> System</span>
        <i class="fa fa-caret-down"></i>
    </div>

    <div class="submenu-group">
        <a href="system_info.php" class="list-group-item level-1 <?= ($current=='system_info.php')?'active':'' ?>">
            <i class="fa fa-server"></i> Info Système
        </a>

        <a href="/pages/scheduler.php" class="list-group-item level-1 <?= ($current=='scheduler.php' || $current=='add_scheduler.php')?'active':'' ?>"><i class="fa fa-clock-o"></i> Scheduler</a>
    </div>

</div>

<!-- DHCP -->
<a href="dhcp_leases.php"
   class="list-group-item level-0 <?= ($current=='dhcp_leases.php' || $current=='add_dhcp_lease.php')?'active':'' ?>">
    <i class="fa fa-sitemap"></i> DHCP Leases
</a>

<!-- TRAFFIC -->
<a href="traffic_monitoring.php"
   class="list-group-item level-0 <?= ($current=='traffic_monitoring.php')?'active':'' ?>">
    <i class="fa fa-area-chart"></i> Traffic Monitor
</a>

<!-- FINANCES -->
<div class="menu-item">

    <div class="list-group-item dropdown-btn level-0">
        <span><i class="fa fa-file-alt"></i> Finances</span>
        <i class="fa fa-caret-down"></i>
    </div>

    <div class="submenu-group">
        <a href="reports.php"
           class="list-group-item level-1 <?= ($current=='reports.php')?'active':'' ?>">
            <i class="fa fa-file-lines"></i> Rapport
        </a>
        <a href="user_recharge.php"
           class="list-group-item level-1 <?= ($current=='user_recharge.php')?'active':'' ?>">
            <i class="fa fa-repeat"></i> Recharge Client
        </a>
        <?php if ($isAdminUser): ?>
        <a href="recouvrement.php"
           class="list-group-item level-1 <?= ($current=='recouvrement.php')?'active':'' ?>">
            <i class="fa fa-hand-holding-dollar"></i> Recouvrement
        </a>
        <a href="recouvrement_invoices.php"
           class="list-group-item level-1 <?= ($current=='recouvrement_invoices.php')?'active':'' ?>">
            <i class="fa fa-file-invoice-dollar"></i> Suivi Facture
        </a>
        <?php endif; ?>
    </div>

</div>

<!-- SETTINGS -->
 <div class="menu-item">

    <div class="list-group-item dropdown-btn level-0">

    <span><i class="fa fa-cog"></i> Paramètres</span>
    <i class="fa fa-caret-down"></i>
</div>

<div class="submenu-group">

    <?php if ($isAdminUser): ?>
    <a href="administration.php"
       class="list-group-item level-1 <?= ($current=='administration.php')?'active':'' ?>">
        <i class="fa fa-user-shield"></i> Administration
    </a>

    <!-- NETWORK DEVICES -->
    <a href="network_devices.php"
       class="list-group-item level-1 <?= ($current=='network_devices.php')?'active':'' ?>">
        <i class="fa fa-network-wired"></i> Network Devices
    </a>

    <!-- LICENCE -->
    <a href="license_manager.php"
       class="list-group-item level-1 <?= ($current=='license_manager.php')?'active':'' ?>">
        <i class="fa fa-key"></i> Licences
    </a>

    <!-- MIKHMON -->
    <a href="freeradius.php"
       class="list-group-item level-1 <?= ($current=='freeradius.php')?'active':'' ?>">
        <i class="fa fa-server"></i> FreeRADIUS
    </a>

    <a href="portal_profiles.php"
       class="list-group-item level-1 <?= ($current=='portal_profiles.php')?'active':'' ?>">
        <i class="fa fa-globe"></i> Profils Portail
    </a>
    <?php endif; ?>

</div>
</div>

<!-- ABOUT -->
<a href="/pages/about.php" class="list-group-item level-0 <?= ($current=='about.php')?'active':'' ?>">
    <i class="fa fa-info-circle"></i> À propos
</a>

</div>
</div>
