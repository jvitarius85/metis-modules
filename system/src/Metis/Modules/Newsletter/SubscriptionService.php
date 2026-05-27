<?php
declare(strict_types=1);

namespace Metis\Modules\Newsletter;

final class SubscriptionService {
    public static function upsert(array $input): array {
        NewsletterModule::ensureSchema();

        $db = \metis_db();
        $subs_table = \Metis_Tables::get('newsletter_subs');
        $suppressions_table = \Metis_Tables::get('newsletter_suppressions');

        $email = \metis_email_clean((string) ($input['email'] ?? ''));
        $first_name = \metis_text_clean((string) ($input['first_name'] ?? ''));
        $last_name = \metis_text_clean((string) ($input['last_name'] ?? ''));
        $list_id = (int) ($input['list_id'] ?? 0);
        $status = strtolower(trim((string) ($input['status'] ?? 'subscribed')));
        $status = in_array($status, ['subscribed', 'unsubscribed', 'bounced', 'rejected'], true) ? $status : 'subscribed';

        if ($email === '' || $list_id < 1) {
            return ['success' => false, 'status' => 400, 'message' => 'Email and list are required.'];
        }

        $contact_id = ContactService::findOrCreateContactId($email, $first_name, $last_name);
        if ($contact_id < 1) {
            return ['success' => false, 'status' => 500, 'message' => 'Unable to resolve contact.'];
        }

        $existing_id = (int) $db->scalar(
            "SELECT id FROM {$subs_table} WHERE contact_id = %d AND list_id = %d LIMIT 1",
            [$contact_id, $list_id]
        );

        $now = \metis_current_time('mysql');
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
                        'suppression_code' => \metis_generate_code('NS', $suppressions_table, 'suppression_code'),
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
}
