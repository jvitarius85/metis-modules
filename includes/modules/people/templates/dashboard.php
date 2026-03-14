<?php if (!defined('ABSPATH')) exit;

if (!metis_people_can_view()) {
    echo '<div class="mw-alert mw-alert-error">You do not have permission to view People.</div>';
    return;
}

metis_people_ensure_schema();
metis_people_seed_permissions_and_roles();

global $wpdb;

$people_table = Metis_Tables::get('people');
$roles_table = Metis_Tables::get('people_roles');
$perms_table = Metis_Tables::get('people_permissions');

$total_people = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$people_table}");
$staff_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$people_table} WHERE is_staff = 1");
$board_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$people_table} WHERE is_board = 1");
$volunteer_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$people_table} WHERE is_volunteer = 1");
$workspace_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$people_table} WHERE is_workspace_user = 1");
$stripe_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$people_table} WHERE stripe_role IS NOT NULL AND stripe_role <> ''");

$roles_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$roles_table}");
$permissions_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$perms_table}");
$active_people = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$people_table} WHERE status = 'active'");
$requests_table = Metis_Tables::get('people_access_requests');
$templates_table = Metis_Tables::get('people_role_templates');
$activity_table = Metis_Tables::get('people_activity');
$documents_table = Metis_Tables::get('people_documents');
$pending_requests = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$requests_table} WHERE status = 'pending'");
$templates_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$templates_table}");
$activity_24h = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$activity_table} WHERE created_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)");
$mfa_gaps = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$people_table} WHERE status='active' AND requires_2fa = 1 AND (totp_enabled = 0 AND passkey_enabled = 0)");
$expired_requests = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$requests_table} WHERE status = 'expired'");
$expired_docs = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$documents_table} WHERE lifecycle_status = 'expired'");
$can_workspace_manage = function_exists('metis_people_can_workspace_manage') ? metis_people_can_workspace_manage() : metis_people_can_manage();
?>

