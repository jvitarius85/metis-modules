<?php
declare(strict_types=1);

namespace Metis\Modules\Contacts;

final class ContactReadService {
    public static function dashboardRows(): array {
        $db = \metis_db();
        $contacts_table = \Metis_Tables::get( 'contacts' );
        $details_table = \Metis_Tables::get( 'contact_details' );

        if ( \metis_contacts_table_exists( $details_table ) && \metis_contacts_column_exists( $details_table, 'contact_id' ) ) {
            return array_map( static fn( array $row ): object => (object) $row, $db->fetchAll(
                "SELECT c.id, c.cid, c.did, c.email, c.first_name, c.last_name, c.created_at, c.updated_at, d.phone
                 FROM {$contacts_table} c
                 LEFT JOIN {$details_table} d ON d.contact_id = c.id
                 ORDER BY c.last_name ASC, c.first_name ASC, c.id ASC"
            ) ?: [] );
        }

        return array_map( static fn( array $row ): object => (object) $row, $db->fetchAll(
            "SELECT c.id, c.cid, c.did, c.email, c.first_name, c.last_name, c.created_at, c.updated_at, '' AS phone
             FROM {$contacts_table} c
             ORDER BY c.last_name ASC, c.first_name ASC, c.id ASC"
        ) ?: [] );
    }

    public static function donationTotalsByDid(): array {
        $transactions_table = \Metis_Tables::get( 'transactions' );
        if ( ! \metis_contacts_table_exists( $transactions_table ) ) {
            return [];
        }

        $donation_totals = [];
        $rows = array_map( static fn( array $row ): object => (object) $row, \metis_db()->fetchAll(
            "SELECT did, SUM(amount) AS total_amount
             FROM {$transactions_table}
             WHERE did IS NOT NULL AND did <> ''
             GROUP BY did"
        ) ?: [] );

        foreach ( $rows as $row ) {
            $did_key = (string) ( $row->did ?? '' );
            if ( $did_key !== '' ) {
                $donation_totals[ $did_key ] = (float) ( $row->total_amount ?? 0 );
            }
        }

        return $donation_totals;
    }

    public static function getByCid( string $cid ): ?object {
        $contacts_table = \Metis_Tables::get( 'contacts' );
        $row = \metis_db()->fetchOne( "SELECT * FROM {$contacts_table} WHERE cid = %s LIMIT 1", [ $cid ] );
        return is_array( $row ) ? (object) $row : null;
    }

    public static function donorSummary( string $did ): array {
        $summary = [
            'total_contributions' => 0.0,
            'last_donation' => null,
        ];

        $transactions_table = \Metis_Tables::get( 'transactions' );
        if ( $did === '' || ! \metis_contacts_table_exists( $transactions_table ) ) {
            return $summary;
        }

        $sum = \metis_db()->scalar( "SELECT SUM(amount) FROM {$transactions_table} WHERE did = %s", [ $did ] );
        $summary['total_contributions'] = is_numeric( $sum ) ? (float) $sum : 0.0;

        $campaigns_table = \Metis_Tables::get( 'campaigns' );
        if ( \metis_contacts_table_exists( $campaigns_table ) ) {
            $summary['last_donation'] = (object) ( \metis_db()->fetchOne(
                "SELECT t.amount, t.tran_date, t.campaign_code, c.cname AS campaign_name
                 FROM {$transactions_table} t
                 LEFT JOIN {$campaigns_table} c ON c.cid = t.campaign_code
                 WHERE t.did = %s
                 ORDER BY t.tran_date DESC, t.id DESC
                 LIMIT 1",
                [ $did ]
            ) ?? [] );
        } else {
            $summary['last_donation'] = (object) ( \metis_db()->fetchOne(
                "SELECT t.amount, t.tran_date, t.campaign_code
                 FROM {$transactions_table} t
                 WHERE t.did = %s
                 ORDER BY t.tran_date DESC, t.id DESC
                 LIMIT 1",
                [ $did ]
            ) ?? [] );
        }

        return $summary;
    }

    public static function detailRows( string $cid, int $contact_id, string $did ): array {
        $details_table = \Metis_Tables::get( 'contact_details' );
        if ( ! \metis_contacts_table_exists( $details_table ) ) {
            return [];
        }

        return ContactMutationService::collectLinkedDetailRows( $details_table, $cid, $contact_id, $did );
    }

