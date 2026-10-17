<?php
session_start();

require_once '../includes/message.php';
require_once '../includes/auth.php';
require_once '../config/db.php';
require_once '../includes/device_manager.php';

requireAdministratorAccess('Le contrôle et le filtrage du trafic sont réservés à l administrateur.');

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$store = loadDeviceStore();
$activeDevice = getActiveDeviceRecord($store);
$activeDeviceId = trim((string)($activeDevice['id'] ?? ''));
$activeDeviceType = strtolower((string)($activeDevice['type'] ?? 'other'));
$isMikrotik = is_array($activeDevice) && $activeDeviceType === 'mikrotik';
$activeDeviceLabel = is_array($activeDevice) ? getDeviceDisplayLabel($activeDevice) : 'Aucun routeur sélectionné';

$pageTitle = 'DNS et réseau';
$extraCss = ['../css/traffic_control.css?v=20260915idle2'];
require_once '../includes/layout_header.php';
?>

<input type="hidden" id="trafficControlDeviceId" value="<?= htmlspecialchars($activeDeviceId, ENT_QUOTES) ?>">
<input type="hidden" id="trafficControlCsrf" value="<?= htmlspecialchars((string)$_SESSION['csrf_token'], ENT_QUOTES) ?>">

<div class="traffic-workspace">
<div class="traffic-navigation-bar">
<div class="traffic-mobile-navigation">
<label for="trafficSectionSelect">Section</label>
<select id="trafficSectionSelect" class="form-select">
<option value="dashboard">Vue d’ensemble</option>
<option value="filtering">Filtrage DNS</option>
<option value="activity">Activité & clients</option>
<option value="usage">Consommation</option>
<option value="security">Sécurité réseau</option>
<option value="performance">Performance & QoS</option>
<option value="settings">Paramètres</option>
</select>
</div>
<nav class="traffic-control-modules" role="tablist" aria-label="Sections DNS et réseau">
<button type="button" class="traffic-control-module active" id="trafficTabDashboard" role="tab" aria-selected="true" aria-controls="trafficPanelDashboard" tabindex="0" data-traffic-tab="dashboard"><i class="fa fa-gauge-high traffic-menu-icon-dashboard" aria-hidden="true"></i><span>Vue d’ensemble</span>
</button>
<button type="button" class="traffic-control-module" id="trafficTabFiltering" role="tab" aria-selected="false" aria-controls="trafficPanelFiltering" tabindex="-1" data-traffic-tab="filtering"><i class="fa fa-filter traffic-menu-icon-filtering" aria-hidden="true"></i><span>Filtrage DNS</span>
</button>
<button type="button" class="traffic-control-module" id="trafficTabActivity" role="tab" aria-selected="false" aria-controls="trafficPanelActivity" tabindex="-1" data-traffic-tab="activity"><i class="fa fa-diagram-project traffic-menu-icon-activity" aria-hidden="true"></i><span>Activité & clients</span>
</button>
<button type="button" class="traffic-control-module" id="trafficTabUsage" role="tab" aria-selected="false" aria-controls="trafficPanelUsage" tabindex="-1" data-traffic-tab="usage"><i class="fa fa-chart-column traffic-menu-icon-usage" aria-hidden="true"></i><span>Consommation</span>
</button>
<button type="button" class="traffic-control-module" id="trafficTabSecurity" role="tab" aria-selected="false" aria-controls="trafficPanelSecurity" tabindex="-1" data-traffic-tab="security"><i class="fa fa-shield-halved traffic-menu-icon-security" aria-hidden="true"></i><span>Sécurité réseau</span>
</button>
<button type="button" class="traffic-control-module" id="trafficTabPerformance" role="tab" aria-selected="false" aria-controls="trafficPanelPerformance" tabindex="-1" data-traffic-tab="performance"><i class="fa fa-heart-pulse traffic-menu-icon-performance" aria-hidden="true"></i><span>Performance & QoS</span>
</button>
<button type="button" class="traffic-control-module" id="trafficTabSettings" role="tab" aria-selected="false" aria-controls="trafficPanelSettings" tabindex="-1" data-traffic-tab="settings"><i class="fa fa-sliders traffic-menu-icon-settings" aria-hidden="true"></i><span>Paramètres</span>
</button>
<button type="button" class="traffic-control-module traffic-control-refresh-module" id="trafficControlRefresh" title="Actualiser" aria-label="Actualiser la configuration" <?= $isMikrotik ? '' : 'disabled' ?>><i class="fa fa-rotate" aria-hidden="true"></i><span class="visually-hidden">Actualiser</span></button>
</nav>
</div>
<?php if ($isMikrotik): ?>
<div class="traffic-control-panel active" id="trafficPanelDashboard" role="tabpanel" aria-labelledby="trafficTabDashboard" tabindex="-1" data-traffic-panel="dashboard">
<section class="card traffic-overview-analysis-card mb-3">
<div class="card-body">
<div class="traffic-history-grid" aria-label="Historique réel collecté">
<article class="traffic-history-card traffic-history-card-adlist"><div class="traffic-history-head"><strong><i class="fa fa-ban traffic-title-icon" aria-hidden="true"></i>Adlists DNS</strong><span id="trafficHistoryAdlistNote">Match-count</span></div><div class="traffic-history-body"><div class="traffic-history-canvas"><canvas id="trafficHistoryAdlistChart" role="img" aria-label="Historique des correspondances Adlist par collecte"></canvas></div><div class="traffic-history-stats" id="trafficHistoryAdlistStats" role="status">En attente.</div></div></article>
<article class="traffic-history-card traffic-history-card-firewall"><div class="traffic-history-head"><strong><i class="fa fa-shield-halved traffic-title-icon" aria-hidden="true"></i>Firewall</strong><span id="trafficHistoryFirewallNote">Delta paquets</span></div><div class="traffic-history-body"><div class="traffic-history-canvas"><canvas id="trafficHistoryFirewallChart" role="img" aria-label="Historique des paquets bloqués et acceptés par collecte"></canvas></div><div class="traffic-history-stats" id="trafficHistoryFirewallStats" role="status">En attente.</div></div></article>
<article class="traffic-history-card traffic-history-card-connections"><div class="traffic-history-head"><strong><i class="fa fa-wave-square traffic-title-icon" aria-hidden="true"></i>Connexions</strong><span id="trafficHistoryConnectionNote">Instantané actif</span></div><div class="traffic-history-body"><div class="traffic-history-canvas"><canvas id="trafficHistoryConnectionChart" role="img" aria-label="Historique des connexions actives"></canvas></div><div class="traffic-history-stats" id="trafficHistoryConnectionStats" role="status">En attente.</div></div></article>
<article class="traffic-history-card traffic-history-card-clients"><div class="traffic-history-head"><strong><i class="fa fa-users traffic-title-icon" aria-hidden="true"></i>Clients</strong><span id="trafficHistoryClientNote">Hotspot / observés</span></div><div class="traffic-history-body"><div class="traffic-history-canvas"><canvas id="trafficHistoryClientChart" role="img" aria-label="Historique des clients actifs et observés"></canvas></div><div class="traffic-history-stats" id="trafficHistoryClientStats" role="status">En attente.</div></div></article>
</div>
</div>
</section>
<div class="traffic-overview-status-row mb-4">
<section class="card traffic-status-mini-card"><div class="card-body" id="trafficOverviewAlerts" role="status">En attente de l’analyse du trafic.</div></section>
<section class="card traffic-status-mini-card">
<div class="card-body">
<div id="trafficOverviewChecks" role="status">Les états seront disponibles après lecture du routeur.</div>
</div>
</section>
</div>
</div>

