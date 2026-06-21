<?php
declare(strict_types=1);

namespace Metis\Modules\People;

use Metis\Core\Application;
use Metis\Core\Cache\CacheService;
use Metis\Core\ModuleLoader;

final class AccessManager {
    private static bool $seeded = false;

    public static function seedPermissionsAndRoles(): void {
        if ( self::$seeded ) {
            return;
        }

        if ( function_exists( 'metis_runtime_run_once_per_signature' ) ) {
            \metis_runtime_run_once_per_signature(
                'people_access_seed',
                self::setupSignatureFiles(),
                static function (): void {
                    self::seedPermissionsAndRolesNow();
                }
            );
            self::$seeded = true;
            return;
        }

        self::seedPermissionsAndRolesNow();
        self::$seeded = true;
    }

    private static function seedPermissionsAndRolesNow(): void {
        SchemaManager::ensureSchema();
        $db = self::db();

        $roles_table = \Metis_Tables::get( 'people_roles' );
        $perms_table = \Metis_Tables::get( 'people_permissions' );
        $role_perms_table = \Metis_Tables::get( 'people_role_perms' );
        $cache_changed = false;

        $default_roles = [
            [ 'role_key' => 'administrator', 'role_domain' => 'metis', 'name' => 'Administrator', 'description' => 'Full Metis access', 'is_system' => 1 ],
            [ 'role_key' => 'donor_admin', 'role_domain' => 'metis', 'name' => 'Donor Admin', 'description' => 'Donations + contacts operations', 'is_system' => 1 ],
            [ 'role_key' => 'donor_user', 'role_domain' => 'metis', 'name' => 'Donor User', 'description' => 'Read-only donor/contact access', 'is_system' => 1 ],
            [ 'role_key' => 'newsletter_admin', 'role_domain' => 'metis', 'name' => 'Newsletter Admin', 'description' => 'Newsletter operations access', 'is_system' => 1 ],
            [ 'role_key' => 'board', 'role_domain' => 'metis', 'name' => 'Board', 'description' => 'Board-level read access', 'is_system' => 1 ],
            [ 'role_key' => 'finance', 'role_domain' => 'metis', 'name' => 'Finance', 'description' => 'Finance mode operational access for finance workflows and controls.', 'is_system' => 1 ],
            [ 'role_key' => 'website_manager', 'role_domain' => 'metis', 'name' => 'Website Manager', 'description' => 'Manages website pages, posts, categories, menus, themes, and publishing workflows.', 'is_system' => 1 ],
            [ 'role_key' => 'workspace_manager', 'role_domain' => 'metis', 'name' => 'Workspace Manager', 'description' => 'Manages Google Workspace users, groups, and security actions in Metis.', 'is_system' => 1 ],
            [ 'role_key' => 'account_owner', 'role_domain' => 'stripe', 'name' => 'Account owner', 'description' => 'Full account ownership, billing, and sensitive account controls.', 'is_system' => 1 ],
            [ 'role_key' => 'administrator', 'role_domain' => 'stripe', 'name' => 'Administrator', 'description' => 'Broad administrative access across Stripe settings and operations.', 'is_system' => 1 ],
            [ 'role_key' => 'analyst', 'role_domain' => 'stripe', 'name' => 'Analyst', 'description' => 'Read-focused access for reporting and operational visibility.', 'is_system' => 1 ],
            [ 'role_key' => 'developer', 'role_domain' => 'stripe', 'name' => 'Developer', 'description' => 'API and technical configuration access for integrations.', 'is_system' => 1 ],
            [ 'role_key' => 'disputes_analyst', 'role_domain' => 'stripe', 'name' => 'Disputes analyst', 'description' => 'Focused access for chargebacks and dispute workflows.', 'is_system' => 1 ],
            [ 'role_key' => 'iam_admin', 'role_domain' => 'stripe', 'name' => 'IAM admin', 'description' => 'Manage team members, authentication, and role assignments.', 'is_system' => 1 ],
            [ 'role_key' => 'refunds_analyst', 'role_domain' => 'stripe', 'name' => 'Refunds analyst', 'description' => 'Focused access for refund operations and reviews.', 'is_system' => 1 ],
            [ 'role_key' => 'risk_analyst', 'role_domain' => 'stripe', 'name' => 'Risk analyst', 'description' => 'Access to fraud/risk monitoring and related tooling.', 'is_system' => 1 ],
            [ 'role_key' => 'support_specialist', 'role_domain' => 'stripe', 'name' => 'Support specialist', 'description' => 'Operational access for support tasks and issue handling.', 'is_system' => 1 ],
            [ 'role_key' => 'view_only', 'role_domain' => 'stripe', 'name' => 'View only', 'description' => 'Read-only dashboard access with no write actions.', 'is_system' => 1 ],
            [ 'role_key' => 'super_admin', 'role_domain' => 'workspace', 'name' => 'Super Admin', 'description' => 'Full Google Workspace admin access.', 'is_system' => 1 ],
            [ 'role_key' => 'groups_admin', 'role_domain' => 'workspace', 'name' => 'Groups Admin', 'description' => 'Manages groups and memberships in Google Workspace.', 'is_system' => 1 ],
            [ 'role_key' => 'help_desk_admin', 'role_domain' => 'workspace', 'name' => 'Help Desk Admin', 'description' => 'Supports user account recovery and assistance.', 'is_system' => 1 ],
            [ 'role_key' => 'services_admin', 'role_domain' => 'workspace', 'name' => 'Services Admin', 'description' => 'Controls access to Workspace services and app settings.', 'is_system' => 1 ],
            [ 'role_key' => 'user_management_admin', 'role_domain' => 'workspace', 'name' => 'User Management Admin', 'description' => 'Creates, updates, and suspends user accounts.', 'is_system' => 1 ],
            [ 'role_key' => 'readonly_admin', 'role_domain' => 'workspace', 'name' => 'Read-only Admin', 'description' => 'Read-only access to Workspace admin settings.', 'is_system' => 1 ],
        ];

        foreach ( $default_roles as $meta ) {
            $role_key = (string) ( $meta['role_key'] ?? '' );
            $role_domain = (string) ( $meta['role_domain'] ?? 'metis' );
            if ( $role_key === '' ) {
                continue;
            }

            $existing = $db->scalar( "SELECT id FROM {$roles_table} WHERE role_key = %s AND role_domain = %s LIMIT 1", [ $role_key, $role_domain ] );
            if ( $existing ) {
                continue;
            }

            $db->insert(
                $roles_table,
                [
                    'role_key' => $role_key,
                    'role_domain' => $role_domain,
                    'role_name' => (string) $meta['name'],
                    'description' => (string) $meta['description'],
                    'is_system' => (int) $meta['is_system'],
                ],
                [ '%s', '%s', '%s', '%s', '%d' ]
            );
            $cache_changed = true;
        }

        self::syncDeclaredPermissions();

        if ( self::repairLegacyPermissionKeys( $db ) ) {
            $cache_changed = true;
        }

        if ( self::syncDeclaredRolePermissions( $db ) ) {
            $cache_changed = true;
        }

        if ( $cache_changed ) {
            CacheService::clearGroup( 'permissions' );
        }

        self::$seeded = true;
    }

