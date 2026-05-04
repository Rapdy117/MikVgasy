const rechargeForm = document.getElementById('userRechargeForm');
const deviceSelect = document.getElementById('rechargeDeviceSelect');
const userSelect = document.getElementById('rechargeUserSelect');
const userSearchInput = document.getElementById('rechargeUserSearch');
const userResultsBox = document.getElementById('rechargeUserResults');
const currentProfileSelect = document.getElementById('rechargeCurrentProfileSelect');
const profileSelect = document.getElementById('rechargeProfileSelect');
const profileLabel = document.getElementById('rechargeProfileLabel');
const profileIdInput = document.createElement('input');
profileIdInput.type = 'hidden';
profileIdInput.name = 'profile_id';
rechargeForm?.appendChild(profileIdInput);

const profileNameInput = document.createElement('input');
profileNameInput.type = 'hidden';
profileNameInput.name = 'profile_name';
rechargeForm?.appendChild(profileNameInput);

const modeSelect = document.getElementById('rechargeModeSelect');
const applyRechargeBtn = document.getElementById('applyRechargeBtn');
const dataUnitSelect = document.getElementById('rechargeDataUnitSelect');
const messageArea = document.getElementById('messageArea');
const pageParams = new URLSearchParams(window.location.search);
const presetUsername = pageParams.get('username') || '';
let isPreviewLoading = false;
let isApplyInProgress = false;
const presetProfileId = pageParams.get('profile_id') || '';
const presetProfileName = pageParams.get('profile_name') || '';
let lastPreviewPayload = null;
let previewLoadPromise = null;
let previewLoadSignature = '';
let rechargeUserItems = [];
let previewDebounceTimer = null;
let historyDebounceTimer = null;
let lastPreviewSignature = '';
let lastHistorySignature = '';
const rechargePreviewHint = 'Choisissez un utilisateur, un profil et un mode pour préparer l aperçu.';

function resetPreviewState(showHint = false) {
    lastPreviewPayload = null;
    lastPreviewSignature = '';
    previewLoadSignature = '';

    const previewEmpty = document.getElementById('rechargePreviewEmpty');
    const previewContent = document.getElementById('rechargePreviewContent');
    const notesBox = document.getElementById('rechargeNotesBox');

    if (previewEmpty) {
        previewEmpty.classList.add('d-none');
    }
    if (previewContent) {
        previewContent.classList.add('d-none');
    }
    if (notesBox) {
        notesBox.textContent = '';
        notesBox.classList.add('d-none');
    }
    if (applyRechargeBtn) {
        applyRechargeBtn.disabled = true;
        applyRechargeBtn.dataset.canApplyNow = '0';
    }
    if (showHint) {
        showRechargeToast(rechargePreviewHint, 'info');
    }
}

function buildPreviewSignature() {
    return JSON.stringify({
        device_id: (deviceSelect?.value || '').trim(),
        username: (userSelect?.value || '').trim(),
        mode: (modeSelect?.value || '').trim(),
        profile_id: (profileIdInput?.value || '').trim(),
        profile_name: (profileNameInput?.value || '').trim(),
    });
}

function buildHistorySignature() {
    return JSON.stringify({
        device_id: (deviceSelect?.value || '').trim(),
        username: (userSelect?.value || '').trim(),
    });
}

function schedulePreviewLoad(delay = 180) {
    if (previewDebounceTimer) {
        clearTimeout(previewDebounceTimer);
    }
    previewDebounceTimer = setTimeout(() => {
        previewDebounceTimer = null;
        loadPreview();
    }, delay);
}

function scheduleHistoryLoad(delay = 180) {
    if (historyDebounceTimer) {
        clearTimeout(historyDebounceTimer);
    }
    historyDebounceTimer = setTimeout(() => {
        historyDebounceTimer = null;
        loadHistory();
    }, delay);
}

function showInlineMessage(message, type = 'info') {
    showRechargeToast(message, type);
    if (messageArea) {
        messageArea.innerHTML = '';
        messageArea.style.display = 'none';
    }
}

