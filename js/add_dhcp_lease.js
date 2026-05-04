document.addEventListener('DOMContentLoaded', () => {
    const form = document.getElementById('addDhcpLeaseForm');
    const messageArea = document.getElementById('messageArea');
    const saveBtn = document.getElementById('saveBtn');

    if (!form) {
        return;
    }

    const showMessage = (message, type = 'success') => {
        if (!messageArea) {
            return;
        }

        messageArea.innerHTML = `<div class="alert alert-${type} py-2 px-3 mb-3" role="alert">${message}</div>`;
        messageArea.style.display = 'block';
    };

    form.addEventListener('submit', async (event) => {
        event.preventDefault();

        saveBtn.disabled = true;

        try {
            const response = await fetch('../api/create_mikrotik_dhcp_lease.php', {
                method: 'POST',
                body: new FormData(form),
            });

            const data = await response.json();
            if (!response.ok || !data.success) {
                throw new Error(data.message || 'Création impossible');
            }

            showMessage(data.message || 'Bail DHCP ajouté.', 'success');
            window.setTimeout(() => {
                window.location.href = 'dhcp_leases.php';
            }, 700);
        } catch (error) {
            showMessage(error.message || 'Erreur de création.', 'danger');
        } finally {
            saveBtn.disabled = false;
        }
    });
});
