<?php
if ( ! defined( 'METIS_ROOT' ) ) exit;
require_once __DIR__ . '/_settings_bootstrap.php';
$ctx = metis_settings_bootstrap( 'drive' );
if ( empty( $ctx['allowed'] ) ) return;
extract( $ctx, EXTR_SKIP );
?>
<h1 class="mw-page-title"><?php echo metis_escape_html( metis_current_module_view_title( 'Settings' ) ); ?></h1>
<p class="mw-subtitle">Choose the shared drives used across the portal.</p>
<?php metis_settings_render_messages( $saved, $errors ); ?>
<?php metis_settings_render_section_nav( 'drive' ); ?>
<form method="post" class="mw-settings-form" data-metis-settings-form="1" data-settings-section="drive">
    <?php metis_runtime_nonce_field( 'metis_save_settings_drive', 'metis_settings_nonce' ); ?>
    <div class="mw-settings-card">
        <div class="mw-settings-header"><h2>Drive Configurations</h2></div>
        <div class="mw-settings-body">
            <?php if ( ! $is_system_admin ) : ?>
                <div class="mw-callout mw-callout-warning">Only system admins can manage Drive configurations.</div>
            <?php else : ?>
                <p class="mw-help">Add one or more shared drives. Mark one as the default Drive module source and mark one as the users-home drive.</p>
                <?php if ( $workspace_shared_drive_error !== '' ) : ?>
                    <p class="mw-help" style="color:#b91c1c;"><?php echo metis_escape_html( $workspace_shared_drive_error ); ?></p>
                <?php endif; ?>
                <div class="metis-settings-repeatable">
                    <div class="metis-settings-repeatable-list" data-repeatable-list="drive">
                        <?php foreach ( $workspace_drive_configs as $index => $row ) : ?>
                            <div class="metis-settings-row" data-repeatable-row>
                                <div class="mw-field">
                                    <label>Label</label>
                                    <input type="text" class="mw-input" name="workspace_drive_configs[<?php echo (int) $index; ?>][label]" value="<?php echo metis_escape_attr( (string) ( $row['label'] ?? '' ) ); ?>" placeholder="Board Drive">
                                </div>
                                <div class="mw-field">
                                    <label>Shared Drive</label>
                                    <select class="mw-input" name="workspace_drive_configs[<?php echo (int) $index; ?>][drive_id]" data-drive-select>
                                        <option value="">Select shared drive</option>
                                        <?php foreach ( $workspace_shared_drive_options as $opt ) :
                                            $opt_id = (string) ( $opt['id'] ?? '' );
                                            if ( $opt_id === '' ) { continue; }
                                        ?>
                                            <option value="<?php echo metis_escape_attr( $opt_id ); ?>" data-drive-name="<?php echo metis_escape_attr( (string) ( $opt['name'] ?? $opt_id ) ); ?>" <?php metis_attr_selected( (string) ( $row['drive_id'] ?? '' ), $opt_id ); ?>>
                                                <?php echo metis_escape_html( (string) ( $opt['name'] ?? $opt_id ) ); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <input type="hidden" name="workspace_drive_configs[<?php echo (int) $index; ?>][drive_name]" value="<?php echo metis_escape_attr( (string) ( $row['drive_name'] ?? '' ) ); ?>" data-drive-name-input>
                                </div>
                                <label class="metis-settings-flag"><input type="checkbox" name="workspace_drive_configs[<?php echo (int) $index; ?>][is_default]" value="1" <?php metis_attr_checked( ! empty( $row['is_default'] ) ); ?>> Default</label>
                                <label class="metis-settings-flag"><input type="checkbox" name="workspace_drive_configs[<?php echo (int) $index; ?>][is_users_home]" value="1" <?php metis_attr_checked( ! empty( $row['is_users_home'] ) ); ?>> Users folder</label>
                                <button type="button" class="mw-btn mw-btn-xs mw-btn-danger" data-repeatable-remove>Remove</button>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <button type="button" class="mw-btn mw-btn-secondary mw-btn-xs" data-repeatable-add="drive">Add Drive</button>
                </div>
            <?php endif; ?>
        </div>
    </div>
    <?php if ( $is_system_admin ) : ?>
        <div class="mw-settings-card">
            <div class="mw-settings-header"><h2>Drive Sync</h2></div>
            <div class="mw-settings-body">
                <p class="mw-help">Run an immediate listing sync for all configured drives. This refreshes the database-backed Drive cache now instead of waiting for the hourly task.</p>
                <div class="mw-settings-actions" style="justify-content:flex-start;">
                    <button type="button" class="mw-btn mw-btn-secondary" data-drive-sync-now>Sync Drive Cache Now</button>
                </div>
                <div class="mw-help" data-drive-sync-status style="margin-top:10px;"></div>
            </div>
        </div>
    <?php endif; ?>
    <div class="mw-settings-actions">
        <button type="submit" class="mw-btn">Save Drive Settings</button>
    </div>
