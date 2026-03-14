<?php
if (!defined('ABSPATH')) exit;

function metis_newsletter_ajax_verify_nonce(): void {
    $nonce = isset($_POST['nonce']) ? sanitize_text_field(metis_unslash($_POST['nonce'])) : '';
    if (!metis_verify_nonce($nonce, 'metis_newsletter')) {
        metis_send_json_error('Invalid nonce.', 403);
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

function metis_newsletter_find_or_create_contact(string $email, string $first_name = '', string $last_name = ''): int {
    global $wpdb;

    $contacts_table = Metis_Tables::get('contacts');
    $email = strtolower(trim($email));
    if ($email === '') return 0;

    $contact_id = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$contacts_table} WHERE email = %s LIMIT 1", $email));
    if ($contact_id > 0) return $contact_id;

    $payload = [
        'email' => $email,
        'first_name' => $first_name,
        'last_name' => $last_name,
    ];
    $format = ['%s', '%s', '%s'];

    if (metis_newsletter_column_exists($contacts_table, 'cid')) {
        $payload['cid'] = metis_generate_code('CN', $contacts_table, 'cid');
        $format[] = '%s';
    }

    $ok = $wpdb->insert($contacts_table, $payload, $format);
    if ($ok === false) return 0;
    return (int) $wpdb->insert_id;
}

metis_add_action('wp_ajax_metis_newsletter_save_defaults', function () {
    metis_newsletter_ajax_verify_nonce();
    if (!metis_newsletter_can_manage()) metis_send_json_error('Unauthorized', 403);

    $from_name = sanitize_text_field(metis_unslash($_POST['from_name'] ?? ''));
    $from_email = sanitize_email(metis_unslash($_POST['from_email'] ?? ''));
    $reply_to = sanitize_email(metis_unslash($_POST['reply_to'] ?? ''));

    if ($from_email !== '' && !is_email($from_email)) {
        metis_send_json_error('Default From Email is invalid.', 400);
    }
    if ($reply_to !== '' && !is_email($reply_to)) {
        metis_send_json_error('Default Reply-To is invalid.', 400);
    }

    Core_Settings_Service::set('newsletter_default_from_name', $from_name, true);
    Core_Settings_Service::set('newsletter_default_from_email', $from_email, true);
    Core_Settings_Service::set('newsletter_default_reply_to', $reply_to, true);

    metis_send_json_success([
        'from_name' => $from_name,
        'from_email' => $from_email,
        'reply_to' => $reply_to,
    ]);
});

metis_add_action('wp_ajax_metis_newsletter_save_template', function () {
    metis_newsletter_ajax_verify_nonce();
    if (!metis_newsletter_can_manage()) metis_send_json_error('Unauthorized', 403);
    metis_newsletter_ensure_schema();

    global $wpdb;
    $table = Metis_Tables::get('newsletter_templates');

    $template_id = isset($_POST['template_id']) ? (int) metis_unslash($_POST['template_id']) : 0;
    $name = sanitize_text_field(metis_unslash($_POST['name'] ?? ''));
    $subject = sanitize_text_field(metis_unslash($_POST['subject'] ?? ''));
    $from_name = sanitize_text_field(metis_unslash($_POST['from_name'] ?? ''));
    $from_email = sanitize_email(metis_unslash($_POST['from_email'] ?? ''));
    $reply_to = sanitize_email(metis_unslash($_POST['reply_to'] ?? ''));
    $doc_json_raw = (string) metis_unslash($_POST['doc_json'] ?? '');
    $has_html_body = array_key_exists('html_body', $_POST);
    $raw_html_body = $has_html_body ? (string) metis_unslash($_POST['html_body']) : '';
    $html_body = $has_html_body ? metis_kses_post($raw_html_body) : '';
    $has_text_body = array_key_exists('text_body', $_POST);
    $text_body = $has_text_body ? sanitize_textarea_field(metis_unslash($_POST['text_body'])) : '';

    $compiled = null;
    if ($doc_json_raw !== '') {
        $compiled = metis_newsletter_doc_compile($doc_json_raw);
        $doc_json_raw = (string) ($compiled['doc_json'] ?? '');
        $html_body = (string) ($compiled['html'] ?? $html_body);
        $text_body = (string) ($compiled['text'] ?? $text_body);
    }
    if ($name === '' || $subject === '' || trim($html_body) === '') {
        metis_send_json_error('Name, subject, and content are required.', 400);
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
        'updated_at' => current_time('mysql'),
    ];
    $format = ['%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s'];

    if ($template_id > 0) {
        $ok = $wpdb->update($table, $payload, ['id' => $template_id], $format, ['%d']);
        if ($ok === false) metis_send_json_error('Failed to update template.', 500);
    } else {
        $payload['template_code'] = metis_generate_code('NT', $table, 'template_code');
        $payload['created_by'] = metis_current_user_id() ?: null;
        $ok = $wpdb->insert($table, $payload, ['%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d']);
        if ($ok === false) metis_send_json_error('Failed to create template.', 500);
        $template_id = (int) $wpdb->insert_id;
    }

    metis_newsletter_save_revision('template', $template_id, (string) ($payload['doc_json'] ?? ''), (string) ($payload['html_body'] ?? ''), (string) ($payload['text_body'] ?? ''), 'Template saved');
    metis_newsletter_audit_log('template_saved', 'template', $template_id, ['name' => $name]);

    metis_send_json_success(['template_id' => $template_id]);
});

metis_add_action('wp_ajax_metis_newsletter_save_list', function () {
    metis_newsletter_ajax_verify_nonce();
    if (!metis_newsletter_can_manage()) metis_send_json_error('Unauthorized', 403);
    metis_newsletter_ensure_schema();

    global $wpdb;
    $table = Metis_Tables::get('newsletter_lists');

    $list_id = isset($_POST['list_id']) ? (int) metis_unslash($_POST['list_id']) : 0;
    $name = sanitize_text_field(metis_unslash($_POST['name'] ?? ''));
    $description = sanitize_textarea_field(metis_unslash($_POST['description'] ?? ''));
    $is_active = isset($_POST['is_active']) ? (int) metis_unslash($_POST['is_active']) : 1;

    if ($name === '') metis_send_json_error('List name is required.', 400);

    $payload = [
        'name' => $name,
        'description' => $description,
        'is_active' => $is_active ? 1 : 0,
        'updated_at' => current_time('mysql'),
    ];

    if ($list_id > 0) {
        $ok = $wpdb->update($table, $payload, ['id' => $list_id], ['%s', '%s', '%d', '%s'], ['%d']);
        if ($ok === false) metis_send_json_error('Failed to update list.', 500);
    } else {
        $payload['list_key'] = metis_generate_code('NL', $table, 'list_key');
        $ok = $wpdb->insert($table, $payload, ['%s', '%s', '%d', '%s', '%s']);
        if ($ok === false) metis_send_json_error('Failed to create list.', 500);
        $list_id = (int) $wpdb->insert_id;
    }

    metis_send_json_success(['list_id' => $list_id]);
});

metis_add_action('wp_ajax_metis_newsletter_save_campaign', function () {
    metis_newsletter_ajax_verify_nonce();
    if (!metis_newsletter_can_manage()) metis_send_json_error('Unauthorized', 403);
    metis_newsletter_ensure_schema();

    global $wpdb;

    $campaigns_table = Metis_Tables::get('newsletter_campaigns');
    $campaign_lists_table = Metis_Tables::get('newsletter_campaign_lists');
    $lists_table = Metis_Tables::get('newsletter_lists');

    $campaign_id = isset($_POST['campaign_id']) ? (int) metis_unslash($_POST['campaign_id']) : 0;
    $template_id = isset($_POST['template_id']) ? (int) metis_unslash($_POST['template_id']) : 0;
    $name = sanitize_text_field(metis_unslash($_POST['name'] ?? ''));
    $subject = sanitize_text_field(metis_unslash($_POST['subject'] ?? ''));
    $from_name = sanitize_text_field(metis_unslash($_POST['from_name'] ?? ''));
    $from_email = sanitize_email(metis_unslash($_POST['from_email'] ?? ''));
    $reply_to = sanitize_email(metis_unslash($_POST['reply_to'] ?? ''));
    $preheader = sanitize_text_field(metis_unslash($_POST['preheader'] ?? ''));
    $doc_json_raw = (string) metis_unslash($_POST['doc_json'] ?? '');
    $has_html_body = array_key_exists('html_body', $_POST);
    $html_body = $has_html_body ? metis_kses_post(metis_unslash($_POST['html_body'])) : '';
    $has_text_body = array_key_exists('text_body', $_POST);
    $text_body = $has_text_body ? sanitize_textarea_field(metis_unslash($_POST['text_body'])) : '';
    $audience_json_raw = (string) metis_unslash($_POST['audience_json'] ?? '');
    $attachments_json_raw = (string) metis_unslash($_POST['attachments_json'] ?? '');
    $scheduled_at = metis_newsletter_parse_datetime_local((string) (metis_unslash($_POST['scheduled_at'] ?? '')));
    $status = metis_newsletter_sanitize_status((string) (metis_unslash($_POST['status'] ?? 'draft')));
    $attachments_payload = [];
    if ($attachments_json_raw !== '') {
        $decoded_attachments = json_decode($attachments_json_raw, true);
        if (is_array($decoded_attachments)) {
            foreach ($decoded_attachments as $att) {
                if (!is_array($att)) continue;
                $path = (string) ($att['path'] ?? '');
                $url = esc_url_raw((string) ($att['url'] ?? ''));
                $name = sanitize_text_field((string) ($att['name'] ?? ''));
                $mime = sanitize_text_field((string) ($att['mime'] ?? 'application/octet-stream'));
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
        $decoded = json_decode((string) metis_unslash($_POST['list_ids']), true);
        if (is_array($decoded)) {
            $list_ids = array_values(array_unique(array_map('intval', $decoded)));
        }
    }

    if ($name === '' || $subject === '') {
        metis_send_json_error('Campaign name and subject are required.', 400);
    }

    if ($doc_json_raw !== '') {
        $compiled = metis_newsletter_doc_compile($doc_json_raw);
        $doc_json_raw = (string) ($compiled['doc_json'] ?? '');
        $html_body = (string) ($compiled['html'] ?? $html_body);
        $text_body = (string) ($compiled['text'] ?? $text_body);
    }

    if (!empty($list_ids)) {
        $valid_query = $wpdb->prepare(
            'SELECT id FROM ' . $lists_table . ' WHERE id IN (' . implode(',', array_fill(0, count($list_ids), '%d')) . ')',
            ...$list_ids
        );
        $valid_ids = $wpdb->get_col($valid_query) ?: [];
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
        'status' => $status,
        'scheduled_at' => $scheduled_at,
        'audience_json' => $audience_json_raw !== '' ? metis_json_encode(metis_newsletter_decode_audience_json($audience_json_raw)) : null,
        'attachments_json' => !empty($attachments_payload) ? metis_json_encode($attachments_payload) : null,
        'updated_at' => current_time('mysql'),
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
        'status' => '%s',
        'scheduled_at' => '%s',
        'audience_json' => '%s',
        'attachments_json' => '%s',
        'updated_at' => '%s',
        'html_body' => '%s',
        'text_body' => '%s',
        'campaign_code' => '%s',
        'created_by' => '%d',
    ];
    $payload_formats = [];
    foreach (array_keys($payload) as $payload_key) {
        $payload_formats[] = $field_formats[$payload_key] ?? '%s';
    }

    if ($campaign_id > 0) {
        $ok = $wpdb->update(
            $campaigns_table,
            $payload,
            ['id' => $campaign_id],
            $payload_formats,
            ['%d']
        );
        if ($ok === false) metis_send_json_error('Failed to update campaign.', 500);
    } else {
        $payload['campaign_code'] = metis_generate_code('NC', $campaigns_table, 'campaign_code');
        $payload['created_by'] = metis_current_user_id() ?: null;
        $payload_formats = [];
        foreach (array_keys($payload) as $payload_key) {
            $payload_formats[] = $field_formats[$payload_key] ?? '%s';
        }
        $ok = $wpdb->insert(
            $campaigns_table,
            $payload,
            $payload_formats
        );
        if ($ok === false) metis_send_json_error('Failed to create campaign.', 500);
        $campaign_id = (int) $wpdb->insert_id;
    }

    $delete_ok = $wpdb->delete($campaign_lists_table, ['campaign_id' => $campaign_id], ['%d']);
    if ($delete_ok === false) metis_send_json_error('Failed to reset campaign lists.', 500);

    foreach ($list_ids as $list_id) {
        $ok = $wpdb->insert(
            $campaign_lists_table,
            ['campaign_id' => $campaign_id, 'list_id' => $list_id],
            ['%d', '%d']
        );
        if ($ok === false) {
            metis_send_json_error('Failed to assign campaign lists.', 500);
        }
    }

    metis_newsletter_save_revision('campaign', $campaign_id, (string) ($payload['doc_json'] ?? ''), (string) ($payload['html_body'] ?? ''), (string) ($payload['text_body'] ?? ''), 'Campaign saved');
    metis_newsletter_audit_log('campaign_saved', 'campaign', $campaign_id, ['status' => $status, 'list_ids' => $list_ids]);

    metis_send_json_success(['campaign_id' => $campaign_id]);
});

metis_add_action('wp_ajax_metis_newsletter_queue_campaign', function () {
    metis_newsletter_ajax_verify_nonce();
    if (!metis_newsletter_can_manage()) metis_send_json_error('Unauthorized', 403);
    metis_newsletter_ensure_schema();

    $campaign_id = isset($_POST['campaign_id']) ? (int) metis_unslash($_POST['campaign_id']) : 0;
    if ($campaign_id < 1) metis_send_json_error('Invalid campaign.', 400);

    $result = metis_newsletter_queue_campaign_messages($campaign_id);
    if (empty($result['ok'])) {
        metis_send_json_error((string) ($result['message'] ?? 'Failed to queue campaign.'), 500);
    }

    metis_send_json_success([
        'queued' => (int) ($result['queued'] ?? 0),
        'campaign_id' => $campaign_id,
    ]);
});

metis_add_action('wp_ajax_metis_newsletter_test_send_campaign', function () {
    metis_newsletter_ajax_verify_nonce();
    if (!metis_newsletter_can_manage()) metis_send_json_error('Unauthorized', 403);
    metis_newsletter_ensure_schema();

    global $wpdb;
    $campaigns_table = Metis_Tables::get('newsletter_campaigns');
    $campaign_id = isset($_POST['campaign_id']) ? (int) metis_unslash($_POST['campaign_id']) : 0;
    $test_email = sanitize_email(metis_unslash($_POST['test_email'] ?? ''));
    $override_from_name = sanitize_text_field(metis_unslash($_POST['from_name'] ?? ''));
    $override_from_email = sanitize_email(metis_unslash($_POST['from_email'] ?? ''));
    $override_reply_to = sanitize_email(metis_unslash($_POST['reply_to'] ?? ''));
    if ($campaign_id < 1 || !is_email($test_email)) metis_send_json_error('Campaign and valid test email are required.', 400);

    $campaign = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$campaigns_table} WHERE id = %d LIMIT 1", $campaign_id), ARRAY_A);
    if (!$campaign) metis_send_json_error('Campaign not found.', 404);

    $doc_json = (string) ($campaign['doc_json'] ?? '');
    $html_body = (string) ($campaign['html_body'] ?? '');
    $text_body = (string) ($campaign['text_body'] ?? '');
    if ($doc_json !== '') {
        $compiled = metis_newsletter_doc_compile($doc_json);
        $html_body = (string) ($compiled['html'] ?? $html_body);
        $text_body = (string) ($compiled['text'] ?? $text_body);
    }
    $html_body = metis_newsletter_ensure_email_container($html_body);
    $contact_payload = ['first_name' => 'Test', 'last_name' => 'Recipient', 'email' => $test_email];
    $rendered_html = metis_newsletter_render_template($html_body, $contact_payload);
    $rendered_html = str_replace(['{{unsubscribe_url}}', '{{manage_subscription_url}}'], ['#', '#'], $rendered_html);
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
                $name = sanitize_text_field((string) ($att['name'] ?? ''));
                $mime = sanitize_text_field((string) ($att['mime'] ?? 'application/octet-stream'));
                if ($path !== '' && file_exists($path)) {
                    $attachments[] = ['path' => $path, 'name' => $name !== '' ? $name : basename($path), 'mime' => $mime];
                } elseif ($url !== '') {
                    $attachments[] = ['url' => esc_url_raw($url), 'name' => $name !== '' ? $name : basename(parse_url($url, PHP_URL_PATH) ?: 'attachment'), 'mime' => $mime];
                }
            }
        }
    }

    $send = metis_newsletter_gmail_send($test_email, $subject, $rendered_html !== '' ? $rendered_html : '<p>' . esc_html($text_body) . '</p>', [
        'from_name' => $override_from_name !== '' ? $override_from_name : (string) ($campaign['from_name'] ?? ''),
        'from_email' => is_email($override_from_email) ? $override_from_email : (string) ($campaign['from_email'] ?? ''),
        'reply_to' => is_email($override_reply_to) ? $override_reply_to : (string) ($campaign['reply_to'] ?? ''),
        'attachments' => $attachments,
    ]);
    if (empty($send['ok'])) {
        metis_send_json_error((string) ($send['error'] ?? 'Test send failed.'), 500);
    }
    $wpdb->update($campaigns_table, ['status' => 'test_ready', 'test_sent_at' => current_time('mysql'), 'updated_at' => current_time('mysql')], ['id' => $campaign_id], ['%s', '%s', '%s'], ['%d']);
    metis_newsletter_audit_log('campaign_test_sent', 'campaign', $campaign_id, ['test_email' => $test_email]);
    metis_send_json_success(['campaign_id' => $campaign_id]);
});

