<?php
if ( ! defined( 'METIS_ROOT' ) ) exit;
require_once __DIR__ . '/_settings_bootstrap.php';
$ctx = metis_settings_bootstrap( 'logging' );
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
?>
<h1 class="mw-page-title"><?php echo metis_escape_html( metis_current_module_view_title( 'Settings' ) ); ?></h1>
<p class="mw-subtitle">Set log level and review events.</p>
<?php metis_settings_render_messages( $saved, $errors ); ?>
<?php metis_settings_render_section_nav( 'logging' ); ?>
<form method="post" class="mw-settings-form" data-metis-settings-form="1" data-settings-section="logging">
    <?php metis_runtime_nonce_field( 'metis_save_settings_logging', 'metis_settings_nonce' ); ?>
    <input type="hidden" id="logging_page" name="logging_page" value="<?php echo metis_escape_attr( (string) ( $logging_page ?? 1 ) ); ?>">
    <div class="mw-settings-card">
        <div class="mw-settings-header"><h2>Logging Controls</h2></div>
        <div class="mw-settings-body">
            <div class="mw-field">
                <label for="logging_enabled">Enable Logging</label>
                <label style="display:flex;align-items:center;gap:8px;font-weight:500;">
                    <input type="checkbox" id="logging_enabled" name="logging_enabled" value="1" <?php metis_attr_checked( $logging_enabled, true ); ?>>
                    Write events to the Metis log file
                </label>
                <p class="mw-help">When disabled, Metis logger output is suppressed for all levels.</p>
            </div>
            <div class="mw-field">
                <label for="logging_min_level">Minimum Log Level</label>
                <select id="logging_min_level" name="logging_min_level" class="mw-input mw-input-wide">
                    <option value="INFO" <?php metis_attr_selected( $logging_min_level, 'INFO' ); ?>>Info</option>
                    <option value="WARN" <?php metis_attr_selected( $logging_min_level, 'WARN' ); ?>>Warning</option>
                    <option value="ERROR" <?php metis_attr_selected( $logging_min_level, 'ERROR' ); ?>>Error</option>
                </select>
                <p class="mw-help">Info logs everything important, Warning keeps warnings and errors, and Error records only failures.</p>
            </div>
            <div class="mw-field">
                <label for="logging_force_url_token">Force Logging URL String</label>
                <input type="text" id="logging_force_url_token" name="logging_force_url_token" class="mw-input mw-input-wide" value="<?php echo metis_escape_attr( $logging_force_url_token ); ?>" placeholder="debug-logging-token">
                <p class="mw-help">Optional. Use at least 16 characters. Force logging now requires an exact token match via <code>?metis_log_token=...</code> or <code>X-Metis-Log-Token</code>.</p>
            </div>
            <div class="mw-field">
                <label>Current Log File</label>
                <div class="mw-help"><code><?php echo metis_escape_html( (string) $logging_log_path ); ?></code></div>
            </div>
        </div>
    </div>
    <div class="mw-settings-actions">
        <button type="submit" class="mw-btn">Save Logging Settings</button>
    </div>
