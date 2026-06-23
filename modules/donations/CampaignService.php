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
        return self::listCampaigns( $limit, true );
    }

    /**
     * @return array<int,array{label:string,value:string,category:string}>
     */
    public static function getActiveCampaignOptions( int $limit = 300 ): array {
        return self::getCampaignOptions( $limit, true );
    }

    /**
     * @return array<int,array{label:string,value:string,category:string}>
     */
    public static function getCampaignOptions( int $limit = 300, bool $active_only = false ): array {
        $options = [];
        foreach ( self::listCampaigns( $limit, $active_only ) as $row ) {
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

    public static function resolveCampaignReference( string $reference, bool $active_only = false ): ?string {
        $reference = trim( $reference );
        if ( $reference === '' ) {
            return null;
        }

        foreach ( self::listCampaigns( 1000, $active_only ) as $row ) {
            $values = array_filter( [
                trim( (string) ( $row['campaign_uid'] ?? '' ) ),
                trim( (string) ( $row['cid'] ?? '' ) ),
                trim( (string) ( $row['campaign_code'] ?? '' ) ),
                trim( (string) ( $row['code'] ?? '' ) ),
                isset( $row['id'] ) ? trim( (string) $row['id'] ) : '',
            ] );
            if ( in_array( $reference, $values, true ) ) {
                $cid = trim( (string) ( $row['cid'] ?? '' ) );
                return $cid !== '' ? $cid : null;
            }
        }

        return null;
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

    public static function normalizeDescriptionHtml( string $html ): string {
        $current = function_exists( 'metis_runtime_normalize_text_encoding' )
            ? trim( (string) \metis_runtime_normalize_text_encoding( $html ) )
            : trim( $html );
        if ( $current === '' ) {
            return '';
        }
        if ( strpos( $current, '<' ) === false || ! class_exists( '\DOMDocument' ) ) {
            return $current;
        }

        $document = new \DOMDocument( '1.0', 'UTF-8' );
        $wrapped = '<div id="metis-donation-description-root">' . $current . '</div>';
        $loaded = @$document->loadHTML(
            '<?xml encoding="utf-8" ?>' . $wrapped,
            \LIBXML_HTML_NOIMPLIED | \LIBXML_HTML_NODEFDTD
        );
        if ( ! $loaded ) {
            return $current;
        }

        $root = $document->getElementById( 'metis-donation-description-root' );
        if ( ! $root instanceof \DOMNode ) {
            return $current;
        }

        self::normalizeDescriptionElements( $root, $document );
        self::repairDescriptionTextNodes( $root );

        $output = '';
        foreach ( $root->childNodes as $child ) {
            $output .= $document->saveHTML( $child );
        }

        return function_exists( 'metis_runtime_normalize_text_encoding' )
            ? trim( (string) \metis_runtime_normalize_text_encoding( $output ) )
            : trim( $output );
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
     * @return array<int,array<string,mixed>>
     */
    private static function listCampaigns( int $limit, bool $active_only ): array {
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
        if ( $active_only ) {
            if ( isset( $columns['active'] ) ) {
                $where[] = 'active = %d';
                $args[] = self::ACTIVE_FLAG;
            }
            if ( isset( $columns['status'] ) ) {
                $where[] = 'LOWER(COALESCE(status, \'\')) = %s';
                $args[] = self::ACTIVE_STATUS;
            }
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

    private static function normalizeDescriptionElements( \DOMNode $node, \DOMDocument $document ): void {
        for ( $child = $node->firstChild; $child !== null; $child = $next ) {
            $next = $child->nextSibling;
            if ( $child instanceof \DOMElement ) {
                if ( strtolower( $child->tagName ) === 'img' ) {
                    self::normalizeDescriptionImage( $child, $document );
                    continue;
                }

                self::normalizeDescriptionElementStyles( $child );
                self::normalizeDescriptionElements( $child, $document );
            }
        }
    }

    private static function normalizeDescriptionImage( \DOMElement $image, \DOMDocument $document ): void {
        $replacement = trim( (string) $image->getAttribute( 'alt' ) );
        if ( $replacement === '' ) {
            $replacement = trim( (string) $image->getAttribute( 'aria-label' ) );
        }
        if ( $replacement === '' ) {
            $replacement = trim( (string) $image->getAttribute( 'title' ) );
        }

        $class_name = strtolower( trim( (string) $image->getAttribute( 'class' ) ) );
        $src = strtolower( trim( (string) $image->getAttribute( 'src' ) ) );
        $looks_like_emoji = $replacement !== '' && self::looksLikeEmojiText( $replacement );
        $emoji_markup = str_contains( $class_name, 'emoji' )
            || str_contains( $class_name, 'ql-emoj' )
            || str_contains( $src, 'emoji' )
            || str_contains( $src, 'twemoji' )
            || str_contains( $src, 'emojione' )
            || str_contains( $src, 'data:image/' );

        if ( $replacement !== '' ) {
            $text = $document->createTextNode( $replacement );
            if ( $image->parentNode ) {
                $image->parentNode->replaceChild( $text, $image );
            }
            return;
        }

        if ( $image->parentNode ) {
            $image->parentNode->removeChild( $image );
        }
    }

    private static function normalizeDescriptionElementStyles( \DOMElement $element ): void {
        if ( ! $element->hasAttribute( 'style' ) ) {
            return;
        }

        $style = strtolower( trim( (string) $element->getAttribute( 'style' ) ) );
        if ( $style === '' ) {
            $element->removeAttribute( 'style' );
            return;
        }

        $normalized = [];
        foreach ( explode( ';', $style ) as $declaration ) {
            $parts = explode( ':', $declaration, 2 );
            if ( count( $parts ) !== 2 ) {
                continue;
            }
            $property = trim( $parts[0] );
            $value = trim( $parts[1] );
            if ( $property === '' || $value === '' ) {
                continue;
            }
            if ( ! in_array( $property, [ 'color', 'font-weight', 'font-style', 'text-decoration', 'text-align', 'font-size' ], true ) ) {
                continue;
            }
            if ( $property === 'font-size' && preg_match( '/([0-9]+(?:\.[0-9]+)?)(px|rem|em|pt)/', $value, $matches ) ) {
                $size = (float) $matches[1];
                $unit = strtolower( $matches[2] );
                if (
                    ( $unit === 'px' && $size > 32 )
                    || ( $unit === 'pt' && $size > 24 )
                    || ( in_array( $unit, [ 'rem', 'em' ], true ) && $size > 2 )
                ) {
                    continue;
                }
            }
            $normalized[] = $property . ':' . $value;
        }

        if ( $normalized === [] ) {
            $element->removeAttribute( 'style' );
            return;
        }

        $element->setAttribute( 'style', implode( ';', $normalized ) );
    }

    private static function repairDescriptionTextNodes( \DOMNode $node ): void {
        foreach ( $node->childNodes as $child ) {
            if ( $child->nodeType === \XML_TEXT_NODE ) {
                $child->nodeValue = self::repairDescriptionText( (string) $child->nodeValue );
                continue;
            }
            self::repairDescriptionTextNodes( $child );
        }
    }

    private static function repairDescriptionText( string $text ): string {
        return function_exists( 'metis_runtime_normalize_text_encoding' )
            ? (string) \metis_runtime_normalize_text_encoding( $text )
            : $text;
    }

    private static function looksLikeEmojiText( string $text ): bool {
        $candidate = trim( $text );
        if ( $candidate === '' ) {
            return false;
        }

        if ( function_exists( 'mb_strlen' ) && mb_strlen( $candidate ) > 8 ) {
            return false;
        }

        return preg_match( '/[\x{00A9}\x{00AE}\x{203C}\x{2049}\x{2122}\x{2139}\x{2194}-\x{21AA}\x{231A}-\x{27BF}\x{2934}-\x{2935}\x{2B05}-\x{2B55}\x{3030}\x{303D}\x{3297}\x{3299}\x{1F000}-\x{1FAFF}]/u', $candidate ) === 1;
    }
}
