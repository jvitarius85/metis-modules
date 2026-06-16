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
    metis_ajax_register_controller( 'metis_contacts_import_csv_stage', [
        'module' => 'contacts',
        'permission' => 'edit',
        'nonce_action' => metis_ajax_nonce_action( 'metis_contacts_import_csv_stage' ),
    ] );
    metis_ajax_register_controller( 'metis_contacts_import_csv_analyze', [
        'module' => 'contacts',
        'permission' => 'edit',
        'nonce_action' => metis_ajax_nonce_action( 'metis_contacts_import_csv_analyze' ),
    ] );
    metis_ajax_register_controller( 'metis_contacts_import_csv_run', [
        'module' => 'contacts',
        'permission' => 'edit',
        'nonce_action' => metis_ajax_nonce_action( 'metis_contacts_import_csv_run' ),
    ] );
}

metis_ajax_register_handler( 'metis_contacts_merge_duplicates', function () {
    metis_contacts_ajax_verify_nonce();

    if ( ! metis_contacts_can_manage() ) {
        metis_runtime_send_json_error( 'Unauthorized', 403 );
    }

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
    metis_runtime_send_json_success(
        \Metis\Modules\Contacts\MergeService::mergeDuplicates( $primary_cid, $duplicate_cids )
    );
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

metis_ajax_register_handler( 'metis_contacts_import_csv_stage', function () {
    metis_contacts_ajax_verify_nonce();

    if ( ! metis_contacts_can_manage() ) {
        metis_runtime_send_json_error( 'Unauthorized', 403 );
    }

    if ( empty( metis_request_files()['import_file'] ) || ! is_array( metis_request_files()['import_file'] ) ) {
        metis_runtime_send_json_error( 'Import file is required.', 400 );
    }

    $result = \Metis\Modules\Contacts\ContactImportService::stageCsvUpload( metis_request_files()['import_file'] );
    if ( empty( $result['success'] ) ) {
        metis_runtime_send_json_error(
            (string) ( $result['message'] ?? 'Failed to read CSV file.' ),
            (int) ( $result['status'] ?? 500 )
        );
    }

    metis_runtime_send_json_success( [
        'token' => (string) ( $result['token'] ?? '' ),
        'filename' => (string) ( $result['filename'] ?? '' ),
        'headers' => array_values( (array) ( $result['headers'] ?? [] ) ),
        'row_count' => (int) ( $result['row_count'] ?? 0 ),
        'mapping' => (array) ( $result['mapping'] ?? [] ),
        'sample_rows' => array_values( (array) ( $result['sample_rows'] ?? [] ) ),
    ] );
} );

metis_ajax_register_handler( 'metis_contacts_import_csv_analyze', function () {
    metis_contacts_ajax_verify_nonce();

    if ( ! metis_contacts_can_manage() ) {
        metis_runtime_send_json_error( 'Unauthorized', 403 );
    }

    $token = metis_text_clean( (string) metis_runtime_unslash( metis_request_post()['token'] ?? '' ) );
    $mapping = json_decode( (string) metis_runtime_unslash( metis_request_post()['mapping'] ?? '{}' ), true );
    $create_missing_lists = (string) metis_runtime_unslash( metis_request_post()['create_missing_lists'] ?? '1' ) !== '0';

    $result = \Metis\Modules\Contacts\ContactImportService::analyzeStage(
        $token,
        is_array( $mapping ) ? $mapping : [],
        $create_missing_lists
    );
    if ( empty( $result['success'] ) ) {
        metis_runtime_send_json_error(
            (string) ( $result['message'] ?? 'Failed to analyze import.' ),
            (int) ( $result['status'] ?? 500 )
        );
    }

    metis_runtime_send_json_success( [
        'summary' => (array) ( $result['summary'] ?? [] ),
        'preview_rows' => array_values( (array) ( $result['preview_rows'] ?? [] ) ),
        'errors' => array_values( (array) ( $result['errors'] ?? [] ) ),
        'mapping' => (array) ( $result['mapping'] ?? [] ),
    ] );
} );

metis_ajax_register_handler( 'metis_contacts_import_csv_run', function () {
    metis_contacts_ajax_verify_nonce();

    if ( ! metis_contacts_can_manage() ) {
        metis_runtime_send_json_error( 'Unauthorized', 403 );
    }

    $token = metis_text_clean( (string) metis_runtime_unslash( metis_request_post()['token'] ?? '' ) );
    $mapping = json_decode( (string) metis_runtime_unslash( metis_request_post()['mapping'] ?? '{}' ), true );
    $create_missing_lists = (string) metis_runtime_unslash( metis_request_post()['create_missing_lists'] ?? '1' ) !== '0';

    $result = \Metis\Modules\Contacts\ContactImportService::importStage(
        $token,
        is_array( $mapping ) ? $mapping : [],
        $create_missing_lists
    );
    if ( empty( $result['success'] ) ) {
        metis_runtime_send_json_error(
            (string) ( $result['message'] ?? 'Import failed.' ),
            (int) ( $result['status'] ?? 500 )
        );
    }

    metis_portal_dashboard_forget_all();
    metis_runtime_send_json_success( [
        'imported_count' => (int) ( $result['imported_count'] ?? 0 ),
        'created_count' => (int) ( $result['created_count'] ?? 0 ),
        'updated_count' => (int) ( $result['updated_count'] ?? 0 ),
        'skipped_count' => (int) ( $result['skipped_count'] ?? 0 ),
        'errors' => array_values( (array) ( $result['errors'] ?? [] ) ),
        'missing_lists' => array_values( (array) ( $result['missing_lists'] ?? [] ) ),
    ] );
} );
