<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Contacts AJAX Controller
 */

function metis_contacts_ajax_verify_nonce(): void {
    check_ajax_referer( 'metis_contacts', 'nonce' );
}

function metis_contacts_ajax_format_row( object $row, ?object $details = null ): array {
    $updated_ts = strtotime( (string) ( $row->updated_at ?? '' ) );

    return [
        'cid'        => (string) ( $row->cid ?? '' ),
        'first_name' => (string) ( $row->first_name ?? '' ),
        'last_name'  => (string) ( $row->last_name ?? '' ),
        'email'      => (string) ( $row->email ?? '' ),
        'phone'      => (string) ( $details->phone ?? '' ),
        'did'        => (string) ( $row->did ?? '' ),
        'updated_ts' => $updated_ts ?: 0,
        'detail_url' => metis_contacts_detail_url( (string) ( $row->cid ?? '' ) ),
    ];
}

function metis_contacts_normalize_relationships( array $raw, string $self_cid ): array {
    $normalized = [];
    $seen = [];

    foreach ( $raw as $entry ) {
        if ( ! is_array( $entry ) ) {
            continue;
        }

        $related_cid = sanitize_text_field( (string) ( $entry['related_contact_cid'] ?? $entry['related_contact_id'] ?? '' ) );
        $relation_type = sanitize_text_field( (string) ( $entry['relation_type'] ?? '' ) );
        $notes = sanitize_text_field( (string) ( $entry['notes'] ?? '' ) );

        if ( $related_cid === '' || $relation_type === '' || $related_cid === $self_cid ) {
            continue;
        }

        $key = strtolower( $related_cid . '|' . $relation_type . '|' . $notes );
        if ( isset( $seen[ $key ] ) ) {
            continue;
        }
        $seen[ $key ] = true;

        $normalized[] = [
            'related_contact_cid' => $related_cid,
            'relation_type'       => $relation_type,
            'notes'               => $notes,
        ];
    }

    return $normalized;
}

function metis_contacts_normalize_additional_emails( array $raw ): array {
    $normalized = [];
    $seen = [];
    $normalize_value = static function ( string $value ): string {
        if ( class_exists( 'Normalizer' ) ) {
            $normalized_value = Normalizer::normalize( $value, Normalizer::FORM_KC );
            if ( is_string( $normalized_value ) ) {
                $value = $normalized_value;
            }
        }
        $value = preg_replace( '/[\x{200B}-\x{200D}\x{FEFF}\p{Cf}]/u', '', (string) $value );
        $value = preg_replace( '/\s+/u', '', (string) $value );
        return strtolower( trim( sanitize_email( $value ) ) );
    };

    foreach ( $raw as $entry ) {
        $candidate = '';

        if ( is_string( $entry ) || is_numeric( $entry ) ) {
            $candidate = (string) $entry;
        } elseif ( is_array( $entry ) ) {
            $candidate = (string) ( $entry['email'] ?? '' );
        }

        $candidate = $normalize_value( (string) $candidate );
        if ( $candidate === '' || ! is_email( $candidate ) ) {
            continue;
        }

        if ( isset( $seen[ $candidate ] ) ) {
            continue;
        }
        $seen[ $candidate ] = true;
        $normalized[] = $candidate;
    }

    return $normalized;
}

function metis_contacts_reconcile_primary_and_additional_emails( string $primary_email, array $additional_raw ): array {
    if ( class_exists( 'Normalizer' ) ) {
        $normalized_primary = Normalizer::normalize( $primary_email, Normalizer::FORM_KC );
        if ( is_string( $normalized_primary ) ) {
            $primary_email = $normalized_primary;
        }
    }
    $primary_email = preg_replace( '/[\x{200B}-\x{200D}\x{FEFF}\p{Cf}]/u', '', $primary_email );
    $primary_email = preg_replace( '/\s+/u', '', $primary_email );
    $primary_email = strtolower( trim( sanitize_email( $primary_email ) ) );

    $additional = metis_contacts_normalize_additional_emails( $additional_raw );
    if ( $primary_email !== '' ) {
        $additional = array_values( array_filter( $additional, static function ( string $candidate ) use ( $primary_email ): bool {
            return $candidate !== $primary_email;
        } ) );
    }

    return [
        'primary_email' => $primary_email,
        'additional'    => $additional,
    ];
}

function metis_contacts_format_phone_us( string $value ): string {
    $digits = preg_replace( '/\D+/', '', $value );
    if ( ! is_string( $digits ) ) {
        return '';
    }
    if ( strlen( $digits ) === 11 && strpos( $digits, '1' ) === 0 ) {
        $digits = substr( $digits, 1 );
    }
    if ( strlen( $digits ) !== 10 ) {
        return trim( $value );
    }
    return substr( $digits, 0, 3 ) . '-' . substr( $digits, 3, 3 ) . '-' . substr( $digits, 6, 4 );
}

function metis_contacts_parse_address_full( string $value ): array {
    $raw = trim( (string) $value );
    if ( $raw === '' ) {
        return [
            'address' => '',
            'city'    => '',
            'state'   => '',
            'zip'     => '',
            'line_1'  => '',
            'line_2'  => '',
            'value'   => '',
        ];
    }

    $raw = str_replace( [ "\r\n", "\n", "\r" ], ', ', $raw );
    $raw = preg_replace( '/\s+/', ' ', (string) $raw );
    $parts = array_values( array_filter( array_map( 'trim', explode( ',', (string) $raw ) ), static function ( string $part ): bool {
        return $part !== '';
    } ) );

    $address = '';
    $city = '';
    $state = '';
    $zip = '';

    if ( count( $parts ) >= 3 ) {
        $address = (string) array_shift( $parts );
        $city = (string) array_shift( $parts );
        $state_zip_raw = trim( implode( ' ', $parts ) );
    } elseif ( count( $parts ) === 2 ) {
        $address = (string) $parts[0];
        $state_zip_raw = (string) $parts[1];
    } else {
        $address = (string) $parts[0];
        $state_zip_raw = '';
    }

    if ( $state_zip_raw !== '' ) {
        if ( preg_match( '/^(.+?)\s+([A-Za-z]{2})(?:\s+(\d{5}(?:-\d{4})?))?$/', $state_zip_raw, $matches ) ) {
            if ( $city === '' ) {
                $city = trim( (string) $matches[1] );
            }
            $state = strtoupper( trim( (string) $matches[2] ) );
            $zip = trim( (string) ( $matches[3] ?? '' ) );
        } elseif ( preg_match( '/^([A-Za-z]{2})(?:\s+(\d{5}(?:-\d{4})?))?$/', $state_zip_raw, $matches ) ) {
            $state = strtoupper( trim( (string) $matches[1] ) );
            $zip = trim( (string) ( $matches[2] ?? '' ) );
        } elseif ( $city === '' ) {
            $city = $state_zip_raw;
        }
    }

    $address = sanitize_text_field( $address );
    $city = sanitize_text_field( $city );
    $state = strtoupper( sanitize_text_field( $state ) );
    $state = preg_replace( '/[^A-Z]/', '', (string) $state );
    if ( strlen( $state ) > 2 ) {
        $state = substr( $state, 0, 2 );
    }
    $zip = sanitize_text_field( $zip );

    $line_1 = trim( $address );
    $line_2 = trim( implode( ', ', array_filter( [
        $city,
        trim( $state . ( $zip !== '' ? ' ' . $zip : '' ) ),
    ] ) ) );
    $full_value = trim( implode( ', ', array_filter( [ $line_1, $line_2 ] ) ) );

    return [
        'address' => $address,
        'city'    => $city,
        'state'   => $state,
        'zip'     => $zip,
        'line_1'  => $line_1,
        'line_2'  => $line_2,
        'value'   => $full_value,
    ];
}

function metis_contacts_collect_linked_detail_rows( string $details_table, string $cid, int $id, string $did ): array {
    global $wpdb;

    $rows = [];
    $seen = [];
    $add_rows = static function ( array $incoming ) use ( &$rows, &$seen ): void {
        foreach ( $incoming as $row ) {
            if ( ! is_object( $row ) ) {
                continue;
            }
            $row_id = isset( $row->id ) ? (int) $row->id : 0;
            $key = $row_id > 0 ? 'id:' . $row_id : 'hash:' . md5( metis_json_encode( $row ) );
            if ( isset( $seen[ $key ] ) ) {
                continue;
            }
            $seen[ $key ] = true;
            $rows[] = $row;
        }
    };

    if ( metis_contacts_column_exists( $details_table, 'contact_cid' ) ) {
        $found = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$details_table} WHERE contact_cid = %s",
            $cid
        ) );
        $add_rows( is_array( $found ) ? $found : [] );
    }
    if ( metis_contacts_column_exists( $details_table, 'contact_id' ) ) {
        $found = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$details_table} WHERE contact_id = %d",
            $id
        ) );
        $add_rows( is_array( $found ) ? $found : [] );
    }
    if ( $did !== '' && metis_contacts_column_exists( $details_table, 'did' ) ) {
        $found = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$details_table} WHERE did = %s",
            $did
        ) );
        $add_rows( is_array( $found ) ? $found : [] );
    }

    return $rows;
}

