<?php
declare(strict_types=1);

namespace Metis\Hermes;

/**
 * AttributeResolver
 *
 * Maps natural-language attribute requests to canonical field names and
 * retrieves the value from a resolved entity record.
 *
 * Supported attributes:
 *   email, phone, address, role, status, groups, permissions
 *
 * Natural-language aliases (case-insensitive):
 *   "email address" → email
 *   "phone number"  → phone
 *   "user role"     → role
 *   etc.
 */
final class AttributeResolver {
    public function __construct(
        private readonly ?HermesAttributeRegistry $registry = null
    ) {}

    // ------------------------------------------------------------------
    // Public API
    // ------------------------------------------------------------------

    /**
     * Detect the requested attribute from a natural-language query.
     *
     * @param  string $query Normalised (lowercased) query string
     * @return string|null   Canonical attribute key or null if not detected
     */
    public function detectFromQuery( string $query ): ?string {
        return $this->attributeRegistry()->detectFromQuery( $query );
    }

    /**
     * Resolve a canonical attribute name from any alias.
     *
     * @param  string $raw  e.g. "email address", "phone number", "role"
     * @return string|null  Canonical key or null if unknown
     */
    public function resolve( string $raw ): ?string {
        return $this->attributeRegistry()->resolve( $raw );
    }

    /**
     * Extract the attribute value from a resolved entity record.
     *
     * @param  array  $record    Entity row from DB (or merged data)
     * @param  string $attribute Canonical attribute key
     * @param  string $entity_type e.g. 'person'
     * @return array{ ok:bool, attribute:string, value:mixed, error?:string }
     */
    public function extract( array $record, string $attribute, string $entity_type = '' ): array {
        switch ( $attribute ) {
            case 'email':
                return $this->fieldResult( $attribute, $record['email'] ?? null );

            case 'phone':
                $value = $record['phone'] ?? $record['phone_number'] ?? $record['mobile'] ?? null;
                if ( $value === null || $value === '' ) {
                    $value = $this->lookupPhoneFallback( $record, $entity_type );
                }
                return $this->fieldResult( $attribute, $value );

            case 'address':
                $value = $this->buildAddress( $record );
                return $this->fieldResult( $attribute, $value !== '' ? $value : null );

            case 'role':
                $value = $record['role'] ?? $record['roles'] ?? null;
                return $this->fieldResult( $attribute, $value );

            case 'status':
                return $this->fieldResult( $attribute, $record['status'] ?? null );

            case 'volunteer_status':
                $value = $record['volunteer_status'] ?? $record['is_volunteer'] ?? null;
                if ( is_string( $value ) ) {
                    $value = strtolower( trim( $value ) );
                }
                if ( $value === 1 || $value === '1' || $value === true || $value === 'yes' || $value === 'true' ) {
                    return [ 'ok' => true, 'attribute' => $attribute, 'value' => 'yes' ];
                }
                if ( $value === 0 || $value === '0' || $value === false || $value === 'no' || $value === 'false' ) {
                    return [ 'ok' => true, 'attribute' => $attribute, 'value' => 'no' ];
                }
                return $this->fieldResult( $attribute, $value );

            case 'groups':
                return $this->fetchGroups( $record, $entity_type );

            case 'permissions':
                return $this->fetchPermissions( $record, $entity_type );

            case 'name':
                $value = trim( ( $record['first_name'] ?? '' ) . ' ' . ( $record['last_name'] ?? '' ) );
                if ( $value === '' ) {
                    $value = $record['display_name'] ?? $record['name'] ?? null;
                }
                return $this->fieldResult( $attribute, $value !== '' ? $value : null );

            case 'created_at':
                $value = $record['created_at'] ?? $record['created'] ?? null;
                return $this->fieldResult( $attribute, $value );

            case 'last_login_at':
                $value = $record['last_login_at'] ?? $record['last_login'] ?? null;
                return $this->fieldResult( $attribute, $value );

            default:
                // Generic field fallback
                if ( isset( $record[ $attribute ] ) ) {
                    return $this->fieldResult( $attribute, $record[ $attribute ] );
                }
                return [
                    'ok'        => false,
                    'attribute' => $attribute,
                    'error'     => sprintf( 'Attribute "%s" is not available for this entity.', $attribute ),
                ];
        }
    }

    /**
     * List all supported canonical attribute keys.
     */
    public function supportedAttributes(): array {
        return $this->attributeRegistry()->supportedAttributes();
    }

    // ------------------------------------------------------------------
    // Private helpers
    // ------------------------------------------------------------------

