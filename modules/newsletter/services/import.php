<?php
declare(strict_types=1);

if ( ! defined( 'METIS_ROOT' ) ) {
    exit;
}

use Metis\Modules\Newsletter\CampaignService;
use Metis\Modules\Newsletter\NewsletterModule;

function metis_newsletter_import_wordpress_archive( array $parsed, array $options = [] ): array {
    NewsletterModule::ensureSchema();

    $selected_ids = [];
    foreach ( (array) ( $options['selected_newsletter_ids'] ?? [] ) as $value ) {
        $id = (int) $value;
        if ( $id > 0 ) {
            $selected_ids[] = $id;
        }
    }
    $selected_ids = array_values( array_unique( $selected_ids ) );

    $results = [
        'newsletters' => 0,
        'lists' => 0,
        'skipped' => 0,
        'errors' => [],
    ];

    $list_cache = [];
    $fallback_list = is_array( $parsed['default_list'] ?? null ) ? $parsed['default_list'] : [];

    foreach ( (array) ( $parsed['newsletters'] ?? [] ) as $item ) {
        if ( ! is_array( $item ) ) {
            continue;
        }

        $source_id = (int) ( $item['source_id'] ?? 0 );
        if ( $selected_ids !== [] && ! in_array( $source_id, $selected_ids, true ) ) {
            continue;
        }

        $uid = trim( (string) ( $item['uid'] ?? '' ) );
        if ( $uid !== '' && metis_newsletter_import_campaign_exists( $uid ) > 0 ) {
            $results['skipped']++;
            continue;
        }

        $list_ids = metis_newsletter_import_list_ids_for_item( $item, $fallback_list, $list_cache, $results );
        if ( $list_ids === [] ) {
            $results['errors'][] = 'Unable to resolve a newsletter list for source item ' . max( 1, $source_id ) . '.';
            continue;
        }

        $html_body = trim( (string) ( $item['html_body'] ?? '' ) );
        if ( $html_body === '' ) {
            $results['errors'][] = 'Archived newsletter ' . max( 1, $source_id ) . ' has no HTML body.';
            continue;
        }

        $sent_at = trim( (string) ( $item['sent_at'] ?? '' ) );
        $updated_at = trim( (string) ( $item['updated_at'] ?? '' ) );
        $text_body = trim( (string) ( $item['text_body'] ?? '' ) );
        if ( $text_body === '' ) {
            $text_body = metis_newsletter_import_plain_text( $html_body );
        }

        $payload = [
            'template_id' => null,
            'name' => trim( (string) ( $item['title'] ?? $item['subject'] ?? 'Imported Newsletter' ) ),
            'subject' => trim( (string) ( $item['subject'] ?? $item['title'] ?? 'Imported Newsletter' ) ),
            'from_name' => trim( (string) ( $item['from_name'] ?? '' ) ),
            'from_email' => trim( (string) ( $item['from_email'] ?? '' ) ),
            'reply_to' => trim( (string) ( $item['reply_to'] ?? '' ) ),
            'preheader' => trim( (string) ( $item['preheader'] ?? '' ) ),
            'doc_json' => null,
            'editor_body_html' => $html_body,
            'status' => 'sent',
            'scheduled_at' => $sent_at !== '' ? $sent_at : null,
            'audience_json' => metis_newsletter_import_json_encode( [
                'source' => 'wordpress_newsletter_archive_import',
                'source_id' => $source_id,
            ] ),
            'attachments_json' => null,
            'updated_at' => $updated_at !== '' ? $updated_at : metis_current_time( 'mysql' ),
            'html_body' => $html_body,
            'text_body' => $text_body !== '' ? $text_body : null,
            'sent_at' => $sent_at !== '' ? $sent_at : null,
            'newsletter_campaign_uid' => $uid,
        ];

        $payload_formats = [
            '%d',
            '%s',
            '%s',
            '%s',
            '%s',
            '%s',
            '%s',
            '%s',
            '%s',
            '%s',
            '%s',
            '%s',
            '%s',
            '%s',
            '%s',
            '%s',
            '%s',
            '%s',
        ];

        $save_result = CampaignService::save( 0, $payload, $payload_formats, $list_ids );
        if ( empty( $save_result['success'] ) ) {
            $results['errors'][] = 'Failed to import archived newsletter "' . ( $payload['name'] !== '' ? $payload['name'] : ( 'Item ' . max( 1, $source_id ) ) ) . '".';
            continue;
        }

        $results['newsletters']++;
    }

    $results['lists'] = count( $list_cache );

    return [
        'ok' => true,
        'results' => $results,
    ];
}

/**
 * @param array<string,mixed> $results
 * @return array<int,int>
 */
function metis_newsletter_import_list_ids_for_item( array $item, array $fallback_list, array &$list_cache, array &$results ): array {
    $refs = [];
    foreach ( (array) ( $item['list_refs'] ?? [] ) as $value ) {
        $ref = trim( (string) $value );
        if ( $ref !== '' ) {
            $refs[] = [
                'ref' => $ref,
                'name' => $ref,
                'description' => '',
            ];
        }
    }

    if ( $refs === [] ) {
        foreach ( (array) ( $item['list_names'] ?? [] ) as $value ) {
            $name = trim( (string) $value );
            if ( $name !== '' ) {
                $refs[] = [
                    'ref' => metis_newsletter_import_slug( $name ),
                    'name' => $name,
                    'description' => '',
                ];
            }
        }
    }

    if ( $refs === [] ) {
        $refs[] = $fallback_list;
    }

    $list_ids = [];
    foreach ( $refs as $definition ) {
        $list_id = metis_newsletter_import_resolve_or_create_list( is_array( $definition ) ? $definition : [], $list_cache, $results );
        if ( $list_id > 0 ) {
            $list_ids[] = $list_id;
        }
    }

    return array_values( array_unique( array_map( 'intval', $list_ids ) ) );
}

