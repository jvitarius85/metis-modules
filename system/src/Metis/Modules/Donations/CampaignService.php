<?php
declare(strict_types=1);

namespace Metis\Modules\Donations;

final class CampaignService {
    private const ACTIVE_STATUS = 'active';
    private const ACTIVE_FLAG = 1;

    /**
     * @return array<int,array<string,mixed>>
     */
    public static function getActiveCampaigns( int $limit = 300 ): array {
        $table = \Metis_Tables::get( 'campaigns' );
        if ( ! is_string( $table ) || $table === '' ) {
            return [];
        }

        $columns = self::availableColumns( $table );
        if ( $columns === [] ) {
            return [];
        }

        $select_columns = [];
        foreach ( [ 'id', 'cid', 'campaign_uid', 'campaign_code', 'code', 'cname', 'name', 'active', 'status' ] as $column ) {
            if ( isset( $columns[ $column ] ) ) {
                $select_columns[] = $column;
            }
        }

        if ( $select_columns === [] ) {
            return [];
        }

        $where = [];
        $args = [];
        if ( isset( $columns['active'] ) ) {
            $where[] = 'active = %d';
            $args[] = self::ACTIVE_FLAG;
        }
        if ( isset( $columns['status'] ) ) {
            $where[] = 'LOWER(COALESCE(status, \'\')) = %s';
            $args[] = self::ACTIVE_STATUS;
        }

        $order_by = isset( $columns['cname'] )
            ? 'cname ASC'
            : ( isset( $columns['name'] ) ? 'name ASC' : 'id DESC' );

        $sql = sprintf(
            'SELECT %s FROM %s%s ORDER BY %s LIMIT %%d',
            implode( ', ', $select_columns ),
            $table,
            $where === [] ? '' : ' WHERE ' . implode( ' AND ', $where ),
            $order_by
        );
        $args[] = max( 1, min( 1000, $limit ) );

        return \metis_db()->fetchAll( $sql, $args );
    }

    /**
     * @return array<int,array{label:string,value:string,category:string}>
     */
    public static function getActiveCampaignOptions( int $limit = 300 ): array {
        $options = [];
        foreach ( self::getActiveCampaigns( $limit ) as $row ) {
            $value = self::campaignValue( $row );
            if ( $value === '' ) {
                continue;
            }

            $options[] = [
                'label' => self::campaignLabel( $row, $value ),
                'value' => $value,
                'category' => '',
            ];
        }

        return $options;
    }

    /**
     * @return array<string,bool>
     */
    private static function availableColumns( string $table ): array {
        $columns = [];
        foreach ( \metis_db()->fetchAll( "SHOW COLUMNS FROM {$table}" ) as $row ) {
            $name = strtolower( trim( (string) ( $row['Field'] ?? '' ) ) );
            if ( $name !== '' ) {
                $columns[ $name ] = true;
            }
        }

        return $columns;
    }

    /**
     * @param array<string,mixed> $row
     */
    private static function campaignValue( array $row ): string {
        foreach ( [ 'campaign_uid', 'cid', 'campaign_code', 'code', 'id' ] as $key ) {
            $value = trim( (string) ( $row[ $key ] ?? '' ) );
            if ( $value !== '' ) {
                return $value;
            }
        }

        return '';
    }

    /**
     * @param array<string,mixed> $row
     */
    private static function campaignLabel( array $row, string $fallback ): string {
        $label = trim( (string) ( $row['cname'] ?? $row['name'] ?? '' ) );
        return $label !== '' ? $label : $fallback;
    }
}
