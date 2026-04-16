let selectedRow = null;
let selectedUserId = null;
let nasList = [];
let currentUsersViewMode = 'list';

const rows = Array.from(document.querySelectorAll('.user-row'));
const columnToggles = Array.from(document.querySelectorAll('.js-users-column-toggle'));
const usersListColumn = document.getElementById('usersListColumn');
const userDetailsColumn = document.getElementById('userDetailsColumn');
const usersViewModeToggle = document.getElementById('usersViewModeToggle');
const usersSearchInput = document.getElementById('usersSearchInput');
const usersProfileFilter = document.getElementById('usersProfileFilter');
const usersStatusFilter = document.getElementById('usersStatusFilter');
const usersSearchSuggestions = document.getElementById('usersSearchSuggestions');
const usersTableWrapper = document.querySelector('#usersListColumn .table-responsive');
const usersRefreshBtn = document.getElementById('usersRefreshBtn');
const emptyState = document.getElementById('emptyState');
const userContent = document.getElementById('userContent');
const usersDetailsInlineSlot = document.getElementById('usersDetailsInlineSlot');
const sessionsTable = document.getElementById('sessionsTable');
const readonlyMode = document.body.dataset.usersListReadonly === '1';
const activeDeviceType = (document.body.dataset.activeDeviceType || '').toLowerCase();
const activeDeviceId = (document.body.dataset.activeDeviceId || '').trim();

const editBtn = document.getElementById('editBtn');
const saveBtn = document.getElementById('saveBtn');
const cancelBtn = document.getElementById('cancelBtn');
const deleteBtn = document.getElementById('deleteBtn');
const reloadBtn = document.getElementById('reloadBtn');
const USERS_COLUMNS_STORAGE_KEY = 'users_list.columns';

function getSummaryElement(id) {
    return document.getElementById(id);
}

function getRowData(row, keys, fallback = '') {
    for (const key of keys) {
        const value = row?.dataset?.[key];
        if (value !== undefined && value !== null && String(value).trim() !== '') {
            return value;
        }
    }
    return fallback;
}

function applyColumnVisibility() {
    const stored = (() => {
        try {
            return JSON.parse(localStorage.getItem(USERS_COLUMNS_STORAGE_KEY) || '{}');
        } catch (error) {
            return {};
        }
    })();

    columnToggles.forEach((toggle) => {
        const key = toggle.dataset.columnKey || '';
        if (!key) {
            return;
        }
        const visible = stored[key] !== false;
        toggle.checked = visible;
        document.querySelectorAll(`[data-column-key="${key}"]`).forEach((cell) => {
            cell.classList.toggle('d-none', !visible);
        });
    });
}

async function readJsonResponse(response) {
    const text = await response.text();
    if (text.trim() === '') {
        return null;
    }
    try {
        return JSON.parse(text);
    } catch (error) {
        return null;
    }
}

function flashMessage(message, type = 'info') {
    const container = document.getElementById('usersDetailsMessage') || document.createElement('div');
    container.id = 'usersDetailsMessage';
    container.className = `alert alert-${type} py-2 px-3 mb-3`;
    container.textContent = message;
    const parent = userContent?.parentElement;
    if (parent && !container.parentElement) {
        parent.insertBefore(container, userContent);
    }
    if (container.parentElement) {
        container.parentElement.insertBefore(container, userContent);
    }
}

function resolveNasIdFromAddress(address) {
    if (!address) {
        return nasList[0]?.nas_id ?? '';
    }
    const match = nasList.find((nas) => nas.nasname === address || nas.shortname === address);
    return match ? (match.nas_id ?? '') : (nasList[0]?.nas_id ?? '');
}

function maskPassword(rawValue) {
    if (activeDeviceType === 'mikrotik') {
        return rawValue || '';
    }
    return '****';
}

function isReliableCreatedAt(value) {
    return /^\d{4}-\d{2}-\d{2}/.test(String(value || '').trim());
}

