document.addEventListener('DOMContentLoaded', () => {
    const tableBody = document.getElementById('cookiesTableBody');
    const messageArea = document.getElementById('messageArea');
    const table = document.querySelector('table[data-csrf-token]');
    const csrfToken = table?.dataset.csrfToken || '';
    const selectAll = document.getElementById('selectAllCookies');
    const deleteSelectedBtn = document.getElementById('deleteSelectedCookiesBtn');

    if (!tableBody || !csrfToken) {
        return;
    }

    const getCookieCheckboxes = () => Array.from(tableBody.querySelectorAll('.cookie-select'));
    const getSelectedCookieIds = () => getCookieCheckboxes()
        .filter((checkbox) => checkbox.checked)
        .map((checkbox) => checkbox.value)
        .filter(Boolean);

    const showMessage = (message, type = 'success') => {
        if (!messageArea) {
            return;
        }

        messageArea.innerHTML = `<div class="alert alert-${type} py-2 px-3 mb-3" role="alert">${message}</div>`;
        messageArea.style.display = 'block';
    };

    const hideMessage = () => {
        if (!messageArea) {
            return;
        }

        messageArea.style.display = 'none';
        messageArea.innerHTML = '';
    };

    const updateBulkButtonState = () => {
        const selectedCount = getSelectedCookieIds().length;
        if (deleteSelectedBtn) {
            deleteSelectedBtn.disabled = selectedCount === 0;
        }

        if (selectAll) {
            const checkboxes = getCookieCheckboxes();
            selectAll.checked = checkboxes.length > 0 && checkboxes.every((checkbox) => checkbox.checked);
        }
    };

    const deleteCookies = async (cookieIds) => {
        if (cookieIds.length === 0) {
            showMessage('Sélectionnez au moins un cookie.', 'warning');
            return;
        }

        hideMessage();

        const payload = new URLSearchParams();
        payload.set('csrf_token', csrfToken);
        cookieIds.forEach((cookieId) => payload.append('cookie_ids[]', cookieId));

        const response = await fetch('../api/delete_mikrotik_cookie.php', {
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

        cookieIds.forEach((cookieId) => {
            const row = document.getElementById(`cookie-row-${cookieId}`);
            if (row) {
                row.remove();
            }
        });

        if (!tableBody.querySelector('tr')) {
            tableBody.innerHTML = '<tr data-sort-disabled="1"><td colspan="9" class="text-center py-4 text-white-50">Aucun cookie present sur le routeur actif.</td></tr>';
        }

        updateBulkButtonState();
        showMessage(data.message || 'Cookie supprimé avec succès.', 'success');
    };

    selectAll?.addEventListener('change', () => {
        getCookieCheckboxes().forEach((checkbox) => {
            checkbox.checked = selectAll.checked;
        });
        updateBulkButtonState();
    });

    deleteSelectedBtn?.addEventListener('click', async () => {
        const selectedIds = getSelectedCookieIds();
        if (selectedIds.length === 0) {
            return;
        }

        if (!window.confirm('Supprimer les cookies sélectionnés ?')) {
            return;
        }

        deleteSelectedBtn.disabled = true;

        try {
            await deleteCookies(selectedIds);
        } catch (error) {
            showMessage(error.message || 'Erreur de suppression.', 'danger');
        } finally {
            updateBulkButtonState();
        }
    });

    tableBody.addEventListener('click', async (event) => {
        const button = event.target.closest('.js-delete-cookie');
        if (!button) {
            return;
        }

        const cookieId = button.dataset.cookieId || '';
        const rowId = button.dataset.rowId || '';
        if (!cookieId || !rowId) {
            showMessage('Cookie introuvable.', 'danger');
            return;
        }

        if (!window.confirm('Supprimer ce cookie hotspot ?')) {
            return;
        }

        button.disabled = true;

        try {
            await deleteCookies([cookieId]);
        } catch (error) {
            showMessage(error.message || 'Erreur de suppression.', 'danger');
            button.disabled = false;
        }
    });

    tableBody.addEventListener('change', (event) => {
        if (event.target.closest('.cookie-select')) {
            updateBulkButtonState();
        }
    });

    updateBulkButtonState();
});
