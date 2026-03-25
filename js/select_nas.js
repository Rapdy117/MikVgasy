document.addEventListener("DOMContentLoaded", function () {
    const select = document.getElementById('nasSelect');
    const rateLimitLabel = document.getElementById('profileRateLimitLabel');
    const rateLimitInput = document.getElementById('profileRateLimitInput');
    const form = document.getElementById('profileForm');

    let nasList = [];

    function setCapabilityFieldState(wrapper, enabled) {
        if (!wrapper) {
            return;
        }

        wrapper.classList.toggle('d-none', !enabled);

        wrapper.querySelectorAll('input, select, textarea').forEach(field => {
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
        const selectedNas = nasList.find(nas => String(nas.id) === String(select.value));
        const capabilities = selectedNas?.capabilities ?? [];
        const nasType = selectedNas?.type ?? 'other';

        document.querySelectorAll('[data-capability]').forEach(wrapper => {
            const capability = wrapper.dataset.capability;
            setCapabilityFieldState(wrapper, capabilities.includes(capability));
        });

        const rateGroup = document.querySelector('[data-rate-limit-group]');
        const supportsRateLimit = capabilities.includes('Mikrotik-Rate-Limit')
            || (capabilities.includes('WISPr-Bandwidth-Max-Down') && capabilities.includes('WISPr-Bandwidth-Max-Up'));

        setCapabilityFieldState(rateGroup, supportsRateLimit);

        if (rateLimitLabel && rateLimitInput) {
            if (nasType === 'mikrotik') {
                rateLimitLabel.textContent = 'Rate MikroTik';
                rateLimitInput.placeholder = '2M/2M';
            } else {
                rateLimitLabel.textContent = 'Rate';
                rateLimitInput.placeholder = '2M/2M';
            }
        }
    }

    if (!select) {
        return;
    }

    fetch('../api/nas.php')
        .then(res => res.json())
        .then(response => {
            if (!response.success || !Array.isArray(response.data)) {
                throw new Error(response.message || 'NAS introuvables');
            }

            nasList = response.data;
            select.innerHTML = '<option value="">-- Choisir un serveur --</option>';

            nasList.forEach(nas => {
                const option = document.createElement('option');
                option.value = nas.id;
                option.textContent = nas.shortname || nas.nasname;
                select.appendChild(option);
            });

            if (nasList.length > 0) {
                select.value = String(nasList[0].id);
                applyNasCapabilities();
            }
        })
        .catch(err => {
            console.error("Erreur NAS:", err);
        });

    select.addEventListener('change', applyNasCapabilities);

    if (form) {
        form.addEventListener('submit', function (e) {
            e.preventDefault();

            const formData = new FormData(form);

            fetch('../api/profiles/create_profile.php', {
                method: 'POST',
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    alert(data.message || 'Profil cree');
                    form.reset();

                    if (nasList.length > 0) {
                        select.value = String(nasList[0].id);
                        applyNasCapabilities();
                    }
                } else {
                    alert(data.message || 'Creation impossible');
                }
            })
            .catch(err => {
                console.error('Erreur profil:', err);
                alert('Erreur serveur');
            });
        });
    }
});
