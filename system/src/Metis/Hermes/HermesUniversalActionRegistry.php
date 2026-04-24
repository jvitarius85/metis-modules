<?php
declare(strict_types=1);

namespace Metis\Hermes;

/**
 * HermesUniversalActionRegistry
 *
 * The canonical catalog of every action Hermes is permitted to invoke.
 * Any action not present in this registry must fail — no exceptions.
 *
 * Actions are grouped by domain:
 *   entity.*   — data CRUD operations on module entities
 *   system.*   — platform-level operations (backup, cache, self-heal)
 *   user.*     — identity and access management
 *   notify.*   — communication dispatch
 *   module.*   — module lifecycle operations
 *
 * Each definition declares:
 *   key          — unique dot-separated identifier
 *   category     — read | write | system | notify
 *   domain       — top-level grouping
 *   description  — human-readable purpose
 *   read_only    — true = no enclave approval required
 *   permission   — required permission key (empty = no restriction)
 *   enclave_op   — registered enclave operation (empty = read-only path)
 *   safe_for_playbook — can appear in a playbook step definition
 */
final class HermesUniversalActionRegistry {

    private static ?array $index = null;

    // ------------------------------------------------------------------
    // Public API
    // ------------------------------------------------------------------

    /** Returns all registered action definitions, keyed by action key. */
    public function all(): array {
        return $this->index();
    }

    /** Returns a single action definition, or null if not registered. */
    public function get( string $key ): ?array {
        return $this->index()[ strtolower( trim( $key ) ) ] ?? null;
    }

    /** Returns true when the key maps to a registered action. */
    public function has( string $key ): bool {
        return isset( $this->index()[ strtolower( trim( $key ) ) ] );
    }

    /** Returns all actions that are safe to use inside a playbook step. */
    public function playbookActions(): array {
        return array_filter(
            $this->index(),
            static fn ( array $def ): bool => (bool) ( $def['safe_for_playbook'] ?? false )
        );
    }

    /** Returns all actions matching a domain prefix (e.g. "entity", "system"). */
    public function forDomain( string $domain ): array {
        $domain = strtolower( trim( $domain ) );
        return array_filter(
            $this->index(),
            static fn ( array $def ): bool => (string) ( $def['domain'] ?? '' ) === $domain
        );
    }

    // ------------------------------------------------------------------
    // Registry definition
    // ------------------------------------------------------------------

