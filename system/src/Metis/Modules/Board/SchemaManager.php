<?php
declare(strict_types=1);

namespace Metis\Modules\Board;

final class SchemaManager {
    private static bool $schema_ready = false;
    private static bool $templates_seeded = false;

    public static function tableExists( string $table ): bool {
        $db = self::db();
        $exists = $db->scalar( 'SHOW TABLES LIKE %s', [ $table ] );
        return $exists === $table;
    }

    public static function ensureSchema(): void {
        if ( self::$schema_ready ) {
            return;
        }

        $db = self::db();
        $charset_collate         = $db->get_charset_collate();
        $committees_table        = \Metis_Tables::get( 'board_committees' );
        $meetings_table          = \Metis_Tables::get( 'board_meetings' );
        $decisions_table         = \Metis_Tables::get( 'board_decisions' );
        $actions_table           = \Metis_Tables::get( 'board_action_items' );
        $attendance_table        = \Metis_Tables::get( 'board_attendance' );
        $documents_table         = \Metis_Tables::get( 'board_documents' );
        $compliance_table        = \Metis_Tables::get( 'board_compliance' );
        $announcements_table     = \Metis_Tables::get( 'board_announcements' );
        $bylaws_table            = \Metis_Tables::get( 'board_bylaws' );
        $agenda_templates_table  = \Metis_Tables::get( 'board_agenda_templates' );
        $decision_templates_table= \Metis_Tables::get( 'board_decision_templates' );

        $sql_committees = "CREATE TABLE {$committees_table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            committee_code VARCHAR(16) DEFAULT NULL,
            name VARCHAR(191) NOT NULL,
            description TEXT DEFAULT NULL,
            chair_person_id BIGINT UNSIGNED DEFAULT NULL,
            newsletter_list_id BIGINT UNSIGNED DEFAULT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY committee_code (committee_code),
            KEY name (name),
            KEY chair_person_id (chair_person_id),
            KEY newsletter_list_id (newsletter_list_id),
            KEY is_active (is_active)
        ) {$charset_collate};";

