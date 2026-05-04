document.addEventListener('DOMContentLoaded', () => {
    const form = document.getElementById('addIpBindingForm');
    const messageArea = document.getElementById('messageArea');
    const saveBtn = document.getElementById('saveBtn');
    const deleteBtn = document.getElementById('deleteBtn');
    const mode = window.ipBindingEditorConfig?.mode || 'create';
    const targetDeviceType = window.ipBindingEditorConfig?.device_type || window.ipBindingEditorConfig?.backend || 'mikrotik';

    if (!form) {
        return;
    }

    const showMessage = (message, type = 'success') => {
        if (!messageArea) {
            return;
        }

        messageArea.innerHTML = `<div class="alert alert-${type} py-2 px-3 mb-3" role="alert">${message}</div>`;
        messageArea.style.display = 'block';
    };

    const normalizeMac = (value) => {
        const compact = String(value || '')
            .trim()
            .replace(/[^0-9a-fA-F]/g, '')
            .toUpperCase();

        if (compact.length !== 12) {
            return String(value || '').trim().toUpperCase();
        }

        return compact.match(/.{1,2}/g)?.join(':') || compact;
    };

    const macInput = form.querySelector('[name="mac"]');
    const bindingValueInput = form.querySelector('[name="binding_value"]');

    const normalizeBindingValue = (value) => {
        const raw = String(value || '').trim();
        const compact = raw.replace(/[^0-9a-fA-F]/g, '');
        if (compact.length === 12) {
            return normalizeMac(raw);
        }
        return raw;
    };

    macInput?.addEventListener('blur', () => {
        macInput.value = normalizeMac(macInput.value);
    });

    bindingValueInput?.addEventListener('blur', () => {
        bindingValueInput.value = normalizeBindingValue(bindingValueInput.value);
    });

    form.addEventListener('submit', async (event) => {
        event.preventDefault();

        if (macInput) {
            macInput.value = normalizeMac(macInput.value);
        }

        if (bindingValueInput) {
            bindingValueInput.value = normalizeBindingValue(bindingValueInput.value);
        }

        saveBtn.disabled = true;

        try {
            let endpoint = '../api/create_mikrotik_ip_binding.php';
            if (targetDeviceType === 'opnsense') {
                endpoint = mode === 'edit'
                    ? '../api/update_opnsense_ip_binding.php'
                    : '../api/create_opnsense_ip_binding.php';
            } else {
                endpoint = mode === 'edit'
                    ? '../api/update_mikrotik_ip_binding.php'
                    : '../api/create_mikrotik_ip_binding.php';
            }

            const response = await fetch(endpoint, {
                method: 'POST',
                body: new FormData(form),
            });

            const data = await response.json();
            if (!response.ok || !data.success) {
                throw new Error(data.message || 'Enregistrement impossible');
            }

            showMessage(data.message || 'IP binding enregistré.', 'success');
            window.setTimeout(() => {
                window.location.href = 'ip_bindings.php';
            }, 700);
        } catch (error) {
            showMessage(error.message || 'Erreur d’enregistrement.', 'danger');
        } finally {
            saveBtn.disabled = false;
        }
    });

    deleteBtn?.addEventListener('click', async () => {
        const bindingId = form.querySelector('[name="binding_id"]')?.value || '';
        const csrfToken = form.querySelector('[name="csrf_token"]')?.value || '';
        const originalZoneUuid = form.querySelector('[name="original_zone_uuid"]')?.value || '';
        const originalValue = form.querySelector('[name="original_value"]')?.value || '';
        const originalKind = form.querySelector('[name="original_kind"]')?.value || '';

        if (targetDeviceType === 'opnsense' ? (!originalZoneUuid || !originalValue || !originalKind) : !bindingId) {
            showMessage('Binding introuvable.', 'danger');
            return;
        }

        if (!window.confirm('Supprimer ce binding ?')) {
            return;
        }

        deleteBtn.disabled = true;

        try {
            const payload = new URLSearchParams();
            payload.set('csrf_token', csrfToken);
            if (targetDeviceType === 'opnsense') {
                payload.set('zone_uuid', originalZoneUuid);
                payload.set('binding_value', originalValue);
                payload.set('binding_kind', originalKind);
            } else {
                payload.set('binding_id', bindingId);
            }

            const endpoint = targetDeviceType === 'opnsense'
                ? '../api/delete_opnsense_ip_binding.php'
                : '../api/delete_mikrotik_ip_binding.php';

            const response = await fetch(endpoint, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
                },
                body: payload.toString(),
            });

            const data = await response.json();
            if (!response.ok || !data.success) {
                throw new Error(data.message || 'Suppression impossible');
            }

            showMessage(data.message || 'IP binding supprimé.', 'success');
            window.setTimeout(() => {
                window.location.href = 'ip_bindings.php';
            }, 700);
        } catch (error) {
            showMessage(error.message || 'Erreur de suppression.', 'danger');
        } finally {
            deleteBtn.disabled = false;
        }
    });
});
