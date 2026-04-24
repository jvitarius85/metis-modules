<?php
declare(strict_types=1);

if ( ! defined( 'METIS_ROOT' ) ) {
    exit;
}

function metis_finance_ajax_current_user_id(): int {
    if ( function_exists( 'metis_people_get_current_person_id' ) ) {
        return (int) metis_people_get_current_person_id();
    }
    return function_exists( 'metis_current_user_id' ) ? (int) metis_current_user_id() : 0;
}

function metis_finance_ajax_post_value( string $key, mixed $default = '' ): mixed {
    if ( ! isset( $_POST[ $key ] ) ) {
        return $default;
    }
    return metis_runtime_unslash( $_POST[ $key ] );
}

function metis_finance_ajax_post_json_array( string $key ): array {
    $raw = metis_finance_ajax_post_value( $key, '' );
    if ( is_array( $raw ) ) {
        return $raw;
    }
    if ( ! is_string( $raw ) || trim( $raw ) === '' ) {
        return [];
    }
    $decoded = json_decode( $raw, true );
    return is_array( $decoded ) ? $decoded : [];
}

function metis_finance_ajax_verify_nonce( array $actions = [] ): void {
    $nonceCandidates = [];
    foreach ( [ 'nonce', 'metis_action_nonce', '_wpnonce' ] as $field ) {
        $value = metis_finance_ajax_post_value( $field, '' );
        if ( is_scalar( $value ) ) {
            $token = metis_text_clean( (string) $value );
            if ( $token !== '' ) {
                $nonceCandidates[] = $token;
            }
        }
    }

    $nonceCandidates = array_values( array_unique( $nonceCandidates ) );
    $nonceActions = $actions === [] ? [ 'metis_finance', 'metis_core' ] : $actions;

    $requestAction = metis_key_clean( (string) metis_finance_ajax_post_value( 'action', '' ) );
    if ( $requestAction !== '' && ! in_array( $requestAction, $nonceActions, true ) ) {
        $nonceActions[] = $requestAction;
    }

    foreach ( $nonceCandidates as $token ) {
        foreach ( $nonceActions as $action ) {
            if ( metis_runtime_verify_nonce( $token, $action ) ) {
                return;
            }
            if ( function_exists( 'metis_ajax_nonce_action' ) ) {
                $ajaxAction = metis_ajax_nonce_action( $action );
                if ( metis_runtime_verify_nonce( $token, $ajaxAction ) ) {
                    return;
                }
            }
        }
    }

    metis_runtime_send_json_error( 'Invalid nonce.', 403 );
}

function metis_finance_ajax_require_view(): void {
    if ( ! function_exists( 'metis_finance_can_view' ) || ! metis_finance_can_view() ) {
        metis_runtime_send_json_error( 'Unauthorized', 403 );
    }
}

function metis_finance_ajax_require_manage(): void {
    if ( ! function_exists( 'metis_finance_can_manage' ) || ! metis_finance_can_manage() ) {
        metis_runtime_send_json_error( 'Unauthorized', 403 );
    }
}

function metis_finance_ajax_send_service_result( array $result ): void {
    $ok = ! empty( $result['ok'] );
    if ( ! $ok ) {
        $status = (int) ( $result['status'] ?? 422 );
        $message = (string) ( $result['message'] ?? 'Finance request failed.' );
        metis_runtime_send_json_error( $message, $status );
    }
    metis_runtime_send_json_success( $result );
}

