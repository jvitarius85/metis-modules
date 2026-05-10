<?php
if ( ! defined( 'METIS_ROOT' ) ) exit;

if ( function_exists( 'metis_ajax_register_controller' ) ) {
    metis_ajax_register_controller( 'metis_contacts_merge_duplicates', [
        'module' => 'contacts',
        'permission' => 'edit',
        'nonce_action' => metis_ajax_nonce_action( 'metis_contacts_merge_duplicates' ),
    ] );
    metis_ajax_register_controller( 'metis_contacts_cleanup_merge_notes', [
        'module' => 'contacts',
        'permission' => 'edit',
        'nonce_action' => metis_ajax_nonce_action( 'metis_contacts_cleanup_merge_notes' ),
    ] );
}

metis_ajax_register_handler( 'metis_contacts_merge_duplicates', function () {
    metis_contacts_ajax_verify_nonce();

    if ( ! metis_contacts_can_manage() ) {
        metis_runtime_send_json_error( 'Unauthorized', 403 );
    }

    metis_contacts_ensure_schema();

    $db = metis_db();
    $contacts_table = Metis_Tables::get( 'contacts' );
    $details_table  = Metis_Tables::get( 'contact_details' );
    $notes_table    = Metis_Tables::get( 'contact_notes' );
    $transactions_table = Metis_Tables::get( 'transactions' );

    $primary_cid = isset( metis_request_post()['primary_cid'] ) ? metis_text_clean( metis_runtime_unslash( metis_request_post()['primary_cid'] ) ) : '';
    $duplicate_cids = [];
    if ( isset( metis_request_post()['duplicate_cids'] ) ) {
        $decoded = json_decode( (string) metis_runtime_unslash( metis_request_post()['duplicate_cids'] ), true );
        if ( is_array( $decoded ) ) {
            foreach ( $decoded as $item ) {
                $candidate = metis_text_clean( (string) $item );
                if ( $candidate !== '' ) {
                    $duplicate_cids[] = $candidate;
                }
            }
        }
    }
    if ( empty( $duplicate_cids ) && isset( metis_request_post()['duplicate_cid'] ) ) {
        $candidate = metis_text_clean( metis_runtime_unslash( metis_request_post()['duplicate_cid'] ) );
        if ( $candidate !== '' ) {
            $duplicate_cids[] = $candidate;
        }
    }
    $duplicate_cids = array_values( array_unique( array_filter( $duplicate_cids, static function ( $cid ) use ( $primary_cid ) {
        return is_string( $cid ) && $cid !== '' && $cid !== $primary_cid;
    } ) ) );
    if ( $primary_cid === '' || empty( $duplicate_cids ) ) {
        metis_runtime_send_json_error( 'Select a valid primary and duplicate contact(s).', 400 );
    }

    $collect_detail_rows = static function ( string $cid, int $id, string $did ) use ( $db, $details_table ): array {
        if ( ! metis_contacts_table_exists( $details_table ) ) {
            return [];
        }

        if ( function_exists( 'metis_contacts_collect_linked_detail_rows' ) ) {
            return metis_contacts_collect_linked_detail_rows( $details_table, $cid, $id, $did );
        }

        $rows = [];
        $seen = [];
        $push = static function ( array $found ) use ( &$rows, &$seen ): void {
            foreach ( $found as $row ) {
                if ( ! is_array( $row ) ) {
                    continue;
                }
                $row_obj = (object) $row;
                $row_id = isset( $row_obj->id ) ? (int) $row_obj->id : 0;
                $key = $row_id > 0 ? 'id:' . $row_id : 'hash:' . md5( metis_json_encode( $row_obj ) );
                if ( isset( $seen[ $key ] ) ) {
                    continue;
                }
                $seen[ $key ] = true;
                $rows[] = $row_obj;
            }
        };

        if ( metis_contacts_column_exists( $details_table, 'contact_cid' ) ) {
            $push( $db->fetchAll( "SELECT * FROM {$details_table} WHERE contact_cid = %s", [ $cid ] ) ?: [] );
        }
        if ( metis_contacts_column_exists( $details_table, 'contact_id' ) ) {
            $push( $db->fetchAll( "SELECT * FROM {$details_table} WHERE contact_id = %d", [ $id ] ) ?: [] );
        }
        if ( $did !== '' && metis_contacts_column_exists( $details_table, 'did' ) ) {
            $push( $db->fetchAll( "SELECT * FROM {$details_table} WHERE did = %s", [ $did ] ) ?: [] );
        }

        return $rows;
    };

    $decode_email_json = static function ( $json ) {
        $decoded = json_decode( (string) $json, true );
        return is_array( $decoded ) ? metis_contacts_normalize_additional_emails( $decoded ) : [];
    };

    $decode_rel_json = static function ( $json, string $self_cid ) {
        $decoded = json_decode( (string) $json, true );
        return is_array( $decoded ) ? metis_contacts_normalize_relationships( $decoded, $self_cid ) : [];
    };

    $rollback = static function ( string $message, array $data = [] ) use ( $db ) {
        $db->execute( 'ROLLBACK' );
        $db_error = trim( (string) ( $db->connection()->last_error ?? '' ) );
        if ( $db_error !== '' ) {
            $data['db_error'] = $db_error;
        }
        metis_runtime_send_json_error( array_merge( [ 'message' => $message ], $data ), 500 );
    };

    $detail_where_from_row = static function ( ?object $row ) use ( $details_table ): array {
        if ( ! $row ) {
            return [ [], [] ];
        }

        if ( isset( $row->id ) && (int) $row->id > 0 && metis_contacts_column_exists( $details_table, 'id' ) ) {
            return [ [ 'id' => (int) $row->id ], [ '%d' ] ];
        }

        $where = [];
        $where_format = [];
        if ( metis_contacts_column_exists( $details_table, 'contact_cid' ) ) {
            $value = trim( (string) ( $row->contact_cid ?? '' ) );
            if ( $value !== '' ) {
                $where['contact_cid'] = $value;
                $where_format[] = '%s';
            }
        }
        if ( metis_contacts_column_exists( $details_table, 'contact_id' ) ) {
            $value = (int) ( $row->contact_id ?? 0 );
            if ( $value > 0 ) {
                $where['contact_id'] = $value;
                $where_format[] = '%d';
            }
        }
        if ( metis_contacts_column_exists( $details_table, 'did' ) ) {
            $value = trim( (string) ( $row->did ?? '' ) );
            if ( $value !== '' ) {
                $where['did'] = $value;
                $where_format[] = '%s';
            }
        }

        return [ $where, $where_format ];
    };

    $db->execute( 'START TRANSACTION' );

    $merged_records = [];
    $primary_id = 0;
    $primary_did = '';

    foreach ( $duplicate_cids as $duplicate_cid ) {
        $primary_data = $db->fetchOne( "SELECT * FROM {$contacts_table} WHERE cid = %s LIMIT 1", [ $primary_cid ] );
        $dup_data     = $db->fetchOne( "SELECT * FROM {$contacts_table} WHERE cid = %s LIMIT 1", [ $duplicate_cid ] );
        $primary = is_array( $primary_data ) ? (object) $primary_data : null;
        $dup     = is_array( $dup_data ) ? (object) $dup_data : null;
        if ( ! $primary || ! $dup ) {
            $db->execute( 'ROLLBACK' );
            metis_runtime_send_json_error( 'One or more contacts no longer exist.', 404 );
        }

        $primary_id  = (int) $primary->id;
        $dup_id      = (int) $dup->id;
        $primary_did = (string) ( $primary->did ?? '' );
        $dup_did     = (string) ( $dup->did ?? '' );

        $primary_detail_rows = $collect_detail_rows( $primary_cid, $primary_id, $primary_did );
        $dup_detail_rows     = $collect_detail_rows( $duplicate_cid, $dup_id, $dup_did );
        $primary_detail      = $primary_detail_rows[0] ?? null;
        $dup_detail          = $dup_detail_rows[0] ?? null;

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
            $tx_update = $db->update(
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

        $contact_update = $db->update(
            $contacts_table,
            [
                'first_name' => $primary_first,
                'last_name'  => $primary_last,
                'email'      => strtolower( trim( $primary_email ) ),
                'did'        => $primary_did !== '' ? $primary_did : '',
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
            $detail_seed = $primary_detail ?: $dup_detail;
            if ( metis_contacts_column_exists( $details_table, 'contact_cid' ) ) {
                $detail_payload['contact_cid'] = $primary_cid;
                $detail_format[] = '%s';
            }
            if ( metis_contacts_column_exists( $details_table, 'contact_id' ) ) {
                $detail_payload['contact_id'] = $primary_id;
                $detail_format[] = '%d';
            }
            if ( metis_contacts_column_exists( $details_table, 'did' ) ) {
                $detail_payload['did'] = $primary_did !== '' ? $primary_did : '';
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
            if ( ! $primary_detail && $detail_seed ) {
                foreach ( [
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
                ] as $column => $format ) {
                    if ( metis_contacts_column_exists( $details_table, $column ) ) {
                        $detail_payload[ $column ] = isset( $detail_seed->{$column} ) && $detail_seed->{$column} !== ''
                            ? $detail_seed->{$column}
                            : null;
                        $detail_format[] = $format;
                    }
                }
                foreach ( [
                    'do_not_contact',
                    'volunteer_status',
                    'anonymous_donor',
                ] as $column ) {
                    if ( metis_contacts_column_exists( $details_table, $column ) ) {
                        $detail_payload[ $column ] = ! empty( $detail_seed->{$column} ) ? 1 : 0;
                        $detail_format[] = '%d';
                    }
                }
            }
            if ( metis_contacts_column_exists( $details_table, 'updated_at' ) ) {
                $detail_payload['updated_at'] = metis_current_time( 'mysql' );
                $detail_format[] = '%s';
            }

            if ( ! empty( $primary_detail_rows ) ) {
                foreach ( $primary_detail_rows as $detail_row ) {
                    [ $detail_where, $detail_where_format ] = $detail_where_from_row( $detail_row );
                    if ( empty( $detail_where ) ) {
                        $rollback( 'Failed to identify primary detail row during merge.', [
                            'primary_cid' => $primary_cid,
                            'duplicate_cid' => $duplicate_cid,
                        ] );
                    }
                    $res = $db->update( $details_table, $detail_payload, $detail_where, $detail_format, $detail_where_format );
                    if ( $res === false ) {
                        $rollback( 'Failed to update primary detail during merge.', [
                            'primary_cid' => $primary_cid,
                            'duplicate_cid' => $duplicate_cid,
                        ] );
                    }
                }
            } elseif ( ! empty( $dup_detail_rows ) ) {
                foreach ( $dup_detail_rows as $detail_row ) {
                    [ $detail_where, $detail_where_format ] = $detail_where_from_row( $detail_row );
                    if ( empty( $detail_where ) ) {
                        $rollback( 'Failed to identify duplicate detail row during merge.', [
                            'primary_cid' => $primary_cid,
                            'duplicate_cid' => $duplicate_cid,
                        ] );
                    }
                    $res = $db->update( $details_table, $detail_payload, $detail_where, $detail_format, $detail_where_format );
                    if ( $res === false ) {
                        $rollback( 'Failed to repoint duplicate detail during merge.', [
                            'primary_cid' => $primary_cid,
                            'duplicate_cid' => $duplicate_cid,
                        ] );
                    }
                }
                $primary_detail = (object) array_merge( (array) $dup_detail, $detail_payload );
            } else {
                $res = $db->insert( $details_table, $detail_payload, $detail_format );
                if ( $res === false ) {
                    $rollback( 'Failed to create primary detail during merge.', [
                        'primary_cid' => $primary_cid,
                        'duplicate_cid' => $duplicate_cid,
                        'primary_detail_rows' => count( $primary_detail_rows ),
                        'duplicate_detail_rows' => count( $dup_detail_rows ),
                        'detail_columns' => array_keys( $detail_payload ),
                    ] );
                }
            }

            if ( metis_contacts_column_exists( $details_table, 'relationships_json' ) ) {
                $like = '%' . $db->escapeLike( '"related_contact_cid":"' . $duplicate_cid . '"' ) . '%';
                $related_rows = array_map(
                    static fn( array $row ): object => (object) $row,
                    $db->fetchAll( "SELECT id, relationships_json, contact_cid FROM {$details_table} WHERE relationships_json LIKE %s", [ $like ] ) ?: []
                );
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
                    $res = $db->update( $details_table, [ 'relationships_json' => metis_json_encode( $rels ) ], [ 'id' => (int) $r->id ], [ '%s' ], [ '%d' ] );
                    if ( $res === false ) $rollback( 'Failed to normalize related relationships during merge.' );
                }
            }
        }

        if ( metis_contacts_table_exists( $notes_table ) ) {
            if ( metis_contacts_column_exists( $notes_table, 'cid' ) ) {
                $res = $db->update( $notes_table, [ 'cid' => $primary_cid ], [ 'cid' => $duplicate_cid ], [ '%s' ], [ '%s' ] );
                if ( $res === false ) $rollback( 'Failed to remap notes by cid during merge.' );
            }
            if ( metis_contacts_column_exists( $notes_table, 'did' ) && $dup_did !== '' ) {
                $res = $db->update(
                    $notes_table,
                    [ 'did' => $primary_did !== '' ? $primary_did : null ],
                    [ 'did' => $dup_did ],
                    [ '%s' ],
                    [ '%s' ]
                );
                if ( $res === false ) $rollback( 'Failed to remap notes by donor id during merge.' );
            }
            if ( metis_contacts_column_exists( $notes_table, 'contact_id' ) ) {
                $res = $db->update( $notes_table, [ 'contact_id' => $primary_id ], [ 'contact_id' => $dup_id ], [ '%d' ], [ '%d' ] );
                if ( $res === false ) $rollback( 'Failed to remap notes by contact id during merge.' );
            }
        }

        if ( metis_contacts_table_exists( $details_table ) ) {
            if ( metis_contacts_column_exists( $details_table, 'contact_cid' ) ) {
                $res = $db->delete( $details_table, [ 'contact_cid' => $duplicate_cid ], [ '%s' ] );
                if ( $res === false ) $rollback( 'Failed to remove duplicate detail row.' );
            } elseif ( metis_contacts_column_exists( $details_table, 'contact_id' ) ) {
                $res = $db->delete( $details_table, [ 'contact_id' => $dup_id ], [ '%d' ] );
                if ( $res === false ) $rollback( 'Failed to remove duplicate detail row.' );
            }
        }

        $contact_delete = $db->delete( $contacts_table, [ 'id' => $dup_id ], [ '%d' ] );
        if ( $contact_delete === false ) {
            $rollback( 'Failed to remove duplicate contact after merge.' );
        }

        $merged_records[] = [
            'cid' => $duplicate_cid,
            'did' => $dup_did,
        ];
    }

    $primary_after_data = $db->fetchOne( "SELECT * FROM {$contacts_table} WHERE cid = %s LIMIT 1", [ $primary_cid ] );
    $primary_after = is_array( $primary_after_data ) ? (object) $primary_after_data : null;
    $primary_id = $primary_after ? (int) $primary_after->id : $primary_id;
    $primary_did = $primary_after ? (string) ( $primary_after->did ?? '' ) : $primary_did;

    $actor = metis_runtime_current_user();
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
            $note_payload['created_at'] = metis_current_time( 'mysql' );
            $note_format[] = '%s';
        }
        $res = $db->insert( $notes_table, $note_payload, $note_format );
        if ( $res === false ) $rollback( 'Failed to create merge system note.' );
    }

    $db->execute( 'COMMIT' );

    $primary_contact_payload = null;
    if ( $primary_after && function_exists( 'metis_contacts_ajax_format_row' ) ) {
        $primary_detail = null;
        if ( function_exists( 'metis_contacts_collect_linked_detail_rows' ) && metis_contacts_table_exists( $details_table ) ) {
            $detail_rows = metis_contacts_collect_linked_detail_rows( $details_table, $primary_cid, $primary_id, $primary_did );
            $primary_detail = $detail_rows[0] ?? null;
        }
        $primary_contact_payload = metis_contacts_ajax_format_row( $primary_after, $primary_detail );
    }

    metis_runtime_send_json_success( [
        'primary_cid' => $primary_cid,
        'primary_contact' => $primary_contact_payload,
        'merged_cids' => array_map( static function ( array $item ) {
            return $item['cid'];
        }, $merged_records ),
        'merged_count' => count( $merged_records ),
        'message'     => 'Contacts merged successfully.',
    ] );
} );

metis_ajax_register_handler( 'metis_contacts_cleanup_merge_notes', function () {
    metis_contacts_ajax_verify_nonce();

    if ( ! metis_contacts_can_manage() ) {
        metis_runtime_send_json_error( 'Unauthorized', 403 );
    }

    metis_contacts_ensure_schema();
    $result = metis_contacts_cleanup_merge_notes();

    metis_runtime_send_json_success( [
        'groups_consolidated' => (int) ( $result['groups_consolidated'] ?? 0 ),
        'notes_created'       => (int) ( $result['notes_created'] ?? 0 ),
        'notes_deleted'       => (int) ( $result['notes_deleted'] ?? 0 ),
    ] );
} );
