const administrationCsrfToken = document.body.dataset.administrationCsrfToken || '';

function resetAdminForm() {
    document.getElementById('adminActionInput').value = 'create';
    document.getElementById('adminIdInput').value = '0';
    document.getElementById('adminUsernameInput').value = '';
    document.getElementById('adminPasswordInput').value = '';
    document.getElementById('adminStatusInput').value = '1';
    document.getElementById('adminRoleInput').value = 'administrator';
}

function fillAdminForm(id, username, isActive, role) {
    document.getElementById('adminActionInput').value = 'update';
    document.getElementById('adminIdInput').value = id;
    document.getElementById('adminUsernameInput').value = username;
    document.getElementById('adminPasswordInput').value = '';
    document.getElementById('adminStatusInput').value = isActive;
    document.getElementById('adminRoleInput').value = role || 'administrator';
}

function confirmDeleteAdmin() {
    const actionInput = document.getElementById('adminActionInput');
    if (actionInput.value !== 'update') {
        return false;
    }
    actionInput.value = 'delete';
    return window.confirm('Supprimer cet utilisateur local ?');
}

async function postAdministrationAction(url, payload) {
    const response = await fetch(url, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
            Accept: 'application/json',
        },
        body: new URLSearchParams({
            csrf_token: administrationCsrfToken,
            ...payload,
        }),
    });

    let data = null;
    try {
        data = await response.json();
    } catch (error) {
        data = null;
    }

    if (!response.ok || !data || data.success !== true) {
        throw new Error(data && data.message ? data.message : 'Action administrative impossible.');
    }

    return data;
}

async function readAdministrationJsonResponse(response) {
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

async function validateMikrotikImportTarget(deviceId) {
    const response = await fetch('/api/admin/validate_mikrotik_target.php', {
        method: 'POST',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
        },
        body: new URLSearchParams({
            csrf_token: administrationCsrfToken,
            device_id: deviceId,
        }),
    });

    const data = await readAdministrationJsonResponse(response);
    if (!response.ok || !data || data.success !== true) {
        throw new Error(data && data.message ? data.message : 'Routeur MikroTik cible non joignable.');
    }

    return data;
}

function administrationDownloadBlob(blob, fileName) {
    const url = window.URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = url;
    link.download = fileName;
    document.body.appendChild(link);
    link.click();
    link.remove();
    window.URL.revokeObjectURL(url);
}

function administrationExtractDownloadFilename(contentDisposition, fallbackName) {
    const value = String(contentDisposition || '');
    const utf8Match = value.match(/filename\*=UTF-8''([^;]+)/i);
    if (utf8Match && utf8Match[1]) {
        return decodeURIComponent(utf8Match[1]);
    }

    const basicMatch = value.match(/filename="?([^"]+)"?/i);
    if (basicMatch && basicMatch[1]) {
        return basicMatch[1];
    }

    return fallbackName;
}

function readJsonFromText(text) {
    const raw = String(text || '').trim();
    if (raw === '') {
        return null;
    }

    try {
        return JSON.parse(raw);
    } catch (error) {
        return null;
    }
}

function administrationEscapeHtml(value) {
    return String(value)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}

function normalizeDateOnly(value) {
    const raw = String(value || '').trim();
    const match = raw.match(/^\d{4}-\d{2}-\d{2}/);
    return match ? match[0] : '';
}

function isPastDate(dateOnly) {
    if (!dateOnly) {
        return false;
    }
    const today = new Date();
    const utcToday = new Date(Date.UTC(today.getUTCFullYear(), today.getUTCMonth(), today.getUTCDate()));
    const target = new Date(`${dateOnly}T00:00:00Z`);
    if (Number.isNaN(target.getTime())) {
        return false;
    }
    return target.getTime() < utcToday.getTime();
}

function getDocumentArrays(payload) {
    const profiles = Array.isArray(payload?.profiles) ? payload.profiles : [];
    const users = Array.isArray(payload?.users) ? payload.users : [];
    return { profiles, users };
}