function metis_finance_register_ajax_controllers(): void {
    if ( ! function_exists( 'metis_ajax_register_controller' ) ) {
        return;
    }

    $actions = [
        'metis_finance_mode_switch_schedule' => [ 'permission' => 'edit', 'nonce_action' => metis_ajax_nonce_action( 'metis_finance_mode_switch_schedule' ) ],
        'metis_finance_mode_switch_status' => [ 'permission' => 'view', 'nonce_action' => metis_ajax_nonce_action( 'metis_finance_mode_switch_status' ) ],
        'metis_finance_mode_status' => [ 'permission' => 'view', 'nonce_action' => metis_ajax_nonce_action( 'metis_finance_mode_status' ) ],

        'metis_finance_v2_bootstrap' => [ 'permission' => 'view', 'nonce_action' => 'metis_finance' ],
        'metis_finance_v2_gl_entries_list' => [ 'permission' => 'view', 'nonce_action' => 'metis_finance' ],
        'metis_finance_v2_gl_create' => [ 'permission' => 'edit', 'nonce_action' => 'metis_finance_v2_gl_create' ],
        'metis_finance_v2_categories_list' => [ 'permission' => 'view', 'nonce_action' => 'metis_finance' ],
        'metis_finance_v2_category_save' => [ 'permission' => 'edit', 'nonce_action' => 'metis_finance_v2_category_save' ],

        'metis_finance_v2_recon_import' => [ 'permission' => 'edit', 'nonce_action' => 'metis_finance_v2_recon_import' ],
        'metis_finance_v2_recon_workflow' => [ 'permission' => 'view', 'nonce_action' => 'metis_finance' ],
        'metis_finance_v2_recon_item_toggle' => [ 'permission' => 'edit', 'nonce_action' => 'metis_finance_v2_recon_review' ],
        'metis_finance_v2_recon_finalize' => [ 'permission' => 'edit', 'nonce_action' => 'metis_finance_v2_recon_review' ],
        'metis_finance_v2_recon_reopen' => [ 'permission' => 'edit', 'nonce_action' => 'metis_finance_v2_recon_review' ],
        'metis_finance_v2_recon_delete' => [ 'permission' => 'edit', 'nonce_action' => 'metis_finance_v2_recon_review' ],
        'metis_finance_v2_recon_mapping_list' => [ 'permission' => 'view', 'nonce_action' => 'metis_finance' ],
        'metis_finance_v2_recon_mapping' => [ 'permission' => 'edit', 'nonce_action' => 'metis_finance_v2_recon_mapping' ],
        'metis_finance_v2_recon_review' => [ 'permission' => 'edit', 'nonce_action' => 'metis_finance_v2_recon_review' ],
        'metis_finance_v2_recon_match_line' => [ 'permission' => 'edit', 'nonce_action' => 'metis_finance_v2_recon_review' ],

        'metis_finance_v2_budget_snapshot' => [ 'permission' => 'view', 'nonce_action' => 'metis_finance' ],
        'metis_finance_v2_budget_version' => [ 'permission' => 'edit', 'nonce_action' => 'metis_finance_v2_budget_version' ],
        'metis_finance_v2_budget_lines' => [ 'permission' => 'edit', 'nonce_action' => 'metis_finance_v2_budget_lines' ],

        'metis_finance_v2_invoices_list' => [ 'permission' => 'view', 'nonce_action' => 'metis_finance' ],
        'metis_finance_v2_invoice_create' => [ 'permission' => 'edit', 'nonce_action' => 'metis_finance_v2_invoice_create' ],
        'metis_finance_v2_invoice_send' => [ 'permission' => 'edit', 'nonce_action' => 'metis_finance_v2_invoice_send' ],
        'metis_finance_v2_invoice_paid' => [ 'permission' => 'edit', 'nonce_action' => 'metis_finance_v2_invoice_paid' ],

        'metis_finance_v2_fiscal_settings_get' => [ 'permission' => 'view', 'nonce_action' => 'metis_finance' ],
        'metis_finance_v2_fiscal_settings' => [ 'permission' => 'edit', 'nonce_action' => 'metis_finance_v2_fiscal_settings' ],
        'metis_finance_v2_fiscal_migrate' => [ 'permission' => 'edit', 'nonce_action' => 'metis_finance_v2_fiscal_migrate' ],

        'metis_finance_v2_reports_snapshot' => [ 'permission' => 'view', 'nonce_action' => 'metis_finance' ],
        'metis_finance_v2_report_render' => [ 'permission' => 'view', 'nonce_action' => 'metis_finance_v2_report_render' ],
        'metis_finance_v2_report_pdf' => [ 'permission' => 'view', 'nonce_action' => 'metis_finance_v2_report_pdf' ],

        'metis_finance_v2_stripe_overview' => [ 'permission' => 'view', 'nonce_action' => 'metis_finance' ],
        'metis_finance_v2_stripe_event' => [ 'permission' => 'edit', 'nonce_action' => 'metis_finance_v2_stripe_event' ],
        'metis_finance_v2_stripe_payout' => [ 'permission' => 'edit', 'nonce_action' => 'metis_finance_v2_stripe_payout' ],
        'metis_finance_v2_bank_line' => [ 'permission' => 'edit', 'nonce_action' => 'metis_finance_v2_bank_line' ],
        'metis_finance_v2_stripe_match' => [ 'permission' => 'edit', 'nonce_action' => 'metis_finance_v2_stripe_match' ],
        'metis_finance_v2_stripe_auto_match' => [ 'permission' => 'edit', 'nonce_action' => 'metis_finance_v2_stripe_match' ],
    ];

    foreach ( $actions as $action => $config ) {
        metis_ajax_register_controller( $action, [
            'module' => 'finance',
            'permission' => (string) $config['permission'],
            'nonce_action' => (string) $config['nonce_action'],
        ] );
    }
}

metis_finance_register_ajax_controllers();

