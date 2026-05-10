<?php
declare(strict_types=1);

return [
    'approved_layers' => [
        'superglobals' => [
        ],
        'request_boundary' => [
            'system/src/Metis/Core/Runtime/RequestRuntime.php',
        ],
        'native_db' => [
            'system/src/Metis/Services/DatabaseService.php',
            'system/src/Metis/Core/CoreBootstrap.php',
            'system/src/Metis/Core/Runtime/StandaloneApplicationBootstrap.php',
            'system/src/Metis/Core/Runtime/StandaloneBootstrap.php',
            'system/tools/migrate_remote_transcript_posts.php',
            'system/tools/repair_website_post_text_encoding.php',
            'system/tools/security_scan.php',
        ],
        'serialization' => [
            'system/src/Metis/Core/Serialization/LegacyPhpSerializedPayload.php',
            'system/src/Metis/Core/SettingsService.php',
        ],
        'raw_sql' => [
            'system/src/Metis/Core/CoreBootstrap.php',
            'system/src/Metis/Core/Runtime/StandaloneApplicationBootstrap.php',
            'system/src/Metis/Core/Runtime/StandaloneBootstrap.php',
            'system/src/Metis/Services/DatabaseService.php',
            'system/tools/database_cleanup.php',
            'system/tools/repair_transcript_post_encoding.php',
            'system/tools/repair_website_post_text_encoding.php',
            'system/tools/security_scan.php',
        ],
        'process' => [
            'system/src/Metis/Core/Services/ProcessRunner.php',
        ],
    ],
    'required_media_roots' => [
        'storage/public-media',
        'storage/protected-media',
        'storage/private-records',
    ],
    'canonical_media_helpers' => [
        'public' => 'metis_store_public_media',
        'protected' => 'metis_store_protected_media',
        'private' => 'metis_store_private_record',
    ],
    'deprecated_media_roots' => [
        'storage/uploads',
        'storage/media',
    ],
    'sensitive_media_writes' => [
        [
            'path' => 'system/src/Metis/Modules/CommunicationsInbound/AttachmentStorageService.php',
            'required_storage_class' => 'protected',
        ],
        [
            'path' => 'system/src/Metis/Modules/Finance/FinanceV2Service.php',
            'required_storage_class' => 'private',
        ],
    ],
    'required_process_context_keys' => [
        'security_context',
        'audit_context',
        'permission_context',
    ],
    'forbidden_runtime_patterns' => [
        'eval',
        'request_superglobal',
        'native_db_access',
        'static_app_key_fallback',
    ],
];
