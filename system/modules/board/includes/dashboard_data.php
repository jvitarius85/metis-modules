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
