<?php
if ( ! defined( 'METIS_ROOT' ) ) exit;
require_once __DIR__ . '/_settings_bootstrap.php';
$ctx = metis_settings_bootstrap( 'modules' );
if ( empty( $ctx['allowed'] ) ) return;
extract( $ctx, EXTR_SKIP );

$module_registry = function_exists( 'metis_github_update_service' )
    ? (array) metis_github_update_service()->moduleRegistry()
    : [];
$module_update_status = function_exists( 'metis_module_update_status_snapshot' )
    ? (array) metis_module_update_status_snapshot()
    : [];
$registry_rows = is_array( $module_registry['modules'] ?? null ) ? (array) $module_registry['modules'] : [];
$update_rows = is_array( $module_update_status['modules'] ?? null ) ? (array) $module_update_status['modules'] : [];
$update_map = [];
foreach ( $update_rows as $row ) {
    if ( ! is_array( $row ) ) {
        continue;
    }
    $row_id = metis_key_clean( (string) ( $row['id'] ?? '' ) );
    if ( $row_id !== '' ) {
        $update_map[ $row_id ] = $row;
    }
}

$modules = [];
foreach ( $registry_rows as $module_id => $registry_row ) {
    if ( ! is_array( $registry_row ) ) {
        continue;
    }
    $module_id = metis_key_clean( (string) $module_id );
    if ( $module_id === '' ) {
        continue;
    }
    $update_row = is_array( $update_map[ $module_id ] ?? null ) ? (array) $update_map[ $module_id ] : [];
    $current_version = trim( (string) ( $update_row['current'] ?? '' ) );
    $name = trim( (string) ( $update_row['name'] ?? '' ) );
    if ( $name === '' ) {
        $name = ucwords( str_replace( [ '_', '-' ], ' ', $module_id ) );
    }
    $modules[] = [
        'id' => $module_id,
        'name' => $name,
        'latest' => trim( (string) ( $registry_row['latest'] ?? '' ) ),
        'minimum_metis' => trim( (string) ( $registry_row['minimum_metis'] ?? '' ) ),
        'release_channel' => trim( (string) ( $registry_row['release_channel'] ?? 'stable' ) ) ?: 'stable',
        'download_url' => trim( (string) ( $registry_row['download_url'] ?? '' ) ),
        'installed' => $current_version !== '',
        'current' => $current_version,
        'update_available' => ! empty( $update_row['update_available'] ),
        'status' => trim( (string) ( $update_row['status'] ?? ( $current_version !== '' ? 'installed' : 'available' ) ) ),
        'reason' => trim( (string) ( $update_row['reason'] ?? '' ) ),
    ];
}

usort( $modules, static fn ( array $left, array $right ): int => strcmp( (string) $left['name'], (string) $right['name'] ) );
?>
<h1 class="metis-page-title"><?php echo metis_escape_html( metis_current_module_view_title( 'Settings' ) ); ?></h1>
<p class="metis-subtitle">Browse registry-backed modules and install updates immediately.</p>
<?php metis_settings_render_messages( $saved, $errors ); ?>
<?php metis_settings_render_section_nav( 'modules' ); ?>

