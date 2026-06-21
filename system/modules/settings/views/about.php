<?php
if ( ! defined( 'METIS_ROOT' ) ) exit;
require_once __DIR__ . '/_settings_bootstrap.php';
$ctx = metis_settings_bootstrap( 'about' );
if ( empty( $ctx['allowed'] ) ) return;
extract( $ctx, EXTR_SKIP );

$release_current = is_array( $release_status['current'] ?? null ) ? $release_status['current'] : [];
$release_latest = is_array( $release_status['latest'] ?? null ) ? $release_status['latest'] : [];
$release_repository = is_array( $release_status['repository'] ?? null ) ? $release_status['repository'] : [];
$release_installed_version = (string) ( $release_current['version'] ?? $release_status['installed_version'] ?? ( $system_version['metis_version'] ?? 'unknown' ) );
$release_installed_label = trim( (string) ( $release_current['tag'] ?? $release_status['installed_tag'] ?? '' ) );
if ( $release_installed_label === '' && $release_installed_version !== '' && $release_installed_version !== 'unknown' ) {
    $release_installed_label = 'v' . ltrim( $release_installed_version, 'vV' );
}
if ( $release_installed_label === '' ) {
    $release_installed_label = 'unknown';
}
$release_can_apply_latest = $is_system_admin
    && ! empty( $release_status['update_available'] )
    && ! empty( $release_latest['tag'] );
$release_can_rollback = $is_system_admin
    && is_array( $release_status['state'] ?? null )
    && (string) ( $release_status['state']['previous_tag'] ?? '' ) !== '';
$release_auto_update_enabled = function_exists( 'metis_release_auto_update_enabled' )
    ? metis_release_auto_update_enabled()
    : (bool) Core_Settings_Service::get( 'release_auto_update_enabled', true );
$release_auto_update_max_level = function_exists( 'metis_release_auto_update_max_level' )
    ? metis_release_auto_update_max_level()
    : (string) Core_Settings_Service::get( 'release_auto_update_max_level', 'patch' );
$module_count = is_array( $system_version['modules'] ?? null ) ? count( $system_version['modules'] ) : 0;
$repository_head = (string) ( $release_repository['head'] ?? '' );
$module_versions = is_array( $system_version['modules'] ?? null ) ? $system_version['modules'] : [];
$module_details = is_array( $system_version['module_details'] ?? null ) ? $system_version['module_details'] : [];
$module_failures = is_array( $system_version['module_failures'] ?? null ) ? $system_version['module_failures'] : [];
$failed_module_count = count( $module_failures );
$loaded_module_count = max( 0, $module_count - $failed_module_count );
if ( $module_details === [] && $module_versions !== [] ) {
    foreach ( $module_versions as $module_slug => $module_version ) {
        $module_details[] = [
            'slug' => (string) $module_slug,
            'version' => (string) $module_version,
            'status' => 'loaded',
            'reason' => '',
        ];
    }
}
$modules = function_exists( 'metis_get_modules' ) ? metis_get_modules() : [];
$module_registry = function_exists( 'metis_github_update_service' )
    ? (array) metis_github_update_service()->moduleRegistry()
    : [];
$module_update_status = function_exists( 'metis_module_update_status_snapshot' )
    ? (array) metis_module_update_status_snapshot()
    : ( function_exists( 'metis_module_update_status' ) ? (array) metis_module_update_status( false ) : [] );
$registry_modules = is_array( $module_registry['modules'] ?? null ) ? (array) $module_registry['modules'] : [];
$registry_module_lookup = array_fill_keys( array_keys( $registry_modules ), true );
$module_update_rows = is_array( $module_update_status['modules'] ?? null ) ? (array) $module_update_status['modules'] : [];
$module_update_map = [];
foreach ( $module_update_rows as $module_update_row ) {
    if ( ! is_array( $module_update_row ) ) {
        continue;
    }
    $update_id = metis_key_clean( (string) ( $module_update_row['id'] ?? '' ) );
    if ( $update_id !== '' ) {
        $module_update_map[ $update_id ] = $module_update_row;
    }
}
$available_module_updates = array_values(
    array_filter(
        $module_update_rows,
        static fn ( mixed $row ): bool => is_array( $row ) && ! empty( $row['update_available'] )
    )
);
$module_update_count = (int) ( $module_update_status['update_count'] ?? count( $available_module_updates ) );
$update_notice_count = ( ! empty( $release_status['update_available'] ) ? 1 : 0 ) + $module_update_count;
usort( $module_details, static function ( array $a, array $b ): int {
    return strcmp( (string) ( $a['slug'] ?? '' ), (string) ( $b['slug'] ?? '' ) );
} );
?>
<h1 class="metis-page-title"><?php echo metis_escape_html( metis_current_module_view_title( 'Settings' ) ); ?></h1>
<p class="metis-subtitle">Review the installed Metis version and current release state.</p>
<?php metis_settings_render_messages( $saved, $errors ); ?>
<?php metis_settings_render_section_nav( 'about' ); ?>

