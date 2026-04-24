<?php
declare(strict_types=1);

namespace Metis\Modules\People;

final class SchemaManager {
    private static bool $done = false;

    public static function tableExists( string $table ): bool {
        $db = self::db();
        $exists = $db->scalar( 'SHOW TABLES LIKE %s', [ $table ] );
        return $exists === $table;
    }

    public static function columnExists( string $table, string $column ): bool {
        $db = self::db();

        if ( ! self::tableExists( $table ) ) {
            return false;
        }

        $exists = $db->scalar( "SHOW COLUMNS FROM {$table} LIKE %s", [ $column ] );
        return ! empty( $exists );
    }

    public static function addColumnIfMissing( string $table, string $column, string $definition ): void {
        $db = self::db();

        if ( ! self::tableExists( $table ) || self::columnExists( $table, $column ) ) {
            return;
        }

        $db->execute( "ALTER TABLE {$table} ADD COLUMN {$column} {$definition}" );
    }

    public static function dropIndexIfExists( string $table, string $index_name ): void {
        $db = self::db();

        if ( ! self::tableExists( $table ) ) {
            return;
        }

        $index = $db->scalar( "SHOW INDEX FROM {$table} WHERE Key_name = %s", [ $index_name ] );
        if ( $index === null ) {
            return;
        }

        $db->execute( "ALTER TABLE {$table} DROP INDEX {$index_name}" );
    }

    public static function addIndexIfMissing( string $table, string $index_name, string $definition ): void {
        $db = self::db();

        if ( ! self::tableExists( $table ) ) {
            return;
        }

        $index = $db->scalar( "SHOW INDEX FROM {$table} WHERE Key_name = %s", [ $index_name ] );
        if ( $index !== null ) {
            return;
        }

        $db->execute( "ALTER TABLE {$table} ADD {$definition}" );
    }

    public static function ensureSchema(): void {
        if ( self::$done ) {
            return;
        }

        $db = self::db();

        $people_table = \Metis_Tables::get( 'people' );
        $roles_table = \Metis_Tables::get( 'people_roles' );
        $perms_table = \Metis_Tables::get( 'people_permissions' );
        $role_perms_table = \Metis_Tables::get( 'people_role_perms' );
        $user_roles_table = \Metis_Tables::get( 'people_user_roles' );
        $activity_table = \Metis_Tables::get( 'people_activity' );
        $requests_table = \Metis_Tables::get( 'people_access_requests' );
        $templates_table = \Metis_Tables::get( 'people_role_templates' );
        $template_roles_table = \Metis_Tables::get( 'people_template_roles' );
        $documents_table = \Metis_Tables::get( 'people_documents' );
        $emergency_access_table = \Metis_Tables::get( 'people_emergency_access' );
        $passkeys_table = \Metis_Tables::get( 'people_passkeys' );
        $challenges_table = \Metis_Tables::get( 'people_auth_challenges' );
        $lifecycle_tasks_table = \Metis_Tables::get( 'people_lifecycle_tasks' );
        $workspace_users_table = \Metis_Tables::get( 'people_workspace_users' );
        $workspace_user_roles_table = \Metis_Tables::get( 'people_workspace_user_roles' );
        $workspace_groups_table = \Metis_Tables::get( 'people_workspace_groups' );
        $workspace_group_members_table = \Metis_Tables::get( 'people_workspace_group_members' );
        $workspace_security_actions_table = \Metis_Tables::get( 'people_workspace_security_actions' );
        $workspace_sync_jobs_table = \Metis_Tables::get( 'people_workspace_sync_jobs' );
        $positions_table = \Metis_Tables::get( 'people_positions' );
        $charset_collate = $db->connection()->get_charset_collate();


        $people_sql = "CREATE TABLE {$people_table} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        pid VARCHAR(16) DEFAULT NULL,
        auth_provider VARCHAR(32) NOT NULL DEFAULT 'metis',
        email VARCHAR(191) NOT NULL,
        first_name VARCHAR(120) DEFAULT NULL,
        last_name VARCHAR(120) DEFAULT NULL,
        display_name VARCHAR(191) NOT NULL,
        linked_donor_id VARCHAR(64) DEFAULT NULL,
        is_workspace_user TINYINT(1) NOT NULL DEFAULT 0,
        workspace_email VARCHAR(191) DEFAULT NULL,
        workspace_role VARCHAR(64) DEFAULT NULL,
        stripe_role VARCHAR(64) DEFAULT NULL,
        is_staff TINYINT(1) NOT NULL DEFAULT 0,
        is_board TINYINT(1) NOT NULL DEFAULT 0,
        board_position VARCHAR(120) DEFAULT NULL,
        staff_position VARCHAR(120) DEFAULT NULL,
        is_volunteer TINYINT(1) NOT NULL DEFAULT 0,
        volunteer_position VARCHAR(120) DEFAULT NULL,
        manager_pid VARCHAR(16) DEFAULT NULL,
        department VARCHAR(120) DEFAULT NULL,
        board_term_start DATE DEFAULT NULL,
        board_term_end DATE DEFAULT NULL,
        volunteer_area VARCHAR(120) DEFAULT NULL,
        lifecycle_status VARCHAR(24) NOT NULL DEFAULT 'active',
        email_notifications TINYINT(1) NOT NULL DEFAULT 1,
        sms_notifications TINYINT(1) NOT NULL DEFAULT 0,
        notification_prefs_json LONGTEXT DEFAULT NULL,
        requires_2fa TINYINT(1) NOT NULL DEFAULT 0,
        mfa_method VARCHAR(24) NOT NULL DEFAULT 'none',
        totp_enabled TINYINT(1) NOT NULL DEFAULT 0,
        passkey_enabled TINYINT(1) NOT NULL DEFAULT 0,
        totp_secret_enc TEXT DEFAULT NULL,
        password_hash VARCHAR(255) DEFAULT NULL,
        avatar_url VARCHAR(255) DEFAULT NULL,
        last_active_at DATETIME DEFAULT NULL,
        offboarded_at DATETIME DEFAULT NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'active',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY pid (pid),
        UNIQUE KEY email (email),
        KEY linked_donor_id (linked_donor_id),
        KEY board_position (board_position),
        KEY staff_position (staff_position),
        KEY volunteer_position (volunteer_position),
        KEY workspace_email (workspace_email),
        KEY auth_provider (auth_provider),
        KEY status (status)
    ) {$charset_collate};";
        \metis_db_delta( $people_sql );

