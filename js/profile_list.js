document.addEventListener('DOMContentLoaded', () => {
    const table = document.querySelector('.profiles-table');
    const csrfToken = String(table?.dataset.csrfToken || '').trim();
    const deleteButtons = Array.from(document.querySelectorAll('.js-delete-profile'));
    const columnToggles = Array.from(document.querySelectorAll('.js-profile-column-toggle'));
    const searchInput = document.getElementById('profilesSearchInput');
    const searchClearButton = document.getElementById('profilesSearchClear');
    const searchEmptyRow = document.getElementById('profilesSearchEmptyRow');
    const searchableRows = Array.from(document.querySelectorAll('#profilesTableBody tr[data-profile-search]'));
    const storageKey = 'profile_list_visible_columns_v1';

    function getStoredColumnMap() {
        try {
            const raw = window.localStorage.getItem(storageKey);
            if (!raw) {
                return {};
            }

            const parsed = JSON.parse(raw);
            return parsed && typeof parsed === 'object' ? parsed : {};
        } catch (error) {
            return {};
        }
    }

    function saveStoredColumnMap(map) {
        try {
            window.localStorage.setItem(storageKey, JSON.stringify(map));
        } catch (error) {
            // ignore persistence errors
        }
    }

    function normalizeSearchValue(value) {
        const raw = String(value || '').toLowerCase().trim();
        if (raw === '') {
            return '';
        }

        try {
            return raw
                .normalize('NFD')
                .replace(/[\u0300-\u036f]/g, '')
                .replace(/\s+/g, ' ')
                .trim();
        } catch (error) {
            return raw
                .replace(/\s+/g, ' ')
                .trim();
        }
    }

    const searchableRowIndex = searchableRows.map((row) => ({
        row,
        index: normalizeSearchValue(row.dataset.profileSearch || ''),
    }));

    function setColumnVisibility(columnKey, visible) {
        if (!table || !columnKey) {
            return;
        }

        table.querySelectorAll(`[data-column-key="${columnKey}"]`).forEach((cell) => {
            cell.classList.toggle('d-none', !visible);
        });
    }

    function syncColumnVisibility() {
        const storedMap = getStoredColumnMap();

        columnToggles.forEach((toggle) => {
            const columnKey = String(toggle.dataset.columnKey || '').trim();
            const visible = storedMap[columnKey] !== false;
            toggle.checked = visible;
            setColumnVisibility(columnKey, visible);
        });
    }

    function bindColumnToggles() {
        columnToggles.forEach((toggle) => {
            toggle.addEventListener('change', () => {
                const columnKey = String(toggle.dataset.columnKey || '').trim();
                const checkedToggles = columnToggles.filter((item) => item.checked);

                if (!toggle.checked && checkedToggles.length === 0) {
                    toggle.checked = true;
                    return;
                }

                const storedMap = getStoredColumnMap();
                storedMap[columnKey] = toggle.checked;
                saveStoredColumnMap(storedMap);
                setColumnVisibility(columnKey, toggle.checked);
            });
        });
    }

    function syncSearchClearButton() {
        if (!searchClearButton || !searchInput) {
            return;
        }

        const hasValue = normalizeSearchValue(searchInput.value) !== '';
        searchClearButton.classList.toggle('d-none', !hasValue);
        searchClearButton.disabled = !hasValue;
    }

    function applySearchFilter() {
        if (!searchInput) {
            return;
        }

        const query = normalizeSearchValue(searchInput.value);
        let visibleCount = 0;

        searchableRowIndex.forEach(({ row, index }) => {
            const matches = query === '' || index.includes(query);
            row.classList.toggle('d-none', !matches);
            if (matches) {
                visibleCount += 1;
            }
        });

        if (searchEmptyRow) {
            const shouldShowEmptyRow = searchableRowIndex.length > 0 && visibleCount === 0;
            searchEmptyRow.classList.toggle('d-none', !shouldShowEmptyRow);
        }

        syncSearchClearButton();
    }

    async function deleteProfile(button) {
        const profileId = String(button.dataset.profileId || '').trim();
        const routerId = String(button.dataset.routerId || '').trim();
        const profileName = String(button.dataset.profileName || '').trim();

        if (!profileId && !routerId && !profileName) {
            alert('Profil introuvable.');
            return;
        }

        if (!confirm(`Supprimer le profil "${profileName || profileId}" ?`)) {
            return;
        }

        const previousDisabled = button.disabled;
        button.disabled = true;

        try {
            const formData = new FormData();
            formData.append('csrf_token', csrfToken);
            formData.append('profile_id', profileId);
            formData.append('router_profile_id', routerId);
            formData.append('profile_name', profileName);

            const response = await fetch('../api/profiles/delete_profile.php', {
                method: 'POST',
                body: formData,
            });

            const data = await response.json().catch(() => ({
                success: false,
                message: 'Erreur serveur',
            }));

            if (!response.ok || !data.success) {
                throw new Error(data.message || 'Suppression impossible');
            }

            alert(data.message || 'Profil supprime avec succes.');
            window.location.reload();
        } catch (error) {
            alert(error.message || 'La suppression du profil a echoue.');
            button.disabled = previousDisabled;
        }
    }

    deleteButtons.forEach((button) => {
        button.addEventListener('click', () => {
            deleteProfile(button);
        });
    });

    if (searchInput) {
        searchInput.addEventListener('input', applySearchFilter);
        searchInput.addEventListener('search', applySearchFilter);
    }

    if (searchClearButton) {
        searchClearButton.addEventListener('click', () => {
            if (!searchInput) {
                return;
            }

            searchInput.value = '';
            applySearchFilter();
            searchInput.focus();
        });
    }

    syncColumnVisibility();
    bindColumnToggles();
});