<div class="metis-settings-card">
    <div class="metis-settings-header">
        <h2>Modules Store</h2>
        <span class="metis-settings-status <?php echo $modules === [] ? 'is-missing' : 'is-ok'; ?>"><?php echo metis_escape_html( (string) count( $modules ) ); ?></span>
    </div>
    <div class="metis-settings-body">
        <div class="metis-shortcode-wrap" style="align-items:flex-start; gap:16px; flex-wrap:wrap;">
            <div>
                <div><strong>Registry Status</strong></div>
                <div class="metis-help"><?php echo metis_escape_html( (string) ( $module_registry['status'] ?? 'unavailable' ) ); ?></div>
            </div>
            <div>
                <div><strong>Generated</strong></div>
                <div class="metis-help"><?php echo metis_escape_html( (string) ( $module_registry['generated_at'] ?? 'unknown' ) ); ?></div>
            </div>
            <div>
                <div><strong>Catalog Source</strong></div>
                <div class="metis-help"><code>meta/modules.json</code></div>
            </div>
            <div class="metis-settings-actions">
                <button type="button" class="metis-btn metis-btn-secondary metis-btn-sm" data-release-check-updates>Refresh Registry</button>
            </div>
        </div>
        <?php if ( ! empty( $module_registry['error'] ) ) : ?>
            <p class="metis-help" style="color:#b91c1c;"><?php echo metis_escape_html( (string) $module_registry['error'] ); ?></p>
        <?php endif; ?>
        <?php if ( $modules === [] ) : ?>
            <p class="metis-help">No registry modules are currently available.</p>
        <?php else : ?>
            <div class="metis-module-grid">
                <?php foreach ( $modules as $module ) : ?>
                    <div class="metis-module-card">
                        <div class="metis-module-card__head">
                            <div>
                                <div class="metis-module-card__title"><?php echo metis_escape_html( (string) $module['name'] ); ?></div>
                                <div class="metis-help" style="margin-top:4px;">
                                    <?php if ( ! empty( $module['installed'] ) ) : ?>
                                        <?php echo metis_escape_html( (string) $module['current'] ); ?> → <?php echo metis_escape_html( (string) $module['latest'] ); ?>
                                    <?php else : ?>
                                        Latest <?php echo metis_escape_html( (string) $module['latest'] ); ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="metis-module-card__actions">
                                <?php
                                $module_update_icon = metis_navigation_icon_markup( 'icon:repeat' );
                                $module_loading_icon = metis_navigation_svg_icon_markup( 'loading-circle' );
                                ?>
                                <button
                                    type="button"
                                    class="metis-btn metis-btn-secondary metis-btn-sm metis-module-action"
                                    data-module-install-id="<?php echo metis_escape_attr( (string) $module['id'] ); ?>"
                                    data-module-install-name="<?php echo metis_escape_attr( (string) $module['name'] ); ?>"
                                    data-module-install-version="<?php echo metis_escape_attr( (string) $module['latest'] ); ?>"
                                >
                                    <span class="metis-module-action__label"><?php echo ! empty( $module['installed'] ) ? ( ! empty( $module['update_available'] ) ? 'Update' : 'Reinstall' ) : 'Install'; ?></span>
                                    <span class="metis-module-action__icon" aria-hidden="true"><?php echo $module_update_icon; ?></span>
                                    <span class="metis-module-action__spinner" aria-hidden="true"><?php echo $module_loading_icon; ?></span>
                                </button>
                            </div>
                        </div>
                        <div class="metis-module-card__meta">
                            <span class="metis-chip" style="background:#eef2ff; color:#3730a3; border:1px solid #c7d2fe;"><?php echo metis_escape_html( (string) $module['release_channel'] ); ?></span>
                            <span class="metis-chip" style="background:#ecfdf5; color:#166534; border:1px solid #a7f3d0;">Registry</span>
                            <?php if ( ! empty( $module['installed'] ) ) : ?>
                                <span class="metis-chip" style="background:#f3f4f6; color:#374151; border:1px solid #d1d5db;">Installed</span>
                            <?php endif; ?>
                            <?php if ( ! empty( $module['update_available'] ) ) : ?>
                                <span class="metis-chip" style="background:#fff7ed; color:#9a3412; border:1px solid #fdba74;">Update Available</span>
                            <?php endif; ?>
                        </div>
                        <div class="metis-help">Minimum Metis <?php echo metis_escape_html( (string) $module['minimum_metis'] ); ?></div>
                        <?php if ( ! empty( $module['reason'] ) ) : ?>
                            <div class="metis-help" style="color:#92400e;"><?php echo metis_escape_html( (string) $module['reason'] ); ?></div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>
