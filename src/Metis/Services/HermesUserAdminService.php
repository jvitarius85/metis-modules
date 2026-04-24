<?php
declare(strict_types=1);

namespace Metis\Services;

use Metis\Modules\People\AccessManager;
use Metis\Modules\People\SchemaManager;

final class HermesUserAdminService {
    public function __construct(
        private readonly HermesDirectoryService $directory,
        private readonly ?DatabaseService $db = null
    ) {}

    public function createUser( mixed $request = null ): array {
        $request = is_array( $request ) ? $request : [];
        $email = strtolower( trim( (string) ( $request['email'] ?? '' ) ) );
        $firstName = trim( (string) ( $request['first_name'] ?? '' ) );
        $lastName = trim( (string) ( $request['last_name'] ?? '' ) );
        $displayName = trim( (string) ( $request['display_name'] ?? '' ) );
        $roles = $this->normalizeKeys( (array) ( $request['roles'] ?? [] ) );
        $workspaceGroups = $this->normalizeEmails( (array) ( $request['workspace_groups'] ?? [] ) );
        $workspaceEnabled = ! empty( $request['workspace_enabled'] ) || $workspaceGroups !== [];
        $workspaceEmail = strtolower( trim( (string) ( $request['workspace_email'] ?? $email ) ) );
        $password = (string) ( $request['password'] ?? '' );

        if ( ! \metis_email_is_valid( $email ) ) {
            throw new \RuntimeException( 'A valid email is required to create a user.' );
        }

        if ( $displayName === '' ) {
            $displayName = trim( $firstName . ' ' . $lastName );
        }
        if ( $displayName === '' ) {
            $displayName = $email;
        }

        SchemaManager::ensureSchema();
        AccessManager::seedPermissionsAndRoles();
        $peopleTable = \Metis_Tables::get( 'people' );
        $existing = $this->database()->fetchOne( "SELECT * FROM {$peopleTable} WHERE email = %s LIMIT 1", [ $email ] );
        if ( is_array( $existing ) ) {
            throw new \RuntimeException( 'A person with that email already exists.' );
        }

        $personPayload = [
            'auth_provider' => $workspaceEnabled ? 'workspace' : 'metis',
            'email' => $email,
            'first_name' => $firstName !== '' ? $firstName : null,
            'last_name' => $lastName !== '' ? $lastName : null,
            'display_name' => $displayName,
            'is_workspace_user' => $workspaceEnabled ? 1 : 0,
            'workspace_email' => $workspaceEnabled && \metis_email_is_valid( $workspaceEmail ) ? $workspaceEmail : null,
            'status' => 'active',
            'lifecycle_status' => 'active',
        ];
        if ( function_exists( 'metis_entity_id_service' ) ) {
            $personPayload = \metis_entity_id_service()->assignForInsert( 'person', $personPayload );
        } else {
            $personPayload['pid'] = function_exists( 'metis_generate_code' )
                ? \metis_generate_code( 'PPL', $peopleTable, 'pid' )
                : 'PPL' . strtoupper( substr( bin2hex( random_bytes( 6 ) ), 0, 10 ) );
        }
        $pid = (string) ( $personPayload['person_uid'] ?? $personPayload['pid'] ?? '' );

        $inserted = $this->database()->insert( $peopleTable, $personPayload );

        if ( ! $inserted ) {
            throw new \RuntimeException( 'Failed to create the person record.' );
        }

        $personId = $this->database()->lastInsertId();
        if ( $personId > 0 && function_exists( 'metis_entity_id_service' ) ) {
            \metis_entity_id_service()->register( 'person', $personId, $pid );
        }
        $assignedRoles = $this->replaceUserRoles( $personId, $roles );
        $workspace = $workspaceEnabled
            ? $this->createOrUpdateWorkspaceUser( $personId, [
                'primary_email' => \metis_email_is_valid( $workspaceEmail ) ? $workspaceEmail : $email,
                'first_name' => $firstName,
                'last_name' => $lastName,
                'display_name' => $displayName,
                'password' => $password,
                'groups' => $workspaceGroups,
            ] )
            : [];

        return [
            'status' => 'success',
            'user' => [
                'pid' => $pid,
                'name' => $displayName,
                'email' => $email,
                'roles' => $assignedRoles,
            ],
            'workspace' => $workspace,
            'message' => sprintf( 'Created user %s.', $displayName ),
        ];
    }

