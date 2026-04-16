document.addEventListener('DOMContentLoaded', () => {
    const form = document.getElementById('addSchedulerForm');
    const deleteBtn = document.getElementById('deleteBtn');
    const messageArea = document.getElementById('messageArea');
    const config = window.schedulerEditorConfig || { mode: 'create' };

    const showMessage = (message, type = 'success') => {
        if (!messageArea) {
            return;
        }

        messageArea.style.display = '';
        messageArea.innerHTML = `
            <div class="alert alert-${type} alert-dismissible fade show" role="alert">
                ${message}
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        `;
    };

    const sendForm = async (url, formData) => {
        const response = await fetch(url, {
            method: 'POST',
            body: formData,
        });

        let data = null;
        try {
            data = await response.json();
        } catch (error) {
            throw new Error('Reponse JSON invalide');
        }

        if (!response.ok || !data || data.success !== true) {
            throw new Error(data && data.message ? data.message : 'Operation impossible');
        }

        return data;
    };

    if (form) {
        form.addEventListener('submit', async (event) => {
            event.preventDefault();
            const url = config.mode === 'edit'
                ? '/api/update_mikrotik_scheduler.php'
                : '/api/create_mikrotik_scheduler.php';

            try {
                const data = await sendForm(url, new FormData(form));
                showMessage(data.message || 'Enregistre', 'success');
                setTimeout(() => {
                    window.location.href = '/pages/scheduler.php';
                }, 500);
            } catch (error) {
                showMessage(error.message, 'danger');
            }
        });
    }

    if (deleteBtn && form) {
        deleteBtn.addEventListener('click', async () => {
            if (!confirm('Supprimer ce scheduler ?')) {
                return;
            }

            const formData = new FormData();
            formData.append('csrf_token', form.querySelector('[name="csrf_token"]').value);
            formData.append('scheduler_id', form.querySelector('[name="scheduler_id"]').value);

            try {
                const data = await sendForm('/api/delete_mikrotik_scheduler.php', formData);
                showMessage(data.message || 'Supprime', 'success');
                setTimeout(() => {
                    window.location.href = '/pages/scheduler.php';
                }, 500);
            } catch (error) {
                showMessage(error.message, 'danger');
            }
        });
    }
});
