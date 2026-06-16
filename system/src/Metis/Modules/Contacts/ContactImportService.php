<?php
declare(strict_types=1);

namespace Metis\Modules\Contacts;

final class ContactImportService {
    private const STAGE_PREFIX = 'metis_contacts_import_stage_';
    private const STAGE_TTL = 3600;
    private const PREVIEW_LIMIT = 8;

    public static function stageCsvUpload( array $file ): array {
        \metis_contacts_ensure_schema();

        $tmp = (string) ( $file['tmp_name'] ?? '' );
        $name = trim( (string) ( $file['name'] ?? 'contacts-import.csv' ) );
        if ( $tmp === '' || ! is_uploaded_file( $tmp ) ) {
            return [ 'success' => false, 'status' => 400, 'message' => 'Please choose a CSV file to import.' ];
        }

        $handle = fopen( $tmp, 'r' );
        if ( $handle === false ) {
            return [ 'success' => false, 'status' => 400, 'message' => 'Unable to read the uploaded CSV file.' ];
        }

        $delimiter = self::detectDelimiter( $tmp );
        $headers = fgetcsv( $handle, 0, $delimiter );
        if ( ! is_array( $headers ) || $headers === [] ) {
            fclose( $handle );
            return [ 'success' => false, 'status' => 400, 'message' => 'The CSV file is missing a header row.' ];
        }

        $header_labels = self::normalizeHeaders( $headers );
        $rows = [];
        while ( ( $row = fgetcsv( $handle, 0, $delimiter ) ) !== false ) {
            if ( self::rowIsEmpty( $row ) ) {
                continue;
            }

            $assoc = [];
            foreach ( $header_labels as $index => $header_label ) {
                $assoc[ $header_label ] = isset( $row[ $index ] ) ? trim( (string) $row[ $index ] ) : '';
            }
            $rows[] = $assoc;
        }
        fclose( $handle );

        if ( $rows === [] ) {
            return [ 'success' => false, 'status' => 400, 'message' => 'The CSV file does not contain any contact rows.' ];
        }

        $token = bin2hex( random_bytes( 12 ) );
        \metis_set_transient( self::stageKey( $token ), [
            'filename' => $name,
            'headers' => $header_labels,
            'rows' => $rows,
            'created_at' => time(),
        ], self::STAGE_TTL );

        return [
            'success' => true,
            'status' => 200,
            'token' => $token,
            'filename' => $name,
            'headers' => $header_labels,
            'row_count' => count( $rows ),
            'mapping' => self::suggestMapping( $header_labels ),
            'sample_rows' => array_slice( $rows, 0, self::PREVIEW_LIMIT ),
        ];
    }

    public static function analyzeStage( string $token, array $mapping, bool $create_missing_lists = true ): array {
        $stage = self::loadStage( $token );
        if ( $stage === null ) {
            return [ 'success' => false, 'status' => 404, 'message' => 'The import session expired. Please upload the CSV again.' ];
        }

        $mapping = self::sanitizeMapping( $mapping, (array) ( $stage['headers'] ?? [] ) );
        $normalized = self::normalizeRows( (array) ( $stage['rows'] ?? [] ), $mapping );
        $resolution = self::resolveImportRows( $normalized['rows'], $create_missing_lists, false );
        $errors = array_merge( $normalized['errors'], $resolution['errors'] );

        return [
            'success' => true,
            'status' => 200,
            'summary' => [
                'filename' => (string) ( $stage['filename'] ?? 'contacts-import.csv' ),
                'total_rows' => count( (array) ( $stage['rows'] ?? [] ) ),
                'valid_rows' => count( $resolution['valid_rows'] ),
                'skipped_rows' => count( $resolution['skipped_rows'] ),
                'create_count' => (int) $resolution['create_count'],
                'update_count' => (int) $resolution['update_count'],
                'missing_lists' => array_values( $resolution['missing_lists'] ),
                'create_missing_lists' => $create_missing_lists,
            ],
            'preview_rows' => array_slice( $resolution['preview_rows'], 0, self::PREVIEW_LIMIT ),
            'errors' => array_slice( $errors, 0, 20 ),
            'mapping' => $mapping,
        ];
    }

