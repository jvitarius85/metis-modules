<?php
if ( ! defined( 'METIS_ROOT' ) ) {
    exit;
}

if ( ! function_exists( 'metis_board_fetch_dashboard_meetings' ) ) {
    function metis_board_fetch_dashboard_meetings( int $limit = 300 ): array {
        $db = metis_db();
        $meetings_table = Metis_Tables::get( 'board_meetings' );
        $committees_table = Metis_Tables::get( 'board_committees' );
        $actions_table = Metis_Tables::get( 'board_action_items' );
        $decisions_table = Metis_Tables::get( 'board_decisions' );

        return $db->fetchAll(
            "SELECT m.id, m.meeting_code, m.title, m.committee_id, m.meeting_date, m.meeting_type, m.location, m.status, m.updated_at,
                    m.google_calendar_event_id, m.google_drive_folder_id,
                    c.name AS committee_name,
                    (SELECT COUNT(*) FROM {$actions_table} a WHERE a.meeting_id = m.id AND a.status <> 'done') AS open_actions,
                    (SELECT COUNT(*) FROM {$decisions_table} d WHERE d.meeting_id = m.id) AS decisions_count
             FROM {$meetings_table} m
             LEFT JOIN {$committees_table} c ON c.id = m.committee_id
             ORDER BY m.meeting_date DESC, m.id DESC
             LIMIT %d",
            [ max( 1, $limit ) ]
        ) ?: [];
    }
}

if ( ! function_exists( 'metis_board_fetch_dashboard_committees' ) ) {
    function metis_board_fetch_dashboard_committees( bool $include_newsletter_lists = false ): array {
        $db = metis_db();
        $committees_table = Metis_Tables::get( 'board_committees' );
        $meetings_table = Metis_Tables::get( 'board_meetings' );
        $people_table = Metis_Tables::get( 'people' );
        $newsletter_lists_table = Metis_Tables::get( 'newsletter_lists' );
        $has_newsletter_lists = $include_newsletter_lists && function_exists( 'metis_board_table_exists' ) && metis_board_table_exists( $newsletter_lists_table );
        $list_select = $has_newsletter_lists ? 'nl.name AS newsletter_list_name' : "'' AS newsletter_list_name";
        $list_join = $has_newsletter_lists ? "LEFT JOIN {$newsletter_lists_table} nl ON nl.id = c.newsletter_list_id" : '';

        return $db->fetchAll(
            "SELECT c.id, c.committee_code, c.name, c.description, c.chair_person_id, c.newsletter_list_id, c.is_active, c.updated_at,
                    p.display_name AS chair_name,
                    {$list_select},
                    (SELECT COUNT(*) FROM {$meetings_table} m WHERE m.committee_id = c.id) AS meeting_count
             FROM {$committees_table} c
             LEFT JOIN {$people_table} p ON p.id = c.chair_person_id
             {$list_join}
             ORDER BY c.name ASC"
        ) ?: [];
    }
}

if ( ! function_exists( 'metis_board_fetch_dashboard_open_actions' ) ) {
    function metis_board_fetch_dashboard_open_actions( int $limit = 12 ): array {
        $db = metis_db();
        $actions_table = Metis_Tables::get( 'board_action_items' );
        $meetings_table = Metis_Tables::get( 'board_meetings' );
        $people_table = Metis_Tables::get( 'people' );

        return $db->fetchAll(
            "SELECT a.id, a.action_code, a.title, a.status, a.priority, a.due_date, a.owner_person_id,
                    m.meeting_code, m.title AS meeting_title,
                    p.display_name AS owner_name
             FROM {$actions_table} a
             LEFT JOIN {$meetings_table} m ON m.id = a.meeting_id
             LEFT JOIN {$people_table} p ON p.id = a.owner_person_id
             WHERE a.status <> 'done'
             ORDER BY (a.due_date IS NULL), a.due_date ASC, a.updated_at DESC
             LIMIT %d",
            [ max( 1, $limit ) ]
        ) ?: [];
    }
}

