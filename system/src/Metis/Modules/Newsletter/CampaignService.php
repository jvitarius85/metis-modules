<?php
declare(strict_types=1);

namespace Metis\Modules\Newsletter;

final class CampaignService {
    public const TYPE_CAMPAIGN = 'campaign';
    public const TYPE_ANNOUNCEMENT_BLAST = 'announcement_blast';

    public static function normalizeType( string $campaign_type ): string {
        $campaign_type = trim( strtolower( $campaign_type ) );
        return $campaign_type === self::TYPE_ANNOUNCEMENT_BLAST ? self::TYPE_ANNOUNCEMENT_BLAST : self::TYPE_CAMPAIGN;
    }

    public static function isAnnouncementBlast( array $campaign ): bool {
        return self::normalizeType( (string) ( $campaign['campaign_type'] ?? '' ) ) === self::TYPE_ANNOUNCEMENT_BLAST;
    }

    public static function isWordPressArchiveImport( array $campaign ): bool {
        $audience = trim( (string) ( $campaign['audience_json'] ?? '' ) );
        if ( $audience === '' ) {
            return false;
        }

        $decoded = json_decode( $audience, true );
        if ( ! is_array( $decoded ) ) {
            return false;
        }

        return trim( (string) ( $decoded['source'] ?? '' ) ) === 'wordpress_newsletter_archive_import';
    }

    public static function codeById( int $campaign_id ): string {
        if ( $campaign_id < 1 ) {
            return '';
        }

        return (string) \metis_db()->scalar(
            'SELECT campaign_code FROM ' . \Metis_Tables::get( 'newsletter_campaigns' ) . ' WHERE id = %d LIMIT 1',
            [ $campaign_id ]
        );
    }

    public static function resolveId( string $campaign_code ): int {
        $campaign_code = trim( $campaign_code );
        if ( $campaign_code === '' ) {
            return 0;
        }

        return (int) \metis_db()->scalar(
            'SELECT id FROM ' . \Metis_Tables::get( 'newsletter_campaigns' ) . ' WHERE campaign_code = %s LIMIT 1',
            [ $campaign_code ]
        );
    }

    /**
     * @param array<int,int> $list_ids
     */
    public static function normalizeListIds( array $list_ids ): array {
        $list_ids = array_values( array_unique( array_map( 'intval', $list_ids ) ) );
        if ( $list_ids === [] ) {
            return [];
        }

        $lists_table = \Metis_Tables::get( 'newsletter_lists' );
        $query = \metis_db()->prepare(
            'SELECT id FROM ' . $lists_table . ' WHERE id IN (' . implode( ',', array_fill( 0, count( $list_ids ), '%d' ) ) . ')',
            ...$list_ids
        );

        return array_values( array_map( 'intval', \metis_db()->column( $query ) ) );
    }

    public static function save( int $campaign_id, array $payload, array $payload_formats, array $list_ids ): array {
        $db = \metis_db();
        $campaigns_table = \Metis_Tables::get( 'newsletter_campaigns' );
        $campaign_lists_table = \Metis_Tables::get( 'newsletter_campaign_lists' );

        if ( $campaign_id > 0 ) {
            $ok = $db->update( $campaigns_table, $payload, [ 'id' => $campaign_id ], $payload_formats, [ '%d' ] );
            if ( $ok === false ) {
                return [ 'success' => false, 'campaign_id' => 0 ];
            }
        } else {
            if ( \function_exists( 'metis_entity_id_service' ) ) {
                $payload = \metis_entity_id_service()->assignForInsert( 'newsletter_campaign', $payload );
            } else {
                $payload['campaign_code'] = \metis_generate_code( 'NC', $campaigns_table, 'campaign_code' );
            }
            $payload['created_by'] = \metis_current_user_id() ?: null;

            $payload_formats = [];
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
            foreach ( array_keys( $payload ) as $payload_key ) {
                $payload_formats[] = $field_formats[ $payload_key ] ?? '%s';
            }

            $ok = $db->insert( $campaigns_table, $payload, $payload_formats );
            if ( $ok === false ) {
                return [ 'success' => false, 'campaign_id' => 0 ];
            }

            $campaign_id = (int) $db->lastInsertId();
            if ( $campaign_id > 0 && \function_exists( 'metis_entity_id_service' ) ) {
                \metis_entity_id_service()->register( 'newsletter_campaign', $campaign_id, (string) ( $payload['newsletter_campaign_uid'] ?? $payload['campaign_code'] ?? '' ) );
            }
        }

        $delete_ok = $db->delete( $campaign_lists_table, [ 'campaign_id' => $campaign_id ], [ '%d' ] );
        if ( $delete_ok === false ) {
            return [ 'success' => false, 'campaign_id' => 0 ];
        }

        foreach ( $list_ids as $list_id ) {
            $ok = $db->insert(
                $campaign_lists_table,
                [ 'campaign_id' => $campaign_id, 'list_id' => $list_id ],
                [ '%d', '%d' ]
            );
            if ( $ok === false ) {
                return [ 'success' => false, 'campaign_id' => 0 ];
            }
        }

        return [
            'success' => true,
            'campaign_id' => $campaign_id,
            'campaign' => self::get( $campaign_id, '' ),
            'list_ids' => self::listIds( $campaign_id ),
        ];
    }

