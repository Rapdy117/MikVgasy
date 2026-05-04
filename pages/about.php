<?php
session_start();

require_once '../includes/device_manager.php';
require_once '../config/db.php';

/* SECURITY */
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: ../index.php');
    exit();
}

/* CSRF */
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$deviceStore = loadDeviceStore();
$devices = $deviceStore['devices'] ?? [];
$activeDeviceId = (string)($deviceStore['active_device_id'] ?? '');
$activeDevice = null;
foreach ($devices as $device) {
    if ((string)($device['id'] ?? '') === $activeDeviceId) {
        $activeDevice = $device;
        break;
    }
}

$counts = [
    'profiles' => 0,
    'users' => 0,
    'vouchers' => 0,
    'recharges' => 0,
];

try {
    $counts['profiles'] = (int)$pdo->query('SELECT COUNT(*) FROM profiles')->fetchColumn();
    $counts['users'] = (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS vouchers (
            id INT(11) NOT NULL AUTO_INCREMENT,
            code VARCHAR(100) NOT NULL,
            profile_id INT(11) NOT NULL,
            used TINYINT(1) DEFAULT 0,
            used_by VARCHAR(100) DEFAULT NULL,
            used_at DATETIME DEFAULT NULL,
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY code (code),
            KEY profile_id (profile_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
    );
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS recharge_history (
            id INT(11) NOT NULL AUTO_INCREMENT,
            device_id INT(11) DEFAULT NULL,
            device_type VARCHAR(50) DEFAULT NULL,
            username VARCHAR(100) DEFAULT NULL,
            profile_name VARCHAR(100) DEFAULT NULL,
            mode VARCHAR(50) DEFAULT NULL,
            operator_username VARCHAR(100) DEFAULT NULL,
            effect_summary TEXT DEFAULT NULL,
            current_profile VARCHAR(100) DEFAULT NULL,
            current_time_limit VARCHAR(100) DEFAULT NULL,
            current_data_limit VARCHAR(100) DEFAULT NULL,
            current_expiration VARCHAR(100) DEFAULT NULL,
            projected_profile VARCHAR(100) DEFAULT NULL,
            projected_time_limit VARCHAR(100) DEFAULT NULL,
            projected_data_limit VARCHAR(100) DEFAULT NULL,
            projected_expiration VARCHAR(100) DEFAULT NULL,
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
    );
    $counts['vouchers'] = (int)$pdo->query('SELECT COUNT(*) FROM vouchers')->fetchColumn();
    $counts['recharges'] = (int)$pdo->query('SELECT COUNT(*) FROM recharge_history')->fetchColumn();
} catch (Throwable $e) {
}
?>

<?php
$pageTitle = 'À propos';
require_once '../includes/layout_header.php';
?>
<style>
.about-card .card-header {
        background-color: var(--theme-card-soft) !important;
        color: var(--theme-primary) !important;
        border-bottom: 1px solid rgba(255, 255, 255, 0.08) !important;
    }

    .about-card .card-body {
        color: var(--theme-text);
    }

    .about-page-title {
        font-size: calc(0.875rem + 2px);
    }

    .about-label {
        color: rgba(255, 255, 255, 0.62);
        font-size: 12px;
        margin-bottom: 2px;
    }

    .about-value {
        color: var(--theme-text);
    }

    .about-rule {
        color: var(--theme-text);
        font-size: 13px;
        line-height: 1.55;
    }
</style>

<div class="card shadow-sm mb-3">
    <div class="card-body py-3">
        <div class="d-flex align-items-center text-white about-page-title">
            <i class="fa fa-info-circle me-2"></i>
            <span class="small fw-semibold">À propos</span>
        </div>
    </div>
</div>

<div class="row">
    <div class="col-lg-7 mb-3">
        <div class="card shadow-sm mb-3 about-card">
            <div class="card-body">
                <p class="text-white mb-3">
                    Radius Manager centralise la gestion hotspot, profils, utilisateurs, vouchers,
                    recharges, supervision et recouvrement avec une architecture locale orientée
                    <strong>MikroTik</strong>, <strong>RADIUS</strong> et <strong>OPNsense</strong>.
                    Les actions sensibles sont progressivement protégées par un agent Windows local.
                </p>

                <div class="row g-3">
                    <div class="col-md-6">
                        <div class="about-label">Version applicative</div>
                        <div class="about-value">V1 locale sécurisée</div>
                    </div>
                    <div class="col-md-6">
                        <div class="about-label">Statut</div>
                        <div><span class="badge bg-success">Actif</span></div>
                    </div>
                    <div class="col-md-6">
                        <div class="about-label">Backend pilote</div>
                        <div class="about-value">PHP UI + Agent Windows</div>
                    </div>
                    <div class="col-md-6">
                        <div class="about-label">Backends supportés</div>
                        <div class="about-value">MikroTik, RADIUS, OPNsense</div>
                    </div>
                    <div class="col-md-6">
                        <div class="about-label">Device actif</div>
                        <div class="about-value">
                            <?= htmlspecialchars((string)($activeDevice['name'] ?? 'Aucun device actif')) ?>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="about-label">Type actif</div>
                        <div class="about-value">
                            <?= htmlspecialchars(strtoupper((string)($activeDevice['type'] ?? '-'))) ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="card shadow-sm about-card">
            <div class="card-header">
                <i class="fa fa-sitemap me-2"></i> Modules opérationnels
            </div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-6">
                        <div class="about-label">Hotspot / Users</div>
                        <div class="about-value">Création, liste, modification simple, suppression, recharge</div>
                    </div>
                    <div class="col-md-6">
                        <div class="about-label">Profils</div>
                        <div class="about-value">CRUD profil, règles d’offre, quota data, recharge liée</div>
                    </div>
                    <div class="col-md-6">
                        <div class="about-label">Logs / Monitoring</div>
                        <div class="about-value">Dashboard, sessions, traffic, user log, system log</div>
                    </div>
                    <div class="col-md-6">
                        <div class="about-label">Réseau local</div>
                        <div class="about-value">Hosts, cookies, IP bindings, DHCP leases</div>
                    </div>
                    <div class="col-md-6">
                        <div class="about-label">Commercial</div>
                        <div class="about-value">Recharge, historique local, reports, vouchers locaux</div>
                    </div>
                    <div class="col-md-6">
                        <div class="about-label">Administration</div>
                        <div class="about-value">Comptes locaux, export SQL, import SQL</div>
                    </div>
                    <div class="col-md-6">
                        <div class="about-label">Sécurité locale</div>
                        <div class="about-value">Licence signée, intégrité, agent backend</div>
                    </div>
                    <div class="col-md-6">
                        <div class="about-label">Installation</div>
                        <div class="about-value">WAMP local, agents dans bin/agent</div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="col-lg-5 mb-3">
        <div class="card shadow-sm mb-3 about-card">
            <div class="card-header">
                <i class="fa fa-chart-bar me-2"></i> Bilan rapide
            </div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-6">
                        <div class="about-label">Profils</div>
                        <div class="about-value"><?= (int)$counts['profiles'] ?></div>
                    </div>
                    <div class="col-6">
                        <div class="about-label">Utilisateurs</div>
                        <div class="about-value"><?= (int)$counts['users'] ?></div>
                    </div>
                    <div class="col-6">
                        <div class="about-label">Vouchers</div>
                        <div class="about-value"><?= (int)$counts['vouchers'] ?></div>
                    </div>
                    <div class="col-6">
                        <div class="about-label">Recharges</div>
                        <div class="about-value"><?= (int)$counts['recharges'] ?></div>
                    </div>
                </div>
            </div>
        </div>

        <div class="card shadow-sm about-card">
            <div class="card-header">
                <i class="fa fa-circle-info me-2"></i> Règles retenues
            </div>
            <div class="card-body">
                <div class="about-rule mb-2">
                    Les opérations sensibles passent par le contrôle de licence et d’intégrité avant exécution.
                </div>
                <div class="about-rule mb-2">
                    La génération de licence reste côté éditeur et ne doit pas être livrée au client.
                </div>
                <div class="about-rule">
                    L’interface PHP reste l’affichage ; l’agent local devient le point d’autorisation backend.
                </div>
            </div>
        </div>
    </div>
</div>

</div>
</div>
</div>


<?php
require_once '../includes/layout_footer.php';
?>