        $sql_meetings = "CREATE TABLE {$meetings_table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            meeting_code VARCHAR(16) DEFAULT NULL,
            title VARCHAR(191) NOT NULL,
            committee_id BIGINT UNSIGNED DEFAULT NULL,
            meeting_date DATETIME NOT NULL,
            meeting_type VARCHAR(32) NOT NULL DEFAULT 'board',
            location VARCHAR(191) DEFAULT NULL,
            status VARCHAR(32) NOT NULL DEFAULT 'draft',
            agenda_json LONGTEXT DEFAULT NULL,
            minutes_html LONGTEXT DEFAULT NULL,
            board_packet_notes LONGTEXT DEFAULT NULL,
            packet_source_minutes_meeting_id BIGINT UNSIGNED DEFAULT NULL,
            packet_financial_document_id BIGINT UNSIGNED DEFAULT NULL,
            google_calendar_event_id VARCHAR(191) DEFAULT NULL,
            google_calendar_event_name VARCHAR(191) DEFAULT NULL,
            google_calendar_html_link VARCHAR(255) DEFAULT NULL,
            google_drive_folder_id VARCHAR(191) DEFAULT NULL,
            google_drive_folder_name VARCHAR(191) DEFAULT NULL,
            google_drive_folder_url VARCHAR(255) DEFAULT NULL,
            attendance_locked TINYINT(1) NOT NULL DEFAULT 0,
            created_by_person_id BIGINT UNSIGNED DEFAULT NULL,
            published_at DATETIME DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY meeting_code (meeting_code),
            KEY committee_id (committee_id),
            KEY meeting_date (meeting_date),
            KEY status (status)
        ) {$charset_collate};";

        $sql_decisions = "CREATE TABLE {$decisions_table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            decision_code VARCHAR(16) DEFAULT NULL,
            meeting_id BIGINT UNSIGNED NOT NULL,
            title VARCHAR(191) NOT NULL,
            agenda_section_title VARCHAR(191) DEFAULT NULL,
            agenda_item_title VARCHAR(191) DEFAULT NULL,
            agenda_point_hash VARCHAR(64) DEFAULT NULL,
            decision_text LONGTEXT DEFAULT NULL,
            outcome VARCHAR(32) NOT NULL DEFAULT 'pending',
            votes_for INT NOT NULL DEFAULT 0,
            votes_against INT NOT NULL DEFAULT 0,
            votes_abstain INT NOT NULL DEFAULT 0,
            decision_votes_json LONGTEXT DEFAULT NULL,
            passed TINYINT(1) NOT NULL DEFAULT 0,
            passed_at DATETIME DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY decision_code (decision_code),
            KEY meeting_id (meeting_id),
            KEY agenda_point_hash (agenda_point_hash),
            KEY outcome (outcome)
        ) {$charset_collate};";

        $sql_actions = "CREATE TABLE {$actions_table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            action_code VARCHAR(16) DEFAULT NULL,
            meeting_id BIGINT UNSIGNED DEFAULT NULL,
            decision_id BIGINT UNSIGNED DEFAULT NULL,
            title VARCHAR(191) NOT NULL,
            description LONGTEXT DEFAULT NULL,
            owner_person_id BIGINT UNSIGNED DEFAULT NULL,
            due_date DATE DEFAULT NULL,
            priority VARCHAR(16) NOT NULL DEFAULT 'normal',
            status VARCHAR(24) NOT NULL DEFAULT 'open',
            completed_at DATETIME DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY action_code (action_code),
            KEY meeting_id (meeting_id),
            KEY owner_person_id (owner_person_id),
            KEY status (status),
            KEY due_date (due_date)
        ) {$charset_collate};";

        $sql_attendance = "CREATE TABLE {$attendance_table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            meeting_id BIGINT UNSIGNED NOT NULL,
            person_id BIGINT UNSIGNED NOT NULL,
            role_label VARCHAR(64) DEFAULT NULL,
            status VARCHAR(24) NOT NULL DEFAULT 'present',
            checkin_at DATETIME DEFAULT NULL,
            notes TEXT DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY meeting_person (meeting_id, person_id),
            KEY status (status)
        ) {$charset_collate};";

        $sql_documents = "CREATE TABLE {$documents_table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            document_code VARCHAR(16) DEFAULT NULL,
            meeting_id BIGINT UNSIGNED DEFAULT NULL,
            committee_id BIGINT UNSIGNED DEFAULT NULL,
            title VARCHAR(191) NOT NULL,
            doc_type VARCHAR(40) NOT NULL DEFAULT 'board_packet',
            google_file_id VARCHAR(191) DEFAULT NULL,
            google_drive_url VARCHAR(255) DEFAULT NULL,
            mime_type VARCHAR(120) DEFAULT NULL,
            file_size BIGINT UNSIGNED DEFAULT NULL,
            version_label VARCHAR(32) DEFAULT NULL,
            status VARCHAR(24) NOT NULL DEFAULT 'active',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY document_code (document_code),
            KEY meeting_id (meeting_id),
            KEY committee_id (committee_id),
            KEY doc_type (doc_type)
        ) {$charset_collate};";

        $sql_compliance = "CREATE TABLE {$compliance_table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            item_code VARCHAR(16) DEFAULT NULL,
            person_id BIGINT UNSIGNED NOT NULL,
            item_type VARCHAR(40) NOT NULL DEFAULT 'policy_ack',
            title VARCHAR(191) NOT NULL,
            description LONGTEXT DEFAULT NULL,
            due_date DATE DEFAULT NULL,
            status VARCHAR(24) NOT NULL DEFAULT 'pending',
            acknowledged_at DATETIME DEFAULT NULL,
            disclosure_json LONGTEXT DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY item_code (item_code),
            KEY person_id (person_id),
            KEY item_type (item_type),
            KEY status (status),
            KEY due_date (due_date)
        ) {$charset_collate};";

        $sql_announcements = "CREATE TABLE {$announcements_table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            announcement_code VARCHAR(16) DEFAULT NULL,
            title VARCHAR(191) NOT NULL,
            body_html LONGTEXT DEFAULT NULL,
            audience_json LONGTEXT DEFAULT NULL,
            status VARCHAR(24) NOT NULL DEFAULT 'draft',
            publish_at DATETIME DEFAULT NULL,
            published_by_person_id BIGINT UNSIGNED DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY announcement_code (announcement_code),
            KEY status (status),
            KEY publish_at (publish_at)
        ) {$charset_collate};";

        $sql_bylaws = "CREATE TABLE {$bylaws_table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            bylaw_code VARCHAR(16) DEFAULT NULL,
            title VARCHAR(191) NOT NULL DEFAULT 'Bylaws',
            source_text LONGTEXT DEFAULT NULL,
            formatted_html LONGTEXT DEFAULT NULL,
            signed_pdf_file_id VARCHAR(191) DEFAULT NULL,
            signed_pdf_url VARCHAR(255) DEFAULT NULL,
            signed_pdf_title VARCHAR(191) DEFAULT NULL,
            status VARCHAR(24) NOT NULL DEFAULT 'active',
            approval_stage VARCHAR(32) NOT NULL DEFAULT 'active',
            version_number INT UNSIGNED NOT NULL DEFAULT 1,
            document_hash VARCHAR(64) DEFAULT NULL,
            pdf_hash VARCHAR(64) DEFAULT NULL,
            generated_pdf_path VARCHAR(255) DEFAULT NULL,
            meeting_id BIGINT UNSIGNED DEFAULT NULL,
            decision_id BIGINT UNSIGNED DEFAULT NULL,
            action_item_id BIGINT UNSIGNED DEFAULT NULL,
            secretary_person_id BIGINT UNSIGNED DEFAULT NULL,
            secretary_signature_name VARCHAR(191) DEFAULT NULL,
            secretary_certified_at DATETIME DEFAULT NULL,
            secretary_context_json LONGTEXT DEFAULT NULL,
            president_person_id BIGINT UNSIGNED DEFAULT NULL,
            president_signature_name VARCHAR(191) DEFAULT NULL,
            president_approved_at DATETIME DEFAULT NULL,
            president_context_json LONGTEXT DEFAULT NULL,
            board_vote_context_json LONGTEXT DEFAULT NULL,
            approved_by_person_id BIGINT UNSIGNED DEFAULT NULL,
            approved_signature_name VARCHAR(191) DEFAULT NULL,
            approval_context_json LONGTEXT DEFAULT NULL,
            change_summary LONGTEXT DEFAULT NULL,
            effective_date DATE DEFAULT NULL,
            approved_at DATETIME DEFAULT NULL,
            created_by_person_id BIGINT UNSIGNED DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY bylaw_code (bylaw_code),
            KEY status (status),
            KEY approval_stage (approval_stage),
            KEY version_number (version_number),
            KEY meeting_id (meeting_id),
            KEY decision_id (decision_id),
            KEY action_item_id (action_item_id),
            KEY effective_date (effective_date),
            KEY updated_at (updated_at)
        ) {$charset_collate};";

        $sql_agenda_templates = "CREATE TABLE {$agenda_templates_table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            template_code VARCHAR(16) DEFAULT NULL,
            name VARCHAR(191) NOT NULL,
            description TEXT DEFAULT NULL,
            default_items_json LONGTEXT DEFAULT NULL,
            sort_order INT NOT NULL DEFAULT 0,
            is_required TINYINT(1) NOT NULL DEFAULT 0,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY template_code (template_code),
            KEY sort_order (sort_order),
            KEY is_active (is_active)
        ) {$charset_collate};";

        $sql_decision_templates = "CREATE TABLE {$decision_templates_table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            template_code VARCHAR(16) DEFAULT NULL,
            title VARCHAR(191) NOT NULL,
            description TEXT DEFAULT NULL,
            default_outcome VARCHAR(32) NOT NULL DEFAULT 'pending',
            sort_order INT NOT NULL DEFAULT 0,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY template_code (template_code),
            KEY sort_order (sort_order),
            KEY is_active (is_active)
        ) {$charset_collate};";

        \metis_db_delta( $sql_committees );
        \metis_db_delta( $sql_meetings );
        \metis_db_delta( $sql_decisions );
        \metis_db_delta( $sql_actions );
        \metis_db_delta( $sql_attendance );
        \metis_db_delta( $sql_documents );
        \metis_db_delta( $sql_compliance );
        \metis_db_delta( $sql_announcements );
        \metis_db_delta( $sql_bylaws );
        \metis_db_delta( $sql_agenda_templates );
        \metis_db_delta( $sql_decision_templates );
        self::ensureRequiredColumns( $committees_table, $meetings_table, $decisions_table, $bylaws_table );

        if ( function_exists( 'metis_entity_id_service' ) ) {
            \metis_entity_id_service()->ensureSchema();
        }

        self::$schema_ready = true;
    }

    private static function ensureRequiredColumns( string $committees_table, string $meetings_table, string $decisions_table, string $bylaws_table ): void {
        $db = self::db();
        $committee_cols = self::tableColumns( $committees_table );
        if ( ! isset( $committee_cols['newsletter_list_id'] ) ) {
            $db->execute( "ALTER TABLE {$committees_table} ADD COLUMN newsletter_list_id BIGINT UNSIGNED DEFAULT NULL AFTER chair_person_id" );
        }
        if ( ! self::tableIndexExists( $committees_table, 'newsletter_list_id' ) ) {
            $db->execute( "ALTER TABLE {$committees_table} ADD KEY newsletter_list_id (newsletter_list_id)" );
        }

        $meeting_cols = self::tableColumns( $meetings_table );
        if ( ! isset( $meeting_cols['google_calendar_event_name'] ) ) {
            $db->execute( "ALTER TABLE {$meetings_table} ADD COLUMN google_calendar_event_name VARCHAR(191) DEFAULT NULL AFTER google_calendar_event_id" );
        }
        if ( ! isset( $meeting_cols['google_drive_folder_name'] ) ) {
            $db->execute( "ALTER TABLE {$meetings_table} ADD COLUMN google_drive_folder_name VARCHAR(191) DEFAULT NULL AFTER google_drive_folder_id" );
        }

        $decision_cols = self::tableColumns( $decisions_table );
        if ( ! isset( $decision_cols['decision_votes_json'] ) ) {
            $db->execute( "ALTER TABLE {$decisions_table} ADD COLUMN decision_votes_json LONGTEXT DEFAULT NULL AFTER votes_abstain" );
        }

        $bylaws_cols = self::tableColumns( $bylaws_table );
        $bylaws_columns = [
            'approval_stage'          => "VARCHAR(32) NOT NULL DEFAULT 'active' AFTER status",
            'version_number'          => "INT UNSIGNED NOT NULL DEFAULT 1 AFTER approval_stage",
            'document_hash'           => "VARCHAR(64) DEFAULT NULL AFTER version_number",
            'pdf_hash'                => "VARCHAR(64) DEFAULT NULL AFTER document_hash",
            'generated_pdf_path'      => "VARCHAR(255) DEFAULT NULL AFTER pdf_hash",
            'meeting_id'              => "BIGINT UNSIGNED DEFAULT NULL AFTER generated_pdf_path",
            'decision_id'             => "BIGINT UNSIGNED DEFAULT NULL AFTER meeting_id",
            'action_item_id'          => "BIGINT UNSIGNED DEFAULT NULL AFTER decision_id",
            'secretary_person_id'     => "BIGINT UNSIGNED DEFAULT NULL AFTER action_item_id",
            'secretary_signature_name'=> "VARCHAR(191) DEFAULT NULL AFTER secretary_person_id",
            'secretary_certified_at'  => "DATETIME DEFAULT NULL AFTER secretary_signature_name",
            'secretary_context_json'  => "LONGTEXT DEFAULT NULL AFTER secretary_certified_at",
            'president_person_id'     => "BIGINT UNSIGNED DEFAULT NULL AFTER secretary_context_json",
            'president_signature_name'=> "VARCHAR(191) DEFAULT NULL AFTER president_person_id",
            'president_approved_at'   => "DATETIME DEFAULT NULL AFTER president_signature_name",
            'president_context_json'  => "LONGTEXT DEFAULT NULL AFTER president_approved_at",
            'board_vote_context_json' => "LONGTEXT DEFAULT NULL AFTER president_context_json",
            'approved_by_person_id'   => "BIGINT UNSIGNED DEFAULT NULL AFTER board_vote_context_json",
            'approved_signature_name' => "VARCHAR(191) DEFAULT NULL AFTER approved_by_person_id",
            'approval_context_json'   => "LONGTEXT DEFAULT NULL AFTER approved_signature_name",
            'change_summary'          => "LONGTEXT DEFAULT NULL AFTER approval_context_json",
        ];
        foreach ( $bylaws_columns as $column => $definition ) {
            if ( ! isset( $bylaws_cols[ $column ] ) ) {
                $db->execute( "ALTER TABLE {$bylaws_table} ADD COLUMN {$column} {$definition}" );
            }
        }
        if ( ! self::tableIndexExists( $bylaws_table, 'version_number' ) ) {
            $db->execute( "ALTER TABLE {$bylaws_table} ADD KEY version_number (version_number)" );
        }
        foreach ( [ 'approval_stage', 'meeting_id', 'decision_id', 'action_item_id' ] as $index_name ) {
            if ( ! self::tableIndexExists( $bylaws_table, $index_name ) ) {
                $db->execute( "ALTER TABLE {$bylaws_table} ADD KEY {$index_name} ({$index_name})" );
            }
        }
    }

    private static function tableIndexExists( string $table, string $index_name ): bool {
        $db = self::db();
        $rows = $db->fetchAll( "SHOW INDEX FROM {$table} WHERE Key_name = %s", [ $index_name ] ) ?: [];
        return ! empty( $rows );
    }

    /** @return array<string,bool> */
    private static function tableColumns( string $table ): array {
        $db = self::db();
        $rows = $db->fetchAll( "SHOW COLUMNS FROM {$table}" ) ?: [];
        $cols = [];
        foreach ( $rows as $row ) {
            if ( ! is_array( $row ) ) {
                continue;
            }
            $name = trim( (string) ( $row['Field'] ?? '' ) );
            if ( $name !== '' ) {
                $cols[$name] = true;
            }
        }
        return $cols;
    }

    public static function seedWorkflowTemplates(): void {
        if ( self::$templates_seeded ) {
            return;
        }
        self::$templates_seeded = true;

        self::ensureSchema();
        $db = self::db();

        $agenda_table   = \Metis_Tables::get( 'board_agenda_templates' );
        $decision_table = \Metis_Tables::get( 'board_decision_templates' );

        $agenda_defaults = [
            [ 'Board Business', 'Core board governance items for the meeting.', [ 'January Minutes', 'February Financials' ], 10, 1 ],
            [ 'Fundraising Update', 'Fundraising pipeline, grant timelines, and outreach updates.', [ 'Grant updates', 'Donor/foundation outreach' ], 20, 0 ],
            [ 'Access Update', 'Program access and service delivery updates.', [ 'Program report', 'Collaboration updates' ], 30, 0 ],
            [ 'Visibility Update', 'Communications and public visibility updates.', [ 'Campaign updates', 'Communications actions' ], 40, 0 ],
            [ 'Leadership Update', 'Leadership and partner strategy updates.', [ 'Partner updates', 'Operational planning' ], 50, 0 ],
            [ 'Action Team Update', 'Action Team status and upcoming meeting prep.', [ 'Prior Action Team recap', 'Next meeting plan' ], 60, 0 ],
            [ 'Executive Session', 'Executive session if needed.', [ 'Executive session if needed' ], 70, 0 ],
        ];
        $agenda_count = (int) $db->scalar( "SELECT COUNT(*) FROM {$agenda_table}" );
        if ( $agenda_count < 1 ) {
            foreach ( $agenda_defaults as $row ) {
                $db->insert( $agenda_table, [
                    'template_code'      => Support::generateCode( 'BAT', $agenda_table, 'template_code' ),
                    'name'               => $row[0],
                    'description'        => $row[1],
                    'default_items_json' => \metis_json_encode( $row[2] ),
                    'sort_order'         => (int) $row[3],
                    'is_required'        => (int) $row[4],
                    'is_active'          => 1,
                ], [ '%s', '%s', '%s', '%s', '%d', '%d', '%d' ] );
            }
        }

        $decision_defaults = [
            [ 'Approve Minutes', 'Approve prior meeting minutes.', 10 ],
            [ 'Approve Financial Report', 'Approve the monthly financial report.', 20 ],
            [ 'Approve Bylaw Update', 'Approve bylaw revision.', 30 ],
            [ 'Approve Policy Update', 'Approve policy adoption or revision.', 40 ],
            [ 'Approve Committee Recommendation', 'Approve a formal committee recommendation.', 50 ],
            [ 'Approve Budget Adjustment', 'Approve budget reallocation or adjustment.', 60 ],
            [ 'Approve Executive Session Action', 'Approve action originating from executive session.', 70 ],
        ];
        $decision_count = (int) $db->scalar( "SELECT COUNT(*) FROM {$decision_table}" );
        if ( $decision_count < 1 ) {
            foreach ( $decision_defaults as $row ) {
                $db->insert( $decision_table, [
                    'template_code'    => Support::generateCode( 'BDT', $decision_table, 'template_code' ),
                    'title'            => $row[0],
                    'description'      => $row[1],
                    'default_outcome'  => 'pending',
                    'sort_order'       => (int) $row[2],
                    'is_active'        => 1,
                ], [ '%s', '%s', '%s', '%s', '%d', '%d' ] );
            }
        }

        $migrate_flag = (string) \metis_get_option( 'metis_board_workflow_defaults_v2', '' );
        if ( $migrate_flag !== '1' ) {
            $legacy_names = [
                'Call to Order',
                'Approval of Minutes',
                'Financial Report',
                'Committee Reports',
                'Old Business',
                'New Business',
                'Adjournment',
            ];
            $has_new_defaults = (int) $db->scalar( "SELECT COUNT(*) FROM {$agenda_table} WHERE name = %s AND is_active = 1", [ 'Board Business' ] ) > 0;
            $legacy_active_count = (int) $db->scalar( "SELECT COUNT(*) FROM {$agenda_table} WHERE is_active = 1 AND name IN ('" . implode( "','", array_map( 'esc_sql', $legacy_names ) ) . "')" );
            if ( ! $has_new_defaults && $legacy_active_count > 0 ) {
                $db->execute( "UPDATE {$agenda_table} SET is_active = 0 WHERE is_active = 1 AND name IN ('" . implode( "','", array_map( 'esc_sql', $legacy_names ) ) . "')" );
                foreach ( $agenda_defaults as $row ) {
                    $db->insert( $agenda_table, [
                        'template_code'      => Support::generateCode( 'BAT', $agenda_table, 'template_code' ),
                        'name'               => $row[0],
                        'description'        => $row[1],
                        'default_items_json' => \metis_json_encode( $row[2] ),
                        'sort_order'         => (int) $row[3],
                        'is_required'        => (int) $row[4],
                        'is_active'          => 1,
                    ], [ '%s', '%s', '%s', '%s', '%d', '%d', '%d' ] );
                }
            }
            \metis_update_option( 'metis_board_workflow_defaults_v2', '1', false );
        }
    }

    private static function db(): \Metis\Services\DatabaseService {
        return \metis_db();
    }
}
