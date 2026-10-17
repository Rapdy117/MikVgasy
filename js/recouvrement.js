document.addEventListener('DOMContentLoaded', () => {
    const historyBody = document.getElementById('recouvrementHistoryBody');
    const selectAll = document.getElementById('selectAllRecouvrementRows');
    const detailOperator = document.getElementById('recouvrementDetailOperator');
    const invoiceLink = document.getElementById('recouvrementInvoiceLink');
    const filterDay = document.getElementById('recouvrementFilterDay');
    const filterMonth = document.getElementById('recouvrementFilterMonth');
    const filterYear = document.getElementById('recouvrementFilterYear');
    const filterReseller = document.getElementById('recouvrementFilterReseller');
    const metricRecharges = document.getElementById('recouvrementMetricRecharges');
    const metricVoucherBatches = document.getElementById('recouvrementMetricVoucherBatches');
    const metricVouchers = document.getElementById('recouvrementMetricVouchers');
    const metricAmount = document.getElementById('recouvrementMetricAmount');
    const metricOperators = document.getElementById('recouvrementMetricOperators');
    const metricProfiles = document.getElementById('recouvrementMetricProfiles');
    const metricCommercialOperations = document.getElementById('recouvrementMetricCommercialOperations');
    const metricUsers = document.getElementById('recouvrementMetricUsers');
    const recouvrementData = window.recouvrementData || { rows: {}, vouchers: [], operations: [] };
    const csrfToken = recouvrementData.csrfToken || '';

    if (!historyBody) {
        return;
    }

    const historyRows = Array.from(historyBody.querySelectorAll('tr[data-reseller]'));
    const getVisibleCheckboxes = () => historyRows
        .filter((row) => !row.classList.contains('d-none'))
        .map((row) => row.querySelector('.recouvrement-select'))
        .filter(Boolean);

    const getSelectedCheckboxes = () => historyRows
        .map((row) => row.querySelector('.recouvrement-select'))
        .filter((checkbox) => checkbox && checkbox.checked);

    const getSelectedRows = () => getSelectedCheckboxes()
        .map((checkbox) => checkbox.closest('tr[data-reseller]'))
        .filter(Boolean);

    const getPrimarySelectedRow = () => {
        const selectedRows = getSelectedRows();
        if (selectedRows.length > 0) {
            return selectedRows[0];
        }

        return null;
    };

    const formatNumber = (value) => new Intl.NumberFormat('fr-FR', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    }).format(Number(value || 0));

    const matchesDateFilters = (isoDate) => {
        if (!isoDate) {
            return false;
        }

        const [year, month, day] = isoDate.split('-');
        const selectedDay = filterDay?.value || '';
        const selectedMonth = filterMonth?.value || '';
        const selectedYear = filterYear?.value || '';

        if (selectedYear !== '' && year !== selectedYear.padStart(4, '0')) {
            return false;
        }
        if (selectedMonth !== '' && month !== selectedMonth.padStart(2, '0')) {
            return false;
        }
        if (selectedDay !== '' && day !== selectedDay.padStart(2, '0')) {
            return false;
        }
        return true;
    };

    const getInvoicePeriod = () => {
        const selectedDay = filterDay?.value || '';
        const selectedMonth = filterMonth?.value || '';
        const selectedYear = filterYear?.value || '';

        if (selectedMonth === '' || selectedYear === '') {
            return { dateFrom: '', dateTo: '' };
        }

        const month = selectedMonth.padStart(2, '0');
        const year = selectedYear.padStart(4, '0');

        if (selectedDay !== '') {
            const day = selectedDay.padStart(2, '0');
            const date = `${year}-${month}-${day}`;
            return { dateFrom: date, dateTo: date };
        }

        const lastDay = new Date(Number(selectedYear), Number(selectedMonth), 0).getDate();
        return {
            dateFrom: `${year}-${month}-01`,
            dateTo: `${year}-${month}-${String(lastDay).padStart(2, '0')}`,
        };
    };

    const getRowEntries = (row) => {
        const rowKey = row?.dataset.rowKey || '';
        const rowData = recouvrementData.rows?.[rowKey];
        return Array.isArray(rowData?.entries) ? rowData.entries : [];
    };

    const renderRowMetrics = (row) => {
        if (!row) {
            return false;
        }

        const entries = getRowEntries(row).filter((entry) => matchesDateFilters((entry.created_at || '').slice(0, 10)));
        if (entries.length === 0) {
            row.dataset.filteredCount = '0';
            return false;
        }

        const lastEntry = entries.reduce((latest, current) => {
            return !latest || (current.created_at || '') > (latest.created_at || '') ? current : latest;
        }, null);
        const rechargeEntries = entries.filter((entry) => (entry.entry_type || 'recharge') === 'recharge');
        const totalAmount = rechargeEntries.reduce((sum, entry) => sum + Number(entry.amount_value || 0), 0);
        const count = rechargeEntries.length;
        const amountLabel = formatNumber(totalAmount);
        const summaryText = lastEntry?.summary || '-';
        const lastDate = lastEntry?.created_at || '-';
        const lastDateLabel = lastDate !== '-' ? lastDate.slice(0, 10) : '-';

        row.dataset.filteredCount = String(count);
        row.dataset.amount = amountLabel;
        row.dataset.summary = summaryText;
        row.dataset.date = lastDate;
        row.dataset.dateIso = (lastDate || '').slice(0, 10);
        row.querySelector('td:nth-child(5)').textContent = String(count);
        row.querySelector('td:nth-child(6)').textContent = summaryText;
        row.querySelector('td:nth-child(7)').textContent = lastDateLabel;
        row.querySelector('td:nth-child(8)').textContent = amountLabel;
        return true;
    };

    const updateInvoiceLink = (selectedRows) => {
        if (!invoiceLink) {
            return;
        }

        invoiceLink.classList.remove('recouvrement-invoice-pending');

        if (!Array.isArray(selectedRows) || selectedRows.length === 0) {
            invoiceLink.href = '#';
            invoiceLink.classList.add('disabled');
            invoiceLink.setAttribute('aria-disabled', 'true');
            return;
        }

        const selectedOperators = Array.from(new Set(selectedRows
            .map((row) => (row.dataset.operator || '').trim())
            .filter((value) => value !== '' && value !== '-')));
        const selectedItems = selectedRows.map((row) => ({
            operator: (row.dataset.operator || '').trim(),
            username: (row.dataset.username || '').trim(),
            profile: (row.dataset.profile || '').trim(),
        })).filter((item) => item.username !== '' && item.username !== '-');
        const operators = selectedItems.length > 0
            ? Array.from(new Set(selectedItems.map((item) => item.operator).filter(Boolean)))
            : selectedOperators;

        if (operators.length !== 1 || operators[0] === '-' || operators[0] === '') {
            invoiceLink.href = '#';
            invoiceLink.classList.add('disabled');
            invoiceLink.setAttribute('aria-disabled', 'true');
            return;
        }

        const cleanOperator = operators[0];
        const params = new URLSearchParams({ operator: cleanOperator });
        const { dateFrom, dateTo } = getInvoicePeriod();
        if (dateFrom) {
            params.set('date_from', dateFrom);
        }
        if (dateTo) {
            params.set('date_to', dateTo);
        }
        if (selectedItems.length > 0) {
            params.set('selected_lines', btoa(unescape(encodeURIComponent(JSON.stringify(selectedItems)))));
        }

        invoiceLink.href = `../pages/print_recouvrement_invoice.php?${params.toString()}`;
        invoiceLink.dataset.operator = cleanOperator;
        invoiceLink.dataset.dateFrom = dateFrom;
        invoiceLink.dataset.dateTo = dateTo;
        invoiceLink.dataset.selectedLines = selectedItems.length > 0
            ? btoa(unescape(encodeURIComponent(JSON.stringify(selectedItems))))
            : '';
        invoiceLink.classList.remove('disabled');
        invoiceLink.setAttribute('aria-disabled', 'false');
    };

    const setSelectedOperatorMetrics = (selectedRows) => {
        const selectedKeys = new Set((selectedRows || []).map((row) => row.dataset.rowKey || '').filter(Boolean));
        const matchedRows = Object.entries(recouvrementData.rows || {})
            .filter(([rowKey]) => selectedKeys.has(rowKey))
            .map(([, row]) => row);
        const operators = Array.from(new Set(matchedRows.map((row) => row.operator).filter(Boolean)));
        const rechargeEntriesMap = new Map();

        matchedRows.forEach((row) => {
            (Array.isArray(row.entries) ? row.entries : []).forEach((entry) => {
                if ((entry.entry_type || 'recharge') !== 'recharge') {
                    return;
                }
                if (!matchesDateFilters((entry.created_at || '').slice(0, 10))) {
                    return;
                }
                const entryId = entry.entry_id || `${row.operator || ''}|${row.username || ''}|${entry.created_at || ''}|${entry.summary || ''}|${entry.amount_value || 0}`;
                if (!rechargeEntriesMap.has(entryId)) {
                    rechargeEntriesMap.set(entryId, entry);
                }
            });
        });

        const rechargeEntries = Array.from(rechargeEntriesMap.values());
        const voucherUseEntries = rechargeEntries.filter((entry) => (entry.source_type || '') === 'voucher_use');
        const accountRechargeEntries = rechargeEntries.filter((entry) => (entry.source_type || 'recharge') === 'recharge');
        const operationEntries = [];
        const profileSet = new Set();
        const userSet = new Set();
        const operatorLabel = operators.length === 1 ? operators[0] : (operators.length > 1 ? operators.join(', ') : '-');

        matchedRows.forEach((row) => {
            const hasRowEntries = (row.entries || []).some((entry) => matchesDateFilters((entry.created_at || '').slice(0, 10)));
            if (!hasRowEntries) {
                return;
            }
            if (row.username && row.username !== '-') {
                userSet.add(row.username);
            }
            if (row.profile && row.profile !== '-') {
                profileSet.add(row.profile);
            }
            (row.entries || []).forEach((entry) => {
                if ((entry.entry_type || '') !== 'operation') {
                    return;
                }
                if (!matchesDateFilters((entry.created_at || '').slice(0, 10))) {
                    return;
                }
                operationEntries.push(entry);
            });
        });

        if (detailOperator) {
            detailOperator.value = operatorLabel || '-';
        }
        if (metricRecharges) {
            metricRecharges.value = String(rechargeEntries.length);
        }
        if (metricVoucherBatches) {
            metricVoucherBatches.value = String(voucherUseEntries.length);
        }
        if (metricVouchers) {
            metricVouchers.value = String(accountRechargeEntries.length);
        }
        if (metricAmount) {
            metricAmount.value = formatNumber(rechargeEntries.reduce((sum, entry) => sum + Number(entry.amount_value || 0), 0));
        }
        if (metricOperators) {
            metricOperators.value = String(operators.length);
        }
        if (metricProfiles) {
            metricProfiles.value = String(profileSet.size);
        }
        if (metricCommercialOperations) {
            metricCommercialOperations.value = String(operationEntries.length);
        }
        if (metricUsers) {
            metricUsers.value = String(userSet.size);
        }
        updateInvoiceLink(selectedRows || []);
    };

    const setDetailFromSelection = (selectedRows) => {
        setSelectedOperatorMetrics(selectedRows || []);
    };

    const updateSelectionState = () => {
        const selectedRows = getSelectedCheckboxes()
            .map((checkbox) => checkbox.closest('tr[data-reseller]'))
            .filter(Boolean);

        historyRows.forEach((row) => row.classList.remove('recouvrement-row-active'));
        selectedRows.forEach((row) => row.classList.add('recouvrement-row-active'));

        if (selectAll) {
            const visible = getVisibleCheckboxes();
            selectAll.checked = visible.length > 0 && visible.every((checkbox) => checkbox.checked);
        }

        setDetailFromSelection(selectedRows);
    };

    const toggleEmptyState = (body, colspan, visibleCount, message) => {
        let emptyRow = body.querySelector('tr[data-recouvrement-empty="1"]');
        if (!emptyRow && visibleCount === 0) {
            emptyRow = document.createElement('tr');
            emptyRow.dataset.recouvrementEmpty = '1';
            emptyRow.innerHTML = `<td colspan="${colspan}" class="text-center text-white-50">${message}</td>`;
            body.appendChild(emptyRow);
        }

        if (emptyRow) {
            emptyRow.classList.toggle('d-none', visibleCount !== 0);
        }
    };

    const filterRows = (rows, body, colspan, emptyMessage) => {
        const reseller = (filterReseller?.value || '').trim().toLowerCase();
        let visibleCount = 0;

        rows.forEach((row) => {
            const hasFilteredEntries = renderRowMetrics(row);
            const rowReseller = (row.dataset.operator || '').trim().toLowerCase();
            const resellerOk = reseller === '' || rowReseller === reseller;
            const visible = hasFilteredEntries && resellerOk;

            row.classList.toggle('d-none', !visible);
            if (visible) {
                visibleCount += 1;
            }
        });

        toggleEmptyState(body, colspan, visibleCount, emptyMessage);
    };

    const applyFilters = () => {
        filterRows(historyRows, historyBody, 8, 'Aucun utilisateur ne correspond au filtre');
        updateSelectionState();
    };

    historyRows.forEach((row) => {
        const checkbox = row.querySelector('.recouvrement-select');
        if (checkbox) {
            checkbox.addEventListener('change', updateSelectionState);
        }
    });

    if (selectAll) {
        selectAll.addEventListener('change', () => {
            getVisibleCheckboxes().forEach((checkbox) => {
                checkbox.checked = selectAll.checked;
            });
            updateSelectionState();
        });
    }

    [filterDay, filterMonth, filterYear].forEach((element) => {
        if (!element) {
            return;
        }
        element.addEventListener('input', applyFilters);
        element.addEventListener('change', applyFilters);
    });
    filterReseller?.addEventListener('input', applyFilters);
    filterReseller?.addEventListener('change', applyFilters);

    invoiceLink?.addEventListener('click', async (event) => {
        if (invoiceLink.classList.contains('disabled')) {
            event.preventDefault();
            return;
        }

        event.preventDefault();

        try {
            invoiceLink.classList.add('disabled');
            invoiceLink.classList.add('recouvrement-invoice-pending');
            invoiceLink.setAttribute('aria-disabled', 'true');

            const formData = new FormData();
            formData.append('csrf_token', csrfToken);
            formData.append('operator', invoiceLink.dataset.operator || '');
            formData.append('date_from', invoiceLink.dataset.dateFrom || '');
            formData.append('date_to', invoiceLink.dataset.dateTo || '');
            formData.append('selected_lines', invoiceLink.dataset.selectedLines || '');

            const response = await fetch('../api/recouvrement/create_invoice.php', {
                method: 'POST',
                body: formData,
            });
            const data = await response.json();
            if (!response.ok || !data.success) {
                throw new Error(data.message || 'Creation impossible');
            }

            window.open(data.print_url, '_blank');
            getSelectedCheckboxes().forEach((checkbox) => {
                checkbox.checked = false;
            });
            if (selectAll) {
                selectAll.checked = false;
            }
            updateSelectionState();
            window.location.reload();
        } catch (error) {
            AppToast.flash(error.message || 'Creation impossible', 'danger');
            invoiceLink.classList.remove('recouvrement-invoice-pending');
            updateSelectionState();
        }
    });

    applyFilters();
});
