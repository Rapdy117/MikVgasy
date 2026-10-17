document.addEventListener('DOMContentLoaded', () => {
    const searchInput = document.getElementById('leasesSearchInput');
    const tableBody = document.getElementById('leasesTableBody');
    const rows = Array.from(document.querySelectorAll('#leasesTableBody tr'));
    const selectAll = document.getElementById('selectAllLeases');
    const csrfToken = document.getElementById('dhcpCsrfToken')?.value || '';
    const messageArea = document.getElementById('messageArea');
    const deleteBtn = document.getElementById('deleteLeaseBtn');
    const refreshBtn = document.getElementById('refreshLanInventoryBtn');
    const portalDiagnosticBtn = document.getElementById('openSelectedPortalDiagnosticBtn');
    const portalDiagnosticModal = document.getElementById('portalDiagnosticModal');
    const portalDiagnosticState = document.getElementById('portalDiagnosticState');
    const portalDiagnosticCheckedAt = document.getElementById('portalDiagnosticCheckedAt');
    const portalDiagnosticSummary = document.getElementById('portalDiagnosticSummary');
    const portalDiagnosticResults = document.getElementById('portalDiagnosticResults');
    const portalDiagnosticNotice = document.getElementById('portalDiagnosticNotice');
    const activeDeviceType = String(document.body?.dataset?.activeDeviceType || '').trim().toLowerCase();


    const isPermanentDisabled = (element) => element?.dataset?.permanentDisabled === '1';

    const getVisibleCheckboxes = () => rows
        .filter((row) => !row.classList.contains('d-none'))
        .map((row) => row.querySelector('.lease-select'))
        .filter((checkbox) => checkbox && !checkbox.disabled);

    const getSelectedCheckboxes = () => rows
        .map((row) => row.querySelector('.lease-select'))
        .filter((checkbox) => checkbox && checkbox.checked && !checkbox.disabled);

    const showMessage = (message, type = 'success') => {
        if (!messageArea) return;
        messageArea.innerHTML = `<div class="alert alert-${type} py-2 px-3 mb-3" role="alert">${message}</div>`;
        messageArea.style.display = 'block';
    };

    const escapeHtml = (value) => String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');

    const closePortalDiagnosticModal = () => {
        if (!portalDiagnosticModal) return;
        portalDiagnosticModal.hidden = true;
        portalDiagnosticModal.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('leases-conformity-open');
    };

    const diagnosticCellValue = (value) => {
        if (value === null || value === undefined || value === '') return '-';
        if (value === true || value === 'true' || value === 'yes') return 'Oui';
        if (value === false || value === 'false' || value === 'no') return 'Non';
        return String(value);
    };

    const renderPortalDiagnosticTable = (title, entries, columns) => {
        const rows = Array.isArray(entries) ? entries : [];
        const body = rows.length
            ? rows.map((row) => `<tr>${columns.map(([key]) => `<td>${escapeHtml(diagnosticCellValue(row[key]))}</td>`).join('')}</tr>`).join('')
            : `<tr><td colspan="${columns.length}" class="text-center">Aucune entrée correspondante dans cet instantané.</td></tr>`;
        return `<div class="leases-conformity-block"><strong>${escapeHtml(title)} · ${rows.length}</strong><div class="table-responsive"><table class="table table-dark table-sm mb-0 leases-conformity-table"><thead><tr>${columns.map(([, label]) => `<th>${escapeHtml(label)}</th>`).join('')}</tr></thead><tbody>${body}</tbody></table></div></div>`;
    };

    const renderPortalDiagnostic = (data) => {
        const configuration = data.configuration || {};
        const diagnostics = Array.isArray(data.diagnostics) ? data.diagnostics : [];
        const total = (key) => diagnostics.reduce((sum, item) => sum + (Number(item[key]) || 0), 0);
        const activeClients = diagnostics.filter((item) => Number(item.active_count) > 0).length;
        const hotspotServers = configuration.hotspot_servers || [];
        const hotspotProfiles = configuration.hotspot_profiles || [];
        const dns = configuration.dns || {};
        const cards = [
            ['Baux analysés', diagnostics.length],
            ['Baux correspondants', total('lease_count')],
            ['ARP correspondants', total('arp_count')],
            ['Hotspot hosts', total('hotspot_host_count')],
            ['Sessions actives', activeClients],
        ];
        if (portalDiagnosticSummary) {
            portalDiagnosticSummary.innerHTML = cards.map(([label, value]) => `<div class="leases-conformity-kpi"><span>${escapeHtml(label)}</span><strong>${escapeHtml(value)}</strong></div>`).join('');
        }
        if (portalDiagnosticCheckedAt) portalDiagnosticCheckedAt.textContent = `Routeur ${data.device?.name || data.device?.host || '-'} · ${data.checked_at || '-'}`;
        if (portalDiagnosticState) {
            portalDiagnosticState.textContent = `${activeClients} session(s) active(s)`;
            portalDiagnosticState.className = `leases-conformity-state ${activeClients > 0 ? 'is-ok' : 'is-warning'}`;
        }
        if (portalDiagnosticNotice) portalDiagnosticNotice.textContent = data.notice || 'Instantané RouterOS en lecture seule.';
        if (!portalDiagnosticResults) return;
        portalDiagnosticResults.innerHTML = [
            `<div class="leases-conformity-block"><strong>Diagnostic par bail · ${diagnostics.length} lignes</strong><div class="table-responsive"><table class="table table-dark table-sm mb-0 leases-conformity-table"><thead><tr><th>Client</th><th>DHCP</th><th>Passerelle / DNS</th><th>ARP</th><th>Bridge</th><th>Hotspot host</th><th>Hotspot active</th></tr></thead><tbody>${diagnostics.map((item) => `<tr><td><strong>${escapeHtml(diagnosticCellValue(item.ip))}</strong><small>${escapeHtml(diagnosticCellValue(item.mac))}</small></td><td><strong>${escapeHtml(diagnosticCellValue(item.lease_state))}</strong><small>${escapeHtml(diagnosticCellValue(item.lease_servers))} · ${escapeHtml(diagnosticCellValue(item.lease_count))} bail(s)</small></td><td><strong>${escapeHtml(diagnosticCellValue(item.gateways))}</strong><small>${escapeHtml(diagnosticCellValue(item.dhcp_dns))}</small></td><td><strong>${escapeHtml(diagnosticCellValue(item.arp_count))} entrée(s)</strong><small>${escapeHtml(diagnosticCellValue(item.arp_macs))} · ${escapeHtml(diagnosticCellValue(item.arp_interfaces))}</small></td><td>${escapeHtml(diagnosticCellValue(item.bridge_ports))}</td><td><strong>${escapeHtml(diagnosticCellValue(item.hotspot_host_count))} entrée(s)</strong><small>${escapeHtml(diagnosticCellValue(item.hotspot_host_state))} · ${escapeHtml(diagnosticCellValue(item.hotspot_host_servers))}</small></td><td><strong>${escapeHtml(diagnosticCellValue(item.active_count))} session(s)</strong><small>${escapeHtml(diagnosticCellValue(item.hotspot_active))}</small></td></tr>`).join('') || '<tr><td colspan="7" class="text-center">Aucun bail valide à diagnostiquer.</td></tr>'}</tbody></table></div></div>`,
            renderPortalDiagnosticTable('Serveurs Hotspot', hotspotServers, [['name', 'Nom'], ['interface', 'Interface'], ['profile', 'Profil'], ['disabled', 'Désactivé'], ['invalid', 'Invalide']]),
            renderPortalDiagnosticTable('Profils Hotspot', hotspotProfiles, [['name', 'Nom'], ['dns-name', 'DNS captif'], ['hotspot-address', 'Adresse Hotspot'], ['login-by', 'Login par'], ['html-directory', 'Portail']]),
            `<div class="leases-conformity-block"><strong>DNS RouterOS</strong><p class="leases-conformity-note">Demandes distantes : ${escapeHtml(dns['allow-remote-requests'] ?? '-')} · Serveurs : ${escapeHtml(dns.servers || dns['dynamic-servers'] || '-')} · DoH : ${escapeHtml(dns['use-doh-server'] || 'non configuré')}</p></div>`,
            renderPortalDiagnosticTable('Règles NAT pertinentes, dans l’ordre RouterOS', configuration.nat_rules, [['order', '#'], ['chain', 'Chaîne'], ['action', 'Action'], ['protocol', 'Protocole'], ['dst_port', 'Port destination'], ['to_ports', 'Port redirigé'], ['in_interface', 'Interface'], ['dynamic', 'Dynamique'], ['disabled', 'Désactivée'], ['comment', 'Commentaire']]),
            renderPortalDiagnosticTable('Règles filter Hotspot', configuration.filter_rules, [['order', '#'], ['chain', 'Chaîne'], ['action', 'Action'], ['protocol', 'Protocole'], ['dst_port', 'Port destination'], ['in_interface', 'Interface'], ['dynamic', 'Dynamique'], ['disabled', 'Désactivée'], ['comment', 'Commentaire']]),
        ].join('');
    };

    const loadPortalDiagnostic = async () => {
        if (!portalDiagnosticModal) return;
        const selectedCheckboxes = getSelectedCheckboxes();
        const records = selectedCheckboxes.map((checkbox) => {
            const row = checkbox.closest('tr');
            return { ip: row?.dataset.address || '', mac: row?.dataset.mac || '' };
        })
            .filter((record) => record.ip && record.mac);
        if (records.length === 0) {
            showMessage('Sélectionnez les baux à diagnostiquer.', 'warning');
            return;
        }
        if (records.length > 30) {
            showMessage('Le diagnostic est limité à 30 baux par relevé. Réduisez la sélection.', 'warning');
            return;
        }
        portalDiagnosticModal.hidden = false;
        portalDiagnosticModal.setAttribute('aria-hidden', 'false');
        document.body.classList.add('leases-conformity-open');
        if (portalDiagnosticState) {
            portalDiagnosticState.textContent = 'Lecture…';
            portalDiagnosticState.className = 'leases-conformity-state';
        }
        if (portalDiagnosticCheckedAt) portalDiagnosticCheckedAt.textContent = `Lecture de ${records.length} baux`;
        if (portalDiagnosticSummary) portalDiagnosticSummary.innerHTML = '';
        if (portalDiagnosticResults) portalDiagnosticResults.innerHTML = '<div class="leases-conformity-note">Lecture RouterOS en cours…</div>';
        if (portalDiagnosticBtn) portalDiagnosticBtn.disabled = true;
        try {
            const response = await fetch('../api/dhcp_portal_diagnostic.php', {
                method: 'POST',
                headers: { 'Accept': 'application/json', 'Content-Type': 'application/json' },
                credentials: 'same-origin',
                body: JSON.stringify({ csrf_token: csrfToken, records }),
            });
            const data = await response.json();
            if (!response.ok || !data.success) throw new Error(data.message || 'Diagnostic impossible.');
            renderPortalDiagnostic(data);
        } catch (error) {
            if (portalDiagnosticState) {
                portalDiagnosticState.textContent = 'Erreur';
                portalDiagnosticState.className = 'leases-conformity-state is-error';
            }
            if (portalDiagnosticResults) portalDiagnosticResults.innerHTML = `<div class="leases-conformity-error">${escapeHtml(error.message || 'Diagnostic impossible.')}</div>`;
        } finally {
            if (portalDiagnosticBtn) portalDiagnosticBtn.disabled = false;
        }
    };

    const postInventory = async (payload) => {
        const response = await fetch('../api/lan_inventory.php', {
            method: 'POST',
            headers: { 'Accept': 'application/json', 'Content-Type': 'application/json' },
            credentials: 'same-origin',
            body: JSON.stringify({ csrf_token: csrfToken, ...payload }),
        });
        const data = await response.json();
        if (!response.ok || !data.success) throw new Error(data.message || 'Inventaire impossible.');
        return data;
    };

    // Seule lecture RouterOS de l'inventaire : enregistre un nouvel instantané SQL puis recharge la page.
    const refreshInventory = async (successMessage = '') => {
        if (refreshBtn) {
            refreshBtn.disabled = true;
            refreshBtn.innerHTML = '<i class="fa fa-rotate fa-spin me-1"></i> Lecture RouterOS…';
        }
        try {
            await postInventory({ action: 'refresh' });
            if (successMessage) showMessage(escapeHtml(successMessage), 'success');
            window.location.reload();
        } catch (error) {
            showMessage(escapeHtml(error.message || 'Mise à jour impossible.'), 'danger');
            if (refreshBtn) {
                refreshBtn.disabled = false;
                refreshBtn.innerHTML = '<i class="fa fa-rotate me-1"></i> Mettre à jour';
            }
        }
    };

    const runConformityAction = async (button) => {
        const label = button.dataset.label || 'Corriger';
        const ip = button.dataset.ip || '';
        const mac = button.dataset.mac || '';
        const question = `${label} pour ${ip} / ${mac} ?`;
        if (!window.confirm(question)) return;

        try {
            button.disabled = true;
            const response = await fetch('../api/dhcp_conformity_action.php', {
                method: 'POST',
                headers: {
                    'Accept': 'application/json',
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    csrf_token: csrfToken,
                    scope: button.dataset.scope || '',
                    id: button.dataset.id || '',
                    ip: button.dataset.ip || '',
                    mac: button.dataset.mac || '',
                    arp_id: button.dataset.arpId || '',
                    hotspot_host_id: button.dataset.hotspotHostId || '',
                    arp_mac: button.dataset.arpMac || '',
                    hotspot_host_mac: button.dataset.hotspotHostMac || '',
                }),
            });
            const data = await response.json();
            if (!response.ok || !data.success) throw new Error(data.message || 'Action impossible.');
            showMessage(escapeHtml(data.message || 'Correction effectuée.'), 'success');
            await refreshInventory();
        } catch (error) {
            showMessage(escapeHtml(error.message || 'Action impossible.'), 'danger');
            button.disabled = false;
        }
    };

    const updateActionButtons = () => {
        const hasSelection = getSelectedCheckboxes().length > 0;
        [deleteBtn].forEach((button) => {
            if (button && !isPermanentDisabled(button)) button.disabled = !hasSelection;
        });
        if (portalDiagnosticBtn) portalDiagnosticBtn.disabled = !hasSelection;

        if (selectAll) {
            if (isPermanentDisabled(selectAll)) return;
            const visible = getVisibleCheckboxes();
            selectAll.checked = visible.length > 0 && visible.every((checkbox) => checkbox.checked);
        }
    };

    const runAction = async (action, confirmMessage = '') => {
        const selected = getSelectedCheckboxes().filter((checkbox) => checkbox.value !== '');
        if (selected.length === 0) {
            showMessage('Sélectionnez au moins un bail.', 'warning');
            return;
        }

        if (confirmMessage && !window.confirm(confirmMessage)) return;

        try {
            const payload = new URLSearchParams();
            payload.set('csrf_token', csrfToken);
            payload.set('action', action);
            selected.forEach((checkbox) => payload.append('lease_ids[]', checkbox.value));

            const response = await fetch('../api/dhcp_lease_action.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' },
                body: payload.toString(),
            });

            const data = await response.json();
            if (!response.ok || !data.success) throw new Error(data.message || 'Action impossible');

            showMessage(data.message || 'Action effectuée.', 'success');
            if (refreshBtn) await refreshInventory();
            else window.setTimeout(() => window.location.reload(), 500);
        } catch (error) {
            showMessage(error.message || 'Erreur d’action.', 'danger');
        }
    };

    const filterAnomaliesBtn = document.getElementById('leasesFilterAnomaliesBtn');
    const filterActionsBtn = document.getElementById('leasesFilterActionsBtn');
    let rowFilter = null; // null | 'anomaly' | 'action'

    const applyFilters = () => {
        const query = searchInput ? searchInput.value.trim().toLowerCase() : '';
        rows.forEach((row) => {
            const matchesQuery = query === '' || row.innerText.toLowerCase().includes(query);
            const matchesFilter = rowFilter === null
                || (rowFilter === 'anomaly' && (row.dataset.anomaly || '') !== '')
                || (rowFilter === 'action' && row.dataset.hasAction === '1');
            row.classList.toggle('d-none', !(matchesQuery && matchesFilter));
        });
        updateActionButtons();
    };

    searchInput?.addEventListener('input', applyFilters);

    const toggleRowFilter = (name, button) => {
        rowFilter = rowFilter === name ? null : name;
        [filterAnomaliesBtn, filterActionsBtn].forEach((btn) => btn?.setAttribute('aria-pressed', 'false'));
        if (rowFilter !== null) button.setAttribute('aria-pressed', 'true');
        applyFilters();
    };
    filterAnomaliesBtn?.addEventListener('click', () => toggleRowFilter('anomaly', filterAnomaliesBtn));
    filterActionsBtn?.addEventListener('click', () => toggleRowFilter('action', filterActionsBtn));

    // Le badge d'anomalie d'une ligne bascule le même filtre que le compteur d'en-tête.
    tableBody?.addEventListener('click', (event) => {
        if (event.target.closest('[data-filter-anomaly]')) toggleRowFilter('anomaly', filterAnomaliesBtn);
    });

    if (selectAll) {
        selectAll.addEventListener('change', () => {
            if (isPermanentDisabled(selectAll)) return;
            getVisibleCheckboxes().forEach((checkbox) => {
                checkbox.checked = selectAll.checked;
            });
            updateActionButtons();
        });
    }

    if (tableBody) {
        tableBody.addEventListener('change', (event) => {
            if (event.target.closest('.lease-select')) updateActionButtons();
        });
    }

    deleteBtn?.addEventListener('click', () => runAction('delete', 'Supprimer les baux sélectionnés ?'));
    refreshBtn?.addEventListener('click', () => refreshInventory());
    portalDiagnosticBtn?.addEventListener('click', loadPortalDiagnostic);
    portalDiagnosticModal?.addEventListener('click', (event) => {
        if (event.target.closest('[data-portal-diagnostic-close]')) closePortalDiagnosticModal();
    });
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && portalDiagnosticModal && !portalDiagnosticModal.hidden) closePortalDiagnosticModal();
    });

    updateActionButtons();

    if (activeDeviceType === 'mikrotik') {
        tableBody?.addEventListener('click', (event) => {
            const actionButtonEl = event.target.closest('[data-conformity-action]');
            if (actionButtonEl) {
                runConformityAction(actionButtonEl);
                return;
            }
            if (event.target.closest('a, button, .lease-select')) return;

            const row = event.target.closest('tr[data-lease-id]');
            if (!row) return;

            const params = new URLSearchParams();
            params.set('lease_id', row.dataset.leaseId || '');
            params.set('address', row.dataset.address || '');
            params.set('mac', row.dataset.mac || '');
            params.set('host_name', row.dataset.host_name || '');
            params.set('server', row.dataset.server || '');
            params.set('comment', row.dataset.comment || '');
            params.set('disabled', row.dataset.disabled || '0');

            window.location.href = `add_dhcp_lease.php?${params.toString()}`;
        });
    }
});
