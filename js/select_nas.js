document.addEventListener("DOMContentLoaded", function () {
    const select = document.getElementById('nasSelect');
    const nasIdInput = document.getElementById('nasIdInput');
    const rateLimitLabel = document.getElementById('profileRateLimitLabel');
    const rateLimitInput = document.getElementById('profileRateLimitInput');
    const form = document.getElementById('profileForm');
    const addressPoolSelect = document.getElementById('addressPoolSelect');
    const parentQueueSelect = document.getElementById('parentQueueSelect');
    const initialAddressPool = form?.dataset?.initialAddressPool || '';
    const initialParentQueue = form?.dataset?.initialParentQueue || '';
    const initialDeviceId = form?.dataset?.initialDeviceId || '';

    let deviceList = [];
    let nasMappingsByDeviceId = {};

    if (!select) {
        return;
    }

    function normalizeDeviceType(type) {
        return String(type || '').trim().toLowerCase();
    }

    function resolveDefaultCapabilities(deviceType) {
        const type = normalizeDeviceType(deviceType);
        const base = ['Session-Timeout', 'Idle-Timeout', 'Simultaneous-Use', 'Max-Octets'];

        if (type === 'mikrotik') {
            return [...base, 'Mikrotik-Rate-Limit'];
        }

        if (type === 'opnsense' || type === 'radius' || type === 'freeradius') {
            return [...base, 'WISPr-Bandwidth-Max-Down', 'WISPr-Bandwidth-Max-Up'];
        }

        return base;
    }

    async function parseJsonResponse(response) {
        const text = await response.text();
        let data = null;

        try {
            data = text !== '' ? JSON.parse(text) : null;
        } catch (error) {
            throw new Error(text || 'Reponse JSON invalide');
        }

        if (!response.ok) {
            throw new Error(data?.message || text || 'Erreur de chargement');
        }

        if (!data || typeof data !== 'object') {
            throw new Error(text || 'Reponse JSON vide');
        }

        return data;
    }

    function setSelectOptions(selectNode, items, placeholder, selectedValue) {
        if (!selectNode) {
            return;
        }

        selectNode.innerHTML = '';
        const placeholderOption = document.createElement('option');
        placeholderOption.value = '';
        placeholderOption.textContent = placeholder;
        selectNode.appendChild(placeholderOption);

        items.forEach((item) => {
            const option = document.createElement('option');
            option.value = item;
            option.textContent = item;
            selectNode.appendChild(option);
        });

        if (selectedValue) {
            selectNode.value = selectedValue;
        }
    }

    async function loadMikrotikOptions(deviceId) {
        if (!addressPoolSelect && !parentQueueSelect) {
            return;
        }

        try {
            const response = await fetch(`../api/profiles/mikrotik_options.php?device_id=${encodeURIComponent(deviceId)}`, {
                credentials: 'same-origin',
            });
            const data = await parseJsonResponse(response);
            if (data.success !== true) {
                throw new Error(data.message || 'Chargement MikroTik impossible');
            }

            setSelectOptions(addressPoolSelect, data.address_pools || [], '-- Choisir --', initialAddressPool);
            setSelectOptions(parentQueueSelect, data.parent_queues || [], '-- Choisir --', initialParentQueue);
            if (addressPoolSelect) {
                addressPoolSelect.disabled = false;
            }
            if (parentQueueSelect) {
                parentQueueSelect.disabled = false;
            }
        } catch (error) {
            setSelectOptions(addressPoolSelect, [], '-- Choisir --', '');
            setSelectOptions(parentQueueSelect, [], '-- Choisir --', '');
            if (addressPoolSelect) {
                addressPoolSelect.disabled = true;
            }
            if (parentQueueSelect) {
                parentQueueSelect.disabled = true;
            }
        }
    }

    function setCapabilityFieldState(wrapper, enabled) {
        if (!wrapper) {
            return;
        }

        wrapper.classList.toggle('d-none', !enabled);

        wrapper.querySelectorAll('input, select, textarea').forEach((field) => {
            if (!field.name) {
                return;
            }

            field.disabled = !enabled;
            if (!enabled) {
                field.value = '';
            }
        });
    }

    function getSelectedDeviceInfo() {
        const selectedDevice = deviceList.find((device) => String(device.device_id || '') === String(select.value));
        if (!selectedDevice) {
            return null;
        }

        const mapping = nasMappingsByDeviceId[String(selectedDevice.device_id || '')] || null;
        const deviceType = normalizeDeviceType(selectedDevice.device_type || selectedDevice.type);

        return {
            ...selectedDevice,
            nas_id: mapping ? Number(mapping.nas_id || 0) : 0,
            nas_type: normalizeDeviceType(mapping?.nas_type || deviceType),
            type: normalizeDeviceType(mapping?.nas_type || deviceType),
            capabilities: Array.isArray(mapping?.capabilities) && mapping.capabilities.length > 0
                ? mapping.capabilities
                : resolveDefaultCapabilities(deviceType),
        };
    }

    function syncSelectedNasHiddenFields() {
        if (!select || !nasIdInput) {
            return;
        }

        const selected = getSelectedDeviceInfo();
        nasIdInput.value = selected && selected.nas_id > 0 ? String(selected.nas_id) : '';
    }

    function applyNasCapabilities() {
        syncSelectedNasHiddenFields();
        const selected = getSelectedDeviceInfo();

        if (!selected) {
            document.querySelectorAll('[data-capability]').forEach((wrapper) => {
                setCapabilityFieldState(wrapper, false);
            });
            setCapabilityFieldState(document.querySelector('[data-rate-limit-group]'), false);
            setSelectOptions(addressPoolSelect, [], '-- Choisir --', '');
            setSelectOptions(parentQueueSelect, [], '-- Choisir --', '');
            if (addressPoolSelect) {
                addressPoolSelect.disabled = true;
            }
            if (parentQueueSelect) {
                parentQueueSelect.disabled = true;
            }
            return;
        }

        const capabilities = selected.capabilities ?? [];
        const deviceType = normalizeDeviceType(selected.device_type || selected.type);

        document.querySelectorAll('[data-capability]').forEach((wrapper) => {
            const capability = wrapper.dataset.capability;
            setCapabilityFieldState(wrapper, capabilities.includes(capability));
        });

        const rateGroup = document.querySelector('[data-rate-limit-group]');
        const supportsRateLimit = capabilities.includes('Mikrotik-Rate-Limit')
            || (capabilities.includes('WISPr-Bandwidth-Max-Down') && capabilities.includes('WISPr-Bandwidth-Max-Up'));

        setCapabilityFieldState(rateGroup, supportsRateLimit);

        if (rateLimitLabel && rateLimitInput) {
            if (deviceType === 'mikrotik') {
                rateLimitLabel.textContent = 'Rate MikroTik';
                rateLimitInput.placeholder = '2M/2M';
            } else {
                rateLimitLabel.textContent = 'Rate';
                rateLimitInput.placeholder = '2M/2M';
            }
        }

        if (deviceType === 'mikrotik' && selected.device_id) {
            loadMikrotikOptions(selected.device_id);
        } else {
            setSelectOptions(addressPoolSelect, [], '-- Choisir --', '');
            setSelectOptions(parentQueueSelect, [], '-- Choisir --', '');
            if (addressPoolSelect) {
                addressPoolSelect.disabled = true;
            }
            if (parentQueueSelect) {
                parentQueueSelect.disabled = true;
            }
        }
    }

    function hydrateDeviceSelect(payload) {
        const devices = Array.isArray(payload?.devices) ? payload.devices : [];
        const activeDeviceId = String(payload?.active_device_id || document.body?.dataset?.activeDeviceId || '');

        deviceList = devices.map((device) => ({
            device_id: String(device.id || ''),
            label: `${device.name || device.id || 'Device'} (${device.ip || device.host || device.type || 'inconnu'})`,
            device_type: normalizeDeviceType(device.type),
            business_source: String(device.business_source || '').toLowerCase(),
            backend_driver: String(device.backend_driver || device.backend || '').toLowerCase(),
            raw: device,
        })).filter((device) => device.device_id !== '');

        select.innerHTML = '<option value="">-- Choisir un serveur --</option>';

        deviceList.forEach((device) => {
            const option = document.createElement('option');
            option.value = device.device_id;
            option.textContent = device.label;
            select.appendChild(option);
        });

        if (initialDeviceId && deviceList.some((device) => device.device_id === String(initialDeviceId))) {
            select.value = String(initialDeviceId);
        } else if (activeDeviceId && deviceList.some((device) => device.device_id === activeDeviceId)) {
            select.value = activeDeviceId;
        } else if (deviceList.length > 0) {
            select.value = deviceList[0].device_id;
        }

        syncSelectedNasHiddenFields();
        applyNasCapabilities();
    }

    function hydrateNasMappings(responseData) {
        nasMappingsByDeviceId = {};

        (Array.isArray(responseData) ? responseData : []).forEach((item) => {
            const deviceId = String(item.device_id || '');
            if (deviceId === '') {
                return;
            }

            nasMappingsByDeviceId[deviceId] = {
                nas_id: Number(item.nas_id || 0),
                nas_type: normalizeDeviceType(item.nas_type || item.device_type || ''),
                capabilities: Array.isArray(item.capabilities) ? item.capabilities : [],
            };
        });

        syncSelectedNasHiddenFields();
        applyNasCapabilities();
    }

    async function init() {
        try {
            const deviceResponse = await fetch('../api/network_devices_api.php', {
                credentials: 'same-origin',
            });
            const devicePayload = await parseJsonResponse(deviceResponse);

            if (!Array.isArray(devicePayload.devices)) {
                throw new Error(devicePayload.message || 'Serveurs introuvables');
            }

            hydrateDeviceSelect(devicePayload);
        } catch (error) {
            console.error('Erreur devices:', error);
            return;
        }

        try {
            const nasResponse = await fetch('../api/nas.php', {
                credentials: 'same-origin',
            });
            const nasPayload = await parseJsonResponse(nasResponse);
            if (nasPayload.success === true && Array.isArray(nasPayload.data)) {
                hydrateNasMappings(nasPayload.data);
            }
        } catch (error) {
            syncSelectedNasHiddenFields();
            applyNasCapabilities();
        }
    }

    select.addEventListener('change', applyNasCapabilities);

    if (form) {
        form.addEventListener('submit', function (e) {
            e.preventDefault();

            syncSelectedNasHiddenFields();
            const formData = new FormData(form);

            fetch('../api/profiles/create_profile.php', {
                method: 'POST',
                body: formData,
                credentials: 'same-origin',
            })
                .then(async (res) => {
                    const data = await parseJsonResponse(res);
                    return data;
                })
                .then((data) => {
                    if (data.success) {
                        alert(data.message || 'Profil cree');
                        const profileIdValue = Number(formData.get('profile_id') || 0);
                        if (profileIdValue <= 0) {
                            const preservedDeviceId = select.value;
                            form.reset();
                            if (preservedDeviceId && deviceList.some((device) => device.device_id === preservedDeviceId)) {
                                select.value = preservedDeviceId;
                            } else if (deviceList.length > 0) {
                                select.value = deviceList[0].device_id;
                            }
                            applyNasCapabilities();
                        } else {
                            const oldNameInput = form.querySelector('input[name="old_profile_name"]');
                            const nameInput = form.querySelector('input[name="profile_name"]');
                            if (oldNameInput && nameInput) {
                                oldNameInput.value = nameInput.value;
                            }
                        }
                    } else {
                        alert(data.message || 'Creation impossible');
                    }
                })
                .catch((err) => {
                    console.error('Erreur profil:', err);
                    alert(err.message || 'Erreur serveur');
                });
        });
    }

    init();
});
