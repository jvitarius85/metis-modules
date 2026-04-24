<?php
declare(strict_types=1);

if ( ! defined( 'METIS_ROOT' ) ) {
    exit;
}

function metis_forms_ajax_current_user_id(): int {
    if ( function_exists( 'metis_current_user_id' ) ) {
        return (int) metis_current_user_id();
    }

    return 0;
}

function metis_forms_ajax_post_value( string $key, mixed $default = '' ): mixed {
    if ( ! isset( $_POST[ $key ] ) ) {
        return $default;
    }

    return metis_runtime_unslash( $_POST[ $key ] );
}

function metis_forms_ajax_post_json_array( string $key ): array {
    $raw = metis_forms_ajax_post_value( $key, '' );
    if ( is_array( $raw ) ) {
        return $raw;
    }

    if ( ! is_string( $raw ) || trim( $raw ) === '' ) {
        return [];
    }

    $decoded = json_decode( $raw, true );
    return is_array( $decoded ) ? $decoded : [];
}

function metis_forms_ajax_verify_nonce( array $actions = [] ): void {
    $nonce_candidates = [];
    foreach ( [ 'nonce', 'metis_action_nonce', '_wpnonce' ] as $field ) {
        $value = metis_forms_ajax_post_value( $field, '' );
        if ( is_scalar( $value ) ) {
            $token = trim( (string) $value );
            if ( $token !== '' ) {
                $nonce_candidates[] = $token;
            }
        }
    }

    $nonce_candidates = array_values( array_unique( $nonce_candidates ) );
    $nonce_actions = $actions === [] ? [ 'metis_forms', 'metis_core' ] : $actions;
    $request_action = metis_key_clean( (string) metis_forms_ajax_post_value( 'action', '' ) );
    if ( $request_action !== '' && ! in_array( $request_action, $nonce_actions, true ) ) {
        $nonce_actions[] = $request_action;
    }

    foreach ( $nonce_candidates as $token ) {
        foreach ( $nonce_actions as $action ) {
            if ( function_exists( 'metis_runtime_verify_nonce' ) && metis_runtime_verify_nonce( $token, $action ) ) {
                return;
            }
            if ( function_exists( 'metis_ajax_nonce_action' ) ) {
                $ajax_action = metis_ajax_nonce_action( $action );
                if ( function_exists( 'metis_runtime_verify_nonce' ) && metis_runtime_verify_nonce( $token, $ajax_action ) ) {
                    return;
                }
            }
        }
    }

    metis_runtime_send_json_error( 'Invalid nonce.', 403 );
}

function metis_forms_ajax_require_view(): void {
    if ( ! function_exists( 'metis_forms_can_view' ) || ! metis_forms_can_view() ) {
        metis_runtime_send_json_error( 'Unauthorized', 403 );
    }
}

function metis_forms_ajax_require_manage(): void {
    if ( ! function_exists( 'metis_forms_can_manage' ) || ! metis_forms_can_manage() ) {
        metis_runtime_send_json_error( 'Unauthorized', 403 );
    }
}

function metis_forms_ajax_require_delete(): void {
    if ( ! function_exists( 'metis_forms_can_delete' ) || ! metis_forms_can_delete() ) {
        metis_runtime_send_json_error( 'Unauthorized', 403 );
    }
}

function metis_forms_ajax_send_result( array $result, string $fallback = 'Forms request failed.' ): void {
    if ( empty( $result['ok'] ) ) {
        $status = (int) ( $result['status'] ?? 422 );
        $message = (string) ( $result['error'] ?? $result['message'] ?? $fallback );
        metis_runtime_send_json_error( [ 'message' => $message, 'result' => $result ], $status );
    }

    metis_runtime_send_json_success( $result );
}

function metis_forms_register_ajax_controllers(): void {
    if ( ! function_exists( 'metis_ajax_register_controller' ) ) {
        return;
    }

    $actions = [
        'metis_forms_list' => [ 'permission' => 'view' ],
        'metis_forms_get' => [ 'permission' => 'view' ],
        'metis_forms_save' => [ 'permission' => 'edit' ],
        'metis_forms_publish' => [ 'permission' => 'edit' ],
        'metis_forms_duplicate' => [ 'permission' => 'edit' ],
        'metis_forms_delete' => [ 'permission' => 'delete' ],
        'metis_forms_entries' => [ 'permission' => 'view' ],
        'metis_forms_export' => [ 'permission' => 'view' ],
        'metis_forms_dynamic_options' => [ 'permission' => 'view' ],
    ];

    foreach ( $actions as $action => $config ) {
        metis_ajax_register_controller(
            $action,
            [
                'module' => 'forms',
                'permission' => (string) $config['permission'],
                'nonce_action' => function_exists( 'metis_ajax_nonce_action' )
                    ? metis_ajax_nonce_action( $action )
                    : $action,
            ]
        );
    }
}

metis_forms_register_ajax_controllers();

metis_ajax_register_handler( 'metis_forms_list', static function (): void {
    metis_forms_ajax_verify_nonce( [ 'metis_forms_list', 'metis_forms', 'metis_core' ] );
    metis_forms_ajax_require_view();

    metis_runtime_send_json_success( [
        'forms' => \Metis\Modules\Forms\Repository::listForms(),
    ] );
} );

