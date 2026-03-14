<?php
if (!defined('ABSPATH')) exit;

function metis_newsletter_unsubscribe_url_from_message_code(string $message_code): string {
    return home_url('/metis/v1/newsletter/unsubscribe/' . rawurlencode($message_code));
}

function metis_newsletter_manage_url_from_message_code(string $message_code): string {
    return home_url('/metis/v1/newsletter/manage/' . rawurlencode($message_code));
}

function metis_newsletter_open_pixel_url_from_message_code(string $message_code): string {
    return home_url('/metis/v1/newsletter/open/' . rawurlencode($message_code) . '.gif');
}

function metis_newsletter_click_url_from_message_code(string $message_code, string $target_url): string {
    return home_url('/metis/v1/newsletter/click/' . rawurlencode($message_code)) . '?u=' . rawurlencode(base64_encode($target_url));
}

function metis_newsletter_inject_click_tracking(string $html, string $message_code): string {
    if ($html === '' || $message_code === '') return $html;
    return preg_replace_callback('/href=(["\'])(.*?)\1/i', static function (array $m) use ($message_code) {
        $url = (string) ($m[2] ?? '');
        if ($url === '' || stripos($url, 'mailto:') === 0 || stripos($url, '#') === 0) return $m[0];
        $tracked = metis_newsletter_click_url_from_message_code($message_code, $url);
        return 'href=' . $m[1] . esc_url($tracked) . $m[1];
    }, $html) ?: $html;
}

function metis_newsletter_track_event_for_message(string $message_code, string $event_type, string $reason = ''): bool {
    global $wpdb;
    $messages_table = Metis_Tables::get('newsletter_messages');
    $events_table = Metis_Tables::get('newsletter_events');
    $subs_table = Metis_Tables::get('newsletter_subs');
    $suppressions_table = Metis_Tables::get('newsletter_suppressions');

    $message = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$messages_table} WHERE message_code = %s LIMIT 1", $message_code), ARRAY_A);
    if (!$message) return false;

    $message_id = (int) ($message['id'] ?? 0);
    $campaign_id = (int) ($message['campaign_id'] ?? 0);
    $contact_id = (int) ($message['contact_id'] ?? 0);
    $email = strtolower(trim((string) ($message['email'] ?? '')));
    $now = current_time('mysql');
    $event_type = sanitize_key($event_type);

    if ($event_type === 'open') {
        if (empty($message['opened_at'])) {
            $wpdb->update($messages_table, ['opened_at' => $now, 'updated_at' => $now], ['id' => $message_id], ['%s', '%s'], ['%d']);
        }
    } elseif ($event_type === 'click') {
        if (empty($message['clicked_at'])) {
            $wpdb->update($messages_table, ['clicked_at' => $now, 'updated_at' => $now], ['id' => $message_id], ['%s', '%s'], ['%d']);
        }
    } elseif (in_array($event_type, ['bounce', 'bounced', 'rejected', 'unsubscribe'], true)) {
        $new_status = $event_type === 'unsubscribe' ? 'unsubscribed' : ($event_type === 'rejected' ? 'rejected' : 'bounced');
        $wpdb->update(
            $subs_table,
            [
                'status' => $new_status,
                'unsubscribed_at' => $now,
                'last_event_at' => $now,
                'updated_at' => $now,
                'bounce_count' => in_array($new_status, ['bounced', 'rejected'], true) ? 1 : 0,
            ],
            ['contact_id' => $contact_id],
            ['%s', '%s', '%s', '%s', '%d'],
            ['%d']
        );
        if ($email !== '') {
            $exists = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$suppressions_table} WHERE email = %s AND is_active = 1 LIMIT 1",
                $email
            ));
            if ($exists < 1) {
                $wpdb->insert(
                    $suppressions_table,
                    [
                        'suppression_code' => metis_generate_code('NS', $suppressions_table, 'suppression_code'),
                        'contact_id' => $contact_id > 0 ? $contact_id : null,
                        'email' => $email,
                        'reason' => $new_status,
                        'source' => 'event',
                        'is_active' => 1,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ],
                    ['%s', '%d', '%s', '%s', '%s', '%d', '%s', '%s']
                );
            }
        }
    }

    $wpdb->insert(
        $events_table,
        [
            'event_code' => metis_generate_code('NE', $events_table, 'event_code'),
            'message_id' => $message_id,
            'campaign_id' => $campaign_id,
            'contact_id' => $contact_id > 0 ? $contact_id : null,
            'email' => $email,
            'event_type' => $event_type,
            'reason' => sanitize_text_field($reason),
            'source' => 'tracking',
            'event_at' => $now,
        ],
        ['%s', '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s']
    );

    return true;
}
