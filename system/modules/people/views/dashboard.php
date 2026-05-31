<?php if (!defined('METIS_ROOT')) exit;

if (!metis_people_can_view()) {
    echo '<div class="metis-alert metis-alert-error">You do not have permission to view People.</div>';
    return;
}

metis_people_ensure_schema();
metis_people_seed_permissions_and_roles();

$dashboard = \Metis\Modules\People\ReadService::dashboardSnapshot();
$total_people = (int) ($dashboard['total_people'] ?? 0);
$staff_count = (int) ($dashboard['staff_count'] ?? 0);
$board_count = (int) ($dashboard['board_count'] ?? 0);
$volunteer_count = (int) ($dashboard['volunteer_count'] ?? 0);
$workspace_count = (int) ($dashboard['workspace_count'] ?? 0);
$stripe_count = (int) ($dashboard['stripe_count'] ?? 0);
$roles_count = (int) ($dashboard['roles_count'] ?? 0);
$permissions_count = (int) ($dashboard['permissions_count'] ?? 0);
$active_people = (int) ($dashboard['active_people'] ?? 0);
$pending_requests = (int) ($dashboard['pending_requests'] ?? 0);
$templates_count = (int) ($dashboard['templates_count'] ?? 0);
$activity_24h = (int) ($dashboard['activity_24h'] ?? 0);
$mfa_gaps = (int) ($dashboard['mfa_gaps'] ?? 0);
$expired_requests = (int) ($dashboard['expired_requests'] ?? 0);
$expired_docs = (int) ($dashboard['expired_docs'] ?? 0);
$can_workspace_manage = function_exists('metis_people_can_workspace_manage') ? metis_people_can_workspace_manage() : metis_people_can_manage();
?>

