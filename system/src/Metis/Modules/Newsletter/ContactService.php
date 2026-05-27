<?php
declare(strict_types=1);

namespace Metis\Modules\Newsletter;

use Metis\Modules\Contacts\ContactMutationService;

final class ContactService {
    public static function findOrCreateContactId( string $email, string $first_name = '', string $last_name = '' ): int {
        $email = strtolower( trim( \metis_email_clean( $email ) ) );
        if ( $email === '' ) {
            return 0;
        }

        $contacts_table = \Metis_Tables::get( 'contacts' );
        $contact_id = (int) \metis_db()->scalar(
            "SELECT id FROM {$contacts_table} WHERE email = %s LIMIT 1",
            [ $email ]
        );
        if ( $contact_id > 0 ) {
            return $contact_id;
        }

        $result = ContactMutationService::createContact( [
            'first_name' => $first_name !== '' ? $first_name : 'Newsletter',
            'last_name' => $last_name !== '' ? $last_name : 'Subscriber',
            'email' => $email,
        ] );
        if ( empty( $result['success'] ) ) {
            return 0;
        }

        $contact = is_array( $result['contact'] ?? null ) ? $result['contact'] : [];
        return (int) ( $contact['id'] ?? 0 );
    }

    public static function previewPayload(): array {
        $user = \function_exists( 'metis_runtime_current_user' ) ? \metis_runtime_current_user() : null;
        $email = strtolower( trim( (string) ( ( $user->user_email ?? '' ) ?: '' ) ) );
        $first_name = trim( (string) ( ( $user->first_name ?? '' ) ?: '' ) );
        $last_name = trim( (string) ( ( $user->last_name ?? '' ) ?: '' ) );
        $display_name = trim( (string) ( ( $user->display_name ?? '' ) ?: '' ) );

        $contact = [
            'contact_id' => 0,
            'contact_cid' => '',
            'first_name' => $first_name,
            'last_name' => $last_name,
            'full_name' => trim( $first_name . ' ' . $last_name ),
            'name' => $first_name !== '' ? $first_name : ( $display_name !== '' ? $display_name : trim( $first_name . ' ' . $last_name ) ),
            'email' => $email,
            'city' => '',
            'state' => '',
        ];

        if ( $email === '' ) {
            if ( $contact['full_name'] === '' ) {
                $contact['full_name'] = $display_name;
            }

            return $contact;
        }

        $db = \metis_db();
        $contacts_table = \Metis_Tables::get( 'contacts' );
        $details_table = \Metis_Tables::get( 'contact_details' );
        $contact_row = NewsletterModule::tableExists( $contacts_table )
            ? $db->fetchOne(
                "SELECT id, cid, first_name, last_name, email
                 FROM {$contacts_table}
                 WHERE LOWER(email) = %s
                 LIMIT 1",
                [ $email ]
            )
            : null;

        if ( is_array( $contact_row ) && $contact_row !== [] ) {
            $contact['contact_id'] = (int) ( $contact_row['id'] ?? 0 );
            $contact['contact_cid'] = (string) ( $contact_row['cid'] ?? '' );
            $contact['first_name'] = trim( (string) ( $contact_row['first_name'] ?? $contact['first_name'] ) );
            $contact['last_name'] = trim( (string) ( $contact_row['last_name'] ?? $contact['last_name'] ) );
            $contact['email'] = strtolower( trim( (string) ( $contact_row['email'] ?? $contact['email'] ) ) );

            $detail_row = null;
            if (
                NewsletterModule::tableExists( $details_table )
                && NewsletterModule::columnExists( $details_table, 'city' )
                && NewsletterModule::columnExists( $details_table, 'state' )
            ) {
                $detail_where = [];
                $detail_args = [];
                if ( NewsletterModule::columnExists( $details_table, 'contact_id' ) ) {
                    $detail_where[] = 'contact_id = %d';
                    $detail_args[] = (int) ( $contact_row['id'] ?? 0 );
                }
                if ( NewsletterModule::columnExists( $details_table, 'contact_cid' ) ) {
                    $detail_where[] = 'contact_cid = %s';
                    $detail_args[] = (string) ( $contact_row['cid'] ?? '' );
                }
                if ( $detail_where !== [] ) {
                    $detail_row = $db->fetchOne(
                        "SELECT city, state
                         FROM {$details_table}
                         WHERE " . implode( ' OR ', $detail_where ) . '
                         ORDER BY id DESC
                         LIMIT 1',
                        $detail_args
                    );
                }
            }

            if ( is_array( $detail_row ) && $detail_row !== [] ) {
                $contact['city'] = trim( (string) ( $detail_row['city'] ?? '' ) );
                $contact['state'] = trim( (string) ( $detail_row['state'] ?? '' ) );
            }
        }

        $contact['full_name'] = trim( $contact['first_name'] . ' ' . $contact['last_name'] );
        if ( $contact['full_name'] === '' ) {
            $contact['full_name'] = $display_name;
        }
        $contact['name'] = $contact['first_name'] !== '' ? $contact['first_name'] : ( $contact['full_name'] !== '' ? $contact['full_name'] : $display_name );

        return $contact;
    }

    public static function searchContacts( string $query, int $limit = 20 ): array {
        $query = trim( \metis_text_clean( $query ) );
        if ( $query === '' ) {
            return [];
        }

        $db = \metis_db();
        $contacts_table = \Metis_Tables::get( 'contacts' );
        $like = '%' . $db->escapeLike( strtolower( $query ) ) . '%';
        $rows = $db->fetchAll(
            "SELECT cid, first_name, last_name, email
             FROM {$contacts_table}
             WHERE LOWER(CONCAT(COALESCE(first_name,''), ' ', COALESCE(last_name,''))) LIKE %s
                OR LOWER(email) LIKE %s
                OR LOWER(COALESCE(cid,'')) LIKE %s
             ORDER BY first_name ASC, last_name ASC
             LIMIT %d",
            [ $like, $like, $like, max( 1, min( 100, $limit ) ) ]
        );

        return array_values( array_filter( array_map( static function ( array $row ): array {
            $email = strtolower( trim( (string) ( $row['email'] ?? '' ) ) );
            if ( $email === '' || ! \metis_email_is_valid( $email ) ) {
                return [];
            }

            $name = trim( (string) ( $row['first_name'] ?? '' ) . ' ' . (string) ( $row['last_name'] ?? '' ) );
            return [
                'cid' => (string) ( $row['cid'] ?? '' ),
                'email' => $email,
                'name' => $name !== '' ? $name : $email,
                'label' => trim( ( $name !== '' ? $name . ' - ' : '' ) . $email . ( (string) ( $row['cid'] ?? '' ) !== '' ? ( ' - ' . (string) ( $row['cid'] ?? '' ) ) : '' ) ),
            ];
        }, $rows ?: [] ), static fn( array $row ): bool => $row !== [] ) );
    }
}
