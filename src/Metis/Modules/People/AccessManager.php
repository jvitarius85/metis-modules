<?php
declare(strict_types=1);

namespace Metis\Modules\People;

final class AccessManager {
    private static bool $seeded = false;

    public static function seedPermissionsAndRoles(): void {
        if ( self::$seeded ) {
            return;
        }

        SchemaManager::ensureSchema();
        global $wpdb;

        $roles_table = \Metis_Tables::get( 'people_roles' );
        $perms_table = \Metis_Tables::get( 'people_permissions' );
        $role_perms_table = \Metis_Tables::get( 'people_role_perms' );

        $default_roles = [
            [ 'role_key' => 'administrator', 'role_domain' => 'metis', 'name' => 'Administrator', 'description' => 'Full Metis access', 'is_system' => 1 ],
            [ 'role_key' => 'donor_admin', 'role_domain' => 'metis', 'name' => 'Donor Admin', 'description' => 'Donations + contacts operations', 'is_system' => 1 ],
            [ 'role_key' => 'donor_user', 'role_domain' => 'metis', 'name' => 'Donor User', 'description' => 'Read-only donor/contact access', 'is_system' => 1 ],
            [ 'role_key' => 'newsletter_admin', 'role_domain' => 'metis', 'name' => 'Newsletter Admin', 'description' => 'Newsletter operations access', 'is_system' => 1 ],
            [ 'role_key' => 'board', 'role_domain' => 'metis', 'name' => 'Board', 'description' => 'Board-level read access', 'is_system' => 1 ],
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

            $existing = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$roles_table} WHERE role_key = %s AND role_domain = %s LIMIT 1", $role_key, $role_domain ) );
            if ( $existing ) {
                continue;
            }

            $wpdb->insert(
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
        }

        $modules = function_exists( 'metis_get_modules' ) ? \metis_get_modules() : [];
        $default_actions = [ 'view', 'edit', 'create', 'delete' ];

        foreach ( $modules as $slug => $module ) {
            $slug = \sanitize_key( (string) $slug );
            if ( $slug === '' ) {
                continue;
            }

            foreach ( $default_actions as $action ) {
                $perm_key = $slug . '.' . $action;
                $existing = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$perms_table} WHERE permission_key = %s LIMIT 1", $perm_key ) );
                if ( $existing ) {
                    continue;
                }

                $wpdb->insert(
                    $perms_table,
                    [
                        'permission_key' => $perm_key,
                        'module_slug' => $slug,
                        'action_key' => $action,
                        'permission_name' => ucfirst( $slug ) . ' ' . ucfirst( $action ),
                    ],
                    [ '%s', '%s', '%s', '%s' ]
                );
            }
        }

        $custom_permissions = [
            [ 'permission_key' => 'people.workspace_manage', 'module_slug' => 'people', 'action_key' => 'workspace_manage', 'permission_name' => 'People Workspace Manage' ],
        ];
        foreach ( $custom_permissions as $perm ) {
            $permission_key = (string) ( $perm['permission_key'] ?? '' );
            if ( $permission_key === '' ) {
                continue;
            }

            $existing = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$perms_table} WHERE permission_key = %s LIMIT 1", $permission_key ) );
            if ( $existing ) {
                continue;
            }