function analyzeMikrotikStandardDocument(payload) {
    const blockers = [];
    const warnings = [];

    if (!payload || typeof payload !== 'object') {
        return {
            blockers: ['Le fichier JSON est invalide.'],
            warnings: [],
        };
    }

    const format = String(payload.format || '').trim();
    const version = Number(payload.version || 0);
    const sourceBackend = String(payload.source_backend || payload.backend || '').trim().toLowerCase();

    if (format !== 'radius-manager-standard') {
        blockers.push('Format standard invalide.');
    }

    if (![1, 2].includes(version)) {
        blockers.push('Version de document non supportee.');
    } else if (version === 1) {
        warnings.push('Document standard legacy v1 : reexport recommande avant import.');
    }

    if (sourceBackend !== 'mikrotik') {
        blockers.push('Le document charge n est pas un export MikroTik.');
    }

    if (version === 2) {
        const mik = payload.backend_specific && payload.backend_specific.mikrotik;
        if (!mik || !Array.isArray(mik.profiles) || !Array.isArray(mik.users)) {
            blockers.push('Document v2 incomplet : backend_specific.mikrotik (profiles / users) manquant.');
        }
    }

    const { profiles, users } = getDocumentArrays(payload);
    if (!Array.isArray(profiles) || !Array.isArray(users)) {
        blockers.push('Structure standard invalide : profils ou utilisateurs manquants.');
        return { blockers, warnings };
    }

    const profileNames = new Set();
    profiles.forEach((profile, index) => {
        const name = String(profile?.name || '').trim();
        if (name === '') {
            blockers.push(`Profil #${index + 1} : nom manquant.`);
            return;
        }
        const key = name.toLowerCase();
        if (profileNames.has(key)) {
            blockers.push(`Profil duplique dans le fichier : ${name}.`);
        }
        profileNames.add(key);

        if (key === 'default') {
            warnings.push('Profil protege detecte : default.');
        }

        const sharedUsers = Number(profile?.shared_users ?? profile?.simultaneous_use ?? 1);
        if (Number.isFinite(sharedUsers) && sharedUsers < 1) {
            warnings.push(`Profil ${name} : shared_users < 1.`);
        }
    });

    const usernames = new Set();
    users.forEach((user, index) => {
        const username = String(user?.username || '').trim();
        const profile = String(user?.profile || '').trim();

        if (username.toLowerCase() === 'default-trial') {
            return;
        }

        if (username === '') {
            blockers.push(`Utilisateur #${index + 1} : username manquant.`);
            return;
        }
        const userKey = username.toLowerCase();
        if (usernames.has(userKey)) {
            blockers.push(`Utilisateur duplique dans le fichier : ${username}.`);
        }
        usernames.add(userKey);

        if (profile === '') {
            blockers.push(`Utilisateur ${username} : profil manquant.`);
        } else if (!profileNames.has(profile.toLowerCase())) {
            warnings.push(`Utilisateur ${username} : profil ${profile} absent du fichier (verification serveur requise).`);
        }

        if (userKey === 'admin') {
            warnings.push('Compte sensible detecte : admin.');
        }

        const expirationDate = normalizeDateOnly(user?.expiration_date || user?.comment_raw || '');
        const statusEffective = String(user?.status_effective || user?.status || 'active').trim().toLowerCase();
        if (expirationDate !== '' && isPastDate(expirationDate) && statusEffective === 'active') {
            warnings.push(`Compte expire encore marque actif dans le fichier : ${username}.`);
        }

        const sessionTimeout = Number(user?.session_timeout ?? 0);
        if (Number.isFinite(sessionTimeout) && sessionTimeout > 0 && sessionTimeout <= 60) {
            warnings.push(`Session timeout tres court detecte pour ${username} : ${sessionTimeout}s.`);
        }
    });

    return { blockers, warnings };
}

window.resetAdminForm = resetAdminForm;
window.fillAdminForm = fillAdminForm;
window.confirmDeleteAdmin = confirmDeleteAdmin;