    public static function syncDeclaredPermissions(): void {
        SchemaManager::ensureSchema();
        $db = self::db();

        $perms_table = \Metis_Tables::get( 'people_permissions' );

        foreach ( self::declaredPermissions() as $permission ) {
            $permission_key = (string) ( $permission['key'] ?? '' );
            if ( $permission_key === '' ) {
                continue;
            }

            $record = [
                'permission_key'  => $permission_key,
                'module_slug'     => (string) ( $permission['module'] ?? '' ),
                'action_key'      => (string) ( $permission['action'] ?? '' ),
                'permission_name' => (string) ( $permission['name'] ?? $permission_key ),
            ];

            $existing = $db->fetchOne(
                "SELECT id, module_slug, action_key, permission_name FROM {$perms_table} WHERE permission_key = %s LIMIT 1",
                [ $permission_key ]
            );

            if ( ! is_array( $existing ) ) {
                $db->insert(
                    $perms_table,
                    $record,
                    [ '%s', '%s', '%s', '%s' ]
                );
                CacheService::clearGroup( 'permissions' );
                continue;
            }

            $updates = [];
            foreach ( [ 'module_slug', 'action_key', 'permission_name' ] as $field ) {
                if ( (string) ( $existing[ $field ] ?? '' ) !== (string) $record[ $field ] ) {
                    $updates[ $field ] = $record[ $field ];
                }
            }

            if ( $updates === [] ) {
                continue;
            }

            $db->update(
                $perms_table,
                $updates,
                [ 'id' => (int) $existing['id'] ],
                array_fill( 0, count( $updates ), '%s' ),
                [ '%d' ]
            );
            CacheService::clearGroup( 'permissions' );
        }
    }

