(function (window) {
    'use strict';

    function uid(prefix) {
        return (prefix || 'tmp_') + Math.random().toString(36).slice(2, 10);
    }

    function newRow(config) {
        const row = {
            row_id: uid('tmp_'),
            status: 'pending',
            errors: []
        };

        (config.fields || []).forEach((field) => {
            row[String(field.key || '')] = '';
        });

        return row;
    }

    function isMeaningfulRow(row, config) {
        return (config.fields || []).some((field) => {
            const key = String(field.key || '');
            const value = row ? row[key] : '';
            return String(value || '').trim() !== '';
        });
    }

    const Engine = {
        init(config) {
            const target = document.querySelector(String(config.container || ''));
            if (!target) {
                return null;
            }

            const state = {
                config: config,
                root: target,
                rows: [newRow(config)],
                stats: { total: 0, valid: 0, invalid: 0, saved: 0 },
                message: ''
            };

            const api = {
                addRow() {
                    state.rows.push(newRow(config));
                    render();
                },
                validateRow(index) {
                    const row = state.rows[index];
                    if (!row) return;
                    const result = window.BatchEntryValidation.validateRow(row, config, state.rows);
                    row.status = result.status;
                    row.errors = result.errors || [];
                },
                save() {
                    const validRows = [];
                    const skipped = [];

                    state.rows.forEach((row, index) => {
                        if (!isMeaningfulRow(row, config)) {
                            return;
                        }

                        api.validateRow(index);
                        if (row.status === 'valid') {
                            validRows.push(Object.assign({}, row));
                        } else {
                            skipped.push(row);
                        }
                    });

                    if (validRows.length < 1) {
                        state.message = 'No valid rows to save.';
                        render();
                        return Promise.resolve();
                    }

                    let batchEndpointBase = String(config.endpointBase || '/api/batch');
                    while (batchEndpointBase.length > 1 && batchEndpointBase.endsWith('/')) {
                        batchEndpointBase = batchEndpointBase.slice(0, -1);
                    }

                    const payload = {
                        module: config.module,
                        action: config.action,
                        nonce: String(config.nonce || ''),
                        rows: validRows
                    };

                    return fetch(batchEndpointBase + '/' + encodeURIComponent(config.module) + '/' + encodeURIComponent(config.action), {
                        method: 'POST',
                        credentials: 'same-origin',
                        headers: {
                            'Content-Type': 'application/json'
                        },
                        body: JSON.stringify(payload)
                    })
                        .then((response) => response.json())
                        .then((data) => {
                            const results = Array.isArray(data.results) ? data.results : [];
                            let savedCount = 0;

                            results.forEach((result) => {
                                const rowId = String(result.row_id || '');
                                const status = String(result.status || 'error');
                                const targetRow = state.rows.find((entry) => String(entry.row_id || '') === rowId);
                                if (!targetRow) return;

                                if (status === 'saved') {
                                    savedCount += 1;
                                    targetRow.status = 'saved';
                                    targetRow.errors = [];
                                } else {
                                    targetRow.status = 'invalid';
                                    targetRow.errors = [String(result.message || 'Save failed.')];
                                }
                            });

                            state.message = savedCount + ' rows saved. ' + skipped.length + ' rows skipped.';
                            render();
                        })
                        .catch((error) => {
                            state.message = (error && error.message) ? error.message : 'Batch save failed.';
                            render();
                        });
                }
            };

            function recompute() {
                let total = 0;
                let valid = 0;
                let invalid = 0;
                let saved = 0;

                state.rows.forEach((row) => {
                    if (!isMeaningfulRow(row, config)) return;
                    total += 1;
                    if (row.status === 'valid') valid += 1;
                    if (row.status === 'invalid' || row.status === 'warning') invalid += 1;
                    if (row.status === 'saved') saved += 1;
                });

                state.stats = { total: total, valid: valid, invalid: invalid, saved: saved };
            }

            function maybeAppendRow() {
                if (!config.autoAppendRow) return;
                if (state.rows.length < 1) {
                    state.rows.push(newRow(config));
                    return;
                }

                const last = state.rows[state.rows.length - 1];
                if (isMeaningfulRow(last, config)) {
                    state.rows.push(newRow(config));
                }
            }

            function bindEvents() {
                state.root.addEventListener('input', (event) => {
                    const input = event.target.closest('.mbe-input');
                    if (!input) return;
                    const cell = input.closest('.mbe-row');
                    if (!cell) return;

                    const rowIndex = Number(cell.getAttribute('data-row-index') || '-1');
                    const key = String(input.getAttribute('data-key') || '');
                    if (rowIndex < 0 || !key || !state.rows[rowIndex]) return;

                    state.rows[rowIndex][key] = input.value;
                    maybeAppendRow();
                    render();
                });

                state.root.addEventListener('blur', (event) => {
                    const input = event.target.closest('.mbe-input');
                    if (!input) return;
                    const cell = input.closest('.mbe-row');
                    if (!cell) return;

                    const rowIndex = Number(cell.getAttribute('data-row-index') || '-1');
                    if (rowIndex < 0) return;
                    api.validateRow(rowIndex);
                    render();
                }, true);

                state.root.addEventListener('keydown', (event) => {
                    const input = event.target.closest('.mbe-input');
                    if (!input) return;
                    const row = input.closest('.mbe-row');
                    if (!row) return;

                    const currentRow = Number(row.getAttribute('data-row-index') || '-1');
                    const cells = Array.from(row.querySelectorAll('.mbe-input'));
                    const col = cells.indexOf(input);

                    if (event.key === 'Tab') {
                        return;
                    }

                    if (event.key === 'Enter') {
                        event.preventDefault();
                        const nextRow = state.root.querySelector('.mbe-row[data-row-index="' + (currentRow + 1) + '"] .mbe-input[data-key="' + input.getAttribute('data-key') + '"]');
                        if (nextRow) nextRow.focus();
                    }
                });

                state.root.addEventListener('click', (event) => {
                    const delBtn = event.target.closest('.mbe-delete-row');
                    if (delBtn) {
                        const index = Number(delBtn.getAttribute('data-row-index') || '-1');
                        if (index >= 0 && state.rows[index]) {
                            state.rows.splice(index, 1);
                            if (state.rows.length < 1) {
                                state.rows.push(newRow(config));
                            }
                            render();
                        }
                        return;
                    }

                    const saveBtn = event.target.closest('[data-batch-action="save"]');
                    if (saveBtn) {
                        event.preventDefault();
                        api.save();
                    }

                    const addBtn = event.target.closest('[data-batch-action="add"]');
                    if (addBtn) {
                        event.preventDefault();
                        api.addRow();
                    }
                });
            }

            function render() {
                window.BatchEntryRenderer.renderRows(state.root, state.rows, config);
                recompute();
                const totals = window.BatchEntryTotals.calculate(state.rows, config);
                window.BatchEntryRenderer.renderFooter(state.root, state.stats, totals, config, state.message);
            }

            window.BatchEntryRenderer.renderShell(state.root, config);
            bindEvents();
            render();

            return api;
        }
    };

    window.BatchEntry = Engine;
}(window));