document.addEventListener('DOMContentLoaded', () => {
    const searchInput = document.getElementById('adminSearchInput');
    const tableBody = document.getElementById('adminTableBody');
    const passwordInput = document.getElementById('adminPasswordInput');
    const togglePasswordBtn = document.getElementById('toggleAdminPasswordBtn');
    const sqlFileInput = document.getElementById('adminSqlFileInput');
    const chooseSqlFileBtn = document.getElementById('chooseSqlFileBtn');
    const selectedSqlFileName = document.getElementById('selectedSqlFileName');
    const syncOpnsenseSessionsBtn = document.getElementById('syncOpnsenseSessionsBtn');
    const installOpnsenseCronBtn = document.getElementById('installOpnsenseCronBtn');
    const opnsenseMaintenanceStatus =
        document.getElementById('opnsenseMaintenanceStatus')
        || document.querySelector('.administration-opnsense-status');

    const mikrotikIoDeviceSelect = document.getElementById('mikrotikIoDeviceSelect');
    const mikrotikImportMode = document.getElementById('mikrotikImportMode');
    const mikrotikStandardFileInput = document.getElementById('mikrotikStandardFileInput');
    const chooseMikrotikStandardFileBtn = document.getElementById('chooseMikrotikStandardFileBtn');
    const selectedMikrotikStandardFileName = document.getElementById('selectedMikrotikStandardFileName');
    const mikrotikIoStatus = document.getElementById('mikrotikIoStatus');
    const importMikrotikStandardBtn = document.getElementById('importMikrotikStandardBtn');
    const exportMikrotikStandardBtn = document.getElementById('exportMikrotikStandardBtn');
    const mikrotikRiskEmpty = document.getElementById('mikrotikRiskEmpty');
    const mikrotikRiskGroups = document.getElementById('mikrotikRiskGroups');
    const mikrotikRiskBlockers = document.getElementById('mikrotikRiskBlockers');
    const mikrotikRiskWarnings = document.getElementById('mikrotikRiskWarnings');
    const mikrotikWarningsConfirm = document.getElementById('mikrotikWarningsConfirm');
    const mikrotikIncludeSensitive = document.getElementById('mikrotikIncludeSensitive');

    let currentRiskState = {
        blockers: [],
        warnings: [],
    };
    let keepMikrotikImportSummaryVisible = false;
    let mikrotikRiskAnalyzed = false;

    if (searchInput && tableBody) {
        searchInput.addEventListener('input', () => {
            const query = searchInput.value.trim().toLowerCase();
            tableBody.querySelectorAll('tr').forEach((row) => {
                row.style.display = query === '' || row.textContent.toLowerCase().includes(query) ? '' : 'none';
            });
        });
    }

    if (passwordInput && togglePasswordBtn) {
        togglePasswordBtn.addEventListener('click', () => {
            const nextType = passwordInput.type === 'password' ? 'text' : 'password';
            passwordInput.type = nextType;
            togglePasswordBtn.innerHTML = nextType === 'text'
                ? '<i class="fa fa-eye-slash"></i>'
                : '<i class="fa fa-eye"></i>';
        });
    }

    if (sqlFileInput && chooseSqlFileBtn && selectedSqlFileName) {
        chooseSqlFileBtn.addEventListener('click', () => {
            sqlFileInput.click();
        });

        sqlFileInput.addEventListener('change', () => {
            selectedSqlFileName.textContent = sqlFileInput.files && sqlFileInput.files[0]
                ? sqlFileInput.files[0].name
                : 'Aucun fichier';
        });
    }

    const setMikrotikIoStatus = (message) => {
        if (!mikrotikIoStatus) {
            return;
        }
        mikrotikIoStatus.textContent = message;
    };

    const getSelectedMikrotikDeviceLabel = () => {
        if (!mikrotikIoDeviceSelect) {
            return '';
        }
        const option = mikrotikIoDeviceSelect.selectedOptions && mikrotikIoDeviceSelect.selectedOptions[0]
            ? mikrotikIoDeviceSelect.selectedOptions[0]
            : null;
        return option ? option.textContent.trim() : '';
    };

    const renderRiskState = () => {
        if (!mikrotikRiskEmpty || !mikrotikRiskGroups || !mikrotikRiskBlockers || !mikrotikRiskWarnings || !mikrotikWarningsConfirm) {
            return;
        }

        const hasFile = !!(
            mikrotikStandardFileInput &&
            mikrotikStandardFileInput.files &&
            mikrotikStandardFileInput.files[0]
        );

        if (!hasFile) {
            mikrotikRiskAnalyzed = false;
            mikrotikRiskEmpty.classList.remove('d-none');
            mikrotikRiskGroups.classList.add('d-none');
            mikrotikWarningsConfirm.disabled = true;
            mikrotikWarningsConfirm.checked = false;
            return;
        }

        if (!mikrotikRiskAnalyzed) {
            mikrotikRiskEmpty.classList.remove('d-none');
            mikrotikRiskGroups.classList.add('d-none');
            return;
        }

        const { blockers, warnings } = currentRiskState;

        mikrotikRiskEmpty.classList.add('d-none');
        mikrotikRiskGroups.classList.remove('d-none');

        mikrotikRiskBlockers.innerHTML = blockers.length > 0
            ? blockers.map((item) => `<li>${administrationEscapeHtml(item)}</li>`).join('')
            : '<li>Aucun blocage detecte.</li>';
        mikrotikRiskWarnings.innerHTML = warnings.length > 0
            ? warnings.map((item) => `<li>${administrationEscapeHtml(item)}</li>`).join('')
            : '<li>Aucun warning detecte.</li>';

        mikrotikWarningsConfirm.disabled = warnings.length === 0;
        if (warnings.length === 0) {
            mikrotikWarningsConfirm.checked = false;
        }
    };

    const syncMikrotikIoUi = () => {
        const hasSelectedDevice = !!(
            mikrotikIoDeviceSelect &&
            !mikrotikIoDeviceSelect.disabled &&
            String(mikrotikIoDeviceSelect.value || '').trim() !== ''
        );
        const hasSelectedFile = !!(
            mikrotikStandardFileInput &&
            mikrotikStandardFileInput.files &&
            mikrotikStandardFileInput.files[0]
        );

        if (chooseMikrotikStandardFileBtn) {
            chooseMikrotikStandardFileBtn.disabled = !hasSelectedDevice;
        }

        if (exportMikrotikStandardBtn) {
            exportMikrotikStandardBtn.disabled = !hasSelectedDevice;
            exportMikrotikStandardBtn.setAttribute('aria-disabled', hasSelectedDevice ? 'false' : 'true');
        }

        if (importMikrotikStandardBtn) {
            const canEnable = hasSelectedDevice && hasSelectedFile;
            importMikrotikStandardBtn.disabled = !canEnable;
            if (canEnable) {
                importMikrotikStandardBtn.removeAttribute('aria-disabled');
            } else {
                importMikrotikStandardBtn.setAttribute('aria-disabled', 'true');
            }
        }

        if (!mikrotikIoStatus) {
            return;
        }

        if (keepMikrotikImportSummaryVisible) {
            return;
        }

        if (!mikrotikIoDeviceSelect || mikrotikIoDeviceSelect.disabled) {
            setMikrotikIoStatus('Aucun device MikroTik disponible pour initialiser le flux standard.');
            return;
        }

        if (!hasSelectedDevice) {
            setMikrotikIoStatus('Choisissez un device MikroTik.');
            return;
        }

        const deviceLabel = getSelectedMikrotikDeviceLabel();
        const fileLabel = hasSelectedFile ? mikrotikStandardFileInput.files[0].name : 'aucun fichier';
        const modeLabel = mikrotikImportMode ? String(mikrotikImportMode.value || 'skip') : 'skip';

        setMikrotikIoStatus(
            'Device : ' + deviceLabel
            + ' | Fichier : ' + fileLabel
            + ' | Mode : ' + modeLabel
            + ' | Blocants : ' + currentRiskState.blockers.length
            + ' | Warnings : ' + currentRiskState.warnings.length
            + '.'
        );
    };

    const formatImportSummary = (data) => {
        const profileSummary = data && data.profiles ? data.profiles : {};
        const userSummary = data && data.users ? data.users : {};

        const profileErrors = Array.isArray(profileSummary.errors) ? profileSummary.errors.length : 0;
        const userErrors = Array.isArray(userSummary.errors) ? userSummary.errors.length : 0;
        const profileCreated = Number(profileSummary.created || 0);
        const profileUpdated = Number(profileSummary.updated || 0);
        const userCreated = Number(userSummary.created || 0);
        const userUpdated = Number(userSummary.updated || 0);
        const totalCreated = profileCreated + userCreated;
        const totalUpdated = profileUpdated + userUpdated;
        const resolvedDeviceId = String(data && data.device_id ? data.device_id : '').trim();
        const resolvedNasId = String(data && data.resolved_nas_id ? data.resolved_nas_id : '').trim();
        const resolvedNasType = String(data && data.resolved_nas_type ? data.resolved_nas_type : '').trim();
        const resolvedBusinessSource = String(data && data.resolved_business_source ? data.resolved_business_source : '').trim();

        const targetSummary = [
            resolvedDeviceId !== '' ? ('Device: ' + resolvedDeviceId) : null,
            resolvedNasId !== '' ? ('NAS: ' + resolvedNasId) : null,
            resolvedNasType !== '' ? ('Type NAS: ' + resolvedNasType) : null,
            resolvedBusinessSource !== '' ? ('Source: ' + resolvedBusinessSource) : null,
        ].filter(Boolean).join(' | ');

        const resultLabel = totalCreated > 0
            ? 'Import standard termine : creations et mises a jour confirmees.'
            : (totalUpdated > 0
                ? 'Import standard termine : aucune creation, elements existants mis a jour.'
                : 'Import standard termine : aucune ecriture confirmee.');

        const parts = [
            resultLabel,
            targetSummary !== '' ? targetSummary + '.' : null,
            'Profils: +' + String(profileCreated)
                + ' / ~' + String(profileUpdated)
                + ' / =' + String(profileSummary.skipped || 0)
                + ' / prot ' + String(profileSummary.protected || 0)
                + ' / err ' + String(profileErrors) + '.',
            'Utilisateurs: +' + String(userCreated)
                + ' / ~' + String(userUpdated)
                + ' / =' + String(userSummary.skipped || 0)
                + ' / sensibles skip ' + String(userSummary.sensitive_skipped || 0)
                + ' / invalides skip ' + String(userSummary.invalid_skipped || 0)
                + ' / err ' + String(userErrors) + '.',
        ];

        return parts.filter(Boolean).join(' ');
    };

    const analyzeSelectedStandardFile = async () => {
        keepMikrotikImportSummaryVisible = false;
        if (!mikrotikStandardFileInput || !mikrotikStandardFileInput.files || !mikrotikStandardFileInput.files[0]) {
            currentRiskState = { blockers: [], warnings: [] };
            mikrotikRiskAnalyzed = false;
            renderRiskState();
            syncMikrotikIoUi();
            return;
        }

        try {
            const file = mikrotikStandardFileInput.files[0];
            const text = await file.text();
            const payload = readJsonFromText(text);
            currentRiskState = analyzeMikrotikStandardDocument(payload);
            mikrotikRiskAnalyzed = true;
        } catch (error) {
            currentRiskState = {
                blockers: ['Lecture ou analyse du fichier impossible.'],
                warnings: [],
            };
            mikrotikRiskAnalyzed = true;
        }
        renderRiskState();
        syncMikrotikIoUi();
    };

    if (mikrotikStandardFileInput && chooseMikrotikStandardFileBtn && selectedMikrotikStandardFileName) {
        chooseMikrotikStandardFileBtn.addEventListener('click', () => {
            if (chooseMikrotikStandardFileBtn.disabled) {
                return;
            }
            mikrotikStandardFileInput.click();
        });

        mikrotikStandardFileInput.addEventListener('change', async () => {
            selectedMikrotikStandardFileName.textContent =
                mikrotikStandardFileInput.files && mikrotikStandardFileInput.files[0]
                    ? mikrotikStandardFileInput.files[0].name
                    : 'Aucun fichier';
            if (mikrotikWarningsConfirm) {
                mikrotikWarningsConfirm.checked = false;
            }
            if (!mikrotikStandardFileInput.files || !mikrotikStandardFileInput.files[0]) {
                currentRiskState = { blockers: [], warnings: [] };
                mikrotikRiskAnalyzed = false;
                renderRiskState();
                syncMikrotikIoUi();
                return;
            }
            syncMikrotikIoUi();
            await analyzeSelectedStandardFile();
        });
    }

    if (mikrotikWarningsConfirm) {
        mikrotikWarningsConfirm.addEventListener('change', () => {
            syncMikrotikIoUi();
        });
    }

    if (mikrotikIoDeviceSelect) {
        mikrotikIoDeviceSelect.addEventListener('change', () => {
            keepMikrotikImportSummaryVisible = false;
            syncMikrotikIoUi();
        });
    }

    if (mikrotikImportMode) {
        mikrotikImportMode.addEventListener('change', () => {
            keepMikrotikImportSummaryVisible = false;
            syncMikrotikIoUi();
        });
    }

    if (mikrotikIncludeSensitive) {
        mikrotikIncludeSensitive.addEventListener('change', () => {
            keepMikrotikImportSummaryVisible = false;
            syncMikrotikIoUi();
        });
    }

    if (exportMikrotikStandardBtn) {
        exportMikrotikStandardBtn.addEventListener('click', async () => {
            keepMikrotikImportSummaryVisible = false;
            const deviceId = mikrotikIoDeviceSelect ? String(mikrotikIoDeviceSelect.value || '').trim() : '';
            const deviceLabel = getSelectedMikrotikDeviceLabel();

            if (deviceId === '') {
                setMikrotikIoStatus('Choisissez un device MikroTik avant export.');
                return;
            }

            exportMikrotikStandardBtn.disabled = true;
            setMikrotikIoStatus('Export standard MikroTik en cours pour ' + deviceLabel + '...');

            try {
                const response = await fetch('/api/admin/mikrotik_export_standard.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
                        Accept: 'application/json, application/octet-stream',
                    },
                    body: new URLSearchParams({
                        csrf_token: administrationCsrfToken,
                        device_id: deviceId,
                    }),
                });

                if (!response.ok) {
                    const errorPayload = readJsonFromText(await response.text());
                    throw new Error(errorPayload && errorPayload.message ? errorPayload.message : 'Export MikroTik impossible.');
                }

                const blob = await response.blob();
                const fileName = administrationExtractDownloadFilename(
                    response.headers.get('content-disposition'),
                    'mikrotik_standard_v2.json'
                );
                administrationDownloadBlob(blob, fileName);
                setMikrotikIoStatus('Export standard termine pour ' + deviceLabel + ' : ' + fileName);
            } catch (error) {
                setMikrotikIoStatus(error.message || 'Export MikroTik impossible.');
            } finally {
                syncMikrotikIoUi();
            }
        });
    }

    if (importMikrotikStandardBtn) {
        importMikrotikStandardBtn.addEventListener('click', async () => {
            keepMikrotikImportSummaryVisible = false;
            const deviceId = mikrotikIoDeviceSelect ? String(mikrotikIoDeviceSelect.value || '').trim() : '';
            const file = mikrotikStandardFileInput && mikrotikStandardFileInput.files
                ? mikrotikStandardFileInput.files[0]
                : null;

            if (deviceId === '') {
                setMikrotikIoStatus('Choisissez un device cible avant import.');
                return;
            }

            if (!file) {
                setMikrotikIoStatus('Choisissez un fichier JSON standard avant import.');
                return;
            }

            if (!mikrotikRiskAnalyzed) {
                await analyzeSelectedStandardFile();
            }

            const modal = new bootstrap.Modal(document.getElementById('mikrotikImportModal'));
            modal.show();

            const startBtn = document.getElementById('mikrotikImportStartBtn');
            const confirmBtn = document.getElementById('mikrotikImportConfirmBtn');
            const warningsConfirm = mikrotikWarningsConfirm;
            const includeSensitiveModal = mikrotikIncludeSensitive;
            const deviceLabel = getSelectedMikrotikDeviceLabel();
            const mode = mikrotikImportMode ? String(mikrotikImportMode.value || 'skip').trim() : 'skip';

            if (includeSensitiveModal) {
                includeSensitiveModal.checked = !!(mikrotikIncludeSensitive && mikrotikIncludeSensitive.checked);
            }

            document.getElementById('mikrotikImportPreAnalysis').classList.remove('d-none');
            document.getElementById('mikrotikImportProcess').classList.add('d-none');
            document.getElementById('mikrotikImportSummary').classList.add('d-none');
            document.getElementById('mikrotikImportProgress').style.width = '0%';
            document.getElementById('mikrotikImportProgress').classList.remove('bg-danger');
            startBtn.classList.remove('d-none');
            confirmBtn.classList.add('d-none');

            startBtn.onclick = async () => {
                const hasBlockers = currentRiskState.blockers.length > 0;
                const requiresWarningConfirm = currentRiskState.warnings.length > 0;
                const warningsConfirmed = !!(warningsConfirm && warningsConfirm.checked);
                const includeSensitive = !!(mikrotikIncludeSensitive && mikrotikIncludeSensitive.checked);

                if (hasBlockers) {
                    alert('Import bloqué : corrigez d\'abord les blocants.');
                    return;
                }

                if (requiresWarningConfirm && !warningsConfirmed) {
                    alert('Confirmez les warnings avant import.');
                    return;
                }

                await performMikrotikImport(deviceId, deviceLabel, mode, includeSensitive, file, modal, {
                    setStatus: setMikrotikIoStatus,
                    formatSummary: formatImportSummary,
                    syncUi: syncMikrotikIoUi,
                    markSummaryVisible: () => {
                        keepMikrotikImportSummaryVisible = true;
                    },
                });
            };
        });
    }

    if (syncOpnsenseSessionsBtn && opnsenseMaintenanceStatus) {
        syncOpnsenseSessionsBtn.addEventListener('click', async () => {
            syncOpnsenseSessionsBtn.disabled = true;
            opnsenseMaintenanceStatus.textContent = 'Synchronisation OPNsense en cours...';

            try {
                const result = await postAdministrationAction('/api/admin/opnsense_sync_sessions.php', {});
                const synced = Array.isArray(result.synced) && result.synced.length > 0
                    ? result.synced.join(', ')
                    : 'aucun';
                const deletedRules = Array.isArray(result.deleted_rules) && result.deleted_rules.length > 0
                    ? result.deleted_rules.length
                    : 0;

                opnsenseMaintenanceStatus.textContent =
                    'Synchro OPNsense OK. Sessions: ' + String(result.sessions || 0) +
                    ' | Utilisateurs synchronises: ' + synced +
                    ' | Rules nettoyees: ' + String(deletedRules);
            } catch (error) {
                opnsenseMaintenanceStatus.textContent = error.message || 'Synchronisation OPNsense impossible.';
            } finally {
                syncOpnsenseSessionsBtn.disabled = false;
            }
        });
    }

    if (installOpnsenseCronBtn && opnsenseMaintenanceStatus) {
        installOpnsenseCronBtn.addEventListener('click', async () => {
            installOpnsenseCronBtn.disabled = true;
            opnsenseMaintenanceStatus.textContent = 'Installation du cron OPNsense en cours...';

            try {
                const result = await postAdministrationAction('/api/admin/install_opnsense_cron.php', {});
                opnsenseMaintenanceStatus.textContent =
                    'Cron installe pour l utilisateur OS ' + String(result.os_user || '-') +
                    '. Session sync: ' + String(result.script_path || '-') +
                    ' | Supervision devices: ' + String(result.monitor_script_path || '-') +
                    ' | Frequence supervision: 10 min';
            } catch (error) {
                opnsenseMaintenanceStatus.textContent = error.message || 'Installation cron impossible.';
            } finally {
                installOpnsenseCronBtn.disabled = false;
            }
        });
    }

    renderRiskState();
    syncMikrotikIoUi();
});

