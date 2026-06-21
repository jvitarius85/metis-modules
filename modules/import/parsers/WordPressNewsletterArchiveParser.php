<?php
declare(strict_types=1);

namespace Metis\Modules\Import\Parsers;

final class WordPressNewsletterArchiveParser {
    public static function parse( string $file_path ): array {
        if ( ! is_file( $file_path ) || ! is_readable( $file_path ) ) {
            return [
                'success' => false,
                'error' => 'File not found.',
            ];
        }

        $raw = (string) file_get_contents( $file_path );
        if ( trim( $raw ) === '' ) {
            return [
                'success' => false,
                'error' => 'Export file is empty.',
            ];
        }

        $trimmed = ltrim( $raw );
        if ( str_starts_with( $trimmed, '<!DOCTYPE html' ) || str_starts_with( $trimmed, '<html' ) ) {
            return [
                'success' => false,
                'error' => 'Downloaded file is HTML, not a newsletter archive JSON export. Re-download the export and make sure WordPress returns the JSON file directly.',
            ];
        }

        $decoded = json_decode( $raw, true );
        if ( ! is_array( $decoded ) ) {
            return [
                'success' => false,
                'error' => 'Export file is not valid JSON.',
            ];
        }

        if ( (string) ( $decoded['format'] ?? '' ) !== 'metis.wordpress.newsletter_archive.v1' ) {
            return [
                'success' => false,
                'error' => 'Unsupported newsletter archive export format.',
            ];
        }

        $source = is_array( $decoded['source'] ?? null ) ? $decoded['source'] : [];
        $default_list = self::normalizeListDefinition( $decoded['default_list'] ?? [] );
        $newsletters = [];

        foreach ( (array) ( $decoded['newsletters'] ?? [] ) as $index => $row ) {
            if ( ! is_array( $row ) ) {
                continue;
            }

            $source_id = (int) ( $row['source_id'] ?? $row['id'] ?? 0 );
            if ( $source_id < 1 ) {
                $source_id = $index + 1;
            }

            $subject = trim( (string) ( $row['subject'] ?? '' ) );
            $title = trim( (string) ( $row['title'] ?? $row['name'] ?? $subject ) );
            $html_body = trim( (string) ( $row['html_body'] ?? $row['body_html'] ?? $row['message'] ?? '' ) );
            if ( $html_body === '' ) {
                continue;
            }

            $sent_at = self::normalizeDateTime(
                (string) ( $row['sent_at'] ?? $row['send_on'] ?? $row['updated_at'] ?? $row['created_at'] ?? '' )
            );
            $updated_at = self::normalizeDateTime(
                (string) ( $row['updated_at'] ?? $row['modified_at'] ?? $row['created_at'] ?? $sent_at )
            );

            $uid = trim( (string) ( $row['uid'] ?? '' ) );
            if ( $uid === '' ) {
                $site_seed = trim( (string) ( $source['site_url'] ?? $source['site_title'] ?? 'wordpress' ) );
                $uid = self::uidFromSeed( $site_seed . '|' . $source_id . '|' . $subject );
            }

            $newsletters[] = [
                'source_id' => $source_id,
                'uid' => $uid,
                'title' => $title !== '' ? $title : 'Imported Newsletter ' . $source_id,
                'subject' => $subject !== '' ? $subject : ( $title !== '' ? $title : 'Imported Newsletter ' . $source_id ),
                'preheader' => trim( (string) ( $row['preheader'] ?? '' ) ),
                'from_name' => trim( (string) ( $row['from_name'] ?? '' ) ),
                'from_email' => trim( (string) ( $row['from_email'] ?? '' ) ),
                'reply_to' => trim( (string) ( $row['reply_to'] ?? '' ) ),
                'html_body' => $html_body,
                'text_body' => trim( (string) ( $row['text_body'] ?? '' ) ),
                'sent_at' => $sent_at,
                'updated_at' => $updated_at !== '' ? $updated_at : $sent_at,
                'list_refs' => self::normalizeStringList( $row['list_refs'] ?? [] ),
                'list_names' => self::normalizeStringList( $row['list_names'] ?? [] ),
            ];
        }

        if ( $newsletters === [] ) {
            return [
                'success' => false,
                'error' => 'No archived newsletters were found in this export.',
            ];
        }

        return [
            'success' => true,
            'import_type' => 'wordpress_newsletter_archive',
            'site_info' => [
                'title' => trim( (string) ( $source['site_title'] ?? $source['site_url'] ?? 'WordPress' ) ),
                'link' => trim( (string) ( $source['site_url'] ?? '' ) ),
                'description' => trim( (string) ( $source['generator'] ?? 'The Newsletter Plugin archive export' ) ),
                'language' => trim( (string) ( $source['language'] ?? '' ) ),
            ],
            'source' => $source,
            'default_list' => $default_list,
            'newsletters' => $newsletters,
            'stats' => [
                'total_items' => count( $newsletters ),
                'newsletters' => count( $newsletters ),
                'pages' => 0,
                'posts' => 0,
                'media' => 0,
                'menus' => 0,
            ],
        ];
    }

    private static function normalizeListDefinition( mixed $raw ): array {
        $row = is_array( $raw ) ? $raw : [];
        $ref = trim( (string) ( $row['ref'] ?? '' ) );
        $name = trim( (string) ( $row['name'] ?? '' ) );
        $description = trim( (string) ( $row['description'] ?? '' ) );

        return [
            'ref' => $ref !== '' ? $ref : 'wp_newsletter_archive',
            'name' => $name !== '' ? $name : 'Imported Newsletter Archive',
            'description' => $description !== '' ? $description : 'Archived newsletters imported from WordPress.',
        ];
    }

    /**
     * @return array<int,string>
     */
    private static function normalizeStringList( mixed $raw ): array {
        $values = [];
        if ( is_array( $raw ) ) {
            $values = $raw;
        } elseif ( is_string( $raw ) && trim( $raw ) !== '' ) {
            $values = preg_split( '/\s*,\s*/', $raw ) ?: [];
        }

        $normalized = [];
        foreach ( $values as $value ) {
            $text = trim( (string) $value );
            if ( $text !== '' ) {
                $normalized[] = $text;
            }
        }

        return array_values( array_unique( $normalized ) );
    }

    private static function normalizeDateTime( string $value ): string {
        $value = trim( $value );
        if ( $value === '' ) {
            return '';
        }

        if ( ctype_digit( $value ) ) {
            $timestamp = (int) $value;
            if ( $timestamp > 0 ) {
                try {
                    return ( new \DateTimeImmutable( '@' . $timestamp ) )
                        ->setTimezone( new \DateTimeZone( 'UTC' ) )
                        ->format( 'Y-m-d H:i:s' );
                } catch ( \Throwable $e ) {
                    return '';
                }
            }
        }

        try {
            return ( new \DateTimeImmutable( $value ) )->format( 'Y-m-d H:i:s' );
        } catch ( \Throwable $e ) {
            return '';
        }
    }

    private static function uidFromSeed( string $seed ): string {
        return strtoupper( substr( sha1( $seed ), 0, 16 ) );
    }
}