metis_add_action( 'wp_ajax_metis_contacts_save', function () {

    metis_contacts_ajax_verify_nonce();

    if ( ! metis_contacts_can_manage() ) {
        metis_send_json_error( 'Unauthorized', 403 );
    }

    metis_contacts_ensure_schema();

    global $wpdb;

    $contacts_table = Metis_Tables::get( 'contacts' );
    $details_table  = Metis_Tables::get( 'contact_details' );

    $first_name = isset( $_POST['first_name'] ) ? sanitize_text_field( metis_unslash( $_POST['first_name'] ) ) : '';
    $last_name  = isset( $_POST['last_name'] ) ? sanitize_text_field( metis_unslash( $_POST['last_name'] ) ) : '';
    $email      = isset( $_POST['email'] ) ? sanitize_email( metis_unslash( $_POST['email'] ) ) : '';
    $phone      = isset( $_POST['phone'] ) ? sanitize_text_field( metis_unslash( $_POST['phone'] ) ) : '';

    $email = strtolower( trim( $email ) );
    $phone = trim( $phone );

    if ( ! is_email( $email ) ) {
        metis_send_json_error( 'A valid email is required.', 400 );
    }

    if ( $first_name === '' || $last_name === '' ) {
        metis_send_json_error( 'First name and last name are required.', 400 );
    }

    $existing_email_id = (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT id FROM {$contacts_table} WHERE email = %s LIMIT 1",
        $email
    ) );

    if ( $existing_email_id > 0 ) {
        metis_send_json_error( 'That email is already assigned to another contact.', 400 );
    }

    $new_cid = metis_generate_code( 'CN', $contacts_table, 'cid' );

    $inserted = $wpdb->insert(
        $contacts_table,
        [
            'first_name' => $first_name,
            'last_name'  => $last_name,
            'email'      => $email,
            'cid'        => $new_cid,
        ],
        [ '%s', '%s', '%s', '%s' ]
    );

    if ( ! $inserted ) {
        metis_send_json_error( 'Failed to create contact.', 500 );
    }

    $id = (int) $wpdb->insert_id;

    $existing_detail = null;
    if ( metis_contacts_table_exists( $details_table ) ) {
        if ( metis_contacts_column_exists( $details_table, 'contact_cid' ) ) {
            $existing_detail = $wpdb->get_row( $wpdb->prepare(
                "SELECT * FROM {$details_table} WHERE contact_cid = %s LIMIT 1",
                $new_cid
            ) );
        }
        if ( ! $existing_detail && metis_contacts_column_exists( $details_table, 'contact_id' ) ) {
            $existing_detail = $wpdb->get_row( $wpdb->prepare(
                "SELECT * FROM {$details_table} WHERE contact_id = %d LIMIT 1",
                $id
            ) );
        }

        $details_payload = [];
        $details_format  = [];

        if ( metis_contacts_column_exists( $details_table, 'contact_cid' ) ) {
            $details_payload['contact_cid'] = $new_cid;
            $details_format[] = '%s';
        }
        if ( metis_contacts_column_exists( $details_table, 'contact_id' ) ) {
            $details_payload['contact_id'] = $id;
            $details_format[] = '%d';
        }
        if ( metis_contacts_column_exists( $details_table, 'phone' ) ) {
            $details_payload['phone'] = $phone !== '' ? $phone : null;
            $details_format[] = '%s';
        }
        if ( metis_contacts_column_exists( $details_table, 'additional_emails_json' ) ) {
            $details_payload['additional_emails_json'] = null;
            $details_format[] = '%s';
        }
        if ( metis_contacts_column_exists( $details_table, 'relationships_json' ) ) {
            $details_payload['relationships_json'] = metis_json_encode( [] );
            $details_format[] = '%s';
        }

        if ( ! $existing_detail ) {
            $wpdb->insert( $details_table, $details_payload, $details_format );
        } else {
            $update_payload = $details_payload;
            $update_format = $details_format;
            if ( ! empty( $update_payload ) ) {
                if ( isset( $existing_detail->id ) ) {
                    $wpdb->update(
                        $details_table,
                        $update_payload,
                        [ 'id' => (int) $existing_detail->id ],
                        $update_format,
                        [ '%d' ]
                    );
                } elseif ( metis_contacts_column_exists( $details_table, 'contact_cid' ) ) {
                    $wpdb->update(
                        $details_table,
                        $update_payload,
                        [ 'contact_cid' => $new_cid ],
                        $update_format,
                        [ '%s' ]
                    );
                } else {
                    $wpdb->update(
                        $details_table,
                        $update_payload,
                        [ 'contact_id' => $id ],
                        $update_format,
                        [ '%d' ]
                    );
                }
            }
        }

        // Safety sync: ensure all linked detail rows carry canonical deduped email/relationship JSON.
        $sync_payload = [];
        $sync_format = [];
        if ( metis_contacts_column_exists( $details_table, 'additional_emails_json' ) ) {
            $sync_payload['additional_emails_json'] = metis_json_encode( $decoded_additional );
            $sync_format[] = '%s';
        }
        if ( metis_contacts_column_exists( $details_table, 'relationships_json' ) ) {
            $sync_payload['relationships_json'] = metis_json_encode( $decoded_relationships );
            $sync_format[] = '%s';
        }
        if ( ! empty( $sync_payload ) ) {
            if ( metis_contacts_column_exists( $details_table, 'contact_cid' ) ) {
                $res = $wpdb->update( $details_table, $sync_payload, [ 'contact_cid' => $cid ], $sync_format, [ '%s' ] );
                if ( $res === false ) {
                    metis_send_json_error( 'Failed to sync canonical detail rows by CID.', 500 );
                }
            }
            if ( metis_contacts_column_exists( $details_table, 'contact_id' ) ) {
                $res = $wpdb->update( $details_table, $sync_payload, [ 'contact_id' => $id ], $sync_format, [ '%d' ] );
                if ( $res === false ) {
                    metis_send_json_error( 'Failed to sync canonical detail rows by contact ID.', 500 );
                }
            }
            if ( $contact_did !== '' && metis_contacts_column_exists( $details_table, 'did' ) ) {
                $res = $wpdb->update( $details_table, $sync_payload, [ 'did' => $contact_did ], $sync_format, [ '%s' ] );
                if ( $res === false ) {
                    metis_send_json_error( 'Failed to sync canonical detail rows by Donor ID.', 500 );
                }
            }
        }

        // Recompute canonical additional emails across all linked detail rows, then enforce on all rows.
        $detail_rows = [];
        $seen_detail_ids = [];
        $collect_rows = static function ( array $rows ) use ( &$detail_rows, &$seen_detail_ids ): void {
            foreach ( $rows as $row ) {
                $rid = isset( $row->id ) ? (int) $row->id : 0;
                $key = $rid > 0 ? 'id:' . $rid : 'hash:' . md5( metis_json_encode( $row ) );
                if ( isset( $seen_detail_ids[ $key ] ) ) {
                    continue;
                }
                $seen_detail_ids[ $key ] = true;
                $detail_rows[] = $row;
            }
        };
        if ( metis_contacts_column_exists( $details_table, 'contact_cid' ) ) {
            $collect_rows( $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$details_table} WHERE contact_cid = %s", $cid ) ) ?: [] );
        }
        if ( metis_contacts_column_exists( $details_table, 'contact_id' ) ) {
            $collect_rows( $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$details_table} WHERE contact_id = %d", $id ) ) ?: [] );
        }
        if ( $contact_did !== '' && metis_contacts_column_exists( $details_table, 'did' ) ) {
            $collect_rows( $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$details_table} WHERE did = %s", $contact_did ) ) ?: [] );
        }

        if ( ! empty( $detail_rows ) && metis_contacts_column_exists( $details_table, 'additional_emails_json' ) ) {
            $all_candidates = $decoded_additional;
            foreach ( $detail_rows as $detail_row ) {
                $decoded_row = json_decode( (string) ( $detail_row->additional_emails_json ?? '[]' ), true );
                if ( is_array( $decoded_row ) ) {
                    $all_candidates = array_merge( $all_candidates, $decoded_row );
                }
            }
            $canonical = metis_contacts_reconcile_primary_and_additional_emails( $email, $all_candidates );
            $canonical_additional = (array) ( $canonical['additional'] ?? [] );
            foreach ( $detail_rows as $detail_row ) {
                if ( ! isset( $detail_row->id ) ) {
                    continue;
                }
                $patch_payload = [ 'additional_emails_json' => metis_json_encode( $canonical_additional ) ];
                $patch_format = [ '%s' ];
                if ( metis_contacts_column_exists( $details_table, 'updated_at' ) ) {
                    $patch_payload['updated_at'] = current_time( 'mysql' );
                    $patch_format[] = '%s';
                }
                $res = $wpdb->update(
                    $details_table,
                    $patch_payload,
                    [ 'id' => (int) $detail_row->id ],
                    $patch_format,
                    [ '%d' ]
                );
                if ( $res === false ) {
                    metis_send_json_error( 'Failed to enforce canonical additional emails.', 500 );
                }
            }
            $decoded_additional = $canonical_additional;
        }
    }

    $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$contacts_table} WHERE id = %d", $id ) );

    $details = null;
    if ( metis_contacts_table_exists( $details_table ) && metis_contacts_column_exists( $details_table, 'contact_id' ) ) {
        $details = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$details_table} WHERE contact_id = %d", $id ) );
    }

    if ( ! $row ) {
        metis_send_json_error( 'Contact saved, but response data is unavailable.', 500 );
    }

    if ( function_exists( 'metis_contacts_carddav_log_change' ) ) {
        $carddav_entry = function_exists( 'metis_contacts_carddav_fetch_contact' ) ? metis_contacts_carddav_fetch_contact( (string) $new_cid ) : null;
        if ( is_array( $carddav_entry ) ) {
            metis_contacts_carddav_log_change(
                (string) $new_cid,
                'upsert',
                metis_contacts_carddav_book_slugs_for_contact( $carddav_entry['contact'], $carddav_entry['details'] ),
                metis_contacts_carddav_contact_etag( $carddav_entry['contact'], $carddav_entry['details'] )
            );
        }
    }

    metis_send_json_success( [
        'contact' => metis_contacts_ajax_format_row( $row, $details ),
    ] );
} );