        self::addColumnIfMissing( $people_table, 'first_name', 'VARCHAR(120) DEFAULT NULL' );
        self::addColumnIfMissing( $people_table, 'last_name', 'VARCHAR(120) DEFAULT NULL' );
        self::addColumnIfMissing( $people_table, 'linked_donor_id', 'VARCHAR(64) DEFAULT NULL' );
        self::addColumnIfMissing( $people_table, 'workspace_role', 'VARCHAR(64) DEFAULT NULL' );
        self::addColumnIfMissing( $people_table, 'board_position', 'VARCHAR(120) DEFAULT NULL' );
        self::addColumnIfMissing( $people_table, 'staff_position', 'VARCHAR(120) DEFAULT NULL' );
        self::addColumnIfMissing( $people_table, 'volunteer_position', 'VARCHAR(120) DEFAULT NULL' );
        self::addColumnIfMissing( $people_table, 'manager_pid', 'VARCHAR(16) DEFAULT NULL' );
        self::addColumnIfMissing( $people_table, 'department', 'VARCHAR(120) DEFAULT NULL' );
        self::addColumnIfMissing( $people_table, 'board_term_start', 'DATE DEFAULT NULL' );
        self::addColumnIfMissing( $people_table, 'board_term_end', 'DATE DEFAULT NULL' );
        self::addColumnIfMissing( $people_table, 'volunteer_area', 'VARCHAR(120) DEFAULT NULL' );
        self::addColumnIfMissing( $people_table, 'lifecycle_status', "VARCHAR(24) NOT NULL DEFAULT 'active'" );
        self::addColumnIfMissing( $people_table, 'email_notifications', 'TINYINT(1) NOT NULL DEFAULT 1' );
        self::addColumnIfMissing( $people_table, 'sms_notifications', 'TINYINT(1) NOT NULL DEFAULT 0' );
        self::addColumnIfMissing( $people_table, 'notification_prefs_json', 'LONGTEXT DEFAULT NULL' );
        self::addColumnIfMissing( $people_table, 'requires_2fa', 'TINYINT(1) NOT NULL DEFAULT 0' );
        self::addColumnIfMissing( $people_table, 'mfa_method', "VARCHAR(24) NOT NULL DEFAULT 'none'" );
        self::addColumnIfMissing( $people_table, 'totp_enabled', 'TINYINT(1) NOT NULL DEFAULT 0' );
        self::addColumnIfMissing( $people_table, 'passkey_enabled', 'TINYINT(1) NOT NULL DEFAULT 0' );
        self::addColumnIfMissing( $people_table, 'totp_secret_enc', 'TEXT DEFAULT NULL' );
        self::addColumnIfMissing( $people_table, 'password_hash', 'VARCHAR(255) DEFAULT NULL' );
        self::addColumnIfMissing( $people_table, 'avatar_url', 'VARCHAR(255) DEFAULT NULL' );
        self::addColumnIfMissing( $people_table, 'last_active_at', 'DATETIME DEFAULT NULL' );
        self::addColumnIfMissing( $people_table, 'offboarded_at', 'DATETIME DEFAULT NULL' );
        self::addColumnIfMissing( $people_table, 'person_uid', 'VARCHAR(16) DEFAULT NULL' );
        self::addIndexIfMissing( $people_table, 'volunteer_position', 'KEY volunteer_position (volunteer_position)' );
        self::addColumnIfMissing( $workspace_users_table, 'is_protected', 'TINYINT(1) NOT NULL DEFAULT 0' );
        $db->execute( "UPDATE {$people_table} SET auth_provider = 'metis' WHERE auth_provider IS NULL OR auth_provider = '' OR auth_provider NOT IN ('metis', 'workspace')" );