metis_ajax_register_handler( 'metis_finance_mode_switch_schedule', static function (): void {
    metis_finance_ajax_verify_nonce( [ 'metis_finance_mode_switch_schedule', 'metis_finance', 'metis_core' ] );
    metis_finance_ajax_require_manage();

    $targetMode = metis_key_clean( (string) metis_finance_ajax_post_value( 'target_mode', '' ) );
    $effectiveAt = metis_text_clean( (string) metis_finance_ajax_post_value( 'effective_at', '' ) );

    $result = \Metis\Modules\Finance\ModeService::scheduleSwitch( $targetMode, $effectiveAt, metis_finance_ajax_current_user_id() );
    if ( empty( $result['ok'] ) ) {
        metis_runtime_send_json_error( (string) ( $result['message'] ?? 'Unable to schedule mode switch.' ), (int) ( $result['status'] ?? 422 ) );
    }

    metis_runtime_send_json_success( [
        'mode' => \Metis\Modules\Finance\ModeService::currentMode(),
        'scheduled' => $result,
    ] );
} );

metis_ajax_register_handler( 'metis_finance_mode_switch_status', static function (): void {
    metis_finance_ajax_verify_nonce( [ 'metis_finance_mode_switch_status', 'metis_finance', 'metis_core' ] );
    metis_finance_ajax_require_view();
    metis_runtime_send_json_success( \Metis\Modules\Finance\ModeService::switchStatus() );
} );

metis_ajax_register_handler( 'metis_finance_mode_status', static function (): void {
    metis_finance_ajax_verify_nonce( [ 'metis_finance_mode_status', 'metis_finance', 'metis_core' ] );
    metis_finance_ajax_require_view();
    metis_runtime_send_json_success( [
        'mode' => \Metis\Modules\Finance\ModeService::currentMode(),
        'can_manage' => \Metis\Modules\Finance\Access::canManage(),
        'has_finance_role' => \Metis\Modules\Finance\ModeService::currentUserHasFinanceRole(),
    ] );
} );

metis_ajax_register_handler( 'metis_finance_v2_bootstrap', static function (): void {
    metis_finance_ajax_verify_nonce( [ 'metis_finance', 'metis_core' ] );
    metis_finance_ajax_require_view();
    metis_runtime_send_json_success( \Metis\Modules\Finance\FinanceV2Service::bootstrapData( 25 ) );
} );

metis_ajax_register_handler( 'metis_finance_v2_gl_entries_list', static function (): void {
    metis_finance_ajax_verify_nonce( [ 'metis_finance', 'metis_core' ] );
    metis_finance_ajax_require_view();
    $limit = max( 1, min( 100, (int) metis_finance_ajax_post_value( 'limit', 50 ) ) );
    metis_runtime_send_json_success( [
        'entries' => \Metis\Modules\Finance\FinanceV2Service::entries( $limit ),
        'summary' => \Metis\Modules\Finance\FinanceV2Service::summary(),
    ] );
} );

metis_ajax_register_handler( 'metis_finance_v2_gl_create', static function (): void {
    metis_finance_ajax_verify_nonce( [ 'metis_finance_v2_gl_create', 'metis_finance', 'metis_core' ] );
    metis_finance_ajax_require_manage();

    $input = [
        'entry_date' => metis_text_clean( (string) metis_finance_ajax_post_value( 'entry_date', '' ) ),
        'account_code' => metis_key_clean( (string) metis_finance_ajax_post_value( 'account_code', '' ) ),
        'description' => metis_text_clean( (string) metis_finance_ajax_post_value( 'description', '' ) ),
        'amount' => (float) metis_finance_ajax_post_value( 'amount', 0 ),
        'dc_type' => metis_key_clean( (string) metis_finance_ajax_post_value( 'dc_type', '' ) ),
        'category_code' => metis_key_clean( (string) metis_finance_ajax_post_value( 'category_code', '' ) ),
    ];

    $result = \Metis\Modules\Finance\FinanceV2Service::createEntry( $input, metis_finance_ajax_current_user_id() );
    metis_finance_ajax_send_service_result( $result );
} );

metis_ajax_register_handler( 'metis_finance_v2_categories_list', static function (): void {
    metis_finance_ajax_verify_nonce( [ 'metis_finance', 'metis_core' ] );
    metis_finance_ajax_require_view();
    metis_runtime_send_json_success( [
        'categories' => \Metis\Modules\Finance\FinanceV2Service::categories(),
        'categories_all' => \Metis\Modules\Finance\FinanceV2Service::categories( true ),
    ] );
} );

metis_ajax_register_handler( 'metis_finance_v2_category_save', static function (): void {
    metis_finance_ajax_verify_nonce( [ 'metis_finance_v2_category_save', 'metis_finance', 'metis_core' ] );
    metis_finance_ajax_require_manage();

    $input = [
        'category_name' => metis_text_clean( (string) metis_finance_ajax_post_value( 'category_name', '' ) ),
        'category_code' => metis_key_clean( (string) metis_finance_ajax_post_value( 'category_code', '' ) ),
        'is_active' => (int) metis_finance_ajax_post_value( 'is_active', 1 ),
        'sort_order' => (int) metis_finance_ajax_post_value( 'sort_order', 0 ),
    ];

    $result = \Metis\Modules\Finance\FinanceV2Service::saveCategory( $input, metis_finance_ajax_current_user_id() );
    metis_finance_ajax_send_service_result( $result );
} );