    public static function importStage( string $token, array $mapping, bool $create_missing_lists = true ): array {
        \metis_contacts_ensure_schema();
        \metis_newsletter_ensure_schema();

        $stage = self::loadStage( $token );
        if ( $stage === null ) {
            return [ 'success' => false, 'status' => 404, 'message' => 'The import session expired. Please upload the CSV again.' ];
        }

        $mapping = self::sanitizeMapping( $mapping, (array) ( $stage['headers'] ?? [] ) );
        $normalized = self::normalizeRows( (array) ( $stage['rows'] ?? [] ), $mapping );
        $resolution = self::resolveImportRows( $normalized['rows'], $create_missing_lists, true );

        $created = 0;
        $updated = 0;
        $imported = 0;
        $errors = array_merge( $normalized['errors'], $resolution['errors'] );

        foreach ( $resolution['valid_rows'] as $row ) {
            try {
                $contact_id = self::upsertContactRow( $row );
                if ( $contact_id < 1 ) {
                    $errors[] = 'Unable to save contact for row ' . (int) ( $row['row_number'] ?? 0 ) . '.';
                    continue;
                }

                self::syncNewsletterListMembership( $contact_id, (array) ( $row['newsletter_list_ids'] ?? [] ) );
                $imported++;
                if ( (string) ( $row['action'] ?? 'create' ) === 'update' ) {
                    $updated++;
                } else {
                    $created++;
                }
            } catch ( \Throwable $throwable ) {
                $errors[] = 'Row ' . (int) ( $row['row_number'] ?? 0 ) . ': ' . $throwable->getMessage();
            }
        }

        \metis_delete_transient( self::stageKey( $token ) );

        return [
            'success' => true,
            'status' => 200,
            'imported_count' => $imported,
            'created_count' => $created,
            'updated_count' => $updated,
            'skipped_count' => count( $resolution['skipped_rows'] ) + max( 0, count( $errors ) - count( $resolution['errors'] ) ),
            'errors' => array_slice( $errors, 0, 50 ),
            'missing_lists' => array_values( $resolution['missing_lists'] ),
        ];
    }

    private static function stageKey( string $token ): string {
        return self::STAGE_PREFIX . trim( $token );
    }

    private static function loadStage( string $token ): ?array {
        $token = trim( $token );
        if ( $token === '' ) {
            return null;
        }
        $stage = \metis_get_transient( self::stageKey( $token ) );
        return is_array( $stage ) ? $stage : null;
    }

    private static function detectDelimiter( string $path ): string {
        $line = '';
        $handle = fopen( $path, 'r' );
        if ( $handle ) {
            $line = (string) fgets( $handle );
            fclose( $handle );
        }

        $candidates = [ ',', ';', "\t", '|' ];
        $best = ',';
        $best_count = -1;
        foreach ( $candidates as $candidate ) {
            $count = substr_count( $line, $candidate );
            if ( $count > $best_count ) {
                $best = $candidate;
                $best_count = $count;
            }
        }

        return $best;
    }

    private static function normalizeHeaders( array $headers ): array {
        $normalized = [];
        $seen = [];
        foreach ( $headers as $index => $header ) {
            $label = trim( preg_replace( '/\s+/', ' ', (string) $header ) ?? '' );
            if ( $label === '' ) {
                $label = 'Column ' . ( $index + 1 );
            }
            $base = $label;
            $suffix = 2;
            while ( isset( $seen[ strtolower( $label ) ] ) ) {
                $label = $base . ' (' . $suffix . ')';
                $suffix++;
            }
            $seen[ strtolower( $label ) ] = true;
            $normalized[] = $label;
        }

        return $normalized;
    }