metis_add_action('wp_ajax_metis_newsletter_archive_campaign', function () {
    metis_newsletter_ajax_verify_nonce();
    if (!metis_newsletter_can_manage()) metis_send_json_error('Unauthorized', 403);
    metis_newsletter_ensure_schema();

    global $wpdb;
    $campaigns_table = Metis_Tables::get('newsletter_campaigns');
    $campaign_id = isset($_POST['campaign_id']) ? (int) metis_unslash($_POST['campaign_id']) : 0;
    if ($campaign_id < 1) metis_send_json_error('Invalid campaign.', 400);
    $ok = $wpdb->update($campaigns_table, ['status' => 'archived', 'archived_at' => current_time('mysql'), 'updated_at' => current_time('mysql')], ['id' => $campaign_id], ['%s', '%s', '%s'], ['%d']);
    if ($ok === false) metis_send_json_error('Unable to archive campaign.', 500);
    metis_newsletter_audit_log('campaign_archived', 'campaign', $campaign_id);
    metis_send_json_success(['campaign_id' => $campaign_id]);
});

metis_add_action('wp_ajax_metis_newsletter_delete_campaign', function () {
    metis_newsletter_ajax_verify_nonce();
    if (!metis_newsletter_can_manage()) metis_send_json_error('Unauthorized', 403);
    metis_newsletter_ensure_schema();

    global $wpdb;
    $campaigns_table = Metis_Tables::get('newsletter_campaigns');
    $campaign_lists_table = Metis_Tables::get('newsletter_campaign_lists');
    $messages_table = Metis_Tables::get('newsletter_messages');
    $events_table = Metis_Tables::get('newsletter_events');

    $campaign_id = isset($_POST['campaign_id']) ? (int) metis_unslash($_POST['campaign_id']) : 0;
    if ($campaign_id < 1) metis_send_json_error('Invalid campaign.', 400);

    $campaign = $wpdb->get_row($wpdb->prepare(
        "SELECT id, status FROM {$campaigns_table} WHERE id = %d LIMIT 1",
        $campaign_id
    ), ARRAY_A);
    if (!$campaign) metis_send_json_error('Campaign not found.', 404);

    $status = strtolower((string) ($campaign['status'] ?? 'draft'));
    if (in_array($status, ['sending', 'sent', 'archived'], true)) {
        metis_send_json_error('Sent, sending, or archived campaigns cannot be deleted.', 400);
    }

    $wpdb->query($wpdb->prepare("DELETE FROM {$events_table} WHERE campaign_id = %d", $campaign_id));
    $wpdb->query($wpdb->prepare("DELETE FROM {$messages_table} WHERE campaign_id = %d", $campaign_id));
    $wpdb->query($wpdb->prepare("DELETE FROM {$campaign_lists_table} WHERE campaign_id = %d", $campaign_id));
    $ok = $wpdb->delete($campaigns_table, ['id' => $campaign_id], ['%d']);
    if ($ok === false) metis_send_json_error('Unable to delete campaign.', 500);

    metis_newsletter_audit_log('campaign_deleted', 'campaign', $campaign_id, ['status' => $status]);
    metis_send_json_success(['campaign_id' => $campaign_id]);
});