metis_ajax_register_handler( 'metis_finance_v2_recon_import', static function (): void {
    metis_finance_ajax_verify_nonce( [ 'metis_finance_v2_recon_import', 'metis_finance', 'metis_core' ] );
    metis_finance_ajax_require_manage();

    $input = [
        'recon_month' => metis_text_clean( (string) metis_finance_ajax_post_value( 'recon_month', '' ) ),
        'starting_balance' => metis_text_clean( (string) metis_finance_ajax_post_value( 'starting_balance', '' ) ),
        'statement_ending_balance' => metis_text_clean( (string) metis_finance_ajax_post_value( 'statement_ending_balance', '' ) ),
    ];

    $requestId = function_exists( 'metis_audit_request_id' ) ? (string) metis_audit_request_id() : '';
    $requestedBy = metis_finance_ajax_current_user_id();

    if ( class_exists( 'Metis_Logger' ) ) {
        Metis_Logger::info( 'finance.v2.recon_import.received', [
            'request_id' => $requestId,
            'requested_by' => $requestedBy,
            'recon_month' => $input['recon_month'],
            'has_file' => isset( $_FILES['recon_file'] ) ? 1 : 0,
            'file_keys' => array_keys( is_array( $_FILES ) ? $_FILES : [] ),
        ] );
    }

    try {
        $result = \Metis\Modules\Finance\FinanceV2Service::createReconciliationImportRun( $input, $requestedBy, is_array( $_FILES ) ? $_FILES : [] );
    } catch ( \Throwable $e ) {
        if ( class_exists( 'Metis_Logger' ) ) {
            Metis_Logger::error( 'finance.v2.recon_import.exception', [
                'request_id' => $requestId,
                'requested_by' => $requestedBy,
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ] );
        }
        $result = [
            'ok' => false,
            'status' => 500,
            'message' => 'Reconciliation upload failed: ' . metis_text_clean( $e->getMessage() ),
        ];
    }

    $status = (int) ( $result['status'] ?? ( ! empty( $result['ok'] ) ? 200 : 422 ) );
    if ( empty( $result['message'] ) && $status >= 500 ) {
        $result['message'] = 'Reconciliation request failed. Reference: ' . ( $requestId !== '' ? $requestId : 'n/a' );
    }

    if ( class_exists( 'Metis_Logger' ) ) {
        $context = [
            'request_id' => $requestId,
            'requested_by' => $requestedBy,
            'status' => $status,
            'ok' => ! empty( $result['ok'] ) ? 1 : 0,
            'message' => isset( $result['message'] ) ? (string) $result['message'] : '',
        ];
        if ( ! empty( $result['ok'] ) ) {
            Metis_Logger::info( 'finance.v2.recon_import.completed', $context );
        } else {
            Metis_Logger::warn( 'finance.v2.recon_import.failed', $context );
        }
    }

    metis_finance_ajax_send_service_result( $result );
} );

metis_ajax_register_handler( 'metis_finance_v2_recon_workflow', static function (): void {
    metis_finance_ajax_verify_nonce( [ 'metis_finance', 'metis_core' ] );
    metis_finance_ajax_require_view();

    $monthId = (int) metis_finance_ajax_post_value( 'month_id', 0 );
    $workflow = \Metis\Modules\Finance\FinanceV2Service::manualReconciliationWorkflow( $monthId );
    metis_runtime_send_json_success( [
        'reconciliation' => $workflow,
        'summary' => \Metis\Modules\Finance\FinanceV2Service::summary(),
    ] );
} );

metis_ajax_register_handler( 'metis_finance_v2_recon_item_toggle', static function (): void {
    metis_finance_ajax_verify_nonce( [ 'metis_finance_v2_recon_review', 'metis_finance', 'metis_core' ] );
    metis_finance_ajax_require_manage();

    $input = [
        'month_id' => (int) metis_finance_ajax_post_value( 'month_id', 0 ),
        'item_id' => (int) metis_finance_ajax_post_value( 'item_id', 0 ),
        'is_cleared' => (int) metis_finance_ajax_post_value( 'is_cleared', 0 ),
    ];

    $result = \Metis\Modules\Finance\FinanceV2Service::toggleReconciliationItem( $input, metis_finance_ajax_current_user_id() );
    metis_finance_ajax_send_service_result( $result );
} );

metis_ajax_register_handler( 'metis_finance_v2_recon_finalize', static function (): void {
    metis_finance_ajax_verify_nonce( [ 'metis_finance_v2_recon_review', 'metis_finance', 'metis_core' ] );
    metis_finance_ajax_require_manage();

    $input = [
        'month_id' => (int) metis_finance_ajax_post_value( 'month_id', 0 ),
    ];

    $result = \Metis\Modules\Finance\FinanceV2Service::finalizeReconciliationMonth( $input, metis_finance_ajax_current_user_id() );
    metis_finance_ajax_send_service_result( $result );
} );