<div class="metis-people-dashboard" data-person-base-url="<?php echo metis_escape_url( metis_people_person_url() ); ?>">
    <h1 class="metis-page-title"><?php echo metis_escape_html( metis_current_module_label( 'People' ) ); ?></h1>
    <p class="metis-subtitle">People and access.</p>

    <div class="metis-people-stats">
        <article class="metis-people-stat"><div class="metis-people-stat-label">Total People</div><div class="metis-people-stat-value"><?php echo metis_escape_html((string) $total_people); ?></div></article>
        <article class="metis-people-stat"><div class="metis-people-stat-label">Staff</div><div class="metis-people-stat-value"><?php echo metis_escape_html((string) $staff_count); ?></div></article>
        <article class="metis-people-stat"><div class="metis-people-stat-label">Board</div><div class="metis-people-stat-value"><?php echo metis_escape_html((string) $board_count); ?></div></article>
        <article class="metis-people-stat"><div class="metis-people-stat-label">Volunteers</div><div class="metis-people-stat-value"><?php echo metis_escape_html((string) $volunteer_count); ?></div></article>
        <article class="metis-people-stat"><div class="metis-people-stat-label">Workspace</div><div class="metis-people-stat-value"><?php echo metis_escape_html((string) $workspace_count); ?></div></article>
        <article class="metis-people-stat"><div class="metis-people-stat-label">Stripe Access</div><div class="metis-people-stat-value"><?php echo metis_escape_html((string) $stripe_count); ?></div></article>
    </div>

    <div class="metis-grid metis-people-tiles-grid">
        <div class="metis-tile metis-people-search-tile">
            <div class="metis-tile-inner">
                <div class="metis-tile-title">Find Person</div>
                <div class="metis-tile-desc">
                    <label for="metis-people-dashboard-search" class="metis-muted">Search name, email, or PID</label>
                    <div class="metis-people-search-input-wrap">
                        <input id="metis-people-dashboard-search" class="metis-input" type="text" placeholder="Start typing..." name="metis_people_find_person_search" autocomplete="off" autocorrect="off" autocapitalize="none" spellcheck="false" data-lpignore="true" data-1p-ignore="true" role="combobox" aria-autocomplete="list" aria-expanded="false" aria-controls="metis-people-dashboard-results" aria-activedescendant="">
                        <div id="metis-people-dashboard-results" class="metis-people-search-results" style="display:none;" role="listbox"></div>
                    </div>
                </div>
                <div class="metis-tile-cta">Open profile →</div>
            </div>
        </div>

        <a class="metis-tile" href="<?php echo metis_escape_url(metis_people_people_list_url()); ?>">
            <div class="metis-tile-inner">
                <div class="metis-tile-title">People</div>
                <div class="metis-tile-desc">View and edit people profiles.</div>
                <div class="metis-tile-cta"><?php echo metis_escape_html((string) $total_people); ?> total</div>
            </div>
        </a>

        <a class="metis-tile" href="<?php echo metis_escape_url(metis_people_roles_list_url()); ?>">
            <div class="metis-tile-inner">
                <div class="metis-tile-title">Roles</div>
                <div class="metis-tile-desc">Manage Metis, Stripe, and Workspace roles.</div>
                <div class="metis-tile-cta"><?php echo metis_escape_html((string) $roles_count); ?> roles</div>
            </div>
        </a>

        <?php if ($can_workspace_manage) : ?>
            <a class="metis-tile" href="<?php echo metis_escape_url(metis_people_workspace_url()); ?>">
                <div class="metis-tile-inner">
                    <div class="metis-tile-title">Workspace</div>
                    <div class="metis-tile-desc">Manage Workspace users, groups, and security actions.</div>
                    <div class="metis-tile-cta"><?php echo metis_escape_html((string) $workspace_count); ?> linked users</div>
                </div>
            </a>
        <?php endif; ?>

        <a class="metis-tile" href="<?php echo metis_escape_url(metis_people_permissions_url()); ?>">
            <div class="metis-tile-inner">
                <div class="metis-tile-title">Permissions</div>
                <div class="metis-tile-desc">Review module permissions and role coverage.</div>
                <div class="metis-tile-cta"><?php echo metis_escape_html((string) $permissions_count); ?> permissions</div>
            </div>
        </a>

        <a class="metis-tile" href="<?php echo metis_escape_url(metis_people_access_requests_url()); ?>">
            <div class="metis-tile-inner">
                <div class="metis-tile-title">Access Requests</div>
                <div class="metis-tile-desc">Queue and approve role access requests.</div>
                <div class="metis-tile-cta"><?php echo metis_escape_html((string) $pending_requests); ?> pending</div>
            </div>
        </a>
    </div>

    <details class="metis-premium-wrap">
        <summary class="metis-btn metis-btn-xs metis-btn-ghost">Show More Tools</summary>
        <div class="metis-grid metis-people-tiles-grid" style="margin-top:12px;">
            <a class="metis-tile" href="<?php echo metis_escape_url(metis_people_templates_url()); ?>">
                <div class="metis-tile-inner">
                    <div class="metis-tile-title">Role Templates</div>
                    <div class="metis-tile-desc">Reusable bundles for common roles.</div>
                    <div class="metis-tile-cta"><?php echo metis_escape_html((string) $templates_count); ?> templates</div>
                </div>
            </a>
            <a class="metis-tile" href="<?php echo metis_escape_url(metis_people_bulk_actions_url()); ?>">
                <div class="metis-tile-inner">
                    <div class="metis-tile-title">Bulk Actions</div>
                    <div class="metis-tile-desc">Assign or remove roles across many people.</div>
                    <div class="metis-tile-cta">Open</div>
                </div>
            </a>
            <a class="metis-tile" href="<?php echo metis_escape_url(metis_people_activity_url()); ?>">
                <div class="metis-tile-inner">
                    <div class="metis-tile-title">Activity Log</div>
                    <div class="metis-tile-desc">Recent changes and operational history.</div>
                    <div class="metis-tile-cta"><?php echo metis_escape_html((string) $activity_24h); ?> in 24h</div>
                </div>
            </a>
            <a class="metis-tile" href="<?php echo metis_escape_url(metis_people_activity_url()); ?>">
                <div class="metis-tile-inner">
                    <div class="metis-tile-title">Security Posture</div>
                    <div class="metis-tile-desc">MFA and expired access health.</div>
                    <div class="metis-tile-cta"><?php echo metis_escape_html((string) $mfa_gaps); ?> MFA gaps · <?php echo metis_escape_html((string) $expired_requests); ?> req · <?php echo metis_escape_html((string) $expired_docs); ?> docs</div>
                </div>
            </a>
            <div class="metis-tile">
                <div class="metis-tile-inner">
                    <div class="metis-tile-title">Overview</div>
                    <div class="metis-tile-desc">Quick health snapshot.</div>
                    <div class="metis-tile-cta"><?php echo metis_escape_html((string) $active_people); ?> active</div>
                </div>
            </div>
        </div>
    </details>
</div>
