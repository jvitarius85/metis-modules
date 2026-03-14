<?php
if ( ! defined( 'ABSPATH' ) ) exit;
require_once __DIR__ . '/_settings_bootstrap.php';
$ctx = metis_settings_bootstrap( 'menu' );
if ( empty( $ctx['allowed'] ) ) return;
extract( $ctx, EXTR_SKIP );
?>
<h1 class="mw-page-title">Settings</h1>
<p class="mw-subtitle">Reorder the sidebar menu items used throughout the portal.</p>
<?php metis_settings_render_messages( $saved, $errors ); ?>
<?php metis_settings_render_section_nav( 'menu' ); ?>
<form method="post" class="mw-settings-form" data-metis-settings-form="1">
    <?php metis_nonce_field( 'metis_save_settings_menu', 'metis_settings_nonce' ); ?>
    <div class="mw-settings-card">
        <div class="mw-settings-header"><h2>Menu Order</h2></div>
        <div class="mw-settings-body">
            <p class="mw-help">Use the controls below to move modules up or down. The sidebar will follow this saved order.</p>
            <div class="metis-menu-order" data-menu-order-root>
                <?php foreach ( $menu_modules as $module ) : ?>
                    <div class="metis-menu-order-item" data-menu-order-item>
                        <input type="hidden" name="menu_module_order[]" value="<?php echo esc_attr( (string) $module['slug'] ); ?>">
                        <div class="metis-menu-order-labels">
                            <strong><?php echo esc_html( (string) $module['label'] ); ?></strong>
                            <span><?php echo esc_html( (string) $module['slug'] ); ?></span>
                        </div>
                        <div class="metis-menu-order-actions">
                            <button type="button" class="mw-btn mw-btn-xs mw-btn-secondary" data-menu-move="up">Up</button>
                            <button type="button" class="mw-btn mw-btn-xs mw-btn-secondary" data-menu-move="down">Down</button>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <div class="mw-settings-actions">
        <button type="submit" class="mw-btn">Save Menu Order</button>
    </div>
</form>