async function performMikrotikImport(deviceId, deviceLabel, mode, includeSensitive, file, modal, callbacks) {
    const processSection = document.getElementById('mikrotikImportProcess');
    const summarySection = document.getElementById('mikrotikImportSummary');
    const statusDiv = document.getElementById('mikrotikImportStatus');
    const progressBar = document.getElementById('mikrotikImportProgress');
    const profilesCount = document.getElementById('mikrotikImportProfilesCount');
    const usersCount = document.getElementById('mikrotikImportUsersCount');
    const startBtn = document.getElementById('mikrotikImportStartBtn');
    const confirmBtn = document.getElementById('mikrotikImportConfirmBtn');

    // Masquer pré-analyse, afficher processus
    document.getElementById('mikrotikImportPreAnalysis').classList.add('d-none');
    processSection.classList.remove('d-none');
    summarySection.classList.add('d-none');

    startBtn.classList.add('d-none');
    confirmBtn.classList.add('d-none');

    try {
        // Étape 1: Validation du routeur
        statusDiv.className = 'alert alert-primary';
        statusDiv.innerHTML = '<i class="fa fa-spinner fa-spin me-2"></i>Validation du routeur MikroTik cible...';
        progressBar.style.width = '25%';

        const validation = await validateMikrotikImportTarget(deviceId);
        const routerIdentity = String(validation && validation.router_identity ? validation.router_identity : '').trim();

        statusDiv.innerHTML = `<i class="fa fa-check-circle me-2"></i>Routeur cible valide${routerIdentity !== '' ? ' (' + administrationEscapeHtml(routerIdentity) + ')' : ''}.`;
        profilesCount.textContent = '0';
        usersCount.textContent = '0';

        // Étape 2: Import en cours
        statusDiv.className = 'alert alert-primary';
        statusDiv.innerHTML = '<i class="fa fa-cog fa-spin me-2"></i>Import standard en cours vers ' + administrationEscapeHtml(deviceLabel) + '...';
        progressBar.style.width = '50%';

        const formData = new FormData();
        formData.append('csrf_token', administrationCsrfToken);
        formData.append('device_id', deviceId);
        formData.append('mode', mode);
        formData.append('include_sensitive', includeSensitive ? '1' : '0');
        formData.append('standard_file', file);

        const response = await fetch('/api/admin/mikrotik_import_standard.php', {
            method: 'POST',
            headers: {
                Accept: 'application/json',
            },
            body: formData,
        });

        const data = await readAdministrationJsonResponse(response);
        if (!response.ok || !data || data.success !== true) {
            throw new Error(data && data.message ? data.message : 'Import standard impossible.');
        }

        // Étape 3: Import terminé
        progressBar.style.width = '100%';
        statusDiv.className = 'alert alert-success';
        statusDiv.innerHTML = '<i class="fa fa-check-circle me-2"></i>Import terminé avec succès !';

        // Mettre à jour les compteurs
        const profileSummary = data.profiles || {};
        const userSummary = data.users || {};
        profilesCount.textContent = (profileSummary.created || 0) + (profileSummary.updated || 0);
        usersCount.textContent = (userSummary.created || 0) + (userSummary.updated || 0);

        // Afficher le résumé détaillé
        setTimeout(() => {
            showMikrotikImportSummary(data, callbacks);
        }, 1000);

    } catch (error) {
        progressBar.style.width = '100%';
        progressBar.classList.add('bg-danger');
        statusDiv.className = 'alert alert-danger';
        statusDiv.innerHTML = '<i class="fa fa-exclamation-triangle me-2"></i>' + administrationEscapeHtml(error.message || 'Import standard impossible.');
    }
}