    public function offboardUser( mixed $request = null ): array {
        $request = is_array( $request ) ? $request : [];
        $person = $this->requirePerson( (string) ( $request['subject'] ?? '' ) );
        $peopleTable = \Metis_Tables::get( 'people' );
        $userRolesTable = \Metis_Tables::get( 'people_user_roles' );
        $workspaceUsersTable = \Metis_Tables::get( 'people_workspace_users' );
        $personId = (int) ( $person['id'] ?? 0 );

        $protectedWorkspaceUserId = (int) $this->database()->scalar(
            "SELECT id
                 FROM {$workspaceUsersTable}
                 WHERE person_id = %d
                   AND is_protected = 1
                 LIMIT 1",
            [ $personId ]
        );
        if ( $protectedWorkspaceUserId > 0 ) {
            throw new \RuntimeException( 'This person has a protected Workspace account and cannot be offboarded.' );
        }

        $workspaceEmail = strtolower( trim( (string) ( $person['workspace_email'] ?? '' ) ) );
        $this->database()->update(
            $peopleTable,
            [
                'status' => 'inactive',
                'lifecycle_status' => 'alumni',
                'is_workspace_user' => 0,
                'workspace_email' => null,
                'workspace_role' => null,
                'stripe_role' => null,
                'offboarded_at' => \metis_current_time( 'mysql' ),
            ],
            [ 'id' => $personId ]
        );
        $this->database()->delete( $userRolesTable, [ 'person_id' => $personId ], [ '%d' ] );

        if ( \metis_email_is_valid( $workspaceEmail ) ) {
            $workspaceUserId = (int) $this->database()->scalar(
                "SELECT id FROM {$workspaceUsersTable} WHERE person_id = %d OR primary_email = %s LIMIT 1",
                [ $personId, $workspaceEmail ]
            );
            if ( $workspaceUserId > 0 ) {
                $this->database()->update(
                    $workspaceUsersTable,
                    [ 'is_suspended' => 1, 'sync_status' => 'queued' ],
                    [ 'id' => $workspaceUserId ],
                    [ '%d', '%s' ],
                    [ '%d' ]
                );
                if ( function_exists( 'metis_people_workspace_queue_job' ) ) {
                    \metis_people_workspace_queue_job(
                        'workspace_security_action',
                        'workspace_user',
                        $workspaceUserId,
                        $this->currentPersonId(),
                        [ 'action_type' => 'suspend_account', 'reason' => 'person_offboarded' ]
                    );
                }
            }
        }

        return [
            'status' => 'success',
            'user' => [
                'pid' => (string) ( $person['pid'] ?? '' ),
                'name' => $this->personName( $person ),
                'workspace_email' => $workspaceEmail,
            ],
            'message' => sprintf( 'Offboarded %s.', $this->personName( $person ) ),
        ];
    }

    public function manageUserRoles( mixed $request = null ): array {
        $request = is_array( $request ) ? $request : [];
        $person = $this->requirePerson( (string) ( $request['subject'] ?? '' ) );
        $roles = $this->normalizeKeys( (array) ( $request['roles'] ?? [] ) );
        $mode = \metis_key_clean( (string) ( $request['mode'] ?? 'replace' ) );
        $personId = (int) ( $person['id'] ?? 0 );

        if ( $roles === [] ) {
            throw new \RuntimeException( 'At least one role is required.' );
        }

        $assigned = match ( $mode ) {
            'add' => $this->mergeUserRoles( $personId, $roles, true ),
            'remove' => $this->mergeUserRoles( $personId, $roles, false ),
            default => $this->replaceUserRoles( $personId, $roles ),
        };

        return [
            'status' => 'success',
            'user' => [
                'pid' => (string) ( $person['pid'] ?? '' ),
                'name' => $this->personName( $person ),
                'roles' => $assigned,
            ],
            'message' => sprintf( 'Updated roles for %s.', $this->personName( $person ) ),
        ];
    }

