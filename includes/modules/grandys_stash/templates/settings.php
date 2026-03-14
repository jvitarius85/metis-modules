<?php
if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! metis_grandys_stash_can_view() ) {
    echo '<div class="mw-alert mw-alert-error">You do not have permission to view Grandy&apos;s Stash.</div>';
    return;
}

$state = \Metis\Modules\GrandyStashRepository::dashboardData();
?>
<div class="metis-stash-app" data-can-manage="<?php echo esc_attr( metis_grandys_stash_can_manage() ? '1' : '0' ); ?>">
    <div id="metis-stash-alert" class="mw-alert" style="display:none;"></div>

    <div class="metis-stash-hero">
        <div>
            <h1 class="mw-page-title">Grandy's Stash Settings</h1>
            <p class="mw-subtitle">Control who new request and donation tickets route to by default.</p>
        </div>
    </div>

    <section class="metis-stash-card">
        <div class="metis-stash-card-head">
            <div>
                <h2>Routing defaults</h2>
                <p>Choose who receives new request tickets and new donation tickets when they enter the intake inbox.</p>
            </div>
        </div>
        <form id="metis-stash-routing-form" class="metis-stash-form">
            <div class="metis-stash-form-row">
                <label><span>Default for requests</span><select class="mw-select" name="request_assignee_user_id" id="metis-stash-routing-request"></select></label>
                <label><span>Default for donations</span><select class="mw-select" name="donation_assignee_user_id" id="metis-stash-routing-donation"></select></label>
            </div>
            <?php if ( metis_grandys_stash_can_manage() ) : ?>
                <div class="metis-stash-form-actions">
                    <button type="submit" class="mw-btn">Save routing</button>
                </div>
            <?php endif; ?>
        </form>
    </section>

    <script id="metis-stash-boot" type="application/json"><?php echo wp_json_encode( $state, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ); ?></script>
</div>
