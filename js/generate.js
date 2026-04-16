document.addEventListener('DOMContentLoaded', function () {
    const STORAGE_KEY_SSID = 'generate_ticket_ssid_v1';
    const STORAGE_KEY_DNS = 'generate_ticket_dns_v1';
    const STORAGE_KEY_LOGO_URL = 'generate_ticket_logo_url_v1';
    const STORAGE_KEY_LOGO_NAME = 'generate_ticket_logo_name_v1';
    const LOGO_MAX_WIDTH = 520;
    const LOGO_MAX_HEIGHT = 220;
    const LOGO_JPEG_QUALITY = 0.82;

    const deviceSelect = document.querySelector('select[name="device_id"]');
    const profileSelect = document.getElementById('profileSelect');
    const profileNameInput = document.getElementById('profileNameInput');
    const ssidInput = document.getElementById('ssidInput');
    const dnsInput = document.getElementById('dnsInput');
    const generateForm = document.getElementById('generateForm');
    const generateFieldset = document.getElementById('generateFieldset');
    const csrfTokenInput = generateForm ? generateForm.querySelector('input[name="csrf_token"]') : null;
    const previewCardBody = document.querySelector('.generate-card-ticket-preview .card-body');
    const applyBtn = document.getElementById('applyAndPrintBtn');
    const prepareBtn = document.getElementById('prepareBtn');
    const cancelPendingBtn = document.getElementById('cancelPendingBtn');
    const printDisabledBtn = document.getElementById('printDisabledBtn');
    const ticketFormatSelect = document.getElementById('ticketFormatSelect');
    const ticketQrSelect = document.getElementById('ticketQrSelect');
    const ticketLogoSelect = document.getElementById('ticketLogoSelect');
    const showQrCheckbox = document.getElementById('showQrCheckbox');
    const showLogoCheckbox = document.getElementById('showLogoCheckbox');
    const logoFileInput = document.getElementById('logoFileInput');
    const logoUploadBtn = document.getElementById('logoUploadBtn');
    const logoEditBtn = document.getElementById('logoEditBtn');
    const logoUrlInput = document.getElementById('logoUrlInput');
    const logoFileMeta = document.getElementById('logoFileMeta');
    const logoUploaderBlock = document.querySelector('.generate-logo-uploader');
    const profileFields = {
        rateLimit: document.getElementById('profileFieldRateLimit'),
        timeLimit: document.getElementById('profileFieldTimeLimit'),
        dataLimit: document.getElementById('profileFieldDataLimit'),
        validityTime: document.getElementById('profileFieldValidityTime'),
        expiredMode: document.getElementById('profileFieldExpiredMode'),
        price: document.getElementById('profileFieldPrice'),
        sellingPrice: document.getElementById('profileFieldSellingPrice')
    };

    function initializeFlashMessage() {
        const flash = document.getElementById('messageArea');
        if (!flash) {
            return;
        }

        window.setTimeout(() => {
            flash.classList.add('generate-flash-hide');
        }, 3400);

        window.setTimeout(() => {
            if (flash && flash.parentNode) {
                flash.parentNode.removeChild(flash);
            }
        }, 3900);
    }

    function showFlashMessage(message, type) {
        const existing = document.getElementById('messageArea');
        if (existing && existing.parentNode) {
            existing.parentNode.removeChild(existing);
        }

        const flash = document.createElement('div');
        flash.id = 'messageArea';
        flash.className = `alert alert-${type || 'info'}`;
        flash.setAttribute('role', 'alert');
        flash.textContent = String(message || '').trim();
        document.body.appendChild(flash);
        initializeFlashMessage();
    }

    function updatePreviewBlock(html) {
        if (!previewCardBody || String(html || '').trim() === '') {
            return;
        }
        previewCardBody.innerHTML = String(html);
        renderTicketQRCodes(previewCardBody);
    }

    function formatSecondsLabel(value) {
        const seconds = Number.parseInt(String(value || '0'), 10);
        if (!Number.isFinite(seconds) || seconds <= 0) {
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

    function formatPriceLabel(value) {
        const raw = String(value || '').trim();
        if (raw === '') {
            return '';
        }

        return /^[0-9]+([.,][0-9]+)?$/.test(raw) ? `${raw} Ar` : raw;
    }

    function updateProfileFields(selectedOption) {
        const safeValue = (value) => String(value || '').trim();
        const setField = (field, value) => {
            if (field) {
                field.value = safeValue(value);
            }
        };

        if (!selectedOption) {
            Object.values(profileFields).forEach((field) => {
                if (field) {
                    field.value = '';
                }
            });
            return;
        }

        setField(profileFields.rateLimit, selectedOption.dataset.rateLimit || '');
        setField(profileFields.timeLimit, formatSecondsLabel(selectedOption.dataset.sessionTimeout || ''));
        setField(profileFields.dataLimit, selectedOption.dataset.dataQuota || '');
        setField(profileFields.validityTime, formatSecondsLabel(selectedOption.dataset.validity || ''));
        setField(profileFields.expiredMode, selectedOption.dataset.expiredMode || '');
        setField(profileFields.price, formatPriceLabel(selectedOption.dataset.price || ''));
        setField(profileFields.sellingPrice, formatPriceLabel(selectedOption.dataset.sellingPrice || ''));
    }

    function renderTicketQRCodes(scope) {
        if (typeof QRCode === 'undefined') {
            return;
        }

        const root = scope || document;
        root.querySelectorAll('.voucher-qr[data-qr-text]').forEach((node) => {
            const payload = String(node.getAttribute('data-qr-text') || '').trim();
            if (payload === '') {
                return;
            }

            node.innerHTML = '';
            new QRCode(node, {
                text: payload,
                width: 80,
                height: 80
            });
        });
    }

    function syncSelectedProfileName() {
        if (!profileSelect || !profileNameInput) {
            return;
        }

        const selectedOption = profileSelect.selectedOptions[0] || null;
        profileNameInput.value = selectedOption ? String(selectedOption.dataset.profileName || selectedOption.textContent || '').trim() : '';
        updateProfileFields(selectedOption);
    }

    function syncTicketBooleanControls() {
        if (ticketQrSelect && showQrCheckbox) {
            showQrCheckbox.checked = ticketQrSelect.value === 'on';
        }
        if (ticketLogoSelect && showLogoCheckbox) {
            showLogoCheckbox.checked = ticketLogoSelect.value === 'on';
        }
    }

    function updateLogoMetaLabel(text) {
        if (logoFileMeta) {
            logoFileMeta.textContent = String(text || '').trim() || 'Aucun logo sélectionné';
        }
    }

    function persistTicketDraft() {
        try {
            if (ssidInput) {
                const ssidValue = String(ssidInput.value || '').trim();
                if (ssidValue === '') {
                    localStorage.removeItem(STORAGE_KEY_SSID);
                } else {
                    localStorage.setItem(STORAGE_KEY_SSID, ssidValue);
                }
            }

            if (dnsInput) {
                const dnsValue = String(dnsInput.value || '').trim();
                if (dnsValue === '') {
                    localStorage.removeItem(STORAGE_KEY_DNS);
                } else {
                    localStorage.setItem(STORAGE_KEY_DNS, dnsValue);
                }
            }

            if (logoUrlInput) {
                const logoValue = String(logoUrlInput.value || '').trim();
                if (logoValue === '') {
                    localStorage.removeItem(STORAGE_KEY_LOGO_URL);
                    localStorage.removeItem(STORAGE_KEY_LOGO_NAME);
                } else if (logoValue.length < 1400000) {
                    localStorage.setItem(STORAGE_KEY_LOGO_URL, logoValue);
                }
            }
        } catch (error) {
            // ignore storage quota / private mode issues
        }
    }

    function restoreTicketDraft() {
        try {
            if (ssidInput && String(ssidInput.value || '').trim() === '') {
                const savedSsid = String(localStorage.getItem(STORAGE_KEY_SSID) || '').trim();
                if (savedSsid !== '') {
                    ssidInput.value = savedSsid;
                }
            }

            if (dnsInput && String(dnsInput.value || '').trim() === '') {
                const savedDns = String(localStorage.getItem(STORAGE_KEY_DNS) || '').trim();
                if (savedDns !== '') {
                    dnsInput.value = savedDns;
                }
            }

            if (logoUrlInput && String(logoUrlInput.value || '').trim() === '') {
                const savedLogo = String(localStorage.getItem(STORAGE_KEY_LOGO_URL) || '').trim();
                if (savedLogo !== '') {
                    logoUrlInput.value = savedLogo;
                    const savedLogoName = String(localStorage.getItem(STORAGE_KEY_LOGO_NAME) || '').trim();
                    updateLogoMetaLabel(savedLogoName !== '' ? savedLogoName : 'Logo prêt');
                }
            }
        } catch (error) {
            // ignore storage issues
        }
    }

    function compressLogoDataUrl(dataUrl) {
        return new Promise((resolve) => {
            const source = String(dataUrl || '').trim();
            if (source === '') {
                resolve('');
                return;
            }

            const mimeMatch = source.match(/^data:(image\/[a-zA-Z0-9.+-]+);base64,/);
            const mime = mimeMatch ? String(mimeMatch[1] || '').toLowerCase() : '';
            if (mime === 'image/svg+xml') {
                resolve(source);
                return;
            }

            const img = new Image();
            img.onload = function () {
                const srcW = Math.max(1, Number(img.naturalWidth || img.width || 1));
                const srcH = Math.max(1, Number(img.naturalHeight || img.height || 1));
                const ratio = Math.min(LOGO_MAX_WIDTH / srcW, LOGO_MAX_HEIGHT / srcH, 1);
                const outW = Math.max(1, Math.round(srcW * ratio));
                const outH = Math.max(1, Math.round(srcH * ratio));

                const canvas = document.createElement('canvas');
                canvas.width = outW;
                canvas.height = outH;

                const ctx = canvas.getContext('2d');
                if (!ctx) {
                    resolve(source);
                    return;
                }

                ctx.clearRect(0, 0, outW, outH);
                ctx.drawImage(img, 0, 0, outW, outH);

                let compressed = '';
                try {
                    compressed = canvas.toDataURL('image/jpeg', LOGO_JPEG_QUALITY);
                } catch (error) {
                    compressed = '';
                }

                if (compressed && compressed.length > 0 && compressed.length < source.length) {
                    resolve(compressed);
                    return;
                }

                try {
                    const pngOutput = canvas.toDataURL('image/png');
                    resolve(pngOutput && pngOutput.length < source.length ? pngOutput : source);
                } catch (error) {
                    resolve(source);
                }
            };
            img.onerror = function () {
                resolve(source);
            };
            img.src = source;
        });
    }

    function openLogoPicker() {
        if (logoFileInput) {
            logoFileInput.click();
        }
    }

    function initializeTicketControls() {
        if (ticketQrSelect && showQrCheckbox) {
            ticketQrSelect.value = showQrCheckbox.checked ? 'on' : 'off';
        }
        if (ticketLogoSelect && showLogoCheckbox) {
            ticketLogoSelect.value = showLogoCheckbox.checked ? 'on' : 'off';
        }

        syncTicketBooleanControls();

        const syncFormatDependentControls = () => {
            const hasLogo = String(logoUrlInput ? logoUrlInput.value || '' : '').trim() !== '';
            const logoEnabled = ticketLogoSelect ? ticketLogoSelect.value === 'on' : false;

            if (ticketLogoSelect) {
                ticketLogoSelect.disabled = false;
            }
            if (logoUploadBtn) {
                logoUploadBtn.disabled = !logoEnabled;
            }
            if (logoEditBtn) {
                logoEditBtn.disabled = !logoEnabled || !hasLogo;
            }
            if (logoUploaderBlock) {
                logoUploaderBlock.classList.toggle('generate-logo-uploader-disabled', !logoEnabled);
            }
        };

        if (ticketQrSelect) {
            ticketQrSelect.addEventListener('change', syncTicketBooleanControls);
        }
        if (ticketLogoSelect) {
            ticketLogoSelect.addEventListener('change', function () {
                syncTicketBooleanControls();
                syncFormatDependentControls();
            });
        }
        if (ticketFormatSelect) {
            ticketFormatSelect.addEventListener('change', function () {
                syncFormatDependentControls();
                syncTicketBooleanControls();
            });
        }

        if (logoUploadBtn) {
            logoUploadBtn.addEventListener('click', openLogoPicker);
        }
        if (logoEditBtn) {
            logoEditBtn.addEventListener('click', openLogoPicker);
        }

        if (logoFileInput) {
            logoFileInput.addEventListener('change', function () {
                const file = logoFileInput.files && logoFileInput.files[0] ? logoFileInput.files[0] : null;
                if (!file) {
                    return;
                }

                const reader = new FileReader();
                reader.onload = async function (event) {
                    const rawDataUrl = String(event.target && event.target.result ? event.target.result : '');
                    const optimizedLogo = await compressLogoDataUrl(rawDataUrl);
                    if (logoUrlInput) {
                        logoUrlInput.value = optimizedLogo;
                    }
                    if (ticketLogoSelect) {
                        ticketLogoSelect.value = 'on';
                    }
                    syncTicketBooleanControls();
                    if (logoEditBtn) {
                        logoEditBtn.disabled = false;
                    }
                    updateLogoMetaLabel(file.name);
                    try {
                        localStorage.setItem(STORAGE_KEY_LOGO_NAME, String(file.name || '').trim());
                    } catch (error) {
                        // ignore storage issues
                    }
                    persistTicketDraft();
                    syncFormatDependentControls();
                };
                reader.readAsDataURL(file);
            });
        }

        if (logoUrlInput && String(logoUrlInput.value || '').trim() !== '') {
            if (logoEditBtn) {
                logoEditBtn.disabled = false;
            }
            updateLogoMetaLabel('Logo prêt');
        }

        syncFormatDependentControls();
    }

    function setPendingMode(isPending) {
        if (generateFieldset) {
            if (isPending) {
                generateFieldset.setAttribute('disabled', 'disabled');
            } else {
                generateFieldset.removeAttribute('disabled');
            }
        }

        if (prepareBtn) {
            prepareBtn.classList.toggle('d-none', isPending);
        }
        if (cancelPendingBtn) {
            cancelPendingBtn.classList.toggle('d-none', !isPending);
        }
        if (applyBtn) {
            applyBtn.classList.toggle('d-none', !isPending);
        }
        if (printDisabledBtn) {
            printDisabledBtn.classList.toggle('d-none', isPending);
        }
        if (generateForm) {
            generateForm.setAttribute('data-has-pending', isPending ? '1' : '0');
        }
    }

    function initializePendingControls() {
        const hasPending = generateForm && generateForm.getAttribute('data-has-pending') === '1';
        setPendingMode(!!hasPending);

        if (cancelPendingBtn) {
            cancelPendingBtn.addEventListener('click', function () {
                const csrfToken = csrfTokenInput ? String(csrfTokenInput.value || '').trim() : '';
                if (csrfToken === '') {
                    showFlashMessage('CSRF invalide', 'danger');
                    return;
                }

                const payload = new FormData();
                payload.append('csrf_token', csrfToken);

                fetch('../api/vouchers/cancel_batch.php', {
                    method: 'POST',
                    body: payload
                })
                    .then((res) => res.json())
                    .then((data) => {
                        if (!data.success) {
                            throw new Error(data.message || 'Annulation impossible');
                        }

                        setPendingMode(false);
                        updatePreviewBlock(data.preview_block_html || '');
                        showFlashMessage(data.message || 'Préparation annulée.', 'success');
                    })
                    .catch((error) => {
                        showFlashMessage(error.message || 'Annulation impossible', 'danger');
                    });
            });
        }
    }

    function resetProfiles(placeholder) {
        if (!profileSelect) {
            return;
        }

        profileSelect.innerHTML = '';
        const option = document.createElement('option');
        option.value = '';
        option.textContent = placeholder;
        profileSelect.appendChild(option);
        profileSelect.value = '';
        syncSelectedProfileName();
    }

    function loadProfiles() {
        if (!deviceSelect || !profileSelect) {
            return;
        }

        const deviceId = String(deviceSelect.value || '').trim();
        if (deviceId === '') {
            resetProfiles('-- Choisir un profil --');
            return;
        }

        resetProfiles('Chargement...');

        fetch(`../api/users/profile_options.php?device_id=${encodeURIComponent(deviceId)}`)
            .then((response) => response.json())
            .then((data) => {
                if (!data.success || !Array.isArray(data.profiles)) {
                    throw new Error(data.message || 'Profils introuvables');
                }

                resetProfiles('-- Choisir un profil --');

                data.profiles.forEach((profile) => {
                    const option = document.createElement('option');
                    option.value = String(profile.id || '');
                    option.textContent = String(profile.name || 'Profil');
                    option.dataset.profileName = String(profile.name || '');

                    option.dataset.rateLimit = profile.rate_limit || '';
                    option.dataset.price = profile.price != null && profile.price !== '' ? String(profile.price) : '';
                    option.dataset.sellingPrice = profile.selling_price != null && profile.selling_price !== '' ? String(profile.selling_price) : '';
                    option.dataset.dataQuota = profile.data_quota_mb ? profile.data_quota_mb + ' MB' : '';
                    option.dataset.validity = profile.validity_time || '';
                    option.dataset.sessionTimeout = profile.session_timeout || '';
                    option.dataset.expiredMode = profile.expired_mode || '';

                    profileSelect.appendChild(option);
                });

                syncSelectedProfileName();
            })
            .catch((error) => {
                console.error('Erreur chargement profils voucher:', error);
                resetProfiles('-- Indisponible --');
            });
    }

    if (deviceSelect) {
        deviceSelect.addEventListener('change', loadProfiles);
    }

    if (profileSelect) {
        profileSelect.addEventListener('change', syncSelectedProfileName);
    }

    if (generateForm) {
        generateForm.addEventListener('submit', function (event) {
            const isPending = generateForm.getAttribute('data-has-pending') === '1';
            if (isPending) {
                return;
            }

            event.preventDefault();

            const payload = new FormData(generateForm);
            payload.set('action', 'prepare');

            if (prepareBtn) {
                prepareBtn.disabled = true;
                prepareBtn.innerHTML = '<i class="fa fa-spinner fa-spin me-1"></i> Préparation...';
            }

            fetch('../api/vouchers/prepare_batch.php', {
                method: 'POST',
                body: payload
            })
                .then((res) => res.json())
                .then((data) => {
                    if (!data.success) {
                        throw new Error(data.message || 'Préparation impossible');
                    }

                    setPendingMode(true);
                    updatePreviewBlock(data.preview_block_html || '');
                    showFlashMessage(data.message || 'Lot préparé.', 'success');
                })
                .catch((error) => {
                    showFlashMessage(error.message || 'Préparation impossible', 'danger');
                })
                .finally(() => {
                    if (prepareBtn) {
                        prepareBtn.disabled = false;
                        prepareBtn.innerHTML = '<i class="fa fa-eye me-1"></i> Préparer';
                    }
                });
        });
    }

    syncSelectedProfileName();
    restoreTicketDraft();
    if (ssidInput) {
        ssidInput.addEventListener('input', persistTicketDraft);
        ssidInput.addEventListener('change', persistTicketDraft);
    }
    if (dnsInput) {
        dnsInput.addEventListener('input', persistTicketDraft);
        dnsInput.addEventListener('change', persistTicketDraft);
    }
    loadProfiles();
    initializeTicketControls();
    initializePendingControls();
    initializeFlashMessage();
    renderTicketQRCodes(document);

    if (applyBtn) {
        applyBtn.addEventListener('click', function () {
            applyBtn.disabled = true;
            applyBtn.textContent = 'Traitement...';
            const csrfToken = csrfTokenInput ? String(csrfTokenInput.value || '').trim() : '';
            const payload = new FormData();
            payload.append('csrf_token', csrfToken);

            fetch('../api/vouchers/apply_batch.php', {
                method: 'POST',
                body: payload
            })
                .then((res) => res.json())
                .then((data) => {
                    if (!data.success) {
                        throw new Error(data.message || 'Erreur application');
                    }

                    window.open('/pages/print_vouchers.php', '_blank', 'noopener');
                    setPendingMode(false);
                    showFlashMessage('Lot appliqué. Impression ouverte dans un autre onglet.', 'success');
                })
                .catch((err) => {
                    alert(err.message);
                    applyBtn.disabled = false;
                    applyBtn.innerHTML = '<i class="fa fa-print me-1"></i> Appliquer &amp; Imprimer';
                });
        });
    }
});
