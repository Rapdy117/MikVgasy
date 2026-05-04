document.addEventListener('DOMContentLoaded', () => {
    const rows = Array.from(document.querySelectorAll('.binding-row[data-edit-href]'));
    const searchInput = document.getElementById('bindingsSearchInput');

    rows.forEach((row) => {
        row.addEventListener('click', (event) => {
            const interactive = event.target.closest('a, button, input, select, textarea, .action-cell');
            if (interactive) {
                return;
            }

            const href = row.dataset.editHref || '';
            if (href) {
                window.location.href = href;
            }
        });
    });

    if (searchInput) {
        searchInput.addEventListener('input', () => {
            const query = searchInput.value.trim().toLowerCase();

            rows.forEach((row) => {
                const haystack = [
                    row.dataset.id,
                    row.dataset.address,
                    row.dataset.mac,
                    row.dataset.type,
                    row.dataset.to_address,
                    row.dataset.server,
                    row.dataset.comment,
                    row.dataset.status,
                ]
                    .filter(Boolean)
                    .join(' ')
                    .toLowerCase();

                row.classList.toggle('d-none', query !== '' && !haystack.includes(query));
            });
        });
    }
});
