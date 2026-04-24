<?php if (!defined('METIS_ROOT')) exit;

if (!metis_people_can_view()) {
    echo '<div class="mw-alert mw-alert-error">You do not have permission to view Activity.</div>';
    return;
}
metis_people_ensure_schema();
metis_people_seed_permissions_and_roles();

$db = metis_db();
$page = isset($_GET['page']) ? (int) metis_runtime_unslash($_GET['page']) : 1;
$q = isset($_GET['q']) ? metis_text_clean(metis_runtime_unslash($_GET['q'])) : '';
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
    <h1 class="mw-page-title"><?php echo metis_escape_html( metis_current_module_view_title( 'People Activity' ) ); ?></h1>
    <p class="mw-subtitle">Recent operational activity and profile changes.</p>
    <div class="metis-people-toolbar metis-people-roles-toolbar metis-people-activity-toolbar">
        <div class="metis-contacts-toolbar-right"><a href="<?php echo metis_escape_url(metis_people_base_url()); ?>" class="mw-btn mw-btn-ghost">Dashboard</a></div>
        <div class="metis-contact-field metis-contact-field-half metis-people-activity-filter-field">
            <label for="metis-people-activity-filter">Filter</label>
            <input id="metis-people-activity-filter" class="mw-input" type="text" placeholder="Type, summary, target, actor" value="<?php echo metis_escape_attr($q); ?>">
        </div>
    </div>

    <section class="mw-premium-table">
        <div class="mw-premium-header" style="display:grid;grid-template-columns:180px 220px 1fr 180px 220px;">
            <div class="mw-premium-cell">Time</div>
            <div class="mw-premium-cell">Type</div>
            <div class="mw-premium-cell">Summary</div>
            <div class="mw-premium-cell">Target</div>
            <div class="mw-premium-cell">Actor</div>
        </div>
        <div id="metis-people-activity-rows">
        <?php foreach($rows as $row): ?>
            <?php
            $activity_key = strtolower(trim((string) ($row['activity_type'] ?? '')));
            $type_label = (string) ($type_labels[$activity_key] ?? ucwords(str_replace('_', ' ', $activity_key)));
            $created_raw = trim((string) ($row['created_at'] ?? ''));
            $created = $created_raw;
            $ts = strtotime($created_raw);
            if ($ts) $created = date('M j, Y g:i a', $ts);
            ?>
            <div class="mw-premium-row" style="display:grid;grid-template-columns:180px 220px 1fr 180px 220px;align-items:center;">
                <div class="mw-premium-cell"><?php echo metis_escape_html($created); ?></div>
                <div class="mw-premium-cell"><?php echo metis_escape_html($type_label); ?></div>
                <div class="mw-premium-cell"><?php echo metis_escape_html((string)$row['summary']); ?></div>
                <div class="mw-premium-cell"><?php echo metis_escape_html(trim((string)$row['target_name']) !== '' ? (string)$row['target_name'] . ' (' . (string)$row['target_pid'] . ')' : '—'); ?></div>
                <div class="mw-premium-cell"><?php echo metis_escape_html(trim((string)$row['actor_name']) !== '' ? (string)$row['actor_name'] . ' (' . (string)$row['actor_pid'] . ')' : 'System'); ?></div>
            </div>
        <?php endforeach; ?>
        <?php if (empty($rows)) : ?>
            <div class="mw-premium-row" style="display:grid;grid-template-columns:180px 220px 1fr 180px 220px;align-items:center;">
                <div class="mw-premium-cell">No activity yet.</div>
                <div class="mw-premium-cell">—</div>
                <div class="mw-premium-cell">—</div>
                <div class="mw-premium-cell">—</div>
                <div class="mw-premium-cell">—</div>
            </div>
        <?php endif; ?>
        </div>
        <div class="metis-workspace-log-pagination" id="metis-people-activity-pagination" data-page="<?php echo metis_escape_attr((string) $page); ?>" data-total-pages="<?php echo metis_escape_attr((string) $total_pages); ?>" data-q="<?php echo metis_escape_attr($q); ?>">
            <span class="mw-muted" id="metis-people-activity-page-label">Page <?php echo metis_escape_html((string) $page); ?> of <?php echo metis_escape_html((string) $total_pages); ?></span>
            <div class="metis-workspace-log-pagination-actions" id="metis-people-activity-page-actions">
                <?php if (!empty($payload['has_prev'])) : ?>
                    <button type="button" class="metis-workspace-page-link" data-activity-page="<?php echo metis_escape_attr((string) ($payload['prev_page'] ?? 1)); ?>">&larr; Previous</button>
                <?php endif; ?>
                <?php if (!empty($payload['has_next'])) : ?>
                    <button type="button" class="metis-workspace-page-link" data-activity-page="<?php echo metis_escape_attr((string) ($payload['next_page'] ?? $page)); ?>">Next &rarr;</button>
                <?php endif; ?>
            </div>
        </div>
    </section>
</div>
