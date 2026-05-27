<?php
declare(strict_types=1);

namespace Metis\Modules\Contacts;

final class ContactMutationService {
    /**
     * @return array<string,string>
     */
    public static function emailDidMap(): array {
        $map = [];
        $contacts_table = \Metis_Tables::get( 'contacts' );
        foreach ( \metis_db()->fetchAll( "SELECT did, email FROM {$contacts_table} WHERE email IS NOT NULL AND email <> ''" ) as $row ) {
            $email = trim( (string) ( $row['email'] ?? '' ) );
            $did = trim( (string) ( $row['did'] ?? '' ) );
            if ( $email === '' || $did === '' ) {
                continue;
            }

            $map[ strtolower( $email ) ] = $did;
            $map[ $email ] = $did;
        }

        return $map;
    }

    public static function resolveOrCreateDonorContact( string $email, string $first_name = '', string $last_name = '' ): array {
        $email = strtolower( trim( \metis_email_clean( $email ) ) );
        if ( $email === '' ) {
            return [ 'did' => null, 'created' => false, 'status' => 'missing', 'error' => null ];
        }

        $db = \metis_db();
        $contacts_table = \Metis_Tables::get( 'contacts' );
        $existing = $db->fetchOne(
            "SELECT id, did FROM {$contacts_table} WHERE email = %s LIMIT 1",
            [ $email ]
        );

        if ( is_array( $existing ) && ! empty( $existing ) ) {
            $did = trim( (string) ( $existing['did'] ?? '' ) );
            if ( $did === '' ) {
                $did = \metis_generate_code( 'MW', $contacts_table, 'did' );
                $db->update( $contacts_table, [ 'did' => $did ], [ 'id' => (int) ( $existing['id'] ?? 0 ) ] );
            }

            return [ 'did' => $did, 'created' => false, 'status' => 'existing', 'error' => null ];
        }

        $did = \metis_generate_code( 'MW', $contacts_table, 'did' );
        $ok = $db->insert( $contacts_table, [
            'did' => $did,
            'first_name' => $first_name !== '' ? $first_name : null,
            'last_name' => $last_name !== '' ? $last_name : null,
            'email' => $email,
            'created_at' => \metis_current_time( 'mysql' ),
            'updated_at' => \metis_current_time( 'mysql' ),
        ] );

        if ( ! $ok ) {
            return [ 'did' => null, 'created' => false, 'status' => 'error', 'error' => 'Failed to create contact.' ];
        }

        return [ 'did' => $did, 'created' => true, 'status' => 'created', 'error' => null ];
    }

    public static function collectLinkedDetailRows( string $details_table, string $cid, int $id, string $did ): array {
        $db = \metis_db();
        $rows = [];
        $seen = [];
        $add_rows = static function ( array $incoming ) use ( &$rows, &$seen ): void {
            foreach ( $incoming as $row ) {
                if ( ! is_object( $row ) ) {
                    continue;
                }
                $row_id = isset( $row->id ) ? (int) $row->id : 0;
                $key = $row_id > 0 ? 'id:' . $row_id : 'hash:' . md5( \metis_json_encode( $row ) );
                if ( isset( $seen[ $key ] ) ) {
                    continue;
                }
                $seen[ $key ] = true;
                $rows[] = $row;
            }
        };

        if ( \metis_contacts_column_exists( $details_table, 'contact_cid' ) ) {
            $found = $db->fetchAll( "SELECT * FROM {$details_table} WHERE contact_cid = %s", [ $cid ] );
            $add_rows( array_map( static fn( array $row ): object => (object) $row, is_array( $found ) ? $found : [] ) );
        }
        if ( \metis_contacts_column_exists( $details_table, 'cid' ) ) {
            $found = $db->fetchAll( "SELECT * FROM {$details_table} WHERE cid = %s", [ $cid ] );
            $add_rows( array_map( static fn( array $row ): object => (object) $row, is_array( $found ) ? $found : [] ) );
        }
        if ( \metis_contacts_column_exists( $details_table, 'contact_id' ) ) {
            $found = $db->fetchAll( "SELECT * FROM {$details_table} WHERE contact_id = %d", [ $id ] );
            $add_rows( array_map( static fn( array $row ): object => (object) $row, is_array( $found ) ? $found : [] ) );
        }
        if ( $did !== '' && \metis_contacts_column_exists( $details_table, 'did' ) ) {
            $found = $db->fetchAll( "SELECT * FROM {$details_table} WHERE did = %s", [ $did ] );
            $add_rows( array_map( static fn( array $row ): object => (object) $row, is_array( $found ) ? $found : [] ) );
        }

        return $rows;
    }