function showRechargeToast(message, type = 'success') {
    AppToast.flash(message, type);
}

function hideInlineMessage() {
    if (!messageArea) {
        return;
    }
    messageArea.innerHTML = '';
    messageArea.style.display = 'none';
}

function resolveDataLimitMegabytes(section) {
    if (!section || typeof section !== 'object') {
        return 0;
    }
    const direct = Number(section.data_limit_mb ?? 0);
    if (Number.isFinite(direct) && direct > 0) {
        return direct;
    }
    const label = String(section.data_limit || '').trim().toUpperCase();
    const match = label.match(/^([0-9]+(?:\.[0-9]+)?)\s*(KB|MB|GB)$/);
    if (!match) {
        return 0;
    }
    const value = Number(match[1]);
    const unit = match[2];
    if (!Number.isFinite(value) || value <= 0) {
        return 0;
    }
    if (unit === 'GB') return value * 1024;
    if (unit === 'KB') return value / 1024;
    return value;
}

function formatDataLimitFromMegabytes(megabytes) {
    const valueMb = Number(megabytes || 0);
    if (!Number.isFinite(valueMb) || valueMb <= 0) {
        return '-';
    }

    const valueKb = valueMb * 1024;
    if (valueKb < 1000) {
        return `${valueKb.toFixed(2).replace(/\.?0+$/, '')} KB`;
    }

    if (valueMb < 1000) {
        return `${valueMb.toFixed(2).replace(/\.?0+$/, '')} MB`;
    }

    return `${(valueMb / 1024).toFixed(2).replace(/\.?0+$/, '')} GB`;
}

function renderHistory(items = []) {
    const tbody = document.getElementById('rechargeHistoryTable');
    if (!tbody) {
        return;
    }

    tbody.innerHTML = '';

    const rows = Array.isArray(items) ? items.slice(0, 4) : [];
    const paddedRows = [...rows];
    while (paddedRows.length < 4) {
        paddedRows.push(null);
    }

    paddedRows.forEach((item, index) => {
        const tr = document.createElement('tr');
        if (!item) {
            tr.innerHTML = `
                <td class="text-white-50">-</td>
                <td class="text-white-50">-</td>
                <td class="text-white-50">-</td>
                <td class="text-white-50">-</td>
                <td class="text-white-50">-</td>
                <td class="text-white-50">${index === 0 && rows.length === 0 ? 'Aucun historique' : '-'}</td>
            `;
            tbody.appendChild(tr);
            return;
        }

        tr.innerHTML = `
            <td>${item.date || '-'}</td>
            <td>${item.username || '-'}</td>
            <td>${item.profile || '-'}</td>
            <td>${item.mode || '-'}</td>
            <td>${item.operator || '-'}</td>
            <td>${item.effect || '-'}</td>
        `;
        tbody.appendChild(tr);
    });
}

async function loadHistory() {
    const signature = buildHistorySignature();
    if (signature === lastHistorySignature) {
        return;
    }

    const params = new URLSearchParams();
    if (deviceSelect?.value) {
        params.set('device_id', deviceSelect.value);
    }
    if (userSelect?.value) {
        params.set('username', userSelect.value);
    }
    params.set('limit', '4');

    try {
        const data = await fetchJson(`../api/users/recharge_history.php?${params.toString()}`);
        renderHistory(data.items || []);
        lastHistorySignature = signature;
    } catch (error) {
        renderHistory([]);
    }
}

function setSelectOptions(select, items, placeholder) {
    select.innerHTML = '';

    const defaultOption = document.createElement('option');
    defaultOption.value = '';
    defaultOption.textContent = placeholder;
    select.appendChild(defaultOption);

    items.forEach((item) => {
        const option = document.createElement('option');
        option.value = item.value;
        option.textContent = item.label;
        if (item.profile_id !== undefined) {
            option.dataset.profileId = item.profile_id || '';
        }
        if (item.profile_name !== undefined) {
            option.dataset.profileName = item.profile_name || '';
        }
        if (item.current_profile) {
            option.dataset.currentProfile = item.current_profile;
        }
        if (item.current_profile_label) {
            option.dataset.currentProfileLabel = item.current_profile_label;
        }
        select.appendChild(option);
    });

    select.disabled = items.length === 0;
}