if ( ! function_exists( 'metis_board_fetch_dashboard_announcements' ) ) {
    function metis_board_fetch_dashboard_announcements( int $limit = 10 ): array {
        $db = metis_db();
        $announcements_table = Metis_Tables::get( 'board_announcements' );

        return $db->fetchAll(
            "SELECT id, announcement_code, title, status, publish_at, updated_at
             FROM {$announcements_table}
             ORDER BY updated_at DESC
             LIMIT %d",
            [ max( 1, $limit ) ]
        ) ?: [];
    }
}

if ( ! function_exists( 'metis_board_fetch_dashboard_bylaws' ) ) {
    function metis_board_fetch_dashboard_bylaws(): array {
        $bylaws_table = Metis_Tables::get( 'board_bylaws' );
        if ( ! function_exists( 'metis_board_table_exists' ) || ! metis_board_table_exists( $bylaws_table ) ) {
            return [];
        }

        $db = metis_db();
        $row = $db->fetchOne(
            "SELECT id, bylaw_code, title, source_text, formatted_html, signed_pdf_file_id, signed_pdf_url,
                    signed_pdf_title, status, approval_stage, version_number, meeting_id, decision_id, action_item_id,
                    secretary_signature_name, secretary_certified_at, president_signature_name, president_approved_at,
                    effective_date, approved_at, updated_at
             FROM {$bylaws_table}
             WHERE status = 'active'
             ORDER BY (effective_date IS NULL), effective_date DESC, updated_at DESC, id DESC
             LIMIT 1"
        );

        return is_array( $row ) ? $row : [];
    }
}

if ( ! function_exists( 'metis_board_fetch_dashboard_bylaws_history' ) ) {
    function metis_board_fetch_dashboard_bylaws_history( int $limit = 20 ): array {
        $bylaws_table = Metis_Tables::get( 'board_bylaws' );
        if ( ! function_exists( 'metis_board_table_exists' ) || ! metis_board_table_exists( $bylaws_table ) ) {
            return [];
        }

        $db = metis_db();
        return $db->fetchAll(
            "SELECT id, bylaw_code, title, signed_pdf_url, signed_pdf_title, status, approval_stage, version_number,
                    document_hash, pdf_hash, approved_signature_name, secretary_signature_name, president_signature_name, change_summary,
                    effective_date, approved_at, updated_at
             FROM {$bylaws_table}
             WHERE status IN ('active', 'archived')
             ORDER BY version_number DESC, approved_at DESC, updated_at DESC, id DESC
             LIMIT %d",
            [ max( 1, min( 100, $limit ) ) ]
        ) ?: [];
    }
}

if ( ! function_exists( 'metis_board_fetch_dashboard_bylaws_decision_options' ) ) {
    function metis_board_fetch_dashboard_bylaws_decision_options( int $limit = 500 ): array {
        $decisions_table = Metis_Tables::get( 'board_decisions' );
        $meetings_table = Metis_Tables::get( 'board_meetings' );
        if ( ! function_exists( 'metis_board_table_exists' ) || ! metis_board_table_exists( $decisions_table ) ) {
            return [];
        }

        $db = metis_db();
        return $db->fetchAll(
            "SELECT d.id, d.decision_code, d.title, d.outcome, d.passed, d.meeting_id,
                    m.meeting_code, m.title AS meeting_title, m.meeting_date
             FROM {$decisions_table} d
             LEFT JOIN {$meetings_table} m ON m.id = d.meeting_id
             ORDER BY d.updated_at DESC, d.id DESC
             LIMIT %d",
            [ max( 1, min( 1000, $limit ) ) ]
        ) ?: [];
    }
}