function formatSessionDateLabel(value) {
    const raw = String(value || '').trim();
    if (raw === '') {
        return '-';
    }
    const date = new Date(raw.replace(' ', 'T'));
    if (Number.isNaN(date.getTime())) {
        return raw;
    }
    const dd = String(date.getDate()).padStart(2, '0');
    const mm = String(date.getMonth() + 1).padStart(2, '0');
    const yyyy = String(date.getFullYear());
    const hh = String(date.getHours()).padStart(2, '0');
    const min = String(date.getMinutes()).padStart(2, '0');
    const sec = String(date.getSeconds()).padStart(2, '0');
    return `${dd}/${mm}/${yyyy} ${hh}:${min}:${sec}`;
}

function formatDateOnly(value) {
    const raw = String(value || '').trim();
    if (raw === '' || raw === 'RouterOS') {
        return '-';
    }
    const match = raw.match(/^\d{4}-\d{2}-\d{2}/);
    if (match) {
        return match[0];
    }
    const date = new Date(raw.replace(' ', 'T'));
    if (Number.isNaN(date.getTime())) {
        return '-';
    }
    const yyyy = date.getFullYear();
    const mm = String(date.getMonth() + 1).padStart(2, '0');
    const dd = String(date.getDate()).padStart(2, '0');
    return `${yyyy}-${mm}-${dd}`;
}

function formatDurationLabel(seconds) {
    const total = Number(seconds || 0);
    if (!Number.isFinite(total) || total <= 0) {
        return '-';
    }
    const whole = Math.floor(total);
    const days = Math.floor(whole / 86400);
    let rem = whole % 86400;
    const hours = Math.floor(rem / 3600);
    rem %= 3600;
    const minutes = Math.floor(rem / 60);
    const secs = rem % 60;

    const parts = [];
    if (days > 0) parts.push(`${days}j`);
    if (hours > 0) parts.push(`${hours}h`);
    if (minutes > 0) parts.push(`${minutes}m`);
    if (secs > 0 || parts.length === 0) parts.push(`${secs}s`);
    return parts.join(' ');
}

function setViewMode(mode) {
    currentUsersViewMode = mode === 'details' ? 'details' : 'list';
    if (usersViewModeToggle) {
        usersViewModeToggle.innerHTML = currentUsersViewMode === 'details'
            ? '<i class="fa fa-table me-1"></i> Mode liste'
            : '<i class="fa fa-list me-1"></i> Mode détails';
    }
    if (usersListColumn) {
        usersListColumn.classList.toggle('users-list-details-mode', currentUsersViewMode === 'details');
    }
    if (currentUsersViewMode === 'details') {
        mountDetailsInline();
    } else {
        mountDetailsSide();
    }
}

function mountDetailsInline() {
    if (!usersDetailsInlineSlot || !userDetailsColumn) {
        return;
    }
    const card = userDetailsColumn.querySelector('.card');
    if (card && card.parentElement !== usersDetailsInlineSlot) {
        usersDetailsInlineSlot.appendChild(card);
    }
    userDetailsColumn.classList.add('d-none');
}

function mountDetailsSide() {
    if (!usersDetailsInlineSlot || !userDetailsColumn) {
        return;
    }
    const card = usersDetailsInlineSlot.querySelector('.card');
    if (card && card.parentElement !== userDetailsColumn) {
        userDetailsColumn.appendChild(card);
    }
    userDetailsColumn.classList.add('d-none');
}

