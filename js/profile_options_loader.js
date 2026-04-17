(function () {
    function profileOptionValue(profile) {
        const selectValue = String(profile?.select_value || '').trim();
        if (selectValue !== '') {
            return selectValue;
        }

        const id = Number.parseInt(String(profile?.id ?? '0'), 10);
        if (Number.isFinite(id) && id > 0) {
            return String(id);
        }

        const routerId = String(profile?.router_id || '').trim();
        if (routerId !== '') {
            return routerId;
        }

        return String(profile?.name || '').trim();
    }

    function resetProfileSelect(select, placeholder) {
        if (!select) {
            return;
        }

        select.innerHTML = '';
        const option = document.createElement('option');
        option.value = '';
        option.textContent = placeholder;
        select.appendChild(option);
        select.value = '';
    }

    async function parseJsonResponse(response) {
        const text = await response.text();
        let data = null;

        try {
            data = text !== '' ? JSON.parse(text) : null;
        } catch (error) {
            throw new Error(text || 'Reponse JSON invalide');
        }

        if (!response.ok || !data || data.success !== true || !Array.isArray(data.profiles)) {
            throw new Error(data?.message || text || 'Profils introuvables');
        }

        return data;
    }

    async function loadProfilesForDevice(options) {
        const select = options?.select || null;
        const deviceId = String(options?.deviceId || '').trim();
        const onReset = typeof options?.onReset === 'function' ? options.onReset : null;
        const onOption = typeof options?.onOption === 'function' ? options.onOption : null;
        const onLoaded = typeof options?.onLoaded === 'function' ? options.onLoaded : null;

        if (!select || deviceId === '') {
            resetProfileSelect(select, options?.placeholder || '-- Choisir un profil --');
            if (onReset) {
                onReset();
            }
            return [];
        }

        resetProfileSelect(select, options?.loadingPlaceholder || 'Chargement...');
        if (onReset) {
            onReset();
        }

        const response = await fetch(`../api/users/profile_options.php?device_id=${encodeURIComponent(deviceId)}`, {
            method: 'GET',
            credentials: 'same-origin',
            cache: 'no-store',
        });
        const data = await parseJsonResponse(response);

        resetProfileSelect(select, options?.placeholder || '-- Choisir un profil --');
        if (onReset) {
            onReset();
        }

        data.profiles.forEach((profile) => {
            const option = document.createElement('option');
            option.value = profileOptionValue(profile);
            option.textContent = String(profile.name || 'Profil');
            option.dataset.profileId = String(profile.id ?? '0');
            option.dataset.profileName = String(profile.name || '');
            option.dataset.routerId = String(profile.router_id || '');

            if (onOption) {
                onOption(option, profile, data);
            }

            select.appendChild(option);
        });

        if (onLoaded) {
            onLoaded(data.profiles, data);
        }

        return data.profiles;
    }

    window.ProfileOptionsLoader = {
        loadProfilesForDevice,
        resetProfileSelect,
        profileOptionValue,
    };
})();
