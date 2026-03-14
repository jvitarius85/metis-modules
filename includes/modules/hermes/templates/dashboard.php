<?php
if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! metis_hermes_can_view() ) {
    echo '<div class="mw-alert mw-alert-error">You do not have permission to view Hermes.</div>';
    return;
}

metis_hermes_ensure_schema();
$payload = metis_hermes_dashboard_payload();
$overview = is_array( $payload['overview'] ?? null ) ? $payload['overview'] : [];
$modules = is_array( $payload['module_summaries'] ?? null ) ? $payload['module_summaries'] : [];
$alerts = is_array( $payload['alerts'] ?? null ) ? $payload['alerts'] : [];
$workers = is_array( $payload['workers'] ?? null ) ? $payload['workers'] : [ 'tasks' => [], 'registered_workers' => [], 'summary' => [] ];
$reconciliation = is_array( $payload['reconciliation'] ?? null ) ? $payload['reconciliation'] : [ 'summary' => [], 'rows' => [] ];
$permission_issues = is_array( $payload['permission_inconsistencies'] ?? null ) ? $payload['permission_inconsistencies'] : [];
$integration_failures = is_array( $payload['integration_failures'] ?? null ) ? $payload['integration_failures'] : [];
$reports = is_array( $payload['reports'] ?? null ) ? $payload['reports'] : [];
$trends = is_array( $payload['diagnostic_trends'] ?? null ) ? $payload['diagnostic_trends'] : [ 'points' => [], 'max_finding_count' => 1 ];
$capabilities = is_array( $payload['capabilities'] ?? null ) ? $payload['capabilities'] : [];
$default_module = (string) ( $modules[0]['key'] ?? '' );
$default_report = (string) ( $reports[0]['report_code'] ?? '' );

$badge_class = static function ( string $severity ): string {
    $severity = strtolower( $severity );
    return match ( $severity ) {
        'critical', 'high' => 'is-high',
        'medium' => 'is-medium',
        'low' => 'is-low',
        default => 'is-neutral',
    };
};

$status_class = static function ( string $status ): string {
    $status = strtolower( $status );
    return match ( $status ) {
        'at-risk' => 'is-high',
        'monitoring', 'running', 'lagging' => 'is-medium',
        'healthy' => 'is-low',
        'restricted', 'disabled' => 'is-neutral',
        default => 'is-neutral',
    };
};

$format_date = static function ( string $value ): string {
    if ( $value === '' ) {
        return 'Not yet recorded';
    }

    $timestamp = strtotime( $value );
    if ( $timestamp === false ) {
        return $value;
    }

    if ( function_exists( 'wp_date' ) ) {
        return wp_date( 'M j, Y g:i a', $timestamp );
    }

    return date( 'M j, Y g:i a', $timestamp );
};

$format_money = static function ( float $value ): string {
    return '$' . number_format_i18n( $value, 2 );
};

$max_trend = max( 1, (int) ( $trends['max_finding_count'] ?? 1 ) );
?>

