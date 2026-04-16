document.addEventListener('DOMContentLoaded', () => {
    const searchInput = document.getElementById('leasesSearchInput');
    const tableBody = document.getElementById('leasesTableBody');
    const rows = Array.from(document.querySelectorAll('#leasesTableBody tr[data-lease-id]'));
    const selectAll = document.getElementById('selectAllLeases');
    const csrfToken = document.getElementById('dhcpCsrfToken')?.value || '';
    const messageArea = document.getElementById('messageArea');
    const deleteBtn = document.getElementById('deleteLeaseBtn');
    const activeDeviceType = document.body?.dataset?.activeDeviceType || '';

    const isPermanentDisabled = (element) => element?.dataset?.permanentDisabled === '1';

    const getVisibleCheckboxes = () => rows
        .filter((row) => !row.classList.contains('d-none'))
        .map((row) => row.querySelector('.lease-select'))
        .filter((checkbox) => checkbox && !checkbox.disabled);

    const getSelectedCheckboxes = () => rows
        .map((row) => row.querySelector('.lease-select'))
        .filter((checkbox) => checkbox && checkbox.checked && !checkbox.disabled);

    const showMessage = (message, type = 'success') => {
        if (!messageArea) {
            return;
        }

        messageArea.innerHTML = `<div class="alert alert-${type} py-2 px-3 mb-3" role="alert">${message}</div>`;
        messageArea.style.display = 'block';
    };

    const updateActionButtons = () => {
        const hasSelection = getSelectedCheckboxes().length > 0;
        [deleteBtn].forEach((button) => {
            if (button) {
                if (!isPermanentDisabled(button)) {
                    button.disabled = !hasSelection;
                }
            }
        });

        if (selectAll) {
            if (isPermanentDisabled(selectAll)) {
                return;
            }
            const visible = getVisibleCheckboxes();
            selectAll.checked = visible.length > 0 && visible.every((checkbox) => checkbox.checked);
        }
    };

    const runAction = async (action, confirmMessage = '') => {
        const selected = getSelectedCheckboxes();
        if (selected.length === 0) {
            showMessage('Sélectionnez au moins un bail.', 'warning');
            return;
        }

        if (confirmMessage && !window.confirm(confirmMessage)) {
            return;
        }

        try {
            const payload = new URLSearchParams();
            payload.set('csrf_token', csrfToken);
            payload.set('action', action);
            selected.forEach((checkbox) => payload.append('lease_ids[]', checkbox.value));

            const response = await fetch('../api/dhcp_lease_action.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
                },
                body: payload.toString(),
            });

            const data = await response.json();
            if (!response.ok || !data.success) {
                throw new Error(data.message || 'Action impossible');
            }

            showMessage(data.message || 'Action effectuée.', 'success');
            window.setTimeout(() => window.location.reload(), 500);
        } catch (error) {
            showMessage(error.message || 'Erreur d’action.', 'danger');
        }
    };

    if (searchInput && rows.length > 0) {
        searchInput.addEventListener('input', () => {
            const query = searchInput.value.trim().toLowerCase();

            rows.forEach((row) => {
                const haystack = row.innerText.toLowerCase();
                row.classList.toggle('d-none', query !== '' && !haystack.includes(query));
            });

            updateActionButtons();
        });
    }

        if (selectAll) {
            selectAll.addEventListener('change', () => {
                if (isPermanentDisabled(selectAll)) {
                    return;
                }
                getVisibleCheckboxes().forEach((checkbox) => {
                    checkbox.checked = selectAll.checked;
                });
                updateActionButtons();
            });
        }

    if (tableBody) {
        tableBody.addEventListener('change', (event) => {
            if (event.target.closest('.lease-select')) {
                updateActionButtons();
            }
        });
    }

    deleteBtn?.addEventListener('click', () => runAction('delete', 'Supprimer les baux sélectionnés ?'));

    updateActionButtons();

    if (activeDeviceType === 'mikrotik') {
        tableBody?.addEventListener('click', (event) => {
            const target = event.target;
            if (target.closest('.lease-select')) {
                return;
            }

            const row = target.closest('tr[data-lease-id]');
            if (!row) {
                return;
            }

            const params = new URLSearchParams();
            params.set('lease_id', row.dataset.leaseId || '');
            params.set('address', row.dataset.address || '');
            params.set('mac', row.dataset.mac || '');
            params.set('host_name', row.dataset.host_name || '');
            params.set('server', row.dataset.server || '');
            params.set('comment', row.dataset.comment || '');
            params.set('disabled', row.dataset.disabled || '0');

            window.location.href = `add_dhcp_lease.php?${params.toString()}`;
        });
    }
});