            $wpdb->insert(
                $perms_table,
                [
                    'permission_key' => $permission_key,
                    'module_slug' => (string) ( $perm['module_slug'] ?? 'people' ),
                    'action_key' => (string) ( $perm['action_key'] ?? 'workspace_manage' ),
                    'permission_name' => (string) ( $perm['permission_name'] ?? $permission_key ),
                ],
                [ '%s', '%s', '%s', '%s' ]
            );
        }

        $has_links = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$role_perms_table}" );
        if ( $has_links === 0 ) {
            $role_ids = [];
            $rows = $wpdb->get_results( "SELECT id, role_key FROM {$roles_table} WHERE role_domain = 'metis'" );
            foreach ( (array) $rows as $row ) {
                $role_ids[ (string) $row->role_key ] = (int) $row->id;
            }

            $perm_ids = [];
            $p_rows = $wpdb->get_results( "SELECT id, permission_key FROM {$perms_table}" );
            foreach ( (array) $p_rows as $row ) {
                $perm_ids[ (string) $row->permission_key ] = (int) $row->id;
            }

            $policy = [
                'administrator' => [ '*' ],
                'donor_admin' => [ 'portal.view', 'donations.view', 'donations.edit', 'donations.create', 'contacts.view', 'contacts.edit', 'contacts.create', 'people.view' ],
                'donor_user' => [ 'portal.view', 'donations.view', 'contacts.view' ],
                'newsletter_admin' => [ 'portal.view', 'newsletter.view', 'newsletter.edit', 'newsletter.create', 'newsletter.delete', 'contacts.view' ],
                'board' => [ 'portal.view', 'board.view', 'drive.view', 'drive.edit', 'drive.create', 'drive.delete', 'calendar.view', 'calendar.edit', 'calendar.create', 'calendar.delete', 'contacts.view', 'donations.view', 'people.view' ],
                'workspace_manager' => [ 'people.view', 'people.edit', 'people.workspace_manage' ],
            ];

            foreach ( $policy as $role_key => $allowed_perms ) {
                if ( empty( $role_ids[ $role_key ] ) ) {
                    continue;
                }

                $rid = (int) $role_ids[ $role_key ];
                $keys_to_allow = in_array( '*', $allowed_perms, true ) ? array_keys( $perm_ids ) : $allowed_perms;

                foreach ( $keys_to_allow as $perm_key ) {
                    if ( empty( $perm_ids[ $perm_key ] ) ) {
                        continue;
                    }

                    $wpdb->insert(
                        $role_perms_table,
                        [
                            'role_id' => $rid,
                            'permission_id' => (int) $perm_ids[ $perm_key ],
                            'allow_access' => 1,
                        ],
                        [ '%d', '%d', '%d' ]
                    );
                }
            }
        }

        $workspace_perm_id = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM {$perms_table} WHERE permission_key = %s LIMIT 1",
                'people.workspace_manage'
            )
        );
        if ( $workspace_perm_id > 0 ) {
            foreach ( [ 'administrator', 'workspace_manager' ] as $role_key ) {
                $role_id = (int) $wpdb->get_var(
                    $wpdb->prepare(
                        "SELECT id FROM {$roles_table} WHERE role_key = %s AND role_domain = 'metis' LIMIT 1",
                        $role_key
                    )
                );
                if ( $role_id < 1 ) {
                    continue;
                }

                $exists = (int) $wpdb->get_var(
                    $wpdb->prepare(
                        "SELECT id FROM {$role_perms_table} WHERE role_id = %d AND permission_id = %d LIMIT 1",
                        $role_id,
                        $workspace_perm_id
                    )
                );
                if ( $exists > 0 ) {
                    continue;
                }

                $wpdb->insert(
                    $role_perms_table,
                    [
                        'role_id' => $role_id,
                        'permission_id' => $workspace_perm_id,
                        'allow_access' => 1,
                    ],
                    [ '%d', '%d', '%d' ]
                );
            }
        }

        self::$seeded = true;
    }

    public static function getOrCreateCurrentPerson(): ?array {
        if ( ! \metis_user_logged_in() ) {
            return null;
        }

        SchemaManager::ensureSchema();
        self::seedPermissionsAndRoles();
        global $wpdb;

        $people_table = \Metis_Tables::get( 'people' );
        $roles_table = \Metis_Tables::get( 'people_roles' );
        $user_roles_table = \Metis_Tables::get( 'people_user_roles' );

        if ( function_exists( 'metis_auth_current_person_id' ) ) {
            $current_person_id = (int) \metis_auth_current_person_id();
            if ( $current_person_id > 0 ) {
                $current_person = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$people_table} WHERE id = %d LIMIT 1", $current_person_id ), ARRAY_A );
                if ( is_array( $current_person ) ) {
                    return $current_person;
                }
            }
        }

        $current_user = \metis_current_user();
        $email = strtolower( trim( (string) ( $current_user->user_email ?? '' ) ) );
        if ( $email === '' || ! \is_email( $email ) ) {
            return null;
        }

        $person = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$people_table} WHERE email = %s LIMIT 1", $email ), ARRAY_A );
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
                $wpdb->update( $people_table, $update, [ 'id' => (int) $person['id'] ], $formats, [ '%d' ] );
            }

            $person_id = (int) $person['id'];
            $pid = (string) ( $person['pid'] ?? '' );
            if ( $pid === '' ) {
                $pid = \metis_generate_code( 'PE', $people_table, 'pid' );
                $wpdb->update( $people_table, [ 'pid' => $pid ], [ 'id' => $person_id ], [ '%s' ], [ '%d' ] );
            }
        } else {
            $payload = [
                'auth_provider' => 'metis',
                'email' => $email,
                'first_name' => (string) ( $current_user->first_name ?? '' ),
                'last_name' => (string) ( $current_user->last_name ?? '' ),
                'display_name' => (string) ( $current_user->display_name ?? $email ),
            ];
            $payload['pid'] = \metis_generate_code( 'PPL', $people_table, 'pid' );
            $wpdb->insert( $people_table, $payload, [ '%s', '%s', '%s', '%s', '%s', '%s' ] );
            $person_id = (int) $wpdb->insert_id;
        }

        $role_keys = [];
        if ( \metis_current_user_can( 'manage_options' ) ) {
            $role_keys[] = 'administrator';
        }
        foreach ( (array) $current_user->roles as $role ) {
            $rk = \sanitize_key( (string) $role );
            if ( $rk !== '' ) {
                $role_keys[] = $rk;
            }
        }
        $role_keys = array_values( array_unique( $role_keys ) );

        foreach ( $role_keys as $rk ) {
            $rid = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$roles_table} WHERE role_key = %s AND role_domain = 'metis' LIMIT 1", $rk ) );
            if ( $rid < 1 ) {
                continue;
            }

            $exists = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$user_roles_table} WHERE person_id = %d AND role_id = %d LIMIT 1", $person_id, $rid ) );
            if ( $exists > 0 ) {
                continue;
            }

            $wpdb->insert( $user_roles_table, [ 'person_id' => $person_id, 'role_id' => $rid ], [ '%d', '%d' ] );
        }

        return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$people_table} WHERE id = %d LIMIT 1", $person_id ), ARRAY_A );
    }

    public static function can( string $domain, string $action = 'view' ): bool {
        $domain = \sanitize_key( $domain );
        $action = \sanitize_key( $action );
        if ( $domain === '' || $action === '' ) {
            return false;
        }

        if ( ! \metis_user_logged_in() ) {
            return false;
        }
        if ( \metis_current_user_can( 'manage_options' ) ) {
            return true;
        }

        SchemaManager::ensureSchema();
        self::seedPermissionsAndRoles();
        global $wpdb;

        $person = null;
        if ( function_exists( 'metis_auth_current_person_id' ) ) {
            $person_id = (int) \metis_auth_current_person_id();
            if ( $person_id > 0 ) {
                $people_table = \Metis_Tables::get( 'people' );
                $person = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$people_table} WHERE id = %d LIMIT 1", $person_id ), ARRAY_A );
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
            $allowed = (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*)
                     FROM {$user_roles_table} ur
                     INNER JOIN {$role_perms_table} rp ON rp.role_id = ur.role_id AND rp.allow_access = 1
                     INNER JOIN {$perms_table} p ON p.id = rp.permission_id
                     WHERE ur.person_id = %d
                       AND (ur.start_at IS NULL OR ur.start_at <= NOW())
                       AND (ur.end_at IS NULL OR ur.end_at >= NOW())
                       AND p.permission_key = %s",
                    (int) $person['id'],
                    $permission_key
                )
            );
            if ( $allowed > 0 ) {
                return true;
            }
        }

        $modules = function_exists( 'metis_get_modules' ) ? \metis_get_modules() : [];
        $module_cfg = $modules[ $domain ]['config'] ?? [];
        $allowed_roles = [];
        if ( isset( $module_cfg['permissions'][ $action ] ) && is_array( $module_cfg['permissions'][ $action ] ) ) {
            $allowed_roles = array_map( 'strval', $module_cfg['permissions'][ $action ] );
        }
        if ( empty( $allowed_roles ) ) {
            return false;
        }

        $user = \metis_current_user();
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
}
