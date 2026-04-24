(function (window) {
    'use strict';

    const Validation = {
        validateRow(row, config, allRows) {
            const errors = [];
            const warnings = [];
            const fields = Array.isArray(config.fields) ? config.fields : [];
            const cleanRow = row || {};

            fields.forEach((field) => {
                const key = String(field.key || '').trim();
                if (!key) return;

                const label = String(field.label || key);
                const type = String(field.type || 'text').toLowerCase();
                const required = !!field.required;
                const rawValue = cleanRow[key];
                const value = (rawValue === undefined || rawValue === null) ? '' : String(rawValue).trim();

                if (required && value === '') {
                    errors.push(label + ' is required.');
                    return;
                }

                if (value === '') {
                    return;
                }

                if (type === 'email') {
                    const emailPattern = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
                    if (!emailPattern.test(value)) {
                        errors.push(label + ' must be a valid email address.');
                    }
                }

                if (type === 'number' && Number.isNaN(Number(value))) {
                    errors.push(label + ' must be numeric.');
                }

                if (type === 'date' && !/^\d{4}-\d{2}-\d{2}$/.test(value)) {
                    errors.push(label + ' must be YYYY-MM-DD.');
                }

                const allowedValues = Array.isArray(field.allowed_values) ? field.allowed_values : [];
                const optionValues = Array.isArray(field.options) ? field.options.map((entry) => (entry && typeof entry === "object") ? String(entry.value || "") : String(entry || "")) : [];
                const allowed = allowedValues.concat(optionValues).map((entry) => String(entry)).filter((entry, idx, arr) => entry !== "" && arr.indexOf(entry) === idx);
                if (allowed.length > 0 && !allowed.includes(value)) {
                    errors.push(label + " is not in the allowed options.");
                }
            });

            fields.forEach((field) => {
                if (!field || !field.unique_in_batch || !field.key) return;
                const key = String(field.key);
                const value = String(cleanRow[key] || '').trim().toLowerCase();
                if (!value) return;

                let duplicates = 0;
                (allRows || []).forEach((candidate) => {
                    const compare = String((candidate && candidate[key]) || '').trim().toLowerCase();
                    if (compare && compare === value) duplicates += 1;
                });

                if (duplicates > 1) {
                    errors.push((field.label || key) + ' is duplicated in this batch.');
                }
            });

            if (errors.length > 0) {
                return { status: 'invalid', errors: errors, warnings: warnings };
            }

            if (warnings.length > 0) {
                return { status: 'warning', errors: [], warnings: warnings };
            }

            return { status: 'valid', errors: [], warnings: [] };
        }
    };

    window.BatchEntryValidation = Validation;
}(window));
