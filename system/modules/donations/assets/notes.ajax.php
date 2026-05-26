<?php
if ( ! defined( 'METIS_ROOT' ) ) exit;

require_once dirname( __DIR__, 3 ) . '/src/Metis/Core/Integrations/StripeRuntimeBootstrap.php';

/**
 * Donations Notes AJAX Handlers
 *
 * Endpoints:
 *   metis_ajax_metis_add_batch_note
 *   metis_ajax_metis_update_note
 *   metis_ajax_metis_delete_note
 */

Metis_Logger::info( 'Donations Notes AJAX loaded' );

if ( function_exists( 'metis_ajax_register_controller' ) ) {
    metis_ajax_register_controller( 'metis_add_batch_note', [
        'module' => 'donations',
        'permission' => 'edit',
        'nonce_action' => metis_ajax_nonce_action( 'metis_add_batch_note' ),
    ] );
    metis_ajax_register_controller( 'metis_update_note', [
        'module' => 'donations',
        'permission' => 'edit',
        'nonce_action' => metis_ajax_nonce_action( 'metis_update_note' ),
    ] );
    metis_ajax_register_controller( 'metis_delete_note', [
        'module' => 'donations',
        'permission' => 'delete',
        'nonce_action' => metis_ajax_nonce_action( 'metis_delete_note' ),
    ] );
    metis_ajax_register_controller( 'metis_add_transaction_note', [
        'module' => 'donations',
        'permission' => 'edit',
        'nonce_action' => metis_ajax_nonce_action( 'metis_add_transaction_note' ),
    ] );
    metis_ajax_register_controller( 'metis_record_transaction_refund', [
        'module' => 'donations',
        'permission' => 'edit',
        'nonce_action' => metis_ajax_nonce_action( 'metis_record_transaction_refund' ),
    ] );
}

function metis_donations_notes_ajax_verify( string $action, string $permission = 'edit' ): void {
    $nonce = '';
    foreach ( [ 'metis_action_nonce', 'nonce' ] as $field ) {
        $value = metis_request_post()[ $field ] ?? '';
        if ( is_scalar( $value ) ) {
            $nonce = trim( (string) metis_runtime_unslash( $value ) );
            if ( $nonce !== '' ) {
                break;
            }
        }
    }

    $nonce_action = function_exists( 'metis_ajax_nonce_action' )
        ? metis_ajax_nonce_action( $action )
        : $action;

    if ( $nonce === '' || ! function_exists( 'metis_runtime_verify_nonce' ) || ! metis_runtime_verify_nonce( $nonce, $nonce_action ) ) {
        metis_runtime_send_json_error( [ 'message' => 'Invalid nonce.' ], 403 );
    }

    $allowed = match ( $permission ) {
        'delete' => function_exists( 'metis_donations_can_delete' ) && metis_donations_can_delete(),
        'view' => function_exists( 'metis_donations_can' ) && metis_donations_can( 'view' ),
        default => function_exists( 'metis_donations_can_manage' ) && metis_donations_can_manage(),
    };

    if ( ! $allowed ) {
        metis_runtime_send_json_error( [ 'message' => 'Unauthorized.' ], 403 );
    }
}

