<?php
declare(strict_types=1);

namespace Metis\Modules\Import\Services;

final class IdMappingService {
    /** @var array<int,array<string,array<string,string>>> */
    private static array $map = [];

    public static function map( int $job_id, string $entity_type, string|int $source_id, string|int $metis_id ): void {
        $job = max( 0, $job_id );
        $entity = trim( strtolower( $entity_type ) );
        $source = trim( (string) $source_id );
        $target = trim( (string) $metis_id );
        if ( $entity === "" || $source === "" || $target === "" ) {
            return;
        }

        self::$map[ $job ][ $entity ][ $source ] = $target;
    }

    public static function getMappedId( int $job_id, string $entity_type, string|int $source_id ): string {
        return self::resolve( $job_id, $entity_type, $source_id );
    }

    public static function resolve( int $job_id, string $entity_type, string|int $source_id ): string {
        $job = max( 0, $job_id );
        $entity = trim( strtolower( $entity_type ) );
        $source = trim( (string) $source_id );
        if ( $entity === "" || $source === "" ) {
            return "";
        }

        return (string) ( self::$map[ $job ][ $entity ][ $source ] ?? "" );
    }

    public static function lookup( int $job_id, string $entity_type, string|int $source_id ): string {
        return self::resolve( $job_id, $entity_type, $source_id );
    }
}

if ( ! function_exists( "metis_import_id_mapping_service" ) ) {
    function metis_import_id_mapping_service(): object {
        return new class {
            public function map( int $job_id, string $entity_type, string|int $source_id, string|int $metis_id ): void {
                IdMappingService::map( $job_id, $entity_type, $source_id, $metis_id );
            }

            public function getMappedId( int $job_id, string $entity_type, string|int $source_id ): string {
                return IdMappingService::getMappedId( $job_id, $entity_type, $source_id );
            }

            public function resolve( int $job_id, string $entity_type, string|int $source_id ): string {
                return IdMappingService::resolve( $job_id, $entity_type, $source_id );
            }

            public function lookup( int $job_id, string $entity_type, string|int $source_id ): string {
                return IdMappingService::lookup( $job_id, $entity_type, $source_id );
            }
        };
    }
}
