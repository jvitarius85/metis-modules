<?php
declare(strict_types=1);

namespace Metis\Modules\Newsletter;

final class ReadService {
    public static function editorId( string $editor_key, string $context ): int {
        $editor_key = trim( $editor_key );
        if ( $editor_key === '' ) {
            return 0;
        }

        if ( $context === 'newsletter_template' ) {
            return TemplateService::resolveId( $editor_key );
        }

        return CampaignService::resolveId( $editor_key );
    }

    public static function legacyCampaignRef( int $campaign_id ): string {
        if ( $campaign_id < 1 ) {
            return '';
        }

        $campaign_code = CampaignService::codeById( $campaign_id );
        return $campaign_code !== '' ? $campaign_code : (string) $campaign_id;
    }

    public static function campaignsSnapshot( string $campaign_view, bool $compose_mode, int $edit_campaign_id, string $edit_campaign_code ): array {
        $db = \metis_db();
        $lists_table = \Metis_Tables::get('newsletter_lists');
        $templates_table = \Metis_Tables::get('newsletter_templates');
        $campaigns_table = \Metis_Tables::get('newsletter_campaigns');
        $campaign_lists_table = \Metis_Tables::get('newsletter_campaign_lists');

        $lists = $db->fetchAll(
            "SELECT id, name, description, is_active FROM {$lists_table} WHERE is_active = 1 ORDER BY name ASC"
        ) ?: [];

        $templates = $db->fetchAll(
            "SELECT id, template_code, name, subject, from_name, from_email, reply_to, doc_json, html_body FROM {$templates_table} WHERE is_active = 1 ORDER BY updated_at DESC, id DESC"
        ) ?: [];

        $campaign_where = "WHERE c.campaign_type = 'campaign'";
        if ($campaign_view === 'active') {
            $campaign_where .= " AND c.status <> 'archived'";
        } elseif ($campaign_view === 'archived') {
            $campaign_where .= " AND c.status = 'archived'";
        }

        $campaigns = $db->fetchAll(
            "SELECT c.*, t.name AS template_name
             FROM {$campaigns_table} c
             LEFT JOIN {$templates_table} t ON t.id = c.template_id
             {$campaign_where}
             ORDER BY c.created_at DESC, c.id DESC
             LIMIT 200"
        ) ?: [];

        $campaign_lists = $db->fetchAll(
            "SELECT cl.campaign_id, cl.list_id, l.name
             FROM {$campaign_lists_table} cl
             INNER JOIN {$lists_table} l ON l.id = cl.list_id
             ORDER BY cl.campaign_id ASC, l.name ASC"
        ) ?: [];

        $campaign_lists_map = [];
        foreach ($campaign_lists as $cl) {
            $cid = (int) ($cl['campaign_id'] ?? 0);
            if ($cid < 1) {
                continue;
            }
            if (!isset($campaign_lists_map[$cid])) {
                $campaign_lists_map[$cid] = [];
            }
            $campaign_lists_map[$cid][] = [
                'id' => (int) ($cl['list_id'] ?? 0),
                'name' => (string) ($cl['name'] ?? ''),
            ];
        }

        $selected_campaign = null;
        if ($compose_mode && ($edit_campaign_code !== '' || $edit_campaign_id > 0)) {
            if ($edit_campaign_code !== '') {
                $selected_campaign = $db->fetchOne(
                    "SELECT c.*, t.name AS template_name, t.template_code AS template_code
                     FROM {$campaigns_table} c
                     LEFT JOIN {$templates_table} t ON t.id = c.template_id
                     WHERE c.campaign_code = %s
                     LIMIT 1",
                    [ $edit_campaign_code ]
                );
            } else {
                $selected_campaign = $db->fetchOne(
                    "SELECT c.*, t.name AS template_name, t.template_code AS template_code
                     FROM {$campaigns_table} c
                     LEFT JOIN {$templates_table} t ON t.id = c.template_id
                     WHERE c.id = %d
                     LIMIT 1",
                    [ $edit_campaign_id ]
                );
            }
        }

        return [
            'lists' => $lists,
            'templates' => $templates,
            'campaigns' => $campaigns,
            'campaign_lists_map' => $campaign_lists_map,
            'selected_campaign' => is_array( $selected_campaign ) ? $selected_campaign : null,
        ];
    }

