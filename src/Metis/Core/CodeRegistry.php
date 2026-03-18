<?php
if ( ! defined( 'METIS_ROOT' ) ) exit;

final class Metis_Code_Registry {
    public static function init(): void {
        if ( function_exists( 'metis_entity_id_service' ) ) {
            metis_entity_id_service()->ensureSchema();
        }
    }

    public static function ensure_schema(): void {
        self::init();
    }

    public static function parse_prefix( string $code ): string {
        $code = strtoupper( trim( $code ) );
        if ( strpos( $code, '-' ) !== false ) {
            return (string) explode( '-', $code )[0];
        }

        if ( preg_match( '/^([A-Z]+)/', $code, $matches ) ) {
            return (string) ( $matches[1] ?? '' );
        }

        return '';
    }

    public static function prefix_label( string $prefix ): string {
        $entity_type = class_exists( '\Metis\Core\EntityCatalog' ) ? \Metis\Core\EntityCatalog::entityTypeForPrefix( $prefix ) : null;
        $definition = is_string( $entity_type ) ? \Metis\Core\EntityCatalog::definition( $entity_type ) : null;
        return (string) ( $definition['description'] ?? strtoupper( $prefix ) );
    }

    public static function prefix_module( string $prefix ): string {
        $entity_type = class_exists( '\Metis\Core\EntityCatalog' ) ? \Metis\Core\EntityCatalog::entityTypeForPrefix( $prefix ) : null;
        $definition = is_string( $entity_type ) ? \Metis\Core\EntityCatalog::definition( $entity_type ) : null;
        return (string) ( $definition['module_slug'] ?? '' );
    }

    public static function prefix_entity_type( string $prefix ): string {
        return class_exists( '\Metis\Core\EntityCatalog' ) ? (string) ( \Metis\Core\EntityCatalog::entityTypeForPrefix( $prefix ) ?? '' ) : '';
    }

    public static function get_prefix_map(): array {
        return class_exists( '\Metis\Core\EntityCatalog' ) ? \Metis\Core\EntityCatalog::definitions() : [];
    }

    public static function register( string $code, int $internal_id = 0, string $resolve_url = '' ): bool {
        self::init();
        if ( ! function_exists( 'metis_entity_id_service' ) || $internal_id < 1 ) {
            return false;
        }

        $entity_type = self::prefix_entity_type( self::parse_prefix( $code ) );
        if ( $entity_type === '' ) {
            return false;
        }

        return metis_entity_id_service()->register( $entity_type, $internal_id, strtoupper( trim( $code ) ) );
    }

    public static function update_url( string $code, string $resolve_url ): bool {
        return true;
    }

    public static function resolve( string $code ): ?array {
        self::init();
        if ( ! function_exists( 'metis_entity_resolver' ) ) {
            return null;
        }

        $resolved = metis_entity_resolver()->resolve( strtoupper( trim( $code ) ) );
        if ( ! is_array( $resolved ) ) {
            return null;
        }

        $prefix = self::parse_prefix( (string) ( $resolved['entity_uid'] ?? '' ) );

        $entity_type = (string) ( $resolved['entity_type'] ?? '' );
        $entity_id   = (int) ( $resolved['id'] ?? 0 );
        $meta        = self::entity_meta( $entity_type, $entity_id, (string) ( $resolved['entity_uid'] ?? '' ) );

        return [
            'code' => (string) ( $resolved['entity_uid'] ?? '' ),
            'prefix' => $prefix,
            'label' => (string) ( $meta['label'] ?? self::prefix_label( $prefix ) ),
            'entity_type' => $entity_type,
            'module_slug' => (string) ( $resolved['module_slug'] ?? '' ),
            'internal_id' => $entity_id,
            'resolve_url' => (string) ( $meta['url'] ?? '' ),
            'table' => (string) ( $resolved['table'] ?? '' ),
        ];
    }