metis_add_action( 'wp_ajax_metis_contact_detail_save', function () {

    metis_contacts_ajax_verify_nonce();

    if ( ! metis_contacts_can_manage() ) {
        metis_send_json_error( 'Unauthorized', 403 );
    }

    metis_contacts_ensure_schema();

    global $wpdb;

    $contacts_table = Metis_Tables::get( 'contacts' );
    $details_table  = Metis_Tables::get( 'contact_details' );
    $newsletter_subs_table = Metis_Tables::get( 'newsletter_subs' );
    $newsletter_lists_table = Metis_Tables::get( 'newsletter_lists' );

    $cid   = isset( $_POST['cid'] ) ? sanitize_text_field( metis_unslash( $_POST['cid'] ) ) : '';
    $first = isset( $_POST['first_name'] ) ? sanitize_text_field( metis_unslash( $_POST['first_name'] ) ) : '';
    $last  = isset( $_POST['last_name'] ) ? sanitize_text_field( metis_unslash( $_POST['last_name'] ) ) : '';
    $email = isset( $_POST['email'] ) ? sanitize_email( metis_unslash( $_POST['email'] ) ) : '';
    $phone = isset( $_POST['phone'] ) ? sanitize_text_field( metis_unslash( $_POST['phone'] ) ) : '';
    $preferred_name = isset( $_POST['preferred_name'] ) ? sanitize_text_field( metis_unslash( $_POST['preferred_name'] ) ) : '';
    $preferred_contact = isset( $_POST['preferred_contact_method'] ) ? sanitize_text_field( metis_unslash( $_POST['preferred_contact_method'] ) ) : '';
    $address = isset( $_POST['address'] ) ? sanitize_text_field( metis_unslash( $_POST['address'] ) ) : '';
    $city = isset( $_POST['city'] ) ? sanitize_text_field( metis_unslash( $_POST['city'] ) ) : '';
    $state = isset( $_POST['state'] ) ? sanitize_text_field( metis_unslash( $_POST['state'] ) ) : '';
    $zip = isset( $_POST['zip'] ) ? sanitize_text_field( metis_unslash( $_POST['zip'] ) ) : '';
    $birthday = isset( $_POST['birthday'] ) ? sanitize_text_field( metis_unslash( $_POST['birthday'] ) ) : '';
    $spouse_name = isset( $_POST['spouse_name'] ) ? sanitize_text_field( metis_unslash( $_POST['spouse_name'] ) ) : null;
    $household_id = isset( $_POST['household_id'] ) ? sanitize_text_field( metis_unslash( $_POST['household_id'] ) ) : '';
    $source_code = isset( $_POST['source_code'] ) ? sanitize_text_field( metis_unslash( $_POST['source_code'] ) ) : null;
    $first_contacted = isset( $_POST['first_contacted'] ) ? sanitize_text_field( metis_unslash( $_POST['first_contacted'] ) ) : null;
    $staff_owner = isset( $_POST['staff_owner'] ) ? sanitize_text_field( metis_unslash( $_POST['staff_owner'] ) ) : null;
    $do_not_contact = isset( $_POST['do_not_contact'] ) ? ( ! empty( metis_unslash( $_POST['do_not_contact'] ) ) ? 1 : 0 ) : 0;
    $volunteer_status = isset( $_POST['volunteer_status'] ) ? ( ! empty( metis_unslash( $_POST['volunteer_status'] ) ) ? 1 : 0 ) : 0;
    $anonymous_donor = isset( $_POST['anonymous_donor'] ) ? ( ! empty( metis_unslash( $_POST['anonymous_donor'] ) ) ? 1 : 0 ) : 0;
    $relationships_json = isset( $_POST['relationships_json'] ) ? metis_unslash( $_POST['relationships_json'] ) : '[]';
    $additional_emails_json = isset( $_POST['additional_emails_json'] ) ? metis_unslash( $_POST['additional_emails_json'] ) : '[]';
    $newsletter_list_ids = [];
    if ( isset( $_POST['newsletter_list_ids'] ) ) {
        $decoded_newsletters = json_decode( (string) metis_unslash( $_POST['newsletter_list_ids'] ), true );
        if ( is_array( $decoded_newsletters ) ) {
            $newsletter_list_ids = array_values( array_unique( array_map( 'intval', $decoded_newsletters ) ) );
        }
    }

    $email = strtolower( trim( $email ) );
    $phone = metis_contacts_format_phone_us( trim( $phone ) );
    $birthday = trim( $birthday );
    if ( null !== $first_contacted ) {
        $first_contacted = trim( $first_contacted );
    }
    if ( $birthday !== '' && ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $birthday ) ) {
        metis_send_json_error( 'Birthday must use YYYY-MM-DD format.', 400 );
    }
    if ( null !== $first_contacted && $first_contacted !== '' ) {
        $first_contacted_ts = strtotime( $first_contacted );
        if ( $first_contacted_ts === false ) {
            metis_send_json_error( 'First contacted must be a valid date/time.', 400 );
        }
        $first_contacted = metis_date( 'Y-m-d H:i:s', $first_contacted_ts, metis_timezone() );
    }

    if ( $cid === '' || $first === '' || $last === '' || ! is_email( $email ) ) {
        metis_send_json_error( 'Invalid contact payload.', 400 );
    }

    $contact_row = $wpdb->get_row( $wpdb->prepare(
        "SELECT id, did, email, first_name, last_name FROM {$contacts_table} WHERE cid = %s LIMIT 1",
        $cid
    ) );
    $id = $contact_row ? (int) $contact_row->id : 0;
    $contact_did = $contact_row ? (string) ( $contact_row->did ?? '' ) : '';
    $previous_primary_email = $contact_row ? strtolower( trim( (string) ( $contact_row->email ?? '' ) ) ) : '';
    if ( $id < 1 ) {
        metis_send_json_error( 'Contact not found for CID.', 404 );
    }

    $email_conflict = (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT id FROM {$contacts_table} WHERE email = %s AND id <> %d LIMIT 1",
        $email,
        $id
    ) );
    if ( $email_conflict > 0 ) {
        metis_send_json_error( 'Email already exists on another contact.', 400 );
    }

    $decoded_relationships = json_decode( $relationships_json, true );
    if ( json_last_error() !== JSON_ERROR_NONE ) {
        metis_send_json_error( 'JSON fields must be valid JSON arrays.', 400 );
    }
    $decoded_additional = json_decode( $additional_emails_json, true );
    if ( json_last_error() !== JSON_ERROR_NONE ) {
        metis_send_json_error( 'JSON fields must be valid JSON arrays.', 400 );
    }
    if ( ! is_array( $decoded_relationships ) || ! is_array( $decoded_additional ) ) {
        metis_send_json_error( 'JSON fields must be arrays.', 400 );
    }

    $decoded_relationships = metis_contacts_normalize_relationships( $decoded_relationships, $cid );
    $reconciled_emails = metis_contacts_reconcile_primary_and_additional_emails( $email, $decoded_additional );
    $email = (string) $reconciled_emails['primary_email'];
    $decoded_additional = (array) $reconciled_emails['additional'];
    if ( $previous_primary_email !== '' && $previous_primary_email !== $email && is_email( $previous_primary_email ) ) {
        $decoded_additional[] = $previous_primary_email;
    }
    $reconciled_emails = metis_contacts_reconcile_primary_and_additional_emails( $email, $decoded_additional );
    $email = (string) $reconciled_emails['primary_email'];
    $decoded_additional = (array) $reconciled_emails['additional'];

    $contact_payload = [
        'first_name' => $first,
        'last_name'  => $last,
        'email'      => $email,
    ];
    $contact_format = [ '%s', '%s', '%s' ];
    if ( metis_contacts_column_exists( $contacts_table, 'updated_at' ) ) {
        $contact_payload['updated_at'] = current_time( 'mysql' );
        $contact_format[] = '%s';
    }
    $contact_update = $wpdb->update(
        $contacts_table,
        $contact_payload,
        [ 'id' => $id ],
        $contact_format,
        [ '%d' ]
    );
    if ( $contact_update === false ) {
        metis_send_json_error( 'Failed to update contact record.', 500 );
    }

    $existing_detail = null;
    if ( metis_contacts_table_exists( $details_table ) ) {
        if ( metis_contacts_column_exists( $details_table, 'contact_cid' ) ) {
            $existing_detail = $wpdb->get_row( $wpdb->prepare(
                "SELECT * FROM {$details_table} WHERE contact_cid = %s LIMIT 1",
                $cid
            ) );
        }
        if ( ! $existing_detail && metis_contacts_column_exists( $details_table, 'contact_id' ) ) {
            $existing_detail = $wpdb->get_row( $wpdb->prepare(
                "SELECT * FROM {$details_table} WHERE contact_id = %d LIMIT 1",
                $id
            ) );
        }
        if ( ! $existing_detail && $contact_did !== '' && metis_contacts_column_exists( $details_table, 'did' ) ) {
            $existing_detail = $wpdb->get_row( $wpdb->prepare(
                "SELECT * FROM {$details_table} WHERE did = %s LIMIT 1",
                $contact_did
            ) );
        }
        $details_payload = [];
        $details_format  = [];

        if ( metis_contacts_column_exists( $details_table, 'contact_cid' ) ) {
            $details_payload['contact_cid'] = $cid;
            $details_format[] = '%s';
        }
        if ( metis_contacts_column_exists( $details_table, 'contact_id' ) ) {
            $details_payload['contact_id'] = $id;
            $details_format[] = '%d';
        }
        if ( metis_contacts_column_exists( $details_table, 'did' ) ) {
            $details_payload['did'] = $contact_did !== '' ? $contact_did : null;
            $details_format[] = '%s';
        }
        if ( metis_contacts_column_exists( $details_table, 'phone' ) ) {
            $details_payload['phone'] = $phone !== '' ? $phone : null;
            $details_format[] = '%s';
        }
        if ( metis_contacts_column_exists( $details_table, 'preferred_name' ) ) {
            $details_payload['preferred_name'] = $preferred_name !== '' ? $preferred_name : null;
            $details_format[] = '%s';
        }
        if ( metis_contacts_column_exists( $details_table, 'preferred_contact_method' ) ) {
            $details_payload['preferred_contact_method'] = $preferred_contact !== '' ? $preferred_contact : null;
            $details_format[] = '%s';
        }
        if ( metis_contacts_column_exists( $details_table, 'address' ) ) {
            $details_payload['address'] = $address !== '' ? $address : null;
            $details_format[] = '%s';
        }
        if ( metis_contacts_column_exists( $details_table, 'city' ) ) {
            $details_payload['city'] = $city !== '' ? $city : null;
            $details_format[] = '%s';
        }
        if ( metis_contacts_column_exists( $details_table, 'state' ) ) {
            $details_payload['state'] = $state !== '' ? $state : null;
            $details_format[] = '%s';
        }
        if ( metis_contacts_column_exists( $details_table, 'zip' ) ) {
            $details_payload['zip'] = $zip !== '' ? $zip : null;
            $details_format[] = '%s';
        }
        if ( metis_contacts_column_exists( $details_table, 'birthday' ) ) {
            $details_payload['birthday'] = $birthday !== '' ? $birthday : null;
            $details_format[] = '%s';
        }
        if ( null !== $spouse_name && metis_contacts_column_exists( $details_table, 'spouse_name' ) ) {
            $details_payload['spouse_name'] = $spouse_name !== '' ? $spouse_name : null;
            $details_format[] = '%s';
        }
        if ( metis_contacts_column_exists( $details_table, 'household_id' ) ) {
            $details_payload['household_id'] = $household_id !== '' ? $household_id : null;
            $details_format[] = '%s';
        }
        if ( null !== $source_code && metis_contacts_column_exists( $details_table, 'source_code' ) ) {
            $details_payload['source_code'] = $source_code !== '' ? $source_code : null;
            $details_format[] = '%s';
        }
        if ( null !== $first_contacted && metis_contacts_column_exists( $details_table, 'first_contacted' ) ) {
            $details_payload['first_contacted'] = $first_contacted !== '' ? $first_contacted : null;
            $details_format[] = '%s';
        }
        if ( null !== $staff_owner && metis_contacts_column_exists( $details_table, 'staff_owner' ) ) {
            $details_payload['staff_owner'] = $staff_owner !== '' ? $staff_owner : null;
            $details_format[] = '%s';
        }
        if ( metis_contacts_column_exists( $details_table, 'do_not_contact' ) ) {
            $details_payload['do_not_contact'] = $do_not_contact;
            $details_format[] = '%d';
        }
        if ( metis_contacts_column_exists( $details_table, 'volunteer_status' ) ) {
            $details_payload['volunteer_status'] = $volunteer_status;
            $details_format[] = '%d';
        }
        if ( metis_contacts_column_exists( $details_table, 'anonymous_donor' ) ) {
            $details_payload['anonymous_donor'] = $anonymous_donor;
            $details_format[] = '%d';
        }
        if ( metis_contacts_column_exists( $details_table, 'relationships_json' ) ) {
            $details_payload['relationships_json'] = metis_json_encode( $decoded_relationships );
            $details_format[] = '%s';
        }
        if ( metis_contacts_column_exists( $details_table, 'additional_emails_json' ) ) {
            $details_payload['additional_emails_json'] = metis_json_encode( $decoded_additional );
            $details_format[] = '%s';
        }
        if ( metis_contacts_column_exists( $details_table, 'updated_at' ) ) {
            $details_payload['updated_at'] = current_time( 'mysql' );
            $details_format[] = '%s';
        }

        if ( ! $existing_detail ) {
            $wpdb->insert( $details_table, $details_payload, $details_format );
        } else {
            $update_payload = $details_payload;
            $update_format = $details_format;
            if ( ! empty( $update_payload ) ) {
                if ( isset( $existing_detail->id ) ) {
                    $wpdb->update(
                        $details_table,
                        $update_payload,
                        [ 'id' => (int) $existing_detail->id ],
                        $update_format,
                        [ '%d' ]
                    );
                } elseif ( metis_contacts_column_exists( $details_table, 'contact_cid' ) ) {
                    $wpdb->update(
                        $details_table,
                        $update_payload,
                        [ 'contact_cid' => $cid ],
                        $update_format,
                        [ '%s' ]
                    );
                } else {
                    $wpdb->update(
                        $details_table,
                        $update_payload,
                        [ 'contact_id' => $id ],
                        $update_format,
                        [ '%d' ]
                    );
                }
            }
        }

        // Force canonical data across every linked detail row for this contact.
        $sync_payload = [];
        $sync_format = [];
        if ( metis_contacts_column_exists( $details_table, 'additional_emails_json' ) ) {
            $sync_payload['additional_emails_json'] = metis_json_encode( $decoded_additional );
            $sync_format[] = '%s';
        }
        if ( metis_contacts_column_exists( $details_table, 'relationships_json' ) ) {
            $sync_payload['relationships_json'] = metis_json_encode( $decoded_relationships );
            $sync_format[] = '%s';
        }
        foreach ( [
            'phone' => [ $phone !== '' ? $phone : null, '%s' ],
            'preferred_name' => [ $preferred_name !== '' ? $preferred_name : null, '%s' ],
            'preferred_contact_method' => [ $preferred_contact !== '' ? $preferred_contact : null, '%s' ],
            'address' => [ $address !== '' ? $address : null, '%s' ],
            'city' => [ $city !== '' ? $city : null, '%s' ],
            'state' => [ $state !== '' ? $state : null, '%s' ],
            'zip' => [ $zip !== '' ? $zip : null, '%s' ],
            'birthday' => [ $birthday !== '' ? $birthday : null, '%s' ],
            'spouse_name' => [ null !== $spouse_name ? ( $spouse_name !== '' ? $spouse_name : null ) : null, '%s' ],
            'household_id' => [ $household_id !== '' ? $household_id : null, '%s' ],
            'source_code' => [ null !== $source_code ? ( $source_code !== '' ? $source_code : null ) : null, '%s' ],
            'first_contacted' => [ null !== $first_contacted ? ( $first_contacted !== '' ? $first_contacted : null ) : null, '%s' ],
            'staff_owner' => [ null !== $staff_owner ? ( $staff_owner !== '' ? $staff_owner : null ) : null, '%s' ],
            'do_not_contact' => [ $do_not_contact, '%d' ],
            'volunteer_status' => [ $volunteer_status, '%d' ],
            'anonymous_donor' => [ $anonymous_donor, '%d' ],
        ] as $column => $meta ) {
            if ( in_array( $column, [ 'spouse_name', 'source_code', 'staff_owner', 'first_contacted' ], true ) && null === $meta[0] ) {
                continue;
            }
            if ( metis_contacts_column_exists( $details_table, $column ) ) {
                $sync_payload[ $column ] = $meta[0];
                $sync_format[] = $meta[1];
            }
        }
        if ( metis_contacts_column_exists( $details_table, 'updated_at' ) ) {
            $sync_payload['updated_at'] = current_time( 'mysql' );
            $sync_format[] = '%s';
        }
        if ( ! empty( $sync_payload ) ) {
            if ( metis_contacts_column_exists( $details_table, 'contact_cid' ) ) {
                $res = $wpdb->update( $details_table, $sync_payload, [ 'contact_cid' => $cid ], $sync_format, [ '%s' ] );
                if ( $res === false ) {
                    metis_send_json_error( 'Failed to sync details by contact CID.', 500 );
                }
            }
            if ( metis_contacts_column_exists( $details_table, 'contact_id' ) ) {
                $res = $wpdb->update( $details_table, $sync_payload, [ 'contact_id' => $id ], $sync_format, [ '%d' ] );
                if ( $res === false ) {
                    metis_send_json_error( 'Failed to sync details by contact ID.', 500 );
                }
            }
            if ( $contact_did !== '' && metis_contacts_column_exists( $details_table, 'did' ) ) {
                $res = $wpdb->update( $details_table, $sync_payload, [ 'did' => $contact_did ], $sync_format, [ '%s' ] );
                if ( $res === false ) {
                    metis_send_json_error( 'Failed to sync details by donor ID.', 500 );
                }
            }
        }
    }

    // Keep relationship visibility in sync on the related contact records.
    if ( metis_contacts_table_exists( $details_table ) &&
         ( metis_contacts_column_exists( $details_table, 'contact_id' ) || metis_contacts_column_exists( $details_table, 'contact_cid' ) ) &&
         metis_contacts_column_exists( $details_table, 'relationships_json' ) ) {
        $current_links = [];
        foreach ( $decoded_relationships as $entry ) {
            if ( ! is_array( $entry ) ) {
                continue;
            }
            $related_cid = (string) ( $entry['related_contact_cid'] ?? '' );
            if ( $related_cid === '' ) {
                continue;
            }
            if ( ! isset( $current_links[ $related_cid ] ) ) {
                $current_links[ $related_cid ] = [];
            }
            $current_links[ $related_cid ][] = [
                'related_contact_cid' => $cid,
                'relation_type'       => (string) ( $entry['relation_type'] ?? '' ),
                'notes'               => (string) ( $entry['notes'] ?? '' ),
            ];
        }

        $previous_links = [];
        if ( $existing_detail && ! empty( $existing_detail->relationships_json ) ) {
            $decoded_previous = json_decode( (string) $existing_detail->relationships_json, true );
            if ( is_array( $decoded_previous ) ) {
                $decoded_previous = metis_contacts_normalize_relationships( $decoded_previous, $cid );
                foreach ( $decoded_previous as $entry ) {
                    $prev_cid = (string) ( $entry['related_contact_cid'] ?? '' );
                    if ( $prev_cid !== '' ) {
                        $previous_links[ $prev_cid ] = true;
                    }
                }
            }
        }

        $sync_cids = array_unique( array_merge( array_keys( $current_links ), array_keys( $previous_links ) ) );
        foreach ( $sync_cids as $related_cid ) {
            if ( ! is_string( $related_cid ) || $related_cid === '' || $related_cid === $cid ) {
                continue;
            }

            $related_contact = $wpdb->get_row( $wpdb->prepare(
                "SELECT id, cid FROM {$contacts_table} WHERE cid = %s LIMIT 1",
                $related_cid
            ) );
            if ( ! $related_contact ) {
                continue;
            }

            $related_id = (int) $related_contact->id;
            if ( $related_id < 1 ) {
                continue;
            }

            $related_contact_cid = (string) ( $related_contact->cid ?? '' );
            $related_detail = null;
            if ( metis_contacts_column_exists( $details_table, 'contact_cid' ) ) {
                $related_detail = $wpdb->get_row( $wpdb->prepare(
                    "SELECT * FROM {$details_table} WHERE contact_cid = %s LIMIT 1",
                    $related_contact_cid
                ) );
            }
            if ( ! $related_detail && metis_contacts_column_exists( $details_table, 'contact_id' ) ) {
                $related_detail = $wpdb->get_row( $wpdb->prepare(
                    "SELECT * FROM {$details_table} WHERE contact_id = %d LIMIT 1",
                    $related_id
                ) );
            }

            $related_relationships = [];
            if ( $related_detail && ! empty( $related_detail->relationships_json ) ) {
                $decoded_related = json_decode( (string) $related_detail->relationships_json, true );
                if ( is_array( $decoded_related ) ) {
                    $related_relationships = metis_contacts_normalize_relationships( $decoded_related, $related_cid );
                }
            }

            $filtered_related = [];
            foreach ( $related_relationships as $entry ) {
                $target_cid = (string) ( $entry['related_contact_cid'] ?? '' );
                if ( $target_cid === $cid ) {
                    continue;
                }
                $filtered_related[] = $entry;
            }

            if ( isset( $current_links[ $related_cid ] ) && is_array( $current_links[ $related_cid ] ) ) {
                $filtered_related = array_merge( $filtered_related, $current_links[ $related_cid ] );
            }

            $filtered_related = metis_contacts_normalize_relationships( $filtered_related, $related_cid );
            $encoded = metis_json_encode( $filtered_related );

            if ( $related_detail ) {
                if ( isset( $related_detail->id ) ) {
                    $wpdb->update(
                        $details_table,
                        [ 'relationships_json' => $encoded ],
                        [ 'id' => (int) $related_detail->id ],
                        [ '%s' ],
                        [ '%d' ]
                    );
                } elseif ( metis_contacts_column_exists( $details_table, 'contact_cid' ) ) {
                    $wpdb->update(
                        $details_table,
                        [ 'relationships_json' => $encoded ],
                        [ 'contact_cid' => $related_contact_cid ],
                        [ '%s' ],
                        [ '%s' ]
                    );
                } else {
                    $wpdb->update(
                        $details_table,
                        [ 'relationships_json' => $encoded ],
                        [ 'contact_id' => $related_id ],
                        [ '%s' ],
                        [ '%d' ]
                    );
                }
            } else {
                $insert_payload = [ 'relationships_json' => $encoded ];
                $insert_format = [ '%s' ];
                if ( metis_contacts_column_exists( $details_table, 'contact_cid' ) ) {
                    $insert_payload['contact_cid'] = $related_contact_cid;
                    $insert_format[] = '%s';
                }
                if ( metis_contacts_column_exists( $details_table, 'contact_id' ) ) {
                    $insert_payload['contact_id'] = $related_id;
                    $insert_format[] = '%d';
                }
                $wpdb->insert( $details_table, $insert_payload, $insert_format );
            }
        }
    }

    if ( metis_contacts_table_exists( $newsletter_subs_table ) && metis_contacts_table_exists( $newsletter_lists_table ) ) {
        $valid_list_ids = [];
        if ( ! empty( $newsletter_list_ids ) ) {
            $placeholders = implode( ',', array_fill( 0, count( $newsletter_list_ids ), '%d' ) );
            $query = $wpdb->prepare(
                "SELECT id FROM {$newsletter_lists_table} WHERE id IN ({$placeholders})",
                ...$newsletter_list_ids
            );
            $valid_list_ids = $wpdb->get_col( $query ) ?: [];
            $valid_list_ids = array_map( 'intval', $valid_list_ids );
        }

        $delete_ok = $wpdb->delete( $newsletter_subs_table, [ 'contact_id' => $id ], [ '%d' ] );
        if ( $delete_ok === false ) {
            metis_send_json_error( 'Failed to update newsletter subscriptions.', 500 );
        }
        foreach ( $valid_list_ids as $list_id ) {
            $ins = $wpdb->insert(
                $newsletter_subs_table,
                [ 'contact_id' => $id, 'list_id' => $list_id ],
                [ '%d', '%d' ]
            );
            if ( $ins === false ) {
                metis_send_json_error( 'Failed to save newsletter subscription.', 500 );
            }
        }
    }

    if ( function_exists( 'metis_contacts_carddav_fetch_contact' ) ) {
        $carddav_entry = metis_contacts_carddav_fetch_contact( $cid );
        if ( is_array( $carddav_entry ) ) {
            metis_contacts_carddav_log_change(
                $cid,
                'upsert',
                metis_contacts_carddav_book_slugs_for_contact( $carddav_entry['contact'], $carddav_entry['details'] ),
                metis_contacts_carddav_contact_etag( $carddav_entry['contact'], $carddav_entry['details'] )
            );
        }
    }

    metis_send_json_success( [ 'cid' => $cid ] );
} );

