<?php
declare(strict_types=1);

namespace Metis\Modules\Board;

final class PacketRecipientService {
    public static function newsletterListExists( int $list_id ): bool {
        if ( $list_id < 1 ) {
            return false;
        }

        $newsletter_lists_table = \Metis_Tables::get( 'newsletter_lists' );
        if ( ! self::tableExists( $newsletter_lists_table ) ) {
            return false;
        }

        return (int) \metis_db()->scalar(
            "SELECT id FROM {$newsletter_lists_table} WHERE id = %d AND is_active = 1 LIMIT 1",
            [ $list_id ]
        ) > 0;
    }

    public static function collectNewsletterListRecipients( int $list_id ): array {
        if ( $list_id < 1 ) {
            return [];
        }

        if ( \function_exists( 'metis_newsletter_collect_recipients' ) ) {
            $rows = \metis_newsletter_collect_recipients( 0, [
                'list_ids' => [ $list_id ],
            ] );

            return array_values( array_filter( array_map( static function ( array $row ): array {
                $email = strtolower( trim( (string) ( $row['email'] ?? '' ) ) );
                if ( ! \metis_email_is_valid( $email ) ) {
                    return [];
                }

                return [
                    'email' => $email,
                    'first_name' => trim( (string) ( $row['first_name'] ?? '' ) ),
                    'display_name' => trim( (string) ( ( ( $row['first_name'] ?? '' ) . ' ' . ( $row['last_name'] ?? '' ) ) ?: ( $row['contact_cid'] ?? '' ) ) ),
                ];
            }, is_array( $rows ) ? $rows : [] ), static fn( array $row ): bool => ! empty( $row['email'] ) ) );
        }

        $db = \metis_db();
        $subs_table = \Metis_Tables::get( 'newsletter_subs' );
        $contacts_table = \Metis_Tables::get( 'contacts' );
        $suppressions_table = \Metis_Tables::get( 'newsletter_suppressions' );
        if ( ! self::tableExists( $subs_table ) || ! self::tableExists( $contacts_table ) ) {
            return [];
        }

        $join_suppressions = self::tableExists( $suppressions_table )
            ? " LEFT JOIN {$suppressions_table} sup ON (sup.contact_id = c.id OR sup.email = LOWER(TRIM(c.email))) AND sup.is_active = 1"
            : '';
        $suppression_where = self::tableExists( $suppressions_table ) ? ' AND sup.id IS NULL' : '';

        $rows = $db->fetchAll(
            "SELECT DISTINCT c.email, c.first_name, c.last_name, c.cid
             FROM {$subs_table} s
             INNER JOIN {$contacts_table} c ON c.id = s.contact_id
             {$join_suppressions}
             WHERE s.list_id = %d
               AND s.status = 'subscribed'
               AND c.email IS NOT NULL
               AND TRIM(c.email) <> ''
               {$suppression_where}
             ORDER BY c.first_name ASC, c.last_name ASC, c.email ASC",
            [ $list_id ]
        ) ?: [];

        $recipients = [];
        foreach ( $rows as $row ) {
            $email = strtolower( trim( (string) ( $row['email'] ?? '' ) ) );
            if ( ! \metis_email_is_valid( $email ) ) {
                continue;
            }

            $display_name = trim( (string) ( $row['first_name'] ?? '' ) . ' ' . (string) ( $row['last_name'] ?? '' ) );
            if ( $display_name === '' ) {
                $display_name = trim( (string) ( $row['cid'] ?? '' ) );
            }

            $recipients[] = [
                'email' => $email,
                'first_name' => trim( (string) ( $row['first_name'] ?? '' ) ),
                'display_name' => $display_name,
            ];
        }

        return $recipients;
    }

    public static function resolvePacketRecipients( array $meeting, array $committee_summary ): array {
        $meeting_type = \metis_key_clean( (string) ( $meeting['meeting_type'] ?? 'board' ) );
        $committee_id = (int) ( $meeting['committee_id'] ?? 0 );

        if ( $meeting_type === 'committee' ) {
            if ( $committee_id < 1 ) {
                return [ 'ok' => false, 'error' => 'Committee meetings must be linked to a committee before sending packets.' ];
            }

            if ( $committee_summary === [] ) {
                return [ 'ok' => false, 'error' => 'Committee settings could not be loaded.' ];
            }

            $newsletter_list_id = (int) ( $committee_summary['newsletter_list_id'] ?? 0 );
            if ( $newsletter_list_id < 1 ) {
                return [ 'ok' => false, 'error' => 'This committee does not have a newsletter list configured for packet delivery.' ];
            }
            if ( ! self::newsletterListExists( $newsletter_list_id ) ) {
                return [ 'ok' => false, 'error' => 'The committee newsletter list is missing or inactive.' ];
            }

            $recipients = [];
            foreach ( self::collectNewsletterListRecipients( $newsletter_list_id ) as $row ) {
                $email = strtolower( trim( (string) ( $row['email'] ?? '' ) ) );
                if ( ! \metis_email_is_valid( $email ) ) {
                    continue;
                }

                $recipients[ $email ] = [
                    'email' => $email,
                    'first_name' => trim( (string) ( $row['first_name'] ?? '' ) ),
                    'display_name' => trim( (string) ( $row['display_name'] ?? '' ) ),
                ];
            }

            if ( $recipients === [] ) {
                return [ 'ok' => false, 'error' => 'No active subscribed committee recipients were found in the configured newsletter list.' ];
            }

            return [
                'ok' => true,
                'recipients' => $recipients,
                'audience_label' => 'committee members',
                'packet_heading' => 'Committee Packet',
                'committee_name' => (string) ( $committee_summary['name'] ?? '' ),
            ];
        }

        $people_table = \Metis_Tables::get( 'people' );
        $recipient_rows = \metis_db()->fetchAll(
            "SELECT id, email, first_name, display_name
             FROM {$people_table}
             WHERE is_board = 1
               AND status = 'active'
               AND email IS NOT NULL
               AND email <> ''
             ORDER BY display_name ASC, email ASC"
        ) ?: [];

        $recipients = [];
        foreach ( $recipient_rows as $row ) {
            $email = strtolower( trim( (string) ( $row['email'] ?? '' ) ) );
            if ( ! \metis_email_is_valid( $email ) ) {
                continue;
            }

            $recipients[ $email ] = [
                'email' => $email,
                'first_name' => trim( (string) ( $row['first_name'] ?? '' ) ),
                'display_name' => trim( (string) ( $row['display_name'] ?? '' ) ),
            ];
        }

        if ( $recipients === [] ) {
            return [ 'ok' => false, 'error' => 'No board member recipients found.' ];
        }

        return [
            'ok' => true,
            'recipients' => $recipients,
            'audience_label' => 'board members',
            'packet_heading' => 'Board Packet',
            'committee_name' => '',
        ];
    }

    private static function tableExists( string $table ): bool {
        return \function_exists( 'metis_board_table_exists' ) ? \metis_board_table_exists( $table ) : false;
    }
}