<div class="traffic-control-panel traffic-filtering-unified" id="trafficPanelFiltering" role="tabpanel" aria-labelledby="trafficTabFiltering" tabindex="-1" data-traffic-panel="filtering" hidden>
<div class="traffic-overview-status-row traffic-filtering-summary-row mb-3" id="trafficFilterSummary" aria-label="Résumé du filtrage DNS">
<section class="card traffic-status-mini-card"><div class="card-body">
<div class="traffic-overview-check-grid">
<article class="traffic-overview-check" style="--card-accent:#ef4444"><div class="traffic-card-copy"><span class="traffic-card-node">Adlist</span><span class="traffic-card-label" id="trafficFilterSummaryAdlistLabel">--</span><small id="trafficFilterSummaryAdlistDetail">Chargement…</small></div><div class="traffic-card-metric"><span class="traffic-card-icon"><i class="fa fa-ban" aria-hidden="true"></i></span><strong id="trafficFilterSummaryAdlistValue">--</strong></div></article>
<article class="traffic-overview-check" style="--card-accent:#f59e0b"><div class="traffic-card-copy"><span class="traffic-card-node">Blocages ciblés</span><span class="traffic-card-label">Domaines NXDOMAIN</span><small id="trafficFilterSummaryBlockDetail">--</small></div><div class="traffic-card-metric"><span class="traffic-card-icon"><i class="fa fa-list-check" aria-hidden="true"></i></span><strong id="trafficFilterSummaryBlockValue">--</strong></div></article>
<article class="traffic-overview-check" style="--card-accent:#22c55e"><div class="traffic-card-copy"><span class="traffic-card-node">Exceptions</span><span class="traffic-card-label">Domaines autorisés</span><small id="trafficFilterSummaryAllowDetail">--</small></div><div class="traffic-card-metric"><span class="traffic-card-icon"><i class="fa fa-check" aria-hidden="true"></i></span><strong id="trafficFilterSummaryAllowValue">--</strong></div></article>
<article class="traffic-overview-check" style="--card-accent:#17a2b8"><div class="traffic-card-copy"><span class="traffic-card-node">Protections</span><span class="traffic-card-label">Services et sécurité</span><small id="trafficFilterSummaryServicesDetail">--</small></div><div class="traffic-card-metric"><span class="traffic-card-icon"><i class="fa fa-scale-balanced" aria-hidden="true"></i></span><strong id="trafficFilterSummaryServicesValue">--</strong></div></article>
</div>
</div></section>
</div>
<div class="traffic-filtering-stack">
<section class="card shadow-sm mb-4 traffic-filtering-card traffic-filtering-card-adlists">
                <div class="card-header standard-card-header"><i class="fa fa-ban me-2"></i>Adlists DNS MikroTik</div>
                <div class="card-body">
                    <form id="trafficAdlistForm" class="d-flex gap-2 flex-wrap mb-3">
                        <label class="traffic-form-field" for="trafficAdlistUrl"><span>Liste approuvée</span>
<select class="form-select" id="trafficAdlistUrl" required>
<option value="">Chargement du catalogue…</option>
</select>
</label>
                        <button class="btn btn-primary" type="submit" id="trafficAdlistSubmit">Ajouter et activer</button>
                    </form>
                    <button type="button" class="btn btn-outline-info mb-3" id="trafficAdlistReload" disabled>Rechercher les mises à jour des listes</button>
                    <div class="table-responsive"><table class="table table-sm align-middle mb-0"><thead><tr><th>Source</th><th>Domaines</th><th>Correspondances</th><th>État</th></tr></thead><tbody id="trafficAdlistBody"><tr><td colspan="4" class="text-center text-white-50">Aucune Adlist.</td></tr></tbody></table>
</div>
                </div>
            </section>
<section class="card shadow-sm mb-4 traffic-domain-check traffic-filtering-card traffic-filtering-card-check" aria-labelledby="trafficDomainCheckTitle">
                <div class="card-header standard-card-header">
                    <i class="fa fa-magnifying-glass me-2"></i><span id="trafficDomainCheckTitle">Vérifier les règles du domaine</span>
                </div>
                <div class="card-body">
                    <form id="trafficDomainCheckForm" class="traffic-domain-check-form" novalidate>
                        <div class="traffic-domain-check-field">
                            <label for="trafficDomainCheckInput" class="form-label">Domaine à vérifier</label>
                            <input type="text" class="form-control" id="trafficDomainCheckInput" placeholder="exemple.com" inputmode="url" autocomplete="off" spellcheck="false" required aria-describedby="trafficDomainCheckHelp">
                            <div id="trafficDomainCheckHelp" class="form-text text-white-50">Compare les règles gérées sur le routeur, sans effectuer de résolution DNS. Le résultat peut rester indéterminé pour les listes.</div>
                        </div>
                        <button class="btn btn-primary traffic-domain-check-submit" id="trafficDomainCheckSubmit" type="submit" disabled>
                            <i class="fa fa-search me-1"></i><span>Vérifier</span>
                        </button>
                    </form>
                    <div class="traffic-domain-check-result" id="trafficDomainCheckResult" role="status" aria-live="polite" hidden>
</div>
                </div>
            </section>
<div class="traffic-section-toolbar">
<label for="trafficRuleEffectFilter">Afficher les règles<select class="form-select" id="trafficRuleEffectFilter">
<option value="all">Blocages et exceptions</option>
<option value="block">Blocages</option>
<option value="allow">Exceptions aux listes</option>
</select>
</label>
<p class="traffic-section-help">Toutes les règles concernent le DNS du routeur sélectionné, domaine et sous-domaines inclus.</p>
</div>
<section class="card shadow-sm mb-4 traffic-filtering-card traffic-filtering-card-block" data-rule-kind="block">
                <div class="card-header standard-card-header"><i class="fa fa-list-check me-2"></i>Blocages DNS ciblés</div>
                <div class="card-body">
                    <form id="trafficDnsBlockForm" class="d-flex gap-2 flex-wrap mb-3">
                        <label class="traffic-form-field" for="trafficDnsBlockDomain"><span>Domaine à bloquer</span><input type="text" class="form-control" id="trafficDnsBlockDomain" placeholder="domaine.exemple" autocomplete="off" spellcheck="false" required>
