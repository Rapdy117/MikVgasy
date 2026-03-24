const select = document.getElementById('statusSelect');
const badge = document.getElementById('statusPreview');

function updateBadge() {
    if (!select || !badge) return; // ✅ sécurité

    const val = select.value;
    badge.className = 'status-badge';

    if (val === 'active') {
        badge.classList.add('online');
        badge.innerText = 'Actif';
    } else if (val === 'disabled') {
        badge.classList.add('offline');
        badge.innerText = 'Désactivé';
    } else {
        badge.classList.add('checking');
        badge.innerText = 'Expiré';
    }
}

if (select) {
    select.addEventListener('change', updateBadge);
    updateBadge();
}

select.addEventListener('change', updateBadge);
updateBadge();

/* ================= AJAX SUBMIT ================= */

document.getElementById('userForm').addEventListener('submit', function(e) {
    e.preventDefault();

    const form = e.target;
    const formData = new FormData(form);

    fetch('../api/create_user.php', {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            alert('✅ ' + data.message);
            form.reset();
        } else {
            alert('❌ ' + data.message);
        }
    })
    .catch(err => {
        console.error(err);
        alert('Erreur serveur');
    });
});