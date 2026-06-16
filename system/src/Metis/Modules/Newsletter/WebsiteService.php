<?php
declare(strict_types=1);

namespace Metis\Modules\Newsletter;

final class WebsiteService {
    /**
     * @return array<int,array{value:string,label:string}>
     */
    public static function listOptions(): array {
        NewsletterModule::ensureSchema();

        $rows = \metis_db()->fetchAll(
            'SELECT id, name FROM ' . \Metis_Tables::get( 'newsletter_lists' ) . ' WHERE is_active = 1 ORDER BY name ASC'
        ) ?: [];

        $options = [];
        foreach ( $rows as $row ) {
            $id = (int) ( $row['id'] ?? 0 );
            if ( $id < 1 ) {
                continue;
            }
            $options[] = [
                'value' => (string) $id,
                'label' => trim( (string) ( $row['name'] ?? ( 'List ' . $id ) ) ),
            ];
        }

        return $options;
    }

    /**
     * @param mixed $raw
     * @return array<int,int>
     */
    public static function normalizeListIds( mixed $raw ): array {
        $source = [];
        if ( is_array( $raw ) ) {
            $source = $raw;
        } elseif ( is_string( $raw ) ) {
            $decoded = json_decode( $raw, true );
            if ( is_array( $decoded ) ) {
                $source = $decoded;
            } else {
                $source = preg_split( '/\s*,\s*/', $raw ) ?: [];
            }
        } elseif ( is_scalar( $raw ) ) {
            $source = [ $raw ];
        }

        $list_ids = array_values(
            array_unique(
                array_filter(
                    array_map(
                        'intval',
                        $source
                    ),
                    static fn( int $id ): bool => $id > 0
                )
            )
        );

        return CampaignService::normalizeListIds( $list_ids );
    }

    /**
     * @param array<int,int> $list_ids
     * @return array<int,array{id:int,name:string,ref:string}>
     */
    public static function listsByIds( array $list_ids ): array {
        $list_ids = CampaignService::normalizeListIds( $list_ids );
        if ( $list_ids === [] ) {
            return [];
        }

        $lists_table = \Metis_Tables::get( 'newsletter_lists' );
        $query = \metis_db()->prepare(
            'SELECT id, name, newsletter_list_uid, list_key FROM ' . $lists_table . ' WHERE id IN (' . implode( ',', array_fill( 0, count( $list_ids ), '%d' ) ) . ') ORDER BY name ASC',
            ...$list_ids
        );
        $rows = \metis_db()->fetchAll( $query ) ?: [];

        $lists = [];
        foreach ( $rows as $row ) {
            $id = (int) ( $row['id'] ?? 0 );
            if ( $id < 1 ) {
                continue;
            }
            $lists[] = [
                'id' => $id,
                'name' => trim( (string) ( $row['name'] ?? ( 'List ' . $id ) ) ),
                'ref' => trim( (string) ( ( $row['newsletter_list_uid'] ?? '' ) ?: ( $row['list_key'] ?? '' ) ) ),
            ];
        }

        return $lists;
    }

    /**
     * @param array<int,int> $list_ids
     * @return array<int,array<string,mixed>>
     */
    public static function publicArchiveCampaigns( array $list_ids, int $limit = 12 ): array {
        return self::fetchArchiveCampaignRows( $list_ids, $limit, 0 );
    }

    /**
     * @return array{rows: array<int,array<string,mixed>>, page: int, per_page: int, has_more: bool}
     */
    public static function publicArchiveCampaignPage( array $list_ids, int $per_page = 12, int $page = 1 ): array {
        $per_page = max( 1, min( 50, $per_page ) );
        $page = max( 1, $page );
        $offset = ( $page - 1 ) * $per_page;
        $rows = self::fetchArchiveCampaignRows( $list_ids, $per_page + 1, $offset );
        $has_more = count( $rows ) > $per_page;
        if ( $has_more ) {
            $rows = array_slice( $rows, 0, $per_page );
        }

        return [
            'rows' => $rows,
            'page' => $page,
            'per_page' => $per_page,
            'has_more' => $has_more,
        ];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private static function fetchArchiveCampaignRows( array $list_ids, int $limit = 12, int $offset = 0 ): array {
        NewsletterModule::ensureSchema();

        $limit = max( 1, min( 51, $limit ) );
        $offset = max( 0, $offset );
        $campaigns_table = \Metis_Tables::get( 'newsletter_campaigns' );
        $campaign_lists_table = \Metis_Tables::get( 'newsletter_campaign_lists' );
        $lists_table = \Metis_Tables::get( 'newsletter_lists' );

        $params = [];
        $join = '';
        $where = "WHERE c.status = 'sent'";
        $group = ' GROUP BY c.id';

        $list_ids = CampaignService::normalizeListIds( $list_ids );
        if ( $list_ids !== [] ) {
            $join = " INNER JOIN {$campaign_lists_table} cl ON cl.campaign_id = c.id INNER JOIN {$lists_table} l ON l.id = cl.list_id";
            $where .= ' AND cl.list_id IN (' . implode( ',', array_fill( 0, count( $list_ids ), '%d' ) ) . ')';
            $params = $list_ids;
        } else {
            $join = " LEFT JOIN {$campaign_lists_table} cl ON cl.campaign_id = c.id LEFT JOIN {$lists_table} l ON l.id = cl.list_id";
        }

        $params[] = $limit;
        $params[] = $offset;
        $query = \metis_db()->prepare(
            "SELECT c.id, c.campaign_code, c.name, c.subject, c.preheader, c.sent_at, c.updated_at,
                    GROUP_CONCAT(DISTINCT l.name ORDER BY l.name SEPARATOR '||') AS list_names
             FROM {$campaigns_table} c
             {$join}
             {$where}
             {$group}
             ORDER BY COALESCE(c.sent_at, c.updated_at, c.created_at) DESC, c.id DESC
             LIMIT %d OFFSET %d",
            ...$params
        );

        return \metis_db()->fetchAll( $query ) ?: [];
    }
}