metis_add_action('wp_ajax_metis_newsletter_campaign_status', function () {
    metis_newsletter_ajax_verify_nonce();
    if (!metis_newsletter_can_view()) metis_send_json_error('Unauthorized', 403);
    metis_newsletter_ensure_schema();

    global $wpdb;
    $campaigns_table = Metis_Tables::get('newsletter_campaigns');
    $messages_table = Metis_Tables::get('newsletter_messages');
    $contacts_table = Metis_Tables::get('contacts');

    $campaign_id = isset($_POST['campaign_id']) ? (int) metis_unslash($_POST['campaign_id']) : 0;
    if ($campaign_id < 1) metis_send_json_error('Invalid campaign.', 400);

    $campaign = $wpdb->get_row($wpdb->prepare(
        "SELECT id, campaign_code, name, subject, status, total_recipients, sent_count, failed_count, bounced_count, rejected_count, updated_at
         FROM {$campaigns_table}
         WHERE id = %d
         LIMIT 1",
        $campaign_id
    ), ARRAY_A);
    if (!$campaign) metis_send_json_error('Campaign not found.', 404);

    $message_rows = $wpdb->get_results($wpdb->prepare(
        "SELECT m.id, m.email, m.status, m.sent_at, m.delivered_at, m.bounced_at, m.rejected_at, m.opened_at, m.clicked_at, m.last_error,
                c.first_name, c.last_name, c.cid
         FROM {$messages_table} m
         LEFT JOIN {$contacts_table} c ON c.id = m.contact_id
         WHERE m.campaign_id = %d
         ORDER BY m.id ASC
         LIMIT 500",
        $campaign_id
    ), ARRAY_A) ?: [];

    $current = $wpdb->get_row($wpdb->prepare(
        "SELECT email, status
         FROM {$messages_table}
         WHERE campaign_id = %d AND status IN ('queued','sending')
         ORDER BY id ASC
         LIMIT 1",
        $campaign_id
    ), ARRAY_A);

    $total = (int) ($campaign['total_recipients'] ?? 0);
    if ($total < 1) {
        $total = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$messages_table} WHERE campaign_id = %d",
            $campaign_id
        ));
    }
    $sent = (int) ($campaign['sent_count'] ?? 0);
    $failed = (int) ($campaign['failed_count'] ?? 0);
    $bounced = (int) ($campaign['bounced_count'] ?? 0);
    $rejected = (int) ($campaign['rejected_count'] ?? 0);
    $processed = $sent + $failed + $bounced + $rejected;
    $progress_pct = $total > 0 ? min(100, max(0, (int) round(($processed / $total) * 100))) : 0;

    metis_send_json_success([
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

metis_add_action('wp_ajax_metis_newsletter_search_contacts', function () {
    metis_newsletter_ajax_verify_nonce();
    if (!metis_newsletter_can_view()) metis_send_json_error('Unauthorized', 403);
    metis_newsletter_ensure_schema();

    global $wpdb;
    $contacts_table = Metis_Tables::get('contacts');
    $query = sanitize_text_field((string) metis_unslash($_POST['query'] ?? ''));
    $query = trim($query);
    if ($query === '') metis_send_json_success(['results' => []]);

    $like = '%' . $wpdb->esc_like(strtolower($query)) . '%';
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT cid, first_name, last_name, email
         FROM {$contacts_table}
         WHERE LOWER(CONCAT(COALESCE(first_name,''), ' ', COALESCE(last_name,''))) LIKE %s
            OR LOWER(email) LIKE %s
            OR LOWER(COALESCE(cid,'')) LIKE %s
         ORDER BY first_name ASC, last_name ASC
         LIMIT 20",
        $like,
        $like,
        $like
    ), ARRAY_A) ?: [];

    $results = array_values(array_filter(array_map(static function (array $row): array {
        $email = strtolower(trim((string) ($row['email'] ?? '')));
        if ($email === '' || !is_email($email)) return [];
        $name = trim((string) ($row['first_name'] ?? '') . ' ' . (string) ($row['last_name'] ?? ''));
        return [
            'cid' => (string) ($row['cid'] ?? ''),
            'email' => $email,
            'name' => $name !== '' ? $name : $email,
            'label' => trim(($name !== '' ? $name . ' - ' : '') . $email . ((string) ($row['cid'] ?? '') !== '' ? (' - ' . (string) ($row['cid'] ?? '')) : '')),
        ];
    }, $rows), static fn(array $r): bool => !empty($r)));

    metis_send_json_success(['results' => $results]);
});