    public function manageWorkspaceGroups( mixed $request = null ): array {
        $request = is_array( $request ) ? $request : [];
        $person = $this->requirePerson( (string) ( $request['subject'] ?? '' ) );
        $groupEmails = $this->normalizeEmails( (array) ( $request['group_emails'] ?? [] ) );
        $mode = \metis_key_clean( (string) ( $request['mode'] ?? 'add' ) );
        $workspaceEmail = strtolower( trim( (string) ( $person['workspace_email'] ?? $person['email'] ?? '' ) ) );

        if ( ! \metis_email_is_valid( $workspaceEmail ) ) {
            throw new \RuntimeException( 'This person does not have a Workspace email.' );
        }
        if ( $groupEmails === [] ) {
            throw new \RuntimeException( 'At least one Workspace group email is required.' );
        }
        if ( ! function_exists( 'metis_people_workspace_sync_settings' ) || ! function_exists( 'metis_people_workspace_set_group_membership' ) ) {
            throw new \RuntimeException( 'Workspace group management is not available.' );
        }

        $cfg = \metis_people_workspace_sync_settings();
        if ( empty( $cfg['ok'] ) ) {
            throw new \RuntimeException( 'Workspace integration is not configured.' );
        }

        $workspaceUserId = $this->workspaceUserIdForPerson( (int) ( $person['id'] ?? 0 ), $workspaceEmail );
        $applied = [];

        foreach ( $groupEmails as $groupEmail ) {
            $include = $mode !== 'remove';
            $result = \metis_people_workspace_set_group_membership( $groupEmail, $workspaceEmail, $include, $cfg );
            if ( empty( $result['ok'] ) ) {
                throw new \RuntimeException( 'Workspace group update failed.' );
            }

            $this->syncLocalWorkspaceGroupMembership( $workspaceUserId, $groupEmail, $include );
            $applied[] = $groupEmail;
        }

        return [
            'status' => 'success',
            'user' => [
                'pid' => (string) ( $person['pid'] ?? '' ),
                'name' => $this->personName( $person ),
                'workspace_email' => $workspaceEmail,
            ],
            'groups' => $applied,
            'message' => sprintf( 'Updated Workspace groups for %s.', $this->personName( $person ) ),
        ];
    }

    public function resetWorkspacePassword( mixed $request = null ): array {
        $request = is_array( $request ) ? $request : [];
        $person = $this->requirePerson( (string) ( $request['subject'] ?? '' ) );
        $workspaceEmail = strtolower( trim( (string) ( $person['workspace_email'] ?? $person['email'] ?? '' ) ) );
        $newPassword = (string) ( $request['new_password'] ?? '' );

        if ( ! \metis_email_is_valid( $workspaceEmail ) ) {
            throw new \RuntimeException( 'This person does not have a linked Workspace account.' );
        }
        if ( ! function_exists( 'metis_people_workspace_sync_settings' ) || ! function_exists( 'metis_people_workspace_google_request' ) ) {
            throw new \RuntimeException( 'Workspace password management is not available.' );
        }

        if ( $newPassword === '' ) {
            $newPassword = function_exists( 'metis_people_workspace_random_password' )
                ? \metis_people_workspace_random_password( 20 )
                : substr( bin2hex( random_bytes( 12 ) ), 0, 20 );
        }

        $cfg = \metis_people_workspace_sync_settings();
        if ( empty( $cfg['ok'] ) ) {
            throw new \RuntimeException( 'Workspace integration is not configured.' );
        }

        $resp = \metis_people_workspace_google_request(
            'PUT',
            'users/' . rawurlencode( $workspaceEmail ),
            [
                'password' => $newPassword,
                'changePasswordAtNextLogin' => false,
            ],
            $cfg
        );

        if ( empty( $resp['ok'] ) ) {
            throw new \RuntimeException( 'Workspace password update failed.' );
        }

        return [
            'status' => 'success',
            'user' => [
                'pid' => (string) ( $person['pid'] ?? '' ),
                'name' => $this->personName( $person ),
                'workspace_email' => $workspaceEmail,
            ],
            'credential_package' => [
                'password' => $newPassword,
                'delivery_note' => 'Provide this password securely to the user.',
            ],
            'message' => sprintf( 'Updated the Workspace password for %s.', $this->personName( $person ) ),
        ];
    }

