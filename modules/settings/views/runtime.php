<?php
if ( ! defined( 'METIS_ROOT' ) ) exit;
require_once __DIR__ . '/_settings_bootstrap.php';
$ctx = metis_settings_bootstrap( 'runtime' );
if ( empty( $ctx['allowed'] ) ) return;
extract( $ctx, EXTR_SKIP );
$logging_fetch_action_nonce = function_exists( 'metis_runtime_create_nonce' ) && function_exists( 'metis_ajax_nonce_action' )
    ? (string) metis_runtime_create_nonce( metis_ajax_nonce_action( 'metis_settings_fetch_logging_viewer' ) )
    : '';
$logging_clear_action_nonce = function_exists( 'metis_runtime_create_nonce' ) && function_exists( 'metis_ajax_nonce_action' )
    ? (string) metis_runtime_create_nonce( metis_ajax_nonce_action( 'metis_settings_clear_log' ) )
    : '';
$render_context_value = static function ( mixed $value ): string {
    if ( is_bool( $value ) ) {
        return $value ? 'true' : 'false';
    }
    if ( is_null( $value ) ) {
        return 'null';
    }
    if ( is_scalar( $value ) ) {
        return (string) $value;
    }
    $encoded = metis_json_encode( $value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
    return is_string( $encoded ) ? $encoded : '';
};
$format_log_message = static function ( string $message ): array {
    $message = trim( $message );
    if ( $message === '' ) {
        return [ 'short' => '', 'full' => '', 'truncated' => false ];
    }
    $single_line = preg_replace( '/\s+/', ' ', $message );
    if ( ! is_string( $single_line ) ) {
        $single_line = $message;
    }
    $limit = 180;
    if ( function_exists( 'mb_strlen' ) && function_exists( 'mb_substr' ) ) {
        $truncated = mb_strlen( $single_line ) > $limit;
        $short = $truncated ? ( mb_substr( $single_line, 0, $limit ) . '…' ) : $single_line;
    } else {
        $truncated = strlen( $single_line ) > $limit;
        $short = $truncated ? ( substr( $single_line, 0, $limit ) . '…' ) : $single_line;
    }
    return [ 'short' => $short, 'full' => $single_line, 'truncated' => $truncated ];
};
$summarize_log_row_message = static function ( string $level, string $message, array $context = [] ): string {
    $text = trim( preg_replace( '/\s+/', ' ', $message ) ?? $message );
    $text = preg_replace( '/^\[trace:[^\]]+\]\s*/i', '', $text ) ?? $text;
    $line = '';
    if ( preg_match( '/\bline\s+(\d+)\b/i', $text, $m ) ) {
        $line = (string) $m[1];
    }

    if ( preg_match( '/Call to undefined method\s+([A-Za-z0-9_\\\\]+::[A-Za-z0-9_]+)\s*\(/i', $text, $m ) ) {
        return 'Fatal error: undefined method ' . (string) $m[1] . ( $line !== '' ? ( ' on line ' . $line . '.' ) : '.' );
    }
    if ( preg_match( '/Class\s+["\']([^"\']+)["\']\s+not found/i', $text, $m ) ) {
        return 'Fatal error: missing class ' . (string) $m[1] . ( $line !== '' ? ( ' on line ' . $line . '.' ) : '.' );
    }
    if ( preg_match( '/Call to undefined function\s+([A-Za-z0-9_\\\\]+)\s*\(/i', $text, $m ) ) {
        return 'Fatal error: undefined function ' . (string) $m[1] . ( $line !== '' ? ( ' on line ' . $line . '.' ) : '.' );
    }
    if ( preg_match( '/SECURITY\s+router\.reject.*code="?invalid_nonce"?/i', $text ) ) {
        $action = trim( (string) ( $context['ajax_action'] ?? '' ) );
        if ( $action === '' ) {
            $action = trim( (string) ( $context['action'] ?? '' ) );
        }
        $path = trim( (string) ( $context['path'] ?? '' ) );
        if ( $action !== '' && $path !== '' ) {
            return 'Security warning: request rejected due to invalid nonce initiated by ' . $action . ' at ' . $path . '.';
        }
        if ( $action !== '' ) {
            return 'Security warning: request rejected due to invalid nonce initiated by ' . $action . '.';
        }
        if ( $path !== '' ) {
            return 'Security warning: request rejected due to invalid nonce at ' . $path . '.';
        }
        return 'Security warning: request rejected due to invalid nonce.';
    }
    if ( preg_match( '/timeout|timed out|maximum execution time|lock wait timeout/i', $text ) ) {
        $service = trim( (string) ( $context['service'] ?? '' ) );
        $module = trim( (string) ( $context['module'] ?? '' ) );
        $target = $service !== '' ? $service : ( $module !== '' ? $module : 'runtime operation' );
        return 'Timeout warning: operation exceeded the allowed time in ' . $target . '.';
    }
    if ( preg_match( '/sqlstate|pdoexception|mysql|database error|query failed|deadlock|duplicate entry|unknown column|cannot add or update a child row/i', $text ) ) {
        $module = trim( (string) ( $context['module'] ?? '' ) );
        $service = trim( (string) ( $context['service'] ?? '' ) );
        $target = $service !== '' ? $service : ( $module !== '' ? $module : 'application data layer' );
        return 'Database error: query or write operation failed in ' . $target . '.';
    }
    if ( preg_match( '/permission denied|access denied|unauthorized|forbidden|not allowed|insufficient privileges|read-only file system/i', $text ) ) {
        $action = trim( (string) ( $context['ajax_action'] ?? '' ) );
        if ( $action === '' ) {
            $action = trim( (string) ( $context['action'] ?? '' ) );
        }
        $path = trim( (string) ( $context['path'] ?? '' ) );
        if ( $action !== '' && $path !== '' ) {
            return 'Permission warning: blocked while running ' . $action . ' at ' . $path . '.';
        }
        if ( $action !== '' ) {
            return 'Permission warning: blocked while running ' . $action . '.';
        }
        if ( $path !== '' ) {
            return 'Permission warning: blocked while accessing ' . $path . '.';
        }
        return 'Permission warning: operation blocked by access controls.';
    }
    if ( preg_match( '/could not resolve host|name or service not known|getaddrinfo|dns/i', $text ) ) {
        $service = trim( (string) ( $context['service'] ?? '' ) );
        $target = $service !== '' ? $service : 'external API';
        return 'Network error: DNS lookup failed while contacting ' . $target . '.';
    }
    if ( preg_match( '/connection refused|failed to connect|connection reset|connection timed out|econnrefused|econnreset|etimedout/i', $text ) ) {
        $service = trim( (string) ( $context['service'] ?? '' ) );
        $target = $service !== '' ? $service : 'external API';
        return 'Network error: connection to ' . $target . ' failed.';
    }
    if ( preg_match( '/ssl|tls|certificate|cert verify|x509/i', $text ) ) {
        $service = trim( (string) ( $context['service'] ?? '' ) );
        $target = $service !== '' ? $service : 'external API';
        return 'Network security error: TLS/certificate validation failed for ' . $target . '.';
    }
    if ( preg_match( '/\b5\d\d\b|bad gateway|gateway timeout|service unavailable|upstream/i', $text ) ) {
        $service = trim( (string) ( $context['service'] ?? '' ) );
        $target = $service !== '' ? $service : 'upstream service';
        return 'API error: ' . $target . ' returned a server error.';
    }
    if ( preg_match( '/\b429\b|rate limit|too many requests/i', $text ) ) {
        $service = trim( (string) ( $context['service'] ?? '' ) );
        $target = $service !== '' ? $service : 'external API';
        return 'API warning: rate limit reached for ' . $target . '.';
    }
    if ( preg_match( '/^error\.trace\b/i', $text ) ) {
        return 'Error trace event recorded for this request.';
    }

    $level = strtoupper( $level );
    if ( $level === 'ERROR' ) {
        return 'Application error detected.';
    }
    if ( $level === 'WARN' ) {
        return 'Application warning detected.';
    }
    return 'Log event recorded.';
};
?>
<h1 class="mw-page-title"><?php echo metis_escape_html( metis_current_module_view_title( 'Settings' ) ); ?></h1>
<p class="mw-subtitle">Manage runtime logging, cache controls, and log viewer access.</p>
<?php metis_settings_render_messages( $saved, $errors ); ?>
<?php metis_settings_render_section_nav( 'runtime' ); ?>
<form method="post" class="mw-settings-form" data-metis-settings-form="1" data-settings-section="runtime">
    <?php metis_runtime_nonce_field( 'metis_save_settings_runtime', 'metis_settings_nonce' ); ?>
    <input type="hidden" id="logging_page" name="logging_page" value="<?php echo metis_escape_attr( (string) ( $logging_page ?? 1 ) ); ?>">
    <div class="mw-settings-card">
        <div class="mw-settings-header"><h2>Logging</h2></div>
        <div class="mw-settings-body">
            <div class="mw-field">
                <label style="display:flex;align-items:center;gap:8px;font-weight:500;">
                    <input type="checkbox" id="logging_enabled" name="logging_enabled" value="1" <?php metis_attr_checked( $logging_enabled, true ); ?>>
                    Enable runtime logging
                </label>
            </div>
            <div class="mw-field">
                <label for="logging_min_level">Minimum log level</label>
                <select id="logging_min_level" name="logging_min_level" class="mw-input mw-input-wide">
                    <option value="INFO" <?php metis_attr_selected( $logging_min_level, 'INFO' ); ?>>Info</option>
                    <option value="WARN" <?php metis_attr_selected( $logging_min_level, 'WARN' ); ?>>Warning</option>
                    <option value="ERROR" <?php metis_attr_selected( $logging_min_level, 'ERROR' ); ?>>Error</option>
                </select>
            </div>
            <div class="mw-field">
                <label for="logging_force_url_token">Force logging URL token</label>
                <input type="text" id="logging_force_url_token" name="logging_force_url_token" class="mw-input mw-input-wide" value="<?php echo metis_escape_attr( $logging_force_url_token ); ?>" placeholder="debug-logging-token">
            </div>
        </div>
    </div>

    <div class="mw-settings-card">
        <div class="mw-settings-header"><h2>Cache</h2></div>
        <div class="mw-settings-body">
            <div class="mw-settings-actions">
                <button type="button" class="mw-btn" data-cache-action="clear_all">Clear All Cache</button>
                <button type="button" class="mw-btn" data-cache-action="clear_group" data-cache-group="modules">Clear Module Cache</button>
                <button type="button" class="mw-btn" data-cache-action="clear_group" data-cache-group="permissions">Clear Permission Cache</button>
                <button type="button" class="mw-btn" data-cache-action="rebuild">Rebuild System Cache</button>
            </div>
            <p class="mw-help" data-cache-status>Ready.</p>
        </div>
    </div>

    <div class="mw-settings-card">
        <div class="mw-settings-header"><h2>Log Viewer</h2></div>
        <div class="mw-settings-body">
            <div class="mw-field metis-runtime-log-toolbar">
                <button type="button" id="logging_refresh_btn" class="mw-btn mw-btn-ghost">Refresh</button>
                <button type="button" id="logging_clear_btn" class="mw-btn mw-btn-ghost">Clear Log</button>
                <label class="metis-runtime-log-live-label">
                    <input type="checkbox" id="logging_auto_refresh" name="logging_auto_refresh" value="1" <?php metis_attr_checked( ! empty( $logging_auto_refresh ), true ); ?>>
                    Live
                </label>
                <select id="logging_auto_refresh_seconds" name="logging_auto_refresh_seconds" class="mw-input metis-runtime-log-interval">
                    <?php foreach ( [ 5, 10, 15, 30, 60 ] as $refresh_seconds ) : ?>
                        <option value="<?php echo metis_escape_attr( (string) $refresh_seconds ); ?>" <?php metis_attr_selected( (int) ( $logging_auto_refresh_seconds ?? 10 ), $refresh_seconds ); ?>><?php echo metis_escape_html( (string) $refresh_seconds ); ?>s</option>
                    <?php endforeach; ?>
                </select>
            </div>
            <details class="mw-field" style="margin-bottom:8px;">
                <summary><strong>Show Filters</strong></summary>
                <div style="display:flex;flex-direction:row;gap:10px;align-items:center;flex-wrap:nowrap;overflow-x:auto;white-space:nowrap;margin-top:8px;padding-bottom:2px;">
                    <label for="logging_view_file" style="font-weight:600;margin:0;">Log File</label>
                    <select id="logging_view_file" name="logging_view_file" class="mw-input" style="min-width:250px;">
                        <option value="">Current Active Log</option>
                        <?php foreach ( (array) $logging_available_logs as $log_file_row ) : ?>
                            <?php $value = (string) ( $log_file_row['value'] ?? '' ); ?>
                            <option value="<?php echo metis_escape_attr( $value ); ?>" <?php metis_attr_selected( (string) $logging_view_file, $value ); ?>>
                                <?php echo metis_escape_html( (string) ( $log_file_row['label'] ?? $value ) ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <label for="logging_view_lines" style="font-weight:600;margin:0;">Lines</label>
                    <select id="logging_view_lines" name="logging_view_lines" class="mw-input" style="min-width:130px;width:130px;">
                        <?php foreach ( [ 100, 200, 500, 1000 ] as $line_count ) : ?>
                            <option value="<?php echo metis_escape_attr( (string) $line_count ); ?>" <?php metis_attr_selected( (int) $logging_view_lines, $line_count ); ?>><?php echo metis_escape_html( (string) $line_count ); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <label for="logging_search" style="font-weight:600;margin:0;">Search</label>
                    <input type="text" id="logging_search" name="logging_search" class="mw-input" style="min-width:240px;" value="<?php echo metis_escape_attr( (string) ( $logging_search ?? '' ) ); ?>" placeholder="message, level, context...">
                </div>
            </details>
            <div class="mw-help" id="logging_entries_loaded" style="margin-bottom:10px;">Loaded <?php echo metis_escape_html( number_format( (int) ( $logging_total_entries ?? count( (array) $logging_entries ) ) ) ); ?> entries.</div>
            <div class="mw-help" id="logging_viewer_error" style="display:none;margin-bottom:10px;color:#b42318;"></div>
            <div style="border:1px solid #d0d7de;border-radius:12px;overflow:auto;max-height:620px;background:#fff;">
                <div class="mw-help" style="padding:8px 12px;border-bottom:1px solid #eef2f7;background:#f8fafc;">
                    Click <strong>View context</strong> on any row to inspect structured payload details.
                </div>
                <table style="width:100%;border-collapse:collapse;font-size:13px;line-height:1.45;">
                    <thead style="position:sticky;top:0;background:#f8fafc;z-index:2;">
                        <tr>
                            <th style="text-align:left;padding:10px 12px;border-bottom:1px solid #e5e7eb;">Time</th>
                            <th style="text-align:left;padding:10px 12px;border-bottom:1px solid #e5e7eb;">Level</th>
                            <th style="text-align:left;padding:10px 12px;border-bottom:1px solid #e5e7eb;">Message</th>
                        </tr>
                    </thead>
                    <tbody id="logging_viewer_body">
                        <?php if ( empty( $logging_entries ) ) : ?>
                            <tr>
                                <td colspan="3" style="padding:16px;color:#6b7280;">No log entries found.</td>
                            </tr>
                        <?php else : ?>
                            <?php foreach ( $logging_entries as $entry ) : ?>
                                <?php
                                $level = strtoupper( (string) ( $entry['level'] ?? 'INFO' ) );
                                $badge_bg = '#e5e7eb';
                                $badge_fg = '#1f2937';
                                if ( $level === 'ERROR' ) {
                                    $badge_bg = '#fee2e2';
                                    $badge_fg = '#991b1b';
                                } elseif ( $level === 'WARN' ) {
                                    $badge_bg = '#fef3c7';
                                    $badge_fg = '#92400e';
                                } elseif ( $level === 'DEBUG' ) {
                                    $badge_bg = '#dbeafe';
                                    $badge_fg = '#1e40af';
                                }
                                $context = is_array( $entry['context'] ?? null ) ? $entry['context'] : null;
                                $raw_message = (string) ( $entry['message'] ?? '' );
                                $message_parts = $format_log_message( $raw_message );
                                $message_summary = $summarize_log_row_message( $level, $raw_message, $context ?? [] );
                                ?>
                                <tr>
                                    <td style="padding:10px 12px;border-bottom:1px solid #f1f5f9;white-space:nowrap;vertical-align:top;"><?php echo metis_escape_html( (string) ( $entry['timestamp_display'] ?? $entry['timestamp'] ?? '' ) ); ?></td>
                                    <td style="padding:10px 12px;border-bottom:1px solid #f1f5f9;vertical-align:top;">
                                        <span style="display:inline-block;padding:2px 8px;border-radius:999px;background:<?php echo metis_escape_attr( $badge_bg ); ?>;color:<?php echo metis_escape_attr( $badge_fg ); ?>;font-weight:600;"><?php echo metis_escape_html( $level ); ?></span>
                                    </td>
                                    <td style="padding:10px 12px;border-bottom:1px solid #f1f5f9;vertical-align:top;">
                                        <span><?php echo metis_escape_html( $message_summary ); ?></span>
                                        <?php if ( (string) ( $message_parts['full'] ?? '' ) !== '' ) : ?>
                                            <details style="margin-top:6px;">
                                                <summary style="cursor:pointer;color:#334155;display:inline;font-weight:600;">Show raw log message</summary>
                                                <pre style="margin-top:8px;padding:10px;background:#f8fafc;border:1px solid #e5e7eb;border-radius:8px;white-space:pre-wrap;"><?php echo metis_escape_html( (string) ( $message_parts['full'] ?? '' ) ); ?></pre>
                                            </details>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <div style="display:flex;justify-content:space-between;align-items:center;margin-top:10px;gap:10px;flex-wrap:wrap;">
                <div class="mw-help">
                    <span id="logging_page_info">Page <?php echo metis_escape_html( (string) ( $logging_page ?? 1 ) ); ?> of <?php echo metis_escape_html( (string) ( $logging_total_pages ?? 1 ) ); ?></span>
                </div>
                <div style="display:flex;gap:8px;align-items:center;">
                    <button type="button" id="logging_prev_btn" class="mw-btn mw-btn-ghost" <?php echo (int) ( $logging_page ?? 1 ) <= 1 ? 'disabled' : ''; ?>>Previous</button>
                    <button type="button" id="logging_next_btn" class="mw-btn mw-btn-ghost" <?php echo (int) ( $logging_page ?? 1 ) >= (int) ( $logging_total_pages ?? 1 ) ? 'disabled' : ''; ?>>Next</button>
                    <label for="logging_page_jump" style="font-weight:600;margin:0;">Go to</label>
                    <input type="number" id="logging_page_jump" name="logging_page_jump" min="1" max="<?php echo metis_escape_attr( (string) max( 1, (int) ( $logging_total_pages ?? 1 ) ) ); ?>" value="<?php echo metis_escape_attr( (string) ( $logging_page ?? 1 ) ); ?>" class="mw-input" style="width:90px;">
                    <button type="button" id="logging_jump_btn" class="mw-btn mw-btn-ghost">Go</button>
                </div>
            </div>
        </div>
    </div>

    <div class="mw-settings-actions">
        <button type="submit" class="mw-btn">Save Runtime Settings</button>
    </div>
</form>
<script>
(function () {
    var els = {
        page: document.getElementById('logging_page'),
        viewFile: document.getElementById('logging_view_file'),
        viewLines: document.getElementById('logging_view_lines'),
        search: document.getElementById('logging_search'),
        refresh: document.getElementById('logging_refresh_btn'),
        clear: document.getElementById('logging_clear_btn'),
        live: document.getElementById('logging_auto_refresh'),
        liveSeconds: document.getElementById('logging_auto_refresh_seconds'),
        tbody: document.getElementById('logging_viewer_body'),
        loaded: document.getElementById('logging_entries_loaded'),
        error: document.getElementById('logging_viewer_error'),
        pageInfo: document.getElementById('logging_page_info'),
        prev: document.getElementById('logging_prev_btn'),
        next: document.getElementById('logging_next_btn'),
        jumpInput: document.getElementById('logging_page_jump'),
        jumpBtn: document.getElementById('logging_jump_btn')
    };
    if (!els.tbody) return;

    var currentPage = parseInt('<?php echo metis_escape_js( (string) ( $logging_page ?? 1 ) ); ?>', 10) || 1;
    var totalPages = parseInt((els.jumpInput && els.jumpInput.max) || '1', 10) || 1;
    var searchTimer = null;
    var liveTimer = null;
    var loading = false;
    var serverAjaxUrl = '<?php echo metis_escape_js( (string) metis_ajax_endpoint_url() ); ?>';
    var serverNonce = '<?php echo metis_escape_js( (string) metis_runtime_create_nonce( 'metis_core' ) ); ?>';
    var viewerAjax = (function() {
        var globalAjax = (window.metisAjax && typeof window.metisAjax === 'object') ? window.metisAjax : {};
        var coreAjax = (window.Metis && Metis.ajax && typeof Metis.ajax === 'object') ? Metis.ajax : {};
        var ajaxUrl = String(
            globalAjax.ajax_url
            || globalAjax.url
            || coreAjax.ajax_url
            || coreAjax.url
            || serverAjaxUrl
            || (typeof metisResolveAjaxUrl === 'function' ? metisResolveAjaxUrl() : '')
            || ''
        ).trim();
        var nonce = String(
            globalAjax.nonce
            || coreAjax.nonce
            || serverNonce
            || ''
        ).trim();

        return {
            ajax_url: ajaxUrl,
            url: ajaxUrl,
            nonce: nonce,
            action_nonces: Object.assign({}, globalAjax.action_nonces || {}, coreAjax.action_nonces || {}, {
                metis_settings_fetch_logging_viewer: '<?php echo metis_escape_js( $logging_fetch_action_nonce ); ?>',
                metis_settings_clear_log: '<?php echo metis_escape_js( $logging_clear_action_nonce ); ?>'
            })
        };
    })();

    var esc = function(value) {
        return String(value == null ? '' : value).replace(/[&<>"']/g, function(ch) {
            return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' })[ch];
        });
    };

    var levelBadge = function(level) {
        var l = String(level || 'INFO').toUpperCase();
        var bg = '#e5e7eb';
        var fg = '#1f2937';
        if (l === 'ERROR') { bg = '#fee2e2'; fg = '#991b1b'; }
        else if (l === 'WARN') { bg = '#fef3c7'; fg = '#92400e'; }
        else if (l === 'DEBUG') { bg = '#dbeafe'; fg = '#1e40af'; }
        return '<span style="display:inline-block;padding:2px 8px;border-radius:999px;background:' + bg + ';color:' + fg + ';font-weight:600;">' + esc(l) + '</span>';
    };

    var truncateMessage = function(message) {
        var raw = String(message || '').replace(/\s+/g, ' ').trim();
        if (!raw) return { short: '', full: '', truncated: false };
        if (raw.length <= 180) return { short: raw, full: raw, truncated: false };
        return { short: raw.slice(0, 180) + '…', full: raw, truncated: true };
    };

    var summarizeRowMessage = function(level, message, context) {
        var text = String(message || '').replace(/\s+/g, ' ').trim().replace(/^\[trace:[^\]]+\]\s*/i, '');
        var lineMatch = text.match(/\bline\s+(\d+)\b/i);
        var line = lineMatch && lineMatch[1] ? lineMatch[1] : '';

        var methodMatch = text.match(/Call to undefined method\s+([A-Za-z0-9_\\]+::[A-Za-z0-9_]+)\s*\(/i);
        if (methodMatch && methodMatch[1]) {
            return 'Fatal error: undefined method ' + methodMatch[1] + (line ? (' on line ' + line + '.') : '.');
        }
        var classMatch = text.match(/Class\s+["']([^"']+)["']\s+not found/i);
        if (classMatch && classMatch[1]) {
            return 'Fatal error: missing class ' + classMatch[1] + (line ? (' on line ' + line + '.') : '.');
        }
        var functionMatch = text.match(/Call to undefined function\s+([A-Za-z0-9_\\]+)\s*\(/i);
        if (functionMatch && functionMatch[1]) {
            return 'Fatal error: undefined function ' + functionMatch[1] + (line ? (' on line ' + line + '.') : '.');
        }
        if (/SECURITY\s+router\.reject/i.test(text) && /invalid_nonce/i.test(text)) {
            var ctx = context && typeof context === 'object' ? context : {};
            var action = String(ctx.ajax_action || ctx.action || '').trim();
            var path = String(ctx.path || '').trim();
            if (action && path) {
                return 'Security warning: request rejected due to invalid nonce initiated by ' + action + ' at ' + path + '.';
            }
            if (action) {
                return 'Security warning: request rejected due to invalid nonce initiated by ' + action + '.';
            }
            if (path) {
                return 'Security warning: request rejected due to invalid nonce at ' + path + '.';
            }
            return 'Security warning: request rejected due to invalid nonce.';
        }
        if (/timeout|timed out|maximum execution time|lock wait timeout/i.test(text)) {
            var timeoutCtx = context && typeof context === 'object' ? context : {};
            var timeoutTarget = String(timeoutCtx.service || timeoutCtx.module || 'runtime operation').trim();
            return 'Timeout warning: operation exceeded the allowed time in ' + timeoutTarget + '.';
        }
        if (/sqlstate|pdoexception|mysql|database error|query failed|deadlock|duplicate entry|unknown column|cannot add or update a child row/i.test(text)) {
            var dbCtx = context && typeof context === 'object' ? context : {};
            var dbTarget = String(dbCtx.service || dbCtx.module || 'application data layer').trim();
            return 'Database error: query or write operation failed in ' + dbTarget + '.';
        }
        if (/permission denied|access denied|unauthorized|forbidden|not allowed|insufficient privileges|read-only file system/i.test(text)) {
            var permCtx = context && typeof context === 'object' ? context : {};
            var permAction = String(permCtx.ajax_action || permCtx.action || '').trim();
            var permPath = String(permCtx.path || '').trim();
            if (permAction && permPath) return 'Permission warning: blocked while running ' + permAction + ' at ' + permPath + '.';
            if (permAction) return 'Permission warning: blocked while running ' + permAction + '.';
            if (permPath) return 'Permission warning: blocked while accessing ' + permPath + '.';
            return 'Permission warning: operation blocked by access controls.';
        }
        if (/could not resolve host|name or service not known|getaddrinfo|dns/i.test(text)) {
            var dnsCtx = context && typeof context === 'object' ? context : {};
            var dnsTarget = String(dnsCtx.service || 'external API').trim();
            return 'Network error: DNS lookup failed while contacting ' + dnsTarget + '.';
        }
        if (/connection refused|failed to connect|connection reset|connection timed out|econnrefused|econnreset|etimedout/i.test(text)) {
            var connCtx = context && typeof context === 'object' ? context : {};
            var connTarget = String(connCtx.service || 'external API').trim();
            return 'Network error: connection to ' + connTarget + ' failed.';
        }
        if (/ssl|tls|certificate|cert verify|x509/i.test(text)) {
            var tlsCtx = context && typeof context === 'object' ? context : {};
            var tlsTarget = String(tlsCtx.service || 'external API').trim();
            return 'Network security error: TLS/certificate validation failed for ' + tlsTarget + '.';
        }
        if (/\b5\d\d\b|bad gateway|gateway timeout|service unavailable|upstream/i.test(text)) {
            var upstreamCtx = context && typeof context === 'object' ? context : {};
            var upstreamTarget = String(upstreamCtx.service || 'upstream service').trim();
            return 'API error: ' + upstreamTarget + ' returned a server error.';
        }
        if (/\b429\b|rate limit|too many requests/i.test(text)) {
            var rlCtx = context && typeof context === 'object' ? context : {};
            var rlTarget = String(rlCtx.service || 'external API').trim();
            return 'API warning: rate limit reached for ' + rlTarget + '.';
        }
        if (/^error\.trace\b/i.test(text)) {
            return 'Error trace event recorded for this request.';
        }

        var lvl = String(level || '').toUpperCase();
        if (lvl === 'ERROR') return 'Application error detected.';
        if (lvl === 'WARN') return 'Application warning detected.';
        return 'Log event recorded.';
    };

    var extractLineNumber = function(message) {
        var text = String(message || '');
        var match = text.match(/\bline\s+(\d+)\b/i);
        if (match && match[1]) return match[1];
        match = text.match(/\bon\s+line\s+(\d+)\b/i);
        if (match && match[1]) return match[1];
        return '';
    };

    var extractItem = function(message) {
        var text = String(message || '');
        var match = text.match(/(?:class|function|method|property|constant|variable)\s+["']?([A-Za-z0-9_:\\$->-]+)["']?/i);
        if (match && match[1]) return match[1];
        match = text.match(/undefined\s+(?:array\s+)?key\s+["']?([^"'\s]+)["']?/i);
        if (match && match[1]) return match[1];
        match = text.match(/cannot\s+redeclare\s+([A-Za-z0-9_:\\$]+)\s*\(/i);
        if (match && match[1]) return match[1];
        match = text.match(/failed opening required ['"]([^'"]+)['"]/i);
        if (match && match[1]) return match[1];
        return '';
    };

    var renderRows = function(entries) {
        if (!Array.isArray(entries) || entries.length === 0) {
            els.tbody.innerHTML = '<tr><td colspan="3" style="padding:16px;color:#6b7280;">No log entries found.</td></tr>';
            return;
        }

        els.tbody.innerHTML = entries.map(function(entry) {
            var ts = esc(entry.timestamp_display || entry.timestamp || '');
            var compactMessage = truncateMessage(entry.message || '');
            var context = entry.context && typeof entry.context === 'object' ? entry.context : null;
            var summaryMessage = summarizeRowMessage(entry.level, entry.message || '', context);
            var msg = esc(summaryMessage || '');
            var fullMessageHtml = compactMessage.truncated
                ? ('<details style="margin-top:6px;"><summary style="cursor:pointer;color:#334155;display:inline;font-weight:600;">Show raw log message</summary><pre style="margin-top:8px;padding:10px;background:#f8fafc;border:1px solid #e5e7eb;border-radius:8px;white-space:pre-wrap;">' + esc(compactMessage.full) + '</pre></details>')
                : ('<details style="margin-top:6px;"><summary style="cursor:pointer;color:#334155;display:inline;font-weight:600;">Show raw log message</summary><pre style="margin-top:8px;padding:10px;background:#f8fafc;border:1px solid #e5e7eb;border-radius:8px;white-space:pre-wrap;">' + esc(compactMessage.full) + '</pre></details>');
            return '<tr>'
                + '<td style="padding:10px 12px;border-bottom:1px solid #f1f5f9;white-space:nowrap;vertical-align:top;">' + ts + '</td>'
                + '<td style="padding:10px 12px;border-bottom:1px solid #f1f5f9;vertical-align:top;">' + levelBadge(entry.level) + '</td>'
                + '<td style="padding:10px 12px;border-bottom:1px solid #f1f5f9;vertical-align:top;"><span>' + msg + '</span>' + fullMessageHtml + '</td>'
                + '</tr>';
        }).join('');
    };

    var nonceBase = String(viewerAjax.nonce || '').trim();
    var setPageValue = function(page) {
        if (els.page) {
            els.page.value = String(page);
        }
    };

    var postAction = function(action, extraData) {
        var body = new FormData();
        Object.keys(extraData || {}).forEach(function(key) {
            body.append(key, String(extraData[key]));
        });
        if (window.Metis && Metis.request && typeof Metis.request.postForm === 'function') {
            return Metis.request.postForm(viewerAjax, action, body, 'AJAX endpoint is not configured.');
        }
        if (!viewerAjax.ajax_url) {
            return Promise.reject(new Error('AJAX endpoint is not configured.'));
        }

        body.set('action', action);
        if (!body.has('nonce') && nonceBase) {
            body.set('nonce', nonceBase);
        }
        if (!body.has('metis_action_nonce')) {
            var explicitNonce = (viewerAjax.action_nonces && viewerAjax.action_nonces[action]) ? viewerAjax.action_nonces[action] : '';
            body.set('metis_action_nonce', explicitNonce || nonceBase);
        }

        return fetch(viewerAjax.ajax_url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: typeof metisCsrfHeaders === 'function' ? metisCsrfHeaders(body.get('metis_action_nonce') || body.get('nonce') || '') : undefined,
            body: body
        }).then(function(res) {
            return res.json().catch(function() {
                throw new Error('Invalid AJAX response.');
            });
        }).then(function(json) {
            if (!json || !json.success) {
                var message = (json && json.data && json.data.message) ? String(json.data.message) : 'Unable to load logs.';
                throw new Error(message);
            }
            return json.data || {};
        });
    };

    var statePayload = function() {
        return {
            logging_view_file: (els.viewFile && els.viewFile.value) || '',
            logging_view_lines: (els.viewLines && els.viewLines.value) || '200',
            logging_search: (els.search && els.search.value) || '',
            logging_page: String(currentPage),
            logging_auto_refresh: els.live && els.live.checked ? '1' : '0',
            logging_auto_refresh_seconds: (els.liveSeconds && els.liveSeconds.value) || '10'
        };
    };

    var updatePaging = function(page, pages, totalEntries) {
        currentPage = page;
        totalPages = pages;
        setPageValue(page);
        if (els.pageInfo) els.pageInfo.textContent = 'Page ' + page + ' of ' + pages;
        if (els.loaded) els.loaded.textContent = 'Loaded ' + Number(totalEntries || 0).toLocaleString() + ' entries.';
        if (els.prev) els.prev.disabled = page <= 1;
        if (els.next) els.next.disabled = page >= pages;
        if (els.jumpInput) {
            els.jumpInput.max = String(Math.max(1, pages));
            if (parseInt(els.jumpInput.value || '0', 10) < 1 || parseInt(els.jumpInput.value || '0', 10) > pages) {
                els.jumpInput.value = String(page);
            }
        }
    };

    var refreshViewer = function(resetPage) {
        if (loading) return;
        if (resetPage) currentPage = 1;
        if (els.error) {
            els.error.style.display = 'none';
            els.error.textContent = '';
        }
        loading = true;
        postAction('metis_settings_fetch_logging_viewer', statePayload()).then(function(data) {
            renderRows((data && data.entries) || []);
            updatePaging(
                Number((data && data.page) || 1),
                Number((data && data.total_pages) || 1),
                Number((data && data.total_entries) || 0)
            );
        }).catch(function(err) {
            if (els.error) {
                els.error.textContent = err && err.message ? err.message : 'Unable to load log entries.';
                els.error.style.display = 'block';
            }
            if (window.console && console.error) console.error('[Logging] viewer load failed', err);
        }).finally(function() {
            loading = false;
        });
    };

    var startLive = function() {
        if (!els.live || !els.live.checked) return;
        var seconds = parseInt((els.liveSeconds && els.liveSeconds.value) || '10', 10);
        if (!seconds || seconds < 5) seconds = 10;
        if (liveTimer) window.clearInterval(liveTimer);
        liveTimer = window.setInterval(function() { refreshViewer(false); }, seconds * 1000);
    };
    var stopLive = function() {
        if (liveTimer) {
            window.clearInterval(liveTimer);
            liveTimer = null;
        }
    };

    if (els.refresh) els.refresh.addEventListener('click', function() { refreshViewer(false); });
    if (els.clear) {
        els.clear.addEventListener('click', function() {
            if (els.error) {
                els.error.style.display = 'none';
                els.error.textContent = '';
            }
            postAction('metis_settings_clear_log', statePayload()).then(function(data) {
                renderRows((data && data.entries) || []);
                updatePaging(
                    Number((data && data.page) || 1),
                    Number((data && data.total_pages) || 1),
                    Number((data && data.total_entries) || 0)
                );
            }).catch(function(err) {
                if (els.error) {
                    els.error.textContent = err && err.message ? err.message : 'Unable to clear log.';
                    els.error.style.display = 'block';
                }
            });
        });
    }

    if (els.viewFile) els.viewFile.addEventListener('change', function() { refreshViewer(true); });
    if (els.viewLines) els.viewLines.addEventListener('change', function() { refreshViewer(true); });
    if (els.search) {
        els.search.addEventListener('keydown', function(ev) {
            if (ev.key === 'Enter') ev.preventDefault();
        });
        els.search.addEventListener('input', function() {
            if (searchTimer) window.clearTimeout(searchTimer);
            searchTimer = window.setTimeout(function() { refreshViewer(true); }, 300);
        });
    }

    if (els.prev) els.prev.addEventListener('click', function() { if (currentPage > 1) { currentPage -= 1; refreshViewer(false); } });
    if (els.next) els.next.addEventListener('click', function() { if (currentPage < totalPages) { currentPage += 1; refreshViewer(false); } });
    if (els.jumpBtn) els.jumpBtn.addEventListener('click', function() {
        var target = parseInt((els.jumpInput && els.jumpInput.value) || '1', 10);
        if (!target || target < 1) target = 1;
        if (target > totalPages) target = totalPages;
        currentPage = target;
        refreshViewer(false);
    });
    if (els.jumpInput) {
        els.jumpInput.addEventListener('keydown', function(ev) {
            if (ev.key === 'Enter') {
                ev.preventDefault();
                if (els.jumpBtn) els.jumpBtn.click();
            }
        });
    }

    if (els.live) {
        els.live.addEventListener('change', function() {
            if (els.live.checked) startLive();
            else stopLive();
        });
    }
    if (els.liveSeconds) {
        els.liveSeconds.addEventListener('change', function() {
            if (els.live && els.live.checked) startLive();
        });
    }
    startLive();
})();
</script>
<?php metis_settings_render_section_end(); ?>
