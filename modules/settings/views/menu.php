<?php
if ( ! defined( 'METIS_ROOT' ) ) exit;
require_once __DIR__ . '/_settings_bootstrap.php';
$ctx = metis_settings_bootstrap( 'navigation' );
if ( empty( $ctx['allowed'] ) ) return;
extract( $ctx, EXTR_SKIP );
$navigation_state = function_exists( 'metis_settings_navigation_editor_state' ) ? metis_settings_navigation_editor_state() : [ 'items' => [], 'unassigned' => [] ];
$nav_items = is_array( $navigation_state['items'] ?? null ) ? $navigation_state['items'] : [];
$unassigned = is_array( $navigation_state['unassigned'] ?? null ) ? $navigation_state['unassigned'] : [];
$normalize_nav_children = static function ( array $nodes ) use ( &$normalize_nav_children ): array {
    $out = [];
    foreach ( $nodes as $node ) {
        if ( ! is_array( $node ) ) {
            continue;
        }
        $children = is_array( $node['children'] ?? null ) ? $normalize_nav_children( (array) $node['children'] ) : [];
        $out[] = [
            'id' => (int) ( $node['id'] ?? 0 ),
            'label' => (string) ( $node['label'] ?? '' ),
            'icon' => (string) ( $node['icon'] ?? '' ),
            'route' => (string) ( $node['route'] ?? '' ),
            'is_visible' => ! empty( $node['is_visible'] ) ? 1 : 0,
            'permissions_required' => (string) ( $node['permissions_required'] ?? '' ),
            'module_key' => (string) ( $node['module_key'] ?? '' ),
            'children' => $children,
        ];
    }
    return $out;
};
if ( $nav_items === [] && function_exists( 'metis_navigation_service' ) ) {
    $visible_fallback = metis_navigation_service()->visibleTree();
    if ( is_array( $visible_fallback ) && $visible_fallback !== [] ) {
        $nav_items = $normalize_nav_children( $visible_fallback );
    }
}
$icon_picker_category = static function ( string $slug ): string {
    $slug = strtolower( trim( $slug ) );
    if ( $slug === '' ) {
        return 'general';
    }

    $explicit = [
        'accounting' => 'finance',
        'accessibility' => 'accessibility',
        'add' => 'ui',
        'android' => 'brands',
        'animals' => 'animals',
        'apple' => 'brands',
        'apple-ios' => 'brands',
        'campfire' => 'activities',
        'bat' => 'animals',
        'braille-blind' => 'accessibility',
        'briefcase' => 'people',
        'cards' => 'activities',
        'calendar' => 'actions',
        'dice' => 'activities',
        'chart-scatter' => 'analytics',
        'checkbox' => 'ui',
        'checkbox-checked' => 'ui',
        'checkbox-checked-filled' => 'ui',
        'checkbox-indeterminate' => 'ui',
        'checkbox-indeterminate-filled' => 'ui',
        'checkmark-filled' => 'ui',
        'checkmark-outline' => 'ui',
        'close-filled' => 'ui',
        'close-outline' => 'ui',
        'coffee' => 'food-drinks',
        'contacts' => 'people',
        'credit-card' => 'finance',
        'croissant' => 'food-drinks',
        'database' => 'infrastructure',
        'diagram-alt' => 'analytics',
        'diagram' => 'analytics',
        'disability-ramp' => 'accessibility',
        'divider' => 'ui',
        'donut' => 'food-drinks',
        'drive' => 'files',
        'drink' => 'food-drinks',
        'emoji' => 'ui',
        'facebook' => 'brands',
        'fries' => 'food-drinks',
        'generate-pdf' => 'files',
        'globe' => 'communication',
        'google' => 'brands',
        'grid' => 'ui',
        'h1' => 'editor',
        'heat-map' => 'analytics',
        'hiking' => 'activities',
        'invite' => 'communication',
        'italic' => 'editor',
        'instagram' => 'brands',
        'list' => 'editor',
        'list-boxes' => 'editor',
        'list-bulleted' => 'editor',
        'list-checked' => 'editor',
        'list-dropdown' => 'editor',
        'loading-circle' => 'status',
        'meeting' => 'people',
        'microsoft' => 'brands',
        'movie' => 'activities',
        'muffin' => 'food-drinks',
        'need' => 'donations',
        'notification' => 'communication',
        'pie' => 'food-drinks',
        'pie-slice' => 'food-drinks',
        'pizza' => 'food-drinks',
        'puzzle' => 'activities',
        'radio-button' => 'ui',
        'radio-button-checked' => 'ui',
        'redo' => 'ui',
        'reward' => 'donations',
        'burger' => 'food-drinks',
        'burrito' => 'food-drinks',
        'cake' => 'food-drinks',
        'cat' => 'animals',
        'jenga' => 'activities',
        'cupcake' => 'food-drinks',
        'dog' => 'animals',
        'paw-print' => 'animals',
        'pig' => 'animals',
        'sankey-diagram' => 'analytics',
        'sankey-diagram-alt' => 'analytics',
        'shield-cross' => 'health',
        'sign-language' => 'accessibility',
        'stripe' => 'brands',
        'sushi' => 'food-drinks',
        'taco' => 'food-drinks',
        'undo' => 'ui',
        'vote' => 'activities',
        'website' => 'layout',
        'youtube' => 'brands',
    ];
    if ( isset( $explicit[ $slug ] ) ) {
        return $explicit[ $slug ];
    }

    $contains_any = static function ( string $value, array $needles ): bool {
        foreach ( $needles as $needle ) {
            if ( $needle !== '' && str_contains( $value, (string) $needle ) ) {
                return true;
            }
        }

        return false;
    };

    if ( $contains_any( $slug, [ 'accessibility', 'braille', 'blind', 'sign-language', 'hearing' ] ) ) {
        return 'accessibility';
    }

    if ( str_contains( $slug, 'checkmark' ) || str_contains( $slug, 'checkbox' ) || str_contains( $slug, 'radio-' ) || str_contains( $slug, 'add-' ) || str_contains( $slug, 'close-' ) || str_contains( $slug, 'collapse' ) || str_contains( $slug, 'expand' ) || in_array( $slug, [ 'add', 'grid', 'emoji', 'divider', 'undo', 'redo' ], true ) ) {
        return 'ui';
    }

    if ( str_starts_with( $slug, 'arrow-' ) || str_starts_with( $slug, 'align-' ) || str_starts_with( $slug, 'distribute-' ) || str_starts_with( $slug, 'next-' ) || str_starts_with( $slug, 'repeat' ) || str_contains( $slug, 'tabs' ) ) {
        return 'layout';
    }

    if ( str_starts_with( $slug, 'text-' ) || str_contains( $slug, 'indent' ) || in_array( $slug, [ 'h1', 'italic' ], true ) || str_contains( $slug, 'list-' ) || str_contains( $slug, 'code' ) || str_contains( $slug, 'data-format' ) || str_contains( $slug, 'data-table' ) ) {
        return 'editor';
    }

    if ( $contains_any( $slug, [ 'accounting', 'finance', 'currency', 'money', 'wallet', 'piggy-bank', 'purchase', 'pricing', 'coin', 'cashing-check', 'credit-card' ] ) ) {
        return 'finance';
    }

    if ( $contains_any( $slug, [ 'donat', 'donor', 'campaign', 'hand-donation', 'handshake', 'reward', 'need' ] ) ) {
        return 'donations';
    }

    if ( $contains_any( $slug, [ 'activity', 'activities', 'camp', 'campfire', 'cards', 'dice', 'game', 'hike', 'hiking', 'jenga', 'movie', 'puzzle', 'vote' ] ) ) {
        return 'activities';
    }

    if ( $contains_any( $slug, [ 'food', 'drink', 'burger', 'burrito', 'cake', 'coffee', 'croissant', 'cupcake', 'donut', 'fries', 'muffin', 'pie', 'pizza', 'sushi', 'taco' ] ) ) {
        return 'food-drinks';
    }

    if ( $contains_any( $slug, [ 'shield', 'padlock', 'lock', 'security', 'fingerprint', 'passkey', 'scan', 'auth', 'license' ] ) ) {
        return 'security';
    }

    if ( $contains_any( $slug, [ 'health', 'medical', 'stethoscope', 'reminder-medical' ] ) ) {
        return 'health';
    }

    if ( $contains_any( $slug, [ 'chart', 'graph', 'heat-map', 'dashboard', 'report', 'progress', 'finance', 'phrase-sentiment' ] ) ) {
        return 'analytics';
    }

    if ( str_starts_with( $slug, 'document' ) || str_starts_with( $slug, 'doc' ) || in_array( $slug, [ 'pdf', 'csv', 'json', 'png', 'ppt', 'raw', 'svg', 'txt', 'xls', 'zip', 'gif', 'mp3', 'mp4', 'mov', 'wmv', 'tif', 'vmdk-disk', 'bat' ], true ) ) {
        return 'files';
    }

    if ( $contains_any( $slug, [ 'image', 'screen', 'mobile', 'tablet' ] ) ) {
        return 'files';
    }

    if ( $contains_any( $slug, [ 'email', 'chat', 'notification', 'forum', 'share', 'link', 'phone', 'newsletter', 'service-desk', 'wikis' ] ) ) {
        return 'communication';
    }

    if ( in_array( $slug, [ 'animal', 'animals', 'bat', 'cat', 'dog', 'paw', 'paw-print', 'pig', 'pet', 'pets' ], true ) ) {
        return 'animals';
    }

    if ( str_starts_with( $slug, 'user' ) || str_starts_with( $slug, 'group' ) || str_starts_with( $slug, 'home' ) || str_starts_with( $slug, 'building' ) || str_starts_with( $slug, 'workspace' ) || str_starts_with( $slug, 'apps' ) ) {
        return 'people';
    }

    if ( $contains_any( $slug, [ 'server', 'data-base', 'database', 'ibm-watsonx' ] ) ) {
        return 'infrastructure';
    }

    if ( str_starts_with( $slug, 'settings' ) || str_contains( $slug, 'task' ) || str_contains( $slug, 'calendar' ) || str_contains( $slug, 'time' ) || str_contains( $slug, 'event' ) || str_contains( $slug, 'save' ) || str_contains( $slug, 'edit' ) || str_contains( $slug, 'cut' ) || str_contains( $slug, 'pen' ) || str_contains( $slug, 'printer' ) || str_contains( $slug, 'folder' ) || str_contains( $slug, 'box' ) || str_contains( $slug, 'template' ) || str_contains( $slug, 'add-' ) || str_contains( $slug, 'close-' ) || str_contains( $slug, 'download' ) || str_contains( $slug, 'upload' ) || str_contains( $slug, 'export' ) || str_contains( $slug, 'collapse' ) || str_contains( $slug, 'expand' ) || str_contains( $slug, 'trash' ) || str_contains( $slug, 'logout' ) || str_contains( $slug, 'paper-clip' ) || str_contains( $slug, 'arrows-horizontal' ) ) {
        return 'actions';
    }

    if ( str_starts_with( $slug, 'help' ) || str_contains( $slug, 'information' ) || str_contains( $slug, 'accessibility' ) || str_contains( $slug, 'in-progress' ) || str_contains( $slug, 'need' ) || str_contains( $slug, 'result' ) || str_contains( $slug, 'loading' ) || str_contains( $slug, 'favorite' ) || str_contains( $slug, 'reminder' ) ) {
        return 'status';
    }

    if ( str_starts_with( $slug, 'logo-' ) ) {
        return 'brands';
    }

    return 'general';
};

