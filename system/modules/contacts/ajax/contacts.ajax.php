<?php
if ( ! defined( 'METIS_ROOT' ) ) exit;

/**
 * Contacts AJAX Controller
 */

function metis_contacts_ajax_verify_nonce(): void {
    metis_check_ajax_referer( 'metis_contacts', 'nonce' );
}

if ( function_exists( 'metis_ajax_register_controller' ) ) {
    $metis_contacts_actions = [
        'metis_contacts_save',
        'metis_contact_detail_save',
        'metis_contact_inline_update',
        'metis_contact_remove_additional_email',
        'metis_contact_add_additional_email',
        'metis_contact_add_note',
    ];

    foreach ( $metis_contacts_actions as $action ) {
        metis_ajax_register_controller( $action, [
            'module' => 'contacts',
            'permission' => 'edit',
            'nonce_action' => metis_ajax_nonce_action( $action ),
        ] );
    }
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

function metis_contacts_quick_action_contact_form( array $action = [] ): array {
    unset( $action );

    $html = '<form class="metis-form-grid metis-quick-action-form" data-quick-action-form="contacts_add_contact">'
        . '<div class="metis-field metis-field-half"><label for="qa-contact-first-name">First Name</label><input id="qa-contact-first-name" name="first_name" class="metis-input" type="text" maxlength="120" required></div>'
        . '<div class="metis-field metis-field-half"><label for="qa-contact-last-name">Last Name</label><input id="qa-contact-last-name" name="last_name" class="metis-input" type="text" maxlength="120" required></div>'
        . '<div class="metis-field metis-field-full"><label for="qa-contact-email">Email <span class="metis-required">*</span></label><input id="qa-contact-email" name="email" class="metis-input" type="email" maxlength="180" required></div>'
        . '<div class="metis-field metis-field-full"><label for="qa-contact-phone">Phone</label><input id="qa-contact-phone" name="phone" class="metis-input" type="text" maxlength="50" placeholder="Optional"></div>'
        . '</form>';

    return [
        'title' => 'Add Contact',
        'html' => $html,
        'submit_action' => 'metis_contacts_save',
        'submit_nonce_action' => 'metis_contacts',
        'submit_label' => 'Save Contact',
        'success_message' => 'Contact created.',
        'redirect' => function_exists( 'metis_portal_url' ) ? (string) metis_portal_url( 'contacts' ) : '',
    ];
}

function metis_contacts_normalize_relationships( array $raw, string $self_cid ): array {
    $normalized = [];
    $seen = [];

    foreach ( $raw as $entry ) {
        if ( ! is_array( $entry ) ) {
            continue;
        }

        $related_cid = metis_text_clean( (string) ( $entry['related_contact_cid'] ?? $entry['related_contact_id'] ?? '' ) );
        $relation_type = metis_text_clean( (string) ( $entry['relation_type'] ?? '' ) );
        $notes = metis_text_clean( (string) ( $entry['notes'] ?? '' ) );

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
        return strtolower( trim( metis_email_clean( $value ) ) );
    };

    foreach ( $raw as $entry ) {
        $candidate = '';

        if ( is_string( $entry ) || is_numeric( $entry ) ) {
            $candidate = (string) $entry;
        } elseif ( is_array( $entry ) ) {
            $candidate = (string) ( $entry['email'] ?? '' );
        }

        $candidate = $normalize_value( (string) $candidate );
        if ( $candidate === '' || ! metis_email_is_valid( $candidate ) ) {
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
    $primary_email = strtolower( trim( metis_email_clean( $primary_email ) ) );

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

    $address = metis_text_clean( $address );
    $city = metis_text_clean( $city );
    $state = strtoupper( metis_text_clean( $state ) );
    $state = preg_replace( '/[^A-Z]/', '', (string) $state );
    if ( strlen( $state ) > 2 ) {
        $state = substr( $state, 0, 2 );
    }
    $zip = metis_text_clean( $zip );

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
    $db = metis_db();

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
        $found = $db->fetchAll( "SELECT * FROM {$details_table} WHERE contact_cid = %s", [ $cid ] );
        $add_rows( array_map( static fn( array $row ): object => (object) $row, is_array( $found ) ? $found : [] ) );
    }
    if ( metis_contacts_column_exists( $details_table, 'cid' ) ) {
        $found = $db->fetchAll( "SELECT * FROM {$details_table} WHERE cid = %s", [ $cid ] );
        $add_rows( array_map( static fn( array $row ): object => (object) $row, is_array( $found ) ? $found : [] ) );
    }
    if ( metis_contacts_column_exists( $details_table, 'contact_id' ) ) {
        $found = $db->fetchAll( "SELECT * FROM {$details_table} WHERE contact_id = %d", [ $id ] );
        $add_rows( array_map( static fn( array $row ): object => (object) $row, is_array( $found ) ? $found : [] ) );
    }
    if ( $did !== '' && metis_contacts_column_exists( $details_table, 'did' ) ) {
        $found = $db->fetchAll( "SELECT * FROM {$details_table} WHERE did = %s", [ $did ] );
        $add_rows( array_map( static fn( array $row ): object => (object) $row, is_array( $found ) ? $found : [] ) );
    }

    return $rows;
}


function metis_contacts_create_contact( array $input ): array {
    metis_contacts_ensure_schema();

    $db = metis_db();
    $contacts_table = Metis_Tables::get( 'contacts' );
    $details_table  = Metis_Tables::get( 'contact_details' );

    $first_name = isset( $input['first_name'] ) ? metis_text_clean( (string) $input['first_name'] ) : '';
    $last_name  = isset( $input['last_name'] ) ? metis_text_clean( (string) $input['last_name'] ) : '';
    $email      = isset( $input['email'] ) ? metis_email_clean( (string) $input['email'] ) : '';
    $phone      = isset( $input['phone'] ) ? metis_text_clean( (string) $input['phone'] ) : '';

    $email = strtolower( trim( $email ) );
    $phone = trim( $phone );

    if ( $first_name === '' || $last_name === '' ) {
        return [ 'success' => false, 'status' => 400, 'message' => 'First name and last name are required.' ];
    }

    if ( ! metis_email_is_valid( $email ) ) {
        return [ 'success' => false, 'status' => 400, 'message' => 'A valid email is required.' ];
    }

    $existing_email_id = (int) $db->scalar(
        "SELECT id FROM {$contacts_table} WHERE email = %s LIMIT 1",
        [ $email ]
    );
    if ( $existing_email_id > 0 ) {
        return [ 'success' => false, 'status' => 400, 'message' => 'That email is already assigned to another contact.' ];
    }

    $contact_payload = [
        'first_name' => $first_name,
        'last_name'  => $last_name,
        'email'      => $email,
    ];

    if ( function_exists( 'metis_entity_id_service' ) ) {
        $contact_payload = metis_entity_id_service()->assignForInsert( 'contact', $contact_payload );
    } else {
        $contact_payload['cid'] = metis_generate_code( 'CN', $contacts_table, 'cid' );
    }

    $inserted = $db->insert(
        $contacts_table,
        $contact_payload,
        [ '%s', '%s', '%s', '%s', '%s' ]
    );

    if ( ! $inserted ) {
        return [ 'success' => false, 'status' => 500, 'message' => 'Failed to create contact.' ];
    }

    $id = (int) $db->lastInsertId();
    $cid = (string) ( $contact_payload['contact_uid'] ?? $contact_payload['cid'] ?? '' );

    if ( $id > 0 && function_exists( 'metis_entity_id_service' ) ) {
        metis_entity_id_service()->register( 'contact', $id, $cid );
    }

    if ( metis_contacts_table_exists( $details_table ) ) {
        $details_payload = [];
        $details_format  = [];

        if ( metis_contacts_column_exists( $details_table, 'contact_cid' ) ) {
            $details_payload['contact_cid'] = $cid;
            $details_format[] = '%s';
        }
        if ( metis_contacts_column_exists( $details_table, 'cid' ) ) {
            $details_payload['cid'] = $cid;
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

        if ( ! empty( $details_payload ) ) {
            $db->insert( $details_table, $details_payload, $details_format );
        }
    }

    $row_data = $db->fetchOne( "SELECT * FROM {$contacts_table} WHERE id = %d", [ $id ] );
    $row = is_array( $row_data ) ? (object) $row_data : null;

    $details = null;
    if ( metis_contacts_table_exists( $details_table ) ) {
        $detail_rows = metis_contacts_collect_linked_detail_rows( $details_table, $cid, $id, '' );
        $details = $detail_rows[0] ?? null;
    }

    if ( ! $row ) {
        return [ 'success' => false, 'status' => 500, 'message' => 'Contact saved, but response data is unavailable.' ];
    }

    return [
        'success' => true,
        'status' => 200,
        'message' => 'Contact created successfully.',
        'contact' => metis_contacts_ajax_format_row( $row, $details ),
    ];
}

metis_ajax_register_handler( 'metis_contacts_save', function () {

    metis_contacts_ajax_verify_nonce();

    if ( ! metis_contacts_can_manage() ) {
        metis_runtime_send_json_error( 'Unauthorized', 403 );
    }

    $result = metis_contacts_create_contact( [
        'first_name' => (string) ( isset( metis_request_post()['first_name'] ) ? metis_runtime_unslash( metis_request_post()['first_name'] ) : '' ),
        'last_name' => (string) ( isset( metis_request_post()['last_name'] ) ? metis_runtime_unslash( metis_request_post()['last_name'] ) : '' ),
        'email' => (string) ( isset( metis_request_post()['email'] ) ? metis_runtime_unslash( metis_request_post()['email'] ) : '' ),
        'phone' => (string) ( isset( metis_request_post()['phone'] ) ? metis_runtime_unslash( metis_request_post()['phone'] ) : '' ),
    ] );

    if ( empty( $result['success'] ) ) {
        $status = (int) ( $result['status'] ?? 500 );
        $status = in_array( $status, [ 400, 401, 403, 404, 409, 422, 429 ], true ) ? $status : 500;
        metis_runtime_send_json_error( 'Failed to create contact.', $status );
    }

    metis_runtime_send_json_success( [
        'contact' => (array) ( $result['contact'] ?? [] ),
    ] );
} );
metis_ajax_register_handler( 'metis_contact_detail_save', function () {

    metis_contacts_ajax_verify_nonce();

    if ( ! metis_contacts_can_manage() ) {
        metis_runtime_send_json_error( 'Unauthorized', 403 );
    }

    metis_contacts_ensure_schema();

    $db = metis_db();

    $contacts_table = Metis_Tables::get( 'contacts' );
    $details_table  = Metis_Tables::get( 'contact_details' );
    $newsletter_subs_table = Metis_Tables::get( 'newsletter_subs' );
    $newsletter_lists_table = Metis_Tables::get( 'newsletter_lists' );

    $cid   = isset( metis_request_post()['cid'] ) ? metis_text_clean( metis_runtime_unslash( metis_request_post()['cid'] ) ) : '';
    $first = isset( metis_request_post()['first_name'] ) ? metis_text_clean( metis_runtime_unslash( metis_request_post()['first_name'] ) ) : '';
    $last  = isset( metis_request_post()['last_name'] ) ? metis_text_clean( metis_runtime_unslash( metis_request_post()['last_name'] ) ) : '';
    $email = isset( metis_request_post()['email'] ) ? metis_email_clean( metis_runtime_unslash( metis_request_post()['email'] ) ) : '';
    $phone = isset( metis_request_post()['phone'] ) ? metis_text_clean( metis_runtime_unslash( metis_request_post()['phone'] ) ) : '';
    $preferred_name = isset( metis_request_post()['preferred_name'] ) ? metis_text_clean( metis_runtime_unslash( metis_request_post()['preferred_name'] ) ) : '';
    $preferred_contact = isset( metis_request_post()['preferred_contact_method'] ) ? metis_text_clean( metis_runtime_unslash( metis_request_post()['preferred_contact_method'] ) ) : '';
    $address = isset( metis_request_post()['address'] ) ? metis_text_clean( metis_runtime_unslash( metis_request_post()['address'] ) ) : '';
    $city = isset( metis_request_post()['city'] ) ? metis_text_clean( metis_runtime_unslash( metis_request_post()['city'] ) ) : '';
    $state = isset( metis_request_post()['state'] ) ? metis_text_clean( metis_runtime_unslash( metis_request_post()['state'] ) ) : '';
    $zip = isset( metis_request_post()['zip'] ) ? metis_text_clean( metis_runtime_unslash( metis_request_post()['zip'] ) ) : '';
    $birthday = isset( metis_request_post()['birthday'] ) ? metis_text_clean( metis_runtime_unslash( metis_request_post()['birthday'] ) ) : '';
    $spouse_name = isset( metis_request_post()['spouse_name'] ) ? metis_text_clean( metis_runtime_unslash( metis_request_post()['spouse_name'] ) ) : null;
    $household_id = isset( metis_request_post()['household_id'] ) ? metis_text_clean( metis_runtime_unslash( metis_request_post()['household_id'] ) ) : '';
    $source_code = isset( metis_request_post()['source_code'] ) ? metis_text_clean( metis_runtime_unslash( metis_request_post()['source_code'] ) ) : null;
    $first_contacted = isset( metis_request_post()['first_contacted'] ) ? metis_text_clean( metis_runtime_unslash( metis_request_post()['first_contacted'] ) ) : null;
    $staff_owner = isset( metis_request_post()['staff_owner'] ) ? metis_text_clean( metis_runtime_unslash( metis_request_post()['staff_owner'] ) ) : null;
    $do_not_contact = isset( metis_request_post()['do_not_contact'] ) ? ( ! empty( metis_runtime_unslash( metis_request_post()['do_not_contact'] ) ) ? 1 : 0 ) : 0;
    $volunteer_status = isset( metis_request_post()['volunteer_status'] ) ? ( ! empty( metis_runtime_unslash( metis_request_post()['volunteer_status'] ) ) ? 1 : 0 ) : 0;
    $anonymous_donor = isset( metis_request_post()['anonymous_donor'] ) ? ( ! empty( metis_runtime_unslash( metis_request_post()['anonymous_donor'] ) ) ? 1 : 0 ) : 0;
    $relationships_json = isset( metis_request_post()['relationships_json'] ) ? metis_runtime_unslash( metis_request_post()['relationships_json'] ) : '[]';
    $additional_emails_json = isset( metis_request_post()['additional_emails_json'] ) ? metis_runtime_unslash( metis_request_post()['additional_emails_json'] ) : '[]';
    $newsletter_list_ids = [];
    if ( isset( metis_request_post()['newsletter_list_ids'] ) ) {
        $decoded_newsletters = json_decode( (string) metis_runtime_unslash( metis_request_post()['newsletter_list_ids'] ), true );
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
        metis_runtime_send_json_error( 'Birthday must use YYYY-MM-DD format.', 400 );
    }
    if ( null !== $first_contacted && $first_contacted !== '' ) {
        $first_contacted_ts = strtotime( $first_contacted );
        if ( $first_contacted_ts === false ) {
            metis_runtime_send_json_error( 'First contacted must be a valid date/time.', 400 );
        }
        $first_contacted = metis_runtime_date( 'Y-m-d H:i:s', $first_contacted_ts, metis_runtime_timezone() );
    }

    if ( $cid === '' || $first === '' || $last === '' || ! metis_email_is_valid( $email ) ) {
        metis_runtime_send_json_error( 'Invalid contact payload.', 400 );
    }

    $contact_row_data = $db->fetchOne(
        "SELECT id, did, email, first_name, last_name FROM {$contacts_table} WHERE cid = %s LIMIT 1",
        [ $cid ]
    );
    $contact_row = is_array( $contact_row_data ) ? (object) $contact_row_data : null;
    $id = $contact_row ? (int) $contact_row->id : 0;
    $contact_did = $contact_row ? (string) ( $contact_row->did ?? '' ) : '';
    $previous_primary_email = $contact_row ? strtolower( trim( (string) ( $contact_row->email ?? '' ) ) ) : '';
    if ( $id < 1 ) {
        metis_runtime_send_json_error( 'Contact not found for CID.', 404 );
    }

    $email_conflict = (int) $db->scalar(
        "SELECT id FROM {$contacts_table} WHERE email = %s AND id <> %d LIMIT 1",
        [ $email, $id ]
    );
    if ( $email_conflict > 0 ) {
        metis_runtime_send_json_error( 'Email already exists on another contact.', 400 );
    }

    $decoded_relationships = json_decode( $relationships_json, true );
    if ( json_last_error() !== JSON_ERROR_NONE ) {
        metis_runtime_send_json_error( 'JSON fields must be valid JSON arrays.', 400 );
    }
    $decoded_additional = json_decode( $additional_emails_json, true );
    if ( json_last_error() !== JSON_ERROR_NONE ) {
        metis_runtime_send_json_error( 'JSON fields must be valid JSON arrays.', 400 );
    }
    if ( ! is_array( $decoded_relationships ) || ! is_array( $decoded_additional ) ) {
        metis_runtime_send_json_error( 'JSON fields must be arrays.', 400 );
    }

    $decoded_relationships = metis_contacts_normalize_relationships( $decoded_relationships, $cid );
    $reconciled_emails = metis_contacts_reconcile_primary_and_additional_emails( $email, $decoded_additional );
    $email = (string) $reconciled_emails['primary_email'];
    $decoded_additional = (array) $reconciled_emails['additional'];
    if ( $previous_primary_email !== '' && $previous_primary_email !== $email && metis_email_is_valid( $previous_primary_email ) ) {
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
        $contact_payload['updated_at'] = metis_current_time( 'mysql' );
        $contact_format[] = '%s';
    }
    $contact_update = $db->update(
        $contacts_table,
        $contact_payload,
        [ 'id' => $id ],
        $contact_format,
        [ '%d' ]
    );
    if ( $contact_update === false ) {
        metis_runtime_send_json_error( 'Failed to update contact record.', 500 );
    }

    $existing_detail = null;
    if ( metis_contacts_table_exists( $details_table ) ) {
        if ( metis_contacts_column_exists( $details_table, 'contact_cid' ) ) {
            $existing_detail_data = $db->fetchOne(
                "SELECT * FROM {$details_table} WHERE contact_cid = %s LIMIT 1",
                [ $cid ]
            );
            $existing_detail = is_array( $existing_detail_data ) ? (object) $existing_detail_data : null;
        }
        if ( ! $existing_detail && metis_contacts_column_exists( $details_table, 'cid' ) ) {
            $existing_detail_data = $db->fetchOne(
                "SELECT * FROM {$details_table} WHERE cid = %s LIMIT 1",
                [ $cid ]
            );
            $existing_detail = is_array( $existing_detail_data ) ? (object) $existing_detail_data : null;
        }
        if ( ! $existing_detail && metis_contacts_column_exists( $details_table, 'contact_id' ) ) {
            $existing_detail_data = $db->fetchOne(
                "SELECT * FROM {$details_table} WHERE contact_id = %d LIMIT 1",
                [ $id ]
            );
            $existing_detail = is_array( $existing_detail_data ) ? (object) $existing_detail_data : null;
        }
        if ( ! $existing_detail && $contact_did !== '' && metis_contacts_column_exists( $details_table, 'did' ) ) {
            $existing_detail_data = $db->fetchOne(
                "SELECT * FROM {$details_table} WHERE did = %s LIMIT 1",
                [ $contact_did ]
            );
            $existing_detail = is_array( $existing_detail_data ) ? (object) $existing_detail_data : null;
        }
        $details_payload = [];
        $details_format  = [];

        if ( metis_contacts_column_exists( $details_table, 'contact_cid' ) ) {
            $details_payload['contact_cid'] = $cid;
            $details_format[] = '%s';
        }
        if ( metis_contacts_column_exists( $details_table, 'cid' ) ) {
            $details_payload['cid'] = $cid;
            $details_format[] = '%s';
        }
        if ( metis_contacts_column_exists( $details_table, 'contact_id' ) ) {
            $details_payload['contact_id'] = $id;
            $details_format[] = '%d';
        }
        if ( metis_contacts_column_exists( $details_table, 'did' ) ) {
            $details_payload['did'] = $contact_did !== '' ? $contact_did : '';
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
            $details_payload['updated_at'] = metis_current_time( 'mysql' );
            $details_format[] = '%s';
        }

        if ( ! $existing_detail ) {
            $db->insert( $details_table, $details_payload, $details_format );
        } else {
            $update_payload = $details_payload;
            $update_format = $details_format;
            if ( ! empty( $update_payload ) ) {
                if ( isset( $existing_detail->id ) ) {
                    $db->update(
                        $details_table,
                        $update_payload,
                        [ 'id' => (int) $existing_detail->id ],
                        $update_format,
                        [ '%d' ]
                    );
                } elseif ( metis_contacts_column_exists( $details_table, 'contact_cid' ) ) {
                    $db->update(
                        $details_table,
                        $update_payload,
                        [ 'contact_cid' => $cid ],
                        $update_format,
                        [ '%s' ]
                    );
                } elseif ( metis_contacts_column_exists( $details_table, 'cid' ) ) {
                    $db->update(
                        $details_table,
                        $update_payload,
                        [ 'cid' => $cid ],
                        $update_format,
                        [ '%s' ]
                    );
                } else {
                    $db->update(
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
            $sync_payload['updated_at'] = metis_current_time( 'mysql' );
            $sync_format[] = '%s';
        }
        if ( ! empty( $sync_payload ) ) {
            if ( metis_contacts_column_exists( $details_table, 'contact_cid' ) ) {
                $res = $db->update( $details_table, $sync_payload, [ 'contact_cid' => $cid ], $sync_format, [ '%s' ] );
                if ( $res === false ) {
                    metis_runtime_send_json_error( 'Failed to sync details by contact CID.', 500 );
                }
            }
            if ( metis_contacts_column_exists( $details_table, 'cid' ) ) {
                $res = $db->update( $details_table, $sync_payload, [ 'cid' => $cid ], $sync_format, [ '%s' ] );
                if ( $res === false ) {
                    metis_runtime_send_json_error( 'Failed to sync details by legacy CID.', 500 );
                }
            }
            if ( metis_contacts_column_exists( $details_table, 'contact_id' ) ) {
                $res = $db->update( $details_table, $sync_payload, [ 'contact_id' => $id ], $sync_format, [ '%d' ] );
                if ( $res === false ) {
                    metis_runtime_send_json_error( 'Failed to sync details by contact ID.', 500 );
                }
            }
            if ( $contact_did !== '' && metis_contacts_column_exists( $details_table, 'did' ) ) {
                $res = $db->update( $details_table, $sync_payload, [ 'did' => $contact_did ], $sync_format, [ '%s' ] );
                if ( $res === false ) {
                    metis_runtime_send_json_error( 'Failed to sync details by donor ID.', 500 );
                }
            }
        }
    }

    // Keep relationship visibility in sync on the related contact records.
    if ( metis_contacts_table_exists( $details_table ) &&
         ( metis_contacts_column_exists( $details_table, 'contact_id' ) || metis_contacts_column_exists( $details_table, 'contact_cid' ) || metis_contacts_column_exists( $details_table, 'cid' ) ) &&
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

            $related_contact_data = $db->fetchOne(
                "SELECT id, cid FROM {$contacts_table} WHERE cid = %s LIMIT 1",
                [ $related_cid ]
            );
            $related_contact = is_array( $related_contact_data ) ? (object) $related_contact_data : null;
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
                $related_detail_data = $db->fetchOne(
                    "SELECT * FROM {$details_table} WHERE contact_cid = %s LIMIT 1",
                    [ $related_contact_cid ]
                );
                $related_detail = is_array( $related_detail_data ) ? (object) $related_detail_data : null;
            }
            if ( ! $related_detail && metis_contacts_column_exists( $details_table, 'cid' ) ) {
                $related_detail_data = $db->fetchOne(
                    "SELECT * FROM {$details_table} WHERE cid = %s LIMIT 1",
                    [ $related_contact_cid ]
                );
                $related_detail = is_array( $related_detail_data ) ? (object) $related_detail_data : null;
            }
            if ( ! $related_detail && metis_contacts_column_exists( $details_table, 'contact_id' ) ) {
                $related_detail_data = $db->fetchOne(
                    "SELECT * FROM {$details_table} WHERE contact_id = %d LIMIT 1",
                    [ $related_id ]
                );
                $related_detail = is_array( $related_detail_data ) ? (object) $related_detail_data : null;
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
                    $db->update(
                        $details_table,
                        [ 'relationships_json' => $encoded ],
                        [ 'id' => (int) $related_detail->id ],
                        [ '%s' ],
                        [ '%d' ]
                    );
                } elseif ( metis_contacts_column_exists( $details_table, 'contact_cid' ) ) {
                    $db->update(
                        $details_table,
                        [ 'relationships_json' => $encoded ],
                        [ 'contact_cid' => $related_contact_cid ],
                        [ '%s' ],
                        [ '%s' ]
                    );
                } elseif ( metis_contacts_column_exists( $details_table, 'cid' ) ) {
                    $db->update(
                        $details_table,
                        [ 'relationships_json' => $encoded ],
                        [ 'cid' => $related_contact_cid ],
                        [ '%s' ],
                        [ '%s' ]
                    );
                } else {
                    $db->update(
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
                if ( metis_contacts_column_exists( $details_table, 'cid' ) ) {
                    $insert_payload['cid'] = $related_contact_cid;
                    $insert_format[] = '%s';
                }
                if ( metis_contacts_column_exists( $details_table, 'contact_id' ) ) {
                    $insert_payload['contact_id'] = $related_id;
                    $insert_format[] = '%d';
                }
                $db->insert( $details_table, $insert_payload, $insert_format );
            }
        }
    }

    if ( metis_contacts_table_exists( $newsletter_subs_table ) && metis_contacts_table_exists( $newsletter_lists_table ) ) {
        $valid_list_ids = [];
        if ( ! empty( $newsletter_list_ids ) ) {
            $placeholders = implode( ',', array_fill( 0, count( $newsletter_list_ids ), '%d' ) );
            $valid_list_ids = $db->column(
                "SELECT id FROM {$newsletter_lists_table} WHERE id IN ({$placeholders})",
                $newsletter_list_ids
            ) ?: [];
            $valid_list_ids = array_map( 'intval', $valid_list_ids );
        }

        $delete_ok = $db->delete( $newsletter_subs_table, [ 'contact_id' => $id ], [ '%d' ] );
        if ( $delete_ok === false ) {
            metis_runtime_send_json_error( 'Failed to update newsletter subscriptions.', 500 );
        }
        foreach ( $valid_list_ids as $list_id ) {
            $ins = $db->insert(
                $newsletter_subs_table,
                [ 'contact_id' => $id, 'list_id' => $list_id ],
                [ '%d', '%d' ]
            );
            if ( $ins === false ) {
                metis_runtime_send_json_error( 'Failed to save newsletter subscription.', 500 );
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

    metis_runtime_send_json_success( [ 'cid' => $cid ] );
} );

metis_ajax_register_handler( 'metis_contact_inline_update', function () {

    metis_contacts_ajax_verify_nonce();

    if ( ! metis_contacts_can_manage() ) {
        metis_runtime_send_json_error( 'Unauthorized', 403 );
    }

    metis_contacts_ensure_schema();

    $db = metis_db();

    $contacts_table = Metis_Tables::get( 'contacts' );
    $details_table  = Metis_Tables::get( 'contact_details' );

    $cid = isset( metis_request_post()['cid'] ) ? metis_text_clean( metis_runtime_unslash( metis_request_post()['cid'] ) ) : '';
    $field = isset( metis_request_post()['field'] ) ? metis_key_clean( metis_runtime_unslash( metis_request_post()['field'] ) ) : '';
    $value = isset( metis_request_post()['value'] ) ? metis_runtime_unslash( metis_request_post()['value'] ) : '';

    if ( $cid === '' ) {
        metis_runtime_send_json_error( 'Invalid inline update payload.', 400 );
    }

    $contact_row_data = $db->fetchOne(
        "SELECT id, cid, did, email, first_name, last_name FROM {$contacts_table} WHERE cid = %s LIMIT 1",
        [ $cid ]
    );
    $contact_row = is_array( $contact_row_data ) ? (object) $contact_row_data : null;
    if ( ! $contact_row ) {
        metis_runtime_send_json_error( 'Contact not found.', 404 );
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
                    $insert_payload['did'] = $did !== '' ? $did : '';
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
                    $insert_payload['updated_at'] = metis_current_time( 'mysql' );
                    $insert_format[] = '%s';
                }
                if ( ! empty( $insert_payload ) ) {
                    $inserted = $db->insert( $details_table, $insert_payload, $insert_format );
                    if ( $inserted === false ) {
                        metis_runtime_send_json_error( 'Failed to create details row.', 500 );
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
                        $patch_payload['updated_at'] = metis_current_time( 'mysql' );
                        $patch_format[] = '%s';
                    }
                    if ( empty( $patch_payload ) ) {
                        continue;
                    }
                    $update_ok = $db->update(
                        $details_table,
                        $patch_payload,
                        [ 'id' => (int) $detail_row->id ],
                        $patch_format,
                        [ '%d' ]
                    );
                    if ( $update_ok === false ) {
                        metis_runtime_send_json_error( 'Failed to update address.', 500 );
                    }
                }
            }
        }

        if ( metis_contacts_column_exists( $contacts_table, 'updated_at' ) ) {
            $ok = $db->update(
                $contacts_table,
                [ 'updated_at' => metis_current_time( 'mysql' ) ],
                [ 'id' => $id ],
                [ '%s' ],
                [ '%d' ]
            );
            if ( $ok === false ) {
                metis_runtime_send_json_error( 'Failed to touch contact update time.', 500 );
            }
        }

        metis_runtime_send_json_success( [
            'cid'            => $cid,
            'field'          => $field,
            'value'          => (string) $parsed['value'],
            'address_line_1' => (string) $parsed['line_1'],
            'address_line_2' => (string) $parsed['line_2'],
        ] );
    }

    if ( $field === 'email' ) {
        $next_email = strtolower( trim( metis_email_clean( (string) $value ) ) );
        if ( ! metis_email_is_valid( $next_email ) ) {
            metis_runtime_send_json_error( 'Please enter a valid email address.', 400 );
        }

        $email_conflict = (int) $db->scalar(
            "SELECT id FROM {$contacts_table} WHERE email = %s AND id <> %d LIMIT 1",
            [ $next_email, $id ]
        );
        if ( $email_conflict > 0 ) {
            metis_runtime_send_json_error( 'Email already exists on another contact.', 400 );
        }

        $contact_payload = [ 'email' => $next_email ];
        $contact_format = [ '%s' ];
        if ( metis_contacts_column_exists( $contacts_table, 'updated_at' ) ) {
            $contact_payload['updated_at'] = metis_current_time( 'mysql' );
            $contact_format[] = '%s';
        }
        $res = $db->update( $contacts_table, $contact_payload, [ 'id' => $id ], $contact_format, [ '%d' ] );
        if ( $res === false ) {
            metis_runtime_send_json_error( 'Failed to update primary email.', 500 );
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
                    $insert_payload['did'] = $did !== '' ? $did : '';
                    $insert_format[] = '%s';
                }
                if ( metis_contacts_column_exists( $details_table, 'additional_emails_json' ) ) {
                    $insert_payload['additional_emails_json'] = metis_json_encode( $additional );
                    $insert_format[] = '%s';
                }
                if ( metis_contacts_column_exists( $details_table, 'updated_at' ) ) {
                    $insert_payload['updated_at'] = metis_current_time( 'mysql' );
                    $insert_format[] = '%s';
                }
                if ( ! empty( $insert_payload ) ) {
                    $db->insert( $details_table, $insert_payload, $insert_format );
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
                        $patch_payload['updated_at'] = metis_current_time( 'mysql' );
                        $patch_format[] = '%s';
                    }
                    if ( empty( $patch_payload ) ) {
                        continue;
                    }
                    $update_ok = $db->update(
                        $details_table,
                        $patch_payload,
                        [ 'id' => (int) $detail_row->id ],
                        $patch_format,
                        [ '%d' ]
                    );
                    if ( $update_ok === false ) {
                        metis_runtime_send_json_error( 'Failed to sync additional emails.', 500 );
                    }
                }
            }
        }

        metis_runtime_send_json_success( [
            'cid' => $cid,
            'field' => 'email',
            'value' => $next_email,
            'additional_emails' => $additional,
        ] );
    }

    if ( isset( $contact_fields[ $field ] ) ) {
        $sanitized = metis_text_clean( (string) $value );
        if ( $field === 'full_name' ) {
            $clean = preg_replace( '/\s+/', ' ', trim( $sanitized ) );
            if ( ! is_string( $clean ) || $clean === '' ) {
                metis_runtime_send_json_error( 'Name cannot be empty.', 400 );
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
                metis_runtime_send_json_error( 'Name needs at least a first name.', 400 );
            }
            $contact_payload = [ 'first_name' => $first_part, 'last_name' => $last_part ];
            $contact_format = [ '%s', '%s' ];
            if ( metis_contacts_column_exists( $contacts_table, 'updated_at' ) ) {
                $contact_payload['updated_at'] = metis_current_time( 'mysql' );
                $contact_format[] = '%s';
            }
            $ok = $db->update( $contacts_table, $contact_payload, [ 'id' => $id ], $contact_format, [ '%d' ] );
            if ( $ok === false ) {
                metis_runtime_send_json_error( 'Failed to update full name.', 500 );
            }
            metis_runtime_send_json_success( [
                'cid'   => $cid,
                'field' => $field,
                'value' => trim( $first_part . ' ' . $last_part ),
                'first_name' => $first_part,
                'last_name' => $last_part,
            ] );
        }
        if ( in_array( $field, [ 'first_name', 'last_name' ], true ) && $sanitized === '' ) {
            metis_runtime_send_json_error( 'Name fields cannot be empty.', 400 );
        }

        $contact_payload = [ $field => $sanitized ];
        $contact_format = [ $contact_fields[ $field ] ];
        if ( metis_contacts_column_exists( $contacts_table, 'updated_at' ) ) {
            $contact_payload['updated_at'] = metis_current_time( 'mysql' );
            $contact_format[] = '%s';
        }
        $ok = $db->update( $contacts_table, $contact_payload, [ 'id' => $id ], $contact_format, [ '%d' ] );
        if ( $ok === false ) {
            metis_runtime_send_json_error( 'Failed to update contact field.', 500 );
        }

        metis_runtime_send_json_success( [
            'cid'   => $cid,
            'field' => $field,
            'value' => $sanitized,
        ] );
    }

    if ( ! isset( $detail_fields[ $field ] ) ) {
        metis_runtime_send_json_error( 'Inline field is not supported.', 400 );
    }

    $sanitized_value = '';
    if ( in_array( $field, [ 'do_not_contact', 'volunteer_status', 'anonymous_donor' ], true ) ) {
        $sanitized_value = (string) ( ! empty( $value ) && (string) $value !== '0' ? 1 : 0 );
    } elseif ( $field === 'birthday' ) {
        $candidate = metis_text_clean( (string) $value );
        $candidate = trim( $candidate );
        if ( $candidate !== '' && ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $candidate ) ) {
            metis_runtime_send_json_error( 'Birthday must use YYYY-MM-DD format.', 400 );
        }
        $sanitized_value = $candidate;
    } elseif ( $field === 'first_contacted' ) {
        $candidate = trim( metis_text_clean( (string) $value ) );
        if ( $candidate !== '' ) {
            $ts = strtotime( $candidate );
            if ( $ts === false ) {
                metis_runtime_send_json_error( 'First contacted must be a valid date/time.', 400 );
            }
            $candidate = metis_runtime_date( 'Y-m-d H:i:s', $ts, metis_runtime_timezone() );
        }
        $sanitized_value = $candidate;
    } elseif ( $field === 'phone' ) {
        $sanitized_value = metis_contacts_format_phone_us( metis_text_clean( (string) $value ) );
    } else {
        $sanitized_value = metis_text_clean( (string) $value );
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
                $insert_payload['did'] = $did !== '' ? $did : '';
                $insert_format[] = '%s';
            }
            if ( metis_contacts_column_exists( $details_table, $field ) ) {
                $insert_payload[ $field ] = $sanitized_value !== '' ? $sanitized_value : null;
                $insert_format[] = $detail_fields[ $field ];
            }
            if ( metis_contacts_column_exists( $details_table, 'updated_at' ) ) {
                $insert_payload['updated_at'] = metis_current_time( 'mysql' );
                $insert_format[] = '%s';
            }
            if ( ! empty( $insert_payload ) ) {
                $inserted = $db->insert( $details_table, $insert_payload, $insert_format );
                if ( $inserted === false ) {
                    metis_runtime_send_json_error( 'Failed to create details row.', 500 );
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
                    $patch_payload['updated_at'] = metis_current_time( 'mysql' );
                    $patch_format[] = '%s';
                }
                if ( empty( $patch_payload ) ) {
                    continue;
                }
                $update_ok = $db->update(
                    $details_table,
                    $patch_payload,
                    [ 'id' => (int) $detail_row->id ],
                    $patch_format,
                    [ '%d' ]
                );
                if ( $update_ok === false ) {
                    metis_runtime_send_json_error( 'Failed to update detail field.', 500 );
                }
            }
        }
    }

    if ( metis_contacts_column_exists( $contacts_table, 'updated_at' ) ) {
        $ok = $db->update(
            $contacts_table,
            [ 'updated_at' => metis_current_time( 'mysql' ) ],
            [ 'id' => $id ],
            [ '%s' ],
            [ '%d' ]
        );
        if ( $ok === false ) {
            metis_runtime_send_json_error( 'Failed to touch contact update time.', 500 );
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

    metis_runtime_send_json_success( [
        'cid'   => $cid,
        'field' => $field,
        'value' => $sanitized_value,
    ] );
} );

metis_ajax_register_handler( 'metis_contact_remove_additional_email', function () {

    metis_contacts_ajax_verify_nonce();

    if ( ! metis_contacts_can_manage() ) {
        metis_runtime_send_json_error( 'Unauthorized', 403 );
    }

    metis_contacts_ensure_schema();

    $db = metis_db();
    $contacts_table = Metis_Tables::get( 'contacts' );
    $details_table  = Metis_Tables::get( 'contact_details' );

    $cid = isset( metis_request_post()['cid'] ) ? metis_text_clean( metis_runtime_unslash( metis_request_post()['cid'] ) ) : '';
    $email_to_remove = isset( metis_request_post()['email'] ) ? metis_email_clean( metis_runtime_unslash( metis_request_post()['email'] ) ) : '';
    $email_to_remove = strtolower( trim( $email_to_remove ) );

    if ( $cid === '' || $email_to_remove === '' ) {
        metis_runtime_send_json_error( 'CID and email are required.', 400 );
    }

    $contact_row_data = $db->fetchOne(
        "SELECT id, cid, did, email FROM {$contacts_table} WHERE cid = %s LIMIT 1",
        [ $cid ]
    );
    $contact_row = is_array( $contact_row_data ) ? (object) $contact_row_data : null;
    if ( ! $contact_row ) {
        metis_runtime_send_json_error( 'Contact not found.', 404 );
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
                $insert_payload['did'] = $did !== '' ? $did : '';
                $insert_format[] = '%s';
            }
            if ( metis_contacts_column_exists( $details_table, 'additional_emails_json' ) ) {
                $insert_payload['additional_emails_json'] = metis_json_encode( $additional );
                $insert_format[] = '%s';
            }
            if ( metis_contacts_column_exists( $details_table, 'updated_at' ) ) {
                $insert_payload['updated_at'] = metis_current_time( 'mysql' );
                $insert_format[] = '%s';
            }
            if ( ! empty( $insert_payload ) ) {
                $db->insert( $details_table, $insert_payload, $insert_format );
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
                    $patch_payload['updated_at'] = metis_current_time( 'mysql' );
                    $patch_format[] = '%s';
                }
                if ( empty( $patch_payload ) ) {
                    continue;
                }
                $ok = $db->update(
                    $details_table,
                    $patch_payload,
                    [ 'id' => (int) $detail_row->id ],
                    $patch_format,
                    [ '%d' ]
                );
                if ( $ok === false ) {
                    metis_runtime_send_json_error( 'Failed to update additional emails.', 500 );
                }
            }
        }
    }

    if ( metis_contacts_column_exists( $contacts_table, 'updated_at' ) ) {
        $ok = $db->update(
            $contacts_table,
            [ 'updated_at' => metis_current_time( 'mysql' ) ],
            [ 'id' => $id ],
            [ '%s' ],
            [ '%d' ]
        );
        if ( $ok === false ) {
            metis_runtime_send_json_error( 'Failed to touch contact update time.', 500 );
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

    metis_runtime_send_json_success( [
        'cid' => $cid,
        'additional_emails' => $additional,
    ] );
} );

