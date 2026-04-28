<?php
if (!defined('METIS_ROOT')) exit;

require_once dirname( __DIR__, 2 ) . '/portal/views/_dashboard_data.php';

function metis_newsletter_ajax_verify_nonce(): void {
    $nonce = isset($_POST['nonce']) ? metis_text_clean(metis_runtime_unslash($_POST['nonce'])) : '';
    $action_nonce = isset($_POST['metis_action_nonce']) ? metis_text_clean(metis_runtime_unslash($_POST['metis_action_nonce'])) : '';
    $action = isset($_POST['action']) ? metis_key_clean(metis_runtime_unslash($_POST['action'])) : '';

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
    $db = metis_db();

    $contacts_table = Metis_Tables::get('contacts');
    $email = strtolower(trim($email));
    if ($email === '') return 0;

    $contact_id = (int) $db->scalar("SELECT id FROM {$contacts_table} WHERE email = %s LIMIT 1", [$email]);
    if ($contact_id > 0) return $contact_id;

    $payload = [
        'email' => $email,
        'first_name' => $first_name,
        'last_name' => $last_name,
    ];
    $format = ['%s', '%s', '%s'];

    if (function_exists('metis_entity_id_service')) {
        $payload = metis_entity_id_service()->assignForInsert('contact', $payload);
        $format[] = '%s';
        $format[] = '%s';
    } elseif (metis_newsletter_column_exists($contacts_table, 'cid')) {
        $payload['cid'] = metis_generate_code('CN', $contacts_table, 'cid');
        $format[] = '%s';
    }

    $ok = $db->insert($contacts_table, $payload, $format);
    if ($ok === false) return 0;
    $contact_id = $db->lastInsertId();
    if ($contact_id > 0 && function_exists('metis_entity_id_service')) {
        metis_entity_id_service()->register('contact', $contact_id, (string) ($payload['contact_uid'] ?? $payload['cid'] ?? ''));
    }
    return $contact_id;
}