</label>
                        <label class="traffic-form-field" for="trafficDnsBlockCategory"><span>Catégorie</span>
<select class="form-select" id="trafficDnsBlockCategory">
                            <option value="Personnalisé">Personnalisé</option>
                            <option value="Publicité">Publicité</option>
                            <option value="Mise à jour">Mise à jour</option>
                            <option value="Télémétrie">Télémétrie</option>
                        </select>
</label>
                        <button class="btn btn-danger" type="submit">Bloquer</button>
                    </form>
                    <div class="small text-white-50 mb-2">Chaque entrée NXDOMAIN peut être désactivée, réactivée ou supprimée. Portée globale sur le routeur sélectionné ; les sous-domaines sont inclus.</div>
                    <input type="search" class="form-control form-control-sm traffic-table-search mb-2" id="trafficDnsBlockSearch" placeholder="Rechercher un domaine ou une catégorie…" aria-label="Rechercher un blocage DNS">
                    <div class="table-responsive"><table class="table table-sm align-middle mb-0"><thead><tr><th>Domaine</th><th>Catégorie</th><th>Sous-domaines</th><th>État</th><th>Actions</th></tr></thead><tbody id="trafficDnsBlockBody"><tr><td colspan="5" class="text-center text-white-50">Aucun blocage DNS géré.</td></tr></tbody></table>
</div>
                </div>
            </section>
<section class="card shadow-sm mb-4 traffic-filtering-card traffic-filtering-card-allow" data-rule-kind="allow">
                <div class="card-header standard-card-header"><i class="fa fa-check me-2"></i>Exceptions aux listes DNS</div>
                <div class="card-body">
                    <form id="trafficWhitelistForm" class="d-flex gap-2 flex-wrap mb-3">
                        <label class="traffic-form-field" for="trafficWhitelistDomain"><span>Domaine à excepter des listes</span><input type="text" class="form-control" id="trafficWhitelistDomain" placeholder="exemple.com" autocomplete="off" spellcheck="false" required>
</label>
                        <button class="btn btn-success" type="submit">Ajouter une exception</button>
                    </form>
                    <div class="small text-white-50 mb-2">Portée globale sur le routeur sélectionné, domaine et sous-domaines inclus. Un conflit avec un blocage personnalisé actif est refusé.</div>
                    <input type="search" class="form-control form-control-sm traffic-table-search mb-2" id="trafficWhitelistSearch" placeholder="Rechercher une exception…" aria-label="Rechercher une exception DNS">
                    <div class="table-responsive"><table class="table table-sm align-middle mb-0"><thead><tr><th>Domaine</th><th>Sous-domaines</th><th>Commentaire</th><th>Action</th></tr></thead><tbody id="trafficWhitelistBody"><tr><td colspan="4" class="text-center text-white-50">Aucune exception aux listes.</td></tr></tbody></table>
</div>
                </div>
            </section>
<section class="card shadow-sm mb-4 traffic-policy-reference traffic-filtering-card traffic-filtering-card-services" aria-labelledby="trafficPolicyTitle">
                <div class="card-header standard-card-header d-flex align-items-center justify-content-between gap-2 flex-wrap">
                    <span id="trafficPolicyTitle"><i class="fa fa-scale-balanced me-2"></i>Services et mises à jour</span>
                    <span class="badge text-bg-info" id="trafficPolicyCount">État réel en attente</span>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0 traffic-policy-table">
                        <thead><tr><th>Protection</th><th>Portée</th><th>État réel</th><th>Action</th></tr></thead>
                        <tbody id="trafficPolicyBody">
                            <tr><td colspan="4" class="text-center text-white-50">Chargement des protections RouterOS…</td></tr>
                        </tbody>
                    </table>
                </div>
            </section>
</div>
</div>

<div class="traffic-control-panel" id="trafficPanelActivity" role="tabpanel" aria-labelledby="trafficTabActivity" tabindex="-1" data-traffic-panel="activity" hidden>
<section class="card shadow-sm mb-4 traffic-listener" id="connectionObservation" aria-labelledby="trafficListenerTitle">
            <div class="card-header standard-card-header d-flex align-items-center justify-content-between gap-3 flex-wrap">
                <div>
                    <div class="d-flex align-items-center gap-2">
                        <i class="fa fa-headphones"></i>
                        <span id="trafficListenerTitle">Corrélation réseau MikroTik</span>
                        <span class="badge text-bg-secondary" id="trafficListenerState">Arrêtée</span>
                    </div>
                    <small class="traffic-listener-subtitle">Instantané conntrack en lecture seule · activité confirmée uniquement lorsqu’un débit est mesuré</small>
                </div>
                <div class="d-flex align-items-center gap-2 flex-wrap">
                    <select class="form-select form-select-sm traffic-listener-refresh" id="trafficListenerRefresh" aria-label="Fréquence de l'observation">
                        <option value="60000" selected>60 secondes</option>
                        <option value="120000">2 minutes</option>
                        <option value="300000">5 minutes</option>
                    </select>
                    <button type="button" class="btn btn-primary btn-sm" id="trafficListenerToggle" disabled>
                        <i class="fa fa-hourglass-half me-1"></i>Préparation…
                    </button>
                    <button type="button" class="btn btn-outline-light btn-sm" id="trafficListenerRefreshNow" disabled title="Actualiser maintenant" aria-label="Actualiser l’observation">
                        <i class="fa fa-rotate"></i>
                    </button>
                </div>
            </div>
            <div class="card-body">
                <div class="traffic-listener-notice mb-3" id="trafficListenerNotice">
                    L’observation démarrera après la lecture de l’état RouterOS.
                </div>

                <div class="traffic-listener-metrics mb-3">
                    <div><span>Entrées conntrack conservées</span><strong id="listenerTracked">--</strong>
</div>
                    <div><span>Entrées NAT analysées</span><strong id="listenerObserved">--</strong>
</div>
                    <div><span>Débit confirmé / à revoir</span><strong id="listenerActivity">--</strong>
</div>
                    <div><span>Hotspot actifs / IP suivies</span><strong id="listenerUsers">--</strong>
</div>
                    <div><span>Connexions corrélées au DNS</span><strong id="listenerDns">--</strong>
</div>
                    <div><span>IP à vérifier</span><strong id="listenerReview">--</strong>