function metis_donations_notes_ensure_tables(): void {
    $db = metis_db();
    $charset = function_exists( 'metis_core_db_charset_collate' ) ? metis_core_db_charset_collate() : 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';
    $notes_table = Metis_Tables::get( 'transaction_notes' );
    $refunds_table = Metis_Tables::get( 'transaction_refunds' );

    $db->execute( "CREATE TABLE IF NOT EXISTS {$notes_table} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        tid VARCHAR(32) NOT NULL,
        note TEXT NOT NULL,
        user_id BIGINT UNSIGNED DEFAULT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY tid (tid)
    ) {$charset}" );

    $db->execute( "CREATE TABLE IF NOT EXISTS {$refunds_table} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        tid VARCHAR(32) NOT NULL,
        refund_date DATE NOT NULL,
        amount DECIMAL(10,2) NOT NULL,
        reason VARCHAR(255) DEFAULT NULL,
        notes TEXT DEFAULT NULL,
        source VARCHAR(32) NOT NULL DEFAULT 'manual',
        stripe_refund_id VARCHAR(64) DEFAULT NULL,
        refunded_by BIGINT UNSIGNED DEFAULT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY tid (tid),
        KEY stripe_refund_id (stripe_refund_id)
    ) {$charset}" );

    $note_columns = $db->column( "SHOW COLUMNS FROM {$notes_table}" );
    if ( ! in_array( 'updated_at', $note_columns, true ) ) {
        $db->execute( "ALTER TABLE {$notes_table} ADD COLUMN updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP" );
    }

    $refund_columns = $db->column( "SHOW COLUMNS FROM {$refunds_table}" );
    $refund_alters = [
        'refund_date' => "ADD COLUMN refund_date DATE NULL AFTER tid",
        'notes' => "ADD COLUMN notes TEXT DEFAULT NULL AFTER reason",
        'source' => "ADD COLUMN source VARCHAR(32) NOT NULL DEFAULT 'manual' AFTER notes",
        'stripe_refund_id' => "ADD COLUMN stripe_refund_id VARCHAR(64) DEFAULT NULL AFTER source",
        'refunded_by' => "ADD COLUMN refunded_by BIGINT UNSIGNED DEFAULT NULL AFTER stripe_refund_id",
        'updated_at' => "ADD COLUMN updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP",
    ];
    foreach ( $refund_alters as $column => $alter_sql ) {
        if ( ! in_array( $column, $refund_columns, true ) ) {
            $db->execute( "ALTER TABLE {$refunds_table} {$alter_sql}" );
        }
    }
}

function metis_donations_current_user_id(): ?int {
    $id = function_exists( 'metis_current_user_id' ) ? (int) metis_current_user_id() : 0;
    return $id > 0 ? $id : null;
}

function metis_donations_transaction_exists( string $tid ): ?array {
    return metis_db()->fetchOne(
        'SELECT tid, amount, stripe_charge_id, stripe_pay_int, stripe_refund_id FROM ' . Metis_Tables::get( 'transactions' ) . ' WHERE tid = %s LIMIT 1',
        [ $tid ]
    );
}

// -------------------------------------------------------------------------
// Add batch note
// -------------------------------------------------------------------------

metis_ajax_register_handler( 'metis_add_batch_note', function () {
    metis_donations_notes_ajax_verify( 'metis_add_batch_note', 'edit' );

    $batch = metis_text_clean( metis_request_post()['batch_code'] ?? '' );
    $text  = metis_text_clean( metis_request_post()['text']       ?? '' );

    if ( ! $batch || ! $text ) {
        metis_runtime_send_json_error( [ 'message' => 'Missing batch_code or text' ], 400 );
    }

    metis_add_batch_note( $batch, $text );
    metis_add_batch_audit( $batch, 'note_added', $text );

    metis_runtime_send_json_success();
}, [
    'module' => 'donations',
    'permission' => 'edit',
] );

// -------------------------------------------------------------------------
// Update batch note
// -------------------------------------------------------------------------

metis_ajax_register_handler( 'metis_update_note', function () {
    metis_donations_notes_ajax_verify( 'metis_update_note', 'edit' );

    $db = metis_db();

    $id    = intval( metis_request_post()['id']    ?? 0 );
    $text  = metis_textarea_clean( metis_request_post()['text']  ?? '' );
    $batch = metis_text_clean( metis_request_post()['batch'] ?? '' );

    if ( ! $id || ! $batch ) {
        metis_runtime_send_json_error( [ 'message' => 'Missing id or batch' ], 400 );
    }

    $db->update(
        Metis_Tables::get( 'batch_notes' ),
        [ 'note_text' => $text ],
        [ 'id'        => $id   ]
    );

    metis_add_batch_audit( $batch, 'note_updated', metis_json_encode( [ 'note_id' => $id ] ) );

    metis_runtime_send_json_success();
}, [
    'module' => 'donations',
    'permission' => 'edit',
] );

// -------------------------------------------------------------------------
// Delete batch note
// -------------------------------------------------------------------------