    public function resetMetisPassword( mixed $request = null ): array {
        $request = is_array( $request ) ? $request : [];
        $person = $this->requirePerson( (string) ( $request['subject'] ?? '' ) );
        $personId = (int) ( $person['id'] ?? 0 );
        $newPassword = (string) ( $request['new_password'] ?? '' );

        if ( $personId < 1 ) {
            throw new \RuntimeException( 'No matching person record was found.' );
        }

        if (
            ! function_exists( 'metis_auth_find_user' )
            || ! function_exists( 'metis_auth_set_initial_password_for_person' )
            || ! function_exists( 'metis_auth_admin_reset_password' )
        ) {
            throw new \RuntimeException( 'Metis password management is not available.' );
        }

        if ( $newPassword === '' ) {
            $newPassword = function_exists( 'metis_people_workspace_random_password' )
                ? \metis_people_workspace_random_password( 20 )
                : substr( bin2hex( random_bytes( 12 ) ), 0, 20 );
        }

        $adminPersonId = $this->currentPersonId() ?? 1;
        $authUser = \metis_auth_find_user( 'person_id', $personId );
        if ( is_array( $authUser ) && ! empty( $authUser['id'] ) ) {
            \metis_auth_admin_reset_password( max( 1, $adminPersonId ), $personId, $newPassword );
        } else {
            \metis_auth_set_initial_password_for_person( $personId, $newPassword, $newPassword );
        }

        $authUser = \metis_auth_find_user( 'person_id', $personId );

        return [
            'status' => 'success',
            'user' => [
                'pid' => (string) ( $person['pid'] ?? '' ),
                'name' => $this->personName( $person ),
                'email' => (string) ( $authUser['user_email'] ?? $person['email'] ?? '' ),
            ],
            'credential_package' => [
                'password' => $newPassword,
                'delivery_note' => 'Provide this password securely to the user.',
            ],
            'message' => sprintf( 'Updated the Metis password for %s.', $this->personName( $person ) ),
        ];
    }

