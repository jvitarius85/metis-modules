document.addEventListener('DOMContentLoaded', function () {
    const search = document.getElementById('metis-finance-search');
    const rows = Array.from(document.querySelectorAll('.metis-finance-search-row'));
    const activityForm = document.querySelector('.metis-finance-activity-form');
    const activityType = document.getElementById('metis-activity-type');
    const fromLabel = document.querySelector('.metis-activity-from-label');
    const toLabel = document.querySelector('.metis-activity-to-label');

    if (search && rows.length) {
        const applySearch = () => {
            const query = search.value.trim().toLowerCase();

            rows.forEach((row) => {
                const haystack = (row.dataset.search || '').toLowerCase();
                row.style.display = query === '' || haystack.includes(query) ? '' : 'none';
            });
        };

        search.addEventListener('input', applySearch);
    }

    if (activityForm && activityType) {
        const syncActivityMode = () => {
            const mode = activityType.value || '';
            activityForm.dataset.mode = mode;

            if (fromLabel) {
                fromLabel.textContent = mode === 'transfer' ? 'Move From' : mode === 'adjustment' ? 'Adjust Account' : 'Paid From';
            }

            if (toLabel) {
                toLabel.textContent = mode === 'adjustment' ? 'Offset Against' : 'Move To';
            }
        };

        activityType.addEventListener('change', syncActivityMode);
        syncActivityMode();
    }
});