    public static function createContact( array $input ): array {
        \metis_contacts_ensure_schema();

        $db = \metis_db();
        $contacts_table = \Metis_Tables::get( 'contacts' );
        $details_table = \Metis_Tables::get( 'contact_details' );

        $first_name = isset( $input['first_name'] ) ? \metis_text_clean( (string) $input['first_name'] ) : '';
        $last_name = isset( $input['last_name'] ) ? \metis_text_clean( (string) $input['last_name'] ) : '';
        $email = isset( $input['email'] ) ? \metis_email_clean( (string) $input['email'] ) : '';
        $phone = isset( $input['phone'] ) ? \metis_text_clean( (string) $input['phone'] ) : '';

        $email = strtolower( trim( $email ) );
        $phone = trim( $phone );

        if ( $first_name === '' || $last_name === '' ) {
            return [ 'success' => false, 'status' => 400, 'message' => 'First name and last name are required.' ];
        }
        if ( ! \metis_email_is_valid( $email ) ) {
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
            'last_name' => $last_name,
            'email' => $email,
        ];

        if ( function_exists( 'metis_entity_id_service' ) ) {
            $contact_payload = \metis_entity_id_service()->assignForInsert( 'contact', $contact_payload );
        } else {
            $contact_payload['cid'] = \metis_generate_code( 'CN', $contacts_table, 'cid' );
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
            \metis_entity_id_service()->register( 'contact', $id, $cid );
        }

        if ( \metis_contacts_table_exists( $details_table ) ) {
            $details_payload = [];
            $details_format = [];
            if ( \metis_contacts_column_exists( $details_table, 'contact_cid' ) ) {
                $details_payload['contact_cid'] = $cid;
                $details_format[] = '%s';
            }
            if ( \metis_contacts_column_exists( $details_table, 'cid' ) ) {
                $details_payload['cid'] = $cid;
                $details_format[] = '%s';
            }
            if ( \metis_contacts_column_exists( $details_table, 'contact_id' ) ) {
                $details_payload['contact_id'] = $id;
                $details_format[] = '%d';
            }
            if ( \metis_contacts_column_exists( $details_table, 'phone' ) ) {
                $details_payload['phone'] = $phone !== '' ? $phone : null;
                $details_format[] = '%s';
            }
            if ( \metis_contacts_column_exists( $details_table, 'additional_emails_json' ) ) {
                $details_payload['additional_emails_json'] = null;
                $details_format[] = '%s';
            }
            if ( \metis_contacts_column_exists( $details_table, 'relationships_json' ) ) {
                $details_payload['relationships_json'] = \metis_json_encode( [] );
                $details_format[] = '%s';
            }
            if ( $details_payload !== [] ) {
                $db->insert( $details_table, $details_payload, $details_format );
            }
        }

        $row_data = $db->fetchOne( "SELECT * FROM {$contacts_table} WHERE id = %d", [ $id ] );
        $row = is_array( $row_data ) ? (object) $row_data : null;
        $details = null;
        if ( \metis_contacts_table_exists( $details_table ) ) {
            $detail_rows = self::collectLinkedDetailRows( $details_table, $cid, $id, '' );
            $details = $detail_rows[0] ?? null;
        }
        if ( ! $row ) {
            return [ 'success' => false, 'status' => 500, 'message' => 'Contact saved, but response data is unavailable.' ];
        }

        return [
            'success' => true,
            'status' => 200,
            'message' => 'Contact created successfully.',
            'contact' => \metis_contacts_ajax_format_row( $row, $details ),
        ];
    }

    public static function saveContactDetail( array $input ): array {
        \metis_contacts_ensure_schema();
        $db = \metis_db();

        $contacts_table = \Metis_Tables::get( 'contacts' );
        $details_table = \Metis_Tables::get( 'contact_details' );
        $newsletter_subs_table = \Metis_Tables::get( 'newsletter_subs' );
        $newsletter_lists_table = \Metis_Tables::get( 'newsletter_lists' );

        $cid = (string) ( $input['cid'] ?? '' );
        $first = (string) ( $input['first_name'] ?? '' );
        $last = (string) ( $input['last_name'] ?? '' );
        $email = (string) ( $input['email'] ?? '' );
        $phone = (string) ( $input['phone'] ?? '' );
        $preferred_name = (string) ( $input['preferred_name'] ?? '' );
        $preferred_contact = (string) ( $input['preferred_contact_method'] ?? '' );
        $address = (string) ( $input['address'] ?? '' );
        $city = (string) ( $input['city'] ?? '' );
        $state = (string) ( $input['state'] ?? '' );
        $zip = (string) ( $input['zip'] ?? '' );
        $birthday = (string) ( $input['birthday'] ?? '' );
        $spouse_name = array_key_exists( 'spouse_name', $input ) ? (string) $input['spouse_name'] : null;
        $household_id = (string) ( $input['household_id'] ?? '' );
        $source_code = array_key_exists( 'source_code', $input ) ? (string) $input['source_code'] : null;
        $first_contacted = array_key_exists( 'first_contacted', $input ) ? (string) $input['first_contacted'] : null;
        $staff_owner = array_key_exists( 'staff_owner', $input ) ? (string) $input['staff_owner'] : null;
        $do_not_contact = ! empty( $input['do_not_contact'] ) ? 1 : 0;
        $volunteer_status = ! empty( $input['volunteer_status'] ) ? 1 : 0;
        $anonymous_donor = ! empty( $input['anonymous_donor'] ) ? 1 : 0;
        $relationships_json = (string) ( $input['relationships_json'] ?? '[]' );
        $additional_emails_json = (string) ( $input['additional_emails_json'] ?? '[]' );
        $newsletter_list_ids = is_array( $input['newsletter_list_ids'] ?? null ) ? array_values( array_unique( array_map( 'intval', (array) $input['newsletter_list_ids'] ) ) ) : [];

        $email = strtolower( trim( $email ) );
        $phone = \metis_contacts_format_phone_us( trim( $phone ) );
        $birthday = trim( $birthday );
        if ( null !== $first_contacted ) {
            $first_contacted = trim( $first_contacted );
        }
        if ( $birthday !== '' && ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $birthday ) ) {
            \metis_runtime_send_json_error( 'Birthday must use YYYY-MM-DD format.', 400 );
        }
        if ( null !== $first_contacted && $first_contacted !== '' ) {
            $first_contacted_ts = strtotime( $first_contacted );
            if ( $first_contacted_ts === false ) {
                \metis_runtime_send_json_error( 'First contacted must be a valid date/time.', 400 );
            }
            $first_contacted = \metis_runtime_date( 'Y-m-d H:i:s', $first_contacted_ts, \metis_runtime_timezone() );
        }