    public function resetUserMfa( mixed $request = null ): array {
        $request = is_array( $request ) ? $request : [];
        $person = $this->requirePerson( (string) ( $request['subject'] ?? '' ) );
        $personId = (int) ( $person['id'] ?? 0 );

        if ( $personId < 1 ) {
            throw new \RuntimeException( 'No matching person record was found.' );
        }

        $peopleTable = \Metis_Tables::get( 'people' );
        $passkeysTable = \Metis_Tables::get( 'people_passkeys' );

        $revokedPasskeys = 0;
        if ( class_exists( 'Metis_Tables' ) && \Metis_Tables::has( 'people_passkeys' ) ) {
            $activePasskeys = $this->database()->fetchAll(
                "SELECT id
                 FROM {$passkeysTable}
                 WHERE person_id = %d
                   AND revoked_at IS NULL",
                [ $personId ]
            ) ?: [];

            foreach ( $activePasskeys as $passkey ) {
                $updated = $this->database()->update(
                    $passkeysTable,
                    [ 'revoked_at' => \metis_current_time( 'mysql' ) ],
                    [ 'id' => (int) ( $passkey['id'] ?? 0 ) ],
                    [ '%s' ],
                    [ '%d' ]
                );
                if ( $updated !== false ) {
                    $revokedPasskeys++;
                }
            }
        }

        $updated = $this->database()->update(
            $peopleTable,
            [
                'requires_2fa' => 0,
                'mfa_method' => 'none',
                'totp_enabled' => 0,
                'passkey_enabled' => 0,
                'totp_secret_enc' => null,
                'updated_at' => \metis_current_time( 'mysql' ),
            ],
            [ 'id' => $personId ],
            [ '%d', '%s', '%d', '%d', '%s', '%s' ],
            [ '%d' ]
        );

        if ( $updated === false ) {
            throw new \RuntimeException( 'Failed to reset MFA.' );
        }

        if ( function_exists( 'metis_people_log_activity' ) ) {
            \metis_people_log_activity( $personId, 'mfa_reset', 'Reset MFA configuration', [ 'revoked_passkeys' => $revokedPasskeys ] );
        }

        return [
            'status' => 'success',
            'user' => [
                'pid' => (string) ( $person['pid'] ?? '' ),
                'name' => $this->personName( $person ),
                'email' => (string) ( $person['email'] ?? '' ),
            ],
            'revoked_passkeys' => $revokedPasskeys,
            'message' => sprintf( 'Reset MFA for %s.', $this->personName( $person ) ),
        ];
    }

    public function clarifyPasswordReset( mixed $request = null ): array {
        $request = is_array( $request ) ? $request : [];
        $subject = trim( (string) ( $request['subject'] ?? '' ) );
        $label = $subject !== '' ? $subject : 'this user';

        return [
            'status' => 'success',
            'message' => sprintf( 'Do you want to reset %s\'s Metis password or Workspace password?', $label ),
            'options' => [ 'metis_password', 'workspace_password' ],
            'next_step' => 'Reply with "reset Metis password for ... " or "reset Workspace password for ...".',
        ];
    }