    private static function repairLegacyPermissionKeys( object $db ): bool {
        $perms_table = \Metis_Tables::get( 'people_permissions' );
        $role_perms_table = \Metis_Tables::get( 'people_role_perms' );

        $declared_by_pair = [];
        foreach ( self::declaredPermissions() as $permission ) {
            $module_slug = \metis_key_clean( (string) ( $permission['module'] ?? '' ) );
            $action_key = \metis_key_clean( (string) ( $permission['action'] ?? '' ) );
            $permission_key = self::normalizePermissionKey( (string) ( $permission['key'] ?? '' ) );
            if ( $module_slug === '' || $action_key === '' || $permission_key === '' ) {
                continue;
            }
            $declared_by_pair[ $module_slug . '|' . $action_key ] = $permission_key;
        }

        if ( $declared_by_pair === [] ) {
            return false;
        }

        $rows = $db->fetchAll(
            "SELECT id, permission_key, module_slug, action_key
             FROM {$perms_table}"
        ) ?: [];

        $permission_ids_by_key = [];
        foreach ( $rows as $row ) {
            $permission_key = self::normalizePermissionKey( (string) ( $row['permission_key'] ?? '' ) );
            $permission_id = (int) ( $row['id'] ?? 0 );
            if ( $permission_key !== '' && $permission_id > 0 && ! isset( $permission_ids_by_key[ $permission_key ] ) ) {
                $permission_ids_by_key[ $permission_key ] = $permission_id;
            }
        }

        $changed = false;
        foreach ( $rows as $row ) {
            $permission_id = (int) ( $row['id'] ?? 0 );
            $module_slug = \metis_key_clean( (string) ( $row['module_slug'] ?? '' ) );
            $action_key = \metis_key_clean( (string) ( $row['action_key'] ?? '' ) );
            $current_key = self::normalizePermissionKey( (string) ( $row['permission_key'] ?? '' ) );
            if ( $permission_id < 1 || $module_slug === '' || $action_key === '' || $current_key === '' ) {
                continue;
            }

            $pair = $module_slug . '|' . $action_key;
            $canonical_key = (string) ( $declared_by_pair[ $pair ] ?? '' );
            if ( $canonical_key === '' || $canonical_key === $current_key ) {
                continue;
            }

            $canonical_id = (int) ( $permission_ids_by_key[ $canonical_key ] ?? 0 );
            if ( $canonical_id < 1 ) {
                $db->update(
                    $perms_table,
                    [ 'permission_key' => $canonical_key ],
                    [ 'id' => $permission_id ],
                    [ '%s' ],
                    [ '%d' ]
                );
                unset( $permission_ids_by_key[ $current_key ] );
                $permission_ids_by_key[ $canonical_key ] = $permission_id;
                $changed = true;
                continue;
            }

            $role_perm_rows = $db->fetchAll(
                "SELECT id, role_id, allow_access
                 FROM {$role_perms_table}
                 WHERE permission_id = %d",
                [ $permission_id ]
            ) ?: [];

            foreach ( $role_perm_rows as $role_perm_row ) {
                $role_perm_id = (int) ( $role_perm_row['id'] ?? 0 );
                $role_id = (int) ( $role_perm_row['role_id'] ?? 0 );
                $allow_access = (int) ( $role_perm_row['allow_access'] ?? 0 );
                if ( $role_perm_id < 1 || $role_id < 1 ) {
                    continue;
                }

                $existing_canonical = $db->fetchOne(
                    "SELECT id, allow_access
                     FROM {$role_perms_table}
                     WHERE role_id = %d AND permission_id = %d
                     LIMIT 1",
                    [ $role_id, $canonical_id ]
                );

                if ( is_array( $existing_canonical ) ) {
                    if ( $allow_access === 1 && (int) ( $existing_canonical['allow_access'] ?? 0 ) !== 1 ) {
                        $db->update(
                            $role_perms_table,
                            [ 'allow_access' => 1 ],
                            [ 'id' => (int) $existing_canonical['id'] ],
                            [ '%d' ],
                            [ '%d' ]
                        );
                    }
                    $db->delete( $role_perms_table, [ 'id' => $role_perm_id ], [ '%d' ] );
                    $changed = true;
                    continue;
                }

                $db->update(
                    $role_perms_table,
                    [ 'permission_id' => $canonical_id ],
                    [ 'id' => $role_perm_id ],
                    [ '%d' ],
                    [ '%d' ]
                );
                $changed = true;
            }

            $db->delete( $perms_table, [ 'id' => $permission_id ], [ '%d' ] );
            $changed = true;
        }

        return $changed;
    }