</form>
<script>
(function () {
    function syncNames(list) {
        list.querySelectorAll('[data-repeatable-row]').forEach(function (row) {
            const select = row.querySelector('[data-drive-select]');
            const input = row.querySelector('[data-drive-name-input]');
            if (!select || !input) return;
            const opt = select.options[select.selectedIndex];
            input.value = opt ? String(opt.dataset.driveName || '') : '';
        });
    }
    function reindex() {
        const list = document.querySelector('[data-repeatable-list="drive"]');
        if (!list) return;
        Array.from(list.querySelectorAll('[data-repeatable-row]')).forEach(function (row, index) {
            row.querySelectorAll('input, select').forEach(function (field) {
                if (!field.name) return;
                field.name = field.name.replace(/workspace_drive_configs\[(\d+)\]/, 'workspace_drive_configs[' + index + ']');
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
                <input type="text" class="mw-input" name="workspace_drive_configs[${index}][label]" placeholder="Board Drive">
            </div>
            <div class="mw-field">
                <label>Shared Drive</label>
                <select class="mw-input" name="workspace_drive_configs[${index}][drive_id]" data-drive-select>
                    <option value="">Select shared drive</option>
                    <?php foreach ( $workspace_shared_drive_options as $opt ) :
                        $opt_id = (string) ( $opt['id'] ?? '' );
                        if ( $opt_id === '' ) { continue; }
                    ?>
                    <option value="<?php echo metis_escape_attr( $opt_id ); ?>" data-drive-name="<?php echo metis_escape_attr( (string) ( $opt['name'] ?? $opt_id ) ); ?>"><?php echo metis_escape_html( (string) ( $opt['name'] ?? $opt_id ) ); ?></option>
                    <?php endforeach; ?>
                </select>
                <input type="hidden" name="workspace_drive_configs[${index}][drive_name]" value="" data-drive-name-input>
            </div>
            <label class="metis-settings-flag"><input type="checkbox" name="workspace_drive_configs[${index}][is_default]" value="1"> Default</label>
            <label class="metis-settings-flag"><input type="checkbox" name="workspace_drive_configs[${index}][is_users_home]" value="1"> Users folder</label>
            <button type="button" class="mw-btn mw-btn-xs mw-btn-danger" data-repeatable-remove>Remove</button>
        `;
        return wrap;
    }
    document.addEventListener('change', function (event) {
        if (event.target.matches('[data-drive-select]')) {
            const list = event.target.closest('[data-repeatable-list="drive"]');
            if (list) syncNames(list);
        }
    });
    document.addEventListener('click', function (event) {
        const add = event.target.closest('[data-repeatable-add="drive"]');
        if (add) {
            const list = document.querySelector('[data-repeatable-list="drive"]');
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
    document.querySelectorAll('[data-repeatable-list="drive"]').forEach(syncNames);
})();
</script>
<?php metis_settings_render_section_end(); ?>
