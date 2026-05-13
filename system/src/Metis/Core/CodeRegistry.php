<?php
declare(strict_types=1);

namespace Metis\Core {
    final class CodeRegistry {
        public static function init(): void {
            if ( \function_exists( 'metis_entity_id_service' ) ) {
                \metis_entity_id_service()->ensureSchema();
            }
        }

        public static function ensure_schema(): void {
            self::init();
        }

        public static function generate( string $prefix ): string {
            $prefix = strtoupper( trim( $prefix ) );
            if ( $prefix === '' ) {
                $prefix = 'MET';
            }

            if ( \function_exists( 'metis_generate_code' ) ) {
                return \metis_generate_code( $prefix );
            }

            return $prefix . '-' . str_pad( (string) random_int( 1, 999999 ), 6, '0', STR_PAD_LEFT );
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

        public static function register( string $code, string|int $entityTypeOrInternalId = '', int $internalId = 0 ): bool {
            self::init();
            if ( ! \function_exists( 'metis_entity_id_service' ) ) {
                return false;
            }

            $entityType = '';
            $id = 0;

            if ( is_string( $entityTypeOrInternalId ) ) {
                $entityType = trim( $entityTypeOrInternalId );
                $id = $internalId;
            } else {
                $id = (int) $entityTypeOrInternalId;
                $entityType = self::prefix_entity_type( self::parse_prefix( $code ) );
            }

            if ( $id < 1 || $entityType === '' ) {
                return false;
            }

            return (bool) \metis_entity_id_service()->register( $entityType, $id, strtoupper( trim( $code ) ) );
        }

        /**
         * Rebuild the lookup registry from canonical entity catalog definitions.
         *
         * @return array<string, mixed>
         */
        public static function rehydrate( bool $sync_legacy_columns = true ): array {
            self::init();
            if ( ! \function_exists( 'metis_entity_id_service' ) ) {
                return [
                    'ok' => false,
                    'updated_rows' => 0,
                    'registry_rows' => 0,
                    'entities' => [],
                    'message' => 'Entity ID service is unavailable.',
                ];
            }

            $summary = (array) \metis_entity_id_service()->migrateExistingRecords( $sync_legacy_columns );
            $summary['ok'] = true;

            return $summary;
        }

        /**
         * Search registry-backed entity records by code fragments and safe label fields.
         *
         * The code registry remains the authority for returned objects. Matching table rows
         * that do not resolve through the registry are intentionally ignored so keyword lookup
         * cannot expose unregistered records.
         *
         * @return array<int, array<string, mixed>>
         */
        public static function search( string $query, int $limit = 10 ): array {
            self::init();
            if (
                ! \function_exists( 'metis_entity_id_service' )
                || ! \function_exists( 'metis_db' )
                || ! \class_exists( '\Metis_Tables' )
                || ! \class_exists( '\Metis\Core\EntityCatalog' )
            ) {
                return [];
            }

            $query = self::normalize_search_query( $query );
            if ( $query === '' || strlen( str_replace( ' ', '', $query ) ) < 3 ) {
                return [];
            }

            $limit = max( 1, min( 25, $limit ) );
            $pattern = '%' . strtoupper( $query ) . '%';
            $db = \metis_db();
            $service = \metis_entity_id_service();
            if ( ! is_object( $service ) || ! method_exists( $service, 'tableExists' ) || ! method_exists( $service, 'columnExists' ) ) {
                return [];
            }

            $matches = [];
            $seen = [];
            $append = static function ( string $entity_uid, string $matched_on = 'code' ) use ( &$matches, &$seen, $limit ): void {
                if ( count( $matches ) >= $limit ) {
                    return;
                }

                $entity_uid = strtoupper( trim( $entity_uid ) );
                if ( $entity_uid === '' || isset( $seen[ $entity_uid ] ) ) {
                    return;
                }

                $candidate = self::resolve( $entity_uid );
                if ( ! is_array( $candidate ) ) {
                    return;
                }

                $seen[ $entity_uid ] = true;
                $candidate['match_type'] = 'keyword';
                $candidate['matched_on'] = $matched_on;
                $matches[] = $candidate;
            };

            try {
                $registry_table = \Metis_Tables::get( 'entity_registry' );
                if ( is_string( $registry_table ) && $registry_table !== '' && $service->tableExists( $registry_table ) ) {
                    $rows = $db->fetchAll(
                        "SELECT entity_uid
                         FROM {$registry_table}
                         WHERE UPPER(entity_uid) LIKE %s
                         ORDER BY entity_uid ASC
                         LIMIT %d",
                        [ $pattern, $limit ]
                    );

                    foreach ( (array) $rows as $row ) {
                        if ( is_array( $row ) ) {
                            $append( (string) ( $row['entity_uid'] ?? '' ), 'code' );
                        }
                    }
                }
            } catch ( \Throwable ) {
                // Keyword lookup is best-effort; exact code lookup still works if this path is unavailable.
            }

            foreach ( \Metis\Core\EntityCatalog::definitions() as $entity_type => $definition ) {
                if ( count( $matches ) >= $limit ) {
                    break;
                }
                if ( ! is_array( $definition ) ) {
                    continue;
                }

                $table_key = (string) ( $definition['table_key'] ?? '' );
                $uid_column = (string) ( $definition['uid_column'] ?? '' );
                if ( $table_key === '' || $uid_column === '' || ! \Metis_Tables::has( $table_key ) ) {
                    continue;
                }

                $table = \Metis_Tables::get( $table_key );
                if ( ! is_string( $table ) || $table === '' || ! $service->tableExists( $table ) || ! $service->columnExists( $table, $uid_column ) ) {
                    continue;
                }

                $searchable_columns = self::searchable_columns( $table, $definition, $service );
                if ( $searchable_columns === [] ) {
                    continue;
                }

                $clauses = [];
                $params = [];
                foreach ( $searchable_columns as $column ) {
                    $clauses[] = "UPPER(COALESCE({$column}, '')) LIKE %s";
                    $params[] = $pattern;
                }

                if ( $service->columnExists( $table, 'first_name' ) && $service->columnExists( $table, 'last_name' ) ) {
                    $clauses[] = "UPPER(CONCAT_WS(' ', first_name, last_name)) LIKE %s";
                    $params[] = $pattern;
                }

                $where = trim( (string) ( $definition['where'] ?? '' ) );
                $order_column = $service->columnExists( $table, 'id' ) ? 'id' : $uid_column;
                $order_direction = $order_column === 'id' ? 'DESC' : 'ASC';
                $query_limit = min( 50, max( 10, $limit * 3 ) );
                $params[] = $query_limit;

                $sql = "SELECT {$uid_column} AS entity_uid
                        FROM {$table}
                        WHERE {$uid_column} IS NOT NULL
                          AND {$uid_column} <> ''
                          AND (" . implode( ' OR ', $clauses ) . ')';
                if ( $where !== '' ) {
                    $sql .= " AND ({$where})";
                }
                $sql .= " ORDER BY {$order_column} {$order_direction} LIMIT %d";

                try {
                    $rows = $db->fetchAll( $sql, $params );
                } catch ( \Throwable ) {
                    continue;
                }

                foreach ( (array) $rows as $row ) {
                    if ( ! is_array( $row ) ) {
                        continue;
                    }

                    $append( (string) ( $row['entity_uid'] ?? '' ), (string) $entity_type );
                    if ( count( $matches ) >= $limit ) {
                        break 2;
                    }
                }
            }

            return $matches;
        }

        public static function resolve( string $code ): ?array {
            self::init();
            if ( ! \function_exists( 'metis_entity_resolver' ) ) {
                return null;
            }

            $lookup = strtoupper( trim( $code ) );
            $resolved = \metis_entity_resolver()->resolve( $lookup );
            if ( ! is_array( $resolved ) ) {
                return null;
            }

            $entity_type = (string) ( $resolved['entity_type'] ?? '' );
            $entity_id   = (int) ( $resolved['id'] ?? 0 );
            $entity_uid  = (string) ( $resolved['entity_uid'] ?? $lookup );
            $entity_uid  = strtoupper( trim( $entity_uid ) );
            if ( $entity_uid === '' ) {
                $entity_uid = $lookup;
            }

            $row = [];
            $definition = class_exists( '\Metis\Core\EntityCatalog' ) ? \Metis\Core\EntityCatalog::definition( $entity_type ) : null;
            if ( is_array( $definition ) && $entity_id > 0 ) {
                $table_key = (string) ( $definition['table_key'] ?? '' );
                if ( $table_key !== '' && \Metis_Tables::has( $table_key ) ) {
                    $table = \Metis_Tables::get( $table_key );
                    $row = \metis_db()->fetchOne( "SELECT * FROM {$table} WHERE id = %d LIMIT 1", [ $entity_id ] ) ?: [];

                    $uid_column = (string) ( $definition['uid_column'] ?? '' );
                    if ( $uid_column !== '' && is_array( $row ) ) {
                        $canonical = strtoupper( trim( (string) ( $row[ $uid_column ] ?? '' ) ) );
                        if ( $canonical !== '' ) {
                            $entity_uid = $canonical;
                        }
                    }
                }
            }

            $prefix = self::parse_prefix( $entity_uid );
            $meta = self::entity_meta( $entity_type, $entity_id, $entity_uid, is_array( $row ) ? $row : [], is_array( $definition ) ? $definition : [] );

            return [
                'code' => $entity_uid,
                'entity_uid' => $entity_uid,
                'prefix' => $prefix,
                'label' => (string) ( $meta['label'] ?? self::prefix_label( $prefix ) ),
                'entity_type' => $entity_type,
                'module_slug' => (string) ( $resolved['module_slug'] ?? ( is_array( $definition ) ? (string) ( $definition['module_slug'] ?? '' ) : '' ) ),
                'internal_id' => $entity_id,
                'id' => $entity_id,
                'resolve_url' => (string) ( $meta['url'] ?? '' ),
                'table' => (string) ( $resolved['table'] ?? '' ),
            ];
        }

        public static function prefix_entity_type( string $prefix ): string {
            return class_exists( '\Metis\Core\EntityCatalog' ) ? (string) ( \Metis\Core\EntityCatalog::entityTypeForPrefix( $prefix ) ?? '' ) : '';
        }

        public static function prefix_label( string $prefix ): string {
            $entity_type = class_exists( '\Metis\Core\EntityCatalog' ) ? \Metis\Core\EntityCatalog::entityTypeForPrefix( $prefix ) : null;
            $definition = is_string( $entity_type ) ? \Metis\Core\EntityCatalog::definition( $entity_type ) : null;
            return (string) ( $definition['description'] ?? strtoupper( $prefix ) );
        }

        private static function normalize_search_query( string $query ): string {
            $query = preg_replace( '/[\x00-\x1F\x7F]+/', ' ', $query ) ?? '';
            $query = preg_replace( '/[^A-Za-z0-9 @.-]+/', ' ', $query ) ?? '';
            $query = preg_replace( '/\s+/', ' ', $query ) ?? '';
            return trim( $query );
        }

        /**
         * @param array<string, mixed> $definition
         * @return array<int, string>
         */
        private static function searchable_columns( string $table, array $definition, object $service ): array {
            $candidates = [
                (string) ( $definition['uid_column'] ?? '' ),
                ...array_map( 'strval', (array) ( $definition['legacy_columns'] ?? [] ) ),
                'display_name',
                'first_name',
                'last_name',
                'email',
                'name',
                'title',
                'subject',
                'label',
                'slug',
                'cname',
                'provider_ref',
                'batch_name',
                'batch_code',
                'transaction_uid',
                'campaign_code',
                'template_code',
                'list_key',
                'meeting_code',
                'page_title',
                'post_title',
                'banner_title',
                'role_name',
                'role_key',
                'permission_key',
                'permission_label',
                'event_title',
                'form_uuid',
                'submission_key',
            ];

            $columns = [];
            foreach ( $candidates as $column ) {
                $column = trim( (string) $column );
                if ( $column === '' || isset( $columns[ $column ] ) || preg_match( '/^[A-Za-z0-9_]+$/', $column ) !== 1 ) {
                    continue;
                }

                if ( method_exists( $service, 'columnExists' ) && $service->columnExists( $table, $column ) ) {
                    $columns[ $column ] = true;
                }
            }

            return array_keys( $columns );
        }

        /**
         * @param array<string, mixed> $row
         * @param array<string, mixed> $definition
         * @return array{label:string,url:string}
         */
        private static function entity_meta( string $entity_type, int $entity_id, string $entity_uid, array $row, array $definition ): array {
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
                    if ( \function_exists( 'metis_contacts_detail_url' ) ) {
                        $url = \metis_contacts_detail_url( (string) ( $row['cid'] ?? $row['contact_uid'] ?? $entity_uid ) );
                    }
                    break;

                case 'person':
                    $label = trim( (string) ( $row['display_name'] ?? '' ) );
                    if ( $label === '' ) {
                        $label = trim( (string) ( $row['first_name'] ?? '' ) . ' ' . (string) ( $row['last_name'] ?? '' ) );
                    }
                    $label = $label !== '' ? $label : (string) ( $row['email'] ?? $entity_uid );
                    if ( \function_exists( 'metis_people_person_url' ) ) {
                        $url = \metis_people_person_url( (string) ( $row['pid'] ?? $row['person_uid'] ?? $entity_uid ) );
                    }
                    break;

                case 'donor':
                    $label = trim( (string) ( $row['display_name'] ?? '' ) );
                    if ( $label === '' ) {
                        $label = trim( (string) ( $row['first_name'] ?? '' ) . ' ' . (string) ( $row['last_name'] ?? '' ) );
                    }
                    $label = $label !== '' ? $label : (string) ( $row['email'] ?? $entity_uid );
                    $url = \metis_portal_url( 'donations', 'donor' ) . '?id=' . rawurlencode( (string) ( $row['did'] ?? $row['donor_uid'] ?? $entity_uid ) );
                    break;

                case 'donation_campaign':
                    $label = (string) ( $row['cname'] ?? $row['name'] ?? $entity_uid );
                    $url = \metis_portal_url( 'donations', 'campaign' ) . '?cid=' . rawurlencode( (string) ( $row['cid'] ?? $row['campaign_uid'] ?? $entity_uid ) );
                    break;

                case 'donation_transaction':
                    $label = (string) ( $row['transaction_uid'] ?? $row['tid'] ?? $entity_uid );
                    $url = \metis_portal_url( 'donations', 'transaction' ) . '?id=' . rawurlencode( (string) ( $row['tid'] ?? $row['transaction_uid'] ?? $entity_uid ) );
                    break;

                case 'donation_deposit':
                    $label = (string) ( $row['provider_ref'] ?? $row['deposit_uid'] ?? $entity_uid );
                    $url = \metis_portal_url( 'donations', 'deposit' ) . '?id=' . rawurlencode( (string) ( $row['provider_ref'] ?? $row['deposit_uid'] ?? $entity_uid ) );
                    break;

                case 'deposit_batch':
                    $label = (string) ( $row['batch_name'] ?? $row['batch_code'] ?? $row['batch_uid'] ?? $entity_uid );
                    $url = \metis_portal_url( 'donations', 'deposits' );
                    break;

                case 'newsletter_campaign':
                    $label = (string) ( $row['name'] ?? $row['subject'] ?? $entity_uid );
                    $url = \metis_portal_url( 'newsletter', 'campaigns' );
                    break;

                case 'newsletter_template':
                    $label = (string) ( $row['name'] ?? $entity_uid );
                    $url = \metis_portal_url( 'newsletter', 'theme' );
                    break;

                case 'newsletter_list':
                    $label = (string) ( $row['name'] ?? $entity_uid );
                    $url = \metis_portal_url( 'newsletter', 'lists' );
                    break;

                case 'meeting':
                    $label = (string) ( $row['title'] ?? $row['meeting_uid'] ?? $entity_uid );
                    if ( \function_exists( 'metis_board_meeting_url' ) ) {
                        $url = \metis_board_meeting_url( (string) ( $row['meeting_code'] ?? $row['meeting_uid'] ?? $entity_uid ) );
                    }
                    break;

                case 'form':
                    $label = (string) ( $row['name'] ?? $entity_uid );
                    if ( \function_exists( 'metis_forms_detail_url' ) ) {
                        $url = \metis_forms_detail_url( (int) ( $row['id'] ?? $entity_id ) );
                    }
                    break;

                case 'security_role':
                    $label = (string) ( $row['role_name'] ?? $row['role_key'] ?? $entity_uid );
                    if ( \function_exists( 'metis_people_role_url' ) ) {
                        $url = \metis_people_role_url( (string) ( $row['role_key'] ?? '' ), (string) ( $row['role_domain'] ?? 'metis' ) );
                    }
                    break;

                default:
                    foreach ( [ 'title', 'name', 'display_name', 'subject', 'label' ] as $field ) {
                        $value = trim( (string) ( $row[ $field ] ?? '' ) );
                        if ( $value !== '' ) {
                            $label = $value;
                            break;
                        }
                    }
                    if ( $label === '' ) {
                        $label = $entity_uid !== '' ? $entity_uid : $fallback_label;
                    }
                    $module_slug = (string) ( $definition['module_slug'] ?? '' );
                    if ( $module_slug !== '' ) {
                        $url = \metis_portal_url( $module_slug, 'dashboard' );
                    }
                    break;
            }

            return [
                'label' => $label !== '' ? $label : $fallback_label,
                'url' => $url,
            ];
        }
    }
}

namespace {
    if ( ! defined( 'METIS_ROOT' ) ) {
        exit;
    }

    if ( ! class_exists( 'Metis_Code_Registry', false ) ) {
        final class Metis_Code_Registry {
            public static function init(): void {
                \Metis\Core\CodeRegistry::init();
            }

            public static function ensure_schema(): void {
                \Metis\Core\CodeRegistry::ensure_schema();
            }

            public static function generate( string $prefix ): string {
                return \Metis\Core\CodeRegistry::generate( $prefix );
            }

            public static function parse_prefix( string $code ): string {
                return \Metis\Core\CodeRegistry::parse_prefix( $code );
            }

            public static function register( string $code, int $internal_id = 0, string $resolve_url = '' ): bool {
                return \Metis\Core\CodeRegistry::register( $code, $internal_id );
            }

            public static function resolve( string $code ): ?array {
                return \Metis\Core\CodeRegistry::resolve( $code );
            }

            public static function search( string $query, int $limit = 10 ): array {
                return \Metis\Core\CodeRegistry::search( $query, $limit );
            }

            public static function rehydrate( bool $sync_legacy_columns = true ): array {
                return \Metis\Core\CodeRegistry::rehydrate( $sync_legacy_columns );
            }
        }
    }
}
