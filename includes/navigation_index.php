<?php

function getNavigationSearchIndex(): array
{
    return [
        [
            'label' => 'Dashboard',
            'breadcrumb' => 'Dashboard',
            'href' => '/pages/dashboard.php',
            'keywords' => ['accueil', 'home', 'tableau de bord', 'dashboard'],
        ],
        [
            'label' => 'List',
            'breadcrumb' => 'Hotspot > Users > List',
            'href' => '/pages/users_list.php',
            'keywords' => ['utilisateurs', 'users', 'liste users', 'clients'],
        ],
        [
            'label' => 'Add User',
            'breadcrumb' => 'Hotspot > Users > Add User',
            'href' => '/pages/add_hotspot_user.php',
            'keywords' => ['ajout user', 'nouvel utilisateur', 'add user', 'creer user'],
        ],
        [
            'label' => 'Generate',
            'breadcrumb' => 'Hotspot > Users > Generate',
            'href' => '/pages/generate.php',
            'keywords' => ['generation', 'ticket', 'voucher', 'vouchers', 'generer'],
        ],
        [
            'label' => 'Vouchers',
            'breadcrumb' => 'Hotspot > Users > Vouchers',
            'href' => '/pages/hotspot_vouchers.php',
            'keywords' => ['voucher list', 'tickets', 'liste vouchers', 'coupons'],
        ],
        [
            'label' => 'Profile List',
            'breadcrumb' => 'Hotspot > User Profile > Profile List',
            'href' => '/pages/profile_list.php',
            'keywords' => ['profils', 'profiles', 'liste profils'],
        ],
        [
            'label' => 'Add Profile',
            'breadcrumb' => 'Hotspot > User Profile > Add Profile',
            'href' => '/pages/add_profile.php',
            'keywords' => ['ajout profil', 'nouveau profil', 'creer profil'],
        ],
        [
            'label' => 'Hotspot Active',
            'breadcrumb' => 'Hotspot > Hotspot Active',
            'href' => '/pages/sessions_list.php',
            'keywords' => ['sessions', 'actifs', 'hotspot active', 'connexions'],
        ],
        [
            'label' => 'Hosts',
            'breadcrumb' => 'Hotspot > Hosts',
            'href' => '/pages/hosts.php',
            'keywords' => ['hosts', 'hotes', 'hotspot host'],
        ],
        [
            'label' => 'IP Bindings',
            'breadcrumb' => 'Hotspot > IP Bindings',
            'href' => '/pages/ip_bindings.php',
            'keywords' => ['binding', 'bindings', 'ip binding', 'liaison ip'],
        ],
        [
            'label' => 'Cookies',
            'breadcrumb' => 'Hotspot > Cookies',
            'href' => '/pages/cookies.php',
            'keywords' => ['cookie', 'cookies hotspot'],
        ],
        [
            'label' => 'System Log',
            'breadcrumb' => 'Log > System Log',
            'href' => '/pages/system_log.php',
            'keywords' => ['logs', 'journal systeme', 'system log', 'winbox log'],
        ],
        [
            'label' => 'User Log',
            'breadcrumb' => 'Log > User Log',
            'href' => '/pages/user_logs.php',
            'keywords' => ['journal user', 'user log', 'logs user', 'session user', 'rapport user'],
        ],
        [
            'label' => 'Notifications',
            'breadcrumb' => 'Log > Notifications',
            'href' => '/pages/admin_notifications.php',
            'keywords' => ['notifications', 'alertes admin', 'suivi incidents', 'evenements importants'],
        ],
        [
            'label' => 'Info Système',
            'breadcrumb' => 'System > Info Système',
            'href' => '/pages/system_info.php',
            'keywords' => ['info systeme', 'system info', 'resource'],
        ],
        [
            'label' => 'Scheduler',
            'breadcrumb' => 'System > Scheduler',
            'href' => '/pages/scheduler.php',
            'keywords' => ['sched', 'planificateur', 'taches'],
        ],
        [
            'label' => 'DHCP Leases',
            'breadcrumb' => 'DHCP Leases',
            'href' => '/pages/dhcp_leases.php',
            'keywords' => ['dhcp', 'leases', 'baux', 'adresse dhcp'],
        ],
        [
            'label' => 'Traffic Monitor',
            'breadcrumb' => 'Traffic Monitor',
            'href' => '/pages/traffic_monitoring.php',
            'keywords' => ['traffic', 'monitoring', 'bande passante', 'debit'],
        ],
        [
            'label' => 'Rapport',
            'breadcrumb' => 'Finances > Rapport',
            'href' => '/pages/reports.php',
            'keywords' => ['rapport', 'reporting', 'rapports', 'statistiques'],
        ],
        [
            'label' => 'Recharge Client',
            'breadcrumb' => 'Finances > Recharge Client',
            'href' => '/pages/user_recharge.php',
            'keywords' => ['recharge', 'rechargement', 'rajout', 'reabonnement'],
        ],
        [
            'label' => 'Rouvrement',
            'breadcrumb' => 'Finances > Rouvrement',
            'href' => '/pages/recouvrement.php',
            'keywords' => ['recouvrement', 'collection', 'suivi', 'operations sensibles'],
        ],
        [
            'label' => 'Suivi Facture',
            'breadcrumb' => 'Finances > Suivi Facture',
            'href' => '/pages/recouvrement_invoices.php',
            'keywords' => ['facture', 'suivi facture', 'paiement facture', 'invoice', 'recouvrement facture'],
        ],
        [
            'label' => 'Administration',
            'breadcrumb' => 'Paramètres > Administration',
            'href' => '/pages/administration.php',
            'keywords' => ['admin', 'administration', 'utilisateur local', 'login local'],
        ],
        [
            'label' => 'Network Devices',
            'breadcrumb' => 'Paramètres > Network Devices',
            'href' => '/pages/network_devices.php',
            'keywords' => ['device', 'devices', 'equipements', 'network devices', 'nas'],
        ],
        [
            'label' => 'FreeRADIUS',
            'breadcrumb' => 'Paramètres > FreeRADIUS',
            'href' => '/pages/freeradius.php',
            'keywords' => ['radius', 'freeradius', 'serveur radius'],
        ],
        [
            'label' => 'Profils Portail',
            'breadcrumb' => 'Paramètres > Profils Portail',
            'href' => '/pages/portal_profiles.php',
            'keywords' => ['portal', 'profils portail', 'captive portal', 'publication portail', 'multi backend'],
        ],
        [
            'label' => 'À propos',
            'breadcrumb' => 'À propos',
            'href' => '/pages/about.php',
            'keywords' => ['apropos', 'a propos', 'about', 'informations'],
        ],
    ];
}