</div>
                </div>

                <div class="traffic-analysis-summary mb-3">
                    <div id="trafficStateSummary"><strong>États TCP :</strong> --</div>
                    <div id="trafficSnapshotSummary"><strong>Source :</strong> --</div>
                    <div><span class="traffic-status-dot active"></span>Débit confirmé <span class="traffic-status-dot retained ms-3"></span>Conntrack sans débit <span class="traffic-status-dot review ms-3"></span>À vérifier</div>
                </div>

                <div class="d-flex align-items-center justify-content-between gap-2 flex-wrap mb-2">
                    <div>
                        <h3 class="h6 mb-1 traffic-section-title">Explorer l’activité</h3>
                        <div class="traffic-section-help">Identité MikroTik, volume conntrack, activité confirmée et principales destinations DNS.</div>
                    </div>
                    <div class="d-flex gap-2 flex-wrap justify-content-end">
                        <div class="btn-group btn-group-sm traffic-filter-group" role="group" aria-label="Filtrer le diagnostic">
                            <button type="button" class="btn active" data-listener-filter="all">Toutes</button>
                            <button type="button" class="btn" data-listener-filter="active">Actives</button>
                            <button type="button" class="btn" data-listener-filter="review">À vérifier</button>
                            <button type="button" class="btn" data-listener-filter="unresolved">DNS inconnu</button>
                        </div>
                        <label>Protocole<select id="trafficListenerProtocol" class="form-select form-select-sm"><option value="">Tous</option><option value="tcp">TCP</option><option value="udp">UDP</option><option value="icmp">ICMP</option></select></label><label>Trier<select id="trafficListenerSort" class="form-select form-select-sm"><option value="connections">Connexions</option><option value="bytes">Volume</option><option value="active_connections">Activité</option></select></label><input type="search" class="form-control form-control-sm traffic-listener-search" id="trafficListenerSearch" placeholder="IP, utilisateur, domaine, port…" aria-label="Filtrer les connexions">
                    </div>
                </div>
<div class="traffic-subtabs" role="tablist" aria-label="Vues de l’activité">
<button type="button" id="trafficSubactivityclients" class="traffic-subtab active" role="tab" aria-selected="true" aria-controls="trafficSubpanelactivityclients" tabindex="0" data-traffic-subtab="clients" data-traffic-subgroup="activity">Clients</button>
<button type="button" id="trafficSubactivitydomains" class="traffic-subtab" role="tab" aria-selected="false" aria-controls="trafficSubpanelactivitydomains" tabindex="-1" data-traffic-subtab="domains" data-traffic-subgroup="activity">Domaines</button>
<button type="button" id="trafficSubactivitysignatures" class="traffic-subtab" role="tab" aria-selected="false" aria-controls="trafficSubpanelactivitysignatures" tabindex="-1" data-traffic-subtab="signatures" data-traffic-subgroup="activity">Signatures DNS</button>
<button type="button" id="trafficSubactivityconnections" class="traffic-subtab" role="tab" aria-selected="false" aria-controls="trafficSubpanelactivityconnections" tabindex="-1" data-traffic-subtab="connections" data-traffic-subgroup="activity">Connexions</button>
</div>
<div id="trafficSubpanelactivityclients" role="tabpanel" aria-labelledby="trafficSubactivityclients" data-traffic-subpanel="clients" data-traffic-subgroup="activity"><button type="button" class="btn btn-outline-info mb-2" data-traffic-list-open="trafficSubpanelactivityclients">Ouvrir la liste : Clients</button>                <div class="table-responsive traffic-listener-table-wrap mb-4">
                    <table class="table table-sm table-hover align-middle mb-0">
                        <thead>
                            <tr>
                                <th>Diagnostic</th>
                                <th>Utilisateur / IP Binding</th>
                                <th>IP locale</th>
                                <th>Conntrack conservé</th>
                                <th>Débit confirmé</th>
                                <th>Principales destinations DNS</th>
                                <th>Volume mémorisé</th>
                            </tr>
                        </thead>
                        <tbody id="trafficListenerUsersBody">
                            <tr><td colspan="7" class="text-center text-white-50 py-4">Démarrez l'observation pour charger un instantané.</td></tr>
                        </tbody>
                    </table>
                </div>

</div>
<div id="trafficSubpanelactivitydomains" role="tabpanel" aria-labelledby="trafficSubactivitydomains" data-traffic-subpanel="domains" data-traffic-subgroup="activity" hidden><button type="button" class="btn btn-outline-info mb-2" data-traffic-list-open="trafficSubpanelactivitydomains">Ouvrir la liste : Domaines</button>                <h3 class="h6 mb-1 traffic-section-title">Domaines les plus corrélés par famille probable</h3>
                <div class="traffic-section-help mb-2">Classement des destinations sortantes corrélées au cache DNS. Windows, Apple et Android sont des signaux de service, pas une identification certaine du terminal. « Sans débit » signifie que conntrack conserve le flux au moment de la collecte.</div>
                <div class="table-responsive traffic-listener-table-wrap mb-4">
                    <table class="table table-sm table-hover align-middle mb-0">
                        <thead><tr><th>Domaine corrélé</th><th>Famille probable</th><th>Appareils</th><th>Connexions</th><th>Sans débit</th><th>Préparer une règle</th></tr></thead>
                        <tbody id="trafficListenerDomainsBody"><tr><td colspan="6" class="text-center text-white-50 py-4">Aucun instantané chargé.</td></tr></tbody>
                    </table>
                </div>

</div>
<div id="trafficSubpanelactivitysignatures" role="tabpanel" aria-labelledby="trafficSubactivitysignatures" data-traffic-subpanel="signatures" data-traffic-subgroup="activity" hidden><button type="button" class="btn btn-outline-info mb-2" data-traffic-list-open="trafficSubpanelactivitysignatures">Ouvrir la liste : Signatures DNS</button>                <h3 class="h6 mb-1 traffic-section-title"><i class="fa fa-fingerprint traffic-title-icon" aria-hidden="true"></i>Signatures DNS communes</h3>
                <div class="traffic-section-help mb-2">Analyse par familles observées sur plusieurs clients : présence connexion, mises à jour, Google Play, contenus et services actifs. Les mises à jour bloquées ne doivent plus ressortir vers WAN.</div>
                <div class="traffic-dns-signature-grid mb-3">
                    <div class="traffic-signature-kpi" id="trafficDnsUpdateStatus"><span>Blocage mises à jour</span><strong>--</strong><small>Windows · Apple · Android</small></div>
                    <div class="traffic-signature-kpi" id="trafficDnsUpdateLeaks"><span>Fuites mise à jour</span><strong>--</strong><small>Domaines encore observés</small></div>
                    <div class="traffic-signature-kpi" id="trafficDnsLocalReply"><span>Réponse locale possible</span><strong>--</strong><small>Tests présence connexion</small></div>
                    <div class="traffic-signature-kpi" id="trafficDnsStrictActive"><span>Strict actif</span><strong>--</strong><small>À bloquer/répondre localement</small></div>
                </div>
                <div class="table-responsive traffic-listener-table-wrap mb-4">
                    <table class="table table-sm table-hover align-middle mb-0">
                        <thead><tr><th>Famille</th><th>Décision</th><th>Domaines clés</th><th>Appareils</th><th>Connexions</th><th>Débit confirmé</th></tr></thead>
                        <tbody id="trafficDnsSignatureBody"><tr><td colspan="6" class="text-center text-white-50 py-4">Aucun instantané chargé.</td></tr></tbody>
                    </table>
                </div>