function metis_newsletter_preview_contact_payload(): array {
    $user = function_exists('metis_runtime_current_user') ? metis_runtime_current_user() : null;
    $email = strtolower(trim((string) (($user->user_email ?? '') ?: '')));
    $first_name = trim((string) (($user->first_name ?? '') ?: ''));
    $last_name = trim((string) (($user->last_name ?? '') ?: ''));
    $display_name = trim((string) (($user->display_name ?? '') ?: ''));

    $contact = [
        'contact_id' => 0,
        'contact_cid' => '',
        'first_name' => $first_name,
        'last_name' => $last_name,
        'full_name' => trim($first_name . ' ' . $last_name),
        'name' => $first_name !== '' ? $first_name : ($display_name !== '' ? $display_name : trim($first_name . ' ' . $last_name)),
        'email' => $email,
        'city' => '',
        'state' => '',
    ];

    if ($email === '') {
        if ($contact['full_name'] === '') {
            $contact['full_name'] = $display_name;
        }
        return $contact;
    }

    $db = metis_db();
    $contacts_table = Metis_Tables::get('contacts');
    $details_table = Metis_Tables::get('contact_details');

    $contact_row = metis_newsletter_table_exists($contacts_table)
        ? $db->fetchOne(
            "SELECT id, cid, first_name, last_name, email
             FROM {$contacts_table}
             WHERE LOWER(email) = %s
             LIMIT 1",
            [$email]
        )
        : null;

    if (is_array($contact_row) && !empty($contact_row)) {
        $contact['contact_id'] = (int) ($contact_row['id'] ?? 0);
        $contact['contact_cid'] = (string) ($contact_row['cid'] ?? '');
        $contact['first_name'] = trim((string) ($contact_row['first_name'] ?? $contact['first_name']));
        $contact['last_name'] = trim((string) ($contact_row['last_name'] ?? $contact['last_name']));
        $contact['email'] = strtolower(trim((string) ($contact_row['email'] ?? $contact['email'])));

        $detail_row = null;
        if (
            metis_newsletter_table_exists($details_table)
            && metis_newsletter_column_exists($details_table, 'city')
            && metis_newsletter_column_exists($details_table, 'state')
        ) {
            $detail_where = [];
            $detail_args = [];
            if (metis_newsletter_column_exists($details_table, 'contact_id')) {
                $detail_where[] = 'contact_id = %d';
                $detail_args[] = (int) ($contact_row['id'] ?? 0);
            }
            if (metis_newsletter_column_exists($details_table, 'contact_cid')) {
                $detail_where[] = 'contact_cid = %s';
                $detail_args[] = (string) ($contact_row['cid'] ?? '');
            }
            if (!empty($detail_where)) {
                $detail_row = $db->fetchOne(
                    "SELECT city, state
                     FROM {$details_table}
                     WHERE " . implode(' OR ', $detail_where) . "
                     ORDER BY id DESC
                     LIMIT 1",
                    $detail_args
                );
            }
        }
        if (is_array($detail_row) && !empty($detail_row)) {
            $contact['city'] = trim((string) ($detail_row['city'] ?? ''));
            $contact['state'] = trim((string) ($detail_row['state'] ?? ''));
        }
    }

    $contact['full_name'] = trim($contact['first_name'] . ' ' . $contact['last_name']);
    if ($contact['full_name'] === '') {
        $contact['full_name'] = $display_name;
    }
    $contact['name'] = $contact['first_name'] !== '' ? $contact['first_name'] : ($contact['full_name'] !== '' ? $contact['full_name'] : $display_name);

    return $contact;
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
        'metis_newsletter_save_campaign' => 'edit',
        'metis_newsletter_campaign_get' => 'view',
        'metis_newsletter_queue_campaign' => 'edit',
        'metis_newsletter_test_send_campaign' => 'edit',
        'metis_newsletter_archive_campaign' => 'edit',
        'metis_newsletter_delete_campaign' => 'delete',
        'metis_newsletter_campaign_status' => 'view',
        'metis_newsletter_search_contacts' => 'view',
        'metis_newsletter_run_queue' => 'edit',
        'metis_newsletter_upsert_subscription' => 'edit',
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

    $from_name = metis_text_clean(metis_runtime_unslash($_POST['from_name'] ?? ($_POST['sender_name'] ?? '')));
    $from_email = metis_email_clean(metis_runtime_unslash($_POST['from_email'] ?? ($_POST['sender_email'] ?? '')));
    $reply_to = metis_email_clean(metis_runtime_unslash($_POST['reply_to'] ?? ''));

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

    $requested = isset($_POST['newsletter_layout_profile'])
        ? metis_key_clean((string) metis_runtime_unslash($_POST['newsletter_layout_profile']))
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

    $header_html = metis_newsletter_sanitize_theme_html((string) ($_POST['header_html'] ?? ''));
    $personalized_html = metis_newsletter_sanitize_theme_html((string) ($_POST['personalized_html'] ?? ''));
    $closing_html = metis_newsletter_sanitize_theme_html((string) ($_POST['closing_html'] ?? ''));
    $footer_html = metis_newsletter_sanitize_theme_html((string) ($_POST['footer_html'] ?? ''));
    $canvas_bg = metis_text_clean((string) metis_runtime_unslash($_POST['canvas_bg'] ?? 'transparent')) ?: 'transparent';
    $text_color = metis_text_clean((string) metis_runtime_unslash($_POST['text_color'] ?? 'text')) ?: 'text';
    $font_size = max(10, min(28, (int) metis_runtime_unslash($_POST['font_size'] ?? 16)));
    $content_width = max(520, min(820, (int) metis_runtime_unslash($_POST['content_width'] ?? 680)));
    $content_width_mode = metis_key_clean((string) metis_runtime_unslash($_POST['content_width_mode'] ?? ''));
    $divider_color = metis_text_clean((string) metis_runtime_unslash($_POST['divider_color'] ?? 'border')) ?: 'border';
    $divider_style = metis_key_clean((string) metis_runtime_unslash($_POST['divider_style'] ?? 'solid'));
    $divider_weight = max(1, min(6, (int) metis_runtime_unslash($_POST['divider_weight'] ?? 1)));
    if ($content_width_mode === '') { $content_width_mode = $content_width <= 600 ? 'narrow' : ($content_width >= 740 ? 'wide' : 'normal'); }
    if (!in_array($content_width_mode, ['narrow','normal','wide'], true)) $content_width_mode = $content_width <= 600 ? 'narrow' : ($content_width >= 740 ? 'wide' : 'normal');
    if (!in_array($divider_style, ['solid', 'dashed', 'dotted', 'double'], true)) $divider_style = 'solid';
    $section_keys = ['header','personalized','closing','footer'];
    foreach ($section_keys as $section_key) {
        Core_Settings_Service::set('newsletter_theme_' . $section_key . '_bg', metis_text_clean((string) metis_runtime_unslash($_POST[$section_key . '_bg'] ?? 'transparent')) ?: 'transparent', true);
        Core_Settings_Service::set('newsletter_theme_' . $section_key . '_text_color', metis_text_clean((string) metis_runtime_unslash($_POST[$section_key . '_text_color'] ?? 'text')) ?: 'text', true);
        Core_Settings_Service::set('newsletter_theme_' . $section_key . '_padding', metis_text_clean((string) metis_runtime_unslash($_POST[$section_key . '_padding'] ?? '0 0 0 0')) ?: '0 0 0 0', true);
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

    $db = metis_db();
    $table = Metis_Tables::get('newsletter_templates');

    $template_code = metis_newsletter_sanitize_ref_code($_POST['template_code'] ?? '');
    $template_id = 0;
    if ($template_code !== '') {
        $template_id = (int) $db->scalar("SELECT id FROM {$table} WHERE template_code = %s LIMIT 1", [$template_code]);
        if ($template_id < 1) {
            metis_runtime_send_json_error('Template not found.', 404);
        }
    } else {
        $template_id = isset($_POST['template_id']) ? (int) metis_runtime_unslash($_POST['template_id']) : 0;
    }
    $name = metis_text_clean(metis_runtime_unslash($_POST['name'] ?? ''));
    $subject = metis_text_clean(metis_runtime_unslash($_POST['subject'] ?? ''));
    $from_name = metis_text_clean(metis_runtime_unslash($_POST['from_name'] ?? ($_POST['sender_name'] ?? '')));
    $from_email = metis_email_clean(metis_runtime_unslash($_POST['from_email'] ?? ($_POST['sender_email'] ?? '')));
    $reply_to = metis_email_clean(metis_runtime_unslash($_POST['reply_to'] ?? ''));
    $doc_json_raw = (string) metis_runtime_unslash($_POST['doc_json'] ?? '');
    $has_html_body = array_key_exists('html_body', $_POST);
    $raw_html_body = $has_html_body ? (string) metis_runtime_unslash($_POST['html_body']) : '';
    $html_body = $has_html_body ? metis_runtime_kses_post($raw_html_body) : '';
    $has_text_body = array_key_exists('text_body', $_POST);
    $text_body = $has_text_body ? metis_textarea_clean(metis_runtime_unslash($_POST['text_body'])) : '';

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
    $format = ['%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s'];

    if ($template_id > 0) {
        $ok = $db->update($table, $payload, ['id' => $template_id], $format, ['%d']);
        if ($ok === false) metis_runtime_send_json_error('Failed to update template.', 500);
    } else {
        if (function_exists('metis_entity_id_service')) {
            $payload = metis_entity_id_service()->assignForInsert('newsletter_template', $payload);
        } else {
            $payload['template_code'] = metis_generate_code('NT', $table, 'template_code');
        }
        $payload['created_by'] = metis_current_user_id() ?: null;
        $ok = $db->insert($table, $payload, ['%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d']);
        if ($ok === false) metis_runtime_send_json_error('Failed to create template.', 500);
        $template_id = $db->lastInsertId();
        if ($template_id > 0 && function_exists('metis_entity_id_service')) {
            metis_entity_id_service()->register('newsletter_template', $template_id, (string) ($payload['newsletter_template_uid'] ?? $payload['template_code'] ?? ''));
        }
    }

    $saved_template_code = (string) $db->scalar("SELECT template_code FROM {$table} WHERE id = %d LIMIT 1", [$template_id]);

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

    $db = metis_db();
    $table = Metis_Tables::get('newsletter_templates');

    $template_code = metis_newsletter_sanitize_ref_code($_POST['template_code'] ?? ($_POST['key'] ?? ''));
    $template_id = isset($_POST['template_id']) ? (int) metis_runtime_unslash($_POST['template_id']) : 0;
    if ($template_code === '' && $template_id < 1) {
        metis_runtime_send_json_error('Template reference is required.', 400);
    }

    if ($template_code !== '') {
        $row = $db->fetchOne(
            "SELECT id, template_code, name, subject, from_name, from_email, reply_to, doc_json, html_body, text_body, is_active, updated_at
             FROM {$table}
             WHERE template_code = %s
             LIMIT 1",
            [ $template_code ]
        );
    } else {
        $row = $db->fetchOne(
            "SELECT id, template_code, name, subject, from_name, from_email, reply_to, doc_json, html_body, text_body, is_active, updated_at
             FROM {$table}
             WHERE id = %d
             LIMIT 1",
            [ $template_id ]
        );
    }

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

    $list_id = isset($_POST['list_id']) ? (int) metis_runtime_unslash($_POST['list_id']) : 0;
    $name = metis_text_clean(metis_runtime_unslash($_POST['name'] ?? ''));
    $description = metis_textarea_clean(metis_runtime_unslash($_POST['description'] ?? ''));
    $is_active = isset($_POST['is_active']) ? (int) metis_runtime_unslash($_POST['is_active']) : 1;

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

metis_ajax_register_handler( 'metis_newsletter_save_campaign', function () {
    metis_newsletter_ajax_verify_nonce();
    if (!metis_newsletter_can_manage()) metis_runtime_send_json_error('Unauthorized', 403);
    metis_newsletter_ensure_schema();

    $db = metis_db();

    $campaigns_table = Metis_Tables::get('newsletter_campaigns');
    $campaign_lists_table = Metis_Tables::get('newsletter_campaign_lists');
    $templates_table = Metis_Tables::get('newsletter_templates');
    $lists_table = Metis_Tables::get('newsletter_lists');

    $campaign_code = metis_newsletter_sanitize_ref_code($_POST['campaign_code'] ?? '');
    $campaign_id = 0;
    if ($campaign_code !== '') {
        $campaign_id = (int) $db->scalar("SELECT id FROM {$campaigns_table} WHERE campaign_code = %s LIMIT 1", [$campaign_code]);
        if ($campaign_id < 1) {
            metis_runtime_send_json_error('Campaign not found.', 404);
        }
    } else {
        $campaign_id = isset($_POST['campaign_id']) ? (int) metis_runtime_unslash($_POST['campaign_id']) : 0;
    }
    $template_code = metis_newsletter_sanitize_ref_code($_POST['template_code'] ?? '');
    $template_id = 0;
    if ($template_code !== '') {
        $template_id = (int) $db->scalar("SELECT id FROM {$templates_table} WHERE template_code = %s LIMIT 1", [$template_code]);
        if ($template_id < 1) {
            metis_runtime_send_json_error('Template not found.', 404);
        }
    } else {
        $template_id = isset($_POST['template_id']) ? (int) metis_runtime_unslash($_POST['template_id']) : 0;
    }
    $name = metis_text_clean(metis_runtime_unslash($_POST['name'] ?? ''));
    $subject = metis_text_clean(metis_runtime_unslash($_POST['subject'] ?? ''));
    $from_name = metis_text_clean(metis_runtime_unslash($_POST['from_name'] ?? ($_POST['sender_name'] ?? '')));
    $from_email = metis_email_clean(metis_runtime_unslash($_POST['from_email'] ?? ($_POST['sender_email'] ?? '')));
    $reply_to = metis_email_clean(metis_runtime_unslash($_POST['reply_to'] ?? ''));
    $preheader = metis_text_clean(metis_runtime_unslash($_POST['preheader'] ?? ($_POST['preview_text'] ?? '')));
    $doc_json_raw = (string) metis_runtime_unslash($_POST['doc_json'] ?? '');
    $editor_body_field_present = array_key_exists('editor_body_html', $_POST);
    $editor_body_html = $editor_body_field_present
        ? metis_newsletter_sanitize_theme_html((string) metis_runtime_unslash($_POST['editor_body_html']))
        : '';
    $has_html_body = array_key_exists('html_body', $_POST);
    $html_body = $has_html_body ? metis_runtime_kses_post(metis_runtime_unslash($_POST['html_body'])) : '';
    $has_text_body = array_key_exists('text_body', $_POST);
    $text_body = $has_text_body ? metis_textarea_clean(metis_runtime_unslash($_POST['text_body'])) : '';
    $audience_json_raw = (string) metis_runtime_unslash($_POST['audience_json'] ?? '');
    $attachments_json_raw = (string) metis_runtime_unslash($_POST['attachments_json'] ?? '');
    $scheduled_at = metis_newsletter_parse_datetime_local((string) (metis_runtime_unslash($_POST['scheduled_at'] ?? '')));
    $status = metis_newsletter_sanitize_status((string) (metis_runtime_unslash($_POST['status'] ?? 'draft')));
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
    if (isset($_POST['list_ids'])) {
        $decoded = json_decode((string) metis_runtime_unslash($_POST['list_ids']), true);
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
        $valid_query = $db->prepare(
            'SELECT id FROM ' . $lists_table . ' WHERE id IN (' . implode(',', array_fill(0, count($list_ids), '%d')) . ')',
            ...$list_ids
        );
        $valid_ids = $db->column($valid_query);
        $list_ids = array_values(array_map('intval', $valid_ids));
    }

    $payload = [
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

    if ($campaign_id > 0) {
        $ok = $db->update(
            $campaigns_table,
            $payload,
            ['id' => $campaign_id],
            $payload_formats,
            ['%d']
        );
        if ($ok === false) metis_runtime_send_json_error('Failed to update campaign.', 500);
    } else {
        if (function_exists('metis_entity_id_service')) {
            $payload = metis_entity_id_service()->assignForInsert('newsletter_campaign', $payload);
        } else {
            $payload['campaign_code'] = metis_generate_code('NC', $campaigns_table, 'campaign_code');
        }
        $payload['created_by'] = metis_current_user_id() ?: null;
        $payload_formats = [];
        foreach (array_keys($payload) as $payload_key) {
            $payload_formats[] = $field_formats[$payload_key] ?? '%s';
        }
        $ok = $db->insert(
            $campaigns_table,
            $payload,
            $payload_formats
        );
        if ($ok === false) metis_runtime_send_json_error('Failed to create campaign.', 500);
        $campaign_id = $db->lastInsertId();
        if ($campaign_id > 0 && function_exists('metis_entity_id_service')) {
            metis_entity_id_service()->register('newsletter_campaign', $campaign_id, (string) ($payload['newsletter_campaign_uid'] ?? $payload['campaign_code'] ?? ''));
        }
    }

    $delete_ok = $db->delete($campaign_lists_table, ['campaign_id' => $campaign_id], ['%d']);
    if ($delete_ok === false) metis_runtime_send_json_error('Failed to reset campaign lists.', 500);

    foreach ($list_ids as $list_id) {
        $ok = $db->insert(
            $campaign_lists_table,
            ['campaign_id' => $campaign_id, 'list_id' => $list_id],
            ['%d', '%d']
        );
        if ($ok === false) {
            metis_runtime_send_json_error('Failed to assign campaign lists.', 500);
        }
    }

    metis_newsletter_save_revision('campaign', $campaign_id, (string) ($payload['doc_json'] ?? ''), (string) ($payload['html_body'] ?? ''), (string) ($payload['text_body'] ?? ''), 'Campaign saved');
    metis_newsletter_audit_log('campaign_saved', 'campaign', $campaign_id, ['status' => $status, 'list_ids' => $list_ids]);

    $saved_row = $db->fetchOne(
        "SELECT c.id, c.campaign_code, c.template_id, c.name, c.subject, c.from_name, c.from_email, c.reply_to, c.preheader,
                c.doc_json, c.editor_body_html, c.html_body, c.text_body, c.status, c.scheduled_at, c.audience_json, c.attachments_json, c.updated_at,
                t.template_code
         FROM {$campaigns_table} c
         LEFT JOIN {$templates_table} t ON t.id = c.template_id
         WHERE c.id = %d
         LIMIT 1",
        [ $campaign_id ]
    ) ?: [];
    $saved_list_rows = $campaign_id > 0
        ? ($db->fetchAll(
            "SELECT list_id FROM {$campaign_lists_table} WHERE campaign_id = %d ORDER BY list_id ASC",
            [ $campaign_id ]
        ) ?: [])
        : [];
    $saved_list_ids = [];
    foreach ($saved_list_rows as $saved_list_row) {
        $saved_list_ids[] = (int) ($saved_list_row['list_id'] ?? 0);
    }

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


metis_ajax_register_handler( 'metis_newsletter_doc_preview', function () {
    metis_newsletter_ajax_verify_nonce();
    if (!metis_newsletter_can_view()) metis_runtime_send_json_error('Unauthorized', 403);

    try {
        $doc_json_raw = (string) metis_runtime_unslash($_POST['doc_json'] ?? '');
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

    $db = metis_db();
    $campaigns_table = Metis_Tables::get('newsletter_campaigns');
    $templates_table = Metis_Tables::get('newsletter_templates');
    $campaign_lists_table = Metis_Tables::get('newsletter_campaign_lists');

    $campaign_code = metis_newsletter_sanitize_ref_code($_POST['campaign_code'] ?? ($_POST['key'] ?? ''));
    $campaign_id = isset($_POST['campaign_id']) ? (int) metis_runtime_unslash($_POST['campaign_id']) : 0;
    if ($campaign_code === '' && $campaign_id < 1) {
        metis_runtime_send_json_error('Campaign reference is required.', 400);
    }

    if ($campaign_code !== '') {
        $row = $db->fetchOne(
            "SELECT c.id, c.campaign_code, c.template_id, c.name, c.subject, c.from_name, c.from_email, c.reply_to, c.preheader,
                    c.doc_json, c.editor_body_html, c.html_body, c.text_body, c.status, c.scheduled_at, c.audience_json, c.attachments_json, c.updated_at,
                    t.template_code
             FROM {$campaigns_table} c
             LEFT JOIN {$templates_table} t ON t.id = c.template_id
             WHERE c.campaign_code = %s
             LIMIT 1",
            [ $campaign_code ]
        );
    } else {
        $row = $db->fetchOne(
            "SELECT c.id, c.campaign_code, c.template_id, c.name, c.subject, c.from_name, c.from_email, c.reply_to, c.preheader,
                    c.doc_json, c.editor_body_html, c.html_body, c.text_body, c.status, c.scheduled_at, c.audience_json, c.attachments_json, c.updated_at,
                    t.template_code
             FROM {$campaigns_table} c
             LEFT JOIN {$templates_table} t ON t.id = c.template_id
             WHERE c.id = %d
             LIMIT 1",
            [ $campaign_id ]
        );
    }
    if (!$row) {
        metis_runtime_send_json_error('Campaign not found.', 404);
    }

    $campaign_id = (int) ($row['id'] ?? 0);
    $list_rows = $campaign_id > 0
        ? ($db->fetchAll(
            "SELECT list_id FROM {$campaign_lists_table} WHERE campaign_id = %d ORDER BY list_id ASC",
            [ $campaign_id ]
        ) ?: [])
        : [];
    $list_ids = [];
    foreach ($list_rows as $list_row) {
        $list_ids[] = (int) ($list_row['list_id'] ?? 0);
    }

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

    $campaign_id = isset($_POST['campaign_id']) ? (int) metis_runtime_unslash($_POST['campaign_id']) : 0;
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

    $db = metis_db();
    $campaigns_table = Metis_Tables::get('newsletter_campaigns');
    $campaign_id = isset($_POST['campaign_id']) ? (int) metis_runtime_unslash($_POST['campaign_id']) : 0;
    $test_email = metis_email_clean(metis_runtime_unslash($_POST['test_email'] ?? ''));
    $override_from_name = metis_text_clean(metis_runtime_unslash($_POST['from_name'] ?? ''));
    $override_from_email = metis_email_clean(metis_runtime_unslash($_POST['from_email'] ?? ''));
    $override_reply_to = metis_email_clean(metis_runtime_unslash($_POST['reply_to'] ?? ''));
    if ($campaign_id < 1 || !metis_email_is_valid($test_email)) metis_runtime_send_json_error('Campaign and valid test email are required.', 400);

    $campaign = $db->fetchOne("SELECT * FROM {$campaigns_table} WHERE id = %d LIMIT 1", [$campaign_id]);
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
    $list_ref = '';
    $campaign_lists_table = Metis_Tables::get('newsletter_campaign_lists');
    $lists_table = Metis_Tables::get('newsletter_lists');
    $list_row = $db->fetchOne(
        "SELECT l.newsletter_list_uid, l.list_key
         FROM {$campaign_lists_table} cl
         INNER JOIN {$lists_table} l ON l.id = cl.list_id
         WHERE cl.campaign_id = %d
         ORDER BY cl.list_id ASC
         LIMIT 1",
        [$campaign_id]
    );
    if (is_array($list_row) && !empty($list_row)) {
        $list_ref = trim((string) (($list_row['newsletter_list_uid'] ?? '') ?: ($list_row['list_key'] ?? '')));
    }
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
    $db->update($campaigns_table, ['status' => 'test_ready', 'test_sent_at' => metis_current_time('mysql'), 'updated_at' => metis_current_time('mysql')], ['id' => $campaign_id], ['%s', '%s', '%s'], ['%d']);
    metis_newsletter_audit_log('campaign_test_sent', 'campaign', $campaign_id, ['test_email' => $test_email]);
    metis_portal_dashboard_forget_all();
    metis_portal_dashboard_forget_all();
    metis_runtime_send_json_success(['campaign_id' => $campaign_id]);
});

metis_ajax_register_handler( 'metis_newsletter_archive_campaign', function () {
    metis_newsletter_ajax_verify_nonce();
    if (!metis_newsletter_can_manage()) metis_runtime_send_json_error('Unauthorized', 403);
    metis_newsletter_ensure_schema();

    $db = metis_db();
    $campaigns_table = Metis_Tables::get('newsletter_campaigns');
    $campaign_id = isset($_POST['campaign_id']) ? (int) metis_runtime_unslash($_POST['campaign_id']) : 0;
    if ($campaign_id < 1) metis_runtime_send_json_error('Invalid campaign.', 400);
    $ok = $db->update($campaigns_table, ['status' => 'archived', 'archived_at' => metis_current_time('mysql'), 'updated_at' => metis_current_time('mysql')], ['id' => $campaign_id], ['%s', '%s', '%s'], ['%d']);
    if ($ok === false) metis_runtime_send_json_error('Unable to archive campaign.', 500);
    metis_newsletter_audit_log('campaign_archived', 'campaign', $campaign_id);
    metis_portal_dashboard_forget_all();
    metis_runtime_send_json_success(['campaign_id' => $campaign_id]);
});

metis_ajax_register_handler( 'metis_newsletter_delete_campaign', function () {
    metis_newsletter_ajax_verify_nonce();
    if (!metis_newsletter_can_manage()) metis_runtime_send_json_error('Unauthorized', 403);
    metis_newsletter_ensure_schema();

    $db = metis_db();
    $campaigns_table = Metis_Tables::get('newsletter_campaigns');
    $campaign_lists_table = Metis_Tables::get('newsletter_campaign_lists');
    $messages_table = Metis_Tables::get('newsletter_messages');
    $events_table = Metis_Tables::get('newsletter_events');

    $campaign_id = isset($_POST['campaign_id']) ? (int) metis_runtime_unslash($_POST['campaign_id']) : 0;
    if ($campaign_id < 1) metis_runtime_send_json_error('Invalid campaign.', 400);

    $campaign = $db->fetchOne(
        "SELECT id, status FROM {$campaigns_table} WHERE id = %d LIMIT 1",
        [$campaign_id]
    );
    if (!$campaign) metis_runtime_send_json_error('Campaign not found.', 404);

    $status = strtolower((string) ($campaign['status'] ?? 'draft'));
    if (in_array($status, ['sending', 'sent', 'archived'], true)) {
        metis_runtime_send_json_error('Sent, sending, or archived campaigns cannot be deleted.', 400);
    }

    $db->execute($db->prepare("DELETE FROM {$events_table} WHERE campaign_id = %d", $campaign_id));
    $db->execute($db->prepare("DELETE FROM {$messages_table} WHERE campaign_id = %d", $campaign_id));
    $db->execute($db->prepare("DELETE FROM {$campaign_lists_table} WHERE campaign_id = %d", $campaign_id));
    $ok = $db->delete($campaigns_table, ['id' => $campaign_id], ['%d']);
    if ($ok === false) metis_runtime_send_json_error('Unable to delete campaign.', 500);

    metis_newsletter_audit_log('campaign_deleted', 'campaign', $campaign_id, ['status' => $status]);
    metis_runtime_send_json_success(['campaign_id' => $campaign_id]);
});

metis_ajax_register_handler( 'metis_newsletter_campaign_status', function () {
    metis_newsletter_ajax_verify_nonce();
    if (!metis_newsletter_can_view()) metis_runtime_send_json_error('Unauthorized', 403);
    metis_newsletter_ensure_schema();

    $db = metis_db();
    $campaigns_table = Metis_Tables::get('newsletter_campaigns');
    $messages_table = Metis_Tables::get('newsletter_messages');
    $contacts_table = Metis_Tables::get('contacts');

    $campaign_id = isset($_POST['campaign_id']) ? (int) metis_runtime_unslash($_POST['campaign_id']) : 0;
    if ($campaign_id < 1) metis_runtime_send_json_error('Invalid campaign.', 400);

    $campaign = $db->fetchOne(
        "SELECT id, campaign_code, name, subject, status, total_recipients, sent_count, failed_count, bounced_count, rejected_count, updated_at
         FROM {$campaigns_table}
         WHERE id = %d
         LIMIT 1",
        [$campaign_id]
    );
    if (!$campaign) metis_runtime_send_json_error('Campaign not found.', 404);

    $message_rows = $db->fetchAll(
        "SELECT m.id, m.email, m.status, m.sent_at, m.delivered_at, m.bounced_at, m.rejected_at, m.opened_at, m.clicked_at, m.last_error,
                c.first_name, c.last_name, c.cid
         FROM {$messages_table} m
         LEFT JOIN {$contacts_table} c ON c.id = m.contact_id
         WHERE m.campaign_id = %d
         ORDER BY m.id ASC
         LIMIT 500",
        [$campaign_id]
    );

    $current = $db->fetchOne(
        "SELECT email, status
         FROM {$messages_table}
         WHERE campaign_id = %d AND status IN ('queued','sending')
         ORDER BY id ASC
         LIMIT 1",
        [$campaign_id]
    );

    $total = (int) ($campaign['total_recipients'] ?? 0);
    if ($total < 1) {
        $total = (int) $db->scalar(
            "SELECT COUNT(*) FROM {$messages_table} WHERE campaign_id = %d",
            [$campaign_id]
        );
    }
    $sent = (int) ($campaign['sent_count'] ?? 0);
    $failed = (int) ($campaign['failed_count'] ?? 0);
    $bounced = (int) ($campaign['bounced_count'] ?? 0);
    $rejected = (int) ($campaign['rejected_count'] ?? 0);
    $processed = $sent + $failed + $bounced + $rejected;
    $progress_pct = $total > 0 ? min(100, max(0, (int) round(($processed / $total) * 100))) : 0;

    metis_runtime_send_json_success([
        'campaign' => [
            'id' => (int) ($campaign['id'] ?? 0),
            'campaign_code' => (string) ($campaign['campaign_code'] ?? ''),
            'name' => (string) ($campaign['name'] ?? ''),
            'subject' => (string) ($campaign['subject'] ?? ''),
            'status' => (string) ($campaign['status'] ?? 'draft'),
            'updated_at' => (string) ($campaign['updated_at'] ?? ''),
        ],
        'summary' => [
            'total' => $total,
            'sent' => $sent,
            'failed' => $failed,
            'bounced' => $bounced,
            'rejected' => $rejected,
            'processed' => $processed,
            'progress_pct' => $progress_pct,
            'current_email' => (string) ($current['email'] ?? ''),
            'current_status' => (string) ($current['status'] ?? ''),
        ],
        'messages' => array_values(array_map(static function (array $row): array {
            return [
                'id' => (int) ($row['id'] ?? 0),
                'email' => (string) ($row['email'] ?? ''),
                'status' => (string) ($row['status'] ?? ''),
                'sent_at' => (string) ($row['sent_at'] ?? ''),
                'delivered_at' => (string) ($row['delivered_at'] ?? ''),
                'bounced_at' => (string) ($row['bounced_at'] ?? ''),
                'rejected_at' => (string) ($row['rejected_at'] ?? ''),
                'opened_at' => (string) ($row['opened_at'] ?? ''),
                'clicked_at' => (string) ($row['clicked_at'] ?? ''),
                'last_error' => (string) ($row['last_error'] ?? ''),
                'first_name' => (string) ($row['first_name'] ?? ''),
                'last_name' => (string) ($row['last_name'] ?? ''),
                'cid' => (string) ($row['cid'] ?? ''),
            ];
        }, $message_rows)),
    ]);
});

metis_ajax_register_handler( 'metis_newsletter_search_contacts', function () {
    metis_newsletter_ajax_verify_nonce();
    if (!metis_newsletter_can_view()) metis_runtime_send_json_error('Unauthorized', 403);
    metis_newsletter_ensure_schema();

    $db = metis_db();
    $contacts_table = Metis_Tables::get('contacts');
    $query = metis_text_clean((string) metis_runtime_unslash($_POST['query'] ?? ''));
    $query = trim($query);
    if ($query === '') metis_runtime_send_json_success(['results' => []]);

    $like = '%' . $db->escapeLike(strtolower($query)) . '%';
    $rows = $db->fetchAll(
        "SELECT cid, first_name, last_name, email
         FROM {$contacts_table}
         WHERE LOWER(CONCAT(COALESCE(first_name,''), ' ', COALESCE(last_name,''))) LIKE %s
            OR LOWER(email) LIKE %s
            OR LOWER(COALESCE(cid,'')) LIKE %s
         ORDER BY first_name ASC, last_name ASC
         LIMIT 20",
        [$like, $like, $like]
    );

    $results = array_values(array_filter(array_map(static function (array $row): array {
        $email = strtolower(trim((string) ($row['email'] ?? '')));
        if ($email === '' || !metis_email_is_valid($email)) return [];
        $name = trim((string) ($row['first_name'] ?? '') . ' ' . (string) ($row['last_name'] ?? ''));
        return [
            'cid' => (string) ($row['cid'] ?? ''),
            'email' => $email,
            'name' => $name !== '' ? $name : $email,
            'label' => trim(($name !== '' ? $name . ' - ' : '') . $email . ((string) ($row['cid'] ?? '') !== '' ? (' - ' . (string) ($row['cid'] ?? '')) : '')),
        ];
    }, $rows), static fn(array $r): bool => !empty($r)));

    metis_runtime_send_json_success(['results' => $results]);
});

metis_ajax_register_handler( 'metis_newsletter_run_queue', function () {
    metis_newsletter_ajax_verify_nonce();
    if (!metis_newsletter_can_manage()) metis_runtime_send_json_error('Unauthorized', 403);
    metis_newsletter_ensure_schema();

    $limit = isset($_POST['limit']) ? (int) metis_runtime_unslash($_POST['limit']) : 100;
    $result = metis_newsletter_process_queue($limit);

    metis_portal_dashboard_forget_all();
    metis_runtime_send_json_success($result);
});


function metis_newsletter_upsert_subscription_record( array $input ): array {
    metis_newsletter_ensure_schema();

    $db = metis_db();
    $subs_table = Metis_Tables::get('newsletter_subs');
    $suppressions_table = Metis_Tables::get('newsletter_suppressions');

    $email = metis_email_clean((string) ($input['email'] ?? ''));
    $first_name = metis_text_clean((string) ($input['first_name'] ?? ''));
    $last_name = metis_text_clean((string) ($input['last_name'] ?? ''));
    $list_id = (int) ($input['list_id'] ?? 0);
    $status = metis_newsletter_sanitize_sub_status((string) ($input['status'] ?? 'subscribed'));

    if ($email === '' || $list_id < 1) {
        return ['success' => false, 'status' => 400, 'message' => 'Email and list are required.'];
    }

    $contact_id = metis_newsletter_find_or_create_contact($email, $first_name, $last_name);
    if ($contact_id < 1) {
        return ['success' => false, 'status' => 500, 'message' => 'Unable to resolve contact.'];
    }

    $existing_id = (int) $db->scalar(
        "SELECT id FROM {$subs_table} WHERE contact_id = %d AND list_id = %d LIMIT 1",
        [$contact_id, $list_id]
    );

    $now = metis_current_time('mysql');
    $payload = [
        'status' => $status,
        'source' => 'metis_manual',
        'last_event_at' => $now,
        'updated_at' => $now,
    ];

    if ($status === 'subscribed') {
        $payload['subscribed_at'] = $now;
        $payload['unsubscribed_at'] = null;
        $db->execute($db->prepare(
            "UPDATE {$suppressions_table} SET is_active = 0, updated_at = %s WHERE (contact_id = %d OR email = %s) AND is_active = 1",
            $now,
            $contact_id,
            strtolower($email)
        ));
    } else {
        $payload['subscribed_at'] = null;
        $payload['unsubscribed_at'] = $now;
        $exists_sup = (int) $db->scalar(
            "SELECT id FROM {$suppressions_table} WHERE (contact_id = %d OR email = %s) AND is_active = 1 LIMIT 1",
            [$contact_id, strtolower($email)]
        );

        if ($exists_sup < 1) {
            $db->insert(
                $suppressions_table,
                [
                    'suppression_code' => metis_generate_code('NS', $suppressions_table, 'suppression_code'),
                    'contact_id' => $contact_id,
                    'email' => strtolower($email),
                    'reason' => $status,
                    'source' => 'manual',
                    'is_active' => 1,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                ['%s', '%d', '%s', '%s', '%s', '%d', '%s', '%s']
            );
        }
    }

    if ($existing_id > 0) {
        $ok = $db->update(
            $subs_table,
            $payload,
            ['id' => $existing_id],
            ['%s', '%s', '%s', '%s', '%s', '%s'],
            ['%d']
        );
        if ($ok === false) {
            return ['success' => false, 'status' => 500, 'message' => 'Failed to update subscription.'];
        }
    } else {
        $payload = array_merge(
            [
                'contact_id' => $contact_id,
                'list_id' => $list_id,
                'bounce_count' => 0,
                'created_at' => $now,
            ],
            $payload
        );

        $ok = $db->insert(
            $subs_table,
            $payload,
            ['%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s']
        );
        if ($ok === false) {
            return ['success' => false, 'status' => 500, 'message' => 'Failed to create subscription.'];
        }
    }

    return [
        'success' => true,
        'status' => 200,
        'message' => 'Subscriber processed successfully.',
        'contact_id' => $contact_id,
        'list_id' => $list_id,
        'status_value' => $status,
    ];
}

metis_ajax_register_handler( 'metis_newsletter_upsert_subscription', function () {
    metis_newsletter_ajax_verify_nonce();
    if (!metis_newsletter_can_manage()) metis_runtime_send_json_error('Unauthorized', 403);

    $result = metis_newsletter_upsert_subscription_record([
        'email' => (string) metis_runtime_unslash($_POST['email'] ?? ''),
        'first_name' => (string) metis_runtime_unslash($_POST['first_name'] ?? ''),
        'last_name' => (string) metis_runtime_unslash($_POST['last_name'] ?? ''),
        'list_id' => (int) metis_runtime_unslash($_POST['list_id'] ?? 0),
        'status' => (string) metis_runtime_unslash($_POST['status'] ?? 'subscribed'),
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
metis_ajax_register_handler( 'metis_newsletter_record_event', function () {
    metis_newsletter_ajax_verify_nonce();
    if (!metis_newsletter_can_manage()) metis_runtime_send_json_error('Unauthorized', 403);
    metis_newsletter_ensure_schema();

    $db = metis_db();
    $messages_table = Metis_Tables::get('newsletter_messages');

    $message_id = isset($_POST['message_id']) ? (int) metis_runtime_unslash($_POST['message_id']) : 0;
    $message_code = metis_text_clean((string) metis_runtime_unslash($_POST['message_code'] ?? ''));
    $event_type = metis_key_clean(metis_runtime_unslash($_POST['event_type'] ?? ''));
    $reason = metis_text_clean(metis_runtime_unslash($_POST['reason'] ?? ''));
    if ($event_type === '') metis_runtime_send_json_error('Event type required.', 400);

    if ($message_code === '' && $message_id > 0) {
        $message_code = (string) $db->scalar("SELECT message_code FROM {$messages_table} WHERE id = %d LIMIT 1", [$message_id]);
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

    $date_input = isset($_POST['usage_date']) ? metis_text_clean(metis_runtime_unslash($_POST['usage_date'])) : '';
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

    $query = metis_text_clean((string) metis_runtime_unslash($_POST['query'] ?? ''));
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

    if (empty($_FILES['image']) || !is_array($_FILES['image'])) {
        metis_runtime_send_json_error('Image file is required.', 400);
    }
    $file = $_FILES['image'];
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

    if (empty($_FILES['attachment']) || !is_array($_FILES['attachment'])) {
        metis_runtime_send_json_error('Attachment file is required.', 400);
    }
    $file = $_FILES['attachment'];
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