    private function fieldResult( string $attribute, mixed $value ): array {
        if ( $value === null || $value === '' ) {
            if ( $attribute === 'phone' ) {
                return [
                    'ok'        => false,
                    'attribute' => $attribute,
                    'error'     => 'No phone number is on file for this entity.',
                ];
            }

            return [
                'ok'        => false,
                'attribute' => $attribute,
                'error'     => sprintf( 'The "%s" attribute is not set for this entity.', $attribute ),
            ];
        }
        return [ 'ok' => true, 'attribute' => $attribute, 'value' => $value ];
    }

    private function buildAddress( array $record ): string {
        $parts = array_filter( [
            $record['address']   ?? $record['address_line_1'] ?? '',
            $record['address_2'] ?? $record['address_line_2'] ?? '',
            $record['city']      ?? '',
            $record['state']     ?? $record['province'] ?? '',
            $record['zip']       ?? $record['postal_code'] ?? '',
        ] );
        return implode( ', ', array_map( 'trim', $parts ) );
    }

    private function fetchGroups( array $record, string $entity_type ): array {
        // Groups are stored in workspace / Google Workspace — not in a local DB field.
        // Returned as empty with a note; full implementation requires workspace API call.
        $value = $record['workspace_groups'] ?? $record['groups'] ?? null;
        if ( is_array( $value ) ) {
            return [ 'ok' => true, 'attribute' => 'groups', 'value' => $value ];
        }
        return [
            'ok'        => false,
            'attribute' => 'groups',
            'error'     => 'Group membership requires a live Workspace lookup.',
        ];
    }

    private function fetchPermissions( array $record, string $entity_type ): array {
        $value = $record['permissions'] ?? $record['role'] ?? null;
        if ( $value !== null && $value !== '' ) {
            return [ 'ok' => true, 'attribute' => 'permissions', 'value' => $value ];
        }
        return [
            'ok'        => false,
            'attribute' => 'permissions',
            'error'     => 'Permissions require a live role lookup.',
        ];
    }

    private function attributeRegistry(): HermesAttributeRegistry {
        return $this->registry ?? new HermesAttributeRegistry();
    }

    private function lookupPhoneFallback( array $record, string $entity_type ): ?string {
        if ( ! class_exists( 'Metis_Tables' ) || ! function_exists( 'metis_db' ) ) {
            return null;
        }

        $contactsTable = \Metis_Tables::get( 'contacts' );
        $detailsTable = \Metis_Tables::get( 'contact_details' );
        $db = \metis_db();

        if ( in_array( $entity_type, [ 'contact', 'donor' ], true ) ) {
            $cid = trim( (string) ( $record['cid'] ?? '' ) );
            $contactId = (int) ( $record['id'] ?? 0 );
            if ( $cid !== '' || $contactId > 0 ) {
                $row = $db->fetchOne(
                    "SELECT COALESCE(phone, '') AS phone
                     FROM {$detailsTable}
                     WHERE contact_cid = %s OR cid = %s OR contact_id = %d
                     ORDER BY id DESC
                     LIMIT 1",
                    [ $cid, $cid, $contactId ]
                );
                $phone = trim( (string) ( $row['phone'] ?? '' ) );
                if ( $phone !== '' ) {
                    return $phone;
                }
            }
        }

        if ( $entity_type === 'person' ) {
            $did = trim( (string) ( $record['linked_donor_id'] ?? '' ) );
            $email = trim( (string) ( $record['email'] ?? '' ) );
            $row = null;

            if ( $did !== '' ) {
                $row = $db->fetchOne(
                    "SELECT COALESCE(d.phone, '') AS phone
                     FROM {$contactsTable} c
                     LEFT JOIN {$detailsTable} d ON d.contact_cid = c.cid OR d.cid = c.cid OR d.contact_id = c.id
                     WHERE c.did = %s
                     ORDER BY c.id DESC, d.id DESC
                     LIMIT 1",
                    [ $did ]
                );
            }

            if ( ! is_array( $row ) && $email !== '' ) {
                $row = $db->fetchOne(
                    "SELECT COALESCE(d.phone, '') AS phone
                     FROM {$contactsTable} c
                     LEFT JOIN {$detailsTable} d ON d.contact_cid = c.cid OR d.cid = c.cid OR d.contact_id = c.id
                     WHERE LOWER(COALESCE(c.email, '')) = %s
                     ORDER BY c.id DESC, d.id DESC
                     LIMIT 1",
                    [ strtolower( $email ) ]
                );
            }

            $phone = trim( (string) ( $row['phone'] ?? '' ) );
            if ( $phone !== '' ) {
                return $phone;
            }
        }

        return null;
    }
}
