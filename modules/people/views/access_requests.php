<?php if (!defined('METIS_ROOT')) exit;

if (!metis_people_can_view()) {
    echo '<div class="mw-alert mw-alert-error">You do not have permission to view Access Requests.</div>';
    return;
}

metis_people_ensure_schema();
metis_people_seed_permissions_and_roles();

$db = metis_db();
$requests_table = Metis_Tables::get('people_access_requests');
$people_table = Metis_Tables::get('people');
$roles_table = Metis_Tables::get('people_roles');
$can_manage = metis_people_can_manage();

$rows = $db->fetchAll(
    "SELECT ar.id, ar.request_code, ar.status, ar.reason, ar.decision_note,
            ar.required_approvals, ar.approval_count, ar.requested_start_at, ar.requested_end_at, ar.expires_at, ar.created_at,
            t.pid AS target_pid, t.display_name AS target_name,
            r.role_key, r.role_name
     FROM {$requests_table} ar
     INNER JOIN {$people_table} t ON t.id = ar.target_person_id
     INNER JOIN {$roles_table} r ON r.id = ar.role_id
     ORDER BY ar.status='pending' DESC, ar.created_at DESC
     LIMIT 200"
) ?: [];
$metis_roles = $db->fetchAll("SELECT role_key, role_name FROM {$roles_table} WHERE role_domain='metis' ORDER BY role_name ASC") ?: [];
?>

<div class="metis-people-ops">
    <h1 class="mw-page-title"><?php echo metis_escape_html( metis_current_module_view_title( 'Access Requests' ) ); ?></h1>
    <p class="mw-subtitle">Create and resolve role access requests.</p>

    <div id="metis-people-alert" class="mw-alert" style="display:none;"></div>

    <div class="metis-people-toolbar metis-people-roles-toolbar">
        <div class="metis-contacts-toolbar-right">
            <a href="<?php echo metis_escape_url(metis_people_base_url()); ?>" class="mw-btn mw-btn-ghost">Dashboard</a>
        </div>
    </div>

    <section class="mw-premium-wrap">
        <h3 class="metis-people-section-title">New Request</h3>
        <form id="metis-access-request-form" class="metis-contact-form">
            <div class="metis-contact-field metis-contact-field-half">
                <label for="metis-access-target-pid">Target PID</label>
                <input id="metis-access-target-pid" class="mw-input" type="text" placeholder="PE..." required>
            </div>
            <div class="metis-contact-field metis-contact-field-half">
                <label for="metis-access-role-key">Role</label>
                <select id="metis-access-role-key" class="mw-select" required>
                    <option value="">Select role</option>
                    <?php foreach ($metis_roles as $role): ?>
                        <option value="<?php echo metis_escape_attr((string) $role['role_key']); ?>"><?php echo metis_escape_html((string) $role['role_name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="metis-contact-field metis-contact-field-full">
                <label for="metis-access-reason">Reason</label>
                <textarea id="metis-access-reason" class="mw-input" rows="2" placeholder="Why this access is needed" required></textarea>
            </div>
            <div class="metis-contact-field metis-contact-field-half">
                <label for="metis-access-requested-start">Requested Start (optional)</label>
                <input id="metis-access-requested-start" class="mw-input" type="datetime-local">
            </div>
            <div class="metis-contact-field metis-contact-field-half">
                <label for="metis-access-requested-end">Requested End (optional)</label>
                <input id="metis-access-requested-end" class="mw-input" type="datetime-local">
            </div>
            <div class="metis-contact-field metis-contact-field-half">
                <label for="metis-access-expires-at">Request Expires (optional)</label>
                <input id="metis-access-expires-at" class="mw-input" type="datetime-local">
            </div>
            <div class="metis-contact-field metis-contact-field-half">
                <label for="metis-access-required-approvals">Required Approvals</label>
                <select id="metis-access-required-approvals" class="mw-select">
                    <option value="1">1 approver</option>
                    <option value="2" selected>2 approvers</option>
                    <option value="3">3 approvers</option>
                </select>
            </div>
            <div class="metis-contact-actions">
                <button type="submit" class="mw-btn">Submit Request</button>
            </div>
        </form>
    </section>

    <section class="mw-premium-table" style="margin-top:14px;">
        <div class="mw-premium-header" style="display:grid;grid-template-columns:120px 120px 130px 170px 160px 130px 200px 1fr 170px;">
            <div class="mw-premium-cell">Request</div>
            <div class="mw-premium-cell">Status</div>
            <div class="mw-premium-cell">PID</div>
            <div class="mw-premium-cell">Person</div>
            <div class="mw-premium-cell">Role</div>
            <div class="mw-premium-cell">Approvals</div>
            <div class="mw-premium-cell">Window</div>
            <div class="mw-premium-cell">Reason</div>
            <div class="mw-premium-cell">Actions</div>
        </div>
        <?php foreach ($rows as $row): ?>
            <div class="mw-premium-row" style="display:grid;grid-template-columns:120px 120px 130px 170px 160px 130px 200px 1fr 170px;align-items:center;">
                <div class="mw-premium-cell"><?php echo metis_escape_html((string) $row['request_code']); ?></div>
                <div class="mw-premium-cell"><?php echo metis_escape_html((string) $row['status']); ?></div>
                <div class="mw-premium-cell"><?php echo metis_escape_html((string) $row['target_pid']); ?></div>
                <div class="mw-premium-cell"><?php echo metis_escape_html((string) $row['target_name']); ?></div>
                <div class="mw-premium-cell"><?php echo metis_escape_html((string) $row['role_name']); ?></div>
                <div class="mw-premium-cell"><?php echo metis_escape_html((string) ((int) ($row['approval_count'] ?? 0) . '/' . (int) ($row['required_approvals'] ?? 0))); ?></div>
                <div class="mw-premium-cell">
                    <?php
                    $window = [];
                    if (!empty($row['requested_start_at'])) $window[] = 'Start ' . (string) $row['requested_start_at'];
                    if (!empty($row['requested_end_at'])) $window[] = 'End ' . (string) $row['requested_end_at'];
                    if (!empty($row['expires_at'])) $window[] = 'Req exp ' . (string) $row['expires_at'];
                    echo metis_escape_html(!empty($window) ? implode(' | ', $window) : '—');
                    ?>
                </div>
                <div class="mw-premium-cell">
                    <div><?php echo metis_escape_html((string) $row['reason']); ?></div>
                    <?php if (!empty($row['decision_note'])): ?>
                        <div class="mw-muted">Decision: <?php echo metis_escape_html((string) $row['decision_note']); ?></div>
                    <?php endif; ?>
                </div>
                <div class="mw-premium-cell">
                    <?php if ($can_manage && (string) $row['status'] === 'pending'): ?>
                        <button type="button" class="mw-btn-xs metis-access-resolve" data-id="<?php echo metis_escape_attr((string) $row['id']); ?>" data-decision="approved">Approve</button>
                        <button type="button" class="mw-btn-xs mw-btn-danger metis-access-resolve" data-id="<?php echo metis_escape_attr((string) $row['id']); ?>" data-decision="rejected">Reject</button>
                    <?php else: ?>
                        <span class="mw-muted">—</span>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </section>
</div>