function showMikrotikImportSummary(data, callbacks) {
    const processSection = document.getElementById('mikrotikImportProcess');
    const summarySection = document.getElementById('mikrotikImportSummary');
    const resultDiv = document.getElementById('mikrotikImportResult');
    const profilesSummary = document.getElementById('mikrotikImportProfilesSummary');
    const usersSummary = document.getElementById('mikrotikImportUsersSummary');
    const confirmBtn = document.getElementById('mikrotikImportConfirmBtn');

    // Masquer processus, afficher résumé
    processSection.classList.add('d-none');
    summarySection.classList.remove('d-none');

    // Résumé général
    const profileSummary = data.profiles || {};
    const userSummary = data.users || {};
    const totalCreated = (profileSummary.created || 0) + (userSummary.created || 0);
    const totalUpdated = (profileSummary.updated || 0) + (userSummary.updated || 0);

    const resultLabel = totalCreated > 0
        ? 'Import standard terminé : créations et mises à jour confirmées.'
        : (totalUpdated > 0
            ? 'Import standard terminé : aucune création, éléments existants mis à jour.'
            : 'Import standard terminé : aucune écriture confirmée.');

    resultDiv.innerHTML = '<i class="fa fa-check-circle me-2"></i>' + administrationEscapeHtml(resultLabel);

    // Détails profils
    profilesSummary.innerHTML = `
        Créés: ${profileSummary.created || 0}<br>
        Mis à jour: ${profileSummary.updated || 0}<br>
        Protégés: ${profileSummary.protected || 0}<br>
        Erreurs: ${Array.isArray(profileSummary.errors) ? profileSummary.errors.length : 0}
    `;

    // Détails utilisateurs
    usersSummary.innerHTML = `
        Créés: ${userSummary.created || 0}<br>
        Mis à jour: ${userSummary.updated || 0}<br>
        Sensibles ignorés: ${userSummary.sensitive_skipped || 0}<br>
        Invalides ignorés: ${userSummary.invalid_skipped || 0}<br>
        Erreurs: ${Array.isArray(userSummary.errors) ? userSummary.errors.length : 0}
    `;

    // Afficher le bouton de confirmation
    confirmBtn.classList.remove('d-none');
    confirmBtn.onclick = () => {
        const modal = bootstrap.Modal.getInstance(document.getElementById('mikrotikImportModal'));
        modal.hide();
        if (callbacks && typeof callbacks.setStatus === 'function' && typeof callbacks.formatSummary === 'function') {
            callbacks.setStatus(callbacks.formatSummary(data));
        }
        if (callbacks && typeof callbacks.markSummaryVisible === 'function') {
            callbacks.markSummaryVisible();
        }
        if (callbacks && typeof callbacks.syncUi === 'function') {
            callbacks.syncUi();
        }
    };
}

function administrationEscapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}