    public static function incomingRelationships( string $cid, int $contact_id ): array {
        $details_table = \Metis_Tables::get( 'contact_details' );
        $contacts_table = \Metis_Tables::get( 'contacts' );
        if (
            ! \metis_contacts_table_exists( $details_table ) ||
            ! \metis_contacts_column_exists( $details_table, 'relationships_json' ) ||
            ! ( \metis_contacts_column_exists( $details_table, 'contact_id' ) || \metis_contacts_column_exists( $details_table, 'contact_cid' ) )
        ) {
            return [];
        }

        $db = \metis_db();
        $like = '%' . $db->escapeLike( '"related_contact_cid":"' . $cid . '"' ) . '%';
        if ( \metis_contacts_column_exists( $details_table, 'contact_cid' ) ) {
            return array_map( static fn( array $row ): object => (object) $row, $db->fetchAll(
                "SELECT c.cid AS source_cid, c.first_name AS source_first_name, c.last_name AS source_last_name, c.email AS source_email, d.relationships_json
                 FROM {$details_table} d
                 INNER JOIN {$contacts_table} c ON c.cid = d.contact_cid
                 WHERE d.contact_cid <> %s
                   AND d.relationships_json LIKE %s",
                [ $cid, $like ]
            ) ?: [] );
        }

        return array_map( static fn( array $row ): object => (object) $row, $db->fetchAll(
            "SELECT c.cid AS source_cid, c.first_name AS source_first_name, c.last_name AS source_last_name, c.email AS source_email, d.relationships_json
             FROM {$details_table} d
             INNER JOIN {$contacts_table} c ON c.id = d.contact_id
             WHERE d.contact_id <> %d
               AND d.relationships_json LIKE %s",
            [ $contact_id, $like ]
        ) ?: [] );
    }

    public static function notes( string $cid, int $contact_id, string $did ): array {
        $notes_table = \Metis_Tables::get( 'contact_notes' );
        if ( ! \metis_contacts_table_exists( $notes_table ) ) {
            return [];
        }

        $auth_users_table = \Metis_Tables::get( 'auth_users' );
        if ( \metis_contacts_column_exists( $notes_table, 'cid' ) ) {
            return array_map( static fn( array $row ): object => (object) $row, \metis_db()->fetchAll(
                "SELECT n.note, n.created_at, n.admin_user_id, u.display_name AS author_name
                 FROM {$notes_table} n
                 LEFT JOIN {$auth_users_table} u ON u.ID = n.admin_user_id
                 WHERE n.cid = %s
                   AND (n.deleted_at IS NULL OR n.deleted_at = '')
                 ORDER BY n.created_at DESC
                 LIMIT 30",
                [ $cid ]
            ) ?: [] );
        }
        if ( $did !== '' && \metis_contacts_column_exists( $notes_table, 'did' ) ) {
            return array_map( static fn( array $row ): object => (object) $row, \metis_db()->fetchAll(
                "SELECT n.note, n.created_at, n.admin_user_id, u.display_name AS author_name
                 FROM {$notes_table} n
                 LEFT JOIN {$auth_users_table} u ON u.ID = n.admin_user_id
                 WHERE n.did = %s
                   AND (n.deleted_at IS NULL OR n.deleted_at = '')
                 ORDER BY n.created_at DESC
                 LIMIT 30",
                [ $did ]
            ) ?: [] );
        }
        if ( \metis_contacts_column_exists( $notes_table, 'contact_id' ) ) {
            return array_map( static fn( array $row ): object => (object) $row, \metis_db()->fetchAll(
                "SELECT n.note, n.created_at, n.admin_user_id, u.display_name AS author_name
                 FROM {$notes_table} n
                 LEFT JOIN {$auth_users_table} u ON u.ID = n.admin_user_id
                 WHERE n.contact_id = %d
                   AND (n.deleted_at IS NULL OR n.deleted_at = '')
                 ORDER BY n.created_at DESC
                 LIMIT 30",
                [ $contact_id ]
            ) ?: [] );
        }

        return [];
    }

    public static function allContactsExcept( int $contact_id ): array {
        $contacts_table = \Metis_Tables::get( 'contacts' );
        return array_map( static fn( array $row ): object => (object) $row, \metis_db()->fetchAll(
            "SELECT cid, first_name, last_name, email
             FROM {$contacts_table}
             WHERE id <> %d
             ORDER BY first_name ASC, last_name ASC, email ASC",
            [ $contact_id ]
        ) ?: [] );
    }

    public static function newsletterLists(): array {
        $newsletter_lists_table = \Metis_Tables::get( 'newsletter_lists' );
        if ( ! \metis_contacts_table_exists( $newsletter_lists_table ) ) {
            return [];
        }

        return array_map( static fn( array $row ): object => (object) $row, \metis_db()->fetchAll(
            "SELECT id, name
             FROM {$newsletter_lists_table}
             WHERE name IS NOT NULL
               AND TRIM(name) <> ''
             ORDER BY name ASC"
        ) ?: [] );
    }

    public static function newsletterSelectedIds( int $contact_id ): array {
        $newsletter_subs_table = \Metis_Tables::get( 'newsletter_subs' );
        if ( ! \metis_contacts_table_exists( $newsletter_subs_table ) || $contact_id < 1 ) {
            return [];
        }

        return array_map( 'intval', \metis_db()->column(
            "SELECT list_id FROM {$newsletter_subs_table} WHERE contact_id = %d",
            [ $contact_id ]
        ) ?: [] );
    }
}
