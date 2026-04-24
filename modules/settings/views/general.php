<?php
if ( ! defined( 'METIS_ROOT' ) ) exit;
require_once __DIR__ . '/_settings_bootstrap.php';
$ctx = metis_settings_bootstrap( 'general' );
if ( empty( $ctx['allowed'] ) ) return;
extract( $ctx, EXTR_SKIP );
$format_preview = static function ( string $format ): string {
    try {
        $tz = new DateTimeZone( (string) $timezone );
        return ( new DateTimeImmutable( 'now', $tz ) )->format( $format );
    } catch ( Throwable ) {
        return $format;
    }
};
?>
<h1 class="mw-page-title"><?php echo metis_escape_html( metis_current_module_label( 'Settings' ) ); ?></h1>
<p class="mw-subtitle">Manage portal identity.</p>
<?php metis_settings_render_messages( $saved, $errors ); ?>
<?php metis_settings_render_section_nav( 'general' ); ?>
<form method="post" class="mw-settings-form" data-metis-settings-form="1" data-settings-section="general">
    <?php metis_runtime_nonce_field( 'metis_save_settings_general', 'metis_settings_nonce' ); ?>
    <div class="mw-settings-card">
        <div class="mw-settings-header"><h2>Portal</h2></div>
        <div class="mw-settings-body">
            <div class="mw-field">
                <label for="portal_name">Site Name</label>
                <input type="text" id="portal_name" name="portal_name" class="mw-input mw-input-wide" value="<?php echo metis_escape_attr( $portal_name ); ?>" placeholder="Metis Portal">
            </div>
            <div class="mw-field">
                <label for="org_name">Organization Name</label>
                <input type="text" id="org_name" name="org_name" class="mw-input mw-input-wide" value="<?php echo metis_escape_attr( $org_name ); ?>" placeholder="Mobilize Waco">
            </div>
            <div class="mw-field">
                <label for="org_tagline">Organization Tagline</label>
                <input type="text" id="org_tagline" name="org_tagline" class="mw-input mw-input-wide" value="<?php echo metis_escape_attr( (string) $org_tagline ); ?>" placeholder="Access. Visibility. Leadership.">
                <p class="mw-help">Used on homepage title as <code>{org name}: {tagline}</code>.</p>
            </div>
            <div class="mw-field">
                <label for="site_homepage_page_id">Homepage</label>
                <select id="site_homepage_page_id" name="site_homepage_page_id" class="mw-input mw-input-wide">
                    <option value="0">No homepage selected</option>
                    <?php foreach ( $website_pages_for_homepage as $homepage_page ) : ?>
                        <option value="<?php echo metis_escape_attr( (string) ( $homepage_page->id ?? 0 ) ); ?>" <?php metis_attr_selected( (int) ( $homepage_page->id ?? 0 ), (int) $site_homepage_page_id ); ?>>
                            <?php echo metis_escape_html( (string) ( $homepage_page->title ?? 'Untitled' ) ); ?> (/<?php echo metis_escape_html( (string) ( $homepage_page->slug ?? '' ) ); ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
                <p class="mw-help">Only published pages are available. This setting controls route <code>/</code>.</p>
            </div>
            <div class="mw-field">
                <label for="timezone">Timezone</label>
                <select id="timezone" name="timezone" class="mw-input mw-input-wide">
                    <?php foreach ( timezone_identifiers_list() as $tz_id ) : ?>
                        <option value="<?php echo metis_escape_attr( $tz_id ); ?>" <?php metis_attr_selected( (string) $timezone, (string) $tz_id ); ?>>
                            <?php echo metis_escape_html( $tz_id ); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <p class="mw-help">Default timezone for logs, reports, and date/time rendering.</p>
            </div>
            <div class="mw-field">
                <label for="date_format_choice">Date Format</label>
                <select id="date_format_choice" name="date_format_choice" class="mw-input mw-input-wide">
                    <?php foreach ( (array) $date_presets as $date_ui => $date_php ) : ?>
                        <option value="<?php echo metis_escape_attr( (string) $date_ui ); ?>" <?php metis_attr_selected( (string) $date_format_choice, (string) $date_ui ); ?>>
                            <?php echo metis_escape_html( $format_preview( (string) $date_php ) ); ?>
                        </option>
                    <?php endforeach; ?>
                    <option value="custom" <?php metis_attr_selected( (string) $date_format_choice, 'custom' ); ?>>Custom</option>
                </select>
                <div id="date_format_custom_wrap" style="<?php echo $date_format_choice === 'custom' ? '' : 'display:none;'; ?> margin-top:8px;">
                    <input type="text" id="date_format_custom" name="date_format_custom" class="mw-input mw-input-wide" value="<?php echo metis_escape_attr( (string) $date_format_custom ); ?>" placeholder="yyyy.mm.dd">
                    <p class="mw-help">Custom tokens: <code>yyyy</code>, <code>yy</code>, <code>mm</code>, <code>m</code>, <code>dd</code>, <code>d</code>.</p>
                </div>
                <p class="mw-help" id="date_format_preview">Preview: <?php echo metis_escape_html( $format_preview( (string) $date_format ) ); ?></p>
            </div>
            <div class="mw-field">
                <label for="time_format_choice">Time Format</label>
                <select id="time_format_choice" name="time_format_choice" class="mw-input mw-input-wide">
                    <?php foreach ( (array) $time_presets as $time_ui => $time_php ) : ?>
                        <option value="<?php echo metis_escape_attr( (string) $time_ui ); ?>" <?php metis_attr_selected( (string) $time_format_choice, (string) $time_ui ); ?>>
                            <?php echo metis_escape_html( $format_preview( (string) $time_php ) ); ?>
                        </option>
                    <?php endforeach; ?>
                    <option value="custom" <?php metis_attr_selected( (string) $time_format_choice, 'custom' ); ?>>Custom</option>
                </select>
                <div id="time_format_custom_wrap" style="<?php echo $time_format_choice === 'custom' ? '' : 'display:none;'; ?> margin-top:8px;">
                    <input type="text" id="time_format_custom" name="time_format_custom" class="mw-input mw-input-wide" value="<?php echo metis_escape_attr( (string) $time_format_custom ); ?>" placeholder="h:m:s a/m">
                    <p class="mw-help">Custom tokens: <code>HH</code>, <code>H</code>, <code>hh</code>, <code>h</code>, <code>mm</code>/<code>m</code>, <code>ss</code>/<code>s</code>, <code>a/m</code> or <code>A/P</code>.</p>
                </div>
                <p class="mw-help" id="time_format_preview">Preview: <?php echo metis_escape_html( $format_preview( (string) $time_format ) ); ?></p>
            </div>
            <div class="mw-field">
                <label>Combined Preview</label>
                <div class="mw-help" id="datetime_format_preview"><?php echo metis_escape_html( $format_preview( (string) $date_format . ' ' . (string) $time_format ) ); ?></div>
            </div>
            <details class="mw-field">
                <summary><strong>Show Brand Assets</strong></summary>
                <div class="mw-field" data-settings-media-field="portal_logo">
                    <label>Logo</label>
                    <div class="metis-logo-upload">
                        <?php if ( $portal_logo_src !== '' ) : ?>
                            <div class="metis-logo-preview-wrap" data-settings-media-preview-wrap="portal_logo">
                                <img src="<?php echo metis_escape_attr( $portal_logo_src ); ?>" alt="Current logo" class="metis-logo-preview" data-settings-media-preview="portal_logo">
                                <div class="metis-logo-meta">
                                    <strong data-settings-media-name="portal_logo"><?php echo metis_escape_html( (string) ( $portal_logo['filename'] ?? 'Current logo' ) ); ?></strong>
                                    <span data-settings-media-mime="portal_logo"><?php echo metis_escape_html( strtoupper( str_replace( 'image/', '', (string) ( $portal_logo['mime_type'] ?? '' ) ) ) ); ?></span>
                                </div>
                            </div>
                        <?php else : ?>
                            <div class="metis-logo-preview-wrap" data-settings-media-preview-wrap="portal_logo" style="display:none;">
                                <img src="" alt="Selected logo" class="metis-logo-preview" data-settings-media-preview="portal_logo">
                                <div class="metis-logo-meta">
                                    <strong data-settings-media-name="portal_logo"></strong>
                                    <span data-settings-media-mime="portal_logo"></span>
                                </div>
                            </div>
                            <p class="mw-help" data-settings-media-empty="portal_logo">No logo selected yet.</p>
                        <?php endif; ?>
                        <input type="hidden" name="portal_logo_media_token" value="<?php echo metis_escape_attr( (string) ( $portal_logo['public_token'] ?? '' ) ); ?>" data-settings-media-token="portal_logo">
                        <input type="hidden" name="portal_logo_media_url" value="<?php echo metis_escape_attr( (string) ( $portal_logo['url'] ?? '' ) ); ?>" data-settings-media-url="portal_logo">
                        <input type="hidden" name="portal_logo_media_name" value="<?php echo metis_escape_attr( (string) ( $portal_logo['filename'] ?? '' ) ); ?>" data-settings-media-filename="portal_logo">
                        <input type="hidden" name="portal_logo_media_mime" value="<?php echo metis_escape_attr( (string) ( $portal_logo['mime_type'] ?? '' ) ); ?>" data-settings-media-mimevalue="portal_logo">
                        <button type="button" class="mw-btn mw-btn-ghost mw-btn-sm" data-settings-media-pick="portal_logo" data-settings-media-types="image">Choose from Media Library</button>
                        <button type="button" class="mw-btn mw-btn-ghost mw-btn-sm" data-settings-media-clear="portal_logo">Clear selection</button>
                        <p class="mw-help">Uses the centralized Media Library. Recommended max logo size: 2 MB.</p>
                        <?php if ( $portal_logo_src !== '' ) : ?>
                            <label class="metis-settings-flag">
                                <input type="checkbox" name="remove_portal_logo" value="1">
                                Remove current logo
                            </label>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="mw-field" data-settings-media-field="portal_favicon">
                    <label>Favicon</label>
                    <div class="metis-logo-upload">
                        <?php if ( $portal_favicon_src !== '' ) : ?>
                            <div class="metis-logo-preview-wrap metis-favicon-preview-wrap" data-settings-media-preview-wrap="portal_favicon">
                                <img src="<?php echo metis_escape_attr( $portal_favicon_src ); ?>" alt="Current favicon" class="metis-logo-preview metis-favicon-preview" data-settings-media-preview="portal_favicon">
                                <div class="metis-logo-meta">
                                    <strong data-settings-media-name="portal_favicon"><?php echo metis_escape_html( (string) ( $portal_favicon['filename'] ?? 'Current favicon' ) ); ?></strong>
                                    <span data-settings-media-mime="portal_favicon"><?php echo metis_escape_html( strtoupper( str_replace( [ 'image/', 'vnd.microsoft.' ], '', (string) ( $portal_favicon['mime_type'] ?? '' ) ) ) ); ?></span>
                                </div>
                            </div>
                        <?php else : ?>
                            <div class="metis-logo-preview-wrap metis-favicon-preview-wrap" data-settings-media-preview-wrap="portal_favicon" style="display:none;">
                                <img src="" alt="Selected favicon" class="metis-logo-preview metis-favicon-preview" data-settings-media-preview="portal_favicon">
                                <div class="metis-logo-meta">
                                    <strong data-settings-media-name="portal_favicon"></strong>
                                    <span data-settings-media-mime="portal_favicon"></span>
                                </div>
                            </div>
                            <p class="mw-help" data-settings-media-empty="portal_favicon">No favicon selected yet.</p>
                        <?php endif; ?>
                        <input type="hidden" name="portal_favicon_media_token" value="<?php echo metis_escape_attr( (string) ( $portal_favicon['public_token'] ?? '' ) ); ?>" data-settings-media-token="portal_favicon">
                        <input type="hidden" name="portal_favicon_media_url" value="<?php echo metis_escape_attr( (string) ( $portal_favicon['url'] ?? '' ) ); ?>" data-settings-media-url="portal_favicon">
                        <input type="hidden" name="portal_favicon_media_name" value="<?php echo metis_escape_attr( (string) ( $portal_favicon['filename'] ?? '' ) ); ?>" data-settings-media-filename="portal_favicon">
                        <input type="hidden" name="portal_favicon_media_mime" value="<?php echo metis_escape_attr( (string) ( $portal_favicon['mime_type'] ?? '' ) ); ?>" data-settings-media-mimevalue="portal_favicon">
                        <button type="button" class="mw-btn mw-btn-ghost mw-btn-sm" data-settings-media-pick="portal_favicon" data-settings-media-types="image">Choose from Media Library</button>
                        <button type="button" class="mw-btn mw-btn-ghost mw-btn-sm" data-settings-media-clear="portal_favicon">Clear selection</button>
                        <p class="mw-help">Uses the centralized Media Library. Use a square image, ideally 32x32 or 48x48.</p>
                        <?php if ( $portal_favicon_src !== '' ) : ?>
                            <label class="metis-settings-flag">
                                <input type="checkbox" name="remove_portal_favicon" value="1">
                                Remove current favicon
                            </label>
                        <?php endif; ?>
                    </div>
                </div>
            </details>
        </div>
    </div>
    <div class="mw-settings-actions">
        <button type="submit" class="mw-btn">Save General Settings</button>
    </div>
</form>
<script>
(function() {
    var dateChoice = document.getElementById('date_format_choice');
    var timeChoice = document.getElementById('time_format_choice');
    var dateCustom = document.getElementById('date_format_custom');
    var timeCustom = document.getElementById('time_format_custom');
    var dateCustomWrap = document.getElementById('date_format_custom_wrap');
    var timeCustomWrap = document.getElementById('time_format_custom_wrap');
    var datePreview = document.getElementById('date_format_preview');
    var timePreview = document.getElementById('time_format_preview');
    var combinedPreview = document.getElementById('datetime_format_preview');
    var timezoneSel = document.getElementById('timezone');

    function pad2(v) { return String(v).padStart(2, '0'); }

    function nowParts(tz) {
        var now = new Date();
        var fmt = new Intl.DateTimeFormat('en-US', {
            timeZone: tz || 'UTC',
            year: 'numeric',
            month: 'numeric',
            day: 'numeric',
            hour: 'numeric',
            minute: 'numeric',
            second: 'numeric',
            hour12: true
        });
        var parts = {};
        fmt.formatToParts(now).forEach(function(p) { parts[p.type] = p.value; });

        var year = parseInt(parts.year || '0', 10);
        var month = parseInt(parts.month || '1', 10);
        var day = parseInt(parts.day || '1', 10);
        var hour12 = parseInt(parts.hour || '12', 10);
        var minute = parseInt(parts.minute || '0', 10);
        var second = parseInt(parts.second || '0', 10);
        var period = (parts.dayPeriod || 'AM').toUpperCase();
        var hour24 = hour12 % 12;
        if (period === 'PM') hour24 += 12;

        return {
            yyyy: String(year),
            yy: String(year).slice(-2),
            mm: pad2(month),
            m: String(month),
            dd: pad2(day),
            d: String(day),
            HH: pad2(hour24),
            H: String(hour24),
            hh: pad2(hour12),
            h: String(hour12),
            mi: pad2(minute),
            m1: String(minute),
            ss: pad2(second),
            s: String(second),
            a: period === 'AM' ? 'am' : 'pm',
            A: period === 'AM' ? 'AM' : 'PM',
            monthShort: new Intl.DateTimeFormat('en-US', { timeZone: tz || 'UTC', month: 'short' }).format(now)
        };
    }

    function formatDate(pattern, p) {
        var out = String(pattern || '');
        out = out.replace(/mmmm/ig, p.monthShort);
        out = out.replace(/mmm/ig, p.monthShort);
        out = out.replace(/yyyy/ig, p.yyyy);
        out = out.replace(/yy/ig, p.yy);
        out = out.replace(/mm/g, p.mm);
        out = out.replace(/\bm\b/g, p.m);
        out = out.replace(/dd/ig, p.dd);
        out = out.replace(/\bd\b/ig, p.d);
        return out;
    }

    function formatTime(pattern, p) {
        var out = String(pattern || '');
        out = out.replace(/am\/pm|a\/m/ig, p.a);
        out = out.replace(/A\/P|AM\/PM/g, p.A);
        out = out.replace(/HH/g, p.HH);
        out = out.replace(/\bH\b/g, p.H);
        out = out.replace(/hh/g, p.hh);
        out = out.replace(/\bh\b/g, p.h);
        out = out.replace(/mm/g, p.mi);
        out = out.replace(/\bm\b/g, p.m1);
        out = out.replace(/ss/g, p.ss);
        out = out.replace(/\bs\b/g, p.s);
        out = out.replace(/\bA\b/g, p.A);
        out = out.replace(/\ba\b/g, p.a);
        return out;
    }

    function selectedDatePattern() {
        if (!dateChoice) return '';
        return dateChoice.value === 'custom' ? (dateCustom ? dateCustom.value : '') : dateChoice.value;
    }

    function selectedTimePattern() {
        if (!timeChoice) return '';
        return timeChoice.value === 'custom' ? (timeCustom ? timeCustom.value : '') : timeChoice.value;
    }

    function refreshPreview() {
        var tz = timezoneSel ? timezoneSel.value : 'UTC';
        var p = nowParts(tz);
        var dateOut = formatDate(selectedDatePattern(), p);
        var timeOut = formatTime(selectedTimePattern(), p);

        if (dateCustomWrap) dateCustomWrap.style.display = (dateChoice && dateChoice.value === 'custom') ? '' : 'none';
        if (timeCustomWrap) timeCustomWrap.style.display = (timeChoice && timeChoice.value === 'custom') ? '' : 'none';
        if (datePreview) datePreview.textContent = 'Preview: ' + dateOut;
        if (timePreview) timePreview.textContent = 'Preview: ' + timeOut;
        if (combinedPreview) combinedPreview.textContent = dateOut + ' ' + timeOut;
    }

    [dateChoice, timeChoice, dateCustom, timeCustom, timezoneSel].forEach(function(el) {
        if (!el) return;
        el.addEventListener('change', refreshPreview);
        el.addEventListener('input', refreshPreview);
    });

    refreshPreview();
})();
</script>
<?php metis_settings_render_section_end(); ?>
