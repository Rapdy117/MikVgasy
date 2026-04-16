document.addEventListener('DOMContentLoaded', () => {
    const tableBody = document.getElementById('hostsTableBody');
    const rows = Array.from(document.querySelectorAll('#hostsTableBody tr[data-host-id]'));
    const messageArea = document.getElementById('messageArea');
    const csrfToken = document.getElementById('hostsCsrfToken')?.value || '';
    const searchInput = document.getElementById('hostsSearchInput');
    const statusFilter = document.getElementById('hostsStatusFilter');
    const selectAll = document.getElementById('selectAllHosts');
    const deleteBtn = document.getElementById('deleteHostsBtn');
    const refreshBtn = document.getElementById('refreshHostsBtn');

    const showMessage = (message, type = 'success') => {
        if (!messageArea) {
            return;
        }

        messageArea.innerHTML = `<div class="alert alert-${type} py-2 px-3 mb-3" role="alert">${message}</div>`;
        messageArea.style.display = 'block';
    };

    const getVisibleRows = () => rows.filter((row) => !row.classList.contains('d-none'));
    const getVisibleCheckboxes = () => getVisibleRows()
        .map((row) => row.querySelector('.host-select'))
        .filter(Boolean);

    const getSelectedCheckboxes = () => rows
        .map((row) => row.querySelector('.host-select'))
        .filter((checkbox) => checkbox && checkbox.checked);

    const updateActionState = () => {
        const selectedCount = getSelectedCheckboxes().length;
        if (deleteBtn) {
            deleteBtn.disabled = selectedCount === 0;
        }

        if (selectAll) {
            const visible = getVisibleCheckboxes();
            selectAll.checked = visible.length > 0 && visible.every((checkbox) => checkbox.checked);
        }
    };

    const applyFilters = () => {
        const query = (searchInput?.value || '').trim().toLowerCase();
        const status = (statusFilter?.value || '').trim().toLowerCase();

        rows.forEach((row) => {
            const haystack = row.innerText.toLowerCase();
            const rowStatus = (row.dataset.filterStatus || '').toLowerCase();
            const matchesQuery = query === '' || haystack.includes(query);
            const matchesStatus = status === '' || rowStatus === status;

            row.classList.toggle('d-none', !(matchesQuery && matchesStatus));
        });

        updateActionState();
    };

    const removeHosts = async (hostIds) => {
        const payload = new URLSearchParams();
        payload.set('csrf_token', csrfToken);
        hostIds.forEach((hostId) => payload.append('host_ids[]', hostId));

        const response = await fetch('../api/delete_mikrotik_host.php', {
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

        hostIds.forEach((hostId) => {
            const row = rows.find((candidate) => candidate.dataset.hostId === hostId);
            if (row) {
                row.remove();
            }
        });

        showMessage(data.message || 'Host supprime avec succes.', 'success');
        window.setTimeout(() => window.location.reload(), 450);
    };

    if (searchInput) {
        searchInput.addEventListener('input', applyFilters);
    }

    if (statusFilter) {
        statusFilter.addEventListener('change', applyFilters);
    }

    if (selectAll) {
        selectAll.addEventListener('change', () => {
            getVisibleCheckboxes().forEach((checkbox) => {
                checkbox.checked = selectAll.checked;
            });
            updateActionState();
        });
    }

    tableBody?.addEventListener('change', (event) => {
        if (event.target.closest('.host-select')) {
            updateActionState();
        }
    });

    tableBody?.addEventListener('click', async (event) => {
        const button = event.target.closest('.js-delete-host');
        if (!button) {
            return;
        }

        const hostId = button.dataset.hostId || '';
        if (!hostId) {
            showMessage('Host introuvable.', 'danger');
            return;
        }

        if (!window.confirm('Supprimer ce host hotspot ?')) {
            return;
        }

        button.disabled = true;

        try {
            await removeHosts([hostId]);
        } catch (error) {
            showMessage(error.message || 'Erreur de suppression.', 'danger');
            button.disabled = false;
        }
    });

    deleteBtn?.addEventListener('click', async () => {
        const selectedIds = getSelectedCheckboxes()
            .map((checkbox) => checkbox.value)
            .filter(Boolean);

        if (selectedIds.length === 0) {
            showMessage('Selectionnez au moins un host.', 'warning');
            return;
        }

        if (!window.confirm('Supprimer les hosts selectionnes ?')) {
            return;
        }

        deleteBtn.disabled = true;

        try {
            await removeHosts(selectedIds);
        } catch (error) {
            showMessage(error.message || 'Erreur de suppression.', 'danger');
            updateActionState();
        }
    });

    refreshBtn?.addEventListener('click', () => {
        window.location.reload();
    });

    applyFilters();
});
