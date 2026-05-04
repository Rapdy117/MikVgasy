/**
 * Recherche locale (attribut data-search sur chaque ligne) + option rafraîchissement 30s.
 */
document.addEventListener('DOMContentLoaded', () => {
    const filterInput = document.getElementById('userLogsFilter');
    const table = document.getElementById('userLogsTable');
    const autoBtn = document.getElementById('userLogsAutoRefreshBtn');
    let refreshTimer = null;

    const applySearch = () => {
        if (!table) {
            return;
        }
        const tbody = table.tBodies[0];
        if (!tbody) {
            return;
        }
        const q = (filterInput?.value || '').trim().toLowerCase();
        tbody.querySelectorAll('tr').forEach((row) => {
            if (row.hasAttribute('data-sort-disabled')) {
                row.classList.toggle('d-none', q !== '');
                return;
            }
            const fromData = row.getAttribute('data-search');
            const hay = (fromData !== null && fromData !== ''
                ? String(fromData)
                : (row.textContent || '')).toLowerCase();
            const match = q === '' || hay.includes(q);
            row.classList.toggle('d-none', !match);
        });
    };

    filterInput?.addEventListener('input', applySearch);
    filterInput?.addEventListener('search', applySearch);

    autoBtn?.addEventListener('click', () => {
        if (refreshTimer) {
            clearInterval(refreshTimer);
            refreshTimer = null;
            autoBtn.classList.remove('active');
            autoBtn.setAttribute('aria-pressed', 'false');
            return;
        }
        refreshTimer = window.setInterval(() => {
            window.location.reload();
        }, 30000);
        autoBtn.classList.add('active');
        autoBtn.setAttribute('aria-pressed', 'true');
    });
});
