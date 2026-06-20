<?php
if ( ! defined( 'METIS_ROOT' ) ) exit;

if ( ! metis_grandys_stash_can_view() ) {
    echo '<div class="metis-alert metis-alert-error">You do not have permission to view Grandy&apos;s Stash.</div>';
    return;
}

$state = \Metis\Modules\GrandyStash\GrandyStashRepository::dashboardData();
$email_prefs = \Metis\Modules\GrandyStash\GrandyStashRepository::getEmailPrefs();
$can_settings = function_exists( 'metis_grandys_stash_can_settings' ) && metis_grandys_stash_can_settings();

if ( ! $can_settings ) {
    echo '<div class="metis-alert metis-alert-error">You do not have permission to manage Grandy&apos;s Stash settings.</div>';
    return;
}
?>

<div class="metis-stash-app"
     data-can-manage="1"
     data-can-settings="1">

    <h1 class="metis-page-title"><?php echo metis_escape_html( metis_current_module_view_title( "Grandy's Stash Settings" ) ); ?></h1>
    <p class="metis-subtitle">Routing defaults and daily summary email subscriptions.</p>
    <div id="metis-stash-alert" class="metis-alert" style="display:none;"></div>

    <?php metis_render_sidebar_layout([
        'sidebar' => static function () { ?>
            <div class="metis-list-sidebar-section">
                <div class="metis-list-sidebar-label">Navigation</div>
                <nav class="metis-list-sidebar-nav" aria-label="Grandy's Stash navigation">
                    <a href="<?php echo metis_escape_url( metis_grandys_stash_base_url() ); ?>" class="metis-list-sidebar-nav-item">Inbox</a>
                    <a href="<?php echo metis_escape_url( metis_grandys_stash_base_url() . '/groups/' ); ?>" class="metis-list-sidebar-nav-item">People Groups</a>
                    <a href="<?php echo metis_escape_url( metis_grandys_stash_base_url() . '/organizations/' ); ?>" class="metis-list-sidebar-nav-item">Organizations</a>
                    <a href="<?php echo metis_escape_url( metis_grandys_stash_base_url() . '/reports/' ); ?>" class="metis-list-sidebar-nav-item">Reports</a>
                    <a href="<?php echo metis_escape_url( metis_grandys_stash_base_url() . '/settings/' ); ?>" class="metis-list-sidebar-nav-item is-active">Settings</a>
                </nav>
            </div>
        <?php },
        'content' => static function () use ( $state, $email_prefs ) {
            $assignees = $state['assignees'] ?? [];
            $routing   = $state['routing_defaults'] ?? [];
        ?>

    <section style="margin-bottom:30px;">
        <h2 style="font-size:18px;margin:0 0 8px;">Routing Defaults</h2>
        <p class="metis-muted" style="margin:0 0 16px;">Choose who receives new request and donation tickets by default.</p>
        <form id="metis-stash-routing-form" class="metis-stash-form">
            <div class="metis-stash-form-row">
                <label><span>Default for requests</span>
                    <select class="metis-select" name="request_assignee_user_id" id="metis-stash-routing-request">
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
                    <select class="metis-select" name="donation_assignee_user_id" id="metis-stash-routing-donation">
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
            <div class="metis-stash-form-actions">
                <button type="submit" class="metis-btn">Save Routing</button>
            </div>
        </form>
    </section>

    <section style="margin-bottom:30px;">
        <h2 style="font-size:18px;margin:0 0 8px;">Remote Legacy Source</h2>
        <p class="metis-muted" style="margin:0 0 16px;">Configure the remote WordPress export endpoint and shared secret used for importing old Grandy&apos;s Stash tickets.</p>
        <form id="metis-stash-legacy-settings-form" class="metis-stash-form">
            <div class="metis-stash-form-row">
                <label style="flex:1 1 100%;"><span>Remote endpoint URL</span>
                    <input class="metis-input" type="url" name="endpoint_url" value="<?php echo metis_escape_attr( (string) ( $state['legacy_import_settings']['endpoint_url'] ?? '' ) ); ?>" placeholder="https://mobilizewaco.org/wp-json/metis/v1/grandys-stash-export">
                </label>
            </div>
            <div class="metis-stash-form-row">
                <label style="flex:1 1 100%;"><span>Shared secret</span>
                    <input class="metis-input" type="password" name="secret" value="" placeholder="<?php echo ! empty( $state['legacy_import_settings']['secret_configured'] ) ? 'Configured. Enter a new value only to rotate it.' : 'Paste the export secret'; ?>">
                </label>
            </div>
            <div class="metis-stash-form-actions">
                <button type="submit" class="metis-btn">Save Remote Source</button>
            </div>
        </form>
    </section>

    <section style="margin-bottom:30px;">
        <h2 style="font-size:18px;margin:0 0 8px;">Legacy Ticket Import</h2>
        <p class="metis-muted" style="margin:0 0 16px;">Import tickets from the old Gravity Forms system. Existing imported entries are skipped using the legacy parent entry ID.</p>
        <form id="metis-stash-legacy-import-form" class="metis-stash-form">
            <div class="metis-stash-form-row">
                <label><span>Gravity Form ID</span>
                    <input class="metis-input" type="number" name="form_id" min="1" step="1" value="17">
                </label>
                <label><span>Max entries this run</span>
                    <input class="metis-input" type="number" name="limit" min="1" max="1000" step="1" value="500">
                </label>
            </div>
            <div class="metis-stash-form-actions">
                <button type="submit" class="metis-btn">Import Legacy Tickets</button>
            </div>
            <div id="metis-stash-legacy-import-result" class="metis-muted" style="margin-top:12px;"></div>
        </form>
    </section>

    <section style="margin-bottom:30px;">
        <h2 style="font-size:18px;margin:0 0 8px;">One-Time Legacy Wipe</h2>
        <p class="metis-muted" style="margin:0 0 16px;">Deletes tickets imported from the old Gravity Forms source so you can do a clean reimport. This does not remove manually-created tickets.</p>
        <form id="metis-stash-legacy-wipe-form" class="metis-stash-form">
            <div class="metis-stash-form-actions" style="justify-content:flex-start;">
                <button type="submit" class="metis-btn metis-btn-ghost">Wipe Legacy Imported Tickets</button>
            </div>
            <div id="metis-stash-legacy-wipe-result" class="metis-muted" style="margin-top:12px;"></div>
        </form>
    </section>

    <section>
        <h2 style="font-size:18px;margin:0 0 8px;">Daily Summary Email</h2>
        <p class="metis-muted" style="margin:0 0 16px;">Toggle who receives the daily digest of new, waitlisted, and aging tickets.</p>

    <table class="metis-premium-table metis-stash-email-table">
        <thead>
            <tr class="metis-premium-row metis-premium-header">
                <th class="metis-premium-cell" scope="col">Name</th>
                <th class="metis-premium-cell" scope="col">Email</th>
                <th class="metis-premium-cell" scope="col">Receives Summary</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ( $email_prefs as $pref ) :
            $uid     = (int) ( $pref['user_id'] ?? 0 );
            $checked = ! empty( $pref['receive_grandys_summary'] );
        ?>
        <tr class="metis-premium-row">
            <td class="metis-premium-cell"><?php echo metis_escape_html( (string) ( $pref['display_name'] ?? '' ) ); ?></td>
            <td class="metis-premium-cell"><?php echo metis_escape_html( (string) ( $pref['user_email'] ?? '' ) ); ?></td>
            <td class="metis-premium-cell">
                <label style="cursor:pointer;display:inline-flex;align-items:center;gap:6px;">
                    <input type="checkbox" class="metis-stash-email-toggle" data-user-id="<?php echo metis_escape_attr( (string) $uid ); ?>"
                            <?php echo $checked ? 'checked' : ''; ?>>
                        <span><?php echo $checked ? 'Yes' : 'No'; ?></span>
                    </label>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </section>

        <?php },
    ]); ?>

    <script id="metis-stash-boot" type="application/json"><?php echo metis_json_encode( $state, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ); ?></script>
</div>