metis_add_action('wp_ajax_metis_newsletter_run_queue', function () {
    metis_newsletter_ajax_verify_nonce();
    if (!metis_newsletter_can_manage()) metis_send_json_error('Unauthorized', 403);
    metis_newsletter_ensure_schema();

    $limit = isset($_POST['limit']) ? (int) metis_unslash($_POST['limit']) : 100;
    $result = metis_newsletter_process_queue($limit);

    metis_send_json_success($result);
});

metis_add_action('wp_ajax_metis_newsletter_upsert_subscription', function () {
    metis_newsletter_ajax_verify_nonce();
    if (!metis_newsletter_can_manage()) metis_send_json_error('Unauthorized', 403);
    metis_newsletter_ensure_schema();

    global $wpdb;
    $subs_table = Metis_Tables::get('newsletter_subs');
    $suppressions_table = Metis_Tables::get('newsletter_suppressions');

    $email = sanitize_email(metis_unslash($_POST['email'] ?? ''));
    $first_name = sanitize_text_field(metis_unslash($_POST['first_name'] ?? ''));
    $last_name = sanitize_text_field(metis_unslash($_POST['last_name'] ?? ''));
    $list_id = isset($_POST['list_id']) ? (int) metis_unslash($_POST['list_id']) : 0;
    $status = metis_newsletter_sanitize_sub_status((string) metis_unslash($_POST['status'] ?? 'subscribed'));

    if ($email === '' || $list_id < 1) {
        metis_send_json_error('Email and list are required.', 400);
    }

    $contact_id = metis_newsletter_find_or_create_contact($email, $first_name, $last_name);
    if ($contact_id < 1) metis_send_json_error('Unable to resolve contact.', 500);

    $existing_id = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT id FROM {$subs_table} WHERE contact_id = %d AND list_id = %d LIMIT 1",
        $contact_id,
        $list_id
    ));

    $now = current_time('mysql');
    $payload = [
        'status' => $status,
        'source' => 'metis_manual',
        'last_event_at' => $now,
        'updated_at' => $now,
    ];

    if ($status === 'subscribed') {
        $payload['subscribed_at'] = $now;
        $payload['unsubscribed_at'] = null;
        $wpdb->query($wpdb->prepare(
            "UPDATE {$suppressions_table} SET is_active = 0, updated_at = %s WHERE (contact_id = %d OR email = %s) AND is_active = 1",
            $now,
            $contact_id,
            strtolower($email)
        ));
    } else {
        $payload['subscribed_at'] = null;
        $payload['unsubscribed_at'] = $now;
        $exists_sup = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$suppressions_table} WHERE (contact_id = %d OR email = %s) AND is_active = 1 LIMIT 1",
            $contact_id,
            strtolower($email)
        ));
        if ($exists_sup < 1) {
            $wpdb->insert(
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
        $ok = $wpdb->update(
            $subs_table,
            $payload,
            ['id' => $existing_id],
            ['%s', '%s', '%s', '%s', '%s', '%s'],
            ['%d']
        );
        if ($ok === false) metis_send_json_error('Failed to update subscription.', 500);
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

        $ok = $wpdb->insert(
            $subs_table,
            $payload,
            ['%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s']
        );
        if ($ok === false) metis_send_json_error('Failed to create subscription.', 500);
    }

    metis_send_json_success([
        'contact_id' => $contact_id,
        'list_id' => $list_id,
        'status' => $status,
    ]);
});

