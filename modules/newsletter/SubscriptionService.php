<?php
declare(strict_types=1);

namespace Metis\Modules\Newsletter;

final class SubscriptionService {
    public const DEFAULT_SIGNUP_FORM_REF = '__newsletter_default_signup__';
    public const DEFAULT_LIST_NAME = 'Newsletter';

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
        $source = \metis_key_clean((string) ($input['source'] ?? 'metis_manual'));
        $status = in_array($status, ['subscribed', 'unsubscribed', 'bounced', 'rejected'], true) ? $status : 'subscribed';
        if ($source === '') {
            $source = 'metis_manual';
        }

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
            'source' => $source,
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

    public static function defaultListId(): int {
        NewsletterModule::ensureSchema();

        $lists_table = \Metis_Tables::get('newsletter_lists');
        if ($lists_table === '') {
            return 0;
        }

        return max(0, (int) \metis_db()->scalar(
            "SELECT id
             FROM {$lists_table}
             WHERE LOWER(name) = %s
             ORDER BY is_active DESC, id ASC
             LIMIT 1",
            [ strtolower(self::DEFAULT_LIST_NAME) ]
        ));
    }

    public static function bulkSubscribeExistingContacts(int $list_id, array $contact_ids, string $source = 'metis_bulk_contacts'): array {
        NewsletterModule::ensureSchema();

        $list_id = max(0, $list_id);
        $contact_ids = array_values(array_unique(array_filter(array_map('intval', $contact_ids), static fn (int $id): bool => $id > 0)));
        $source = \metis_key_clean($source);
        if ($source === '') {
            $source = 'metis_bulk_contacts';
        }

        if ($list_id < 1 || $contact_ids === []) {
            return ['success' => false, 'status' => 400, 'message' => 'List and contacts are required.'];
        }

        $db = \metis_db();
        $lists_table = \Metis_Tables::get('newsletter_lists');
        $contacts_table = \Metis_Tables::get('contacts');
        $subs_table = \Metis_Tables::get('newsletter_subs');
        $suppressions_table = \Metis_Tables::get('newsletter_suppressions');

        $list_exists = (int) $db->scalar(
            "SELECT id FROM {$lists_table} WHERE id = %d LIMIT 1",
            [$list_id]
        );
        if ($list_exists < 1) {
            return ['success' => false, 'status' => 404, 'message' => 'List not found.'];
        }

        $resolved_contacts = [];
        foreach (array_chunk($contact_ids, 250) as $contact_id_chunk) {
            $placeholders = implode(',', array_fill(0, count($contact_id_chunk), '%d'));
            $rows = $db->fetchAll(
                "SELECT id, LOWER(TRIM(email)) AS email
                 FROM {$contacts_table}
                 WHERE id IN ({$placeholders})
                   AND email IS NOT NULL
                   AND TRIM(email) <> ''",
                $contact_id_chunk
            ) ?: [];

            foreach ($rows as $row) {
                $contact_id = (int) ($row['id'] ?? 0);
                $email = strtolower(trim((string) ($row['email'] ?? '')));
                if ($contact_id < 1 || $email === '' || !\metis_email_is_valid($email)) {
                    continue;
                }
                $resolved_contacts[$contact_id] = $email;
            }
        }

        if ($resolved_contacts === []) {
            return ['success' => false, 'status' => 422, 'message' => 'No eligible contacts with email addresses were found.'];
        }

        $now = \metis_current_time('mysql');
        $resolved_ids = array_keys($resolved_contacts);
        $resolved_emails = array_values(array_unique(array_values($resolved_contacts)));

        foreach (array_chunk($resolved_ids, 250) as $id_chunk) {
            $id_placeholders = implode(',', array_fill(0, count($id_chunk), '%d'));
            $db->execute(
                $db->prepare(
                    "UPDATE {$suppressions_table}
                     SET is_active = 0, updated_at = %s
                     WHERE is_active = 1
                       AND contact_id IN ({$id_placeholders})",
                    ...array_merge([$now], $id_chunk)
                )
            );
        }

        foreach (array_chunk($resolved_emails, 250) as $email_chunk) {
            $email_placeholders = implode(',', array_fill(0, count($email_chunk), '%s'));
            $db->execute(
                $db->prepare(
                    "UPDATE {$suppressions_table}
                     SET is_active = 0, updated_at = %s
                     WHERE is_active = 1
                       AND email IN ({$email_placeholders})",
                    ...array_merge([$now], $email_chunk)
                )
            );
        }

        $processed = 0;
        foreach (array_chunk($resolved_ids, 200) as $id_chunk) {
            $value_sql = [];
            $params = [];
            foreach ($id_chunk as $contact_id) {
                $value_sql[] = '(%d, %d, %s, %s, %s, %s, %d, %s, %s, %s)';
                $params[] = $contact_id;
                $params[] = $list_id;
                $params[] = 'subscribed';
                $params[] = $source;
                $params[] = $now;
                $params[] = null;
                $params[] = 0;
                $params[] = $now;
                $params[] = $now;
                $params[] = $now;
            }

            if ($value_sql === []) {
                continue;
            }

            $sql = "INSERT INTO {$subs_table}
                (contact_id, list_id, status, source, subscribed_at, unsubscribed_at, bounce_count, last_event_at, created_at, updated_at)
                VALUES " . implode(', ', $value_sql) . "
                ON DUPLICATE KEY UPDATE
                    status = VALUES(status),
                    source = VALUES(source),
                    subscribed_at = VALUES(subscribed_at),
                    unsubscribed_at = VALUES(unsubscribed_at),
                    last_event_at = VALUES(last_event_at),
                    updated_at = VALUES(updated_at)";

            $ok = $db->execute($db->prepare($sql, ...$params));
            if ($ok === false) {
                return ['success' => false, 'status' => 500, 'message' => 'Failed to update subscriptions.'];
            }

            $processed += count($id_chunk);
        }

        return [
            'success' => true,
            'status' => 200,
            'message' => 'Contacts added to list.',
            'list_id' => $list_id,
            'processed_count' => $processed,
            'skipped_count' => max(0, count($contact_ids) - count($resolved_contacts)),
        ];
    }
}
