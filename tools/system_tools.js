document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('#sidebar-wrapper a[href]').forEach((link) => {
        const href = link.getAttribute('href') || '';
        if (href && !href.startsWith('/') && !href.startsWith('#') && !href.startsWith('http')) link.setAttribute('href', '/pages/' + href);
    });
    const csrf = document.body.dataset.csrfToken || '';
    const status = document.getElementById('systemToolsStatus');

    async function run(url, pending) {
        status.textContent = pending;
        const body = new FormData();
        body.append('csrf_token', csrf);
        const response = await fetch(url, {method: 'POST', body, credentials: 'same-origin'});
        const result = await response.json();
        if (!response.ok || result.success === false) {
            throw new Error(result.message || 'Opération impossible.');
        }
        return result;
    }

    document.getElementById('syncOpnsenseSessionsBtn')?.addEventListener('click', async () => {
        try {
            const result = await run('/api/admin/opnsense_sync_sessions.php', 'Synchronisation en cours…');
            status.textContent = 'Synchronisation terminée. Sessions : ' + String(result.sessions || 0);
        } catch (error) {
            status.textContent = error.message;
        }
    });


    document.getElementById('licenseForm')?.addEventListener('submit', async (event) => {
        event.preventDefault(); const box = document.getElementById('licenseResult'); const body = new FormData(event.currentTarget); body.set('csrf_token', csrf); body.set('license_type', document.getElementById('licenseType')?.value || 'lifetime'); box.textContent = 'Génération en cours…';
        const generated = document.getElementById('generatedLicenseKey'); const copyButton = document.getElementById('copyGeneratedLicenseBtn');
        if (generated) generated.value = ''; if (copyButton) copyButton.disabled = true;
        try { const response = await fetch('/api/admin/generate_license.php', {method:'POST', body, credentials:'same-origin'}); const data = await response.json(); if (!response.ok || data.success === false) throw new Error(data.message || 'Génération impossible.'); if (generated) generated.value = data.license_key || ''; if (copyButton) copyButton.disabled = !data.license_key; box.textContent = 'Licence générée et enregistrée dans SQL.'; } catch (error) { box.textContent = error.message; }
    });

    const licenseSearch = document.getElementById('licenseTableSearch');
    const licenseRows = Array.from(document.querySelectorAll('.device-license-table tbody tr[data-license-search]'));
    licenseSearch?.addEventListener('input', () => { const q = licenseSearch.value.toLowerCase().trim(); licenseRows.forEach((row) => { row.classList.toggle('d-none', q !== '' && !row.dataset.licenseSearch.includes(q)); }); });
    document.querySelectorAll('.device-license-table th[data-sort-key]').forEach((th) => { th.addEventListener('click', () => { const table = th.closest('table'); const tbody = table?.querySelector('tbody'); if (!tbody) return; const rows = Array.from(tbody.querySelectorAll('tr[data-license-search]')); const index = Array.from(th.parentElement.children).indexOf(th); const direction = th.dataset.sortDirection === 'asc' ? 'desc' : 'asc'; th.dataset.sortDirection = direction; rows.sort((a,b) => { const av=(a.children[index]?.textContent||'').trim().toLowerCase(); const bv=(b.children[index]?.textContent||'').trim().toLowerCase(); return direction === 'asc' ? av.localeCompare(bv, 'fr', {numeric:true}) : bv.localeCompare(av, 'fr', {numeric:true}); }); rows.forEach((row) => tbody.appendChild(row)); }); });

    document.querySelectorAll('.license-view-btn').forEach((button) => {
        button.addEventListener('click', () => {
            const key = button.dataset.licenseKey || '';
            const field = document.getElementById('licenseKeyModalValue');
            document.getElementById('licenseKeyModalSerial')?.replaceChildren(document.createTextNode(button.dataset.licenseSerial || '—'));
            document.getElementById('licenseKeyModalModel')?.replaceChildren(document.createTextNode(button.dataset.licenseModel || '—'));
            document.getElementById('licenseKeyModalType')?.replaceChildren(document.createTextNode(button.dataset.licenseType || '—'));
            if (field) field.value = key;
            const modal = document.getElementById('licenseKeyModal');
            if (modal && window.bootstrap?.Modal) window.bootstrap.Modal.getOrCreateInstance(modal).show();
        });
    });
    document.getElementById('copyGeneratedLicenseBtn')?.addEventListener('click', async () => {
        const field = document.getElementById('generatedLicenseKey');
        if (!field?.value) return;
        try { await navigator.clipboard.writeText(field.value); } catch (error) { field.select(); document.execCommand('copy'); }
    });

    document.getElementById('licenseModalCopyBtn')?.addEventListener('click', async () => {
        const field = document.getElementById('licenseKeyModalValue');
        if (!field?.value) return;
        try { await navigator.clipboard.writeText(field.value); } catch (error) { field.select(); document.execCommand('copy'); }
    });

    document.querySelectorAll('.license-delete-btn').forEach((button) => {
        button.addEventListener('click', async () => {
            if (!window.confirm('Supprimer définitivement cette licence ?')) return;
            const body = new FormData(); body.append('csrf_token', csrf); body.append('id', button.dataset.licenseId || '');
            try {
                const response = await fetch('/api/admin/delete_license.php', {method: 'POST', body, credentials: 'same-origin'});
                const data = await response.json();
                if (!response.ok || data.success === false) throw new Error(data.message || 'Suppression impossible.');
                button.closest('tr')?.remove();
            } catch (error) { window.alert(error.message); }
        });
    });

    document.getElementById('installOpnsenseCronBtn')?.addEventListener('click', async () => {
        try {
            const result = await run('/api/admin/install_opnsense_cron.php', 'Installation du cron en cours…');
            status.textContent = result.message || 'Cron installé.';
        } catch (error) {
            status.textContent = error.message;
        }
    });
    async function maintenance(action, confirm = '0') {
        const body = new FormData();
        body.append('csrf_token', csrf); body.append('action', action);
        body.append('table', document.getElementById('maintenanceTable')?.value || 'operation_history');
        body.append('months', document.getElementById('trafficRetentionMonths')?.value || '6');
        body.append('confirm', confirm);
        const response = await fetch('/api/admin/database_maintenance.php', {method: 'POST', body, credentials: 'same-origin'});
        const result = await response.json();
        if (!response.ok || result.success === false) throw new Error(result.message || 'Opération impossible.');
        return result;
    }
    document.getElementById('maintenanceStatusBtn')?.addEventListener('click', async () => {
        const box = document.getElementById('maintenanceStatus'); box.textContent = 'Lecture en cours…';
        try { const result = await maintenance('status'); box.textContent = Object.entries(result.tables || {}).map(([name, row]) => name + ': ' + row.total + ' ligne(s), ' + (row.size ? row.size + ', ' : '') + (row.oldest || '—') + ' → ' + (row.newest || '—')).join('\n') || 'Aucune table disponible.'; } catch (error) { box.textContent = error.message; }
    });
    document.getElementById('maintenanceTrafficBackupBtn')?.addEventListener('click', async () => {
        const box = document.getElementById('maintenanceStatus'); box.textContent = 'Export Traffic Control en cours…';
        try { const result = await maintenance('backup_traffic'); box.textContent = result.message + ' (' + result.path + ')'; } catch (error) { box.textContent = error.message; }
    });
    document.getElementById('maintenanceTrafficArchiveBtn')?.addEventListener('click', async () => {
        const box = document.getElementById('maintenanceStatus'); box.textContent = 'Archivage Traffic Control en cours…';
        try { const result = await maintenance('archive_traffic_retention'); box.textContent = result.message + ' Lignes : ' + result.rows + '.'; } catch (error) { box.textContent = error.message; }
    });
    document.getElementById('maintenanceTrafficCleanBtn')?.addEventListener('click', async () => {
        const months = document.getElementById('trafficRetentionMonths')?.value || '6';
        if (!window.confirm('Confirmer la suppression des historiques Traffic Control plus anciens que ' + months + ' mois ? Exporter ou archiver avant suppression est recommandé.')) return;
        const box = document.getElementById('maintenanceStatus'); box.textContent = 'Nettoyage Traffic Control en cours…';
        try { const result = await maintenance('clean_traffic_retention', '1'); box.textContent = result.message + ' Lignes supprimées : ' + result.rows + '.'; } catch (error) { box.textContent = error.message; }
    });
    document.getElementById('maintenanceSensitiveBackupBtn')?.addEventListener('click', async () => {
        const box = document.getElementById('maintenanceStatus'); box.textContent = 'Sauvegarde sensible en cours…';
        try { const result = await maintenance('backup_sensitive'); box.textContent = result.message + ' (' + result.path + ')'; } catch (error) { box.textContent = error.message; }
    });

});
