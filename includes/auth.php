<?php

require_once __DIR__ . '/message.php';

function currentLocalUserRole(): string
{
    $role = trim((string)($_SESSION['user_role'] ?? 'administrator'));
    return $role === 'reseller' ? 'reseller' : 'administrator';
}

function currentLocalUserRoleLabel(): string
{
    return currentLocalUserRole() === 'reseller' ? 'Revendeur' : 'Administrateur';
}

function currentLocalUsername(): string
{
    return trim((string)($_SESSION['username'] ?? ''));
}

function isAdministrator(): bool
{
    return currentLocalUserRole() === 'administrator';
}

function canAccessApplicationPage(string $page): bool
{
    if (isAdministrator()) {
        return true;
    }

    $resellerAllowed = [
        'dashboard.php',
        'users_list.php',
        'hosts.php',
        'ip_bindings.php',
        'add_ip_binding.php',
        'cookies.php',
        'generate.php',
        'print_vouchers.php',
        'hotspot_vouchers.php',
        'sessions_list.php',
        'system_log.php',
        'system_info.php',
        'scheduler.php',
        'add_scheduler.php',
        'dhcp_leases.php',
        'add_dhcp_lease.php',
        'traffic_monitoring.php',
        'reports.php',
        'user_recharge.php',
        'user_logs.php',
        'about.php',
    ];

    return in_array($page, $resellerAllowed, true);
}

function denyAccessAndRedirect(string $message = 'Accès réservé à l administrateur.'): void
{
    set_message($message, 'warning');
    header('Location: /pages/dashboard.php');
    exit();
}

function requireAdministratorAccess(string $message = 'Accès réservé à l administrateur.'): void
{
    if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
        set_message('Veuillez vous connecter pour accéder à cette page.', 'danger');
        header('Location: /index.php');
        exit();
    }

    if (!isAdministrator()) {
        denyAccessAndRedirect($message);
    }
}

function requireCurrentPageAccess(): void
{
    if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
        set_message('Veuillez vous connecter pour accéder à cette page.', 'danger');
        header('Location: /index.php');
        exit();
    }

    $page = basename((string)($_SERVER['PHP_SELF'] ?? ''));
    if (!canAccessApplicationPage($page)) {
        denyAccessAndRedirect();
    }
}