<div class="metis-hermes-health" data-hermes-dashboard>
    <section class="metis-hermes-hero">
        <div class="metis-hermes-hero-copy">
            <span class="metis-hermes-eyebrow">Hermes System Health</span>
            <h1 class="mw-page-title">Operational health, worker status, and diagnostic drift in one bounded view.</h1>
            <p class="mw-subtitle">Hermes aggregates Diagnostic Engine findings and worker framework state into permission-aware health surfaces, without relying on dashboard AJAX or WordPress API calls.</p>
        </div>
        <div class="metis-hermes-hero-meta">
            <div class="metis-hermes-hero-chip">
                <span>View</span>
                <strong><?php echo esc_html( ! empty( $capabilities['can_manage'] ) ? 'Operator scope' : 'Observer scope' ); ?></strong>
            </div>
            <div class="metis-hermes-hero-chip">
                <span>Generated</span>
                <strong><?php echo esc_html( $format_date( (string) ( $payload['generated_at'] ?? '' ) ) ); ?></strong>
            </div>
            <div class="metis-hermes-hero-chip">
                <span>Registered workers</span>
                <strong><?php echo esc_html( number_format_i18n( count( (array) ( $workers['registered_workers'] ?? [] ) ) ) ); ?></strong>
            </div>
        </div>
    </section>

    <section class="metis-hermes-overview-grid">
        <article class="metis-hermes-stat-card">
            <span class="metis-hermes-stat-label">Module Health</span>
            <strong><?php echo esc_html( number_format_i18n( (int) ( $overview['module_count'] ?? 0 ) ) ); ?></strong>
            <span class="metis-hermes-stat-note">Hermes context packs under watch</span>
        </article>
        <article class="metis-hermes-stat-card">
            <span class="metis-hermes-stat-label">Alerts</span>
            <strong><?php echo esc_html( number_format_i18n( (int) ( $overview['high_alert_count'] ?? 0 ) ) ); ?></strong>
            <span class="metis-hermes-stat-note"><?php echo esc_html( number_format_i18n( (int) ( $overview['alert_count'] ?? 0 ) ) ); ?> active alerts total</span>
        </article>
        <article class="metis-hermes-stat-card">
            <span class="metis-hermes-stat-label">Integrations</span>
            <strong><?php echo esc_html( number_format_i18n( (int) ( $overview['integration_failure_count'] ?? 0 ) ) ); ?></strong>
            <span class="metis-hermes-stat-note">Cross-surface failures requiring attention</span>
        </article>
        <article class="metis-hermes-stat-card">
            <span class="metis-hermes-stat-label">Workers</span>
            <strong><?php echo esc_html( number_format_i18n( (int) ( $overview['worker_issue_count'] ?? 0 ) ) ); ?></strong>
            <span class="metis-hermes-stat-note">Cron or queue issues detected</span>
        </article>
        <article class="metis-hermes-stat-card">
            <span class="metis-hermes-stat-label">Reconciliation</span>
            <strong><?php echo esc_html( number_format_i18n( (int) ( $overview['reconciliation_anomaly_count'] ?? 0 ) ) ); ?></strong>
            <span class="metis-hermes-stat-note">Open anomalies across finance snapshots</span>
        </article>
        <article class="metis-hermes-stat-card">
            <span class="metis-hermes-stat-label">Permissions</span>
            <strong><?php echo esc_html( number_format_i18n( (int) ( $overview['permission_issue_count'] ?? 0 ) ) ); ?></strong>
            <span class="metis-hermes-stat-note">Manifest or access mismatches</span>
        </article>
    </section>

    <section class="metis-hermes-layout">
        <div class="metis-hermes-main-column">
            <article class="metis-hermes-panel">
                <div class="metis-hermes-panel-head">
                    <div>
                        <span class="metis-hermes-panel-kicker">Module Health Summaries</span>
                        <h2>Permission-aware operational drill-downs</h2>
                    </div>
                    <p>Each module summary reflects Hermes health state, declared diagnostic lenses, and whether the current user can drill further into the owning surface.</p>
                </div>
                <div class="metis-hermes-drilldown">
                    <nav class="metis-hermes-drilldown-nav" aria-label="Module drill-down">
                        <?php foreach ( $modules as $module ) : ?>
                            <?php
                            $module_key = (string) ( $module['key'] ?? '' );
                            $module_status = (string) ( $module['status'] ?? 'healthy' );
                            ?>
                            <button
                                type="button"
                                class="metis-hermes-nav-button <?php echo $module_key === $default_module ? 'is-active' : ''; ?>"
                                data-hermes-target="module"
                                data-hermes-id="<?php echo esc_attr( $module_key ); ?>"
                            >
                                <span><?php echo esc_html( (string) ( $module['title'] ?? $module_key ) ); ?></span>
                                <strong class="metis-hermes-status-pill <?php echo esc_attr( $status_class( $module_status ) ); ?>"><?php echo esc_html( ucwords( str_replace( '-', ' ', $module_status ) ) ); ?></strong>
                            </button>
                        <?php endforeach; ?>
                    </nav>
                    <div class="metis-hermes-drilldown-panels">
                        <?php foreach ( $modules as $module ) : ?>
                            <?php
                            $module_key = (string) ( $module['key'] ?? '' );
                            $module_status = (string) ( $module['status'] ?? 'healthy' );
                            $module_alerts = is_array( $module['alerts'] ?? null ) ? $module['alerts'] : [];
                            $module_permission_issues = is_array( $module['permission_issues'] ?? null ) ? $module['permission_issues'] : [];
                            $module_diagnostics = is_array( $module['diagnostics'] ?? null ) ? $module['diagnostics'] : [];
                            $module_issues = is_array( $module['common_operational_issues'] ?? null ) ? $module['common_operational_issues'] : [];
                            $module_actions = is_array( $module['available_actions'] ?? null ) ? $module['available_actions'] : [];
                            $live_diagnostic = is_array( $module['live_diagnostic'] ?? null ) ? $module['live_diagnostic'] : [];
                            ?>
                            <article
                                class="metis-hermes-detail-panel <?php echo $module_key === $default_module ? 'is-active' : ''; ?>"
                                data-hermes-panel="module"
                                data-hermes-id="<?php echo esc_attr( $module_key ); ?>"
                            >
                                <div class="metis-hermes-detail-head">
                                    <div>
                                        <span class="metis-hermes-panel-kicker"><?php echo esc_html( strtoupper( (string) ( $module['module_slug'] ?? $module_key ) ) ); ?></span>
                                        <h3><?php echo esc_html( (string) ( $module['title'] ?? $module_key ) ); ?></h3>
                                    </div>
                                    <span class="metis-hermes-status-pill <?php echo esc_attr( $status_class( $module_status ) ); ?>"><?php echo esc_html( ucwords( str_replace( '-', ' ', $module_status ) ) ); ?></span>
                                </div>
                                <p class="metis-hermes-detail-summary"><?php echo esc_html( (string) ( $module['summary'] ?? '' ) ); ?></p>

                                <div class="metis-hermes-detail-grid">
                                    <section class="metis-hermes-subpanel">
                                        <h4>Permission Surface</h4>
                                        <div class="metis-hermes-inline-metrics">
                                            <div>
                                                <span>Direct view</span>
                                                <strong><?php echo esc_html( ! empty( $module['can_view_module'] ) ? 'Available' : 'Restricted' ); ?></strong>
                                            </div>
                                            <div>
                                                <span>Direct edit</span>
                                                <strong><?php echo esc_html( ! empty( $module['can_edit_module'] ) ? 'Available' : 'Not granted' ); ?></strong>
                                            </div>
                                            <div>
                                                <span>Source modules</span>
                                                <strong><?php echo esc_html( implode( ', ', array_map( 'strval', (array) ( $module['source_modules'] ?? [] ) ) ) ); ?></strong>
                                            </div>
                                        </div>
                                        <?php if ( ! empty( $module_permission_issues ) ) : ?>
                                            <ul class="metis-hermes-list">
                                                <?php foreach ( $module_permission_issues as $issue ) : ?>
                                                    <li>
                                                        <span class="metis-hermes-badge <?php echo esc_attr( $badge_class( (string) ( $issue['severity'] ?? 'low' ) ) ); ?>"><?php echo esc_html( strtoupper( (string) ( $issue['severity'] ?? 'low' ) ) ); ?></span>
                                                        <strong><?php echo esc_html( (string) ( $issue['title'] ?? 'Permission issue' ) ); ?></strong>
                                                        <p><?php echo esc_html( (string) ( $issue['summary'] ?? '' ) ); ?></p>
                                                    </li>
                                                <?php endforeach; ?>
                                            </ul>
                                        <?php else : ?>
                                            <p class="metis-hermes-empty">No permission inconsistencies are open for this module.</p>
                                        <?php endif; ?>
                                    </section>

                                    <section class="metis-hermes-subpanel">
                                        <h4>Active Alerts</h4>
                                        <?php if ( ! empty( $module_alerts ) ) : ?>
                                            <ul class="metis-hermes-list">
                                                <?php foreach ( $module_alerts as $alert ) : ?>
                                                    <li>
                                                        <span class="metis-hermes-badge <?php echo esc_attr( $badge_class( (string) ( $alert['severity'] ?? 'low' ) ) ); ?>"><?php echo esc_html( strtoupper( (string) ( $alert['severity'] ?? 'low' ) ) ); ?></span>
                                                        <strong><?php echo esc_html( (string) ( $alert['title'] ?? 'Alert' ) ); ?></strong>
                                                        <p><?php echo esc_html( (string) ( $alert['summary'] ?? '' ) ); ?></p>
                                                    </li>
                                                <?php endforeach; ?>
                                            </ul>
                                        <?php else : ?>
                                            <p class="metis-hermes-empty">No active Hermes alerts are assigned to this module.</p>
                                        <?php endif; ?>

                                        <?php if ( ! empty( $live_diagnostic ) ) : ?>
                                            <div class="metis-hermes-inline-note">
                                                <strong><?php echo esc_html( (string) ( $live_diagnostic['title'] ?? 'Live diagnostic' ) ); ?></strong>
                                                <p><?php echo esc_html( (string) ( $live_diagnostic['summary'] ?? '' ) ); ?></p>
                                            </div>
                                        <?php endif; ?>
                                    </section>
                                </div>

                                <div class="metis-hermes-detail-grid">
                                    <section class="metis-hermes-subpanel">
                                        <h4>Diagnostic Lenses</h4>
                                        <?php if ( ! empty( $module_diagnostics ) ) : ?>
                                            <ul class="metis-hermes-list">
                                                <?php foreach ( $module_diagnostics as $diagnostic ) : ?>
                                                    <li>
                                                        <span class="metis-hermes-badge <?php echo esc_attr( $badge_class( (string) ( $diagnostic['severity'] ?? 'low' ) ) ); ?>"><?php echo esc_html( strtoupper( (string) ( $diagnostic['severity'] ?? 'low' ) ) ); ?></span>
                                                        <strong><?php echo esc_html( (string) ( $diagnostic['purpose'] ?? $diagnostic['key'] ?? 'Diagnostic' ) ); ?></strong>
                                                        <p><?php echo esc_html( implode( ', ', array_map( 'strval', (array) ( $diagnostic['evidence'] ?? [] ) ) ) ); ?></p>
                                                    </li>
                                                <?php endforeach; ?>
                                            </ul>
                                        <?php else : ?>
                                            <p class="metis-hermes-empty">No diagnostic lenses are registered for this module.</p>
                                        <?php endif; ?>
                                    </section>

                                    <section class="metis-hermes-subpanel">
                                        <h4>Common Failure Modes</h4>
                                        <?php if ( ! empty( $module_issues ) ) : ?>
                                            <ul class="metis-hermes-list">
                                                <?php foreach ( $module_issues as $issue ) : ?>
                                                    <li>
                                                        <strong><?php echo esc_html( (string) ( $issue['issue'] ?? 'Operational issue' ) ); ?></strong>
                                                        <p><?php echo esc_html( implode( ', ', array_map( 'strval', (array) ( $issue['likely_causes'] ?? [] ) ) ) ); ?></p>
                                                    </li>
                                                <?php endforeach; ?>
                                            </ul>
                                        <?php else : ?>
                                            <p class="metis-hermes-empty">No failure modes are documented for this module.</p>
                                        <?php endif; ?>
                                    </section>

                                    <section class="metis-hermes-subpanel">
                                        <h4>Drill-down Actions</h4>
                                        <?php if ( ! empty( $module_actions ) ) : ?>
                                            <ul class="metis-hermes-list">
                                                <?php foreach ( $module_actions as $action ) : ?>
                                                    <li>
                                                        <strong><?php echo esc_html( (string) ( $action['key'] ?? 'Action' ) ); ?></strong>
                                                        <p><?php echo esc_html( (string) ( $action['description'] ?? '' ) ); ?></p>
                                                    </li>
                                                <?php endforeach; ?>
                                            </ul>
                                        <?php else : ?>
                                            <p class="metis-hermes-empty">No bounded Hermes actions are declared for this module.</p>
                                        <?php endif; ?>
                                    </section>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                </div>
            </article>

            <article class="metis-hermes-panel">
                <div class="metis-hermes-panel-head">
                    <div>
                        <span class="metis-hermes-panel-kicker">Diagnostic Reports</span>
                        <h2>Recent Hermes report outputs</h2>
                    </div>
                    <p>Reports are local drill-down artifacts. Selecting one reveals its evidence and severity mix without re-querying the server.</p>
                </div>
                <div class="metis-hermes-drilldown">
                    <nav class="metis-hermes-drilldown-nav is-compact" aria-label="Report drill-down">
                        <?php foreach ( $reports as $report ) : ?>
                            <?php $report_code = (string) ( $report['report_code'] ?? '' ); ?>
                            <button
                                type="button"
                                class="metis-hermes-nav-button <?php echo $report_code === $default_report ? 'is-active' : ''; ?>"
                                data-hermes-target="report"
                                data-hermes-id="<?php echo esc_attr( $report_code ); ?>"
                            >
                                <span><?php echo esc_html( strtoupper( (string) ( $report['report_type'] ?? 'report' ) ) ); ?></span>
                                <small><?php echo esc_html( (string) ( $report['subject_key'] ?? 'system' ) ); ?></small>
                            </button>
                        <?php endforeach; ?>
                    </nav>
                    <div class="metis-hermes-drilldown-panels">
                        <?php foreach ( $reports as $report ) : ?>
                            <?php
                            $report_code = (string) ( $report['report_code'] ?? '' );
                            $report_summary = is_array( $report['summary'] ?? null ) ? $report['summary'] : [];
                            $report_findings = is_array( $report_summary['findings'] ?? null ) ? $report_summary['findings'] : [];
                            $report_meta = is_array( $report_summary['summary'] ?? null ) ? $report_summary['summary'] : [];
                            ?>
                            <article
                                class="metis-hermes-detail-panel <?php echo $report_code === $default_report ? 'is-active' : ''; ?>"
                                data-hermes-panel="report"
                                data-hermes-id="<?php echo esc_attr( $report_code ); ?>"
                            >
                                <div class="metis-hermes-detail-head">
                                    <div>
                                        <span class="metis-hermes-panel-kicker"><?php echo esc_html( strtoupper( (string) ( $report['report_type'] ?? 'report' ) ) ); ?></span>
                                        <h3><?php echo esc_html( (string) ( $report['subject_key'] ?? $report_code ) ); ?></h3>
                                    </div>
                                    <span class="metis-hermes-status-pill <?php echo esc_attr( $status_class( ! empty( $report_findings ) ? 'monitoring' : 'healthy' ) ); ?>"><?php echo esc_html( (string) ( $report['status'] ?? 'ready' ) ); ?></span>
                                </div>
                                <div class="metis-hermes-inline-metrics">
                                    <div>
                                        <span>Updated</span>
                                        <strong><?php echo esc_html( $format_date( (string) ( $report['updated_at'] ?? '' ) ) ); ?></strong>
                                    </div>
                                    <div>
                                        <span>Findings</span>
                                        <strong><?php echo esc_html( number_format_i18n( (int) ( $report_meta['finding_count'] ?? count( $report_findings ) ) ) ); ?></strong>
                                    </div>
                                    <div>
                                        <span>High severity</span>
                                        <strong><?php echo esc_html( number_format_i18n( (int) ( $report_meta['high_severity'] ?? 0 ) ) ); ?></strong>
                                    </div>
                                </div>

                                <?php if ( ! empty( $report_findings ) ) : ?>
                                    <ul class="metis-hermes-list">
                                        <?php foreach ( $report_findings as $finding ) : ?>
                                            <li>
                                                <span class="metis-hermes-badge <?php echo esc_attr( $badge_class( (string) ( $finding['severity'] ?? 'low' ) ) ); ?>"><?php echo esc_html( strtoupper( (string) ( $finding['severity'] ?? 'low' ) ) ); ?></span>
                                                <strong><?php echo esc_html( (string) ( $finding['title'] ?? $finding['key'] ?? 'Finding' ) ); ?></strong>
                                                <p><?php echo esc_html( (string) ( $finding['summary'] ?? '' ) ); ?></p>
                                            </li>
                                        <?php endforeach; ?>
                                    </ul>
                                <?php else : ?>
                                    <p class="metis-hermes-empty">This report does not include finding rows. Hermes stored the output as a lightweight summary.</p>
                                <?php endif; ?>
                            </article>
                        <?php endforeach; ?>
                    </div>
                </div>
            </article>
        </div>

        <aside class="metis-hermes-side-column">
            <article class="metis-hermes-panel">
                <div class="metis-hermes-panel-head">
                    <div>
                        <span class="metis-hermes-panel-kicker">Alerts</span>
                        <h2>Priority watchlist</h2>
                    </div>
                </div>
                <?php if ( ! empty( $alerts ) ) : ?>
                    <ul class="metis-hermes-list">
                        <?php foreach ( $alerts as $alert ) : ?>
                            <li>
                                <span class="metis-hermes-badge <?php echo esc_attr( $badge_class( (string) ( $alert['severity'] ?? 'low' ) ) ); ?>"><?php echo esc_html( strtoupper( (string) ( $alert['severity'] ?? 'low' ) ) ); ?></span>
                                <strong><?php echo esc_html( (string) ( $alert['title'] ?? 'Alert' ) ); ?></strong>
                                <p><?php echo esc_html( (string) ( $alert['summary'] ?? '' ) ); ?></p>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php else : ?>
                    <p class="metis-hermes-empty">Hermes has no active alerts to surface right now.</p>
                <?php endif; ?>
            </article>

            <article class="metis-hermes-panel">
                <div class="metis-hermes-panel-head">
                    <div>
                        <span class="metis-hermes-panel-kicker">Integration Failures</span>
                        <h2>Cross-surface breakpoints</h2>
                    </div>
                </div>
                <?php if ( ! empty( $integration_failures ) ) : ?>
                    <ul class="metis-hermes-list">
                        <?php foreach ( $integration_failures as $failure ) : ?>
                            <li>
                                <span class="metis-hermes-badge <?php echo esc_attr( $badge_class( (string) ( $failure['severity'] ?? 'low' ) ) ); ?>"><?php echo esc_html( strtoupper( (string) ( $failure['severity'] ?? 'low' ) ) ); ?></span>
                                <strong><?php echo esc_html( (string) ( $failure['title'] ?? 'Failure' ) ); ?></strong>
                                <p><?php echo esc_html( (string) ( $failure['summary'] ?? '' ) ); ?></p>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php else : ?>
                    <p class="metis-hermes-empty">No cross-module integration failures are active in the current snapshot.</p>
                <?php endif; ?>
            </article>

            <article class="metis-hermes-panel">
                <div class="metis-hermes-panel-head">
                    <div>
                        <span class="metis-hermes-panel-kicker">Workers</span>
                        <h2>Cron and queue status</h2>
                    </div>
                </div>
                <div class="metis-hermes-inline-metrics">
                    <div>
                        <span>Failed tasks</span>
                        <strong><?php echo esc_html( number_format_i18n( (int) ( $workers['summary']['failed_count'] ?? 0 ) ) ); ?></strong>
                    </div>
                    <div>
                        <span>Lagging tasks</span>
                        <strong><?php echo esc_html( number_format_i18n( (int) ( $workers['summary']['lagging_count'] ?? 0 ) ) ); ?></strong>
                    </div>
                    <div>
                        <span>Queue failures</span>
                        <strong><?php echo esc_html( number_format_i18n( (int) ( $payload['queue']['failed_count'] ?? 0 ) ) ); ?></strong>
                    </div>
                </div>
                <?php if ( ! empty( $workers['tasks'] ) ) : ?>
                    <ul class="metis-hermes-list">
                        <?php foreach ( $workers['tasks'] as $task ) : ?>
                            <li>
                                <span class="metis-hermes-badge <?php echo esc_attr( $status_class( (string) ( $task['health'] ?? 'healthy' ) ) ); ?>"><?php echo esc_html( strtoupper( (string) ( $task['health'] ?? 'healthy' ) ) ); ?></span>
                                <strong><?php echo esc_html( (string) ( $task['label'] ?? 'Worker' ) ); ?></strong>
                                <p><?php echo esc_html( (string) ( $task['last_error'] ?? '' ) !== '' ? (string) $task['last_error'] : 'Last finished: ' . $format_date( (string) ( $task['last_finished_at'] ?? '' ) ) ); ?></p>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php else : ?>
                    <p class="metis-hermes-empty">No registered cron tasks were available.</p>
                <?php endif; ?>
            </article>

            <article class="metis-hermes-panel">
                <div class="metis-hermes-panel-head">
                    <div>
                        <span class="metis-hermes-panel-kicker">Reconciliation</span>
                        <h2>Anomaly watch</h2>
                    </div>
                </div>
                <div class="metis-hermes-inline-metrics">
                    <div>
                        <span>Open</span>
                        <strong><?php echo esc_html( number_format_i18n( (int) ( $reconciliation['summary']['open_count'] ?? 0 ) ) ); ?></strong>
                    </div>
                    <div>
                        <span>Variance rows</span>
                        <strong><?php echo esc_html( number_format_i18n( (int) ( $reconciliation['summary']['variance_count'] ?? 0 ) ) ); ?></strong>
                    </div>
                    <div>
                        <span>Total anomalies</span>
                        <strong><?php echo esc_html( number_format_i18n( (int) ( $reconciliation['summary']['anomaly_count'] ?? 0 ) ) ); ?></strong>
                    </div>
                </div>
                <?php if ( ! empty( $reconciliation['rows'] ) ) : ?>
                    <ul class="metis-hermes-list">
                        <?php foreach ( $reconciliation['rows'] as $row ) : ?>
                            <li>
                                <strong><?php echo esc_html( strtoupper( (string) ( $row['account_key'] ?? 'account' ) ) ); ?></strong>
                                <p><?php echo esc_html( (string) ( $row['status'] ?? 'pending' ) . ' for ' . (string) ( $row['period_end'] ?? '' ) ); ?></p>
                                <p><?php echo esc_html( 'Variance ' . $format_money( (float) ( $row['variance'] ?? 0 ) ) ); ?></p>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php else : ?>
                    <p class="metis-hermes-empty">No reconciliation anomalies are in the current snapshot.</p>
                <?php endif; ?>
            </article>

            <article class="metis-hermes-panel">
                <div class="metis-hermes-panel-head">
                    <div>
                        <span class="metis-hermes-panel-kicker">Permissions</span>
                        <h2>Consistency review</h2>
                    </div>
                </div>
                <?php if ( ! empty( $permission_issues ) ) : ?>
                    <ul class="metis-hermes-list">
                        <?php foreach ( $permission_issues as $issue ) : ?>
                            <li>
                                <span class="metis-hermes-badge <?php echo esc_attr( $badge_class( (string) ( $issue['severity'] ?? 'low' ) ) ); ?>"><?php echo esc_html( strtoupper( (string) ( $issue['severity'] ?? 'low' ) ) ); ?></span>
                                <strong><?php echo esc_html( (string) ( $issue['title'] ?? 'Permission inconsistency' ) ); ?></strong>
                                <p><?php echo esc_html( (string) ( $issue['summary'] ?? '' ) ); ?></p>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php else : ?>
                    <p class="metis-hermes-empty">Hermes did not detect permission-map inconsistencies in this snapshot.</p>
                <?php endif; ?>
            </article>

            <article class="metis-hermes-panel">
                <div class="metis-hermes-panel-head">
                    <div>
                        <span class="metis-hermes-panel-kicker">Diagnostic Trends</span>
                        <h2>Recent trajectory</h2>
                    </div>
                </div>
                <?php if ( ! empty( $trends['points'] ) ) : ?>
                    <div class="metis-hermes-trend-list">
                        <?php foreach ( $trends['points'] as $point ) : ?>
                            <?php $bar_width = min( 100, (int) round( ( (int) ( $point['finding_count'] ?? 0 ) / $max_trend ) * 100 ) ); ?>
                            <div class="metis-hermes-trend-row">
                                <div class="metis-hermes-trend-meta">
                                    <strong><?php echo esc_html( strtoupper( (string) ( $point['report_type'] ?? 'report' ) ) ); ?></strong>
                                    <span><?php echo esc_html( $format_date( (string) ( $point['label'] ?? '' ) ) ); ?></span>
                                </div>
                                <div class="metis-hermes-trend-bar">
                                    <span style="width: <?php echo esc_attr( (string) $bar_width ); ?>%;"></span>
                                </div>
                                <div class="metis-hermes-trend-counts">
                                    <strong><?php echo esc_html( number_format_i18n( (int) ( $point['finding_count'] ?? 0 ) ) ); ?></strong>
                                    <span><?php echo esc_html( number_format_i18n( (int) ( $point['high_severity'] ?? 0 ) ) ); ?> high</span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else : ?>
                    <p class="metis-hermes-empty">Hermes does not have enough recent reports to chart a trend yet.</p>
                <?php endif; ?>
            </article>
        </aside>
    </section>
</div>