function applyUsersFilters() {
    const query = (usersSearchInput?.value || '').trim().toLowerCase();
    const profileFilter = (usersProfileFilter?.value || '').trim();
    const statusFilter = (usersStatusFilter?.value || '').trim();

    rows.forEach((row) => {
        const username = (row.dataset.username || '').toLowerCase();
        const profile = (row.dataset.plan || '').toLowerCase();
        const status = (row.dataset.status || '').toLowerCase();
        const haystack = currentUsersViewMode === 'details'
            ? username
            : `${username} ${profile} ${status} ${row.textContent.toLowerCase()}`;

        const matchQuery = query === '' || haystack.includes(query);
        const matchProfile = currentUsersViewMode === 'details' ? true : (profileFilter === '' || profile === profileFilter);
        const matchStatus = currentUsersViewMode === 'details' ? true : (statusFilter === '' || status === statusFilter);
        row.classList.toggle('d-none', !(matchQuery && matchProfile && matchStatus));
    });

    if (currentUsersViewMode === 'details') {
        const visibleRow = rows.find((row) => !row.classList.contains('d-none'));
        if (visibleRow) {
            selectUserRow(visibleRow, false);
        } else {
            selectedRow = null;
            selectedUserId = null;
            resetUserDetails();
            emptyState?.classList.remove('d-none');
        }
    }

    renderSearchSuggestions(query);
}

function renderSearchSuggestions(query) {
    if (!usersSearchSuggestions) {
        return;
    }
    const normalized = (query || '').trim().toLowerCase();
    if (normalized.length < 1) {
        usersSearchSuggestions.classList.add('d-none');
        usersSearchSuggestions.innerHTML = '';
        return;
    }

    const candidates = rows
        .filter((row) => !row.classList.contains('d-none'))
        .map((row) => (row.dataset.username || '').trim())
        .filter((name) => name.toLowerCase().includes(normalized))
        .slice(0, 6);

    if (candidates.length === 0) {
        usersSearchSuggestions.classList.add('d-none');
        usersSearchSuggestions.innerHTML = '';
        return;
    }

    usersSearchSuggestions.innerHTML = candidates
        .map((name) => `<button type="button" class="users-search-suggestion-item" data-username="${name}">${name}</button>`)
        .join('');
    usersSearchSuggestions.classList.remove('d-none');
}