    /**
     * @param array<int,int> $list_ids
     */
    public static function replaceListIds( int $campaign_id, array $list_ids ): array {
        if ( $campaign_id < 1 ) {
            return [ 'success' => false, 'campaign_id' => 0 ];
        }

        $db = \metis_db();
        $campaign_lists_table = \Metis_Tables::get( 'newsletter_campaign_lists' );
        $list_ids = self::normalizeListIds( $list_ids );

        $delete_ok = $db->delete( $campaign_lists_table, [ 'campaign_id' => $campaign_id ], [ '%d' ] );
        if ( $delete_ok === false ) {
            return [ 'success' => false, 'campaign_id' => 0 ];
        }

        foreach ( $list_ids as $list_id ) {
            $ok = $db->insert(
                $campaign_lists_table,
                [ 'campaign_id' => $campaign_id, 'list_id' => $list_id ],
                [ '%d', '%d' ]
            );
            if ( $ok === false ) {
                return [ 'success' => false, 'campaign_id' => 0 ];
            }
        }

        return [
            'success' => true,
            'campaign_id' => $campaign_id,
            'campaign' => self::get( $campaign_id, '' ),
            'list_ids' => self::listIds( $campaign_id ),
        ];
    }

    public static function get( int $campaign_id, string $campaign_code = '' ): ?array {
        $campaigns_table = \Metis_Tables::get( 'newsletter_campaigns' );
        $templates_table = \Metis_Tables::get( 'newsletter_templates' );

        if ( trim( $campaign_code ) !== '' ) {
            $row = \metis_db()->fetchOne(
                "SELECT c.id, c.campaign_code, c.campaign_type, c.template_id, c.name, c.subject, c.from_name, c.from_email, c.reply_to, c.preheader,
                        c.doc_json, c.editor_body_html, c.html_body, c.text_body, c.status, c.scheduled_at, c.audience_json, c.attachments_json, c.updated_at,
                        t.template_code
                 FROM {$campaigns_table} c
                 LEFT JOIN {$templates_table} t ON t.id = c.template_id
                 WHERE c.campaign_code = %s
                 LIMIT 1",
                [ $campaign_code ]
            );
        } else {
            $row = \metis_db()->fetchOne(
                "SELECT c.id, c.campaign_code, c.campaign_type, c.template_id, c.name, c.subject, c.from_name, c.from_email, c.reply_to, c.preheader,
                        c.doc_json, c.editor_body_html, c.html_body, c.text_body, c.status, c.scheduled_at, c.audience_json, c.attachments_json, c.updated_at,
                        t.template_code
                 FROM {$campaigns_table} c
                 LEFT JOIN {$templates_table} t ON t.id = c.template_id
                 WHERE c.id = %d
                 LIMIT 1",
                [ $campaign_id ]
            );
        }

        return is_array( $row ) ? $row : null;
    }

    public static function rawById( int $campaign_id ): ?array {
        if ( $campaign_id < 1 ) {
            return null;
        }

        $row = \metis_db()->fetchOne(
            'SELECT * FROM ' . \Metis_Tables::get( 'newsletter_campaigns' ) . ' WHERE id = %d LIMIT 1',
            [ $campaign_id ]
        );

        return is_array( $row ) ? $row : null;
    }

