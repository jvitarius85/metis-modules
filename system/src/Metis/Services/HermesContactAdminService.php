<?php
declare(strict_types=1);

namespace Metis\Services;

use Metis\Core\Services\EntityResolverService;
use Metis\Modules\Contacts\SchemaManager as ContactsSchemaManager;

final class HermesContactAdminService {
    public function __construct(
        private readonly ?DatabaseService $db = null,
        private readonly ?EntityResolverService $entityResolver = null
    ) {}

    public function updateContact( mixed $request = null ): array {
        $request = is_array( $request ) ? $request : [];
        $subject = trim( (string) ( $request['subject'] ?? '' ) );
        $operation = \metis_key_clean( (string) ( $request['operation'] ?? 'set_field' ) );
        $field = \metis_key_clean( (string) ( $request['field'] ?? '' ) );
        $value = trim( (string) ( $request['value'] ?? '' ) );
        $listName = trim( (string) ( $request['list_name'] ?? '' ) );

        if ( $subject === '' ) {
            throw new \RuntimeException( 'Please specify which contact should be updated.' );
        }

        $contact = $this->resolveContact( $subject );

        return match ( $operation ) {
            'add_to_list' => $this->updateNewsletterSubscription( $contact, $listName, true ),
            'remove_from_list' => $this->updateNewsletterSubscription( $contact, $listName, false ),
            default => $this->updateContactField( $contact, $field, $value ),
        };
    }

    private function resolveContact( string $subject ): array {
        $resolved = $this->entityResolver()->resolve( $subject, 'contact' )->toArray();
        $status = (string) ( $resolved['status'] ?? '' );

        if ( $status === 'resolved' ) {
            $match = (array) ( $resolved['match'] ?? [] );
            $record = (array) ( $match['metadata']['record'] ?? [] );
            if ( $record !== [] ) {
                return $record;
            }
        }

        if ( $status === 'ambiguous' ) {
            $candidates = (array) ( $resolved['candidates'] ?? [] );
            if ( count( $candidates ) === 1 ) {
                $match = (array) ( $resolved['match'] ?? [] );
                $record = (array) ( $match['metadata']['record'] ?? [] );
                if ( $record !== [] ) {
                    return $record;
                }
            }
        }

        throw new \RuntimeException( (string) ( $resolved['message'] ?? 'Contact not found. Try using full name or email.' ) );
    }

    private function updateContactField( array $contact, string $field, string $value ): array {
        if ( $field === '' ) {
            throw new \RuntimeException( 'Please specify what to update (phone, address, city, state, zip, or preferred name).' );
        }

        $allowed = [
            'phone',
            'address_full',
            'address',
            'city',
            'state',
            'zip',
            'birthday',
            'preferred_name',
            'preferred_contact_method',
        ];
        if ( ! in_array( $field, $allowed, true ) ) {
            throw new \RuntimeException( sprintf( 'Updating "%s" is not supported yet. Try phone, address, city, state, zip, birthday, preferred name, or contact method.', $field ) );
        }

        if ( $value === '' ) {
            throw new \RuntimeException( 'Please provide the new value to apply.' );
        }

        ContactsSchemaManager::ensureSchema();

        $contactsTable = \Metis_Tables::get( 'contacts' );
        $detailsTable = \Metis_Tables::get( 'contact_details' );
        $contactId = (int) ( $contact['id'] ?? 0 );
        $cid = trim( (string) ( $contact['cid'] ?? '' ) );
        $did = trim( (string) ( $contact['did'] ?? '' ) );

        if ( $contactId < 1 ) {
            throw new \RuntimeException( 'Resolved contact is missing an internal ID and cannot be updated.' );
        }

        if ( $field === 'address_full' ) {
            $parsed = $this->parseAddress( $value );
            $this->upsertDetailRow( $contactId, $cid, $did, [
                'address' => $parsed['address'],
                'city' => $parsed['city'],
                'state' => $parsed['state'],
                'zip' => $parsed['zip'],
            ] );
        } else {
            $normalizedValue = $field === 'phone'
                ? $this->normalizePhone( $value )
                : $this->sanitizeText( $value );

            $this->upsertDetailRow( $contactId, $cid, $did, [ $field => $normalizedValue ] );
        }

        $this->touchContactUpdatedAt( $contactsTable, $contactId );

        return [
            'status' => 'success',
            'contact' => [
                'id' => $contactId,
                'cid' => $cid,
                'name' => $this->contactName( $contact ),
                'email' => (string) ( $contact['email'] ?? '' ),
            ],
            'message' => sprintf( 'Updated %s for %s.', str_replace( '_', ' ', $field ), $this->contactName( $contact ) ),
        ];
    }