</div>
<div id="trafficSubpanelactivityconnections" role="tabpanel" aria-labelledby="trafficSubactivityconnections" data-traffic-subpanel="connections" data-traffic-subgroup="activity" hidden><button type="button" class="btn btn-outline-info mb-2" data-traffic-list-open="trafficSubpanelactivityconnections">Ouvrir la liste : Connexions</button>                <h3 class="h6 mb-1 traffic-section-title">Principales corrélations de flux</h3>
                <div class="traffic-section-help mb-2">Le domaine provient du cache DNS RouterOS ; l’adresse IP reste affichée comme preuve technique.</div>
                <div class="table-responsive traffic-listener-table-wrap">
                    <table class="table table-sm table-hover align-middle mb-0">
                        <thead>
                            <tr>
                                <th>Signal</th>
                                <th>Sens</th>
                                <th>Compte Hotspot / IP locale</th>
                                <th>Destination DNS / IP</th>
                                <th>Service</th>
                                <th>Conntrack</th>
                                <th>Débit confirmé</th>
                                <th>Volume mémorisé</th>
                            </tr>
                        </thead>
                        <tbody id="trafficListenerFlowsBody">
                            <tr><td colspan="8" class="text-center text-white-50 py-4">Aucun instantané chargé.</td></tr>
                        </tbody>
                    </table>
                </div>
</div>                <div class="traffic-last-update mt-2" id="trafficListenerLastUpdate">Dernière observation : --</div>
            </div>
        </section>
</div>

<div class="traffic-control-panel" id="trafficPanelUsage" role="tabpanel" aria-labelledby="trafficTabUsage" tabindex="-1" data-traffic-panel="usage" hidden>
<section class="card shadow-sm mb-3 traffic-usage-daily" aria-label="Consommation utilisateurs" tabindex="-1">
<div class="card-body">
<div class="traffic-usage-top">
<div class="traffic-usage-chart-card traffic-progress-frame" id="trafficUsageChartCard">
<div class="traffic-usage-card-head"><strong><i class="fa fa-wave-square traffic-title-icon" aria-hidden="true"></i><span id="trafficUsageChartTitle">Total par heure</span></strong><div class="traffic-usage-chart-controls"><div class="traffic-usage-chart-toggle" role="group" aria-label="Période de la courbe"><button type="button" class="active" aria-pressed="true" data-usage-chart-mode="day">Jour</button><button type="button" aria-pressed="false" data-usage-chart-mode="month">Mois</button></div><label class="traffic-usage-cycle-control" data-usage-date-control for="trafficUsageDate"><span>Date</span><input class="form-control form-control-sm" type="date" id="trafficUsageDate" aria-label="Date de consommation"></label><label class="traffic-usage-cycle-control d-none" data-usage-cycle-control for="trafficUsageCycleStart"><span>Cycle</span><select id="trafficUsageCycleStart" class="form-select form-select-sm"><option value="1" selected>Jour 1</option><option value="5">Jour 5</option><option value="10">Jour 10</option><option value="15">Jour 15</option><option value="20">Jour 20</option><option value="25">Jour 25</option></select></label></div></div>
<div class="traffic-usage-chart"><canvas id="trafficUsageDailyChart" role="img" aria-label="Consommation totale collectée"></canvas></div>
<p class="traffic-section-help mb-0" id="trafficUsageNote">Comptage par session/source : seuls les deltas positifs collectés sont totalisés.</p>
</div>
<div class="traffic-usage-summary" aria-label="Synthèse consommation">
<div class="traffic-progress-frame" id="trafficUsageTotalCard"><span id="trafficUsageTotalLabel">Total collecté</span><div class="traffic-usage-value"><strong id="trafficUsageTotal">--</strong><i class="fa fa-database" aria-hidden="true"></i></div><small id="trafficUsageSamples">Collectes : --</small></div>
<div><span>Hotspot</span><div class="traffic-usage-value"><strong id="trafficUsageHotspot">--</strong><i class="fa fa-wifi" aria-hidden="true"></i></div><small>Sessions actives identifiées</small></div>
<div><span>IP binding</span><div class="traffic-usage-value"><strong id="trafficUsageBinding">--</strong><i class="fa fa-network-wired" aria-hidden="true"></i></div><small>Appareils liés au routeur</small></div>
<div><span>Non associés</span><div class="traffic-usage-value"><strong id="trafficUsageObserved">--</strong><i class="fa fa-circle-question" aria-hidden="true"></i></div><small>Sans compteur fiable</small></div>
</div>
</div>
<div class="traffic-usage-layout">
<section class="traffic-usage-ranking traffic-usage-ranking-all">
<div class="traffic-usage-ranking-head"><h3><i class="fa fa-ranking-star traffic-title-icon" aria-hidden="true"></i><span id="trafficUsageRankingTitle">Classement utilisateurs · jour</span></h3><input type="search" class="form-control form-control-sm traffic-usage-filter" id="trafficUsageFilter" placeholder="Filtrer utilisateur, IP, MAC, type…" aria-label="Filtrer le classement consommation"></div>
<div class="table-responsive traffic-listener-table-wrap"><table class="table table-sm align-middle mb-0"><thead><tr><th>#</th><th><button type="button" data-usage-sort="source_type">Type</button></th><th><button type="button" data-usage-sort="label">Utilisateur / appareil</button></th><th><button type="button" data-usage-sort="local_ip">IP</button></th><th><button type="button" data-usage-sort="bytes" id="trafficUsageTablePeriodHead">Conso jour</button></th><th><button type="button" data-usage-sort="connections">Connexions</button></th></tr></thead><tbody id="trafficUsageBody"><tr><td colspan="6" class="text-center text-white-50 py-3">Collecte en attente.</td></tr></tbody></table></div>
</section>
</div>
</div>
</section>
</div>

<div class="traffic-control-panel" id="trafficPanelSecurity" role="tabpanel" aria-labelledby="trafficTabSecurity" tabindex="-1" data-traffic-panel="security" hidden>
<div class="traffic-section-toolbar">
<div><span class="badge text-bg-secondary" id="networkSecurityStatus" role="status">Historique en attente</span>
<p id="networkSecurityUpdatedAt" class="traffic-section-help">Dernière mesure : —</p>
</div>
<label for="networkSecurityRange">Période de l’historique<select class="form-select" id="networkSecurityRange">
<option value="6">6 heures</option>
<option value="24" selected>24 heures</option>
<option value="48">48 heures</option>
</select>
</label><span id="networkSecurityPeriod" class="traffic-section-help">Les compteurs cumulés ci-dessous ne dépendent pas de cette période.</span>
</div>
<section class="card mb-4">
<div class="card-header standard-card-header">
<h3 class="h6 mb-0">Mécanismes réseau configurés</h3>
</div>
<div class="card-body">
<p class="traffic-section-help">Redirection DNS et restrictions de ports : les changements s’appliquent au routeur sélectionné.</p>
<div class="table-responsive"><table class="table traffic-policy-table"><thead><tr><th>Mécanisme</th><th>Portée</th><th>État réel</th><th>Action</th></tr></thead><tbody id="trafficNetworkPolicyBody"><tr><td colspan="4">Lecture en attente.</td></tr></tbody></table>
</div>
</div>
</section>
<section class="card mb-4">
<div class="card-body">                    <div class="d-flex align-items-center justify-content-between gap-2 flex-wrap mb-2">
                        <div>
                            <h3 class="h6 mb-1 traffic-section-title">Sécurité DNS et activité WAN</h3>
                            <div class="traffic-section-help">Historique passif de 48 h. Les observations ne déclenchent aucun blocage automatique.</div>
                        </div>
                        <span class="badge text-bg-secondary" id="networkSecurityCollectionLoad">Charge : --</span>
                    </div>
                    <div class="traffic-listener-metrics network-quality-metrics mb-3">
                        <div><span>DoT observé</span><strong id="networkSecurityDot">--</strong>
