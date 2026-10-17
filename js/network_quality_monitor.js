document.addEventListener('DOMContentLoaded', () => {
    const panel = document.querySelector('[data-traffic-panel="performance"]');
    if (!panel) return;

    const deviceId = document.getElementById('trafficControlDeviceId')?.value || '';
    const range = document.getElementById('networkQualityRange');
    const securityRange = document.getElementById('networkSecurityRange');
    if (securityRange) securityRange.value = range.value;
    const refresh = document.getElementById('networkQualityRefresh');
    const state = document.getElementById('networkQualityState');
    const verdict = document.getElementById('networkQualityVerdict');
    const incidentsBody = document.getElementById('networkQualityIncidents');
    let loading = false;
    let activePanel = 'dashboard';
    let lastRead = 0;
    let controller = null;
    let generation = 0;
    let chart = null;
    let securityDetailRows = [];
    const isVisible = () => !document.hidden && ['dashboard', 'security', 'performance'].includes(activePanel);

    const diagnosis = {
        healthy: { label: 'Stable pendant la mesure', icon: 'fa-circle-check', level: 'healthy', action: 'Continuer la surveillance ; aucune action immédiate.' },
        starlink_or_upstream: { label: 'Starlink ou réseau amont', icon: 'fa-satellite-dish', level: 'critical', action: 'Vérifier l’application Starlink, les obstructions, le câble et l’alimentation du kit.' },
        intermittent_wan: { label: 'Microperte Internet', icon: 'fa-cloud-bolt', level: 'warning', action: 'Comparer l’heure avec Starlink et surveiller si les deux cibles Internet échouent ensemble.' },
        single_probe_loss: { label: 'Perte isolée à confirmer', icon: 'fa-circle-exclamation', level: 'warning', action: 'Une seule cible a perdu des paquets ; attendre une répétition ou un échec simultané avant d’accuser Starlink.' },
        wan_interface: { label: 'Interface WAN ether1', icon: 'fa-plug-circle-xmark', level: 'critical', action: 'Contrôler le câble Starlink–ether1, le négocié Ethernet et l’alimentation.' },
        lan_interface: { label: 'Liaison locale ether3', icon: 'fa-network-wired', level: 'critical', action: 'Contrôler câble, switch et point d’accès reliés à ether3.' },
        lan_clients_or_wifi: { label: 'Réseau clients / Wi‑Fi', icon: 'fa-wifi', level: 'warning', action: 'Tester un client en câble puis contrôler les AP derrière ether3, les canaux et le roaming.', panel: 'activity' },
        mikrotik_or_lan: { label: 'MikroTik, VM ou liaison serveur', icon: 'fa-server', level: 'critical', action: 'Vérifier Proxmox, la VM MikroTik, le vSwitch et le chemin entre le serveur PHP et 10.10.10.1.' },
        mikrotik_reboot: { label: 'Redémarrage MikroTik', icon: 'fa-power-off', level: 'critical', action: 'Consulter les événements Proxmox et l’alimentation à cette heure.' },
        mikrotik_pressure: { label: 'Pression sur MikroTik', icon: 'fa-microchip', level: 'critical', action: 'Vérifier CPU, conntrack et tâches actives avant toute nouvelle règle.', panel: 'settings' },
        upload_saturation: { label: 'Saturation upload', icon: 'fa-arrow-up-from-bracket', level: 'warning', action: 'Identifier les principaux uploaders et réserver une marge sous les 65 Mbit/s.', panel: 'activity' },
        hotspot_shaping: { label: 'Limitation Hotspot', icon: 'fa-gauge-high', level: 'warning', action: 'Contrôler le profil et la file du client : les paquets sont rejetés par la limite, pas par Starlink.', panel: 'activity' },
        dns_or_https: { label: 'DNS ou établissement HTTPS', icon: 'fa-globe', level: 'warning', action: 'Comparer les DNS MikroTik/Starlink et tester une résolution puis une connexion TLS.', panel: 'filtering' },
        insufficient_data: { label: 'Données insuffisantes', icon: 'fa-hourglass-half', level: 'unknown', action: 'Attendre plusieurs collectes avant de conclure.' },
    };

    const escapeHtml = (value) => String(value ?? '')
        .replaceAll('&', '&amp;').replaceAll('<', '&lt;').replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;').replaceAll("'", '&#39;');

    const isMeasured = (value) => value !== null && value !== undefined && value !== '' && Number.isFinite(Number(value));
    const number = (value) => !isMeasured(value) ? '--' : new Intl.NumberFormat('fr-FR').format(Number(value));
    const percent = (value) => !isMeasured(value) ? '--' : `${Number(value).toFixed(2).replace('.', ',')} %`;
    const rate = (value) => {
        if (!isMeasured(value)) return '--';
        const bps = Number(value);
        if (!Number.isFinite(bps)) return '--';
        if (bps >= 1000000) return `${(bps / 1000000).toFixed(1).replace('.', ',')} Mbit/s`;
        if (bps >= 1000) return `${(bps / 1000).toFixed(1).replace('.', ',')} kbit/s`;
        return `${Math.round(bps)} bit/s`;
    };
    const bytes = (value) => {
        if (!isMeasured(value)) return '--';
        let size = Math.max(0, Number(value));
        const units = ['o', 'Ko', 'Mo', 'Go', 'To'];
        let unit = 0;
        while (size >= 1000 && unit < units.length - 1) {
            size /= 1000;
            unit++;
        }
        return `${size.toFixed(unit === 0 ? 0 : 1).replace('.', ',')} ${units[unit]}`;
    };
    const setText = (id, value) => {
        const element = document.getElementById(id);
        if (element) element.textContent = value;
    };

    function securityStatus(label, type) {
        const badge = document.getElementById('networkSecurityStatus');
        if (!badge) return;
        badge.textContent = label;
        badge.className = `badge text-bg-${type}`;
    }

    function evidence(row) {
        const details = row?.details || {};
        const phpLoss = details.php_server_probe?.loss;
        const topQueue = details.top_queue_drops?.[0];
        const queueProof = topQueue
            ? ` · File ${topQueue.name || '-'} ${topQueue.target || ''} −${number(topQueue.dropped)}${topQueue.drop_percent === null ? '' : ` (${percent(topQueue.drop_percent)})`}`
            : '';
        return {
            wan: `CF ${percent(row?.cloudflare_loss)} · Google ${percent(row?.google_loss)} · HTTPS ${row?.https_ok === null || row?.https_ok === undefined ? 'inconnu' : Number(row.https_ok) === 1 ? 'OK' : 'échec'}`,
            lan: `API ${row?.router_api_ms ?? '--'} ms · PHP→routeur ${percent(phpLoss)} · DHCP −${number(row?.dhcp_deassignments)} · Hotspot −${number(row?.hotspot_keepalive_timeouts)}${queueProof}`,
            load: `CPU ${row?.cpu_load ?? '--'} % · Conntrack ${number(row?.conntrack_entries)} · Files −${number(row?.queue_drop_delta)}`,
        };
    }

    function primaryDiagnosis(report) {
        return report.latest?.diagnosis || 'insufficient_data';
    }

    function render(report) {
        const summary = report.summary || {};
        const latest = report.latest;
        const primary = primaryDiagnosis(report);
        const meta = diagnosis[primary] || diagnosis.insufficient_data;
        verdict.className = `network-quality-verdict ${meta.level} mb-3`;
        verdict.innerHTML = `<div class="network-quality-verdict-icon"><i class="fa ${meta.icon}"></i></div><div><strong>${escapeHtml(meta.label)}</strong><p>${escapeHtml(meta.action)}</p></div>`;
        const action = document.getElementById('networkQualityAction');
        action.innerHTML = meta.panel
            ? `<button type="button" class="btn btn-sm btn-outline-light" data-network-action-panel="${escapeHtml(meta.panel)}"><i class="fa fa-arrow-right me-1"></i>Examiner les détails</button>`
            : '<span class="small text-white-50">Vérification manuelle : aucune modification automatique sûre.</span>';

        state.textContent = report.sample_count > 0 ? (meta.level === 'healthy' ? 'Stable pendant la dernière mesure' : 'Incident à analyser') : 'En attente';
        state.className = `badge ${meta.level === 'healthy' ? 'text-bg-success' : (meta.level === 'critical' ? 'text-bg-danger' : (meta.level === 'warning' ? 'text-bg-warning' : 'text-bg-secondary'))}`;
        setText('networkQualityHttps', summary.https_availability === null ? '--' : percent(summary.https_availability));
        setText('networkQualityLoss', latest ? `${percent(latest.cloudflare_loss)} / ${percent(latest.google_loss)}` : '--');
        setText('networkQualityWanRate', `${rate(summary.max_wan_rx_bps)} / ${rate(summary.max_wan_tx_bps)}`);
        setText('networkQualityRouterLoad', `${summary.max_cpu_load ?? '--'} % / ${number(summary.max_conntrack_entries)}`);
        setText('networkQualityKeepalive', number(summary.hotspot_keepalive_timeouts));
        setText('networkQualityLanSignals', `${number(summary.dhcp_deassignments)} / ${number(summary.queue_drops)}`);
        setText('networkQualitySampleCount', `${number(report.sample_count)} mesure(s) · ${number(summary.incident_count)} incident(s)`);

        const security = report.security || {};
        const securitySummary = security.summary || {};
        const hasSecurityData = Number(securitySummary.sample_count) > 0;
        securityStatus(hasSecurityData ? 'Rapport disponible' : 'Aucune mesure', hasSecurityData ? 'info' : 'secondary');
        setText('networkSecurityPeriod', `Période ${report.hours} h · ${number(securitySummary.sample_count)} mesure(s) · rétention ${number(security.retention_hours)} h`);
        setText('networkSecurityUpdatedAt', `Rapport relu à ${new Date().toLocaleTimeString('fr-FR')} · date de dernière collecte sécurité non fournie.`);
        if (securityRange) securityRange.value = String(report.hours);
        setText('networkSecurityDot', number(securitySummary.dot_observations));
        setText('networkSecurityDoq', number(securitySummary.doq_observations));
        setText('networkSecurityDoh', number(securitySummary.doh_suspected_observations));
        setText('networkSecurityForcedDns', `${number(securitySummary.forced_dns_udp)} / ${number(securitySummary.forced_dns_tcp)}`);
        setText('networkSecurityStarlinkUdp', `${number(securitySummary.starlink_udp_drop_packets)} · ${bytes(securitySummary.starlink_udp_drop_bytes)}`);
        setText('networkSecurityWanDrops', number(securitySummary.wan_drop_packets));
        setText('networkSecurityCollectionLoad', `Charge max. : ${number(securitySummary.max_collection_ms)} ms`);
        const securityAlerts = [];
        if (Number(securitySummary.dot_observations) + Number(securitySummary.doq_observations) + Number(securitySummary.doh_suspected_observations) > 0) {
            securityAlerts.push({ level: 'warning', title: 'DNS chiffré ou suspect observé', detail: 'DoT/DoQ/DoH peuvent contourner le filtrage DNS local ; ouvrir la sécurité pour les clients concernés.', panel: 'security' });
        }
        if (Number(securitySummary.starlink_udp_drop_packets) > 0 || Number(securitySummary.wan_drop_packets) > 0) {
            securityAlerts.push({ level: 'info', title: 'Rejets WAN mesurés', detail: 'Les compteurs indiquent des rejets réseau ; la preuve par règle est disponible dans Sécurité réseau.', panel: 'security' });
        }
        window.TrafficOverviewAlerts?.update('security', securityAlerts);
        const securityClients = document.getElementById('networkSecurityClients');
        const signalLabels = {
            dot: 'Port DoT observé',
            doq: 'DoQ probable',
            doh_suspected: 'DoH suspecté',
        };
        const clientRows = security.clients || [];
        securityDetailRows = [
            ...clientRows.map((row) => ({ type: 'Client sécurité', row })),
            ...(security.wan_evidence || []).map((row) => ({ type: 'Preuve WAN', row })),
        ];
        if (securityClients) {
            securityClients.innerHTML = clientRows.length ? clientRows.map((row, index) => `<tr>
                <td>${escapeHtml(new Date(String(row.last_seen).replace(' ', 'T')).toLocaleString('fr-FR'))}</td>
                <td><button type="button" class="btn btn-sm btn-outline-light traffic-detail-button" data-network-security-detail="${index}">Détails</button> <strong>${escapeHtml(row.username || 'Non associé')}</strong><div class="traffic-cell-muted">IP locale : ${escapeHtml(row.client_ip || '-')}</div></td>
                <td>${escapeHtml(signalLabels[row.signal_type] || row.signal_type || '-')}</td>
                <td>${number(row.observations)}</td>
                <td>${number(row.max_connections)}</td>
                <td>${bytes(row.max_snapshot_bytes)}</td>
            </tr>`).join('') : `<tr><td colspan="6" class="text-center text-white-50 py-4">${hasSecurityData ? 'Aucun contournement observé sur cette période.' : 'Aucune mesure sécurité disponible sur cette période.'}</td></tr>`;
        }
        const wanEvidence = document.getElementById('networkSecurityWanEvidence');
        const evidenceRows = security.wan_evidence || [];
        if (wanEvidence) {
            wanEvidence.innerHTML = evidenceRows.length ? evidenceRows.map((row, index) => `<tr>
                <td>${escapeHtml(new Date(String(row.sampled_at).replace(' ', 'T')).toLocaleString('fr-FR'))}</td>
                <td><button type="button" class="btn btn-sm btn-outline-light traffic-detail-button" data-network-security-detail="${clientRows.length + index}">Détails</button> ${escapeHtml(row.comment || 'Règle sans commentaire')}</td>
                <td>${escapeHtml(row.chain || '-')}</td>
                <td>${escapeHtml(`${String(row.protocol || 'tous').toUpperCase()} / ${row.dst_port || 'tous'}`)}</td>
                <td>${number(row.packet_delta)}</td>
                <td>${bytes(row.byte_delta)}</td>
            </tr>`).join('') : `<tr><td colspan="6" class="text-center text-white-50 py-4">${hasSecurityData ? 'Aucun nouveau rejet WAN sur cette période.' : 'Aucune mesure sécurité disponible sur cette période.'}</td></tr>`;
        }

        const guidance = document.getElementById('networkQualityGuidance');
        guidance.innerHTML = `<strong>Interprétation :</strong> ${escapeHtml(meta.action)} <span>La passerelle Starlink peut limiter l’ICMP ; la décision WAN utilise surtout deux cibles Internet et un test HTTPS réel.</span>`;

        const incidents = report.incidents || [];
        incidentsBody.innerHTML = incidents.length ? incidents.map((row) => {
            const item = diagnosis[row.diagnosis] || diagnosis.insufficient_data;
            const proof = evidence(row);
            return `<tr class="network-quality-row ${escapeHtml(row.severity)}">
                <td><strong>${escapeHtml(new Date(String(row.sampled_at).replace(' ', 'T')).toLocaleString('fr-FR'))}</strong></td>
                <td><span class="network-quality-cause ${escapeHtml(item.level)}"><i class="fa ${item.icon}"></i>${escapeHtml(item.label)}</span></td>
                <td>${escapeHtml(proof.wan)}</td>
                <td>${escapeHtml(proof.lan)}</td>
                <td>${escapeHtml(proof.load)}</td>
                <td>${escapeHtml(item.action)}</td>
            </tr>`;
        }).join('') : `<tr><td colspan="6" class="text-center text-white-50 py-4">${Number(report.sample_count) > 0 ? 'Aucun incident détecté sur cette période.' : 'Aucune mesure qualité disponible sur cette période.'}</td></tr>`;
        const sampled = latest?.sampled_at ? new Date(String(latest.sampled_at).replace(' ', 'T')) : null;
        const stamp = sampled && Number.isFinite(sampled.getTime()) ? sampled.toLocaleString('fr-FR') : null;
        setText('networkQualityLastUpdate', stamp ? `Dernière mesure : ${stamp} · période ${report.hours} h · rétention ${report.retention_hours} h` : 'Dernière mesure : indisponible');
        ['networkQualityIncidents', 'networkSecurityClients', 'networkSecurityWanEvidence'].forEach((id) => {
            const table = document.getElementById(id)?.closest('table');
            if (!table) return;
            const labels = Array.from(table.querySelectorAll('thead th'), (th) => th.textContent.trim());
            table.querySelectorAll('tbody tr').forEach((tr) => Array.from(tr.cells).forEach((cell, index) => {
                if (cell.colSpan === 1) cell.dataset.label = labels[index] || '';
            }));
        });
        const samples = [...(report.recent_samples || [])].reverse();
        const canvas = document.getElementById('networkQualityChart');
        if (canvas && window.Chart) {
            chart?.destroy();
            const measuredRate = (value) => !isMeasured(value) ? null : Number(value) / 1000000;
            chart = new Chart(canvas, {
                type: 'line',
                data: {
                    labels: samples.map((row) => new Date(String(row.sampled_at).replace(' ', 'T')).toLocaleString('fr-FR')),
                    datasets: [
                        { label: 'Réception WAN (Mbit/s)', data: samples.map((row) => measuredRate(row.wan_rx_bps)), borderColor: '#17a2b8', backgroundColor: 'rgba(23,162,184,.12)', pointRadius: 1, tension: 0, spanGaps: false },
                        { label: 'Émission WAN (Mbit/s)', data: samples.map((row) => measuredRate(row.wan_tx_bps)), borderColor: '#94a3b8', borderDash: [5, 4], pointRadius: 1, tension: 0, spanGaps: false },
                    ],
                },
                options: { responsive: true, maintainAspectRatio: false, animation: false, plugins: { legend: { labels: { color: '#cbd5e1' } } }, scales: { x: { ticks: { color: '#94a3b8', maxTicksLimit: 6 }, grid: { display: false } }, y: { beginAtZero: true, ticks: { color: '#94a3b8' }, title: { display: true, text: 'Mbit/s', color: '#94a3b8' }, grid: { color: 'rgba(148,163,184,.12)' } } } },
            });
        }
        setText('networkQualityChartNote', samples.length ? `${samples.length} dernières mesures disponibles (maximum 120), du ${new Date(String(samples[0].sampled_at).replace(' ', 'T')).toLocaleString('fr-FR')} au ${stamp}. Les valeurs absentes restent des interruptions de courbe.` : 'Aucune mesure disponible pour cette courbe.');
    }

    async function load() {
        if (!deviceId || loading || !isVisible()) return;
        loading = true;
        refresh.disabled = true;
        state.textContent = 'Lecture…';
        securityStatus('Lecture…', 'warning');
        state.className = 'badge text-bg-warning';
        const collectionStartedAt = performance.now();
        controller = new AbortController();
        const requestGeneration = ++generation;
        const requestHours = range.value;
        const requestController = controller;
        const timeout = window.setTimeout(() => requestController.abort(), 20000);
        try {
            const response = await fetch(`../api/network_quality_monitor.php?device_id=${encodeURIComponent(deviceId)}&hours=${encodeURIComponent(requestHours)}`, {
                signal: controller.signal,
                credentials: 'same-origin',
                headers: { Accept: 'application/json' },
            });
            const data = await response.json();
            if (!response.ok || !data.success || !data.report) throw new Error(data.message || 'Rapport indisponible.');
            if (requestGeneration !== generation || requestHours !== range.value) return;
            const collectionMs = Math.max(0, Math.round(performance.now() - collectionStartedAt));
            lastRead = Date.now();
            render(data.report);
            window.TrafficCollectionTimes?.update?.('qualite', `qualité ${new Date().toLocaleTimeString('fr-FR')}, lecture ${number(collectionMs)} ms, ${number(data.report.sample_count)} mesure(s)`);
        } catch (error) {
            if (requestGeneration !== generation) return;
            securityStatus('Rapport indisponible', 'danger');
            setText('networkSecurityUpdatedAt', 'Dernière lecture échouée. Les données affichées peuvent être anciennes.');
            window.TrafficCollectionTimes?.update?.('qualite', 'qualité indisponible');
            state.textContent = 'Collecteur indisponible';
            state.className = 'badge text-bg-danger';
            verdict.className = 'network-quality-verdict critical mb-3';
            verdict.innerHTML = `<div class="network-quality-verdict-icon"><i class="fa fa-triangle-exclamation"></i></div><div><strong>Rapport indisponible</strong><p>${escapeHtml(error.message || 'Erreur inconnue')}</p></div>`;
        } finally {
            window.clearTimeout(timeout);
            if (requestGeneration === generation) {
                controller = null;
                loading = false;
                refresh.disabled = false;
            }
        }
    }

    refresh.addEventListener('click', load);
    document.addEventListener('click', (event) => {
        const button = event.target.closest('[data-network-security-detail]');
        if (!button) return;
        const entry = securityDetailRows[Number(button.dataset.networkSecurityDetail)];
        const dialog = document.getElementById('trafficDetailDialog');
        const title = document.getElementById('trafficDetailTitle');
        const content = document.getElementById('trafficDetailContent');
        if (!entry || !dialog || !title || !content) return;
        const fields = Object.entries(entry.row || {}).filter(([, value]) => value !== null && value !== undefined && value !== '');
        title.textContent = entry.type;
        content.innerHTML = `<dl>${fields.map(([key, value]) => `<dt>${escapeHtml(key.replaceAll('_', ' '))}</dt><dd>${escapeHtml(typeof value === 'object' ? JSON.stringify(value) : value)}</dd>`).join('')}</dl>`;
        dialog.showModal();
    });
    document.getElementById('networkQualityAction')?.addEventListener('click', (event) => {
        const button = event.target.closest('[data-network-action-panel]');
        if (!button) return;
        document.dispatchEvent(new CustomEvent('traffic-control-show-panel', {
            detail: { panel: button.dataset.networkActionPanel },
        }));
    });
    const changeRange = (value) => {
        range.value = value;
        if (securityRange) securityRange.value = value;
        controller?.abort();
        generation++;
        loading = false;
        lastRead = 0;
        load();
    };
    range.addEventListener('change', () => changeRange(range.value));
    securityRange?.addEventListener('change', () => changeRange(securityRange.value));
    const activate = () => {
        if (!isVisible()) {
            if (loading) lastRead = 0;
            controller?.abort();
            controller = null;
            generation++;
            loading = false;
            refresh.disabled = false;
            return;
        }
        if (Date.now() - lastRead >= 60000) load();
    };
    document.addEventListener('traffic-control-panel-change', (event) => { activePanel = event.detail.panel; activate(); });
    document.addEventListener('traffic-control-refresh', (event) => {
        if (['dashboard', 'security', 'performance'].includes(event.detail.panel)) load();
    });
    document.addEventListener('visibilitychange', activate);
    const timer = window.setInterval(activate, 60000);
    window.addEventListener('beforeunload', () => { window.clearInterval(timer); controller?.abort(); chart?.destroy(); });
});