    private static function syncDeclaredRolePermissions( object $db ): bool {
        $roles_table = \Metis_Tables::get( 'people_roles' );
        $perms_table = \Metis_Tables::get( 'people_permissions' );
        $role_perms_table = \Metis_Tables::get( 'people_role_perms' );

        $role_ids = [];
        $role_rows = $db->fetchAll( "SELECT id, role_key FROM {$roles_table} WHERE role_domain = 'metis'" ) ?: [];
        foreach ( $role_rows as $row ) {
            $role_key = \metis_key_clean( (string) ( $row['role_key'] ?? '' ) );
            $role_id = (int) ( $row['id'] ?? 0 );
            if ( $role_key !== '' && $role_id > 0 ) {
                $role_ids[ $role_key ] = $role_id;
            }
        }

        $permission_ids = [];
        $permission_rows = $db->fetchAll( "SELECT id, permission_key FROM {$perms_table}" ) ?: [];
        foreach ( $permission_rows as $row ) {
            $permission_key = self::normalizePermissionKey( (string) ( $row['permission_key'] ?? '' ) );
            $permission_id = (int) ( $row['id'] ?? 0 );
            if ( $permission_key !== '' && $permission_id > 0 ) {
                $permission_ids[ $permission_key ] = $permission_id;
            }
        }

        if ( $permission_ids === [] ) {
            return false;
        }

        $policy = [ 'administrator' => array_keys( $permission_ids ) ];
        foreach ( self::declaredPermissions() as $permission ) {
            $permission_key = self::normalizePermissionKey( (string) ( $permission['key'] ?? '' ) );
            if ( $permission_key === '' || empty( $permission_ids[ $permission_key ] ) ) {
                continue;
            }

            foreach ( (array) ( $permission['roles'] ?? [] ) as $role_key ) {
                $role_key = \metis_key_clean( (string) $role_key );
                if ( $role_key === '' ) {
                    continue;
                }

                if ( ! isset( $policy[ $role_key ] ) ) {
                    $policy[ $role_key ] = [];
                }

                $policy[ $role_key ][] = $permission_key;
            }
        }

        $tracked_permission_ids = array_values( array_map( 'intval', array_values( $permission_ids ) ) );
        $tracked_permission_ids = array_values( array_filter( $tracked_permission_ids, static fn( int $id ): bool => $id > 0 ) );
        if ( $tracked_permission_ids === [] ) {
            return false;
        }

        $changed = false;
        foreach ( $policy as $role_key => $allowed_permission_keys ) {
            $role_id = (int) ( $role_ids[ $role_key ] ?? 0 );
            if ( $role_id < 1 ) {
                continue;
            }

            $allowed_permission_keys = array_values( array_unique( array_filter(
                array_map( static fn( mixed $key ): string => self::normalizePermissionKey( (string) $key ), $allowed_permission_keys ),
                static fn( string $key ): bool => $key !== ''
            ) ) );

            $desired_permission_ids = [];
            foreach ( $allowed_permission_keys as $permission_key ) {
                $permission_id = (int) ( $permission_ids[ $permission_key ] ?? 0 );
                if ( $permission_id > 0 ) {
                    $desired_permission_ids[] = $permission_id;
                }
            }
            $desired_permission_ids = array_values( array_unique( $desired_permission_ids ) );

            $existing_rows = $db->fetchAll(
                "SELECT id, permission_id, allow_access
                 FROM {$role_perms_table}
                 WHERE role_id = %d",
                [ $role_id ]
            ) ?: [];

            $existing_map = [];
            foreach ( $existing_rows as $row ) {
                $permission_id = (int) ( $row['permission_id'] ?? 0 );
                $row_id = (int) ( $row['id'] ?? 0 );
                if ( $permission_id > 0 && $row_id > 0 ) {
                    $existing_map[ $permission_id ] = [
                        'id' => $row_id,
                        'allow_access' => (int) ( $row['allow_access'] ?? 0 ),
                    ];
                }
            }

            foreach ( $desired_permission_ids as $permission_id ) {
                if ( isset( $existing_map[ $permission_id ] ) ) {
                    if ( (int) $existing_map[ $permission_id ]['allow_access'] !== 1 ) {
                        $db->update(
                            $role_perms_table,
                            [ 'allow_access' => 1 ],
                            [ 'id' => (int) $existing_map[ $permission_id ]['id'] ],
                            [ '%d' ],
                            [ '%d' ]
                        );
                        $changed = true;
                    }
                    continue;
                }

                $db->insert(
                    $role_perms_table,
                    [
                        'role_id' => $role_id,
                        'permission_id' => $permission_id,
                        'allow_access' => 1,
                    ],
                    [ '%d', '%d', '%d' ]
                );
                $changed = true;
            }

            foreach ( $tracked_permission_ids as $permission_id ) {
                if ( in_array( $permission_id, $desired_permission_ids, true ) ) {
                    continue;
                }

                if ( ! isset( $existing_map[ $permission_id ] ) ) {
                    continue;
                }

                $db->delete(
                    $role_perms_table,
                    [
                        'id' => (int) $existing_map[ $permission_id ]['id'],
                    ],
                    [ '%d' ]
                );
                $changed = true;
            }
        }

        return $changed;
    }

