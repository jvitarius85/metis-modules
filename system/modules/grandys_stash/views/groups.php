<?php
if ( ! defined( 'METIS_ROOT' ) ) exit;

if ( ! metis_grandys_stash_can_view() ) {
    echo '<div class="metis-alert metis-alert-error">You do not have permission to view Grandy&apos;s Stash.</div>';
    return;
}

$state = \Metis\Modules\GrandyStash\GrandyStashRepository::dashboardData();
$groups = $state['groups'] ?? [];
$can_assign = function_exists( 'metis_grandys_stash_can_assign' ) && metis_grandys_stash_can_assign();
$can_settings = function_exists( 'metis_grandys_stash_can_settings' ) && metis_grandys_stash_can_settings();
?>

<div class="metis-stash-app metis-stash-groups-page"
     data-can-manage="<?php echo metis_escape_attr( $can_assign ? '1' : '0' ); ?>"
     data-stash-view="groups"
     data-view-base-url="<?php echo metis_escape_attr( metis_grandys_stash_view_url() ); ?>">

    <h1 class="metis-page-title"><?php echo metis_escape_html( metis_current_module_view_title( "Grandy's Stash People Groups" ) ); ?></h1>
    <p class="metis-subtitle">Search person groups, review ticket history, and manage core group details.</p>
    <div id="metis-stash-alert" class="metis-alert" style="display:none;"></div>

    <?php metis_render_sidebar_layout([
        'sidebar' => static function () use ( $can_settings ) { ?>
            <div class="metis-list-sidebar-section">
                <div class="metis-list-sidebar-label">Search</div>
                <input id="metis-stash-group-search" class="metis-input" type="text" placeholder="Name, code, email, or phone">
            </div>
            <div class="metis-list-sidebar-section">
                <div class="metis-list-sidebar-label">Filter</div>
                <button type="button" class="metis-btn metis-btn-xs metis-stash-manager-filter is-active" data-manager-filter="all">All Groups</button>
                <button type="button" class="metis-btn metis-btn-xs metis-btn-ghost metis-stash-manager-filter" data-manager-filter="open">Open Tickets</button>
                <button type="button" class="metis-btn metis-btn-xs metis-btn-ghost metis-stash-manager-filter" data-manager-filter="recent">Recently Active</button>
            </div>
            <div class="metis-list-sidebar-section">
                <div class="metis-list-sidebar-label">Navigation</div>
                <nav class="metis-list-sidebar-nav" aria-label="Grandy's Stash navigation">
                    <a href="<?php echo metis_escape_url( metis_grandys_stash_base_url() ); ?>" class="metis-list-sidebar-nav-item">Inbox</a>
                    <a href="<?php echo metis_escape_url( metis_grandys_stash_base_url() . '/groups/' ); ?>" class="metis-list-sidebar-nav-item is-active">People Groups</a>
                    <a href="<?php echo metis_escape_url( metis_grandys_stash_base_url() . '/organizations/' ); ?>" class="metis-list-sidebar-nav-item">Organizations</a>
                    <a href="<?php echo metis_escape_url( metis_grandys_stash_base_url() . '/reports/' ); ?>" class="metis-list-sidebar-nav-item">Reports</a>
                    <?php if ( $can_settings ) : ?>
                    <a href="<?php echo metis_escape_url( metis_grandys_stash_base_url() . '/settings/' ); ?>" class="metis-list-sidebar-nav-item">Settings</a>
                    <?php endif; ?>
                </nav>
            </div>
        <?php },
        'content' => static function () use ( $groups, $can_assign ) { ?>
            <section class="metis-stash-manager-layout">
                <div class="metis-stash-manager-list">
                    <table class="metis-premium-table metis-stash-manager-table">
                        <thead>
                            <tr class="metis-premium-row metis-premium-header">
                                <th class="metis-premium-cell" scope="col">Group</th>
                                <th class="metis-premium-cell" scope="col">Tickets</th>
                                <th class="metis-premium-cell" scope="col">Open</th>
                                <th class="metis-premium-cell" scope="col">Last Activity</th>
                            </tr>
                        </thead>
                        <tbody id="metis-stash-group-rows">
                            <?php foreach ( $groups as $group ) : ?>
                            <tr class="metis-premium-row metis-stash-manager-row"
                                data-manager-kind="group"
                                data-id="<?php echo metis_escape_attr( (string) ( $group['id'] ?? 0 ) ); ?>"
                                data-open-count="<?php echo metis_escape_attr( (string) ( $group['open_count'] ?? 0 ) ); ?>"
                                data-last-ticket="<?php echo metis_escape_attr( (string) ( $group['last_ticket_at'] ?? '' ) ); ?>"
                                data-search="<?php echo metis_escape_attr( strtolower( implode( ' ', [ (string) ( $group['code'] ?? '' ), (string) ( $group['name'] ?? '' ), (string) ( $group['email'] ?? '' ), (string) ( $group['phone'] ?? '' ) ] ) ) ); ?>">
                                <td class="metis-premium-cell"><strong><?php echo metis_escape_html( (string) ( $group['name'] ?? 'Unknown' ) ); ?></strong><div class="metis-muted"><?php echo metis_escape_html( (string) ( $group['code'] ?? '' ) ); ?></div></td>
                                <td class="metis-premium-cell"><?php echo (int) ( $group['ticket_count'] ?? 0 ); ?></td>
                                <td class="metis-premium-cell"><?php echo (int) ( $group['open_count'] ?? 0 ); ?></td>
                                <td class="metis-premium-cell"><?php echo metis_escape_html( ! empty( $group['last_ticket_at'] ) ? metis_runtime_format_date( (string) $group['last_ticket_at'] ) : '—' ); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <aside class="metis-stash-manager-card" id="metis-stash-group-card">
                    <h2>Group Manager</h2>
                    <p class="metis-muted">Select a person group to view linked tickets and update group information.</p>
                    <?php if ( $can_assign ) : ?>
                    <form id="metis-stash-group-form" class="metis-stash-form" autocomplete="off">
                        <input type="hidden" name="id" value="">
                        <label><span>Name</span><input class="metis-input" type="text" name="name"></label>
                        <label><span>Email</span><input class="metis-input" type="email" name="email"></label>
                        <label><span>Phone</span><input class="metis-input" type="text" name="phone"></label>
                        <label><span>Notes</span><textarea class="metis-input" name="notes" rows="4"></textarea></label>
                        <div class="metis-stash-form-actions">
                            <button type="submit" class="metis-btn">Save Group</button>
                        </div>
                    </form>
                    <?php endif; ?>
                    <div id="metis-stash-group-ticket-list" class="metis-stash-manager-ticket-list"></div>
                </aside>
            </section>
        <?php },
    ]); ?>

    <script id="metis-stash-boot" type="application/json"><?php echo metis_json_encode( $state, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ); ?></script>
</div>