function hideUserResults() {
    if (userResultsBox) {
        userResultsBox.style.display = 'none';
        userResultsBox.innerHTML = '';
    }
}

function renderUserResults(query = '') {
    if (!userResultsBox || !userSearchInput) {
        return;
    }

    const term = query.trim().toLowerCase();
    if (term === '') {
        hideUserResults();
        return;
    }

    const matches = rechargeUserItems
        .filter((item) => {
            const haystack = `${item.label || ''} ${item.value || ''} ${item.current_profile_label || ''}`.toLowerCase();
            return haystack.includes(term);
        })
        .slice(0, 20);

    if (matches.length === 0) {
        userResultsBox.innerHTML = '<button type="button" class="list-group-item list-group-item-action disabled">Aucun utilisateur</button>';
        userResultsBox.style.display = 'block';
        return;
    }

    userResultsBox.innerHTML = '';
    matches.forEach((item) => {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'list-group-item list-group-item-action';
        button.textContent = item.label || item.value;
        button.addEventListener('click', async () => {
            userSearchInput.value = item.label || item.value;
            userSelect.value = item.value;
            hideUserResults();
            lastHistorySignature = '';
            syncCurrentProfileFromUserSelection();
            updateProfileLockState();
            schedulePreviewLoad();
            scheduleHistoryLoad();
        });
        userResultsBox.appendChild(button);
    });

    userResultsBox.style.display = 'block';
}

function syncCurrentProfileFromUserSelection() {
    if (!currentProfileSelect) {
        return;
    }

    const selectedOption = userSelect?.selectedOptions?.[0] || null;
    const currentProfile = selectedOption?.dataset?.currentProfile || '';
    const currentProfileLabel = selectedOption?.dataset?.currentProfileLabel || currentProfile;

    currentProfileSelect.innerHTML = '';

    const option = document.createElement('option');
    option.value = currentProfile;
    option.textContent = currentProfileLabel || '-- Profil actuel --';
    currentProfileSelect.appendChild(option);
    currentProfileSelect.value = currentProfile;
    syncProfileHiddenValue();
}

function updateProfileLockState() {
    if (!profileSelect) {
        return;
    }

    const mode = modeSelect?.value || '';
    const currentProfile = currentProfileSelect?.value || '';
    const hasProfileChoices = Array.from(profileSelect.options || []).length > 1;

    if (profileLabel) {
        profileLabel.textContent = 'Nouveau profil';
    }

    if (mode === 'accumulate_offer') {
        if (profileLabel) {
            profileLabel.textContent = 'Champ grise';
        }
        profileSelect.value = currentProfile;
        profileSelect.disabled = true;
        syncProfileHiddenValue();
        return;
    }

    if (mode === 'replace_offer' && profileLabel) {
        profileLabel.textContent = 'A changer en';
    }

    if (mode === 'extend_offer' && profileLabel) {
        profileLabel.textContent = 'Rajout de';
    }

    profileSelect.disabled = !hasProfileChoices;
    syncProfileHiddenValue();
}

function syncProfileHiddenValue() {
    const selectedOption = profileSelect?.selectedOptions?.[0] || null;
    const fallbackCurrentProfile = (currentProfileSelect?.value || '').trim();
    const fallbackCurrentLabel = (currentProfileSelect?.selectedOptions?.[0]?.textContent || fallbackCurrentProfile || '').trim();

    profileIdInput.value = (selectedOption?.dataset?.profileId || '').trim();
    profileNameInput.value = (
        selectedOption?.dataset?.profileName
        || selectedOption?.textContent
        || fallbackCurrentLabel
        || ''
    ).trim();
}

