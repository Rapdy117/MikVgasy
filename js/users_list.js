// =========================
// CLICK USER
// =========================
document.querySelectorAll('.user-row').forEach(row => {
    row.addEventListener('click', function () {

        const selectedRow = this;

        // highlight
        document.querySelectorAll('.user-row').forEach(r => r.classList.remove('table-active'));
        selectedRow.classList.add('table-active');

        // show panel
        document.getElementById('emptyState').classList.add('d-none');
        document.getElementById('userContent').classList.remove('d-none');

        // =========================
        // FILL USER DATA
        // =========================
        document.getElementById('username').value = selectedRow.dataset.username;
        document.getElementById('fullname').value = selectedRow.dataset.fullname;
        document.getElementById('phone').value = selectedRow.dataset.phone;
        document.getElementById('address').value = selectedRow.dataset.address;
        document.getElementById('email').value = selectedRow.dataset.email;

        document.getElementById('account_type').value = selectedRow.dataset.account_type || "-";

        document.getElementById('service').value = selectedRow.dataset.service;
        document.getElementById('plan').value = selectedRow.dataset.plan;
        document.getElementById('expiration').value = selectedRow.dataset.expiration;

        document.getElementById('status').value = selectedRow.dataset.status;
        document.getElementById('balance').value = selectedRow.dataset.balance;

        document.getElementById('created_at').value = selectedRow.dataset.created_at;
        document.getElementById('last_login').value = selectedRow.dataset.last_login;
        document.getElementById('auto_renewal').value = selectedRow.dataset.auto_renewal;

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

    fetch(`/api/users/get_user_sessions.php?username=${username}`)
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

function enableEditMode() {
    document.querySelectorAll('.editable').forEach(el => el.disabled = false);
    editBtn.classList.add('d-none');
    saveBtn.classList.remove('d-none');
    cancelBtn.classList.remove('d-none');
}

function disableEditMode() {
    document.querySelectorAll('.editable').forEach(el => el.disabled = true);
    editBtn.classList.remove('d-none');
    saveBtn.classList.add('d-none');
    cancelBtn.classList.add('d-none');
}

editBtn.addEventListener('click', enableEditMode);
cancelBtn.addEventListener('click', disableEditMode);

saveBtn.addEventListener('click', () => {
    alert("Save à connecter avec update_user.php");
});