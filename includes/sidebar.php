<?php
require_once __DIR__ . '/device_manager.php';

$current = basename($_SERVER['PHP_SELF']);
$navbarDevice = getNavbarDeviceInfo();
?>

<nav class="navbar navbar-expand-lg navbar-dark fixed-top" id="main-navbar">
    <div class="container-fluid d-flex align-items-center p-0">

        <!-- LOGO (aligné sidebar) -->
        <div class="navbar-logo-block">
            <a href="dashboard.php" class="d-flex align-items-center justify-content-center h-100 text-decoration-none">
                <img src="../assets/images/logo.png" class="navbar-logo">
            </a>
        </div>

        <!-- CONTENU NAVBAR -->
        <div class="d-flex align-items-center flex-grow-1 px-3">

            <!-- SEARCH -->
            <form class="d-flex mx-3 navbar-search" role="search">
                <div class="input-group">
                    <input class="form-control bg-secondary text-white border-0"
                           type="search"
                           placeholder="Rechercher utilisateur..."
                           aria-label="Search">
                    <button class="btn btn-outline-light" type="submit">
                        <i class="fas fa-search"></i>
                    </button>
                </div>
            </form>

            <!-- USER / INFOS -->
            <div class="d-flex align-items-center ms-auto">
                <span class="navbar-text text-white me-4 d-none d-md-block">
                    <i class="fas fa-network-wired me-2 navbar-device-icon" id="navbarDeviceIcon"></i>
                    <span id="routerInfo" data-device-id="<?= htmlspecialchars((string)($navbarDevice['id'] ?? '')) ?>" data-device-type="<?= htmlspecialchars($navbarDevice['type']) ?>">
                        <?= htmlspecialchars($navbarDevice['name']) ?>
                        <?php if (!empty($navbarDevice['ip'])): ?>
                            | <?= htmlspecialchars($navbarDevice['ip']) ?>
                        <?php elseif (!empty($navbarDevice['host'])): ?>
                            | <?= htmlspecialchars($navbarDevice['host']) ?>
                        <?php endif; ?>
                    </span>
                </span>

                <span class="navbar-text text-white me-4">
                    <i class="fas fa-user-circle me-1"></i>
                    Bienvenue,
                    <span id="authenticatedUsername">
                        <?php echo htmlspecialchars($_SESSION['username'] ?? 'Utilisateur'); ?>
                    </span>
                </span>

                <a class="btn btn-danger" href="../index.php?logout=true">
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

                <a href="add_hotspot_user.php" class="list-group-item level-2 <?= ($current=='add_hotspot_user.php')?'active':'' ?>">
                    <i class="fa fa-user-plus"></i> Add User
                </a>

                <a href="/pages/generate.php" class="list-group-item level-2">
                    <i class="fa fa-ticket"></i> Generate
                </a>
            </div>

        </div>

        <!-- PROFILE -->
        <div class="menu-item">

            <div class="list-group-item dropdown-btn level-1">
                <span><i class="fa fa-pie-chart"></i> User Profile</span>
                <i class="fa fa-caret-down"></i>
            </div>

            <div class="submenu-group">
                <a href="/pages/profile_list.php" class="list-group-item level-2"><i class="fa fa-list"></i> Profile List</a>
                <a href="/pages/add_profile.php" class="list-group-item level-2"><i class="fa fa-plus-square"></i> Add Profile</a>
            </div>

        </div>

        <!-- autres -->
        <a href="sessions_liste.php" class="list-group-item level-1 <?= ($current=='sessions_liste.php')?'active':'' ?>">
            <i class="fa fa-wifi"></i> Hotspot Active
        </a>
        <a href="/pages/hosts.php" class="list-group-item level-1"><i class="fa fa-laptop"></i> Hosts</a>
        <a href="/pages/ip_bindings.php" class="list-group-item level-1"><i class="fa fa-address-book"></i> IP Bindings</a>
        <a href="/pages/cookies.php" class="list-group-item level-1"><i class="fa fa-hourglass"></i> Cookies</a>

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
        <a href="hotspot_logs.php" class="list-group-item level-1 <?= ($current=='hotspot_logs.php')?'active':'' ?>">
            <i class="fa fa-clipboard-list"></i> Hotspot Log
        </a>

        <a href="/pages/user_logs.php" class="list-group-item level-1">
            <i class="fa fa-users"></i> User Log
        </a>
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

        <a href="/pages/scheduler.php" class="list-group-item level-1"><i class="fa fa-clock-o"></i> Scheduler</a>
        <a href="/pages/reboot.php" class="list-group-item level-1"><i class="fa fa-power-off"></i> Reboot</a>
        <a href="/pages/shutdown.php" class="list-group-item level-1"><i class="fa fa-power-off"></i> Shutdown</a>
    </div>

</div>

<!-- DHCP -->
<a href="dhcp_leases.php"
   class="list-group-item level-0 <?= ($current=='dhcp_leases.php')?'active':'' ?>">
    <i class="fa fa-sitemap"></i> DHCP Leases
</a>

<!-- TRAFFIC -->
<a href="traffic_monitoring.php"
   class="list-group-item level-0 <?= ($current=='traffic_monitoring.php')?'active':'' ?>">
    <i class="fa fa-area-chart"></i> Traffic Monitor
</a>

<!-- REPORT -->
<a href="reports.php"
   class="list-group-item level-0 <?= ($current=='reports.php')?'active':'' ?>">
    <i class="fa fa-file-alt"></i> Report
</a>

<!-- SETTINGS -->
 <div class="menu-item">

    <div class="list-group-item dropdown-btn level-0">

    <span><i class="fa fa-cog"></i> Paramètres</span>
    <i class="fa fa-caret-down"></i>
</div>

<div class="submenu-group">

    <!-- NETWORK DEVICES -->
    <a href="network_devices.php"
       class="list-group-item level-1 <?= ($current=='network_devices.php')?'active':'' ?>">
        <i class="fa fa-network-wired"></i> Network Devices
    </a>

    <!-- ADMINISTRATION -->
    <a href="administration.php"
       class="list-group-item level-1 <?= ($current=='administration.php')?'active':'' ?>">
        <i class="fa fa-user-shield"></i> Administration
    </a>

    <!-- FREERADIUS -->
    <a href="freeradius.php"
       class="list-group-item level-1 <?= ($current=='freeradius.php')?'active':'' ?>">
        <i class="fa fa-shield-alt"></i> FreeRadius
    </a>

</div>
</div>

<!-- ABOUT -->
<a href="/pages/about.php" class="list-group-item level-0">
    <i class="fa fa-info-circle"></i> À propos
</a>

</div>
</div>