    private function index(): array {
        if ( self::$index !== null ) {
            return self::$index;
        }

        $actions = [

            // ---- entity: read operations --------------------------------

            'entity.get' => [
                'key' => 'entity.get', 'domain' => 'entity', 'category' => 'read',
                'description'      => 'Retrieve a single entity record by ID or UID.',
                'read_only'        => true,  'permission' => '',
                'enclave_op'       => '',    'safe_for_playbook' => true,
            ],
            'entity.get_attribute' => [
                'key' => 'entity.get_attribute', 'domain' => 'entity', 'category' => 'read',
                'description'      => 'Retrieve a specific attribute (email, phone, role, etc.) from an entity.',
                'read_only'        => true,  'permission' => 'people.view',
                'enclave_op'       => '',    'safe_for_playbook' => true,
            ],
            'entity.list' => [
                'key' => 'entity.list', 'domain' => 'entity', 'category' => 'read',
                'description'      => 'List entity records with optional filters and pagination.',
                'read_only'        => true,  'permission' => '',
                'enclave_op'       => '',    'safe_for_playbook' => true,
            ],
            'entity.search' => [
                'key' => 'entity.search', 'domain' => 'entity', 'category' => 'read',
                'description'      => 'Full-text or filtered search across entity records.',
                'read_only'        => true,  'permission' => '',
                'enclave_op'       => '',    'safe_for_playbook' => true,
            ],
            'entity.count' => [
                'key' => 'entity.count', 'domain' => 'entity', 'category' => 'read',
                'description'      => 'Return a scalar count matching filters.',
                'read_only'        => true,  'permission' => '',
                'enclave_op'       => '',    'safe_for_playbook' => true,
            ],
            'entity.aggregate' => [
                'key' => 'entity.aggregate', 'domain' => 'entity', 'category' => 'read',
                'description'      => 'Run sum, avg, min, max, or count aggregate operations.',
                'read_only'        => true,  'permission' => '',
                'enclave_op'       => '',    'safe_for_playbook' => true,
            ],

            // ---- entity: write operations --------------------------------

            'entity.create' => [
                'key' => 'entity.create', 'domain' => 'entity', 'category' => 'write',
                'description'      => 'Create a new entity record.',
                'read_only'        => false, 'permission' => '',
                'enclave_op'       => 'hermes.entity.create', 'safe_for_playbook' => false,
            ],
            'entity.update' => [
                'key' => 'entity.update', 'domain' => 'entity', 'category' => 'write',
                'description'      => 'Update fields on an existing entity record.',
                'read_only'        => false, 'permission' => '',
                'enclave_op'       => 'hermes.entity.update', 'safe_for_playbook' => false,
            ],
            'entity.delete' => [
                'key' => 'entity.delete', 'domain' => 'entity', 'category' => 'write',
                'description'      => 'Delete an entity record.',
                'read_only'        => false, 'permission' => '',
                'enclave_op'       => 'hermes.entity.delete', 'safe_for_playbook' => false,
            ],
            'entity.set_status' => [
                'key' => 'entity.set_status', 'domain' => 'entity', 'category' => 'write',
                'description'      => 'Change the status field on an entity record.',
                'read_only'        => false, 'permission' => '',
                'enclave_op'       => 'hermes.entity.set_status', 'safe_for_playbook' => false,
            ],
            'entity.archive' => [
                'key' => 'entity.archive', 'domain' => 'entity', 'category' => 'write',
                'description'      => 'Archive an entity record without deleting it.',
                'read_only'        => false, 'permission' => '',
                'enclave_op'       => 'hermes.entity.archive', 'safe_for_playbook' => false,
            ],
            'entity.restore' => [
                'key' => 'entity.restore', 'domain' => 'entity', 'category' => 'write',
                'description'      => 'Restore a previously archived entity record.',
                'read_only'        => false, 'permission' => '',
                'enclave_op'       => 'hermes.entity.restore', 'safe_for_playbook' => false,
            ],
            'entity.assign_user' => [
                'key' => 'entity.assign_user', 'domain' => 'entity', 'category' => 'write',
                'description'      => 'Assign a user to an entity record.',
                'read_only'        => false, 'permission' => '',
                'enclave_op'       => 'hermes.entity.assign_user', 'safe_for_playbook' => false,
            ],
            'entity.link' => [
                'key' => 'entity.link', 'domain' => 'entity', 'category' => 'write',
                'description'      => 'Create a relationship link between two entity records.',
                'read_only'        => false, 'permission' => '',
                'enclave_op'       => 'hermes.entity.link', 'safe_for_playbook' => false,
            ],
            'entity.unlink' => [
                'key' => 'entity.unlink', 'domain' => 'entity', 'category' => 'write',
                'description'      => 'Remove a relationship link between two entity records.',
                'read_only'        => false, 'permission' => '',
                'enclave_op'       => 'hermes.entity.unlink', 'safe_for_playbook' => false,
            ],
            'entity.add_comment' => [
                'key' => 'entity.add_comment', 'domain' => 'entity', 'category' => 'write',
                'description'      => 'Append a comment or note to an entity record.',
                'read_only'        => false, 'permission' => '',
                'enclave_op'       => 'hermes.entity.add_comment', 'safe_for_playbook' => false,
            ],
            'entity.attach_file' => [
                'key' => 'entity.attach_file', 'domain' => 'entity', 'category' => 'write',
                'description'      => 'Attach a file reference to an entity record.',
                'read_only'        => false, 'permission' => '',
                'enclave_op'       => 'hermes.entity.attach_file', 'safe_for_playbook' => false,
            ],
            'entity.remove_attachment' => [
                'key' => 'entity.remove_attachment', 'domain' => 'entity', 'category' => 'write',
                'description'      => 'Remove an attached file from an entity record.',
                'read_only'        => false, 'permission' => '',
                'enclave_op'       => 'hermes.entity.remove_attachment', 'safe_for_playbook' => false,
            ],
            'entity.bulk_create' => [
                'key' => 'entity.bulk_create', 'domain' => 'entity', 'category' => 'write',
                'description'      => 'Create multiple entity records in a single operation.',
                'read_only'        => false, 'permission' => '',
                'enclave_op'       => 'hermes.entity.bulk_create', 'safe_for_playbook' => false,
            ],
            'entity.bulk_update' => [
                'key' => 'entity.bulk_update', 'domain' => 'entity', 'category' => 'write',
                'description'      => 'Update multiple entity records in a single operation.',
                'read_only'        => false, 'permission' => '',
                'enclave_op'       => 'hermes.entity.bulk_update', 'safe_for_playbook' => false,
            ],
            'entity.bulk_delete' => [
                'key' => 'entity.bulk_delete', 'domain' => 'entity', 'category' => 'write',
                'description'      => 'Delete multiple entity records in a single operation.',
                'read_only'        => false, 'permission' => '',
                'enclave_op'       => 'hermes.entity.bulk_delete', 'safe_for_playbook' => false,
            ],

            // ---- system operations ----------------------------------------

            'system.validate' => [
                'key' => 'system.validate', 'domain' => 'system', 'category' => 'read',
                'description'      => 'Run a platform integrity validation check.',
                'read_only'        => true,  'permission' => 'system.diagnostics.view',
                'enclave_op'       => '',    'safe_for_playbook' => true,
            ],
            'system.health_check' => [
                'key' => 'system.health_check', 'domain' => 'system', 'category' => 'read',
                'description'      => 'Return a health summary for all registered modules and workers.',
                'read_only'        => true,  'permission' => 'system.diagnostics.view',
                'enclave_op'       => '',    'safe_for_playbook' => true,
            ],
            'system.backup' => [
                'key' => 'system.backup', 'domain' => 'system', 'category' => 'system',
                'description'      => 'Trigger a full system backup.',
                'read_only'        => false, 'permission' => 'system.backup.execute',
                'enclave_op'       => 'hermes.command.run_backup', 'safe_for_playbook' => true,
            ],
            'system.restore' => [
                'key' => 'system.restore', 'domain' => 'system', 'category' => 'system',
                'description'      => 'Restore from a backup snapshot.',
                'read_only'        => false, 'permission' => 'system.backup.execute',
                'enclave_op'       => 'hermes.system.restore', 'safe_for_playbook' => false,
            ],
            'system.clear_cache' => [
                'key' => 'system.clear_cache', 'domain' => 'system', 'category' => 'system',
                'description'      => 'Flush runtime caches.',
                'read_only'        => false, 'permission' => 'system.backup.execute',
                'enclave_op'       => 'hermes.system.clear_cache', 'safe_for_playbook' => true,
            ],
            'system.self_heal' => [
                'key' => 'system.self_heal', 'domain' => 'system', 'category' => 'system',
                'description'      => 'Run integrity scan and restore damaged assets.',
                'read_only'        => false, 'permission' => 'system.backup.execute',
                'enclave_op'       => 'hermes.command.aut_self_heal', 'safe_for_playbook' => true,
            ],

            // ---- user / identity operations --------------------------------

            'user.create' => [
                'key' => 'user.create', 'domain' => 'user', 'category' => 'write',
                'description'      => 'Create a new user and person record.',
                'read_only'        => false, 'permission' => 'people.create',
                'enclave_op'       => 'hermes.command.create_user', 'safe_for_playbook' => false,
            ],
            'user.update' => [
                'key' => 'user.update', 'domain' => 'user', 'category' => 'write',
                'description'      => 'Update user profile fields.',
                'read_only'        => false, 'permission' => 'people.edit',
                'enclave_op'       => 'hermes.user.update', 'safe_for_playbook' => false,
            ],
            'user.disable' => [
                'key' => 'user.disable', 'domain' => 'user', 'category' => 'write',
                'description'      => 'Disable a user account without deleting it.',
                'read_only'        => false, 'permission' => 'people.edit',
                'enclave_op'       => 'hermes.command.offboard_user', 'safe_for_playbook' => false,
            ],
            'user.enable' => [
                'key' => 'user.enable', 'domain' => 'user', 'category' => 'write',
                'description'      => 'Re-enable a previously disabled user account.',
                'read_only'        => false, 'permission' => 'people.edit',
                'enclave_op'       => 'hermes.user.enable', 'safe_for_playbook' => false,
            ],
            'user.assign_role' => [
                'key' => 'user.assign_role', 'domain' => 'user', 'category' => 'write',
                'description'      => 'Assign or change roles for a user.',
                'read_only'        => false, 'permission' => 'people.edit',
                'enclave_op'       => 'hermes.command.manage_user_roles', 'safe_for_playbook' => false,
            ],

            // ---- notify operations ----------------------------------------

            'notify.user' => [
                'key' => 'notify.user', 'domain' => 'notify', 'category' => 'notify',
                'description'      => 'Send a notification to a specific user.',
                'read_only'        => false, 'permission' => 'communications.announcement.send',
                'enclave_op'       => 'hermes.notify.user', 'safe_for_playbook' => true,
            ],
            'notify.group' => [
                'key' => 'notify.group', 'domain' => 'notify', 'category' => 'notify',
                'description'      => 'Send a notification to a user group.',
                'read_only'        => false, 'permission' => 'communications.announcement.send',
                'enclave_op'       => 'hermes.notify.group', 'safe_for_playbook' => true,
            ],
            'notify.email' => [
                'key' => 'notify.email', 'domain' => 'notify', 'category' => 'notify',
                'description'      => 'Send a transactional email through the communications service.',
                'read_only'        => false, 'permission' => 'communications.announcement.send',
                'enclave_op'       => 'hermes.command.send_announcement', 'safe_for_playbook' => true,
            ],
            'notify.webhook' => [
                'key' => 'notify.webhook', 'domain' => 'notify', 'category' => 'notify',
                'description'      => 'Dispatch an outbound webhook event.',
                'read_only'        => false, 'permission' => 'system.backup.execute',
                'enclave_op'       => 'hermes.notify.webhook', 'safe_for_playbook' => false,
            ],

            // ---- module lifecycle operations --------------------------------

            'module.enable' => [
                'key' => 'module.enable', 'domain' => 'module', 'category' => 'system',
                'description'      => 'Enable a registered Metis module.',
                'read_only'        => false, 'permission' => 'system.backup.execute',
                'enclave_op'       => 'hermes.module.enable', 'safe_for_playbook' => false,
            ],
            'module.disable' => [
                'key' => 'module.disable', 'domain' => 'module', 'category' => 'system',
                'description'      => 'Disable a registered Metis module.',
                'read_only'        => false, 'permission' => 'system.backup.execute',
                'enclave_op'       => 'hermes.module.disable', 'safe_for_playbook' => false,
            ],
            'module.validate' => [
                'key' => 'module.validate', 'domain' => 'module', 'category' => 'read',
                'description'      => 'Validate a module\'s schema, routes, and service registrations.',
                'read_only'        => true,  'permission' => 'system.diagnostics.view',
                'enclave_op'       => '',    'safe_for_playbook' => true,
            ],

        ];

        self::$index = $actions;
        return self::$index;
    }
}
