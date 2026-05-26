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
    return \Metis\Modules\Contacts\ContactMutationService::collectLinkedDetailRows( $details_table, $cid, $id, $did );
}


function metis_contacts_create_contact( array $input ): array {
    return \Metis\Modules\Contacts\ContactMutationService::createContact( $input );
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

    $newsletter_list_ids = [];
    if ( isset( metis_request_post()['newsletter_list_ids'] ) ) {
        $decoded_newsletters = json_decode( (string) metis_runtime_unslash( metis_request_post()['newsletter_list_ids'] ), true );
        if ( is_array( $decoded_newsletters ) ) {
            $newsletter_list_ids = array_values( array_unique( array_map( 'intval', $decoded_newsletters ) ) );
        }
    }

    metis_runtime_send_json_success( \Metis\Modules\Contacts\ContactMutationService::saveContactDetail( [
        'cid' => isset( metis_request_post()['cid'] ) ? metis_text_clean( metis_runtime_unslash( metis_request_post()['cid'] ) ) : '',
        'first_name' => isset( metis_request_post()['first_name'] ) ? metis_text_clean( metis_runtime_unslash( metis_request_post()['first_name'] ) ) : '',
        'last_name' => isset( metis_request_post()['last_name'] ) ? metis_text_clean( metis_runtime_unslash( metis_request_post()['last_name'] ) ) : '',
        'email' => isset( metis_request_post()['email'] ) ? metis_email_clean( metis_runtime_unslash( metis_request_post()['email'] ) ) : '',
        'phone' => isset( metis_request_post()['phone'] ) ? metis_text_clean( metis_runtime_unslash( metis_request_post()['phone'] ) ) : '',
        'preferred_name' => isset( metis_request_post()['preferred_name'] ) ? metis_text_clean( metis_runtime_unslash( metis_request_post()['preferred_name'] ) ) : '',
        'preferred_contact_method' => isset( metis_request_post()['preferred_contact_method'] ) ? metis_text_clean( metis_runtime_unslash( metis_request_post()['preferred_contact_method'] ) ) : '',
        'address' => isset( metis_request_post()['address'] ) ? metis_text_clean( metis_runtime_unslash( metis_request_post()['address'] ) ) : '',
        'city' => isset( metis_request_post()['city'] ) ? metis_text_clean( metis_runtime_unslash( metis_request_post()['city'] ) ) : '',
        'state' => isset( metis_request_post()['state'] ) ? metis_text_clean( metis_runtime_unslash( metis_request_post()['state'] ) ) : '',
        'zip' => isset( metis_request_post()['zip'] ) ? metis_text_clean( metis_runtime_unslash( metis_request_post()['zip'] ) ) : '',
        'birthday' => isset( metis_request_post()['birthday'] ) ? metis_text_clean( metis_runtime_unslash( metis_request_post()['birthday'] ) ) : '',
        'spouse_name' => isset( metis_request_post()['spouse_name'] ) ? metis_text_clean( metis_runtime_unslash( metis_request_post()['spouse_name'] ) ) : null,
        'household_id' => isset( metis_request_post()['household_id'] ) ? metis_text_clean( metis_runtime_unslash( metis_request_post()['household_id'] ) ) : '',
        'source_code' => isset( metis_request_post()['source_code'] ) ? metis_text_clean( metis_runtime_unslash( metis_request_post()['source_code'] ) ) : null,
        'first_contacted' => isset( metis_request_post()['first_contacted'] ) ? metis_text_clean( metis_runtime_unslash( metis_request_post()['first_contacted'] ) ) : null,
        'staff_owner' => isset( metis_request_post()['staff_owner'] ) ? metis_text_clean( metis_runtime_unslash( metis_request_post()['staff_owner'] ) ) : null,
        'do_not_contact' => isset( metis_request_post()['do_not_contact'] ) ? ( ! empty( metis_runtime_unslash( metis_request_post()['do_not_contact'] ) ) ? 1 : 0 ) : 0,
        'volunteer_status' => isset( metis_request_post()['volunteer_status'] ) ? ( ! empty( metis_runtime_unslash( metis_request_post()['volunteer_status'] ) ) ? 1 : 0 ) : 0,
        'anonymous_donor' => isset( metis_request_post()['anonymous_donor'] ) ? ( ! empty( metis_runtime_unslash( metis_request_post()['anonymous_donor'] ) ) ? 1 : 0 ) : 0,
        'relationships_json' => isset( metis_request_post()['relationships_json'] ) ? (string) metis_runtime_unslash( metis_request_post()['relationships_json'] ) : '[]',
        'additional_emails_json' => isset( metis_request_post()['additional_emails_json'] ) ? (string) metis_runtime_unslash( metis_request_post()['additional_emails_json'] ) : '[]',
        'newsletter_list_ids' => $newsletter_list_ids,
    ] ) );
} );

