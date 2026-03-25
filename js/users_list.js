// =========================
// CLICK USER
// =========================
let selectedRow = null;
let selectedUserId = null;
let nasList = [];

function resolveNasIdFromAddress(address) {
    if (!address) {
        return nasList[0]?.id ?? '';
    }

    const match = nasList.find(nas => nas.nasname === address);
    return match ? match.id : (nasList[0]?.id ?? '');
}

function fillUserDataFromRow(row) {
    document.getElementById('username').value = row.dataset.username;
    document.getElementById('fullname').value = row.dataset.fullname;
    document.getElementById('phone').value = row.dataset.phone;
    document.getElementById('address').value = row.dataset.address;
    document.getElementById('email').value = row.dataset.email;
    document.getElementById('account_type').value = row.dataset.account_type || "-";
    document.getElementById('service').value = row.dataset.service;
    document.getElementById('plan').value = row.dataset.plan;
    document.getElementById('expiration').value = row.dataset.expiration;
    document.getElementById('status').value = row.dataset.status;
    document.getElementById('balance').value = row.dataset.balance;
    document.getElementById('created_at').value = row.dataset.created_at;
    document.getElementById('last_login').value = row.dataset.last_login;
    document.getElementById('auto_renewal').value = row.dataset.auto_renewal || '0';
    document.getElementById('profile_id').value = row.dataset.profile_id || '';
    document.getElementById('rate_limit').value = row.dataset.rate_limit || '';
    document.getElementById('session_timeout').value = row.dataset.session_timeout || '0';
    document.getElementById('idle_timeout').value = row.dataset.idle_timeout || '0';
    document.getElementById('simultaneous_use').value = row.dataset.simultaneous_use || '0';
    document.getElementById('data_limit').value = row.dataset.data_limit || '0';
    document.getElementById('nas_id').value = nasList[0]?.id ?? '';
}

function getSelectedProfileOption() {
    const select = document.getElementById('profile_id');
    if (!select) {
        return null;
    }

    return select.options[select.selectedIndex] ?? null;
}

fetch('../api/nas.php')
    .then(res => res.json())
    .then(data => {
        if (data.success && Array.isArray(data.data)) {
            nasList = data.data;
        }
    })
    .catch(err => {
        console.error('Erreur NAS:', err);
    });

document.querySelectorAll('.user-row').forEach(row => {
    row.addEventListener('click', function () {

        selectedRow = this;
        selectedUserId = this.dataset.id || null;

        // highlight
        document.querySelectorAll('.user-row').forEach(r => r.classList.remove('table-active'));
        selectedRow.classList.add('table-active');

        // show panel
        document.getElementById('emptyState').classList.add('d-none');
        document.getElementById('userContent').classList.remove('d-none');
        document.getElementById('user_id').value = selectedUserId || '';

        fillUserDataFromRow(selectedRow);

        // =========================
        // LOAD SESSIONS (API)
        // =========================
        loadSessions(selectedRow.dataset.username);

        disableEditMode();
    });
});


// =========================
// LOAD SESSIONS
// =========================
function loadSessions(username) {

    fetch(`../api/users/get_user_sessions.php?username=${encodeURIComponent(username)}`)
    .then(res => res.json())
    .then(data => {

        let html = "";
        let total = 0;

        if (!data.sessions || data.sessions.length === 0) {
            html = `<tr><td colspan="5" class="text-center">Aucune session</td></tr>`;
        } else {
            data.sessions.forEach(s => {

                total += parseFloat(s.data_mb || 0);

                html += `
                <tr>
                    <td>${s.start ?? '-'}</td>
                    <td>${s.stop ?? 'En cours'}</td>
                    <td>${s.duration ?? 0}s</td>
                    <td>${s.data_mb} MB</td>
                    <td>${s.ip ?? '-'}</td>
                </tr>`;
            });
        }

        // inject table
        document.getElementById("sessionsTable").innerHTML = html;

        // total usage
        document.getElementById("data_usage").value = total.toFixed(2) + " MB";

        // network
        document.getElementById("ip").value = data.ip ?? "-";
        document.getElementById("mac").value = data.mac ?? "-";
        document.getElementById("nas").value = data.nas ?? "-";
        document.getElementById("online").value = data.online ? "Oui" : "Non";
        document.getElementById("nas_id").value = resolveNasIdFromAddress(data.nas ?? '');

    })
    .catch(err => {
        console.error("Erreur sessions:", err);
    });
}


// =========================
// EDIT MODE
// =========================
const editBtn = document.getElementById('editBtn');
const saveBtn = document.getElementById('saveBtn');
const cancelBtn = document.getElementById('cancelBtn');
const deleteBtn = document.getElementById('deleteBtn');

function enableEditMode() {
    if (!selectedUserId) {
        alert("Aucun utilisateur sélectionné");
        return;
    }

    document.querySelectorAll('.editable').forEach(el => {
        el.disabled = false;
        el.readOnly = false;
        el.classList.add('editable-active');
    });
    editBtn.classList.add('d-none');
    saveBtn.classList.remove('d-none');
    cancelBtn.classList.remove('d-none');

    const firstEditable = document.querySelector('#userContent .editable');
    if (firstEditable) {
        firstEditable.focus();
    }
}

