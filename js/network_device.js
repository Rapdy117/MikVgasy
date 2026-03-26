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
    const activeBadge = document.getElementById("activeDeviceBadge");
    const backendStatus = document.getElementById("deviceBackendStatus");
    const typeField = form ? form.querySelector('[name="type"]') : null;
    const apiKeyField = form ? form.querySelector('[name="api_key"]') : null;
    const apiSecretField = form ? form.querySelector('[name="api_secret"]') : null;
    const isActiveField = form ? form.querySelector('[name="is_active"]') : null;
    let selectedDeviceId = null;
    let currentDevice = null;
    let activeDeviceId = null;

    function formatDeviceType(type) {
        const normalized = String(type || 'opnsense').toLowerCase();

        if (normalized === 'mikrotik') {
            return 'MikroTik';
        }

        if (normalized === 'other' || normalized === 'radius') {
            return 'Autre';
        }

        return 'OPNsense';
    }

    function updateActiveBadge(device) {
        if (!activeBadge) {
            return;
        }

        if (!device) {
            activeBadge.textContent = 'Actif: aucun';
            return;
        }

        const label = [
            device.name || device.ip || device.host || formatDeviceType(device.type),
            device.ip || device.host || '',
            formatDeviceType(device.type),
        ].filter(Boolean).join(' | ');
        activeBadge.textContent = `Actif: ${label}`;
    }

    function renderBackendStatus(device, connectionState) {
        if (!backendStatus) {
            return;
        }

        if (!device) {
            backendStatus.innerHTML = '<span class="text-muted">Aucun device actif</span>';
            return;
        }

        const statusValue = String(connectionState?.status || 'unknown').toUpperCase();
        const supported = connectionState?.supported === true;
        const colorClass = supported ? 'text-success' : 'text-warning';
        const message = connectionState?.label || 'Backend inconnu';

        backendStatus.innerHTML = `
            <span class="${colorClass}">${formatDeviceType(device.type)} | ${device.backend || 'generic'} | ${statusValue}</span>
            <div class="small text-muted mt-1">${message}</div>
        `;
    }

    function applyDeviceTypeRules() {
        if (!form || !typeField || !apiKeyField || !apiSecretField) {
            return;
        }

        const normalizedType = String(typeField.value || 'opnsense').toLowerCase();
        const apiKeyWrapper = apiKeyField.closest('.input-group');
        const apiSecretLabel = apiSecretField.closest('.input-group')?.querySelector('.input-group-text');
        const isOther = normalizedType === 'other' || normalizedType === 'radius';
        const isEditing = saveBtn ? !saveBtn.classList.contains('d-none') : false;

        if (apiKeyWrapper) {
            apiKeyWrapper.classList.toggle('d-none', isOther);
        }

        apiKeyField.disabled = isOther || !isEditing;
        apiKeyField.required = !isOther;
        if (isOther) {
            apiKeyField.value = '';
        }

        apiSecretField.placeholder = isOther ? 'Secret / Token optionnel' : '';
        if (apiSecretLabel) {
            apiSecretLabel.textContent = isOther ? 'Secret' : 'API Secret';
        }

        if (testBtn) {
            testBtn.disabled = isOther;
            testBtn.classList.toggle('disabled', isOther);
            testBtn.title = isOther ? 'Test API indisponible pour ce type de device' : '';
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
            form.querySelector('[name="type"]').value = 'opnsense';
            if (isActiveField) {
                isActiveField.value = activeDeviceId ? '0' : '1';
            }
            status.innerHTML = '<span class="text-muted">No test yet</span>';
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

            // 🔥 FORCER CRÉATION SI ID VIDE OU MODE NEW
            const formData = new FormData(form);

            console.log("ID VALUE:", form.querySelector('[name="id"]').value);
            console.log("SAVE DATA:", [...formData.entries()]);

            fetch('../api/network_devices_api.php', {
                method: 'POST',
                body: formData
            })
            .then(res => res.text())
            .then(data => {

                console.log("RAW RESPONSE:", data);

                let json;

                try {
                    json = JSON.parse(data);
                } catch (e) {
                    console.error("INVALID JSON:", data);
                    return;
                }

                if (!json.success) {
                    console.error("SAVE FAILED:", json.message);
                    alert("Erreur: " + (json.message || "Unknown error"));
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

            status.innerHTML = "Testing...\n";

            const formData = new FormData(form);

            fetch('../api/test_opnsense.php', {
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

                renderBackendStatus({
                    type: form.querySelector('[name="type"]').value,
                    backend: data.backend || 'generic'
                }, {
                    supported: !!data.success,
                    status: data.success ? 'connected' : 'failed',
                    label: data.log || 'Test termine'
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
                updateActiveBadge(activeDevice);
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

        document.querySelector('[name="id"]').value = device.id || '';
        document.querySelector('[name="type"]').value = device.type || 'opnsense';
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
            status.innerHTML = '<span class="text-muted">No test yet</span>';
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

            tr.innerHTML = `
                <td>${device.name}</td>
                <td>${device.host}</td>
                <td>
                    <span class="type-badge type-${String(device.type || 'opnsense').toLowerCase()}">${formatDeviceType(device.type)}</span>
                    ${(device.id || '') === activeDeviceId ? '<span class="badge bg-success ms-2">Actif</span>' : ''}
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
                alert("Erreur: " + (data.message || "Suppression impossible"));
                return;
            }

            if (selectedDeviceId === identifier) {
                selectedDeviceId = null;
                currentDevice = null;
                form.reset();
                form.querySelector('[name="id"]').value = '';
                form.querySelector('[name="type"]').value = 'opnsense';
                status.innerHTML = '<span class="text-muted">No test yet</span>';
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
                    alert("Erreur: " + (data.message || "Activation impossible"));
                    return;
                }

                activeDeviceId = data.active_device_id || currentDevice.id;
                updateActiveBadge(data.active_device || currentDevice);
                renderBackendStatus(data.active_device || currentDevice, data.connection_state || null);
                loadDevices();
            })
            .catch(err => console.error(err));
        });
    }
});