    public static function announcementsSnapshot(): array {
        $db = \metis_db();
        $lists_table = \Metis_Tables::get('newsletter_lists');
        $campaigns_table = \Metis_Tables::get('newsletter_campaigns');
        $campaign_lists_table = \Metis_Tables::get('newsletter_campaign_lists');

        $lists = $db->fetchAll(
            "SELECT id, name, description, is_active FROM {$lists_table} WHERE is_active = 1 ORDER BY name ASC"
        ) ?: [];

        $announcements = $db->fetchAll(
            "SELECT id, campaign_code, campaign_type, name, subject, status, queued_at, sent_at, updated_at, total_recipients, sent_count, open_count, click_count
             FROM {$campaigns_table}
             WHERE campaign_type = %s
             ORDER BY created_at DESC, id DESC
             LIMIT 200",
            [ CampaignService::TYPE_ANNOUNCEMENT_BLAST ]
        ) ?: [];

        $announcement_ids = array_values(array_filter(array_map(
            static fn(array $row): int => (int) ($row['id'] ?? 0),
            $announcements
        )));

        $announcement_lists_map = [];
        if (!empty($announcement_ids)) {
            $placeholders = implode(',', array_fill(0, count($announcement_ids), '%d'));
            $campaign_lists = $db->fetchAll(
                "SELECT cl.campaign_id, cl.list_id, l.name
                 FROM {$campaign_lists_table} cl
                 INNER JOIN {$lists_table} l ON l.id = cl.list_id
                 WHERE cl.campaign_id IN ({$placeholders})
                 ORDER BY cl.campaign_id ASC, l.name ASC",
                $announcement_ids
            ) ?: [];

            foreach ($campaign_lists as $cl) {
                $cid = (int) ($cl['campaign_id'] ?? 0);
                if ($cid < 1) {
                    continue;
                }
                if (!isset($announcement_lists_map[$cid])) {
                    $announcement_lists_map[$cid] = [];
                }
                $announcement_lists_map[$cid][] = [
                    'id' => (int) ($cl['list_id'] ?? 0),
                    'name' => (string) ($cl['name'] ?? ''),
                ];
            }
        }

        return [
            'lists' => $lists,
            'announcements' => $announcements,
            'announcement_lists_map' => $announcement_lists_map,
        ];
    }

    public static function dashboardSnapshot(): array {
        $db = \metis_db();
        $lists_table = \Metis_Tables::get('newsletter_lists');
        $subs_table = \Metis_Tables::get('newsletter_subs');
        $campaigns_table = \Metis_Tables::get('newsletter_campaigns');
        $messages_table = \Metis_Tables::get('newsletter_messages');
        $contacts_table = \Metis_Tables::get('contacts');

        return [
            'kpi_lists' => (int) $db->scalar("SELECT COUNT(*) FROM {$lists_table} WHERE is_active = 1"),
            'kpi_campaigns' => (int) $db->scalar(
                "SELECT COUNT(*) FROM {$campaigns_table} WHERE campaign_type = %s",
                [ CampaignService::TYPE_CAMPAIGN ]
            ),
            'kpi_blasts' => (int) $db->scalar(
                "SELECT COUNT(*) FROM {$campaigns_table} WHERE campaign_type = %s",
                [ CampaignService::TYPE_ANNOUNCEMENT_BLAST ]
            ),
            'kpi_subscribers' => (int) $db->scalar("SELECT COUNT(*) FROM {$subs_table} WHERE status = 'subscribed'"),
            'kpi_queued' => (int) $db->scalar("SELECT COUNT(*) FROM {$messages_table} WHERE status = 'queued'"),
            'kpi_sent_total' => (int) $db->scalar("SELECT COUNT(*) FROM {$messages_table} WHERE status = 'sent'"),
            'kpi_30d' => (int) $db->scalar(
                "SELECT COUNT(*) FROM {$messages_table} WHERE status='sent' AND sent_at >= %s",
                [ ( new \DateTimeImmutable( 'now', \metis_newsletter_resolved_timezone() ) )->modify( '-30 days' )->format( 'Y-m-d H:i:s' ) ]
            ),
            'recent_subscribers' => $db->fetchAll(
                "SELECT
                    c.cid,
                    c.first_name,
                    c.last_name,
                    c.email,
                    GROUP_CONCAT(DISTINCT l.name ORDER BY l.name SEPARATOR '||') AS list_names,
                    MAX(s.updated_at) AS updated_at
                 FROM {$subs_table} s
                 INNER JOIN {$contacts_table} c ON c.id = s.contact_id
                 INNER JOIN {$lists_table} l ON l.id = s.list_id
                 WHERE s.status = 'subscribed' AND l.is_active = 1
                 GROUP BY c.id, c.cid, c.first_name, c.last_name, c.email
                 ORDER BY updated_at DESC
                 LIMIT 7"
            ) ?: [],
            'recent_campaigns' => $db->fetchAll(
                "SELECT c.id, c.campaign_code, c.name, c.status, c.updated_at, c.sent_count, c.total_recipients, c.open_count, c.click_count
                 FROM {$campaigns_table} c
                 WHERE c.campaign_type = %s
                 ORDER BY c.updated_at DESC, c.id DESC
                 LIMIT 7",
                [ CampaignService::TYPE_CAMPAIGN ]
            ) ?: [],
        ];
    }