    private function updateNewsletterSubscription( array $contact, string $listName, bool $subscribe ): array {
        if ( $listName === '' ) {
            throw new \RuntimeException( 'Please specify which newsletter list should be updated.' );
        }

        ContactsSchemaManager::ensureSchema();
        $contactId = (int) ( $contact['id'] ?? 0 );
        if ( $contactId < 1 ) {
            throw new \RuntimeException( 'Resolved contact is missing an internal ID and cannot be updated.' );
        }

        $listsTable = \Metis_Tables::get( 'newsletter_lists' );
        $subsTable = \Metis_Tables::get( 'newsletter_subs' );

        $list = $this->database()->fetchOne(
            "SELECT id, name
                 FROM {$listsTable}
                 WHERE LOWER(COALESCE(name, '')) = %s
                 LIMIT 1",
            [ strtolower( $listName ) ]
        );

        if ( ! is_array( $list ) ) {
            throw new \RuntimeException( sprintf( 'Newsletter list "%s" was not found. Try the exact list name.', $listName ) );
        }

        $listId = (int) ( $list['id'] ?? 0 );
        $resolvedListName = trim( (string) ( $list['name'] ?? $listName ) );

        if ( $subscribe ) {
            $exists = (int) $this->database()->scalar(
                "SELECT id FROM {$subsTable} WHERE contact_id = %d AND list_id = %d LIMIT 1",
                [ $contactId, $listId ]
            );
            if ( $exists < 1 ) {
                $ok = $this->database()->insert( $subsTable, [ 'contact_id' => $contactId, 'list_id' => $listId ], [ '%d', '%d' ] );
                if ( $ok === false ) {
                    throw new \RuntimeException( 'Failed to add the contact to the newsletter list.' );
                }
            }
        } else {
            $ok = $this->database()->delete( $subsTable, [ 'contact_id' => $contactId, 'list_id' => $listId ], [ '%d', '%d' ] );
            if ( $ok === false ) {
                throw new \RuntimeException( 'Failed to remove the contact from the newsletter list.' );
            }
        }

        return [
            'status' => 'success',
            'contact' => [
                'id' => $contactId,
                'cid' => (string) ( $contact['cid'] ?? '' ),
                'name' => $this->contactName( $contact ),
                'email' => (string) ( $contact['email'] ?? '' ),
            ],
            'list' => [
                'id' => $listId,
                'name' => $resolvedListName,
                'subscribed' => $subscribe,
            ],
            'message' => $subscribe
                ? sprintf( 'Added %s to the "%s" list.', $this->contactName( $contact ), $resolvedListName )
                : sprintf( 'Removed %s from the "%s" list.', $this->contactName( $contact ), $resolvedListName ),
        ];
    }

    private function upsertDetailRow( int $contactId, string $cid, string $did, array $patch ): void {
        $detailsTable = \Metis_Tables::get( 'contact_details' );
        $detail = $this->database()->fetchOne(
            "SELECT id FROM {$detailsTable} WHERE contact_id = %d OR contact_cid = %s LIMIT 1",
            [ $contactId, $cid ]
        );

        $payload = [];
        $format = [];

        foreach ( $patch as $column => $rawValue ) {
            if ( ! ContactsSchemaManager::columnExists( $detailsTable, (string) $column ) ) {
                continue;
            }

            $payload[ (string) $column ] = $rawValue !== '' ? $rawValue : null;
            $format[] = '%s';
        }

        if ( $payload === [] ) {
            throw new \RuntimeException( 'The requested contact field is not available in this environment.' );
        }

        if ( ContactsSchemaManager::columnExists( $detailsTable, 'updated_at' ) ) {
            $payload['updated_at'] = \metis_current_time( 'mysql' );
            $format[] = '%s';
        }

        if ( is_array( $detail ) ) {
            $ok = $this->database()->update(
                $detailsTable,
                $payload,
                [ 'id' => (int) ( $detail['id'] ?? 0 ) ],
                $format,
                [ '%d' ]
            );
            if ( $ok === false ) {
                throw new \RuntimeException( 'Failed to update the contact details row.' );
            }
            return;
        }

        $insertPayload = [];
        $insertFormat = [];
        if ( ContactsSchemaManager::columnExists( $detailsTable, 'contact_id' ) ) {
            $insertPayload['contact_id'] = $contactId;
            $insertFormat[] = '%d';
        }
        if ( ContactsSchemaManager::columnExists( $detailsTable, 'contact_cid' ) ) {
            $insertPayload['contact_cid'] = $cid !== '' ? $cid : null;
            $insertFormat[] = '%s';
        }
        if ( ContactsSchemaManager::columnExists( $detailsTable, 'did' ) ) {
            $insertPayload['did'] = $did !== '' ? $did : null;
            $insertFormat[] = '%s';
        }

        foreach ( $payload as $column => $fieldValue ) {
            $insertPayload[ $column ] = $fieldValue;
            $insertFormat[] = '%s';
        }

        $ok = $this->database()->insert( $detailsTable, $insertPayload, $insertFormat );
        if ( $ok === false ) {
            throw new \RuntimeException( 'Failed to create the contact details row.' );
        }
    }