function fillUserDataFromRow(row) {
    const id = getRowData(row, ['id']);
    const nasId = getRowData(row, ['nas_id']);
    const username = getRowData(row, ['username']);
    const password = getRowData(row, ['password']);
    const serverDisplay = getRowData(row, ['server_display'], '-');
    const profileId = getRowData(row, ['profile_id']);
    const expiration = getRowData(row, ['expiration'], '-');
    const rateLimitLabel = getRowData(row, ['rate_limit_label', 'rateLimitLabel', 'rate_limit'], '-');
    const sharedUsersLabel = getRowData(row, ['shared_users_label', 'sharedUsersLabel', 'simultaneous_use'], '-');
    const timeLimitLabel = getRowData(row, ['time_limit_label', 'timeLimitLabel', 'session_timeout'], '-');
    const profileTimeLimitLabel = getRowData(row, ['profile_time_limit_label', 'profileTimeLimitLabel'], '-');
    const validityLabel = getRowData(row, ['validity_label', 'validityLabel', 'validity'], '-');
    const dataLimitLabel = getRowData(row, ['data_limit_label', 'dataLimitLabel', 'data_limit'], '-');
    const sessionTotalLabel = getRowData(row, ['session_total_label', 'sessionTotalLabel'], '-');
    const dataConsumedLabel = getRowData(row, ['data_consumed_label', 'dataConsumedLabel'], '-');
    const expiredMode = getRowData(row, ['expired_mode'], '-');
    const createdAt = getRowData(row, ['created_at'], '-');

    document.getElementById('user_id').value = id;
    document.getElementById('nas_id').value = nasId;
    document.getElementById('username').value = username;
    const passwordInput = document.getElementById('password');
    if (passwordInput) {
        passwordInput.value = maskPassword(password);
        passwordInput.dataset.rawPassword = password;
    }
    document.getElementById('server').value = serverDisplay;
    const profileSelect = document.getElementById('profile_id');
    if (profileSelect) {
        if (activeDeviceType === 'mikrotik') {
            const profileName = getRowData(row, ['plan'], '');
            const placeholderOption = profileSelect.options[0] || null;
            if (placeholderOption) {
                placeholderOption.textContent = profileName || '-- Choisir un profil --';
            }
            profileSelect.value = profileId || '';
            profileSelect.selectedIndex = profileId ? profileSelect.selectedIndex : 0;
        } else {
            profileSelect.value = profileId;
        }
    }
    document.getElementById('expiration').value = expiration;
    document.getElementById('rate_limit_display').value = rateLimitLabel;
    document.getElementById('shared_users_display').value = sharedUsersLabel;
    const profileTimeLimit = document.getElementById('profile_time_limit_display');
    if (profileTimeLimit) {
        profileTimeLimit.value = profileTimeLimitLabel !== '-' ? profileTimeLimitLabel : timeLimitLabel;
    }
    const validityDisplay = document.getElementById('validity_display');
    if (validityDisplay) {
        validityDisplay.value = validityLabel;
    }
    const expiredModeDisplay = document.getElementById('expired_mode_display');
    if (expiredModeDisplay) {
        expiredModeDisplay.value = expiredMode;
    }
    document.getElementById('time_limit_display').value = timeLimitLabel;
    document.getElementById('data_limit_display').value = dataLimitLabel;
    document.getElementById('session_total_display').value = sessionTotalLabel;
    document.getElementById('data_consumed_display').value = dataConsumedLabel;
    document.getElementById('price_display').value = getRowData(row, ['price_label', 'price'], '-');
    document.getElementById('selling_price_display').value = getRowData(row, ['selling_price_label', 'selling_price'], '-');
    const createdAtLabel = formatDateOnly(createdAt);
    document.getElementById('created_at_display').value = createdAtLabel;

    // Preserve full user/profile payload for update APIs.
    document.getElementById('fullname').value = getRowData(row, ['fullname']);
    document.getElementById('phone').value = getRowData(row, ['phone']);
    document.getElementById('address').value = getRowData(row, ['address']);
    document.getElementById('email').value = getRowData(row, ['email']);
    const statusValue = getRowData(row, ['status'], 'active');
    document.getElementById('status').value = statusValue;
    document.getElementById('balance').value = getRowData(row, ['balance'], '0');
    document.getElementById('auto_renewal').value = getRowData(row, ['auto_renewal'], '0');
    const isMikrotik = activeDeviceType === 'mikrotik';
    document.getElementById('rate_limit').value = isMikrotik ? getRowData(row, ['rate_limit']) : '';
    document.getElementById('session_timeout').value = getRowData(row, ['session_timeout']);
    document.getElementById('idle_timeout').value = isMikrotik ? getRowData(row, ['idle_timeout']) : '';
    document.getElementById('simultaneous_use').value = isMikrotik ? getRowData(row, ['simultaneous_use']) : '';
    document.getElementById('data_limit').value = getRowData(row, ['data_limit']);
    document.getElementById('account_type').value = getRowData(row, ['account_type']);
    document.getElementById('service').value = getRowData(row, ['service']);
    document.getElementById('plan').value = getRowData(row, ['plan']);
    document.getElementById('created_at').value = createdAt;
    document.getElementById('last_login').value = getRowData(row, ['last_login']);
    document.getElementById('online').value = getRowData(row, ['online']);
    document.getElementById('uptime_display').value = isMikrotik
        ? getRowData(row, ['current_session_uptime_label', 'currentSessionUptimeLabel'], '-')
        : getRowData(row, ['time_limit_label', 'timeLimitLabel', 'session_timeout'], '-');
    document.getElementById('ip').value = getRowData(row, ['ip']);
    document.getElementById('mac').value = getRowData(row, ['mac']);
    document.getElementById('nas').value = getRowData(row, ['nas']);

    getSummaryElement('summary_profile').textContent = getRowData(row, ['plan'], '-');
    getSummaryElement('summary_time').textContent = timeLimitLabel;
    getSummaryElement('summary_data_limit').textContent = dataLimitLabel;
    getSummaryElement('summary_session_total').textContent = sessionTotalLabel;
    getSummaryElement('summary_expiration').textContent = expiration;
    getSummaryElement('summary_created_at').textContent = createdAtLabel;
    getSummaryElement('summary_data').textContent = dataConsumedLabel;
    getSummaryElement('summary_status').textContent = statusValue || '-';
    const selectedUsernameLabel = document.getElementById('selectedUsernameLabel');
    if (selectedUsernameLabel) {
        selectedUsernameLabel.textContent = username || '-';
    }
}

