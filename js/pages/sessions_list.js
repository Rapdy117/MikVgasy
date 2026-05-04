(function () {
    'use strict';

    const config = window.SESSIONS_LIST_CONFIG || {};
    const csrfToken = String(config.csrfToken || '');
    const isOpnsenseSessions = Boolean(config.isOpnsenseSessions);

    let sessionsRefreshInFlight = false;
    let sessionsColumnsState = {};

    function showToast(message, type = 'info', duration = 3000) {
        AppToast.flash(message, type, duration);
    }

    function showConfirm(message) {
        return new Promise((resolve) => {
            const confirmed = window.confirm(message);
            resolve(confirmed);
        });
    }

    function sessionsColumnsStorageKey() {
        const mode = (document.body?.dataset?.sessionMode || 'default').trim();
        return `sessions_columns_visibility_${mode}`;
    }

    function loadSessionsColumnsState() {
        try {
            const raw = localStorage.getItem(sessionsColumnsStorageKey());
            const parsed = raw ? JSON.parse(raw) : {};
            return parsed && typeof parsed === 'object' ? parsed : {};
        } catch (error) {
            return {};
        }
    }

    function saveSessionsColumnsState() {
        try {
            localStorage.setItem(sessionsColumnsStorageKey(), JSON.stringify(sessionsColumnsState));
        } catch (error) {
            // ignore storage errors
        }
    }

    function applySessionsColumnVisibility(columnIndex, visible) {
        const table = document.querySelector('.sessions-table');
        if (!table) {
            return;
        }

        table.querySelectorAll('tr').forEach((row) => {
            const cell = row.children[columnIndex];
            if (cell) {
                cell.style.display = visible ? '' : 'none';
            }
        });
    }

    function applySessionsColumnsFromState() {
        const table = document.querySelector('.sessions-table');
        if (!table) {
            return;
        }

        const headCells = Array.from(table.querySelectorAll('thead th'));
        headCells.forEach((th, index) => {
            const label = (th.textContent || '').trim() || `Colonne ${index + 1}`;
            const key = label.toLowerCase().replace(/[^a-z0-9]+/g, '_').replace(/^_+|_+$/g, '') || `col_${index}`;
            const visible = sessionsColumnsState[key] !== false;
            applySessionsColumnVisibility(index, visible);
        });
    }

    function buildSessionsColumnsSelector() {
        const table = document.querySelector('.sessions-table');
        const menu = document.getElementById('sessionsColumnsMenu');
        if (!table || !menu) {
            return;
        }

        const headCells = Array.from(table.querySelectorAll('thead th'));
        menu.innerHTML = '';

        headCells.forEach((th, index) => {
            const label = (th.textContent || '').trim() || `Colonne ${index + 1}`;
            const key = label.toLowerCase().replace(/[^a-z0-9]+/g, '_').replace(/^_+|_+$/g, '') || `col_${index}`;
            const visible = sessionsColumnsState[key] !== false;
            applySessionsColumnVisibility(index, visible);

            const option = document.createElement('label');
            option.className = 'dropdown-item-text profile-column-option';
            option.innerHTML = `
                <input class="form-check-input mt-0 me-2" type="checkbox" ${visible ? 'checked' : ''}>
                <span>${label.replaceAll('<', '&lt;').replaceAll('>', '&gt;')}</span>
            `;
            const checkbox = option.querySelector('input');
            checkbox?.addEventListener('change', () => {
                const checked = checkbox.checked;
                sessionsColumnsState[key] = checked;
                saveSessionsColumnsState();
                applySessionsColumnVisibility(index, checked);
            });

            menu.appendChild(option);
        });
    }

    function buildEmptyFilterRowHtml() {
        const colspan = isOpnsenseSessions ? 11 : 13;
        return `<td colspan="${colspan}" class="text-center">Aucune session ne correspond aux filtres</td>`;
    }

    function applySessionsFilters() {
        const searchFilter = document.getElementById('sessionsSearchFilter');
        const tbody = document.getElementById('sessionsTableBody');

        if (!tbody) {
            return;
        }

        const searchValue = (searchFilter?.value || '').trim().toLowerCase();
        const rows = Array.from(tbody.querySelectorAll('tr')).filter((row) => row.dataset.sortDisabled !== '1');

        let visibleCount = 0;

        rows.forEach((row) => {
            const rowSearch = (row.dataset.search || '').trim();
            const isVisible = searchValue === '' || rowSearch.includes(searchValue);

            row.style.display = isVisible ? '' : 'none';
            if (isVisible) {
                visibleCount += 1;
            }
        });

        let emptyRow = document.getElementById('sessions-filter-empty-row');
        if (visibleCount === 0) {
            if (!emptyRow) {
                emptyRow = document.createElement('tr');
                emptyRow.id = 'sessions-filter-empty-row';
                emptyRow.setAttribute('data-sort-disabled', '1');
                emptyRow.innerHTML = buildEmptyFilterRowHtml();
                tbody.appendChild(emptyRow);
            }
        } else if (emptyRow) {
            emptyRow.remove();
        }
    }

    function refreshSessions() {
        if (sessionsRefreshInFlight) {
            return;
        }

        const tbody = document.getElementById('sessionsTableBody');
        if (!tbody) {
            return;
        }

        sessionsRefreshInFlight = true;
        const searchFilter = document.getElementById('sessionsSearchFilter');
        const searchValue = searchFilter ? searchFilter.value : '';

        fetch(`${window.location.pathname}?_partial=sessions&_ts=${Date.now()}`, {
            method: 'GET',
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            },
            credentials: 'same-origin'
        })
            .then(response => {
                if (!response.ok) {
                    throw new Error(`Erreur HTTP ${response.status}`);
                }
                return response.text();
            })
            .then(html => {
                tbody.innerHTML = html.trim();

                if (searchFilter) {
                    searchFilter.value = searchValue;
                }

                applySessionsColumnsFromState();
                applySessionsFilters();
            })
            .catch(error => {
                console.error('Erreur actualisation sessions:', error);
            })
            .finally(() => {
                sessionsRefreshInFlight = false;
            });
    }

    async function disconnectSession(sessionId, rowId) {
        if (!sessionId) {
            showToast('Session invalide', 'danger');
            return;
        }

        const confirmed = await showConfirm('Déconnecter cette session ?');
        if (!confirmed) {
            return;
        }

        const row = document.getElementById(`session-row-${rowId}`);
        const actionButton = row ? row.querySelector('.session-action-btn') : null;
        const rowContext = row ? {
            username: row.dataset.username || '',
            address: row.dataset.address || '',
            source: row.dataset.login || '',
            profile: row.dataset.profile || '',
        } : {};

        if (actionButton) {
            actionButton.disabled = true;
        }
        if (row) {
            row.style.opacity = '0.55';
        }

        fetch('../api/disconnect_session.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({
                sessionId,
                username: rowContext.username,
                address: rowContext.address,
                source: rowContext.source,
                profile: rowContext.profile,
                csrf_token: csrfToken
            })
        })
        .then(async (response) => {
            const data = await response.json().catch(() => ({
                status: 'error',
                message: 'Reponse serveur invalide.',
            }));
            return { ok: response.ok, data };
        })
        .then(({ ok, data }) => {
            if (!ok || data.status !== 'success') {
                if (actionButton) {
                    actionButton.disabled = false;
                }
                if (row) {
                    row.style.opacity = '';
                }
                showToast(data.message || 'Déconnexion impossible', 'danger');
                return;
            }
            showToast(data.message || 'Session déconnectée', 'success');
            window.setTimeout(() => {
                window.location.reload();
            }, data.verified === false ? 1800 : 900);
        })
        .catch(error => {
            console.error('Erreur déconnexion:', error);
            if (actionButton) {
                actionButton.disabled = false;
            }
            if (row) {
                row.style.opacity = '';
            }
            showToast('Le serveur n a pas répondu correctement.', 'danger');
        });
    }

    const SessionsCommands = {
        refresh: refreshSessions,
        disconnect: disconnectSession,
        filter: applySessionsFilters,
    };

    document.addEventListener('DOMContentLoaded', () => {
        const searchFilter = document.getElementById('sessionsSearchFilter');
        sessionsColumnsState = loadSessionsColumnsState();
        buildSessionsColumnsSelector();
        applySessionsColumnsFromState();

        searchFilter?.addEventListener('input', SessionsCommands.filter);
        SessionsCommands.filter();

        document.getElementById('sessionsManualRefreshBtn')?.addEventListener('click', () => {
            SessionsCommands.refresh();
        });
    });

    window.disconnectSession = SessionsCommands.disconnect;
    window.SessionsCommands = SessionsCommands;
})();
