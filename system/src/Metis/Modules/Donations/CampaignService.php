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

    public static function goalStringForCampaign( string $campaign_id, int $year, float $amount ): ?string {
        $campaign_id = trim( $campaign_id );
        if ( $campaign_id === '' || $year <= 0 ) {
            return null;
        }

        $table = \Metis_Tables::get( 'campaigns' );
        $row = \metis_db()->fetchOne(
            "SELECT goals FROM {$table} WHERE cid = %s LIMIT 1",
            [ $campaign_id ]
        );

        if ( ! is_array( $row ) ) {
            return null;
        }

        $goals = self::parseGoalString( (string) ( $row['goals'] ?? '' ) );
        if ( $amount <= 0 ) {
            unset( $goals[ $year ] );
        } else {
            $goals[ $year ] = round( $amount, 2 );
        }

        krsort( $goals );
        return self::serializeGoalMap( $goals );
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

    /**
     * @return array<int,float>
     */
    private static function parseGoalString( string $goal_string ): array {
        $goals = [];
        if ( $goal_string === '' ) {
            return $goals;
        }

        foreach ( explode( '|', $goal_string ) as $entry ) {
            $parts = explode( ':', $entry, 2 );
            if ( count( $parts ) !== 2 || ! is_numeric( $parts[0] ) || ! is_numeric( $parts[1] ) ) {
                continue;
            }

            $goals[ (int) $parts[0] ] = (float) $parts[1];
        }

        return $goals;
    }

    /**
     * @param array<int,float> $goals
     */
    private static function serializeGoalMap( array $goals ): string {
        if ( $goals === [] ) {
            return '';
        }

        return implode( '|', array_map(
            static fn( int $year, float $amount ): string => "{$year}:{$amount}",
            array_keys( $goals ),
            array_values( $goals )
        ) );
    }
}