    public function linkDriveFolder( mixed $request = null ): array {
        $request = is_array( $request ) ? $request : [];
        $person = $this->requirePerson( (string) ( $request['subject'] ?? '' ) );
        $folderId = trim( (string) ( $request['folder_id'] ?? '' ) );

        if (
            ! function_exists( 'metis_drive_workspace_settings' )
            || ! function_exists( 'metis_drive_find_or_create_user_folder' )
            || ! function_exists( 'metis_drive_upsert_user_folder_mapping' )
            || ! function_exists( 'metis_drive_get_users_root_folder' )
            || ! function_exists( 'metis_drive_get_file_meta' )
            || ! function_exists( 'metis_drive_folder_is_descendant_of' )
        ) {
            throw new \RuntimeException( 'Drive linking is not available.' );
        }

        $cfg = \metis_drive_workspace_settings();
        if ( empty( $cfg['ok'] ) ) {
            throw new \RuntimeException( 'Drive integration is not configured.' );
        }

        if ( $folderId === '' ) {
            $folder = \metis_drive_find_or_create_user_folder( $cfg, (int) ( $person['id'] ?? 0 ), true );
            if ( empty( $folder['ok'] ) || empty( $folder['folder_id'] ) ) {
                throw new \RuntimeException( 'Failed to link a Drive folder.' );
            }

            return [
                'status' => 'success',
                'folder' => [
                    'folder_id' => (string) ( $folder['folder_id'] ?? '' ),
                    'folder_name' => (string) ( $folder['folder_name'] ?? '' ),
                    'created' => ! empty( $folder['created'] ),
                ],
                'user' => [
                    'pid' => (string) ( $person['pid'] ?? '' ),
                    'name' => $this->personName( $person ),
                ],
                'message' => sprintf( 'Linked a Drive folder for %s.', $this->personName( $person ) ),
            ];
        }

        $usersRoot = \metis_drive_get_users_root_folder( $cfg, false );
        $usersRootId = (string) ( $usersRoot['folder_id'] ?? '' );
        if ( $usersRootId === '' ) {
            throw new \RuntimeException( 'Users folder could not be resolved.' );
        }

        $meta = \metis_drive_get_file_meta( $cfg, $folderId, 'id,name,mimeType,parents,driveId,webViewLink' );
        if ( empty( $meta['ok'] ) ) {
            throw new \RuntimeException( 'Invalid folder.' );
        }
        $body = (array) ( $meta['body'] ?? [] );
        if ( (string) ( $body['driveId'] ?? '' ) !== (string) ( $cfg['shared_drive_id'] ?? '' ) ) {
            throw new \RuntimeException( 'That folder is not in the configured Shared Drive.' );
        }
        if ( (string) ( $body['mimeType'] ?? '' ) !== 'application/vnd.google-apps.folder' ) {
            throw new \RuntimeException( 'Selected item is not a folder.' );
        }
        if ( $folderId === $usersRootId || ! \metis_drive_folder_is_descendant_of( $cfg, $folderId, $usersRootId ) ) {
            throw new \RuntimeException( 'Selected folder must be inside the Users container.' );
        }

        $folderName = (string) ( $body['name'] ?? $folderId );
        $parentId = (string) ( ( $body['parents'][0] ?? '' ) ?: $usersRootId );
        \metis_drive_upsert_user_folder_mapping( $cfg, (int) ( $person['id'] ?? 0 ), $folderId, $folderName, $parentId );

        return [
            'status' => 'success',
            'folder' => [
                'folder_id' => $folderId,
                'folder_name' => $folderName,
                'created' => false,
            ],
            'user' => [
                'pid' => (string) ( $person['pid'] ?? '' ),
                'name' => $this->personName( $person ),
            ],
            'message' => sprintf( 'Linked the selected Drive folder for %s.', $this->personName( $person ) ),
        ];
    }

    private function requirePerson( string $subject ): array {
        $person = $this->directory->resolvePersonReference( $subject );
        if ( ! is_array( $person ) ) {
            throw new \RuntimeException( 'No matching person record was found.' );
        }

        return $person;
    }

    private function replaceUserRoles( int $personId, array $roles ): array {
        $userRolesTable = \Metis_Tables::get( 'people_user_roles' );
        $this->database()->delete( $userRolesTable, [ 'person_id' => $personId ], [ '%d' ] );

        return $this->mergeUserRoles( $personId, $roles, true, true );
    }

    private function mergeUserRoles( int $personId, array $roles, bool $include, bool $skipExisting = false ): array {
        $rolesTable = \Metis_Tables::get( 'people_roles' );
        $userRolesTable = \Metis_Tables::get( 'people_user_roles' );

        $existingRoleIds = $this->database()->column( "SELECT role_id FROM {$userRolesTable} WHERE person_id = %d", [ $personId ] ) ?: [];
        $existingRoleIds = array_map( 'intval', $existingRoleIds );

        foreach ( $roles as $roleKey ) {
            $roleId = (int) $this->database()->scalar(
                "SELECT id FROM {$rolesTable} WHERE role_key = %s AND role_domain = 'metis' LIMIT 1",
                [ $roleKey ]
            );
            if ( $roleId < 1 ) {
                continue;
            }

            if ( $include ) {
                if ( $skipExisting || ! in_array( $roleId, $existingRoleIds, true ) ) {
                    $this->database()->insert( $userRolesTable, [ 'person_id' => $personId, 'role_id' => $roleId ], [ '%d', '%d' ] );
                }
            } else {
                $this->database()->delete( $userRolesTable, [ 'person_id' => $personId, 'role_id' => $roleId ], [ '%d', '%d' ] );
            }
        }

        $rows = $this->database()->column(
            "SELECT r.role_key
                 FROM {$userRolesTable} ur
                 INNER JOIN {$rolesTable} r ON r.id = ur.role_id
                 WHERE ur.person_id = %d
                 ORDER BY r.role_key ASC",
            [ $personId ]
        ) ?: [];

        return array_values( array_map( 'strval', $rows ) );
    }

