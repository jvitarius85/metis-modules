<?php
if ( ! defined( 'METIS_ROOT' ) ) exit;
require_once __DIR__ . '/_settings_bootstrap.php';
$ctx = metis_settings_bootstrap( 'calendar' );
if ( empty( $ctx['allowed'] ) ) return;
extract( $ctx, EXTR_SKIP );
?>
<h1 class="mw-page-title"><?php echo metis_escape_html( metis_current_module_view_title( 'Settings' ) ); ?></h1>
<p class="mw-subtitle">Choose the calendars used across the portal.</p>
<?php metis_settings_render_messages( $saved, $errors ); ?>
<?php metis_settings_render_section_nav( 'calendar' ); ?>
<form method="post" class="mw-settings-form" data-metis-settings-form="1" data-settings-section="calendar">
    <?php metis_runtime_nonce_field( 'metis_save_settings_calendar', 'metis_settings_nonce' ); ?>
    <div class="mw-settings-card">
        <div class="mw-settings-header"><h2>Calendar Configurations</h2></div>
        <div class="mw-settings-body">
            <?php if ( ! $is_system_admin ) : ?>
                <div class="mw-callout mw-callout-warning">Only system admins can manage Calendar configurations.</div>
            <?php else : ?>
                <p class="mw-help">Add one or more calendars. Mark one as the default Calendar module source.</p>
                <?php if ( $workspace_calendar_error !== '' ) : ?>
                    <p class="mw-help" style="color:#b91c1c;"><?php echo metis_escape_html( $workspace_calendar_error ); ?></p>
                <?php endif; ?>
                <div class="metis-settings-repeatable">
                    <div class="metis-settings-repeatable-list" data-repeatable-list="calendar">
                        <?php foreach ( $workspace_calendar_configs as $index => $row ) : ?>
                            <div class="metis-settings-row" data-repeatable-row>
                                <div class="mw-field">
                                    <label>Label</label>
                                    <input type="text" class="mw-input" name="workspace_calendar_configs[<?php echo (int) $index; ?>][label]" value="<?php echo metis_escape_attr( (string) ( $row['label'] ?? '' ) ); ?>" placeholder="Board Calendar">
                                </div>
                                <div class="mw-field">
                                    <label>Calendar</label>
                                    <select class="mw-input" name="workspace_calendar_configs[<?php echo (int) $index; ?>][calendar_id]" data-calendar-select>
                                        <option value="">Select calendar</option>
                                        <?php foreach ( $workspace_calendar_options as $opt ) :
                                            $opt_id = (string) ( $opt['id'] ?? '' );
                                            if ( $opt_id === '' ) { continue; }
                                        ?>
                                            <option value="<?php echo metis_escape_attr( $opt_id ); ?>" data-calendar-name="<?php echo metis_escape_attr( (string) ( $opt['name'] ?? $opt_id ) ); ?>" <?php metis_attr_selected( (string) ( $row['calendar_id'] ?? '' ), $opt_id ); ?>>
                                                <?php echo metis_escape_html( (string) ( $opt['name'] ?? $opt_id ) ); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <input type="hidden" name="workspace_calendar_configs[<?php echo (int) $index; ?>][calendar_name]" value="<?php echo metis_escape_attr( (string) ( $row['calendar_name'] ?? '' ) ); ?>" data-calendar-name-input>
                                </div>
                                <label class="metis-settings-flag"><input type="checkbox" name="workspace_calendar_configs[<?php echo (int) $index; ?>][is_default]" value="1" <?php metis_attr_checked( ! empty( $row['is_default'] ) ); ?>> Default</label>
                                <button type="button" class="mw-btn mw-btn-xs mw-btn-danger" data-repeatable-remove>Remove</button>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <button type="button" class="mw-btn mw-btn-secondary mw-btn-xs" data-repeatable-add="calendar">Add Calendar</button>
                </div>
            <?php endif; ?>
        </div>
    </div>
    <div class="mw-settings-actions">
        <button type="submit" class="mw-btn">Save Calendar Settings</button>
    </div>
</form>
<script>
(function () {
    function syncNames(list) {
        list.querySelectorAll('[data-repeatable-row]').forEach(function (row) {
            const select = row.querySelector('[data-calendar-select]');
            const input = row.querySelector('[data-calendar-name-input]');
            if (!select || !input) return;
            const opt = select.options[select.selectedIndex];
            input.value = opt ? String(opt.dataset.calendarName || '') : '';
        });
    }
    function reindex() {
        const list = document.querySelector('[data-repeatable-list="calendar"]');
        if (!list) return;
        Array.from(list.querySelectorAll('[data-repeatable-row]')).forEach(function (row, index) {
            row.querySelectorAll('input, select').forEach(function (field) {
                if (!field.name) return;
                field.name = field.name.replace(/workspace_calendar_configs\[(\d+)\]/, 'workspace_calendar_configs[' + index + ']');
            });
        });
        syncNames(list);
    }
    function createRow(index) {
        const wrap = document.createElement('div');
        wrap.className = 'metis-settings-row';
        wrap.setAttribute('data-repeatable-row', '');
        wrap.innerHTML = `
            <div class="mw-field">
                <label>Label</label>
                <input type="text" class="mw-input" name="workspace_calendar_configs[${index}][label]" placeholder="Board Calendar">
            </div>
            <div class="mw-field">
                <label>Calendar</label>
                <select class="mw-input" name="workspace_calendar_configs[${index}][calendar_id]" data-calendar-select>
                    <option value="">Select calendar</option>
                    <?php foreach ( $workspace_calendar_options as $opt ) :
                        $opt_id = (string) ( $opt['id'] ?? '' );
                        if ( $opt_id === '' ) { continue; }
                    ?>
                    <option value="<?php echo metis_escape_attr( $opt_id ); ?>" data-calendar-name="<?php echo metis_escape_attr( (string) ( $opt['name'] ?? $opt_id ) ); ?>"><?php echo metis_escape_html( (string) ( $opt['name'] ?? $opt_id ) ); ?></option>
                    <?php endforeach; ?>
                </select>
                <input type="hidden" name="workspace_calendar_configs[${index}][calendar_name]" value="" data-calendar-name-input>
            </div>
            <label class="metis-settings-flag"><input type="checkbox" name="workspace_calendar_configs[${index}][is_default]" value="1"> Default</label>
            <button type="button" class="mw-btn mw-btn-xs mw-btn-danger" data-repeatable-remove>Remove</button>
        `;
        return wrap;
    }
    document.addEventListener('change', function (event) {
        if (event.target.matches('[data-calendar-select]')) {
            const list = event.target.closest('[data-repeatable-list="calendar"]');
            if (list) syncNames(list);
        }
    });
    document.addEventListener('click', function (event) {
        const add = event.target.closest('[data-repeatable-add="calendar"]');
        if (add) {
            const list = document.querySelector('[data-repeatable-list="calendar"]');
            if (!list) return;
            list.appendChild(createRow(list.querySelectorAll('[data-repeatable-row]').length));
            return;
        }
        const remove = event.target.closest('[data-repeatable-remove]');
        if (remove) {
            const row = remove.closest('[data-repeatable-row]');
            if (!row) return;
            row.remove();
            reindex();
        }
    });
    document.querySelectorAll('[data-repeatable-list="calendar"]').forEach(syncNames);
})();
</script>
<?php metis_settings_render_section_end(); ?>
