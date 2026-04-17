document.addEventListener('DOMContentLoaded', () => {
    const form = document.getElementById('userForm');
    const nasSelect = document.getElementById('nasSelect');
    const profileSelect = document.getElementById('profileSelect');
    const nasIdInput = document.getElementById('nasIdInput');
    const profileIdInput = document.getElementById('profileIdInput');
    const profileNameInput = document.getElementById('profileNameInput');
    const sessionDaysInput = document.getElementById('sessionDaysInput');
    const sessionHoursInput = document.getElementById('sessionHoursInput');
    const sessionMinutesInput = document.getElementById('sessionMinutesInput');
    const sessionTimeoutInput = document.getElementById('sessionTimeoutInput');
    const dataLimitValueInput = document.getElementById('dataLimitValueInput');
    const dataLimitUnitSelect = document.getElementById('dataLimitUnitSelect');
    const dataLimitInput = document.getElementById('dataLimitInput');
    const profileFields = {
        rateLimit: document.getElementById('profileFieldRateLimit'),
        timeLimit: document.getElementById('profileFieldTimeLimit'),
        dataLimit: document.getElementById('profileFieldDataLimit'),
        validityTime: document.getElementById('profileFieldValidityTime'),
        expiredMode: document.getElementById('profileFieldExpiredMode'),
        sellingPrice: document.getElementById('profileFieldSellingPrice'),
    };
    const activeDeviceBusinessSource = String(document.body?.dataset?.activeDeviceBusinessSource || '').trim().toLowerCase();
    const activeDeviceId = String(document.body?.dataset?.activeDeviceId || '').trim();

    let nasList = [];
    let filteredNasList = [];

    function applyNasTypeLock() {
        if (!nasSelect) {
            return;
        }

        const requiredBusinessSource = activeDeviceBusinessSource === ''
            ? null
            : activeDeviceBusinessSource;
        let candidates = requiredBusinessSource === null
            ? nasList.slice()
            : nasList.filter((nas) => {
                const businessSource = String(nas.business_source || '').trim().toLowerCase();
                return businessSource === requiredBusinessSource;
            });

        if (activeDeviceId) {
            const activeIndex = candidates.findIndex((nas) => String(nas.device_id || '') === activeDeviceId);
            if (activeIndex >= 0) {
                const activeNas = candidates[activeIndex];
                candidates = [activeNas];
            }
        }

        filteredNasList = candidates;

        nasSelect.innerHTML = '<option value="">-- Choisir un serveur --</option>';
        filteredNasList.forEach((nas) => {
            const option = document.createElement('option');
            option.value = String(nas.device_id || '');
            option.textContent = String(nas.label || nas.shortname || nas.nasname || `Cible ${nas.nas_id}`);
            nasSelect.appendChild(option);
        });

        if (filteredNasList.length === 1) {
            nasSelect.value = String(filteredNasList[0].device_id || '');
            nasSelect.disabled = true;
        } else {
            nasSelect.disabled = false;
            if (filteredNasList.length > 0) {
                const activeCandidate = activeDeviceId
                    ? filteredNasList.find((nas) => String(nas.device_id || '') === activeDeviceId)
                    : null;
                nasSelect.value = String((activeCandidate || filteredNasList[0]).device_id || '');
            }
        }
    }

    function toInt(value) {
        const parsed = Number.parseInt(String(value ?? '').trim(), 10);
        return Number.isFinite(parsed) ? parsed : 0;
    }

    function formatSecondsLabel(value) {
        const seconds = toInt(value);
        if (seconds <= 0) {
            return '';
        }

        const days = Math.floor(seconds / 86400);
        const hours = Math.floor((seconds % 86400) / 3600);
        const minutes = Math.floor((seconds % 3600) / 60);
        const parts = [];

        if (days > 0) parts.push(`${days}j`);
        if (hours > 0) parts.push(`${hours}h`);
        if (minutes > 0) parts.push(`${minutes}m`);

        return parts.join(' ');
    }

    function formatDataLimitLabel(value) {
        const megabytes = toInt(value);
        if (megabytes <= 0) {
            return '';
        }

        return megabytes % 1024 === 0
            ? `${megabytes / 1024} GB`
            : `${megabytes} MB`;
    }

    function formatPriceLabel(value) {
        const raw = String(value || '').trim();
        if (raw === '') {
            return '';
        }

        return /^[0-9]+([.,][0-9]+)?$/.test(raw) ? `${raw} Ar` : raw;
    }

    function updateProfileInheritedFields(selectedOption) {
        const setField = (field, value) => {
            if (field) {
                field.value = String(value || '').trim();
            }
        };

        if (!selectedOption) {
            Object.values(profileFields).forEach((field) => setField(field, ''));
            return;
        }

        setField(profileFields.rateLimit, selectedOption.dataset.profileRateLimit || '');
        setField(profileFields.timeLimit, formatSecondsLabel(selectedOption.dataset.profileSessionTimeout || ''));
        setField(profileFields.dataLimit, formatDataLimitLabel(selectedOption.dataset.profileDataQuotaMb || ''));
        setField(profileFields.validityTime, formatSecondsLabel(selectedOption.dataset.profileValidityTime || ''));
        setField(profileFields.expiredMode, selectedOption.dataset.profileExpiredMode || '');
        setField(profileFields.sellingPrice, formatPriceLabel(selectedOption.dataset.profileSellingPrice || ''));
    }

    function clearInheritedLimitFields() {
        [sessionDaysInput, sessionHoursInput, sessionMinutesInput, dataLimitValueInput]
            .filter(Boolean)
            .forEach((input) => {
                input.value = '';
            });

        if (dataLimitUnitSelect) {
            dataLimitUnitSelect.value = 'MB';
        }
    }

    function applySelectedProfileInheritedLimits() {
        const profileOption = profileSelect?.selectedOptions?.[0] ?? null;
        const inheritedSessionTimeout = profileOption ? toInt(profileOption.dataset.profileSessionTimeout) : 0;
        const inheritedDataLimitMb = profileOption ? toInt(profileOption.dataset.profileDataQuotaMb) : 0;

        updateProfileInheritedFields(profileOption);

        if (sessionDaysInput && sessionHoursInput && sessionMinutesInput) {
            if (inheritedSessionTimeout > 0) {
                const days = Math.floor(inheritedSessionTimeout / 86400);
                const hours = Math.floor((inheritedSessionTimeout % 86400) / 3600);
                const minutes = Math.floor((inheritedSessionTimeout % 3600) / 60);

                sessionDaysInput.value = String(days);
                sessionHoursInput.value = String(hours);
                sessionMinutesInput.value = String(minutes);
            } else {
                sessionDaysInput.value = '';
                sessionHoursInput.value = '';
                sessionMinutesInput.value = '';
            }
        }

        if (dataLimitValueInput && dataLimitUnitSelect) {
            if (inheritedDataLimitMb > 0) {
                if (inheritedDataLimitMb % 1024 === 0) {
                    dataLimitValueInput.value = String(inheritedDataLimitMb / 1024);
                    dataLimitUnitSelect.value = 'GB';
                } else {
                    dataLimitValueInput.value = String(inheritedDataLimitMb);
                    dataLimitUnitSelect.value = 'MB';
                }
            } else {
                dataLimitValueInput.value = '';
                dataLimitUnitSelect.value = 'MB';
            }
        }

        syncComputedLimits();
    }

    function setCapabilityFieldState(wrapper, enabled) {
        if (!wrapper) {
            return;
        }

        wrapper.classList.toggle('d-none', !enabled);

        wrapper.querySelectorAll('input, select, textarea').forEach((field) => {
            if (!field.name) {
                return;
            }

            field.disabled = !enabled;
            if (!enabled) {
                field.value = '';
            }
        });
    }

    function applyNasCapabilities() {
        if (!nasSelect) {
            return;
        }

        const selectedNas = (filteredNasList.length > 0 ? filteredNasList : nasList)
            .find((nas) => String(nas.device_id || '') === String(nasSelect.value));
        const capabilities = Array.isArray(selectedNas?.capabilities) ? selectedNas.capabilities : [];

        document.querySelectorAll('[data-capability]').forEach((wrapper) => {
            const capability = String(wrapper.dataset.capability || '');
            setCapabilityFieldState(wrapper, capabilities.includes(capability));
        });
    }

    function syncSelectedNasHiddenFields() {
        if (!nasSelect || !nasIdInput) {
            return '';
        }

        const selectedNas = (filteredNasList.length > 0 ? filteredNasList : nasList)
            .find((nas) => String(nas.device_id || '') === String(nasSelect.value));
        nasIdInput.value = selectedNas ? String(selectedNas.nas_id || '') : '';
        return selectedNas ? String(selectedNas.device_id || '') : '';
    }

    function syncSelectedProfileHiddenFields() {
        if (!profileSelect || !profileIdInput || !profileNameInput) {
            return;
        }

        const selectedOption = profileSelect.selectedOptions[0] || null;
        profileIdInput.value = selectedOption ? String(selectedOption.dataset.profileId || '0').trim() : '';
        profileNameInput.value = selectedOption
            ? String(selectedOption.dataset.profileName || selectedOption.textContent || '').trim()
            : '';
    }

    function resetProfiles(placeholderText) {
        if (!profileSelect) {
            return;
        }

        profileSelect.innerHTML = '';
        const option = document.createElement('option');
        option.value = '';
        option.textContent = placeholderText;
        profileSelect.appendChild(option);
        profileSelect.value = '';
        syncSelectedProfileHiddenFields();
        clearInheritedLimitFields();
        updateProfileInheritedFields(null);
        syncComputedLimits();
    }

    async function loadProfilesForNas() {
        if (!profileSelect || !nasSelect) {
            return;
        }

        const sourceList = filteredNasList.length > 0 ? filteredNasList : nasList;
        const selectedNas = sourceList.find((nas) => String(nas.device_id || '') === String(nasSelect.value));
        if (!selectedNas) {
            resetProfiles('-- Choisir un profil --');
            return;
        }

        const deviceId = String(selectedNas.device_id || '').trim();
        if (deviceId === '') {
            resetProfiles('-- Device inconnu --');
            return;
        }

        if (!window.ProfileOptionsLoader) {
            resetProfiles('-- Indisponible --');
            return;
        }

        try {
            await window.ProfileOptionsLoader.loadProfilesForDevice({
                deviceId,
                select: profileSelect,
                placeholder: '-- Choisir un profil --',
                loadingPlaceholder: 'Chargement...',
                onReset: syncSelectedProfileHiddenFields,
                onOption(option, profile) {
                    option.dataset.profileSessionTimeout = String(profile.session_timeout ?? '');
                    option.dataset.profileValidityTime = String(profile.validity_time ?? '');
                    option.dataset.profileDataQuotaMb = String(profile.data_quota_mb ?? '');
                    option.dataset.profileRateLimit = String(profile.rate_limit ?? '');
                    option.dataset.profileSimultaneousUse = String(profile.simultaneous_use ?? '');
                    option.dataset.profileExpiredMode = String(profile.expired_mode ?? '');
                    option.dataset.profilePrice = String(profile.price ?? '');
                    option.dataset.profileSellingPrice = String(profile.selling_price ?? '');
                    option.dataset.profileIpPool = String(profile.ip_pool ?? '');
                },
            });

            if (profileSelect.options.length > 1) {
                profileSelect.selectedIndex = 1;
            }
            syncSelectedProfileHiddenFields();
            applySelectedProfileInheritedLimits();
        } catch (error) {
            console.error('Erreur chargement profils:', error);
            resetProfiles('-- Indisponible --');
        }
    }

    function syncComputedLimits() {
        const profileOption = profileSelect?.selectedOptions?.[0] ?? null;
        const inheritedSessionTimeout = profileOption ? toInt(profileOption.dataset.profileSessionTimeout) : 0;
        const inheritedDataLimitMb = profileOption ? toInt(profileOption.dataset.profileDataQuotaMb) : 0;

        if (sessionTimeoutInput) {
            const daysRaw = String(sessionDaysInput?.value ?? '').trim();
            const hoursRaw = String(sessionHoursInput?.value ?? '').trim();
            const minutesRaw = String(sessionMinutesInput?.value ?? '').trim();
            const isEmpty = daysRaw === '' && hoursRaw === '' && minutesRaw === '';

            if (isEmpty) {
                sessionTimeoutInput.value = inheritedSessionTimeout > 0 ? String(inheritedSessionTimeout) : '';
            } else {
                const days = Math.max(0, toInt(daysRaw));
                const hours = Math.max(0, toInt(hoursRaw));
                const minutes = Math.max(0, toInt(minutesRaw));
                sessionTimeoutInput.value = String((days * 86400) + (hours * 3600) + (minutes * 60));
            }
        }

        if (dataLimitInput) {
            const rawInput = String(dataLimitValueInput?.value ?? '').trim();
            if (rawInput === '') {
                dataLimitInput.value = inheritedDataLimitMb > 0 ? String(inheritedDataLimitMb) : '';
                return;
            }

            const rawValue = Number.parseFloat(rawInput.replace(',', '.'));
            const value = Number.isFinite(rawValue) ? Math.max(0, rawValue) : 0;
            const unit = String(dataLimitUnitSelect?.value || 'MB').toUpperCase();

            let valueInMb = value;
            if (unit === 'GB') {
                valueInMb = value * 1024;
            } else if (unit === 'KB') {
                valueInMb = value / 1024;
            }

            dataLimitInput.value = String(Math.max(0, Math.round(valueInMb)));
        }
    }

    async function loadNasOptions() {
        if (!nasSelect) {
            return;
        }

        try {
            const response = await fetch('../api/nas.php', {
                method: 'GET',
                credentials: 'same-origin',
                cache: 'no-store',
            });
            const data = await response.json();

            if (!data.success || !Array.isArray(data.data)) {
                throw new Error(data.message || 'NAS introuvables');
            }

            nasList = data.data;
            applyNasTypeLock();

            syncSelectedNasHiddenFields();
            applyNasCapabilities();
            await loadProfilesForNas();
        } catch (error) {
            console.error('Erreur NAS:', error);
        }
    }

    if (profileSelect) {
        profileSelect.addEventListener('change', () => {
            syncSelectedProfileHiddenFields();
            applySelectedProfileInheritedLimits();
        });
    }

    if (nasSelect) {
        nasSelect.addEventListener('change', async () => {
            syncSelectedNasHiddenFields();
            applyNasCapabilities();
            await loadProfilesForNas();
        });
    }

    [sessionDaysInput, sessionHoursInput, sessionMinutesInput, dataLimitValueInput, dataLimitUnitSelect]
        .filter(Boolean)
        .forEach((input) => {
            input.addEventListener('input', syncComputedLimits);
            input.addEventListener('change', syncComputedLimits);
        });

    if (form) {
        form.addEventListener('submit', async (event) => {
            event.preventDefault();
            const resolvedDeviceId = syncSelectedNasHiddenFields();
            syncSelectedProfileHiddenFields();
            syncComputedLimits();

            const formData = new FormData(form);
            if (resolvedDeviceId) {
                formData.set('device_id', resolvedDeviceId);
            }

            try {
                const response = await fetch('../api/users/create_user.php', {
                    method: 'POST',
                    body: formData,
                    credentials: 'same-origin',
                });
                const data = await response.json();

                if (!data.success) {
                    alert(data.message || 'Creation impossible');
                    return;
                }

                alert('Utilisateur cree avec succes');
                form.reset();
                await loadNasOptions();
                syncComputedLimits();
            } catch (error) {
                console.error(error);
                alert('Erreur serveur');
            }
        });
    }

    loadNasOptions().then(() => {
        syncComputedLimits();
    });
});