metis_add_action( 'wp_ajax_metis_contact_inline_update', function () {

    metis_contacts_ajax_verify_nonce();

    if ( ! metis_contacts_can_manage() ) {
        metis_send_json_error( 'Unauthorized', 403 );
    }

    metis_contacts_ensure_schema();

    global $wpdb;

    $contacts_table = Metis_Tables::get( 'contacts' );
    $details_table  = Metis_Tables::get( 'contact_details' );

    $cid = isset( $_POST['cid'] ) ? sanitize_text_field( metis_unslash( $_POST['cid'] ) ) : '';
    $field = isset( $_POST['field'] ) ? sanitize_key( metis_unslash( $_POST['field'] ) ) : '';
    $value = isset( $_POST['value'] ) ? metis_unslash( $_POST['value'] ) : '';

    if ( $cid === '' ) {
        metis_send_json_error( 'Invalid inline update payload.', 400 );
    }

    $contact_row = $wpdb->get_row( $wpdb->prepare(
        "SELECT id, cid, did, email, first_name, last_name FROM {$contacts_table} WHERE cid = %s LIMIT 1",
        $cid
    ) );
    if ( ! $contact_row ) {
        metis_send_json_error( 'Contact not found.', 404 );
    }

    $id = (int) $contact_row->id;
    $did = (string) ( $contact_row->did ?? '' );
    $primary_email_before = (string) ( $contact_row->email ?? '' );
    $detail_rows = metis_contacts_collect_linked_detail_rows( $details_table, $cid, $id, $did );

    $contact_fields = [
        'full_name'  => '%s',
        'first_name' => '%s',
        'last_name'  => '%s',
    ];
    $detail_fields = [
        'phone'                    => '%s',
        'preferred_name'           => '%s',
        'preferred_contact_method' => '%s',
        'address'                  => '%s',
        'city'                     => '%s',
        'state'                    => '%s',
        'zip'                      => '%s',
        'birthday'                 => '%s',
        'spouse_name'              => '%s',
        'household_id'             => '%s',
        'source_code'              => '%s',
        'first_contacted'          => '%s',
        'staff_owner'              => '%s',
        'do_not_contact'           => '%d',
        'volunteer_status'         => '%d',
        'anonymous_donor'          => '%d',
    ];

    if ( $field === 'address_full' ) {
        $parsed = metis_contacts_parse_address_full( (string) $value );
        if ( metis_contacts_table_exists( $details_table ) ) {
            if ( empty( $detail_rows ) ) {
                $insert_payload = [];
                $insert_format = [];
                if ( metis_contacts_column_exists( $details_table, 'contact_cid' ) ) {
                    $insert_payload['contact_cid'] = $cid;
                    $insert_format[] = '%s';
                }
                if ( metis_contacts_column_exists( $details_table, 'contact_id' ) ) {
                    $insert_payload['contact_id'] = $id;
                    $insert_format[] = '%d';
                }
                if ( metis_contacts_column_exists( $details_table, 'did' ) ) {
                    $insert_payload['did'] = $did !== '' ? $did : null;
                    $insert_format[] = '%s';
                }
                if ( metis_contacts_column_exists( $details_table, 'address' ) ) {
                    $insert_payload['address'] = $parsed['address'] !== '' ? $parsed['address'] : null;
                    $insert_format[] = '%s';
                }
                if ( metis_contacts_column_exists( $details_table, 'city' ) ) {
                    $insert_payload['city'] = $parsed['city'] !== '' ? $parsed['city'] : null;
                    $insert_format[] = '%s';
                }
                if ( metis_contacts_column_exists( $details_table, 'state' ) ) {
                    $insert_payload['state'] = $parsed['state'] !== '' ? $parsed['state'] : null;
                    $insert_format[] = '%s';
                }
                if ( metis_contacts_column_exists( $details_table, 'zip' ) ) {
                    $insert_payload['zip'] = $parsed['zip'] !== '' ? $parsed['zip'] : null;
                    $insert_format[] = '%s';
                }
                if ( metis_contacts_column_exists( $details_table, 'updated_at' ) ) {
                    $insert_payload['updated_at'] = current_time( 'mysql' );
                    $insert_format[] = '%s';
                }
                if ( ! empty( $insert_payload ) ) {
                    $inserted = $wpdb->insert( $details_table, $insert_payload, $insert_format );
                    if ( $inserted === false ) {
                        metis_send_json_error( 'Failed to create details row.', 500 );
                    }
                }
            } else {
                foreach ( $detail_rows as $detail_row ) {
                    if ( ! isset( $detail_row->id ) ) {
                        continue;
                    }
                    $patch_payload = [];
                    $patch_format = [];
                    if ( metis_contacts_column_exists( $details_table, 'address' ) ) {
                        $patch_payload['address'] = $parsed['address'] !== '' ? $parsed['address'] : null;
                        $patch_format[] = '%s';
                    }
                    if ( metis_contacts_column_exists( $details_table, 'city' ) ) {
                        $patch_payload['city'] = $parsed['city'] !== '' ? $parsed['city'] : null;
                        $patch_format[] = '%s';
                    }
                    if ( metis_contacts_column_exists( $details_table, 'state' ) ) {
                        $patch_payload['state'] = $parsed['state'] !== '' ? $parsed['state'] : null;
                        $patch_format[] = '%s';
                    }
                    if ( metis_contacts_column_exists( $details_table, 'zip' ) ) {
                        $patch_payload['zip'] = $parsed['zip'] !== '' ? $parsed['zip'] : null;
                        $patch_format[] = '%s';
                    }
                    if ( metis_contacts_column_exists( $details_table, 'updated_at' ) ) {
                        $patch_payload['updated_at'] = current_time( 'mysql' );
                        $patch_format[] = '%s';
                    }
                    if ( empty( $patch_payload ) ) {
                        continue;
                    }
                    $update_ok = $wpdb->update(
                        $details_table,
                        $patch_payload,
                        [ 'id' => (int) $detail_row->id ],
                        $patch_format,
                        [ '%d' ]
                    );
                    if ( $update_ok === false ) {
                        metis_send_json_error( 'Failed to update address.', 500 );
                    }
                }
            }
        }

        if ( metis_contacts_column_exists( $contacts_table, 'updated_at' ) ) {
            $ok = $wpdb->update(
                $contacts_table,
                [ 'updated_at' => current_time( 'mysql' ) ],
                [ 'id' => $id ],
                [ '%s' ],
                [ '%d' ]
            );
            if ( $ok === false ) {
                metis_send_json_error( 'Failed to touch contact update time.', 500 );
            }
        }

        metis_send_json_success( [
            'cid'            => $cid,
            'field'          => $field,
            'value'          => (string) $parsed['value'],
            'address_line_1' => (string) $parsed['line_1'],
            'address_line_2' => (string) $parsed['line_2'],
        ] );
    }

    if ( $field === 'email' ) {
        $next_email = strtolower( trim( sanitize_email( (string) $value ) ) );
        if ( ! is_email( $next_email ) ) {
            metis_send_json_error( 'Please enter a valid email address.', 400 );
        }

        $email_conflict = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$contacts_table} WHERE email = %s AND id <> %d LIMIT 1",
            $next_email,
            $id
        ) );
        if ( $email_conflict > 0 ) {
            metis_send_json_error( 'Email already exists on another contact.', 400 );
        }

        $contact_payload = [ 'email' => $next_email ];
        $contact_format = [ '%s' ];
        if ( metis_contacts_column_exists( $contacts_table, 'updated_at' ) ) {
            $contact_payload['updated_at'] = current_time( 'mysql' );
            $contact_format[] = '%s';
        }
        $res = $wpdb->update( $contacts_table, $contact_payload, [ 'id' => $id ], $contact_format, [ '%d' ] );
        if ( $res === false ) {
            metis_send_json_error( 'Failed to update primary email.', 500 );
        }

        $all_additional_candidates = [];
        foreach ( $detail_rows as $detail_row ) {
            $decoded = json_decode( (string) ( $detail_row->additional_emails_json ?? '[]' ), true );
            if ( is_array( $decoded ) ) {
                $all_additional_candidates = array_merge( $all_additional_candidates, $decoded );
            }
        }
        if ( $primary_email_before !== '' && strtolower( trim( $primary_email_before ) ) !== $next_email ) {
            $all_additional_candidates[] = $primary_email_before;
        }
        $reconciled = metis_contacts_reconcile_primary_and_additional_emails( $next_email, $all_additional_candidates );
        $additional = (array) ( $reconciled['additional'] ?? [] );

        if ( metis_contacts_table_exists( $details_table ) ) {
            if ( empty( $detail_rows ) ) {
                $insert_payload = [];
                $insert_format = [];
                if ( metis_contacts_column_exists( $details_table, 'contact_cid' ) ) {
                    $insert_payload['contact_cid'] = $cid;
                    $insert_format[] = '%s';
                }
                if ( metis_contacts_column_exists( $details_table, 'contact_id' ) ) {
                    $insert_payload['contact_id'] = $id;
                    $insert_format[] = '%d';
                }
                if ( metis_contacts_column_exists( $details_table, 'did' ) ) {
                    $insert_payload['did'] = $did !== '' ? $did : null;
                    $insert_format[] = '%s';
                }
                if ( metis_contacts_column_exists( $details_table, 'additional_emails_json' ) ) {
                    $insert_payload['additional_emails_json'] = metis_json_encode( $additional );
                    $insert_format[] = '%s';
                }
                if ( metis_contacts_column_exists( $details_table, 'updated_at' ) ) {
                    $insert_payload['updated_at'] = current_time( 'mysql' );
                    $insert_format[] = '%s';
                }
                if ( ! empty( $insert_payload ) ) {
                    $wpdb->insert( $details_table, $insert_payload, $insert_format );
                }
            } else {
                foreach ( $detail_rows as $detail_row ) {
                    if ( ! isset( $detail_row->id ) ) {
                        continue;
                    }
                    $patch_payload = [];
                    $patch_format = [];
                    if ( metis_contacts_column_exists( $details_table, 'additional_emails_json' ) ) {
                        $patch_payload['additional_emails_json'] = metis_json_encode( $additional );
                        $patch_format[] = '%s';
                    }
                    if ( metis_contacts_column_exists( $details_table, 'updated_at' ) ) {
                        $patch_payload['updated_at'] = current_time( 'mysql' );
                        $patch_format[] = '%s';
                    }
                    if ( empty( $patch_payload ) ) {
                        continue;
                    }
                    $update_ok = $wpdb->update(
                        $details_table,
                        $patch_payload,
                        [ 'id' => (int) $detail_row->id ],
                        $patch_format,
                        [ '%d' ]
                    );
                    if ( $update_ok === false ) {
                        metis_send_json_error( 'Failed to sync additional emails.', 500 );
                    }
                }
            }
        }

        metis_send_json_success( [
            'cid' => $cid,
            'field' => 'email',
            'value' => $next_email,
            'additional_emails' => $additional,
        ] );
    }

    if ( isset( $contact_fields[ $field ] ) ) {
        $sanitized = sanitize_text_field( (string) $value );
        if ( $field === 'full_name' ) {
            $clean = preg_replace( '/\s+/', ' ', trim( $sanitized ) );
            if ( ! is_string( $clean ) || $clean === '' ) {
                metis_send_json_error( 'Name cannot be empty.', 400 );
            }
            $first_part = $clean;
            $last_part = '';
            if ( strpos( $clean, ',' ) !== false ) {
                $parts = array_map( 'trim', explode( ',', $clean, 2 ) );
                $last_part = (string) ( $parts[0] ?? '' );
                $first_part = (string) ( $parts[1] ?? '' );
            } else {
                $parts = preg_split( '/\s+/', $clean );
                if ( is_array( $parts ) && count( $parts ) > 1 ) {
                    $last_part = (string) array_pop( $parts );
                    $first_part = implode( ' ', $parts );
                }
            }
            $first_part = trim( $first_part );
            $last_part = trim( $last_part );
            if ( $first_part === '' ) {
                metis_send_json_error( 'Name needs at least a first name.', 400 );
            }
            $contact_payload = [ 'first_name' => $first_part, 'last_name' => $last_part ];
            $contact_format = [ '%s', '%s' ];
            if ( metis_contacts_column_exists( $contacts_table, 'updated_at' ) ) {
                $contact_payload['updated_at'] = current_time( 'mysql' );
                $contact_format[] = '%s';
            }
            $ok = $wpdb->update( $contacts_table, $contact_payload, [ 'id' => $id ], $contact_format, [ '%d' ] );
            if ( $ok === false ) {
                metis_send_json_error( 'Failed to update full name.', 500 );
            }
            metis_send_json_success( [
                'cid'   => $cid,
                'field' => $field,
                'value' => trim( $first_part . ' ' . $last_part ),
                'first_name' => $first_part,
                'last_name' => $last_part,
            ] );
        }
        if ( in_array( $field, [ 'first_name', 'last_name' ], true ) && $sanitized === '' ) {
            metis_send_json_error( 'Name fields cannot be empty.', 400 );
        }

        $contact_payload = [ $field => $sanitized ];
        $contact_format = [ $contact_fields[ $field ] ];
        if ( metis_contacts_column_exists( $contacts_table, 'updated_at' ) ) {
            $contact_payload['updated_at'] = current_time( 'mysql' );
            $contact_format[] = '%s';
        }
        $ok = $wpdb->update( $contacts_table, $contact_payload, [ 'id' => $id ], $contact_format, [ '%d' ] );
        if ( $ok === false ) {
            metis_send_json_error( 'Failed to update contact field.', 500 );
        }

        metis_send_json_success( [
            'cid'   => $cid,
            'field' => $field,
            'value' => $sanitized,
        ] );
    }

    if ( ! isset( $detail_fields[ $field ] ) ) {
        metis_send_json_error( 'Inline field is not supported.', 400 );
    }

    $sanitized_value = '';
    if ( in_array( $field, [ 'do_not_contact', 'volunteer_status', 'anonymous_donor' ], true ) ) {
        $sanitized_value = (string) ( ! empty( $value ) && (string) $value !== '0' ? 1 : 0 );
    } elseif ( $field === 'birthday' ) {
        $candidate = sanitize_text_field( (string) $value );
        $candidate = trim( $candidate );
        if ( $candidate !== '' && ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $candidate ) ) {
            metis_send_json_error( 'Birthday must use YYYY-MM-DD format.', 400 );
        }
        $sanitized_value = $candidate;
    } elseif ( $field === 'first_contacted' ) {
        $candidate = trim( sanitize_text_field( (string) $value ) );
        if ( $candidate !== '' ) {
            $ts = strtotime( $candidate );
            if ( $ts === false ) {
                metis_send_json_error( 'First contacted must be a valid date/time.', 400 );
            }
            $candidate = metis_date( 'Y-m-d H:i:s', $ts, metis_timezone() );
        }
        $sanitized_value = $candidate;
    } elseif ( $field === 'phone' ) {
        $sanitized_value = metis_contacts_format_phone_us( sanitize_text_field( (string) $value ) );
    } else {
        $sanitized_value = sanitize_text_field( (string) $value );
    }

    if ( metis_contacts_table_exists( $details_table ) ) {
        if ( empty( $detail_rows ) ) {
            $insert_payload = [];
            $insert_format = [];
            if ( metis_contacts_column_exists( $details_table, 'contact_cid' ) ) {
                $insert_payload['contact_cid'] = $cid;
                $insert_format[] = '%s';
            }
            if ( metis_contacts_column_exists( $details_table, 'contact_id' ) ) {
                $insert_payload['contact_id'] = $id;
                $insert_format[] = '%d';
            }
            if ( metis_contacts_column_exists( $details_table, 'did' ) ) {
                $insert_payload['did'] = $did !== '' ? $did : null;
                $insert_format[] = '%s';
            }
            if ( metis_contacts_column_exists( $details_table, $field ) ) {
                $insert_payload[ $field ] = $sanitized_value !== '' ? $sanitized_value : null;
                $insert_format[] = $detail_fields[ $field ];
            }
            if ( metis_contacts_column_exists( $details_table, 'updated_at' ) ) {
                $insert_payload['updated_at'] = current_time( 'mysql' );
                $insert_format[] = '%s';
            }
            if ( ! empty( $insert_payload ) ) {
                $inserted = $wpdb->insert( $details_table, $insert_payload, $insert_format );
                if ( $inserted === false ) {
                    metis_send_json_error( 'Failed to create details row.', 500 );
                }
            }
        } else {
            foreach ( $detail_rows as $detail_row ) {
                if ( ! isset( $detail_row->id ) ) {
                    continue;
                }
                $patch_payload = [];
                $patch_format = [];
                if ( metis_contacts_column_exists( $details_table, $field ) ) {
                    $patch_payload[ $field ] = $sanitized_value !== '' ? $sanitized_value : null;
                    $patch_format[] = $detail_fields[ $field ];
                }
                if ( metis_contacts_column_exists( $details_table, 'updated_at' ) ) {
                    $patch_payload['updated_at'] = current_time( 'mysql' );
                    $patch_format[] = '%s';
                }
                if ( empty( $patch_payload ) ) {
                    continue;
                }
                $update_ok = $wpdb->update(
                    $details_table,
                    $patch_payload,
                    [ 'id' => (int) $detail_row->id ],
                    $patch_format,
                    [ '%d' ]
                );
                if ( $update_ok === false ) {
                    metis_send_json_error( 'Failed to update detail field.', 500 );
                }
            }
        }
    }

    if ( metis_contacts_column_exists( $contacts_table, 'updated_at' ) ) {
        $ok = $wpdb->update(
            $contacts_table,
            [ 'updated_at' => current_time( 'mysql' ) ],
            [ 'id' => $id ],
            [ '%s' ],
            [ '%d' ]
        );
        if ( $ok === false ) {
            metis_send_json_error( 'Failed to touch contact update time.', 500 );
        }
    }

    if ( function_exists( 'metis_contacts_carddav_fetch_contact' ) ) {
        $carddav_entry = metis_contacts_carddav_fetch_contact( $cid );
        if ( is_array( $carddav_entry ) ) {
            metis_contacts_carddav_log_change(
                $cid,
                'upsert',
                metis_contacts_carddav_book_slugs_for_contact( $carddav_entry['contact'], $carddav_entry['details'] ),
                metis_contacts_carddav_contact_etag( $carddav_entry['contact'], $carddav_entry['details'] )
            );
        }
    }

    metis_send_json_success( [
        'cid'   => $cid,
        'field' => $field,
        'value' => $sanitized_value,
    ] );
} );