if ( ! function_exists( 'metis_board_fetch_dashboard_bylaws_action_options' ) ) {
    function metis_board_fetch_dashboard_bylaws_action_options( int $limit = 500 ): array {
        $actions_table = Metis_Tables::get( 'board_action_items' );
        $meetings_table = Metis_Tables::get( 'board_meetings' );
        if ( ! function_exists( 'metis_board_table_exists' ) || ! metis_board_table_exists( $actions_table ) ) {
            return [];
        }

        $db = metis_db();
        return $db->fetchAll(
            "SELECT a.id, a.action_code, a.title, a.status, a.meeting_id, a.decision_id,
                    m.meeting_code, m.title AS meeting_title, m.meeting_date
             FROM {$actions_table} a
             LEFT JOIN {$meetings_table} m ON m.id = a.meeting_id
             ORDER BY a.updated_at DESC, a.id DESC
             LIMIT %d",
            [ max( 1, min( 1000, $limit ) ) ]
        ) ?: [];
    }
}

if ( ! function_exists( 'metis_board_fetch_dashboard_people_options' ) ) {
    function metis_board_fetch_dashboard_people_options(): array {
        $db = metis_db();
        $people_table = Metis_Tables::get( 'people' );

        return $db->fetchAll(
            "SELECT id, pid, display_name, email
             FROM {$people_table}
             WHERE status = 'active' AND (is_board = 1 OR is_staff = 1)
             ORDER BY display_name ASC"
        ) ?: [];
    }
}

if ( ! function_exists( 'metis_board_fetch_dashboard_meeting_options' ) ) {
    function metis_board_fetch_dashboard_meeting_options( int $limit = 500 ): array {
        $db = metis_db();
        $meetings_table = Metis_Tables::get( 'board_meetings' );

        return $db->fetchAll(
            "SELECT id, meeting_code, title, meeting_date
             FROM {$meetings_table}
             ORDER BY meeting_date DESC
             LIMIT %d",
            [ max( 1, $limit ) ]
        ) ?: [];
    }
}

if ( ! function_exists( 'metis_board_fetch_dashboard_newsletter_lists' ) ) {
    function metis_board_fetch_dashboard_newsletter_lists(): array {
        $newsletter_lists_table = Metis_Tables::get( 'newsletter_lists' );
        if ( ! function_exists( 'metis_board_table_exists' ) || ! metis_board_table_exists( $newsletter_lists_table ) ) {
            return [];
        }

        $db = metis_db();
        return $db->fetchAll(
            "SELECT id, name
             FROM {$newsletter_lists_table}
             WHERE is_active = 1
             ORDER BY name ASC"
        ) ?: [];
    }
}

if ( ! function_exists( 'metis_board_fetch_dashboard_kpis' ) ) {
    function metis_board_fetch_dashboard_kpis(): array {
        $db = metis_db();
        $meetings_table = Metis_Tables::get( 'board_meetings' );
        $actions_table = Metis_Tables::get( 'board_action_items' );
        $committees_table = Metis_Tables::get( 'board_committees' );
        $compliance_table = Metis_Tables::get( 'board_compliance' );
        $decisions_table = Metis_Tables::get( 'board_decisions' );
        $now = metis_current_time( 'mysql' );
        $today = metis_current_time( 'Y-m-d' );

        return [
            'total_meetings' => (int) $db->scalar( "SELECT COUNT(*) FROM {$meetings_table}" ),
            'upcoming_meetings' => (int) $db->scalar( "SELECT COUNT(*) FROM {$meetings_table} WHERE meeting_date >= %s AND status IN ('scheduled','draft')", [ $now ] ),
            'open_action_count' => (int) $db->scalar( "SELECT COUNT(*) FROM {$actions_table} WHERE status <> 'done'" ),
            'committee_count' => (int) $db->scalar( "SELECT COUNT(*) FROM {$committees_table} WHERE is_active = 1" ),
            'compliance_overdue' => (int) $db->scalar( "SELECT COUNT(*) FROM {$compliance_table} WHERE status <> 'completed' AND due_date IS NOT NULL AND due_date < %s", [ $today ] ),
            'decision_count' => (int) $db->scalar( "SELECT COUNT(*) FROM {$decisions_table}" ),
        ];
    }
}