    public static function permissionMatrixForPerson( int $person_id, bool $force_refresh = false ): array {
        if ( $person_id < 1 ) {
            return [
                'person_id' => 0,
                'permissions' => [],
                'roles' => [],
            ];
        }

        $cache_key = 'permissions.user_' . $person_id;
        if ( $force_refresh ) {
            CacheService::forget( $cache_key );
        }

        return CacheService::remember( $cache_key, 600, static function () use ( $person_id ): array {
            SchemaManager::ensureSchema();
            self::seedPermissionsAndRoles();
            $db = self::db();

            $roles_table = \Metis_Tables::get( 'people_roles' );
            $perms_table = \Metis_Tables::get( 'people_permissions' );
            $user_roles_table = \Metis_Tables::get( 'people_user_roles' );
            $role_perms_table = \Metis_Tables::get( 'people_role_perms' );

            $now = \function_exists( 'metis_current_time' ) ? \metis_current_time( 'mysql' ) : gmdate( 'Y-m-d H:i:s' );
            $rows = $db->fetchAll(
                "SELECT DISTINCT p.permission_key, r.role_key
                 FROM {$user_roles_table} ur
                 INNER JOIN {$roles_table} r ON r.id = ur.role_id
                 INNER JOIN {$role_perms_table} rp ON rp.role_id = ur.role_id AND rp.allow_access = 1
                 INNER JOIN {$perms_table} p ON p.id = rp.permission_id
                 WHERE ur.person_id = %d
                   AND (ur.start_at IS NULL OR ur.start_at <= %s)
                   AND (ur.end_at IS NULL OR ur.end_at >= %s)",
                [ $person_id, $now, $now ]
            );

            $permissions = [];
            $roles = [];
            foreach ( $rows as $row ) {
                $permission_key = self::normalizePermissionKey( (string) ( $row['permission_key'] ?? '' ) );
                $role_key = \metis_key_clean( (string) ( $row['role_key'] ?? '' ) );
                if ( $permission_key !== '' ) {
                    $permissions[ $permission_key ] = true;
                }
                if ( $role_key !== '' ) {
                    $roles[ $role_key ] = true;
                }
            }

            return [
                'person_id' => $person_id,
                'permissions' => array_values( array_keys( $permissions ) ),
                'roles' => array_values( array_keys( $roles ) ),
            ];
        } );
    }

