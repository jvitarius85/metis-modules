<?php
if (!defined('METIS_ROOT')) exit;

require_once dirname( __DIR__, 2 ) . '/portal/views/_dashboard_data.php';

use Metis\Modules\Newsletter\ContactService;
use Metis\Modules\Newsletter\CampaignService;
use Metis\Modules\Newsletter\TemplateService;
use Metis\Modules\Newsletter\SubscriptionService;

function metis_newsletter_ajax_verify_nonce(): void {
    $nonce = isset(metis_request_post()['nonce']) ? metis_text_clean(metis_runtime_unslash(metis_request_post()['nonce'])) : '';
    $action_nonce = isset(metis_request_post()['metis_action_nonce']) ? metis_text_clean(metis_runtime_unslash(metis_request_post()['metis_action_nonce'])) : '';
    $action = isset(metis_request_post()['action']) ? metis_key_clean(metis_runtime_unslash(metis_request_post()['action'])) : '';

    $valid = metis_runtime_verify_nonce($nonce, 'metis_newsletter');
    if (!$valid && $action_nonce !== '' && $action !== '' && function_exists('metis_ajax_nonce_action')) {
        $valid = metis_runtime_verify_nonce($action_nonce, metis_ajax_nonce_action($action));
    }

    if (!$valid) {
        metis_runtime_send_json_error('Invalid nonce.', 403);
    }
}

function metis_newsletter_parse_datetime_local(string $value): ?string {
    $value = trim($value);
    if ($value === '') return null;

    $tz = metis_newsletter_resolved_timezone();
    $dt = DateTimeImmutable::createFromFormat('Y-m-d\TH:i', $value, $tz);
    if (!($dt instanceof DateTimeImmutable)) {
        $dt = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $value, $tz);
    }
    if (!($dt instanceof DateTimeImmutable)) {
        return null;
    }

    return $dt->format('Y-m-d H:i:s');
}

function metis_newsletter_sanitize_status(string $status): string {
    $status = strtolower(trim($status));
    $allowed = ['draft', 'test_ready', 'scheduled', 'queued', 'sending', 'sent', 'archived', 'paused', 'cancelled'];
    return in_array($status, $allowed, true) ? $status : 'draft';
}

function metis_newsletter_sanitize_sub_status(string $status): string {
    $status = strtolower(trim($status));
    $allowed = ['subscribed', 'unsubscribed', 'bounced', 'rejected'];
    return in_array($status, $allowed, true) ? $status : 'subscribed';
}

function metis_newsletter_error_status($status): int {
    $code = (int) $status;
    return in_array($code, [400, 401, 403, 404, 409, 422, 429], true) ? $code : 500;
}

function metis_newsletter_sanitize_ref_code($value): string {
    if (!is_scalar($value)) return '';
    $raw = trim((string) metis_runtime_unslash($value));
    if ($raw === '') return '';
    return preg_replace('/[^A-Za-z0-9_-]/', '', $raw) ?? '';
}

function metis_newsletter_announcement_org_name(): string {
    $name = class_exists('Core_Settings_Service') ? trim((string) Core_Settings_Service::get('org_name', '')) : '';
    if ($name === '' && class_exists('Core_Settings_Service')) {
        $name = trim((string) Core_Settings_Service::get('portal_name', ''));
    }
    if ($name === '' && function_exists('metis_portal_name')) {
        $name = trim((string) metis_portal_name());
    }
    if ($name === '') {
        $name = 'Organization';
    }
    return $name;
}

function metis_newsletter_announcement_theme_defaults(): array {
    return [
        'header_html' => metis_newsletter_clean_html((string) Core_Settings_Service::get('newsletter_theme_header_html', '')),
        'personalized_html' => '',
        'closing_html' => '',
        'footer_html' => metis_newsletter_clean_html((string) Core_Settings_Service::get('newsletter_theme_footer_html', '')),
        'canvas_bg' => (string) Core_Settings_Service::get('newsletter_theme_canvas_bg', 'transparent'),
        'text_color' => (string) Core_Settings_Service::get('newsletter_theme_text_color', 'text'),
        'font_size' => (int) Core_Settings_Service::get('newsletter_theme_font_size', 16),
        'content_width_mode' => (string) Core_Settings_Service::get('newsletter_theme_content_width_mode', 'normal'),
        'content_width' => max(520, min(820, (int) Core_Settings_Service::get('newsletter_theme_content_width', 680))),
        'divider_color' => (string) Core_Settings_Service::get('newsletter_theme_divider_color', 'border'),
        'divider_style' => (string) Core_Settings_Service::get('newsletter_theme_divider_style', 'solid'),
        'divider_weight' => max(1, min(6, (int) Core_Settings_Service::get('newsletter_theme_divider_weight', 1))),
        'header_bg' => (string) Core_Settings_Service::get('newsletter_theme_header_bg', 'transparent'),
        'header_text_color' => (string) Core_Settings_Service::get('newsletter_theme_header_text_color', 'text'),
        'header_padding' => (string) Core_Settings_Service::get('newsletter_theme_header_padding', '24px 28px 12px 28px'),
        'personalized_bg' => 'transparent',
        'personalized_text_color' => 'text',
        'personalized_padding' => '0 28px 8px 28px',
        'closing_bg' => (string) Core_Settings_Service::get('newsletter_theme_closing_bg', 'transparent'),
        'closing_text_color' => (string) Core_Settings_Service::get('newsletter_theme_closing_text_color', 'text'),
        'closing_padding' => (string) Core_Settings_Service::get('newsletter_theme_closing_padding', '12px 28px 8px 28px'),
        'footer_bg' => (string) Core_Settings_Service::get('newsletter_theme_footer_bg', 'transparent'),
        'footer_text_color' => (string) Core_Settings_Service::get('newsletter_theme_footer_text_color', 'muted'),
        'footer_padding' => (string) Core_Settings_Service::get('newsletter_theme_footer_padding', '16px 28px 28px 28px'),
    ];
}

function metis_newsletter_announcement_normalize_body_html(string $raw): string {
    $raw = trim($raw);
    if ($raw === '') {
        return '';
    }

    if (strpos($raw, '<') !== false) {
        return metis_newsletter_sanitize_theme_html($raw);
    }

    $paragraphs = preg_split("/\n\s*\n/", str_replace(["\r\n", "\r"], "\n", $raw)) ?: [];
    $html = [];
    foreach ($paragraphs as $paragraph) {
        $paragraph = trim($paragraph);
        if ($paragraph === '') {
            continue;
        }
        $html[] = '<p>' . nl2br(metis_escape_html($paragraph)) . '</p>';
    }

    return metis_newsletter_sanitize_theme_html(implode('', $html));
}

function metis_newsletter_announcement_footer_html(string $existing_footer_html): string {
    $existing_footer_html = trim($existing_footer_html);
    $links_html = '<p style="margin:0;"><a href="{{manage_subscription_url}}">Manage Preferences</a> &nbsp;|&nbsp; <a href="{{unsubscribe_url}}">Unsubscribe</a> &nbsp;|&nbsp; <a href="{{view_online_url}}">View online</a></p>';
    if ($existing_footer_html === '') {
        return $links_html;
    }

    $has_links = strpos($existing_footer_html, '{{unsubscribe_url}}') !== false
        || strpos($existing_footer_html, '{{manage_subscription_url}}') !== false
        || strpos($existing_footer_html, '{{view_online_url}}') !== false
        || strpos($existing_footer_html, '{{view_newsletter_url}}') !== false;

    return $has_links ? $existing_footer_html : ($existing_footer_html . $links_html);
}

function metis_newsletter_announcement_doc_json(string $body_html): string {
    $settings = metis_newsletter_announcement_theme_defaults();
    $settings['body_html'] = $body_html;
    $settings['closing_html'] = '<p style="margin:0;">-- ' . metis_escape_html(metis_newsletter_announcement_org_name()) . '</p>';
    $settings['footer_html'] = metis_newsletter_announcement_footer_html((string) ($settings['footer_html'] ?? ''));

    return metis_json_encode([
        'version' => 1,
        'settings' => $settings,
        'blocks' => [[
            'id' => 'announcement-body',
            'type' => 'text',
            'data' => [
                'body' => $body_html,
            ],
        ]],
    ]);
}

function metis_newsletter_default_klipy_search_url(): string {
    return 'https://api.klipy.com/v1/gifs/search';
}

function metis_newsletter_normalize_klipy_search_url(string $raw_url): string {
    $default = metis_newsletter_default_klipy_search_url();
    $raw_url = trim($raw_url);
    if ($raw_url === '') {
        return $default;
    }

    $candidate = metis_url_clean($raw_url);
    if ($candidate === '') {
        return '';
    }

    $parts = parse_url($candidate);
    if (!is_array($parts)) {
        return '';
    }
    if (isset($parts['user']) || isset($parts['pass'])) {
        return '';
    }

    $scheme = strtolower((string) ($parts['scheme'] ?? ''));
    $host = strtolower((string) ($parts['host'] ?? ''));
    $port = isset($parts['port']) ? (int) $parts['port'] : 443;
    $path = '/' . trim((string) ($parts['path'] ?? ''), '/');

    if ($scheme !== 'https' || $host !== 'api.klipy.com' || $port !== 443 || $path !== '/v1/gifs/search') {
        return '';
    }

    return $default;
}

function metis_newsletter_find_or_create_contact(string $email, string $first_name = '', string $last_name = ''): int {
    return ContactService::findOrCreateContactId($email, $first_name, $last_name);
}

function metis_newsletter_preview_contact_payload(): array {
    return ContactService::previewPayload();
}

function metis_newsletter_theme_html_allowed_tags(): array {
    return [
        'a' => ['href' => true, 'title' => true, 'target' => true, 'rel' => true, 'class' => true, 'style' => true],
        'b' => ['class' => true, 'style' => true],
        'strong' => ['class' => true, 'style' => true],
        'i' => ['class' => true, 'style' => true],
        'em' => ['class' => true, 'style' => true],
        'u' => ['class' => true, 'style' => true],
        's' => ['class' => true, 'style' => true],
        'span' => ['class' => true, 'style' => true],
        'div' => ['class' => true, 'style' => true, 'align' => true],
        'p' => ['class' => true, 'style' => true, 'align' => true],
        'br' => [],
        'hr' => ['class' => true, 'style' => true],
        'figure' => ['class' => true, 'style' => true, 'data-align' => true, 'data-size' => true],
        'img' => ['src' => true, 'alt' => true, 'title' => true, 'class' => true, 'style' => true, 'width' => true],
        'ul' => ['class' => true, 'style' => true],
        'ol' => ['class' => true, 'style' => true],
        'li' => ['class' => true, 'style' => true],
        'h1' => ['class' => true, 'style' => true, 'align' => true],
        'h2' => ['class' => true, 'style' => true, 'align' => true],
        'h3' => ['class' => true, 'style' => true, 'align' => true],
        'h4' => ['class' => true, 'style' => true, 'align' => true],
        'h5' => ['class' => true, 'style' => true, 'align' => true],
        'h6' => ['class' => true, 'style' => true, 'align' => true],
        'pre' => ['class' => true, 'style' => true],
        'code' => ['class' => true, 'style' => true],
    ];
}

