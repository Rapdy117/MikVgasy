let isNew = false;
document.addEventListener("DOMContentLoaded", function () {

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
            <div class="small text-muted mt-1">${message}</div>
            <div class="small text-white-50 mt-1">Host : ${hostValue}</div>
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
            return `https://${value.replace(/^\/+/, '')}`;
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
            hostField.placeholder = normalizedType === 'opnsense' ? 'https://10.10.10.1' : '10.10.10.1';
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
                alert('Veuillez sélectionner un type de device.');
                return;
            }

            // 🔥 FORCER CRÉATION SI ID VIDE OU MODE NEW
            const formData = new FormData(form);
            const hostField = form.querySelector('[name="host"]');
            if (hostField) {
                const normalizedHost = normalizeHostByType(hostField.value, getSelectedDeviceType());
                hostField.value = normalizedHost;
                formData.set('host', normalizedHost);
            }

            console.log("ID VALUE:", form.querySelector('[name="id"]').value);
            console.log("SAVE DATA:", [...formData.entries()]);

            fetch('../api/network_devices_api.php', {
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
                    alert((json && json.message) || "La sauvegarde du device a echoue.");
                    return;
                }

                console.log("SAVE OK:", json);
                selectedDeviceId = json.id || null;
                activeDeviceId = json.active_device_id || activeDeviceId;
                renderBackendStatus(json.active_device || null, json.connection_state || null);
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

                status.innerHTML = ""; // reset

                status.innerHTML += data.log + "\n";

                status.innerHTML += data.success
                    ? "✔ SUCCESS\n"
                    : "❌ FAILED\n";

                if (data.success && data.suggested_host && form) {
                    const hostInput = form.querySelector('[name="host"]');
                    if (hostInput && String(hostInput.value || '').trim() !== String(data.suggested_host).trim()) {
                        hostInput.value = String(data.suggested_host).trim();
                        status.innerHTML += `ℹ Host recommandé détecté: ${hostInput.value}\n`;
                    }
                }

                updateStoredDeviceStatus(
                    form.querySelector('[name="id"]').value || selectedDeviceId,
                    data.device_status || (data.success ? 'active' : 'offline')
                );

                renderBackendStatus({
                    type: form.querySelector('[name="type"]').value,
                    backend_driver: data.backend_driver || ''
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
        fetch('../api/network_devices_api.php?t=' + Date.now())
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

        fetch('../api/network_devices_api.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `action=delete&id=${encodeURIComponent(identifier)}`
        })
        .then(res => res.json())
        .then(data => {
            if (!data.success) {
                alert(data.message || "La suppression du device a echoue.");
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

    if (activateBtn) {
        activateBtn.addEventListener('click', () => {
            if (!currentDevice || !currentDevice.id) {
                return;
            }

            const body = new URLSearchParams({
                action: 'set_active',
                id: currentDevice.id,
            });

            fetch('../api/network_devices_api.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: body.toString(),
            })
            .then(res => res.json())
            .then(data => {
                if (!data.success) {
                    alert(data.message || "L activation du device a echoue.");
                    return;
                }

                window.location.reload();
            })
            .catch(err => console.error(err));
        });
    }
});