metis_ajax_register_handler( 'metis_finance_v2_recon_reopen', static function (): void {
    metis_finance_ajax_verify_nonce( [ 'metis_finance_v2_recon_review', 'metis_finance', 'metis_core' ] );
    metis_finance_ajax_require_manage();

    $input = [
        'month_id' => (int) metis_finance_ajax_post_value( 'month_id', 0 ),
        'reason' => metis_text_clean( (string) metis_finance_ajax_post_value( 'reason', '' ) ),
    ];

    $result = \Metis\Modules\Finance\FinanceV2Service::reopenReconciliationMonth( $input, metis_finance_ajax_current_user_id() );
    metis_finance_ajax_send_service_result( $result );
} );

metis_ajax_register_handler( 'metis_finance_v2_recon_delete', static function (): void {
    metis_finance_ajax_verify_nonce( [ 'metis_finance_v2_recon_review', 'metis_finance', 'metis_core' ] );
    metis_finance_ajax_require_manage();

    $input = [
        'month_id' => (int) metis_finance_ajax_post_value( 'month_id', 0 ),
    ];

    $result = \Metis\Modules\Finance\FinanceV2Service::deleteReconciliationMonth( $input, metis_finance_ajax_current_user_id() );
    metis_finance_ajax_send_service_result( $result );
} );

metis_ajax_register_handler( 'metis_finance_v2_recon_mapping_list', static function (): void {
    metis_finance_ajax_verify_nonce( [ 'metis_finance', 'metis_core' ] );
    metis_finance_ajax_require_view();

    $importType = metis_key_clean( (string) metis_finance_ajax_post_value( 'import_type', '' ) );
    metis_runtime_send_json_success( [
        'reconciliation_mappings' => \Metis\Modules\Finance\FinanceV2Service::reconciliationMappings( $importType ),
    ] );
} );

metis_ajax_register_handler( 'metis_finance_v2_recon_mapping', static function (): void {
    metis_finance_ajax_verify_nonce( [ 'metis_finance_v2_recon_mapping', 'metis_finance', 'metis_core' ] );
    metis_finance_ajax_require_manage();

    $input = [
        'import_type' => metis_key_clean( (string) metis_finance_ajax_post_value( 'import_type', '' ) ),
        'mapping_name' => metis_text_clean( (string) metis_finance_ajax_post_value( 'mapping_name', '' ) ),
        'mapping' => metis_finance_ajax_post_json_array( 'mapping' ),
        'is_default' => (int) metis_finance_ajax_post_value( 'is_default', 0 ),
    ];

    $result = \Metis\Modules\Finance\FinanceV2Service::saveReconciliationMapping( $input, metis_finance_ajax_current_user_id() );
    metis_finance_ajax_send_service_result( $result );
} );

metis_ajax_register_handler( 'metis_finance_v2_recon_review', static function (): void {
    metis_finance_ajax_verify_nonce( [ 'metis_finance_v2_recon_review', 'metis_finance', 'metis_core' ] );
    metis_finance_ajax_require_manage();

    $input = [
        'review_queue_id' => (int) metis_finance_ajax_post_value( 'review_queue_id', 0 ),
        'decision' => metis_key_clean( (string) metis_finance_ajax_post_value( 'decision', '' ) ),
        'decision_notes' => metis_text_clean( (string) metis_finance_ajax_post_value( 'decision_notes', '' ) ),
    ];

    $result = \Metis\Modules\Finance\FinanceV2Service::applyReconciliationReviewDecision( $input, metis_finance_ajax_current_user_id() );
    metis_finance_ajax_send_service_result( $result );
} );

metis_ajax_register_handler( 'metis_finance_v2_recon_match_line', static function (): void {
    metis_finance_ajax_verify_nonce( [ 'metis_finance_v2_recon_review', 'metis_finance', 'metis_core' ] );
    metis_finance_ajax_require_manage();

    $input = [
        'bank_line_id' => (int) metis_finance_ajax_post_value( 'bank_line_id', 0 ),
        'match_type' => metis_key_clean( (string) metis_finance_ajax_post_value( 'match_type', '' ) ),
        'match_id' => (int) metis_finance_ajax_post_value( 'match_id', 0 ),
        'run_id' => (int) metis_finance_ajax_post_value( 'run_id', 0 ),
    ];

    $result = \Metis\Modules\Finance\FinanceV2Service::reconcileBankStatementLine( $input, metis_finance_ajax_current_user_id() );
    metis_finance_ajax_send_service_result( $result );
} );