    private static function rowIsEmpty( array $row ): bool {
        foreach ( $row as $value ) {
            if ( trim( (string) $value ) !== '' ) {
                return false;
            }
        }
        return true;
    }

    private static function suggestMapping( array $headers ): array {
        $aliases = [
            'cid' => [ 'cid', 'contact id', 'contactid', 'contact cid', 'contact_cid' ],
            'first_name' => [ 'first name', 'firstname', 'first_name', 'given name', 'given_name' ],
            'last_name' => [ 'last name', 'lastname', 'last_name', 'surname', 'family name', 'family_name' ],
            'full_name' => [ 'name', 'full name', 'full_name', 'contact name', 'contact_name' ],
            'email' => [ 'email', 'email address', 'email_address', 'primary email' ],
            'phone' => [ 'phone', 'phone number', 'phone_number', 'mobile' ],
            'newsletter_lists' => [ 'list', 'lists', 'newsletter list', 'newsletter lists', 'newsletter_list', 'newsletter_lists', 'mailing list', 'mailing lists' ],
        ];

        $suggestions = [];
        foreach ( $aliases as $field => $field_aliases ) {
            $suggestions[ $field ] = '';
            foreach ( $headers as $header ) {
                $normalized_header = strtolower( trim( preg_replace( '/[^a-z0-9]+/', ' ', $header ) ?? '' ) );
                if ( in_array( $normalized_header, $field_aliases, true ) ) {
                    $suggestions[ $field ] = $header;
                    break;
                }
            }
        }

        return $suggestions;
    }

    private static function sanitizeMapping( array $mapping, array $headers ): array {
        $allowed_headers = array_fill_keys( $headers, true );
        $fields = [ 'cid', 'first_name', 'last_name', 'full_name', 'email', 'phone', 'newsletter_lists' ];
        $clean = [];
        foreach ( $fields as $field ) {
            $header = trim( (string) ( $mapping[ $field ] ?? '' ) );
            $clean[ $field ] = isset( $allowed_headers[ $header ] ) ? $header : '';
        }
        return $clean;
    }

    private static function normalizeRows( array $rows, array $mapping ): array {
        $normalized_rows = [];
        $errors = [];

        foreach ( $rows as $index => $row ) {
            if ( ! is_array( $row ) ) {
                continue;
            }

            $row_number = $index + 2;
            $cid = trim( (string) self::mappedValue( $row, $mapping['cid'] ?? '' ) );
            $first_name = \metis_text_clean( (string) self::mappedValue( $row, $mapping['first_name'] ?? '' ) );
            $last_name = \metis_text_clean( (string) self::mappedValue( $row, $mapping['last_name'] ?? '' ) );
            $full_name = \metis_text_clean( (string) self::mappedValue( $row, $mapping['full_name'] ?? '' ) );
            $email = strtolower( trim( \metis_email_clean( (string) self::mappedValue( $row, $mapping['email'] ?? '' ) ) ) );
            $phone = trim( (string) self::mappedValue( $row, $mapping['phone'] ?? '' ) );
            $newsletter_raw = (string) self::mappedValue( $row, $mapping['newsletter_lists'] ?? '' );

            if ( $full_name !== '' && $first_name === '' && $last_name === '' ) {
                [ $first_name, $last_name ] = self::splitName( $full_name );
            }

            if ( $email === '' || ! \metis_email_is_valid( $email ) ) {
                $errors[] = 'Row ' . $row_number . ' is missing a valid email address.';
                continue;
            }

            $normalized_rows[] = [
                'row_number' => $row_number,
                'cid' => $cid,
                'first_name' => $first_name,
                'last_name' => $last_name,
                'email' => $email,
                'phone' => $phone,
                'newsletter_list_names' => self::parseListNames( $newsletter_raw ),
            ];
        }

        return [
            'rows' => $normalized_rows,
            'errors' => $errors,
        ];
    }

    private static function mappedValue( array $row, string $header ): string {
        return $header !== '' ? (string) ( $row[ $header ] ?? '' ) : '';
    }

