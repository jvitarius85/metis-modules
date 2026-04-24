<?php
if ( ! defined( 'METIS_ROOT' ) ) exit;

if ( ! metis_grandys_stash_can_view() ) {
    echo '<div class="mw-alert mw-alert-error">You do not have permission to view Grandy&apos;s Stash.</div>';
    return;
}

$state = \Metis\Modules\GrandyStash\GrandyStashRepository::dashboardData();
$email_prefs = \Metis\Modules\GrandyStash\GrandyStashRepository::getEmailPrefs();
$can_manage = metis_grandys_stash_can_manage();
?>

<div class="metis-stash-app"
     data-can-manage="<?php echo metis_escape_attr( $can_manage ? '1' : '0' ); ?>">

    <h1 class="mw-page-title"><?php echo metis_escape_html( metis_current_module_view_title( "Grandy's Stash Settings" ) ); ?></h1>
    <p class="mw-subtitle">Routing defaults and daily summary email subscriptions.</p>
    <div id="metis-stash-alert" class="mw-alert" style="display:none;"></div>

    <?php metis_render_sidebar_layout([
        'sidebar' => static function () { ?>
            <div class="mw-list-sidebar-section">
                <div class="mw-list-sidebar-label">Navigation</div>
                <a href="<?php echo metis_escape_url( metis_grandys_stash_base_url() ); ?>" class="mw-btn mw-btn-xs mw-btn-ghost">Inbox</a>
                <a href="<?php echo metis_escape_url( metis_grandys_stash_base_url() . '/reports/' ); ?>" class="mw-btn mw-btn-xs mw-btn-ghost">Reports</a>
                <a href="<?php echo metis_escape_url( metis_grandys_stash_base_url() . '/settings/' ); ?>" class="mw-btn mw-btn-xs">Settings</a>
            </div>
        <?php },
        'content' => static function () use ( $state, $email_prefs, $can_manage ) {
            $assignees = $state['assignees'] ?? [];
            $routing   = $state['routing_defaults'] ?? [];
        ?>

    <section style="margin-bottom:30px;">
        <h2 style="font-size:18px;margin:0 0 8px;">Routing Defaults</h2>
        <p class="mw-muted" style="margin:0 0 16px;">Choose who receives new request and donation tickets by default.</p>
        <form id="metis-stash-routing-form" class="metis-stash-form">
            <div class="metis-stash-form-row">
                <label><span>Default for requests</span>
                    <select class="mw-select" name="request_assignee_user_id" id="metis-stash-routing-request">
                        <option value="">Unassigned</option>
                        <?php foreach ( $assignees as $a ) : ?>
                        <option value="<?php echo metis_escape_attr( (string) $a['id'] ); ?>"
                            <?php echo (int)($a['id'] ?? 0) === (int)($routing['request_assignee_user_id'] ?? 0) ? 'selected' : ''; ?>>
                            <?php echo metis_escape_html( (string) $a['label'] ); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label><span>Default for donations</span>
                    <select class="mw-select" name="donation_assignee_user_id" id="metis-stash-routing-donation">
                        <option value="">Unassigned</option>
                        <?php foreach ( $assignees as $a ) : ?>
                        <option value="<?php echo metis_escape_attr( (string) $a['id'] ); ?>"
                            <?php echo (int)($a['id'] ?? 0) === (int)($routing['donation_assignee_user_id'] ?? 0) ? 'selected' : ''; ?>>
                            <?php echo metis_escape_html( (string) $a['label'] ); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </label>
            </div>
            <?php if ( $can_manage ) : ?>
            <div class="metis-stash-form-actions">
                <button type="submit" class="mw-btn">Save Routing</button>
            </div>
            <?php endif; ?>
        </form>
    </section>

    <section>
        <h2 style="font-size:18px;margin:0 0 8px;">Daily Summary Email</h2>
        <p class="mw-muted" style="margin:0 0 16px;">Toggle who receives the daily digest of new, waitlisted, and aging tickets.</p>

        <div class="mw-premium-table metis-stash-email-table">
            <div class="mw-premium-header">
                <div class="mw-premium-cell">Name</div>
                <div class="mw-premium-cell">Email</div>
                <div class="mw-premium-cell">Receives Summary</div>
            </div>
            <?php foreach ( $email_prefs as $pref ) :
                $uid     = (int) ( $pref['user_id'] ?? 0 );
                $checked = ! empty( $pref['receive_grandys_summary'] );
            ?>
            <div class="mw-premium-row">
                <div class="mw-premium-cell"><?php echo metis_escape_html( (string) ( $pref['display_name'] ?? '' ) ); ?></div>
                <div class="mw-premium-cell"><?php echo metis_escape_html( (string) ( $pref['user_email'] ?? '' ) ); ?></div>
                <div class="mw-premium-cell">
                    <?php if ( $can_manage ) : ?>
                    <label style="cursor:pointer;display:inline-flex;align-items:center;gap:6px;">
                        <input type="checkbox" class="metis-stash-email-toggle" data-user-id="<?php echo metis_escape_attr( (string) $uid ); ?>"
                            <?php echo $checked ? 'checked' : ''; ?>>
                        <span><?php echo $checked ? 'Yes' : 'No'; ?></span>
                    </label>
                    <?php else : ?>
                    <?php echo $checked ? 'Yes' : 'No'; ?>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </section>

        <?php },
    ]); ?>

    <script id="metis-stash-boot" type="application/json"><?php echo metis_json_encode( $state, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ); ?></script>
</div>