metis_ajax_register_handler( 'metis_finance_v2_budget_snapshot', static function (): void {
    metis_finance_ajax_verify_nonce( [ 'metis_finance', 'metis_core' ] );
    metis_finance_ajax_require_view();

    $versionId = (int) metis_finance_ajax_post_value( 'version_id', 0 );
    metis_runtime_send_json_success( [
        'budget' => \Metis\Modules\Finance\FinanceV2Service::budgetSnapshot( $versionId ),
    ] );
} );

metis_ajax_register_handler( 'metis_finance_v2_budget_version', static function (): void {
    metis_finance_ajax_verify_nonce( [ 'metis_finance_v2_budget_version', 'metis_finance', 'metis_core' ] );
    metis_finance_ajax_require_manage();

    $input = [
        'version_label' => metis_text_clean( (string) metis_finance_ajax_post_value( 'version_label', '' ) ),
        'fiscal_year' => (int) metis_finance_ajax_post_value( 'fiscal_year', 0 ),
        'period_start' => metis_text_clean( (string) metis_finance_ajax_post_value( 'period_start', '' ) ),
        'period_end' => metis_text_clean( (string) metis_finance_ajax_post_value( 'period_end', '' ) ),
        'source_version_id' => (int) metis_finance_ajax_post_value( 'source_version_id', 0 ),
    ];

    $result = \Metis\Modules\Finance\FinanceV2Service::createBudgetVersion( $input, metis_finance_ajax_current_user_id() );
    metis_finance_ajax_send_service_result( $result );
} );

metis_ajax_register_handler( 'metis_finance_v2_budget_lines', static function (): void {
    metis_finance_ajax_verify_nonce( [ 'metis_finance_v2_budget_lines', 'metis_finance', 'metis_core' ] );
    metis_finance_ajax_require_manage();

    $input = [
        'budget_version_id' => (int) metis_finance_ajax_post_value( 'budget_version_id', 0 ),
        'lines' => metis_finance_ajax_post_json_array( 'lines' ),
    ];

    $result = \Metis\Modules\Finance\FinanceV2Service::saveBudgetLines( $input, metis_finance_ajax_current_user_id() );
    metis_finance_ajax_send_service_result( $result );
} );

metis_ajax_register_handler( 'metis_finance_v2_invoices_list', static function (): void {
    metis_finance_ajax_verify_nonce( [ 'metis_finance', 'metis_core' ] );
    metis_finance_ajax_require_view();

    metis_runtime_send_json_success( [
        'invoices' => \Metis\Modules\Finance\FinanceV2Service::invoiceSnapshot( 20 ),
    ] );
} );

metis_ajax_register_handler( 'metis_finance_v2_invoice_create', static function (): void {
    metis_finance_ajax_verify_nonce( [ 'metis_finance_v2_invoice_create', 'metis_finance', 'metis_core' ] );
    metis_finance_ajax_require_manage();

    $input = [
        'customer_name' => metis_text_clean( (string) metis_finance_ajax_post_value( 'customer_name', '' ) ),
        'customer_email' => metis_email_clean( (string) metis_finance_ajax_post_value( 'customer_email', '' ) ),
        'issued_date' => metis_text_clean( (string) metis_finance_ajax_post_value( 'issued_date', '' ) ),
        'due_date' => metis_text_clean( (string) metis_finance_ajax_post_value( 'due_date', '' ) ),
        'line_description' => metis_text_clean( (string) metis_finance_ajax_post_value( 'line_description', '' ) ),
        'line_quantity' => (float) metis_finance_ajax_post_value( 'line_quantity', 0 ),
        'line_unit_amount' => (float) metis_finance_ajax_post_value( 'line_unit_amount', 0 ),
        'notes' => metis_textarea_clean( (string) metis_finance_ajax_post_value( 'notes', '' ) ),
        'currency' => metis_key_clean( (string) metis_finance_ajax_post_value( 'currency', 'usd' ) ),
    ];

    $result = \Metis\Modules\Finance\FinanceV2Service::createInvoice( $input, metis_finance_ajax_current_user_id() );
    metis_finance_ajax_send_service_result( $result );
} );

metis_ajax_register_handler( 'metis_finance_v2_invoice_send', static function (): void {
    metis_finance_ajax_verify_nonce( [ 'metis_finance_v2_invoice_send', 'metis_finance', 'metis_core' ] );
    metis_finance_ajax_require_manage();

    $invoiceId = (int) metis_finance_ajax_post_value( 'invoice_id', 0 );
    if ( $invoiceId < 1 ) {
        metis_runtime_send_json_error( 'Invoice is required.', 422 );
    }

    $result = \Metis\Modules\Finance\FinanceV2Service::sendInvoice( $invoiceId, metis_finance_ajax_current_user_id() );
    metis_finance_ajax_send_service_result( $result );
} );