    public static function activePermissionKeysForPerson( int $person_id, bool $force_refresh = false ): array {
        $matrix = self::permissionMatrixForPerson( $person_id, $force_refresh );

        return array_values( array_filter(
            array_map(
                static fn( mixed $permission_key ): string => self::normalizePermissionKey( (string) $permission_key ),
                (array) ( $matrix['permissions'] ?? [] )
            ),
            static fn( string $permission_key ): bool => $permission_key !== ''
        ) );
    }

    private static function declaredPermissions(): array {
        if ( Application::has_service( 'modules' ) ) {
            $modules = Application::service( 'modules' );
            if ( method_exists( $modules, 'declaredPermissions' ) ) {
                return (array) $modules->declaredPermissions();
            }
        }

        $modules     = function_exists( 'metis_get_modules' ) ? \metis_get_modules() : [];
        $permissions = [];

        foreach ( $modules as $slug => $module ) {
            $slug = \metis_key_clean( (string) $slug );
            if ( $slug === '' ) {
                continue;
            }

            $normalized = ModuleLoader::normalizeManifestPermissions( $slug, (array) ( $module['config']['permissions'] ?? [] ) );
            foreach ( (array) ( $normalized['definitions'] ?? [] ) as $definition ) {
                if ( is_array( $definition ) ) {
                    $permissions[] = $definition;
                }
            }
        }

        return $permissions;
    }

