/* Copie dans le presse-papier — fonctionne sur HTTP et à l'intérieur d'un Bootstrap Modal */
function copyToClipboard(text) {
    if (navigator.clipboard && window.isSecureContext) {
        return navigator.clipboard.writeText(text);
    }
    /* Fallback execCommand — doit s'exécuter dans le focus-trap du modal si actif */
    const container = document.querySelector('.modal.show .modal-body') || document.body;
    const ta = document.createElement('textarea');
    ta.value = text;
    ta.setAttribute('readonly', '');
    ta.style.cssText = 'position:absolute;left:-9999px;top:0;width:1px;height:1px;';
    container.appendChild(ta);
    ta.focus();
    ta.setSelectionRange(0, ta.value.length);
    let ok = false;
    try { ok = document.execCommand('copy'); } catch (_) {}
    container.removeChild(ta);
    return ok ? Promise.resolve() : Promise.reject(new Error('copy_failed'));
}

let isNew = false;
document.addEventListener("DOMContentLoaded", function () {
    const deviceApiUrl = '../api/network_devices_api.php';
    const deviceAdminLoadUrl = `${deviceApiUrl}?include_secrets=1`;

    const form = document.getElementById("networkDeviceForm");
    const testBtn = document.getElementById("testDevice");
    const loadButton = document.getElementById("loadDeviceBtn");
    const status = document.getElementById("testStatus");
    const newBtn = document.getElementById("newDeviceBtn");
    const emptyState = document.getElementById("deviceEmptyState");
    const deviceContent = document.getElementById("deviceContent");
    const editBtn = document.getElementById("editBtn");
    const saveBtn = document.getElementById("saveBtn");
    const cancelBtn = document.getElementById("cancelBtn");
    const deleteBtn = document.getElementById("deleteBtn");
    const activateBtn = document.getElementById("activateDeviceBtn");
    const backendStatus = document.getElementById("deviceBackendStatus");
    const backendStatusIcon = document.getElementById("backendStatusIcon");
    const typeField = form ? form.querySelector('[name="type"]') : null;
    const apiKeyField = form ? form.querySelector('[name="api_key"]') : null;
    const apiSecretField = form ? form.querySelector('[name="api_secret"]') : null;
    const isActiveField = form ? form.querySelector('[name="is_active"]') : null;
    const STATUS_STORAGE_KEY = 'networkDeviceStatuses';
    let selectedDeviceId = null;
    let currentDevice = null;
    let activeDeviceId = null;
    let deviceStatuses = loadDeviceStatuses();
    let deviceLicInfo = null;
    const VALID_DEVICE_TYPES = ['opnsense', 'mikrotik', 'radius'];

    function isValidDeviceType(type) {
        return VALID_DEVICE_TYPES.includes(String(type || '').trim().toLowerCase());
    }

    function getSelectedDeviceType() {
        const rawType = String(typeField?.value || '').trim().toLowerCase();
        return isValidDeviceType(rawType) ? rawType : '';
    }

    function formatDeviceType(type) {
        const normalized = String(type || '').trim().toLowerCase();

        if (normalized === 'mikrotik') {
            return 'MikroTik';
        }

        if (normalized === 'radius') {
            return 'RADIUS';
        }

        if (normalized === 'opnsense') {
            return 'OPNsense';
        }

        return 'Inconnu';
    }

    function formatBackendDriver(raw) {
        const key = String(raw || '').toLowerCase();
        const map = {
            opnsense_api: 'OPNsense API',
            mikrotik_api: 'MikroTik API',
            radius: 'RADIUS',
        };
        return map[key] || (String(raw || '').trim() !== '' ? String(raw) : '—');
    }

    function formatBusinessSource(raw, labelFromServer) {
        const fromServer = String(labelFromServer || '').trim();
        if (fromServer !== '') {
            return fromServer;
        }
        const key = String(raw || '').toLowerCase();
        const map = {
            mikrotik_local: 'Profils locaux (MikroTik)',
            radius: 'RADIUS (FreeRADIUS / intégration)',
        };
        return map[key] || (String(raw || '').trim() !== '' ? String(raw) : '');
    }

    function renderBackendStatus(device, connectionState) {
        if (!backendStatus) {
            return;
        }

        const setIconStatus = (statusValue) => {
            if (!backendStatusIcon) {
                return;
            }

            const normalized = normalizeDeviceStatus(statusValue);
            backendStatusIcon.classList.remove(
                'backend-status-active',
                'backend-status-connected',
                'backend-status-offline'
            );
            backendStatusIcon.classList.add(`backend-status-${normalized}`);
        };

        if (!device) {
            backendStatus.innerHTML = '<span class="text-muted">Aucun device actif</span>';
            setIconStatus('offline');
            return;
        }

        const currentStatus = getDisplayStatus(device);
        const cs = connectionState && typeof connectionState === 'object' ? connectionState : {};
        const statusValue = String(cs.status || currentStatus || 'unknown').toUpperCase();
        const supported = cs.supported === true;
        const colorClass = supported ? 'text-success' : 'text-warning';
        const message = cs.label || 'Backend inconnu';
        const hostValue = String(device.host || device.ip || '-');
        setIconStatus(currentStatus);

        const driverDisplay = cs.label_backend || formatBackendDriver(cs.backend_driver || device.backend_driver);
        const sourceDisplay = formatBusinessSource(cs.business_source, cs.label_business_source);
        const sourceLine = sourceDisplay
            ? `<div class="small text-muted mt-1">Source : ${sourceDisplay}</div>`
            : '';

        backendStatus.innerHTML = `
            <span class="${colorClass}">${formatDeviceType(device.type)} | ${driverDisplay} | ${statusValue}</span>
            ${sourceLine}
            <div class="small mt-1" style="color:#cbd5e1;">${message}</div>
            <div class="small mt-1" style="color:#94a3b8;">Host : ${hostValue}</div>
        `;
    }

    function normalizeHostByType(rawValue, typeValue) {
        const value = String(rawValue || '').trim();
        const normalizedType = String(typeValue || '').trim().toLowerCase();

        if (value === '') {
            return '';
        }

        if (/^https?:\/\//i.test(value)) {
            return value.replace(/\/+$/, '');
        }

        if (normalizedType === 'opnsense') {
            return `http://${value.replace(/^\/+/, '')}`;
        }

        return value;
    }

    function loadDeviceStatuses() {
        try {
            const raw = window.localStorage.getItem(STATUS_STORAGE_KEY);
            const parsed = raw ? JSON.parse(raw) : {};
            return parsed && typeof parsed === 'object' ? parsed : {};
        } catch (error) {
            console.error('STATUS LOAD ERROR:', error);
            return {};
        }
    }

    function saveDeviceStatuses() {
        try {
            window.localStorage.setItem(STATUS_STORAGE_KEY, JSON.stringify(deviceStatuses));
        } catch (error) {
            console.error('STATUS SAVE ERROR:', error);
        }
    }

    function normalizeDeviceStatus(statusValue) {
        const normalized = String(statusValue || 'offline').toLowerCase();
        if (normalized === 'active' || normalized === 'connected' || normalized === 'offline') {
            return normalized;
        }
        return 'offline';
    }

    function getStatusMeta(statusValue) {
        const normalized = normalizeDeviceStatus(statusValue);

        if (normalized === 'active') {
            return { label: 'Actif', className: 'bg-success' };
        }

        if (normalized === 'connected') {
            return { label: 'Connecté', className: 'bg-info text-dark' };
        }

        return { label: 'Hors ligne', className: 'bg-secondary' };
    }

    function getDisplayStatus(device) {
        const storedStatus = normalizeDeviceStatus(deviceStatuses[device.id || ''] || 'offline');

        if ((device.id || '') === activeDeviceId) {
            return storedStatus === 'offline' ? 'offline' : 'active';
        }

        if (storedStatus === 'active') {
            return 'connected';
        }

        return storedStatus;
    }

    function formatTableType(type) {
        return formatDeviceType(type);
    }

    function updateStoredDeviceStatus(deviceId, statusValue) {
        if (!deviceId) {
            return;
        }

        deviceStatuses[deviceId] = normalizeDeviceStatus(statusValue);
        saveDeviceStatuses();
    }

    function buildFullFormData() {
        const formData = new FormData();

        if (!form) {
            return formData;
        }

        form.querySelectorAll('[name]').forEach(field => {
            if (!field.name) {
                return;
            }

            if ((field.type === 'checkbox' || field.type === 'radio') && !field.checked) {
                return;
            }

            formData.set(field.name, field.value ?? '');
        });

        /* Ajoute le fingerprint si disponible (récupéré lors du test) */
        if (form.dataset.deviceFingerprint) {
            formData.set('device_fingerprint', form.dataset.deviceFingerprint);
            formData.set('hardware_info', form.dataset.hardwareInfo || '{}');
        }

        return formData;
    }

    function applyDeviceTypeRules() {
        if (!form || !typeField || !apiKeyField || !apiSecretField) {
            return;
        }

        const normalizedType = getSelectedDeviceType();
        const apiKeyWrapper = apiKeyField.closest('.input-group');
        const apiSecretLabel = apiSecretField.closest('.input-group')?.querySelector('.input-group-text');
        const apiKeyLabel = apiKeyField.closest('.input-group')?.querySelector('.input-group-text');
        const verifySslLabel = form.querySelector('[name="verify_ssl"]')?.closest('.input-group')?.querySelector('.input-group-text');
        const isRadius = normalizedType === 'radius';
        const isKnownType = normalizedType !== '';
        const isEditing = saveBtn ? !saveBtn.classList.contains('d-none') : false;

        if (apiKeyWrapper) {
            apiKeyWrapper.classList.toggle('d-none', isRadius);
        }

        apiKeyField.disabled = isRadius || !isEditing;
        apiKeyField.required = !isRadius;
        if (isRadius) {
            apiKeyField.value = '';
        }

        if (apiKeyLabel) {
            apiKeyLabel.textContent = normalizedType === 'mikrotik' ? 'Administrateur' : 'Clé API';
        }

        const hostField = form.querySelector('[name="host"]');
        if (hostField) {
            hostField.placeholder = normalizedType === 'opnsense' ? 'http://10.10.10.1' : '10.10.10.1';
        }

        apiSecretField.placeholder = isRadius ? 'Secret / Token optionnel' : '';
        if (apiSecretLabel) {
            apiSecretLabel.textContent = isRadius ? 'Secret' : (normalizedType === 'mikrotik' ? 'Mot de passe' : 'Secret API');
        }

        if (verifySslLabel) {
            verifySslLabel.textContent = 'Vérifier SSL';
        }

        if (testBtn) {
            const testUnsupported = !isKnownType || isRadius;
            testBtn.disabled = testUnsupported;
            testBtn.classList.toggle('disabled', testUnsupported);
            testBtn.title = !isKnownType
                ? 'Type de device invalide'
                : (isRadius ? 'Test de connexion indisponible pour ce type de device' : '');
        }

        if (activateBtn) {
            activateBtn.disabled = !currentDevice || !currentDevice.id || (currentDevice.id === activeDeviceId);
        }
    }

    if (newBtn && form) {
        newBtn.addEventListener("click", () => {
            selectedDeviceId = null;
            currentDevice = null;
            document.querySelectorAll('.device-row').forEach(row => row.classList.remove('table-active'));
            form.reset();
            form.querySelector('[name="id"]').value = '';
            if (typeField) {
                typeField.value = 'opnsense';
            }
            if (isActiveField) {
                isActiveField.value = activeDeviceId ? '0' : '1';
            }
            status.innerHTML = '<span class="text-muted">Aucun test effectué</span>';
            showDeviceForm();
            enableEditMode();
            applyDeviceTypeRules();
            console.log("NEW DEVICE MODE");
        });
    }

    // =========================
    // SAVE DEVICE
    // =========================
    if (form) {
        form.addEventListener("submit", function (e) {
            e.preventDefault();

            console.log("SUBMIT INTERCEPTED");

            if (!getSelectedDeviceType()) {
                AppToast.flash('Veuillez sélectionner un type de device.', 'warning');
                return;
            }

            // 🔥 FORCER CRÉATION SI ID VIDE OU MODE NEW
            const formData = buildFullFormData();
            const hostField = form.querySelector('[name="host"]');
            if (hostField) {
                const normalizedHost = normalizeHostByType(hostField.value, getSelectedDeviceType());
                hostField.value = normalizedHost;
                formData.set('host', normalizedHost);
            }

            console.log("ID VALUE:", form.querySelector('[name="id"]').value);
            console.log("SAVE DATA:", [...formData.entries()]);

            fetch(deviceApiUrl, {
                method: 'POST',
                body: formData
            })
            .then(async (res) => {
                const text = await res.text();
                console.log("RAW RESPONSE:", text);

                let json = null;
                try {
                    json = text ? JSON.parse(text) : null;
                } catch (e) {
                    console.error("INVALID JSON:", text);
                }

                return { ok: res.ok, json, text };
            })
            .then(({ ok, json, text }) => {
                if (!json || !ok || !json.success) {
                    console.error("SAVE FAILED:", json?.message || text);
                    AppToast.flash((json && json.message) || 'La sauvegarde du device a echoue.', 'danger');
                    return;
                }

                console.log("SAVE OK:", json);
                selectedDeviceId = json.id || null;
                activeDeviceId = json.active_device_id || activeDeviceId;
                renderBackendStatus(json.active_device || null, json.connection_state || null);

                if (json.license) {
                    deviceLicInfo = json.license;
                    if (json.license.status === 'active') {
                        /* Licencié → rechargement (l'API a déjà activé le device) */
                        window.location.reload();
                        return;
                    } else {
                        /* Pas encore licencié → ouvre directement le panneau d'activation */
                        renderLicensePanel(json.license, json.id);
                    }
                }

                loadDevices();
            })
            .catch(err => {
                console.error("SAVE ERROR:", err);
            });
        });
    }

    // =========================
    // LOAD BUTTON
    // =========================
    if (loadButton) {
        loadButton.addEventListener('click', loadDevices);
    }

    // =========================
    // AUTO LOAD
    // =========================
    loadDevices();

    // =========================
    // TEST API
    // =========================
    if (testBtn) {
        testBtn.addEventListener("click", function () {

            status.innerHTML = "Test en cours...\n";

            const formData = buildFullFormData();

            fetch('../api/test_device.php', {
                method: 'POST',
                body: formData
            })
            .then(res => res.json())
            .then(data => {

                status.innerHTML = '';
                const divider = '─'.repeat(36);

                if (data.success) {
                    /* ── SUCCÈS ── */
                    if (data.device_fingerprint) {
                        /* Stocke le fingerprint pour le save */
                        form.dataset.deviceFingerprint = data.device_fingerprint;
                        form.dataset.hardwareInfo      = JSON.stringify(data.hardware_info || {});
                        const hw    = data.hardware_info || {};
                        const sn    = hw.serial   || hw.hostname || hw.host || '';
                        const model = hw.board    || hw.product  || '';
                        const typeL = hw.type     || String(data.device_type || '');
                        const isAlreadyLicensed = (currentDevice?.license_status === 'active') || (currentDevice?.license_key !== '' && currentDevice?.license_key != null);
                        const endMsg = isAlreadyLicensed
                            ? `✅  Connexion réussie — Routeur licencié.`
                            : `ℹ️  Connexion réussie — Sauvegardez pour enregistrer ce routeur.`;

                        status.innerHTML =
                            (typeL ? `Type      : ${typeL}\n`               : '') +
                            (sn    ? `N° Série  : ${sn}\n`                  : '') +
                            (model ? `Modèle    : ${model}\n`               : '') +
                            `Device ID : ${data.device_id || '-'}\n` +
                            `${divider}\n` +
                            `${endMsg}\n`;
                    } else {
                        /* Succès mais pas de fingerprint (ex: RADIUS) */
                        status.innerHTML =
                            `${divider}\n` +
                            `✔  Connexion réussie\n` +
                            `${divider}\n` +
                            (data.log ? data.log + '\n' : '') +
                            `ℹ️  Sauvegardez pour enregistrer ce serveur.\n`;
                    }

                    /* Host suggéré différent */
                    if (data.suggested_host && form) {
                        const hostInput = form.querySelector('[name="host"]');
                        if (hostInput && String(hostInput.value || '').trim() !== String(data.suggested_host).trim()) {
                            hostInput.value = String(data.suggested_host).trim();
                            status.innerHTML += `⚠️  Host corrigé automatiquement : ${hostInput.value}\n`;
                        }
                    }

                } else {
                    /* ── ÉCHEC ── */
                    const rawLog   = String(data.log || '').trim();
                    const lines    = rawLog.split('\n').map(l => l.trim()).filter(Boolean);
                    const headline = lines[0] || 'Connexion impossible';
                    const details  = lines.slice(1).join('\n');

                    status.innerHTML =
                        `${divider}\n` +
                        `❌  Échec de connexion\n` +
                        `${divider}\n` +
                        `Cause     : ${headline}\n` +
                        (details ? `Détails   :\n${details}\n` : '') +
                        `${divider}\n` +
                        `⚠️  Vérifiez l'adresse IP, le port et les identifiants.\n`;
                }

                updateStoredDeviceStatus(
                    form.querySelector('[name="id"]').value || selectedDeviceId,
                    data.device_status || (data.success ? 'active' : 'offline')
                );

                renderBackendStatus({
                    type: form.querySelector('[name="type"]').value,
                    backend_driver: data.backend_driver || '',
                    host: form.querySelector('[name="host"]')?.value || ''
                }, {
                    supported: !!data.success,
                    status: data.success ? 'connected' : 'failed',
                    label: data.log || 'Test terminé'
                });

            })
            .catch(err => {
                status.innerHTML = "❌ ERROR\n";
                console.error(err);
            });
        });
    }

    // =========================
    // LOAD DEVICES
    // =========================
    function loadDevices() {
        fetch(deviceAdminLoadUrl + '&t=' + Date.now())
            .then(res => res.json())
            .then(data => {

                if (!data.devices) return;

                activeDeviceId = data.active_device_id || null;
                const activeDevice = data.active_device || data.devices.find(device => (device.id || '') === activeDeviceId) || null;
                if (!selectedDeviceId && activeDevice && activeDevice.id) {
                    selectedDeviceId = activeDevice.id;
                    currentDevice = activeDevice;
                }
                renderBackendStatus(activeDevice, data.connection_state || null);
                renderTable(data.devices);
            })
            .catch(err => console.error("LOAD ERROR:", err));
    }

    // =========================
    // FILL FORM
    // =========================
    function fillForm(device) {

        showDeviceForm();
        selectedDeviceId = device.id || null;
        currentDevice = device;
        const resolvedType = isValidDeviceType(device.type) ? String(device.type).trim().toLowerCase() : '';

        document.querySelector('[name="id"]').value = device.id || '';
        document.querySelector('[name="type"]').value = resolvedType;
        document.querySelector('[name="device_name"]').value = device.name || '';
        document.querySelector('[name="host"]').value = device.host || '';
        document.querySelector('[name="api_key"]').value = device.api_key || '';
        document.querySelector('[name="api_secret"]').value = device.api_secret || device.secret || '';
        document.querySelector('[name="verify_ssl"]').value =
            device.verify_ssl ? "true" : "false";
        if (isActiveField) {
            isActiveField.value = device.id && device.id === activeDeviceId ? "1" : "0";
        }

        disableEditMode();
        applyDeviceTypeRules();
    }

    function showDeviceForm() {
        if (emptyState) emptyState.classList.add('d-none');
        if (deviceContent) deviceContent.classList.remove('d-none');
    }

    function hideDeviceForm() {
        if (emptyState) emptyState.classList.remove('d-none');
        if (deviceContent) deviceContent.classList.add('d-none');
    }

    function enableEditMode() {
        document.querySelectorAll('[data-device-field="1"]').forEach(el => {
            el.disabled = false;
            el.classList.add('editable-active');
        });

        if (editBtn) editBtn.classList.add('d-none');
        if (saveBtn) saveBtn.classList.remove('d-none');
        if (cancelBtn) cancelBtn.classList.remove('d-none');
        if (deleteBtn && currentDevice && currentDevice.id) {
            deleteBtn.classList.remove('d-none');
        }
        if (activateBtn && currentDevice && currentDevice.id && currentDevice.id !== activeDeviceId) {
            activateBtn.classList.remove('d-none');
        }
    }

    function disableEditMode() {
        document.querySelectorAll('[data-device-field="1"]').forEach(el => {
            el.disabled = true;
            el.classList.remove('editable-active');
        });

        if (editBtn) editBtn.classList.remove('d-none');
        if (saveBtn) saveBtn.classList.add('d-none');
        if (cancelBtn) cancelBtn.classList.add('d-none');
        if (deleteBtn) deleteBtn.classList.add('d-none');
        if (activateBtn) activateBtn.classList.toggle('d-none', !currentDevice || !currentDevice.id || currentDevice.id === activeDeviceId);
    }

    if (editBtn) {
        editBtn.addEventListener('click', () => {
            if (!deviceContent || deviceContent.classList.contains('d-none')) {
                return;
            }
            enableEditMode();
            applyDeviceTypeRules();
        });
    }

    if (cancelBtn) {
        cancelBtn.addEventListener('click', () => {
            if (currentDevice) {
                fillForm(currentDevice);
                return;
            }

            form.reset();
            form.querySelector('[name="id"]').value = '';
            status.innerHTML = '<span class="text-muted">Aucun test effectué</span>';
            hideDeviceForm();
            disableEditMode();
            applyDeviceTypeRules();
        });
    }

    if (deleteBtn) {
        deleteBtn.addEventListener('click', () => {
            if (!currentDevice || !currentDevice.id) {
                return;
            }

            deleteDevice(currentDevice.id);
        });
    }
    // =========================
    // RENDER TABLE
    // =========================
    function renderTable(devices) {

        const tbody = document.getElementById("deviceTableBody");
        tbody.innerHTML = "";

        devices.forEach(device => {

            const tr = document.createElement("tr");
            tr.className = "device-row";
            tr.dataset.id = device.id || '';
            tr.dataset.active = (device.id || '') === activeDeviceId ? '1' : '0';
            const statusMeta = getStatusMeta(getDisplayStatus(device));

            tr.innerHTML = `
                <td>${device.name}</td>
                <td>${device.host}</td>
                <td class="device-type-cell">${formatTableType(device.type)}</td>
                <td>
                    <span class="badge ${statusMeta.className}">${statusMeta.label}</span>
                </td>
            `;

            if (selectedDeviceId && selectedDeviceId === (device.id || '')) {
                tr.classList.add('table-active');
                fillForm(device);
            }

            tr.addEventListener("click", () => {
                document.querySelectorAll('.device-row').forEach(row => row.classList.remove('table-active'));
                tr.classList.add('table-active');
                fillForm(device);
            });

            tbody.appendChild(tr);
        });
    }

    function deleteDevice(identifier) {
        if (!confirm("Supprimer ce device ?")) {
            return;
        }

        fetch(deviceApiUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `action=delete&id=${encodeURIComponent(identifier)}`
        })
        .then(res => res.json())
        .then(data => {
            if (!data.success) {
                AppToast.flash(data.message || 'La suppression du device a echoue.', 'danger');
                return;
            }

            if (selectedDeviceId === identifier) {
                selectedDeviceId = null;
                currentDevice = null;
                form.reset();
                form.querySelector('[name="id"]').value = '';
                if (typeField) {
                    typeField.value = 'opnsense';
                }
                status.innerHTML = '<span class="text-muted">Aucun test effectué</span>';
                hideDeviceForm();
                disableEditMode();
                applyDeviceTypeRules();
            }

            loadDevices();
        })
        .catch(err => console.error(err));
    }

    hideDeviceForm();
    disableEditMode();
    applyDeviceTypeRules();

    if (typeField) {
        typeField.addEventListener('change', applyDeviceTypeRules);
    }

    const hostField = form ? form.querySelector('[name="host"]') : null;
    if (hostField) {
        hostField.addEventListener('blur', () => {
            hostField.value = normalizeHostByType(hostField.value, getSelectedDeviceType());
        });
    }

    /* ── Panneau licence ── */
    /* Charge les infos de contact admin depuis la config */
    async function fetchAdminContact() {
        try {
            const res  = await fetch('../api/license/notification_config.php?action=get');
            const data = await res.json();
            if (!data.success) return {};
            return {
                phone: (data.config?.whatsapp?.admin_phone || '').replace(/[^0-9]/g, ''),
                email: data.config?.email?.to_email || '',
            };
        } catch (e) {
            return {};
        }
    }

    function buildContactMessage(deviceId, hw, clientName) {
        const sn    = (hw?.serial || '').trim();
        const model = (hw?.model  || '').trim();
        const type  = (hw?.type   || '').trim();
        const name  = (clientName || '').trim();

        const lines = [
            'Bonjour,',
            '',
            'Je souhaite activer mon routeur.',
            '',
            `Device ID : ${deviceId}`,
        ];
        if (sn)    lines.push(`N° Série  : ${sn}`);
        if (type)  lines.push(`Type      : ${type}`);
        if (model) lines.push(`Modèle    : ${model}`);
        if (name)  lines.push(`Nom       : ${name}`);
        lines.push('', 'Merci.');

        return lines.join('\n');
    }

    /* ── Référence unique au modal Bootstrap ── */
    const licModal   = document.getElementById('licenceModal');
    let   licBsModal = licModal ? new bootstrap.Modal(licModal) : null;

    /* CSRF depuis le champ injecté par PHP */
    function getPageCsrf() {
        return document.getElementById('pagecsrfToken')?.value || '';
    }

    async function renderLicensePanel(licenseInfo, storeDeviceId) {
        deviceLicInfo = licenseInfo;
        const status     = licenseInfo.status    || 'unlicensed';
        const deviceId   = licenseInfo.device_id || '-';
        const hw         = licenseInfo.hw        || {};
        const isLicensed = status === 'active';
        const csrfToken  = getPageCsrf();

        /* ── LICENCIÉ → ferme le modal + badge succès ── */
        if (isLicensed) {
            licBsModal?.hide();
            AppToast.flash('✅ Routeur activé — Device ID : ' + deviceId, 'success', 5000);
            return;
        }

        /* ── PAS DE FINGERPRINT → toast info ── */
        if (deviceId === '-' || status === 'no_fingerprint') {
            const hasLocalFp = !!(form?.dataset?.deviceFingerprint);
            AppToast.flash(
                hasLocalFp
                    ? 'Test effectué — sauvegardez d\'abord, puis cliquez "Activer".'
                    : 'Testez la connexion d\'abord pour identifier ce routeur.',
                'info'
            );
            return;
        }

        /* ── LICENCE REQUISE → remplissage + ouverture du modal ── */
        const contact  = await fetchAdminContact();
        const hasWa    = contact.phone !== '';
        const hasEmail = contact.email !== '';

        /* Méta du routeur */
        const devName = currentDevice?.name || 'Routeur';
        const setText = (id, val) => { const el = document.getElementById(id); if (el) el.textContent = val || '—'; };
        const showRow = (rowId, val) => { const r = document.getElementById(rowId); if (r) r.classList.toggle('d-none', !val); };

        setText('licModalDeviceName', devName);
        setText('licModalDeviceId',   deviceId);
        setText('licModalSn',    hw.serial || '');
        setText('licModalType',  hw.type   || '');
        setText('licModalModel', hw.model  || '');
        showRow('licModalSnRow',    hw.serial);
        showRow('licModalTypeRow',  hw.type);
        showRow('licModalModelRow', hw.model);

        const badge = document.getElementById('licModalBadge');
        if (badge) {
            badge.textContent  = licenseInfo.label || 'Sans licence';
            badge.className    = status === 'expired' ? 'badge bg-danger ms-2'
                               : status === 'invalid'  ? 'badge bg-danger ms-2'
                               : 'badge bg-warning text-dark ms-2';
        }

        /* Boutons de contact */
        const waBtn    = document.getElementById('licModalWaBtn');
        const emailBtn = document.getElementById('licModalEmailBtn');
        if (waBtn)    waBtn.classList.toggle('d-none', !hasWa);
        if (emailBtn) emailBtn.classList.toggle('d-none', !hasEmail);

        /* Helper message */
        function getModalMsg() {
            const name = document.getElementById('licModalClientName')?.value?.trim() || '';
            return buildContactMessage(deviceId, hw, name);
        }

        /* Helper envoi requête (email admin) */
        async function sendLicenseRequest() {
            const name = document.getElementById('licModalClientName')?.value?.trim() || '';
            const fd   = new FormData();
            fd.set('device_id',   deviceId);
            fd.set('client_name', name);
            try { await fetch('../api/license/request_license.php', { method: 'POST', body: fd }); }
            catch (e) { /* silencieux */ }
        }

        /* Clone pour supprimer les anciens listeners */
        function rebind(id, handler) {
            const el = document.getElementById(id);
            if (!el) return;
            const clone = el.cloneNode(true);
            el.parentNode.replaceChild(clone, el);
            clone.addEventListener('click', handler);
        }

        rebind('licModalCopyId', () => {
            copyToClipboard(deviceId)
                .then(() => AppToast.flash('Device ID copié !', 'success'))
                .catch(() => AppToast.flash('Erreur copie.', 'danger'));
        });

        rebind('licModalWaBtn', () => {
            const url = 'https://wa.me/' + contact.phone + '?text=' + encodeURIComponent(getModalMsg());
            window.open(url, '_blank');
            sendLicenseRequest();
        });

        rebind('licModalEmailBtn', () => {
            const subject = encodeURIComponent('Demande de licence — ' + deviceId);
            const body    = encodeURIComponent(getModalMsg());
            window.location.href = 'mailto:' + contact.email + '?subject=' + subject + '&body=' + body;
            sendLicenseRequest();
        });

        rebind('licModalCopyMsgBtn', () => {
            copyToClipboard(getModalMsg())
                .then(() => {
                    AppToast.flash('Message copié ! Collez-le et envoyez-le à l\'administrateur.', 'success', 5000);
                    sendLicenseRequest();
                })
                .catch(() => AppToast.flash('Erreur copie.', 'danger'));
        });

        rebind('licModalActivateBtn', async () => {
            const key = document.getElementById('licModalKeyInput')?.value?.trim();
            if (!key) { AppToast.flash('Collez la clé de licence reçue.', 'warning'); return; }

            const fd = new FormData();
            fd.set('store_device_id', storeDeviceId);
            fd.set('license_key',     key);
            fd.set('csrf_token',      getPageCsrf());

            try {
                const res  = await fetch('../api/license/activate_license.php', { method: 'POST', body: fd });
                const data = await res.json();
                if (data.success) {
                    renderLicensePanel({ status: 'active', device_id: deviceId, label: 'Licencié ✓', expiry: data.expiry }, storeDeviceId);
                    AppToast.flash('Routeur activé ! Rechargement…', 'success');
                    setTimeout(() => window.location.reload(), 1500);
                } else {
                    AppToast.flash(data.message || 'Activation impossible.', 'danger');
                }
            } catch (e) {
                AppToast.flash('Erreur réseau.', 'danger');
            }
        });

        /* Ouvre le modal */
        if (!licBsModal && licModal) { licBsModal = new bootstrap.Modal(licModal); }
        licBsModal?.show();
    }

    if (activateBtn) {
        activateBtn.addEventListener('click', () => {
            if (!currentDevice || !currentDevice.id) {
                return;
            }

            const body = new URLSearchParams({
                action: 'set_active',
                id: currentDevice.id,
            });

            fetch(deviceApiUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: body.toString(),
            })
            .then(res => res.json())
            .then(data => {
                if (!data.success) {
                    if (data.license_required && data.license) {
                        /* Affiche le panneau licence au lieu d'un simple toast */
                        AppToast.flash('Licence requise pour activer ce routeur.', 'warning', 5000);
                        renderLicensePanel(data.license, currentDevice.id);
                    } else {
                        AppToast.flash(data.message || 'L activation du device a echoue.', 'danger');
                    }
                    return;
                }

                window.location.reload();
            })
            .catch(err => console.error(err));
        });
    }
});