function metis_newsletter_sanitize_theme_html(string $html): string {
    $html = (string) metis_runtime_unslash($html);
    if (trim($html) === '') {
        return '';
    }

    $allowed_map = metis_newsletter_theme_html_allowed_tags();
    $allowed_tags = array_keys($allowed_map);
    $stripped = strip_tags($html, '<' . implode('><', $allowed_tags) . '>');

    if (!class_exists('DOMDocument')) {
        return trim($stripped);
    }

    $internal_errors = libxml_use_internal_errors(true);
    $doc = new DOMDocument('1.0', 'UTF-8');
    $wrapper = '<!DOCTYPE html><html><body><metis-root>' . $stripped . '</metis-root></body></html>';
    $loaded = $doc->loadHTML($wrapper, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    libxml_clear_errors();
    libxml_use_internal_errors($internal_errors);
    if ($loaded === false) {
        return trim($stripped);
    }

    $roots = $doc->getElementsByTagName('metis-root');
    $root = $roots->length > 0 ? $roots->item(0) : null;
    if (!$root) {
        return trim($stripped);
    }

    $walk = static function ($node) use (&$walk, $allowed_map, $doc): void {
        if (!$node) {
            return;
        }

        for ($i = $node->childNodes->length - 1; $i >= 0; $i--) {
            $walk($node->childNodes->item($i));
        }

        if (!($node instanceof DOMElement)) {
            return;
        }

        $tag = strtolower($node->tagName);
        if ($tag === 'metis-root') {
            return;
        }

        if (!isset($allowed_map[$tag])) {
            $fragment = $doc->createDocumentFragment();
            while ($node->firstChild) {
                $fragment->appendChild($node->firstChild);
            }
            $node->parentNode?->replaceChild($fragment, $node);
            return;
        }

        $allowed_attrs = $allowed_map[$tag];
        $remove = [];
        foreach ($node->attributes as $attr) {
            $name = strtolower($attr->name);
            $value = (string) $attr->value;
            if (!isset($allowed_attrs[$name])) {
                $remove[] = $attr->name;
                continue;
            }
            if ($name === 'src') {
                $resolved = metis_newsletter_resolve_media_reference_url($value);
                if ($resolved !== '') {
                    $node->setAttribute($attr->name, $resolved);
                    $value = $resolved;
                }
            }
            if (in_array($name, ['href', 'src'], true) && function_exists('metis_runtime_is_safe_url') && !metis_runtime_is_safe_url($value)) {
                $remove[] = $attr->name;
                continue;
            }
            if ($name === 'style' && function_exists('metis_runtime_is_safe_css_value') && !metis_runtime_is_safe_css_value($value)) {
                $remove[] = $attr->name;
                continue;
            }
        }
        foreach ($remove as $attr_name) {
            $node->removeAttribute($attr_name);
        }
    };

    $walk($root);

    $output = '';
    foreach ($root->childNodes as $child) {
        $output .= $doc->saveHTML($child);
    }

    return trim($output);
}

function metis_newsletter_extract_region_html(string $html, string $region): string {
    $html = trim($html);
    $region = trim($region);
    if ($html === '' || $region === '') {
        return '';
    }

    if (!class_exists('DOMDocument')) {
        return '';
    }

    $internal_errors = libxml_use_internal_errors(true);
    $doc = new DOMDocument('1.0', 'UTF-8');
    $wrapper = '<!DOCTYPE html><html><body><metis-root>' . $html . '</metis-root></body></html>';
    $loaded = $doc->loadHTML($wrapper, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    libxml_clear_errors();
    libxml_use_internal_errors($internal_errors);
    if ($loaded === false) {
        return '';
    }

    $xpath = new DOMXPath($doc);
    $query = sprintf('//*[@data-metis-newsletter-region="%s"]', $region);
    $nodes = $xpath->query($query);
    if (!$nodes || $nodes->length < 1) {
        return '';
    }

    $node = $nodes->item(0);
    if (!$node) {
        return '';
    }

    $output = '';
    foreach ($node->childNodes as $child) {
        $output .= $doc->saveHTML($child);
    }

    return trim($output);
}

function metis_newsletter_apply_editor_body_to_doc(string $doc_json_raw, string $editor_body_html): string {
    return metis_newsletter_normalize_campaign_doc_json(
        $doc_json_raw,
        metis_newsletter_sanitize_theme_html($editor_body_html)
    );
}

function metis_newsletter_register_ajax_controllers(): void {
    $actions = [
        'metis_newsletter_save_defaults' => 'edit',
        'metis_newsletter_save_template' => 'edit',
        'metis_newsletter_template_get' => 'view',
        'metis_newsletter_doc_preview' => 'view',
        'metis_newsletter_save_list' => 'edit',
        'metis_newsletter_get_list' => 'view',
        'metis_newsletter_delete_list' => 'delete',
        'metis_newsletter_send_announcement_blast' => 'edit',
        'metis_newsletter_save_campaign' => 'edit',
        'metis_newsletter_campaign_get' => 'view',
        'metis_newsletter_queue_campaign' => 'edit',
        'metis_newsletter_test_send_campaign' => 'edit',
        'metis_newsletter_archive_campaign' => 'edit',
        'metis_newsletter_reassign_import_lists' => 'edit',
        'metis_newsletter_delete_campaign' => 'delete',
        'metis_newsletter_campaign_status' => 'view',
        'metis_newsletter_search_contacts' => 'view',
        'metis_newsletter_list_contacts' => 'view',
        'metis_newsletter_run_queue' => 'edit',
        'metis_newsletter_upsert_subscription' => 'edit',
        'metis_newsletter_bulk_add_contacts_to_list' => 'edit',
        'metis_newsletter_record_event' => 'edit',
        'metis_newsletter_sync_google_usage' => 'edit',
        'metis_newsletter_klipy_search' => 'view',
        'metis_newsletter_giphy_search' => 'view',
        'metis_newsletter_upload_image' => 'edit',
        'metis_newsletter_upload_attachment' => 'edit',
        'metis_newsletter_layout_profile_save' => 'edit',
        'metis_newsletter_save_theme_defaults' => 'edit',
    ];

    foreach ($actions as $action => $permission) {
        metis_ajax_register_controller($action, [
            'module' => 'newsletter',
            'permission' => $permission,
            'nonce_action' => metis_ajax_nonce_action($action),
        ]);
    }
}

metis_newsletter_register_ajax_controllers();

metis_ajax_register_handler( 'metis_newsletter_save_defaults', function () {
    metis_newsletter_ajax_verify_nonce();
    if (!metis_newsletter_can_manage()) metis_runtime_send_json_error('Unauthorized', 403);

    $from_name = metis_text_clean(metis_runtime_unslash(metis_request_post()['from_name'] ?? (metis_request_post()['sender_name'] ?? '')));
    $from_email = metis_email_clean(metis_runtime_unslash(metis_request_post()['from_email'] ?? (metis_request_post()['sender_email'] ?? '')));
    $reply_to = metis_email_clean(metis_runtime_unslash(metis_request_post()['reply_to'] ?? ''));

    if ($from_email !== '' && !metis_email_is_valid($from_email)) {
        metis_runtime_send_json_error('Default From Email is invalid.', 400);
    }
    if ($reply_to !== '' && !metis_email_is_valid($reply_to)) {
        metis_runtime_send_json_error('Default Reply-To is invalid.', 400);
    }

    Core_Settings_Service::set('newsletter_default_from_name', $from_name, true);
    Core_Settings_Service::set('newsletter_default_from_email', $from_email, true);
    Core_Settings_Service::set('newsletter_default_reply_to', $reply_to, true);

    metis_portal_dashboard_forget_all();
    metis_runtime_send_json_success([
        'from_name' => $from_name,
        'from_email' => $from_email,
        'reply_to' => $reply_to,
    ]);
});

metis_ajax_register_handler( 'metis_newsletter_layout_profile_save', function () {
    metis_newsletter_ajax_verify_nonce();
    if (!metis_newsletter_can_manage()) metis_runtime_send_json_error('Unauthorized', 403);

    $requested = isset(metis_request_post()['newsletter_layout_profile'])
        ? metis_key_clean((string) metis_runtime_unslash(metis_request_post()['newsletter_layout_profile']))
        : '';
    $profile = \Metis\Modules\Website\Services\LayoutProfileService::sanitizeNewsletterProfile($requested);
    Core_Settings_Service::set('newsletter_layout_profile', $profile, true);

    metis_portal_dashboard_forget_all();
    metis_runtime_send_json_success([
        'newsletter_layout_profile' => $profile,
    ]);
});

metis_ajax_register_handler( 'metis_newsletter_save_theme_defaults', function () {
    metis_newsletter_ajax_verify_nonce();
    if (!metis_newsletter_can_manage()) metis_runtime_send_json_error('Unauthorized', 403);

    $header_html = metis_newsletter_sanitize_theme_html((string) (metis_request_post()['header_html'] ?? ''));
    $personalized_html = metis_newsletter_sanitize_theme_html((string) (metis_request_post()['personalized_html'] ?? ''));
    $closing_html = metis_newsletter_sanitize_theme_html((string) (metis_request_post()['closing_html'] ?? ''));
    $footer_html = metis_newsletter_sanitize_theme_html((string) (metis_request_post()['footer_html'] ?? ''));
    $canvas_bg = metis_text_clean((string) metis_runtime_unslash(metis_request_post()['canvas_bg'] ?? 'transparent')) ?: 'transparent';
    $text_color = metis_text_clean((string) metis_runtime_unslash(metis_request_post()['text_color'] ?? 'text')) ?: 'text';
    $font_size = max(10, min(28, (int) metis_runtime_unslash(metis_request_post()['font_size'] ?? 16)));
    $content_width = max(520, min(820, (int) metis_runtime_unslash(metis_request_post()['content_width'] ?? 680)));
    $content_width_mode = metis_key_clean((string) metis_runtime_unslash(metis_request_post()['content_width_mode'] ?? ''));
    $divider_color = metis_text_clean((string) metis_runtime_unslash(metis_request_post()['divider_color'] ?? 'border')) ?: 'border';
    $divider_style = metis_key_clean((string) metis_runtime_unslash(metis_request_post()['divider_style'] ?? 'solid'));
    $divider_weight = max(1, min(6, (int) metis_runtime_unslash(metis_request_post()['divider_weight'] ?? 1)));
    if ($content_width_mode === '') { $content_width_mode = $content_width <= 600 ? 'narrow' : ($content_width >= 740 ? 'wide' : 'normal'); }
    if (!in_array($content_width_mode, ['narrow','normal','wide'], true)) $content_width_mode = $content_width <= 600 ? 'narrow' : ($content_width >= 740 ? 'wide' : 'normal');
    if (!in_array($divider_style, ['solid', 'dashed', 'dotted', 'double'], true)) $divider_style = 'solid';
    $section_keys = ['header','personalized','closing','footer'];
    foreach ($section_keys as $section_key) {
        Core_Settings_Service::set('newsletter_theme_' . $section_key . '_bg', metis_text_clean((string) metis_runtime_unslash(metis_request_post()[$section_key . '_bg'] ?? 'transparent')) ?: 'transparent', true);
        Core_Settings_Service::set('newsletter_theme_' . $section_key . '_text_color', metis_text_clean((string) metis_runtime_unslash(metis_request_post()[$section_key . '_text_color'] ?? 'text')) ?: 'text', true);
        Core_Settings_Service::set('newsletter_theme_' . $section_key . '_padding', metis_text_clean((string) metis_runtime_unslash(metis_request_post()[$section_key . '_padding'] ?? '0 0 0 0')) ?: '0 0 0 0', true);
    }

    Core_Settings_Service::set('newsletter_theme_header_html', $header_html, true);
    Core_Settings_Service::set('newsletter_theme_personalized_html', $personalized_html, true);
    Core_Settings_Service::set('newsletter_theme_closing_html', $closing_html, true);
    Core_Settings_Service::set('newsletter_theme_footer_html', $footer_html, true);
    Core_Settings_Service::set('newsletter_theme_canvas_bg', $canvas_bg, true);
    Core_Settings_Service::set('newsletter_theme_text_color', $text_color, true);
    Core_Settings_Service::set('newsletter_theme_font_size', $font_size, true);
    Core_Settings_Service::set('newsletter_theme_content_width_mode', $content_width_mode, true);
    Core_Settings_Service::set('newsletter_theme_content_width', $content_width, true);
    Core_Settings_Service::set('newsletter_theme_divider_color', $divider_color, true);
    Core_Settings_Service::set('newsletter_theme_divider_style', $divider_style, true);
    Core_Settings_Service::set('newsletter_theme_divider_weight', $divider_weight, true);

    metis_portal_dashboard_forget_all();
    metis_runtime_send_json_success([
        'theme_defaults' => [
            'header_html' => $header_html,
            'personalized_html' => $personalized_html,
            'closing_html' => $closing_html,
            'footer_html' => $footer_html,
            'canvas_bg' => $canvas_bg,
            'text_color' => $text_color,
            'font_size' => $font_size,
            'content_width_mode' => $content_width_mode,
            'content_width' => $content_width,
            'divider_color' => $divider_color,
            'divider_style' => $divider_style,
            'divider_weight' => $divider_weight,
            'header_bg' => (string) Core_Settings_Service::get('newsletter_theme_header_bg', 'transparent'),
            'header_text_color' => (string) Core_Settings_Service::get('newsletter_theme_header_text_color', 'text'),
            'header_padding' => (string) Core_Settings_Service::get('newsletter_theme_header_padding', '24px 28px 12px 28px'),
            'personalized_bg' => (string) Core_Settings_Service::get('newsletter_theme_personalized_bg', 'transparent'),
            'personalized_text_color' => (string) Core_Settings_Service::get('newsletter_theme_personalized_text_color', 'text'),
            'personalized_padding' => (string) Core_Settings_Service::get('newsletter_theme_personalized_padding', '0 28px 8px 28px'),
            'closing_bg' => (string) Core_Settings_Service::get('newsletter_theme_closing_bg', 'transparent'),
            'closing_text_color' => (string) Core_Settings_Service::get('newsletter_theme_closing_text_color', 'text'),
            'closing_padding' => (string) Core_Settings_Service::get('newsletter_theme_closing_padding', '12px 28px 8px 28px'),
            'footer_bg' => (string) Core_Settings_Service::get('newsletter_theme_footer_bg', 'transparent'),
            'footer_text_color' => (string) Core_Settings_Service::get('newsletter_theme_footer_text_color', 'muted'),
            'footer_padding' => (string) Core_Settings_Service::get('newsletter_theme_footer_padding', '16px 28px 28px 28px'),
        ],
    ]);
});

metis_ajax_register_handler( 'metis_newsletter_save_template', function () {
    metis_newsletter_ajax_verify_nonce();
    if (!metis_newsletter_can_manage()) metis_runtime_send_json_error('Unauthorized', 403);
    metis_newsletter_ensure_schema();

    $template_code = metis_newsletter_sanitize_ref_code(metis_request_post()['template_code'] ?? '');
    $template_id = 0;
    if ($template_code !== '') {
        $template_id = TemplateService::resolveId($template_code);
        if ($template_id < 1) {
            metis_runtime_send_json_error('Template not found.', 404);
        }
    } else {
        $template_id = isset(metis_request_post()['template_id']) ? (int) metis_runtime_unslash(metis_request_post()['template_id']) : 0;
    }
    $name = metis_text_clean(metis_runtime_unslash(metis_request_post()['name'] ?? ''));
    $subject = metis_text_clean(metis_runtime_unslash(metis_request_post()['subject'] ?? ''));
    $from_name = metis_text_clean(metis_runtime_unslash(metis_request_post()['from_name'] ?? (metis_request_post()['sender_name'] ?? '')));
    $from_email = metis_email_clean(metis_runtime_unslash(metis_request_post()['from_email'] ?? (metis_request_post()['sender_email'] ?? '')));
    $reply_to = metis_email_clean(metis_runtime_unslash(metis_request_post()['reply_to'] ?? ''));
    $doc_json_raw = (string) metis_runtime_unslash(metis_request_post()['doc_json'] ?? '');
    $has_html_body = array_key_exists('html_body', metis_request_post());
    $raw_html_body = $has_html_body ? (string) metis_runtime_unslash(metis_request_post()['html_body']) : '';
    $html_body = $has_html_body ? metis_runtime_kses_post($raw_html_body) : '';
    $has_text_body = array_key_exists('text_body', metis_request_post());
    $text_body = $has_text_body ? metis_textarea_clean(metis_runtime_unslash(metis_request_post()['text_body'])) : '';

    $compiled = null;
    if ($doc_json_raw !== '') {
        $compiled = metis_newsletter_doc_compile($doc_json_raw);
        $doc_json_raw = (string) ($compiled['doc_json'] ?? '');
        $html_body = (string) ($compiled['html'] ?? $html_body);
        $text_body = (string) ($compiled['text'] ?? $text_body);
    }
    if ($name === '' || $subject === '' || trim($html_body) === '') {
        metis_runtime_send_json_error('Name, subject, and content are required.', 400);
    }

    $payload = [
        'name' => $name,
        'subject' => $subject,
        'from_name' => $from_name,
        'from_email' => $from_email,
        'reply_to' => $reply_to,
        'doc_json' => $doc_json_raw !== '' ? $doc_json_raw : null,
        'html_body' => $html_body,
        'text_body' => $text_body !== '' ? $text_body : null,
        'updated_at' => metis_current_time('mysql'),
    ];
    $save_result = TemplateService::save($template_id, $payload);
    if (empty($save_result['success'])) {
        metis_runtime_send_json_error($template_id > 0 ? 'Failed to update template.' : 'Failed to create template.', 500);
    }
    $template_id = (int) ($save_result['template_id'] ?? 0);
    $saved_template_code = (string) ($save_result['template_code'] ?? '');

    metis_newsletter_save_revision('template', $template_id, (string) ($payload['doc_json'] ?? ''), (string) ($payload['html_body'] ?? ''), (string) ($payload['text_body'] ?? ''), 'Template saved');
    metis_newsletter_audit_log('template_saved', 'template', $template_id, ['name' => $name]);

    metis_portal_dashboard_forget_all();
    metis_runtime_send_json_success([
        'template_id' => $template_id,
        'template_code' => $saved_template_code,
    ]);
});

metis_ajax_register_handler( 'metis_newsletter_template_get', function () {
    metis_newsletter_ajax_verify_nonce();
    if (!metis_newsletter_can_view()) metis_runtime_send_json_error('Unauthorized', 403);
    metis_newsletter_ensure_schema();

    $template_code = metis_newsletter_sanitize_ref_code(metis_request_post()['template_code'] ?? (metis_request_post()['key'] ?? ''));
    $template_id = isset(metis_request_post()['template_id']) ? (int) metis_runtime_unslash(metis_request_post()['template_id']) : 0;
    if ($template_code === '' && $template_id < 1) {
        metis_runtime_send_json_error('Template reference is required.', 400);
    }

    $row = TemplateService::get($template_id, $template_code);

    if (!$row) {
        metis_runtime_send_json_error('Template not found.', 404);
    }

    metis_runtime_send_json_success([
        'template' => [
            'id' => (int) ($row['id'] ?? 0),
            'template_code' => (string) ($row['template_code'] ?? ''),
            'name' => (string) ($row['name'] ?? ''),
            'subject' => (string) ($row['subject'] ?? ''),
            'from_name' => (string) ($row['from_name'] ?? ''),
            'from_email' => (string) ($row['from_email'] ?? ''),
            'sender_name' => (string) ($row['from_name'] ?? ''),
            'sender_email' => (string) ($row['from_email'] ?? ''),
            'reply_to' => (string) ($row['reply_to'] ?? ''),
            'doc_json' => (string) ($row['doc_json'] ?? ''),
            'html_body' => (string) ($row['html_body'] ?? ''),
            'text_body' => (string) ($row['text_body'] ?? ''),
            'is_active' => (int) ($row['is_active'] ?? 1),
            'updated_at' => (string) ($row['updated_at'] ?? ''),
        ],
    ]);
});

metis_ajax_register_handler( 'metis_newsletter_save_list', function () {
    metis_newsletter_ajax_verify_nonce();
    if (!metis_newsletter_can_manage()) metis_runtime_send_json_error('Unauthorized', 403);
    metis_newsletter_ensure_schema();

    $db = metis_db();
    $table = Metis_Tables::get('newsletter_lists');

    $list_id = isset(metis_request_post()['list_id']) ? (int) metis_runtime_unslash(metis_request_post()['list_id']) : 0;
    $name = metis_text_clean(metis_runtime_unslash(metis_request_post()['name'] ?? ''));
    $description = metis_textarea_clean(metis_runtime_unslash(metis_request_post()['description'] ?? ''));
    $is_active = isset(metis_request_post()['is_active']) ? (int) metis_runtime_unslash(metis_request_post()['is_active']) : 1;

    if ($name === '') metis_runtime_send_json_error('List name is required.', 400);

    $payload = [
        'name' => $name,
        'description' => $description,
        'is_active' => $is_active ? 1 : 0,
        'updated_at' => metis_current_time('mysql'),
    ];

    if ($list_id > 0) {
        $ok = $db->update($table, $payload, ['id' => $list_id], ['%s', '%s', '%d', '%s'], ['%d']);
        if ($ok === false) metis_runtime_send_json_error('Failed to update list.', 500);
    } else {
        if (function_exists('metis_entity_id_service')) {
            $payload = metis_entity_id_service()->assignForInsert('newsletter_list', $payload);
        } else {
            $payload['list_key'] = metis_generate_code('NL', $table, 'list_key');
        }
        $ok = $db->insert($table, $payload, ['%s', '%s', '%d', '%s', '%s', '%s']);
        if ($ok === false) metis_runtime_send_json_error('Failed to create list.', 500);
        $list_id = $db->lastInsertId();
        if ($list_id > 0 && function_exists('metis_entity_id_service')) {
            metis_entity_id_service()->register('newsletter_list', $list_id, (string) ($payload['newsletter_list_uid'] ?? $payload['list_key'] ?? ''));
        }
    }

    metis_portal_dashboard_forget_all();
    metis_runtime_send_json_success(['list_id' => $list_id]);
});

metis_ajax_register_handler( 'metis_newsletter_get_list', function () {
    metis_newsletter_ajax_verify_nonce();
    if (!metis_newsletter_can_view()) metis_runtime_send_json_error('Unauthorized', 403);
    metis_newsletter_ensure_schema();

    $list_id = isset(metis_request_post()['list_id']) ? (int) metis_runtime_unslash(metis_request_post()['list_id']) : 0;
    $snapshot = \Metis\Modules\Newsletter\ReadService::listDetailSnapshot($list_id);
    $selected_list = is_array($snapshot['selected_list'] ?? null) ? $snapshot['selected_list'] : null;
    if (!$selected_list) {
        metis_runtime_send_json_error('List not found.', 404);
    }

    metis_runtime_send_json_success([
        'list' => $selected_list,
        'subscribers' => is_array($snapshot['list_subscribers'] ?? null) ? $snapshot['list_subscribers'] : [],
    ]);
});

metis_ajax_register_handler( 'metis_newsletter_delete_list', function () {
    metis_newsletter_ajax_verify_nonce();
    if (!metis_newsletter_can_manage()) metis_runtime_send_json_error('Unauthorized', 403);
    metis_newsletter_ensure_schema();

    $db = metis_db();
    $lists_table = Metis_Tables::get('newsletter_lists');
    $subs_table = Metis_Tables::get('newsletter_subs');
    $campaign_lists_table = Metis_Tables::get('newsletter_campaign_lists');

    $list_id = isset(metis_request_post()['list_id']) ? (int) metis_runtime_unslash(metis_request_post()['list_id']) : 0;
    if ($list_id < 1) {
        metis_runtime_send_json_error('List is required.', 400);
    }

    $existing_snapshot = \Metis\Modules\Newsletter\ReadService::listDetailSnapshot( $list_id );
    $existing = is_array( $existing_snapshot['selected_list'] ?? null ) ? $existing_snapshot['selected_list'] : null;
    if (!is_array($existing) || empty($existing['id'])) {
        metis_runtime_send_json_error('List not found.', 404);
    }

    $db->execute('START TRANSACTION');

    $subs_deleted = $db->delete($subs_table, [ 'list_id' => $list_id ], [ '%d' ]);
    if ($subs_deleted === false) {
        $db->execute('ROLLBACK');
        metis_runtime_send_json_error('Failed to remove list subscribers.', 500);
    }

    $campaign_links_deleted = $db->delete($campaign_lists_table, [ 'list_id' => $list_id ], [ '%d' ]);
    if ($campaign_links_deleted === false) {
        $db->execute('ROLLBACK');
        metis_runtime_send_json_error('Failed to remove list campaign links.', 500);
    }

    $list_deleted = $db->delete($lists_table, [ 'id' => $list_id ], [ '%d' ]);
    if ($list_deleted === false) {
        $db->execute('ROLLBACK');
        metis_runtime_send_json_error('Failed to delete list.', 500);
    }

    $db->execute('COMMIT');

    metis_portal_dashboard_forget_all();
    metis_runtime_send_json_success([
        'list_id' => $list_id,
        'name' => (string) ($existing['name'] ?? ''),
        'deleted_subscribers' => max(0, (int) $subs_deleted),
        'deleted_campaign_links' => max(0, (int) $campaign_links_deleted),
    ]);
});

metis_ajax_register_handler( 'metis_newsletter_save_campaign', function () {
    metis_newsletter_ajax_verify_nonce();
    if (!metis_newsletter_can_manage()) metis_runtime_send_json_error('Unauthorized', 403);
    metis_newsletter_ensure_schema();

    $db = metis_db();

    $campaigns_table = Metis_Tables::get('newsletter_campaigns');
    $campaign_lists_table = Metis_Tables::get('newsletter_campaign_lists');
    $templates_table = Metis_Tables::get('newsletter_templates');
    $lists_table = Metis_Tables::get('newsletter_lists');

    $campaign_code = metis_newsletter_sanitize_ref_code(metis_request_post()['campaign_code'] ?? '');
    $campaign_id = 0;
    if ($campaign_code !== '') {
        $campaign_id = CampaignService::resolveId($campaign_code);
        if ($campaign_id < 1) {
            metis_runtime_send_json_error('Campaign not found.', 404);
        }
    } else {
        $campaign_id = isset(metis_request_post()['campaign_id']) ? (int) metis_runtime_unslash(metis_request_post()['campaign_id']) : 0;
    }
    $template_code = metis_newsletter_sanitize_ref_code(metis_request_post()['template_code'] ?? '');
    $template_id = 0;
    if ($template_code !== '') {
        $template_id = TemplateService::resolveId($template_code);
        if ($template_id < 1) {
            metis_runtime_send_json_error('Template not found.', 404);
        }
    } else {
        $template_id = isset(metis_request_post()['template_id']) ? (int) metis_runtime_unslash(metis_request_post()['template_id']) : 0;
    }
    $name = metis_text_clean(metis_runtime_unslash(metis_request_post()['name'] ?? ''));
    $campaign_type = CampaignService::normalizeType((string) metis_runtime_unslash(metis_request_post()['campaign_type'] ?? 'campaign'));
    $subject = metis_text_clean(metis_runtime_unslash(metis_request_post()['subject'] ?? ''));
    $from_name = metis_text_clean(metis_runtime_unslash(metis_request_post()['from_name'] ?? (metis_request_post()['sender_name'] ?? '')));
    $from_email = metis_email_clean(metis_runtime_unslash(metis_request_post()['from_email'] ?? (metis_request_post()['sender_email'] ?? '')));
    $reply_to = metis_email_clean(metis_runtime_unslash(metis_request_post()['reply_to'] ?? ''));
    $preheader = metis_text_clean(metis_runtime_unslash(metis_request_post()['preheader'] ?? (metis_request_post()['preview_text'] ?? '')));
    $doc_json_raw = (string) metis_runtime_unslash(metis_request_post()['doc_json'] ?? '');
    $editor_body_field_present = array_key_exists('editor_body_html', metis_request_post());
    $editor_body_html = $editor_body_field_present
        ? metis_newsletter_sanitize_theme_html((string) metis_runtime_unslash(metis_request_post()['editor_body_html']))
        : '';
    $has_html_body = array_key_exists('html_body', metis_request_post());
    $html_body = $has_html_body ? metis_runtime_kses_post(metis_runtime_unslash(metis_request_post()['html_body'])) : '';
    $has_text_body = array_key_exists('text_body', metis_request_post());
    $text_body = $has_text_body ? metis_textarea_clean(metis_runtime_unslash(metis_request_post()['text_body'])) : '';
    $audience_json_raw = (string) metis_runtime_unslash(metis_request_post()['audience_json'] ?? '');
    $attachments_json_raw = (string) metis_runtime_unslash(metis_request_post()['attachments_json'] ?? '');
    $scheduled_at = metis_newsletter_parse_datetime_local((string) (metis_runtime_unslash(metis_request_post()['scheduled_at'] ?? '')));
    $status = metis_newsletter_sanitize_status((string) (metis_runtime_unslash(metis_request_post()['status'] ?? 'draft')));
    $attachments_payload = [];
    if ($attachments_json_raw !== '') {
        $decoded_attachments = json_decode($attachments_json_raw, true);
        if (is_array($decoded_attachments)) {
            foreach ($decoded_attachments as $att) {
                if (!is_array($att)) continue;
                $path = (string) ($att['path'] ?? '');
                $url = metis_url_clean((string) ($att['url'] ?? ''));
                $name = metis_text_clean((string) ($att['name'] ?? ''));
                $mime = metis_text_clean((string) ($att['mime'] ?? 'application/octet-stream'));
                $size = max(0, (int) ($att['size'] ?? 0));
                if ($path !== '' && file_exists($path)) {
                    $attachments_payload[] = ['path' => $path, 'url' => $url, 'name' => $name !== '' ? $name : basename($path), 'mime' => $mime, 'size' => $size];
                } elseif ($url !== '') {
                    $attachments_payload[] = ['url' => $url, 'name' => $name !== '' ? $name : basename(parse_url($url, PHP_URL_PATH) ?: 'attachment'), 'mime' => $mime, 'size' => $size];
                }
            }
        }
    }

    $list_ids = [];
    if (isset(metis_request_post()['list_ids'])) {
        $decoded = json_decode((string) metis_runtime_unslash(metis_request_post()['list_ids']), true);
        if (is_array($decoded)) {
            $list_ids = array_values(array_unique(array_map('intval', $decoded)));
        }
    }

    if ($name === '' || $subject === '') {
        metis_runtime_send_json_error('Campaign name and subject are required.', 400);
    }

    if ($doc_json_raw !== '' || $editor_body_field_present) {
        $doc_json_raw = metis_newsletter_normalize_campaign_doc_json(
            $doc_json_raw,
            $editor_body_field_present ? $editor_body_html : null
        );
    }

    if ($doc_json_raw !== '') {
        $compiled = metis_newsletter_doc_compile($doc_json_raw);
        $doc_json_raw = (string) ($compiled['doc_json'] ?? '');
        $html_body = (string) ($compiled['html'] ?? $html_body);
        $text_body = (string) ($compiled['text'] ?? $text_body);
    }

    $persisted_doc = $doc_json_raw !== '' ? metis_newsletter_doc_parse($doc_json_raw) : [];
    $persisted_settings = isset($persisted_doc['settings']) && is_array($persisted_doc['settings']) ? $persisted_doc['settings'] : [];
    $persisted_editor_body_html = (string) ($persisted_settings['body_html'] ?? '');

    if (!empty($list_ids)) {
        $list_ids = CampaignService::normalizeListIds($list_ids);
    }

    $payload = [
        'campaign_type' => $campaign_type,
        'template_id' => $template_id > 0 ? $template_id : null,
        'name' => $name,
        'subject' => $subject,
        'from_name' => $from_name,
        'from_email' => $from_email,
        'reply_to' => $reply_to,
        'preheader' => $preheader,
        'doc_json' => $doc_json_raw !== '' ? $doc_json_raw : null,
        'editor_body_html' => $persisted_editor_body_html !== '' ? $persisted_editor_body_html : null,
        'status' => $status,
        'scheduled_at' => $scheduled_at,
        'audience_json' => $audience_json_raw !== '' ? metis_json_encode(metis_newsletter_decode_audience_json($audience_json_raw)) : null,
        'attachments_json' => !empty($attachments_payload) ? metis_json_encode($attachments_payload) : null,
        'updated_at' => metis_current_time('mysql'),
    ];

    if ($has_html_body || $doc_json_raw !== '') {
        $payload['html_body'] = $html_body !== '' ? $html_body : null;
    }
    if ($has_text_body || $doc_json_raw !== '') {
        $payload['text_body'] = $text_body !== '' ? $text_body : null;
    }

    $field_formats = [
        'campaign_type' => '%s',
        'template_id' => '%d',
        'name' => '%s',
        'subject' => '%s',
        'from_name' => '%s',
        'from_email' => '%s',
        'reply_to' => '%s',
        'preheader' => '%s',
        'doc_json' => '%s',
        'editor_body_html' => '%s',
        'status' => '%s',
        'scheduled_at' => '%s',
        'audience_json' => '%s',
        'attachments_json' => '%s',
        'updated_at' => '%s',
        'html_body' => '%s',
        'text_body' => '%s',
        'newsletter_campaign_uid' => '%s',
        'campaign_code' => '%s',
        'created_by' => '%d',
    ];
    $payload_formats = [];
    foreach (array_keys($payload) as $payload_key) {
        $payload_formats[] = $field_formats[$payload_key] ?? '%s';
    }

    $save_result = CampaignService::save($campaign_id, $payload, $payload_formats, $list_ids);
    if (empty($save_result['success'])) metis_runtime_send_json_error($campaign_id > 0 ? 'Failed to update campaign.' : 'Failed to create campaign.', 500);
    $campaign_id = (int) ($save_result['campaign_id'] ?? 0);

    metis_newsletter_save_revision('campaign', $campaign_id, (string) ($payload['doc_json'] ?? ''), (string) ($payload['html_body'] ?? ''), (string) ($payload['text_body'] ?? ''), 'Campaign saved');
    metis_newsletter_audit_log('campaign_saved', 'campaign', $campaign_id, ['status' => $status, 'list_ids' => $list_ids]);
    $saved_row = is_array($save_result['campaign'] ?? null) ? $save_result['campaign'] : [];
    $saved_list_ids = is_array($save_result['list_ids'] ?? null) ? array_values(array_map('intval', $save_result['list_ids'])) : [];

    $saved_doc = metis_newsletter_doc_parse((string) ($saved_row['doc_json'] ?? ''));
    $saved_settings = isset($saved_doc['settings']) && is_array($saved_doc['settings']) ? $saved_doc['settings'] : [];
    $saved_editor_body_html = (string) ($saved_row['editor_body_html'] ?? '');
    if ($saved_editor_body_html === '') {
        $saved_editor_body_html = (string) ($saved_settings['body_html'] ?? '');
    }
    if ($saved_editor_body_html === '') {
        $saved_editor_body_html = metis_newsletter_extract_region_html((string) ($saved_row['html_body'] ?? ''), 'body');
    }

    metis_portal_dashboard_forget_all();
    metis_runtime_send_json_success([
        'campaign_id' => $campaign_id,
        'campaign_code' => (string) ($saved_row['campaign_code'] ?? ''),
        'campaign' => [
            'id' => $campaign_id,
            'campaign_code' => (string) ($saved_row['campaign_code'] ?? ''),
            'campaign_type' => CampaignService::normalizeType((string) ($saved_row['campaign_type'] ?? 'campaign')),
            'template_id' => (int) ($saved_row['template_id'] ?? 0),
            'template_code' => (string) ($saved_row['template_code'] ?? ''),
            'name' => (string) ($saved_row['name'] ?? ''),
            'subject' => (string) ($saved_row['subject'] ?? ''),
            'from_name' => (string) ($saved_row['from_name'] ?? ''),
            'from_email' => (string) ($saved_row['from_email'] ?? ''),
            'sender_name' => (string) ($saved_row['from_name'] ?? ''),
            'sender_email' => (string) ($saved_row['from_email'] ?? ''),
            'reply_to' => (string) ($saved_row['reply_to'] ?? ''),
            'preheader' => (string) ($saved_row['preheader'] ?? ''),
            'preview_text' => (string) ($saved_row['preheader'] ?? ''),
            'doc_json' => metis_newsletter_normalize_campaign_doc_json((string) ($saved_row['doc_json'] ?? ''), $saved_editor_body_html),
            'html_body' => (string) ($saved_row['html_body'] ?? ''),
            'editor_body_html' => $saved_editor_body_html,
            'text_body' => (string) ($saved_row['text_body'] ?? ''),
            'status' => (string) ($saved_row['status'] ?? 'draft'),
            'scheduled_at' => (string) ($saved_row['scheduled_at'] ?? ''),
            'audience_json' => (string) ($saved_row['audience_json'] ?? ''),
            'attachments_json' => (string) ($saved_row['attachments_json'] ?? ''),
            'updated_at' => (string) ($saved_row['updated_at'] ?? ''),
            'list_ids' => $saved_list_ids,
        ],
    ]);
});

metis_ajax_register_handler( 'metis_newsletter_send_announcement_blast', function () {
    metis_newsletter_ajax_verify_nonce();
    if (!metis_newsletter_can_manage()) metis_runtime_send_json_error('Unauthorized', 403);
    metis_newsletter_ensure_schema();

    $subject = metis_text_clean(metis_runtime_unslash(metis_request_post()['subject'] ?? ''));
    $body_input = (string) metis_runtime_unslash(metis_request_post()['body'] ?? (metis_request_post()['body_html'] ?? ''));
    $body_html = metis_newsletter_announcement_normalize_body_html($body_input);
    $list_ids = [];
    if (isset(metis_request_post()['list_ids'])) {
        $decoded = json_decode((string) metis_runtime_unslash(metis_request_post()['list_ids']), true);
        if (is_array($decoded)) {
            $list_ids = array_values(array_unique(array_map('intval', $decoded)));
        }
    }
    $list_ids = CampaignService::normalizeListIds($list_ids);

    if ($subject === '') {
        metis_runtime_send_json_error('Subject is required.', 400);
    }
    if ($body_html === '') {
        metis_runtime_send_json_error('Body is required.', 400);
    }
    if (empty($list_ids)) {
        metis_runtime_send_json_error('Choose at least one list.', 400);
    }

    $eligible = metis_newsletter_collect_recipients(0, ['list_ids' => $list_ids]);
    if (empty($eligible)) {
        metis_runtime_send_json_error('No eligible subscribed contacts were found in the selected lists.', 422);
    }

    $default_from_name = trim((string) Core_Settings_Service::get('newsletter_default_from_name', ''));
    $default_from_email = trim((string) Core_Settings_Service::get('newsletter_default_from_email', ''));
    $default_reply_to = trim((string) Core_Settings_Service::get('newsletter_default_reply_to', ''));
    $doc_json = metis_newsletter_announcement_doc_json($body_html);
    $compiled = metis_newsletter_doc_compile($doc_json);
    $name = 'Announcement Blast: ' . $subject;
    $now = metis_current_time('mysql');

    $payload = [
        'campaign_type' => CampaignService::TYPE_ANNOUNCEMENT_BLAST,
        'template_id' => null,
        'name' => $name,
        'subject' => $subject,
        'from_name' => $default_from_name,
        'from_email' => $default_from_email,
        'reply_to' => $default_reply_to,
        'preheader' => '',
        'doc_json' => $doc_json,
        'editor_body_html' => $body_html,
        'html_body' => (string) ($compiled['html'] ?? ''),
        'text_body' => (string) ($compiled['text'] ?? ''),
        'status' => 'draft',
        'scheduled_at' => null,
        'audience_json' => metis_json_encode([
            'mode' => 'lists',
            'list_ids' => $list_ids,
            'rules' => [],
        ]),
        'attachments_json' => null,
        'updated_at' => $now,
    ];
    $formats = ['%s','%d','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s'];

    $save_result = CampaignService::save(0, $payload, $formats, $list_ids);
    if (empty($save_result['success'])) {
        metis_runtime_send_json_error('Failed to create announcement blast.', 500);
    }

    $campaign_id = (int) ($save_result['campaign_id'] ?? 0);
    $queue_result = metis_newsletter_queue_campaign_messages($campaign_id);
    if (empty($queue_result['ok'])) {
        metis_runtime_send_json_error((string) ($queue_result['message'] ?? 'Failed to queue announcement blast.'), 500);
    }

    $saved_row = CampaignService::get($campaign_id, '');
    $saved_row = is_array($saved_row) ? $saved_row : [];
    $list_rows = \Metis\Modules\Newsletter\ReadService::listRowsByIds( $list_ids );

    metis_newsletter_audit_log('announcement_blast_sent', 'campaign', $campaign_id, [
        'subject' => $subject,
        'list_ids' => $list_ids,
        'queued' => (int) ($queue_result['queued'] ?? 0),
    ]);
    metis_portal_dashboard_forget_all();

    metis_runtime_send_json_success([
        'announcement' => [
            'id' => $campaign_id,
            'campaign_code' => (string) ($saved_row['campaign_code'] ?? ''),
            'subject' => (string) ($saved_row['subject'] ?? $subject),
            'name' => (string) ($saved_row['name'] ?? $name),
            'status' => (string) ($saved_row['status'] ?? 'queued'),
            'queued_at' => (string) ($saved_row['queued_at'] ?? $now),
            'sent_at' => (string) ($saved_row['sent_at'] ?? ''),
            'updated_at' => (string) ($saved_row['updated_at'] ?? $now),
            'total_recipients' => (int) ($saved_row['total_recipients'] ?? count($eligible)),
            'sent_count' => (int) ($saved_row['sent_count'] ?? 0),
            'list_ids' => $list_ids,
            'list_names' => array_values(array_map(static fn(array $row): string => (string) ($row['name'] ?? ''), $list_rows)),
        ],
    ]);
});


metis_ajax_register_handler( 'metis_newsletter_doc_preview', function () {
    metis_newsletter_ajax_verify_nonce();
    if (!metis_newsletter_can_view()) metis_runtime_send_json_error('Unauthorized', 403);

    try {
        $doc_json_raw = (string) metis_runtime_unslash(metis_request_post()['doc_json'] ?? '');
        $compiled = metis_newsletter_doc_compile($doc_json_raw);
        $contact_payload = [];
        $html_body = (string) ($compiled['html'] ?? '');
        try {
            $contact_payload = metis_newsletter_preview_contact_payload();
            $html_body = metis_newsletter_render_template($html_body, $contact_payload);
        } catch (Throwable $render_error) {
            error_log('[newsletter.preview.contact] ' . $render_error->getMessage());
        }
        $text_body = (string) ($compiled['text'] ?? '');
        $doc_json = (string) ($compiled['doc_json'] ?? '');

        metis_runtime_send_json_success([
            'html' => $html_body,
            'text' => $text_body,
            'doc_json' => $doc_json,
            'contact' => $contact_payload,
        ]);
    } catch (Throwable $e) {
        error_log('[newsletter.preview] ' . $e->getMessage());
        metis_runtime_send_json_error('Preview compile failed: ' . $e->getMessage(), 500);
    }
});

metis_ajax_register_handler( 'metis_newsletter_campaign_get', function () {
    metis_newsletter_ajax_verify_nonce();
    if (!metis_newsletter_can_view()) metis_runtime_send_json_error('Unauthorized', 403);
    metis_newsletter_ensure_schema();

    $campaign_code = metis_newsletter_sanitize_ref_code(metis_request_post()['campaign_code'] ?? (metis_request_post()['key'] ?? ''));
    $campaign_id = isset(metis_request_post()['campaign_id']) ? (int) metis_runtime_unslash(metis_request_post()['campaign_id']) : 0;
    if ($campaign_code === '' && $campaign_id < 1) {
        metis_runtime_send_json_error('Campaign reference is required.', 400);
    }

    $row = CampaignService::get($campaign_id, $campaign_code);
    if (!$row) {
        metis_runtime_send_json_error('Campaign not found.', 404);
    }

    $campaign_id = (int) ($row['id'] ?? 0);
    $list_ids = CampaignService::listIds($campaign_id);

    $doc = metis_newsletter_doc_parse((string) ($row['doc_json'] ?? ''));
    $settings = isset($doc['settings']) && is_array($doc['settings']) ? $doc['settings'] : [];
    $editor_body_html = (string) ($row['editor_body_html'] ?? '');
    if ($editor_body_html === '') {
        $editor_body_html = (string) ($settings['body_html'] ?? '');
    }
    if ($editor_body_html === '') {
        $editor_body_html = metis_newsletter_extract_region_html((string) ($row['html_body'] ?? ''), 'body');
    }

    metis_runtime_send_json_success([
        'campaign' => [
            'id' => $campaign_id,
            'campaign_code' => (string) ($row['campaign_code'] ?? ''),
            'campaign_type' => CampaignService::normalizeType((string) ($row['campaign_type'] ?? 'campaign')),
            'template_id' => (int) ($row['template_id'] ?? 0),
            'template_code' => (string) ($row['template_code'] ?? ''),
            'name' => (string) ($row['name'] ?? ''),
            'subject' => (string) ($row['subject'] ?? ''),
            'from_name' => (string) ($row['from_name'] ?? ''),
            'from_email' => (string) ($row['from_email'] ?? ''),
            'sender_name' => (string) ($row['from_name'] ?? ''),
            'sender_email' => (string) ($row['from_email'] ?? ''),
            'reply_to' => (string) ($row['reply_to'] ?? ''),
            'preheader' => (string) ($row['preheader'] ?? ''),
            'preview_text' => (string) ($row['preheader'] ?? ''),
            'doc_json' => metis_newsletter_normalize_campaign_doc_json((string) ($row['doc_json'] ?? ''), $editor_body_html),
            'html_body' => (string) ($row['html_body'] ?? ''),
            'editor_body_html' => $editor_body_html,
            'text_body' => (string) ($row['text_body'] ?? ''),
            'status' => (string) ($row['status'] ?? 'draft'),
            'scheduled_at' => (string) ($row['scheduled_at'] ?? ''),
            'audience_json' => (string) ($row['audience_json'] ?? ''),
            'attachments_json' => (string) ($row['attachments_json'] ?? ''),
            'updated_at' => (string) ($row['updated_at'] ?? ''),
            'list_ids' => $list_ids,
        ],
    ]);
});

metis_ajax_register_handler( 'metis_newsletter_queue_campaign', function () {
    metis_newsletter_ajax_verify_nonce();
    if (!metis_newsletter_can_manage()) metis_runtime_send_json_error('Unauthorized', 403);
    metis_newsletter_ensure_schema();

    $campaign_id = isset(metis_request_post()['campaign_id']) ? (int) metis_runtime_unslash(metis_request_post()['campaign_id']) : 0;
    if ($campaign_id < 1) metis_runtime_send_json_error('Invalid campaign.', 400);

    $result = metis_newsletter_queue_campaign_messages($campaign_id);
    if (empty($result['ok'])) {
        metis_runtime_send_json_error('Failed to queue campaign.', 500);
    }

    metis_portal_dashboard_forget_all();
    metis_runtime_send_json_success([
        'queued' => (int) ($result['queued'] ?? 0),
        'campaign_id' => $campaign_id,
    ]);
});

metis_ajax_register_handler( 'metis_newsletter_test_send_campaign', function () {
    metis_newsletter_ajax_verify_nonce();
    if (!metis_newsletter_can_manage()) metis_runtime_send_json_error('Unauthorized', 403);
    metis_newsletter_ensure_schema();

    $campaign_id = isset(metis_request_post()['campaign_id']) ? (int) metis_runtime_unslash(metis_request_post()['campaign_id']) : 0;
    $test_email = metis_email_clean(metis_runtime_unslash(metis_request_post()['test_email'] ?? ''));
    $override_from_name = metis_text_clean(metis_runtime_unslash(metis_request_post()['from_name'] ?? ''));
    $override_from_email = metis_email_clean(metis_runtime_unslash(metis_request_post()['from_email'] ?? ''));
    $override_reply_to = metis_email_clean(metis_runtime_unslash(metis_request_post()['reply_to'] ?? ''));
    if ($campaign_id < 1 || !metis_email_is_valid($test_email)) metis_runtime_send_json_error('Campaign and valid test email are required.', 400);

    $campaign = CampaignService::rawById($campaign_id);
    if (!$campaign) metis_runtime_send_json_error('Campaign not found.', 404);

    $doc_json = metis_newsletter_normalize_campaign_doc_json(
        (string) ($campaign['doc_json'] ?? ''),
        array_key_exists('editor_body_html', $campaign) ? (string) ($campaign['editor_body_html'] ?? '') : null
    );
    $html_body = (string) ($campaign['html_body'] ?? '');
    $text_body = (string) ($campaign['text_body'] ?? '');
    if ($doc_json !== '') {
        $compiled = metis_newsletter_doc_compile($doc_json);
        $html_body = (string) ($compiled['html'] ?? $html_body);
        $text_body = (string) ($compiled['text'] ?? $text_body);
    }
    $html_body = metis_newsletter_ensure_email_container($html_body);
    $preview_contact = metis_newsletter_preview_contact_payload();
    $contact_ref = trim((string) ($preview_contact['contact_cid'] ?? ''));
    if ($contact_ref === '') {
        $contact_ref = (string) ((int) ($preview_contact['contact_id'] ?? 0));
    }
    $list_ref = CampaignService::firstListRef($campaign_id);
    $newsletter_ref = trim((string) ($campaign['campaign_code'] ?? ''));
    if ($newsletter_ref === '') {
        $newsletter_ref = (string) $campaign_id;
    }
    $contact_payload = [
        'contact_id' => (int) ($preview_contact['contact_id'] ?? 0),
        'contact_cid' => (string) ($preview_contact['contact_cid'] ?? ''),
        'first_name' => 'Test',
        'last_name' => 'Recipient',
        'email' => $test_email
    ];
    $contact_payload['unsubscribe_url'] = metis_newsletter_public_unsubscribe_url($contact_ref, $list_ref);
    $contact_payload['manage_subscription_url'] = metis_newsletter_public_manage_url($contact_ref);
    $contact_payload['view_online_url'] = metis_newsletter_public_view_url($newsletter_ref);
    $contact_payload['view_newsletter_url'] = metis_newsletter_public_view_url($newsletter_ref);
    $rendered_html = metis_newsletter_render_template($html_body, $contact_payload);
    $rendered_html = str_replace(
        ['{{unsubscribe_url}}', '{{manage_subscription_url}}', '{{view_online_url}}', '{{view_newsletter_url}}'],
        [
            metis_newsletter_public_unsubscribe_url($contact_ref, $list_ref),
            metis_newsletter_public_manage_url($contact_ref),
            metis_newsletter_public_view_url($newsletter_ref),
            metis_newsletter_public_view_url($newsletter_ref),
        ],
        $rendered_html
    );
    $subject = '[TEST] ' . (string) ($campaign['subject'] ?? 'Newsletter');
    $attachments = [];
    $attachment_raw = (string) ($campaign['attachments_json'] ?? '');
    if ($attachment_raw !== '') {
        $decoded_attachments = json_decode($attachment_raw, true);
        if (is_array($decoded_attachments)) {
            foreach ($decoded_attachments as $att) {
                if (!is_array($att)) continue;
                $path = (string) ($att['path'] ?? '');
                $url = (string) ($att['url'] ?? '');
                $name = metis_text_clean((string) ($att['name'] ?? ''));
                $mime = metis_text_clean((string) ($att['mime'] ?? 'application/octet-stream'));
                if ($path !== '' && file_exists($path)) {
                    $attachments[] = ['path' => $path, 'name' => $name !== '' ? $name : basename($path), 'mime' => $mime];
                } elseif ($url !== '') {
                    $attachments[] = ['url' => metis_url_clean($url), 'name' => $name !== '' ? $name : basename(parse_url($url, PHP_URL_PATH) ?: 'attachment'), 'mime' => $mime];
                }
            }
        }
    }

    $send = \Metis\Core\Services\EmailService::sendHtml($test_email, $subject, $rendered_html !== '' ? $rendered_html : '<p>' . metis_escape_html($text_body) . '</p>', [
        'from_name' => $override_from_name !== '' ? $override_from_name : (string) ($campaign['from_name'] ?? ''),
        'from_email' => metis_email_is_valid($override_from_email) ? $override_from_email : (string) ($campaign['from_email'] ?? ''),
        'reply_to' => metis_email_is_valid($override_reply_to) ? $override_reply_to : (string) ($campaign['reply_to'] ?? ''),
        'attachments' => $attachments,
        'module' => 'newsletter',
    ]);
    if (empty($send['ok'])) {
        metis_runtime_send_json_error('Test send failed.', 500);
    }
    metis_db()->update(Metis_Tables::get('newsletter_campaigns'), ['status' => 'test_ready', 'test_sent_at' => metis_current_time('mysql'), 'updated_at' => metis_current_time('mysql')], ['id' => $campaign_id], ['%s', '%s', '%s'], ['%d']);
    metis_newsletter_audit_log('campaign_test_sent', 'campaign', $campaign_id, ['test_email' => $test_email]);
    metis_portal_dashboard_forget_all();
    metis_portal_dashboard_forget_all();
    metis_runtime_send_json_success(['campaign_id' => $campaign_id]);
});

metis_ajax_register_handler( 'metis_newsletter_archive_campaign', function () {
    metis_newsletter_ajax_verify_nonce();
    if (!metis_newsletter_can_manage()) metis_runtime_send_json_error('Unauthorized', 403);
    metis_newsletter_ensure_schema();

    $campaign_id = isset(metis_request_post()['campaign_id']) ? (int) metis_runtime_unslash(metis_request_post()['campaign_id']) : 0;
    if ($campaign_id < 1) metis_runtime_send_json_error('Invalid campaign.', 400);
    if (!CampaignService::archive($campaign_id)) metis_runtime_send_json_error('Unable to archive campaign.', 500);
    metis_newsletter_audit_log('campaign_archived', 'campaign', $campaign_id);
    metis_portal_dashboard_forget_all();
    metis_runtime_send_json_success(['campaign_id' => $campaign_id]);
});

metis_ajax_register_handler( 'metis_newsletter_reassign_import_lists', function () {
    metis_newsletter_ajax_verify_nonce();
    if (!metis_newsletter_can_manage()) metis_runtime_send_json_error('Unauthorized', 403);
    metis_newsletter_ensure_schema();

    $campaign_id = isset(metis_request_post()['campaign_id']) ? (int) metis_runtime_unslash(metis_request_post()['campaign_id']) : 0;
    if ($campaign_id < 1) metis_runtime_send_json_error('Invalid campaign.', 400);

    $campaign = CampaignService::rawById($campaign_id);
    if (!$campaign) metis_runtime_send_json_error('Campaign not found.', 404);
    if (!CampaignService::isWordPressArchiveImport($campaign)) {
        metis_runtime_send_json_error('Only imported archive newsletters can be reassigned.', 400);
    }

    $list_ids = [];
    if (isset(metis_request_post()['list_ids'])) {
        $decoded = json_decode((string) metis_runtime_unslash(metis_request_post()['list_ids']), true);
        if (is_array($decoded)) {
            $list_ids = array_values(array_unique(array_map('intval', $decoded)));
        }
    }

    $result = CampaignService::replaceListIds($campaign_id, $list_ids);
    if (empty($result['success'])) metis_runtime_send_json_error('Unable to update campaign lists.', 500);

    metis_newsletter_audit_log('campaign_import_lists_reassigned', 'campaign', $campaign_id, ['list_ids' => $result['list_ids'] ?? []]);
    metis_portal_dashboard_forget_all();
    metis_runtime_send_json_success([
        'campaign_id' => $campaign_id,
        'list_ids' => is_array($result['list_ids'] ?? null) ? array_values(array_map('intval', $result['list_ids'])) : [],
    ]);
});

metis_ajax_register_handler( 'metis_newsletter_delete_campaign', function () {
    metis_newsletter_ajax_verify_nonce();
    if (!metis_newsletter_can_manage()) metis_runtime_send_json_error('Unauthorized', 403);
    metis_newsletter_ensure_schema();

    $campaign_id = isset(metis_request_post()['campaign_id']) ? (int) metis_runtime_unslash(metis_request_post()['campaign_id']) : 0;
    if ($campaign_id < 1) metis_runtime_send_json_error('Invalid campaign.', 400);
    $result = CampaignService::delete($campaign_id);
    if (empty($result['success'])) metis_runtime_send_json_error((string) ($result['message'] ?? 'Unable to delete campaign.'), (int) ($result['status'] ?? 500));

    metis_newsletter_audit_log('campaign_deleted', 'campaign', $campaign_id, ['status' => (string) ($result['campaign_status'] ?? '')]);
    metis_runtime_send_json_success(['campaign_id' => $campaign_id]);
});

metis_ajax_register_handler( 'metis_newsletter_campaign_status', function () {
    metis_newsletter_ajax_verify_nonce();
    if (!metis_newsletter_can_view()) metis_runtime_send_json_error('Unauthorized', 403);
    metis_newsletter_ensure_schema();

    $campaign_id = isset(metis_request_post()['campaign_id']) ? (int) metis_runtime_unslash(metis_request_post()['campaign_id']) : 0;
    if ($campaign_id < 1) metis_runtime_send_json_error('Invalid campaign.', 400);
    $status_payload = CampaignService::status($campaign_id);
    if (!$status_payload) metis_runtime_send_json_error('Campaign not found.', 404);
    metis_runtime_send_json_success($status_payload);
});

metis_ajax_register_handler( 'metis_newsletter_search_contacts', function () {
    metis_newsletter_ajax_verify_nonce();
    if (!metis_newsletter_can_view()) metis_runtime_send_json_error('Unauthorized', 403);
    metis_newsletter_ensure_schema();

    $query = metis_text_clean((string) metis_runtime_unslash(metis_request_post()['query'] ?? ''));
    $query = trim($query);
    if ($query === '') metis_runtime_send_json_success(['results' => []]);

    metis_runtime_send_json_success(['results' => ContactService::searchContacts($query)]);
});

metis_ajax_register_handler( 'metis_newsletter_list_contacts', function () {
    metis_newsletter_ajax_verify_nonce();
    if (!metis_newsletter_can_view()) metis_runtime_send_json_error('Unauthorized', 403);
    metis_newsletter_ensure_schema();

    $list_id = isset(metis_request_post()['list_id']) ? (int) metis_runtime_unslash(metis_request_post()['list_id']) : 0;
    $query = metis_text_clean((string) metis_runtime_unslash(metis_request_post()['query'] ?? ''));
    $page = isset(metis_request_post()['page']) ? max(1, (int) metis_runtime_unslash(metis_request_post()['page'])) : 1;
    $per_page = isset(metis_request_post()['per_page']) ? max(1, min(100, (int) metis_runtime_unslash(metis_request_post()['per_page']))) : 25;

    metis_runtime_send_json_success(
        ContactService::contactsForListPicker($list_id, $query, $page, $per_page)
    );
});

metis_ajax_register_handler( 'metis_newsletter_run_queue', function () {
    metis_newsletter_ajax_verify_nonce();
    if (!metis_newsletter_can_manage()) metis_runtime_send_json_error('Unauthorized', 403);
    metis_newsletter_ensure_schema();

    $limit = isset(metis_request_post()['limit']) ? (int) metis_runtime_unslash(metis_request_post()['limit']) : 100;
    $result = metis_newsletter_process_queue($limit);

    metis_portal_dashboard_forget_all();
    metis_runtime_send_json_success($result);
});


function metis_newsletter_upsert_subscription_record( array $input ): array {
    return SubscriptionService::upsert($input);
}

metis_ajax_register_handler( 'metis_newsletter_upsert_subscription', function () {
    metis_newsletter_ajax_verify_nonce();
    if (!metis_newsletter_can_manage()) metis_runtime_send_json_error('Unauthorized', 403);

    $result = metis_newsletter_upsert_subscription_record([
        'email' => (string) metis_runtime_unslash(metis_request_post()['email'] ?? ''),
        'first_name' => (string) metis_runtime_unslash(metis_request_post()['first_name'] ?? ''),
        'last_name' => (string) metis_runtime_unslash(metis_request_post()['last_name'] ?? ''),
        'list_id' => (int) metis_runtime_unslash(metis_request_post()['list_id'] ?? 0),
        'status' => (string) metis_runtime_unslash(metis_request_post()['status'] ?? 'subscribed'),
    ]);

    if (empty($result['success'])) {
        metis_runtime_send_json_error('Failed to save subscriber.', metis_newsletter_error_status($result['status'] ?? 500));
    }

    metis_portal_dashboard_forget_all();
    metis_runtime_send_json_success([
        'contact_id' => (int) ($result['contact_id'] ?? 0),
        'list_id' => (int) ($result['list_id'] ?? 0),
        'status' => (string) ($result['status_value'] ?? 'subscribed'),
    ]);
});

metis_ajax_register_handler( 'metis_newsletter_bulk_add_contacts_to_list', function () {
    metis_newsletter_ajax_verify_nonce();
    if (!metis_newsletter_can_manage()) metis_runtime_send_json_error('Unauthorized', 403);
    metis_newsletter_ensure_schema();

    $list_id = isset(metis_request_post()['list_id']) ? (int) metis_runtime_unslash(metis_request_post()['list_id']) : 0;
    $decoded = json_decode((string) metis_runtime_unslash(metis_request_post()['contact_ids'] ?? '[]'), true);
    $contact_ids = is_array($decoded) ? array_values(array_unique(array_map('intval', $decoded))) : [];

    $result = SubscriptionService::bulkSubscribeExistingContacts($list_id, $contact_ids);
    if (empty($result['success'])) {
        metis_runtime_send_json_error(
            (string) ($result['message'] ?? 'Failed to add contacts to list.'),
            metis_newsletter_error_status($result['status'] ?? 500)
        );
    }

    metis_portal_dashboard_forget_all();
    metis_runtime_send_json_success([
        'list_id' => (int) ($result['list_id'] ?? $list_id),
        'processed_count' => (int) ($result['processed_count'] ?? 0),
        'skipped_count' => (int) ($result['skipped_count'] ?? 0),
    ]);
});

metis_ajax_register_handler( 'metis_newsletter_record_event', function () {
    metis_newsletter_ajax_verify_nonce();
    if (!metis_newsletter_can_manage()) metis_runtime_send_json_error('Unauthorized', 403);
    metis_newsletter_ensure_schema();

    $message_id = isset(metis_request_post()['message_id']) ? (int) metis_runtime_unslash(metis_request_post()['message_id']) : 0;
    $message_code = metis_text_clean((string) metis_runtime_unslash(metis_request_post()['message_code'] ?? ''));
    $event_type = metis_key_clean(metis_runtime_unslash(metis_request_post()['event_type'] ?? ''));
    $reason = metis_text_clean(metis_runtime_unslash(metis_request_post()['reason'] ?? ''));
    if ($event_type === '') metis_runtime_send_json_error('Event type required.', 400);

    if ($message_code === '' && $message_id > 0) {
        $message_code = \Metis\Modules\Newsletter\QueueService::messageCodeById($message_id);
    }
    if ($message_code === '') metis_runtime_send_json_error('Message reference required.', 400);

    $ok = metis_newsletter_track_event_for_message($message_code, $event_type, $reason);
    if (!$ok) metis_runtime_send_json_error('Failed to record event.', 500);
    metis_portal_dashboard_forget_all();
    metis_runtime_send_json_success(['recorded' => true, 'message_code' => $message_code]);
});

metis_ajax_register_handler( 'metis_newsletter_sync_google_usage', function () {
    metis_newsletter_ajax_verify_nonce();
    if (!metis_newsletter_can_manage()) metis_runtime_send_json_error('Unauthorized', 403);
    metis_newsletter_ensure_schema();

    $date_input = isset(metis_request_post()['usage_date']) ? metis_text_clean(metis_runtime_unslash(metis_request_post()['usage_date'])) : '';
    $date_ymd = '';
    if ($date_input !== '') {
        $dt = DateTimeImmutable::createFromFormat('Y-m-d', $date_input);
        if ($dt instanceof DateTimeImmutable) {
            $date_ymd = $dt->format('Y-m-d');
        }
    }

    $result = metis_newsletter_google_sync_usage_for_date($date_ymd);
    if (empty($result['ok'])) {
        metis_runtime_send_json_error('Failed to sync Google usage.', 500);
    }

    metis_portal_dashboard_forget_all();
    metis_runtime_send_json_success([
        'date' => (string) ($result['date'] ?? ''),
        'imported' => (int) ($result['imported'] ?? 0),
    ]);
});

// Backward-compatible GIF search endpoint, now wired to Klipy.
$metis_newsletter_klipy_search_handler = static function () {
    metis_newsletter_ajax_verify_nonce();
    if (!metis_newsletter_can_view()) metis_runtime_send_json_error('Unauthorized', 403);

    $query = metis_text_clean((string) metis_runtime_unslash(metis_request_post()['query'] ?? ''));
    $query = trim($query);
    if ($query === '' || strlen($query) < 2) {
        metis_runtime_send_json_error('Search query must be at least 2 characters.', 400);
    }

    $api_key = trim((string) \Metis\Core\Services\CredentialService::getBySetting('newsletter_klipy_api_key'));
    $search_url = metis_newsletter_normalize_klipy_search_url((string) Core_Settings_Service::get('newsletter_klipy_search_url', metis_newsletter_default_klipy_search_url()));

    if ($search_url === '') {
        metis_runtime_send_json_error('Klipy search endpoint is not configured securely. Use Settings > Organization > Email to reset it.', 400);
    }
    $sep = strpos($search_url, '?') === false ? '?' : '&';
    $url = $search_url . $sep . 'limit=18&q=' . rawurlencode($query);
    if ($api_key !== '') {
        $url .= '&api_key=' . rawurlencode($api_key);
    }
    $response = metis_runtime_remote_get($url, [
        'timeout' => 12,
        'headers' => ['Accept' => 'application/json'],
    ]);
    if (metis_runtime_is_error($response)) {
        metis_runtime_send_json_error('Klipy request failed.', 500);
    }

    $code = (int) metis_runtime_remote_retrieve_response_code($response);
    $body = (string) metis_runtime_remote_retrieve_body($response);
    $data = json_decode($body, true);
    if ($code < 200 || $code > 299 || !is_array($data)) {
        metis_runtime_send_json_error('Klipy API returned an error.', 500);
    }

    $items = [];
    $rows = [];
    if (isset($data['data']) && is_array($data['data'])) {
        $rows = $data['data'];
    } elseif (isset($data['results']) && is_array($data['results'])) {
        $rows = $data['results'];
    } elseif (isset($data['items']) && is_array($data['items'])) {
        $rows = $data['items'];
    }
    foreach ((array) $rows as $row) {
        if (!is_array($row)) continue;
        $images = (array) ($row['images'] ?? []);
        $original = (string) (($images['original']['url'] ?? $row['url'] ?? ''));
        $preview = (string) (($images['fixed_height_small']['url'] ?? ($images['fixed_width_small']['url'] ?? ($row['preview_url'] ?? ''))));
        if ($original === '' || $preview === '') continue;
        $items[] = [
            'images' => [
                'original' => ['url' => metis_url_clean($original)],
                'fixed_height_small' => ['url' => metis_url_clean($preview)],
            ],
        ];
    }

    metis_runtime_send_json_success(['items' => $items]);
};
metis_ajax_register_handler( 'metis_newsletter_klipy_search', $metis_newsletter_klipy_search_handler);
metis_ajax_register_handler( 'metis_newsletter_giphy_search', $metis_newsletter_klipy_search_handler);

metis_ajax_register_handler( 'metis_newsletter_upload_image', function () {
    metis_newsletter_ajax_verify_nonce();
    if (!metis_newsletter_can_manage()) metis_runtime_send_json_error('Unauthorized', 403);
    metis_newsletter_ensure_schema();

    if (empty(metis_request_files()['image']) || !is_array(metis_request_files()['image'])) {
        metis_runtime_send_json_error('Image file is required.', 400);
    }
    $file = metis_request_files()['image'];
    if (!isset($file['tmp_name']) || (string) $file['tmp_name'] === '') {
        metis_runtime_send_json_error('Image upload is invalid.', 400);
    }
    $size = isset($file['size']) ? (int) $file['size'] : 0;
    if ($size < 1) {
        metis_runtime_send_json_error('Image upload is empty.', 400);
    }
    if ($size > 8 * 1024 * 1024) {
        metis_runtime_send_json_error('Image must be 8MB or smaller.', 400);
    }

    $allowed_mimes = [
        'jpg|jpeg|jpe' => 'image/jpeg',
        'png' => 'image/png',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
    ];

    $uploaded = metis_handle_upload($file, [
        'policy' => 'newsletter_media',
        'test_form' => false,
        'mimes' => $allowed_mimes,
    ]);
    if (!is_array($uploaded) || !empty($uploaded['error'])) {
        metis_runtime_send_json_error('Failed to upload image.', 500);
    }

    $url = isset($uploaded['url']) ? metis_url_clean((string) $uploaded['url']) : '';
    $path = isset($uploaded['file']) ? (string) $uploaded['file'] : '';
    if ($url === '' || $path === '') {
        metis_runtime_send_json_error('Image URL unavailable.', 500);
    }

    $filename = metis_filename_clean((string) ($file['name'] ?? basename($path)));
    $mime_type = (string) (metis_check_filetype($filename, $allowed_mimes)['type'] ?? '');
    if ($mime_type === '') $mime_type = 'application/octet-stream';
    metis_newsletter_audit_log('image_uploaded', 'asset', 0, [
        'filename' => $filename,
        'mime' => $mime_type,
        'size' => $size,
        'url' => $url,
    ]);

    metis_runtime_send_json_success([
        'url' => $url,
        'filename' => $filename,
        'mime' => $mime_type,
        'size' => $size,
    ]);
});

metis_ajax_register_handler( 'metis_newsletter_upload_attachment', function () {
    metis_newsletter_ajax_verify_nonce();
    if (!metis_newsletter_can_manage()) metis_runtime_send_json_error('Unauthorized', 403);
    metis_newsletter_ensure_schema();

    if (empty(metis_request_files()['attachment']) || !is_array(metis_request_files()['attachment'])) {
        metis_runtime_send_json_error('Attachment file is required.', 400);
    }
    $file = metis_request_files()['attachment'];
    if (!isset($file['tmp_name']) || (string) $file['tmp_name'] === '') {
        metis_runtime_send_json_error('Attachment upload is invalid.', 400);
    }
    $size = isset($file['size']) ? (int) $file['size'] : 0;
    if ($size < 1) metis_runtime_send_json_error('Attachment upload is empty.', 400);
    if ($size > 15 * 1024 * 1024) metis_runtime_send_json_error('Attachment must be 15MB or smaller.', 400);

    $allowed_mimes = [
        'pdf' => 'application/pdf',
        'txt' => 'text/plain',
        'csv' => 'text/csv',
        'doc' => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xls' => 'application/vnd.ms-excel',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'ppt' => 'application/vnd.ms-powerpoint',
        'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'zip' => 'application/zip',
        'jpg|jpeg|jpe' => 'image/jpeg',
        'png' => 'image/png',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
    ];

    $uploaded = metis_handle_upload($file, [
        'policy' => 'attachments',
        'test_form' => false,
        'mimes' => $allowed_mimes,
    ]);
    if (!is_array($uploaded) || !empty($uploaded['error'])) {
        metis_runtime_send_json_error('Failed to upload attachment.', 500);
    }

    $url = isset($uploaded['url']) ? metis_url_clean((string) $uploaded['url']) : '';
    $path = isset($uploaded['file']) ? (string) $uploaded['file'] : '';
    if ($url === '' || $path === '') metis_runtime_send_json_error('Attachment URL unavailable.', 500);

    $filename = metis_filename_clean((string) ($file['name'] ?? basename($path)));
    $mime_type = (string) (metis_check_filetype($filename, $allowed_mimes)['type'] ?? '');
    if ($mime_type === '') $mime_type = 'application/octet-stream';

    metis_newsletter_audit_log('attachment_uploaded', 'asset', 0, [
        'filename' => $filename,
        'mime' => $mime_type,
        'size' => $size,
        'url' => $url,
    ]);

    metis_runtime_send_json_success([
        'url' => $url,
        'path' => $path,
        'name' => $filename,
        'mime' => $mime_type,
        'size' => $size,
    ]);
});