function resetUserDetails() {
    const summaryIds = [
        'summary_profile',
        'summary_data_limit',
        'summary_time',
        'summary_data',
        'summary_session_total',
        'summary_expiration',
        'summary_created_at',
        'summary_status',
    ];
    summaryIds.forEach((id) => {
        const el = getSummaryElement(id);
        el.textContent = '-';
    });

    const inputValues = [
        'server',
        'username',
        'password',
        'created_at_display',
        'expiration',
        'status',
        'time_limit_display',
        'data_limit_display',
        'session_total_display',
        'data_consumed_display',
        'price_display',
        'selling_price_display',
        'rate_limit_display',
        'shared_users_display',
    ];
    inputValues.forEach((id) => {
        const el = document.getElementById(id);
        if (el) {
            el.value = id === 'status' ? '' : '-';
        }
    });

    const profileSelect = document.getElementById('profile_id');
    if (profileSelect) {
        const placeholderOption = profileSelect.options[0] || null;
        if (placeholderOption) {
            placeholderOption.textContent = '-- Choisir un profil --';
        }
        profileSelect.selectedIndex = 0;
    }

    const hiddenIds = [
        'user_id',
        'nas_id',
        'fullname',
        'phone',
        'address',
        'email',
        'rate_limit',
        'session_timeout',
        'idle_timeout',
        'simultaneous_use',
        'data_limit',
        'account_type',
        'service',
        'plan',
        'created_at',
        'last_login',
        'online',
        'uptime_display',
        'ip',
        'mac',
        'data_usage',
        'nas',
    ];
    hiddenIds.forEach((id) => {
        const el = document.getElementById(id);
        if (el) {
            el.value = '';
        }
    });

    if (sessionsTable) {
        sessionsTable.innerHTML = '<tr><td colspan="5" class="text-center">Aucune session</td></tr>';
    }
    const selectedUsernameLabel = document.getElementById('selectedUsernameLabel');
    if (selectedUsernameLabel) {
        selectedUsernameLabel.textContent = '-';
    }
}

function selectUserRow(row, switchToDetails = true) {
    selectedRow = row;
    selectedUserId = row.dataset.id || null;
    rows.forEach((r) => r.classList.remove('table-active'));
    row.classList.add('table-active');
    fillUserDataFromRow(row);
    emptyState?.classList.add('d-none');
    loadSessions(row.dataset.username || '');
    disableEditMode();
    if (switchToDetails) {
        setViewMode('details');
    }
}

