(function (window) {
    'use strict';

    const statusLabel = {
        pending: 'UNSAVED',
        valid: 'VALID',
        warning: 'WARNING',
        invalid: 'INVALID',
        saved: 'SAVED'
    };

    function inputForField(field, value) {
        const key = String(field.key || '');
        const type = String(field.type || 'text').toLowerCase();
        const safeValue = value === undefined || value === null ? '' : String(value);

        if (type === 'select') {
            const options = Array.isArray(field.options) ? field.options : [];
            const optionsHtml = ['<option value=""></option>']
                .concat(options.map((entry) => {
                    const v = typeof entry === 'object' ? String(entry.value || '') : String(entry || '');
                    const l = typeof entry === 'object' ? String(entry.label || v) : v;
                    const selected = v === safeValue ? ' selected' : '';
                    return '<option value="' + escapeHtml(v) + '"' + selected + '>' + escapeHtml(l) + '</option>';
                }))
                .join('');
            return '<select class="metis-select mbe-input" data-key="' + escapeHtml(key) + '">' + optionsHtml + '</select>';
        }

        const htmlType = type === 'number' || type === 'email' || type === 'date' ? type : 'text';
        return '<input class="metis-input mbe-input" data-key="' + escapeHtml(key) + '" type="' + htmlType + '" value="' + escapeHtml(safeValue) + '">';
    }

    function escapeHtml(value) {
        return String(value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    const Renderer = {
        renderShell(container, config) {
            const fields = Array.isArray(config.fields) ? config.fields : [];
            const headers = fields.map((field) => '<th>' + escapeHtml(String(field.label || field.key || '')) + '</th>').join('');

            container.innerHTML = '' +
                '<div class="mbe-root">' +
                    '<div class="mbe-grid-wrap">' +
                        '<table class="mbe-grid">' +
                            '<thead><tr><th>Status</th>' + headers + '<th>Actions</th></tr></thead>' +
                            '<tbody class="mbe-body"></tbody>' +
                        '</table>' +
                    '</div>' +
                    '<div class="mbe-footer">' +
                        '<div class="mbe-counts">' +
                            '<span data-stat="total">Rows: 0</span>' +
                            '<span data-stat="valid">Valid: 0</span>' +
                            '<span data-stat="invalid">Invalid: 0</span>' +
                            '<span data-stat="saved">Saved: 0</span>' +
                        '</div>' +
                        '<div class="mbe-totals" data-slot="totals"></div>' +
                        '<div class="mbe-message" data-slot="message"></div>' +
                    '</div>' +
                '</div>';
        },

        renderRows(container, rows, config) {
            const fields = Array.isArray(config.fields) ? config.fields : [];
            const tbody = container.querySelector('.mbe-body');
            if (!tbody) return;

            tbody.innerHTML = rows.map((row, rowIndex) => {
                const status = String(row.status || 'pending');
                const statusText = statusLabel[status] || status.toUpperCase();
                const statusClass = 'mbe-status mbe-status-' + status;
                const cells = fields.map((field) => {
                    const key = String(field.key || '');
                    return '<td>' + inputForField(field, row[key]) + '</td>';
                }).join('');
                return '' +
                    '<tr class="mbe-row" data-row-index="' + rowIndex + '">' +
                        '<td>' +
                            '<span class="' + statusClass + '">' + escapeHtml(statusText) + '</span>' +
                            (row.errors && row.errors.length ? '<div class="mbe-error">' + escapeHtml(row.errors[0]) + '</div>' : '') +
                        '</td>' +
                        cells +
                        '<td><button type="button" class="metis-btn-xs mbe-delete-row" data-row-index="' + rowIndex + '">Delete</button></td>' +
                    '</tr>';
            }).join('');
        },

        renderFooter(container, stats, totals, config, message) {
            const totalNode = container.querySelector('[data-stat="total"]');
            const validNode = container.querySelector('[data-stat="valid"]');
            const invalidNode = container.querySelector('[data-stat="invalid"]');
            const savedNode = container.querySelector('[data-stat="saved"]');
            const totalsNode = container.querySelector('[data-slot="totals"]');
            const messageNode = container.querySelector('[data-slot="message"]');
            const totalSpecs = Array.isArray(config.totals) ? config.totals : [];

            if (totalNode) totalNode.textContent = 'Rows: ' + (stats.total || 0);
            if (validNode) validNode.textContent = 'Valid: ' + (stats.valid || 0);
            if (invalidNode) invalidNode.textContent = 'Invalid: ' + (stats.invalid || 0);
            if (savedNode) savedNode.textContent = 'Saved: ' + (stats.saved || 0);

            if (totalsNode) {
                totalsNode.innerHTML = totalSpecs.map((spec) => {
                    const key = String(spec.key || '');
                    const label = String(spec.label || key);
                    const value = totals[key] === undefined ? 0 : totals[key];
                    return '<span class="mbe-total-item">' + escapeHtml(label) + ': ' + escapeHtml(String(value)) + '</span>';
                }).join('');
            }

            if (messageNode) {
                messageNode.textContent = String(message || '');
            }
        }
    };

    window.BatchEntryRenderer = Renderer;
}(window));
