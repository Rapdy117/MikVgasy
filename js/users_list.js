let selectedRow = null;
let selectedUserId = null;
let nasList = [];
let currentUsersViewMode = 'list';
let userProfileOptionsLoaded = false;

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
const usersBulkDeleteBtn = document.getElementById('usersBulkDeleteBtn');
const usersSelectAll = document.getElementById('usersSelectAll');
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
const disableAccountBtn = document.getElementById('disableAccountBtn');
const USERS_COLUMNS_STORAGE_KEY = 'users_list.columns';
const USER_SELECTION_HELP_TEXT = 'Aucun compte ouvert — utilisez Rechercher pour choisir un utilisateur.';

function ensureSelectOption(select, value, label = value) {
    if (!select || String(value || '').trim() === '') {
        return;
    }
    const normalized = String(value).trim();
    const exists = Array.from(select.options).some((option) => option.value === normalized);
    if (!exists) {
        const option = document.createElement('option');
        option.value = normalized;
        option.textContent = label || normalized;
        select.appendChild(option);
    }
}

async function loadMikrotikProfileOptions() {
    const select = document.getElementById('profile_name_visible');
    if (activeDeviceType !== 'mikrotik' || !select || userProfileOptionsLoaded || !activeDeviceId) {
        return;
    }

    userProfileOptionsLoaded = true;
    try {
        const params = new URLSearchParams({ device_id: activeDeviceId });
        const response = await fetch(`../api/users/profile_options.php?${params.toString()}`, { credentials: 'same-origin' });
        const data = await readJsonResponse(response);
        if (!response.ok || !data?.success || !Array.isArray(data.profiles)) {
            throw new Error(data?.message || 'Profils indisponibles');
        }
        const currentValue = select.value;
        select.innerHTML = '<option value="">Profil</option>';
        data.profiles.forEach((profile) => {
            const name = String(profile?.name || '').trim();
            if (name === '') {
                return;
            }
            ensureSelectOption(select, name, name);
        });
        if (currentValue) {
            ensureSelectOption(select, currentValue, currentValue);
            select.value = currentValue;
        }
    } catch (error) {
        userProfileOptionsLoaded = false;
        flashMessage(error.message || 'Chargement des profils impossible.', 'warning');
    }
}

function setSelectedUsernameLabel(text, isHint = false) {
    const selectedUsernameLabel = document.getElementById('selectedUsernameLabel');
    if (!selectedUsernameLabel) {
        return;
    }

    selectedUsernameLabel.textContent = text || (isHint ? USER_SELECTION_HELP_TEXT : '-');
    selectedUsernameLabel.classList.toggle('text-muted', isHint);
    selectedUsernameLabel.classList.toggle('text-white-50', !isHint);
}

function syncDetailDeleteButtonState() {
    if (deleteBtn) {
        deleteBtn.disabled = !selectedUserId;
    }

    if (disableAccountBtn) {
        const statusValue = String(selectedRow?.dataset?.status || '').toLowerCase();
        if (!selectedRow) {
            disableAccountBtn.disabled = true;
            disableAccountBtn.innerHTML = '<i class="fa fa-user-slash me-1"></i> Désactiver';
            return;
        }

        disableAccountBtn.disabled = false;
        if (statusValue === 'disabled' || statusValue === 'expired') {
            disableAccountBtn.innerHTML = '<i class="fa fa-user-check me-1"></i> Activer';
        } else {
            disableAccountBtn.innerHTML = '<i class="fa fa-user-slash me-1"></i> Désactiver';
        }
    }
}

function formatStatusBadgeLabel(statusValue) {
    const normalized = String(statusValue || '').toLowerCase();
    if (normalized === 'active') {
        return 'ACTIVE';
    }
    if (normalized === 'expired') {
        return 'EXPIRE';
    }
    if (normalized === 'disabled') {
        return 'DESACTIVE';
    }
    return normalized !== '' ? normalized.toUpperCase() : '-';
}

function getRowCheckbox(row) {
    return row?.querySelector('.user-row-select') || null;
}

function getCurrentUserRows() {
    return Array.from(document.querySelectorAll('.user-row'));
}

function getVisibleUserRows() {
    return getCurrentUserRows().filter((row) => !row.classList.contains('d-none'));
}

function getSelectedUserRows() {
    return getCurrentUserRows().filter((row) => {
        const checkbox = getRowCheckbox(row);
        return !!checkbox && checkbox.checked;
    });
}