$icon_picker_items = [];

$svg_icon_keys = function_exists( 'metis_navigation_svg_icon_keys' ) ? metis_navigation_svg_icon_keys() : [];
foreach ( $svg_icon_keys as $icon_key ) {
    $icon_key = metis_key_clean( str_replace( '_', '-', (string) $icon_key ) );
    if ( $icon_key === '' ) {
        continue;
    }

    $label = ucwords( str_replace( '-', ' ', $icon_key ) );
    $svg_markup = function_exists( 'metis_navigation_svg_icon_markup' ) ? (string) metis_navigation_svg_icon_markup( $icon_key ) : '';
    $icon_picker_items[] = [
        'key' => 'icon:' . $icon_key,
        'label' => $label,
        'svg' => $svg_markup,
        'url' => $svg_markup === '' && function_exists( 'metis_navigation_svg_icon_url' ) ? (string) metis_navigation_svg_icon_url( $icon_key ) : '',
        'category' => $icon_picker_category( $icon_key ),
    ];
}
$icon_library_json = function_exists( 'metis_json_encode' ) ? metis_json_encode( $icon_picker_items ) : json_encode( $icon_picker_items );
if ( ! is_string( $icon_library_json ) || $icon_library_json === '' ) {
    $icon_library_json = '[]';
}
?>
<h1 class="metis-page-title"><?php echo metis_escape_html( metis_current_module_view_title( 'Settings' ) ); ?></h1>
<p class="metis-subtitle">Manage portal navigation structure, labels, icons, and visibility.</p>
<?php metis_settings_render_messages( $saved, $errors ); ?>
<?php metis_settings_render_section_nav( 'navigation' ); ?>
<?php if ( empty( $is_system_admin ) ) : ?>
    <div class="metis-callout metis-callout-warning">Only system admins can manage Navigation configurations.</div>
