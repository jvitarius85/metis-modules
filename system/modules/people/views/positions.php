<?php if (!defined('METIS_ROOT')) exit;

if (!metis_people_can_manage()) {
    echo '<div class="metis-alert metis-alert-error">You do not have permission to manage positions.</div>';
    return;
}

metis_people_ensure_schema();
metis_people_seed_permissions_and_roles();

$db = metis_db();
$positions_table = Metis_Tables::get('people_positions');
$rows = $db->fetchAll(
    "SELECT id, group_key, position_label, sort_order
     FROM {$positions_table}
     WHERE is_active = 1
     ORDER BY group_key ASC, sort_order ASC, position_label ASC"
) ?: [];

$grouped = [
    'board' => [],
    'staff' => [],
    'volunteer' => [],
];
foreach ($rows as $row) {
    $group_key = metis_key_clean((string) ($row['group_key'] ?? ''));
    if (!isset($grouped[$group_key])) continue;
    $grouped[$group_key][] = [
        'id' => (int) ($row['id'] ?? 0),
        'label' => (string) ($row['position_label'] ?? ''),
    ];
}
?>

<div class="metis-people metis-people-positions-page" data-can-manage="1">
    <h1 class="metis-page-title"><?php echo metis_escape_html(metis_current_module_label('People')); ?> Positions</h1>
    <p class="metis-subtitle">Standardize positions by group for consistent assignment.</p>
    <div id="metis-people-alert" class="metis-alert" style="display:none;"></div>

    <?php metis_render_sidebar_layout([
        'sidebar' => static function () { ?>
            <div class="metis-list-sidebar-section">
                <div class="metis-list-sidebar-label">Position Groups</div>
                <div class="metis-muted">Board, Staff, Volunteer</div>
            </div>
            <div class="metis-list-sidebar-actions">
                <a href="<?php echo metis_escape_url(metis_people_people_list_url()); ?>" class="metis-btn metis-btn-xs metis-btn-ghost">Back To People</a>
            </div>
        <?php },
        'content' => static function () use ($grouped) { ?>
            <section class="metis-card" style="padding:16px;">
                <?php foreach (['board' => 'Board Positions', 'staff' => 'Staff Positions', 'volunteer' => 'Volunteer Positions'] as $group_key => $group_label) : ?>
                    <div class="metis-field metis-field-full" style="margin-bottom:14px;">
                        <label><?php echo metis_escape_html($group_label); ?></label>
                        <div class="metis-people-position-list" data-position-list="<?php echo metis_escape_attr($group_key); ?>">
                            <?php if (!empty($grouped[$group_key])) : ?>
                                <?php foreach ($grouped[$group_key] as $position) : ?>
                                    <span class="metis-people-position-pill">
                                        <span><?php echo metis_escape_html((string) ($position['label'] ?? '')); ?></span>
                                        <button type="button" title="Delete" data-position-delete="<?php echo metis_escape_attr((string) ($position['id'] ?? 0)); ?>" data-position-group="<?php echo metis_escape_attr($group_key); ?>">x</button>
                                    </span>
                                <?php endforeach; ?>
                            <?php else : ?>
                                <div class="metis-muted">No positions configured.</div>
                            <?php endif; ?>
                        </div>
                        <div class="metis-form-grid" style="margin-top:8px;">
                            <div class="metis-field metis-field-half">
                                <input type="text" class="metis-input" data-position-new-label="<?php echo metis_escape_attr($group_key); ?>" placeholder="New <?php echo metis_escape_attr(strtolower($group_label)); ?>">
                            </div>
                            <div class="metis-field metis-field-half">
                                <button type="button" class="metis-btn" data-position-add="<?php echo metis_escape_attr($group_key); ?>">Add</button>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </section>
        <?php },
    ]); ?>
</div>