metis_add_action( 'wp_ajax_metis_contact_remove_additional_email', function () {

    metis_contacts_ajax_verify_nonce();

    if ( ! metis_contacts_can_manage() ) {
        metis_send_json_error( 'Unauthorized', 403 );
    }

    metis_contacts_ensure_schema();

    global $wpdb;
    $contacts_table = Metis_Tables::get( 'contacts' );
    $details_table  = Metis_Tables::get( 'contact_details' );

    $cid = isset( $_POST['cid'] ) ? sanitize_text_field( metis_unslash( $_POST['cid'] ) ) : '';
    $email_to_remove = isset( $_POST['email'] ) ? sanitize_email( metis_unslash( $_POST['email'] ) ) : '';
    $email_to_remove = strtolower( trim( $email_to_remove ) );

    if ( $cid === '' || $email_to_remove === '' ) {
        metis_send_json_error( 'CID and email are required.', 400 );
    }

    $contact_row = $wpdb->get_row( $wpdb->prepare(
        "SELECT id, cid, did, email FROM {$contacts_table} WHERE cid = %s LIMIT 1",
        $cid
    ) );
    if ( ! $contact_row ) {
        metis_send_json_error( 'Contact not found.', 404 );
    }

    $id = (int) $contact_row->id;
    $did = (string) ( $contact_row->did ?? '' );
    $primary_email = (string) ( $contact_row->email ?? '' );
    $detail_rows = metis_contacts_collect_linked_detail_rows( $details_table, $cid, $id, $did );

    $all_candidates = [];
    foreach ( $detail_rows as $detail_row ) {
        $decoded = json_decode( (string) ( $detail_row->additional_emails_json ?? '[]' ), true );
        if ( is_array( $decoded ) ) {
            $all_candidates = array_merge( $all_candidates, $decoded );
        }
    }

    $reconciled = metis_contacts_reconcile_primary_and_additional_emails( $primary_email, $all_candidates );
    $additional = array_values( array_filter(
        (array) ( $reconciled['additional'] ?? [] ),
        static function ( string $candidate ) use ( $email_to_remove ): bool {
            return $candidate !== $email_to_remove;
        }
    ) );

    if ( metis_contacts_table_exists( $details_table ) ) {
        if ( empty( $detail_rows ) ) {
            $insert_payload = [];
            $insert_format = [];
            if ( metis_contacts_column_exists( $details_table, 'contact_cid' ) ) {
                $insert_payload['contact_cid'] = $cid;
                $insert_format[] = '%s';
            }
            if ( metis_contacts_column_exists( $details_table, 'contact_id' ) ) {
                $insert_payload['contact_id'] = $id;
                $insert_format[] = '%d';
            }
            if ( metis_contacts_column_exists( $details_table, 'did' ) ) {
                $insert_payload['did'] = $did !== '' ? $did : null;
                $insert_format[] = '%s';
            }
            if ( metis_contacts_column_exists( $details_table, 'additional_emails_json' ) ) {
                $insert_payload['additional_emails_json'] = metis_json_encode( $additional );
                $insert_format[] = '%s';
            }
            if ( metis_contacts_column_exists( $details_table, 'updated_at' ) ) {
                $insert_payload['updated_at'] = current_time( 'mysql' );
                $insert_format[] = '%s';
            }
            if ( ! empty( $insert_payload ) ) {
                $wpdb->insert( $details_table, $insert_payload, $insert_format );
            }
        } else {
            foreach ( $detail_rows as $detail_row ) {
                if ( ! isset( $detail_row->id ) ) {
                    continue;
                }
                $patch_payload = [];
                $patch_format = [];
                if ( metis_contacts_column_exists( $details_table, 'additional_emails_json' ) ) {
                    $patch_payload['additional_emails_json'] = metis_json_encode( $additional );
                    $patch_format[] = '%s';
                }
                if ( metis_contacts_column_exists( $details_table, 'updated_at' ) ) {
                    $patch_payload['updated_at'] = current_time( 'mysql' );
                    $patch_format[] = '%s';
                }
                if ( empty( $patch_payload ) ) {
                    continue;
                }
                $ok = $wpdb->update(
                    $details_table,
                    $patch_payload,
                    [ 'id' => (int) $detail_row->id ],
                    $patch_format,
                    [ '%d' ]
                );
                if ( $ok === false ) {
                    metis_send_json_error( 'Failed to update additional emails.', 500 );
                }
            }
        }
    }

    if ( metis_contacts_column_exists( $contacts_table, 'updated_at' ) ) {
        $ok = $wpdb->update(
            $contacts_table,
            [ 'updated_at' => current_time( 'mysql' ) ],
            [ 'id' => $id ],
            [ '%s' ],
            [ '%d' ]
        );
        if ( $ok === false ) {
            metis_send_json_error( 'Failed to touch contact update time.', 500 );
        }
    }

    if ( function_exists( 'metis_contacts_carddav_fetch_contact' ) ) {
        $carddav_entry = metis_contacts_carddav_fetch_contact( $cid );
        if ( is_array( $carddav_entry ) ) {
            metis_contacts_carddav_log_change(
                $cid,
                'upsert',
                metis_contacts_carddav_book_slugs_for_contact( $carddav_entry['contact'], $carddav_entry['details'] ),
                metis_contacts_carddav_contact_etag( $carddav_entry['contact'], $carddav_entry['details'] )
            );
        }
    }

    metis_send_json_success( [
        'cid' => $cid,
        'additional_emails' => $additional,
    ] );
} );