metis_ajax_register_handler( 'metis_delete_note', function () {
    metis_donations_notes_ajax_verify( 'metis_delete_note', 'delete' );

    $db = metis_db();

    $id    = intval( metis_request_post()['id']    ?? 0 );
    $batch = metis_text_clean( metis_request_post()['batch'] ?? '' );

    if ( ! $id || ! $batch ) {
        metis_runtime_send_json_error( [ 'message' => 'Missing id or batch' ], 400 );
    }

    $db->delete(
        Metis_Tables::get( 'batch_notes' ),
        [ 'id' => $id ]
    );

    metis_add_batch_audit( $batch, 'note_deleted', metis_json_encode( [ 'note_id' => $id ] ) );

    metis_runtime_send_json_success();
}, [
    'module' => 'donations',
    'permission' => 'delete',
] );

// -------------------------------------------------------------------------
// Add transaction note
// -------------------------------------------------------------------------

metis_ajax_register_handler( 'metis_add_transaction_note', function () {
    metis_donations_notes_ajax_verify( 'metis_add_transaction_note', 'edit' );

    metis_donations_notes_ensure_tables();

    $post = metis_request_post();
    $tid  = metis_text_clean( $post['tid'] ?? '' );
    $note = metis_textarea_clean( $post['note'] ?? '' );

    if ( $tid === '' || $note === '' ) {
        metis_runtime_send_json_error( [ 'message' => 'Missing transaction ID or note.' ], 400 );
    }

    if ( ! metis_donations_transaction_exists( $tid ) ) {
        metis_runtime_send_json_error( [ 'message' => 'Transaction not found.' ], 404 );
    }

    $now = metis_current_time( 'mysql' );
    $ok = metis_db()->insert( Metis_Tables::get( 'transaction_notes' ), [
        'tid'        => $tid,
        'note'       => $note,
        'user_id'    => metis_donations_current_user_id(),
        'created_at' => $now,
        'updated_at' => $now,
    ] );

    if ( ! $ok ) {
        metis_runtime_send_json_error( [ 'message' => 'Failed to save note.' ], 500 );
    }

    metis_runtime_send_json_success( [
        'note' => [
            'id' => (int) metis_db()->lastInsertId(),
            'note' => $note,
            'display_name' => 'You',
            'created_at' => $now,
        ],
    ] );
}, [
    'module' => 'donations',
    'permission' => 'edit',
] );

// -------------------------------------------------------------------------
// Record transaction refund
// -------------------------------------------------------------------------