    private function createOrUpdateWorkspaceUser( int $personId, array $request ): array {
        if ( ! function_exists( 'metis_people_workspace_sync_settings' ) || ! function_exists( 'metis_people_workspace_google_request' ) ) {
            return [ 'status' => 'skipped', 'message' => 'Workspace integration is not available.' ];
        }

        $cfg = \metis_people_workspace_sync_settings();
        if ( empty( $cfg['ok'] ) ) {
            return [ 'status' => 'skipped', 'message' => 'Workspace integration is not configured.' ];
        }

        $usersTable = \Metis_Tables::get( 'people_workspace_users' );
        $primaryEmail = strtolower( trim( (string) ( $request['primary_email'] ?? '' ) ) );
        $firstName = trim( (string) ( $request['first_name'] ?? '' ) );
        $lastName = trim( (string) ( $request['last_name'] ?? '' ) );
        $displayName = trim( (string) ( $request['display_name'] ?? '' ) );
        $password = (string) ( $request['password'] ?? '' );
        if ( $displayName === '' ) {
            $displayName = trim( $firstName . ' ' . $lastName );
        }
        if ( $displayName === '' ) {
            $displayName = $primaryEmail;
        }
        if ( ! \metis_email_is_valid( $primaryEmail ) ) {
            return [ 'status' => 'skipped', 'message' => 'Workspace email is invalid.' ];
        }
        if ( $password === '' ) {
            $password = function_exists( 'metis_people_workspace_random_password' )
                ? \metis_people_workspace_random_password( 20 )
                : substr( bin2hex( random_bytes( 12 ) ), 0, 20 );
        }

        $body = [
            'primaryEmail' => $primaryEmail,
            'name' => [
                'givenName' => $firstName !== '' ? $firstName : $displayName,
                'familyName' => $lastName !== '' ? $lastName : 'User',
            ],
            'password' => $password,
            'changePasswordAtNextLogin' => true,
        ];

        $create = \metis_people_workspace_google_request( 'POST', 'users', $body, $cfg );
        if ( empty( $create['ok'] ) ) {
            $lookup = \metis_people_workspace_google_request( 'GET', 'users/' . rawurlencode( $primaryEmail ) . '?projection=full', null, $cfg );
            if ( empty( $lookup['ok'] ) ) {
                return [ 'status' => 'error', 'message' => 'Workspace user creation failed.' ];
            }
        }

        $googleId = (string) ( $create['body']['id'] ?? $lookup['body']['id'] ?? '' );
        $existingId = (int) $this->database()->scalar(
            "SELECT id FROM {$usersTable} WHERE person_id = %d OR primary_email = %s LIMIT 1",
            [ $personId, $primaryEmail ]
        );
        if ( $existingId > 0 ) {
            $this->database()->update(
                $usersTable,
                [
                    'person_id' => $personId,
                    'workspace_user_id' => $googleId !== '' ? $googleId : null,
                    'primary_email' => $primaryEmail,
                    'display_name' => $displayName,
                    'first_name' => $firstName !== '' ? $firstName : null,
                    'last_name' => $lastName !== '' ? $lastName : null,
                    'sync_status' => 'synced',
                ],
                [ 'id' => $existingId ],
                [ '%d', '%s', '%s', '%s', '%s', '%s', '%s' ],
                [ '%d' ]
            );
            $workspaceUserId = $existingId;
        } else {
            $this->database()->insert(
                $usersTable,
                [
                    'person_id' => $personId,
                    'workspace_user_id' => $googleId !== '' ? $googleId : null,
                    'primary_email' => $primaryEmail,
                    'display_name' => $displayName,
                    'first_name' => $firstName !== '' ? $firstName : null,
                    'last_name' => $lastName !== '' ? $lastName : null,
                    'sync_status' => 'synced',
                ],
                [ '%d', '%s', '%s', '%s', '%s', '%s', '%s' ]
            );
            $workspaceUserId = $this->database()->lastInsertId();
        }

        if ( ! empty( $request['groups'] ) ) {
            $this->manageWorkspaceGroups( [
                'subject' => $primaryEmail,
                'group_emails' => (array) $request['groups'],
                'mode' => 'add',
            ] );
        }

        return [
            'status' => 'success',
            'workspace_user_id' => $workspaceUserId,
            'primary_email' => $primaryEmail,
            'password' => $password,
            'message' => sprintf( 'Workspace user ready for %s.', $primaryEmail ),
        ];
    }