metis_add_action('wp_ajax_metis_newsletter_record_event', function () {
    metis_newsletter_ajax_verify_nonce();
    if (!metis_newsletter_can_manage()) metis_send_json_error('Unauthorized', 403);
    metis_newsletter_ensure_schema();

    global $wpdb;
    $messages_table = Metis_Tables::get('newsletter_messages');

    $message_id = isset($_POST['message_id']) ? (int) metis_unslash($_POST['message_id']) : 0;
    $message_code = sanitize_text_field((string) metis_unslash($_POST['message_code'] ?? ''));
    $event_type = sanitize_key(metis_unslash($_POST['event_type'] ?? ''));
    $reason = sanitize_text_field(metis_unslash($_POST['reason'] ?? ''));
    if ($event_type === '') metis_send_json_error('Event type required.', 400);

    if ($message_code === '' && $message_id > 0) {
        $message_code = (string) $wpdb->get_var($wpdb->prepare("SELECT message_code FROM {$messages_table} WHERE id = %d LIMIT 1", $message_id));
    }
    if ($message_code === '') metis_send_json_error('Message reference required.', 400);

    $ok = metis_newsletter_track_event_for_message($message_code, $event_type, $reason);
    if (!$ok) metis_send_json_error('Failed to record event.', 500);
    metis_send_json_success(['recorded' => true, 'message_code' => $message_code]);
});

