(function (window) {
    'use strict';

    const Totals = {
        calculate(rows, config) {
            const totals = {};
            const specs = Array.isArray(config.totals) ? config.totals : [];

            specs.forEach((spec) => {
                const key = String(spec.key || '').trim();
                const type = String(spec.type || 'sum').toLowerCase();
                if (!key) return;

                if (type === 'count') {
                    totals[key] = rows.reduce((count, row) => {
                        const value = String((row || {})[key] || '').trim();
                        return value === '' ? count : count + 1;
                    }, 0);
                    return;
                }

                totals[key] = rows.reduce((sum, row) => {
                    const value = Number((row || {})[key] || 0);
                    return Number.isFinite(value) ? sum + value : sum;
                }, 0);
            });

            return totals;
        }
    };

    window.BatchEntryTotals = Totals;
}(window));