<?php endif; ?>
<?php if ( ! empty( $is_system_admin ) ) : ?>
<form method="post" class="metis-settings-form" data-metis-settings-form="1" data-settings-section="navigation">
    <?php metis_runtime_nonce_field( 'metis_save_settings_navigation', 'metis_settings_nonce' ); ?>
    <input type="hidden" name="navigation_structure" id="metis-navigation-structure" value="">
    <div class="metis-settings-card">
        <div class="metis-settings-header"><h2>Navigation Structure</h2></div>
        <div class="metis-settings-body">
            <p class="metis-help">Use Parent, Up, and Down controls for deterministic ordering (max depth: 2). Use ↰ on a child row to move it back to top level.</p>
            <div class="metis-menu-toolbar">
                <button type="button" class="metis-btn metis-btn-secondary metis-btn-sm" data-menu-add-group>Add Group</button>
            </div>
            <script type="application/json" id="metis-menu-icon-library-json"><?php echo $icon_library_json; ?></script>
            <div class="metis-menu-structure" data-menu-structure>
                <ul class="metis-menu-level" data-menu-dropzone="root">
                    <?php foreach ( $nav_items as $item ) : ?>
                        <?php $children = is_array( $item['children'] ?? null ) ? $item['children'] : []; ?>
                        <?php $item_module_key = (string) ( $item['module_key'] ?? '' ); ?>
                        <?php $is_group = strpos( $item_module_key, 'group:' ) === 0; ?>
                        <?php $is_locked = ( $item_module_key === 'hermes' ); ?>
                        <li class="metis-menu-item<?php echo $is_group ? ' is-group' : ''; ?><?php echo $is_locked ? ' is-locked' : ''; ?>" data-menu-item data-menu-locked="<?php echo $is_locked ? '1' : '0'; ?>" data-menu-is-group="<?php echo $is_group ? '1' : '0'; ?>" data-item-id="<?php echo metis_escape_attr( (string) ( $item['id'] ?? '' ) ); ?>" draggable="true">
                            <div class="metis-menu-item-card">
                                <div class="metis-menu-item-handle" title="Drag to reorder">
                                    ↕
                                    <button type="button" class="metis-menu-item-ungroup" data-menu-move-top title="Move To Top Level">↰</button>
                                </div>
                                <div class="metis-menu-item-fields">
                                    <div class="metis-menu-field-row">
                                        <label>Label</label>
                                        <input class="metis-input" type="text" data-menu-label value="<?php echo metis_escape_attr( (string) ( $item['label'] ?? '' ) ); ?>" <?php disabled( $is_locked ); ?>>
                                    </div>
                                    <div class="metis-menu-field-row">
                                        <label>Icon</label>
                                        <input type="hidden" data-menu-icon value="<?php echo metis_escape_attr( (string) ( $item['icon'] ?? '' ) ); ?>" <?php disabled( $is_locked ); ?>>
                                        <div class="metis-menu-icon-picker-row">
                                            <div class="metis-menu-icon-preview" data-menu-icon-preview></div>
                                            <button type="button" class="metis-btn metis-btn-secondary metis-btn-sm" data-menu-icon-picker-toggle <?php disabled( $is_locked ); ?>>Choose Icon</button>
                                            <button type="button" class="metis-btn metis-btn-secondary metis-btn-sm" data-menu-icon-clear <?php disabled( $is_locked ); ?>>Clear</button>
                                            <span class="metis-menu-icon-token" data-menu-icon-token></span>
                                        </div>
                                    </div>
                                    <div class="metis-menu-field-row">
                                        <label>Visible</label>
                                        <input type="checkbox" data-menu-visible <?php metis_attr_checked( ! empty( $item['is_visible'] ) ); ?> <?php disabled( $is_locked ); ?>>
                                    </div>
                                </div>
                            </div>
                            <input type="hidden" data-menu-route value="<?php echo metis_escape_attr( (string) ( $item['route'] ?? '' ) ); ?>">
                            <input type="hidden" data-menu-permission value="<?php echo metis_escape_attr( (string) ( $item['permissions_required'] ?? '' ) ); ?>">
                            <input type="hidden" data-menu-module-key value="<?php echo metis_escape_attr( (string) ( $item['module_key'] ?? '' ) ); ?>">
                            <ul class="metis-menu-level metis-menu-children" data-menu-dropzone="children">
                                <?php foreach ( $children as $child ) : ?>
                                    <?php $child_module_key = (string) ( $child['module_key'] ?? '' ); ?>
                                    <?php $child_is_group = strpos( $child_module_key, 'group:' ) === 0; ?>
                                    <?php $child_is_locked = ( $child_module_key === 'hermes' ); ?>
                                    <li class="metis-menu-item metis-menu-item-child<?php echo $child_is_group ? ' is-group' : ''; ?><?php echo $child_is_locked ? ' is-locked' : ''; ?>" data-menu-item data-menu-locked="<?php echo $child_is_locked ? '1' : '0'; ?>" data-menu-is-group="<?php echo $child_is_group ? '1' : '0'; ?>" data-item-id="<?php echo metis_escape_attr( (string) ( $child['id'] ?? '' ) ); ?>" draggable="true">
                                        <div class="metis-menu-item-card">
                                            <div class="metis-menu-item-handle" title="Drag to reorder">
                                                ↕
                                                <button type="button" class="metis-menu-item-ungroup" data-menu-move-top title="Move To Top Level">↰</button>
                                            </div>
                                            <div class="metis-menu-item-fields">
                                                <div class="metis-menu-field-row">
                                                    <label>Label</label>
                                                    <input class="metis-input" type="text" data-menu-label value="<?php echo metis_escape_attr( (string) ( $child['label'] ?? '' ) ); ?>" <?php disabled( $child_is_locked ); ?>>
                                                </div>
                                                <div class="metis-menu-field-row">
                                                    <label>Icon</label>
                                                    <input type="hidden" data-menu-icon value="<?php echo metis_escape_attr( (string) ( $child['icon'] ?? '' ) ); ?>" <?php disabled( $child_is_locked ); ?>>
                                                    <div class="metis-menu-icon-picker-row">
                                                        <div class="metis-menu-icon-preview" data-menu-icon-preview></div>
                                                        <button type="button" class="metis-btn metis-btn-secondary metis-btn-sm" data-menu-icon-picker-toggle <?php disabled( $child_is_locked ); ?>>Choose Icon</button>
                                                        <button type="button" class="metis-btn metis-btn-secondary metis-btn-sm" data-menu-icon-clear <?php disabled( $child_is_locked ); ?>>Clear</button>
                                                        <span class="metis-menu-icon-token" data-menu-icon-token></span>
                                                    </div>
                                                </div>
                                                <div class="metis-menu-field-row">
                                                    <label>Visible</label>
                                                    <input type="checkbox" data-menu-visible <?php metis_attr_checked( ! empty( $child['is_visible'] ) ); ?> <?php disabled( $child_is_locked ); ?>>
                                                </div>
                                            </div>
                                        </div>
                                        <input type="hidden" data-menu-route value="<?php echo metis_escape_attr( (string) ( $child['route'] ?? '' ) ); ?>">
                                        <input type="hidden" data-menu-permission value="<?php echo metis_escape_attr( (string) ( $child['permissions_required'] ?? '' ) ); ?>">
                                        <input type="hidden" data-menu-module-key value="<?php echo metis_escape_attr( (string) ( $child['module_key'] ?? '' ) ); ?>">
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <div class="metis-menu-icon-panel" data-menu-icon-panel hidden></div>
            <div class="metis-settings-card" style="margin-top:12px;">
                <div class="metis-settings-header"><h2>Unassigned Modules</h2></div>
                <div class="metis-settings-body">
                    <?php if ( empty( $unassigned ) ) : ?>
                        <p class="metis-help">All modules are assigned to the menu.</p>
                    <?php else : ?>
                        <div class="metis-menu-unassigned">
                            <?php foreach ( $unassigned as $module ) : ?>
                                <button type="button"
                                        class="metis-btn metis-btn-secondary metis-btn-sm"
                                        data-menu-add-unassigned
                                        draggable="false"
                                        data-menu-module-key="<?php echo metis_escape_attr( (string) ( $module['module_key'] ?? '' ) ); ?>"
                                        data-menu-module-label="<?php echo metis_escape_attr( (string) ( $module['label'] ?? '' ) ); ?>"
                                        data-menu-module-icon="<?php echo metis_escape_attr( (string) ( $module['icon'] ?? '' ) ); ?>"
                                        data-menu-module-route="<?php echo metis_escape_attr( (string) ( $module['route'] ?? '' ) ); ?>"
                                        data-menu-module-permission="<?php echo metis_escape_attr( (string) ( $module['permissions_required'] ?? '' ) ); ?>">
                                    Add <?php echo metis_escape_html( (string) ( $module['label'] ?? '' ) ); ?>
                                </button>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    <div class="metis-settings-actions">
        <button type="submit" class="metis-btn">Save Navigation</button>
    </div>
</form>
<?php endif; ?>
<?php metis_settings_render_section_end(); ?>
