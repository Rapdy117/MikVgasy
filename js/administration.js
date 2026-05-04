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

function importErrorsFromSummary(summary) {
    return Array.isArray(summary && summary.errors)
        ? summary.errors.filter((item) => String(item || '').trim() !== '')
        : [];
}

function formatImportErrorPreviewText(label, errors) {
    if (errors.length < 1) {
        return null;
    }
    const previewLimit = 3;
    const shown = errors.slice(0, previewLimit).map((item) => String(item));
    const more = errors.length > previewLimit ? ' +' + String(errors.length - previewLimit) + ' autres' : '';
    return label + ' erreurs: ' + shown.join(' | ') + more + '.';
}

function formatImportErrorsHtml(errors) {
    if (errors.length < 1) {
        return '';
    }
    const previewLimit = 5;
    const shown = errors.slice(0, previewLimit)
        .map((item) => administrationEscapeHtml(item))
        .join('<br>');
    const more = errors.length > previewLimit
        ? '<br><span class="text-white-50">+' + String(errors.length - previewLimit) + ' autres erreurs non affichees.</span>'
        : '';
    return '<br><span class="text-warning">Premieres erreurs:</span><br>' + shown + more;
}

function formatSkipCausesHtml(summary, type) {
    const lines = [];
    const existingSkipped = Number(summary && summary.existing_skipped ? summary.existing_skipped : 0);
    if (existingSkipped > 0) {
        lines.push(String(existingSkipped) + ' existant(s), ignoré(s) car mode "Ignorer les doublons".');
    }

    if (type === 'users') {
        const missingProfile = Number(summary && summary.invalid_missing_profile ? summary.invalid_missing_profile : 0);
        const maskedPassword = Number(summary && summary.invalid_masked_password ? summary.invalid_masked_password : 0);
        if (missingProfile > 0) {
            lines.push(String(missingProfile) + ' utilisateur(s) avec profil absent de la cible.');
        }
        if (maskedPassword > 0) {
            lines.push(String(maskedPassword) + ' nouvel utilisateur avec mot de passe masqué.');
        }
    }

    if (lines.length < 1) {
        return '';
    }

    return '<br><span class="text-warning">Causes:</span><br>' + lines
        .map((line) => administrationEscapeHtml(line))
        .join('<br>');
}

function formatDurationSeconds(ms) {
    return Math.max(1, Math.ceil(Number(ms || 0) / 1000)) + 's';
}

function estimateMikrotikImportDurationMs(profileCount, userCount) {
    const estimated = 1800 + (Math.max(0, Number(profileCount || 0)) * 450) + (Math.max(0, Number(userCount || 0)) * 140);
    return Math.min(120000, Math.max(4000, estimated));
}

