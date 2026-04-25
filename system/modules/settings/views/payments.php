<?php
if ( ! defined( 'METIS_ROOT' ) ) exit;
require_once __DIR__ . '/_settings_bootstrap.php';
$ctx = metis_settings_bootstrap( 'payments' );
if ( empty( $ctx['allowed'] ) ) return;
extract( $ctx, EXTR_SKIP );
?>
<h1 class="metis-page-title"><?php echo metis_escape_html( metis_current_module_view_title( 'Settings' ) ); ?></h1>
<p class="metis-subtitle">Platform-wide payment defaults for forms and donation flows.</p>
<?php metis_settings_render_messages( $saved, $errors ); ?>
<?php metis_settings_render_section_nav( 'payments' ); ?>
<form method="post" class="metis-settings-form" data-metis-settings-form="1" data-settings-section="payments">
    <?php metis_runtime_nonce_field( 'metis_save_settings_payments', 'metis_settings_nonce' ); ?>

    <div class="metis-settings-card">
        <div class="metis-settings-header">
            <h2>Stripe Defaults</h2>
            <span class="metis-settings-status <?php echo $is_system_admin ? 'is-ok' : 'is-missing'; ?>"><?php echo $is_system_admin ? 'System Admin' : 'Restricted'; ?></span>
        </div>
        <div class="metis-settings-body">
            <?php if ( ! $is_system_admin ) : ?>
                <div class="metis-callout metis-callout-warning">Only system admins can update payment defaults.</div>
            <?php else : ?>
                <div class="metis-grid metis-grid-2">
                    <div class="metis-field">
                        <label for="stripe_platform_fee_percent">Default Stripe Processing Fee Percent</label>
                        <input type="number" id="stripe_platform_fee_percent" name="stripe_platform_fee_percent" class="metis-input metis-input-wide" min="0" step="0.01" value="<?php echo metis_escape_attr( (string) \Core_Settings_Service::get( 'stripe_platform_fee_percent', '2.9' ) ); ?>">
                        <p class="metis-help">Used as the default fee rate for payment fields. Current Stripe default is 2.9%.</p>
                    </div>
                    <div class="metis-field">
                        <label for="stripe_platform_fee_fixed">Default Stripe Fixed Fee</label>
                        <input type="number" id="stripe_platform_fee_fixed" name="stripe_platform_fee_fixed" class="metis-input metis-input-wide" min="0" step="0.01" value="<?php echo metis_escape_attr( (string) \Core_Settings_Service::get( 'stripe_platform_fee_fixed', '0.30' ) ); ?>">
                        <p class="metis-help">Used as the default fixed fee for payment fields. Current Stripe default is $0.30.</p>
                    </div>
                </div>
                <div class="metis-field">
                    <label for="stripe_cover_fee_label">Default Fee Coverage Label</label>
                    <input type="text" id="stripe_cover_fee_label" name="stripe_cover_fee_label" class="metis-input metis-input-wide" value="<?php echo metis_escape_attr( (string) \Core_Settings_Service::get( 'stripe_cover_fee_label', 'I would like to cover the processing fees.' ) ); ?>">
                    <p class="metis-help">Used when a payment field lets donors or payors choose to cover processing fees.</p>
                </div>
                <div class="metis-callout metis-callout-info">
                    Fee formula used by forms when the payor covers fees:
                    <code>(amount * percent) + fixed fee</code>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="metis-settings-actions">
        <button type="submit" class="metis-btn">Save Payment Settings</button>
    </div>
</form>
<?php metis_settings_render_section_end(); ?>