metis_add_action( 'wp_ajax_metis_contact_add_additional_email', function () {

    metis_contacts_ajax_verify_nonce();

    if ( ! metis_contacts_can_manage() ) {
        metis_send_json_error( 'Unauthorized', 403 );
    }

    metis_contacts_ensure_schema();

    global $wpdb;
    $contacts_table = Metis_Tables::get( 'contacts' );
    $details_table  = Metis_Tables::get( 'contact_details' );

    $cid = isset( $_POST['cid'] ) ? sanitize_text_field( metis_unslash( $_POST['cid'] ) ) : '';
    $new_email = isset( $_POST['email'] ) ? sanitize_email( metis_unslash( $_POST['email'] ) ) : '';
    $new_email = strtolower( trim( $new_email ) );
    if ( $cid === '' || ! is_email( $new_email ) ) {
        metis_send_json_error( 'Valid CID and email are required.', 400 );
    }

    $contact_row = $wpdb->get_row( $wpdb->prepare(
        "SELECT id, did, email FROM {$contacts_table} WHERE cid = %s LIMIT 1",
        $cid
    ) );
    if ( ! $contact_row ) {
        metis_send_json_error( 'Contact not found.', 404 );
    }

    $id = (int) $contact_row->id;
    $did = (string) ( $contact_row->did ?? '' );
    $primary_email = (string) ( $contact_row->email ?? '' );
    $detail_rows = metis_contacts_collect_linked_detail_rows( $details_table, $cid, $id, $did );

    $all_candidates = [ $new_email ];
    foreach ( $detail_rows as $detail_row ) {
        $decoded = json_decode( (string) ( $detail_row->additional_emails_json ?? '[]' ), true );
        if ( is_array( $decoded ) ) {
            $all_candidates = array_merge( $all_candidates, $decoded );
        }
    }
    $reconciled = metis_contacts_reconcile_primary_and_additional_emails( $primary_email, $all_candidates );
    $additional = (array) ( $reconciled['additional'] ?? [] );

    if ( metis_contacts_table_exists( $details_table ) ) {
        if ( empty( $detail_rows ) ) {
            $insert_payload = [];
            $insert_format = [];
            if ( metis_contacts_column_exists( $details_table, 'contact_cid' ) ) {
                $insert_payload['contact_cid'] = $cid;
                $insert_format[] = '%s';
            }
            if ( metis_contacts_column_exists( $details_table, 'contact_id' ) ) {
                $insert_payload['contact_id'] = $id;
                $insert_format[] = '%d';
            }
            if ( metis_contacts_column_exists( $details_table, 'did' ) ) {
                $insert_payload['did'] = $did !== '' ? $did : null;
                $insert_format[] = '%s';
            }
            if ( metis_contacts_column_exists( $details_table, 'additional_emails_json' ) ) {
                $insert_payload['additional_emails_json'] = metis_json_encode( $additional );
                $insert_format[] = '%s';
            }
            if ( metis_contacts_column_exists( $details_table, 'updated_at' ) ) {
                $insert_payload['updated_at'] = current_time( 'mysql' );
                $insert_format[] = '%s';
            }
            if ( ! empty( $insert_payload ) ) {
                $wpdb->insert( $details_table, $insert_payload, $insert_format );
            }
        } else {
            foreach ( $detail_rows as $detail_row ) {
                if ( ! isset( $detail_row->id ) ) continue;
                $patch_payload = [];
                $patch_format = [];
                if ( metis_contacts_column_exists( $details_table, 'additional_emails_json' ) ) {
                    $patch_payload['additional_emails_json'] = metis_json_encode( $additional );
                    $patch_format[] = '%s';
                }
                if ( metis_contacts_column_exists( $details_table, 'updated_at' ) ) {
                    $patch_payload['updated_at'] = current_time( 'mysql' );
                    $patch_format[] = '%s';
                }
                if ( ! empty( $patch_payload ) ) {
                    $ok = $wpdb->update( $details_table, $patch_payload, [ 'id' => (int) $detail_row->id ], $patch_format, [ '%d' ] );
                    if ( $ok === false ) {
                        metis_send_json_error( 'Failed to update additional emails.', 500 );
                    }
                }
            }
        }
    }

    if ( function_exists( 'metis_contacts_carddav_fetch_contact' ) ) {
        $carddav_entry = metis_contacts_carddav_fetch_contact( $cid );
        if ( is_array( $carddav_entry ) ) {
            metis_contacts_carddav_log_change(
                $cid,
                'upsert',
                metis_contacts_carddav_book_slugs_for_contact( $carddav_entry['contact'], $carddav_entry['details'] ),
                metis_contacts_carddav_contact_etag( $carddav_entry['contact'], $carddav_entry['details'] )
            );
        }
    }

    metis_send_json_success( [ 'cid' => $cid, 'additional_emails' => $additional ] );
} );

metis_add_action( 'wp_ajax_metis_contact_add_newsletter', function () {
    metis_contacts_ajax_verify_nonce();

    if ( ! metis_contacts_can_manage() ) {
        metis_send_json_error( 'Unauthorized', 403 );
    }

    global $wpdb;
    $contacts_table = Metis_Tables::get( 'contacts' );
    $newsletter_subs_table = Metis_Tables::get( 'newsletter_subs' );
    $newsletter_lists_table = Metis_Tables::get( 'newsletter_lists' );

    $cid = isset( $_POST['cid'] ) ? sanitize_text_field( metis_unslash( $_POST['cid'] ) ) : '';
    $list_id = isset( $_POST['list_id'] ) ? (int) metis_unslash( $_POST['list_id'] ) : 0;
    if ( $cid === '' || $list_id < 1 ) {
        metis_send_json_error( 'CID and list are required.', 400 );
    }

    $contact_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$contacts_table} WHERE cid = %s LIMIT 1", $cid ) );
    if ( $contact_id < 1 ) {
        metis_send_json_error( 'Contact not found.', 404 );
    }

    $list_name = (string) $wpdb->get_var( $wpdb->prepare(
        "SELECT name FROM {$newsletter_lists_table} WHERE id = %d AND name IS NOT NULL AND TRIM(name) <> '' LIMIT 1",
        $list_id
    ) );
    if ( $list_name === '' ) {
        metis_send_json_error( 'Invalid newsletter list.', 400 );
    }

    $exists = (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT id FROM {$newsletter_subs_table} WHERE contact_id = %d AND list_id = %d LIMIT 1",
        $contact_id,
        $list_id
    ) );
    if ( $exists < 1 ) {
        $ok = $wpdb->insert( $newsletter_subs_table, [ 'contact_id' => $contact_id, 'list_id' => $list_id ], [ '%d', '%d' ] );
        if ( $ok === false ) {
            metis_send_json_error( 'Failed to add newsletter subscription.', 500 );
        }
    }

    if ( function_exists( 'metis_contacts_carddav_fetch_contact' ) ) {
        $carddav_entry = metis_contacts_carddav_fetch_contact( $cid );
        if ( is_array( $carddav_entry ) ) {
            metis_contacts_carddav_log_change(
                $cid,
                'upsert',
                metis_contacts_carddav_book_slugs_for_contact( $carddav_entry['contact'], $carddav_entry['details'] ),
                metis_contacts_carddav_contact_etag( $carddav_entry['contact'], $carddav_entry['details'] )
            );
        }
    }

    metis_send_json_success( [ 'list_id' => $list_id, 'name' => $list_name ] );
} );