function createMikrotikImportProgressController(progressBar, stepsList, etaBox, estimateMs, customSteps) {
    const steps = Array.isArray(customSteps) && customSteps.length > 0 ? customSteps : [
        { at: 5, label: 'Validation du routeur cible' },
        { at: 14, label: 'Préparation du fichier standard' },
        { at: 30, label: 'Validation des profils et address-pool' },
        { at: 54, label: 'Écriture des profils MikroTik' },
        { at: 82, label: 'Écriture des utilisateurs MikroTik' },
        { at: 94, label: 'Finalisation et résumé' },
    ];
    let currentPercent = 0;
    let timer = null;
    const startedAt = Date.now();

    const render = (percent, activeLabel) => {
        currentPercent = Math.max(currentPercent, Math.min(99, percent));
        if (progressBar) {
            progressBar.style.width = currentPercent.toFixed(0) + '%';
            progressBar.setAttribute('aria-valuenow', currentPercent.toFixed(0));
            progressBar.textContent = currentPercent >= 8 ? currentPercent.toFixed(0) + '%' : '';
        }
        if (stepsList) {
            stepsList.innerHTML = steps.map((step) => {
                const icon = currentPercent >= step.at
                    ? 'fa-check text-success'
                    : (step.label === activeLabel ? 'fa-spinner fa-spin text-info' : 'fa-circle text-secondary');
                return '<li><i class="fa ' + icon + ' me-2"></i>' + administrationEscapeHtml(step.label) + '</li>';
            }).join('');
        }
        if (etaBox) {
            const elapsed = Date.now() - startedAt;
            const remaining = Math.max(0, estimateMs - elapsed);
            etaBox.textContent = 'Progression estimée depuis le lot : ' + currentPercent.toFixed(0)
                + '% | Temps estimé restant : ' + formatDurationSeconds(remaining) + '.';
        }
    };

    return {
        start() {
            render(3, steps[0].label);
            timer = window.setInterval(() => {
                const ratio = Math.min(1, (Date.now() - startedAt) / estimateMs);
                const eased = 1 - Math.pow(1 - ratio, 2);
                const percent = Math.min(94, 3 + (eased * 91));
                const activeStep = steps.slice().reverse().find((step) => percent >= step.at) || steps[0];
                render(percent, activeStep.label);
            }, 350);
        },
        set(percent, label) {
            render(percent, label || '');
        },
        complete(hasWarnings) {
            if (timer) {
                window.clearInterval(timer);
            }
            currentPercent = 100;
            if (progressBar) {
                progressBar.style.width = '100%';
                progressBar.setAttribute('aria-valuenow', '100');
                progressBar.textContent = '100%';
            }
            if (stepsList) {
                stepsList.innerHTML = steps.map((step) => (
                    '<li><i class="fa fa-check text-success me-2"></i>' + administrationEscapeHtml(step.label) + '</li>'
                )).join('');
            }
            if (etaBox) {
                etaBox.textContent = hasWarnings
                    ? 'Import terminé avec avertissements à vérifier.'
                    : 'Import terminé, progression complète.';
            }
        },
        fail(message) {
            if (timer) {
                window.clearInterval(timer);
            }
            if (etaBox) {
                etaBox.textContent = message || 'Import interrompu.';
            }
        },
    };
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

function collectMikrotikSourcePools(payload) {
    const pools = new Map();
    const backendProfiles = Array.isArray(payload?.backend_specific?.mikrotik?.profiles)
        ? payload.backend_specific.mikrotik.profiles
        : [];
    const standardProfiles = Array.isArray(payload?.profiles) ? payload.profiles : [];

    backendProfiles.concat(standardProfiles).forEach((profile) => {
        const pool = String(profile?.ip_pool || profile?.address_pool || '').trim();
        if (pool !== '') {
            pools.set(pool.toLowerCase(), pool);
        }
    });

    return Array.from(pools.values()).sort((left, right) => left.localeCompare(right));
}

function analyzeMikrotikStandardDocument(payload) {
    const blockers = [];
    const warnings = [];

    if (!payload || typeof payload !== 'object') {
        return {
            blockers: ['Le fichier JSON est invalide.'],
            warnings: [],
            sourcePools: [],
            profileCount: 0,
            userCount: 0,
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
        return {
            blockers,
            warnings,
            sourcePools: collectMikrotikSourcePools(payload),
            profileCount: profiles.length,
            userCount: users.length,
        };
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

    return {
        blockers,
        warnings,
        sourcePools: collectMikrotikSourcePools(payload),
        profileCount: profiles.length,
        userCount: users.length,
    };
}

function analyzeRadiusStandardDocument(payload) {
    const blockers = [];
    const warnings = [];

    if (!payload || typeof payload !== 'object') {
        return {
            blockers: ['Le fichier JSON est invalide.'],
            warnings: [],
            sourceBackend: '',
            profileCount: 0,
            userCount: 0,
            hasOpnsenseBusiness: false,
            hasRadiusProjection: false,
        };
    }

    const format = String(payload.format || '').trim();
    const version = Number(payload.version || 0);
    const sourceBackend = String(payload.source_backend || payload.backend || '').trim().toLowerCase();
    const { profiles, users } = getDocumentArrays(payload);
    const backendSpecific = payload.backend_specific && typeof payload.backend_specific === 'object'
        ? payload.backend_specific
        : {};
    const hasOpnsenseBusiness = !!(
        backendSpecific.opnsense &&
        (Array.isArray(backendSpecific.opnsense.profiles) || Array.isArray(backendSpecific.opnsense.users))
    );
    const hasRadiusProjection = !!(
        backendSpecific.radius &&
        (Array.isArray(backendSpecific.radius.radgroupreply)
            || (backendSpecific.radius.users && typeof backendSpecific.radius.users === 'object'))
    );

    if (format !== 'radius-manager-standard') {
        blockers.push('Format standard invalide.');
    }
    if (version !== 2) {
        blockers.push('Seul le standard v2 est accepte pour OPNsense / RADIUS.');
    }
    if (!['mikrotik', 'opnsense', 'radius'].includes(sourceBackend)) {
        blockers.push('Source standard non supportee.');
    }
    if (!Array.isArray(profiles) || !Array.isArray(users)) {
        blockers.push('Structure standard invalide : profils ou utilisateurs manquants.');
    }
    if (sourceBackend === 'opnsense' && !hasOpnsenseBusiness) {
        blockers.push('Export OPNsense invalide : base metier SQL manquante.');
    }
    if (sourceBackend === 'opnsense' && !hasRadiusProjection) {
        warnings.push('Export OPNsense sans projection Radius : la projection sera regeneree depuis la base metier.');
    }
    if (sourceBackend === 'mikrotik') {
        warnings.push('Export MikroTik detecte : migration via champs standards, sans base metier SQL source.');
    }

    const profileNames = new Set();
    profiles.forEach((profile) => {
        const name = String(profile && profile.name ? profile.name : '').trim();
        if (name !== '') {
            profileNames.add(name.toLowerCase());
        }
    });

    let maskedPasswords = 0;
    let sensitiveUsers = 0;
    let missingProfiles = 0;
    users.forEach((user) => {
        const username = String(user && user.username ? user.username : '').trim();
        const profile = String(user && (user.profile || user.profile_name) ? (user.profile || user.profile_name) : '').trim();
        const password = String(user && Object.prototype.hasOwnProperty.call(user, 'password') ? user.password : '');
        if (password === '****') {
            maskedPasswords += 1;
        }
        if (username.toLowerCase() === 'admin') {
            sensitiveUsers += 1;
        }
        if (profile === '' || !profileNames.has(profile.toLowerCase())) {
            missingProfiles += 1;
        }
    });

    if (maskedPasswords > 0) {
        warnings.push(String(maskedPasswords) + ' mot(s) de passe masque(s) detecte(s). Les nouveaux utilisateurs masques seront ignores.');
    }
    if (sensitiveUsers > 0) {
        warnings.push(String(sensitiveUsers) + ' compte(s) sensible(s) detecte(s), ignores sauf option explicite.');
    }
    if (missingProfiles > 0) {
        warnings.push(String(missingProfiles) + ' utilisateur(s) avec profil absent ou vide.');
    }

    return {
        blockers,
        warnings,
        sourceBackend,
        profileCount: profiles.length,
        userCount: users.length,
        hasOpnsenseBusiness,
        hasRadiusProjection,
    };
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
    const mikrotikPoolMapBox = document.getElementById('mikrotikPoolMapBox');
    const mikrotikPoolMapRows = document.getElementById('mikrotikPoolMapRows');
    const mikrotikPoolMapIntro = document.getElementById('mikrotikPoolMapIntro');
    const mikrotikPoolMapConfirm = document.getElementById('mikrotikPoolMapConfirm');
    const radiusIoDeviceSelect = document.getElementById('radiusIoDeviceSelect');
    const radiusImportMode = document.getElementById('radiusImportMode');
    const radiusStandardFileInput = document.getElementById('radiusStandardFileInput');
    const chooseRadiusStandardFileBtn = document.getElementById('chooseRadiusStandardFileBtn');
    const selectedRadiusStandardFileName = document.getElementById('selectedRadiusStandardFileName');
    const radiusIncludeSensitive = document.getElementById('radiusIncludeSensitive');
    const radiusIoStatus = document.getElementById('radiusIoStatus');
    const importRadiusStandardBtn = document.getElementById('importRadiusStandardBtn');
    const exportRadiusStandardBtn = document.getElementById('exportRadiusStandardBtn');
    const radiusRiskEmpty = document.getElementById('radiusRiskEmpty');
    const radiusRiskGroups = document.getElementById('radiusRiskGroups');
    const radiusRiskMeta = document.getElementById('radiusRiskMeta');
    const radiusRiskBlockers = document.getElementById('radiusRiskBlockers');
    const radiusRiskWarnings = document.getElementById('radiusRiskWarnings');
    const radiusWarningsConfirm = document.getElementById('radiusWarningsConfirm');

    let currentRiskState = {
        blockers: [],
        warnings: [],
        sourcePools: [],
        profileCount: 0,
        userCount: 0,
    };
    let currentMikrotikPoolMapState = {
        sourcePools: [],
        targetPools: [],
        missingPools: [],
        required: false,
    };
    let keepMikrotikImportSummaryVisible = false;
    let keepRadiusImportSummaryVisible = false;
    let mikrotikRiskAnalyzed = false;
    let currentRadiusAnalysis = null;

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

    const setRadiusIoStatus = (message) => {
        if (!radiusIoStatus) {
            return;
        }
        radiusIoStatus.textContent = message;
    };

    const getSelectedRadiusDeviceLabel = () => {
        if (!radiusIoDeviceSelect) {
            return '';
        }
        const option = radiusIoDeviceSelect.selectedOptions && radiusIoDeviceSelect.selectedOptions[0]
            ? radiusIoDeviceSelect.selectedOptions[0]
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

    const resetMikrotikPoolMapState = () => {
        currentMikrotikPoolMapState = {
            sourcePools: [],
            targetPools: [],
            missingPools: [],
            required: false,
        };
        if (mikrotikPoolMapConfirm) {
            mikrotikPoolMapConfirm.checked = false;
        }
    };

    const renderMikrotikPoolMapState = () => {
        if (!mikrotikPoolMapBox || !mikrotikPoolMapRows || !mikrotikPoolMapIntro || !mikrotikPoolMapConfirm) {
            return;
        }

        const missingPools = currentMikrotikPoolMapState.missingPools || [];
        const targetPools = currentMikrotikPoolMapState.targetPools || [];
        if (missingPools.length < 1) {
            mikrotikPoolMapBox.classList.add('d-none');
            mikrotikPoolMapRows.innerHTML = '';
            mikrotikPoolMapConfirm.checked = false;
            return;
        }

        mikrotikPoolMapBox.classList.remove('d-none');
        mikrotikPoolMapIntro.textContent = targetPools.length > 0
            ? 'Les pools ci-dessous existent dans le fichier source mais pas sur le routeur cible. Choisissez leur equivalent cible.'
            : 'Aucun address-pool cible n a ete lu sur le routeur. L import ne peut pas aligner les pools source.';
        mikrotikPoolMapConfirm.checked = false;
        mikrotikPoolMapConfirm.disabled = targetPools.length < 1;

        if (targetPools.length < 1) {
            mikrotikPoolMapRows.innerHTML = '<div class="small text-danger">Routeur cible sans address-pool disponible.</div>';
            return;
        }

        const targetOptions = targetPools
            .map((pool) => '<option value="' + administrationEscapeHtml(pool) + '">' + administrationEscapeHtml(pool) + '</option>')
            .join('');

        mikrotikPoolMapRows.innerHTML = missingPools.map((sourcePool) => {
            const escapedSource = administrationEscapeHtml(sourcePool);
            return ''
                + '<div class="input-group input-group-sm mb-2">'
                + '<span class="input-group-text">' + escapedSource + '</span>'
                + '<select class="form-select js-mikrotik-pool-map" data-source-pool="' + escapedSource + '">'
                + '<option value="">Choisir pool cible</option>'
                + targetOptions
                + '</select>'
                + '</div>';
        }).join('');
    };

    const gatherMikrotikPoolMap = () => {
        if (!currentMikrotikPoolMapState.required) {
            return {};
        }
        if (!mikrotikPoolMapRows || !mikrotikPoolMapConfirm) {
            throw new Error('Controle address-pool introuvable.');
        }
        if ((currentMikrotikPoolMapState.targetPools || []).length < 1) {
            throw new Error('Aucun address-pool cible disponible pour aligner les profils.');
        }
        if (!mikrotikPoolMapConfirm.checked) {
            throw new Error('Confirmez l alignement des address-pool avant import.');
        }

        const poolMap = {};
        const selects = Array.from(mikrotikPoolMapRows.querySelectorAll('.js-mikrotik-pool-map'));
        for (const select of selects) {
            const sourcePool = String(select.dataset.sourcePool || '').trim();
            const targetPool = String(select.value || '').trim();
            if (sourcePool === '' || targetPool === '') {
                throw new Error('Choisissez un pool cible pour chaque address-pool source.');
            }
            poolMap[sourcePool] = targetPool;
        }
        return poolMap;
    };

    const renderRadiusRiskState = () => {
        if (!radiusRiskEmpty || !radiusRiskGroups || !radiusRiskMeta || !radiusRiskBlockers || !radiusRiskWarnings || !radiusWarningsConfirm) {
            return;
        }

        const hasFile = !!(
            radiusStandardFileInput &&
            radiusStandardFileInput.files &&
            radiusStandardFileInput.files[0]
        );

        if (!hasFile || !currentRadiusAnalysis) {
            radiusRiskEmpty.classList.remove('d-none');
            radiusRiskGroups.classList.add('d-none');
            radiusWarningsConfirm.disabled = true;
            radiusWarningsConfirm.checked = false;
            return;
        }

        const analysis = currentRadiusAnalysis;
        radiusRiskEmpty.classList.add('d-none');
        radiusRiskGroups.classList.remove('d-none');
        radiusRiskMeta.innerHTML = [
            'Source : ' + administrationEscapeHtml(analysis.sourceBackend || '-'),
            'Profils : ' + String(analysis.profileCount || 0),
            'Utilisateurs : ' + String(analysis.userCount || 0),
            'SQL OPNsense : ' + (analysis.hasOpnsenseBusiness ? 'oui' : 'non'),
            'Projection Radius : ' + (analysis.hasRadiusProjection ? 'oui' : 'non'),
        ].join(' | ');

        radiusRiskBlockers.innerHTML = analysis.blockers.length > 0
            ? analysis.blockers.map((item) => `<li>${administrationEscapeHtml(item)}</li>`).join('')
            : '<li>Aucun blocage detecte.</li>';
        radiusRiskWarnings.innerHTML = analysis.warnings.length > 0
            ? analysis.warnings.map((item) => `<li>${administrationEscapeHtml(item)}</li>`).join('')
            : '<li>Aucun warning detecte.</li>';

        radiusWarningsConfirm.disabled = analysis.warnings.length === 0;
        if (analysis.warnings.length === 0) {
            radiusWarningsConfirm.checked = false;
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

    const syncRadiusIoUi = () => {
        const hasSelectedDevice = !!(
            radiusIoDeviceSelect &&
            !radiusIoDeviceSelect.disabled &&
            String(radiusIoDeviceSelect.value || '').trim() !== ''
        );
        const hasSelectedFile = !!(
            radiusStandardFileInput &&
            radiusStandardFileInput.files &&
            radiusStandardFileInput.files[0]
        );

        if (chooseRadiusStandardFileBtn) {
            chooseRadiusStandardFileBtn.disabled = !hasSelectedDevice;
        }
        if (radiusIncludeSensitive) {
            radiusIncludeSensitive.disabled = !hasSelectedDevice;
        }

        if (exportRadiusStandardBtn) {
            exportRadiusStandardBtn.disabled = !hasSelectedDevice;
            exportRadiusStandardBtn.setAttribute('aria-disabled', hasSelectedDevice ? 'false' : 'true');
        }

        if (importRadiusStandardBtn) {
            const canEnable = hasSelectedDevice && hasSelectedFile;
            importRadiusStandardBtn.disabled = !canEnable;
            importRadiusStandardBtn.setAttribute('aria-disabled', canEnable ? 'false' : 'true');
        }

        if (!radiusIoStatus || keepRadiusImportSummaryVisible) {
            return;
        }

        if (!radiusIoDeviceSelect || radiusIoDeviceSelect.disabled) {
            setRadiusIoStatus('Aucun device OPNsense / RADIUS disponible pour initialiser le flux standard.');
            return;
        }

        if (!hasSelectedDevice) {
            setRadiusIoStatus('Choisissez un device OPNsense / RADIUS.');
            return;
        }

        const deviceLabel = getSelectedRadiusDeviceLabel();
        const fileLabel = hasSelectedFile ? radiusStandardFileInput.files[0].name : 'aucun fichier';
        const modeLabel = radiusImportMode ? String(radiusImportMode.value || 'skip') : 'skip';
        const sensitiveLabel = radiusIncludeSensitive && radiusIncludeSensitive.checked ? 'oui' : 'non';
        const analysisLabel = currentRadiusAnalysis
            ? ' | Source : ' + (currentRadiusAnalysis.sourceBackend || '-')
                + ' | Profils : ' + String(currentRadiusAnalysis.profileCount || 0)
                + ' | Users : ' + String(currentRadiusAnalysis.userCount || 0)
                + ' | SQL OPNsense : ' + (currentRadiusAnalysis.hasOpnsenseBusiness ? 'oui' : 'non')
                + ' | Projection Radius : ' + (currentRadiusAnalysis.hasRadiusProjection ? 'oui' : 'non')
                + ' | Blocants : ' + String(currentRadiusAnalysis.blockers.length)
                + ' | Warnings : ' + String(currentRadiusAnalysis.warnings.length)
            : '';
        setRadiusIoStatus('Device : ' + deviceLabel + ' | Fichier : ' + fileLabel + ' | Mode : ' + modeLabel + ' | Sensibles : ' + sensitiveLabel + analysisLabel + '.');
    };

    const analyzeSelectedRadiusStandardFile = async () => {
        if (!radiusStandardFileInput || !radiusStandardFileInput.files || !radiusStandardFileInput.files[0]) {
            currentRadiusAnalysis = null;
            renderRadiusRiskState();
            syncRadiusIoUi();
            return;
        }

        try {
            const file = radiusStandardFileInput.files[0];
            const text = await file.text();
            const payload = readJsonFromText(text);
            currentRadiusAnalysis = analyzeRadiusStandardDocument(payload);
        } catch (error) {
            currentRadiusAnalysis = {
                blockers: ['Lecture ou analyse du fichier impossible.'],
                warnings: [],
                sourceBackend: '',
                profileCount: 0,
                userCount: 0,
                hasOpnsenseBusiness: false,
                hasRadiusProjection: false,
            };
        }
        renderRadiusRiskState();
        syncRadiusIoUi();
    };

    const formatImportSummary = (data) => {
        const profileSummary = data && data.profiles ? data.profiles : {};
        const userSummary = data && data.users ? data.users : {};

        const profileErrorItems = importErrorsFromSummary(profileSummary);
        const userErrorItems = importErrorsFromSummary(userSummary);
        const profileErrors = profileErrorItems.length;
        const userErrors = userErrorItems.length;
        const totalErrors = profileErrors + userErrors;
        const profileCreated = Number(profileSummary.created || 0);
        const profileUpdated = Number(profileSummary.updated || 0);
        const userCreated = Number(userSummary.created || 0);
        const userUpdated = Number(userSummary.updated || 0);
        const totalCreated = profileCreated + userCreated;
        const totalUpdated = profileUpdated + userUpdated;
        const totalSkipped = Number(profileSummary.skipped || 0)
            + Number(userSummary.skipped || 0)
            + Number(userSummary.sensitive_skipped || 0)
            + Number(userSummary.invalid_skipped || 0);
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

        const resultLabel = totalErrors > 0
            ? 'Import standard partiel : des erreurs doivent etre corrigees.'
            : (totalCreated > 0
                ? (totalSkipped > 0
                    ? 'Import standard termine : creations confirmees, certains elements ignores.'
                    : 'Import standard termine : creations et mises a jour confirmees.')
                : (totalUpdated > 0
                    ? (totalSkipped > 0
                        ? 'Import standard termine : elements mis a jour, certains elements ignores.'
                        : 'Import standard termine : aucune creation, elements existants mis a jour.')
                    : (totalSkipped > 0
                        ? 'Import standard termine : aucune ecriture, elements ignores.'
                        : 'Import standard termine : aucune ecriture confirmee.')));

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
            formatImportErrorPreviewText('Profils', profileErrorItems),
            formatImportErrorPreviewText('Utilisateurs', userErrorItems),
            Number(profileSummary.existing_skipped || 0) > 0
                ? 'Profils ignores: ' + String(profileSummary.existing_skipped) + ' existants en mode skip.'
                : null,
            Number(userSummary.existing_skipped || 0) > 0
                ? 'Utilisateurs ignores: ' + String(userSummary.existing_skipped) + ' existants en mode skip.'
                : null,
            Number(userSummary.invalid_missing_profile || 0) > 0
                ? 'Invalides: ' + String(userSummary.invalid_missing_profile) + ' profil absent.'
                : null,
            Number(userSummary.invalid_masked_password || 0) > 0
                ? 'Invalides: ' + String(userSummary.invalid_masked_password) + ' mot de passe masque sans utilisateur existant.'
                : null,
        ];

        return parts.filter(Boolean).join(' ');
    };

    const analyzeSelectedStandardFile = async () => {
        keepMikrotikImportSummaryVisible = false;
        if (!mikrotikStandardFileInput || !mikrotikStandardFileInput.files || !mikrotikStandardFileInput.files[0]) {
            currentRiskState = { blockers: [], warnings: [], sourcePools: [], profileCount: 0, userCount: 0 };
            mikrotikRiskAnalyzed = false;
            resetMikrotikPoolMapState();
            renderRiskState();
            renderMikrotikPoolMapState();
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
                sourcePools: [],
                profileCount: 0,
                userCount: 0,
            };
            mikrotikRiskAnalyzed = true;
        }
        resetMikrotikPoolMapState();
        renderRiskState();
        renderMikrotikPoolMapState();
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
                currentRiskState = { blockers: [], warnings: [], sourcePools: [], profileCount: 0, userCount: 0 };
                mikrotikRiskAnalyzed = false;
                resetMikrotikPoolMapState();
                renderRiskState();
                renderMikrotikPoolMapState();
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
            resetMikrotikPoolMapState();
            renderMikrotikPoolMapState();
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
            let targetValidation = null;

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
            resetMikrotikPoolMapState();
            renderMikrotikPoolMapState();

            try {
                startBtn.disabled = true;
                setMikrotikIoStatus('Validation du routeur cible et lecture des address-pool...');
                targetValidation = await validateMikrotikImportTarget(deviceId);
                const targetPools = Array.isArray(targetValidation.address_pools)
                    ? targetValidation.address_pools.map((pool) => String(pool || '').trim()).filter((pool) => pool !== '')
                    : [];
                const targetLookup = new Set(targetPools.map((pool) => pool.toLowerCase()));
                const sourcePools = Array.isArray(currentRiskState.sourcePools) ? currentRiskState.sourcePools : [];
                const missingPools = sourcePools.filter((pool) => !targetLookup.has(String(pool).toLowerCase()));
                currentMikrotikPoolMapState = {
                    sourcePools,
                    targetPools,
                    missingPools,
                    required: missingPools.length > 0,
                };
                renderMikrotikPoolMapState();
                setMikrotikIoStatus('Routeur cible valide. Pools source a aligner : ' + String(missingPools.length) + '.');
            } catch (error) {
                targetValidation = null;
                setMikrotikIoStatus(error.message || 'Routeur MikroTik cible non joignable.');
            } finally {
                startBtn.disabled = targetValidation === null;
            }

            startBtn.onclick = async () => {
                const hasBlockers = currentRiskState.blockers.length > 0;
                const requiresWarningConfirm = currentRiskState.warnings.length > 0;
                const warningsConfirmed = !!(warningsConfirm && warningsConfirm.checked);
                const includeSensitive = !!(mikrotikIncludeSensitive && mikrotikIncludeSensitive.checked);
                let poolMap = {};

                if (hasBlockers) {
                    AppToast.flash('Import bloqué : corrigez d\'abord les blocants.', 'danger');
                    return;
                }

                if (requiresWarningConfirm && !warningsConfirmed) {
                    AppToast.flash('Confirmez les warnings avant import.', 'warning');
                    return;
                }

                if (targetValidation === null) {
                    AppToast.flash('Routeur cible non valide : relancez la validation avant import.', 'warning');
                    return;
                }

                try {
                    poolMap = gatherMikrotikPoolMap();
                } catch (error) {
                    AppToast.flash(error.message || 'Alignement address-pool invalide.', 'danger');
                    return;
                }

                await performMikrotikImport(deviceId, deviceLabel, mode, includeSensitive, file, modal, {
                    setStatus: setMikrotikIoStatus,
                    formatSummary: formatImportSummary,
                    syncUi: syncMikrotikIoUi,
                    poolMap,
                    targetValidation,
                    profileCount: currentRiskState.profileCount || 0,
                    userCount: currentRiskState.userCount || 0,
                    markSummaryVisible: () => {
                        keepMikrotikImportSummaryVisible = true;
                    },
                });
            };
        });
    }

    if (radiusStandardFileInput && chooseRadiusStandardFileBtn && selectedRadiusStandardFileName) {
        chooseRadiusStandardFileBtn.addEventListener('click', () => {
            if (chooseRadiusStandardFileBtn.disabled) {
                return;
            }
            radiusStandardFileInput.click();
        });

        radiusStandardFileInput.addEventListener('change', async () => {
            keepRadiusImportSummaryVisible = false;
            selectedRadiusStandardFileName.textContent =
                radiusStandardFileInput.files && radiusStandardFileInput.files[0]
                    ? radiusStandardFileInput.files[0].name
                    : 'Aucun fichier';
            await analyzeSelectedRadiusStandardFile();
        });
    }

    if (radiusIoDeviceSelect) {
        radiusIoDeviceSelect.addEventListener('change', () => {
            keepRadiusImportSummaryVisible = false;
            syncRadiusIoUi();
        });
    }

    if (radiusImportMode) {
        radiusImportMode.addEventListener('change', () => {
            keepRadiusImportSummaryVisible = false;
            syncRadiusIoUi();
        });
    }

    if (radiusIncludeSensitive) {
        radiusIncludeSensitive.addEventListener('change', () => {
            keepRadiusImportSummaryVisible = false;
            syncRadiusIoUi();
        });
    }

    if (radiusWarningsConfirm) {
        radiusWarningsConfirm.addEventListener('change', () => {
            syncRadiusIoUi();
        });
    }

    if (exportRadiusStandardBtn) {
        exportRadiusStandardBtn.addEventListener('click', async () => {
            keepRadiusImportSummaryVisible = false;
            const deviceId = radiusIoDeviceSelect ? String(radiusIoDeviceSelect.value || '').trim() : '';
            const deviceLabel = getSelectedRadiusDeviceLabel();

            if (deviceId === '') {
                setRadiusIoStatus('Choisissez un device OPNsense / RADIUS avant export.');
                return;
            }

            exportRadiusStandardBtn.disabled = true;
            setRadiusIoStatus('Export standard OPNsense / RADIUS en cours pour ' + deviceLabel + '...');

            try {
                const response = await fetch('/api/admin/radius_export_standard.php', {
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
                    throw new Error(errorPayload && errorPayload.message ? errorPayload.message : 'Export OPNsense / RADIUS impossible.');
                }

                const blob = await response.blob();
                const fileName = administrationExtractDownloadFilename(
                    response.headers.get('content-disposition'),
                    'radius_standard_v2.json'
                );
                administrationDownloadBlob(blob, fileName);
                keepRadiusImportSummaryVisible = true;
                setRadiusIoStatus('Export standard termine pour ' + deviceLabel + ' : ' + fileName);
            } catch (error) {
                keepRadiusImportSummaryVisible = true;
                setRadiusIoStatus(error.message || 'Export OPNsense / RADIUS impossible.');
            } finally {
                syncRadiusIoUi();
            }
        });
    }

    if (importRadiusStandardBtn) {
        importRadiusStandardBtn.addEventListener('click', async () => {
            keepRadiusImportSummaryVisible = false;
            const deviceId = radiusIoDeviceSelect ? String(radiusIoDeviceSelect.value || '').trim() : '';
            const deviceLabel = getSelectedRadiusDeviceLabel();
            const file = radiusStandardFileInput && radiusStandardFileInput.files
                ? radiusStandardFileInput.files[0]
                : null;
            const mode = radiusImportMode ? String(radiusImportMode.value || 'skip').trim() : 'skip';
            const includeSensitive = !!(radiusIncludeSensitive && radiusIncludeSensitive.checked);

            if (deviceId === '') {
                setRadiusIoStatus('Choisissez un device cible avant import.');
                return;
            }

            if (!file) {
                setRadiusIoStatus('Choisissez un fichier JSON standard avant import.');
                return;
            }

            await analyzeSelectedRadiusStandardFile();
            renderRadiusRiskState();

            const modal = new bootstrap.Modal(document.getElementById('radiusImportModal'));
            modal.show();

            const startBtn = document.getElementById('radiusImportStartBtn');
            const confirmBtn = document.getElementById('radiusImportConfirmBtn');
            document.getElementById('radiusImportPreAnalysis').classList.remove('d-none');
            document.getElementById('radiusImportProcess').classList.add('d-none');
            document.getElementById('radiusImportSummary').classList.add('d-none');
            document.getElementById('radiusImportProgress').style.width = '0%';
            document.getElementById('radiusImportProgress').classList.remove('bg-danger');
            document.getElementById('radiusImportProgress').textContent = '';
            startBtn.classList.remove('d-none');
            startBtn.disabled = false;
            confirmBtn.classList.add('d-none');

            startBtn.onclick = async () => {
                const analysis = currentRadiusAnalysis || {
                    blockers: ['Analyse du fichier indisponible.'],
                    warnings: [],
                    profileCount: 0,
                    userCount: 0,
                    sourceBackend: '',
                };
                const hasBlockers = analysis.blockers.length > 0;
                const requiresWarningConfirm = analysis.warnings.length > 0;
                const warningsConfirmed = !!(radiusWarningsConfirm && radiusWarningsConfirm.checked);

                if (hasBlockers) {
                    AppToast.flash('Import bloqué : corrigez d abord les blocants.', 'danger');
                    return;
                }
                if (requiresWarningConfirm && !warningsConfirmed) {
                    AppToast.flash('Confirmez les warnings avant import.', 'warning');
                    return;
                }

                await performRadiusImport(deviceId, deviceLabel, mode, includeSensitive, file, modal, {
                    setStatus: setRadiusIoStatus,
                    formatSummary: formatImportSummary,
                    syncUi: syncRadiusIoUi,
                    analysis,
                    markSummaryVisible: () => {
                        keepRadiusImportSummaryVisible = true;
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
    renderRadiusRiskState();
    syncMikrotikIoUi();
    syncRadiusIoUi();
});

async function performMikrotikImport(deviceId, deviceLabel, mode, includeSensitive, file, modal, callbacks) {
    const processSection = document.getElementById('mikrotikImportProcess');
    const summarySection = document.getElementById('mikrotikImportSummary');
    const statusDiv = document.getElementById('mikrotikImportStatus');
    const progressBar = document.getElementById('mikrotikImportProgress');
    const stepsList = document.getElementById('mikrotikImportSteps');
    const etaBox = document.getElementById('mikrotikImportEta');
    const startBtn = document.getElementById('mikrotikImportStartBtn');
    const confirmBtn = document.getElementById('mikrotikImportConfirmBtn');
    const profileCount = Number(callbacks && callbacks.profileCount ? callbacks.profileCount : 0);
    const userCount = Number(callbacks && callbacks.userCount ? callbacks.userCount : 0);
    const progress = createMikrotikImportProgressController(
        progressBar,
        stepsList,
        etaBox,
        estimateMikrotikImportDurationMs(profileCount, userCount)
    );

    // Masquer pré-analyse, afficher processus
    document.getElementById('mikrotikImportPreAnalysis').classList.add('d-none');
    processSection.classList.remove('d-none');
    summarySection.classList.add('d-none');

    startBtn.classList.add('d-none');
    confirmBtn.classList.add('d-none');

    try {
        progress.start();

        // Étape 1: Validation du routeur
        statusDiv.className = 'alert alert-primary';
        statusDiv.innerHTML = '<i class="fa fa-spinner fa-spin me-2"></i>Validation du routeur MikroTik cible...';
        progress.set(8, 'Validation du routeur cible');

        const validation = callbacks && callbacks.targetValidation
            ? callbacks.targetValidation
            : await validateMikrotikImportTarget(deviceId);
        const routerIdentity = String(validation && validation.router_identity ? validation.router_identity : '').trim();

        statusDiv.innerHTML = `<i class="fa fa-check-circle me-2"></i>Routeur cible valide${routerIdentity !== '' ? ' (' + administrationEscapeHtml(routerIdentity) + ')' : ''}.`;
        progress.set(18, 'Préparation du fichier standard');

        // Étape 2: Import en cours
        statusDiv.className = 'alert alert-primary';
        statusDiv.innerHTML = '<i class="fa fa-cog fa-spin me-2"></i>Import de '
            + String(profileCount) + ' profil(s) et ' + String(userCount)
            + ' utilisateur(s) vers ' + administrationEscapeHtml(deviceLabel) + '...';
        progress.set(30, 'Validation des profils et address-pool');

        const formData = new FormData();
        formData.append('csrf_token', administrationCsrfToken);
        formData.append('device_id', deviceId);
        formData.append('mode', mode);
        formData.append('include_sensitive', includeSensitive ? '1' : '0');
        formData.append('pool_map', JSON.stringify(callbacks && callbacks.poolMap ? callbacks.poolMap : {}));
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

        const profileSummary = data.profiles || {};
        const userSummary = data.users || {};
        const profileErrors = importErrorsFromSummary(profileSummary);
        const userErrors = importErrorsFromSummary(userSummary);
        const hasImportErrors = (profileErrors.length + userErrors.length) > 0;

        // Étape 3: Import terminé
        progress.complete(hasImportErrors);
        statusDiv.className = hasImportErrors ? 'alert alert-warning' : 'alert alert-success';
        statusDiv.innerHTML = hasImportErrors
            ? '<i class="fa fa-exclamation-triangle me-2"></i>Import terminé avec erreurs. Vérifiez le résumé.'
            : '<i class="fa fa-check-circle me-2"></i>Import terminé avec succès !';

        // Afficher le résumé détaillé
        setTimeout(() => {
            showMikrotikImportSummary(data, callbacks);
        }, 1000);

    } catch (error) {
        progress.fail(error.message || 'Import standard impossible.');
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
    const totalSkipped = (profileSummary.skipped || 0)
        + (userSummary.skipped || 0)
        + (userSummary.sensitive_skipped || 0)
        + (userSummary.invalid_skipped || 0);
    const profileErrors = importErrorsFromSummary(profileSummary);
    const userErrors = importErrorsFromSummary(userSummary);
    const hasImportErrors = (profileErrors.length + userErrors.length) > 0;

    const resultLabel = hasImportErrors
        ? 'Import standard partiel : des erreurs doivent être corrigées.'
        : (totalCreated > 0
            ? 'Import standard terminé : créations et mises à jour confirmées.'
            : (totalUpdated > 0
            ? 'Import standard terminé : aucune création, éléments existants mis à jour.'
                : 'Import standard terminé : aucune écriture confirmée.'));

    resultDiv.className = hasImportErrors ? 'alert alert-warning' : 'alert alert-success';
    resultDiv.innerHTML = (hasImportErrors ? '<i class="fa fa-exclamation-triangle me-2"></i>' : '<i class="fa fa-check-circle me-2"></i>')
        + administrationEscapeHtml(resultLabel);

    // Détails profils
    profilesSummary.innerHTML = `
        Créés: ${profileSummary.created || 0}<br>
        Mis à jour: ${profileSummary.updated || 0}<br>
        Protégés: ${profileSummary.protected || 0}<br>
        Erreurs: ${profileErrors.length}${formatImportErrorsHtml(profileErrors)}
    `;

    // Détails utilisateurs
    usersSummary.innerHTML = `
        Créés: ${userSummary.created || 0}<br>
        Mis à jour: ${userSummary.updated || 0}<br>
        Sensibles ignorés: ${userSummary.sensitive_skipped || 0}<br>
        Invalides ignorés: ${userSummary.invalid_skipped || 0}<br>
        Erreurs: ${userErrors.length}${formatImportErrorsHtml(userErrors)}
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

async function performRadiusImport(deviceId, deviceLabel, mode, includeSensitive, file, modal, callbacks) {
    const processSection = document.getElementById('radiusImportProcess');
    const summarySection = document.getElementById('radiusImportSummary');
    const statusDiv = document.getElementById('radiusImportStatus');
    const progressBar = document.getElementById('radiusImportProgress');
    const stepsList = document.getElementById('radiusImportSteps');
    const etaBox = document.getElementById('radiusImportEta');
    const startBtn = document.getElementById('radiusImportStartBtn');
    const confirmBtn = document.getElementById('radiusImportConfirmBtn');
    const analysis = callbacks && callbacks.analysis ? callbacks.analysis : {};
    const profileCount = Number(analysis.profileCount || 0);
    const userCount = Number(analysis.userCount || 0);
    const sourceBackend = String(analysis.sourceBackend || '').trim().toLowerCase();
    const sourceLabel = sourceBackend === 'opnsense'
        ? 'SQL métier OPNsense + projection Radius'
        : (sourceBackend === 'mikrotik'
            ? 'migration depuis champs standards MikroTik'
            : 'projection FreeRADIUS');
    const steps = [
        { at: 5, label: 'Validation de la cible OPNsense / RADIUS' },
        { at: 14, label: 'Préparation du fichier standard' },
        { at: 30, label: 'Validation de la source ' + (sourceBackend || 'standard') },
        { at: 54, label: sourceBackend === 'opnsense' ? 'Écriture base métier SQL' : 'Conversion vers FreeRADIUS' },
        { at: 82, label: 'Projection / écriture Radius' },
        { at: 94, label: 'Finalisation et résumé' },
    ];
    const progress = createMikrotikImportProgressController(
        progressBar,
        stepsList,
        etaBox,
        estimateMikrotikImportDurationMs(profileCount, userCount),
        steps
    );

    document.getElementById('radiusImportPreAnalysis').classList.add('d-none');
    processSection.classList.remove('d-none');
    summarySection.classList.add('d-none');
    startBtn.classList.add('d-none');
    confirmBtn.classList.add('d-none');

    try {
        progress.start();
        statusDiv.className = 'alert alert-primary';
        statusDiv.innerHTML = '<i class="fa fa-spinner fa-spin me-2"></i>Validation de la cible OPNsense / RADIUS...';
        progress.set(8, 'Validation de la cible OPNsense / RADIUS');

        statusDiv.innerHTML = '<i class="fa fa-cog fa-spin me-2"></i>Import de '
            + String(profileCount) + ' profil(s) et ' + String(userCount)
            + ' utilisateur(s) vers ' + administrationEscapeHtml(deviceLabel)
            + ' | Source : ' + administrationEscapeHtml(sourceLabel) + '...';
        progress.set(30, 'Validation de la source ' + (sourceBackend || 'standard'));

        const formData = new FormData();
        formData.append('csrf_token', administrationCsrfToken);
        formData.append('device_id', deviceId);
        formData.append('mode', mode);
        formData.append('include_sensitive', includeSensitive ? '1' : '0');
        formData.append('standard_file', file);

        const response = await fetch('/api/admin/radius_import_standard.php', {
            method: 'POST',
            headers: {
                Accept: 'application/json',
            },
            body: formData,
        });

        const data = await readAdministrationJsonResponse(response);
        if (!response.ok || !data || data.success !== true) {
            throw new Error(data && data.message ? data.message : 'Import OPNsense / RADIUS impossible.');
        }

        const profileSummary = data.profiles || {};
        const userSummary = data.users || {};
        const profileErrors = importErrorsFromSummary(profileSummary);
        const userErrors = importErrorsFromSummary(userSummary);
        const hasImportErrors = (profileErrors.length + userErrors.length) > 0;

        progress.complete(hasImportErrors);
        statusDiv.className = hasImportErrors ? 'alert alert-warning' : 'alert alert-success';
        statusDiv.innerHTML = hasImportErrors
            ? '<i class="fa fa-exclamation-triangle me-2"></i>Import terminé avec erreurs. Vérifiez le résumé.'
            : '<i class="fa fa-check-circle me-2"></i>Import terminé avec succès !';

        setTimeout(() => {
            showRadiusImportSummary(data, callbacks);
        }, 1000);
    } catch (error) {
        if (callbacks && typeof callbacks.setStatus === 'function') {
            callbacks.setStatus(error.message || 'Import OPNsense / RADIUS impossible.');
        }
        progress.fail(error.message || 'Import OPNsense / RADIUS impossible.');
        progressBar.classList.add('bg-danger');
        statusDiv.className = 'alert alert-danger';
        statusDiv.innerHTML = '<i class="fa fa-exclamation-triangle me-2"></i>' + administrationEscapeHtml(error.message || 'Import OPNsense / RADIUS impossible.');
    }
}

function showRadiusImportSummary(data, callbacks) {
    const processSection = document.getElementById('radiusImportProcess');
    const summarySection = document.getElementById('radiusImportSummary');
    const resultDiv = document.getElementById('radiusImportResult');
    const profilesSummary = document.getElementById('radiusImportProfilesSummary');
    const usersSummary = document.getElementById('radiusImportUsersSummary');
    const confirmBtn = document.getElementById('radiusImportConfirmBtn');

    processSection.classList.add('d-none');
    summarySection.classList.remove('d-none');

    const profileSummary = data.profiles || {};
    const userSummary = data.users || {};
    const totalCreated = (profileSummary.created || 0) + (userSummary.created || 0);
    const totalUpdated = (profileSummary.updated || 0) + (userSummary.updated || 0);
    const profileErrors = importErrorsFromSummary(profileSummary);
    const userErrors = importErrorsFromSummary(userSummary);
    const hasImportErrors = (profileErrors.length + userErrors.length) > 0;
    const resultLabel = hasImportErrors
        ? 'Import standard partiel : des erreurs doivent être corrigées.'
        : (totalCreated > 0
            ? (totalSkipped > 0
                ? 'Import standard terminé : créations confirmées, certains éléments ignorés.'
                : 'Import standard terminé : créations et mises à jour confirmées.')
            : (totalUpdated > 0
                ? (totalSkipped > 0
                    ? 'Import standard terminé : éléments mis à jour, certains éléments ignorés.'
                    : 'Import standard terminé : aucune création, éléments existants mis à jour.')
                : (totalSkipped > 0
                    ? 'Import standard terminé : aucune écriture, éléments ignorés.'
                    : 'Import standard terminé : aucune écriture confirmée.')));

    resultDiv.className = hasImportErrors ? 'alert alert-warning' : 'alert alert-success';
    resultDiv.innerHTML = (hasImportErrors ? '<i class="fa fa-exclamation-triangle me-2"></i>' : '<i class="fa fa-check-circle me-2"></i>')
        + administrationEscapeHtml(resultLabel);

    profilesSummary.innerHTML = `
        Créés: ${profileSummary.created || 0}<br>
        Mis à jour: ${profileSummary.updated || 0}<br>
        Protégés: ${profileSummary.protected || 0}<br>
        Ignorés: ${profileSummary.skipped || 0}${formatSkipCausesHtml(profileSummary, 'profiles')}<br>
        Erreurs: ${profileErrors.length}${formatImportErrorsHtml(profileErrors)}
    `;

    usersSummary.innerHTML = `
        Créés: ${userSummary.created || 0}<br>
        Mis à jour: ${userSummary.updated || 0}<br>
        Ignorés: ${userSummary.skipped || 0}${formatSkipCausesHtml(userSummary, 'users')}<br>
        Sensibles ignorés: ${userSummary.sensitive_skipped || 0}<br>
        Invalides ignorés: ${userSummary.invalid_skipped || 0}<br>
        Erreurs: ${userErrors.length}${formatImportErrorsHtml(userErrors)}
    `;

    confirmBtn.classList.remove('d-none');
    confirmBtn.onclick = () => {
        const modal = bootstrap.Modal.getInstance(document.getElementById('radiusImportModal'));
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
