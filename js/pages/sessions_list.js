(function () {
    'use strict';

    const config = window.SESSIONS_LIST_CONFIG || {};
    const csrfToken = String(config.csrfToken || '');
    const isOpnsenseSessions = Boolean(config.isOpnsenseSessions);
    const isMikrotikSessions = Boolean(config.isMikrotikSessions);
    const configuredDeviceId = String(config.deviceId || document.body?.dataset?.deviceId || '').trim();
    const wanOverrideOptions = Array.isArray(config.wanOverrideOptions) ? config.wanOverrideOptions : [];

    let sessionsRefreshInFlight = false;
    let sessionsColumnsState = {};
    let wanOverrideModalInstance = null;
    let wanOverrideContext = null;

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
        const table = document.querySelector('.sessions-table');
        const columnCount = table ? table.querySelectorAll('thead th').length : (isOpnsenseSessions ? 11 : (isMikrotikSessions ? 14 : 13));
        return `<td colspan="${columnCount}" class="text-center">Aucune session ne correspond aux filtres</td>`;
    }

    function escapeHtml(value) {
        return String(value ?? '')
            .replaceAll('&', '&amp;')
            .replaceAll('<', '&lt;')
            .replaceAll('>', '&gt;')
            .replaceAll('"', '&quot;')
            .replaceAll("'", '&#039;');
    }

    function updateSessionsListCount() {
        const counter = document.getElementById('sessionsListCount');
        const tbody = document.getElementById('sessionsTableBody');

        if (!counter || !tbody) {
            return;
        }

        const rows = Array.from(tbody.querySelectorAll('tr:not([data-sort-disabled="1"])'));
        counter.textContent = String(rows.length);

        const wan1Counter = document.getElementById('sessionsWan1Count');
        const wan2Counter = document.getElementById('sessionsWan2Count');
        if (wan1Counter || wan2Counter) {
            const counts = rows.reduce((acc, row) => {
                const wan = String(row.dataset.wan || '').trim().toUpperCase();
                if (wan === 'WAN1') acc.WAN1 += 1;
                if (wan === 'WAN2') acc.WAN2 += 1;
                return acc;
            }, { WAN1: 0, WAN2: 0 });
            if (wan1Counter) wan1Counter.textContent = String(counts.WAN1);
            if (wan2Counter) wan2Counter.textContent = String(counts.WAN2);
        }
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
                window.TableSort?.refresh?.();
                updateSessionsListCount();
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
            deviceId: row.dataset.deviceId || configuredDeviceId,
        } : {};

        if (!rowContext.deviceId) {
            showToast('Device manquant pour cette session. Actualisez la page puis réessayez.', 'danger');
            return;
        }

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
                device_id: rowContext.deviceId,
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

    function getWanOverrideModal() {
        const modalElement = document.getElementById('sessionWanOverrideModal');
        if (!modalElement || !window.bootstrap?.Modal) {
            return null;
        }
        if (!wanOverrideModalInstance) {
            wanOverrideModalInstance = new window.bootstrap.Modal(modalElement);
        }
        return wanOverrideModalInstance;
    }

    function buildWanOverrideChoices(currentValue) {
        const choices = document.getElementById('sessionWanOverrideChoices');
        if (!choices) {
            return;
        }

        const current = String(currentValue || 'AUTO').toUpperCase();
        const rows = [{
            wan_id: 'AUTO',
            label: 'Automatique',
            interface: '',
            weight: '',
            selectable: true,
            reason: 'supprimer exception',
        }].concat(wanOverrideOptions);

        choices.innerHTML = rows.map((option) => {
            const wanId = String(option.wan_id || '').toUpperCase();
            const selectable = option.selectable !== false;
            const checked = current === wanId;
            const meta = wanId === 'AUTO'
                ? 'supprimer exception'
                : [option.interface || '', option.weight !== '' && option.weight !== undefined ? `${option.weight}%` : '', selectable ? '' : (option.reason || 'inactive')]
                    .filter(Boolean)
                    .join(' · ');
            return `
                <label class="session-wan-choice ${selectable ? '' : 'is-disabled'}">
                    <input
                        type="radio"
                        name="session_wan_override"
                        value="${escapeHtml(wanId)}"
                        ${checked ? 'checked' : ''}
                        ${selectable ? '' : 'disabled'}
                    >
                    <span class="session-wan-choice__main">
                        <span class="session-wan-choice__title">${escapeHtml(wanId === 'AUTO' ? option.label : `${wanId} · ${option.label || wanId}`)}</span>
                        <span class="session-wan-choice__meta">${escapeHtml(meta)}</span>
                    </span>
                </label>
            `;
        }).join('');
    }

    function openWanOverrideModal(row) {
        const modal = getWanOverrideModal();
        if (!modal || !row) {
            showToast('Popup WAN indisponible.', 'danger');
            return;
        }
        const username = String(row.dataset.username || '').trim();
        const address = String(row.dataset.address || '').trim();
        const deviceId = String(row.dataset.deviceId || configuredDeviceId).trim();
        if (!username || username === '-' || !address || address === '-' || !deviceId) {
            showToast('Session incomplète pour créer une exception WAN.', 'danger');
            return;
        }

        wanOverrideContext = {
            username,
            address,
            deviceId,
            currentWan: String(row.dataset.wanOverride || 'AUTO').trim().toUpperCase() || 'AUTO',
        };

        const target = document.getElementById('sessionWanOverrideTarget');
        if (target) {
            target.textContent = `${username} · ${address}`;
        }
        const feedback = document.getElementById('sessionWanOverrideFeedback');
        if (feedback) {
            feedback.textContent = '';
            feedback.className = 'session-wan-modal-feedback';
        }
        buildWanOverrideChoices(wanOverrideContext.currentWan);
        modal.show();
    }

    function setWanOverrideBusy(busy) {
        const saveButton = document.getElementById('sessionWanOverrideSaveBtn');
        if (saveButton) {
            saveButton.disabled = busy;
            saveButton.classList.toggle('is-loading', busy);
        }
    }

    function saveWanOverride() {
        if (!wanOverrideContext) {
            return;
        }
        const selected = document.querySelector('input[name="session_wan_override"]:checked');
        const wan = String(selected?.value || '').trim().toUpperCase();
        const feedback = document.getElementById('sessionWanOverrideFeedback');
        if (!wan) {
            if (feedback) {
                feedback.textContent = 'Choisissez une option.';
                feedback.classList.add('is-error');
            }
            return;
        }

        setWanOverrideBusy(true);
        if (feedback) {
            feedback.textContent = 'Application en cours...';
            feedback.className = 'session-wan-modal-feedback is-info';
        }

        fetch('../api/session_wan_override.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            credentials: 'same-origin',
            body: JSON.stringify({
                csrf_token: csrfToken,
                device_id: wanOverrideContext.deviceId,
                username: wanOverrideContext.username,
                address: wanOverrideContext.address,
                wan
            })
        })
            .then(async (response) => {
                const data = await response.json().catch(() => ({ status: 'error', message: 'Réponse serveur invalide.' }));
                return { ok: response.ok, data };
            })
            .then(({ ok, data }) => {
                if (!ok || data.status !== 'success') {
                    throw new Error(data.message || 'Application impossible.');
                }
                showToast(data.message || 'Exception WAN appliquée.', 'success');
                getWanOverrideModal()?.hide();
                refreshSessions();
            })
            .catch((error) => {
                if (feedback) {
                    feedback.textContent = error.message || 'Application impossible.';
                    feedback.className = 'session-wan-modal-feedback is-error';
                }
                showToast(error.message || 'Application impossible.', 'danger');
            })
            .finally(() => {
                setWanOverrideBusy(false);
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
        updateSessionsListCount();

        searchFilter?.addEventListener('input', SessionsCommands.filter);
        SessionsCommands.filter();

        document.getElementById('sessionsManualRefreshBtn')?.addEventListener('click', () => {
            SessionsCommands.refresh();
        });

        document.getElementById('sessionsTableBody')?.addEventListener('click', (event) => {
            const button = event.target.closest('.session-wan-override-btn');
            if (!button) {
                return;
            }
            const row = button.closest('tr');
            openWanOverrideModal(row);
        });

        document.getElementById('sessionWanOverrideSaveBtn')?.addEventListener('click', saveWanOverride);
    });

    window.disconnectSession = SessionsCommands.disconnect;
    window.SessionsCommands = SessionsCommands;
})();
