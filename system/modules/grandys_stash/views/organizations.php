<?php
if ( ! defined( 'METIS_ROOT' ) ) exit;

if ( ! metis_grandys_stash_can_view() ) {
    echo '<div class="metis-alert metis-alert-error">You do not have permission to view Grandy&apos;s Stash.</div>';
    return;
}

$state = \Metis\Modules\GrandyStash\GrandyStashRepository::dashboardData();
$organizations = $state['organizations'] ?? [];
$can_settings = function_exists( 'metis_grandys_stash_can_settings' ) && metis_grandys_stash_can_settings();
?>

<div class="metis-stash-app metis-stash-organizations-page"
     data-can-manage="<?php echo metis_escape_attr( $can_settings ? '1' : '0' ); ?>"
     data-stash-view="organizations"
     data-view-base-url="<?php echo metis_escape_attr( metis_grandys_stash_view_url() ); ?>">

    <h1 class="metis-page-title"><?php echo metis_escape_html( metis_current_module_view_title( "Grandy's Stash Organizations" ) ); ?></h1>
    <p class="metis-subtitle">Manage organization domains, linked tickets, and reporting labels.</p>
    <div id="metis-stash-alert" class="metis-alert" style="display:none;"></div>

    <?php metis_render_sidebar_layout([
        'sidebar' => static function () use ( $can_settings ) { ?>
            <div class="metis-list-sidebar-section">
                <div class="metis-list-sidebar-label">Search</div>
                <input id="metis-stash-organization-search" class="metis-input" type="text" placeholder="Name, code, or domain">
            </div>
            <div class="metis-list-sidebar-section">
                <div class="metis-list-sidebar-label">Filter</div>
                <button type="button" class="metis-btn metis-btn-xs metis-stash-manager-filter is-active" data-manager-filter="all">All Organizations</button>
                <button type="button" class="metis-btn metis-btn-xs metis-btn-ghost metis-stash-manager-filter" data-manager-filter="open">Open Tickets</button>
                <button type="button" class="metis-btn metis-btn-xs metis-btn-ghost metis-stash-manager-filter" data-manager-filter="active">Active Only</button>
            </div>
            <div class="metis-list-sidebar-section">
                <div class="metis-list-sidebar-label">Navigation</div>
                <nav class="metis-list-sidebar-nav" aria-label="Grandy's Stash navigation">
                    <a href="<?php echo metis_escape_url( metis_grandys_stash_base_url() ); ?>" class="metis-list-sidebar-nav-item">Inbox</a>
                    <a href="<?php echo metis_escape_url( metis_grandys_stash_base_url() . '/groups/' ); ?>" class="metis-list-sidebar-nav-item">People Groups</a>
                    <a href="<?php echo metis_escape_url( metis_grandys_stash_base_url() . '/organizations/' ); ?>" class="metis-list-sidebar-nav-item is-active">Organizations</a>
                    <a href="<?php echo metis_escape_url( metis_grandys_stash_base_url() . '/reports/' ); ?>" class="metis-list-sidebar-nav-item">Reports</a>
                    <?php if ( $can_settings ) : ?>
                    <a href="<?php echo metis_escape_url( metis_grandys_stash_base_url() . '/settings/' ); ?>" class="metis-list-sidebar-nav-item">Settings</a>
                    <?php endif; ?>
                </nav>
            </div>
        <?php },
        'content' => static function () use ( $organizations ) { ?>
            <section class="metis-stash-manager-layout metis-stash-manager-layout--full">
                <div class="metis-stash-manager-list">
                    <table class="metis-premium-table metis-stash-manager-table">
                        <thead>
                            <tr class="metis-premium-row metis-premium-header">
                                <th class="metis-premium-cell" scope="col">Organization</th>
                                <th class="metis-premium-cell" scope="col">Tickets</th>
                                <th class="metis-premium-cell" scope="col">Open</th>
                                <th class="metis-premium-cell" scope="col">Last Activity</th>
                            </tr>
                        </thead>
                        <tbody id="metis-stash-organization-rows">
                            <?php foreach ( $organizations as $organization ) : ?>
                            <tr class="metis-premium-row metis-stash-manager-row"
                                data-manager-kind="organization"
                                data-id="<?php echo metis_escape_attr( (string) ( $organization['id'] ?? 0 ) ); ?>"
                                data-open-count="<?php echo metis_escape_attr( (string) ( $organization['open_count'] ?? 0 ) ); ?>"
                                data-is-active="<?php echo metis_escape_attr( ! empty( $organization['is_active'] ) ? '1' : '0' ); ?>"
                                data-last-ticket="<?php echo metis_escape_attr( (string) ( $organization['last_ticket_at'] ?? '' ) ); ?>"
                                data-search="<?php echo metis_escape_attr( strtolower( implode( ' ', [ (string) ( $organization['code'] ?? '' ), (string) ( $organization['name'] ?? '' ), (string) ( $organization['domain'] ?? '' ) ] ) ) ); ?>">
                                <td class="metis-premium-cell">
                                    <button type="button" class="metis-stash-link-button" data-manager-open="organization" data-id="<?php echo metis_escape_attr( (string) ( $organization['id'] ?? 0 ) ); ?>">
                                        <?php echo metis_escape_html( (string) ( $organization['name'] ?? 'Unknown' ) ); ?>
                                    </button>
                                    <div class="metis-muted"><?php echo metis_escape_html( (string) ( $organization['domain'] ?? '—' ) ); ?></div>
                                </td>
                                <td class="metis-premium-cell"><?php echo (int) ( $organization['ticket_count'] ?? 0 ); ?></td>
                                <td class="metis-premium-cell"><?php echo (int) ( $organization['open_count'] ?? 0 ); ?></td>
                                <td class="metis-premium-cell"><?php echo metis_escape_html( ! empty( $organization['last_ticket_at'] ) ? metis_runtime_format_date( (string) $organization['last_ticket_at'] ) : '—' ); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        <?php },
    ]); ?>

    <div class="metis-stash-modal metis-stash-modal-wide" id="metis-stash-organization-modal" aria-hidden="true">
        <div class="metis-stash-modal-dialog">
            <div class="metis-stash-modal-head">
                <div>
                    <h2 id="metis-stash-organization-modal-title">Organization Manager</h2>
                    <p class="metis-muted" id="metis-stash-organization-modal-subtitle" style="margin:4px 0 0;">Review organization information and linked tickets.</p>
                </div>
                <button type="button" class="metis-btn metis-btn-xs metis-btn-ghost" data-close-modal="metis-stash-organization-modal">Close</button>
            </div>
            <div class="metis-stash-tab-row">
                <button type="button" class="metis-btn metis-btn-xs metis-stash-tab is-active" data-tab-target="organization-general">General Info</button>
                <button type="button" class="metis-btn metis-btn-xs metis-btn-ghost metis-stash-tab" data-tab-target="organization-tickets">Tickets</button>
            </div>
            <div class="metis-stash-tab-panel is-active" data-tab-panel="organization-general">
                <?php if ( $can_settings ) : ?>
                <form id="metis-stash-organization-form" class="metis-stash-form" autocomplete="off">
                    <input type="hidden" name="id" value="">
                    <label><span>Name</span><input class="metis-input" type="text" name="name"></label>
                    <label><span>Domain</span><input class="metis-input" type="text" name="domain" placeholder="example.org"></label>
                    <label><span>Notes</span><textarea class="metis-input" name="notes" rows="4"></textarea></label>
                    <label><span>Status</span>
                        <select class="metis-select" name="is_active">
                            <option value="1">Active</option>
                            <option value="0">Inactive</option>
                        </select>
                    </label>
                    <div class="metis-stash-form-actions">
                        <button type="submit" class="metis-btn">Save Organization</button>
                    </div>
                </form>
                <?php else : ?>
                <div class="metis-muted">You do not have permission to edit this organization.</div>
                <?php endif; ?>
            </div>
            <div class="metis-stash-tab-panel" data-tab-panel="organization-tickets">
                <div id="metis-stash-organization-ticket-list" class="metis-stash-manager-ticket-list"></div>
            </div>
        </div>
    </div>

    <script id="metis-stash-boot" type="application/json"><?php echo metis_json_encode( $state, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ); ?></script>
</div>