function updateBulkSelectionState() {
    const selectedRows = getSelectedUserRows();
    if (usersBulkDeleteBtn) {
        usersBulkDeleteBtn.disabled = selectedRows.length === 0;
    }

    if (usersSelectAll) {
        const visibleCheckboxes = getVisibleUserRows()
            .map((row) => getRowCheckbox(row))
            .filter(Boolean);
        usersSelectAll.checked = visibleCheckboxes.length > 0 && visibleCheckboxes.every((checkbox) => checkbox.checked);
        usersSelectAll.indeterminate =
            visibleCheckboxes.length > 0 &&
            !usersSelectAll.checked &&
            visibleCheckboxes.some((checkbox) => checkbox.checked);
    }
}

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
    AppToast.flash(String(message || '').trim() || 'Opération effectuée.', type);
}

function showUsersFlowExplanationToast() {
    const flowExplanation = document.getElementById('usersFlowExplanation');
    if (!flowExplanation || flowExplanation.dataset.toastShown === '1') {
        return;
    }

    const title = String(flowExplanation.dataset.toastTitle || '').trim();
    const message = String(flowExplanation.dataset.toastMessage || '').trim();
    const text = [title, message].filter(Boolean).join(' — ');
    if (text === '') {
        return;
    }

    flowExplanation.dataset.toastShown = '1';
    AppToast.flash(text, 'info');
}

function resolveNasIdFromAddress(address) {
    if (!address) {
        return nasList[0]?.nas_id ?? '';
    }
    const match = nasList.find((nas) => nas.nasname === address || nas.shortname === address);
    return match ? (match.nas_id ?? '') : (nasList[0]?.nas_id ?? '');
}