<div class="metis-people-dashboard" data-person-base-url="<?php echo esc_url( metis_people_person_url() ); ?>">
    <h1 class="mw-page-title">People</h1>
    <p class="mw-subtitle">Manage Metis users, profile links, roles, and permissions.</p>

    <div class="metis-people-stats">
        <article class="metis-people-stat"><div class="metis-people-stat-label">Total People</div><div class="metis-people-stat-value"><?php echo esc_html((string) $total_people); ?></div></article>
        <article class="metis-people-stat"><div class="metis-people-stat-label">Staff</div><div class="metis-people-stat-value"><?php echo esc_html((string) $staff_count); ?></div></article>
        <article class="metis-people-stat"><div class="metis-people-stat-label">Board</div><div class="metis-people-stat-value"><?php echo esc_html((string) $board_count); ?></div></article>
        <article class="metis-people-stat"><div class="metis-people-stat-label">Volunteers</div><div class="metis-people-stat-value"><?php echo esc_html((string) $volunteer_count); ?></div></article>
        <article class="metis-people-stat"><div class="metis-people-stat-label">Workspace</div><div class="metis-people-stat-value"><?php echo esc_html((string) $workspace_count); ?></div></article>
        <article class="metis-people-stat"><div class="metis-people-stat-label">Stripe Access</div><div class="metis-people-stat-value"><?php echo esc_html((string) $stripe_count); ?></div></article>
    </div>

    <div class="mw-grid metis-people-tiles-grid">
        <div class="mw-tile metis-people-search-tile">
            <div class="mw-tile-inner">
                <div class="mw-tile-title">Find Person</div>
                <div class="mw-tile-desc">
                    <label for="metis-people-dashboard-search" class="mw-muted">Search name, email, or PID</label>
                    <div class="metis-people-search-input-wrap">
                        <input id="metis-people-dashboard-search" class="mw-input" type="text" placeholder="Start typing..." name="metis_people_find_person_search" autocomplete="off" autocorrect="off" autocapitalize="none" spellcheck="false" data-lpignore="true" data-1p-ignore="true">
                        <div id="metis-people-dashboard-results" class="metis-people-search-results" style="display:none;"></div>
                    </div>
                </div>
                <div class="mw-tile-cta">Open profile →</div>
            </div>
        </div>

        <a class="mw-tile" href="<?php echo esc_url(metis_people_people_list_url()); ?>">
            <div class="mw-tile-inner">
                <div class="mw-tile-title">People</div>
                <div class="mw-tile-desc">View and edit people profiles.</div>
                <div class="mw-tile-cta"><?php echo esc_html((string) $total_people); ?> total</div>
            </div>
        </a>

        <a class="mw-tile" href="<?php echo esc_url(metis_people_roles_list_url()); ?>">
            <div class="mw-tile-inner">
                <div class="mw-tile-title">Roles</div>
                <div class="mw-tile-desc">Manage Metis, Stripe, and Workspace roles.</div>
                <div class="mw-tile-cta"><?php echo esc_html((string) $roles_count); ?> roles</div>
            </div>
        </a>

        <?php if ($can_workspace_manage) : ?>
            <a class="mw-tile" href="<?php echo esc_url(metis_people_workspace_url()); ?>">
                <div class="mw-tile-inner">
                    <div class="mw-tile-title">Workspace</div>
                    <div class="mw-tile-desc">Manage Workspace users, groups, and security actions.</div>
                    <div class="mw-tile-cta"><?php echo esc_html((string) $workspace_count); ?> linked users</div>
                </div>
            </a>
        <?php endif; ?>

        <a class="mw-tile" href="<?php echo esc_url(metis_people_permissions_url()); ?>">
            <div class="mw-tile-inner">
                <div class="mw-tile-title">Permissions</div>
                <div class="mw-tile-desc">Review module permissions and role coverage.</div>
                <div class="mw-tile-cta"><?php echo esc_html((string) $permissions_count); ?> permissions</div>
            </div>
        </a>

        <div class="mw-tile">
            <div class="mw-tile-inner">
                <div class="mw-tile-title">Overview</div>
                <div class="mw-tile-desc">Quick health snapshot of People access.</div>
                <div class="mw-tile-cta"><?php echo esc_html((string) $active_people); ?> active profiles</div>
            </div>
        </div>

        <a class="mw-tile" href="<?php echo esc_url(metis_people_access_requests_url()); ?>">
            <div class="mw-tile-inner">
                <div class="mw-tile-title">Access Requests</div>
                <div class="mw-tile-desc">Queue and approve role access requests.</div>
                <div class="mw-tile-cta"><?php echo esc_html((string) $pending_requests); ?> pending</div>
            </div>
        </a>

        <a class="mw-tile" href="<?php echo esc_url(metis_people_templates_url()); ?>">
            <div class="mw-tile-inner">
                <div class="mw-tile-title">Role Templates</div>
                <div class="mw-tile-desc">Reusable bundles for common roles.</div>
                <div class="mw-tile-cta"><?php echo esc_html((string) $templates_count); ?> templates</div>
            </div>
        </a>

        <a class="mw-tile" href="<?php echo esc_url(metis_people_bulk_actions_url()); ?>">
            <div class="mw-tile-inner">
                <div class="mw-tile-title">Bulk Actions</div>
                <div class="mw-tile-desc">Assign or remove roles across many people.</div>
                <div class="mw-tile-cta">Open →</div>
            </div>
        </a>

        <a class="mw-tile" href="<?php echo esc_url(metis_people_activity_url()); ?>">
            <div class="mw-tile-inner">
                <div class="mw-tile-title">Activity Log</div>
                <div class="mw-tile-desc">Recent changes and operational history.</div>
                <div class="mw-tile-cta"><?php echo esc_html((string) $activity_24h); ?> in 24h</div>
            </div>
        </a>

        <a class="mw-tile" href="<?php echo esc_url(metis_people_activity_url()); ?>">
            <div class="mw-tile-inner">
                <div class="mw-tile-title">Security Posture</div>
                <div class="mw-tile-desc">MFA, expired requests, and document lifecycle health.</div>
                <div class="mw-tile-cta"><?php echo esc_html((string) $mfa_gaps); ?> MFA gaps · <?php echo esc_html((string) $expired_requests); ?> req expired · <?php echo esc_html((string) $expired_docs); ?> docs expired</div>
            </div>
        </a>
    </div>
</div>
