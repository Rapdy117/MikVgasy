const TRAFFIC_DURATION_MS = 120000;
const TRAFFIC_DELAY_MS = 5000;
const TRAFFIC_STREAM_PATH = '../api/traffic_stream.php';
const TRAFFIC_INIT_PATH = '../api/get_traffic_stats.php';
const TRAFFIC_CONTROL_PATH = '../api/mikrotik_traffic_control.php';
const DOMAIN_ACTIVITY_PATH = '../api/get_opnsense_domain_activity.php';
const WAN_INTERFACE_KEY = 'wan';
const TRAFFIC_REFRESH_STORAGE_KEY = 'traffic_monitoring.refresh_ms';
const TRAFFIC_SERIES = {
    upload: [
        { key: 'outbytes', label: 'Upload', color: '#36A2EB', formatter: 'bits' },
    ],
    download: [
        { key: 'inbytes', label: 'Download', color: '#22C55E', formatter: 'bits' },
    ],
};
const TRAFFIC_INTERFACE_COLORS = ['#38BDF8', '#F59E0B', '#22C55E', '#A78BFA'];

document.addEventListener('DOMContentLoaded', () => {
    const messageArea = document.getElementById('messageArea');
    const downloadRateLive = document.getElementById('downloadRateLive');
    const uploadRateLive = document.getElementById('uploadRateLive');
    const downloadRateMirror = document.getElementById('downloadRateMirror');
    const uploadRateMirror = document.getElementById('uploadRateMirror');
    const downloadGapMirror = document.getElementById('downloadGapMirror');
    const uploadGapMirror = document.getElementById('uploadGapMirror');
    const downloadDataCounter = document.getElementById('downloadDataCounter');
    const uploadDataCounter = document.getElementById('uploadDataCounter');
    const downloadTrafficLegend = document.getElementById('downloadTrafficLegend');
    const uploadTrafficLegend = document.getElementById('uploadTrafficLegend');
    const bandwidthAdditionalInfo = document.getElementById('bandwidthAdditionalInfo');
    const trafficLastUpdate = document.getElementById('trafficLastUpdate');
    const trafficInterfaceSelect = document.getElementById('trafficInterfaceSelect');
    const trafficInterfaceSelect2 = document.getElementById('trafficInterfaceSelect2');
    const trafficRefreshSelect = document.getElementById('trafficRefreshSelect');
    const trafficSourceLabel = document.getElementById('trafficSourceLabel')?.value || 'Aucun device actif';
    const trafficSourceHost = document.getElementById('trafficSourceHost')?.value || '';
    const trafficSourceType = document.getElementById('trafficSourceType')?.value || '';
    const domainActivitySearch = document.getElementById('domainActivitySearch');
    const domainActivityTableBody = document.getElementById('domainActivityTableBody');
    const domainQueriesCount = document.getElementById('domainQueriesCount');
    const domainBlockedCount = document.getElementById('domainBlockedCount');
    const domainLastUpdate = document.getElementById('domainLastUpdate');
    const controlDeviceId = document.getElementById('trafficControlDeviceId')?.value || '';
    const controlCsrf = document.getElementById('trafficControlCsrf')?.value || '';
    const queueForm = document.getElementById('interfaceQueueForm');
    const queueInterface = document.getElementById('queueInterface');
    const queueDownload = document.getElementById('queueDownload');
    const queueUpload = document.getElementById('queueUpload');
    const queuePriority = document.getElementById('queuePriority');
    const queueToggle = document.getElementById('queueToggle');
    const interfaceQueueState = document.getElementById('interfaceQueueState');
    const wanEditModal = document.getElementById('wanEditModal');
    const wanEditTitleInput = document.getElementById('wanEditTitleInput');
    const wanEditInterfaceSelect = document.getElementById('wanEditInterfaceSelect');
    const wanEditPccInput = document.getElementById('wanEditPccInput');
    const wanEditDailyTargetInput = document.getElementById('wanEditDailyTargetInput');
    const wanEditMonthlyTargetInput = document.getElementById('wanEditMonthlyTargetInput');
    const wanEditSaveBtn = document.getElementById('wanEditSaveBtn');
    const wanEditHelp = document.getElementById('wanEditHelp');
    let activeWanEditCard = null;
    const trafficSourceGrid = document.getElementById('trafficSourceGrid');
    const trafficSourcesSummary = document.getElementById('trafficSourcesSummary');
    const trafficSourceStatsBody = document.getElementById('trafficSourceStatsBody');
    const trafficSourcesActiveKpi = document.getElementById('trafficSourcesActiveKpi');
    const trafficSourcesRxKpi = document.getElementById('trafficSourcesRxKpi');
    const trafficSourcesTxKpi = document.getElementById('trafficSourcesTxKpi');
    const trafficSourcesPccKpi = document.getElementById('trafficSourcesPccKpi');
    let wanTitles = {};
    let wanStates = {};
    let wanInterfaces = {};
    let wanDailyTargetsGb = {};
    let wanMonthlyTargetsGb = {};
    let wanUsage = {};
    let trafficSource = null;
    let trafficInitialized = false;
    let currentInterface = '';
    let currentInterfaces = [];
    let currentInterfaceRoles = {};
    let selectedRefreshMs = 2000;
    let lastTrafficApplyTs = 0;
    let domainRefreshTimer = null;
    let wanRefreshTimer = null;
    let currentWanInterfaceState = null;
    let currentWanTraffic = {};
    let wanLiveHistory = {};
    let wanUsageChart = null;
    let wanUsageViewMode = 'day';
    const usageDateInput = document.getElementById('trafficUsageDate');
    const usageDateControl = document.querySelector('[data-usage-date-control]');
    const usageCycleControl = document.querySelector('[data-usage-cycle-control]');
    if (usageDateInput) {
        usageDateInput.max = dateKey(new Date());
        if (!usageDateInput.value) usageDateInput.value = dateKey(new Date());
    }
    let lastWanFilterInterfaces = '';
    let wanConfigQualityRendered = false;
    let domainRows = [];
    let trafficCharts = {
        trafficIn: null,
        trafficOut: null,
    };

    function persistWanTitles() {}

    function persistWanStates() {}

    function persistWanInterfaces() {}

    function normalizeWanUsage(rawUsage) {
        const normalized = {};
        Object.entries(rawUsage || {}).forEach(([iface, usage]) => {
            if (!iface || !usage || typeof usage !== 'object') return;
            normalized[iface] = {
                ...usage,
                hourly: usage.hourly && typeof usage.hourly === 'object' ? usage.hourly : {},
                daily: usage.daily && typeof usage.daily === 'object' ? usage.daily : {},
                day_bytes: Number(usage.day_bytes || 0),
                month_bytes: Number(usage.month_bytes || 0),
                cycle_bytes: Number(usage.cycle_bytes || 0),
                last_rx_total: Number(usage.last_rx_total || 0),
                last_tx_total: Number(usage.last_tx_total || 0),
            };
        });
        return normalized;
    }

    function updateWanUsageFromCounters(interfaceName, rxTotal, txTotal) {
        if (!interfaceName || !wanUsage[interfaceName]) return false;
        wanUsage[interfaceName].last_rx_total = Number(rxTotal || wanUsage[interfaceName].last_rx_total || 0);
        wanUsage[interfaceName].last_tx_total = Number(txTotal || wanUsage[interfaceName].last_tx_total || 0);
        return false;
    }

    function getWanCards() {
        return [...document.querySelectorAll('.traffic-wan-parameter[data-wan-slot]')];
    }

    function getWanSlotAssignments(overrides = {}) {
        const assignments = {};
        getWanCards().forEach((card) => {
            const slot = card.dataset.wanSlot || '';
            const value = card.querySelector('[data-wan-interface]')?.value || '';
            if (slot && value) assignments[slot] = value;
        });
        Object.entries(overrides || {}).forEach(([slot, value]) => {
            const normalizedSlot = String(slot || '').trim();
            const normalizedValue = String(value || '').trim();
            if (normalizedSlot && normalizedValue) assignments[normalizedSlot] = normalizedValue;
        });
        return assignments;
    }

    function wanSlotLabel(card) {
        return card?.querySelector('[data-wan-title]')?.textContent?.trim() || card?.dataset.wanSlot || 'autre WAN';
    }

    function setWanCardEditMode(card, enabled) {
        card.classList.toggle('is-editing', enabled);
        const editButton = card.querySelector('.traffic-wan-title-edit');
        card.querySelectorAll('select, input').forEach((field) => {
            field.disabled = true;
        });
        if (editButton) {
            editButton.classList.remove('is-saving-mode');
            editButton.setAttribute('aria-label', 'Modifier les paramètres WAN');
            const icon = editButton.querySelector('i');
            if (icon) icon.className = 'fa fa-pen';
        }
    }

    function getActiveWanWeightRows() {
        return getWanCards().map((card, index) => {
            const slot = card.dataset.wanSlot || `wan${index + 1}`;
            const active = card.classList.contains('is-active');
            const selectable = card.dataset.wanInterfaceSelectable !== 'false';
            const input = card.querySelector('.traffic-wan-percent-field input');
            const value = Math.max(0, Number(input?.value || 0));
            return { card, slot, label: wanSlotLabel(card), active, selectable, value };
        }).filter((row) => row.active && row.selectable);
    }

    function normalizeWanWeights(rows) {
        const total = rows.reduce((sum, row) => sum + row.value, 0);
        if (rows.length === 0) return [];
        if (total <= 0) {
            const base = Math.floor(100 / rows.length);
            let rest = 100 - (base * rows.length);
            return rows.map((row) => ({ ...row, recommended: base + (rest-- > 0 ? 1 : 0) }));
        }
        let used = 0;
        return rows.map((row, index) => {
            const recommended = index === rows.length - 1 ? 100 - used : Math.round((row.value / total) * 100);
            used += recommended;
            return { ...row, recommended };
        });
    }

    function validateWanPccWeights({ notify = false } = {}) {
        const rows = getActiveWanWeightRows();
        const total = rows.reduce((sum, row) => sum + row.value, 0);
        getWanCards().forEach((card) => card.classList.remove('has-wan-pcc-warning'));
        if (rows.length === 0 || total === 100) return true;
        rows.forEach((row) => row.card.classList.add('has-wan-pcc-warning'));
        if (notify) {
            const suggested = normalizeWanWeights(rows).map((row) => `${row.label}: ${row.recommended}%`).join(' · ');
            AppToast.flash(`Total PCC actuel ${total}%. Ajustement proposé : ${suggested}`, 'warning');
        }
        return false;
    }

    function getWanInterfaceInfo(interfaceName) {
        const interfaces = currentWanInterfaceState?.multi_wan_interfaces?.interfaces;
        return Array.isArray(interfaces) ? interfaces.find((item) => item.interface === interfaceName) || null : null;
    }

    function getActiveWanInterfaces() {
        return getWanCards().map((card) => {
            const iface = card.querySelector('[data-wan-interface]')?.value || '';
            const info = getWanInterfaceInfo(iface);
            return card.classList.contains('is-active') && iface && info?.selectable ? iface : '';
        }).filter(Boolean);
    }

    function getWanFilterInterfaces() {
        const active = getActiveWanInterfaces();
        return active.length > 0 ? active.join(',') : currentInterfaces.join(',');
    }

    function wanHealthLabel(value) {
        if (value === 'online') return 'ONLINE';
        if (value === 'degraded') return 'DEGRADED';
        return 'OFFLINE';
    }

    function formatWanLatency(value) {
        const numeric = Number(value);
        return Number.isFinite(numeric) ? `${numeric.toFixed(numeric >= 10 ? 0 : 1)} ms` : '-- ms';
    }

    function formatWanPacketLoss(value) {
        const numeric = Number(value);
        return Number.isFinite(numeric) ? `${numeric.toFixed(numeric >= 1 ? 1 : 2)} %` : '-- %';
    }


    function formatMbps(valueBps) {
        const mbps = Math.max(0, Number(valueBps || 0)) / 1000000;
        return `${mbps.toFixed(2).padStart(5, '0')} Mbps`;
    }

    function formatWanBytes(value, decimals = 1) {
        const numeric = Number(value || 0);
        return numeric > 0 ? (formatField(numeric, decimals, false) || '0 o') : '0 o';
    }

    function getWanUsageCycleRange() {
        const now = new Date();
        const cycleSelect = document.getElementById('trafficUsageCycleStart');
        const startDay = Math.max(1, Math.min(28, Number(cycleSelect?.value || 1)));
        const start = new Date(now.getFullYear(), now.getMonth(), startDay);
        if (now.getDate() < startDay) start.setMonth(start.getMonth() - 1);
        const end = new Date(start.getFullYear(), start.getMonth() + 1, startDay);
        return { start, end };
    }

    function dateKey(date) {
        return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
    }

    function getSelectedUsageDate() {
        const value = usageDateInput?.value || dateKey(new Date());
        const [year, month, day] = value.split('-').map(Number);
        const selected = new Date(year, month - 1, day);
        return Number.isFinite(selected.getTime()) && dateKey(selected) === value ? selected : new Date();
    }

    function syncUsagePeriodControls() {
        const monthMode = wanUsageViewMode === 'month';
        usageDateControl?.classList.toggle('d-none', monthMode);
        usageCycleControl?.classList.toggle('d-none', !monthMode);
    }

    function hourKey(date) {
        return `${dateKey(date)} ${String(date.getHours()).padStart(2, '0')}`;
    }

    function getWanUsageValue(interfaceName) {
        const usage = wanUsage[interfaceName] || {};
        if (wanUsageViewMode === 'month') {
            const { start, end } = getWanUsageCycleRange();
            let total = 0;
            for (let cursor = new Date(start); cursor < end; cursor.setDate(cursor.getDate() + 1)) {
                total += Number(usage.daily?.[dateKey(cursor)] || 0);
            }
            return total > 0 ? total : Number(usage.cycle_bytes || usage.month_bytes || 0);
        }
        const selectedDay = dateKey(getSelectedUsageDate());
        return Number(usage.daily?.[selectedDay] ?? (usage.day_key === selectedDay ? usage.day_bytes : 0));
    }

    function getWanUsageChartRows() {
        if (wanUsageViewMode === 'month') {
            const { start, end } = getWanUsageCycleRange();
            const rows = [];
            for (let cursor = new Date(start); cursor < end; cursor.setDate(cursor.getDate() + 1)) {
                rows.push({ key: dateKey(cursor), label: `${String(cursor.getDate()).padStart(2, '0')}/${String(cursor.getMonth() + 1).padStart(2, '0')}` });
            }
            return rows;
        }
        const selectedDay = getSelectedUsageDate();
        const start = new Date(selectedDay.getFullYear(), selectedDay.getMonth(), selectedDay.getDate(), 0, 0, 0);
        return Array.from({ length: 24 }, (_, hour) => {
            const cursor = new Date(start.getTime() + hour * 3600 * 1000);
            return { key: hourKey(cursor), label: `${String(hour).padStart(2, '0')}:00` };
        });
    }

    function recordWanLiveHistory(interfaceName, rxRate, txRate) {
        if (!interfaceName) return;
        const current = wanLiveHistory[interfaceName] || { rx: [], tx: [] };
        current.rx.push(Math.max(0, Number(rxRate || 0)));
        current.tx.push(Math.max(0, Number(txRate || 0)));
        current.rx = current.rx.slice(-60);
        current.tx = current.tx.slice(-60);
        wanLiveHistory[interfaceName] = current;
    }

    function buildWanLivePath(values, maxValue, height = 34) {
        const numeric = values.map((value) => Math.max(0, Number(value || 0)));
        if (numeric.length < 2 || maxValue <= 0) return '';
        const width = 100;
        const step = width / (numeric.length - 1);
        return numeric.map((value, index) => {
            const x = index * step;
            const y = height - ((value / maxValue) * (height - 5)) - 2.5;
            return `${index === 0 ? 'M' : 'L'}${x.toFixed(2)} ${y.toFixed(2)}`;
        }).join(' ');
    }

    function getWanGlobalLiveMax() {
        const activeIfaces = getActiveWanCardsForUsage().map((item) => item.iface);
        const values = activeIfaces.flatMap((iface) => {
            const history = wanLiveHistory[iface] || { rx: [], tx: [] };
            return [...history.rx, ...history.tx];
        });
        return Math.max(...values.map((value) => Math.max(0, Number(value || 0))), 0);
    }

    function renderWanSummarySparkline(summary, iface, globalMaxValue = 0) {
        let sparkline = summary.querySelector('[data-wan-summary-sparkline]');
        if (!sparkline) {
            sparkline = document.createElement('div');
            sparkline.className = 'traffic-wan-summary-sparkline';
            sparkline.dataset.wanSummarySparkline = '';
            sparkline.setAttribute('aria-hidden', 'true');
            summary.prepend(sparkline);
        }

        const history = wanLiveHistory[iface] || { rx: [], tx: [] };
        const maxValue = Math.max(0, Number(globalMaxValue || 0));
        const rxLine = buildWanLivePath(history.rx, maxValue);
        const txLine = buildWanLivePath(history.tx, maxValue);
        const scaleLabel = maxValue > 0 ? (formatBits(maxValue) || '0 b') : '';
        if (!rxLine && !txLine) {
            sparkline.innerHTML = `<span class="traffic-wan-summary-scale">${escapeHtml(scaleLabel || '--')}</span>`;
            return;
        }
        sparkline.innerHTML = `<span class="traffic-wan-summary-scale">${escapeHtml(scaleLabel)}</span><svg viewBox="0 0 100 34" preserveAspectRatio="none" focusable="false"><line class="traffic-wan-summary-gridline" x1="0" y1="9" x2="100" y2="9"></line><line class="traffic-wan-summary-gridline" x1="0" y1="22" x2="100" y2="22"></line>${rxLine ? `<path class="traffic-wan-summary-sparkline-rx" d="${rxLine}"></path>` : ''}${txLine ? `<path class="traffic-wan-summary-sparkline-tx" d="${txLine}"></path>` : ''}</svg>`;
    }

    function getActiveWanCardsForUsage() {
        return getWanCards().map((card, index) => {
            const iface = card.querySelector('[data-wan-interface]')?.value || '';
            const info = iface ? getWanInterfaceInfo(iface) : null;
            return {
                card,
                index,
                iface,
                label: wanSlotLabel(card),
                active: card.classList.contains('is-active') && iface && info?.selectable,
            };
        }).filter((item) => item.active);
    }

    function renderWanUsageChart() {
        const canvas = document.getElementById('trafficUsageDailyChart');
        if (!canvas || !window.Chart) return;
        const rows = getWanUsageChartRows();
        const wanItems = getActiveWanCardsForUsage();
        const datasets = wanItems.map((item) => {
            const usage = wanUsage[item.iface] || {};
            const color = TRAFFIC_INTERFACE_COLORS[item.index % TRAFFIC_INTERFACE_COLORS.length];
            const source = wanUsageViewMode === 'month' ? (usage.daily || (usage.day_key ? { [usage.day_key]: Number(usage.day_bytes || 0) } : {})) : (usage.hourly || (usage.hour_key ? { [usage.hour_key]: Number(usage.hour_bytes || 0) } : {}));
            return {
                label: item.label,
                data: rows.map((row) => Number(source[row.key] || 0)),
                borderColor: color,
                backgroundColor: setAlpha(color, wanUsageViewMode === 'month' ? 0.42 : 0.32),
                borderWidth: 2,
                borderRadius: 6,
                maxBarThickness: wanUsageViewMode === 'month' ? 14 : 18,
            };
        });
        const existingChart = window.Chart.getChart ? window.Chart.getChart(canvas) : wanUsageChart;
        const chart = existingChart || wanUsageChart;
        const data = { labels: rows.map((row) => row.label), datasets };
        const options = {
            responsive: true,
            maintainAspectRatio: false,
            animation: { duration: 180 },
            plugins: {
                legend: { position: 'top', align: 'end', labels: { color: '#cbd5e1', usePointStyle: true, boxWidth: 22 } },
                tooltip: { callbacks: { label: (ctx) => `${ctx.dataset.label}: ${formatWanBytes(ctx.parsed.y || 0, 1)}` } },
            },
            scales: {
                x: { stacked: false, ticks: { color: '#94a3b8', maxTicksLimit: wanUsageViewMode === 'month' ? 10 : 8 }, grid: { color: 'rgba(148, 163, 184, .06)' } },
                y: { beginAtZero: true, ticks: { color: '#94a3b8', callback: (value) => formatWanBytes(value, 1) }, grid: { color: 'rgba(148, 163, 184, .09)' } },
            },
        };
        const title = document.getElementById('trafficUsageChartTitle');
        if (title) title.textContent = wanUsageViewMode === 'month' ? 'WAN par jour' : 'WAN par heure';
        document.querySelectorAll('[data-usage-chart-mode]').forEach((button) => {
            button.classList.toggle('active', button.dataset.usageChartMode === wanUsageViewMode);
            button.setAttribute('aria-pressed', String(button.dataset.usageChartMode === wanUsageViewMode));
        });
        if (chart) {
            chart.data = data;
            chart.options = options;
            chart.config.type = 'bar';
            chart.update('none');
            wanUsageChart = chart;
            return;
        }
        wanUsageChart = new Chart(canvas, { type: 'bar', data, options });
    }

    function ensureWanConfigQuality(card) {
        let quality = card.querySelector('[data-wan-config-quality]');
        if (quality) return quality;
        quality = document.createElement('div');
        quality.className = 'traffic-wan-config-quality';
        quality.dataset.wanConfigQuality = '';
        const pccLabel = card.querySelector('label:nth-of-type(2)');
        if (pccLabel) {
            pccLabel.insertAdjacentElement('afterend', quality);
        } else {
            card.appendChild(quality);
        }
        return quality;
    }

    function renderWanConfigQuality() {
        getWanCards().forEach((card) => {
            const quality = ensureWanConfigQuality(card);
            const iface = card.querySelector('[data-wan-interface]')?.value || '';
            const info = iface ? getWanInterfaceInfo(iface) : null;
            const active = card.classList.contains('is-active');
            const health = active && info?.selectable ? (info.wan_health || 'degraded') : 'offline';
            if (!active || !iface || !info?.selectable) {
                quality.innerHTML = '<span class="traffic-wan-status traffic-wan-status-offline">OFFLINE</span><span>Lat -- ms · Perte -- %</span>';
                return;
            }
            quality.innerHTML = `<span class="traffic-wan-status traffic-wan-status-${escapeHtml(health)}">${escapeHtml(wanHealthLabel(health))}</span><span>Lat ${escapeHtml(formatWanLatency(info.latency))} · Perte ${escapeHtml(formatWanPacketLoss(info.packet_loss))}</span>`;
        });
    }

    function ensureWanCapBadge(card) {
        let badge = card.querySelector('[data-wan-cap-badge]');
        if (badge) return badge;
        ensureWanConfigQuality(card);
        const pccLabel = card.querySelector('label:nth-of-type(2)');
        badge = document.createElement('label');
        badge.className = 'traffic-wan-cap-field';
        badge.dataset.wanCapBadge = '';
        if (pccLabel) {
            pccLabel.insertAdjacentElement('afterend', badge);
        } else {
            card.appendChild(badge);
        }
        return badge;
    }

    function renderWanCapBadges() {
        getWanCards().forEach((card) => {
            const slot = card.dataset.wanSlot || '';
            const iface = card.querySelector('[data-wan-interface]')?.value || '';
            const targetGb = Number(wanDailyTargetsGb[slot] || 0);
            const badge = ensureWanCapBadge(card);
            if (targetGb <= 0 || !iface) {
                badge.innerHTML = '';
                badge.hidden = true;
                return;
            }
            const dayBytes = Number(wanUsage[iface]?.day_bytes || 0);
            const targetBytes = targetGb * 1_000_000_000;
            const ratio = targetBytes > 0 ? dayBytes / targetBytes : 0;
            const capState = ratio >= 1 ? 'danger' : (ratio >= 0.9 ? 'warning' : 'ok');
            badge.hidden = false;
            badge.innerHTML = `<i class="fa fa-gauge-high traffic-wan-field-icon" aria-hidden="true"></i>Cible<span class="traffic-wan-cap-value"><b class="traffic-wan-cap-${capState}">${escapeHtml(formatWanBytes(dayBytes, 1))}</b> / ${escapeHtml(String(targetGb))} Go</span>`;
        });
    }

    function renderWanSummaryCards() {
        const globalLiveMax = getWanGlobalLiveMax();
        document.querySelectorAll('[data-wan-summary-slot]').forEach((summary) => {
            const slot = summary.dataset.wanSummarySlot || '';
            const title = summary.querySelector('[data-wan-summary-title]');
            const value = summary.querySelector('[data-wan-summary-value]');
            const detail = summary.querySelector('[data-wan-summary-detail]');
            const card = document.querySelector(`.traffic-wan-parameter[data-wan-slot="${CSS.escape(slot)}"]`);
            const iface = card?.querySelector('[data-wan-interface]')?.value || '';
            const info = iface ? getWanInterfaceInfo(iface) : null;
            const active = Boolean(card?.classList.contains('is-active'));
            const live = currentWanTraffic[iface] || {};
            const label = card ? wanSlotLabel(card) : slot.toUpperCase();

            if (title) title.textContent = label;
            renderWanSummarySparkline(summary, iface, globalLiveMax);
            if (!active || !iface || !info?.selectable) {
                if (value) value.textContent = '--';
                if (detail) detail.innerHTML = '<span>Réserve inactive</span><span>Aucune interface WAN valide</span>';
                summary.classList.add('is-wan-summary-disabled');
                return;
            }

            if (value) value.textContent = formatWanBytes(getWanUsageValue(iface), 1);
            if (detail) {
                detail.innerHTML = `
                    <span class="traffic-wan-rate"><span class="traffic-wan-rate-rx"><b aria-label="Download">↓</b>&nbsp;${escapeHtml(formatMbps(live.rx_rate))}</span><span class="traffic-wan-rate-tx"><b aria-label="Upload">↑</b>&nbsp;${escapeHtml(formatMbps(live.tx_rate))}</span></span>
                `;
            }
            summary.classList.remove('is-wan-summary-disabled');
        });
    }

    function applyWanCapBarBytes(progress, usedBytes, targetGb) {
        const mask = progress?.querySelector('span');
        if (!progress || !mask) return;
        if (targetGb <= 0) {
            mask.style.width = '100%';
            progress.setAttribute('aria-valuenow', '0');
            return;
        }
        const targetBytes = targetGb * 1_000_000_000;
        const ratio = targetBytes > 0 ? usedBytes / targetBytes : 0;
        const percent = Math.max(0, Math.min(100, Math.round(ratio * 100)));
        /* La piste porte le degrade fixe bleu->vert->orange->rouge ; le span cache (masque) la
           portion non atteinte depuis la droite, pour reveler progressivement la bonne couleur. */
        mask.style.width = `${100 - percent}%`;
        progress.setAttribute('aria-valuenow', String(percent));
    }

    function applyWanCapBar(progress, active, iface, targetGb) {
        if (!active || !iface) {
            applyWanCapBarBytes(progress, 0, 0);
            return;
        }
        applyWanCapBarBytes(progress, Number(wanUsage[iface]?.day_bytes || 0), targetGb);
    }

    function renderWanCapProgressBars() {
        getWanCards().forEach((card) => {
            const slot = card.dataset.wanSlot || '';
            const iface = card.querySelector('[data-wan-interface]')?.value || '';
            const targetGb = Number(wanDailyTargetsGb[slot] || 0);
            applyWanCapBar(card.querySelector('.traffic-wan-progress'), card.classList.contains('is-active'), iface, targetGb);
        });
        document.querySelectorAll('[data-wan-summary-slot]').forEach((summary) => {
            const slot = summary.dataset.wanSummarySlot || '';
            const card = document.querySelector(`.traffic-wan-parameter[data-wan-slot="${CSS.escape(slot)}"]`);
            const iface = card?.querySelector('[data-wan-interface]')?.value || '';
            const targetGb = Number(wanDailyTargetsGb[slot] || 0);
            const active = Boolean(card?.classList.contains('is-active'));
            applyWanCapBar(summary.querySelector('[data-wan-summary-cap-bar]'), active, iface, targetGb);
        });
    }

    function wanCapTargetTotals() {
        let dailyBytes = 0;
        let dailyTargetGb = 0;
        let monthlyBytes = 0;
        let monthlyTargetGb = 0;
        getWanCards().forEach((card) => {
            if (!card.classList.contains('is-active')) return;
            const slot = card.dataset.wanSlot || '';
            const iface = card.querySelector('[data-wan-interface]')?.value || '';
            if (!iface) return;
            const dTarget = Number(wanDailyTargetsGb[slot] || 0);
            if (dTarget > 0) {
                dailyBytes += Number(wanUsage[iface]?.day_bytes || 0);
                dailyTargetGb += dTarget;
            }
            const mTarget = Number(wanMonthlyTargetsGb[slot] || 0);
            if (mTarget > 0) {
                monthlyBytes += Number(wanUsage[iface]?.month_bytes || 0);
                monthlyTargetGb += mTarget;
            }
        });
        return { dailyBytes, dailyTargetGb, monthlyBytes, monthlyTargetGb };
    }

    function renderGlobalMonthlyCap() {
        const frame = document.getElementById('trafficMonthlyCapBar');
        const totals = wanCapTargetTotals();
        window.trafficWanCapTargets = {
            dailyBytes: totals.dailyTargetGb > 0 ? totals.dailyTargetGb * 1_000_000_000 : null,
            monthlyBytes: totals.monthlyTargetGb > 0 ? totals.monthlyTargetGb * 1_000_000_000 : null,
        };
        if (!frame) return;
        const valueEl = frame.querySelector('[data-monthly-cap-value]');
        const labelEl = frame.querySelector('[data-monthly-cap-label]');
        const isMonth = wanUsageViewMode === 'month';
        const usedBytes = isMonth ? totals.monthlyBytes : totals.dailyBytes;
        const targetGb = isMonth ? totals.monthlyTargetGb : totals.dailyTargetGb;

        if (targetGb <= 0) {
            frame.hidden = true;
            return;
        }
        frame.hidden = false;
        if (labelEl) labelEl.textContent = isMonth ? 'Consommation WAN cumulée / cible mensuelle cumulée' : 'Consommation WAN cumulée / cible journalière cumulée';
        if (valueEl) {
            const targetBytes = targetGb * 1_000_000_000;
            const ratio = targetBytes > 0 ? usedBytes / targetBytes : 0;
            const capState = ratio >= 1 ? 'danger' : (ratio >= 0.9 ? 'warning' : 'ok');
            valueEl.textContent = isMonth
                ? `${(usedBytes / 1_000_000_000_000).toFixed(2).replace('.', ',')} To / ${(targetGb / 1000).toFixed(2).replace('.', ',')} To`
                : `${formatWanBytes(usedBytes, 1)} / ${targetGb} Go`;
            valueEl.className = `traffic-wan-cap-${capState}`;
        }
    }

    function renderWanCards(options = {}) {
        getWanCards().forEach((card) => card.querySelector('[data-wan-metrics]')?.remove());
        if (options.updateQuality) {
            renderWanConfigQuality();
            wanConfigQualityRendered = true;
        }
        renderWanSummaryCards();
        renderWanCapBadges();
        renderWanCapProgressBars();
        renderGlobalMonthlyCap();
        renderWanUsageChart();
    }

    function applyWanCardState(card, enabled) {
        card.classList.toggle('is-active', enabled);
        card.classList.toggle('is-disabled', !enabled);
        const titleButton = card.querySelector('[data-wan-title]');
        if (titleButton) {
            titleButton.setAttribute('aria-pressed', enabled ? 'true' : 'false');
            const selectable = card.dataset.wanInterfaceSelectable !== 'false';
            titleButton.title = enabled
                ? 'Cliquer pour désactiver cette source WAN'
                : (selectable ? 'Cliquer pour activer cette source WAN' : 'Port WAN indisponible');
        }
        setWanCardEditMode(card, card.classList.contains('is-editing') && enabled);
    }

    function renderWanInterfaceSelectors(state = {}) {
        const payload = state.multi_wan_interfaces || {};
        const interfaces = Array.isArray(payload.interfaces) ? payload.interfaces : [];
        if (interfaces.length === 0) return;
        currentWanInterfaceState = state;

        const cards = getWanCards();
        const assignments = getWanSlotAssignments();
        const labelBySlot = {};
        cards.forEach((card) => {
            if (card.dataset.wanSlot) labelBySlot[card.dataset.wanSlot] = wanSlotLabel(card);
        });

        cards.forEach((card) => {
            const slot = card.dataset.wanSlot || '';
            const select = card.querySelector('[data-wan-interface]');
            const status = card.querySelector('[data-wan-interface-status]');
            if (!slot || !select) return;

            const current = select.value || wanInterfaces[slot] || select.dataset.defaultInterface || '';
            const currentInfo = interfaces.find((item) => item.interface === current);
            const duplicateSlot = Object.entries(assignments).find(([otherSlot, iface]) => otherSlot !== slot && iface === current)?.[0] || '';
            const currentValid = Boolean(currentInfo?.selectable) && duplicateSlot === '';
            const cardEnabled = card.classList.contains('is-active');

            const options = interfaces.map((item) => {
                const usedBySlot = Object.entries(assignments).find(([otherSlot, iface]) => otherSlot !== slot && iface === item.interface)?.[0] || '';
                const duplicate = usedBySlot !== '';
                const selectable = Boolean(item.selectable) && !duplicate;
                return `<option value="${escapeHtml(item.interface)}"${selectable ? '' : ' disabled'}>${escapeHtml(item.interface)}</option>`;
            });

            if (current && !interfaces.some((item) => item.interface === current)) {
                options.unshift(`<option value="${escapeHtml(current)}" disabled>${escapeHtml(current)}</option>`);
            }

            select.innerHTML = options.join('');
            select.value = current;
            if (select.value !== current && current) {
                const option = document.createElement('option');
                option.value = current;
                option.disabled = true;
                option.textContent = current;
                select.prepend(option);
                select.value = current;
            }

            const visibleReason = duplicateSlot
                ? `Conflit : déjà utilisé par ${labelBySlot[duplicateSlot] || duplicateSlot}`
                : (currentInfo?.reason || 'Interface inexistante');
            if (status) {
                status.textContent = '';
                status.title = visibleReason;
                status.classList.toggle('is-warning', !currentValid);
            }
            card.classList.toggle('has-wan-interface-warning', !currentValid);
            card.dataset.wanInterfaceSelectable = currentValid ? 'true' : 'false';
            setWanCardEditMode(card, card.classList.contains('is-editing') && cardEnabled && currentValid);
            const titleButton = card.querySelector('[data-wan-title]');
            if (titleButton && !cardEnabled) {
                titleButton.title = currentValid ? 'Cliquer pour activer cette source WAN' : 'Port WAN indisponible';
            }
        });
        renderWanCards({ updateQuality: !wanConfigQualityRendered });
    }

    function closeWanEditModal() {
        if (!wanEditModal) return;
        wanEditModal.hidden = true;
        wanEditModal.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('traffic-wan-edit-open');
        activeWanEditCard = null;
    }

    function optionHtmlFromCardSelect(select, currentValue) {
        if (!select) return '';
        const options = Array.from(select.options).map((option) => {
            const selected = option.value === currentValue ? ' selected' : '';
            const disabled = option.disabled ? ' disabled' : '';
            return `<option value="${escapeHtml(option.value)}"${selected}${disabled}>${escapeHtml(option.textContent || option.value)}</option>`;
        });
        if (currentValue && !Array.from(select.options).some((option) => option.value === currentValue)) {
            options.unshift(`<option value="${escapeHtml(currentValue)}" selected disabled>${escapeHtml(currentValue)}</option>`);
        }
        return options.join('');
    }

    function openWanEditModal(card, index) {
        if (!wanEditModal || !card) return;
        activeWanEditCard = card;
        const slot = card.dataset.wanSlot || `wan${index + 1}`;
        const titleButton = card.querySelector('[data-wan-title]');
        const interfaceSelect = card.querySelector('[data-wan-interface]');
        const pccInput = card.querySelector('.traffic-wan-percent-field input');
        const title = titleButton?.textContent?.trim() || `WAN ${index + 1}`;
        const iface = interfaceSelect?.value || wanInterfaces[slot] || '';
        const pcc = pccInput?.value ?? '0';

        if (wanEditTitleInput) wanEditTitleInput.value = title;
        if (wanEditInterfaceSelect) {
            wanEditInterfaceSelect.innerHTML = optionHtmlFromCardSelect(interfaceSelect, iface);
            wanEditInterfaceSelect.value = iface;
        }
        if (wanEditPccInput) wanEditPccInput.value = String(Math.min(100, Math.max(0, Number(pcc || 0))));
        if (wanEditDailyTargetInput) wanEditDailyTargetInput.value = String(Math.max(0, Number(wanDailyTargetsGb[slot] || 0)));
        if (wanEditMonthlyTargetInput) wanEditMonthlyTargetInput.value = String(Math.max(0, Number(wanMonthlyTargetsGb[slot] || 0)));
        if (wanEditHelp) {
            const info = iface ? getWanInterfaceInfo(iface) : null;
            wanEditHelp.textContent = info?.selectable === false
                ? 'Interface actuellement indisponible : choisissez un port WAN valide avant activation.'
                : 'Le poids saisi sera envoyé à RouterOS et appliqué immédiatement.';
        }

        wanEditModal.hidden = false;
        wanEditModal.setAttribute('aria-hidden', 'false');
        document.body.classList.add('traffic-wan-edit-open');
        window.setTimeout(() => wanEditTitleInput?.focus(), 30);
    }

    async function saveWanEditModal() {
        if (!activeWanEditCard) return;
        const card = activeWanEditCard;
        const key = card.dataset.wanSlot || '';
        const index = Math.max(0, getWanCards().indexOf(card));
        const titleButton = card.querySelector('[data-wan-title]');
        const interfaceSelect = card.querySelector('[data-wan-interface]');
        const title = String(wanEditTitleInput?.value || '').trim();
        const iface = String(wanEditInterfaceSelect?.value || '').trim();
        const pcc = String(Math.min(100, Math.max(0, Number(wanEditPccInput?.value || 0))));
        const dailyTargetGb = String(Math.floor(Number(wanEditDailyTargetInput?.value || 0)));
        const monthlyTargetGb = String(Math.floor(Number(wanEditMonthlyTargetInput?.value || 0)));

        if (!(Number(dailyTargetGb) >= 1)) {
            AppToast.flash('Cible quotidienne requise (1 Go minimum).', 'warning');
            wanEditDailyTargetInput?.focus();
            return;
        }
        if (!(Number(monthlyTargetGb) >= 1)) {
            AppToast.flash('Cible mensuelle requise (1 Go minimum).', 'warning');
            wanEditMonthlyTargetInput?.focus();
            return;
        }

        if (!title) {
            AppToast.flash('Titre WAN requis.', 'warning');
            wanEditTitleInput?.focus();
            return;
        }
        if (!iface) {
            AppToast.flash('Interface WAN requise.', 'warning');
            wanEditInterfaceSelect?.focus();
            return;
        }

        try {
            if (wanEditSaveBtn) wanEditSaveBtn.disabled = true;
            const data = await requestQueueControl({ action: 'save_multi_wan_source', slot: key, interface: iface, label: title, weight: pcc }, { [key]: iface });
            const targetData = await requestQueueControl({ action: 'save_wan_daily_target', slot: key, daily_target_gb: dailyTargetGb, monthly_target_gb: monthlyTargetGb });
            if (key) {
                wanDailyTargetsGb[key] = Number(dailyTargetGb || 0);
                wanMonthlyTargetsGb[key] = Number(monthlyTargetGb || 0);
            }
            if (titleButton) {
                titleButton.textContent = title;
                titleButton.setAttribute('aria-label', `Source WAN ${index + 1} : ${title}`);
            }
            if (interfaceSelect) interfaceSelect.value = iface;
            if (key) {
                wanTitles[key] = title;
                wanInterfaces[key] = iface;
            }
            persistWanTitles();
            persistWanInterfaces();
            if (targetData.state || data.state) renderInterfaceQueues(targetData.state || data.state);
            if (currentWanInterfaceState) renderWanInterfaceSelectors(currentWanInterfaceState);
            loadInterfaceQueues();
            renderWanCards();
            validateWanPccWeights({ notify: true });
            closeWanEditModal();
            AppToast.flash(targetData.message || data.message || 'Paramètres WAN enregistrés côté serveur.', 'success');
        } catch (error) {
            showError(error.message || 'Enregistrement WAN impossible.');
        } finally {
            if (wanEditSaveBtn) wanEditSaveBtn.disabled = false;
        }
    }

    function initWanParameterCards() {
        getWanCards().forEach((card, index) => {
            const key = card.dataset.wanSlot || `wan${index + 1}`;
            const titleButton = card.querySelector('[data-wan-title]');
            const editButton = card.querySelector('.traffic-wan-title-edit');
            const interfaceSelect = card.querySelector('[data-wan-interface]');
            const pccInput = card.querySelector('.traffic-wan-percent-field input');
            const defaultTitle = titleButton?.textContent?.trim() || `WAN ${index + 1}`;
            const enabled = Object.prototype.hasOwnProperty.call(wanStates, key) ? Boolean(wanStates[key]) : card.classList.contains('is-active');
            if (!enabled && !card.dataset.wanInterfaceSelectable) {
                card.dataset.wanInterfaceSelectable = 'false';
            }
            if (interfaceSelect) {
                interfaceSelect.value = wanInterfaces[key] || interfaceSelect.dataset.defaultInterface || interfaceSelect.value || '';
                interfaceSelect.addEventListener('change', () => {
                    wanInterfaces[key] = interfaceSelect.value;
                    persistWanInterfaces();
                    if (currentWanInterfaceState) renderWanInterfaceSelectors(currentWanInterfaceState);
                    loadInterfaceQueues();
                });
            }

            if (titleButton) {
                titleButton.textContent = wanTitles[key] || defaultTitle;
                titleButton.addEventListener('click', async () => {
                    const previousEnabled = card.classList.contains('is-active');
                    const nextEnabled = !previousEnabled;
                    if (nextEnabled && card.dataset.wanInterfaceSelectable === 'false' && card.dataset.wanSourceManagedDisabled !== 'true') {
                        AppToast.flash('Port WAN indisponible.', 'warning');
                        return;
                    }
                    wanStates[key] = nextEnabled;
                    applyWanCardState(card, nextEnabled);
                    persistWanStates();
                    renderWanCards();
                    try {
                        const selectedInterface = interfaceSelect?.value || '';
                        if (!selectedInterface) throw new Error('Interface WAN non sélectionnée.');
                        const data = await requestQueueControl({ action: 'update_multi_wan_source', slot: key, interface: selectedInterface, enabled: nextEnabled });
                        renderInterfaceQueues(data.state || {});
                        AppToast.flash(data.message || 'État source WAN enregistré.', 'success');
                    } catch (error) {
                        wanStates[key] = previousEnabled;
                        applyWanCardState(card, previousEnabled);
                        persistWanStates();
                        renderWanCards();
                        showError(error.message);
                    }
                });
            }

            if (editButton && titleButton) {
                editButton.addEventListener('click', (event) => {
                    event.preventDefault();
                    event.stopPropagation();
                    openWanEditModal(card, index);
                });
            }

            applyWanCardState(card, enabled);
            setWanCardEditMode(card, false);
        });
    }

    function updateTrafficSourceSummary() {
        if (!trafficSourceGrid || !trafficSourcesSummary) return;
        const items = [...trafficSourceGrid.querySelectorAll('[data-source-slot]')];
        const activeItems = items.filter((item) => item.classList.contains('is-active'));
        const active = activeItems.length;
        const totalWeight = activeItems.reduce((sum, item) => sum + Number(item.querySelector('[data-source-weight-input]')?.value || 0), 0);
        const ratio = activeItems.map((item) => {
            const weight = Number(item.querySelector('[data-source-weight-input]')?.value || 0);
            const name = item.querySelector('strong')?.textContent || 'Source';
            return `${name} ${totalWeight > 0 ? Math.round(weight / totalWeight * 100) : 0}%`;
        }).join(' · ');
        trafficSourcesSummary.textContent = `${active} active${active > 1 ? 's' : ''} · ${ratio || 'aucune répartition'}`;
        if (trafficSourcesActiveKpi) trafficSourcesActiveKpi.textContent = '--';
        if (trafficSourcesPccKpi) trafficSourcesPccKpi.textContent = '0 o';
    }

    function initTrafficSourceToggles() {
        if (!trafficSourceGrid) return;
        trafficSourceGrid.querySelectorAll('[data-source-toggle]').forEach((toggle) => {
            toggle.addEventListener('click', () => {
                const item = toggle.closest('[data-source-slot]');
                const active = !item.classList.contains('is-active');
                item.classList.toggle('is-active', active);
                toggle.setAttribute('aria-pressed', active ? 'true' : 'false');
                const state = toggle.querySelector('[data-source-state]');
                if (state) state.textContent = active ? 'Allumée' : 'Éteinte';
                updateTrafficSourceSummary();
            });
        });
        trafficSourceGrid.querySelectorAll('[data-source-weight-input]').forEach((input) => {
            input.addEventListener('input', () => {
                const value = Math.min(100, Math.max(0, Number(input.value || 0)));
                input.value = String(value);
                input.closest('[data-source-slot]')?.setAttribute('data-source-weight', String(value));
                updateTrafficSourceSummary();
            });
        });
        trafficSourceGrid.querySelectorAll('[data-source-interface]').forEach((select) => {
            select.addEventListener('change', () => {
                const item = select.closest('[data-source-slot]');
                const label = item?.querySelector('small');
                if (label) label.textContent = `${select.value} · sélection locale`;
            });
        });
        updateTrafficSourceSummary();
    }

    function updateWanSourceStats(data) {
        if (!trafficSourceStatsBody || !trafficSourceGrid) return;
        const stats = data?.interfaces && typeof data.interfaces === 'object' ? data.interfaces : {};
        const rows = [...trafficSourceGrid.querySelectorAll('[data-source-slot]')].map((item, index) => {
            const select = item.querySelector('[data-source-interface]');
            const input = item.querySelector('[data-source-weight-input]');
            const name = item.querySelector('strong')?.textContent || `Source ${index + 1}`;
            const iface = select?.value || '';
            const current = stats[iface] || {};
            const active = item.classList.contains('is-active');
            return `<tr><td>${index + 1}</td><td>${escapeHtml(name)}</td><td><code>${escapeHtml(iface || '—')}</code></td><td><span class="badge text-bg-${active ? 'success' : 'secondary'}">${active ? 'Allumée' : 'Éteinte'}</span></td><td>${escapeHtml(input?.value || '0')}%</td><td>${formatBits(Number(current.inbytes || 0)) || '0 b'}</td><td>${formatBits(Number(current.outbytes || 0)) || '0 b'}</td></tr>`;
        });
        const activeItems = [...trafficSourceGrid.querySelectorAll('[data-source-slot].is-active')];
        const activeStats = activeItems.map((item) => stats[item.querySelector('[data-source-interface]')?.value || ''] || {});
        if (trafficSourcesRxKpi) trafficSourcesRxKpi.textContent = formatBits(activeStats.reduce((sum, item) => sum + Number(item.inbytes || 0), 0)) || '0 b';
        if (trafficSourcesTxKpi) trafficSourcesTxKpi.textContent = formatBits(activeStats.reduce((sum, item) => sum + Number(item.outbytes || 0), 0)) || '0 b';
        if (trafficSourcesActiveKpi) trafficSourcesActiveKpi.textContent = formatBits(activeStats.reduce((sum, item) => sum + Number(item.inbytes || 0) + Number(item.outbytes || 0), 0)) || '0 b';
        const samples = document.getElementById('trafficSourcesSamples');
        if (samples) samples.textContent = `Sources actives : ${activeItems.length}`;
        trafficSourceStatsBody.innerHTML = rows.join('');
    }

    initWanParameterCards();
    syncUsagePeriodControls();
    document.querySelectorAll('[data-usage-chart-mode]').forEach((button) => {
        button.addEventListener('click', () => {
            wanUsageViewMode = button.dataset.usageChartMode === 'month' ? 'month' : 'day';
            syncUsagePeriodControls();
            window.setTimeout(renderWanCards, 0);
        });
    });
    document.getElementById('trafficUsageCycleStart')?.addEventListener('change', () => {
        wanUsageViewMode = 'month';
        syncUsagePeriodControls();
        window.setTimeout(renderWanCards, 0);
    });
    usageDateInput?.addEventListener('change', () => {
        wanUsageViewMode = 'day';
        syncUsagePeriodControls();
        loadInterfaceQueues();
    });
    initTrafficSourceToggles();

    function escapeHtml(value) {
        return String(value ?? '')
            .replaceAll('&', '&amp;')
            .replaceAll('<', '&lt;')
            .replaceAll('>', '&gt;')
            .replaceAll('"', '&quot;')
            .replaceAll("'", '&#39;');
    }

    function setAlpha(color, opacity) {
        const op = Math.round(Math.min(Math.max(opacity || 1, 0), 1) * 255);
        return color + op.toString(16).toUpperCase().padStart(2, '0');
    }

    function formatField(value, decimals = 2, bits = false) {
        const numeric = Number(value);
        if (!Number.isFinite(numeric) || numeric <= 0) {
            return '';
        }

        // Mêmes libellés que traffic_listener.js (formatBytes) : o / Ko / Mo / Go / To, b pour les débits.
        const units = ['', 'K', 'M', 'G', 'T', 'P'];
        const power = Math.floor(Math.log(numeric) / Math.log(1000));
        const safePower = Math.max(0, Math.min(power, units.length - 1));
        const suffix = bits ? 'b' : 'o';

        if (safePower > 0) {
            return `${(numeric / Math.pow(1000, safePower)).toFixed(decimals)} ${units[safePower]}${suffix}`;
        }

        return `${numeric.toFixed(decimals)} ${suffix}`;
    }

    function formatBits(value, decimals = 2) {
        return formatField(value, decimals, true);
    }

    function showError(message) {
        AppToast.flash(message, 'danger');
        if (!messageArea) {
            return;
        }

        messageArea.innerHTML = `<div class="alert alert-danger" role="alert">${escapeHtml(message)}</div>`;
        messageArea.style.display = 'block';
    }

    function hideError() {
        if (!messageArea) {
            return;
        }

        messageArea.innerHTML = '';
        messageArea.style.display = 'none';
    }

    function routerBoolean(value) {
        return value === true || value === 'true' || value === 'yes';
    }

    function splitRouterRate(value) {
        return String(value || '').split('/').map((part) => {
            const match = part.match(/^([0-9.]+)([kKmMgG]?)$/);
            if (!match) return '';
            const multiplier = { k: 1e3, m: 1e6, g: 1e9 }[match[2].toLowerCase()] || 1;
            return String(Math.round((Number(match[1]) * multiplier / 1e6) * 100) / 100);
        });
    }

    async function requestQueueControl(payload = null, assignmentOverrides = {}) {
        const options = { headers: { Accept: 'application/json' }, credentials: 'same-origin' };
        const slotAssignments = getWanSlotAssignments(assignmentOverrides);
        const statusParams = new URLSearchParams({
            device_id: controlDeviceId,
            action: 'status',
            wan_slots: JSON.stringify(slotAssignments),
            usage_day: usageDateInput?.value || dateKey(new Date()),
        });
        let url = `${TRAFFIC_CONTROL_PATH}?${statusParams.toString()}`;
        if (payload) {
            url = TRAFFIC_CONTROL_PATH;
            options.method = 'POST';
            options.headers['Content-Type'] = 'application/json';
            options.body = JSON.stringify({ ...payload, device_id: controlDeviceId, csrf_token: controlCsrf, confirm: true, wan_slots: JSON.stringify(slotAssignments) });
        }
        const response = await fetch(url, options);
        const data = await response.json().catch(() => ({ success: false, message: 'Réponse RouterOS invalide.' }));
        if (!response.ok || !data.success) throw new Error(data.message || `Erreur HTTP ${response.status}`);
        return data;
    }

    function applyMultiWanServerConfig(state = {}) {
        const sources = state.multi_wan_config?.sources;
        if (!sources || typeof sources !== 'object') return;
        getWanCards().forEach((card) => {
            const slot = card.dataset.wanSlot || '';
            if (!slot || !Object.prototype.hasOwnProperty.call(sources, slot)) return;
            if (!Object.prototype.hasOwnProperty.call(sources[slot] || {}, 'enabled')) return;
            const interfaceSelect = card.querySelector('[data-wan-interface]');
            const configuredInterface = String(sources[slot]?.interface || '').trim();
            if (configuredInterface !== '' && interfaceSelect) {
                if (!Array.from(interfaceSelect.options).some((option) => option.value === configuredInterface)) {
                    const option = document.createElement('option');
                    option.value = configuredInterface;
                    option.textContent = configuredInterface;
                    option.disabled = true;
                    interfaceSelect.prepend(option);
                }
                interfaceSelect.value = configuredInterface;
            }
            const enabled = Boolean(sources[slot].enabled);
            card.dataset.wanSourceManagedDisabled = enabled ? 'false' : 'true';
            wanStates[slot] = enabled;
            if (sources[slot].label) wanTitles[slot] = String(sources[slot].label);
            if (sources[slot].interface) wanInterfaces[slot] = String(sources[slot].interface);
            wanDailyTargetsGb[slot] = Number(sources[slot].daily_target_gb || 0);
            wanMonthlyTargetsGb[slot] = Number(sources[slot].monthly_target_gb || 0);
            if (Object.prototype.hasOwnProperty.call(sources[slot], 'weight')) {
                const pccInput = card.querySelector('.traffic-wan-percent-field input');
                if (pccInput) pccInput.value = String(sources[slot].weight);
            }
            applyWanCardState(card, enabled);
        });
        persistWanStates();
        persistWanTitles();
        persistWanInterfaces();
    }

    function renderInterfaceQueues(state = {}) {
        if (Object.prototype.hasOwnProperty.call(state, 'multi_wan_usage')) {
            wanUsage = normalizeWanUsage(state.multi_wan_usage || {});
        }
        applyMultiWanServerConfig(state);
        renderWanInterfaceSelectors(state);
        renderWanSummaryCards();
        renderWanCapBadges();
        renderWanCapProgressBars();
        renderGlobalMonthlyCap();
        if (!queueInterface) return;
        const queueState = state.interface_queues || {};
        const interfaces = Array.isArray(queueState.hotspot_bridges) ? queueState.hotspot_bridges : [];
        const managed = Array.isArray(queueState.managed) ? queueState.managed : [];
        const selected = queueInterface.value || interfaces[0]?.name || '';
        queueInterface.innerHTML = interfaces.map((item) => `<option value="${escapeHtml(item.name)}">${escapeHtml(item.name)} · Hotspot ${escapeHtml((item.hotspot_servers || []).join(', ') || 'actif')}</option>`).join('') || '<option value="">Aucun bridge Hotspot actif</option>';
        if (interfaces.some((item) => item.name === selected)) queueInterface.value = selected;
        const row = managed.find((item) => String(item.comment || '') === `Radius Manager - interface queue:${queueInterface.value}`);
        const rates = splitRouterRate(row?.['max-limit']);
        queueUpload.value = rates[0] || '';
        queueDownload.value = rates[1] || '';
        queuePriority.value = String(row?.priority || '8').split('/')[0];
        const disabled = row ? routerBoolean(row.disabled) : true;
        queueToggle.disabled = !row;
        queueToggle.dataset.nextEnabled = disabled ? 'true' : 'false';
        queueToggle.className = `btn btn-outline-${disabled ? 'success' : 'warning'} btn-sm traffic-queue-action`;
        queueToggle.innerHTML = `<i class="fa fa-power-off me-1"></i>${disabled ? 'Activer' : 'Désactiver'}`;
        interfaceQueueState.textContent = row
            ? `${disabled ? 'Désactivée' : (routerBoolean(row.invalid) ? 'Invalide' : 'Active')} · parent ${row.hotspot_parent_profiles || 0}/${row.hotspot_limited_profiles || 0} · files invalides ${queueState.dynamic_invalid_count || 0}`
            : `${queueState.dynamic_hotspot_count || 0} file(s) Hotspot · aucune limite gérée`;
        interfaceQueueState.className = `badge text-bg-${row?.healthy ? 'success' : (row && routerBoolean(row.invalid) ? 'danger' : 'secondary')}`;
    }

    async function loadInterfaceQueues() {
        if (!queueForm || !controlDeviceId) return;
        try {
            const data = await requestQueueControl();
            renderInterfaceQueues(data.state || {});
            const targetInterfaces = getWanFilterInterfaces();
            if (targetInterfaces && targetInterfaces !== lastWanFilterInterfaces) {
                lastWanFilterInterfaces = targetInterfaces;
                initializeMonitoring(targetInterfaces).catch((error) => showError(error.message));
            }
        } catch (error) {
            interfaceQueueState.textContent = 'Lecture impossible';
            interfaceQueueState.className = 'badge text-bg-danger';
            showError(error.message);
        }
    }

    function formatDomainTimestamp(value) {
        const raw = String(value || '').trim();
        if (raw === '') {
            return '-';
        }

        return raw.length >= 19 ? raw.substring(11, 19) : raw;
    }

    function renderDomainRows() {
        if (!domainActivityTableBody) {
            return;
        }

        const query = String(domainActivitySearch?.value || '').trim().toLowerCase();
        const visibleRows = domainRows.filter((row) => {
            const haystack = [
                row.time,
                row.client,
                row.domain,
                row.type,
                row.action,
                row.source,
                row.rcode,
                row.blocklist,
            ]
                .join(' ')
                .toLowerCase();

            return query === '' || haystack.includes(query);
        });

        if (visibleRows.length === 0) {
            domainActivityTableBody.innerHTML = '<tr><td colspan="8" class="text-center text-white-50">Aucune activité domaine ne correspond au filtre.</td></tr>';
            return;
        }

        domainActivityTableBody.innerHTML = visibleRows.map((row) => `
            <tr>
                <td>${escapeHtml(formatDomainTimestamp(row.time))}</td>
                <td>${escapeHtml(row.client || '-')}</td>
                <td>${escapeHtml(row.domain || '-')}</td>
                <td>${escapeHtml(row.type || '-')}</td>
                <td>${escapeHtml(row.action || '-')}</td>
                <td>${escapeHtml(row.source || '-')}</td>
                <td>${escapeHtml(row.rcode || '-')}</td>
                <td>${escapeHtml(row.blocklist || '-')}</td>
            </tr>
        `).join('');
    }

    async function refreshDomainActivity() {
        if (trafficSourceType !== 'OPNSENSE' || !domainActivityTableBody) {
            return;
        }

        try {
            const response = await fetch(DOMAIN_ACTIVITY_PATH, {
                headers: {
                    Accept: 'application/json',
                },
                credentials: 'same-origin',
            });

            const data = await response.json().catch(() => ({
                success: false,
                message: 'Réponse domaines invalide.',
            }));

            if (!response.ok || !data.success) {
                throw new Error(data.message || 'Lecture des domaines OPNsense impossible.');
            }

            domainRows = Array.isArray(data.queries) ? data.queries : [];

            if (domainQueriesCount) {
                domainQueriesCount.textContent = String(domainRows.length);
            }
            if (domainBlockedCount) {
                domainBlockedCount.textContent = String(data.totals?.total_blocked ?? 0);
            }
            if (domainLastUpdate) {
                domainLastUpdate.textContent = new Date().toLocaleTimeString('fr-FR', { hour12: false });
            }

            renderDomainRows();
        } catch (error) {
            domainActivityTableBody.innerHTML = `<tr><td colspan="8" class="text-center text-warning">${escapeHtml(error.message || 'Lecture des domaines OPNsense impossible.')}</td></tr>`;
        }
    }

    function formatSourceLegend(metricMode) {
        const details = `${metricMode === 'rate' ? 'Direct' : 'Delta'} · ${formatRefreshLabel(selectedRefreshMs)}`;
        return `<span class="traffic-interface-legend-details">Légendes dans chaque courbe · ${details}</span>`;
    }

    function populateInterfaceSelect(selectElement, options, selectedValues, allowEmpty = false) {
        if (!selectElement) {
            return;
        }

        const interfaces = Array.isArray(options) ? options : [];
        const selected = Array.isArray(selectedValues) ? selectedValues : [selectedValues];
        if (interfaces.length === 0) {
            selectElement.innerHTML = '<option value="">Aucune interface</option>';
            selectElement.disabled = true;
            return;
        }

        const emptyOption = allowEmpty ? '<option value="">Aucune</option>' : '';
        selectElement.innerHTML = emptyOption + interfaces.map((item) => {
            const name = String(item.name || '');
            const label = String(item.label || name);
            const suffix = item.running ? '' : ' (hors ligne)';
            const isSelected = selected.includes(name) ? ' selected' : '';
            return `<option value="${escapeHtml(name)}"${isSelected}>${escapeHtml(label + suffix)}</option>`;
        }).join('');
        selectElement.disabled = false;
    }

    function setInterfaceSelectError(label = 'Indisponible') {
        if (!trafficInterfaceSelect && !trafficInterfaceSelect2) {
            return;
        }

        [trafficInterfaceSelect, trafficInterfaceSelect2].forEach((selectElement) => {
            if (selectElement) {
                selectElement.innerHTML = `<option value="">${escapeHtml(label)}</option>`;
                selectElement.disabled = true;
            }
        });
    }

    function getTrafficInterfaces(data) {
        const interfaces = data?.interfaces && typeof data.interfaces === 'object' ? data.interfaces : {};
        const selected = Array.isArray(data?.selected_interfaces) && data.selected_interfaces.length > 0
            ? data.selected_interfaces
            : currentInterfaces.length > 0 ? currentInterfaces : [data?.selected_interface || currentInterface];
        const entries = selected.map((requested, index) => {
            const entry = Object.entries(interfaces).find(([key, stats]) => (
                key === requested || String(stats?.name || '') === requested
            ));
            if (!entry) return null;
            const role = currentInterfaceRoles[entry[0]] || 'WAN';
            return { key: entry[0], stats: entry[1], role };
        }).filter(Boolean);
        if (entries.length > 0) {
            return entries;
        }
        const firstEntry = Object.entries(interfaces)[0];
        return firstEntry ? [{ key: firstEntry[0], stats: firstEntry[1], role: 'WAN' }] : [];
    }

    function buildInterfaceOptions(data) {
        if (Array.isArray(data?.interface_options) && data.interface_options.length > 0) {
            return data.interface_options;
        }

        return Object.entries(data?.interfaces || {}).map(([name, stats]) => ({
            name,
            label: stats?.name || name,
            running: true,
        }));
    }

    function formatRefreshLabel(valueMs) {
        if (valueMs >= 60000) {
            return `${Math.round(valueMs / 60000)}m`;
        }
        return `${Math.round(valueMs / 1000)}s`;
    }

    function buildTrafficChartConfig(datasets, formatter) {
        const realtimeDuration = Math.max(TRAFFIC_DURATION_MS, selectedRefreshMs * 8);
        const realtimeDelay = Math.max(TRAFFIC_DELAY_MS, Math.round(selectedRefreshMs * 1.1));
        const realtimeRefresh = Math.max(1000, Math.round(selectedRefreshMs));

        return {
            type: 'line',
            data: {
                datasets,
            },
            options: {
                maintainAspectRatio: false,
                responsive: true,
                normalized: true,
                animation: false,
                elements: {
                    line: {
                        fill: true,
                        cubicInterpolationMode: 'monotone',
                        clip: 0,
                    },
                },
                layout: {
                    padding: {
                        left: 8,
                        right: 10,
                        top: 2,
                        bottom: 0,
                    },
                },
                scales: {
                    x: {
                        display: false,
                        type: 'realtime',
                        realtime: {
                            duration: realtimeDuration,
                            delay: realtimeDelay,
                            refresh: realtimeRefresh,
                        },
                        time: {
                            tooltipFormat: 'HH:mm:ss',
                            unit: 'second',
                            stepSize: 10,
                            minUnit: 'second',
                            displayFormats: {
                                second: 'HH:mm:ss',
                                minute: 'HH:mm:ss',
                            },
                        },
                    },
                    y: {
                        grace: '3%',
                        ticks: {
                            color: '#88A5C2',
                            callback(value) {
                                return formatter(value);
                            },
                        },
                        grid: {
                            color: 'rgba(96, 128, 160, 0.18)',
                        },
                    },
                },
                plugins: {
                    legend: {
                        display: false,
                    },
                    tooltip: {
                        mode: 'nearest',
                        intersect: false,
                        callbacks: {
                            label(context) {
                                const point = context.dataset.data[context.dataIndex];
                                return `${context.dataset.label}: ${formatter(point?.y ?? 0) || '0'}`;
                            },
                        },
                    },
                    streaming: {
                        frameRate: 30,
                        ttl: Math.max(30000, realtimeDuration + realtimeDelay + realtimeRefresh),
                    },
                    colorschemes: false,
                },
            },
        };
    }

    function destroyTrafficCharts() {
        if (trafficCharts.trafficIn) {
            trafficCharts.trafficIn.destroy();
        }
        if (trafficCharts.trafficOut) {
            trafficCharts.trafficOut.destroy();
        }

        trafficCharts = {
            trafficIn: null,
            trafficOut: null,
        };
    }

    function renderTrafficLegend(element, chart) {
        if (!element || !chart) {
            return;
        }

        element.innerHTML = chart.data.datasets.map((dataset) => `
            <span class="traffic-chart-legend-item">
                <span class="traffic-chart-legend-line" style="background:${dataset.borderColor};box-shadow:0 0 5px ${dataset.borderColor};"></span>
                <span>${escapeHtml(dataset.label)}</span>
            </span>
        `).join('');
    }

    function formatSeriesValue(pointY, formatter) {
        return formatter === 'bits' ? (formatBits(pointY) || '0 b') : '0';
    }

    function buildTrafficHeaderValue(chart) {
        if (!chart) {
            return '--';
        }

        const dataset = chart.data.datasets[0];
        if (!dataset || dataset.data.length === 0) {
            return '--';
        }

        const point = dataset.data[dataset.data.length - 1];
        return `<span class="traffic-header-series" style="--traffic-series:${dataset.borderColor}; color:${dataset.borderColor};"><span class="traffic-header-metric">${escapeHtml(formatSeriesValue(point?.y ?? 0, dataset.formatter))}</span></span>`;
    }

    function updateTrafficCard(chart, valueElement, gapElement) {
        if (!chart) return;
        const entries = chart.data.datasets.map((dataset) => {
            const point = dataset.data[dataset.data.length - 1];
            return {
                label: dataset.label,
                value: Number(point?.y ?? 0),
                formatted: formatSeriesValue(point?.y ?? 0, dataset.formatter),
                color: dataset.borderColor,
            };
        });
        if (valueElement) {
            valueElement.innerHTML = entries.map((entry) => {
                const icon = entry.label.includes('RX') ? 'fa-arrow-down' : 'fa-arrow-up';
                return `<div class="traffic-card-value-line" style="color:${entry.color}"><i class="fa ${icon} traffic-card-value-icon"></i>${escapeHtml(entry.formatted)}</div>`;
            }).join('') || '--';
        }
        if (gapElement) {
            const gap = entries.length >= 2 ? Math.abs(entries[0].value - entries[1].value) : null;
            gapElement.innerHTML = gap === null
                ? '<i class="fa fa-arrows-left-right me-1"></i>Écart : --'
                : `<i class="fa fa-arrows-left-right me-1"></i>Écart : ${formatBits(gap) || '0 b'}`;
        }
    }

    function updateTrafficDataCounter(chart, element) {
        if (!element || !chart) return;
        element.innerHTML = chart.data.datasets.map((dataset) => {
            const totalBits = dataset.data.reduce((sum, point) => sum + Math.max(0, Number(point?.y || 0)), 0) * (selectedRefreshMs / 1000);
            return `<div class="traffic-data-counter-line" style="color:${dataset.borderColor}"><i class="fa fa-database traffic-data-icon me-1"></i>${escapeHtml(String(dataset.intf).toUpperCase())} : ${escapeHtml(formatField(totalBits / 8, 2, false))}</div>`;
        }).join('') || 'Données : --';
    }

    function updateTrafficHeaderValues() {
        const downloadHtml = buildTrafficHeaderValue(trafficCharts.trafficIn);
        const uploadHtml = buildTrafficHeaderValue(trafficCharts.trafficOut);

        if (downloadRateLive) {
            downloadRateLive.innerHTML = downloadHtml;
        }
        if (uploadRateLive) {
            uploadRateLive.innerHTML = uploadHtml;
        }
        if (downloadRateMirror) {
            downloadRateMirror.innerText = downloadRateLive?.textContent?.trim() || '--';
        }
        if (uploadRateMirror) {
            uploadRateMirror.innerText = uploadRateLive?.textContent?.trim() || '--';
        }
        updateTrafficCard(trafficCharts.trafficIn, downloadRateMirror, downloadGapMirror);
        updateTrafficCard(trafficCharts.trafficOut, uploadRateMirror, uploadGapMirror);
        updateTrafficDataCounter(trafficCharts.trafficIn, downloadDataCounter);
        updateTrafficDataCounter(trafficCharts.trafficOut, uploadDataCounter);
    }

    function initializeTraffic(data) {
        if (typeof Chart === 'undefined') {
            throw new Error('Chart.js indisponible');
        }

        const selectedInterfaces = getTrafficInterfaces(data);
        if (selectedInterfaces.length === 0) {
            throw new Error('Aucune interface disponible pour la surveillance trafic');
        }

        const interfaceLabel = data.interface_label || selectedInterfaces.map((item) => item.stats.name || item.key).join(', ');
        const metricMode = String(data.metric_mode || 'delta');

        currentInterfaces = Array.isArray(data.selected_interfaces) && data.selected_interfaces.length > 0
            ? data.selected_interfaces
            : selectedInterfaces.map((item) => item.key);
        currentInterfaceRoles = selectedInterfaces.reduce((roles, item, index) => {
            roles[item.key] = item.role || 'WAN';
            return roles;
        }, currentInterfaceRoles);
        currentInterface = currentInterfaces[0] || interfaceLabel;

        const interfaceOptions = buildInterfaceOptions(data);
        populateInterfaceSelect(trafficInterfaceSelect, interfaceOptions, currentInterfaces[0] || '');
        populateInterfaceSelect(trafficInterfaceSelect2, interfaceOptions, currentInterfaces[1] || '', true);
        const uploadDatasets = selectedInterfaces.flatMap((interfaceItem, interfaceIndex) => TRAFFIC_SERIES.upload.map(series => ({
            label: `${String(interfaceItem.key).toUpperCase()} · ${interfaceItem.role === 'WAN' ? 'TX' : 'RX'}`,
            borderColor: TRAFFIC_INTERFACE_COLORS[interfaceIndex % TRAFFIC_INTERFACE_COLORS.length],
            backgroundColor: setAlpha(TRAFFIC_INTERFACE_COLORS[interfaceIndex % TRAFFIC_INTERFACE_COLORS.length], 0.22),
            pointHoverBackgroundColor: TRAFFIC_INTERFACE_COLORS[interfaceIndex % TRAFFIC_INTERFACE_COLORS.length],
            pointHoverBorderColor: TRAFFIC_INTERFACE_COLORS[interfaceIndex % TRAFFIC_INTERFACE_COLORS.length],
            pointBackgroundColor: TRAFFIC_INTERFACE_COLORS[interfaceIndex % TRAFFIC_INTERFACE_COLORS.length],
            pointBorderColor: TRAFFIC_INTERFACE_COLORS[interfaceIndex % TRAFFIC_INTERFACE_COLORS.length],
            pointRadius: 0,
            borderWidth: 2,
            spanGaps: Math.max(15000, selectedRefreshMs * 2),
            intf: interfaceItem.key,
            last_time: Number(data.time ?? 0),
            src_field: interfaceItem.role === 'WAN' ? 'outbytes' : 'inbytes',
            formatter: series.formatter,
            metric_mode: metricMode,
            data: [],
        })));

        const downloadDatasets = selectedInterfaces.flatMap((interfaceItem, interfaceIndex) => TRAFFIC_SERIES.download.map(series => ({
            label: `${String(interfaceItem.key).toUpperCase()} · ${interfaceItem.role === 'WAN' ? 'RX' : 'TX'}`,
            borderColor: TRAFFIC_INTERFACE_COLORS[interfaceIndex % TRAFFIC_INTERFACE_COLORS.length],
            backgroundColor: setAlpha(TRAFFIC_INTERFACE_COLORS[interfaceIndex % TRAFFIC_INTERFACE_COLORS.length], 0.22),
            pointHoverBackgroundColor: TRAFFIC_INTERFACE_COLORS[interfaceIndex % TRAFFIC_INTERFACE_COLORS.length],
            pointHoverBorderColor: TRAFFIC_INTERFACE_COLORS[interfaceIndex % TRAFFIC_INTERFACE_COLORS.length],
            pointBackgroundColor: TRAFFIC_INTERFACE_COLORS[interfaceIndex % TRAFFIC_INTERFACE_COLORS.length],
            pointBorderColor: TRAFFIC_INTERFACE_COLORS[interfaceIndex % TRAFFIC_INTERFACE_COLORS.length],
            pointRadius: 0,
            borderWidth: 2,
            spanGaps: Math.max(15000, selectedRefreshMs * 2),
            intf: interfaceItem.key,
            last_time: Number(data.time ?? 0),
            src_field: interfaceItem.role === 'WAN' ? 'inbytes' : 'outbytes',
            formatter: series.formatter,
            metric_mode: metricMode,
            data: [],
        })));

        destroyTrafficCharts();
        const downloadCanvas = document.getElementById('downloadTrafficChart');
        const uploadCanvas = document.getElementById('uploadTrafficChart');
        trafficCharts.trafficIn = downloadCanvas ? new Chart(
            downloadCanvas.getContext('2d'),
            buildTrafficChartConfig(downloadDatasets, formatBits)
        ) : null;
        trafficCharts.trafficOut = uploadCanvas ? new Chart(
            uploadCanvas.getContext('2d'),
            buildTrafficChartConfig(uploadDatasets, formatBits)
        ) : null;

        renderTrafficLegend(downloadTrafficLegend, trafficCharts.trafficIn);
        renderTrafficLegend(uploadTrafficLegend, trafficCharts.trafficOut);

        if (bandwidthAdditionalInfo) {
            bandwidthAdditionalInfo.innerHTML = formatSourceLegend(metricMode);
        }
        if (trafficLastUpdate) {
            trafficLastUpdate.innerText = data.last_update || '--';
        }
        updateWanSourceStats(data);

        trafficInitialized = true;
    }

    function applyTrafficPayload(data) {
        if (!data) {
            return;
        }
        if (data.error) {
            throw new Error(data.error);
        }
        if (data.supported === false) {
            throw new Error(`Surveillance trafic indisponible pour le device actif (${data.device_type || 'autre'})`);
        }

        if (!trafficInitialized) {
            initializeTraffic(data);
        }

        const selectedInterfaces = getTrafficInterfaces(data);
        if (selectedInterfaces.length === 0) {
            return;
        }
        const interfaceStats = new Map(selectedInterfaces.map((item) => [item.key, item.stats]));
        selectedInterfaces.forEach((item) => {
            const rxRate = Number(item.stats?.inbytes || 0);
            const txRate = Number(item.stats?.outbytes || 0);
            currentWanTraffic[item.key] = {
                rx_rate: rxRate,
                tx_rate: txRate,
                rx_total: Number(item.stats?.rx_bytes ?? item.stats?.rx_total ?? 0),
                tx_total: Number(item.stats?.tx_bytes ?? item.stats?.tx_total ?? 0),
            };
            recordWanLiveHistory(item.key, rxRate, txRate);
            updateWanUsageFromCounters(item.key, currentWanTraffic[item.key].rx_total, currentWanTraffic[item.key].tx_total);
        });
        Object.values(trafficCharts).filter(Boolean).forEach(chart => {
            chart.config.data.datasets.forEach(dataset => {
                const metricMode = String(data.metric_mode || dataset.metric_mode || 'delta');
                const rawValue = Number(interfaceStats.get(dataset.intf)?.[dataset.src_field] ?? 0);
                const elapsedTime = Number(data.time ?? 0) - Number(dataset.last_time ?? 0);
                let yValue = 0;

                if (metricMode === 'rate') {
                    yValue = Math.max(0, rawValue);
                } else if (elapsedTime > 0) {
                    yValue = Math.round((rawValue / elapsedTime) * 8);
                }

                if (metricMode === 'rate' || elapsedTime > 0) {
                    dataset.data.push({
                        x: Date.now(),
                        y: yValue,
                    });
                }

                dataset.last_time = Number(data.time ?? 0);
                dataset.metric_mode = metricMode;
            });

            chart.update('quiet');
        });

        if (trafficLastUpdate) {
            trafficLastUpdate.innerText = data.last_update || '--';
        }
        if (bandwidthAdditionalInfo) {
            bandwidthAdditionalInfo.innerHTML = formatSourceLegend(String(data.metric_mode || 'rate'));
        }
        updateWanSourceStats(data);
        renderWanCards();

        currentInterfaces = Array.isArray(data.selected_interfaces) && data.selected_interfaces.length > 0
            ? data.selected_interfaces
            : currentInterfaces;
        currentInterface = currentInterfaces[0] || data.interface_label || currentInterface;

        updateTrafficHeaderValues();
    }

    async function fetchInitialTraffic(interfaceName = '') {
        const url = interfaceName
            ? `${TRAFFIC_INIT_PATH}?interfaces=${encodeURIComponent(interfaceName)}`
            : TRAFFIC_INIT_PATH;
        const response = await fetch(url, {
            headers: {
                Accept: 'application/json',
            },
            credentials: 'same-origin',
        });

        const text = await response.text();
        let data = null;

        try {
            data = text ? JSON.parse(text) : null;
        } catch (error) {
            throw new Error(`JSON trafic invalide: ${text.slice(0, 160) || 'reponse vide'}`);
        }

        if (!response.ok) {
            throw new Error(data?.error || `Erreur HTTP ${response.status}`);
        }

        return data;
    }

    function openTrafficStream(interfaceName = '') {
        if (trafficSource) {
            trafficSource.close();
        }

        const streamUrl = interfaceName
            ? `${TRAFFIC_STREAM_PATH}?interfaces=${encodeURIComponent(interfaceName)}`
            : TRAFFIC_STREAM_PATH;
        trafficSource = new EventSource(streamUrl);
        trafficSource.onmessage = event => {
            try {
                const data = JSON.parse(event.data);
                const now = Date.now();
                if (now - lastTrafficApplyTs < selectedRefreshMs) {
                    return;
                }
                lastTrafficApplyTs = now;
                applyTrafficPayload(data);
                hideError();
            } catch (error) {
                showError(`Erreur trafic: ${error.message}`);
            }
        };
        trafficSource.onerror = () => {
            if (bandwidthAdditionalInfo) {
                bandwidthAdditionalInfo.innerText = 'Flux live interrompu ou en reconnexion...';
            }
        };
    }

    async function initializeMonitoring(interfaceName = '') {
        lastWanFilterInterfaces = interfaceName;
        const initialData = await fetchInitialTraffic(interfaceName);
        trafficInitialized = false;
        lastTrafficApplyTs = 0;
        applyTrafficPayload(initialData);
        hideError();
        openTrafficStream((initialData.selected_interfaces || [initialData.selected_interface || interfaceName]).join(','));
    }

    function scheduleDomainActivityRefresh() {
        if (domainRefreshTimer) {
            clearInterval(domainRefreshTimer);
            domainRefreshTimer = null;
        }
        if (trafficSourceType === 'OPNSENSE' && domainActivityTableBody) {
            refreshDomainActivity();
            domainRefreshTimer = window.setInterval(refreshDomainActivity, selectedRefreshMs);
        }
    }

    const handleInterfaceChange = async () => {
        const firstInterface = trafficInterfaceSelect?.value || '';
        const secondInterface = trafficInterfaceSelect2?.value || '';
        const selected = [firstInterface, secondInterface].filter(Boolean);
        if (selected.length === 0) selected.push(...getWanFilterInterfaces().split(',').filter(Boolean));
        currentInterfaceRoles = {};
        if (firstInterface) currentInterfaceRoles[firstInterface] = 'WAN';
        if (secondInterface) currentInterfaceRoles[secondInterface] = 'WAN';
        currentInterfaces = selected;
        currentInterface = selected[0] || '';

        try {
            await initializeMonitoring(selected.join(','));
        } catch (error) {
            destroyTrafficCharts();
            showError(error.message);
            setInterfaceSelectError();
            if (bandwidthAdditionalInfo) {
                bandwidthAdditionalInfo.innerText = error.message;
            }
        }
    };

    wanEditSaveBtn?.addEventListener('click', saveWanEditModal);
    wanEditModal?.querySelectorAll('[data-wan-edit-close]').forEach((button) => button.addEventListener('click', closeWanEditModal));
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && wanEditModal && !wanEditModal.hidden) closeWanEditModal();
        if (event.key === 'Enter' && wanEditModal && !wanEditModal.hidden && document.activeElement !== wanEditInterfaceSelect) {
            event.preventDefault();
            saveWanEditModal();
        }
    });

    trafficInterfaceSelect?.addEventListener('change', handleInterfaceChange);
    trafficInterfaceSelect2?.addEventListener('change', handleInterfaceChange);

    trafficRefreshSelect?.addEventListener('change', async () => {
        selectedRefreshMs = 2000;
        trafficRefreshSelect.value = '2000';
        localStorage.setItem(TRAFFIC_REFRESH_STORAGE_KEY, '2000');
        scheduleDomainActivityRefresh();
        try {
            await initializeMonitoring(getWanFilterInterfaces());
        } catch (error) {
            showError(error.message);
        }
    });

    function changeRefreshStep() {
        selectedRefreshMs = 2000;
        if (trafficRefreshSelect) trafficRefreshSelect.value = '2000';
        localStorage.setItem(TRAFFIC_REFRESH_STORAGE_KEY, '2000');
    }

    ['downloadTrafficChart', 'uploadTrafficChart'].forEach((canvasId) => {
        const canvas = document.getElementById(canvasId);
        canvas?.addEventListener('wheel', (event) => {
            event.preventDefault();
            changeRefreshStep(event.deltaY > 0 ? 1 : -1);
        }, { passive: false });
    });

    domainActivitySearch?.addEventListener('input', renderDomainRows);

    queueInterface?.addEventListener('change', loadInterfaceQueues);
    queueForm?.addEventListener('submit', async (event) => {
        event.preventDefault();
        if (!window.confirm(`Appliquer la limite sur ${queueInterface.value} et vérifier son ordre ou sa hiérarchie avec les files Hotspot ?`)) return;
        try {
            const data = await requestQueueControl({ action: 'save_interface_queue', interface: queueInterface.value, download_mbps: queueDownload.value, upload_mbps: queueUpload.value, priority: queuePriority.value });
            renderInterfaceQueues(data.state || {});
            AppToast.flash(data.message, 'success');
        } catch (error) {
            showError(error.message);
        }
    });
    queueToggle?.addEventListener('click', async () => {
        const enabled = queueToggle.dataset.nextEnabled === 'true';
        if (!window.confirm(`${enabled ? 'Activer' : 'Désactiver'} le contrôle de débit sur ${queueInterface.value} ?`)) return;
        try {
            const data = await requestQueueControl({ action: 'toggle_interface_queue', interface: queueInterface.value, enabled });
            renderInterfaceQueues(data.state || {});
            AppToast.flash(data.message, 'success');
        } catch (error) {
            showError(error.message);
        }
    });

    if (trafficRefreshSelect) {
        selectedRefreshMs = 2000;
        trafficRefreshSelect.value = '2000';
        trafficRefreshSelect.disabled = true;
        localStorage.setItem(TRAFFIC_REFRESH_STORAGE_KEY, '2000');
    }

    (async () => {
        try {
            await initializeMonitoring();
        } catch (error) {
            destroyTrafficCharts();
            showError(error.message);
            setInterfaceSelectError();
            if (bandwidthAdditionalInfo) {
                bandwidthAdditionalInfo.innerText = error.message;
            }
        }
    })();

    scheduleDomainActivityRefresh();
    loadInterfaceQueues();
    if (controlDeviceId) {
        wanRefreshTimer = window.setInterval(loadInterfaceQueues, 60000);
    }
    document.dispatchEvent(new CustomEvent('traffic-control-state-ready', { detail: { success: true } }));

    window.addEventListener('beforeunload', () => {
        if (trafficSource) {
            trafficSource.close();
        }
        if (domainRefreshTimer) {
            clearInterval(domainRefreshTimer);
        }
        if (wanRefreshTimer) {
            clearInterval(wanRefreshTimer);
        }
    });
});