<div class="metis-settings-card">
    <div class="metis-settings-header">
        <h2>Updates Available</h2>
        <span class="metis-settings-status <?php echo $update_notice_count > 0 ? 'is-warning' : 'is-ok'; ?>"><?php echo metis_escape_html( (string) $update_notice_count ); ?></span>
    </div>
    <div class="metis-settings-body">
        <div class="metis-shortcode-wrap" style="align-items:flex-start; gap:16px; flex-wrap:wrap;">
            <div>
                <div><strong>Core</strong></div>
                <div class="metis-help">
                    <?php if ( ! empty( $release_status['update_available'] ) ) : ?>
                        <?php echo metis_escape_html( $release_installed_version ); ?> → <?php echo metis_escape_html( (string) ( $release_latest['version'] ?? $release_latest['tag'] ?? '' ) ); ?>
                    <?php else : ?>
                        No core update available
                    <?php endif; ?>
                </div>
            </div>
            <div>
                <div><strong>Modules</strong></div>
                <div class="metis-help"><?php echo metis_escape_html( (string) $module_update_count ); ?> update<?php echo $module_update_count === 1 ? '' : 's'; ?> available</div>
            </div>
            <div>
                <div><strong>Module Check</strong></div>
                <div class="metis-help">
                    <?php echo metis_escape_html( (string) ( $module_update_status['checked_at'] ?? 'never' ) ); ?>
                    <?php if ( ! empty( $module_update_status['registry_status'] ) ) : ?>
                        · <?php echo metis_escape_html( (string) $module_update_status['registry_status'] ); ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php if ( $available_module_updates !== [] ) : ?>
            <div style="display:grid; gap:10px; margin-top:16px;">
                <?php foreach ( $available_module_updates as $module_update ) : ?>
                    <div style="padding:14px 16px; border:1px solid #d9deea; border-radius:12px; background:#f7f8fc; display:flex; align-items:center; justify-content:space-between; gap:16px; flex-wrap:wrap;">
                        <div>
                            <div style="font-weight:700; color:#1f2330;"><?php echo metis_escape_html( (string) ( $module_update['name'] ?? $module_update['module'] ?? 'Module' ) ); ?></div>
                            <div class="metis-help" style="margin:4px 0 0 0;">
                                <?php echo metis_escape_html( (string) ( $module_update['current'] ?? 'unknown' ) ); ?> → <?php echo metis_escape_html( (string) ( $module_update['latest'] ?? 'unknown' ) ); ?>
                            </div>
                        </div>
                        <div style="display:flex; align-items:center; gap:8px; flex-wrap:wrap;">
                            <span class="metis-chip" style="background:#fff7ed; color:#9a3412; border:1px solid #fdba74;">Update Available</span>
                            <span class="metis-chip" style="background:#f3f4f6; color:#374151; border:1px solid #d1d5db;"><?php echo metis_escape_html( (string) ( $module_update['release_channel'] ?? 'stable' ) ); ?></span>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php elseif ( ! empty( $module_update_status['registry_error'] ) ) : ?>
            <p class="metis-help" style="color:#b91c1c; margin-top:16px;"><?php echo metis_escape_html( (string) $module_update_status['registry_error'] ); ?></p>
        <?php else : ?>
            <p class="metis-help" style="margin-top:16px;">No module updates are currently available.</p>
        <?php endif; ?>
    </div>
</div>