metis_add_action('wp_ajax_metis_newsletter_sync_google_usage', function () {
    metis_newsletter_ajax_verify_nonce();
    if (!metis_newsletter_can_manage()) metis_send_json_error('Unauthorized', 403);
    metis_newsletter_ensure_schema();

    $date_input = isset($_POST['usage_date']) ? sanitize_text_field(metis_unslash($_POST['usage_date'])) : '';
    $date_ymd = '';
    if ($date_input !== '') {
        $dt = DateTimeImmutable::createFromFormat('Y-m-d', $date_input);
        if ($dt instanceof DateTimeImmutable) {
            $date_ymd = $dt->format('Y-m-d');
        }
    }

    $result = metis_newsletter_google_sync_usage_for_date($date_ymd);
    if (empty($result['ok'])) {
        metis_send_json_error((string) ($result['error'] ?? 'Failed to sync Google usage.'), 500);
    }

    metis_send_json_success([
        'date' => (string) ($result['date'] ?? ''),
        'imported' => (int) ($result['imported'] ?? 0),
    ]);
});

// Backward-compatible GIF search endpoint, now wired to Klipy.
$metis_newsletter_klipy_search_handler = static function () {
    metis_newsletter_ajax_verify_nonce();
    if (!metis_newsletter_can_view()) metis_send_json_error('Unauthorized', 403);

    $query = sanitize_text_field((string) metis_unslash($_POST['query'] ?? ''));
    $query = trim($query);
    if ($query === '' || strlen($query) < 2) {
        metis_send_json_error('Search query must be at least 2 characters.', 400);
    }

    $api_key = trim((string) Core_Settings_Service::get('newsletter_klipy_api_key', ''));
    $search_url = trim((string) Core_Settings_Service::get('newsletter_klipy_search_url', 'https://api.klipy.com/v1/gifs/search'));

    if ($search_url === '') {
        metis_send_json_error('Klipy search URL is not configured. Add it in Settings > Newsletter.', 400);
    }
    $sep = strpos($search_url, '?') === false ? '?' : '&';
    $url = $search_url . $sep . 'limit=18&q=' . rawurlencode($query);
    if ($api_key !== '') {
        $url .= '&api_key=' . rawurlencode($api_key);
    }
    $response = metis_remote_get($url, [
        'timeout' => 12,
        'headers' => ['Accept' => 'application/json'],
    ]);
    if (metis_is_error($response)) {
        metis_send_json_error('Klipy request failed.', 500);
    }

    $code = (int) metis_remote_retrieve_response_code($response);
    $body = (string) metis_remote_retrieve_body($response);
    $data = json_decode($body, true);
    if ($code < 200 || $code > 299 || !is_array($data)) {
        metis_send_json_error('Klipy API returned an error.', 500);
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
                'original' => ['url' => esc_url_raw($original)],
                'fixed_height_small' => ['url' => esc_url_raw($preview)],
            ],
        ];
    }

    metis_send_json_success(['items' => $items]);
};
metis_add_action('wp_ajax_metis_newsletter_klipy_search', $metis_newsletter_klipy_search_handler);
metis_add_action('wp_ajax_metis_newsletter_giphy_search', $metis_newsletter_klipy_search_handler);

