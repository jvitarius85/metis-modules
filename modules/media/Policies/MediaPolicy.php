<?php
declare(strict_types=1);

namespace Metis\Modules\Media\Policies;

final class MediaPolicy {
    public static function canView(): bool {
        return function_exists( 'metis_security_user_can' ) && \metis_security_user_can( 'media.view' );
    }

    public static function canManage(): bool {
        return function_exists( 'metis_security_user_can' ) && \metis_security_user_can( 'media.edit' );
    }

    public static function canDelete(): bool {
        return function_exists( 'metis_security_user_can' ) && \metis_security_user_can( 'media.delete' );
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function ajaxControllers(): array {
        return [
            'metis_media_library_list' => [
                'permission' => 'view',
                'nonce_action' => \metis_ajax_nonce_action( 'metis_media_library_list' ),
                'allow_additional_fields' => false,
                'schema' => [
                    'search' => [ 'type' => 'string', 'required' => false ],
                    'type' => [ 'type' => 'string', 'required' => false ],
                    'folder' => [ 'type' => 'string', 'required' => false ],
                    'category' => [ 'type' => 'string', 'required' => false ],
                    'sort' => [ 'type' => 'string', 'required' => false ],
                    'limit' => [ 'type' => 'integer', 'required' => false ],
                ],
            ],
            'metis_media_library_upload' => [
                'permission' => 'edit',
                'nonce_action' => \metis_ajax_nonce_action( 'metis_media_library_upload' ),
                'allow_additional_fields' => false,
                'schema' => [
                    'folder_path' => [ 'type' => 'string', 'required' => false ],
                    'category_key' => [ 'type' => 'string', 'required' => false ],
                ],
            ],
            'metis_media_library_facets' => [
                'permission' => 'view',
                'nonce_action' => \metis_ajax_nonce_action( 'metis_media_library_facets' ),
                'allow_additional_fields' => false,
                'schema' => [],
            ],
            'metis_media_library_update_meta' => [
                'permission' => 'edit',
                'nonce_action' => \metis_ajax_nonce_action( 'metis_media_library_update_meta' ),
                'allow_additional_fields' => false,
                'schema' => [
                    'token' => [ 'type' => 'string', 'required' => true ],
                    'folder_path' => [ 'type' => 'string', 'required' => false ],
                    'category_key' => [ 'type' => 'string', 'required' => false ],
                ],
            ],
            'metis_media_library_delete' => [
                'permission' => 'delete',
                'nonce_action' => \metis_ajax_nonce_action( 'metis_media_library_delete' ),
                'allow_additional_fields' => false,
                'schema' => [
                    'token' => [ 'type' => 'string', 'required' => true ],
                ],
            ],
        ];
    }
}