    public static function firstListRef( int $campaign_id ): string {
        if ( $campaign_id < 1 ) {
            return '';
        }

        $campaign_lists_table = \Metis_Tables::get( 'newsletter_campaign_lists' );
        $lists_table = \Metis_Tables::get( 'newsletter_lists' );
        $row = \metis_db()->fetchOne(
            "SELECT l.newsletter_list_uid, l.list_key
             FROM {$campaign_lists_table} cl
             INNER JOIN {$lists_table} l ON l.id = cl.list_id
             WHERE cl.campaign_id = %d
             ORDER BY cl.list_id ASC
             LIMIT 1",
            [ $campaign_id ]
        );

        return is_array( $row ) ? trim( (string) ( ( $row['newsletter_list_uid'] ?? '' ) ?: ( $row['list_key'] ?? '' ) ) ) : '';
    }

    public static function archive( int $campaign_id ): bool {
        if ( $campaign_id < 1 ) {
            return false;
        }

        $ok = \metis_db()->update(
            \Metis_Tables::get( 'newsletter_campaigns' ),
            [
                'status' => 'archived',
                'archived_at' => \metis_current_time( 'mysql' ),
                'updated_at' => \metis_current_time( 'mysql' ),
            ],
            [ 'id' => $campaign_id ],
            [ '%s', '%s', '%s' ],
            [ '%d' ]
        );

        return $ok !== false;
    }

    public static function delete( int $campaign_id ): array {
        if ( $campaign_id < 1 ) {
            return [ 'success' => false, 'status' => 400, 'message' => 'Invalid campaign.' ];
        }

        $db = \metis_db();
        $campaigns_table = \Metis_Tables::get( 'newsletter_campaigns' );
        $campaign_lists_table = \Metis_Tables::get( 'newsletter_campaign_lists' );
        $messages_table = \Metis_Tables::get( 'newsletter_messages' );
        $events_table = \Metis_Tables::get( 'newsletter_events' );

        $campaign = $db->fetchOne(
            "SELECT id, status FROM {$campaigns_table} WHERE id = %d LIMIT 1",
            [ $campaign_id ]
        );
        if ( ! $campaign ) {
            return [ 'success' => false, 'status' => 404, 'message' => 'Campaign not found.' ];
        }

        $status = strtolower( (string) ( $campaign['status'] ?? 'draft' ) );
        if ( in_array( $status, [ 'sending', 'sent', 'archived' ], true ) ) {
            return [ 'success' => false, 'status' => 400, 'message' => 'Sent, sending, or archived campaigns cannot be deleted.' ];
        }

        $db->execute( $db->prepare( "DELETE FROM {$events_table} WHERE campaign_id = %d", $campaign_id ) );
        $db->execute( $db->prepare( "DELETE FROM {$messages_table} WHERE campaign_id = %d", $campaign_id ) );
        $db->execute( $db->prepare( "DELETE FROM {$campaign_lists_table} WHERE campaign_id = %d", $campaign_id ) );
        $ok = $db->delete( $campaigns_table, [ 'id' => $campaign_id ], [ '%d' ] );

        return [
            'success' => $ok !== false,
            'status' => $ok !== false ? 200 : 500,
            'message' => $ok !== false ? 'Campaign deleted.' : 'Unable to delete campaign.',
            'campaign_id' => $campaign_id,
            'campaign_status' => $status,
        ];
    }