<div class="metis-settings-card">
    <div class="metis-settings-header">
        <h2>Metis Version</h2>
        <span class="metis-settings-status is-ok"><?php echo metis_escape_html( (string) ( $system_version['metis_version'] ?? 'unknown' ) ); ?></span>
    </div>
    <div class="metis-settings-body">
        <div class="metis-shortcode-wrap" style="align-items:flex-start; gap:16px; flex-wrap:wrap;">
            <div>
                <div><strong>Installed Version</strong></div>
                <div class="metis-help"><?php echo metis_escape_html( (string) ( $system_version['metis_version'] ?? 'unknown' ) ); ?></div>
            </div>
            <div>
                <div><strong>Build Stamp</strong></div>
                <div class="metis-help"><?php echo metis_escape_html( (string) ( $system_version['build'] ?? 'unknown' ) ); ?></div>
            </div>
            <div>
                <div><strong>Loaded Modules</strong></div>
                <div class="metis-help">
                    <?php echo metis_escape_html( (string) $loaded_module_count ); ?> loaded
                    <?php if ( $failed_module_count > 0 ) : ?>
                        · <?php echo metis_escape_html( (string) $failed_module_count ); ?> failed
                    <?php endif; ?>
                </div>
            </div>
            <?php if ( $repository_head !== '' ) : ?>
                <div>
                    <div><strong>Current Commit</strong></div>
                    <div class="metis-help"><code><?php echo metis_escape_html( $repository_head ); ?></code></div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php if ( $module_details !== [] ) : ?>
    <div class="metis-settings-card">
        <div class="metis-settings-header"><h2>Loaded Modules</h2></div>
        <div class="metis-settings-body">
            <div class="metis-module-grid">
                <?php foreach ( $module_details as $module_detail ) : ?>
                    <?php
                    $module_slug = metis_key_clean( (string) ( $module_detail['slug'] ?? '' ) );
                    if ( $module_slug === '' ) {
                        continue;
                    }
                    $module_version = (string) ( $module_detail['version'] ?? 'unknown' );
                    $module_status = metis_key_clean( (string) ( $module_detail['status'] ?? 'loaded' ) );
                    $module_reason = trim( (string) ( $module_detail['reason'] ?? '' ) );
                    $module_update = is_array( $module_update_map[ $module_slug ] ?? null ) ? (array) $module_update_map[ $module_slug ] : [];
                    $has_module_update = ! empty( $module_update['update_available'] );
                    $module_badge = 'Custom';
                    if ( ! empty( $modules[ $module_slug ]['config']['required'] ) ) {
                        $module_badge = 'Required';
                    } elseif ( ! empty( $registry_module_lookup[ $module_slug ] ) ) {
                        $module_badge = 'Registry';
                    }
                    $module_badge_style = 'background:#f3f4f6; color:#374151; border:1px solid #d1d5db;';
                    if ( $module_badge === 'Required' ) {
                        $module_badge_style = 'background:#eef2ff; color:#3730a3; border:1px solid #c7d2fe;';
                    } elseif ( $module_badge === 'Registry' ) {
                        $module_badge_style = 'background:#ecfdf5; color:#166534; border:1px solid #a7f3d0;';
                    }
                    $status_label = $module_status === 'failed' ? 'Failed' : 'Loaded';
                    $status_style = $module_status === 'failed'
                        ? 'background:#fef2f2; color:#991b1b; border:1px solid #fecaca;'
                        : 'background:#ecfdf5; color:#166534; border:1px solid #a7f3d0;';
                    ?>
                    <div class="metis-module-card">
                        <div class="metis-module-card__head">
                            <div>
                                <div class="metis-module-card__title">
                                    <?php echo metis_escape_html( ucwords( str_replace( [ '_', '-' ], ' ', (string) $module_slug ) ) ); ?>
                                </div>
                                <div class="metis-help" style="margin:8px 0 0 0;">
                                    <code><?php echo metis_escape_html( (string) $module_version ); ?></code>
                                    <?php if ( $has_module_update ) : ?>
                                        → <code><?php echo metis_escape_html( (string) ( $module_update['latest'] ?? '' ) ); ?></code>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="metis-module-card__actions">
                                <?php if ( $has_module_update ) : ?>
                                    <?php
                                    $module_update_icon = metis_navigation_icon_markup( 'icon:repeat' );
                                    $module_loading_icon = metis_navigation_svg_icon_markup( 'loading-circle' );
                                    ?>
                                    <button
                                        type="button"
                                        class="metis-module-refresh"
                                        data-module-install-id="<?php echo metis_escape_attr( (string) $module_slug ); ?>"
                                        data-module-install-name="<?php echo metis_escape_attr( ucwords( str_replace( [ '_', '-' ], ' ', (string) $module_slug ) ) ); ?>"
                                        data-module-install-version="<?php echo metis_escape_attr( (string) ( $module_update['latest'] ?? '' ) ); ?>"
                                        title="Install module update"
                                        aria-label="Install module update"
                                    >
                                        <span class="metis-module-refresh__icon" aria-hidden="true"><?php echo $module_update_icon; ?></span>
                                        <span class="metis-module-refresh__spinner" aria-hidden="true"><?php echo $module_loading_icon; ?></span>
                                    </button>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div style="display:flex; align-items:center; justify-content:space-between; gap:12px;">
                            <div class="metis-help" style="margin:0;"><?php echo $has_module_update ? 'Update available' : 'Current'; ?></div>
                            <div style="display:flex; align-items:center; gap:6px; flex-wrap:wrap; justify-content:flex-end;">
                                <span
                                    class="metis-chip"
                                    style="flex-shrink:0; <?php echo metis_escape_attr( $status_style ); ?><?php echo $module_status === 'failed' && $module_reason !== '' ? ' cursor:help;' : ''; ?>"
                                    <?php if ( $module_status === 'failed' && $module_reason !== '' ) : ?>
                                        data-metis-tooltip="<?php echo metis_escape_attr( $module_reason ); ?>"
                                        data-metis-tooltip-position="top"
                                        data-metis-tooltip-variant="error"
                                        tabindex="0"
                                        aria-label="<?php echo metis_escape_attr( 'Failure reason available on hover.' ); ?>"
                                    <?php endif; ?>
                                ><?php echo metis_escape_html( $status_label ); ?></span>
                                <span class="metis-chip" style="flex-shrink:0; <?php echo metis_escape_attr( $module_badge_style ); ?>"><?php echo metis_escape_html( $module_badge ); ?></span>
                                <?php if ( $has_module_update ) : ?>
                                    <span class="metis-chip" style="flex-shrink:0; background:#fff7ed; color:#9a3412; border:1px solid #fdba74;">Update Available</span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php if ( $has_module_update && ! empty( $module_update['minimum_metis'] ) ) : ?>
                            <div class="metis-help">Requires Metis <?php echo metis_escape_html( (string) $module_update['minimum_metis'] ); ?>+</div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