    public static function getOrCreateCurrentPerson(): ?array {
        if ( ! \metis_user_logged_in() ) {
            return null;
        }

        self::seedPermissionsAndRoles();
        $db = self::db();

        $people_table = \Metis_Tables::get( 'people' );
        $roles_table = \Metis_Tables::get( 'people_roles' );
        $user_roles_table = \Metis_Tables::get( 'people_user_roles' );

        if ( function_exists( 'metis_auth_current_person_id' ) ) {
            $current_person_id = (int) \metis_auth_current_person_id();
            if ( $current_person_id > 0 ) {
                $current_person = $db->fetchOne( "SELECT * FROM {$people_table} WHERE id = %d LIMIT 1", [ $current_person_id ] );
                if ( is_array( $current_person ) ) {
                    return $current_person;
                }
            }
        }

        $current_user = \metis_runtime_current_user();
        $email = strtolower( trim( (string) ( $current_user->user_email ?? '' ) ) );
        if ( $email === '' || ! \metis_email_is_valid( $email ) ) {
            return null;
        }

        $person = $db->fetchOne( "SELECT * FROM {$people_table} WHERE email = %s LIMIT 1", [ $email ] );
        $created = false;
        if ( $person ) {
            $update = [];
            $formats = [];
            if ( (string) ( $person['auth_provider'] ?? '' ) === '' ) {
                $update['auth_provider'] = 'metis';
                $formats[] = '%s';
            }
            if ( empty( $person['display_name'] ) ) {
                $update['display_name'] = (string) ( $current_user->display_name ?? $email );
                $formats[] = '%s';
            }
            if ( ! empty( $update ) ) {
                $db->update( $people_table, $update, [ 'id' => (int) $person['id'] ], $formats, [ '%d' ] );
            }

            $person_id = (int) $person['id'];
            $pid = (string) ( $person['pid'] ?? '' );
            if ( $pid === '' ) {
                $pid = \metis_generate_code( 'PE', $people_table, 'pid' );
                $db->update( $people_table, [ 'pid' => $pid ], [ 'id' => $person_id ], [ '%s' ], [ '%d' ] );
            }
        } else {
            $payload = [
                'auth_provider' => 'metis',
                'email' => $email,
                'first_name' => (string) ( $current_user->first_name ?? '' ),
                'last_name' => (string) ( $current_user->last_name ?? '' ),
                'display_name' => (string) ( $current_user->display_name ?? $email ),
            ];
            if ( function_exists( 'metis_entity_id_service' ) ) {
                $payload = \metis_entity_id_service()->assignForInsert( 'person', $payload );
            } else {
                $payload['pid'] = \metis_generate_code( 'PPL', $people_table, 'pid' );
            }
            $db->insert( $people_table, $payload, [ '%s', '%s', '%s', '%s', '%s', '%s', '%s' ] );
            $person_id = $db->lastInsertId();
            $created = $person_id > 0;
            if ( $created && function_exists( 'metis_entity_id_service' ) ) {
                \metis_entity_id_service()->register( 'person', $person_id, (string) ( $payload['person_uid'] ?? $payload['pid'] ?? '' ) );
            }
        }

        $role_keys = [];
        if ( \metis_current_user_can( 'manage_options' ) ) {
            $role_keys[] = 'administrator';
        }
        foreach ( (array) $current_user->roles as $role ) {
            $rk = \metis_key_clean( (string) $role );
            if ( $rk !== '' ) {
                $role_keys[] = $rk;
            }
        }
        $role_keys = array_values( array_unique( $role_keys ) );

        foreach ( $role_keys as $rk ) {
            $rid = (int) $db->scalar( "SELECT id FROM {$roles_table} WHERE role_key = %s AND role_domain = 'metis' LIMIT 1", [ $rk ] );
            if ( $rid < 1 ) {
                continue;
            }

            $exists = (int) $db->scalar( "SELECT id FROM {$user_roles_table} WHERE person_id = %d AND role_id = %d LIMIT 1", [ $person_id, $rid ] );
            if ( $exists > 0 ) {
                continue;
            }

            $db->insert( $user_roles_table, [ 'person_id' => $person_id, 'role_id' => $rid ], [ '%d', '%d' ] );
            CacheService::forget( 'permissions.user_' . $person_id );
        }

        $person = $db->fetchOne( "SELECT * FROM {$people_table} WHERE id = %d LIMIT 1", [ $person_id ] );

        if ( $created && is_array( $person ) && \Metis\Core\Application::has_service( 'events' ) ) {
            \Metis\Core\Application::service( 'events' )->publish(
                'user.created',
                [
                    'person_id' => $person_id,
                    'pid'       => (string) ( $person['pid'] ?? '' ),
                    'email'     => (string) ( $person['email'] ?? $email ),
                    'display_name' => (string) ( $person['display_name'] ?? '' ),
                    'auth_provider' => (string) ( $person['auth_provider'] ?? 'metis' ),
                    'roles'     => $role_keys,
                ]
            );
        }

        return $person;
    }