metis_ajax_register_handler( 'metis_finance_v2_invoice_paid', static function (): void {
    metis_finance_ajax_verify_nonce( [ 'metis_finance_v2_invoice_paid', 'metis_finance', 'metis_core' ] );
    metis_finance_ajax_require_manage();

    $invoiceId = (int) metis_finance_ajax_post_value( 'invoice_id', 0 );
    if ( $invoiceId < 1 ) {
        metis_runtime_send_json_error( 'Invoice is required.', 422 );
    }

    $input = [
        'paid_date' => metis_text_clean( (string) metis_finance_ajax_post_value( 'paid_date', '' ) ),
        'stripe_payment_intent_id' => metis_text_clean( (string) metis_finance_ajax_post_value( 'stripe_payment_intent_id', '' ) ),
    ];

    $result = \Metis\Modules\Finance\FinanceV2Service::markInvoicePaid( $invoiceId, $input, metis_finance_ajax_current_user_id() );
    metis_finance_ajax_send_service_result( $result );
} );

metis_ajax_register_handler( 'metis_finance_v2_fiscal_settings_get', static function (): void {
    metis_finance_ajax_verify_nonce( [ 'metis_finance', 'metis_core' ] );
    metis_finance_ajax_require_view();
    metis_runtime_send_json_success( [
        'fiscal' => \Metis\Modules\Finance\FinanceV2Service::fiscalSettingsSnapshot(),
    ] );
} );

metis_ajax_register_handler( 'metis_finance_v2_fiscal_settings', static function (): void {
    metis_finance_ajax_verify_nonce( [ 'metis_finance_v2_fiscal_settings', 'metis_finance', 'metis_core' ] );
    metis_finance_ajax_require_manage();

    $input = [
        'fiscal_year_start_month' => (int) metis_finance_ajax_post_value( 'fiscal_year_start_month', 1 ),
    ];

    $result = \Metis\Modules\Finance\FinanceV2Service::updateFiscalSettings( $input, metis_finance_ajax_current_user_id() );
    metis_finance_ajax_send_service_result( $result );
} );

metis_ajax_register_handler( 'metis_finance_v2_fiscal_migrate', static function (): void {
    metis_finance_ajax_verify_nonce( [ 'metis_finance_v2_fiscal_migrate', 'metis_finance', 'metis_core' ] );
    metis_finance_ajax_require_manage();

    $input = [
        'label' => metis_text_clean( (string) metis_finance_ajax_post_value( 'label', '' ) ),
        'start_date' => metis_text_clean( (string) metis_finance_ajax_post_value( 'start_date', '' ) ),
        'end_date' => metis_text_clean( (string) metis_finance_ajax_post_value( 'end_date', '' ) ),
    ];

    $result = \Metis\Modules\Finance\FinanceV2Service::migrateFiscalPeriod( $input, metis_finance_ajax_current_user_id() );
    metis_finance_ajax_send_service_result( $result );
} );

metis_ajax_register_handler( 'metis_finance_v2_reports_snapshot', static function (): void {
    metis_finance_ajax_verify_nonce( [ 'metis_finance', 'metis_core' ] );
    metis_finance_ajax_require_view();
    metis_runtime_send_json_success( [
        'reports' => \Metis\Modules\Finance\FinanceV2Service::reportSnapshot(),
    ] );
} );

metis_ajax_register_handler( 'metis_finance_v2_report_render', static function (): void {
    metis_finance_ajax_verify_nonce( [ 'metis_finance_v2_report_render', 'metis_finance', 'metis_core' ] );
    metis_finance_ajax_require_view();

    $reportType = metis_key_clean( (string) metis_finance_ajax_post_value( 'report_type', '' ) );
    $periodCode = metis_key_clean( (string) metis_finance_ajax_post_value( 'period_code', '' ) );
    $includePreviousMonth = (int) metis_finance_ajax_post_value( 'include_previous_month', 0 ) === 1;

    $result = \Metis\Modules\Finance\FinanceV2Service::renderReportData( $reportType, $periodCode, $includePreviousMonth );
    metis_finance_ajax_send_service_result( $result );
} );

metis_ajax_register_handler( 'metis_finance_v2_report_pdf', static function (): void {
    metis_finance_ajax_verify_nonce( [ 'metis_finance_v2_report_pdf', 'metis_finance', 'metis_core' ] );
    metis_finance_ajax_require_view();

    $input = [
        'report_type' => metis_key_clean( (string) metis_finance_ajax_post_value( 'report_type', '' ) ),
        'period_code' => metis_key_clean( (string) metis_finance_ajax_post_value( 'period_code', '' ) ),
        'orientation' => metis_key_clean( (string) metis_finance_ajax_post_value( 'orientation', 'landscape' ) ),
        'include_previous_month' => (int) metis_finance_ajax_post_value( 'include_previous_month', 0 ) === 1 ? 1 : 0,
    ];

    $result = \Metis\Modules\Finance\FinanceV2Service::generateReportPdf( $input, metis_finance_ajax_current_user_id() );
    metis_finance_ajax_send_service_result( $result );
} );