    private static function splitName( string $full_name ): array {
        $parts = preg_split( '/\s+/', trim( $full_name ) ) ?: [];
        if ( $parts === [] ) {
            return [ '', '' ];
        }
        $first = array_shift( $parts );
        $last = implode( ' ', $parts );
        return [ \metis_text_clean( (string) $first ), \metis_text_clean( (string) $last ) ];
    }

    private static function parseListNames( string $raw ): array {
        $raw = trim( $raw );
        if ( $raw === '' ) {
            return [];
        }
        $parts = preg_split( '/\s*(?:\||;|,)\s*/', $raw ) ?: [];
        $names = [];
        $seen = [];
        foreach ( $parts as $part ) {
            $name = trim( \metis_text_clean( (string) $part ) );
            if ( $name === '' ) {
                continue;
            }
            $key = strtolower( $name );
            if ( isset( $seen[ $key ] ) ) {
                continue;
            }
            $seen[ $key ] = true;
            $names[] = $name;
        }
        return $names;
    }

    private static function resolveImportRows( array $rows, bool $create_missing_lists, bool $create_lists ): array {
        $contacts_table = \Metis_Tables::get( 'contacts' );
        $db = \metis_db();

        $cid_values = [];
        $email_values = [];
        $all_list_names = [];
        foreach ( $rows as $row ) {
            $cid = trim( (string) ( $row['cid'] ?? '' ) );
            $email = strtolower( trim( (string) ( $row['email'] ?? '' ) ) );
            if ( $cid !== '' ) {
                $cid_values[] = $cid;
            }
            if ( $email !== '' ) {
                $email_values[] = $email;
            }
            foreach ( (array) ( $row['newsletter_list_names'] ?? [] ) as $list_name ) {
                $all_list_names[] = (string) $list_name;
            }
        }

        $existing_by_cid = [];
        foreach ( array_chunk( array_values( array_unique( $cid_values ) ), 250 ) as $chunk ) {
            if ( $chunk === [] ) {
                continue;
            }
            $placeholders = implode( ',', array_fill( 0, count( $chunk ), '%s' ) );
            $results = $db->fetchAll(
                "SELECT id, cid, email FROM {$contacts_table} WHERE cid IN ({$placeholders})",
                $chunk
            ) ?: [];
            foreach ( $results as $result ) {
                $existing_by_cid[ (string) ( $result['cid'] ?? '' ) ] = $result;
            }
        }

        $existing_by_email = [];
        foreach ( array_chunk( array_values( array_unique( $email_values ) ), 250 ) as $chunk ) {
            if ( $chunk === [] ) {
                continue;
            }
            $placeholders = implode( ',', array_fill( 0, count( $chunk ), '%s' ) );
            $results = $db->fetchAll(
                "SELECT id, cid, email FROM {$contacts_table} WHERE email IN ({$placeholders})",
                $chunk
            ) ?: [];
            foreach ( $results as $result ) {
                $existing_by_email[ strtolower( (string) ( $result['email'] ?? '' ) ) ] = $result;
            }
        }

        $list_resolution = self::resolveNewsletterLists( array_values( array_unique( $all_list_names ) ), $create_missing_lists, $create_lists );

        $valid_rows = [];
        $skipped_rows = [];
        $preview_rows = [];
        $create_count = 0;
        $update_count = 0;

        foreach ( $rows as $row ) {
            $cid = trim( (string) ( $row['cid'] ?? '' ) );
            $email = strtolower( trim( (string) ( $row['email'] ?? '' ) ) );
            $existing = null;
            if ( $cid !== '' && isset( $existing_by_cid[ $cid ] ) ) {
                $existing = $existing_by_cid[ $cid ];
            } elseif ( $email !== '' && isset( $existing_by_email[ $email ] ) ) {
                $existing = $existing_by_email[ $email ];
            }

            $list_ids = [];
            foreach ( (array) ( $row['newsletter_list_names'] ?? [] ) as $list_name ) {
                $list_id = (int) ( $list_resolution['resolved'][ strtolower( $list_name ) ] ?? 0 );
                if ( $list_id > 0 ) {
                    $list_ids[] = $list_id;
                }
            }
            $list_ids = array_values( array_unique( array_map( 'intval', $list_ids ) ) );

            $has_requested_lists = ! empty( $row['newsletter_list_names'] );
            if ( $has_requested_lists && $list_ids === [] ) {
                $skipped_rows[] = $row;
                $preview_rows[] = [
                    'row_number' => (int) ( $row['row_number'] ?? 0 ),
                    'action' => 'skip',
                    'name' => trim( (string) ( $row['first_name'] ?? '' ) . ' ' . (string) ( $row['last_name'] ?? '' ) ),
                    'email' => $email,
                    'lists' => array_values( (array) ( $row['newsletter_list_names'] ?? [] ) ),
                ];
                $errors[] = 'Row ' . (int) ( $row['row_number'] ?? 0 ) . ' references newsletter lists that could not be resolved.';
                continue;
            }

            $action = $existing ? 'update' : 'create';
            if ( $action === 'update' ) {
                $update_count++;
            } else {
                $create_count++;
            }

            $row['action'] = $action;
            $row['existing_contact_id'] = (int) ( $existing['id'] ?? 0 );
            $row['existing_cid'] = (string) ( $existing['cid'] ?? '' );
            $row['newsletter_list_ids'] = $list_ids;
            $valid_rows[] = $row;

            $preview_rows[] = [
                'row_number' => (int) ( $row['row_number'] ?? 0 ),
                'action' => $action,
                'name' => trim( (string) ( $row['first_name'] ?? '' ) . ' ' . (string) ( $row['last_name'] ?? '' ) ),
                'email' => $email,
                'lists' => array_values( (array) ( $row['newsletter_list_names'] ?? [] ) ),
            ];
        }

        return [
            'valid_rows' => $valid_rows,
            'skipped_rows' => $skipped_rows,
            'preview_rows' => $preview_rows,
            'errors' => [],
            'create_count' => $create_count,
            'update_count' => $update_count,
            'missing_lists' => $list_resolution['missing'],
        ];
    }