</form>
<div class="mw-settings-card" id="metis-live-log-viewer">
    <div class="mw-settings-header"><h2>Live Log Viewer</h2></div>
    <div class="mw-settings-body">
            <div class="mw-field" style="display:flex;align-items:center;gap:8px;margin-bottom:10px;">
                <button type="button" id="logging_refresh_btn" class="mw-btn mw-btn-ghost">Refresh</button>
                <button type="button" id="logging_clear_btn" class="mw-btn mw-btn-ghost">Clear Log</button>
                <label style="display:flex;align-items:center;gap:6px;margin:0 0 0 2px;font-weight:600;">
                    <input type="checkbox" id="logging_auto_refresh" name="logging_auto_refresh" value="1" <?php metis_attr_checked( ! empty( $logging_auto_refresh ), true ); ?>>
                    Live
                </label>
                <select id="logging_auto_refresh_seconds" name="logging_auto_refresh_seconds" class="mw-input" style="min-width:84px;">
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
                            <th style="text-align:left;padding:10px 12px;border-bottom:1px solid #e5e7eb;white-space:nowrap;">Time</th>
                            <th style="text-align:left;padding:10px 12px;border-bottom:1px solid #e5e7eb;white-space:nowrap;">Level</th>
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
                                ?>
                                <tr>
                                    <td style="padding:10px 12px;border-bottom:1px solid #f1f5f9;white-space:nowrap;vertical-align:top;"><?php echo metis_escape_html( (string) ( $entry['timestamp_display'] ?? $entry['timestamp'] ?? '' ) ); ?></td>
                                    <td style="padding:10px 12px;border-bottom:1px solid #f1f5f9;vertical-align:top;">
                                        <span style="display:inline-block;padding:2px 8px;border-radius:999px;background:<?php echo metis_escape_attr( $badge_bg ); ?>;color:<?php echo metis_escape_attr( $badge_fg ); ?>;font-weight:600;"><?php echo metis_escape_html( $level ); ?></span>
                                    </td>
                                    <td style="padding:10px 12px;border-bottom:1px solid #f1f5f9;vertical-align:top;">
                                        <span><?php echo metis_escape_html( (string) ( $entry['message'] ?? '' ) ); ?></span>
                                        <?php if ( $context !== null ) : ?>
                                            <details style="display:inline-block;margin-left:8px;">
                                                <summary style="cursor:pointer;color:#334155;display:inline;font-weight:600;">View context</summary>
                                                <div style="margin-top:8px;border:1px solid #e5e7eb;border-radius:8px;overflow:hidden;">
                                                    <table style="width:100%;border-collapse:collapse;font-size:12px;">
                                                        <tbody>
                                                            <?php foreach ( $context as $ctx_key => $ctx_value ) : ?>
                                                                <tr>
                                                                    <td style="padding:6px 8px;border-bottom:1px solid #f1f5f9;background:#f8fafc;width:220px;vertical-align:top;"><code><?php echo metis_escape_html( (string) $ctx_key ); ?></code></td>
                                                                    <td style="padding:6px 8px;border-bottom:1px solid #f1f5f9;vertical-align:top;">
                                                                        <?php if ( is_array( $ctx_value ) || is_object( $ctx_value ) ) : ?>
                                                                            <pre style="margin:0;white-space:pre-wrap;"><?php echo metis_escape_html( $render_context_value( $ctx_value ) ); ?></pre>
                                                                        <?php else : ?>
                                                                            <?php echo metis_escape_html( $render_context_value( $ctx_value ) ); ?>
                                                                        <?php endif; ?>
                                                                    </td>
                                                                </tr>
                                                            <?php endforeach; ?>
                                                        </tbody>
                                                    </table>
                                                </div>
                                                <details style="margin-top:8px;">
                                                    <summary style="cursor:pointer;color:#6b7280;">Raw JSON</summary>
                                                    <pre style="margin-top:8px;padding:10px;background:#f8fafc;border:1px solid #e5e7eb;border-radius:8px;white-space:pre-wrap;"><?php echo metis_escape_html( metis_json_encode( $context, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) ); ?></pre>
                                                </details>
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
</div>
<script>
(function() {
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

    var renderRows = function(entries) {
        if (!Array.isArray(entries) || entries.length === 0) {
            els.tbody.innerHTML = '<tr><td colspan="3" style="padding:16px;color:#6b7280;">No log entries found.</td></tr>';
            return;
        }

        els.tbody.innerHTML = entries.map(function(entry) {
            var ts = esc(entry.timestamp_display || entry.timestamp || '');
            var msg = esc(entry.message || '');
            var context = entry.context && typeof entry.context === 'object' ? entry.context : null;
            var contextHtml = '';
            if (context) {
                var json = esc(JSON.stringify(context, null, 2));
                contextHtml = '<details style="display:inline-block;margin-left:8px;">'
                    + '<summary style="cursor:pointer;color:#334155;display:inline;font-weight:600;">View context</summary>'
                    + '<pre style="margin-top:8px;padding:10px;background:#f8fafc;border:1px solid #e5e7eb;border-radius:8px;white-space:pre-wrap;">' + json + '</pre>'
                    + '</details>';
            }
            return '<tr>'
                + '<td style="padding:10px 12px;border-bottom:1px solid #f1f5f9;white-space:nowrap;vertical-align:top;">' + ts + '</td>'
                + '<td style="padding:10px 12px;border-bottom:1px solid #f1f5f9;vertical-align:top;">' + levelBadge(entry.level) + '</td>'
                + '<td style="padding:10px 12px;border-bottom:1px solid #f1f5f9;vertical-align:top;"><span>' + msg + '</span>' + contextHtml + '</td>'
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