function hasRequiredPreviewInputs() {
    const deviceId = (deviceSelect?.value || '').trim();
    const username = (userSelect?.value || '').trim();
    const mode = (modeSelect?.value || '').trim();
    const profileId = (profileIdInput?.value || '').trim();
    const profileName = (profileNameInput?.value || '').trim();

    return deviceId !== '' && username !== '' && mode !== '' && (profileId !== '' || profileName !== '');
}

function setPreviewValue(id, value) {
    const node = document.getElementById(id);
    if (node) {
        node.textContent = value || '-';
    }
}

function formatExpirationPreview(value, section = 'current') {
    const raw = String(value || '').trim();
    if (raw !== '' && raw !== '-') {
        return raw;
    }

    if (section === 'offer') {
        return 'Non applicable';
    }

    return 'En attente 1er login';
}

async function fetchJson(url, options = {}) {
    const response = await fetch(url, options);
    const text = await response.text();
    let data = null;

    try {
        data = text !== '' ? JSON.parse(text) : null;
    } catch (error) {
        throw new Error(text || 'Reponse JSON invalide');
    }

    if (!data || typeof data !== 'object') {
        throw new Error(text || 'Reponse JSON vide');
    }

    if (!response.ok || !data.success) {
        throw new Error(data.message || text || 'Erreur de chargement');
    }
    return data;
}

async function loadDevices() {
    const response = await fetch('../api/network_devices_api.php');
    const text = await response.text();
    let data = null;

    try {
        data = text !== '' ? JSON.parse(text) : null;
    } catch (error) {
        throw new Error(text || 'Reponse JSON invalide');
    }

    if (!data || typeof data !== 'object') {
        throw new Error(text || 'Reponse JSON vide');
    }

    if (!response.ok) {
        throw new Error(data.message || text || 'Erreur de chargement des serveurs');
    }

    const items = (data.devices || []).map((device) => ({
        value: device.id,
        label: `${device.name} (${device.ip || device.host || device.type})`,
    }));

    setSelectOptions(deviceSelect, items, '-- Choisir un serveur --');

    if (data.active_device_id) {
        deviceSelect.value = data.active_device_id;
    }

    if (deviceSelect.value) {
        await loadUsersAndProfiles(deviceSelect.value);
    }
}

async function loadUsersAndProfiles(deviceId) {
    const [usersData, profilesData] = await Promise.all([
        fetchJson(`../api/users/recharge_options.php?action=users&device_id=${encodeURIComponent(deviceId)}`),
        fetchJson(`../api/users/recharge_options.php?action=profiles&device_id=${encodeURIComponent(deviceId)}`),
    ]);

    rechargeUserItems = usersData.items || [];
    setSelectOptions(userSelect, rechargeUserItems, '-- Choisir un utilisateur --');
    setSelectOptions(profileSelect, profilesData.items || [], '-- Choisir un profil --');

    if (userSearchInput) {
        userSearchInput.disabled = rechargeUserItems.length === 0;
        userSearchInput.value = '';
    }
    hideUserResults();

    if (presetUsername && Array.from(userSelect.options).some((option) => option.value === presetUsername)) {
        userSelect.value = presetUsername;
        if (userSearchInput) {
            const presetItem = rechargeUserItems.find((item) => item.value === presetUsername);
            userSearchInput.value = presetItem?.label || presetUsername;
        }
    }

    syncCurrentProfileFromUserSelection();
    updateProfileLockState();

    if (presetProfileId && Array.from(profileSelect.options).some((option) => option.value === presetProfileId)) {
        profileSelect.value = presetProfileId;
        syncProfileHiddenValue();
        return;
    }

    if (presetProfileName) {
        const presetOption = Array.from(profileSelect.options).find((option) => option.value === presetProfileName || option.textContent.trim() === presetProfileName);
        if (presetOption) {
            profileSelect.value = presetOption.value;
        }
    }

    updateProfileLockState();
    syncProfileHiddenValue();
}