    private function touchContactUpdatedAt( string $contactsTable, int $contactId ): void {
        if ( ! ContactsSchemaManager::columnExists( $contactsTable, 'updated_at' ) ) {
            return;
        }

        $ok = $this->database()->update(
            $contactsTable,
            [ 'updated_at' => \metis_current_time( 'mysql' ) ],
            [ 'id' => $contactId ],
            [ '%s' ],
            [ '%d' ]
        );

        if ( $ok === false ) {
            throw new \RuntimeException( 'Failed to update the contact timestamp.' );
        }
    }

    /**
     * @return array{address:string,city:string,state:string,zip:string}
     */
    private function parseAddress( string $value ): array {
        if ( function_exists( 'metis_contacts_parse_address_full' ) ) {
            $parsed = \metis_contacts_parse_address_full( $value );
            if ( is_array( $parsed ) ) {
                return [
                    'address' => trim( (string) ( $parsed['address'] ?? '' ) ),
                    'city' => trim( (string) ( $parsed['city'] ?? '' ) ),
                    'state' => strtoupper( trim( (string) ( $parsed['state'] ?? '' ) ) ),
                    'zip' => trim( (string) ( $parsed['zip'] ?? '' ) ),
                ];
            }
        }

        $parts = array_map( 'trim', explode( ',', $value ) );
        return [
            'address' => $this->sanitizeText( (string) ( $parts[0] ?? $value ) ),
            'city' => $this->sanitizeText( (string) ( $parts[1] ?? '' ) ),
            'state' => strtoupper( $this->sanitizeText( (string) ( $parts[2] ?? '' ) ) ),
            'zip' => $this->sanitizeText( (string) ( $parts[3] ?? '' ) ),
        ];
    }

    private function normalizePhone( string $value ): string {
        if ( function_exists( 'metis_contacts_format_phone_us' ) ) {
            return (string) \metis_contacts_format_phone_us( $value );
        }

        $digits = preg_replace( '/\D+/', '', $value ) ?? '';
        if ( strlen( $digits ) === 10 ) {
            return sprintf( '(%s) %s-%s', substr( $digits, 0, 3 ), substr( $digits, 3, 3 ), substr( $digits, 6, 4 ) );
        }

        return $this->sanitizeText( $value );
    }

    private function sanitizeText( string $value ): string {
        $value = trim( $value );
        if ( function_exists( 'metis_text_clean' ) ) {
            return (string) \metis_text_clean( $value );
        }

        return $value;
    }

    private function contactName( array $contact ): string {
        $name = trim( (string) ( $contact['first_name'] ?? '' ) . ' ' . (string) ( $contact['last_name'] ?? '' ) );
        if ( $name !== '' ) {
            return $name;
        }

        $display = trim( (string) ( $contact['display_name'] ?? '' ) );
        if ( $display !== '' ) {
            return $display;
        }

        $email = trim( (string) ( $contact['email'] ?? '' ) );
        return $email !== '' ? $email : 'contact';
    }

    private function database(): DatabaseService {
        if ( $this->db instanceof DatabaseService ) {
            return $this->db;
        }
        return function_exists( 'metis_resolve_db_service' ) ? \metis_resolve_db_service() : new DatabaseService();
    }

    private function entityResolver(): EntityResolverService {
        if ( $this->entityResolver instanceof EntityResolverService ) {
            return $this->entityResolver;
        }

        return function_exists( 'metis_entity_resolution_service' )
            ? \metis_entity_resolution_service()
            : new EntityResolverService( $this->database() );
    }
}
