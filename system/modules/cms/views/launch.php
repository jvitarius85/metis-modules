<?php
if ( ! defined( 'METIS_ROOT' ) ) {
    exit;
}

require_once __DIR__ . '/_access.php';
if ( ! metis_cms_require_view_permission( 'launch' ) ) {
    return;
}

use Metis\Modules\Cms\Services\CmsLaunchService;

$status = CmsLaunchService::status();
$readiness = is_array( $status['readiness'] ?? null ) ? $status['readiness'] : [];
$items = is_array( $readiness['items'] ?? null ) ? $readiness['items'] : [];
$launched = ! empty( $status['launched'] );
$can_launch = ! empty( $status['can_launch'] );
$score = (int) ( $status['score'] ?? 0 );
$total = (int) ( $status['total'] ?? 0 );
$state_label = $launched ? 'Live' : ( $can_launch ? 'Ready' : 'Setup Needed' );
$state_class = $launched ? 'live' : ( $can_launch ? 'ready' : 'setup' );
$dashboard_url = metis_portal_url( 'cms', 'dashboard' );
$pages_url = metis_portal_url( 'cms', 'pages' );
?>
<div class="metis-cms-home metis-cms-launch" id="metis-cms-launch-shell">
    <div class="metis-page-header">
        <div class="metis-page-header-left">
            <div class="metis-breadcrumb-card"><a href="<?php echo metis_escape_url( $dashboard_url ); ?>">CMS</a> › Launch</div>
            <h1 class="metis-page-title">Launch Center</h1>
            <p class="metis-subtitle">Review readiness and control when CMS pages are available to visitors.</p>
        </div>
        <div class="metis-page-header-right metis-cms-launch-controls">
            <button type="button" id="metis-cms-launch-refresh-btn" class="metis-btn metis-btn-secondary metis-btn-sm">Refresh Readiness</button>
            <button type="button" id="metis-cms-launch-enable-btn" class="metis-btn metis-btn-primary metis-btn-sm<?php echo $launched ? ' metis-is-hidden' : ''; ?>" data-force="<?php echo $can_launch ? '0' : '1'; ?>">Enable Public Routes</button>
            <button type="button" id="metis-cms-launch-disable-btn" class="metis-btn metis-btn-secondary metis-btn-sm<?php echo $launched ? '' : ' metis-is-hidden'; ?>">Disable Public Routes</button>
        </div>
    </div>

    <div class="metis-cms-launch-grid">
        <section class="metis-cms-panel metis-cms-launch-state metis-cms-launch-state--<?php echo metis_escape_attr( $state_class ); ?>">
            <span class="metis-cms-status-label">Public CMS</span>
            <strong id="metis-cms-launch-state-label"><?php echo metis_escape_html( $state_label ); ?></strong>
            <span id="metis-cms-launch-state-copy"><?php echo $launched ? 'Visitor-facing CMS routes are enabled.' : 'CMS routes stay private until launch is enabled.'; ?></span>
        </section>

        <section class="metis-cms-panel metis-cms-launch-score-panel">
            <span class="metis-cms-status-label">Readiness</span>
            <strong id="metis-cms-launch-score"><?php echo metis_escape_html( (string) $score ); ?>/<?php echo metis_escape_html( (string) $total ); ?></strong>
            <span>checks passing</span>
        </section>

        <section class="metis-cms-panel metis-cms-launch-note">
            <span class="metis-cms-status-label">Rollback</span>
            <strong>Instant</strong>
            <span>Disabling public routes returns CMS content to admin-only access without deleting content.</span>
        </section>
    </div>

    <section class="metis-cms-panel metis-cms-readiness-panel">
        <div class="metis-cms-readiness-summary">
            <div class="metis-cms-panel-heading">
                <h2>Launch Checklist</h2>
                <p>Blocked items should be resolved before turning on visitor routes.</p>
            </div>
            <a class="metis-btn metis-btn-secondary metis-btn-sm" href="<?php echo metis_escape_url( $pages_url ); ?>">Open Pages</a>
        </div>

        <div class="metis-cms-readiness-list" id="metis-cms-launch-list">
            <?php foreach ( $items as $item ) : ?>
                <?php
                $item_status = metis_key_clean( (string) ( $item['status'] ?? 'attention' ) );
                $item_status = in_array( $item_status, [ 'ready', 'attention', 'blocked' ], true ) ? $item_status : 'attention';
                $action_url = (string) ( $item['action_url'] ?? '' );
                ?>
                <article class="metis-cms-readiness-item metis-cms-readiness-item--<?php echo metis_escape_attr( $item_status ); ?>">
                    <span class="metis-cms-readiness-dot" aria-hidden="true"></span>
                    <div>
                        <strong><?php echo metis_escape_html( (string) ( $item['label'] ?? '' ) ); ?></strong>
                        <span><?php echo metis_escape_html( (string) ( $item['detail'] ?? '' ) ); ?></span>
                    </div>
                    <?php if ( $action_url !== '' && $item_status !== 'ready' ) : ?>
                        <a class="metis-btn-xs" href="<?php echo metis_escape_url( $action_url ); ?>"><?php echo metis_escape_html( (string) ( $item['action_label'] ?? 'Open' ) ); ?></a>
                    <?php endif; ?>
                </article>
            <?php endforeach; ?>
        </div>
    </section>
</div>
