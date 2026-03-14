<?php if (!defined('ABSPATH')) exit;

if (!metis_people_can_view()) {
    echo '<div class="mw-alert mw-alert-error">You do not have permission to view Activity.</div>';
    return;
}
metis_people_ensure_schema();
metis_people_seed_permissions_and_roles();

global $wpdb;
$activity_table = Metis_Tables::get('people_activity');
$people_table = Metis_Tables::get('people');

$rows = $wpdb->get_results(
    "SELECT a.id, a.activity_type, a.summary, a.details, a.created_at,
            p.pid AS target_pid, p.display_name AS target_name,
            ap.pid AS actor_pid, ap.display_name AS actor_name
     FROM {$activity_table} a
     LEFT JOIN {$people_table} p ON p.id = a.person_id
     LEFT JOIN {$people_table} ap ON ap.id = a.actor_person_id
     ORDER BY a.created_at DESC
     LIMIT 300",
    ARRAY_A
) ?: [];
?>

<div class="metis-people-ops">
    <h1 class="mw-page-title">People Activity</h1>
    <p class="mw-subtitle">Recent operational activity and profile changes.</p>
    <div class="metis-people-toolbar metis-people-roles-toolbar">
        <div class="metis-contacts-toolbar-right"><a href="<?php echo esc_url(metis_people_base_url()); ?>" class="mw-btn mw-btn-ghost">Dashboard</a></div>
    </div>

    <section class="mw-premium-table">
        <div class="mw-premium-header" style="display:grid;grid-template-columns:180px 220px 1fr 180px 220px;">
            <div class="mw-premium-cell">Time</div>
            <div class="mw-premium-cell">Type</div>
            <div class="mw-premium-cell">Summary</div>
            <div class="mw-premium-cell">Target</div>
            <div class="mw-premium-cell">Actor</div>
        </div>
        <?php foreach($rows as $row): ?>
            <div class="mw-premium-row" style="display:grid;grid-template-columns:180px 220px 1fr 180px 220px;align-items:center;">
                <div class="mw-premium-cell"><?php echo esc_html((string)$row['created_at']); ?></div>
                <div class="mw-premium-cell"><?php echo esc_html((string)$row['activity_type']); ?></div>
                <div class="mw-premium-cell"><?php echo esc_html((string)$row['summary']); ?></div>
                <div class="mw-premium-cell"><?php echo esc_html(trim((string)$row['target_name']) !== '' ? (string)$row['target_name'] . ' (' . (string)$row['target_pid'] . ')' : '—'); ?></div>
                <div class="mw-premium-cell"><?php echo esc_html(trim((string)$row['actor_name']) !== '' ? (string)$row['actor_name'] . ' (' . (string)$row['actor_pid'] . ')' : 'System'); ?></div>
            </div>
        <?php endforeach; ?>
    </section>
</div>
