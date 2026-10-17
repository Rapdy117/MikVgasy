document.addEventListener('DOMContentLoaded', () => {
    const form = document.getElementById('addIpBindingForm');
    const saveBtn = document.getElementById('saveBtn');
    const deleteBtn = document.getElementById('deleteBtn');
    const deleteBindingModalElement = document.getElementById('deleteBindingModal');
    const confirmDeleteBindingBtn = document.getElementById('confirmDeleteBindingBtn');
    const deleteMatchingDhcpLease = document.getElementById('deleteMatchingDhcpLease');
    const deleteBindingMessage = document.getElementById('deleteBindingMessage');
    const dhcpLeaseSyncModalElement = document.getElementById('dhcpLeaseSyncModal');
    const dhcpLeaseSyncForm = document.getElementById('dhcpLeaseSyncForm');
    const skipDhcpSyncBtn = document.getElementById('skipDhcpSyncBtn');
    const dhcpLeaseSyncMessage = document.getElementById('dhcpLeaseSyncMessage');
    const syncDhcpLeaseBtn = document.getElementById('syncDhcpLeaseBtn');
    const mode = window.ipBindingEditorConfig?.mode || 'create';
    const targetDeviceType = window.ipBindingEditorConfig?.device_type || window.ipBindingEditorConfig?.backend || 'mikrotik';

    if (!form) {
        return;
    }

    const showMessage = (message, type = 'success') => {
        window.AppToast?.flash(message, type);
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
    const csrfToken = form.querySelector('[name="csrf_token"]')?.value || '';
    const dhcpLeaseSyncModal = dhcpLeaseSyncModalElement && window.bootstrap
        ? new bootstrap.Modal(dhcpLeaseSyncModalElement)
        : null;
    const deleteBindingModal = deleteBindingModalElement && window.bootstrap
        ? new bootstrap.Modal(deleteBindingModalElement)
        : null;

    const redirectToBindings = () => {
        window.location.href = 'ip_bindings.php';
    };

    const showDhcpSync = () => {
        if (!dhcpLeaseSyncModal || !dhcpLeaseSyncForm) {
            redirectToBindings();
            return;
        }

        const address = form.querySelector('[name="address"]')?.value || '';
        const mac = form.querySelector('[name="mac"]')?.value || '';
        dhcpLeaseSyncForm.querySelector('[name="address"]').value = address;
        dhcpLeaseSyncForm.querySelector('[name="mac"]').value = mac;
        dhcpLeaseSyncForm.querySelector('[name="comment"]').value = form.querySelector('[name="comment"]')?.value || '';
        const bindingDisabled = form.querySelector('[name="disabled"]');
        const dhcpDisabled = dhcpLeaseSyncForm.querySelector('[name="disabled"]');
        if (dhcpDisabled) {
            dhcpDisabled.checked = Boolean(bindingDisabled?.checked);
        }
        if (dhcpLeaseSyncMessage) {
            dhcpLeaseSyncMessage.textContent = '';
        }
        dhcpLeaseSyncModal.show();
    };

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
            if (targetDeviceType === 'mikrotik'
                && form.querySelector('[name="address"]')?.value
                && form.querySelector('[name="mac"]')?.value) {
                showDhcpSync();
            } else {
                window.setTimeout(redirectToBindings, 700);
            }
        } catch (error) {
            showMessage(error.message || 'Erreur d’enregistrement.', 'danger');
        } finally {
            saveBtn.disabled = false;
        }
    });

    dhcpLeaseSyncForm?.addEventListener('submit', async (event) => {
        event.preventDefault();
        if (!syncDhcpLeaseBtn) {
            return;
        }

        syncDhcpLeaseBtn.disabled = true;
        try {
            const payload = new FormData(dhcpLeaseSyncForm);
            payload.set('csrf_token', csrfToken);
            const response = await fetch('../api/create_mikrotik_dhcp_lease.php', {
                method: 'POST',
                body: payload,
            });
            const data = await response.json();
            if (!response.ok || !data.success) {
                throw new Error(data.message || 'Synchronisation DHCP impossible.');
            }

            showMessage(data.message || 'Lease DHCP fixe synchronisé.', 'success');
            dhcpLeaseSyncModal?.hide();
            window.setTimeout(redirectToBindings, 700);
        } catch (error) {
            if (dhcpLeaseSyncMessage) {
                dhcpLeaseSyncMessage.textContent = error.message || 'Synchronisation DHCP impossible.';
                dhcpLeaseSyncMessage.className = 'small mt-3 text-danger';
            }
        } finally {
            syncDhcpLeaseBtn.disabled = false;
        }
    });

    skipDhcpSyncBtn?.addEventListener('click', () => {
        window.setTimeout(redirectToBindings, 150);
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

        if (!deleteBindingModal) {
            showMessage('Fenêtre de confirmation indisponible.', 'danger');
            return;
        }

        const displayValue = (selector, value) => {
            const element = document.querySelector(selector);
            if (element) element.textContent = value || '-';
        };
        displayValue('#deleteBindingAddress', form.querySelector('[name="address"]')?.value);
        displayValue('#deleteBindingMac', form.querySelector('[name="mac"]')?.value);
        displayValue('#deleteBindingComment', form.querySelector('[name="comment"]')?.value);
        if (deleteBindingMessage) deleteBindingMessage.textContent = '';
        deleteBindingModal.show();
    });

    confirmDeleteBindingBtn?.addEventListener('click', async () => {
        const bindingId = form.querySelector('[name="binding_id"]')?.value || '';
        const csrfToken = form.querySelector('[name="csrf_token"]')?.value || '';
        const originalZoneUuid = form.querySelector('[name="original_zone_uuid"]')?.value || '';
        const originalValue = form.querySelector('[name="original_value"]')?.value || '';
        const originalKind = form.querySelector('[name="original_kind"]')?.value || '';

        if (targetDeviceType === 'opnsense' ? (!originalZoneUuid || !originalValue || !originalKind) : !bindingId) {
            showMessage('Binding introuvable.', 'danger');
            return;
        }

        confirmDeleteBindingBtn.disabled = true;

        try {
            const payload = new URLSearchParams();
            payload.set('csrf_token', csrfToken);
            if (targetDeviceType === 'opnsense') {
                payload.set('zone_uuid', originalZoneUuid);
                payload.set('binding_value', originalValue);
                payload.set('binding_kind', originalKind);
            } else {
                payload.set('binding_id', bindingId);
                if (deleteMatchingDhcpLease?.checked) {
                    payload.set('delete_dhcp_lease', '1');
                }
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
            deleteBindingModal?.hide();
            window.setTimeout(() => {
                window.location.href = 'ip_bindings.php';
            }, 700);
        } catch (error) {
            if (deleteBindingMessage) {
                deleteBindingMessage.textContent = error.message || 'Erreur de suppression.';
                deleteBindingMessage.className = 'small mt-3 text-danger';
            }
        } finally {
            confirmDeleteBindingBtn.disabled = false;
        }
    });
});
