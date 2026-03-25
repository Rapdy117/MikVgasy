const select = document.getElementById('statusSelect');
const badge = document.getElementById('statusPreview');
const form = document.getElementById('userForm');
const nasSelect = document.getElementById('nasSelect');
const rateLimitLabel = document.getElementById('rateLimitLabel');
const rateLimitInput = document.getElementById('rateLimitInput');

let nasList = [];

function updateBadge() {
    if (!select || !badge) return;

    const val = select.value;
    badge.className = 'status-badge';

    if (val === 'active') {
        badge.classList.add('online');
        badge.innerText = 'Actif';
    } else if (val === 'disabled') {
        badge.classList.add('offline');
        badge.innerText = 'Desactive';
    } else {
        badge.classList.add('checking');
        badge.innerText = 'Expire';
    }
}

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
    const selectedNas = nasList.find(nas => String(nas.id) === String(nasSelect.value));
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
            rateLimitLabel.textContent = 'Debit MikroTik';
            rateLimitInput.placeholder = '2M/2M';
        } else {
            rateLimitLabel.textContent = 'Debit';
            rateLimitInput.placeholder = '2M/2M';
        }
    }
}

function loadNasOptions() {
    if (!nasSelect) {
        return Promise.resolve();
    }

    return fetch('../api/nas.php')
        .then(res => res.json())
        .then(data => {
            if (!data.success || !Array.isArray(data.data)) {
                throw new Error(data.message || 'NAS introuvables');
            }

            nasList = data.data;
            nasSelect.innerHTML = '<option value="">-- Choisir un serveur --</option>';

            nasList.forEach(nas => {
                const option = document.createElement('option');
                option.value = nas.id;
                option.textContent = nas.shortname || nas.nasname;
                nasSelect.appendChild(option);
            });

            if (nasList.length > 0) {
                nasSelect.value = String(nasList[0].id);
                applyNasCapabilities();
            }
        })
        .catch(err => {
            console.error('Erreur NAS:', err);
        });
}

if (select) {
    select.addEventListener('change', updateBadge);
    updateBadge();
}

if (nasSelect) {
    nasSelect.addEventListener('change', applyNasCapabilities);
    loadNasOptions();
}

if (form) {
    form.addEventListener('submit', function(e) {
        e.preventDefault();

        const formData = new FormData(form);

        fetch('../api/users/create_user.php', {
            method: 'POST',
            body: formData
        })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                alert('Utilisateur cree avec succes');
                form.reset();

                if (nasList.length > 0 && nasSelect) {
                    nasSelect.value = String(nasList[0].id);
                    applyNasCapabilities();
                }
            } else {
                alert(data.message || 'Creation impossible');
            }
        })
        .catch(err => {
            console.error(err);
            alert('Erreur serveur');
        });
    });
}
