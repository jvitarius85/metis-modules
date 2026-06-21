<?php if (!defined('METIS_ROOT')) exit;

if (!metis_people_can_view()) {
    echo '<div class="metis-alert metis-alert-error">You do not have permission to view Activity.</div>';
    return;
}
metis_people_ensure_schema();
metis_people_seed_permissions_and_roles();

$db = metis_db();
$page = isset(metis_request_get()['page']) ? (int) metis_runtime_unslash(metis_request_get()['page']) : 1;
$q = isset(metis_request_get()['q']) ? metis_text_clean(metis_runtime_unslash(metis_request_get()['q'])) : '';
if ($page < 1) $page = 1;
$payload = function_exists('metis_people_activity_fetch_page')
    ? metis_people_activity_fetch_page($page, 15, $q)
    : ['rows' => [], 'page' => 1, 'total_pages' => 1, 'has_prev' => false, 'has_next' => false, 'prev_page' => 1, 'next_page' => 1];
$rows = (array) ($payload['rows'] ?? []);
$page = (int) ($payload['page'] ?? 1);
$total_pages = (int) ($payload['total_pages'] ?? 1);
$type_labels = function_exists('metis_people_activity_type_labels') ? metis_people_activity_type_labels() : [];
?>

<div class="metis-people-ops metis-people-activity-log">
    <h1 class="metis-page-title"><?php echo metis_escape_html( metis_current_module_view_title( 'People Activity' ) ); ?></h1>
    <p class="metis-subtitle">Recent operational activity and profile changes.</p>
    <div class="metis-people-toolbar metis-people-roles-toolbar metis-people-activity-toolbar">
        <div class="metis-toolbar-right"><a href="<?php echo metis_escape_url(metis_people_base_url()); ?>" class="metis-btn metis-btn-ghost">Dashboard</a></div>
        <div class="metis-field metis-field-half metis-people-activity-filter-field">
            <label for="metis-people-activity-filter">Filter</label>
            <input id="metis-people-activity-filter" class="metis-input" type="text" placeholder="Type, summary, target, actor" value="<?php echo metis_escape_attr($q); ?>">
        </div>
    </div>

    <table class="metis-premium-table metis-people-activity-table">
        <thead>
            <tr class="metis-premium-row metis-premium-header">
                <th class="metis-premium-cell" scope="col">Time</th>
                <th class="metis-premium-cell" scope="col">Type</th>
                <th class="metis-premium-cell" scope="col">Summary</th>
                <th class="metis-premium-cell" scope="col">Target</th>
                <th class="metis-premium-cell" scope="col">Actor</th>
            </tr>
        </thead>
        <tbody id="metis-people-activity-rows">
        <?php foreach($rows as $row): ?>
            <?php
            $activity_key = strtolower(trim((string) ($row['activity_type'] ?? '')));
            $type_label = (string) ($type_labels[$activity_key] ?? ucwords(str_replace('_', ' ', $activity_key)));
            $created_raw = trim((string) ($row['created_at'] ?? ''));
            $created = $created_raw;
            $ts = strtotime($created_raw);
            if ($ts) $created = date('M j, Y g:i a', $ts);
            ?>
            <tr class="metis-premium-row">
                <td class="metis-premium-cell"><?php echo metis_escape_html($created); ?></td>
                <td class="metis-premium-cell"><?php echo metis_escape_html($type_label); ?></td>
                <td class="metis-premium-cell"><?php echo metis_escape_html((string)$row['summary']); ?></td>
                <td class="metis-premium-cell"><?php echo metis_escape_html(trim((string)$row['target_name']) !== '' ? (string)$row['target_name'] . ' (' . (string)$row['target_pid'] . ')' : '—'); ?></td>
                <td class="metis-premium-cell"><?php echo metis_escape_html(trim((string)$row['actor_name']) !== '' ? (string)$row['actor_name'] . ' (' . (string)$row['actor_pid'] . ')' : 'System'); ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if (empty($rows)) : ?>
            <tr class="metis-premium-row">
                <td class="metis-premium-cell" colspan="5">No activity yet.</td>
            </tr>
        <?php endif; ?>
        </tbody>
    </table>
        <div class="metis-workspace-log-pagination" id="metis-people-activity-pagination" data-page="<?php echo metis_escape_attr((string) $page); ?>" data-total-pages="<?php echo metis_escape_attr((string) $total_pages); ?>" data-q="<?php echo metis_escape_attr($q); ?>">
            <span class="metis-muted" id="metis-people-activity-page-label">Page <?php echo metis_escape_html((string) $page); ?> of <?php echo metis_escape_html((string) $total_pages); ?></span>
            <div class="metis-workspace-log-pagination-actions" id="metis-people-activity-page-actions">
                <?php if (!empty($payload['has_prev'])) : ?>
                    <button type="button" class="metis-workspace-page-link" data-activity-page="<?php echo metis_escape_attr((string) ($payload['prev_page'] ?? 1)); ?>">&larr; Previous</button>
                <?php endif; ?>
                <?php if (!empty($payload['has_next'])) : ?>
                    <button type="button" class="metis-workspace-page-link" data-activity-page="<?php echo metis_escape_attr((string) ($payload['next_page'] ?? $page)); ?>">Next &rarr;</button>
                <?php endif; ?>
            </div>
        </div>
</div>