function renderPreview(data) {
    lastPreviewPayload = data;
    hideInlineMessage();
    document.getElementById('rechargePreviewEmpty').classList.add('d-none');
    document.getElementById('rechargePreviewContent').classList.remove('d-none');

    setPreviewValue('currentProfileValue', data.current?.profile);
    setPreviewValue('currentTimeValue', data.current?.time_limit);
    setPreviewValue('currentValidityValue', data.current?.validity);
    setPreviewValue('currentDataValue', formatDataLimitFromMegabytes(resolveDataLimitMegabytes(data.current)));
    setPreviewValue('currentRateValue', data.current?.rate_limit);
    setPreviewValue('currentExpirationValue', formatExpirationPreview(data.current?.expiration, 'current'));

    setPreviewValue('offerProfileValue', data.offer?.profile);
    setPreviewValue('offerTimeValue', data.offer?.time_limit);
    setPreviewValue('offerValidityValue', data.offer?.validity);
    setPreviewValue('offerDataValue', formatDataLimitFromMegabytes(resolveDataLimitMegabytes(data.offer)));
    setPreviewValue('offerRateValue', data.offer?.rate_limit);
    setPreviewValue('offerExpirationValue', formatExpirationPreview(data.offer?.expiration, 'offer'));
    setPreviewValue('projectedProfileValue', data.projected?.profile);
    setPreviewValue('projectedTimeValue', data.projected?.time_limit);
    setPreviewValue('projectedValidityValue', data.projected?.validity);
    setPreviewValue('projectedDataValue', formatDataLimitFromMegabytes(resolveDataLimitMegabytes(data.projected)));
    setPreviewValue('projectedRateValue', data.projected?.rate_limit);
    setPreviewValue('projectedExpirationValue', formatExpirationPreview(data.projected?.expiration, 'projected'));

    const notesBox = document.getElementById('rechargeNotesBox');
    const notes = Array.isArray(data.notes) ? data.notes.filter(Boolean) : [];
    if (notes.length > 0) {
        notesBox.textContent = notes.join(' ');
        notesBox.classList.remove('d-none');
    } else {
        notesBox.textContent = '';
        notesBox.classList.add('d-none');
    }

    if (applyRechargeBtn) {
        const buttonLabel = (data.apply_label || 'Valider la recharge').trim();
        applyRechargeBtn.innerHTML = `<i class="fa fa-check me-1"></i>${buttonLabel}`;
        applyRechargeBtn.dataset.canApplyNow = data.can_apply_now ? '1' : '0';
        applyRechargeBtn.disabled = !data.can_apply_now;
    }
}

deviceSelect?.addEventListener('change', async () => {
    resetPreviewState();
    lastHistorySignature = '';
    if (!deviceSelect.value) {
        setSelectOptions(userSelect, [], '-- Choisir un utilisateur --');
        setSelectOptions(profileSelect, [], '-- Choisir un profil --');
        rechargeUserItems = [];
        if (userSearchInput) {
            userSearchInput.value = '';
            userSearchInput.disabled = true;
        }
        hideUserResults();
        syncCurrentProfileFromUserSelection();
        return;
    }

    try {
        await loadUsersAndProfiles(deviceSelect.value);
        schedulePreviewLoad();
        scheduleHistoryLoad();
    } catch (error) {
        showInlineMessage(error.message || 'Chargement impossible', 'danger');
    }
});

userSelect?.addEventListener('change', async () => {
    resetPreviewState();
    lastHistorySignature = '';
    syncCurrentProfileFromUserSelection();
    updateProfileLockState();
    schedulePreviewLoad();
    scheduleHistoryLoad();
});

userSearchInput?.addEventListener('input', () => {
    renderUserResults(userSearchInput.value);
});

userSearchInput?.addEventListener('focus', () => {
    if (userSearchInput.value.trim() !== '') {
        renderUserResults(userSearchInput.value);
    }
});

