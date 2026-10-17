document.addEventListener('DOMContentLoaded', () => {
    const toggle = document.getElementById('trafficListenerToggle');
    if (!toggle) {
        return;
    }

    const deviceId = document.getElementById('trafficControlDeviceId')?.value || '';
    const baseEndpoint = `../api/mikrotik_traffic_listener.php?device_id=${encodeURIComponent(deviceId)}`;
    const refreshButton = document.getElementById('trafficListenerRefreshNow');
    const refreshSelect = document.getElementById('trafficListenerRefresh');
    const state = document.getElementById('trafficListenerState');
    const notice = document.getElementById('trafficListenerNotice');
    const search = document.getElementById('trafficListenerSearch');
    const protocolFilter = document.getElementById('trafficListenerProtocol');
    const sortSelect = document.getElementById('trafficListenerSort');
    const usageDateInput = document.getElementById('trafficUsageDate');
    const usageDateControl = document.querySelector('[data-usage-date-control]');
    const usageCycleControl = document.querySelector('[data-usage-cycle-control]');
    const usageCycleStartSelect = document.getElementById('trafficUsageCycleStart');
    const usageFilterInput = document.getElementById('trafficUsageFilter');
    const usersBody = document.getElementById('trafficListenerUsersBody');
    const domainsBody = document.getElementById('trafficListenerDomainsBody');
    const flowsBody = document.getElementById('trafficListenerFlowsBody');
    const dnsSignatureBody = document.getElementById('trafficDnsSignatureBody');
    const lastUpdate = document.getElementById('trafficListenerLastUpdate');
    let timer = null;
    let running = false;
    let loading = false;
    let activeFilter = 'all';
    let activePanel = 'dashboard';
    let ready = false;
    let started = false;
    let controller = null;
    let lastRead = 0;
    let generation = 0;
    let detailRows = [];
    const isVisible = () => !document.hidden && ['dashboard', 'activity', 'usage', 'security'].includes(activePanel);
    let snapshot = { users: [], flows: [], domains: [] };
    let dailyUsageChart = null;
    let usageChartMode = 'day';
    let usageSort = { key: 'bytes', direction: 'desc' };
    const localUsageDay = () => {
        const now = new Date();
        return `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-${String(now.getDate()).padStart(2, '0')}`;
    };
    if (usageDateInput) {
        usageDateInput.max = localUsageDay();
        if (!usageDateInput.value) usageDateInput.value = localUsageDay();
    }
    function syncUsagePeriodControls() {
        const monthMode = usageChartMode === 'month';
        usageDateControl?.classList.toggle('d-none', monthMode);
        usageCycleControl?.classList.toggle('d-none', !monthMode);
    }
    syncUsagePeriodControls();
    const endpointUrl = () => `${baseEndpoint}&cycle_start=${encodeURIComponent(usageCycleStartSelect?.value || '1')}&usage_mode=${encodeURIComponent(usageChartMode === 'month' ? 'month' : 'day')}&day=${encodeURIComponent(usageDateInput?.value || localUsageDay())}`;

    const escapeHtml = (value) => String(value ?? '')
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#39;');

    function formatNumber(value) {
        return value === null || value === undefined || value === '' || !Number.isFinite(Number(value))
            ? '—' : new Intl.NumberFormat('fr-FR').format(Number(value));
    }

    function formatBytes(value) {
        if (value === null || value === undefined || value === '' || !Number.isFinite(Number(value))) return '—';
        let size = Math.max(0, Number(value));
        const units = ['o', 'Ko', 'Mo', 'Go', 'To'];
        let unit = 0;
        while (size >= 1000 && unit < units.length - 1) {
            size /= 1000;
            unit++;
        }
        return `${size.toFixed(unit === 0 ? 0 : 1)} ${units[unit]}`;
    }

    function usageProgressColor(percent) {
        if (percent >= 90) return '#ef4444';
        if (percent >= 75) return '#f59e0b';
        if (percent >= 50) return '#22c55e';
        return '#38bdf8';
    }

    function setUsageProgress(elementId, value, target) {
        const element = document.getElementById(elementId);
        if (!element) return;
        const current = Math.max(0, Number(value) || 0);
        const limit = Number(target) || 0;
        if (limit <= 0) {
            element.style.setProperty('--usage-progress', '0%');
            element.title = `${formatBytes(current)} · cible non définie`;
            return;
        }
        const percent = (current / limit) * 100;
        element.style.setProperty('--usage-progress', `${Math.min(100, percent).toFixed(2)}%`);
        element.style.setProperty('--usage-progress-color', usageProgressColor(percent));
        element.title = `${formatBytes(current)} / ${formatBytes(limit)} · ${percent.toFixed(1).replace('.', ',')} %`;
    }

    function formatRatio(value, total) {
        return `${formatNumber(value)} / ${formatNumber(total)}`;
    }

    function ratioPercent(value, total) {
        const current = Number(value);
        const maximum = Number(total);
        if (!Number.isFinite(current) || !Number.isFinite(maximum) || maximum <= 0) return 0;
        return Math.max(0, Math.min(100, (current / maximum) * 100));
    }

    function historyStat(label, value, detail = '', color = '#17a2b8', ratio = null) {
        const progress = ratio && Number.isFinite(Number(ratio.value)) && Number.isFinite(Number(ratio.total)) && Number(ratio.total) > 0
            ? ratioPercent(ratio.value, ratio.total)
            : 0;
        return `<div class="traffic-history-stat" style="--stat-color:${escapeHtml(color)};--stat-ratio:${progress.toFixed(2)}%"><span>${escapeHtml(label)}</span><strong>${escapeHtml(value)}</strong>${detail ? `<small>${escapeHtml(detail)}</small>` : ''}</div>`;
    }

    const historyCharts = {};

    function sampleNumber(row, key) {
        const value = Number(row?.[key]);
        return Number.isFinite(value) ? value : 0;
    }

    function parseDateValue(value) {
        const normalized = String(value || '').trim().replace(' ', 'T');
        if (!normalized) return null;
        const date = new Date(normalized);
        return Number.isFinite(date.getTime()) ? date : null;
    }

    function sampleLabel(row, offsetMs = 0) {
        const date = parseDateValue(row?.sampled_at);
        if (date && offsetMs) date.setTime(date.getTime() + offsetMs);
        return date && Number.isFinite(date.getTime()) ? date.toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' }) : '';
    }

    function renderHistoryChart(id, type, samples, datasets, chartOptions = {}) {
        const canvas = document.getElementById(id);
        if (!canvas || !window.Chart) return;
        if (canvas.parentElement) {
            canvas.parentElement.style.height = '118px';
            canvas.parentElement.style.maxHeight = '118px';
        }
        canvas.style.height = '118px';
        canvas.style.maxHeight = '118px';
        const labels = samples.map((row) => sampleLabel(row, chartOptions.timeOffsetMs || 0));
        const chartData = {
            labels,
            datasets: datasets.map((dataset) => ({
                label: dataset.label,
                data: samples.map((row) => sampleNumber(row, dataset.key)),
                borderColor: dataset.color,
                backgroundColor: dataset.fill || dataset.color,
                borderWidth: 2,
                pointRadius: type === 'line' ? 0 : 2,
                pointHoverRadius: 3,
                tension: .28,
                fill: Boolean(dataset.area),
                stack: dataset.stack || undefined,
            })),
        };
        const options = {
            responsive: true,
            maintainAspectRatio: false,
            animation: false,
            interaction: { intersect: false, mode: 'index' },
            plugins: {
                legend: { position: 'top', align: 'end', labels: { color: '#cbd5e1', usePointStyle: true, pointStyle: 'line', boxWidth: 28, boxHeight: 2, padding: 10 } },
            },
            scales: {
                x: { ticks: { color: '#94a3b8', maxTicksLimit: 5, padding: 2 }, grid: { color: 'rgba(148, 163, 184, .06)' } },
                y: { beginAtZero: chartOptions.beginAtZero !== false, grace: chartOptions.grace || 0, ticks: { color: '#94a3b8', maxTicksLimit: 4, padding: 2 }, grid: { color: 'rgba(148, 163, 184, .09)' }, stacked: type === 'bar' },
            },
        };
        if (!historyCharts[id]) {
            historyCharts[id] = new Chart(canvas, { type, data: chartData, options });
            return;
        }
        historyCharts[id].data = chartData;
        historyCharts[id].options = options;
        historyCharts[id].update('none');
    }

    function renderTrafficHistory(data) {
        const history = data.history || {};
        const summary = data.summary || {};
        const firewall = data.firewall || {};
        const totals = history.totals || {};
        const latest = history.latest || {};
        const samples = Array.isArray(history?.samples) ? history.samples : [];
        const refreshMeta = document.getElementById('trafficControlRefresh');
        const setRefreshMeta = (text) => {
            if (!refreshMeta) return;
            const label = text ? `Actualiser · ${text}` : 'Actualiser la configuration';
            refreshMeta.title = label;
            refreshMeta.setAttribute('aria-label', label);
            refreshMeta.dataset.collectionTooltip = text || '';
        };
        const note = (id, text) => {
            const element = document.getElementById(id);
            if (element) element.textContent = text;
        };
        const stats = (id, html) => {
            const element = document.getElementById(id);
            if (element) element.innerHTML = html;
        };
        if (!samples.length) {
            setRefreshMeta('Aucune collecte historisée disponible.');
            ['trafficHistoryAdlistNote', 'trafficHistoryFirewallNote', 'trafficHistoryConnectionNote', 'trafficHistoryClientNote']
                .forEach((id) => note(id, 'En attente.'));
            ['trafficHistoryAdlistStats', 'trafficHistoryFirewallStats', 'trafficHistoryConnectionStats', 'trafficHistoryClientStats']
                .forEach((id) => stats(id, 'En attente.'));
            return;
        }
        const rangeText = samples.length > 1
            ? `${samples.length} collectes réelles`
            : '1 collecte réelle';
        const lastSample = samples[samples.length - 1]?.sampled_at || '';
        const routerClock = data.router_clock?.display ? ` · RouterOS ${data.router_clock.display}` : '';
        setRefreshMeta(`${rangeText} · ${formatNumber(history.hours || 24)} h · ${lastSample ? `échantillon serveur ${lastSample}` : 'échantillon serveur --'}${routerClock}`);
        const routerDate = parseDateValue(data.router_clock?.display);
        const latestDate = parseDateValue(lastSample);
        const chartTimeOffsetMs = routerDate && latestDate ? routerDate.getTime() - latestDate.getTime() : 0;
        note('trafficHistoryAdlistNote', 'Delta match-count');
        note('trafficHistoryFirewallNote', 'Delta paquets');
        note('trafficHistoryConnectionNote', 'Connexions avec débit');
        note('trafficHistoryClientNote', 'Actifs / observés');
        const firewallTotal = totals.firewall_total_delta || 0;
        const blocked = totals.firewall_blocked_delta || 0;
        const accepted = totals.firewall_accepted_delta || 0;
        const adlistActive = summary.adlists?.active || latest.adlist_active || 0;
        const adlistTotal = summary.adlists?.total || latest.adlist_total || 0;
        const observed = latest.conntrack_observed || summary.observed || 0;
        const activeConnections = latest.active_connections || summary.active_now || 0;
        const localUsers = latest.local_users || summary.local_users || 0;
        const activeHotspot = latest.active_hotspot || summary.active_hotspot || 0;
        stats('trafficHistoryAdlistStats', [
            historyStat('Blocages période', formatNumber(totals.adlist_match_delta || 0), `${formatNumber(history.hours || 24)} h`, '#ef4444'),
            historyStat('Listes actives', `${formatNumber(adlistActive)} / ${formatNumber(adlistTotal)}`, `${formatNumber(summary.adlists?.domains || latest.adlist_domain_count || 0)} domaines`, '#17a2b8', { value: adlistActive, total: adlistTotal }),
        ].join(''));
        stats('trafficHistoryFirewallStats', [
            historyStat('Bloqués', formatRatio(blocked, firewallTotal), 'delta période', '#ef4444', { value: blocked, total: firewallTotal }),
            historyStat('Acceptés', formatRatio(accepted, firewallTotal), 'delta période', '#22c55e', { value: accepted, total: firewallTotal }),
        ].join(''));
        stats('trafficHistoryConnectionStats', [
            historyStat('Actives', formatRatio(activeConnections, observed), 'dernier échantillon', '#f59e0b', { value: activeConnections, total: observed }),
            historyStat('DNS corrélés', summary.dns_cache_available ? formatRatio(latest.dns_resolved_connections || summary.dns_resolved_connections || 0, observed) : 'Indisponible', 'cache DNS', '#17a2b8', { value: latest.dns_resolved_connections || summary.dns_resolved_connections || 0, total: observed }),
        ].join(''));
        stats('trafficHistoryClientStats', [
            historyStat('Hotspot actifs', formatRatio(activeHotspot, localUsers), 'dernier échantillon', '#83deeb', { value: activeHotspot, total: localUsers }),
            historyStat('À vérifier', formatNumber(latest.review_users || summary.review_users || 0), 'activité suspecte', '#ef4444'),
        ].join(''));
        renderHistoryChart('trafficHistoryAdlistChart', 'bar', samples, [
            { label: 'Correspondances Adlist', key: 'adlist_match_delta', color: '#ef4444', fill: 'rgba(239, 68, 68, .55)' },
        ], { timeOffsetMs: chartTimeOffsetMs });
        renderHistoryChart('trafficHistoryFirewallChart', 'bar', samples, [
            { label: 'Bloqués', key: 'firewall_blocked_delta', color: '#ef4444', fill: 'rgba(239, 68, 68, .55)', stack: 'packets' },
            { label: 'Acceptés', key: 'firewall_accepted_delta', color: '#22c55e', fill: 'rgba(34, 197, 94, .55)', stack: 'packets' },
        ], { timeOffsetMs: chartTimeOffsetMs });
        renderHistoryChart('trafficHistoryConnectionChart', 'line', samples, [
            { label: 'Connexions actives', key: 'active_connections', color: '#f59e0b', fill: 'rgba(245, 158, 11, .08)', area: true },
        ], { beginAtZero: false, grace: '12%', timeOffsetMs: chartTimeOffsetMs });
        renderHistoryChart('trafficHistoryClientChart', 'line', samples, [
            { label: 'Hotspot actifs', key: 'active_hotspot', color: '#83deeb', fill: 'rgba(131, 222, 235, .08)', area: true },
            { label: 'IP observées', key: 'local_users', color: '#cbd5e1' },
        ], { beginAtZero: false, grace: '12%', timeOffsetMs: chartTimeOffsetMs });
    }

    function renderDailyUsage(data) {
        const usage = data.usage_daily || {};
        const dayTotals = usage.totals || {};
        const cycleTotals = usage.cycle_totals || {};
        const totals = usageChartMode === 'month' ? cycleTotals : dayTotals;
        // all_users porte le classement jour ou mois selon le mode demande au serveur (usage_mode) ;
        // un seul champ, pas de cycle_users distinct (doublon retire le 2026-10-11).
        const sourceUsers = Array.isArray(usage.all_users) ? usage.all_users : [];
        const usageFilter = String(usageFilterInput?.value || '').trim().toLowerCase();
        const allUsers = sourceUsers
            .filter((row) => {
                if (!usageFilter) return true;
                return [row.source_type, row.label, row.local_ip, row.mac]
                    .some((value) => String(value || '').toLowerCase().includes(usageFilter));
            })
            .sort((left, right) => {
                const key = usageSort.key;
                const direction = usageSort.direction === 'asc' ? 1 : -1;
                const numeric = ['bytes', 'connections', 'active_connections', 'counter_count'].includes(key);
                const leftValue = numeric ? Number(left[key] || 0) : String(left[key] || left.label || '').toLowerCase();
                const rightValue = numeric ? Number(right[key] || 0) : String(right[key] || right.label || '').toLowerCase();
                if (leftValue < rightValue) return -1 * direction;
                if (leftValue > rightValue) return 1 * direction;
                return String(left.label || '').localeCompare(String(right.label || ''), 'fr');
            });
        setMetric('trafficUsageTotalLabel', 'Total collecté');
        const wanCapTargets = window.trafficWanCapTargets || {};
        const usageTarget = Number(usageChartMode === 'month'
            ? wanCapTargets.monthlyBytes
            : wanCapTargets.dailyBytes) || 0;
        const collectedTotal = Number(totals.all || 0);
        setMetric('trafficUsageTotal', formatBytes(collectedTotal));
        setUsageProgress('trafficUsageTotalCard', collectedTotal, usageTarget);
        setUsageProgress('trafficUsageChartCard', collectedTotal, usageTarget);
        setMetric('trafficUsageHotspot', formatBytes(totals.hotspot || 0));
        setMetric('trafficUsageBinding', formatBytes(totals.ip_binding || 0));
        setMetric('trafficUsageObserved', formatBytes(totals.observed || 0));
        setMetric('trafficUsageSamples', usageChartMode === 'month'
            ? `${usage.month_start || '--'} → ${usage.month_end || '--'}`
            : `Collectes : ${formatNumber(usage.sample_count || 0)}`);
        setMetric('trafficUsageRankingTitle', usageChartMode === 'month' ? 'Classement utilisateurs · mois' : 'Classement utilisateurs · jour');
        const periodHead = document.getElementById('trafficUsageTablePeriodHead');
        if (periodHead) periodHead.textContent = usageChartMode === 'month' ? 'Conso mois' : 'Conso jour';
        document.querySelectorAll('[data-usage-sort]').forEach((button) => {
            const active = button.dataset.usageSort === usageSort.key;
            button.classList.toggle('active', active);
            button.dataset.sortDirection = active ? usageSort.direction : '';
            button.setAttribute('aria-sort', active ? (usageSort.direction === 'asc' ? 'ascending' : 'descending') : 'none');
        });

        const body = document.getElementById('trafficUsageBody');
        if (body) {
            body.innerHTML = allUsers.length ? allUsers.map((row, index) => {
                const isBinding = row.source_type === 'ip_binding';
                const typeLabel = isBinding ? 'IP binding' : row.source_type === 'hotspot' ? 'Hotspot' : 'Observé';
                return `
                    <tr class="traffic-usage-row ${isBinding ? 'binding' : 'hotspot'}">
                        <td><span class="traffic-rank">#${formatNumber(index + 1)}</span></td>
                        <td><span class="traffic-usage-type ${isBinding ? 'binding' : 'hotspot'}">${escapeHtml(typeLabel)}</span></td>
                        <td><strong>${escapeHtml(row.label || row.mac || row.local_ip || '-')}</strong>${row.mac ? `<div class="traffic-cell-muted">${escapeHtml(row.mac)}</div>` : ''}</td>
                        <td>${escapeHtml(row.local_ip || '-')}</td>
                        <td><strong>${formatBytes(row.bytes || 0)}</strong><div class="traffic-cell-muted">${formatNumber(row.counter_count || 0)} session(s) comptabilisée(s)</div></td>
                        <td>${formatNumber(row.connections || 0)}<div class="traffic-cell-muted">${formatNumber(row.active_connections || 0)} actives</div></td>
                    </tr>
                `;
            }).join('') : '<tr><td colspan="6" class="text-center text-white-50 py-3">Aucune consommation utilisateur collectée pour cette période.</td></tr>';
        }

        const hourly = Array.isArray(usage.hourly) ? usage.hourly : [];
        const monthly = Array.isArray(usage.monthly?.days) ? usage.monthly.days : [];
        const systemNow = new Date();
        const usageDay = String(usage.day || '').trim();
        const todayKey = `${systemNow.getFullYear()}-${String(systemNow.getMonth() + 1).padStart(2, '0')}-${String(systemNow.getDate()).padStart(2, '0')}`;
        const currentHour = systemNow.getHours();
        const visibleHourly = usageDay === todayKey
            ? hourly.map((row) => {
                const hour = Number.parseInt(String(row.hour || '').slice(0, 2), 10);
                return Number.isFinite(hour) && hour > currentHour ? { ...row, bytes: 0 } : row;
            })
            : hourly;
        const chartRows = usageChartMode === 'month' ? monthly : visibleHourly;
        setMetric('trafficUsageChartTitle', usageChartMode === 'month' ? 'Total par jour' : 'Total par heure');
        document.querySelectorAll('[data-usage-chart-mode]').forEach((button) => {
            button.classList.toggle('active', button.dataset.usageChartMode === usageChartMode);
            button.setAttribute('aria-pressed', String(button.dataset.usageChartMode === usageChartMode));
        });
        const canvas = document.getElementById('trafficUsageDailyChart');
        if (canvas?.parentElement) {
            canvas.parentElement.style.height = '170px';
            canvas.parentElement.style.maxHeight = '170px';
        }
        if (canvas && window.Chart) {
            const chartData = {
                labels: chartRows.map((row) => usageChartMode === 'month' ? row.label : row.hour),
                datasets: [{
                    label: usageChartMode === 'month' ? 'Consommation par jour' : 'Consommation par heure',
                    data: chartRows.map((row) => Number(row.bytes) || 0),
                    borderColor: '#17a2b8',
                    backgroundColor: usageChartMode === 'month' ? 'rgba(34, 197, 94, .48)' : 'rgba(23, 162, 184, .52)',
                    borderRadius: 6,
                    maxBarThickness: usageChartMode === 'month' ? 14 : 18,
                }],
            };
            const options = {
                responsive: true,
                maintainAspectRatio: false,
                animation: { duration: 240 },
                plugins: {
                    legend: { position: 'top', align: 'end', labels: { color: '#cbd5e1', usePointStyle: true, pointStyle: 'line', boxWidth: 28, boxHeight: 2 } },
                    tooltip: { callbacks: { label: (ctx) => `${ctx.dataset.label}: ${formatBytes(ctx.parsed.y || 0)}` } },
                },
                scales: {
                    x: { ticks: { color: '#94a3b8', maxTicksLimit: usageChartMode === 'month' ? 10 : 8 }, grid: { color: 'rgba(148, 163, 184, .06)' } },
                    y: { beginAtZero: true, ticks: { color: '#94a3b8', callback: (value) => formatBytes(value) }, grid: { color: 'rgba(148, 163, 184, .09)' } },
                },
            };
            if (!dailyUsageChart) {
                dailyUsageChart = new Chart(canvas, { type: 'bar', data: chartData, options });
            } else {
                dailyUsageChart.data = chartData;
                dailyUsageChart.options = options;
                dailyUsageChart.update('none');
            }
        }
    }

    function renderAnalytics(data) {
        const firewall = data.firewall || {};
        const summary = data.summary || {};
        const setMetric = (id, value) => {
            const element = document.getElementById(id);
            if (element) element.textContent = value;
        };
        setMetric('trafficAnalyticsTotal', `${formatNumber(firewall.total_packets)} paquets · ${formatBytes(firewall.total_bytes)}`);
        setMetric('trafficAnalyticsBlocked', `${formatNumber(firewall.blocked_packets)} paquets · ${formatBytes(firewall.blocked_bytes)}`);
        setMetric('trafficAnalyticsSuspicious', formatNumber(summary.suspicious_inbound_sources));
        setMetric('trafficAnalyticsPublicIps', formatNumber(summary.ip_classes?.['publique plausible']));
        setMetric('trafficAnalyticsRules', `${formatNumber(firewall.active_rules)} règles actives / ${formatNumber(firewall.rules_total)}`);
        renderTrafficHistory(data);
        renderDailyUsage(data);

        const p2pUsers = (data.users || []).filter((row) => Number(row.p2p_connections) > 0).length;
        const alerts = [];
        if (Number(summary.review_users) > 0) {
            alerts.push({ level: 'warning', title: `${formatNumber(summary.review_users)} IP à vérifier`, detail: 'Conntrack élevé sans identité suffisante ou sans débit confirmé.', panel: 'activity', subview: 'clients' });
        }
        if (p2pUsers > 0) {
            alerts.push({ level: 'warning', title: `${formatNumber(p2pUsers)} client(s) avec P2P possible`, detail: 'Signal issu des ports et volumes observés ; vérification manuelle recommandée avant restriction.', panel: 'activity', subview: 'connections' });
        }
        if (Number(summary.suspicious_inbound_sources) > 0) {
            alerts.push({ level: 'critical', title: `${formatNumber(summary.suspicious_inbound_sources)} source(s) entrantes suspectes`, detail: 'Trafic entrant à examiner dans les compteurs de sécurité.', panel: 'security' });
        }
        window.TrafficOverviewAlerts?.update('listener', alerts);

        const renderStats = (id, stats) => {
            const body = document.getElementById(id);
            const rows = Object.entries(stats || {});
            if (body) body.innerHTML = rows.length
                ? rows.map(([name, values]) => `<tr><td>${escapeHtml(name)}</td><td>${formatNumber(values.packets)}</td><td>${formatBytes(values.bytes)}</td></tr>`).join('')
                : '<tr><td colspan="3">Aucun compteur.</td></tr>';
        };
        renderStats('trafficAnalyticsActions', firewall.actions);
        renderStats('trafficAnalyticsProtocols', firewall.protocols);
        const ipBody = document.getElementById('trafficAnalyticsIpClasses');
        const ipRows = Object.entries(summary.ip_classes || {});
        if (ipBody) ipBody.innerHTML = ipRows.length
            ? ipRows.map(([name, count]) => `<tr><td>${escapeHtml(name)}</td><td>${formatNumber(count)}</td></tr>`).join('')
            : '<tr><td colspan="2">Aucune IP observée.</td></tr>';

        const rulesBody = document.getElementById('trafficAnalyticsRulesBody');
        const rules = Array.isArray(firewall.top_rules) ? firewall.top_rules : [];
        if (rulesBody) rulesBody.innerHTML = rules.length
            ? rules.map((rule) => `<tr><td>${escapeHtml(rule.table)} · ${escapeHtml(rule.chain)} / ${escapeHtml(rule.action)}${rule.comment ? `<div class="traffic-cell-muted">${escapeHtml(rule.comment)}</div>` : ''}</td><td>${escapeHtml(rule.protocol)}</td><td>${formatNumber(rule.packets)}</td><td>${formatBytes(rule.bytes)}</td></tr>`).join('')
            : '<tr><td colspan="4">Aucune règle active.</td></tr>';

        const usersBody = document.getElementById('trafficAnalyticsUsers');
        const users = Array.isArray(data.users) ? [...data.users].sort((left, right) => Number(right.bytes) - Number(left.bytes)).slice(0, 10) : [];
        if (usersBody) usersBody.innerHTML = users.length
            ? users.map((user) => `<tr><td>${escapeHtml(user.local_ip)}</td><td>${escapeHtml(user.ip_class || 'non classée')}</td><td>${formatNumber(user.connections)}</td><td>${formatBytes(user.bytes)}</td></tr>`).join('')
            : '<tr><td colspan="4">Aucun consommateur observé.</td></tr>';
        setMetric('trafficAnalyticsNote', firewall.note || 'Les compteurs de règles peuvent compter plusieurs fois un même flux.');
    }

    function signatureActionLabel(action) {
        if (action === 'block') return 'Bloquer';
        if (action === 'local_reply') return 'Réponse locale';
        if (action === 'local') return 'Local';
        return 'Surveiller';
    }

    function signatureActionClass(action) {
        if (action === 'block') return 'danger';
        if (action === 'local_reply') return 'info';
        if (action === 'local') return 'success';
        return 'warning';
    }

    function renderDnsSignatures(data) {
        const analysis = data.dns_signature_analysis || {};
        const families = Array.isArray(analysis.families) ? analysis.families : [];
        const updatePolicies = Object.values(analysis.update_policies || {});
        const enabledUpdates = updatePolicies.filter((policy) => Boolean(policy.enabled)).length;
        const leaks = Array.isArray(analysis.update_leaks) ? analysis.update_leaks : [];
        const strictActive = Array.isArray(analysis.active_strict) ? analysis.active_strict : [];
        const localReply = families.find((family) => family.key === 'connectivity_probe');
        const setCard = (id, value, detail, stateClass = '') => {
            const card = document.getElementById(id);
            if (!card) return;
            card.classList.remove('ok', 'warn', 'danger');
            if (stateClass) card.classList.add(stateClass);
            const strong = card.querySelector('strong');
            const small = card.querySelector('small');
            if (strong) strong.textContent = value;
            if (small) small.textContent = detail;
        };
        setCard('trafficDnsUpdateStatus', `${formatNumber(enabledUpdates)} / ${formatNumber(updatePolicies.length)}`, 'Politiques NXDOMAIN actives', enabledUpdates === updatePolicies.length && updatePolicies.length ? 'ok' : 'warn');
        setCard('trafficDnsUpdateLeaks', formatNumber(leaks.length), leaks.length ? 'À examiner : fuite observée' : 'Aucune fuite observée', leaks.length ? 'danger' : 'ok');
        setCard('trafficDnsLocalReply', formatNumber(localReply?.connections || 0), `${formatNumber(localReply?.domains || 0)} domaine(s) de présence`, Number(localReply?.active_connections || 0) > 0 ? 'warn' : 'ok');
        setCard('trafficDnsStrictActive', formatNumber(strictActive.length), 'Signatures bloquer/local avec débit', strictActive.length ? 'warn' : 'ok');
        if (!dnsSignatureBody) return;
        dnsSignatureBody.innerHTML = families.length ? families.map((family) => {
            const action = family.action || 'watch';
            const actionClass = signatureActionClass(action);
            const domains = (family.top_domains || []).map((item) => `<span class="traffic-domain">${escapeHtml(item.domain)} · ${formatNumber(item.connections)}</span>`).join('');
            return `<tr class="traffic-signature-row traffic-signature-${escapeHtml(actionClass)}">
                <td><strong>${escapeHtml(family.label || 'Non classé')}</strong><div class="traffic-cell-muted">${escapeHtml(family.description || '')}</div></td>
                <td><span class="traffic-signature-action ${escapeHtml(actionClass)}">${escapeHtml(signatureActionLabel(action))}</span></td>
                <td>${domains || '<span class="text-white-50">Aucun domaine clé</span>'}</td>
                <td>${formatNumber(family.devices || 0)}</td>
                <td><strong>${formatNumber(family.connections || 0)}</strong><div class="traffic-cell-muted">${formatNumber(family.idle_connections || 0)} sans débit</div></td>
                <td><strong class="traffic-active-value">${formatNumber(family.active_connections || 0)}</strong></td>
            </tr>`;
        }).join('') : '<tr><td colspan="6" class="text-center text-white-50 py-4">Aucune signature DNS corrélée.</td></tr>';
    }

    function render() {
        const query = String(search?.value || '').trim().toLowerCase();
        const protocol = String(protocolFilter?.value || '').trim().toLowerCase();
        const sortKey = String(sortSelect?.value || 'connections');
        const reviewIps = new Set((snapshot.users || []).filter((row) => row.review_required).map((row) => row.local_ip));
        const matchesProtocol = (row) => !protocol || String(row.protocol || '').toLowerCase() === protocol;
        const flowGroups = new Map();
        (snapshot.flows || []).filter(matchesProtocol).forEach((row) => {
            if (!flowGroups.has(row.local_ip)) flowGroups.set(row.local_ip, []);
            flowGroups.get(row.local_ip).push(row);
        });
        const numericSortValue = (row) => {
            const value = Number(row[sortKey]);
            return Number.isFinite(value) ? value : 0;
        };
        const sortRows = (rows) => [...rows].sort((left, right) => numericSortValue(right) - numericSortValue(left));
        const matchesFilter = (row, type) => {
            if (activeFilter === 'active') return Number(row.active_connections) > 0;
            if (activeFilter === 'review') return type === 'user' ? Boolean(row.review_required) : reviewIps.has(row.local_ip) || Boolean(row.review_required);
            if (activeFilter === 'unresolved') return type === 'user' ? !(row.top_domains || []).length : !(row.dns_names || []).length;
            return true;
        };
        const users = sortRows((snapshot.users || []).filter((row) => (!protocol || flowGroups.has(row.local_ip)) && matchesFilter(row, 'user') && (query === '' || [
            row.username, row.local_ip, row.mac, row.binding_comment, row.binding_type, ...(row.top_domains || []),
        ].join(' ').toLowerCase().includes(query))));
        renderDnsSignatures(snapshot);

        const flows = sortRows((snapshot.flows || []).filter((row) => matchesProtocol(row) && matchesFilter(row, 'flow') && (query === '' || [
            row.username, row.local_ip, row.remote_ip, row.protocol, row.port, row.service, row.direction,
            row.binding_comment, row.binding_type, ...(row.dns_names || []),
        ].join(' ').toLowerCase().includes(query))));

        detailRows = [];
        usersBody.innerHTML = users.length ? users.map((row) => `
            <tr class="traffic-row-${escapeHtml(row.diagnostic || 'retained')}">
                <td>${detailButton(row, 'client')}${row.review_required
                    ? '<span class="traffic-diagnostic review"><i class="fa fa-triangle-exclamation"></i> À vérifier</span>'
                    : (Number(row.active_connections) > 0
                        ? '<span class="traffic-diagnostic active"><i class="fa fa-circle-play"></i> Actif</span>'
                        : '<span class="traffic-diagnostic retained"><i class="fa fa-clock"></i> Conservé</span>')}
                    ${Number(row.p2p_connections) > 0 ? `<div class="traffic-diagnostic-reason">P2P possible : ${formatNumber(row.p2p_connections)}</div>` : ''}
                    ${row.review_required && Number(row.p2p_connections) === 0 ? '<div class="traffic-diagnostic-reason">≥ 1 000, sans débit ni identité</div>' : ''}
                </td>
                <td><strong>${escapeHtml(row.username || 'Non associé')}</strong><div class="traffic-cell-muted">Binding : ${escapeHtml(row.binding_comment || row.binding_type || '-')} · ${escapeHtml(row.mac || '-')}</div>${(row.platform_signals || []).length ? `<div class="traffic-cell-muted">Signaux : ${escapeHtml(row.platform_signals.join(', '))}</div>` : ''}</td>
                <td>${escapeHtml(row.local_ip || '-')}</td>
                <td><strong>${formatNumber(row.connections)}</strong><div class="traffic-cell-muted">${formatNumber(row.idle_connections)} sans débit</div></td>
                <td><strong class="traffic-active-value">${formatNumber(row.active_connections)}</strong></td>
                <td>${(row.top_domain_counts || []).length
                    ? (row.top_domain_counts || []).map((item) => `<span class="traffic-domain">${escapeHtml(item.name)} · ${formatNumber(item.connections)}</span>`).join('')
                    : '<span class="traffic-dns-missing">DNS inconnu</span>'}<div class="traffic-cell-muted">${formatNumber(row.destinations)} IP distincte(s)</div></td>
                <td>${formatBytes(row.bytes)}</td>
            </tr>
        `).join('') : '<tr><td colspan="7" class="text-center text-white-50 py-4">Aucun utilisateur ne correspond au filtre.</td></tr>';

        if (domainsBody) {
            const domains = sortRows((snapshot.domains || []).filter((row) => {
                const filtered = activeFilter === 'active' ? Number(row.active_connections) > 0
                    : activeFilter === 'review' ? flows.some((flow) => (flow.dns_names || []).includes(row.domain))
                    : activeFilter !== 'unresolved';
                const domainHasProtocol = !protocol || flows.some((flow) => (flow.dns_names || []).includes(row.domain));
                return domainHasProtocol && filtered && (query === '' || [row.domain, ...(row.platform_signals || [])].join(' ').toLowerCase().includes(query));
            }));
            domainsBody.innerHTML = domains.length ? domains.map((row) => `
                <tr>
                    <td>${detailButton(row, 'domaine')}<span class="traffic-domain">${escapeHtml(row.domain || '-')}</span></td>
                    <td>${escapeHtml(row.signature?.label || ((row.platform_signals || []).length ? row.platform_signals.join(', ') : 'Non classé'))}</td>
                    <td>${formatNumber(row.devices)}</td>
                    <td>${formatNumber(row.connections)}</td>
                    <td><strong>${formatNumber(row.idle_connections)}</strong><div class="traffic-cell-muted">${formatNumber(row.active_connections)} avec débit</div></td>
                    <td><button type="button" class="btn btn-sm btn-outline-warning" data-prepare-dns-block="${escapeHtml(row.domain || '')}">Préparer blocage DNS</button></td>
                </tr>
            `).join('') : '<tr><td colspan="6" class="text-center text-white-50 py-4">Aucun domaine corrélé ne correspond au filtre.</td></tr>';
        }

        const directionLabels = {
            sortant: 'Sortant',
            entrant: 'Entrant WAN',
            redirige: 'Redirection locale',
        };
        flowsBody.innerHTML = flows.length ? flows.map((row) => `
            <tr class="traffic-row-${escapeHtml(row.diagnostic || 'retained')}">
                <td>${detailButton(row, 'connexion')}${row.review_required
                    ? '<span class="traffic-diagnostic review">P2P possible</span>'
                    : (Number(row.active_connections) > 0
                        ? '<span class="traffic-diagnostic active">Actif</span>'
                        : '<span class="traffic-diagnostic retained">Conservé</span>')}</td>
                <td><span class="traffic-direction traffic-direction-${escapeHtml(row.direction)}">${escapeHtml(directionLabels[row.direction] || row.direction)}</span></td>
                <td><strong>${escapeHtml(row.username || 'Non associé')}</strong><div class="traffic-cell-muted">Binding : ${escapeHtml(row.binding_comment || row.binding_type || '-')}</div><div class="traffic-cell-muted">${escapeHtml(row.local_ip || '-')}</div></td>
                <td>${(row.dns_names || []).length
                    ? (row.dns_names || []).map((name) => `<span class="traffic-domain">${escapeHtml(name)}</span>`).join('')
                    : '<span class="traffic-dns-missing">DNS inconnu</span>'}<div class="traffic-remote-ip">${escapeHtml(row.remote_ip || '-')}</div></td>
                <td>${escapeHtml(row.service || '-')}<div class="traffic-cell-muted">${escapeHtml(String(row.protocol || '').toUpperCase())}/${formatNumber(row.port)}</div></td>
                <td><strong>${formatNumber(row.connections)}</strong></td>
                <td><strong class="traffic-active-value">${formatNumber(row.active_connections)}</strong></td>
                <td>${formatBytes(row.bytes)}</td>
            </tr>
        `).join('') : '<tr><td colspan="8" class="text-center text-white-50 py-4">Aucun flux ne correspond au filtre.</td></tr>';
    }

    function detailButton(row, type) {
        const index = detailRows.push({ row, type }) - 1;
        return `<button type="button" class="btn btn-sm btn-outline-light traffic-detail-button" data-traffic-detail="${index}" aria-label="Détails ${escapeHtml(row.domain || row.local_ip || type)}">Détails</button> `;
    }

    function labelTables() {
        ['trafficListenerUsersBody', 'trafficListenerDomainsBody', 'trafficDnsSignatureBody', 'trafficListenerFlowsBody', 'trafficAnalyticsActions', 'trafficAnalyticsProtocols', 'trafficAnalyticsIpClasses', 'trafficAnalyticsRulesBody', 'trafficAnalyticsUsers', 'trafficUsageBody'].forEach((id) => {
            const table = document.getElementById(id)?.closest('table');
            if (!table) return;
            const labels = Array.from(table.querySelectorAll('thead th'), (th) => th.textContent.trim());
            table.querySelectorAll('tbody tr').forEach((tr) => Array.from(tr.cells).forEach((cell, index) => {
                if (cell.colSpan === 1) cell.dataset.label = labels[index] || '';
            }));
        });
    }

    const dialog = document.getElementById('trafficDetailDialog');
    document.addEventListener('click', (event) => {
        if (event.target.closest('[data-traffic-detail-close]')) dialog?.close();
        const button = event.target.closest('[data-traffic-detail]');
        if (!button || !dialog) return;
        const entry = detailRows[Number(button.dataset.trafficDetail)];
        if (!entry) return;
        const { row, type } = entry;
        document.getElementById('trafficDetailTitle').textContent = `${type === 'client' ? 'Client' : type === 'domaine' ? 'Domaine' : 'Connexion'} · ${row.domain || row.local_ip || 'Non associé'}`;
        const relatedFlows = type === 'client'
            ? (snapshot.flows || []).filter((flow) => String(flow.local_ip || '') === String(row.local_ip || '')).slice(0, 50)
            : [];
        const fields = [
            ['Identité observée', row.username], ['IP locale', row.local_ip], ['MAC', row.mac],
            ['Binding', row.binding_comment || row.binding_type], ['IP distante', row.remote_ip],
            ['Direction', row.direction], ['Protocole', row.protocol], ['Port', row.port],
            ['Connexions conservées', row.connections], ['Avec débit confirmé', row.active_connections],
            ['Sans débit à cet instant', row.idle_connections], ['Signatures DNS', (row.signature_labels || []).join(', ') || undefined], ['Appareils observés', row.devices],
            ['Volume conntrack', row.bytes === undefined ? undefined : formatBytes(row.bytes)],
            ['Signaux, à confirmer', (row.platform_signals || []).join(', ') || undefined],
        ].filter(([, value]) => value !== undefined && value !== null && value !== '');
        const domains = row.domain ? [row.domain] : (row.dns_names || row.top_domains || []);
        const flowsHtml = relatedFlows.length ? `<h3>Connexions du client</h3><div class="table-responsive traffic-listener-table-wrap"><table class="table table-sm align-middle mb-0"><thead><tr><th>Destination</th><th>Service</th><th>Connexions</th><th>Débit</th><th>Volume</th></tr></thead><tbody>${relatedFlows.map((flow) => `<tr><td>${(flow.dns_names || []).length ? (flow.dns_names || []).map((name) => `<span class="traffic-domain">${escapeHtml(name)}</span>`).join('') : escapeHtml(flow.remote_ip || '-')}</td><td>${escapeHtml(flow.service || '-')}<div class="traffic-cell-muted">${escapeHtml(String(flow.protocol || '').toUpperCase())}/${formatNumber(flow.port)}</div></td><td>${formatNumber(flow.connections)}</td><td>${formatNumber(flow.active_connections)}</td><td>${formatBytes(flow.bytes)}</td></tr>`).join('')}</tbody></table></div>` : '';
        document.getElementById('trafficDetailContent').innerHTML = `<p class="traffic-cell-muted">Instantané du ${escapeHtml(snapshot.captured_at ? new Date(snapshot.captured_at).toLocaleString('fr-FR') : '—')}. Les domaines proviennent d’une corrélation avec le cache DNS.</p><dl>${fields.map(([label, value]) => `<dt>${escapeHtml(label)}</dt><dd>${escapeHtml(value)}</dd>`).join('')}</dl>${domains.length ? `<h3>Domaines corrélés</h3>${domains.map((domain) => `<p class="traffic-domain-detail"><span>${escapeHtml(domain)}</span> <button type="button" class="btn btn-sm btn-outline-warning" data-prepare-dns-block="${escapeHtml(domain)}">Préparer une règle</button></p>`).join('')}` : '<p>Aucun domaine corrélé disponible.</p>'}${flowsHtml}<p>Une règle DNS concerne tous les clients utilisant ce routeur, domaine et sous-domaines inclus.</p>`;
        dialog.showModal();
    });

    function setMetric(id, value) {
        const element = document.getElementById(id);
        if (element) element.textContent = value;
    }

    async function loadUsageHistory() {
        try {
            const response = await fetch(`${endpointUrl()}&usage_only=1`, {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
            });
            const data = await response.json();
            if (!response.ok || !data.success) throw new Error(data.message || `Erreur HTTP ${response.status}`);
            snapshot = { ...snapshot, usage_daily: data.usage_daily };
            renderDailyUsage(snapshot);
            labelTables();
        } catch (error) {
            window.console.error('Chargement de l’historique de consommation impossible.', error);
        }
    }

    async function loadSnapshot() {
        if (!ready || !running || !isVisible() || loading) {
            return;
        }
        loading = true;
        refreshButton.disabled = true;
        state.textContent = 'Collecte…';
        state.className = 'badge text-bg-warning';
        notice.textContent = 'Lecture de la table conntrack et agrégation en cours. La première collecte peut prendre quelques secondes.';
        const collectionStartedAt = performance.now();

        const requestGeneration = ++generation;
        controller = new AbortController();
        const requestController = controller;
        const timeout = window.setTimeout(() => requestController.abort(), 20000);
        try {
            const response = await fetch(endpointUrl(), {
                signal: controller.signal,
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
            });
            const rawResponse = await response.text();
            let data;
            try {
                data = JSON.parse(rawResponse);
            } catch (error) {
                throw new Error(`Collecteur HTTP ${response.status} : réponse non JSON.`);
            }
            if (!response.ok || !data.success) {
                throw new Error(data.message || `Erreur HTTP ${response.status}`);
            }

            if (requestGeneration !== generation || !isVisible() || !running) return;
            snapshot = data;
            const collectionMs = Math.max(0, Math.round(performance.now() - collectionStartedAt));
            lastRead = Date.now();
            const summary = data.summary || {};
            renderAnalytics(data);
            setMetric('listenerTracked', formatNumber(summary.tracked));
            setMetric('listenerObserved', formatNumber(summary.observed));
            const idleAnalysis = summary.idle_analysis && typeof summary.idle_analysis === 'object' ? summary.idle_analysis : null;
            setMetric('listenerActivity', idleAnalysis
                ? `${formatNumber(summary.active_now)} / ${formatNumber(idleAnalysis.review_idle || 0)}`
                : `${formatNumber(summary.active_now)} / recalcul`);
            setMetric('listenerUsers', `${formatNumber(summary.active_hotspot)} / ${formatNumber(summary.local_users)}`);
            setMetric('listenerDns', summary.dns_cache_available
                ? `${formatNumber(summary.dns_resolved_connections)} / ${formatNumber(summary.observed)}`
                : 'Indisponible');
            setMetric('listenerReview', formatNumber(summary.review_users));
            lastUpdate.textContent = `Dernière collecte : ${data.router_clock?.display || 'horloge RouterOS indisponible'} · échantillon serveur ${data.history?.latest?.sampled_at || '--'} · cache ${formatNumber(data.cache_seconds)} s`;
            const tcpStates = Object.entries(summary.tcp_states || {})
                .sort((left, right) => Number(right[1]) - Number(left[1]))
                .map(([name, count]) => `${name}: ${formatNumber(count)}`)
                .join(' · ');
            const stateSummary = document.getElementById('trafficStateSummary');
            if (stateSummary) stateSummary.innerHTML = `<strong>États TCP :</strong> ${escapeHtml(tcpStates || 'aucun')}`;
            const snapshotSummary = document.getElementById('trafficSnapshotSummary');
            if (snapshotSummary) snapshotSummary.innerHTML = data.served_from_cache
                ? `<strong>Source :</strong> instantané serveur âgé de ${formatNumber(data.cache_age_seconds)} s`
                : '<strong>Source :</strong> nouvelle collecte RouterOS';
            const dnsNotice = summary.dns_cache_available ? '' : ' Le cache DNS RouterOS est indisponible pour cet instantané.';
            const idle = idleAnalysis || {};
            const idleDetail = idleAnalysis
                ? `Sans débit brut : ${formatNumber(idle.total_idle || 0)} · local/redirigé : ${formatNumber(idle.local_redirected || 0)} · présence locale : ${formatNumber(idle.local_reply || 0)} · système connu : ${formatNumber(idle.known_system || 0)} · services surveillés : ${formatNumber(idle.watched_service || 0)} · à revoir WAN : ${formatNumber(idle.review_idle || 0)}.`
                : 'Découpage sans débit indisponible sur cet instantané ; relancez une collecte.';
            notice.innerHTML = `<strong>${formatNumber(summary.active_now)} flux avec débit confirmé</strong> sur ${formatNumber(summary.observed)} entrées NAT analysées. ${escapeHtml(idleDetail)} <strong>${formatNumber(summary.review_users)} IP</strong> demandent une vérification.${dnsNotice}`;
            state.textContent = 'En observation';
            state.className = 'badge text-bg-success';
            render();
            labelTables();
        } catch (error) {
            if (requestGeneration !== generation) return;
            state.textContent = 'Dégradée';
            state.className = 'badge text-bg-danger';
            notice.textContent = error.name === 'AbortError' ? 'Lecture interrompue ou délai dépassé. Le dernier instantané peut être ancien.' : `${error.message || 'Observation indisponible.'} Le dernier instantané peut être ancien.`;
        } finally {
            window.clearTimeout(timeout);
            if (requestGeneration === generation) {
                controller = null;
                loading = false;
                refreshButton.disabled = !running;
            }
        }
    }

    function schedule() {
        if (timer) window.clearInterval(timer);
        if (running && isVisible()) {
            timer = window.setInterval(loadSnapshot, Math.max(60000, Number(refreshSelect.value) || 60000));
        }
    }

    function setRunning(nextRunning) {
        running = nextRunning;
        refreshButton.disabled = !running;
        refreshSelect.disabled = running;
        toggle.innerHTML = running
            ? '<i class="fa fa-stop me-1"></i>Arrêter l\'observation'
            : '<i class="fa fa-play me-1"></i>Démarrer l\'observation';
        toggle.classList.toggle('btn-primary', !running);
        toggle.classList.toggle('btn-danger', running);
        if (!running) {
            cancelRead();
            state.textContent = 'En pause';
            state.className = 'badge text-bg-secondary';
            notice.textContent = 'Observation arrêtée. Le dernier instantané reste affiché.';
        } else {
            loadSnapshot();
        }
        schedule();
    }

    toggle.addEventListener('click', () => setRunning(!running));
    refreshButton.addEventListener('click', loadSnapshot);
    refreshSelect.addEventListener('change', schedule);
    usageDateInput?.addEventListener('change', () => {
        usageChartMode = 'day';
        syncUsagePeriodControls();
        if (running) loadSnapshot();
        else loadUsageHistory();
    });
    usageCycleStartSelect?.addEventListener('change', () => {
        usageChartMode = 'month';
        syncUsagePeriodControls();
        if (running) loadSnapshot();
        else loadUsageHistory();
    });
    usageFilterInput?.addEventListener('input', () => {
        renderDailyUsage(snapshot);
        labelTables();
    });
    document.querySelectorAll('[data-usage-sort]').forEach((button) => {
        button.addEventListener('click', () => {
            const key = button.dataset.usageSort || 'bytes';
            usageSort = {
                key,
                direction: usageSort.key === key && usageSort.direction === 'desc' ? 'asc' : 'desc',
            };
            renderDailyUsage(snapshot);
            labelTables();
        });
    });
    document.querySelectorAll('[data-usage-chart-mode]').forEach((button) => {
        button.addEventListener('click', () => {
            usageChartMode = button.dataset.usageChartMode === 'month' ? 'month' : 'day';
            syncUsagePeriodControls();
            if (running) {
                loadSnapshot();
                return;
            }
            loadUsageHistory();
        });
    });
    search?.addEventListener('input', () => { render(); labelTables(); });
    protocolFilter?.addEventListener('change', () => { render(); labelTables(); });
    sortSelect?.addEventListener('change', () => { render(); labelTables(); });
    document.querySelectorAll('[data-listener-filter]').forEach((button) => {
        button.addEventListener('click', () => {
            activeFilter = button.dataset.listenerFilter || 'all';
            document.querySelectorAll('[data-listener-filter]').forEach((candidate) => candidate.classList.toggle('active', candidate === button));
            render();
            labelTables();
        });
    });
    document.addEventListener('click', (event) => {
        const button = event.target.closest('[data-prepare-dns-block]');
        if (!button) return;
        const domain = String(button.dataset.prepareDnsBlock || '');
        const input = document.getElementById('trafficDnsBlockDomain');
        const category = document.getElementById('trafficDnsBlockCategory');
        if (input) input.value = domain;
        if (category) category.value = 'Personnalisé';
        dialog?.close();
        document.dispatchEvent(new CustomEvent('traffic-control-show-panel', { detail: { panel: 'filtering', subview: 'rules' } }));
        window.AppToast?.flash?.(`Domaine ${domain} préparé. Vérifiez-le avant de confirmer le blocage.`, 'info');
    });
    document.addEventListener('traffic-control-state-ready', (event) => {
        const wasReady = ready;
        ready = event.detail?.success === true;
        toggle.disabled = !ready;
        if (!ready) {
            cancelRead();
            state.textContent = 'Indisponible';
            state.className = 'badge text-bg-danger';
            notice.textContent = 'État du routeur indisponible. Le dernier instantané affiché peut être ancien.';
            return;
        }
        if (!started) { started = true; setRunning(true); }
        else if (!wasReady) setRunning(running);
    });
    function cancelRead() {
        if (loading) lastRead = 0;
        controller?.abort();
        controller = null;
        generation++;
        loading = false;
        refreshButton.disabled = !running || !ready;
    }
    const activate = () => {
        if (!isVisible()) cancelRead();
        schedule();
        if (isVisible() && Date.now() - lastRead >= 60000) loadSnapshot();
    };
    document.addEventListener('traffic-control-panel-change', (event) => { activePanel = event.detail.panel; activate(); });
    document.addEventListener('traffic-control-refresh', (event) => {
        if (['dashboard', 'activity', 'usage', 'security'].includes(event.detail.panel)) loadSnapshot();
    });
    document.addEventListener('visibilitychange', activate);
    window.addEventListener('beforeunload', () => {
        if (timer) window.clearInterval(timer);
        controller?.abort();
    });
});