async function loadSessions(username) {
    if (!sessionsTable) {
        return;
    }
    try {
        const response = await fetch(`../api/users/get_user_sessions.php?username=${encodeURIComponent(username)}`);
        const data = await readJsonResponse(response);
        if (!data) {
            throw new Error('Reponse invalide');
        }
        const sessions = Array.isArray(data.sessions) ? data.sessions : [];
        const summaryDataMb = Number(data.summary_data_mb ?? data.total_data_mb ?? 0);
        const summaryDataLabel = data.summary_data_display
            || (Number.isFinite(summaryDataMb) && summaryDataMb > 0 ? `${summaryDataMb.toFixed(2)} MB` : '-');
        const summarySessionLabel = data.summary_duration_display || data.total_session_label || '-';

        if (sessions.length === 0) {
            sessionsTable.innerHTML = '<tr><td colspan="5" class="text-center">Aucune session</td></tr>';
            const fallbackSessionTotal = getRowData(selectedRow, ['session_total_label', 'sessionTotalLabel'], '-');
            const fallbackDataConsumed = getRowData(selectedRow, ['data_consumed_label', 'dataConsumedLabel'], '-');
            const resolvedSessionTotal = summarySessionLabel !== '-' ? summarySessionLabel : fallbackSessionTotal;
            const resolvedDataConsumed = summaryDataLabel !== '-' ? summaryDataLabel : fallbackDataConsumed;
            getSummaryElement('summary_data').textContent = resolvedDataConsumed;
            getSummaryElement('summary_session_total').textContent = resolvedSessionTotal;
            const sessionTotalInput = document.getElementById('session_total_display');
            if (sessionTotalInput) {
                sessionTotalInput.value = resolvedSessionTotal;
            }
            const dataConsumedInput = document.getElementById('data_consumed_display');
            if (dataConsumedInput) {
                dataConsumedInput.value = resolvedDataConsumed;
            }
            return;
        }

        sessionsTable.innerHTML = sessions.map((s) => {
            const dataValue = (s.data_mb !== undefined && s.data_mb !== null && String(s.data_mb).trim() !== '')
                ? `${s.data_mb} MB`
                : '-';
            return `<tr>
                <td>${formatSessionDateLabel(s.start || '')}</td>
                <td>${s.stop ? formatSessionDateLabel(s.stop) : 'En cours'}</td>
                <td>${s.duration || '0s'}</td>
                <td>${dataValue}</td>
                <td>${s.ip || '-'}</td>
            </tr>`;
        }).join('');

        getSummaryElement('summary_data').textContent = summaryDataLabel;
        getSummaryElement('summary_session_total').textContent = summarySessionLabel;
        const sessionTotalInput = document.getElementById('session_total_display');
        if (sessionTotalInput) {
            sessionTotalInput.value = summarySessionLabel;
        }
        const dataConsumedInput = document.getElementById('data_consumed_display');
        if (dataConsumedInput) {
            dataConsumedInput.value = summaryDataLabel;
        }
        document.getElementById('nas').value = data.nas || '-';
        document.getElementById('nas_id').value = resolveNasIdFromAddress(data.nas || '');
    } catch (error) {
        sessionsTable.innerHTML = '<tr><td colspan="5" class="text-center text-danger">Erreur de chargement</td></tr>';
    }
}

function getSelectedProfileOption() {
    const select = document.getElementById('profile_id');
    return select ? select.options[select.selectedIndex] ?? null : null;
}

function enableEditMode() {
    if (readonlyMode || !selectedUserId) {
        return;
    }
    document.querySelectorAll('.editable, .editable-only').forEach((el) => {
        el.disabled = false;
        el.readOnly = false;
        el.classList.add('editable-active');
    });
    const passwordInput = document.getElementById('password');
    if (passwordInput) {
        passwordInput.disabled = false;
        passwordInput.readOnly = false;
        passwordInput.classList.add('editable-active');
    }
    editBtn?.classList.add('d-none');
    saveBtn?.classList.remove('d-none');
    cancelBtn?.classList.remove('d-none');
}

function disableEditMode() {
    document.querySelectorAll('.editable, .editable-only').forEach((el) => {
        el.disabled = true;
        el.readOnly = true;
        el.classList.remove('editable-active');
    });
    editBtn?.classList.remove('d-none');
    saveBtn?.classList.add('d-none');
    cancelBtn?.classList.add('d-none');
}