    private function workspaceUserIdForPerson( int $personId, string $workspaceEmail ): int {
        $usersTable = \Metis_Tables::get( 'people_workspace_users' );

        return (int) $this->database()->scalar(
            "SELECT id FROM {$usersTable} WHERE person_id = %d OR primary_email = %s LIMIT 1",
            [ $personId, $workspaceEmail ]
        );
    }

    private function syncLocalWorkspaceGroupMembership( int $workspaceUserId, string $groupEmail, bool $include ): void {
        if ( $workspaceUserId < 1 ) {
            return;
        }

        $groupsTable = \Metis_Tables::get( 'people_workspace_groups' );
        $membersTable = \Metis_Tables::get( 'people_workspace_group_members' );

        $groupId = (int) $this->database()->scalar( "SELECT id FROM {$groupsTable} WHERE group_email = %s LIMIT 1", [ $groupEmail ] );
        if ( $groupId < 1 && $include ) {
            $this->database()->insert(
                $groupsTable,
                [
                    'group_email' => $groupEmail,
                    'group_name' => $groupEmail,
                    'sync_status' => 'synced',
                ],
                [ '%s', '%s', '%s' ]
            );
            $groupId = $this->database()->lastInsertId();
        }
        if ( $groupId < 1 ) {
            return;
        }

        if ( $include ) {
            $this->database()->replace(
                $membersTable,
                [
                    'group_id' => $groupId,
                    'workspace_user_id' => $workspaceUserId,
                    'member_role' => 'member',
                ],
                [ '%d', '%d', '%s' ]
            );
            return;
        }

        $this->database()->delete(
            $membersTable,
            [ 'group_id' => $groupId, 'workspace_user_id' => $workspaceUserId ],
            [ '%d', '%d' ]
        );
    }

    private function database(): DatabaseService {
        if ( $this->db instanceof DatabaseService ) {
            return $this->db;
        }
        return function_exists( 'metis_resolve_db_service' ) ? \metis_resolve_db_service() : new DatabaseService();
    }

    private function normalizeKeys( array $values ): array {
        $normalized = [];
        foreach ( $values as $value ) {
            $key = \metis_key_clean( (string) $value );
            if ( $key !== '' ) {
                $normalized[] = $key;
            }
        }

        return array_values( array_unique( $normalized ) );
    }

    private function normalizeEmails( array $values ): array {
        $normalized = [];
        foreach ( $values as $value ) {
            $email = strtolower( trim( (string) $value ) );
            if ( \metis_email_is_valid( $email ) ) {
                $normalized[] = $email;
            }
        }

        return array_values( array_unique( $normalized ) );
    }

    private function personName( array $person ): string {
        $full = trim( (string) ( $person['first_name'] ?? '' ) . ' ' . (string) ( $person['last_name'] ?? '' ) );
        if ( $full !== '' ) {
            return $full;
        }

        return (string) ( $person['display_name'] ?? $person['email'] ?? 'user' );
    }

    private function currentPersonId(): ?int {
        $personId = function_exists( 'metis_people_get_current_person_id' ) ? (int) \metis_people_get_current_person_id() : 0;
        return $personId > 0 ? $personId : null;
    }
}
