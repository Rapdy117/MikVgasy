const DASHBOARD_REFRESH_MS = 45000;
const TRAFFIC_DURATION_MS = 20000;
const TRAFFIC_DELAY_MS = 2000;
const TRAFFIC_STREAM_PATH = `../api/traffic_stream.php`;
const TRAFFIC_INIT_PATH = `../api/get_traffic_stats.php`;
const CPU_STREAM_PATH = `../api/cpu_stream.php`;
const CPU_TYPE_PATH = `../api/get_cpu_type.php`;
const WAN_INTERFACE_KEY = 'wan';
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
    const recentEventsTableBody = document.getElementById('recentEventsTableBody');
    const trafficInterfacesInfo = document.getElementById('trafficInterfacesInfo');
    const downloadRateLive = document.getElementById('downloadRateLive');
    const uploadRateLive = document.getElementById('uploadRateLive');
    const bandwidthAdditionalInfo = document.getElementById('bandwidthAdditionalInfo');
    const cpuTypeLabel = document.getElementById('cpuTypeLabel');
    const cpuTotalLive = document.getElementById('cpuTotalLive');
    const cpuGauge = document.getElementById('cpuGauge');
    const cpuGaugeOuterValue = document.getElementById('cpuGaugeOuterValue');
    const cpuGaugeInnerValue = document.getElementById('cpuGaugeInnerValue');
    const cpuGaugeCpuLabel = document.getElementById('cpuGaugeCpuLabel');
    const cpuGaugeRamLabel = document.getElementById('cpuGaugeRamLabel');
    const connectedUsersCount = document.getElementById('connectedUsersCount');
    const summarySalesToday = document.getElementById('summarySalesToday');
    const summarySalesMonthly = document.getElementById('summarySalesMonthly');
    const salesTrendBars = document.getElementById('salesTrendBars');
    const salesTrendMonthLabel = document.getElementById('salesTrendMonthLabel');
    const salesActiveDays = document.getElementById('salesActiveDays');
    const salesPeakDay = document.getElementById('salesPeakDay');
    const deviceSummaryTitle = document.getElementById('deviceSummaryTitle');
    const deviceTypeLabel = document.getElementById('deviceTypeLabel');

    let trafficSource = null;
    let cpuSource = null;
    let liveStreamsStarted = false;
    let trafficCharts = {
        trafficIn: null,
        trafficOut: null,
    };
    let trafficInitialized = false;
    let latestMemoryPercent = 0;

    function formatDeviceType(type) {
        const normalized = String(type || 'other').toLowerCase();

        if (normalized === 'opnsense') {
            return 'OPNsense';
        }

        if (normalized === 'mikrotik') {
            return 'MikroTik';
        }

        return 'Autre';
    }

    function setGaugeCircleProgress(circle, percent, color) {
        if (!circle) {
            return;
        }

        const radius = Number(circle.getAttribute('r') ?? 0);
        const circumference = 2 * Math.PI * radius;
        const clamped = Math.max(0, Math.min(percent, 100));
        const offset = circumference * (1 - (clamped / 100));

        circle.style.strokeDasharray = `${circumference}`;
        circle.style.strokeDashoffset = `${offset}`;
        if (color) {
            circle.style.stroke = color;
        }
    }

    function escapeHtml(value) {
        return String(value ?? '')
            .replaceAll('&', '&amp;')
            .replaceAll('<', '&lt;')
            .replaceAll('>', '&gt;')
            .replaceAll('"', '&quot;')
            .replaceAll("'", '&#39;');
    }

    function showError(message) {
        if (!messageArea) {
            return;
        }

        messageArea.innerHTML = `<div class="alert alert-danger" role="alert">${escapeHtml(message)}</div>`;
        messageArea.style.display = 'block';
    }

    function hideError() {
        if (messageArea) {
            messageArea.style.display = 'none';
            messageArea.innerHTML = '';
        }
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

        const fileSizeTypes = ['', 'K', 'M', 'G', 'T', 'P', 'E', 'Z', 'Y'];
        const ndx = Math.floor(Math.log(numeric) / Math.log(1000));
        const suffix = bits ? 'b' : 'B';

        if (ndx > 0) {
            return `${(numeric / Math.pow(1000, ndx)).toFixed(decimals)} ${fileSizeTypes[ndx]}${suffix}`;
        }

        return `${numeric.toFixed(decimals)} ${suffix}`;
    }

    function formatBits(value, decimals = 2) {
        return formatField(value, decimals, true);
    }

    function renderTrafficLegend(container, definitions) {
        if (!container) {
            return;
        }

        if (!definitions || definitions.length === 0) {
            container.innerHTML = '';
            container.style.display = 'none';
            return;
        }

        container.style.display = 'block';
        container.innerHTML = definitions.map(def => (
            `<span class="traffic-interface-badge" style="--traffic-badge:${def.color};">${escapeHtml(def.label)}</span>`
        )).join(' ');
    }

    function buildTrafficChartConfig(datasets, formatter) {
        return {
            type: 'line',
            data: {
                datasets,
            },
            options: {
                bezierCurve: false,
                maintainAspectRatio: false,
                scaleShowLabels: false,
                tooltipEvents: [],
                pointDot: true,
                scaleShowGridLines: true,
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
                        type: 'realtime',
                        realtime: {
                            duration: TRAFFIC_DURATION_MS,
                            delay: TRAFFIC_DELAY_MS,
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
                hover: {
                    mode: 'nearest',
                    intersect: false,
                },
                interaction: {
                    mode: 'nearest',
                    intersect: false,
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
                        ttl: 30000,
                    },
                    colorschemes: false,
                },
            },
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

    function stopLiveStreams(reason) {
        if (trafficSource) {
            trafficSource.close();
            trafficSource = null;
        }

        if (cpuSource) {
            cpuSource.close();
            cpuSource = null;
        }

        liveStreamsStarted = false;
        trafficInitialized = false;
        destroyTrafficCharts();

        if (downloadRateLive) {
            downloadRateLive.innerText = '--';
        }
        if (uploadRateLive) {
            uploadRateLive.innerText = '--';
        }
        if (bandwidthAdditionalInfo) {
            bandwidthAdditionalInfo.innerText = reason || 'Telemetrie live indisponible pour le device actif';
        }
        if (trafficInterfacesInfo) {
            trafficInterfacesInfo.innerText = 'Interfaces : N/A';
        }
        if (cpuTypeLabel) {
            cpuTypeLabel.innerText = reason || 'CPU indisponible';
        }
        if (cpuTotalLive) {
            cpuTotalLive.innerText = '--%';
        }
        if (cpuGaugeCpuLabel) {
            cpuGaugeCpuLabel.innerText = '--%';
        }
        if (cpuGaugeRamLabel) {
            cpuGaugeRamLabel.innerText = '--%';
        }
        setGaugeCircleProgress(cpuGaugeOuterValue, 0, '#64748b');
        setGaugeCircleProgress(cpuGaugeInnerValue, 0, '#38bdf8');
    }

    function initializeTraffic(data) {
        if (typeof Chart === 'undefined') {
            throw new Error('Chart.js indisponible');
        }

        const interfaces = data.interfaces ?? {};
        const wanStats = interfaces[WAN_INTERFACE_KEY];
        if (!wanStats) {
            throw new Error('Interface WAN introuvable pour le widget trafic');
        }

        const uploadDatasets = TRAFFIC_SERIES.upload.map(series => ({
            label: series.label,
            hidden: false,
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
            data: [],
        }));
        const downloadDatasets = TRAFFIC_SERIES.download.map(series => ({
            label: series.label,
            hidden: false,
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

        renderTrafficLegend(trafficInterfacesInfo, []);
        if (bandwidthAdditionalInfo) {
            bandwidthAdditionalInfo.innerText = `Interface: WAN | Fenetre affichee: ${Math.round(TRAFFIC_DURATION_MS / 1000)} s | Retard d'affichage: ${Math.round(TRAFFIC_DELAY_MS / 1000)} s`;
        }
        trafficInitialized = true;
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
        if (downloadRateLive) {
            downloadRateLive.innerHTML = buildTrafficHeaderValue(trafficCharts.trafficIn);
        }
        if (uploadRateLive) {
            uploadRateLive.innerHTML = buildTrafficHeaderValue(trafficCharts.trafficOut);
        }
    }

    function applyTrafficEvent(event) {
        if (!event) {
            return;
        }

        const data = JSON.parse(event.data);
        if (data.error) {
            throw new Error(data.error);
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
                const elapsedTime = Number(data.time ?? 0) - Number(dataset.last_time ?? 0);
                if (elapsedTime > 0) {
                    const rawValue = Number(wanStats[dataset.src_field] ?? 0);
                    const yValue = dataset.formatter === 'packets'
                        ? Math.round(rawValue / elapsedTime)
                        : Math.round((rawValue / elapsedTime) * 8);

                    dataset.data.push({
                        x: Date.now(),
                        y: yValue,
                    });
                }

                dataset.last_time = Number(data.time ?? 0);
            });
            chart.update('quiet');
        });

        updateTrafficHeaderValues();
    }

    function openTrafficStream() {
        if (trafficSource) {
            trafficSource.close();
        }

        trafficSource = new EventSource(TRAFFIC_STREAM_PATH);
        trafficSource.onmessage = event => {
            try {
                applyTrafficEvent(event);
            } catch (error) {
                console.error('Erreur flux trafic:', error);
                showError(`Erreur trafic: ${error.message}`);
            }
        };
        trafficSource.onerror = event => {
            if (trafficSource && trafficSource.readyState !== EventSource.CONNECTING) {
                console.error('Flux trafic interrompu', event);
            }
        };
    }

    function updateCpuUi(data) {
        const total = Number(data.total ?? 0);
        let cpuGaugeColor = '#22c55e';

        if (total >= 70) {
            cpuGaugeColor = '#ef4444';
        } else if (total >= 30) {
            cpuGaugeColor = '#f59e0b';
        }

        if (cpuTotalLive) {
            cpuTotalLive.innerText = `${total.toFixed(2)}%`;
        }
        if (cpuGauge) {
            setGaugeCircleProgress(cpuGaugeOuterValue, total, cpuGaugeColor);
            setGaugeCircleProgress(cpuGaugeInnerValue, latestMemoryPercent, '#38bdf8');
            cpuGauge.style.setProperty('--cpu-gauge-color', cpuGaugeColor);
            if (cpuGaugeCpuLabel) {
                cpuGaugeCpuLabel.innerText = `${total.toFixed(1)}%`;
            }
        }
    }

    function renderSalesTrend(trend) {
        if (!salesTrendBars) {
            return;
        }

        if (!Array.isArray(trend) || trend.length === 0) {
            salesTrendBars.innerHTML = '<span class="sales-trend-empty">Aucune donnée</span>';
            if (salesActiveDays) {
                salesActiveDays.innerText = '--';
            }
            if (salesPeakDay) {
                salesPeakDay.innerText = '--';
            }
            return;
        }

        const maxValue = Math.max(...trend.map(item => Number(item.total ?? 0)), 0);
        const activeDays = trend.filter(item => Number(item.total ?? 0) > 0).length;
        const peakEntry = trend.reduce((best, item) => {
            const total = Number(item.total ?? 0);
            if (!best || total > best.total) {
                return { day: Number(item.day ?? 0), total };
            }

            return best;
        }, null);

        if (salesActiveDays) {
            salesActiveDays.innerText = `${activeDays}`;
        }

        if (salesPeakDay) {
            salesPeakDay.innerText = peakEntry && peakEntry.total > 0
                ? `J${peakEntry.day} (${peakEntry.total})`
                : 'Aucun';
        }

        salesTrendBars.innerHTML = trend.map(item => {
            const total = Number(item.total ?? 0);
            const day = Number(item.day ?? 0);
            const height = maxValue > 0 ? Math.max(8, Math.round((total / maxValue) * 100)) : 8;
            const label = `${day}`;
            return `
                <span class="sales-trend-bar-wrap" title="Jour ${escapeHtml(label)} : ${escapeHtml(total)} vente(s)">
                    <span class="sales-trend-bar ${total > 0 ? '' : 'sales-trend-bar-empty'}" style="height:${height}%"></span>
                    <span class="sales-trend-day">${escapeHtml(label)}</span>
                </span>
            `;
        }).join('');
    }

    function openCpuStream() {
        if (cpuSource) {
            cpuSource.close();
        }

        cpuSource = new EventSource(CPU_STREAM_PATH);
        cpuSource.onmessage = event => {
            try {
                const data = JSON.parse(event.data);
                if (data.error) {
                    throw new Error(data.error);
                }

                updateCpuUi(data);
            } catch (error) {
                console.error('Erreur flux CPU:', error);
                showError(`Erreur CPU: ${error.message}`);
            }
        };
        cpuSource.onerror = event => {
            if (cpuSource && cpuSource.readyState !== EventSource.CONNECTING) {
                console.error('Flux CPU interrompu', event);
            }
        };
    }

    function fetchCpuType() {
        fetch(CPU_TYPE_PATH)
            .then(response => {
                if (!response.ok) {
                    throw new Error(`Erreur HTTP ${response.status}`);
                }
                return response.json();
            })
            .then(data => {
                if (!cpuTypeLabel) {
                    return;
                }

                if (data && typeof data === 'object' && data.label) {
                    cpuTypeLabel.innerText = data.label;
                    return;
                }

                if (typeof data === 'string') {
                    cpuTypeLabel.innerText = data;
                    return;
                }

                if (Array.isArray(data)) {
                    cpuTypeLabel.innerText = data.join(' ');
                    return;
                }

                cpuTypeLabel.innerText = Object.values(data).join(' ');
            })
            .catch(error => {
                console.error('Erreur CPU type:', error);
            });
    }

    function startLiveStreams(telemetrySupported) {
        if (!telemetrySupported) {
            stopLiveStreams('Telemetrie live indisponible pour le device actif');
            return;
        }

        if (liveStreamsStarted) {
            return;
        }

        liveStreamsStarted = true;

        window.setTimeout(() => {
            fetch(TRAFFIC_INIT_PATH)
                .then(response => {
                    if (!response.ok) {
                        throw new Error(`Erreur HTTP ${response.status}`);
                    }
                    return response.json();
                })
                .then(data => {
                if (data.error) {
                    throw new Error(data.error);
                }

                if (data.supported === false) {
                    stopLiveStreams('Telemetrie trafic indisponible pour le device actif');
                    return;
                }

                initializeTraffic(data);
                updateTrafficHeaderValues();
                    openTrafficStream();
                })
                .catch(error => {
                    console.error('Erreur initialisation trafic:', error);
                    showError(`Erreur trafic: ${error.message}`);
                });
        }, 350);

        window.setTimeout(() => {
            openCpuStream();
        }, 900);
    }

    function fetchDashboardData() {
        fetch('../api/get_stats.php')
            .then(response => {
                if (!response.ok) {
                    throw new Error(`Erreur HTTP ${response.status}`);
                }
                return response.json();
            })
            .then(data => {
                if (data.error) {
                    throw new Error(data.error);
                }

                hideError();

                const activeHotspotUsersCount = document.getElementById('activeHotspotUsersCount');
                const opnsenseName = document.getElementById('opnsenseName');
                const opnsenseVersion = document.getElementById('opnsenseVersion');
                const opnsenseStatus = document.getElementById('opnsenseStatus');
                const opnsenseZones = document.getElementById('opnsenseZones');
                const deviceName = data.device_name || data.opnsense_name || '--';
                const deviceType = data.device_type || 'other';
                const deviceStatus = data.device_status || data.opnsense_status || '--';
                const deviceZones = data.device_zones || data.opnsense_zones || [];
                const backendLabel = data.device_backend || 'generic';
                const deviceVersion = data.opnsense_version && data.opnsense_version !== 'N/A'
                    ? data.opnsense_version
                    : backendLabel;

                if (activeHotspotUsersCount) activeHotspotUsersCount.innerText = data.active_hotspot_users || '0';
                if (connectedUsersCount) connectedUsersCount.innerText = data.total_users || '0';
                if (cpuGauge && data.memory_used_percent !== undefined) {
                    const ramPercent = Math.max(0, Math.min(Number(data.memory_used_percent ?? 0), 100));
                    latestMemoryPercent = ramPercent;
                    setGaugeCircleProgress(cpuGaugeInnerValue, ramPercent, '#38bdf8');
                    if (cpuGaugeRamLabel) {
                        cpuGaugeRamLabel.innerText = `${ramPercent.toFixed(1)}%`;
                    }
                }
                if (summarySalesToday) summarySalesToday.innerText = data.sales_today || '0';
                if (summarySalesMonthly) summarySalesMonthly.innerText = data.sales_monthly || '0';
                if (salesTrendMonthLabel) {
                    salesTrendMonthLabel.innerText = new Date().toLocaleDateString('fr-FR', { month: 'long', year: 'numeric' });
                }
                if (deviceSummaryTitle) deviceSummaryTitle.innerText = deviceName;
                if (deviceTypeLabel) deviceTypeLabel.innerText = formatDeviceType(deviceType);
                if (opnsenseName) opnsenseName.innerText = deviceName;
                if (opnsenseVersion) opnsenseVersion.innerText = deviceVersion || '--';
                if (opnsenseStatus) opnsenseStatus.innerText = deviceStatus || '--';
                if (opnsenseZones) {
                    opnsenseZones.innerText = Array.isArray(deviceZones) && deviceZones.length > 0
                        ? deviceZones.join(', ')
                        : (data.telemetry_supported === false ? backendLabel : 'Aucune');
                }

                if (recentEventsTableBody) {
                    if (Array.isArray(data.recent_events) && data.recent_events.length > 0) {
                        const paddedEvents = data.recent_events.slice(0, 5);
                        while (paddedEvents.length < 5) {
                            paddedEvents.push({ time: '', user: '', action: '' });
                        }

                        recentEventsTableBody.innerHTML = paddedEvents.map(item => `
                            <tr>
                                <td>${escapeHtml(item.time ?? '') || '&nbsp;'}</td>
                                <td>${escapeHtml(item.user ?? '') || '&nbsp;'}</td>
                                <td>${escapeHtml(item.action ?? '') || '&nbsp;'}</td>
                            </tr>
                        `).join('');
                    } else {
                        recentEventsTableBody.innerHTML = Array.from({ length: 5 }, () => `
                            <tr>
                                <td>&nbsp;</td>
                                <td>&nbsp;</td>
                                <td>&nbsp;</td>
                            </tr>
                        `).join('');
                    }
                }

                renderSalesTrend(data.sales_daily_trend);
                startLiveStreams(data.telemetry_supported !== false);
                if (data.telemetry_supported === false) {
                    fetchCpuType();
                }
            })
            .catch(error => {
                console.error('Erreur lors de la récupération des données du tableau de bord:', error);
                showError(`Erreur lors du chargement des données: ${error.message}`);
            });
    }

    fetchCpuType();
    fetchDashboardData();
    window.setInterval(fetchDashboardData, DASHBOARD_REFRESH_MS);
});

function redirectTo(pageUrl) {
    window.location.href = pageUrl;
}