        if ( $cid === '' || $first === '' || $last === '' || ! \metis_email_is_valid( $email ) ) {
            \metis_runtime_send_json_error( 'Invalid contact payload.', 400 );
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
            \metis_runtime_send_json_error( 'Contact not found for CID.', 404 );
        }

        $email_conflict = (int) $db->scalar(
            "SELECT id FROM {$contacts_table} WHERE email = %s AND id <> %d LIMIT 1",
            [ $email, $id ]
        );
        if ( $email_conflict > 0 ) {
            \metis_runtime_send_json_error( 'Email already exists on another contact.', 400 );
        }

        $decoded_relationships = json_decode( $relationships_json, true );
        if ( json_last_error() !== JSON_ERROR_NONE ) {
            \metis_runtime_send_json_error( 'JSON fields must be valid JSON arrays.', 400 );
        }
        $decoded_additional = json_decode( $additional_emails_json, true );
        if ( json_last_error() !== JSON_ERROR_NONE ) {
            \metis_runtime_send_json_error( 'JSON fields must be valid JSON arrays.', 400 );
        }
        if ( ! is_array( $decoded_relationships ) || ! is_array( $decoded_additional ) ) {
            \metis_runtime_send_json_error( 'JSON fields must be arrays.', 400 );
        }

        $decoded_relationships = \metis_contacts_normalize_relationships( $decoded_relationships, $cid );
        $reconciled_emails = \metis_contacts_reconcile_primary_and_additional_emails( $email, $decoded_additional );
        $email = (string) $reconciled_emails['primary_email'];
        $decoded_additional = (array) $reconciled_emails['additional'];
        if ( $previous_primary_email !== '' && $previous_primary_email !== $email && \metis_email_is_valid( $previous_primary_email ) ) {
            $decoded_additional[] = $previous_primary_email;
        }
        $reconciled_emails = \metis_contacts_reconcile_primary_and_additional_emails( $email, $decoded_additional );
        $email = (string) $reconciled_emails['primary_email'];
        $decoded_additional = (array) $reconciled_emails['additional'];

        $contact_payload = [
            'first_name' => $first,
            'last_name' => $last,
            'email' => $email,
        ];
        $contact_format = [ '%s', '%s', '%s' ];
        if ( \metis_contacts_column_exists( $contacts_table, 'updated_at' ) ) {
            $contact_payload['updated_at'] = \metis_current_time( 'mysql' );
            $contact_format[] = '%s';
        }
        $contact_update = $db->update( $contacts_table, $contact_payload, [ 'id' => $id ], $contact_format, [ '%d' ] );
        if ( $contact_update === false ) {
            \metis_runtime_send_json_error( 'Failed to update contact record.', 500 );
        }

