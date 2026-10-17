/**
 * Filtre instantane sur le tableau (meme principe que hosts.js / sessions_list.js).
 * La recherche ne declenche pas de requete : masquage des lignes par texte.
 */
document.addEventListener('DOMContentLoaded', () => {
    const tableBody = document.getElementById('systemLogTableBody');
    const mikInput = document.getElementById('mikrotikLogSearch');
    const opnInput = document.getElementById('opnsenseSearchFilter');
    const searchInput = mikInput || opnInput;
    if (!tableBody || !searchInput) {
        return;
    }

    const rows = Array.from(tableBody.querySelectorAll('tr'));
    if (rows.length === 0) {
        return;
    }

    const applyFilter = () => {
        const q = (searchInput.value || '').trim().toLowerCase();
        rows.forEach((row) => {
            const text = (row.textContent || '').toLowerCase();
            row.classList.toggle('d-none', q !== '' && !text.includes(q));
        });
    };

    searchInput.addEventListener('input', applyFilter);
    searchInput.addEventListener('search', () => {
        if (searchInput.value === '') {
            applyFilter();
        }
    });
});