metis_add_action( 'wp_ajax_metis_contact_remove_newsletter', function () {
    metis_contacts_ajax_verify_nonce();

    if ( ! metis_contacts_can_manage() ) {
        metis_send_json_error( 'Unauthorized', 403 );
    }

    global $wpdb;
    $contacts_table = Metis_Tables::get( 'contacts' );
    $newsletter_subs_table = Metis_Tables::get( 'newsletter_subs' );

    $cid = isset( $_POST['cid'] ) ? sanitize_text_field( metis_unslash( $_POST['cid'] ) ) : '';
    $list_id = isset( $_POST['list_id'] ) ? (int) metis_unslash( $_POST['list_id'] ) : 0;
    if ( $cid === '' || $list_id < 1 ) {
        metis_send_json_error( 'CID and list are required.', 400 );
    }

    $contact_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$contacts_table} WHERE cid = %s LIMIT 1", $cid ) );
    if ( $contact_id < 1 ) {
        metis_send_json_error( 'Contact not found.', 404 );
    }

    $ok = $wpdb->delete( $newsletter_subs_table, [ 'contact_id' => $contact_id, 'list_id' => $list_id ], [ '%d', '%d' ] );
    if ( $ok === false ) {
        metis_send_json_error( 'Failed to remove newsletter subscription.', 500 );
    }

    if ( function_exists( 'metis_contacts_carddav_fetch_contact' ) ) {
        $carddav_entry = metis_contacts_carddav_fetch_contact( $cid );
        if ( is_array( $carddav_entry ) ) {
            metis_contacts_carddav_log_change(
                $cid,
                'upsert',
                metis_contacts_carddav_book_slugs_for_contact( $carddav_entry['contact'], $carddav_entry['details'] ),
                metis_contacts_carddav_contact_etag( $carddav_entry['contact'], $carddav_entry['details'] )
            );
        }
    }

    metis_send_json_success( [ 'list_id' => $list_id ] );
} );

metis_add_action( 'wp_ajax_metis_contact_remove_relationship', function () {

    metis_contacts_ajax_verify_nonce();

    if ( ! metis_contacts_can_manage() ) {
        metis_send_json_error( 'Unauthorized', 403 );
    }

    metis_contacts_ensure_schema();

    global $wpdb;
    $contacts_table = Metis_Tables::get( 'contacts' );
    $details_table  = Metis_Tables::get( 'contact_details' );

    $cid = isset( $_POST['cid'] ) ? sanitize_text_field( metis_unslash( $_POST['cid'] ) ) : '';
    $related_cid = isset( $_POST['related_cid'] ) ? sanitize_text_field( metis_unslash( $_POST['related_cid'] ) ) : '';
    $relation_type = isset( $_POST['relation_type'] ) ? sanitize_text_field( metis_unslash( $_POST['relation_type'] ) ) : '';
    $notes = isset( $_POST['notes'] ) ? sanitize_text_field( metis_unslash( $_POST['notes'] ) ) : '';

    if ( $cid === '' || $related_cid === '' || $relation_type === '' ) {
        metis_send_json_error( 'Missing relationship payload.', 400 );
    }

    $contact_row = $wpdb->get_row( $wpdb->prepare(
        "SELECT id, cid, did FROM {$contacts_table} WHERE cid = %s LIMIT 1",
        $cid
    ) );
    if ( ! $contact_row ) {
        metis_send_json_error( 'Contact not found.', 404 );
    }

    $id = (int) $contact_row->id;
    $did = (string) ( $contact_row->did ?? '' );
    $detail_rows = metis_contacts_collect_linked_detail_rows( $details_table, $cid, $id, $did );

    $removed = 0;
    foreach ( $detail_rows as $detail_row ) {
        if ( ! isset( $detail_row->id ) ) {
            continue;
        }
        $decoded = json_decode( (string) ( $detail_row->relationships_json ?? '[]' ), true );
        $decoded = is_array( $decoded ) ? $decoded : [];
        $normalized = metis_contacts_normalize_relationships( $decoded, $cid );
        $filtered = array_values( array_filter( $normalized, static function ( array $entry ) use ( $related_cid, $relation_type, $notes ): bool {
            return ! (
                (string) ( $entry['related_contact_cid'] ?? '' ) === $related_cid &&
                (string) ( $entry['relation_type'] ?? '' ) === $relation_type &&
                (string) ( $entry['notes'] ?? '' ) === $notes
            );
        } ) );

        if ( count( $filtered ) === count( $normalized ) ) {
            continue;
        }

        $payload = [ 'relationships_json' => metis_json_encode( $filtered ) ];
        $format = [ '%s' ];
        if ( metis_contacts_column_exists( $details_table, 'updated_at' ) ) {
            $payload['updated_at'] = current_time( 'mysql' );
            $format[] = '%s';
        }
        $ok = $wpdb->update(
            $details_table,
            $payload,
            [ 'id' => (int) $detail_row->id ],
            $format,
            [ '%d' ]
        );
        if ( $ok === false ) {
            metis_send_json_error( 'Failed to remove relationship.', 500 );
        }
        $removed++;
    }

    $other = $wpdb->get_row( $wpdb->prepare(
        "SELECT id, cid, did FROM {$contacts_table} WHERE cid = %s LIMIT 1",
        $related_cid
    ) );
    if ( $other ) {
        $other_rows = metis_contacts_collect_linked_detail_rows( $details_table, (string) $other->cid, (int) $other->id, (string) ( $other->did ?? '' ) );
        foreach ( $other_rows as $other_row ) {
            if ( ! isset( $other_row->id ) ) {
                continue;
            }
            $decoded = json_decode( (string) ( $other_row->relationships_json ?? '[]' ), true );
            $decoded = is_array( $decoded ) ? $decoded : [];
            $normalized = metis_contacts_normalize_relationships( $decoded, (string) $other->cid );
            $filtered = array_values( array_filter( $normalized, static function ( array $entry ) use ( $cid, $relation_type, $notes ): bool {
                return ! (
                    (string) ( $entry['related_contact_cid'] ?? '' ) === $cid &&
                    (string) ( $entry['relation_type'] ?? '' ) === $relation_type &&
                    (string) ( $entry['notes'] ?? '' ) === $notes
                );
            } ) );
            if ( count( $filtered ) === count( $normalized ) ) {
                continue;
            }
            $payload = [ 'relationships_json' => metis_json_encode( $filtered ) ];
            $format = [ '%s' ];
            if ( metis_contacts_column_exists( $details_table, 'updated_at' ) ) {
                $payload['updated_at'] = current_time( 'mysql' );
                $format[] = '%s';
            }
            $wpdb->update(
                $details_table,
                $payload,
                [ 'id' => (int) $other_row->id ],
                $format,
                [ '%d' ]
            );
        }
    }

    metis_send_json_success( [
        'removed' => $removed,
    ] );
} );

metis_add_action( 'wp_ajax_metis_contact_add_note', function () {

    metis_contacts_ajax_verify_nonce();

    if ( ! metis_contacts_can_manage() ) {
        metis_send_json_error( 'Unauthorized', 403 );
    }

    global $wpdb;

    $contacts_table = Metis_Tables::get( 'contacts' );
    $notes_table    = Metis_Tables::get( 'contact_notes' );

    $cid  = isset( $_POST['cid'] ) ? sanitize_text_field( metis_unslash( $_POST['cid'] ) ) : '';
    $note = isset( $_POST['note'] ) ? sanitize_textarea_field( metis_unslash( $_POST['note'] ) ) : '';

    if ( $cid === '' || $note === '' ) {
        metis_send_json_error( 'CID and note are required.', 400 );
    }

    $contact = $wpdb->get_row( $wpdb->prepare(
        "SELECT id, did FROM {$contacts_table} WHERE cid = %s LIMIT 1",
        $cid
    ) );
    if ( ! $contact ) {
        metis_send_json_error( 'Contact not found for CID.', 404 );
    }
    if ( ! metis_contacts_table_exists( $notes_table ) ) {
        metis_send_json_error( 'Notes table is unavailable.', 500 );
    }

    $payload = [ 'note' => $note ];
    $format  = [ '%s' ];

    if ( metis_contacts_column_exists( $notes_table, 'cid' ) ) {
        $payload['cid'] = $cid;
        $format[] = '%s';
    } elseif ( metis_contacts_column_exists( $notes_table, 'did' ) ) {
        $payload['did'] = (string) ( $contact->did ?? '' );
        $format[] = '%s';
    }
    if ( metis_contacts_column_exists( $notes_table, 'admin_user_id' ) ) {
        $payload['admin_user_id'] = metis_current_user_id();
        $format[] = '%d';
    }
    if ( metis_contacts_column_exists( $notes_table, 'created_at' ) ) {
        $payload['created_at'] = current_time( 'mysql' );
        $format[] = '%s';
    }

    $inserted = $wpdb->insert( $notes_table, $payload, $format );
    if ( ! $inserted ) {
        metis_send_json_error( 'Failed to save note.', 500 );
    }

    $author = 'System';
    $person_id = function_exists( 'metis_people_get_current_person_id' ) ? (int) metis_people_get_current_person_id() : 0;
    if ( $person_id > 0 && Metis_Tables::has( 'people' ) ) {
        $people_table = Metis_Tables::get( 'people' );
        $person_name = $wpdb->get_var(
            $wpdb->prepare( "SELECT display_name FROM {$people_table} WHERE id = %d LIMIT 1", $person_id )
        );
        if ( is_string( $person_name ) && trim( $person_name ) !== '' ) {
            $author = trim( $person_name );
        }
    }
    $when = metis_contacts_format_datetime( current_time( 'mysql' ), 'm/d/y g:ia' ) . ' H';

    metis_send_json_success( [
        'note' => $note,
        'author' => $author,
        'when' => $when,
    ] );
} );