</div>
                        <div><span>DoQ probable</span><strong id="networkSecurityDoq">--</strong>
</div>
                        <div><span>DoH suspecté</span><strong id="networkSecurityDoh">--</strong>
</div>
                        <div><span>DNS redirigé UDP / TCP</span><strong id="networkSecurityForcedDns">--</strong>
</div>
                        <div><span>Rejets UDP Starlink‑Shield</span><strong id="networkSecurityStarlinkUdp">--</strong>
</div>
                        <div><span>Rejets WAN observés</span><strong id="networkSecurityWanDrops">--</strong>
</div>
                    </div>
                    <div class="alert alert-info small" id="networkSecurityInterpretation">DoT est identifié par TCP/853. DoQ reste un signal probable. DoH n’est signalé que vers une destination de résolveur connue ; TCP/443 seul n’est jamais classé comme DoH.</div>
                    <button type="button" class="btn btn-outline-info mb-2" data-traffic-list-open="networkSecurityClients">Ouvrir la liste : clients sécurité</button>
                    <div class="table-responsive traffic-listener-table-wrap mb-4">
                        <table class="table table-sm table-hover align-middle mb-0">
                            <thead><tr><th>Dernière observation</th><th>Client</th><th>Signal</th><th>Observations</th><th>Connexions max.</th><th>Volume max. instantané</th></tr></thead>
                            <tbody id="networkSecurityClients"><tr><td colspan="6" class="text-center text-white-50 py-4">Historique en préparation.</td></tr></tbody>
                        </table>
                    </div>
                    <h3 class="h6 mb-1 traffic-section-title">Preuves des rejets WAN</h3>
                    <div class="traffic-section-help mb-2">Chronologie par règle, protocole et port. L’adresse source exige une journalisation pare-feu supplémentaire et n’est pas inventée ici.</div>
                    <button type="button" class="btn btn-outline-info mb-2" data-traffic-list-open="networkSecurityWanEvidence">Ouvrir la liste : preuves WAN</button>
                    <div class="table-responsive traffic-listener-table-wrap mb-4">
                        <table class="table table-sm table-hover align-middle mb-0">
                            <thead><tr><th>Date</th><th>Règle</th><th>Chaîne</th><th>Protocole / port</th><th>Paquets rejetés</th><th>Volume</th></tr></thead>
                            <tbody id="networkSecurityWanEvidence"><tr><td colspan="6" class="text-center text-white-50 py-4">Aucune preuve WAN chargée.</td></tr></tbody>
                        </table>
                    </div>

</div>
</section>
<section class="card mb-4">
<div class="card-body">
<section class="traffic-analytics mb-4" aria-labelledby="trafficAnalyticsTitle">
                    <div class="d-flex align-items-center justify-content-between gap-2 flex-wrap mb-2">
                        <div>
                            <h3 class="h6 mb-1 traffic-section-title" id="trafficAnalyticsTitle">Compteurs du pare-feu et des flux</h3>
                            <div class="traffic-section-help">Compteurs RouterOS cumulés pour le pare-feu, et instantané conntrack pour les consommateurs.</div>
                        </div>
                        <span class="badge text-bg-secondary" id="trafficAnalyticsRules">--</span>
                    </div>
                    <div class="traffic-listener-metrics network-quality-metrics mb-3">
                        <div><span>Total compté</span><strong id="trafficAnalyticsTotal">--</strong>
</div>
                        <div><span>Bloqué (drop/reject)</span><strong id="trafficAnalyticsBlocked">--</strong>
</div>
                        <div><span>Sources entrantes suspectes</span><strong id="trafficAnalyticsSuspicious">--</strong>
</div>
                        <div><span>IP publiques plausibles</span><strong id="trafficAnalyticsPublicIps">--</strong>
</div>
                    </div>
                    <div class="row g-3">
                        <div class="col-lg-4">
<h4 class="h6 traffic-section-title">Par action</h4>
<div class="table-responsive traffic-listener-table-wrap"><table class="table table-sm align-middle mb-0"><thead><tr><th>Action</th><th>Paquets</th><th>Volume</th></tr></thead><tbody id="trafficAnalyticsActions"><tr><td colspan="3">--</td></tr></tbody></table>
</div>
</div>
                        <div class="col-lg-4">
<h4 class="h6 traffic-section-title">Par protocole</h4>
<div class="table-responsive traffic-listener-table-wrap"><table class="table table-sm align-middle mb-0"><thead><tr><th>Type</th><th>Paquets</th><th>Volume</th></tr></thead><tbody id="trafficAnalyticsProtocols"><tr><td colspan="3">--</td></tr></tbody></table>
</div>
</div>
                        <div class="col-lg-4">
<h4 class="h6 traffic-section-title">Classes IP observées</h4>
<div class="table-responsive traffic-listener-table-wrap"><table class="table table-sm align-middle mb-0"><thead><tr><th>Classe</th><th>Flux</th></tr></thead><tbody id="trafficAnalyticsIpClasses"><tr><td colspan="2">--</td></tr></tbody></table>
</div>
</div>
                    </div>
                    <div class="row g-3 mt-1">
                        <div class="col-lg-6">
<h4 class="h6 traffic-section-title">Règles les plus comptées</h4>
<div class="table-responsive traffic-listener-table-wrap"><table class="table table-sm align-middle mb-0"><thead><tr><th>Chaîne / action</th><th>Type</th><th>Paquets</th><th>Volume</th></tr></thead><tbody id="trafficAnalyticsRulesBody"><tr><td colspan="4">--</td></tr></tbody></table>
</div>
</div>
                        <div class="col-lg-6">