document.addEventListener('click', (event) => {
    const target = event.target;
    if (
        target instanceof Node &&
        !userResultsBox?.contains(target) &&
        target !== userSearchInput
    ) {
        hideUserResults();
    }
});

modeSelect?.addEventListener('change', () => {
    resetPreviewState();
    updateProfileLockState();
    syncProfileHiddenValue();
    schedulePreviewLoad();
});

profileSelect?.addEventListener('change', () => {
    resetPreviewState();
    syncProfileHiddenValue();
    schedulePreviewLoad();
});

async function loadPreview() {
    if (isPreviewLoading) {
        return previewLoadSignature === buildPreviewSignature()
            ? (previewLoadPromise || lastPreviewPayload)
            : null;
    }

    if (!hasRequiredPreviewInputs()) {
        resetPreviewState(true);
        return null;
    }

    const signature = buildPreviewSignature();
    if (lastPreviewPayload && signature === lastPreviewSignature) {
        return lastPreviewPayload;
    }

    isPreviewLoading = true;
    previewLoadSignature = signature;
    previewLoadPromise = (async () => {
        try {
            hideInlineMessage();
            const formData = new FormData(rechargeForm);
            const data = await fetchJson('../api/users/recharge_preview.php', {
                method: 'POST',
                body: formData,
            });

            if (signature !== buildPreviewSignature()) {
                return null;
            }

            renderPreview(data);
            lastPreviewSignature = signature;
            return data;
        } catch (error) {
            showInlineMessage(error.message || 'Préparation impossible', 'danger');
            resetPreviewState();
            return null;
        } finally {
            if (previewLoadSignature === signature) {
                isPreviewLoading = false;
                previewLoadPromise = null;
                previewLoadSignature = '';
            }
        }
    })();

    return previewLoadPromise;
}

applyRechargeBtn?.addEventListener('click', async () => {
    if (isApplyInProgress) {
        return;
    }

    try {
        hideInlineMessage();
        const currentSignature = buildPreviewSignature();
        const canApplyFromState = applyRechargeBtn?.dataset?.canApplyNow === '1';
        const previewMatchesCurrentState = lastPreviewPayload && lastPreviewSignature === currentSignature;

        if (!previewMatchesCurrentState || !canApplyFromState) {
            const previewData = await loadPreview();
            if (!previewData?.can_apply_now) {
                return;
            }
        }

        if (!lastPreviewPayload?.can_apply_now) {
            return;
        }

        isApplyInProgress = true;
        applyRechargeBtn.disabled = true;

        const formData = new FormData(rechargeForm);
        const data = await fetchJson('../api/users/apply_recharge.php', {
            method: 'POST',
            body: formData,
        });

        const appliedUsername = (userSelect?.value || '').trim();
        showRechargeToast(data.message || 'Recharge appliquée.', 'success');
        resetPreviewState();
        lastHistorySignature = '';
        if (deviceSelect?.value) {
            await loadUsersAndProfiles(deviceSelect.value);
            if (appliedUsername && Array.from(userSelect.options || []).some((option) => option.value === appliedUsername)) {
                userSelect.value = appliedUsername;
                if (userSearchInput) {
                    const appliedItem = rechargeUserItems.find((item) => item.value === appliedUsername);
                    userSearchInput.value = appliedItem?.label || appliedUsername;
                }
                syncCurrentProfileFromUserSelection();
                updateProfileLockState();
            }
        }
        schedulePreviewLoad(0);
        scheduleHistoryLoad(0);
    } catch (error) {
        const message = error.message || 'Application impossible';
        showRechargeToast(message, 'danger');
    } finally {
        isApplyInProgress = false;
    }
});

loadDevices().then(async () => {
    schedulePreviewLoad(0);
    scheduleHistoryLoad(0);
}).catch((error) => {
    resetPreviewState();
    showInlineMessage(error.message || 'Chargement impossible des serveurs', 'danger');
    console.error(error);
});
