window.MetisPeopleProfileModules = window.MetisPeopleProfileModules || {};

window.MetisPeopleProfileModules.initRoles = function (context) {
    const nowLocalDateTimeValue = context.nowLocalDateTimeValue;
    const addDaysLocalDateTimeValue = context.addDaysLocalDateTimeValue;

    document.querySelectorAll('.metis-role-window-preset').forEach(function (preset) {
        preset.addEventListener('change', function () {
            const roleKey = String(preset.dataset.roleKey || '').trim();
            if (!roleKey) return;
            const startInput = document.querySelector('.metis-role-start[data-role-key="' + roleKey + '"]');
            const endInput = document.querySelector('.metis-role-end[data-role-key="' + roleKey + '"]');
            if (!startInput || !endInput) return;
            const mode = String(preset.value || '');
            if (mode === 'always') {
                startInput.value = '';
                endInput.value = '';
                return;
            }
            if (mode === '30d' || mode === '90d') {
                const days = mode === '30d' ? 30 : 90;
                const start = nowLocalDateTimeValue();
                startInput.value = start;
                endInput.value = addDaysLocalDateTimeValue(start, days);
            }
        });
    });
};