metis_ajax_register_handler( 'metis_finance_v2_stripe_overview', static function (): void {
    metis_finance_ajax_verify_nonce( [ 'metis_finance', 'metis_core' ] );
    metis_finance_ajax_require_view();

    metis_runtime_send_json_success( [
        'stripe_overview' => \Metis\Modules\Finance\FinanceV2Service::stripeOverview(),
        'stripe_payouts' => \Metis\Modules\Finance\FinanceV2Service::stripePayouts( 20 ),
        'bank_lines' => \Metis\Modules\Finance\FinanceV2Service::bankLines( 20 ),
        'summary' => \Metis\Modules\Finance\FinanceV2Service::summary(),
    ] );
} );

metis_ajax_register_handler( 'metis_finance_v2_stripe_event', static function (): void {
    metis_finance_ajax_verify_nonce( [ 'metis_finance_v2_stripe_event', 'metis_finance', 'metis_core' ] );
    metis_finance_ajax_require_manage();

    $input = [
        'event_type' => metis_key_clean( (string) metis_finance_ajax_post_value( 'event_type', '' ) ),
        'event_date' => metis_text_clean( (string) metis_finance_ajax_post_value( 'event_date', '' ) ),
        'reference_id' => metis_text_clean( (string) metis_finance_ajax_post_value( 'reference_id', '' ) ),
        'amount' => (float) metis_finance_ajax_post_value( 'amount', 0 ),
        'description' => metis_text_clean( (string) metis_finance_ajax_post_value( 'description', '' ) ),
    ];

    $result = \Metis\Modules\Finance\FinanceV2Service::recordStripeClearingEvent( $input, metis_finance_ajax_current_user_id() );
    metis_finance_ajax_send_service_result( $result );
} );

metis_ajax_register_handler( 'metis_finance_v2_stripe_payout', static function (): void {
    metis_finance_ajax_verify_nonce( [ 'metis_finance_v2_stripe_payout', 'metis_finance', 'metis_core' ] );
    metis_finance_ajax_require_manage();

    $input = [
        'payout_id' => metis_text_clean( (string) metis_finance_ajax_post_value( 'payout_id', '' ) ),
        'payout_date' => metis_text_clean( (string) metis_finance_ajax_post_value( 'payout_date', '' ) ),
        'expected_deposit_amount' => (float) metis_finance_ajax_post_value( 'expected_deposit_amount', 0 ),
        'bank_account_label' => metis_text_clean( (string) metis_finance_ajax_post_value( 'bank_account_label', '' ) ),
    ];

    $result = \Metis\Modules\Finance\FinanceV2Service::createExpectedPayout( $input, metis_finance_ajax_current_user_id() );
    metis_finance_ajax_send_service_result( $result );
} );

metis_ajax_register_handler( 'metis_finance_v2_bank_line', static function (): void {
    metis_finance_ajax_verify_nonce( [ 'metis_finance_v2_bank_line', 'metis_finance', 'metis_core' ] );
    metis_finance_ajax_require_manage();

    $input = [
        'line_date' => metis_text_clean( (string) metis_finance_ajax_post_value( 'line_date', '' ) ),
        'description' => metis_text_clean( (string) metis_finance_ajax_post_value( 'description', '' ) ),
        'amount_signed' => (float) metis_finance_ajax_post_value( 'amount_signed', 0 ),
    ];

    $result = \Metis\Modules\Finance\FinanceV2Service::recordBankLine( $input, metis_finance_ajax_current_user_id() );
    metis_finance_ajax_send_service_result( $result );
} );

metis_ajax_register_handler( 'metis_finance_v2_stripe_match', static function (): void {
    metis_finance_ajax_verify_nonce( [ 'metis_finance_v2_stripe_match', 'metis_finance', 'metis_core' ] );
    metis_finance_ajax_require_manage();

    $input = [
        'payout_record_id' => (int) metis_finance_ajax_post_value( 'payout_record_id', 0 ),
        'bank_line_id' => (int) metis_finance_ajax_post_value( 'bank_line_id', 0 ),
    ];

    $result = \Metis\Modules\Finance\FinanceV2Service::matchPayoutToBankLine( $input, metis_finance_ajax_current_user_id() );
    metis_finance_ajax_send_service_result( $result );
} );

metis_ajax_register_handler( 'metis_finance_v2_stripe_auto_match', static function (): void {
    metis_finance_ajax_verify_nonce( [ 'metis_finance_v2_stripe_match', 'metis_finance', 'metis_core' ] );
    metis_finance_ajax_require_manage();

    $result = \Metis\Modules\Finance\FinanceV2Service::autoMatchStripeClearing( [], metis_finance_ajax_current_user_id() );
    metis_finance_ajax_send_service_result( $result );
} );