metis_ajax_register_handler( 'metis_contact_add_additional_email', function () {

    metis_contacts_ajax_verify_nonce();

    if ( ! metis_contacts_can_manage() ) {
        metis_runtime_send_json_error( 'Unauthorized', 403 );
    }

    metis_contacts_ensure_schema();

    $db = metis_db();
    $contacts_table = Metis_Tables::get( 'contacts' );
    $details_table  = Metis_Tables::get( 'contact_details' );

    $cid = isset( metis_request_post()['cid'] ) ? metis_text_clean( metis_runtime_unslash( metis_request_post()['cid'] ) ) : '';
    $new_email = isset( metis_request_post()['email'] ) ? metis_email_clean( metis_runtime_unslash( metis_request_post()['email'] ) ) : '';
    $new_email = strtolower( trim( $new_email ) );
    if ( $cid === '' || ! metis_email_is_valid( $new_email ) ) {
        metis_runtime_send_json_error( 'Valid CID and email are required.', 400 );
    }

    $contact_row_data = $db->fetchOne(
        "SELECT id, did, email FROM {$contacts_table} WHERE cid = %s LIMIT 1",
        [ $cid ]
    );
    $contact_row = is_array( $contact_row_data ) ? (object) $contact_row_data : null;
    if ( ! $contact_row ) {
        metis_runtime_send_json_error( 'Contact not found.', 404 );
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
                $insert_payload['did'] = $did !== '' ? $did : '';
                $insert_format[] = '%s';
            }
            if ( metis_contacts_column_exists( $details_table, 'additional_emails_json' ) ) {
                $insert_payload['additional_emails_json'] = metis_json_encode( $additional );
                $insert_format[] = '%s';
            }
            if ( metis_contacts_column_exists( $details_table, 'updated_at' ) ) {
                $insert_payload['updated_at'] = metis_current_time( 'mysql' );
                $insert_format[] = '%s';
            }
            if ( ! empty( $insert_payload ) ) {
                $db->insert( $details_table, $insert_payload, $insert_format );
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
                    $patch_payload['updated_at'] = metis_current_time( 'mysql' );
                    $patch_format[] = '%s';
                }
                if ( ! empty( $patch_payload ) ) {
                    $ok = $db->update( $details_table, $patch_payload, [ 'id' => (int) $detail_row->id ], $patch_format, [ '%d' ] );
                    if ( $ok === false ) {
                        metis_runtime_send_json_error( 'Failed to update additional emails.', 500 );
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

    metis_runtime_send_json_success( [ 'cid' => $cid, 'additional_emails' => $additional ] );
} );



metis_ajax_register_handler( 'metis_contact_add_note', function () {

    metis_contacts_ajax_verify_nonce();

    if ( ! metis_contacts_can_manage() ) {
        metis_runtime_send_json_error( 'Unauthorized', 403 );
    }

    $db = metis_db();

    $contacts_table = Metis_Tables::get( 'contacts' );
    $notes_table    = Metis_Tables::get( 'contact_notes' );

    $cid  = isset( metis_request_post()['cid'] ) ? metis_text_clean( metis_runtime_unslash( metis_request_post()['cid'] ) ) : '';
    $note = isset( metis_request_post()['note'] ) ? metis_textarea_clean( metis_runtime_unslash( metis_request_post()['note'] ) ) : '';

    if ( $cid === '' || $note === '' ) {
        metis_runtime_send_json_error( 'CID and note are required.', 400 );
    }

    $contact_data = $db->fetchOne(
        "SELECT id, did FROM {$contacts_table} WHERE cid = %s LIMIT 1",
        [ $cid ]
    );
    $contact = is_array( $contact_data ) ? (object) $contact_data : null;
    if ( ! $contact ) {
        metis_runtime_send_json_error( 'Contact not found for CID.', 404 );
    }
    if ( ! metis_contacts_table_exists( $notes_table ) ) {
        metis_runtime_send_json_error( 'Notes table is unavailable.', 500 );
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
        $payload['created_at'] = metis_current_time( 'mysql' );
        $format[] = '%s';
    }

    $inserted = $db->insert( $notes_table, $payload, $format );
    if ( ! $inserted ) {
        metis_runtime_send_json_error( 'Failed to save note.', 500 );
    }

    $author = 'System';
    $person_id = function_exists( 'metis_people_get_current_person_id' ) ? (int) metis_people_get_current_person_id() : 0;
    if ( $person_id > 0 && Metis_Tables::has( 'people' ) ) {
        $people_table = Metis_Tables::get( 'people' );
        $person_name = $db->scalar( "SELECT display_name FROM {$people_table} WHERE id = %d LIMIT 1", [ $person_id ] );
        if ( is_string( $person_name ) && trim( $person_name ) !== '' ) {
            $author = trim( $person_name );
        }
    }
    $when = metis_contacts_format_datetime( metis_current_time( 'mysql' ), 'm/d/y g:ia' ) . ' H';

    metis_runtime_send_json_success( [
        'note' => $note,
        'author' => $author,
        'when' => $when,
    ] );
} );

Metis_Logger::info( 'Contacts AJAX handlers loaded' );