    private static function resolveNewsletterLists( array $list_names, bool $create_missing_lists, bool $create_lists ): array {
        $table = \Metis_Tables::get( 'newsletter_lists' );
        $db = \metis_db();
        $resolved = [];
        $missing = [];

        $existing = $db->fetchAll(
            "SELECT id, name FROM {$table} WHERE name IS NOT NULL AND TRIM(name) <> ''"
        ) ?: [];
        $existing_by_name = [];
        foreach ( $existing as $row ) {
            $existing_by_name[ strtolower( trim( (string) ( $row['name'] ?? '' ) ) ) ] = (int) ( $row['id'] ?? 0 );
        }

        foreach ( $list_names as $list_name ) {
            $name = trim( \metis_text_clean( (string) $list_name ) );
            if ( $name === '' ) {
                continue;
            }
            $key = strtolower( $name );
            if ( isset( $existing_by_name[ $key ] ) ) {
                $resolved[ $key ] = $existing_by_name[ $key ];
                continue;
            }
            if ( ! $create_missing_lists ) {
                $missing[ $key ] = $name;
                continue;
            }
            if ( $create_lists ) {
                $list_id = self::createNewsletterList( $name );
                if ( $list_id > 0 ) {
                    $resolved[ $key ] = $list_id;
                    $existing_by_name[ $key ] = $list_id;
                    continue;
                }
            }
            $missing[ $key ] = $name;
        }

        return [
            'resolved' => $resolved,
            'missing' => $missing,
        ];
    }

