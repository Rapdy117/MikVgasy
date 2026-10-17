document.addEventListener('DOMContentLoaded', () => {
    const deviceId = document.getElementById('trafficControlDeviceId')?.value || '';
    const csrfToken = document.getElementById('trafficControlCsrf')?.value || '';
    const tabs = Array.from(document.querySelectorAll('[data-traffic-tab]'));
    const panels = Array.from(document.querySelectorAll('[data-traffic-panel]'));
    const endpoint = '../api/mikrotik_traffic_control.php';
    const tabStorageKey = `traffic-control-tab:${deviceId}`;
    let currentState = null;
    let loading = false;
    let domainCheckLoading = false;
    let stateReady = false;
    let activePanel = '';
    const workspace = document.querySelector('.traffic-workspace');
    const sectionSelect = document.getElementById('trafficSectionSelect');
    const subviews = new Map();
    const overviewAlertSources = new Map();
    const setText = (id, value) => {
        const element = document.getElementById(id);
        if (element) element.textContent = value;
    };

    const escapeHtml = (value) => String(value ?? '')
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#39;');

    const formatNumber = (value) => value === null || value === undefined || value === '' || !Number.isFinite(Number(value)) ? '—' : new Intl.NumberFormat('fr-FR').format(Number(value));

    function notify(message, type = 'info') {
        if (window.AppToast?.flash) {
            window.AppToast.flash(message, type);
            return;
        }
        window.alert(message);
    }

    function routerBoolean(value) {
        return value === true || value === 'true' || value === 'yes';
    }

    window.TrafficOverviewAlerts = {
        update(source, items) {
            overviewAlertSources.set(source, Array.isArray(items) ? items : []);
            const container = document.getElementById('trafficOverviewAlerts');
            const rows = Array.from(overviewAlertSources.values()).flat().filter(Boolean);
            if (container) {
                const critical = rows.filter((item) => item.level === 'critical').length;
                const warning = rows.filter((item) => item.level === 'warning').length;
                container.innerHTML = rows.length
                    ? `<article class="traffic-overview-alert traffic-click-card ${escapeHtml(critical ? 'critical' : 'warning')}" style="--card-accent: ${critical ? '#ef4444' : '#f59e0b'}" role="button" tabindex="0" data-traffic-go="${escapeHtml(rows[0].panel || 'activity')}" ${rows[0].subview ? `data-traffic-subview="${escapeHtml(rows[0].subview)}"` : ''}><div class="traffic-card-copy"><span class="traffic-card-node">État opérationnel</span><span class="traffic-card-label">Activités à vérifier</span><small>${formatNumber(critical)} critique · ${formatNumber(warning)} avertissement</small></div><div class="traffic-card-metric"><span class="traffic-card-icon"><i class="fa fa-triangle-exclamation" aria-hidden="true"></i></span><strong>${formatNumber(rows.length)}</strong></div></article>`
                    : '<article class="traffic-overview-alert info" style="--card-accent: var(--accent)"><div class="traffic-card-copy"><span class="traffic-card-node">État opérationnel</span><span class="traffic-card-label">Activités à vérifier</span><small>Aucun signal exploitable</small></div><div class="traffic-card-metric"><span class="traffic-card-icon"><i class="fa fa-circle-check" aria-hidden="true"></i></span><strong>0</strong></div></article>';
            }
        },
    };

    function setControlsEnabled(enabled) {
        workspace?.querySelectorAll('#trafficTrackingForm input, #trafficTrackingForm select, #trafficTrackingSave, #trafficTrackingPreset, #trafficAdlistForm select, #trafficAdlistForm button, #trafficDnsSettingsForm input, #trafficDnsSettingsForm select, #trafficDnsSettingsForm button, #trafficDnsBlockForm input, #trafficDnsBlockForm select, #trafficDnsBlockForm button, #trafficWhitelistForm input, #trafficWhitelistForm button, #trafficDomainCheckSubmit, #trafficDnsFlushCache, #trafficAdlistReload, [data-policy-key], [data-dns-block-toggle], [data-dns-block-remove], [data-whitelist-remove]').forEach((control) => {
            control.disabled = !enabled;
        });
        if (enabled) {
            const catalog = document.getElementById('trafficAdlistUrl');
            if (catalog && !catalog.querySelector('option[value]:not([value=""]):not(:disabled)')) {
                catalog.disabled = true;
                document.querySelector('#trafficAdlistForm button[type="submit"]')?.setAttribute('disabled', '');
            }
            if (domainCheckLoading) document.getElementById('trafficDomainCheckSubmit')?.setAttribute('disabled', '');
        }
    }

    function showSubview(group, name) {
        const buttons = Array.from(document.querySelectorAll('[data-traffic-subtab]')).filter((button) => button.dataset.trafficSubgroup === group);
        if (!buttons.length) return;
        if (!buttons.some((button) => button.dataset.trafficSubtab === name)) name = buttons[0].dataset.trafficSubtab;
        subviews.set(group, name);
        buttons.forEach((button) => {
            const selected = button.dataset.trafficSubtab === name;
            button.classList.toggle('active', selected);
            button.setAttribute('aria-selected', String(selected));
            button.tabIndex = selected ? 0 : -1;
        });
        document.querySelectorAll('[data-traffic-subpanel]').forEach((panel) => {
            if (panel.dataset.trafficSubgroup === group) panel.hidden = panel.dataset.trafficSubpanel !== name;
        });
    }

    function showPanel(name, persist = true, subview = '') {
        if (!panels.some((panel) => panel.dataset.trafficPanel === name)) name = 'dashboard';
        activePanel = name;
        tabs.forEach((tab) => {
            const selected = tab.dataset.trafficTab === name;
            tab.classList.toggle('active', selected);
            tab.setAttribute('aria-selected', String(selected));
            tab.tabIndex = selected ? 0 : -1;
        });
        panels.forEach((panel) => {
            const selected = panel.dataset.trafficPanel === name;
            panel.hidden = !selected;
            panel.classList.toggle('active', selected);
        });
        if (sectionSelect) sectionSelect.value = name;
        showSubview(name, subview || subviews.get(name));
        if (persist) {
            const fragment = name + (subviews.has(name) ? '/' + subviews.get(name) : '');
            if (window.location.hash !== '#' + fragment) window.history.pushState(null, '', '#' + fragment);
            try { window.localStorage.setItem(tabStorageKey, fragment); } catch (_) { /* stockage facultatif */ }
        }
        document.dispatchEvent(new CustomEvent('traffic-control-panel-change', { detail: { panel: name } }));
    }

    async function request(payload = null) {
        const controller = new AbortController();
        const timer = window.setTimeout(() => controller.abort(), payload && payload.action !== 'check_domain' ? 60000 : 20000);
        const options = {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
            signal: controller.signal,
        };
        let url = `${endpoint}?device_id=${encodeURIComponent(deviceId)}&action=status`;
        if (payload) {
            url = endpoint;
            options.method = 'POST';
            options.headers['Content-Type'] = 'application/json';
            options.body = JSON.stringify({ ...payload, device_id: deviceId, csrf_token: csrfToken, confirm: true });
        }
        try {
            const response = await fetch(url, options);
            const data = await response.json().catch(() => { throw new Error('Réponse invalide du routeur.'); });
            if (!response.ok || !data.success) throw new Error(data.message || `Erreur HTTP ${response.status}`);
            return data;
        } catch (error) {
            if (error.name === 'AbortError') throw new Error(payload && payload.action !== 'check_domain'
                ? 'Délai dépassé : le résultat de la commande est inconnu. Relisez le routeur avant de réessayer.'
                : 'La lecture a dépassé 20 secondes. Réessayez.');
            throw error;
        } finally {
            window.clearTimeout(timer);
        }
    }

    function renderTracking(tracking = {}) {
        const mapping = {
            'tcp-established-timeout': 'tcp_established_timeout',
            'udp-timeout': 'udp_timeout',
            'udp-stream-timeout': 'udp_stream_timeout',
            'generic-timeout': 'generic_timeout',
        };
        Object.entries(mapping).forEach(([field, key]) => {
            const value = String(tracking[key] || '');
            const select = document.querySelector(`#trafficTrackingForm [name="${field}"]`);
            const current = document.querySelector(`[data-current-setting="${field}"]`);
            if (select && value && Array.from(select.options).some((option) => option.value === value)) {
                select.value = value;
            }
            if (current) current.textContent = value || 'indisponible';
        });
        const capacity = document.getElementById('trafficTrackingCapacity');
        if (capacity) {
            capacity.textContent = `Conntrack actuel : ${formatNumber(tracking.total_entries)} / capacité ${formatNumber(tracking.max_entries)} · IPv4 ${tracking.active_ipv4 ? 'actif' : 'inactif'} · IPv6 ${tracking.active_ipv6 ? 'actif' : 'inactif'}`;
        }
    }

    function renderDns(dns = {}) {
        const servers = String(dns.servers || '').trim();
        const dohServer = String(dns.use_doh_server || '').trim();
        const remoteRequests = routerBoolean(dns.allow_remote_requests);
        const verifyCertificate = routerBoolean(dns.verify_doh_cert);
        const cacheSize = String(dns.cache_size || '').trim();
        const cacheUsed = String(dns.cache_used || '').trim();
        const concurrency = String(dns.max_concurrent_queries || '').trim();
        const setText = (id, value) => {
            const element = document.getElementById(id);
            if (element) element.textContent = value;
        };

        setText('trafficDnsServers', servers || 'Non configurés');
        setText('trafficDnsDohServer', dohServer ? 'DoH configuré' : 'Désactivé');
        setText('trafficDnsCertificate', dohServer
            ? `Vérification TLS : ${verifyCertificate ? 'activée' : 'désactivée'}`
            : 'Certificat TLS : sans objet');
        setText('trafficDnsCache', cacheSize || 'Indisponible');
        setText('trafficDnsCacheUsage', cacheUsed
            ? `Utilisation : ${cacheUsed}`
            : 'Utilisation : indisponible');
        setText('trafficDnsRemoteRequests', remoteRequests ? 'Autorisé' : 'Désactivé');
        setText('trafficDnsConcurrency', `Requêtes simultanées : ${concurrency || 'indisponible'}`);

        const badge = document.getElementById('trafficDnsTransportBadge');
        if (badge) {
            const secure = dohServer !== '' && verifyCertificate;
            badge.textContent = dohServer
                ? (secure ? 'DoH configuré · vérification TLS activée' : 'DoH configuré · vérification TLS désactivée')
                : 'DNS classique vers l’amont';
            badge.className = `badge text-bg-${secure ? 'success' : (dohServer ? 'warning' : 'secondary')}`;
        }
        const flush = document.getElementById('trafficDnsFlushCache');
        if (flush) flush.disabled = false;

        document.querySelectorAll('[data-dns-field]').forEach((element) => {
            const value = dns[element.dataset.dnsField];
            element.textContent = value === null || value === undefined || String(value).trim() === ''
                ? 'Non fourni par RouterOS'
                : String(value);
        });

        const form = document.getElementById('trafficDnsSettingsForm');
        if (form && form.dataset.userEditing !== 'true') {
            const values = {
                servers,
                'use-doh-server': dohServer,
                'cache-size': String(cacheSize || '').replace(/KiB$/i, ''),
                'cache-max-ttl': String(dns.cache_max_ttl || ''),
                'max-concurrent-queries': dns.max_concurrent_queries ?? '',
                'max-concurrent-tcp-sessions': dns.max_concurrent_tcp_sessions ?? '',
                'query-server-timeout': String(dns.query_server_timeout || ''),
                'query-total-timeout': String(dns.query_total_timeout || ''),
                'allow-remote-requests': remoteRequests ? 'yes' : 'no',
                'verify-doh-cert': verifyCertificate ? 'yes' : 'no',
            };
            Object.entries(values).forEach(([name, value]) => {
                const control = form.elements[name];
                if (control) control.value = value;
            });
            form.dataset.initialSettings = JSON.stringify(values);
        }
    }

    function renderOverview(state) {
        const dns = state.dns;
        const adlists = state.adlists.filter((row) => !routerBoolean(row.disabled));
        const allAdlists = Array.isArray(state.adlists) ? state.adlists : [];
        const matchCount = allAdlists.reduce((total, row) => total + (Number(row['match-count']) || 0), 0);
        const domainCount = allAdlists.reduce((total, row) => total + (Number(row['name-count']) || 0), 0);
        const hasDoh = String(dns.use_doh_server || '').trim() !== '';
        const certificateCheck = routerBoolean(dns.verify_doh_cert);
        const checks = document.getElementById('trafficOverviewChecks');
        if (checks) {
            const forceDns = state.policies.force_dns;
            const dnsReady = forceDns && forceDns.enabled;
            const items = [
                { value: dnsReady ? 'OK' : 'À voir', label: 'DNS forcé', detail: forceDns ? `${formatNumber(forceDns.configured_items)} / ${formatNumber(forceDns.expected_items)} règles` : 'non fourni', node: 'FIREWALL_DNS', icon: 'fa-shield-halved', accent: dnsReady ? '#22c55e' : '#f59e0b', panel: 'security' },
                { value: `${formatNumber(adlists.length)} / ${formatNumber(allAdlists.length)}`, label: 'Listes', detail: `${formatNumber(domainCount)} domaines · ${formatNumber(matchCount)} matchs`, node: 'ADLIST_CORE', icon: 'fa-list-check', accent: '#17a2b8', panel: 'filtering' },
                { value: hasDoh ? (certificateCheck ? 'DoH OK' : 'DoH') : 'DNS', label: 'Amont', detail: hasDoh ? (certificateCheck ? 'certificat vérifié' : 'certificat non vérifié') : 'DNS classique', node: 'UPSTREAM_NODE', icon: 'fa-cloud-arrow-up', accent: hasDoh && certificateCheck ? '#22c55e' : '#38bdf8', panel: 'settings' },
            ];
            checks.innerHTML = `<div class="traffic-overview-check-grid">${items.map((item) => `<article class="traffic-overview-check traffic-click-card" style="--card-accent: ${item.accent}" role="button" tabindex="0" data-traffic-go="${item.panel}"><div class="traffic-card-copy"><span class="traffic-card-node">État opérationnel</span><span class="traffic-card-label">${escapeHtml(item.label)}</span><small>${escapeHtml(item.detail)}</small></div><div class="traffic-card-metric"><span class="traffic-card-icon"><i class="fa ${escapeHtml(item.icon)}" aria-hidden="true"></i></span><strong>${escapeHtml(item.value)}</strong></div></article>`).join('')}</div>`;
        }
    }

    function renderCatalog(catalog, adlists) {
        const select = document.getElementById('trafficAdlistUrl');
        if (!select) return;
        const previous = select.value;
        select.innerHTML = '<option value="">Choisir une liste approuvée</option>' + catalog.map((item) => {
            const existing = adlists.find((row) => row.url === item.url);
            const state = existing ? (routerBoolean(existing.disabled) ? ' — désactivée, sélectionner pour activer' : ' — active, sélectionner pour désactiver') : '';
            return `<option value="${escapeHtml(item.url)}">${escapeHtml(item.label)}${state}</option>`;
        }).join('');
        if (Array.from(select.options).some((option) => option.value === previous)) select.value = previous;
        updateAdlistSubmitLabel();
    }

    // Le même menu « Liste approuvée » sert à ajouter une source absente, et à activer/désactiver
    // une source déjà configurée — un seul contrôle, pas de bouton par ligne dans le tableau.
    function updateAdlistSubmitLabel() {
        const select = document.getElementById('trafficAdlistUrl');
        const submit = document.getElementById('trafficAdlistSubmit');
        if (!select || !submit) return;
        const existing = (currentState?.adlists || []).find((row) => row.url === select.value);
        if (!existing) { submit.textContent = 'Ajouter et activer'; return; }
        submit.textContent = routerBoolean(existing.disabled) ? 'Activer cette liste' : 'Désactiver cette liste';
    }

    function renderAdlists(adlists = [], dns = {}) {
        const body = document.getElementById('trafficAdlistBody');
        if (body) {
            body.innerHTML = adlists.length ? adlists.map((row) => {
                const disabled = routerBoolean(row.disabled);
                const managed = row.managed === true;
                const source = row.url || row.file || '-';
                return `<tr>
                    <td class="text-break">${escapeHtml(source)}</td>
                    <td>${formatNumber(row['name-count'])}</td>
                    <td>${formatNumber(row['match-count'])}</td>
                    <td><span class="badge text-bg-${disabled ? 'secondary' : 'success'}">${disabled ? 'Désactivée' : 'Active'}</span>${managed ? '' : '<div class="small text-white-50">Externe · lecture seule</div>'}</td>
                </tr>`;
            }).join('') : '<tr><td colspan="4" class="text-center text-white-50">Aucune Adlist configurée.</td></tr>';
        }

    }

    // Ces 4 catégories ne font que regrouper des domaines déjà listés, modifiables un par un,
    // dans « Blocages DNS ciblés » (/ip/dns/static NXDOMAIN) : même donnée, affichage en double.
    const TRAFFIC_SERVICE_POLICY_DUPLICATES = ['windows_update', 'apple_update', 'android_update', 'google_background'];

    function renderPolicies(policies) {
        const rows = Object.values(policies).filter((policy) => !TRAFFIC_SERVICE_POLICY_DUPLICATES.includes(policy.key));
        [['trafficPolicyBody', 'services'], ['trafficNetworkPolicyBody', 'security']].forEach(([id, section]) => {
            const body = document.getElementById(id);
            const entries = rows.filter((policy) => policy.section === section);
            if (!body) return;
            body.innerHTML = entries.length ? entries.map((policy) => {
                const enabled = policy.enabled === true;
                const label = enabled ? 'Activée' : (policy.partial ? 'Partielle' : (policy.configured ? 'Désactivée' : 'Non configurée'));
                return `<tr><td data-label="Mécanisme"><strong>${escapeHtml(policy.label)}</strong></td>
                    <td data-label="Portée">${escapeHtml(policy.description)}</td>
                    <td data-label="Configuration"><span class="badge text-bg-${policy.partial ? 'warning' : 'secondary'}">${label}</span> <small>${formatNumber(policy.configured_items)}/${formatNumber(policy.expected_items)} règles</small></td>
                    <td data-label="Action">${policy.mutable ? `<button type="button" class="btn btn-outline-${enabled ? 'warning' : 'light'}" data-policy-key="${escapeHtml(policy.key)}" data-policy-enable="${!enabled}">${enabled ? 'Désactiver' : 'Activer'}</button>` : 'Lecture seule'}</td></tr>`;
            }).join('') : '<tr><td colspan="4">Aucun mécanisme disponible.</td></tr>';
        });
        setText('trafficPolicyCount', `${rows.filter((row) => row.section === 'services').length} service(s) disponibles`);
    }

    function renderDnsBlocks(blocks = []) {
        const body = document.getElementById('trafficDnsBlockBody');
        if (!body) return;
        body.innerHTML = blocks.length ? blocks.map((row) => {
            const id = escapeHtml(row['.id'] || '');
            const disabled = routerBoolean(row.disabled);
            const category = String(row.comment || '').replace('Radius Manager - blocage ', '') || 'Géré';
            return `<tr>
                <td>${escapeHtml(row.name || '-')}</td>
                <td>${escapeHtml(category)}</td>
                <td>${routerBoolean(row['match-subdomain']) ? 'Oui' : 'Non'}</td>
                <td><span class="badge text-bg-${disabled ? 'secondary' : 'danger'}">${disabled ? 'Désactivé' : 'Bloqué'}</span></td>
                <td class="text-nowrap">
                    <button type="button" class="btn btn-sm btn-outline-${disabled ? 'success' : 'warning'}" data-dns-block-toggle="${id}" data-next-disabled="${disabled ? 'false' : 'true'}">${disabled ? 'Réactiver' : 'Désactiver'}</button>
                    <button type="button" class="btn btn-sm btn-outline-danger" data-dns-block-remove="${id}">Supprimer</button>
                </td>
            </tr>`;
        }).join('') : '<tr><td colspan="5" class="text-center text-white-50">Aucun blocage DNS géré.</td></tr>';
    }

    function renderWhitelist(whitelist = []) {
        const body = document.getElementById('trafficWhitelistBody');
        if (body) {
            body.innerHTML = whitelist.length ? whitelist.map((row) => `
                <tr>
                    <td>${escapeHtml(row.name || '-')}</td>
                    <td>${routerBoolean(row['match-subdomain']) ? 'Oui' : 'Non'}</td>
                    <td>${escapeHtml(row.comment || '-')} ${routerBoolean(row.disabled) ? '<span class="badge text-bg-secondary ms-1">Désactivée</span>' : '<span class="badge text-bg-success ms-1">Active</span>'}</td>
                    <td><button type="button" class="btn btn-sm btn-outline-danger" data-whitelist-remove="${escapeHtml(row['.id'] || '')}">Retirer</button></td>
                </tr>
            `).join('') : '<tr><td colspan="4" class="text-center text-white-50">Liste blanche vide.</td></tr>';
        }
    }

    // Résumé en tête de l'onglet Filtrage DNS : même lecture d'un coup d'œil que Vue d'ensemble,
    // le détail (tableaux) vient ensuite pour qui veut creuser une ligne précise.
    function renderFilterSummary(state) {
        const set = (id, text) => { const el = document.getElementById(id); if (el) el.textContent = text; };
        const adlists = state.adlists || [];
        const active = adlists.filter((row) => !routerBoolean(row.disabled));
        const totalMatches = active.reduce((sum, row) => sum + (Number(row['match-count']) || 0), 0);
        set('trafficFilterSummaryAdlistValue', formatNumber(active.length));
        set('trafficFilterSummaryAdlistLabel', active.length ? 'Liste(s) active(s)' : 'Aucune liste active');
        set('trafficFilterSummaryAdlistDetail', active.length ? `${formatNumber(totalMatches)} correspondances cumulées` : 'Aucun blocage par liste');

        const blocks = state.dns_blocks || [];
        const activeBlocks = blocks.filter((row) => !routerBoolean(row.disabled));
        set('trafficFilterSummaryBlockValue', formatNumber(activeBlocks.length));
        set('trafficFilterSummaryBlockDetail', blocks.length ? `${formatNumber(blocks.length - activeBlocks.length)} désactivé(s)` : 'Aucun blocage ciblé');

        const whitelist = state.whitelist || [];
        set('trafficFilterSummaryAllowValue', formatNumber(whitelist.length));
        set('trafficFilterSummaryAllowDetail', whitelist.length ? 'Domaines exemptés des listes' : 'Aucune exception');

        const policies = Object.values(state.policies || {}).filter((policy) => policy.section === 'services' || policy.section === 'security');
        const enabled = policies.filter((policy) => policy.enabled && !policy.partial).length;
        set('trafficFilterSummaryServicesValue', `${formatNumber(enabled)}/${formatNumber(policies.length)}`);
        set('trafficFilterSummaryServicesDetail', policies.some((policy) => policy.partial) ? 'Au moins une protection partielle' : 'Pleinement appliquées');
    }

    function renderState(state) {
        if (!state || typeof state.dns !== 'object' || !state.policies || !Array.isArray(state.adlists)
            || !Array.isArray(state.dns_blocks) || !Array.isArray(state.whitelist) || !Array.isArray(state.adlist_catalog)) {
            throw new Error('État RouterOS incomplet. Relancez la lecture.');
        }
        currentState = state;
        stateReady = true;
        renderTracking(state.tracking);
        renderDns(state.dns);
        renderOverview(state);
        renderFilterSummary(state);
        renderCatalog(state.adlist_catalog, state.adlists);
        renderAdlists(state.adlists);
        renderPolicies(state.policies);
        renderDnsBlocks(state.dns_blocks);
        renderWhitelist(state.whitelist);
        ['trafficDnsBlockSearch', 'trafficWhitelistSearch'].forEach((id) => document.getElementById(id)?.dispatchEvent(new Event('input')));
        ['trafficAdlistBody', 'trafficDnsBlockBody', 'trafficWhitelistBody', 'trafficPolicyBody', 'trafficNetworkPolicyBody'].forEach((id) => {
            const body = document.getElementById(id);
            const labels = Array.from(body?.closest('table')?.querySelectorAll('thead th') || [], (th) => th.textContent.trim());
            body?.querySelectorAll('tr').forEach((row) => Array.from(row.cells).forEach((cell, index) => {
                if (cell.colSpan === 1) cell.dataset.label = labels[index];
            }));
        });
    }

    function renderDomainCheckResult(result) {
        const container = document.getElementById('trafficDomainCheckResult');
        if (!container) return;

        const statuses = {
            blocked: { label: 'Règle de blocage trouvée', icon: 'fa-ban', className: 'blocked' },
            allowed: { label: 'Exception aux listes trouvée', icon: 'fa-circle-check', className: 'allowed' },
            unmatched: { label: 'Aucune règle gérée correspondante', icon: 'fa-circle-minus', className: 'unmatched' },
            conflict: { label: 'Conflit de règles', icon: 'fa-triangle-exclamation', className: 'conflict' },
            unknown: { label: 'Verdict Adlist non observable', icon: 'fa-circle-question', className: 'unknown' },
        };
        const status = String(result?.status || '');
        const presentation = statuses[status] || { label: 'Résultat non reconnu', icon: 'fa-circle-question', className: 'unknown' };
        const source = String(result?.source || '').trim();
        const details = result?.details;
        let detailsHtml = '';
        if (typeof details === 'string' && details.trim()) {
            detailsHtml = `<p>${escapeHtml(details)}</p>`;
        } else if (details && typeof details === 'object') {
            const entries = Object.entries(details).filter(([, value]) => value !== null && value !== '');
            const detailLabels = {
                whitelist_matches: 'Exceptions correspondantes',
                block_matches: 'Blocages ciblés correspondants',
                enabled_adlists: 'Adlists actives',
                adlist_domain_attribution: 'Attribution à une Adlist',
            };
            const detailValues = { not_observable: 'Non observable par RouterOS' };
            if (entries.length) {
                detailsHtml = `<dl>${entries.map(([key, value]) => `<div><dt>${escapeHtml(detailLabels[key] || key)}</dt><dd>${escapeHtml(detailValues[value] || (Array.isArray(value) ? value.join(', ') : value))}</dd></div>`).join('')}</dl>`;
            }
        }

        container.className = `traffic-domain-check-result ${presentation.className}`;
        container.innerHTML = `
            <div class="traffic-domain-check-verdict">
                <i class="fa ${presentation.icon}" aria-hidden="true"></i>
                <div><strong>${presentation.label}</strong><span>${escapeHtml(result?.domain || '')}</span></div>
            </div>
            ${source ? `<div class="traffic-domain-check-source"><span>Source</span><strong>${escapeHtml({ routeros: 'RouterOS', dns_static_managed: 'Règles DNS gérées', adlist_not_observable: 'Contenu des listes non observable' }[source] || source)}</strong></div>` : ''}
            ${detailsHtml}
        `;
        container.hidden = false;
    }

    function renderDomainCheckError(message) {
        const container = document.getElementById('trafficDomainCheckResult');
        if (!container) return;
        container.className = 'traffic-domain-check-result error';
        container.innerHTML = `<div class="traffic-domain-check-verdict"><i class="fa fa-circle-exclamation" aria-hidden="true"></i><div><strong>Vérification impossible</strong><span>${escapeHtml(message)}</span></div></div>`;
        container.hidden = false;
    }

    function markStateUnavailable(message) {
        stateReady = false;
        const refresh = document.getElementById('trafficControlRefresh');
        if (refresh) {
            refresh.title = 'Relire la configuration';
            refresh.setAttribute('aria-label', 'Relire la configuration');
            refresh.innerHTML = '<i class="fa fa-rotate" aria-hidden="true"></i>';
        }
        const checks = document.getElementById('trafficOverviewChecks');
        if (checks) checks.innerHTML = `<p role="status">${escapeHtml(message)}</p>`;
    }

    async function loadState() {
        if (!deviceId || loading || domainCheckLoading) return;
        loading = true;
        setControlsEnabled(false);
        const refresh = document.getElementById('trafficControlRefresh');
        if (refresh) {
            refresh.disabled = true;
            refresh.title = 'Lecture en cours';
            refresh.setAttribute('aria-label', 'Lecture de la configuration en cours');
            refresh.innerHTML = '<span class="spinner-border spinner-border-sm" aria-hidden="true"></span>';
        }
        let success = false;
        try {
            const data = await request();
            renderState(data.state);
            success = true;
        } catch (error) {
            markStateUnavailable(error.message);
        } finally {
            loading = false;
            setControlsEnabled(stateReady);
            if (refresh) {
                refresh.disabled = false;
                refresh.title = success ? 'Actualiser' : 'Réessayer';
                refresh.setAttribute('aria-label', success ? 'Actualiser la configuration' : 'Réessayer la lecture de la configuration');
                refresh.innerHTML = '<i class="fa fa-rotate" aria-hidden="true"></i>';
            }
            document.dispatchEvent(new CustomEvent('traffic-control-state-ready', { detail: { success } }));
        }
    }

    async function mutate(payload, successMessage) {
        if (loading || domainCheckLoading || !stateReady) return false;
        loading = true;
        setControlsEnabled(false);
        try {
            const data = await request(payload);
            renderState(data.state);
            notify(data.message || successMessage, 'success');
            return true;
        } catch (error) {
            markStateUnavailable('Résultat à vérifier : relisez la configuration avant une nouvelle commande.');
            notify(`${error.message} Une application partielle est possible ; utilisez Réessayer pour relire l’état.`, 'warning');
            return false;
        } finally {
            loading = false;
            setControlsEnabled(stateReady);
        }
    }

    const bindTabs = (buttons, activate) => buttons.forEach((button) => {
        button.addEventListener('click', () => activate(button));
        button.addEventListener('keydown', (event) => {
            if (!['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) return;
            const enabled = buttons.filter((candidate) => !candidate.disabled);
            if (!enabled.length) return;
            event.preventDefault();
            const index = enabled.indexOf(button);
            const next = event.key === 'Home' ? 0 : event.key === 'End' ? enabled.length - 1
                : (index + (event.key === 'ArrowLeft' ? -1 : 1) + enabled.length) % enabled.length;
            enabled[next].focus();
            activate(enabled[next]);
        });
    });
    bindTabs(tabs, (tab) => showPanel(tab.dataset.trafficTab));
    for (const group of new Set(Array.from(document.querySelectorAll('[data-traffic-subtab]')).map((button) => button.dataset.trafficSubgroup))) {
        const buttons = Array.from(document.querySelectorAll('[data-traffic-subtab]')).filter((button) => button.dataset.trafficSubgroup === group);
        bindTabs(buttons, (button) => showPanel(group, true, button.dataset.trafficSubtab));
        showSubview(group);
    }
    sectionSelect?.addEventListener('change', () => showPanel(sectionSelect.value));
    const ruleFilter = document.getElementById('trafficRuleEffectFilter');
    ruleFilter?.addEventListener('change', () => {
        document.querySelectorAll('[data-rule-kind]').forEach((section) => {
            section.hidden = ruleFilter.value !== 'all' && section.dataset.ruleKind !== ruleFilter.value;
        });
    });
    document.addEventListener('traffic-control-show-panel', (event) => {
        showPanel(String(event.detail?.panel || 'activity'), true, event.detail?.subview || '');
        const input = event.detail?.subview === 'rules' ? document.getElementById('trafficDnsBlockDomain') : null;
        if (input) {
            if (ruleFilter) { ruleFilter.value = 'block'; ruleFilter.dispatchEvent(new Event('change')); }
            input.focus();
        }
    });
    workspace?.addEventListener('click', (event) => {
        const shortcut = event.target.closest('[data-traffic-go]');
        if (!shortcut) return;
        showPanel(shortcut.dataset.trafficGo, true, shortcut.dataset.trafficSubview || '');
        panels.find((panel) => !panel.hidden)?.focus({ preventScroll: true });
    });
    workspace?.addEventListener('keydown', (event) => {
        if (!['Enter', ' '].includes(event.key)) return;
        const shortcut = event.target.closest('.traffic-click-card[data-traffic-go]');
        if (!shortcut) return;
        event.preventDefault();
        shortcut.click();
    });
    const listDialog = document.getElementById('trafficListDialog');
    const listTitle = document.getElementById('trafficListTitle');
    const listContent = document.getElementById('trafficListContent');
    workspace?.addEventListener('click', (event) => {
        const opener = event.target.closest('[data-traffic-list-open]');
        if (!opener || !listDialog || !listContent) return;
        const target = document.getElementById(opener.dataset.trafficListOpen);
        const wrap = target?.classList.contains('traffic-listener-table-wrap')
            ? target
            : target?.querySelector('.traffic-listener-table-wrap') || target?.closest('table')?.parentElement;
        if (!wrap) return;
        if (listTitle) listTitle.textContent = opener.textContent.replace(/^Ouvrir la liste\s*:\s*/i, 'Liste complète · ').trim();
        listContent.innerHTML = wrap.outerHTML;
        listContent.querySelectorAll('[id]').forEach((element) => element.removeAttribute('id'));
        listDialog.showModal();
    });
    document.addEventListener('click', (event) => {
        if (event.target.closest('[data-traffic-list-close]')) listDialog?.close();
        if (event.target.closest('[data-traffic-detail], [data-network-security-detail], [data-prepare-dns-block]')) listDialog?.close();
    });
    const syncLocation = () => {
        const [name, subview] = window.location.hash.slice(1).split('/');
        showPanel(name || 'dashboard', false, subview);
    };
    window.addEventListener('popstate', syncLocation);
    window.addEventListener('hashchange', () => {
        const [name, subview] = window.location.hash.slice(1).split('/');
        if (name !== activePanel || (subview && subview !== subviews.get(name))) syncLocation();
    });
    document.getElementById('trafficControlRefresh')?.addEventListener('click', async () => {
        if (loading || domainCheckLoading) return;
        await loadState();
        document.dispatchEvent(new CustomEvent('traffic-control-refresh', { detail: { panel: activePanel } }));
    });

    document.getElementById('trafficTrackingPreset')?.addEventListener('click', (event) => {
        const preset = event.currentTarget.dataset;
        const values = {
            'tcp-established-timeout': preset.tcpEstablishedTimeout,
            'udp-timeout': preset.udpTimeout,
            'udp-stream-timeout': preset.udpStreamTimeout,
            'generic-timeout': preset.genericTimeout,
        };
        Object.entries(values).forEach(([name, value]) => {
            const select = document.querySelector(`#trafficTrackingForm [name="${name}"]`);
            if (select && value) select.value = value;
        });
        notify('Préselection chargée. Utilisez « Appliquer » pour modifier le routeur.', 'info');
    });

    document.getElementById('trafficTrackingSave')?.addEventListener('click', () => {
        const form = document.getElementById('trafficTrackingForm');
        const tracking = Object.fromEntries(new FormData(form).entries());
        const summary = `TCP établi ${tracking['tcp-established-timeout']}, UDP ${tracking['udp-timeout']}, UDP bidirectionnel ${tracking['udp-stream-timeout']}, générique ${tracking['generic-timeout']}`;
        if (window.confirm(`Appliquer ces délais sur ${deviceId} ?\n\n${summary}`)) {
            mutate({ action: 'update_tracking', tracking }, 'Paramètres conntrack appliqués.');
        }
    });

    document.getElementById('trafficDnsFlushCache')?.addEventListener('click', (event) => {
        const button = event.currentTarget;
        if (!window.confirm(`Vider maintenant le cache DNS du MikroTik ${deviceId} ?\n\nLes prochaines résolutions devront être redemandées aux serveurs amont.`)) return;
        button.disabled = true;
        mutate({ action: 'flush_dns_cache' }, 'Cache DNS vidé.').finally(() => {
            button.disabled = !stateReady;
        });
    });

    const dnsSettingsForm = document.getElementById('trafficDnsSettingsForm');
    dnsSettingsForm?.addEventListener('input', () => { dnsSettingsForm.dataset.userEditing = 'true'; });
    dnsSettingsForm?.addEventListener('submit', (event) => {
        event.preventDefault();
        const form = event.currentTarget;
        if (!form.reportValidity()) return;
        const settings = Object.fromEntries(new FormData(form).entries());
        const initial = JSON.parse(form.dataset.initialSettings || '{}');
        const changed = Object.entries(settings).filter(([key, value]) => String(initial[key] ?? '') !== String(value ?? ''));
        if (!changed.length) {
            notify('Aucun changement DNS à enregistrer.', 'info');
            return;
        }
        const summary = changed.map(([key, value]) => `${key}: ${value || 'vide'}`).join('\n');
        if (!window.confirm(`Enregistrer cette configuration DNS sur ${deviceId} ?\n\n${summary}\n\nLes paramètres sont globaux pour le routeur sélectionné.`)) return;
        mutate({ action: 'update_dns_settings', settings }, 'Configuration DNS enregistrée.').then((success) => {
            if (success) form.dataset.userEditing = 'false';
        });
    });

    document.getElementById('trafficAdlistUrl')?.addEventListener('change', updateAdlistSubmitLabel);

    document.getElementById('trafficAdlistForm')?.addEventListener('submit', (event) => {
        event.preventDefault();
        const input = document.getElementById('trafficAdlistUrl');
        const url = String(input?.value || '').trim();
        if (!event.currentTarget.reportValidity()) return;
        const existing = (currentState?.adlists || []).find((row) => row.url === url);
        if (existing) {
            const nextDisabled = !routerBoolean(existing.disabled);
            if (!window.confirm(`${nextDisabled ? 'Désactiver' : 'Activer'} cette liste sur ${deviceId} ?\n\n${url}`)) return;
            mutate({ action: 'toggle_adlist', id: existing['.id'], disabled: nextDisabled }, nextDisabled ? 'Adlist désactivée.' : 'Adlist activée.');
            return;
        }
        if (!window.confirm(`Ajouter et activer cette source DNS sur ${deviceId} ?\n\n${url}`)) return;
        mutate({ action: 'add_adlist', url }, 'Adlist ajoutée.').then((success) => {
            if (success && input) input.value = '';
        });
    });

    document.getElementById('trafficAdlistReload')?.addEventListener('click', () => {
        const managedCount = (currentState?.adlists || []).filter((row) => row.managed === true).length;
        if (!window.confirm(`Demander à RouterOS de recharger les Adlists du routeur ${deviceId} ?\n\nRouterOS recharge toutes les listes DNS configurées. Listes gérées par Radius Manager : ${managedCount}.`)) return;
        mutate({ action: 'reload_adlists' }, 'Mise à jour des Adlists demandée.');
    });

    workspace?.addEventListener('click', (event) => {
        const button = event.target.closest('[data-policy-key]');
        if (!button) return;
        const enabled = button.dataset.policyEnable === 'true';
        const label = button.closest('tr')?.querySelector('strong')?.textContent || button.dataset.policyKey;
        if (window.confirm(`${enabled ? 'Activer' : 'Désactiver'} la politique « ${label} » sur ${deviceId} ?`)) {
            mutate({ action: 'set_policy', policy: button.dataset.policyKey, enabled }, 'Politique modifiée.');
        }
    });

    document.getElementById('trafficDnsBlockForm')?.addEventListener('submit', (event) => {
        event.preventDefault();
        const input = document.getElementById('trafficDnsBlockDomain');
        const category = document.getElementById('trafficDnsBlockCategory')?.value || 'Personnalisé';
        const domain = String(input?.value || '').trim();
        if (!event.currentTarget.reportValidity()) return;
        if (!window.confirm(`Bloquer ${domain} et ses sous-domaines dans la catégorie « ${category} » sur le DNS du routeur ${deviceId} ? Cette règle n’est pas limitée à un client.`)) return;
        mutate({ action: 'add_dns_block', domain, category }, 'Domaine bloqué.').then((success) => {
            if (success && input) input.value = '';
        });
    });

    document.getElementById('trafficDomainCheckForm')?.addEventListener('submit', async (event) => {
        event.preventDefault();
        if (domainCheckLoading || loading || !stateReady) return;
        const form = event.currentTarget;
        const input = document.getElementById('trafficDomainCheckInput');
        const submit = document.getElementById('trafficDomainCheckSubmit');
        const resultContainer = document.getElementById('trafficDomainCheckResult');
        const domain = String(input?.value || '').trim();
        if (!domain) {
            form.reportValidity();
            return;
        }

        domainCheckLoading = true;
        setControlsEnabled(false);
        if (submit) {
            submit.disabled = true;
            submit.innerHTML = '<span class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span><span>Vérification…</span>';
        }
        if (resultContainer) {
            resultContainer.className = 'traffic-domain-check-result loading';
            resultContainer.innerHTML = '<div class="traffic-domain-check-verdict"><span class="spinner-border spinner-border-sm" aria-hidden="true"></span><div><strong>Analyse en cours</strong><span>Lecture des filtres RouterOS…</span></div></div>';
            resultContainer.hidden = false;
        }

        try {
            const data = await request({ action: 'check_domain', domain });
            if (!data.result || typeof data.result !== 'object') {
                throw new Error('Le routeur n\'a retourné aucun résultat exploitable.');
            }
            renderDomainCheckResult(data.result);
        } catch (error) {
            renderDomainCheckError(error.message || 'Lecture RouterOS impossible.');
        } finally {
            domainCheckLoading = false;
            setControlsEnabled(stateReady);
            if (submit) {
                submit.disabled = !stateReady;
                submit.innerHTML = '<i class="fa fa-search me-1"></i><span>Vérifier</span>';
            }
        }
    });

    document.getElementById('trafficDnsBlockBody')?.addEventListener('click', (event) => {
        const toggle = event.target.closest('[data-dns-block-toggle]');
        const remove = event.target.closest('[data-dns-block-remove]');
        if (toggle) {
            const disabled = toggle.dataset.nextDisabled === 'true';
            if (window.confirm(`${disabled ? 'Désactiver' : 'Réactiver'} ce blocage DNS ?`)) {
                mutate({ action: 'toggle_dns_block', id: toggle.dataset.dnsBlockToggle, disabled }, 'Blocage DNS modifié.');
            }
        } else if (remove && window.confirm('Supprimer définitivement ce blocage DNS ? La politique associée pourra le recréer.')) {
            mutate({ action: 'remove_dns_block', id: remove.dataset.dnsBlockRemove }, 'Blocage DNS supprimé.');
        }
    });

    document.getElementById('trafficWhitelistForm')?.addEventListener('submit', (event) => {
        event.preventDefault();
        const input = document.getElementById('trafficWhitelistDomain');
        const domain = String(input?.value || '').trim();
        if (!event.currentTarget.reportValidity()) return;
        if (!window.confirm(`Ajouter une exception aux listes pour ${domain} et ses sous-domaines sur le DNS du routeur ${deviceId} ? Cette exception est globale et ne contourne pas les autres protections réseau.`)) return;
        mutate({ action: 'add_whitelist', domain }, 'Domaine autorisé.').then((success) => {
            if (success && input) input.value = '';
        });
    });

    document.getElementById('trafficWhitelistBody')?.addEventListener('click', (event) => {
        const remove = event.target.closest('[data-whitelist-remove]');
        if (remove && window.confirm('Retirer ce domaine de la liste blanche ?')) {
            mutate({ action: 'remove_whitelist', id: remove.dataset.whitelistRemove }, 'Domaine retiré.');
        }
    });

    const bindTableSearch = (inputId, bodyId, emptyColumns) => {
        document.getElementById(inputId)?.addEventListener('input', (event) => {
            const query = String(event.currentTarget.value || '').trim().toLocaleLowerCase('fr');
            const body = document.getElementById(bodyId);
            if (!body) return;
            const rows = Array.from(body.querySelectorAll('tr')).filter((row) => !row.dataset.searchEmpty);
            let visible = 0;
            rows.forEach((row) => {
                const matches = !query || row.textContent.toLocaleLowerCase('fr').includes(query);
                row.hidden = !matches;
                if (matches) visible++;
            });
            body.querySelector('[data-search-empty]')?.remove();
            if (rows.length && visible === 0) {
                body.insertAdjacentHTML('beforeend', `<tr data-search-empty="true"><td colspan="${emptyColumns}" class="text-center text-white-50">Aucun résultat pour cette recherche.</td></tr>`);
            }
        });
    };
    bindTableSearch('trafficDnsBlockSearch', 'trafficDnsBlockBody', 5);
    bindTableSearch('trafficWhitelistSearch', 'trafficWhitelistBody', 4);

    setControlsEnabled(false);
    if (!panels.length) {
        tabs.forEach((tab) => { tab.disabled = true; });
        if (sectionSelect) sectionSelect.disabled = true;
        return;
    }
    window.setTimeout(() => {
        let saved = '';
        try { saved = window.localStorage.getItem(tabStorageKey) || ''; } catch (_) { /* stockage facultatif */ }
        const [name, subview] = (window.location.hash.slice(1) || saved || 'dashboard').split('/');
        showPanel(name, false, subview);
        if (deviceId && document.getElementById('trafficTrackingForm')) loadState();
    }, 0);
});