function metis_newsletter_import_resolve_or_create_list( array $definition, array &$list_cache, array &$results ): int {
    $ref = trim( (string) ( $definition['ref'] ?? '' ) );
    $name = trim( (string) ( $definition['name'] ?? '' ) );
    $description = trim( (string) ( $definition['description'] ?? '' ) );

    if ( $name === '' ) {
        $name = 'Imported Newsletter Archive';
    }
    if ( $ref === '' ) {
        $ref = metis_newsletter_import_slug( $name );
    }

    $cache_key = strtoupper( $ref );
    if ( isset( $list_cache[ $cache_key ] ) ) {
        return (int) $list_cache[ $cache_key ];
    }

    $list_id = metis_newsletter_import_find_list_id( $ref, $name );
    if ( $list_id > 0 ) {
        $list_cache[ $cache_key ] = $list_id;
        return $list_id;
    }

    $table = Metis_Tables::get( 'newsletter_lists' );
    $payload = [
        'name' => $name,
        'description' => $description !== '' ? $description : 'Imported archived newsletters.',
        'is_active' => 1,
        'updated_at' => metis_current_time( 'mysql' ),
        'newsletter_list_uid' => strtoupper( substr( sha1( 'newsletter_list|' . $ref ), 0, 16 ) ),
        'list_key' => strtoupper( substr( preg_replace( '/[^A-Z0-9]+/', '_', strtoupper( $ref ) ) ?? 'IMPORTED_NEWSLETTER_ARCHIVE', 0, 32 ) ),
    ];

    if ( function_exists( 'metis_entity_id_service' ) ) {
        $payload = metis_entity_id_service()->assignForInsert( 'newsletter_list', $payload );
    }

    $ok = metis_db()->insert( $table, $payload, [ '%s', '%s', '%d', '%s', '%s', '%s' ] );
    if ( $ok === false ) {
        $results['errors'][] = 'Failed to create newsletter list "' . $name . '".';
        return 0;
    }

    $list_id = (int) metis_db()->lastInsertId();
    if ( $list_id > 0 && function_exists( 'metis_entity_id_service' ) ) {
        metis_entity_id_service()->register( 'newsletter_list', $list_id, (string) ( $payload['newsletter_list_uid'] ?? $payload['list_key'] ?? '' ) );
    }

    $list_cache[ $cache_key ] = $list_id;
    return $list_id;
}

function metis_newsletter_import_find_list_id( string $ref, string $name ): int {
    $ref = trim( $ref );
    $name = trim( $name );
    $table = Metis_Tables::get( 'newsletter_lists' );

    if ( $ref !== '' ) {
        $row = metis_db()->fetchOne(
            "SELECT id FROM {$table} WHERE newsletter_list_uid = %s OR list_key = %s LIMIT 1",
            [ $ref, strtoupper( substr( preg_replace( '/[^A-Z0-9]+/', '_', strtoupper( $ref ) ) ?? $ref, 0, 32 ) ) ]
        );
        if ( is_array( $row ) && (int) ( $row['id'] ?? 0 ) > 0 ) {
            return (int) $row['id'];
        }
    }

    if ( $name !== '' ) {
        $row = metis_db()->fetchOne(
            "SELECT id FROM {$table} WHERE name = %s LIMIT 1",
            [ $name ]
        );
        if ( is_array( $row ) && (int) ( $row['id'] ?? 0 ) > 0 ) {
            return (int) $row['id'];
        }
    }

    return 0;
}

function metis_newsletter_import_campaign_exists( string $uid ): int {
    $uid = strtoupper( trim( $uid ) );
    if ( $uid === '' ) {
        return 0;
    }

    if ( class_exists( '\Metis\Core\CodeRegistry' ) ) {
        $resolved = \Metis\Core\CodeRegistry::resolve( $uid );
        if ( is_array( $resolved ) && (string) ( $resolved['entity_type'] ?? '' ) === 'newsletter_campaign' ) {
            return (int) ( $resolved['id'] ?? 0 );
        }
    }

    $table = Metis_Tables::get( 'newsletter_campaigns' );
    $row = metis_db()->fetchOne(
        "SELECT id FROM {$table} WHERE newsletter_campaign_uid = %s LIMIT 1",
        [ $uid ]
    );

    return is_array( $row ) ? (int) ( $row['id'] ?? 0 ) : 0;
}

function metis_newsletter_import_slug( string $value ): string {
    $value = strtolower( trim( $value ) );
    $value = preg_replace( '/[^a-z0-9]+/', '_', $value ) ?? '';
    return trim( $value, '_' );
}

function metis_newsletter_import_plain_text( string $html ): string {
    $text = html_entity_decode( strip_tags( $html ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
    $text = preg_replace( '/\s+/u', ' ', $text ) ?? $text;
    return trim( $text );
}

function metis_newsletter_import_json_encode( mixed $value ): string {
    if ( function_exists( 'metis_json_encode' ) ) {
        return (string) metis_json_encode( $value );
    }

    $encoded = json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
    return is_string( $encoded ) ? $encoded : '{}';
}