async function saveUser() {
    if (!selectedUserId || !selectedRow) {
        flashMessage('Aucun utilisateur sélectionné.', 'warning');
        return;
    }
    const formData = new FormData();
    formData.set('id', selectedUserId);
    const usernameInput = document.getElementById('username');
    if (usernameInput) {
        formData.set('username', usernameInput.value);
    }
    const statusInput = document.getElementById('status');
    if (statusInput) {
        formData.set('status', statusInput.value);
    }
    const passwordInput = document.getElementById('password');
    if (passwordInput) {
        const rawPassword = passwordInput.dataset.rawPassword || passwordInput.value;
        formData.set('password', rawPassword);
    }
    const csrfToken = document.querySelector('input[name="csrf_token"]');
    if (csrfToken) {
        formData.set('csrf_token', csrfToken.value);
    }
    const nasIdInput = document.getElementById('nas_id');
    if (nasIdInput && nasIdInput.value) {
        formData.set('nas_id', nasIdInput.value);
    } else {
        const nasValue = document.getElementById('nas')?.value || '';
        const resolvedNasId = resolveNasIdFromAddress(nasValue);
        if (resolvedNasId) {
            formData.set('nas_id', resolvedNasId);
        }
    }
    if (activeDeviceId) {
        formData.set('device_id', activeDeviceId);
    }
    const isMikrotikRow = String(selectedRow.dataset.readonly || '') === '1' || activeDeviceType === 'mikrotik';
    const endpoint = isMikrotikRow
        ? '../api/users/update_mikrotik_user.php'
        : '../api/users/update_user.php';
    if (isMikrotikRow) {
        formData.set('old_username', selectedRow.dataset.username || '');
    }

    const response = await fetch(endpoint, { method: 'POST', body: formData });
    const data = await readJsonResponse(response);
    if (!data) {
        throw new Error('Reponse invalide');
    }
    if (!response.ok || !data.success) {
        throw new Error(data.message || 'Mise à jour impossible');
    }

    selectedRow.dataset.username = document.getElementById('username').value;
    selectedRow.dataset.expiration = document.getElementById('expiration').value;
    const updatedStatus = document.getElementById('status').value;
    selectedRow.dataset.status = updatedStatus;
    const statusBadge = selectedRow.querySelector('[data-column-key="status"] .badge');
    if (statusBadge) {
        statusBadge.textContent = updatedStatus || '-';
        statusBadge.classList.toggle('bg-success', updatedStatus === 'active');
        statusBadge.classList.toggle('bg-warning', updatedStatus !== 'active');
    }
    const profileOption = getSelectedProfileOption();
    if (profileOption && profileOption.value) {
        selectedRow.dataset.profile_id = profileOption.value;
        selectedRow.dataset.plan = profileOption.dataset.plan || profileOption.textContent.trim();
    }
    fillUserDataFromRow(selectedRow);
}

async function deleteUser() {
    if (!selectedUserId) {
        flashMessage('Aucun utilisateur sélectionné.', 'warning');
        return;
    }
    const formData = new FormData();
    const isReadOnly = (selectedRow?.dataset?.readonly || '') === '1';
    const username = getRowData(selectedRow, ['username'], '');
    let endpoint = '../api/users/delete_user.php';

    if (isReadOnly || activeDeviceType === 'mikrotik') {
        if (!username) {
            flashMessage('Utilisateur introuvable pour suppression.', 'warning');
            return;
        }
        endpoint = '../api/users/delete_mikrotik_user.php';
        formData.append('username', username);
    } else {
        formData.append('id', selectedUserId);
    }
    const csrfToken = document.querySelector('input[name="csrf_token"]');
    if (csrfToken) {
        formData.set('csrf_token', csrfToken.value);
    }
    const response = await fetch(endpoint, { method: 'POST', body: formData });
    const data = await readJsonResponse(response);
    if (!data) {
        throw new Error('Reponse invalide');
    }
    if (!response.ok || !data.success) {
        throw new Error(data.message || 'Suppression impossible');
    }

    selectedRow?.remove();
    selectedRow = null;
    selectedUserId = null;
    resetUserDetails();
    emptyState?.classList.remove('d-none');
    flashMessage(data.message || 'Utilisateur supprimé.', 'success');
}