    private static function createNewsletterList( string $name ): int {
        $table = \Metis_Tables::get( 'newsletter_lists' );
        $now = \metis_current_time( 'mysql' );
        $payload = [
            'name' => $name,
            'description' => 'Imported from contacts CSV.',
            'is_active' => 1,
            'created_at' => $now,
            'updated_at' => $now,
            'list_key' => strtoupper( substr( preg_replace( '/[^A-Z0-9]+/', '_', strtoupper( $name ) ) ?? 'IMPORTED_CONTACTS_LIST', 0, 32 ) ),
            'newsletter_list_uid' => strtoupper( substr( sha1( 'newsletter_list|' . strtolower( $name ) ), 0, 16 ) ),
        ];

        if ( function_exists( 'metis_entity_id_service' ) ) {
            $payload = \metis_entity_id_service()->assignForInsert( 'newsletter_list', $payload );
        }

        $ok = \metis_db()->insert( $table, $payload, [ '%s', '%s', '%d', '%s', '%s', '%s' ] );
        if ( $ok === false ) {
            return 0;
        }
        $list_id = (int) \metis_db()->lastInsertId();
        if ( $list_id > 0 && function_exists( 'metis_entity_id_service' ) ) {
            \metis_entity_id_service()->register( 'newsletter_list', $list_id, (string) ( $payload['newsletter_list_uid'] ?? $payload['list_key'] ?? '' ) );
        }
        return $list_id;
    }