<h4 class="h6 traffic-section-title">Clients par volume observé</h4>
<div class="table-responsive traffic-listener-table-wrap"><table class="table table-sm align-middle mb-0"><thead><tr><th>IP</th><th>Classe</th><th>Connexions</th><th>Volume</th></tr></thead><tbody id="trafficAnalyticsUsers"><tr><td colspan="4">--</td></tr></tbody></table>
</div>
</div>
                    </div>
                    <div class="alert alert-warning small mt-3 mb-0" id="trafficAnalyticsNote">Les compteurs de règles peuvent compter plusieurs fois un même flux. Ils ne constituent pas un volume réseau unique ; le classement des consommateurs vient de l’instantané conntrack.</div>
                </section>
</div>
</section>
</div>

<div class="traffic-control-panel" id="trafficPanelPerformance" role="tabpanel" aria-labelledby="trafficTabPerformance" tabindex="-1" data-traffic-panel="performance" hidden>
<section class="card shadow-sm mb-4 network-quality" aria-labelledby="networkQualityTitle">
                <div class="card-header standard-card-header d-flex align-items-center justify-content-between gap-3 flex-wrap">
                    <div>
                        <div class="d-flex align-items-center gap-2">
                            <i class="fa fa-heart-pulse"></i>
                            <span id="networkQualityTitle">Diagnostic de stabilité Starlink et réseau local</span>
                            <span class="badge text-bg-secondary" id="networkQualityState">En attente</span>
                        </div>
                        <small class="traffic-listener-subtitle">Historique glissant de 48 h · observations indépendantes des limites configurées</small>
                    </div>
                    <div class="d-flex align-items-center gap-2">
                        <select class="form-select form-select-sm network-quality-range" id="networkQualityRange" aria-label="Période du diagnostic">
                            <option value="6">6 heures</option>
                            <option value="24" selected>24 heures</option>
                            <option value="48">48 heures</option>
                        </select>
                        <button type="button" class="btn btn-outline-light btn-sm traffic-icon-button" id="networkQualityRefresh" title="Actualiser" aria-label="Actualiser le diagnostic réseau"><i class="fa fa-rotate" aria-hidden="true"></i></button>
                    </div>
                </div>
                <div class="card-body">
                    <div class="network-quality-verdict unknown mb-3" id="networkQualityVerdict">
                        <div class="network-quality-verdict-icon"><i class="fa fa-hourglass-half"></i>
</div>
                        <div><strong>Collecte en préparation</strong>
<p>Les premiers échantillons permettront de comparer Starlink, MikroTik et le bridge Hotspot actif.</p>
</div>
                    </div>
                    <div class="d-flex justify-content-end mb-3" id="networkQualityAction">
</div>

                    <div class="traffic-listener-metrics network-quality-metrics mb-3">
                        <div><span>Disponibilité HTTPS</span><strong id="networkQualityHttps">--</strong>
</div>
                        <div><span>Perte Cloudflare / Google</span><strong id="networkQualityLoss">--</strong>
</div>
                        <div><span>Débits WAN maximaux sur la période · descendant / montant</span><strong id="networkQualityWanRate">--</strong>
</div>
                        <div><span>CPU / Conntrack maximum</span><strong id="networkQualityRouterLoad">--</strong>
</div>
                        <div><span>Déconnexions Hotspot</span><strong id="networkQualityKeepalive">--</strong>
</div>
                        <div><span>Réattributions DHCP / drops files</span><strong id="networkQualityLanSignals">--</strong>
</div>
                    </div>

                    <div class="network-quality-guidance mb-4" id="networkQualityGuidance">
                        La collecte automatique doit accumuler plusieurs mesures avant de conclure.
                    </div>

<div class="traffic-history-chart mb-4"><canvas id="networkQualityChart" role="img" aria-label="Historique des débits WAN observés"></canvas>
</div>
<p id="networkQualityChartNote" class="traffic-section-help">Les dernières mesures disponibles seront affichées ici.</p>
<div class="d-flex align-items-center justify-content-between gap-2 flex-wrap mb-2">
                        <div>
                            <h3 class="h6 mb-1 traffic-section-title">Chronologie des incidents corrélés</h3>
                            <div class="traffic-section-help">Chaque ligne indique le symptôme, la cause probable et les preuves observées au même instant.</div>
                        </div>
                        <span class="badge text-bg-secondary" id="networkQualitySampleCount">0 mesure</span>
                    </div>
                    <div class="table-responsive traffic-listener-table-wrap network-quality-table-wrap">
                        <table class="table table-sm table-hover align-middle mb-0">
                            <thead><tr><th>Date</th><th>Cause probable</th><th>Starlink</th><th>Réseau local</th><th>Charge</th><th>Action de dépannage</th></tr></thead>
                            <tbody id="networkQualityIncidents"><tr><td colspan="6" class="text-center text-white-50 py-4">Aucune mesure chargée.</td></tr></tbody>
                        </table>
                    </div>
                    <div class="traffic-last-update mt-2" id="networkQualityLastUpdate">Dernière collecte : --</div>
                </div>
            </section>
</div>

<div class="traffic-control-panel" id="trafficPanelSettings" role="tabpanel" aria-labelledby="trafficTabSettings" tabindex="-1" data-traffic-panel="settings" hidden>
<section class="card mb-3 traffic-settings-card traffic-settings-card-observed">
<div class="card-header standard-card-header">
<h3 class="h6 mb-0"><i class="fa fa-sliders traffic-title-icon" aria-hidden="true"></i>Résolveur DNS observé</h3><span id="trafficDnsTransportBadge" class="badge text-bg-secondary">En attente</span>
</div>
<div class="card-body">                    <div class="traffic-dns-status-grid">
                        <div data-setting-icon="&#xf233;"><span>Serveurs DNS classiques</span><strong id="trafficDnsServers">--</strong>
</div>
                        <div class="traffic-settings-doh-card" data-setting-icon="&#xf0c2;"><span>Amont sécurisé</span><strong id="trafficDnsDohServer">--</strong><small id="trafficDnsCertificate">Certificat : --</small>
</div>
                        <div data-setting-icon="&#xf1c0;"><span>Cache DNS</span><strong id="trafficDnsCache">--</strong><small id="trafficDnsCacheUsage">Utilisation : --</small>
</div>
                        <div data-setting-icon="&#xf0c0;"><span>Service clients</span><strong id="trafficDnsRemoteRequests">--</strong><small id="trafficDnsConcurrency">Requêtes simultanées : --</small>
</div>
                    </div>
<p class="traffic-section-help">Le chiffrement DoH concerne uniquement l’amont configuré. Il ne démontre pas la résolution effective de tous les clients.</p>
<button type="button" class="btn btn-outline-warning btn-sm" id="trafficDnsFlushCache" disabled><i class="fa fa-broom me-1" aria-hidden="true"></i>Vider le cache DNS</button>
</div>
</section>
<section class="card shadow-sm mb-3 traffic-settings-card traffic-settings-card-dns">
                <div class="card-header standard-card-header"><i class="fa fa-sliders traffic-title-icon" aria-hidden="true"></i>Configuration DNS avancée</div>
                <div class="card-body">
                    <div class="traffic-dns-advanced-grid" id="trafficDnsAdvanced">
                        <div data-setting-icon="&#xf021;"><span>DNS dynamiques</span><strong data-dns-field="dynamic_servers">--</strong>
