document.addEventListener('DOMContentLoaded', () => {
    const searchInput = document.getElementById('schedulerSearchInput');
    const tableBody = document.getElementById('schedulerTableBody');
    const refreshBtn = document.getElementById('schedulerRefreshBtn');

    if (searchInput && tableBody) {
        searchInput.addEventListener('input', () => {
            const query = searchInput.value.toLowerCase().trim();
            const rows = tableBody.querySelectorAll('.scheduler-row');

            rows.forEach((row) => {
                const text = row.innerText.toLowerCase();
                row.style.display = text.includes(query) ? '' : 'none';
            });
        });
    }

    if (tableBody) {
        tableBody.addEventListener('click', (event) => {
            const row = event.target.closest('.scheduler-row');
            if (row && !event.target.closest('.js-edit-scheduler')) {
                const schedulerId = row.dataset.id;
                if (schedulerId) {
                    window.location.href = `add_scheduler.php?scheduler_id=${encodeURIComponent(schedulerId)}`;
                }
            }
        });
    }

    if (refreshBtn) {
        refreshBtn.addEventListener('click', () => {
            window.location.reload();
        });
    }

    document.querySelectorAll('.js-edit-scheduler').forEach((btn) => {
        btn.addEventListener('click', (e) => {
            e.stopPropagation();
            const schedulerId = btn.dataset.schedulerId;
            if (schedulerId) {
                window.location.href = `add_scheduler.php?scheduler_id=${encodeURIComponent(schedulerId)}`;
            }
        });
    });
});