metis_ajax_register_handler( 'metis_record_transaction_refund', function () {
    metis_donations_notes_ajax_verify( 'metis_record_transaction_refund', 'edit' );

    metis_donations_notes_ensure_tables();

    $post = metis_request_post();
    $tid = metis_text_clean( $post['tid'] ?? '' );
    $amount = isset( $post['amount'] ) ? round( (float) $post['amount'], 2 ) : 0.0;
    $reason = metis_text_clean( $post['reason'] ?? '' );
    $notes = metis_textarea_clean( $post['notes'] ?? '' );
    $source = metis_key_clean( (string) ( $post['source'] ?? 'stripe' ) );
    if ( ! in_array( $source, [ 'stripe', 'manual' ], true ) ) {
        $source = 'stripe';
    }

    if ( $tid === '' || $amount <= 0 ) {
        metis_runtime_send_json_error( [ 'message' => 'Missing transaction ID or refund amount.' ], 400 );
    }

    $transaction = metis_donations_transaction_exists( $tid );
    if ( ! $transaction ) {
        metis_runtime_send_json_error( [ 'message' => 'Transaction not found.' ], 404 );
    }

    $db = metis_db();
    $refunds_table = Metis_Tables::get( 'transaction_refunds' );
    $transactions_table = Metis_Tables::get( 'transactions' );
    $now = metis_current_time( 'mysql' );
    $refund_date = metis_current_time( 'Y-m-d' );
    $stripe_refund_id = null;
    $stripe_charge_id = trim( (string) ( $transaction['stripe_charge_id'] ?? '' ) );
    $stripe_payment_intent = trim( (string) ( $transaction['stripe_pay_int'] ?? '' ) );
    $existing_refunded = (float) $db->scalar( "SELECT COALESCE(SUM(amount), 0) FROM {$refunds_table} WHERE tid = %s", [ $tid ] );
    $remaining = max( 0, (float) ( $transaction['amount'] ?? 0 ) - $existing_refunded );

    if ( $amount > $remaining + 0.0001 ) {
        metis_runtime_send_json_error( [ 'message' => 'Refund amount exceeds the remaining refundable amount.' ], 400 );
    }

    if ( $source === 'stripe' ) {
        $stripe = function_exists( 'metis_stripe_client' ) ? metis_stripe_client() : null;
        if ( ! $stripe ) {
            metis_runtime_send_json_error( [ 'message' => 'Stripe is not configured.' ], 500 );
        }
        if ( $stripe_charge_id === '' && $stripe_payment_intent === '' ) {
            metis_runtime_send_json_error( [ 'message' => 'This transaction is not linked to a Stripe charge or payment intent.' ], 400 );
        }

        $stripe_payload = [
            'amount' => (int) round( $amount * 100 ),
            'metadata' => [
                'metis_tid' => $tid,
                'metis_reason' => $reason,
            ],
        ];
        if ( $stripe_charge_id !== '' ) {
            $stripe_payload['charge'] = $stripe_charge_id;
        } else {
            $stripe_payload['payment_intent'] = $stripe_payment_intent;
        }
        if ( $reason !== '' ) {
            $stripe_payload['reason'] = in_array( $reason, [ 'duplicate', 'fraudulent', 'requested_by_customer' ], true )
                ? $reason
                : 'requested_by_customer';
        }

        try {
            $stripe_refund = $stripe->createRefund(
                $stripe_payload,
                [ 'idempotency_key' => 'metis-refund-' . $tid . '-' . hash( 'sha256', $amount . '|' . $reason . '|' . $notes . '|' . $now ) ]
            );
            $stripe_refund_id = (string) ( $stripe_refund->id ?? '' );
        } catch ( \Throwable $e ) {
            metis_runtime_send_json_error( [ 'message' => 'Stripe refund failed: ' . $e->getMessage() ], 502 );
        }
    }

    $ok = $db->insert( $refunds_table, [
        'tid'         => $tid,
        'refund_date' => $refund_date,
        'amount'      => $amount,
        'reason'      => $reason !== '' ? $reason : null,
        'notes'       => $notes !== '' ? $notes : null,
        'source'      => $source,
        'stripe_refund_id' => $stripe_refund_id !== '' ? $stripe_refund_id : null,
        'refunded_by' => metis_donations_current_user_id(),
        'created_at'  => $now,
        'updated_at'  => $now,
    ] );

    if ( ! $ok ) {
        metis_runtime_send_json_error( [ 'message' => 'Failed to record refund.' ], 500 );
    }

    $total_refunded = (float) $db->scalar( "SELECT COALESCE(SUM(amount), 0) FROM {$refunds_table} WHERE tid = %s", [ $tid ] );
    $tx_columns = $db->column( "SHOW COLUMNS FROM {$transactions_table}" );
    $update = [ 'updated_at' => $now ];
    if ( $stripe_refund_id !== null && $stripe_refund_id !== '' && in_array( 'stripe_refund_id', $tx_columns, true ) ) {
        $update['stripe_refund_id'] = $stripe_refund_id;
    }
    if ( $total_refunded >= (float) ( $transaction['amount'] ?? 0 ) ) {
        $update['status'] = 'Refunded';
        if ( in_array( 'refunded', $tx_columns, true ) ) {
            $update['refunded'] = 1;
        }
        if ( in_array( 'refunded_at', $tx_columns, true ) ) {
            $update['refunded_at'] = $now;
        }
    }
    $db->update( $transactions_table, $update, [ 'tid' => $tid ] );

    metis_runtime_send_json_success( [
        'refund' => [
            'id' => (int) $db->lastInsertId(),
            'amount' => $amount,
            'reason' => $reason,
            'notes' => $notes,
            'source' => $source,
            'stripe_refund_id' => $stripe_refund_id,
            'display_name' => 'You',
            'created_at' => $now,
        ],
        'total_refunded' => $total_refunded,
        'net_after_refunds' => max( 0, (float) ( $transaction['amount'] ?? 0 ) - $total_refunded ),
    ] );
}, [
    'module' => 'donations',
    'permission' => 'edit',
] );