fetch('../api/nas.php', { credentials: 'same-origin' })
    .then(async (res) => {
        const text = await res.text();
        if (!res.ok || text.trim() === '') {
            return null;
        }
        try {
            return JSON.parse(text);
        } catch (error) {
            return null;
        }
    })
    .then((data) => {
        if (data && data.success && Array.isArray(data.data)) {
            nasList = data.data.map((item) => ({
                ...item,
                nas_id: item.nas_id ?? '',
            }));
        }
    })
    .catch(() => {});

rows.forEach((row) => {
    row.addEventListener('click', (event) => {
        if (event.target.closest('.user-action-btn')) {
            selectUserRow(row, true);
            return;
        }
        selectUserRow(row, currentUsersViewMode !== 'details');
    });
});

usersViewModeToggle?.addEventListener('click', () => {
    setViewMode(currentUsersViewMode === 'list' ? 'details' : 'list');
    applyUsersFilters();
});
usersRefreshBtn?.addEventListener('click', () => window.location.reload());
usersSearchInput?.addEventListener('input', applyUsersFilters);
usersProfileFilter?.addEventListener('change', applyUsersFilters);
usersStatusFilter?.addEventListener('change', applyUsersFilters);
usersSearchSuggestions?.addEventListener('click', (event) => {
    const button = event.target.closest('.users-search-suggestion-item');
    if (!button || !usersSearchInput) {
        return;
    }
    usersSearchInput.value = button.dataset.username || '';
    applyUsersFilters();
    usersSearchSuggestions.classList.add('d-none');
});

editBtn?.addEventListener('click', enableEditMode);
cancelBtn?.addEventListener('click', () => {
    if (selectedRow) {
        fillUserDataFromRow(selectedRow);
    }
    disableEditMode();
});
saveBtn?.addEventListener('click', async () => {
    try {
        await saveUser();
        disableEditMode();
        flashMessage('Utilisateur mis à jour.', 'success');
    } catch (error) {
        flashMessage(error.message, 'danger');
    }
});
deleteBtn?.addEventListener('click', async () => {
    try {
        await deleteUser();
    } catch (error) {
        flashMessage(error.message, 'danger');
    }
});
reloadBtn?.addEventListener('click', () => {
    if (!selectedRow) {
        flashMessage('Sélectionner un utilisateur pour recharger.', 'warning');
        return;
    }
    const username = getRowData(selectedRow, ['username'], '');
    if (!username) {
        flashMessage('Utilisateur introuvable pour la recharge.', 'warning');
        return;
    }
    const profileId = getRowData(selectedRow, ['profile_id'], '');
    const profileName = getRowData(selectedRow, ['plan'], '');
    const params = new URLSearchParams();
    params.set('username', username);
    if (profileId) {
        params.set('profile_id', profileId);
    }
    if (profileName) {
        params.set('profile_name', profileName);
    }
    window.location.href = `user_recharge.php?${params.toString()}`;
});

columnToggles.forEach((toggle) => {
    toggle.addEventListener('change', () => {
        const key = toggle.dataset.columnKey || '';
        if (!key) {
            return;
        }
        document.querySelectorAll(`[data-column-key="${key}"]`).forEach((cell) => {
            cell.classList.toggle('d-none', !toggle.checked);
        });
        let stored = {};
        try {
            stored = JSON.parse(localStorage.getItem(USERS_COLUMNS_STORAGE_KEY) || '{}');
        } catch (error) {
            stored = {};
        }
        stored[key] = toggle.checked;
        localStorage.setItem(USERS_COLUMNS_STORAGE_KEY, JSON.stringify(stored));
    });
});

resetUserDetails();
setViewMode('list');
applyColumnVisibility();
applyUsersFilters();
