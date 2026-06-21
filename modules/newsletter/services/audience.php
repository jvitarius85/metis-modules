<?php
if (!defined('METIS_ROOT')) exit;

function metis_newsletter_decode_audience_json($audience_json): array {
    if (is_array($audience_json)) return $audience_json;
    if (!is_string($audience_json) || trim($audience_json) === '') return [];
    $decoded = json_decode($audience_json, true);
    return is_array($decoded) ? $decoded : [];
}

function metis_newsletter_collect_recipients(int $campaign_id, array $audience = []): array {
    $db = metis_db();

    $campaign_lists_table = Metis_Tables::get('newsletter_campaign_lists');
    $subs_table = Metis_Tables::get('newsletter_subs');
    $lists_table = Metis_Tables::get('newsletter_lists');
    $contacts_table = Metis_Tables::get('contacts');
    $details_table = Metis_Tables::get('contact_details');
    $suppressions_table = Metis_Tables::get('newsletter_suppressions');

    $list_ids = array_values(array_unique(array_map('intval', (array) ($audience['list_ids'] ?? []))));
    if (empty($list_ids)) {
        $list_ids = array_map('intval', $db->column(
            "SELECT list_id FROM {$campaign_lists_table} WHERE campaign_id = %d",
            [ $campaign_id ]
        ) ?: []);
    }
    $list_ids = array_values(array_filter($list_ids, static fn($x) => $x > 0));
    if (empty($list_ids)) return [];

    $placeholders = implode(',', array_fill(0, count($list_ids), '%d'));
    $query_params = $list_ids;
    $rows = $db->fetchAll(
        "SELECT DISTINCT
            c.id AS contact_id,
            c.cid AS contact_cid,
            c.did AS donor_id,
            LOWER(TRIM(c.email)) AS email,
            c.first_name,
            c.last_name,
            COALESCE(d_id.city, d_cid.city) AS city,
            COALESCE(d_id.state, d_cid.state) AS state,
            COALESCE(d_id.zip, d_cid.zip) AS zip,
            COALESCE(d_id.do_not_contact, d_cid.do_not_contact) AS do_not_contact,
            COALESCE(d_id.preferred_contact_method, d_cid.preferred_contact_method) AS preferred_contact_method,
            COALESCE(d_id.volunteer_status, d_cid.volunteer_status) AS volunteer_status,
            COALESCE(d_id.anonymous_donor, d_cid.anonymous_donor) AS anonymous_donor
         FROM {$subs_table} s
         INNER JOIN {$contacts_table} c ON c.id = s.contact_id
         INNER JOIN {$lists_table} l ON l.id = s.list_id
         LEFT JOIN {$details_table} d_id ON d_id.contact_id = c.id
         LEFT JOIN {$details_table} d_cid
                ON d_cid.contact_id IS NULL
               AND d_cid.contact_cid COLLATE utf8mb4_general_ci = c.cid COLLATE utf8mb4_general_ci
         LEFT JOIN {$suppressions_table} sup_id
                ON sup_id.contact_id = c.id
               AND sup_id.is_active = 1
         LEFT JOIN {$suppressions_table} sup_email
                ON sup_email.contact_id IS NULL
               AND sup_email.email COLLATE utf8mb4_general_ci = LOWER(TRIM(c.email)) COLLATE utf8mb4_general_ci
               AND sup_email.is_active = 1
         WHERE s.list_id IN ({$placeholders})
           AND s.status = 'subscribed'
           AND l.is_active = 1
           AND c.email IS NOT NULL
           AND TRIM(c.email) <> ''
           AND COALESCE(d_id.do_not_contact, d_cid.do_not_contact, 0) = 0
           AND sup_id.id IS NULL
           AND sup_email.id IS NULL",
        $query_params
    ) ?: [];
    if (empty($rows)) return [];

    $rules = (array) ($audience['rules'] ?? []);
    if (!empty($rules)) {
        $rule_match = strtolower(trim((string) ($audience['rule_match'] ?? 'all')));
        if (!in_array($rule_match, ['all', 'any'], true)) $rule_match = 'all';
        $rows = array_values(array_filter($rows, static function (array $row) use ($rules, $rule_match): bool {
            $results = [];
            foreach ($rules as $rule) {
                if (!is_array($rule)) continue;
                $field = (string) ($rule['field'] ?? '');
                $op = (string) ($rule['op'] ?? 'equals');
                $value = strtolower(trim((string) ($rule['value'] ?? '')));
                if ($field === '') continue;
                $actual = strtolower(trim((string) ($row[$field] ?? '')));

                if ($op === 'is_empty') {
                    $results[] = ($actual === '');
                    continue;
                }
                if ($op === 'is_not_empty') {
                    $results[] = ($actual !== '');
                    continue;
                }
                if ($value === '') continue;

                if ($op === 'contains') $results[] = (strpos($actual, $value) !== false);
                elseif ($op === 'not_contains') $results[] = (strpos($actual, $value) === false);
                elseif ($op === 'equals') $results[] = ($actual === $value);
                elseif ($op === 'not_equals') $results[] = ($actual !== $value);
                elseif ($op === 'starts_with') $results[] = (strpos($actual, $value) === 0);
                else $results[] = ($actual === $value);
            }

            if (empty($results)) return true;
            if ($rule_match === 'any') return in_array(true, $results, true);
            return !in_array(false, $results, true);
        }));
    }

    $engagement = (array) ($audience['engagement'] ?? []);
    $opened_days = isset($engagement['opened_within_days']) ? (int) $engagement['opened_within_days'] : 0;
    $clicked_days = isset($engagement['clicked_within_days']) ? (int) $engagement['clicked_within_days'] : 0;
    if (($opened_days > 0 || $clicked_days > 0) && !empty($rows)) {
        $messages_table = Metis_Tables::get('newsletter_messages');
        $lookback_days = max($opened_days, $clicked_days);
        $cutoff = (new DateTimeImmutable('now', metis_newsletter_resolved_timezone()))->modify('-' . $lookback_days . ' days')->format('Y-m-d H:i:s');
        $contact_ids = array_values(array_unique(array_map(static fn($r) => (int) ($r['contact_id'] ?? 0), $rows)));
        $contact_ids = array_values(array_filter($contact_ids, static fn($id) => $id > 0));
        if (!empty($contact_ids)) {
            $in = implode(',', array_fill(0, count($contact_ids), '%d'));
            $engaged = $db->fetchAll(
                "SELECT contact_id, MAX(opened_at) AS opened_at, MAX(clicked_at) AS clicked_at
                 FROM {$messages_table}
                 WHERE (opened_at >= %s OR clicked_at >= %s) AND contact_id IN ({$in})
                 GROUP BY contact_id",
                array_merge( [ $cutoff, $cutoff ], $contact_ids )
            ) ?: [];
            $engaged_map = [];
            foreach ($engaged as $e) $engaged_map[(int) ($e['contact_id'] ?? 0)] = $e;
            $rows = array_values(array_filter($rows, static function (array $row) use ($engaged_map, $opened_days, $clicked_days): bool {
                $cid = (int) ($row['contact_id'] ?? 0);
                if (!isset($engaged_map[$cid])) return false;
                $hit = $engaged_map[$cid];
                if ($opened_days > 0 && empty($hit['opened_at'])) return false;
                if ($clicked_days > 0 && empty($hit['clicked_at'])) return false;
                return true;
            }));
        }
    }

    return $rows;
}