metis_add_action( 'wp_ajax_metis_contacts_merge_duplicates', function () {
    metis_contacts_ajax_verify_nonce();

    if ( ! metis_contacts_can_manage() ) {
        metis_send_json_error( 'Unauthorized', 403 );
    }

    metis_contacts_ensure_schema();

    global $wpdb;
    $contacts_table = Metis_Tables::get( 'contacts' );
    $details_table  = Metis_Tables::get( 'contact_details' );
    $notes_table    = Metis_Tables::get( 'contact_notes' );
    $transactions_table = Metis_Tables::get( 'transactions' );

    $primary_cid = isset( $_POST['primary_cid'] ) ? sanitize_text_field( metis_unslash( $_POST['primary_cid'] ) ) : '';
    $duplicate_cids = [];
    if ( isset( $_POST['duplicate_cids'] ) ) {
        $decoded = json_decode( (string) metis_unslash( $_POST['duplicate_cids'] ), true );
        if ( is_array( $decoded ) ) {
            foreach ( $decoded as $item ) {
                $candidate = sanitize_text_field( (string) $item );
                if ( $candidate !== '' ) {
                    $duplicate_cids[] = $candidate;
                }
            }
        }
    }
    if ( empty( $duplicate_cids ) && isset( $_POST['duplicate_cid'] ) ) {
        $candidate = sanitize_text_field( metis_unslash( $_POST['duplicate_cid'] ) );
        if ( $candidate !== '' ) {
            $duplicate_cids[] = $candidate;
        }
    }
    $duplicate_cids = array_values( array_unique( array_filter( $duplicate_cids, static function ( $cid ) use ( $primary_cid ) {
        return is_string( $cid ) && $cid !== '' && $cid !== $primary_cid;
    } ) ) );
    if ( $primary_cid === '' || empty( $duplicate_cids ) ) {
        metis_send_json_error( 'Select a valid primary and duplicate contact(s).', 400 );
    }

    $load_detail = static function ( string $cid, int $id, string $did ) use ( $wpdb, $details_table ) {
        if ( ! metis_contacts_table_exists( $details_table ) ) return null;
        if ( metis_contacts_column_exists( $details_table, 'contact_cid' ) ) {
            $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$details_table} WHERE contact_cid = %s LIMIT 1", $cid ) );
            if ( $row ) return $row;
        }
        if ( metis_contacts_column_exists( $details_table, 'contact_id' ) ) {
            $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$details_table} WHERE contact_id = %d LIMIT 1", $id ) );
            if ( $row ) return $row;
        }
        if ( $did !== '' && metis_contacts_column_exists( $details_table, 'did' ) ) {
            return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$details_table} WHERE did = %s LIMIT 1", $did ) );
        }
        return null;
    };

    $decode_email_json = static function ( $json ) {
        $decoded = json_decode( (string) $json, true );
        return is_array( $decoded ) ? metis_contacts_normalize_additional_emails( $decoded ) : [];
    };

    $decode_rel_json = static function ( $json, string $self_cid ) {
        $decoded = json_decode( (string) $json, true );
        return is_array( $decoded ) ? metis_contacts_normalize_relationships( $decoded, $self_cid ) : [];
    };

    $rollback = static function ( string $message ) use ( $wpdb ) {
        $wpdb->query( 'ROLLBACK' );
        metis_send_json_error( $message, 500 );
    };

    $wpdb->query( 'START TRANSACTION' );

    $merged_records = [];
    $primary_id = 0;
    $primary_did = '';

    foreach ( $duplicate_cids as $duplicate_cid ) {
        $primary = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$contacts_table} WHERE cid = %s LIMIT 1", $primary_cid ) );
        $dup     = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$contacts_table} WHERE cid = %s LIMIT 1", $duplicate_cid ) );
        if ( ! $primary || ! $dup ) {
            $wpdb->query( 'ROLLBACK' );
            metis_send_json_error( 'One or more contacts no longer exist.', 404 );
        }

        $primary_id  = (int) $primary->id;
        $dup_id      = (int) $dup->id;
        $primary_did = (string) ( $primary->did ?? '' );
        $dup_did     = (string) ( $dup->did ?? '' );

        $primary_detail = $load_detail( $primary_cid, $primary_id, $primary_did );
        $dup_detail     = $load_detail( $duplicate_cid, $dup_id, $dup_did );

        $primary_emails = $primary_detail ? $decode_email_json( $primary_detail->additional_emails_json ?? '[]' ) : [];
        $dup_emails     = $dup_detail ? $decode_email_json( $dup_detail->additional_emails_json ?? '[]' ) : [];
        $merged_email_reconcile = metis_contacts_reconcile_primary_and_additional_emails(
            (string) ( $primary->email ?? '' ),
            array_merge(
                $primary_emails,
                $dup_emails,
                [ (string) ( $dup->email ?? '' ) ]
            )
        );
        $merged_emails = (array) $merged_email_reconcile['additional'];

        $primary_relationships = $primary_detail ? $decode_rel_json( $primary_detail->relationships_json ?? '[]', $primary_cid ) : [];
        $dup_relationships     = $dup_detail ? $decode_rel_json( $dup_detail->relationships_json ?? '[]', $duplicate_cid ) : [];
        foreach ( $dup_relationships as &$entry ) {
            if ( ( $entry['related_contact_cid'] ?? '' ) === $duplicate_cid ) {
                $entry['related_contact_cid'] = $primary_cid;
            }
        }
        unset( $entry );
        $merged_relationships = metis_contacts_normalize_relationships( array_merge( $primary_relationships, $dup_relationships ), $primary_cid );

        if ( $primary_did === '' && $dup_did !== '' ) {
            $primary_did = $dup_did;
        } elseif ( $primary_did !== '' && $dup_did !== '' && $primary_did !== $dup_did && metis_contacts_table_exists( $transactions_table ) ) {
            $tx_update = $wpdb->update(
                $transactions_table,
                [ 'did' => $primary_did ],
                [ 'did' => $dup_did ],
                [ '%s' ],
                [ '%s' ]
            );
            if ( $tx_update === false ) {
                $rollback( 'Failed to update donor transactions during merge.' );
            }
        }

        $primary_first = (string) ( $primary->first_name ?? '' );
        $primary_last  = (string) ( $primary->last_name ?? '' );
        $primary_email = (string) ( $primary->email ?? '' );
        if ( $primary_first === '' ) $primary_first = (string) ( $dup->first_name ?? '' );
        if ( $primary_last === '' )  $primary_last  = (string) ( $dup->last_name ?? '' );
        if ( $primary_email === '' ) $primary_email = (string) ( $dup->email ?? '' );

        $contact_update = $wpdb->update(
            $contacts_table,
            [
                'first_name' => $primary_first,
                'last_name'  => $primary_last,
                'email'      => strtolower( trim( $primary_email ) ),
                'did'        => $primary_did !== '' ? $primary_did : null,
            ],
            [ 'id' => $primary_id ],
            [ '%s', '%s', '%s', '%s' ],
            [ '%d' ]
        );
        if ( $contact_update === false ) {
            $rollback( 'Failed to update primary contact during merge.' );
        }

        if ( metis_contacts_table_exists( $details_table ) ) {
            $detail_payload = [
                'additional_emails_json' => metis_json_encode( $merged_emails ),
                'relationships_json'     => metis_json_encode( $merged_relationships ),
            ];
            $detail_format = [ '%s', '%s' ];
            if ( metis_contacts_column_exists( $details_table, 'contact_cid' ) ) {
                $detail_payload['contact_cid'] = $primary_cid;
                $detail_format[] = '%s';
            }
            if ( metis_contacts_column_exists( $details_table, 'contact_id' ) ) {
                $detail_payload['contact_id'] = $primary_id;
                $detail_format[] = '%d';
            }
            if ( metis_contacts_column_exists( $details_table, 'did' ) ) {
                $detail_payload['did'] = $primary_did !== '' ? $primary_did : null;
                $detail_format[] = '%s';
            }
            if ( metis_contacts_column_exists( $details_table, 'phone' ) ) {
                $detail_payload['phone'] = (string) ( $primary_detail->phone ?? $dup_detail->phone ?? '' ) ?: null;
                $detail_format[] = '%s';
            }
            if ( metis_contacts_column_exists( $details_table, 'preferred_name' ) ) {
                $detail_payload['preferred_name'] = (string) ( $primary_detail->preferred_name ?? $dup_detail->preferred_name ?? '' ) ?: null;
                $detail_format[] = '%s';
            }
            if ( metis_contacts_column_exists( $details_table, 'preferred_contact_method' ) ) {
                $detail_payload['preferred_contact_method'] = (string) ( $primary_detail->preferred_contact_method ?? $dup_detail->preferred_contact_method ?? '' ) ?: null;
                $detail_format[] = '%s';
            }

            if ( $primary_detail && isset( $primary_detail->id ) ) {
                $res = $wpdb->update( $details_table, $detail_payload, [ 'id' => (int) $primary_detail->id ], $detail_format, [ '%d' ] );
                if ( $res === false ) $rollback( 'Failed to update primary detail during merge.' );
            } else {
                $res = $wpdb->insert( $details_table, $detail_payload, $detail_format );
                if ( $res === false ) $rollback( 'Failed to create primary detail during merge.' );
            }

            if ( metis_contacts_column_exists( $details_table, 'relationships_json' ) ) {
                $like = '%' . $wpdb->esc_like( '"related_contact_cid":"' . $duplicate_cid . '"' ) . '%';
                $related_rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, relationships_json, contact_cid FROM {$details_table} WHERE relationships_json LIKE %s", $like ) ) ?: [];
                foreach ( $related_rows as $r ) {
                    $rels = $decode_rel_json( $r->relationships_json, (string) ( $r->contact_cid ?? '' ) );
                    $changed = false;
                    foreach ( $rels as &$rel_entry ) {
                        if ( ( $rel_entry['related_contact_cid'] ?? '' ) === $duplicate_cid ) {
                            $rel_entry['related_contact_cid'] = $primary_cid;
                            $changed = true;
                        }
                    }
                    unset( $rel_entry );
                    if ( ! $changed ) continue;
                    $rels = metis_contacts_normalize_relationships( $rels, (string) ( $r->contact_cid ?? '' ) );
                    $res = $wpdb->update( $details_table, [ 'relationships_json' => metis_json_encode( $rels ) ], [ 'id' => (int) $r->id ], [ '%s' ], [ '%d' ] );
                    if ( $res === false ) $rollback( 'Failed to normalize related relationships during merge.' );
                }
            }
        }

        if ( metis_contacts_table_exists( $notes_table ) ) {
            if ( metis_contacts_column_exists( $notes_table, 'cid' ) ) {
                $res = $wpdb->update( $notes_table, [ 'cid' => $primary_cid ], [ 'cid' => $duplicate_cid ], [ '%s' ], [ '%s' ] );
                if ( $res === false ) $rollback( 'Failed to remap notes by cid during merge.' );
            }
            if ( metis_contacts_column_exists( $notes_table, 'did' ) && $dup_did !== '' ) {
                $res = $wpdb->update(
                    $notes_table,
                    [ 'did' => $primary_did !== '' ? $primary_did : null ],
                    [ 'did' => $dup_did ],
                    [ '%s' ],
                    [ '%s' ]
                );
                if ( $res === false ) $rollback( 'Failed to remap notes by donor id during merge.' );
            }
            if ( metis_contacts_column_exists( $notes_table, 'contact_id' ) ) {
                $res = $wpdb->update( $notes_table, [ 'contact_id' => $primary_id ], [ 'contact_id' => $dup_id ], [ '%d' ], [ '%d' ] );
                if ( $res === false ) $rollback( 'Failed to remap notes by contact id during merge.' );
            }
        }

        if ( metis_contacts_table_exists( $details_table ) ) {
            if ( metis_contacts_column_exists( $details_table, 'contact_cid' ) ) {
                $res = $wpdb->delete( $details_table, [ 'contact_cid' => $duplicate_cid ], [ '%s' ] );
                if ( $res === false ) $rollback( 'Failed to remove duplicate detail row.' );
            } elseif ( metis_contacts_column_exists( $details_table, 'contact_id' ) ) {
                $res = $wpdb->delete( $details_table, [ 'contact_id' => $dup_id ], [ '%d' ] );
                if ( $res === false ) $rollback( 'Failed to remove duplicate detail row.' );
            }
        }

        $contact_delete = $wpdb->delete( $contacts_table, [ 'id' => $dup_id ], [ '%d' ] );
        if ( $contact_delete === false ) {
            $rollback( 'Failed to remove duplicate contact after merge.' );
        }

        $merged_records[] = [
            'cid' => $duplicate_cid,
            'did' => $dup_did,
        ];
    }

    $primary_after = $wpdb->get_row( $wpdb->prepare( "SELECT id, did FROM {$contacts_table} WHERE cid = %s LIMIT 1", $primary_cid ) );
    $primary_id = $primary_after ? (int) $primary_after->id : $primary_id;
    $primary_did = $primary_after ? (string) ( $primary_after->did ?? '' ) : $primary_did;

    $actor = metis_current_user();
    $actor_name = $actor && ! empty( $actor->display_name ) ? $actor->display_name : 'System';
    $merged_summary = implode(
        '; ',
        array_map(
            static function ( array $item ): string {
                return sprintf(
                    '%s (Donor ID %s)',
                    $item['cid'],
                    $item['did'] !== '' ? $item['did'] : 'none'
                );
            },
            $merged_records
        )
    );
    $system_note = sprintf(
        'System merge by %s: merged contacts %s into %s (Donor ID %s).',
        $actor_name,
        $merged_summary,
        $primary_cid,
        $primary_did !== '' ? $primary_did : 'none'
    );
    if ( metis_contacts_table_exists( $notes_table ) ) {
        $note_payload = [ 'note' => $system_note ];
        $note_format = [ '%s' ];
        if ( metis_contacts_column_exists( $notes_table, 'cid' ) ) {
            $note_payload['cid'] = $primary_cid;
            $note_format[] = '%s';
        }
        if ( metis_contacts_column_exists( $notes_table, 'admin_user_id' ) ) {
            $note_payload['admin_user_id'] = metis_current_user_id();
            $note_format[] = '%d';
        }
        if ( metis_contacts_column_exists( $notes_table, 'created_at' ) ) {
            $note_payload['created_at'] = current_time( 'mysql' );
            $note_format[] = '%s';
        }
        $res = $wpdb->insert( $notes_table, $note_payload, $note_format );
        if ( $res === false ) $rollback( 'Failed to create merge system note.' );
    }

    $wpdb->query( 'COMMIT' );

    metis_send_json_success( [
        'primary_cid' => $primary_cid,
        'merged_cids' => array_map( static function ( array $item ) {
            return $item['cid'];
        }, $merged_records ),
        'merged_count' => count( $merged_records ),
        'message'     => 'Contacts merged successfully.',
    ] );
} );

metis_add_action( 'wp_ajax_metis_contacts_cleanup_merge_notes', function () {
    metis_contacts_ajax_verify_nonce();

    if ( ! metis_contacts_can_manage() ) {
        metis_send_json_error( 'Unauthorized', 403 );
    }

    metis_contacts_ensure_schema();
    $result = metis_contacts_cleanup_merge_notes();

    metis_send_json_success( [
        'groups_consolidated' => (int) ( $result['groups_consolidated'] ?? 0 ),
        'notes_created'       => (int) ( $result['notes_created'] ?? 0 ),
        'notes_deleted'       => (int) ( $result['notes_deleted'] ?? 0 ),
    ] );
} );

Metis_Logger::info( 'Contacts AJAX handlers loaded' );