        $positions_sql = "CREATE TABLE {$positions_table} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        group_key VARCHAR(24) NOT NULL,
        position_key VARCHAR(64) NOT NULL,
        position_label VARCHAR(120) NOT NULL,
        sort_order INT NOT NULL DEFAULT 0,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY group_position (group_key, position_key),
        KEY group_active_sort (group_key, is_active, sort_order, position_label)
    ) {$charset_collate};";
        \metis_db_delta( $positions_sql );
        self::addColumnIfMissing( $positions_table, 'group_key', "VARCHAR(24) NOT NULL DEFAULT 'board'" );
        self::addColumnIfMissing( $positions_table, 'position_key', 'VARCHAR(64) DEFAULT NULL' );
        self::addColumnIfMissing( $positions_table, 'position_label', 'VARCHAR(120) DEFAULT NULL' );
        self::addColumnIfMissing( $positions_table, 'sort_order', 'INT NOT NULL DEFAULT 0' );
        self::addColumnIfMissing( $positions_table, 'is_active', 'TINYINT(1) NOT NULL DEFAULT 1' );
        self::addIndexIfMissing( $positions_table, 'group_position', 'UNIQUE KEY group_position (group_key, position_key)' );
        self::addIndexIfMissing( $positions_table, 'group_active_sort', 'KEY group_active_sort (group_key, is_active, sort_order, position_label)' );
        self::seedPositions( $positions_table );

        $roles_sql = "CREATE TABLE {$roles_table} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        role_key VARCHAR(64) NOT NULL,
        role_domain VARCHAR(24) NOT NULL DEFAULT 'metis',
        role_name VARCHAR(120) NOT NULL,
        description TEXT DEFAULT NULL,
        is_system TINYINT(1) NOT NULL DEFAULT 1,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY role_domain_key (role_domain, role_key),
        KEY role_domain (role_domain)
    ) {$charset_collate};";
        \metis_db_delta( $roles_sql );
        self::addColumnIfMissing( $roles_table, 'role_domain', "VARCHAR(24) NOT NULL DEFAULT 'metis'" );
        $db->execute( "UPDATE {$roles_table} SET role_domain = 'metis' WHERE role_domain IS NULL OR role_domain = ''" );
        self::dropIndexIfExists( $roles_table, 'role_key' );
        self::addIndexIfMissing( $roles_table, 'role_domain_key', 'UNIQUE KEY role_domain_key (role_domain, role_key)' );
        self::addIndexIfMissing( $roles_table, 'role_domain', 'KEY role_domain (role_domain)' );

        $perms_sql = "CREATE TABLE {$perms_table} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        permission_key VARCHAR(191) NOT NULL,
        module_slug VARCHAR(64) NOT NULL,
        action_key VARCHAR(32) NOT NULL,
        permission_name VARCHAR(191) NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY permission_key (permission_key),
        KEY module_action (module_slug, action_key)
    ) {$charset_collate};";
        \metis_db_delta( $perms_sql );

        $role_perms_sql = "CREATE TABLE {$role_perms_table} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        role_id BIGINT UNSIGNED NOT NULL,
        permission_id BIGINT UNSIGNED NOT NULL,
        allow_access TINYINT(1) NOT NULL DEFAULT 1,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY role_permission (role_id, permission_id)
    ) {$charset_collate};";
        \metis_db_delta( $role_perms_sql );

        $user_roles_sql = "CREATE TABLE {$user_roles_table} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        person_id BIGINT UNSIGNED NOT NULL,
        role_id BIGINT UNSIGNED NOT NULL,
        start_at DATETIME DEFAULT NULL,
        end_at DATETIME DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY person_role (person_id, role_id),
        KEY role_id (role_id),
        KEY role_window (start_at, end_at)
    ) {$charset_collate};";
        \metis_db_delta( $user_roles_sql );
        self::addColumnIfMissing( $user_roles_table, 'start_at', 'DATETIME DEFAULT NULL' );
        self::addColumnIfMissing( $user_roles_table, 'end_at', 'DATETIME DEFAULT NULL' );

        $activity_sql = "CREATE TABLE {$activity_table} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        person_id BIGINT UNSIGNED DEFAULT NULL,
        actor_person_id BIGINT UNSIGNED DEFAULT NULL,
        activity_type VARCHAR(64) NOT NULL,
        summary VARCHAR(255) NOT NULL,
        details LONGTEXT DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY person_id (person_id),
        KEY actor_person_id (actor_person_id),
        KEY activity_type (activity_type)
    ) {$charset_collate};";
        \metis_db_delta( $activity_sql );

        $requests_sql = "CREATE TABLE {$requests_table} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        request_code VARCHAR(24) DEFAULT NULL,
        requester_person_id BIGINT UNSIGNED DEFAULT NULL,
        target_person_id BIGINT UNSIGNED NOT NULL,
        role_id BIGINT UNSIGNED NOT NULL,
        status VARCHAR(24) NOT NULL DEFAULT 'pending',
        reason TEXT DEFAULT NULL,
        decision_note TEXT DEFAULT NULL,
        required_approvals TINYINT UNSIGNED NOT NULL DEFAULT 2,
        approval_count TINYINT UNSIGNED NOT NULL DEFAULT 0,
        approval_log_json LONGTEXT DEFAULT NULL,
        requested_start_at DATETIME DEFAULT NULL,
        requested_end_at DATETIME DEFAULT NULL,
        expires_at DATETIME DEFAULT NULL,
        resolver_person_id BIGINT UNSIGNED DEFAULT NULL,
        resolved_at DATETIME DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY request_code (request_code),
        KEY target_person_id (target_person_id),
        KEY role_id (role_id),
        KEY status (status)
    ) {$charset_collate};";
        \metis_db_delta( $requests_sql );
        self::addColumnIfMissing( $requests_table, 'decision_note', 'TEXT DEFAULT NULL' );
        self::addColumnIfMissing( $requests_table, 'required_approvals', 'TINYINT UNSIGNED NOT NULL DEFAULT 2' );
        self::addColumnIfMissing( $requests_table, 'approval_count', 'TINYINT UNSIGNED NOT NULL DEFAULT 0' );
        self::addColumnIfMissing( $requests_table, 'approval_log_json', 'LONGTEXT DEFAULT NULL' );
        self::addColumnIfMissing( $requests_table, 'requested_start_at', 'DATETIME DEFAULT NULL' );
        self::addColumnIfMissing( $requests_table, 'requested_end_at', 'DATETIME DEFAULT NULL' );
        self::addColumnIfMissing( $requests_table, 'expires_at', 'DATETIME DEFAULT NULL' );

        $templates_sql = "CREATE TABLE {$templates_table} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        template_key VARCHAR(64) NOT NULL,
        template_name VARCHAR(120) NOT NULL,
        description TEXT DEFAULT NULL,
        checklist_json LONGTEXT DEFAULT NULL,
        is_system TINYINT(1) NOT NULL DEFAULT 0,
        created_by_person_id BIGINT UNSIGNED DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY template_key (template_key)
    ) {$charset_collate};";
        \metis_db_delta( $templates_sql );
        self::addColumnIfMissing( $templates_table, 'checklist_json', 'LONGTEXT DEFAULT NULL' );

        $template_roles_sql = "CREATE TABLE {$template_roles_table} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        template_id BIGINT UNSIGNED NOT NULL,
        role_id BIGINT UNSIGNED NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY template_role (template_id, role_id),
        KEY role_id (role_id)
    ) {$charset_collate};";
        \metis_db_delta( $template_roles_sql );

        $documents_sql = "CREATE TABLE {$documents_table} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        person_id BIGINT UNSIGNED NOT NULL,
        doc_type VARCHAR(64) NOT NULL,
        doc_label VARCHAR(191) NOT NULL,
        storage_ref VARCHAR(255) DEFAULT NULL,
        meta_json LONGTEXT DEFAULT NULL,
        remind_at DATETIME DEFAULT NULL,
        expires_at DATETIME DEFAULT NULL,
        lifecycle_status VARCHAR(24) NOT NULL DEFAULT 'active',
        created_by_person_id BIGINT UNSIGNED DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY person_id (person_id),
        KEY doc_type (doc_type),
        KEY lifecycle_dates (lifecycle_status, expires_at, remind_at)
    ) {$charset_collate};";
        \metis_db_delta( $documents_sql );
        self::addColumnIfMissing( $documents_table, 'remind_at', 'DATETIME DEFAULT NULL' );
        self::addColumnIfMissing( $documents_table, 'expires_at', 'DATETIME DEFAULT NULL' );
        self::addColumnIfMissing( $documents_table, 'lifecycle_status', "VARCHAR(24) NOT NULL DEFAULT 'active'" );

        $emergency_access_sql = "CREATE TABLE {$emergency_access_table} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        person_id BIGINT UNSIGNED NOT NULL,
        granted_role_id BIGINT UNSIGNED DEFAULT NULL,
        reason TEXT DEFAULT NULL,
        granted_by_person_id BIGINT UNSIGNED DEFAULT NULL,
        starts_at DATETIME NOT NULL,
        ends_at DATETIME NOT NULL,
        revoked_at DATETIME DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY person_id (person_id),
        KEY granted_role_id (granted_role_id),
        KEY ends_at (ends_at)
    ) {$charset_collate};";
        \metis_db_delta( $emergency_access_sql );

        $passkeys_sql = "CREATE TABLE {$passkeys_table} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        person_id BIGINT UNSIGNED NOT NULL,
        credential_id VARCHAR(255) NOT NULL,
        credential_public_key LONGTEXT DEFAULT NULL,
        sign_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
        transports_json LONGTEXT DEFAULT NULL,
        label VARCHAR(120) DEFAULT NULL,
        created_by_person_id BIGINT UNSIGNED DEFAULT NULL,
        last_used_at DATETIME DEFAULT NULL,
        revoked_at DATETIME DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY credential_id (credential_id),
        KEY person_active (person_id, revoked_at)
    ) {$charset_collate};";
        \metis_db_delta( $passkeys_sql );

        $challenges_sql = "CREATE TABLE {$challenges_table} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        person_id BIGINT UNSIGNED DEFAULT NULL,
        challenge_key VARCHAR(64) NOT NULL,
        challenge_value VARCHAR(191) NOT NULL,
        purpose VARCHAR(32) NOT NULL,
        expires_at DATETIME NOT NULL,
        consumed_at DATETIME DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY challenge_key (challenge_key),
        KEY purpose_expires (purpose, expires_at),
        KEY person_id (person_id)
    ) {$charset_collate};";
        \metis_db_delta( $challenges_sql );

        $lifecycle_tasks_sql = "CREATE TABLE {$lifecycle_tasks_table} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        person_id BIGINT UNSIGNED NOT NULL,
        phase VARCHAR(24) NOT NULL DEFAULT 'onboarding',
        task_key VARCHAR(120) DEFAULT NULL,
        task_label VARCHAR(255) NOT NULL,
        status VARCHAR(24) NOT NULL DEFAULT 'pending',
        due_at DATETIME DEFAULT NULL,
        completed_at DATETIME DEFAULT NULL,
        source_template_key VARCHAR(64) DEFAULT NULL,
        created_by_person_id BIGINT UNSIGNED DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY person_phase_status (person_id, phase, status),
        KEY due_at (due_at)
    ) {$charset_collate};";
        \metis_db_delta( $lifecycle_tasks_sql );

        $workspace_users_sql = "CREATE TABLE {$workspace_users_table} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        person_id BIGINT UNSIGNED DEFAULT NULL,
        workspace_user_id VARCHAR(191) DEFAULT NULL,
        primary_email VARCHAR(191) NOT NULL,
        first_name VARCHAR(120) DEFAULT NULL,
        last_name VARCHAR(120) DEFAULT NULL,
        display_name VARCHAR(191) DEFAULT NULL,
        org_unit_path VARCHAR(191) DEFAULT NULL,
        recovery_email VARCHAR(191) DEFAULT NULL,
        is_suspended TINYINT(1) NOT NULL DEFAULT 0,
        is_protected TINYINT(1) NOT NULL DEFAULT 0,
        last_login_at DATETIME DEFAULT NULL,
        source VARCHAR(24) NOT NULL DEFAULT 'metis',
        sync_status VARCHAR(24) NOT NULL DEFAULT 'pending',
        metadata_json LONGTEXT DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY primary_email (primary_email),
        KEY person_id (person_id),
        KEY workspace_user_id (workspace_user_id),
        KEY sync_status (sync_status)
    ) {$charset_collate};";
        \metis_db_delta( $workspace_users_sql );

        $workspace_user_roles_sql = "CREATE TABLE {$workspace_user_roles_table} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        workspace_user_id BIGINT UNSIGNED NOT NULL,
        role_key VARCHAR(64) NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY user_role (workspace_user_id, role_key),
        KEY role_key (role_key)
    ) {$charset_collate};";
        \metis_db_delta( $workspace_user_roles_sql );

        $workspace_groups_sql = "CREATE TABLE {$workspace_groups_table} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        workspace_group_id VARCHAR(191) DEFAULT NULL,
        group_email VARCHAR(191) NOT NULL,
        group_name VARCHAR(191) NOT NULL,
        description TEXT DEFAULT NULL,
        direct_members_count INT UNSIGNED NOT NULL DEFAULT 0,
        source VARCHAR(24) NOT NULL DEFAULT 'metis',
        sync_status VARCHAR(24) NOT NULL DEFAULT 'pending',
        metadata_json LONGTEXT DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY group_email (group_email),
        KEY workspace_group_id (workspace_group_id),
        KEY sync_status (sync_status)
    ) {$charset_collate};";
        \metis_db_delta( $workspace_groups_sql );

        $workspace_group_members_sql = "CREATE TABLE {$workspace_group_members_table} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        group_id BIGINT UNSIGNED NOT NULL,
        workspace_user_id BIGINT UNSIGNED NOT NULL,
        member_role VARCHAR(24) NOT NULL DEFAULT 'member',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY group_member (group_id, workspace_user_id),
        KEY group_id (group_id),
        KEY workspace_user_id (workspace_user_id)
    ) {$charset_collate};";
        \metis_db_delta( $workspace_group_members_sql );

        $workspace_security_actions_sql = "CREATE TABLE {$workspace_security_actions_table} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        workspace_user_id BIGINT UNSIGNED NOT NULL,
        action_type VARCHAR(64) NOT NULL,
        requested_by_person_id BIGINT UNSIGNED DEFAULT NULL,
        status VARCHAR(24) NOT NULL DEFAULT 'pending',
        reason TEXT DEFAULT NULL,
        payload_json LONGTEXT DEFAULT NULL,
        completed_at DATETIME DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY workspace_user_id (workspace_user_id),
        KEY action_status (action_type, status)
    ) {$charset_collate};";
        \metis_db_delta( $workspace_security_actions_sql );

        $workspace_sync_jobs_sql = "CREATE TABLE {$workspace_sync_jobs_table} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        job_type VARCHAR(64) NOT NULL,
        entity_type VARCHAR(32) NOT NULL,
        entity_id BIGINT UNSIGNED DEFAULT NULL,
        requested_by_person_id BIGINT UNSIGNED DEFAULT NULL,
        payload_json LONGTEXT DEFAULT NULL,
        status VARCHAR(24) NOT NULL DEFAULT 'queued',
        last_error TEXT DEFAULT NULL,
        processed_at DATETIME DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY status (status),
        KEY entity_ref (entity_type, entity_id),
        KEY job_type (job_type)
    ) {$charset_collate};";
        \metis_db_delta( $workspace_sync_jobs_sql );

        if ( ! self::tableExists( $people_table ) ) { $db->execute( $people_sql ); }
        if ( ! self::tableExists( $roles_table ) ) { $db->execute( $roles_sql ); }
        if ( ! self::tableExists( $perms_table ) ) { $db->execute( $perms_sql ); }
        if ( ! self::tableExists( $role_perms_table ) ) { $db->execute( $role_perms_sql ); }
        if ( ! self::tableExists( $user_roles_table ) ) { $db->execute( $user_roles_sql ); }
        if ( ! self::tableExists( $activity_table ) ) { $db->execute( $activity_sql ); }
        if ( ! self::tableExists( $requests_table ) ) { $db->execute( $requests_sql ); }
        if ( ! self::tableExists( $templates_table ) ) { $db->execute( $templates_sql ); }
        if ( ! self::tableExists( $template_roles_table ) ) { $db->execute( $template_roles_sql ); }
        if ( ! self::tableExists( $documents_table ) ) { $db->execute( $documents_sql ); }
        if ( ! self::tableExists( $emergency_access_table ) ) { $db->execute( $emergency_access_sql ); }
        if ( ! self::tableExists( $passkeys_table ) ) { $db->execute( $passkeys_sql ); }
        if ( ! self::tableExists( $challenges_table ) ) { $db->execute( $challenges_sql ); }
        if ( ! self::tableExists( $lifecycle_tasks_table ) ) { $db->execute( $lifecycle_tasks_sql ); }
        if ( ! self::tableExists( $workspace_users_table ) ) { $db->execute( $workspace_users_sql ); }
        if ( ! self::tableExists( $workspace_user_roles_table ) ) { $db->execute( $workspace_user_roles_sql ); }
        if ( ! self::tableExists( $workspace_groups_table ) ) { $db->execute( $workspace_groups_sql ); }
        if ( ! self::tableExists( $workspace_group_members_table ) ) { $db->execute( $workspace_group_members_sql ); }
        if ( ! self::tableExists( $workspace_security_actions_table ) ) { $db->execute( $workspace_security_actions_sql ); }
        if ( ! self::tableExists( $workspace_sync_jobs_table ) ) { $db->execute( $workspace_sync_jobs_sql ); }

        if ( function_exists( 'metis_entity_id_service' ) ) {
            \metis_entity_id_service()->ensureSchema();
        }

        self::$done = true;
    }

    private static function seedPositions( string $positions_table ): void {
        if ( ! self::tableExists( $positions_table ) ) {
            return;
        }

        $db = self::db();
        $defaults = [
            'board' => [ 'President', 'Vice President', 'Secretary', 'Treasurer', 'Board Member' ],
            'staff' => [ 'Executive Director', 'Deputy Director', 'Program Manager', 'Operations Manager', 'Coordinator' ],
            'volunteer' => [ 'Lead Volunteer', 'Volunteer Coordinator', 'Committee Volunteer', 'Event Volunteer' ],
        ];

        foreach ( $defaults as $group_key => $labels ) {
            foreach ( $labels as $index => $label ) {
                $position_label = trim( (string) $label );
                if ( $position_label === '' ) {
                    continue;
                }
                $position_key = \metis_key_clean( strtolower( str_replace( ' ', '_', $position_label ) ) );
                if ( $position_key === '' ) {
                    continue;
                }
                $existing = (int) $db->scalar(
                    "SELECT id FROM {$positions_table} WHERE group_key = %s AND position_key = %s LIMIT 1",
                    [ $group_key, $position_key ]
                );
                if ( $existing > 0 ) {
                    continue;
                }
                $db->insert(
                    $positions_table,
                    [
                        'group_key' => $group_key,
                        'position_key' => $position_key,
                        'position_label' => $position_label,
                        'sort_order' => $index + 1,
                        'is_active' => 1,
                    ],
                    [ '%s', '%s', '%s', '%d', '%d' ]
                );
            }
        }
    }

    private static function db(): \Metis\Services\DatabaseService {
        return \metis_db();
    }
}