metis_add_action('wp_ajax_metis_newsletter_upload_image', function () {
    metis_newsletter_ajax_verify_nonce();
    if (!metis_newsletter_can_manage()) metis_send_json_error('Unauthorized', 403);
    metis_newsletter_ensure_schema();

    if (empty($_FILES['image']) || !is_array($_FILES['image'])) {
        metis_send_json_error('Image file is required.', 400);
    }
    $file = $_FILES['image'];
    if (!isset($file['tmp_name']) || (string) $file['tmp_name'] === '') {
        metis_send_json_error('Image upload is invalid.', 400);
    }
    $size = isset($file['size']) ? (int) $file['size'] : 0;
    if ($size < 1) {
        metis_send_json_error('Image upload is empty.', 400);
    }
    if ($size > 8 * 1024 * 1024) {
        metis_send_json_error('Image must be 8MB or smaller.', 400);
    }

    $allowed_mimes = [
        'jpg|jpeg|jpe' => 'image/jpeg',
        'png' => 'image/png',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
    ];

    $uploaded = metis_handle_upload($file, [
        'test_form' => false,
        'mimes' => $allowed_mimes,
    ]);
    if (!is_array($uploaded) || !empty($uploaded['error'])) {
        metis_send_json_error((string) ($uploaded['error'] ?? 'Failed to upload image.'), 500);
    }

    $url = isset($uploaded['url']) ? esc_url_raw((string) $uploaded['url']) : '';
    $path = isset($uploaded['file']) ? (string) $uploaded['file'] : '';
    if ($url === '' || $path === '') {
        metis_send_json_error('Image URL unavailable.', 500);
    }

    $filename = sanitize_file_name((string) ($file['name'] ?? basename($path)));
    $mime_type = (string) (metis_check_filetype($filename, $allowed_mimes)['type'] ?? '');
    if ($mime_type === '') $mime_type = 'application/octet-stream';
    metis_newsletter_audit_log('image_uploaded', 'asset', 0, [
        'filename' => $filename,
        'mime' => $mime_type,
        'size' => $size,
        'url' => $url,
    ]);

    metis_send_json_success([
        'url' => $url,
        'filename' => $filename,
        'mime' => $mime_type,
        'size' => $size,
    ]);
});

