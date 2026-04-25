<?php if (!defined('METIS_ROOT')) exit;

if (!metis_people_can_view()) {
    echo '<div class="metis-alert metis-alert-error">You do not have permission to view Access Requests.</div>';
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
    <h1 class="metis-page-title"><?php echo metis_escape_html( metis_current_module_view_title( 'Access Requests' ) ); ?></h1>
    <p class="metis-subtitle">Create and resolve role access requests.</p>

    <div id="metis-people-alert" class="metis-alert" style="display:none;"></div>

    <div class="metis-people-toolbar metis-people-roles-toolbar">
        <div class="metis-toolbar-right">
            <a href="<?php echo metis_escape_url(metis_people_base_url()); ?>" class="metis-btn metis-btn-ghost">Dashboard</a>
        </div>
    </div>

    <section class="metis-premium-wrap">
        <h3 class="metis-people-section-title">New Request</h3>
        <form id="metis-access-request-form" class="metis-form-grid">
            <div class="metis-field metis-field-half">
                <label for="metis-access-target-pid">Target PID</label>
                <input id="metis-access-target-pid" class="metis-input" type="text" placeholder="PE..." required>
            </div>
            <div class="metis-field metis-field-half">
                <label for="metis-access-role-key">Role</label>
                <select id="metis-access-role-key" class="metis-select" required>
                    <option value="">Select role</option>
                    <?php foreach ($metis_roles as $role): ?>
                        <option value="<?php echo metis_escape_attr((string) $role['role_key']); ?>"><?php echo metis_escape_html((string) $role['role_name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="metis-field metis-field-full">
                <label for="metis-access-reason">Reason</label>
                <textarea id="metis-access-reason" class="metis-input" rows="2" placeholder="Why this access is needed" required></textarea>
            </div>
            <div class="metis-field metis-field-half">
                <label for="metis-access-requested-start">Requested Start (optional)</label>
                <input id="metis-access-requested-start" class="metis-input" type="datetime-local">
            </div>
            <div class="metis-field metis-field-half">
                <label for="metis-access-requested-end">Requested End (optional)</label>
                <input id="metis-access-requested-end" class="metis-input" type="datetime-local">
            </div>
            <div class="metis-field metis-field-half">
                <label for="metis-access-expires-at">Request Expires (optional)</label>
                <input id="metis-access-expires-at" class="metis-input" type="datetime-local">
            </div>
            <div class="metis-field metis-field-half">
                <label for="metis-access-required-approvals">Required Approvals</label>
                <select id="metis-access-required-approvals" class="metis-select">
                    <option value="1">1 approver</option>
                    <option value="2" selected>2 approvers</option>
                    <option value="3">3 approvers</option>
                </select>
            </div>
            <div class="metis-form-actions">
                <button type="submit" class="metis-btn">Submit Request</button>
            </div>
        </form>
    </section>

    <table class="metis-premium-table metis-people-access-table" style="margin-top:14px;">
        <thead>
            <tr class="metis-premium-row metis-premium-header">
                <th class="metis-premium-cell" scope="col">Request</th>
                <th class="metis-premium-cell" scope="col">Status</th>
                <th class="metis-premium-cell" scope="col">PID</th>
                <th class="metis-premium-cell" scope="col">Person</th>
                <th class="metis-premium-cell" scope="col">Role</th>
                <th class="metis-premium-cell" scope="col">Approvals</th>
                <th class="metis-premium-cell" scope="col">Window</th>
                <th class="metis-premium-cell" scope="col">Reason</th>
                <th class="metis-premium-cell" scope="col">Actions</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $row): ?>
            <tr class="metis-premium-row">
                <td class="metis-premium-cell"><?php echo metis_escape_html((string) $row['request_code']); ?></td>
                <td class="metis-premium-cell"><?php echo metis_escape_html((string) $row['status']); ?></td>
                <td class="metis-premium-cell"><?php echo metis_escape_html((string) $row['target_pid']); ?></td>
                <td class="metis-premium-cell"><?php echo metis_escape_html((string) $row['target_name']); ?></td>
                <td class="metis-premium-cell"><?php echo metis_escape_html((string) $row['role_name']); ?></td>
                <td class="metis-premium-cell"><?php echo metis_escape_html((string) ((int) ($row['approval_count'] ?? 0) . '/' . (int) ($row['required_approvals'] ?? 0))); ?></td>
                <td class="metis-premium-cell">
                    <?php
                    $window = [];
                    if (!empty($row['requested_start_at'])) $window[] = 'Start ' . (string) $row['requested_start_at'];
                    if (!empty($row['requested_end_at'])) $window[] = 'End ' . (string) $row['requested_end_at'];
                    if (!empty($row['expires_at'])) $window[] = 'Req exp ' . (string) $row['expires_at'];
                    echo metis_escape_html(!empty($window) ? implode(' | ', $window) : '—');
                    ?>
                </td>
                <td class="metis-premium-cell">
                    <div><?php echo metis_escape_html((string) $row['reason']); ?></div>
                    <?php if (!empty($row['decision_note'])): ?>
                        <div class="metis-muted">Decision: <?php echo metis_escape_html((string) $row['decision_note']); ?></div>
                    <?php endif; ?>
                </td>
                <td class="metis-premium-cell">
                    <?php if ($can_manage && (string) $row['status'] === 'pending'): ?>
                        <button type="button" class="metis-btn-xs metis-access-resolve" data-id="<?php echo metis_escape_attr((string) $row['id']); ?>" data-decision="approved">Approve</button>
                        <button type="button" class="metis-btn-xs metis-btn-danger metis-access-resolve" data-id="<?php echo metis_escape_attr((string) $row['id']); ?>" data-decision="rejected">Reject</button>
                    <?php else: ?>
                        <span class="metis-muted">—</span>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