    public static function status( int $campaign_id ): ?array {
        if ( $campaign_id < 1 ) {
            return null;
        }

        $db = \metis_db();
        $campaigns_table = \Metis_Tables::get( 'newsletter_campaigns' );
        $messages_table = \Metis_Tables::get( 'newsletter_messages' );
        $contacts_table = \Metis_Tables::get( 'contacts' );

        $campaign = $db->fetchOne(
            "SELECT id, campaign_code, name, subject, status, total_recipients, sent_count, failed_count, bounced_count, rejected_count, updated_at
             FROM {$campaigns_table}
             WHERE id = %d
             LIMIT 1",
            [ $campaign_id ]
        );
        if ( ! $campaign ) {
            return null;
        }

        $message_rows = $db->fetchAll(
            "SELECT m.id, m.email, m.status, m.sent_at, m.delivered_at, m.bounced_at, m.rejected_at, m.opened_at, m.clicked_at, m.last_error,
                    c.first_name, c.last_name, c.cid
             FROM {$messages_table} m
             LEFT JOIN {$contacts_table} c ON c.id = m.contact_id
             WHERE m.campaign_id = %d
             ORDER BY m.id ASC
             LIMIT 500",
            [ $campaign_id ]
        ) ?: [];

        $current = $db->fetchOne(
            "SELECT email, status
             FROM {$messages_table}
             WHERE campaign_id = %d AND status IN ('queued','sending')
             ORDER BY id ASC
             LIMIT 1",
            [ $campaign_id ]
        ) ?: [];

        $total = (int) ( $campaign['total_recipients'] ?? 0 );
        if ( $total < 1 ) {
            $total = (int) $db->scalar(
                "SELECT COUNT(*) FROM {$messages_table} WHERE campaign_id = %d",
                [ $campaign_id ]
            );
        }

        $sent = (int) ( $campaign['sent_count'] ?? 0 );
        $failed = (int) ( $campaign['failed_count'] ?? 0 );
        $bounced = (int) ( $campaign['bounced_count'] ?? 0 );
        $rejected = (int) ( $campaign['rejected_count'] ?? 0 );
        $processed = $sent + $failed + $bounced + $rejected;
        $progress_pct = $total > 0 ? min( 100, max( 0, (int) round( ( $processed / $total ) * 100 ) ) ) : 0;

        return [
            'campaign' => [
                'id' => (int) ( $campaign['id'] ?? 0 ),
                'campaign_code' => (string) ( $campaign['campaign_code'] ?? '' ),
                'name' => (string) ( $campaign['name'] ?? '' ),
                'subject' => (string) ( $campaign['subject'] ?? '' ),
                'status' => (string) ( $campaign['status'] ?? 'draft' ),
                'updated_at' => (string) ( $campaign['updated_at'] ?? '' ),
            ],
            'summary' => [
                'total' => $total,
                'sent' => $sent,
                'failed' => $failed,
                'bounced' => $bounced,
                'rejected' => $rejected,
                'processed' => $processed,
                'progress_pct' => $progress_pct,
                'current_email' => (string) ( $current['email'] ?? '' ),
                'current_status' => (string) ( $current['status'] ?? '' ),
            ],
            'messages' => array_values( array_map( static function ( array $row ): array {
                return [
                    'id' => (int) ( $row['id'] ?? 0 ),
                    'email' => (string) ( $row['email'] ?? '' ),
                    'status' => (string) ( $row['status'] ?? '' ),
                    'sent_at' => (string) ( $row['sent_at'] ?? '' ),
                    'delivered_at' => (string) ( $row['delivered_at'] ?? '' ),
                    'bounced_at' => (string) ( $row['bounced_at'] ?? '' ),
                    'rejected_at' => (string) ( $row['rejected_at'] ?? '' ),
                    'opened_at' => (string) ( $row['opened_at'] ?? '' ),
                    'clicked_at' => (string) ( $row['clicked_at'] ?? '' ),
                    'last_error' => (string) ( $row['last_error'] ?? '' ),
                    'first_name' => (string) ( $row['first_name'] ?? '' ),
                    'last_name' => (string) ( $row['last_name'] ?? '' ),
                    'cid' => (string) ( $row['cid'] ?? '' ),
                ];
            }, $message_rows ) ),
        ];
    }

    /**
     * @return array<int,int>
     */
    public static function listIds( int $campaign_id ): array {
        if ( $campaign_id < 1 ) {
            return [];
        }

        $campaign_lists_table = \Metis_Tables::get( 'newsletter_campaign_lists' );
        $rows = \metis_db()->fetchAll(
            "SELECT list_id FROM {$campaign_lists_table} WHERE campaign_id = %d ORDER BY list_id ASC",
            [ $campaign_id ]
        ) ?: [];

        $list_ids = [];
        foreach ( $rows as $row ) {
            $list_ids[] = (int) ( $row['list_id'] ?? 0 );
        }

        return $list_ids;
    }
}