    private static function upsertContactRow( array $row ): int {
        $db = \metis_db();
        $contacts_table = \Metis_Tables::get( 'contacts' );
        $details_table = \Metis_Tables::get( 'contact_details' );

        $contact_id = (int) ( $row['existing_contact_id'] ?? 0 );
        $cid = trim( (string) ( $row['cid'] ?? '' ) );
        $email = strtolower( trim( (string) ( $row['email'] ?? '' ) ) );
        $first_name = \metis_text_clean( (string) ( $row['first_name'] ?? '' ) );
        $last_name = \metis_text_clean( (string) ( $row['last_name'] ?? '' ) );
        $phone = trim( (string) ( $row['phone'] ?? '' ) );
        $now = \metis_current_time( 'mysql' );

        if ( $contact_id > 0 ) {
            $payload = [
                'first_name' => $first_name,
                'last_name' => $last_name,
                'email' => $email,
                'updated_at' => $now,
            ];
            if ( $cid !== '' && \metis_contacts_column_exists( $contacts_table, 'cid' ) ) {
                $payload['cid'] = $cid;
            }
            $ok = $db->update( $contacts_table, $payload, [ 'id' => $contact_id ], array_fill( 0, count( $payload ), '%s' ), [ '%d' ] );
            if ( $ok === false ) {
                throw new \RuntimeException( 'Failed to update contact.' );
            }
        } else {
            $payload = [
                'first_name' => $first_name,
                'last_name' => $last_name,
                'email' => $email,
            ];
            if ( $cid !== '' ) {
                $payload['cid'] = $cid;
            }
            if ( function_exists( 'metis_entity_id_service' ) ) {
                $payload = \metis_entity_id_service()->assignForInsert( 'contact', $payload );
            } elseif ( ! isset( $payload['cid'] ) ) {
                $payload['cid'] = \metis_generate_code( 'CN', $contacts_table, 'cid' );
            }

            $format = [];
            foreach ( array_keys( $payload ) as $key ) {
                $format[] = '%s';
            }
            $ok = $db->insert( $contacts_table, $payload, $format );
            if ( $ok === false ) {
                throw new \RuntimeException( 'Failed to create contact.' );
            }
            $contact_id = (int) $db->lastInsertId();
            $final_cid = (string) ( $payload['contact_uid'] ?? $payload['cid'] ?? '' );
            if ( $contact_id > 0 && function_exists( 'metis_entity_id_service' ) ) {
                \metis_entity_id_service()->register( 'contact', $contact_id, $final_cid );
            }
            $cid = $final_cid !== '' ? $final_cid : $cid;
        }

        if ( \metis_contacts_table_exists( $details_table ) ) {
            $detail_row = $db->fetchOne(
                "SELECT id FROM {$details_table} WHERE contact_id = %d LIMIT 1",
                [ $contact_id ]
            );
            $detail_insert_payload = [];
            $detail_insert_format = [];
            $detail_update_payload = [];
            $detail_update_format = [];

            if ( \metis_contacts_column_exists( $details_table, 'contact_id' ) ) {
                $detail_insert_payload['contact_id'] = $contact_id;
                $detail_insert_format[] = '%d';
            }
            if ( \metis_contacts_column_exists( $details_table, 'contact_cid' ) ) {
                $detail_insert_payload['contact_cid'] = $cid;
                $detail_insert_format[] = '%s';
            }
            if ( \metis_contacts_column_exists( $details_table, 'cid' ) ) {
                $detail_insert_payload['cid'] = $cid;
                $detail_insert_format[] = '%s';
            }
            if ( \metis_contacts_column_exists( $details_table, 'phone' ) ) {
                $detail_insert_payload['phone'] = $phone !== '' ? $phone : null;
                $detail_insert_format[] = '%s';
                $detail_update_payload['phone'] = $phone !== '' ? $phone : null;
                $detail_update_format[] = '%s';
            }
            if ( \metis_contacts_column_exists( $details_table, 'updated_at' ) ) {
                $detail_insert_payload['updated_at'] = $now;
                $detail_insert_format[] = '%s';
                $detail_update_payload['updated_at'] = $now;
                $detail_update_format[] = '%s';
            }
            if ( \metis_contacts_column_exists( $details_table, 'contact_cid' ) ) {
                $detail_update_payload['contact_cid'] = $cid;
                $detail_update_format[] = '%s';
            }
            if ( \metis_contacts_column_exists( $details_table, 'cid' ) ) {
                $detail_update_payload['cid'] = $cid;
                $detail_update_format[] = '%s';
            }

            if ( is_array( $detail_row ) && (int) ( $detail_row['id'] ?? 0 ) > 0 ) {
                $detail_id = (int) $detail_row['id'];
                if ( $detail_update_payload !== [] ) {
                    $ok = $db->update( $details_table, $detail_update_payload, [ 'id' => $detail_id ], $detail_update_format, [ '%d' ] );
                    if ( $ok === false ) {
                        throw new \RuntimeException( 'Failed to update contact details.' );
                    }
                }
            } else {
                if ( $detail_insert_payload !== [] ) {
                    $ok = $db->insert( $details_table, $detail_insert_payload, $detail_insert_format );
                    if ( $ok === false ) {
                        throw new \RuntimeException( 'Failed to create contact details.' );
                    }
                } else {
                    throw new \RuntimeException( 'Failed to create contact details.' );
                }
            }
        }

        return $contact_id;
    }

    private static function syncNewsletterListMembership( int $contact_id, array $list_ids ): void {
        $db = \metis_db();
        $newsletter_subs_table = \Metis_Tables::get( 'newsletter_subs' );
        $valid_ids = array_values( array_unique( array_filter( array_map( 'intval', $list_ids ), static fn ( int $id ): bool => $id > 0 ) ) );
        $now = \metis_current_time( 'mysql' );

        $delete_ok = $db->delete( $newsletter_subs_table, [ 'contact_id' => $contact_id ], [ '%d' ] );
        if ( $delete_ok === false ) {
            throw new \RuntimeException( 'Failed to update newsletter subscriptions.' );
        }

        foreach ( $valid_ids as $list_id ) {
            $ok = $db->insert(
                $newsletter_subs_table,
                [
                    'contact_id' => $contact_id,
                    'list_id' => $list_id,
                    'status' => 'subscribed',
                    'source' => 'contacts_csv_import',
                    'subscribed_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                [ '%d', '%d', '%s', '%s', '%s', '%s', '%s' ]
            );
            if ( $ok === false ) {
                throw new \RuntimeException( 'Failed to save newsletter subscription.' );
            }
        }
    }
}