        $existing_detail = self::findDetailRow( $details_table, $cid, $id, $contact_did );
        if ( \metis_contacts_table_exists( $details_table ) ) {
            $details_payload = [];
            $details_format = [];
            foreach ( [
                'contact_cid' => [ $cid, '%s' ],
                'cid' => [ $cid, '%s' ],
                'contact_id' => [ $id, '%d' ],
                'did' => [ $contact_did !== '' ? $contact_did : '', '%s' ],
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
                'relationships_json' => [ \metis_json_encode( $decoded_relationships ), '%s' ],
                'additional_emails_json' => [ \metis_json_encode( $decoded_additional ), '%s' ],
            ] as $column => $meta ) {
                if ( in_array( $column, [ 'spouse_name', 'source_code', 'staff_owner', 'first_contacted' ], true ) && null === $meta[0] ) {
                    continue;
                }
                if ( \metis_contacts_column_exists( $details_table, $column ) ) {
                    $details_payload[ $column ] = $meta[0];
                    $details_format[] = $meta[1];
                }
            }
            if ( \metis_contacts_column_exists( $details_table, 'updated_at' ) ) {
                $details_payload['updated_at'] = \metis_current_time( 'mysql' );
                $details_format[] = '%s';
            }

            if ( ! $existing_detail ) {
                $db->insert( $details_table, $details_payload, $details_format );
            } elseif ( $details_payload !== [] ) {
                self::updateDetailRow( $details_table, $cid, $id, $existing_detail, $details_payload, $details_format );
            }

            $sync_payload = [];
            $sync_format = [];
            foreach ( [
                'additional_emails_json' => [ \metis_json_encode( $decoded_additional ), '%s' ],
                'relationships_json' => [ \metis_json_encode( $decoded_relationships ), '%s' ],
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
                if ( \metis_contacts_column_exists( $details_table, $column ) ) {
                    $sync_payload[ $column ] = $meta[0];
                    $sync_format[] = $meta[1];
                }
            }
            if ( \metis_contacts_column_exists( $details_table, 'updated_at' ) ) {
                $sync_payload['updated_at'] = \metis_current_time( 'mysql' );
                $sync_format[] = '%s';
            }
            if ( $sync_payload !== [] ) {
                foreach ( self::detailSelectors( $cid, $id, $contact_did, $details_table ) as $selector ) {
                    [ $where, $where_format ] = $selector;
                    $res = $db->update( $details_table, $sync_payload, $where, $sync_format, $where_format );
                    if ( $res === false ) {
                        \metis_runtime_send_json_error( 'Failed to sync contact details.', 500 );
                    }
                }
            }
        }

        if (
            \metis_contacts_table_exists( $details_table )
            && ( \metis_contacts_column_exists( $details_table, 'contact_id' ) || \metis_contacts_column_exists( $details_table, 'contact_cid' ) || \metis_contacts_column_exists( $details_table, 'cid' ) )
            && \metis_contacts_column_exists( $details_table, 'relationships_json' )
        ) {
            $current_links = [];
            foreach ( $decoded_relationships as $entry ) {
                if ( ! is_array( $entry ) ) {
                    continue;
                }
                $related_cid = (string) ( $entry['related_contact_cid'] ?? '' );
                if ( $related_cid === '' ) {
                    continue;
                }
                $current_links[ $related_cid ] ??= [];
                $current_links[ $related_cid ][] = [
                    'related_contact_cid' => $cid,
                    'relation_type' => (string) ( $entry['relation_type'] ?? '' ),
                    'notes' => (string) ( $entry['notes'] ?? '' ),
                ];
            }

            $previous_links = [];
            if ( $existing_detail && ! empty( $existing_detail->relationships_json ) ) {
                $decoded_previous = json_decode( (string) $existing_detail->relationships_json, true );
                if ( is_array( $decoded_previous ) ) {
                    $decoded_previous = \metis_contacts_normalize_relationships( $decoded_previous, $cid );
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
                $related_detail = self::findDetailRow( $details_table, $related_contact_cid, $related_id, '' );
                $related_relationships = [];
                if ( $related_detail && ! empty( $related_detail->relationships_json ) ) {
                    $decoded_related = json_decode( (string) $related_detail->relationships_json, true );
                    if ( is_array( $decoded_related ) ) {
                        $related_relationships = \metis_contacts_normalize_relationships( $decoded_related, $related_cid );
                    }
                }
                $filtered_related = [];
                foreach ( $related_relationships as $entry ) {
                    if ( (string) ( $entry['related_contact_cid'] ?? '' ) === $cid ) {
                        continue;
                    }
                    $filtered_related[] = $entry;
                }
                if ( isset( $current_links[ $related_cid ] ) ) {
                    $filtered_related = array_merge( $filtered_related, $current_links[ $related_cid ] );
                }
                $filtered_related = \metis_contacts_normalize_relationships( $filtered_related, $related_cid );
                $encoded = \metis_json_encode( $filtered_related );

                if ( $related_detail ) {
                    self::updateDetailRow( $details_table, $related_contact_cid, $related_id, $related_detail, [ 'relationships_json' => $encoded ], [ '%s' ] );
                } else {
                    $insert_payload = [ 'relationships_json' => $encoded ];
                    $insert_format = [ '%s' ];
                    if ( \metis_contacts_column_exists( $details_table, 'contact_cid' ) ) {
                        $insert_payload['contact_cid'] = $related_contact_cid;
                        $insert_format[] = '%s';
                    }
                    if ( \metis_contacts_column_exists( $details_table, 'cid' ) ) {
                        $insert_payload['cid'] = $related_contact_cid;
                        $insert_format[] = '%s';
                    }
                    if ( \metis_contacts_column_exists( $details_table, 'contact_id' ) ) {
                        $insert_payload['contact_id'] = $related_id;
                        $insert_format[] = '%d';
                    }
                    $db->insert( $details_table, $insert_payload, $insert_format );
                }
            }
        }

        if ( \metis_contacts_table_exists( $newsletter_subs_table ) && \metis_contacts_table_exists( $newsletter_lists_table ) ) {
            $valid_list_ids = [];
            if ( $newsletter_list_ids !== [] ) {
                $placeholders = implode( ',', array_fill( 0, count( $newsletter_list_ids ), '%d' ) );
                $valid_list_ids = $db->column(
                    "SELECT id FROM {$newsletter_lists_table} WHERE id IN ({$placeholders})",
                    $newsletter_list_ids
                ) ?: [];
                $valid_list_ids = array_map( 'intval', $valid_list_ids );
            }
            $delete_ok = $db->delete( $newsletter_subs_table, [ 'contact_id' => $id ], [ '%d' ] );
            if ( $delete_ok === false ) {
                \metis_runtime_send_json_error( 'Failed to update newsletter subscriptions.', 500 );
            }
            foreach ( $valid_list_ids as $list_id ) {
                $ins = $db->insert( $newsletter_subs_table, [ 'contact_id' => $id, 'list_id' => $list_id ], [ '%d', '%d' ] );
                if ( $ins === false ) {
                    \metis_runtime_send_json_error( 'Failed to save newsletter subscription.', 500 );
                }
            }
        }

        self::logCarddavChange( $cid );
        return [ 'cid' => $cid ];
    }

    public static function inlineUpdate( string $cid, string $field, $value ): array {
        \metis_contacts_ensure_schema();
        $db = \metis_db();
        $contacts_table = \Metis_Tables::get( 'contacts' );
        $details_table = \Metis_Tables::get( 'contact_details' );

        if ( $cid === '' ) {
            \metis_runtime_send_json_error( 'Invalid inline update payload.', 400 );
        }

        $contact_row_data = $db->fetchOne(
            "SELECT id, cid, did, email, first_name, last_name FROM {$contacts_table} WHERE cid = %s LIMIT 1",
            [ $cid ]
        );
        $contact_row = is_array( $contact_row_data ) ? (object) $contact_row_data : null;
        if ( ! $contact_row ) {
            \metis_runtime_send_json_error( 'Contact not found.', 404 );
        }

        $id = (int) $contact_row->id;
        $did = (string) ( $contact_row->did ?? '' );
        $primary_email_before = (string) ( $contact_row->email ?? '' );
        $detail_rows = self::collectLinkedDetailRows( $details_table, $cid, $id, $did );

        $contact_fields = [
            'full_name' => '%s',
            'first_name' => '%s',
            'last_name' => '%s',
        ];
        $detail_fields = [
            'phone' => '%s',
            'preferred_name' => '%s',
            'preferred_contact_method' => '%s',
            'address' => '%s',
            'city' => '%s',
            'state' => '%s',
            'zip' => '%s',
            'birthday' => '%s',
            'spouse_name' => '%s',
            'household_id' => '%s',
            'source_code' => '%s',
            'first_contacted' => '%s',
            'staff_owner' => '%s',
            'do_not_contact' => '%d',
            'volunteer_status' => '%d',
            'anonymous_donor' => '%d',
        ];

        if ( $field === 'address_full' ) {
            $parsed = \metis_contacts_parse_address_full( (string) $value );
            self::upsertDetailsFieldSet( $details_table, $cid, $id, $did, $detail_rows, [
                'address' => $parsed['address'] !== '' ? $parsed['address'] : null,
                'city' => $parsed['city'] !== '' ? $parsed['city'] : null,
                'state' => $parsed['state'] !== '' ? $parsed['state'] : null,
                'zip' => $parsed['zip'] !== '' ? $parsed['zip'] : null,
            ] );
            self::touchContact( $contacts_table, $id );
            return [
                'cid' => $cid,
                'field' => $field,
                'value' => (string) $parsed['value'],
                'address_line_1' => (string) $parsed['line_1'],
                'address_line_2' => (string) $parsed['line_2'],
            ];
        }

        if ( $field === 'email' ) {
            $next_email = strtolower( trim( \metis_email_clean( (string) $value ) ) );
            if ( ! \metis_email_is_valid( $next_email ) ) {
                \metis_runtime_send_json_error( 'Please enter a valid email address.', 400 );
            }
            $email_conflict = (int) $db->scalar(
                "SELECT id FROM {$contacts_table} WHERE email = %s AND id <> %d LIMIT 1",
                [ $next_email, $id ]
            );
            if ( $email_conflict > 0 ) {
                \metis_runtime_send_json_error( 'Email already exists on another contact.', 400 );
            }
            $contact_payload = [ 'email' => $next_email ];
            $contact_format = [ '%s' ];
            if ( \metis_contacts_column_exists( $contacts_table, 'updated_at' ) ) {
                $contact_payload['updated_at'] = \metis_current_time( 'mysql' );
                $contact_format[] = '%s';
            }
            $res = $db->update( $contacts_table, $contact_payload, [ 'id' => $id ], $contact_format, [ '%d' ] );
            if ( $res === false ) {
                \metis_runtime_send_json_error( 'Failed to update primary email.', 500 );
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
            $reconciled = \metis_contacts_reconcile_primary_and_additional_emails( $next_email, $all_additional_candidates );
            $additional = (array) ( $reconciled['additional'] ?? [] );
            self::upsertDetailsFieldSet( $details_table, $cid, $id, $did, $detail_rows, [
                'additional_emails_json' => \metis_json_encode( $additional ),
            ] );

            return [
                'cid' => $cid,
                'field' => 'email',
                'value' => $next_email,
                'additional_emails' => $additional,
            ];
        }

        if ( isset( $contact_fields[ $field ] ) ) {
            $sanitized = \metis_text_clean( (string) $value );
            if ( $field === 'full_name' ) {
                $clean = preg_replace( '/\s+/', ' ', trim( $sanitized ) );
                if ( ! is_string( $clean ) || $clean === '' ) {
                    \metis_runtime_send_json_error( 'Name cannot be empty.', 400 );
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
                    \metis_runtime_send_json_error( 'Name needs at least a first name.', 400 );
                }
                $contact_payload = [ 'first_name' => $first_part, 'last_name' => $last_part ];
                $contact_format = [ '%s', '%s' ];
                if ( \metis_contacts_column_exists( $contacts_table, 'updated_at' ) ) {
                    $contact_payload['updated_at'] = \metis_current_time( 'mysql' );
                    $contact_format[] = '%s';
                }
                $ok = $db->update( $contacts_table, $contact_payload, [ 'id' => $id ], $contact_format, [ '%d' ] );
                if ( $ok === false ) {
                    \metis_runtime_send_json_error( 'Failed to update full name.', 500 );
                }
                return [
                    'cid' => $cid,
                    'field' => $field,
                    'value' => trim( $first_part . ' ' . $last_part ),
                    'first_name' => $first_part,
                    'last_name' => $last_part,
                ];
            }
            if ( in_array( $field, [ 'first_name', 'last_name' ], true ) && $sanitized === '' ) {
                \metis_runtime_send_json_error( 'Name fields cannot be empty.', 400 );
            }
            $contact_payload = [ $field => $sanitized ];
            $contact_format = [ $contact_fields[ $field ] ];
            if ( \metis_contacts_column_exists( $contacts_table, 'updated_at' ) ) {
                $contact_payload['updated_at'] = \metis_current_time( 'mysql' );
                $contact_format[] = '%s';
            }
            $ok = $db->update( $contacts_table, $contact_payload, [ 'id' => $id ], $contact_format, [ '%d' ] );
            if ( $ok === false ) {
                \metis_runtime_send_json_error( 'Failed to update contact field.', 500 );
            }
            return [ 'cid' => $cid, 'field' => $field, 'value' => $sanitized ];
        }

        if ( ! isset( $detail_fields[ $field ] ) ) {
            \metis_runtime_send_json_error( 'Inline field is not supported.', 400 );
        }

        if ( in_array( $field, [ 'do_not_contact', 'volunteer_status', 'anonymous_donor' ], true ) ) {
            $sanitized_value = (string) ( ! empty( $value ) && (string) $value !== '0' ? 1 : 0 );
        } elseif ( $field === 'birthday' ) {
            $candidate = trim( \metis_text_clean( (string) $value ) );
            if ( $candidate !== '' && ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $candidate ) ) {
                \metis_runtime_send_json_error( 'Birthday must use YYYY-MM-DD format.', 400 );
            }
            $sanitized_value = $candidate;
        } elseif ( $field === 'first_contacted' ) {
            $candidate = trim( \metis_text_clean( (string) $value ) );
            if ( $candidate !== '' ) {
                $ts = strtotime( $candidate );
                if ( $ts === false ) {
                    \metis_runtime_send_json_error( 'First contacted must be a valid date/time.', 400 );
                }
                $candidate = \metis_runtime_date( 'Y-m-d H:i:s', $ts, \metis_runtime_timezone() );
            }
            $sanitized_value = $candidate;
        } elseif ( $field === 'phone' ) {
            $sanitized_value = \metis_contacts_format_phone_us( \metis_text_clean( (string) $value ) );
        } else {
            $sanitized_value = \metis_text_clean( (string) $value );
        }

        self::upsertDetailsFieldSet( $details_table, $cid, $id, $did, $detail_rows, [
            $field => $sanitized_value !== '' ? $sanitized_value : null,
        ], true );
        self::touchContact( $contacts_table, $id );
        self::logCarddavChange( $cid );

        return [ 'cid' => $cid, 'field' => $field, 'value' => $sanitized_value ];
    }

    public static function removeAdditionalEmail( string $cid, string $email_to_remove ): array {
        return self::mutateAdditionalEmails( $cid, static function ( array $additional ) use ( $email_to_remove ): array {
            return array_values( array_filter(
                $additional,
                static fn( string $candidate ): bool => $candidate !== $email_to_remove
            ) );
        } );
    }

    public static function addAdditionalEmail( string $cid, string $new_email ): array {
        return self::mutateAdditionalEmails( $cid, static function ( array $additional ) use ( $new_email ): array {
            array_unshift( $additional, $new_email );
            return $additional;
        } );
    }

    public static function addNote( string $cid, string $note ): array {
        $db = \metis_db();
        $contacts_table = \Metis_Tables::get( 'contacts' );
        $notes_table = \Metis_Tables::get( 'contact_notes' );

        if ( $cid === '' || $note === '' ) {
            \metis_runtime_send_json_error( 'CID and note are required.', 400 );
        }

        $contact_data = $db->fetchOne(
            "SELECT id, did FROM {$contacts_table} WHERE cid = %s LIMIT 1",
            [ $cid ]
        );
        $contact = is_array( $contact_data ) ? (object) $contact_data : null;
        if ( ! $contact ) {
            \metis_runtime_send_json_error( 'Contact not found for CID.', 404 );
        }
        if ( ! \metis_contacts_table_exists( $notes_table ) ) {
            \metis_runtime_send_json_error( 'Notes table is unavailable.', 500 );
        }

        $payload = [ 'note' => $note ];
        $format = [ '%s' ];
        if ( \metis_contacts_column_exists( $notes_table, 'cid' ) ) {
            $payload['cid'] = $cid;
            $format[] = '%s';
        } elseif ( \metis_contacts_column_exists( $notes_table, 'did' ) ) {
            $payload['did'] = (string) ( $contact->did ?? '' );
            $format[] = '%s';
        }
        if ( \metis_contacts_column_exists( $notes_table, 'admin_user_id' ) ) {
            $payload['admin_user_id'] = \metis_current_user_id();
            $format[] = '%d';
        }
        if ( \metis_contacts_column_exists( $notes_table, 'created_at' ) ) {
            $payload['created_at'] = \metis_current_time( 'mysql' );
            $format[] = '%s';
        }
        $inserted = $db->insert( $notes_table, $payload, $format );
        if ( ! $inserted ) {
            \metis_runtime_send_json_error( 'Failed to save note.', 500 );
        }

        $author = 'System';
        $person_id = function_exists( 'metis_people_get_current_person_id' ) ? (int) \metis_people_get_current_person_id() : 0;
        if ( $person_id > 0 && \Metis_Tables::has( 'people' ) ) {
            $people_table = \Metis_Tables::get( 'people' );
            $person_name = $db->scalar( "SELECT display_name FROM {$people_table} WHERE id = %d LIMIT 1", [ $person_id ] );
            if ( is_string( $person_name ) && trim( $person_name ) !== '' ) {
                $author = trim( $person_name );
            }
        }
        $when = \metis_contacts_format_datetime( \metis_current_time( 'mysql' ), 'm/d/y g:ia' ) . ' H';

        return [
            'note' => $note,
            'author' => $author,
            'when' => $when,
        ];
    }

    private static function mutateAdditionalEmails( string $cid, callable $mutator ): array {
        \metis_contacts_ensure_schema();
        $db = \metis_db();
        $contacts_table = \Metis_Tables::get( 'contacts' );
        $details_table = \Metis_Tables::get( 'contact_details' );

        if ( $cid === '' ) {
            \metis_runtime_send_json_error( 'CID is required.', 400 );
        }
        $contact_row_data = $db->fetchOne(
            "SELECT id, did, email FROM {$contacts_table} WHERE cid = %s LIMIT 1",
            [ $cid ]
        );
        $contact_row = is_array( $contact_row_data ) ? (object) $contact_row_data : null;
        if ( ! $contact_row ) {
            \metis_runtime_send_json_error( 'Contact not found.', 404 );
        }
        $id = (int) $contact_row->id;
        $did = (string) ( $contact_row->did ?? '' );
        $primary_email = (string) ( $contact_row->email ?? '' );
        $detail_rows = self::collectLinkedDetailRows( $details_table, $cid, $id, $did );
        $all_candidates = [];
        foreach ( $detail_rows as $detail_row ) {
            $decoded = json_decode( (string) ( $detail_row->additional_emails_json ?? '[]' ), true );
            if ( is_array( $decoded ) ) {
                $all_candidates = array_merge( $all_candidates, $decoded );
            }
        }
        $all_candidates = $mutator( $all_candidates );
        $reconciled = \metis_contacts_reconcile_primary_and_additional_emails( $primary_email, $all_candidates );
        $additional = (array) ( $reconciled['additional'] ?? [] );
        self::upsertDetailsFieldSet( $details_table, $cid, $id, $did, $detail_rows, [
            'additional_emails_json' => \metis_json_encode( $additional ),
        ] );
        self::touchContact( $contacts_table, $id );
        self::logCarddavChange( $cid );
        return [ 'cid' => $cid, 'additional_emails' => $additional ];
    }

    private static function findDetailRow( string $details_table, string $cid, int $id, string $did ): ?object {
        $db = \metis_db();
        if ( \metis_contacts_table_exists( $details_table ) ) {
            if ( \metis_contacts_column_exists( $details_table, 'contact_cid' ) ) {
                $row = $db->fetchOne( "SELECT * FROM {$details_table} WHERE contact_cid = %s LIMIT 1", [ $cid ] );
                if ( is_array( $row ) ) {
                    return (object) $row;
                }
            }
            if ( \metis_contacts_column_exists( $details_table, 'cid' ) ) {
                $row = $db->fetchOne( "SELECT * FROM {$details_table} WHERE cid = %s LIMIT 1", [ $cid ] );
                if ( is_array( $row ) ) {
                    return (object) $row;
                }
            }
            if ( \metis_contacts_column_exists( $details_table, 'contact_id' ) ) {
                $row = $db->fetchOne( "SELECT * FROM {$details_table} WHERE contact_id = %d LIMIT 1", [ $id ] );
                if ( is_array( $row ) ) {
                    return (object) $row;
                }
            }
            if ( $did !== '' && \metis_contacts_column_exists( $details_table, 'did' ) ) {
                $row = $db->fetchOne( "SELECT * FROM {$details_table} WHERE did = %s LIMIT 1", [ $did ] );
                if ( is_array( $row ) ) {
                    return (object) $row;
                }
            }
        }
        return null;
    }

    private static function updateDetailRow( string $details_table, string $cid, int $id, object $detail_row, array $payload, array $format ): void {
        $db = \metis_db();
        if ( isset( $detail_row->id ) ) {
            $db->update( $details_table, $payload, [ 'id' => (int) $detail_row->id ], $format, [ '%d' ] );
        } elseif ( \metis_contacts_column_exists( $details_table, 'contact_cid' ) ) {
            $db->update( $details_table, $payload, [ 'contact_cid' => $cid ], $format, [ '%s' ] );
        } elseif ( \metis_contacts_column_exists( $details_table, 'cid' ) ) {
            $db->update( $details_table, $payload, [ 'cid' => $cid ], $format, [ '%s' ] );
        } else {
            $db->update( $details_table, $payload, [ 'contact_id' => $id ], $format, [ '%d' ] );
        }
    }

    private static function detailSelectors( string $cid, int $id, string $did, string $details_table ): array {
        $selectors = [];
        if ( \metis_contacts_column_exists( $details_table, 'contact_cid' ) ) {
            $selectors[] = [ [ 'contact_cid' => $cid ], [ '%s' ] ];
        }
        if ( \metis_contacts_column_exists( $details_table, 'cid' ) ) {
            $selectors[] = [ [ 'cid' => $cid ], [ '%s' ] ];
        }
        if ( \metis_contacts_column_exists( $details_table, 'contact_id' ) ) {
            $selectors[] = [ [ 'contact_id' => $id ], [ '%d' ] ];
        }
        if ( $did !== '' && \metis_contacts_column_exists( $details_table, 'did' ) ) {
            $selectors[] = [ [ 'did' => $did ], [ '%s' ] ];
        }
        return $selectors;
    }

    private static function upsertDetailsFieldSet( string $details_table, string $cid, int $id, string $did, array $detail_rows, array $fields, bool $include_contact_update = false ): void {
        if ( ! \metis_contacts_table_exists( $details_table ) ) {
            return;
        }
        $insert_payload = [];
        $insert_format = [];
        if ( $detail_rows === [] ) {
            if ( \metis_contacts_column_exists( $details_table, 'contact_cid' ) ) {
                $insert_payload['contact_cid'] = $cid;
                $insert_format[] = '%s';
            }
            if ( \metis_contacts_column_exists( $details_table, 'contact_id' ) ) {
                $insert_payload['contact_id'] = $id;
                $insert_format[] = '%d';
            }
            if ( \metis_contacts_column_exists( $details_table, 'did' ) ) {
                $insert_payload['did'] = $did !== '' ? $did : '';
                $insert_format[] = '%s';
            }
            foreach ( $fields as $field => $value ) {
                if ( \metis_contacts_column_exists( $details_table, $field ) ) {
                    $insert_payload[ $field ] = $value;
                    $insert_format[] = in_array( $field, [ 'do_not_contact', 'volunteer_status', 'anonymous_donor' ], true ) ? '%d' : '%s';
                }
            }
            if ( \metis_contacts_column_exists( $details_table, 'updated_at' ) ) {
                $insert_payload['updated_at'] = \metis_current_time( 'mysql' );
                $insert_format[] = '%s';
            }
            if ( $insert_payload !== [] ) {
                $inserted = \metis_db()->insert( $details_table, $insert_payload, $insert_format );
                if ( $inserted === false ) {
                    \metis_runtime_send_json_error( 'Failed to create details row.', 500 );
                }
            }
            return;
        }

        foreach ( $detail_rows as $detail_row ) {
            if ( ! isset( $detail_row->id ) ) {
                continue;
            }
            $patch_payload = [];
            $patch_format = [];
            foreach ( $fields as $field => $field_value ) {
                if ( \metis_contacts_column_exists( $details_table, $field ) ) {
                    $patch_payload[ $field ] = $field_value;
                    $patch_format[] = in_array( $field, [ 'do_not_contact', 'volunteer_status', 'anonymous_donor' ], true ) ? '%d' : '%s';
                }
            }
            if ( \metis_contacts_column_exists( $details_table, 'updated_at' ) ) {
                $patch_payload['updated_at'] = \metis_current_time( 'mysql' );
                $patch_format[] = '%s';
            }
            if ( $patch_payload === [] ) {
                continue;
            }
            $ok = \metis_db()->update(
                $details_table,
                $patch_payload,
                [ 'id' => (int) $detail_row->id ],
                $patch_format,
                [ '%d' ]
            );
            if ( $ok === false ) {
                \metis_runtime_send_json_error( 'Failed to update contact details.', 500 );
            }
        }
    }

    private static function touchContact( string $contacts_table, int $id ): void {
        if ( \metis_contacts_column_exists( $contacts_table, 'updated_at' ) ) {
            $ok = \metis_db()->update(
                $contacts_table,
                [ 'updated_at' => \metis_current_time( 'mysql' ) ],
                [ 'id' => $id ],
                [ '%s' ],
                [ '%d' ]
            );
            if ( $ok === false ) {
                \metis_runtime_send_json_error( 'Failed to touch contact update time.', 500 );
            }
        }
    }

    private static function logCarddavChange( string $cid ): void {
        if ( function_exists( 'metis_contacts_carddav_fetch_contact' ) ) {
            $carddav_entry = \metis_contacts_carddav_fetch_contact( $cid );
            if ( is_array( $carddav_entry ) ) {
                \metis_contacts_carddav_log_change(
                    $cid,
                    'upsert',
                    \metis_contacts_carddav_book_slugs_for_contact( $carddav_entry['contact'], $carddav_entry['details'] ),
                    \metis_contacts_carddav_contact_etag( $carddav_entry['contact'], $carddav_entry['details'] )
                );
            }
        }
    }
}