<?php endif; ?>

<div class="metis-settings-card">
    <div class="metis-settings-header"><h2>Release Status</h2></div>
    <div class="metis-settings-body">
        <div class="metis-field">
            <div class="metis-shortcode-wrap" style="align-items:flex-start; gap:16px; flex-wrap:wrap;">
                <div>
                    <div><strong>Installed</strong>: <code><?php echo metis_escape_html( $release_installed_label ); ?></code></div>
                    <div class="metis-help">Version <?php echo metis_escape_html( $release_installed_version ); ?></div>
                </div>
                <div>
                    <div><strong>Latest Trusted</strong>: <code><?php echo metis_escape_html( (string) ( $release_latest['tag'] ?? 'none' ) ); ?></code></div>
                    <div class="metis-help">
                        <?php if ( ! empty( $release_status['update_available'] ) ) : ?>
                            Update available
                        <?php elseif ( ! empty( $release_latest ) ) : ?>
                            Already current
                        <?php else : ?>
                            No trusted tags discovered yet
                        <?php endif; ?>
                    </div>
                </div>
                <div>
                    <div><strong>Repository</strong>: <code><?php
                        if ( empty( $release_repository['available'] ) ) {
                            echo 'unavailable';
                        } elseif ( array_key_exists( 'clean', $release_repository ) && $release_repository['clean'] === null ) {
                            echo 'available';
                        } else {
                            echo ! empty( $release_repository['clean'] ) ? 'clean' : 'dirty';
                        }
                    ?></code></div>
                    <div class="metis-help">Last check: <?php echo metis_escape_html( (string) ( $release_status['last_checked_at'] ?? 'never' ) ); ?></div>
                </div>
                <div>
                    <div><strong>Auto Update</strong>: <code><?php echo metis_escape_html( $release_auto_update_enabled ? 'enabled' : 'disabled' ); ?></code></div>
                    <div class="metis-help"><?php echo metis_escape_html( ucfirst( $release_auto_update_max_level ) ); ?> releases</div>
                </div>
            </div>
            <?php if ( ! empty( $release_status['remote_error'] ) ) : ?>
                <p class="metis-help" style="color:#b91c1c;"><?php echo metis_escape_html( (string) $release_status['remote_error'] ); ?></p>
            <?php endif; ?>
        </div>
        <div class="metis-field">
            <label>Release Source</label>
            <div class="metis-shortcode-wrap">
                <code class="metis-shortcode">
                    <?php
                    $release_source_label = match ( (string) ( $release_status['remote_status'] ?? '' ) ) {
                        'manifest' => 'release manifest + configured GitHub source',
                        'api' => 'GitHub API release metadata',
                        'live' => 'remote semantic Git tags',
                        'cached', 'cache_only' => 'cached trusted release metadata',
                        default => (string) ( $release_repository['remote'] ?? '' ) !== '' ? (string) $release_repository['remote'] : 'configured release source',
                    };
                    echo metis_escape_html( $release_source_label );
                    ?>
                </code>
            </div>
        </div>
    </div>
</div>

<?php if ( $is_system_admin ) : ?>
    <div class="metis-settings-actions">
        <button type="button" class="metis-btn" data-release-check-updates="1">Refresh Updates</button>
        <?php if ( $release_can_apply_latest ) : ?>
            <button
                type="button"
                class="metis-btn metis-btn-secondary"
                data-release-apply-tag="<?php echo metis_escape_attr( (string) $release_latest['tag'] ); ?>"
            >Apply Latest</button>
        <?php endif; ?>
        <?php if ( $release_can_rollback ) : ?>
            <button type="button" class="metis-btn metis-btn-secondary" data-release-rollback="1">Rollback</button>
        <?php endif; ?>
    </div>
<?php endif; ?>

<?php metis_settings_render_section_end(); ?>
