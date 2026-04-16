const TRAFFIC_DURATION_MS = 20000;
const TRAFFIC_DELAY_MS = 2000;
const TRAFFIC_STREAM_PATH = '../api/traffic_stream.php';
const TRAFFIC_INIT_PATH = '../api/get_traffic_stats.php';
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

document.addEventListener('DOMContentLoaded', () => {
    const messageArea = document.getElementById('messageArea');
    const downloadRateLive = document.getElementById('downloadRateLive');
    const uploadRateLive = document.getElementById('uploadRateLive');
    const downloadRateMirror = document.getElementById('downloadRateMirror');
    const uploadRateMirror = document.getElementById('uploadRateMirror');
    const bandwidthAdditionalInfo = document.getElementById('bandwidthAdditionalInfo');
    const trafficLastUpdate = document.getElementById('trafficLastUpdate');
    const trafficInterfaceSelect = document.getElementById('trafficInterfaceSelect');
    const trafficRefreshSelect = document.getElementById('trafficRefreshSelect');
    const trafficSourceLabel = document.getElementById('trafficSourceLabel')?.value || 'Aucun device actif';
    const trafficSourceHost = document.getElementById('trafficSourceHost')?.value || '';
    const trafficSourceType = document.getElementById('trafficSourceType')?.value || '';
    const domainActivitySearch = document.getElementById('domainActivitySearch');
    const domainActivityTableBody = document.getElementById('domainActivityTableBody');
    const domainQueriesCount = document.getElementById('domainQueriesCount');
    const domainBlockedCount = document.getElementById('domainBlockedCount');
    const domainLastUpdate = document.getElementById('domainLastUpdate');

    let trafficSource = null;
    let trafficInitialized = false;
    let currentInterface = '';
    let selectedRefreshMs = Math.max(2000, Number(localStorage.getItem(TRAFFIC_REFRESH_STORAGE_KEY) || trafficRefreshSelect?.value || 10000));
    let lastTrafficApplyTs = 0;
    let domainRefreshTimer = null;
    let domainRows = [];
    let trafficCharts = {
        trafficIn: null,
        trafficOut: null,
    };

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

        const units = ['', 'K', 'M', 'G', 'T', 'P'];
        const power = Math.floor(Math.log(numeric) / Math.log(1000));
        const safePower = Math.max(0, Math.min(power, units.length - 1));
        const suffix = bits ? 'b' : 'B';

        if (safePower > 0) {
            return `${(numeric / Math.pow(1000, safePower)).toFixed(decimals)} ${units[safePower]}${suffix}`;
        }

        return `${numeric.toFixed(decimals)} ${suffix}`;
    }

    function formatBits(value, decimals = 2) {
        return formatField(value, decimals, true);
    }

    function showError(message) {
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

    function formatSourceLegend(interfaceLabel, metricMode) {
        const sourceParts = [`Source active : ${trafficSourceLabel}`];

        if (trafficSourceHost && !trafficSourceLabel.includes(trafficSourceHost)) {
            sourceParts.push(trafficSourceHost);
        }

        if (trafficSourceType && !trafficSourceLabel.toUpperCase().includes(trafficSourceType)) {
            sourceParts.push(trafficSourceType);
        }

        const detailParts = [];
        if (interfaceLabel) {
            detailParts.push(`Interface: ${interfaceLabel}`);
        }
        detailParts.push(metricMode === 'rate' ? 'Mode direct' : 'Mode delta');
        detailParts.push(`Refresh : ${formatRefreshLabel(selectedRefreshMs)}`);

        return `${sourceParts.join(' | ')} | ${detailParts.join(' | ')}`;
    }

    function populateInterfaceSelect(options, selectedValue) {
        if (!trafficInterfaceSelect) {
            return;
        }

        const interfaces = Array.isArray(options) ? options : [];
        if (interfaces.length === 0) {
            trafficInterfaceSelect.innerHTML = '<option value="">Aucune interface</option>';
            trafficInterfaceSelect.disabled = true;
            return;
        }

        trafficInterfaceSelect.innerHTML = interfaces.map((item) => {
            const name = String(item.name || '');
            const suffix = item.running ? '' : ' (hors ligne)';
            const selected = name === selectedValue ? ' selected' : '';
            return `<option value="${escapeHtml(name)}"${selected}>${escapeHtml(name + suffix)}</option>`;
        }).join('');
        trafficInterfaceSelect.disabled = false;
    }

    function setInterfaceSelectError(label = 'Indisponible') {
        if (!trafficInterfaceSelect) {
            return;
        }

        trafficInterfaceSelect.innerHTML = `<option value="">${escapeHtml(label)}</option>`;
        trafficInterfaceSelect.disabled = true;
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

    function forceTrafficChartPalette(chart, seriesConfig) {
        if (!chart) {
            return;
        }

        chart.data.datasets.forEach((dataset, index) => {
            const series = seriesConfig[index];
            if (!series) {
                return;
            }

            dataset.borderColor = series.color;
            dataset.backgroundColor = setAlpha(series.color, 0.22);
            dataset.pointHoverBackgroundColor = series.color;
            dataset.pointHoverBorderColor = series.color;
            dataset.pointBackgroundColor = series.color;
            dataset.pointBorderColor = series.color;
        });
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
    }

    function initializeTraffic(data) {
        if (typeof Chart === 'undefined') {
            throw new Error('Chart.js indisponible');
        }

        const interfaces = data.interfaces ?? {};
        const wanStats = interfaces[WAN_INTERFACE_KEY];
        if (!wanStats) {
            throw new Error('Interface WAN introuvable pour la surveillance trafic');
        }

        const interfaceLabel = data.interface_label || wanStats.name || 'WAN';
        const metricMode = String(data.metric_mode || 'delta');

        populateInterfaceSelect(data.interface_options || [], data.selected_interface || interfaceLabel);
        currentInterface = data.selected_interface || interfaceLabel;

        const uploadDatasets = TRAFFIC_SERIES.upload.map(series => ({
            label: series.label,
            borderColor: series.color,
            backgroundColor: setAlpha(series.color, 0.22),
            pointHoverBackgroundColor: series.color,
            pointHoverBorderColor: series.color,
            pointBackgroundColor: series.color,
            pointBorderColor: series.color,
            pointRadius: 0,
            borderWidth: 2,
            intf: WAN_INTERFACE_KEY,
            last_time: Number(data.time ?? 0),
            src_field: series.key,
            formatter: series.formatter,
            metric_mode: metricMode,
            data: [],
        }));

        const downloadDatasets = TRAFFIC_SERIES.download.map(series => ({
            label: series.label,
            borderColor: series.color,
            backgroundColor: setAlpha(series.color, 0.22),
            pointHoverBackgroundColor: series.color,
            pointHoverBorderColor: series.color,
            pointBackgroundColor: series.color,
            pointBorderColor: series.color,
            pointRadius: 0,
            borderWidth: 2,
            intf: WAN_INTERFACE_KEY,
            last_time: Number(data.time ?? 0),
            src_field: series.key,
            formatter: series.formatter,
            metric_mode: metricMode,
            data: [],
        }));

        destroyTrafficCharts();
        trafficCharts.trafficIn = new Chart(
            document.getElementById('downloadTrafficChart').getContext('2d'),
            buildTrafficChartConfig(downloadDatasets, formatBits)
        );
        trafficCharts.trafficOut = new Chart(
            document.getElementById('uploadTrafficChart').getContext('2d'),
            buildTrafficChartConfig(uploadDatasets, formatBits)
        );

        forceTrafficChartPalette(trafficCharts.trafficIn, TRAFFIC_SERIES.download);
        forceTrafficChartPalette(trafficCharts.trafficOut, TRAFFIC_SERIES.upload);

        if (bandwidthAdditionalInfo) {
            bandwidthAdditionalInfo.innerText = formatSourceLegend(interfaceLabel, metricMode);
        }
        if (trafficLastUpdate) {
            trafficLastUpdate.innerText = data.last_update || '--';
        }

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

        const wanStats = data.interfaces?.[WAN_INTERFACE_KEY];
        if (!wanStats) {
            return;
        }

        Object.values(trafficCharts).forEach(chart => {
            chart.config.data.datasets.forEach(dataset => {
                const metricMode = String(data.metric_mode || dataset.metric_mode || 'delta');
                const rawValue = Number(wanStats[dataset.src_field] ?? 0);
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
            bandwidthAdditionalInfo.innerText = formatSourceLegend(
                data.interface_label || currentInterface,
                String(data.metric_mode || 'rate')
            );
        }

        currentInterface = data.selected_interface || data.interface_label || currentInterface;

        updateTrafficHeaderValues();
    }

    async function fetchInitialTraffic(interfaceName = '') {
        const url = interfaceName
            ? `${TRAFFIC_INIT_PATH}?interface=${encodeURIComponent(interfaceName)}`
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
            ? `${TRAFFIC_STREAM_PATH}?interface=${encodeURIComponent(interfaceName)}`
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
        const initialData = await fetchInitialTraffic(interfaceName);
        trafficInitialized = false;
        lastTrafficApplyTs = 0;
        applyTrafficPayload(initialData);
        hideError();
        openTrafficStream(initialData.selected_interface || interfaceName);
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

    trafficInterfaceSelect?.addEventListener('change', async () => {
        const selected = trafficInterfaceSelect.value || '';
        currentInterface = selected;

        try {
            await initializeMonitoring(selected);
        } catch (error) {
            destroyTrafficCharts();
            showError(error.message);
            setInterfaceSelectError();
            if (bandwidthAdditionalInfo) {
                bandwidthAdditionalInfo.innerText = error.message;
            }
        }
    });

    trafficRefreshSelect?.addEventListener('change', async () => {
        selectedRefreshMs = Math.max(2000, Number(trafficRefreshSelect.value || 10000));
        localStorage.setItem(TRAFFIC_REFRESH_STORAGE_KEY, String(selectedRefreshMs));
        scheduleDomainActivityRefresh();
        try {
            await initializeMonitoring(currentInterface);
        } catch (error) {
            showError(error.message);
        }
    });

    const refreshSteps = [2000, 10000];
    function changeRefreshStep(direction) {
        const currentIndex = refreshSteps.findIndex((step) => step === selectedRefreshMs);
        const safeIndex = currentIndex >= 0 ? currentIndex : 1;
        const nextIndex = Math.max(0, Math.min(refreshSteps.length - 1, safeIndex + direction));
        selectedRefreshMs = refreshSteps[nextIndex];
        if (trafficRefreshSelect) {
            trafficRefreshSelect.value = String(selectedRefreshMs);
        }
        localStorage.setItem(TRAFFIC_REFRESH_STORAGE_KEY, String(selectedRefreshMs));
        scheduleDomainActivityRefresh();
        initializeMonitoring(currentInterface).catch(() => {});
    }

    ['downloadTrafficChart', 'uploadTrafficChart'].forEach((canvasId) => {
        const canvas = document.getElementById(canvasId);
        canvas?.addEventListener('wheel', (event) => {
            event.preventDefault();
            changeRefreshStep(event.deltaY > 0 ? 1 : -1);
        }, { passive: false });
    });

    domainActivitySearch?.addEventListener('input', renderDomainRows);

    if (trafficRefreshSelect) {
        trafficRefreshSelect.value = String(selectedRefreshMs);
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

    window.addEventListener('beforeunload', () => {
        if (trafficSource) {
            trafficSource.close();
        }
        if (domainRefreshTimer) {
            clearInterval(domainRefreshTimer);
        }
    });
});
