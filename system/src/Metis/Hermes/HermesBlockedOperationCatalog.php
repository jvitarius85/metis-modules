<?php
declare(strict_types=1);

namespace Metis\Hermes;

final class HermesBlockedOperationCatalog {
    /**
     * @return array<string,array<string,string>>
     */
    public static function definitions(): array {
        return [
            'service_restart' => [
                'tool_key' => 'hermes.system.restart_service',
                'capability_method' => 'restartService',
                'query' => 'restart service',
                'unsupported_message' => 'Service restart does not have a trusted backend registered for Hermes execution yet.',
                'message_contains' => 'trusted backend',
            ],
            'recover_module' => [
                'tool_key' => 'hermes.recovery.recover_module',
                'capability_method' => 'recoverModule',
                'query' => 'recover module',
                'unsupported_message' => 'Module recovery does not have an executable backend yet. Use release rollback or backup restore for supported recovery paths.',
                'message_contains' => 'backup restore',
            ],
            'rollback_module' => [
                'tool_key' => 'hermes.recovery.rollback_module',
                'capability_method' => 'rollbackModule',
                'query' => 'rollback module',
                'unsupported_message' => 'Module rollback is not independently executable. Use release rollback for supported rollback behavior.',
                'message_contains' => 'release rollback',
            ],
            'enable_module' => [
                'tool_key' => 'hermes.module.enable_module',
                'capability_method' => 'enableModule',
                'query' => 'enable module',
                'unsupported_message' => 'Module enablement does not have a safe manifest/config writer registered for Hermes yet.',
                'message_contains' => 'manifest/config writer',
            ],
            'disable_module' => [
                'tool_key' => 'hermes.module.disable_module',
                'capability_method' => 'disableModule',
                'query' => 'disable module',
                'unsupported_message' => 'Module disablement does not have a safe manifest/config writer registered for Hermes yet.',
                'message_contains' => 'manifest/config writer',
            ],
            'install_module' => [
                'tool_key' => 'hermes.module.install_module',
                'capability_method' => 'installModule',
                'query' => 'install module',
                'unsupported_message' => 'Module installation is not wired to a trusted package source yet.',
                'message_contains' => 'trusted package source',
            ],
            'update_module' => [
                'tool_key' => 'hermes.module.update_module',
                'capability_method' => 'updateModule',
                'query' => 'update module',
                'unsupported_message' => 'Module-specific updates are not supported separately from trusted system releases.',
                'message_contains' => 'trusted system releases',
            ],
            'export_data' => [
                'tool_key' => 'hermes.data.export_data',
                'capability_method' => 'exportData',
                'query' => 'export data',
                'unsupported_message' => 'Generic data export needs a concrete report or dataset target. Ask Hermes to run or export a specific report.',
                'message_contains' => 'specific report',
            ],
            'import_data' => [
                'tool_key' => 'hermes.data.import_data',
                'capability_method' => 'importData',
                'query' => 'import data',
                'unsupported_message' => 'Generic data import needs a configured import job and source file. Use the Import module workflow for now.',
                'message_contains' => 'Import module workflow',
            ],
            'deduplicate' => [
                'tool_key' => 'hermes.data.deduplicate',
                'capability_method' => 'deduplicate',
                'query' => 'deduplicate',
                'unsupported_message' => 'Deduplication needs a concrete entity type and merge policy before it can run safely.',
                'message_contains' => 'merge policy',
            ],
            'rotate_keys' => [
                'tool_key' => 'hermes.security.rotate_keys',
                'capability_method' => 'rotateKeys',
                'query' => 'rotate keys',
                'unsupported_message' => 'Key rotation does not have a registered key-management backend for Hermes execution yet.',
                'message_contains' => 'key-management backend',
            ],
        ];
    }

    /**
     * @return array<string,string>
     */
    public static function definition( string $operationKey ): array {
        return self::definitions()[ strtolower( trim( $operationKey ) ) ] ?? [];
    }
}