function disableEditMode() {
    document.querySelectorAll('.editable').forEach(el => {
        el.disabled = true;
        el.readOnly = true;
        el.classList.remove('editable-active');
    });
    editBtn.classList.remove('d-none');
    saveBtn.classList.add('d-none');
    cancelBtn.classList.add('d-none');
}

editBtn.addEventListener('click', enableEditMode);
cancelBtn.addEventListener('click', () => {
    if (selectedRow) {
        fillUserDataFromRow(selectedRow);
        loadSessions(selectedRow.dataset.username);
    }
    disableEditMode();
});

saveBtn.addEventListener('click', () => {
    if (!selectedUserId || !selectedRow) {
        alert("Aucun utilisateur sélectionné");
        return;
    }

    const form = document.getElementById('userContent');
    const formData = new FormData(form);
    formData.set('id', selectedUserId);

    if (!formData.get('nas_id')) {
        formData.set('nas_id', resolveNasIdFromAddress(document.getElementById('nas').value));
    }

    fetch('../api/users/update_user.php', {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        if (!data.success) {
            alert(data.message || "Mise à jour impossible");
            return;
        }

        selectedRow.dataset.username = document.getElementById('username').value;
        selectedRow.dataset.fullname = document.getElementById('fullname').value;
        selectedRow.dataset.phone = document.getElementById('phone').value;
        selectedRow.dataset.address = document.getElementById('address').value;
        selectedRow.dataset.email = document.getElementById('email').value;
        selectedRow.dataset.expiration = document.getElementById('expiration').value;
        selectedRow.dataset.status = document.getElementById('status').value;
        selectedRow.dataset.balance = document.getElementById('balance').value;
        selectedRow.dataset.auto_renewal = document.getElementById('auto_renewal').value;

        const selectedProfile = getSelectedProfileOption();
        if (selectedProfile && selectedProfile.value) {
            selectedRow.dataset.profile_id = selectedProfile.value;
            selectedRow.dataset.plan = selectedProfile.dataset.plan || selectedProfile.textContent.trim();
            selectedRow.dataset.service = selectedProfile.dataset.service || '';
            selectedRow.dataset.rate_limit = selectedProfile.dataset.rate_limit || '';
            selectedRow.dataset.session_timeout = selectedProfile.dataset.session_timeout || '0';
            selectedRow.dataset.idle_timeout = selectedProfile.dataset.idle_timeout || '0';
            selectedRow.dataset.simultaneous_use = selectedProfile.dataset.simultaneous_use || '0';
            selectedRow.dataset.data_limit = selectedProfile.dataset.data_limit || '0';
            selectedRow.dataset.account_type = selectedProfile.dataset.account_type || '';
            document.getElementById('service').value = selectedRow.dataset.service;
            document.getElementById('account_type').value = selectedRow.dataset.account_type || '-';
        }

        const cells = selectedRow.querySelectorAll('td');
        if (cells[1]) cells[1].textContent = document.getElementById('username').value;
        if (cells[2]) cells[2].textContent = document.getElementById('phone').value || '-';
        if (cells[3]) {
            const planBadge = cells[3].querySelector('.badge');
            if (planBadge) {
                planBadge.textContent = selectedRow.dataset.plan || '-';
            }
        }
        if (cells[4]) cells[4].textContent = selectedRow.dataset.service || '-';
        if (cells[5]) cells[5].textContent = document.getElementById('expiration').value || '-';
        if (cells[6]) cells[6].textContent = `${Number(document.getElementById('balance').value || 0).toLocaleString('fr-FR')} Ar`;
        if (cells[7]) {
            const badge = cells[7].querySelector('.badge');
            if (badge) {
                const statusValue = document.getElementById('status').value;
                badge.textContent = statusValue;
                badge.className = `badge ${statusValue === 'active' ? 'bg-success' : 'bg-warning'}`;
            }
        }

        loadSessions(document.getElementById('username').value);
        disableEditMode();
        alert(data.message || "Utilisateur mis à jour");
    })
    .catch(err => {
        console.error("Erreur update:", err);
        alert("Erreur serveur");
    });
});

deleteBtn.addEventListener('click', () => {
    if (!selectedUserId) {
        alert("Aucun utilisateur sélectionné");
        return;
    }

    if (!confirm("Supprimer cet utilisateur ?")) {
        return;
    }

    const formData = new FormData();
    formData.append('id', selectedUserId);

    fetch('../api/users/delete_user.php', {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        if (!data.success) {
            alert(data.message || "Suppression impossible");
            return;
        }

        if (selectedRow) {
            selectedRow.remove();
        }

        selectedRow = null;
        selectedUserId = null;
        document.getElementById('user_id').value = '';
        document.getElementById('userContent').classList.add('d-none');
        document.getElementById('emptyState').classList.remove('d-none');
        document.getElementById('sessionsTable').innerHTML = '<tr><td colspan="5" class="text-center">Aucune session</td></tr>';
        document.getElementById('data_usage').value = '';
        document.getElementById('ip').value = '';
        document.getElementById('mac').value = '';
        document.getElementById('nas').value = '';
        document.getElementById('online').value = '';
    })
    .catch(err => {
        console.error("Erreur suppression:", err);
        alert("Erreur serveur");
    });
});
