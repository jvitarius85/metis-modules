<?php
declare(strict_types=1);

namespace Metis\Core;

final class EntityCatalog {
    /** @var array<string, array<string, mixed>>|null */
    private static ?array $definitionsCache = null;

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function definitions(): array {
        if ( is_array( self::$definitionsCache ) ) {
            return self::$definitionsCache;
        }

        $definitions = self::coreDefinitions();
        foreach ( self::manifestDefinitions() as $entity_type => $definition ) {
            $definitions[ $entity_type ] = $definition;
        }

        self::$definitionsCache = $definitions;
        return $definitions;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function coreDefinitions(): array {
        return [
            'automation' => [
                'prefix' => 'AUT',
                'description' => 'Automation',
                'table_key' => null,
                'uid_column' => null,
                'module_slug' => 'automations',
                'legacy_columns' => [],
            ],
            'report' => [
                'prefix' => 'REP',
                'description' => 'Report',
                'table_key' => 'reports',
                'uid_column' => 'report_uid',
                'module_slug' => 'reports',
                'legacy_columns' => [],
            ],
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function manifestDefinitions(): array {
        $definitions = [];
        foreach ( ModulePathRegistry::manifestPaths() as $manifest_path ) {
            $payload = json_decode( (string) file_get_contents( (string) $manifest_path ), true );
            if ( ! is_array( $payload ) ) {
                continue;
            }

            $module_slug = \metis_key_clean(
                (string) ( $payload['slug'] ?? basename( dirname( (string) $manifest_path ) ) )
            );
            $entries = is_array( $payload['entity_prefixes'] ?? null ) ? $payload['entity_prefixes'] : [];

            foreach ( $entries as $entry ) {
                if ( ! is_array( $entry ) ) {
                    continue;
                }

                $entity_type = self::normalizeEntityType( (string) ( $entry['entity_type'] ?? '' ) );
                $prefix = strtoupper( trim( (string) ( $entry['prefix'] ?? '' ) ) );
                if ( $entity_type === '' || ! preg_match( '/^[A-Z]{2,8}$/', $prefix ) ) {
                    continue;
                }

                $legacy_columns = [];
                foreach ( (array) ( $entry['legacy_columns'] ?? [] ) as $legacy ) {
                    $legacy = trim( (string) $legacy );
                    if ( $legacy !== '' ) {
                        $legacy_columns[] = $legacy;
                    }
                }

                $description = trim( (string) ( $entry['description'] ?? '' ) );
                $table_key = $entry['table_key'] ?? null;
                $uid_column = $entry['uid_column'] ?? null;
                $where = trim( (string) ( $entry['where'] ?? '' ) );

                $definitions[ $entity_type ] = [
                    'prefix' => $prefix,
                    'description' => $description !== '' ? $description : ucwords( str_replace( '_', ' ', $entity_type ) ),
                    'table_key' => is_string( $table_key ) && $table_key !== '' ? \metis_key_clean( $table_key ) : null,
                    'uid_column' => is_string( $uid_column ) && $uid_column !== '' ? \metis_key_clean( $uid_column ) : null,
                    'module_slug' => \metis_key_clean( (string) ( $entry['module_slug'] ?? $module_slug ) ),
                    'legacy_columns' => array_values( array_unique( $legacy_columns ) ),
                    'where' => $where,
                ];
            }
        }

        return $definitions;
    }

    public static function definition(string $entity_type): ?array {
        $entity_type = self::normalizeEntityType($entity_type);
        $definitions = self::definitions();
        return $definitions[$entity_type] ?? null;
    }

    public static function normalizeEntityType(string $entity_type): string {
        return strtolower(trim($entity_type));
    }

    public static function entityTypeForPrefix(string $prefix): ?string {
        $prefix = strtoupper(trim($prefix));
        foreach (self::definitions() as $entity_type => $definition) {
            if (($definition['prefix'] ?? '') === $prefix) {
                return $entity_type;
            }
        }

        return match ($prefix) {
            'PE' => 'person',
            'CN', 'CT' => 'contact',
            'MW' => 'donor',
            'CP' => 'donation_campaign',
            'TR' => 'donation_transaction',
            'DP' => 'donation_deposit',
            'BT' => 'deposit_batch',
            'NC' => 'newsletter_campaign',
            'NT' => 'newsletter_template',
            'NL' => 'newsletter_list',
            'FM' => 'form',
            'BM' => 'meeting',
            'BD', 'BDC' => 'board_decision_point',
            'BA' => 'board_action_item',
            'WPG' => 'website_page',
            'WBP' => 'website_post',
            'WBN' => 'website_banner',
            default => null,
        };
    }

    public static function definitionForLegacyCode(string $prefix, string $table = '', string $column = ''): ?array {
        $entity_type = self::entityTypeForPrefix($prefix);
        if ($entity_type === null) {
            return null;
        }

        $definition = self::definition($entity_type);
        if (!is_array($definition)) {
            return null;
        }

        if ($table !== '') {
            $expected_table = $definition['table_key'] ? \Metis_Tables::get((string) $definition['table_key']) : '';
            if ($expected_table !== '' && $expected_table !== $table) {
                return null;
            }
        }

        if ($column !== '' && !in_array($column, (array) ($definition['legacy_columns'] ?? []), true)) {
            return null;
        }

        return $definition + [ 'entity_type' => $entity_type ];
    }
}