    public static function can( string $domain, string $action = 'view' ): bool {
        $domain = \metis_key_clean( $domain );
        $action = \metis_key_clean( $action );
        if ( $domain === '' || $action === '' ) {
            return false;
        }

        if ( ! \metis_user_logged_in() ) {
            return false;
        }
        if ( \metis_current_user_can( 'manage_options' ) ) {
            return true;
        }

        self::seedPermissionsAndRoles();
        $db = self::db();

        $person = null;
        if ( function_exists( 'metis_auth_current_person_id' ) ) {
            $person_id = (int) \metis_auth_current_person_id();
            if ( $person_id > 0 ) {
                $people_table = \Metis_Tables::get( 'people' );
                $person = $db->fetchOne( "SELECT * FROM {$people_table} WHERE id = %d LIMIT 1", [ $person_id ] );
            }
        }
        if ( ! $person ) {
            $person = self::getOrCreateCurrentPerson();
        }

        $perms_table = \Metis_Tables::get( 'people_permissions' );
        $user_roles_table = \Metis_Tables::get( 'people_user_roles' );
        $role_perms_table = \Metis_Tables::get( 'people_role_perms' );

        if ( $person && ! empty( $person['id'] ) ) {
            $permission_key = $domain . '.' . $action;
            $matrix = self::permissionMatrixForPerson( (int) $person['id'] );
            if ( in_array( $permission_key, (array) ( $matrix['permissions'] ?? [] ), true ) ) {
                return true;
            }
        }

        $modules       = function_exists( 'metis_get_modules' ) ? \metis_get_modules() : [];
        $module_cfg    = $modules[ $domain ]['config'] ?? [];
        $allowed_roles = ModuleLoader::rolesForPermission( $module_cfg, $action );
        if ( empty( $allowed_roles ) ) {
            return false;
        }

        $user = \metis_runtime_current_user();
        foreach ( (array) $user->roles as $role ) {
            if ( in_array( (string) $role, $allowed_roles, true ) ) {
                return true;
            }
        }

        return false;
    }

    public static function getCurrentPersonId(): int {
        if ( ! \metis_user_logged_in() ) {
            return 0;
        }

        if ( function_exists( 'metis_auth_current_person_id' ) ) {
            $person_id = (int) \metis_auth_current_person_id();
            if ( $person_id > 0 ) {
                return $person_id;
            }
        }

        $person = self::getOrCreateCurrentPerson();
        return (int) ( $person['id'] ?? 0 );
    }

    private static function db(): \Metis\Services\DatabaseService {
        return \metis_db();
    }

    private static function setupSignatureFiles(): array {
        $files = [
            __FILE__,
            __DIR__ . '/SchemaManager.php',
        ];

        foreach ( \Metis\Core\ModulePathRegistry::allRootPaths() as $rootPath ) {
            $manifests = glob( rtrim( $rootPath, '/\\' ) . '/*/config/module.php' );
            if ( is_array( $manifests ) ) {
                $files = array_merge( $files, $manifests );
            }
        }

        return $files;
    }

    private static function normalizePermissionKey( string $permission_key ): string {
        $permission_key = strtolower( trim( $permission_key ) );
        if ( $permission_key === '' ) {
            return '';
        }

        $permission_key = preg_replace( '/\s+/', '', $permission_key );
        $permission_key = preg_replace( '/[^a-z0-9._-]+/', '', $permission_key );
        $permission_key = preg_replace( '/\.{2,}/', '.', $permission_key );

        return trim( (string) $permission_key, '.' );
    }
}
