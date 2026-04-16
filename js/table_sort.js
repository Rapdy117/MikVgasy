document.addEventListener('DOMContentLoaded', () => {
    const sortTables = Array.from(document.querySelectorAll('table[data-sort-table="1"]'));

    const parseValue = (rawValue, type) => {
        const value = String(rawValue ?? '').trim();

        if (type === 'number' || type === 'bytes' || type === 'currency') {
            const normalized = value.replace(/\s+/g, '').replace(',', '.');
            const numeric = Number.parseFloat(normalized);
            return Number.isNaN(numeric) ? Number.NEGATIVE_INFINITY : numeric;
        }

        if (type === 'date') {
            const timestamp = Date.parse(value);
            return Number.isNaN(timestamp) ? Number.NEGATIVE_INFINITY : timestamp;
        }

        if (type === 'duration') {
            const matches = value.toLowerCase().match(/(\d+)([wdhms])/g);
            if (!matches) {
                return value.toLowerCase();
            }

            const unitMap = { w: 604800, d: 86400, h: 3600, m: 60, s: 1 };
            return matches.reduce((total, part) => {
                const match = part.match(/(\d+)([wdhms])/);
                if (!match) {
                    return total;
                }

                return total + (Number(match[1]) * (unitMap[match[2]] || 0));
            }, 0);
        }

        return value.toLowerCase();
    };

    const applyIndicators = (table, key, direction) => {
        table.querySelectorAll('th[data-sort-key]').forEach((header) => {
            header.classList.remove('sort-asc', 'sort-desc');
            if (header.dataset.sortKey === key) {
                header.classList.add(direction === 'asc' ? 'sort-asc' : 'sort-desc');
            }
        });
    };

    const getCellRawValue = (row, key, columnIndex) => {
        if (!row || !key) {
            return '';
        }

        const datasetValue = row.dataset ? row.dataset[key] : undefined;
        if (datasetValue !== undefined && datasetValue !== null && String(datasetValue).trim() !== '') {
            return String(datasetValue);
        }

        const keyedCell = row.querySelector(`td[data-column-key="${key}"],th[data-column-key="${key}"]`);
        if (keyedCell) {
            return keyedCell.textContent ?? '';
        }

        if (Number.isInteger(columnIndex) && columnIndex >= 0) {
            const positionalCell = row.cells && row.cells[columnIndex] ? row.cells[columnIndex] : null;
            if (positionalCell) {
                return positionalCell.textContent ?? '';
            }
        }

        return '';
    };

    sortTables.forEach((table) => {
        const tbody = table.tBodies[0];
        if (!tbody) {
            return;
        }

        const headers = Array.from(table.querySelectorAll('th[data-sort-key]'));
        if (headers.length === 0) {
            return;
        }

        const sortRows = (key, direction = 'asc', type = 'text') => {
            const headerForKey = headers.find((header) => header.dataset.sortKey === key) || null;
            const columnIndex = headerForKey ? headerForKey.cellIndex : -1;
            const rows = Array.from(tbody.querySelectorAll('tr')).filter((row) => row.dataset.sortDisabled !== '1');
            rows.sort((rowA, rowB) => {
                const valueA = parseValue(getCellRawValue(rowA, key, columnIndex), type);
                const valueB = parseValue(getCellRawValue(rowB, key, columnIndex), type);

                if (typeof valueA === 'number' && typeof valueB === 'number') {
                    return direction === 'asc' ? valueA - valueB : valueB - valueA;
                }

                const compareResult = String(valueA).localeCompare(String(valueB), 'fr', {
                    numeric: true,
                    sensitivity: 'base',
                });

                return direction === 'asc' ? compareResult : -compareResult;
            });

            rows.forEach((row) => tbody.appendChild(row));
            table.dataset.currentSortKey = key;
            table.dataset.currentSortDirection = direction;
            applyIndicators(table, key, direction);
        };

        headers.forEach((header) => {
            header.classList.add('sortable-column');
            header.addEventListener('click', () => {
                const key = header.dataset.sortKey;
                const type = header.dataset.sortType || 'text';
                if (!key) {
                    return;
                }

                const currentKey = table.dataset.currentSortKey || '';
                const currentDirection = table.dataset.currentSortDirection || 'asc';
                const nextDirection = currentKey === key && currentDirection === 'asc' ? 'desc' : 'asc';

                sortRows(key, nextDirection, type);
            });
        });

        const defaultKey = table.dataset.defaultSortKey || '';
        if (defaultKey) {
            const defaultDirection = table.dataset.defaultSortDirection || 'asc';
            const defaultHeader = headers.find((header) => header.dataset.sortKey === defaultKey);
            sortRows(defaultKey, defaultDirection, defaultHeader?.dataset.sortType || 'text');
        }
    });
});