    public static function subscribersSnapshot(): array {
        $db = \metis_db();
        $lists_table = \Metis_Tables::get('newsletter_lists');
        $subs_table = \Metis_Tables::get('newsletter_subs');
        $contacts_table = \Metis_Tables::get('contacts');

        return [
            'list_rows' => $db->fetchAll(
                "SELECT id, name FROM {$lists_table} WHERE is_active = 1 ORDER BY name ASC"
            ) ?: [],
            'rows' => $db->fetchAll(
                "SELECT
                    c.cid,
                    c.first_name,
                    c.last_name,
                    c.email,
                    GROUP_CONCAT(DISTINCT l.name ORDER BY l.name SEPARATOR '||') AS list_names,
                    MAX(s.updated_at) AS updated_at
                 FROM {$subs_table} s
                 INNER JOIN {$contacts_table} c ON c.id = s.contact_id
                 INNER JOIN {$lists_table} l ON l.id = s.list_id
                 WHERE s.status = 'subscribed' AND l.is_active = 1
                 GROUP BY c.id, c.cid, c.first_name, c.last_name, c.email
                 ORDER BY updated_at DESC
                 LIMIT 1000"
            ) ?: [],
        ];
    }

    public static function listsSnapshot( int $selected_list_id ): array {
        $db = \metis_db();
        $lists_table = \Metis_Tables::get('newsletter_lists');
        $subs_table = \Metis_Tables::get('newsletter_subs');
        $contacts_table = \Metis_Tables::get('contacts');

        $lists = $db->fetchAll(
            "SELECT l.id, l.list_key, l.name, l.description, l.is_active, l.updated_at,
                    COALESCE(SUM(CASE WHEN s.status='subscribed' THEN 1 ELSE 0 END), 0) AS subscribed_count,
                    COALESCE(SUM(CASE WHEN s.status IN ('bounced','rejected') THEN 1 ELSE 0 END), 0) AS blocked_count
             FROM {$lists_table} l
             LEFT JOIN {$subs_table} s ON s.list_id = l.id
             GROUP BY l.id
             ORDER BY l.name ASC"
        ) ?: [];

        $selected_list = null;
        foreach ($lists as $list_row) {
            if ((int) ($list_row['id'] ?? 0) === $selected_list_id) {
                $selected_list = $list_row;
                break;
            }
        }

        $list_subscribers = [];
        if ($selected_list_id > 0) {
            $list_subscribers = $db->fetchAll(
                "SELECT s.id, s.status, s.updated_at, c.cid, c.first_name, c.last_name, c.email
                 FROM {$subs_table} s
                 INNER JOIN {$contacts_table} c ON c.id = s.contact_id
                 WHERE s.list_id = %d
                   AND s.status = 'subscribed'
                 ORDER BY c.first_name ASC, c.last_name ASC, c.email ASC
                 LIMIT 500",
                [ $selected_list_id ]
            ) ?: [];
        }

        return [
            'lists' => $lists,
            'selected_list' => $selected_list,
            'list_subscribers' => $list_subscribers,
        ];
    }

    public static function listDetailSnapshot( int $list_id ): array {
        $db = \metis_db();
        $lists_table = \Metis_Tables::get('newsletter_lists');
        $subs_table = \Metis_Tables::get('newsletter_subs');
        $contacts_table = \Metis_Tables::get('contacts');

        if ( $list_id < 1 ) {
            return [
                'selected_list' => null,
                'list_subscribers' => [],
            ];
        }

        $selected_list = $db->fetchOne(
            "SELECT l.id, l.list_key, l.name, l.description, l.is_active, l.updated_at,
                    COALESCE(SUM(CASE WHEN s.status='subscribed' THEN 1 ELSE 0 END), 0) AS subscribed_count,
                    COALESCE(SUM(CASE WHEN s.status IN ('bounced','rejected') THEN 1 ELSE 0 END), 0) AS blocked_count
             FROM {$lists_table} l
             LEFT JOIN {$subs_table} s ON s.list_id = l.id
             WHERE l.id = %d
             GROUP BY l.id
             LIMIT 1",
            [ $list_id ]
        );

        if ( ! is_array( $selected_list ) || $selected_list === [] ) {
            return [
                'selected_list' => null,
                'list_subscribers' => [],
            ];
        }

        $list_subscribers = $db->fetchAll(
            "SELECT c.id AS contact_id, s.id, s.status, s.updated_at, c.cid, c.first_name, c.last_name, c.email
             FROM {$subs_table} s
             INNER JOIN {$contacts_table} c ON c.id = s.contact_id
             WHERE s.list_id = %d
               AND s.status = 'subscribed'
             ORDER BY c.first_name ASC, c.last_name ASC, c.email ASC
             LIMIT 500",
            [ $list_id ]
        ) ?: [];

        return [
            'selected_list' => $selected_list,
            'list_subscribers' => $list_subscribers,
        ];
    }
}