metis_ajax_register_handler( 'metis_forms_get', static function (): void {
    metis_forms_ajax_verify_nonce( [ 'metis_forms_get', 'metis_forms', 'metis_core' ] );
    metis_forms_ajax_require_view();

    $form_id = max( 0, (int) metis_forms_ajax_post_value( 'form_id', 0 ) );
    $form = $form_id > 0
        ? \Metis\Modules\Forms\Repository::getFormById( $form_id, false )
        : \Metis\Modules\Forms\Repository::blankForm();

    if ( ! is_array( $form ) ) {
        metis_runtime_send_json_error( [ 'message' => 'Form not found.' ], 404 );
    }

    metis_runtime_send_json_success( [
        'form' => $form,
        'options' => \Metis\Modules\Forms\Repository::adminOptions(),
    ] );
} );

metis_ajax_register_handler( 'metis_forms_save', static function (): void {
    metis_forms_ajax_verify_nonce( [ 'metis_forms_save', 'metis_forms', 'metis_core' ] );
    metis_forms_ajax_require_manage();

    $form = metis_forms_ajax_post_json_array( 'form' );
    if ( $form === [] ) {
        metis_runtime_send_json_error( [ 'message' => 'Form payload is required.' ], 422 );
    }

    $result = \Metis\Modules\Forms\Repository::saveForm( $form, metis_forms_ajax_current_user_id() );
    metis_forms_ajax_send_result( $result, 'Form save failed.' );
} );

metis_ajax_register_handler( 'metis_forms_publish', static function (): void {
    metis_forms_ajax_verify_nonce( [ 'metis_forms_publish', 'metis_forms', 'metis_core' ] );
    metis_forms_ajax_require_manage();

    $form = metis_forms_ajax_post_json_array( 'form' );
    $form_id = max( 0, (int) ( $form['id'] ?? metis_forms_ajax_post_value( 'form_id', 0 ) ) );
    if ( $form_id < 1 ) {
        metis_runtime_send_json_error( [ 'message' => 'Form id is required.' ], 422 );
    }

    $result = \Metis\Modules\Forms\Repository::publishForm( $form_id, $form !== [] ? $form : null, metis_forms_ajax_current_user_id() );
    metis_forms_ajax_send_result( $result, 'Publish failed.' );
} );

metis_ajax_register_handler( 'metis_forms_duplicate', static function (): void {
    metis_forms_ajax_verify_nonce( [ 'metis_forms_duplicate', 'metis_forms', 'metis_core' ] );
    metis_forms_ajax_require_manage();

    $form_id = max( 0, (int) metis_forms_ajax_post_value( 'form_id', 0 ) );
    if ( $form_id < 1 ) {
        metis_runtime_send_json_error( [ 'message' => 'Form id is required.' ], 422 );
    }

    $result = \Metis\Modules\Forms\Repository::duplicateForm( $form_id, metis_forms_ajax_current_user_id() );
    metis_forms_ajax_send_result( $result, 'Duplicate failed.' );
} );

metis_ajax_register_handler( 'metis_forms_delete', static function (): void {
    metis_forms_ajax_verify_nonce( [ 'metis_forms_delete', 'metis_forms', 'metis_core' ] );
    metis_forms_ajax_require_delete();

    $form_id = max( 0, (int) metis_forms_ajax_post_value( 'form_id', 0 ) );
    if ( $form_id < 1 ) {
        metis_runtime_send_json_error( [ 'message' => 'Form id is required.' ], 422 );
    }

    $result = \Metis\Modules\Forms\Repository::deleteForm( $form_id );
    metis_forms_ajax_send_result( $result, 'Delete failed.' );
} );

metis_ajax_register_handler( 'metis_forms_entries', static function (): void {
    metis_forms_ajax_verify_nonce( [ 'metis_forms_entries', 'metis_forms', 'metis_core' ] );
    metis_forms_ajax_require_view();

    $form_id = max( 0, (int) metis_forms_ajax_post_value( 'form_id', 0 ) );
    if ( $form_id < 1 ) {
        metis_runtime_send_json_error( [ 'message' => 'Form id is required.' ], 422 );
    }

    metis_runtime_send_json_success( [
        'entries' => \Metis\Modules\Forms\Repository::listSubmissions( $form_id ),
        'summary' => \Metis\Modules\Forms\Repository::summarizeSubmissions( $form_id ),
    ] );
} );

metis_ajax_register_handler( 'metis_forms_export', static function (): void {
    metis_forms_ajax_verify_nonce( [ 'metis_forms_export', 'metis_forms', 'metis_core' ] );
    metis_forms_ajax_require_view();

    $form_id = max( 0, (int) metis_forms_ajax_post_value( 'form_id', 0 ) );
    if ( $form_id < 1 ) {
        metis_runtime_send_json_error( [ 'message' => 'Form id is required.' ], 422 );
    }

    $csv = \Metis\Modules\Forms\Repository::exportSubmissionsCsv( $form_id );
    if ( $csv === '' ) {
        metis_runtime_send_json_error( [ 'message' => 'There are no entries to export yet.' ], 404 );
    }

    metis_runtime_send_json_success( [
        'csv' => $csv,
        'filename' => 'form-' . $form_id . '-entries.csv',
    ] );
} );

metis_ajax_register_handler( 'metis_forms_dynamic_options', static function (): void {
    metis_forms_ajax_verify_nonce( [ 'metis_forms_dynamic_options', 'metis_forms', 'metis_core' ] );
    metis_forms_ajax_require_view();

    $source = metis_forms_ajax_post_json_array( 'source' );
    $parent_value = trim( (string) metis_forms_ajax_post_value( 'parent_value', '' ) );

    metis_runtime_send_json_success( [
        'options' => \Metis\Modules\Forms\Repository::resolveDynamicOptions( $source, $parent_value ),
    ] );
} );