metis_add_action('wp_ajax_metis_newsletter_upload_attachment', function () {
    metis_newsletter_ajax_verify_nonce();
    if (!metis_newsletter_can_manage()) metis_send_json_error('Unauthorized', 403);
    metis_newsletter_ensure_schema();

    if (empty($_FILES['attachment']) || !is_array($_FILES['attachment'])) {
        metis_send_json_error('Attachment file is required.', 400);
    }
    $file = $_FILES['attachment'];
    if (!isset($file['tmp_name']) || (string) $file['tmp_name'] === '') {
        metis_send_json_error('Attachment upload is invalid.', 400);
    }
    $size = isset($file['size']) ? (int) $file['size'] : 0;
    if ($size < 1) metis_send_json_error('Attachment upload is empty.', 400);
    if ($size > 15 * 1024 * 1024) metis_send_json_error('Attachment must be 15MB or smaller.', 400);

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

    $uploaded = metis_handle_upload($file, ['test_form' => false, 'mimes' => $allowed_mimes]);
    if (!is_array($uploaded) || !empty($uploaded['error'])) {
        metis_send_json_error((string) ($uploaded['error'] ?? 'Failed to upload attachment.'), 500);
    }

    $url = isset($uploaded['url']) ? esc_url_raw((string) $uploaded['url']) : '';
    $path = isset($uploaded['file']) ? (string) $uploaded['file'] : '';
    if ($url === '' || $path === '') metis_send_json_error('Attachment URL unavailable.', 500);

    $filename = sanitize_file_name((string) ($file['name'] ?? basename($path)));
    $mime_type = (string) (metis_check_filetype($filename, $allowed_mimes)['type'] ?? '');
    if ($mime_type === '') $mime_type = 'application/octet-stream';

    metis_newsletter_audit_log('attachment_uploaded', 'asset', 0, [
        'filename' => $filename,
        'mime' => $mime_type,
        'size' => $size,
        'url' => $url,
    ]);

    metis_send_json_success([
        'url' => $url,
        'path' => $path,
        'name' => $filename,
        'mime' => $mime_type,
        'size' => $size,
    ]);
});
