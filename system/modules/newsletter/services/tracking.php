<?php
if (!defined('METIS_ROOT')) exit;

function metis_newsletter_unsubscribe_url_from_message_code(string $message_code): string {
    return metis_home_url('/metis/v1/newsletter/unsubscribe/' . rawurlencode($message_code));
}

function metis_newsletter_manage_url_from_message_code(string $message_code): string {
    return metis_home_url('/manage/');
}

function metis_newsletter_public_unsubscribe_url(string $contact_ref, string $list_ref = ''): string {
    $url = metis_home_url('/n/unsubscribe/' . rawurlencode(trim($contact_ref)) . '/');
    $list_ref = trim($list_ref);
    if ($list_ref !== '') {
        $url = metis_add_query_arg(['list' => $list_ref], $url);
    }
    return $url;
}

function metis_newsletter_public_manage_url(string $contact_ref): string {
    return metis_home_url('/manage/');
}

function metis_newsletter_public_view_url(string $newsletter_ref): string {
    return metis_home_url('/n/view/' . rawurlencode(trim($newsletter_ref)) . '/');
}

function metis_newsletter_public_signup_url(): string {
    return metis_home_url('/n/signup/');
}

function metis_newsletter_open_pixel_url_from_message_code(string $message_code): string {
    return metis_home_url('/metis/v1/newsletter/open/' . rawurlencode($message_code) . '.gif');
}

function metis_newsletter_click_url_from_message_code(string $message_code, string $target_url): string {
    return metis_home_url('/metis/v1/newsletter/click/' . rawurlencode($message_code)) . '?u=' . rawurlencode(base64_encode($target_url));
}

function metis_newsletter_inject_click_tracking(string $html, string $message_code): string {
    if ($html === '' || $message_code === '') return $html;
    return preg_replace_callback('/href=(["\'])(.*?)\1/i', static function (array $m) use ($message_code) {
        $url = (string) ($m[2] ?? '');
        if ($url === '' || stripos($url, 'mailto:') === 0 || stripos($url, '#') === 0) return $m[0];
        $tracked = metis_newsletter_click_url_from_message_code($message_code, $url);
        return 'href=' . $m[1] . metis_escape_url($tracked) . $m[1];
    }, $html) ?: $html;
}

function metis_newsletter_track_event_for_message(string $message_code, string $event_type, string $reason = ''): bool {
    $db = metis_db();
    $messages_table = Metis_Tables::get('newsletter_messages');
    $events_table = Metis_Tables::get('newsletter_events');
    $subs_table = Metis_Tables::get('newsletter_subs');
    $suppressions_table = Metis_Tables::get('newsletter_suppressions');

    $message = $db->fetchOne( "SELECT * FROM {$messages_table} WHERE message_code = %s LIMIT 1", [ $message_code ] );
    if (!$message) return false;

    $message_id = (int) ($message['id'] ?? 0);
    $campaign_id = (int) ($message['campaign_id'] ?? 0);
    $contact_id = (int) ($message['contact_id'] ?? 0);
    $email = strtolower(trim((string) ($message['email'] ?? '')));
    $now = metis_current_time('mysql');
    $event_type = metis_key_clean($event_type);

    if ($event_type === 'open') {
        if (empty($message['opened_at'])) {
            $db->update( $messages_table, [ 'opened_at' => $now, 'updated_at' => $now ], [ 'id' => $message_id ] );
        }
    } elseif ($event_type === 'click') {
        if (empty($message['clicked_at'])) {
            $db->update( $messages_table, [ 'clicked_at' => $now, 'updated_at' => $now ], [ 'id' => $message_id ] );
        }
    } elseif (in_array($event_type, ['bounce', 'bounced', 'rejected', 'unsubscribe'], true)) {
        $new_status = $event_type === 'unsubscribe' ? 'unsubscribed' : ($event_type === 'rejected' ? 'rejected' : 'bounced');
        $db->update(
            $subs_table,
            [
                'status' => $new_status,
                'unsubscribed_at' => $now,
                'last_event_at' => $now,
                'updated_at' => $now,
                'bounce_count' => in_array($new_status, ['bounced', 'rejected'], true) ? 1 : 0,
            ],
            ['contact_id' => $contact_id]
        );
        if ($email !== '') {
            $exists = (int) $db->scalar(
                "SELECT id FROM {$suppressions_table} WHERE email = %s AND is_active = 1 LIMIT 1",
                [ $email ]
            );
            if ($exists < 1) {
                $db->insert(
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
                    ]
                );
            }
        }
    }

    $db->insert(
        $events_table,
        [
            'event_code' => metis_generate_code('NE', $events_table, 'event_code'),
            'message_id' => $message_id,
            'campaign_id' => $campaign_id,
            'contact_id' => $contact_id > 0 ? $contact_id : null,
            'email' => $email,
            'event_type' => $event_type,
            'reason' => metis_text_clean($reason),
            'source' => 'tracking',
            'event_at' => $now,
        ]
    );

    return true;
}