    private static function entity_meta( string $entity_type, int $entity_id, string $entity_uid ): array {
        $definition = class_exists( '\Metis\Core\EntityCatalog' ) ? \Metis\Core\EntityCatalog::definition( $entity_type ) : null;
        if ( ! is_array( $definition ) ) {
            return [ 'label' => self::prefix_label( self::parse_prefix( $entity_uid ) ), 'url' => '' ];
        }

        $table_key = (string) ( $definition['table_key'] ?? '' );
        $table = $table_key !== '' && \Metis_Tables::has( $table_key ) ? \Metis_Tables::get( $table_key ) : '';
        $row = [];
        if ( $entity_id > 0 && $table !== '' ) {
            $row = \metis_db()->fetchOne( "SELECT * FROM {$table} WHERE id = %d LIMIT 1", [ $entity_id ] ) ?: [];
        }

        $fallback_label = (string) ( $definition['description'] ?? self::prefix_label( self::parse_prefix( $entity_uid ) ) );
        $label = $fallback_label;
        $url = '';

        switch ( $entity_type ) {
            case 'contact':
                $label = trim( (string) ( $row['display_name'] ?? '' ) );
                if ( $label === '' ) {
                    $label = trim( (string) ( $row['first_name'] ?? '' ) . ' ' . (string) ( $row['last_name'] ?? '' ) );
                }
                $label = $label !== '' ? $label : (string) ( $row['email'] ?? $entity_uid );
                if ( function_exists( 'metis_contacts_detail_url' ) ) {
                    $url = metis_contacts_detail_url( (string) ( $row['cid'] ?? $row['contact_uid'] ?? $entity_uid ) );
                }
                break;

            case 'person':
                $label = trim( (string) ( $row['display_name'] ?? '' ) );
                if ( $label === '' ) {
                    $label = trim( (string) ( $row['first_name'] ?? '' ) . ' ' . (string) ( $row['last_name'] ?? '' ) );
                }
                $label = $label !== '' ? $label : (string) ( $row['email'] ?? $entity_uid );
                if ( function_exists( 'metis_people_person_url' ) ) {
                    $url = metis_people_person_url( (string) ( $row['pid'] ?? $row['person_uid'] ?? $entity_uid ) );
                }
                break;

            case 'donor':
                $label = trim( (string) ( $row['display_name'] ?? '' ) );
                if ( $label === '' ) {
                    $label = trim( (string) ( $row['first_name'] ?? '' ) . ' ' . (string) ( $row['last_name'] ?? '' ) );
                }
                $label = $label !== '' ? $label : (string) ( $row['email'] ?? $entity_uid );
                $url = metis_portal_url( 'donations', 'donor' ) . '?id=' . rawurlencode( (string) ( $row['did'] ?? $row['donor_uid'] ?? $entity_uid ) );
                break;

            case 'donation_campaign':
                $label = (string) ( $row['cname'] ?? $row['name'] ?? $entity_uid );
                $url = metis_portal_url( 'donations', 'campaign' ) . '?cid=' . rawurlencode( (string) ( $row['cid'] ?? $row['campaign_uid'] ?? $entity_uid ) );
                break;

            case 'donation_transaction':
                $label = (string) ( $row['transaction_uid'] ?? $row['tid'] ?? $entity_uid );
                $url = metis_portal_url( 'donations', 'transaction' ) . '?id=' . rawurlencode( (string) ( $row['tid'] ?? $row['transaction_uid'] ?? $entity_uid ) );
                break;

            case 'donation_deposit':
                $label = (string) ( $row['provider_ref'] ?? $row['deposit_uid'] ?? $entity_uid );
                $url = metis_portal_url( 'donations', 'deposit' ) . '?id=' . rawurlencode( (string) ( $row['provider_ref'] ?? $row['deposit_uid'] ?? $entity_uid ) );
                break;

            case 'deposit_batch':
                $label = (string) ( $row['batch_name'] ?? $row['batch_code'] ?? $row['batch_uid'] ?? $entity_uid );
                $url = metis_portal_url( 'donations', 'deposits' );
                break;

            case 'newsletter_campaign':
                $label = (string) ( $row['name'] ?? $row['subject'] ?? $entity_uid );
                $url = metis_portal_url( 'newsletter', 'campaigns' );
                break;

            case 'newsletter_template':
                $label = (string) ( $row['name'] ?? $entity_uid );
                $url = metis_portal_url( 'newsletter', 'templates' );
                break;

            case 'newsletter_list':
                $label = (string) ( $row['name'] ?? $entity_uid );
                $url = metis_portal_url( 'newsletter', 'lists' );
                break;

            case 'meeting':
                $label = (string) ( $row['title'] ?? $row['meeting_uid'] ?? $entity_uid );
                if ( function_exists( 'metis_board_meeting_url' ) ) {
                    $url = metis_board_meeting_url( (string) ( $row['meeting_code'] ?? $row['meeting_uid'] ?? $entity_uid ) );
                }
                break;

            case 'form':
                $label = (string) ( $row['name'] ?? $entity_uid );
                if ( function_exists( 'metis_forms_detail_url' ) ) {
                    $url = metis_forms_detail_url( (int) ( $row['id'] ?? $entity_id ) );
                }
                break;

            case 'security_role':
                $label = (string) ( $row['role_name'] ?? $row['role_key'] ?? $entity_uid );
                if ( function_exists( 'metis_people_role_url' ) ) {
                    $url = metis_people_role_url( (string) ( $row['role_key'] ?? '' ), (string) ( $row['role_domain'] ?? 'metis' ) );
                }
                break;

            default:
                $label = self::default_label_for_row( $row, $fallback_label, $entity_uid );
                $module_slug = (string) ( $definition['module_slug'] ?? '' );
                if ( $module_slug !== '' ) {
                    $url = metis_portal_url( $module_slug, 'dashboard' );
                }
                break;
        }

        return [
            'label' => $label !== '' ? $label : $fallback_label,
            'url' => $url,
        ];
    }

    private static function default_label_for_row( array $row, string $fallback, string $entity_uid ): string {
        foreach ( [ 'title', 'name', 'display_name', 'subject', 'label' ] as $field ) {
            $value = trim( (string) ( $row[ $field ] ?? '' ) );
            if ( $value !== '' ) {
                return $value;
            }
        }

        return $entity_uid !== '' ? $entity_uid : $fallback;
    }
}