function maskPassword(rawValue) {
    if (activeDeviceType === 'mikrotik' || activeDeviceType === 'opnsense') {
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
    updateBulkSelectionState();
}

function hideSearchSuggestions() {
    if (!usersSearchSuggestions) {
        return;
    }
    usersSearchSuggestions.classList.add('d-none');
    usersSearchSuggestions.innerHTML = '';
}

function renderSearchSuggestions(query) {
    if (!usersSearchSuggestions) {
        return;
    }
    const normalized = (query || '').trim().toLowerCase();
    if (normalized.length < 1) {
        hideSearchSuggestions();
        return;
    }

    const candidates = rows
        .filter((row) => !row.classList.contains('d-none'))
        .map((row) => (row.dataset.username || '').trim())
        .filter((name) => name.toLowerCase().includes(normalized))
        .slice(0, 6);

    if (candidates.length === 0) {
        hideSearchSuggestions();
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
    const profileName = getRowData(row, ['plan'], '');
    const rateLimitLabel = getRowData(row, ['rate_limit_label', 'rateLimitLabel', 'rate_limit'], '-');
    const sharedUsersLabel = getRowData(row, ['shared_users_label', 'sharedUsersLabel', 'simultaneous_use'], '-');
    const timeLimitLabel = getRowData(row, ['time_limit_label', 'timeLimitLabel', 'session_timeout'], '-');
    const dataLimitLabel = getRowData(row, ['data_limit_label', 'dataLimitLabel', 'data_limit'], '-');
    const sessionTotalLabel = getRowData(row, ['session_total_label', 'sessionTotalLabel'], '-');
    const dataConsumedLabel = getRowData(row, ['data_consumed_label', 'dataConsumedLabel'], '-');
    const priceLabel = getRowData(row, ['price_label', 'priceLabel'], '-');
    const sellingPriceLabel = getRowData(row, ['selling_price_label', 'sellingPriceLabel'], '-');
    const createdAt = getRowData(row, ['created_at'], '-');

    document.getElementById('user_id').value = id;
    document.getElementById('nas_id').value = nasId;
    document.getElementById('username').value = username;
    const loginMode = getRowData(row, ['login_mode'], 'password') === 'mac' ? 'mac' : 'password';
    const accountLabel = getRowData(row, ['label'], '');
    const isMacType = loginMode === 'mac';
    const displayNameInput = document.getElementById('displayName');
    if (displayNameInput) displayNameInput.value = isMacType ? (accountLabel || username) : '';
    const macRestrictionInput = document.getElementById('macRestriction');
    if (macRestrictionInput) {
        macRestrictionInput.value = isMacType ? username : getRowData(row, ['mac_restriction'], '');
    }
    const accountLabelRowEl = document.getElementById('accountLabelRow');
    if (accountLabelRowEl) accountLabelRowEl.hidden = !(isMacType && accountLabel !== '');
    const accountLabelDisplay = document.getElementById('accountLabelDisplay');
    if (accountLabelDisplay) accountLabelDisplay.value = accountLabel;
    const loginTypeDisplay = document.getElementById('loginTypeDisplay');
    if (loginTypeDisplay) {
        loginTypeDisplay.value = loginMode === 'mac' ? 'MAC (sans mot de passe)' : 'Identifiant + mot de passe';
        loginTypeDisplay.classList.remove('d-none');
    }
    const loginTypeSelect = document.getElementById('loginTypeSelect');
    if (loginTypeSelect) {
        loginTypeSelect.value = loginMode;
        loginTypeSelect.classList.add('d-none');
    }
    // Visibilité et contraintes des champs dépendants (Nom, mot de passe, MAC, libellé) : état réel du compte.
    applyAccountTypeEditUI(loginMode);
    const passwordInput = document.getElementById('password');
    if (passwordInput) {
        passwordInput.value = loginMode === 'mac' ? '' : maskPassword(password);
        passwordInput.dataset.rawPassword = loginMode === 'mac' ? '' : password;
        passwordInput.dataset.loginMode = loginMode;
        passwordInput.placeholder = loginMode === 'mac' ? 'Non utilisé (login par MAC)' : '';
    }
    const profileInput = document.getElementById('profile_id');
    if (profileInput) {
        profileInput.value = profileId;
    }
    document.getElementById('expiration').value = expiration;
    const visibleProfile = document.getElementById('profile_id_visible');
    if (visibleProfile) visibleProfile.value = profileId || '';
    const visibleProfileName = document.getElementById('profile_name_visible');
    if (visibleProfileName) {
        ensureSelectOption(visibleProfileName, profileName, profileName);
        visibleProfileName.value = profileName;
        if (activeDeviceType === 'mikrotik') {
            loadMikrotikProfileOptions();
        }
    }
    const visibleExpiration = document.getElementById('expiration_visible');
    if (visibleExpiration) {
        const normalizedExpiration = expiration && expiration !== '-' ? String(expiration).replace(' ', 'T').slice(0, 10) : '';
        visibleExpiration.value = normalizedExpiration;
    }
    const createdAtLabel = formatDateOnly(createdAt);

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

    getSummaryElement('summary_server').textContent = serverDisplay;
    getSummaryElement('summary_profile').textContent = getRowData(row, ['plan'], '-');
    getSummaryElement('summary_rate_limit').textContent = rateLimitLabel;
    getSummaryElement('summary_shared_users').textContent = sharedUsersLabel;
    getSummaryElement('summary_time').textContent = timeLimitLabel;
    getSummaryElement('summary_data_limit').textContent = dataLimitLabel;
    getSummaryElement('summary_session_total').textContent = sessionTotalLabel;
    getSummaryElement('summary_expiration').textContent = formatDateOnly(expiration);
    getSummaryElement('summary_created_at').textContent = createdAtLabel;
    getSummaryElement('summary_data').textContent = dataConsumedLabel;
    getSummaryElement('summary_status').textContent = formatStatusBadgeLabel(statusValue);
    getSummaryElement('summary_price').textContent = priceLabel;
    getSummaryElement('summary_selling_price').textContent = sellingPriceLabel;
    setSelectedUsernameLabel(username || '-', false);
}

function resetUserDetails() {
    const summaryIds = [
        'summary_profile',
        'summary_server',
        'summary_rate_limit',
        'summary_shared_users',
        'summary_data_limit',
        'summary_time',
        'summary_data',
        'summary_session_total',
        'summary_expiration',
        'summary_created_at',
        'summary_status',
        'summary_price',
        'summary_selling_price',
    ];
    summaryIds.forEach((id) => {
        const el = getSummaryElement(id);
        el.textContent = '-';
    });

    const inputValues = [
        'username',
        'password',
        'expiration',
        'status',
    ];
    inputValues.forEach((id) => {
        const el = document.getElementById(id);
        if (el) {
            el.value = id === 'status' ? '' : '-';
        }
    });

    const profileInput = document.getElementById('profile_id');
    if (profileInput) {
        profileInput.value = '';
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
    setSelectedUsernameLabel(USER_SELECTION_HELP_TEXT, true);
    syncDetailDeleteButtonState();
}

function selectUserRow(row, switchToDetails = true) {
    selectedRow = row;
    selectedUserId = row.dataset.id || null;
    rows.forEach((r) => r.classList.remove('table-active'));
    row.classList.add('table-active');
    fillUserDataFromRow(row);
    emptyState?.classList.add('d-none');
    loadSessions(row.dataset.username || '', row.dataset.nas_id || '', row.dataset.device_id || '');
    syncDetailDeleteButtonState();
    disableEditMode();
    if (switchToDetails) {
        setViewMode('details');
    }
}

async function loadSessions(username, nasId = '', deviceId = '') {
    if (!sessionsTable) {
        return;
    }
    try {
        const params = new URLSearchParams({ username: String(username || '') });
        if (String(nasId || '').trim() !== '') {
            params.set('nas_id', String(nasId).trim());
        }
        if (String(deviceId || '').trim() !== '') {
            params.set('device_id', String(deviceId).trim());
        }

        const response = await fetch(`../api/users/get_user_sessions.php?${params.toString()}`);
        const data = await readJsonResponse(response);
        if (!data) {
            throw new Error('Reponse invalide');
        }
        const sessions = Array.isArray(data.sessions) ? data.sessions : [];
        const summaryDataMb = Number(data.summary_data_mb ?? data.total_data_mb ?? 0);
        const summaryDataLabel = data.summary_data_display
            || (Number.isFinite(summaryDataMb) && summaryDataMb > 0 ? `${summaryDataMb.toFixed(2)} MB` : '-');
        const summarySessionLabel = data.summary_duration_display || data.total_session_label || '-';
        const isMikrotikActiveObservation = String(data.observation_mode || '').toLowerCase() === 'active';
        const mikrotikInfoRow = `
            <tr class="users-sessions-info-row">
                <td colspan="5" class="text-start text-white-50 small">
                    <i class="fa fa-info-circle me-2"></i>
                    MikroTik : la session en cours et les cumuls viennent de /ip/hotspot/user. Les anciennes sessions sont affichees uniquement si elles existent dans RADIUS / radacct.
                </td>
            </tr>
        `;

        if (sessions.length === 0) {
            sessionsTable.innerHTML = '<tr><td colspan="5" class="text-center">Aucune session</td></tr>';
            if (isMikrotikActiveObservation) {
                sessionsTable.innerHTML += mikrotikInfoRow;
            }
            const fallbackSessionTotal = getRowData(selectedRow, ['session_total_label', 'sessionTotalLabel'], '-');
            const fallbackDataConsumed = getRowData(selectedRow, ['data_consumed_label', 'dataConsumedLabel'], '-');
            const resolvedSessionTotal = summarySessionLabel !== '-' ? summarySessionLabel : fallbackSessionTotal;
            const resolvedDataConsumed = summaryDataLabel !== '-' ? summaryDataLabel : fallbackDataConsumed;
            getSummaryElement('summary_data').textContent = resolvedDataConsumed;
            getSummaryElement('summary_session_total').textContent = resolvedSessionTotal;
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

        if (isMikrotikActiveObservation) {
            sessionsTable.innerHTML += mikrotikInfoRow;
        }

        getSummaryElement('summary_data').textContent = summaryDataLabel;
        getSummaryElement('summary_session_total').textContent = summarySessionLabel;
        document.getElementById('nas').value = data.nas || '-';
        document.getElementById('nas_id').value = resolveNasIdFromAddress(data.nas || '');
    } catch (error) {
        sessionsTable.innerHTML = '<tr><td colspan="5" class="text-center text-danger">Erreur de chargement</td></tr>';
    }
}

// Bascule des champs dépendants du type de compte MikroTik (Nom, mot de passe, MAC, libellé),
// identique à la page de création (add_hotspot_user.php) : mêmes lignes, même logique.
function applyAccountTypeEditUI(mode) {
    const isMac = mode === 'mac';
    const usernameRow = document.getElementById('usernameRow');
    const usernameField = document.getElementById('username');
    const usernameFieldLabel = document.getElementById('usernameFieldLabel');
    const cardPasswordRow = document.getElementById('cardPasswordRow');
    const passwordInput = document.getElementById('password');
    const cardMacRow = document.getElementById('cardMacRow');
    const macRestrictionLabel = document.getElementById('macRestrictionLabel');
    const macRestrictionInput = document.getElementById('macRestriction');
    const displayNameRow = document.getElementById('displayNameRow');
    const displayNameInput = document.getElementById('displayName');

    if (usernameFieldLabel) usernameFieldLabel.textContent = 'Nom';
    if (usernameRow) usernameRow.hidden = isMac;
    if (usernameField) { usernameField.disabled = isMac; usernameField.required = !isMac; }

    if (cardPasswordRow) cardPasswordRow.hidden = isMac;
    if (passwordInput) { passwordInput.disabled = isMac; passwordInput.required = !isMac; }

    if (cardMacRow) cardMacRow.hidden = false;
    if (macRestrictionLabel) macRestrictionLabel.textContent = isMac ? 'MAC' : 'MAC (restriction, facultatif)';
    if (macRestrictionInput) {
        macRestrictionInput.required = isMac;
        macRestrictionInput.placeholder = 'AA:BB:CC:DD:EE:FF';
    }

    if (displayNameRow) displayNameRow.hidden = !isMac;
    if (displayNameInput) { displayNameInput.disabled = !isMac; displayNameInput.required = isMac; }
}

function enableEditMode() {
    if (readonlyMode || !selectedUserId) {
        return;
    }
    if (activeDeviceType === 'mikrotik') {
        loadMikrotikProfileOptions();
    }
    document.querySelectorAll('.editable, .editable-only').forEach((el) => {
        el.disabled = false;
        el.readOnly = false;
        el.classList.add('editable-active');
    });
    const passwordInput = document.getElementById('password');
    if (passwordInput && passwordInput.dataset.loginMode !== 'mac') {
        passwordInput.classList.add('editable-active');
        // For masked RADIUS values: empty means "keep current password".
        if (activeDeviceType !== 'mikrotik' && activeDeviceType !== 'opnsense') {
            passwordInput.value = '';
            passwordInput.placeholder = 'Laisser vide pour conserver le mot de passe';
        }
    }
    // Compte MikroTik : le type (MAC / identifiant) devient modifiable, comme à la création.
    const loginTypeDisplay = document.getElementById('loginTypeDisplay');
    const loginTypeSelect = document.getElementById('loginTypeSelect');
    if (loginTypeSelect && activeDeviceType === 'mikrotik') {
        loginTypeDisplay?.classList.add('d-none');
        loginTypeSelect.classList.remove('d-none');
        loginTypeSelect.disabled = false;
    }
    applyAccountTypeEditUI(loginTypeSelect ? loginTypeSelect.value : String(selectedRow?.dataset.login_mode || 'password'));
    editBtn?.classList.add('d-none');
    saveBtn?.classList.remove('d-none');
    cancelBtn?.classList.remove('d-none');
}

function disableEditMode() {
    const loginTypeDisplay = document.getElementById('loginTypeDisplay');
    const loginTypeSelect = document.getElementById('loginTypeSelect');
    if (loginTypeSelect && loginTypeDisplay) {
        loginTypeDisplay.value = loginTypeSelect.value === 'mac' ? 'MAC (sans mot de passe)' : 'Identifiant + mot de passe';
    }
    document.querySelectorAll('.editable, .editable-only').forEach((el) => {
        el.disabled = true;
        el.readOnly = true;
        el.classList.remove('editable-active');
    });
    loginTypeDisplay?.classList.remove('d-none');
    loginTypeSelect?.classList.add('d-none');
    editBtn?.classList.remove('d-none');
    saveBtn?.classList.add('d-none');
    cancelBtn?.classList.add('d-none');
}

async function saveUser(options = {}) {
    if (!selectedRow) {
        throw new Error('Aucun utilisateur sélectionné.');
    }
    const isMikrotikRow = String(selectedRow.dataset.readonly || '') === '1' || activeDeviceType === 'mikrotik';
    if (!selectedUserId && !isMikrotikRow) {
        throw new Error('Aucun utilisateur sélectionné.');
    }

    const formData = new FormData();
    if (!isMikrotikRow) {
        formData.set('id', selectedUserId);
    }
    const usernameInput = document.getElementById('username');
    const macRestrictionInput = document.getElementById('macRestriction');
    // Le type peut avoir été changé pendant l'édition (conversion MAC <-> identifiant) : on lit le
    // sélecteur, pas l'ancien type de la ligne, pour cibler le bon enregistrement côté serveur.
    const loginTypeSelectEl = document.getElementById('loginTypeSelect');
    const selectedLoginMode = loginTypeSelectEl ? loginTypeSelectEl.value : String(selectedRow.dataset.login_mode || 'password');
    const rowIsMacAccount = selectedLoginMode === 'mac';
    if (isMikrotikRow) {
        formData.set('login_mode', selectedLoginMode);
    }
    if (rowIsMacAccount) {
        // Compte MAC : le nom RouterOS devient la MAC (jamais le libellé), et aucun mot de passe n'est envoyé.
        const macValue = (macRestrictionInput?.value || usernameInput?.value || '').trim().toUpperCase();
        formData.set('username', macValue);
        formData.set('mac', macValue);
        formData.delete('password');
        // Le libellé (nom affiché) est une donnée locale, enregistrée à part du compte RouterOS.
        const labelInput = document.getElementById('displayName');
        if (labelInput) {
            formData.set('label', labelInput.value.trim());
        }
    } else {
        if (usernameInput) {
            formData.set('username', usernameInput.value);
        }
        // Compte avec mot de passe : restriction MAC facultative (vide = inchangée).
        const restriction = (macRestrictionInput?.value || '').trim().toUpperCase();
        if (restriction !== '') {
            formData.set('mac', restriction);
        }
    }
    const statusInput = document.getElementById('status');
    if (statusInput) {
        formData.set('status', statusInput.value);
    }

    const passwordInput = document.getElementById('password');
    let sentPassword = null;
    if (!options.skipPassword && !rowIsMacAccount && passwordInput) {
        const typedPassword = passwordInput.value.trim();
        // For masked RADIUS: empty = keep old password. For visible backends: use the field value.
        sentPassword = typedPassword !== '' ? typedPassword : (passwordInput.dataset.rawPassword || '');
        formData.set('password', sentPassword);
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
    const endpoint = isMikrotikRow
        ? '../api/users/update_mikrotik_user.php'
        : '../api/users/update_user.php';
    if (isMikrotikRow) {
        formData.set('old_username', selectedRow.dataset.username || '');
        const profileNameInput = document.getElementById('profile_name_visible');
        if (profileNameInput && profileNameInput.value.trim() !== '') {
            formData.set('profile_name', profileNameInput.value.trim());
        }
        const mikrotikExpiration = document.getElementById('expiration_visible');
        if (mikrotikExpiration) {
            formData.set('expiration_date', mikrotikExpiration.value ? mikrotikExpiration.value.replace('T', ' ') : '');
        }
    }

    // For RADIUS: preserve all hidden-field values so the UPDATE query
    // does not overwrite them with NULL/0.
    if (!isMikrotikRow) {
        const visibleProfile = document.getElementById('profile_id_visible');
        if (visibleProfile && visibleProfile.value) {
            formData.set('profile_id', visibleProfile.value);
        }
        const visibleExpiration = document.getElementById('expiration_visible');
        if (visibleExpiration && visibleExpiration.value) {
            const expirationValue = visibleExpiration.value.replace('T', ' ');
            formData.set('expiration_date', expirationValue);
            document.getElementById('expiration').value = expirationValue;
        }
        const profileIdInput = document.getElementById('profile_id');
        if (profileIdInput && profileIdInput.value) {
            formData.set('profile_id', profileIdInput.value);
        }
        ['fullname', 'phone', 'address', 'email', 'balance',
         'auto_renewal', 'session_timeout', 'data_limit'].forEach((field) => {
            const el = document.getElementById(field);
            if (el) {
                formData.set(field, el.value);
            }
        });
        const expirationEl = document.getElementById('expiration');
        const expirationVal = expirationEl ? expirationEl.value : '';
        if (expirationVal && expirationVal !== '-') {
            formData.set('expiration_date', expirationVal);
        }
    }

    const response = await fetch(endpoint, { method: 'POST', body: formData });
    const data = await readJsonResponse(response);
    if (!data) {
        throw new Error('Reponse invalide');
    }
    if (!response.ok || !data.success) {
        throw new Error(data.message || 'Mise à jour impossible');
    }

    // Le nom réellement enregistré dépend du type cible : la MAC pour un compte MAC, le champ Nom sinon.
    const newUsername = rowIsMacAccount
        ? (macRestrictionInput?.value || usernameInput?.value || '').trim().toUpperCase()
        : (usernameInput?.value || '');
    selectedRow.dataset.username = newUsername;
    selectedRow.dataset.login_mode = selectedLoginMode;
    if (rowIsMacAccount) {
        // Le libellé vient d'être enregistré côté serveur : garder la ligne et l'affichage à jour.
        const labelInput = document.getElementById('displayName');
        selectedRow.dataset.label = labelInput ? labelInput.value.trim() : selectedRow.dataset.label || '';
        selectedRow.dataset.mac_restriction = '';
    } else {
        selectedRow.dataset.mac_restriction = (macRestrictionInput?.value || '').trim().toUpperCase();
    }
    const macAccountLabel = String(selectedRow.dataset.label || '');
    const mikrotikExpirationInput = document.getElementById('expiration_visible');
    const radiusExpirationInput = document.getElementById('expiration');
    const savedExpiration = isMikrotikRow
        ? (mikrotikExpirationInput?.value || '')
        : (radiusExpirationInput?.value || '');
    selectedRow.dataset.expiration = savedExpiration;
    const updatedStatus = String(document.getElementById('status')?.value || selectedRow.dataset.status || '').toLowerCase();
    selectedRow.dataset.status = updatedStatus;

    const profileControl = isMikrotikRow
        ? document.getElementById('profile_name_visible')
        : document.getElementById('profile_id_visible');
    const profileLabel = profileControl?.selectedOptions?.[0]?.textContent?.trim()
        || profileControl?.value
        || selectedRow.dataset.plan
        || '';
    if (profileLabel !== '') {
        selectedRow.dataset.plan = profileLabel;
        const profileCell = selectedRow.querySelector('[data-column-key="plan"]');
        if (profileCell) {
            profileCell.textContent = profileLabel;
        }
    }

    // Keep password dataset in sync so subsequent edits start with the correct value.
    if (sentPassword !== null && passwordInput) {
        selectedRow.dataset.password = sentPassword;
        passwordInput.dataset.rawPassword = sentPassword;
    }

    // Update visible username cell in the list so it reflects the change without a reload.
    const usernameCell = selectedRow.querySelector('[data-column-key="username"]');
    if (usernameCell) {
        usernameCell.textContent = rowIsMacAccount && macAccountLabel !== '' ? macAccountLabel : newUsername;
    }

    const statusBadge = selectedRow.querySelector('[data-column-key="status"] .badge');
    if (statusBadge) {
        statusBadge.textContent = formatStatusBadgeLabel(updatedStatus);
        statusBadge.classList.remove('bg-success', 'bg-warning', 'bg-secondary');
        statusBadge.classList.add(
            updatedStatus === 'active'
                ? 'bg-success'
                : (updatedStatus === 'expired' ? 'bg-warning' : 'bg-secondary')
        );
    }
    fillUserDataFromRow(selectedRow);
    syncDetailDeleteButtonState();
}

async function disableSelectedAccount() {
    if (!selectedRow) {
        flashMessage('Aucun compte ouvert à désactiver.', 'warning');
        return;
    }

    const currentStatus = String(selectedRow.dataset.status || '').toLowerCase();
    const nextStatus = (currentStatus === 'disabled' || currentStatus === 'expired') ? 'active' : 'disabled';

    const statusInput = document.getElementById('status');
    if (statusInput) {
        statusInput.value = nextStatus;
    }
    if (nextStatus === 'active' && activeDeviceType === 'mikrotik') {
        const expirationInput = document.getElementById('expiration_visible');
        if (expirationInput) {
            expirationInput.value = '';
        }
    }

    await saveUser({ skipPassword: true });
    disableEditMode();
    flashMessage(nextStatus === 'disabled' ? 'Compte désactivé.' : 'Compte réactivé.', 'success');
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
        if (!activeDeviceId) {
            flashMessage('Serveur MikroTik non sélectionné.', 'warning');
            return;
        }
        endpoint = '../api/users/delete_mikrotik_user.php';
        formData.append('username', username);
        formData.append('device_id', activeDeviceId);
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
    updateBulkSelectionState();
    flashMessage(data.message || 'Utilisateur supprimé.', 'success');
}

async function deleteUserByRow(row) {
    if (!row) {
        throw new Error('Ligne utilisateur introuvable.');
    }

    const rowId = row.dataset.id || '';
    const isReadOnly = (row.dataset.readonly || '') === '1';
    const username = getRowData(row, ['username'], '');
    const formData = new FormData();
    let endpoint = '../api/users/delete_user.php';

    if (isReadOnly || activeDeviceType === 'mikrotik') {
        if (!username) {
            throw new Error('Utilisateur introuvable pour suppression.');
        }
        if (!activeDeviceId) {
            throw new Error('Serveur MikroTik non sélectionné.');
        }
        endpoint = '../api/users/delete_mikrotik_user.php';
        formData.append('username', username);
        formData.append('device_id', activeDeviceId);
    } else {
        if (!rowId) {
            throw new Error('ID utilisateur manquant.');
        }
        formData.append('id', rowId);
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

    row.remove();

    if (selectedRow === row) {
        selectedRow = null;
        selectedUserId = null;
        resetUserDetails();
        emptyState?.classList.remove('d-none');
    }

    updateBulkSelectionState();
    return data;
}

async function deleteSelectedUsers() {
    const selectedRows = getSelectedUserRows();
    if (selectedRows.length === 0) {
        flashMessage('Aucun utilisateur sélectionné.', 'warning');
        return;
    }

    if (!window.confirm(`Supprimer ${selectedRows.length} utilisateur(s) sélectionné(s) ?`)) {
        return;
    }

    if (usersBulkDeleteBtn) {
        usersBulkDeleteBtn.disabled = true;
    }

    let successCount = 0;
    const errors = [];

    for (const row of selectedRows) {
        try {
            await deleteUserByRow(row);
            successCount += 1;
        } catch (error) {
            errors.push(`${getRowData(row, ['username'], '-')}: ${error.message}`);
        }
    }

    updateBulkSelectionState();

    if (errors.length === 0) {
        flashMessage(`${successCount} utilisateur(s) supprimé(s).`, 'success');
        return;
    }

    if (successCount > 0) {
        flashMessage(`${successCount} suppression(s) réussie(s), ${errors.length} échec(s). ${errors[0]}`, 'warning');
        return;
    }

    flashMessage(errors[0], 'danger');
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
    getRowCheckbox(row)?.addEventListener('click', (event) => {
        event.stopPropagation();
        hideSearchSuggestions();
        updateBulkSelectionState();
    });

    row.addEventListener('click', (event) => {
        if (event.target.closest('.user-row-select')) {
            return;
        }
        if (event.target.closest('.user-action-btn')) {
            hideSearchSuggestions();
            selectUserRow(row, true);
            return;
        }
        hideSearchSuggestions();
        selectUserRow(row, currentUsersViewMode !== 'details');
    });
});

usersViewModeToggle?.addEventListener('click', () => {
    setViewMode(currentUsersViewMode === 'list' ? 'details' : 'list');
    applyUsersFilters();
});
usersRefreshBtn?.addEventListener('click', () => window.location.reload());
usersSelectAll?.addEventListener('change', () => {
    getVisibleUserRows().forEach((row) => {
        const checkbox = getRowCheckbox(row);
        if (checkbox) {
            checkbox.checked = usersSelectAll.checked;
        }
    });
    updateBulkSelectionState();
});
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
    hideSearchSuggestions();
});

editBtn?.addEventListener('click', enableEditMode);
document.getElementById('loginTypeSelect')?.addEventListener('change', (event) => {
    applyAccountTypeEditUI(event.target.value === 'mac' ? 'mac' : 'password');
});
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
usersBulkDeleteBtn?.addEventListener('click', async () => {
    try {
        await deleteSelectedUsers();
    } catch (error) {
        flashMessage(error.message, 'danger');
    }
});
disableAccountBtn?.addEventListener('click', async () => {
    try {
        await disableSelectedAccount();
    } catch (error) {
        flashMessage(error.message, 'danger');
    }
});
reloadBtn?.addEventListener('click', () => {
    if (!selectedRow) {
        flashMessage('Aucun compte ouvert — utilisez Rechercher pour choisir un utilisateur avant la recharge.', 'warning');
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
const requestedUsername = new URLSearchParams(window.location.search).get('username');
if (requestedUsername && usersSearchInput) {
    usersSearchInput.value = requestedUsername;
}
applyUsersFilters();
if (requestedUsername) {
    const requestedRow = rows.find((row) => (row.dataset.username || '') === requestedUsername);
    if (requestedRow) selectUserRow(requestedRow, true);
}
updateBulkSelectionState();
showUsersFlowExplanationToast();
