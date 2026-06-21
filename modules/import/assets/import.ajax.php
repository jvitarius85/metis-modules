<?php
declare(strict_types=1);

if ( ! defined( 'METIS_ROOT' ) ) {
    exit;
}

use Metis\Modules\Import\Services\ImporterPipeline;

require_once dirname( __DIR__ ) . '/services/ImporterPipeline.php';
require_once dirname( __DIR__ ) . '/services/ImportService.php';

if ( ! function_exists( 'metis_import_ajax_guard' ) ) {
    function metis_import_ajax_guard( bool $execute_required = false ): void {
        $nonce = isset( metis_request_post()['nonce'] ) && is_scalar( metis_request_post()['nonce'] )
            ? trim( (string) metis_runtime_unslash( metis_request_post()['nonce'] ) )
            : '';
        $action_nonce = isset( metis_request_post()['metis_action_nonce'] ) && is_scalar( metis_request_post()['metis_action_nonce'] )
            ? trim( (string) metis_runtime_unslash( metis_request_post()['metis_action_nonce'] ) )
            : '';
        $action = isset( metis_request_post()['action'] ) && is_scalar( metis_request_post()['action'] )
            ? metis_key_clean( (string) metis_runtime_unslash( metis_request_post()['action'] ) )
            : '';

        $valid = false;
        if ( $nonce !== '' && function_exists( 'metis_runtime_verify_nonce' ) ) {
            $valid = metis_runtime_verify_nonce( $nonce, 'metis_import' );
        }

        if ( ! $valid && $action !== '' && function_exists( 'metis_runtime_verify_nonce' ) ) {
            $nonce_action = function_exists( 'metis_ajax_nonce_action' )
                ? metis_ajax_nonce_action( $action )
                : ( 'metis_ajax:' . $action );
            if ( $action_nonce !== '' ) {
                $valid = metis_runtime_verify_nonce( $action_nonce, $nonce_action );
            }
            if ( ! $valid && $nonce !== '' ) {
                $valid = metis_runtime_verify_nonce( $nonce, $nonce_action );
            }
        }

        if ( ! $valid ) {
            metis_runtime_send_json_error( [ 'message' => 'Security check failed.', 'code' => 'invalid_nonce' ], 403 );
        }

        if ( ! function_exists( 'metis_security_user_can' ) || ! metis_security_user_can( 'import.view' ) ) {
            metis_runtime_send_json_error( 'Unauthorized', 403 );
        }

        if ( $execute_required && ! metis_security_user_can( 'import.execute' ) ) {
            metis_runtime_send_json_error( 'Unauthorized', 403 );
        }
    }
}

if ( function_exists( 'metis_ajax_register_controller' ) ) {
    metis_ajax_register_controller( 'metis_import_upload_parse', [
        'module' => 'import',
        'permission' => 'view',
        'nonce_action' => metis_ajax_nonce_action( 'metis_import_upload_parse' ),
    ] );
    metis_ajax_register_controller( 'metis_import_confirm', [
        'module' => 'import',
        'permission' => 'execute',
        'nonce_action' => metis_ajax_nonce_action( 'metis_import_confirm' ),
    ] );
}

metis_ajax_register_handler( 'metis_import_upload_parse', function (): void {
    metis_import_ajax_guard( false );

    if ( empty( metis_request_files()['import_file'] ) || ! is_array( metis_request_files()['import_file'] ) ) {
        metis_runtime_send_json_error( 'Import file is required.', 400 );
    }

    $user_id = function_exists( 'metis_current_user_id' ) ? (int) metis_current_user_id() : 0;
    $result = ImporterPipeline::parseUpload( metis_request_files()['import_file'], $user_id );

    if ( empty( $result['ok'] ) ) {
        $status = (int) ( $result['status'] ?? 500 );
        $message = trim( (string) ( $result['error'] ?? '' ) );
        if ( $message === '' ) {
            $message = $status >= 500 ? 'Failed to parse import file.' : 'Import file could not be parsed.';
        }
        metis_runtime_send_json_error( $message, $status );
    }

    metis_runtime_send_json_success( [
        'preview' => (array) ( $result['preview'] ?? [] ),
    ] );
} );

metis_ajax_register_handler( 'metis_import_confirm', function (): void {
    metis_import_ajax_guard( true );

    $user_id = function_exists( 'metis_current_user_id' ) ? (int) metis_current_user_id() : 0;
    $options = [
        'import_pages' => (string) ( metis_request_post()['import_pages'] ?? '' ) === '1',
        'import_posts' => (string) ( metis_request_post()['import_posts'] ?? '' ) === '1',
        'import_menus' => (string) ( metis_request_post()['import_menus'] ?? '' ) === '1',
        'import_newsletters' => (string) ( metis_request_post()['import_newsletters'] ?? '' ) === '1',
        'selected_page_ids' => isset( metis_request_post()['selected_page_ids'] ) ? (string) metis_runtime_unslash( metis_request_post()['selected_page_ids'] ) : '[]',
        'selected_post_ids' => isset( metis_request_post()['selected_post_ids'] ) ? (string) metis_runtime_unslash( metis_request_post()['selected_post_ids'] ) : '[]',
        'selected_newsletter_ids' => isset( metis_request_post()['selected_newsletter_ids'] ) ? (string) metis_runtime_unslash( metis_request_post()['selected_newsletter_ids'] ) : '[]',
    ];

    $result = ImporterPipeline::confirm( $options, $user_id );

    if ( empty( $result['ok'] ) ) {
        $status = (int) ( $result['status'] ?? 500 );
        $message = $status >= 500 ? 'Import failed.' : 'Import request is invalid.';
        metis_runtime_send_json_error( $message, $status );
    }

    metis_runtime_send_json_success( [
        'results' => (array) ( $result['results'] ?? [] ),
    ] );
} );
