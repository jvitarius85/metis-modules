<?php
declare(strict_types=1);

namespace Metis\Modules\Contacts;

final class AssociationService {
    public static function addNewsletterSubscription( string $cid, int $list_id ): array {
        $db = \metis_db();
        $contacts_table = \Metis_Tables::get( 'contacts' );
        $newsletter_subs_table = \Metis_Tables::get( 'newsletter_subs' );
        $newsletter_lists_table = \Metis_Tables::get( 'newsletter_lists' );

        $contact_id = self::contactIdByCid( $cid );
        if ( $contact_id < 1 ) {
            \metis_runtime_send_json_error( 'Contact not found.', 404 );
        }

        $list_name = (string) $db->scalar(
            "SELECT name FROM {$newsletter_lists_table} WHERE id = %d AND name IS NOT NULL AND TRIM(name) <> '' LIMIT 1",
            [ $list_id ]
        );
        if ( $list_name === '' ) {
            \metis_runtime_send_json_error( 'Invalid newsletter list.', 400 );
        }

        $exists = (int) $db->scalar(
            "SELECT id FROM {$newsletter_subs_table} WHERE contact_id = %d AND list_id = %d LIMIT 1",
            [ $contact_id, $list_id ]
        );
        if ( $exists < 1 ) {
            $ok = $db->insert( $newsletter_subs_table, [ 'contact_id' => $contact_id, 'list_id' => $list_id ], [ '%d', '%d' ] );
            if ( $ok === false ) {
                \metis_runtime_send_json_error( 'Failed to add newsletter subscription.', 500 );
            }
        }

        return [
            'list_id' => $list_id,
            'name' => $list_name,
        ];
    }

    public static function removeNewsletterSubscription( string $cid, int $list_id ): array {
        $db = \metis_db();
        $newsletter_subs_table = \Metis_Tables::get( 'newsletter_subs' );
        $contact_id = self::contactIdByCid( $cid );
        if ( $contact_id < 1 ) {
            \metis_runtime_send_json_error( 'Contact not found.', 404 );
        }

        $ok = $db->delete( $newsletter_subs_table, [ 'contact_id' => $contact_id, 'list_id' => $list_id ], [ '%d', '%d' ] );
        if ( $ok === false ) {
            \metis_runtime_send_json_error( 'Failed to remove newsletter subscription.', 500 );
        }

        return [
            'list_id' => $list_id,
        ];
    }

    public static function removeRelationship( string $cid, string $related_cid, string $relation_type, string $notes ): array {
        \metis_contacts_ensure_schema();

        $db = \metis_db();
        $contacts_table = \Metis_Tables::get( 'contacts' );
        $details_table = \Metis_Tables::get( 'contact_details' );

        $contact_row = self::contactIdentityByCid( $contacts_table, $cid );
        if ( $contact_row === null ) {
            \metis_runtime_send_json_error( 'Contact not found.', 404 );
        }

        $removed = 0;
        $detail_rows = \metis_contacts_collect_linked_detail_rows( $details_table, $cid, (int) $contact_row->id, (string) ( $contact_row->did ?? '' ) );
        foreach ( $detail_rows as $detail_row ) {
            if ( ! isset( $detail_row->id ) ) {
                continue;
            }
            $decoded = json_decode( (string) ( $detail_row->relationships_json ?? '[]' ), true );
            $decoded = is_array( $decoded ) ? $decoded : [];
            $normalized = \metis_contacts_normalize_relationships( $decoded, $cid );
            $filtered = array_values( array_filter( $normalized, static function ( array $entry ) use ( $related_cid, $relation_type, $notes ): bool {
                return ! (
                    (string) ( $entry['related_contact_cid'] ?? '' ) === $related_cid &&
                    (string) ( $entry['relation_type'] ?? '' ) === $relation_type &&
                    (string) ( $entry['notes'] ?? '' ) === $notes
                );
            } ) );

            if ( count( $filtered ) === count( $normalized ) ) {
                continue;
            }

            self::updateRelationshipsJson( $details_table, (int) $detail_row->id, $filtered );
            $removed++;
        }

        $other = self::contactIdentityByCid( $contacts_table, $related_cid );
        if ( $other !== null ) {
            $other_rows = \metis_contacts_collect_linked_detail_rows( $details_table, (string) $other->cid, (int) $other->id, (string) ( $other->did ?? '' ) );
            foreach ( $other_rows as $other_row ) {
                if ( ! isset( $other_row->id ) ) {
                    continue;
                }
                $decoded = json_decode( (string) ( $other_row->relationships_json ?? '[]' ), true );
                $decoded = is_array( $decoded ) ? $decoded : [];
                $normalized = \metis_contacts_normalize_relationships( $decoded, (string) $other->cid );
                $filtered = array_values( array_filter( $normalized, static function ( array $entry ) use ( $cid, $relation_type, $notes ): bool {
                    return ! (
                        (string) ( $entry['related_contact_cid'] ?? '' ) === $cid &&
                        (string) ( $entry['relation_type'] ?? '' ) === $relation_type &&
                        (string) ( $entry['notes'] ?? '' ) === $notes
                    );
                } ) );
                if ( count( $filtered ) === count( $normalized ) ) {
                    continue;
                }
                self::updateRelationshipsJson( $details_table, (int) $other_row->id, $filtered );
            }
        }

        return [
            'removed' => $removed,
        ];
    }

    private static function contactIdByCid( string $cid ): int {
        $contacts_table = \Metis_Tables::get( 'contacts' );

        return (int) \metis_db()->scalar(
            "SELECT id FROM {$contacts_table} WHERE cid = %s LIMIT 1",
            [ $cid ]
        );
    }

    private static function contactIdentityByCid( string $contacts_table, string $cid ): ?object {
        $row = \metis_db()->fetchOne(
            "SELECT id, cid, did FROM {$contacts_table} WHERE cid = %s LIMIT 1",
            [ $cid ]
        );

        return is_array( $row ) ? (object) $row : null;
    }

    private static function updateRelationshipsJson( string $details_table, int $detail_id, array $relationships ): void {
        $payload = [ 'relationships_json' => \metis_json_encode( $relationships ) ];
        $format = [ '%s' ];
        if ( \metis_contacts_column_exists( $details_table, 'updated_at' ) ) {
            $payload['updated_at'] = \metis_current_time( 'mysql' );
            $format[] = '%s';
        }
        $ok = \metis_db()->update(
            $details_table,
            $payload,
            [ 'id' => $detail_id ],
            $format,
            [ '%d' ]
        );
        if ( $ok === false ) {
            \metis_runtime_send_json_error( 'Failed to remove relationship.', 500 );
        }
    }
}