metis_ajax_register_handler( 'metis_contact_inline_update', function () {

    metis_contacts_ajax_verify_nonce();

    if ( ! metis_contacts_can_manage() ) {
        metis_runtime_send_json_error( 'Unauthorized', 403 );
    }

    metis_runtime_send_json_success(
        \Metis\Modules\Contacts\ContactMutationService::inlineUpdate(
            isset( metis_request_post()['cid'] ) ? metis_text_clean( metis_runtime_unslash( metis_request_post()['cid'] ) ) : '',
            isset( metis_request_post()['field'] ) ? metis_key_clean( metis_runtime_unslash( metis_request_post()['field'] ) ) : '',
            isset( metis_request_post()['value'] ) ? metis_runtime_unslash( metis_request_post()['value'] ) : ''
        )
    );
} );

metis_ajax_register_handler( 'metis_contact_remove_additional_email', function () {

    metis_contacts_ajax_verify_nonce();

    if ( ! metis_contacts_can_manage() ) {
        metis_runtime_send_json_error( 'Unauthorized', 403 );
    }

    $cid = isset( metis_request_post()['cid'] ) ? metis_text_clean( metis_runtime_unslash( metis_request_post()['cid'] ) ) : '';
    $email_to_remove = isset( metis_request_post()['email'] ) ? metis_email_clean( metis_runtime_unslash( metis_request_post()['email'] ) ) : '';
    $email_to_remove = strtolower( trim( $email_to_remove ) );

    if ( $cid === '' || $email_to_remove === '' ) {
        metis_runtime_send_json_error( 'CID and email are required.', 400 );
    }
    metis_runtime_send_json_success(
        \Metis\Modules\Contacts\ContactMutationService::removeAdditionalEmail( $cid, $email_to_remove )
    );
} );

metis_ajax_register_handler( 'metis_contact_add_additional_email', function () {

    metis_contacts_ajax_verify_nonce();

    if ( ! metis_contacts_can_manage() ) {
        metis_runtime_send_json_error( 'Unauthorized', 403 );
    }

    $cid = isset( metis_request_post()['cid'] ) ? metis_text_clean( metis_runtime_unslash( metis_request_post()['cid'] ) ) : '';
    $new_email = isset( metis_request_post()['email'] ) ? metis_email_clean( metis_runtime_unslash( metis_request_post()['email'] ) ) : '';
    $new_email = strtolower( trim( $new_email ) );
    if ( $cid === '' || ! metis_email_is_valid( $new_email ) ) {
        metis_runtime_send_json_error( 'Valid CID and email are required.', 400 );
    }
    metis_runtime_send_json_success(
        \Metis\Modules\Contacts\ContactMutationService::addAdditionalEmail( $cid, $new_email )
    );
} );



metis_ajax_register_handler( 'metis_contact_add_note', function () {

    metis_contacts_ajax_verify_nonce();

    if ( ! metis_contacts_can_manage() ) {
        metis_runtime_send_json_error( 'Unauthorized', 403 );
    }

    $cid  = isset( metis_request_post()['cid'] ) ? metis_text_clean( metis_runtime_unslash( metis_request_post()['cid'] ) ) : '';
    $note = isset( metis_request_post()['note'] ) ? metis_textarea_clean( metis_runtime_unslash( metis_request_post()['note'] ) ) : '';

    metis_runtime_send_json_success(
        \Metis\Modules\Contacts\ContactMutationService::addNote( $cid, $note )
    );
} );

Metis_Logger::info( 'Contacts AJAX handlers loaded' );