</div>
                        <div data-setting-icon="&#xf017;"><span>TTL maximal du cache</span><strong data-dns-field="cache_max_ttl">--</strong>
</div>
                        <div data-setting-icon="&#xf0e7;"><span>Requêtes simultanées</span><strong data-dns-field="max_concurrent_queries">--</strong>
</div>
                        <div data-setting-icon="&#xf1e6;"><span>Sessions TCP simultanées</span><strong data-dns-field="max_concurrent_tcp_sessions">--</strong>
</div>
                        <div data-setting-icon="&#xf252;"><span>Délai par serveur</span><strong data-dns-field="query_server_timeout">--</strong>
</div>
                        <div data-setting-icon="&#xf2f2;"><span>Délai total</span><strong data-dns-field="query_total_timeout">--</strong>
</div>
                    </div>
                    <form id="trafficDnsSettingsForm" class="traffic-settings-grid mt-3"><label><span>Serveurs DNS (IP séparées par des virgules)</span><input class="form-control" type="text" name="servers" disabled></label><label><span>URL DoH (vide pour désactiver)</span><input class="form-control" type="url" name="use-doh-server" disabled></label><label><span>Taille du cache (KiB)</span><input class="form-control" type="text" name="cache-size" disabled required></label><label><span>TTL maximal (ex. 1d)</span><input class="form-control" type="text" name="cache-max-ttl" disabled required></label><label><span>Requêtes simultanées</span><input class="form-control" type="number" name="max-concurrent-queries" disabled required></label><label><span>Sessions TCP simultanées</span><input class="form-control" type="number" name="max-concurrent-tcp-sessions" disabled required></label><label><span>Délai par serveur (ex. 2s)</span><input class="form-control" type="text" name="query-server-timeout" disabled required></label><label><span>Délai total (ex. 10s)</span><input class="form-control" type="text" name="query-total-timeout" disabled required></label><label><span>Service DNS pour les clients</span><select class="form-select" name="allow-remote-requests" disabled><option value="yes">Activé</option><option value="no">Désactivé</option></select></label><label><span>Vérifier le certificat DoH</span><select class="form-select" name="verify-doh-cert" disabled><option value="yes">Activé</option><option value="no">Désactivé</option></select></label><button type="submit" class="btn btn-outline-warning btn-sm" disabled><i class="fa fa-save me-1" aria-hidden="true"></i>Enregistrer DNS</button></form>
                </div>
            </section>
<section class="card shadow-sm mb-3 traffic-settings-card traffic-settings-card-tracking">
                <div class="card-header standard-card-header"><i class="fa fa-sliders traffic-title-icon" aria-hidden="true"></i>Paramètres réseau avancés</div>
                <div class="card-body">
                    <div class="alert alert-warning small traffic-settings-alert"><i class="fa fa-triangle-exclamation me-1" aria-hidden="true"></i>Une valeur plus courte retire plus vite les connexions silencieuses. Point de départ prudent : TCP établi 6 h, sans réduire les délais UDP actuels.</div>
                    <form id="trafficTrackingForm" class="traffic-settings-grid">
                        <label><span>TCP établi</span>
<select class="form-select" name="tcp-established-timeout">
<option value="30m">30 minutes</option>
<option value="1h">1 heure</option>
<option value="3h">3 heures</option>
<option value="6h">6 heures</option>
<option value="12h">12 heures</option>
<option value="1d">1 jour</option>
</select><small>Actuel : <strong data-current-setting="tcp-established-timeout">--</strong></small>
</label>
                        <label><span>UDP simple</span>
<select class="form-select" name="udp-timeout">
<option value="10s">10 secondes</option>
<option value="15s">15 secondes</option>
<option value="30s">30 secondes</option>
<option value="1m">1 minute</option>
</select><small>Actuel : <strong data-current-setting="udp-timeout">--</strong></small>
</label>
                        <label><span>UDP bidirectionnel</span>
<select class="form-select" name="udp-stream-timeout">
<option value="30s">30 secondes</option>
<option value="1m">1 minute</option>
<option value="3m">3 minutes</option>
<option value="5m">5 minutes</option>
</select><small>Actuel : <strong data-current-setting="udp-stream-timeout">--</strong></small>
</label>
                        <label><span>Protocoles génériques</span>
<select class="form-select" name="generic-timeout">
<option value="1m">1 minute</option>
<option value="5m">5 minutes</option>
<option value="10m">10 minutes</option>
<option value="30m">30 minutes</option>
</select><small>Actuel : <strong data-current-setting="generic-timeout">--</strong></small>
</label>
                    </form>
                    <div class="d-flex align-items-center justify-content-between gap-3 flex-wrap mt-3">
                        <div class="text-white-50 small" id="trafficTrackingCapacity">Capacité conntrack : --</div>
                        <div class="d-flex gap-2 flex-wrap">
                            <button type="button" class="btn btn-outline-light btn-sm" id="trafficTrackingPreset" data-tcp-established-timeout="6h" data-udp-timeout="30s" data-udp-stream-timeout="3m" data-generic-timeout="10m"><i class="fa fa-wand-magic-sparkles me-1" aria-hidden="true"></i>Préselection prudente</button>
                            <button type="button" class="btn btn-outline-warning btn-sm" id="trafficTrackingSave"><i class="fa fa-save me-1" aria-hidden="true"></i>Appliquer</button>
                        </div>
                    </div>
                </div>
            </section>
</div>
<dialog id="trafficDetailDialog" class="traffic-detail-dialog" aria-labelledby="trafficDetailTitle">
<div class="traffic-detail-header">
<h3 id="trafficDetailTitle">Détail de l’observation</h3>
<button type="button" class="btn btn-outline-light" data-traffic-detail-close aria-label="Fermer le détail">Fermer</button>
</div>
<div id="trafficDetailContent">
</div>
</dialog>
<dialog id="trafficListDialog" class="traffic-detail-dialog traffic-list-dialog" aria-labelledby="trafficListTitle">
<div class="traffic-detail-header">
<h3 id="trafficListTitle">Liste complète</h3>
<button type="button" class="btn btn-outline-light" data-traffic-list-close aria-label="Fermer la liste">Fermer</button>
</div>
<div id="trafficListContent">
</div>
</dialog>
<?php else: ?>
<div class="alert alert-warning">Sélectionnez un routeur MikroTik dans les équipements pour consulter et gérer cet espace.</div>
<?php endif; ?>
</div>
</div>
</div>
</div>
<?php
$extraJs = [
    '../assets/vendor/chart/chart.umd.min.js?v=20260905',
    '../js/traffic_control.js?v=20260924timefix1',
    '../js/traffic_listener.js?v=20261011a',
    '../js/network_quality_monitor.js?v=20260909ux3',
];
require_once '../includes/layout_footer.php';
?>
