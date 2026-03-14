<?php
declare(strict_types=1);

namespace Metis\Hermes;

use Metis\Modules\Hermes\SchemaManager;

final class HermesRepository {
    public function ensureSession( int $user_id, string $session_code = '', string $title = 'Hermes Session' ): array {
        SchemaManager::ensureSchema();

        if ( $session_code !== '' ) {
            $session = $this->findSessionByCode( $session_code );
            if ( $session !== null ) {
                return $session;
            }
        }

        global $wpdb;

        $table        = \Metis_Tables::get( 'hermes_sessions' );
        $session_code = $session_code !== '' ? $session_code : $this->generateCode( 'HMS' );
        $wpdb->insert(
            $table,
            [
                'session_code' => $session_code,
                'user_id'      => $user_id > 0 ? $user_id : null,
                'title'        => $title,
                'status'       => 'open',
                'last_intent'  => null,
            ],
            [ '%s', '%d', '%s', '%s', '%s' ]
        );

        return $this->findSessionByCode( $session_code ) ?? [];
    }

    public function findSessionByCode( string $session_code ): ?array {
        SchemaManager::ensureSchema();
        global $wpdb;

        $row = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT * FROM ' . \Metis_Tables::get( 'hermes_sessions' ) . ' WHERE session_code = %s LIMIT 1',
                $session_code
            ),
            ARRAY_A
        );

        return \is_array( $row ) ? $row : null;
    }

    public function touchSession( int $session_id, string $intent = '', string $title = '' ): void {
        SchemaManager::ensureSchema();
        global $wpdb;

        $payload = [ 'updated_at' => \current_time( 'mysql' ) ];
        if ( $intent !== '' ) {
            $payload['last_intent'] = $intent;
        }
        if ( $title !== '' ) {
            $payload['title'] = $title;
        }

        $wpdb->update(
            \Metis_Tables::get( 'hermes_sessions' ),
            $payload,
            [ 'id' => $session_id ],
            array_fill( 0, count( $payload ), '%s' ),
            [ '%d' ]
        );
    }

    public function recentSessions( int $user_id, int $limit = 8 ): array {
        SchemaManager::ensureSchema();
        global $wpdb;

        return $wpdb->get_results(
            $wpdb->prepare(
                'SELECT id, session_code, title, status, last_intent, updated_at
                 FROM ' . \Metis_Tables::get( 'hermes_sessions' ) . '
                 WHERE user_id = %d
                 ORDER BY updated_at DESC, id DESC
                 LIMIT %d',
                $user_id,
                max( 1, min( 20, $limit ) )
            ),
            ARRAY_A
        ) ?: [];
    }

    public function sessionMessages( int $session_id, int $limit = 40 ): array {
        SchemaManager::ensureSchema();
        global $wpdb;

        return $wpdb->get_results(
            $wpdb->prepare(
                'SELECT id, role_name, content, metadata_json, created_at
                 FROM ' . \Metis_Tables::get( 'hermes_messages' ) . '
                 WHERE session_id = %d
                 ORDER BY id DESC
                 LIMIT %d',
                $session_id,
                max( 1, min( 200, $limit ) )
            ),
            ARRAY_A
        ) ?: [];
    }

    public function saveMessage( int $session_id, string $role_name, string $content, array $metadata = [] ): array {
        SchemaManager::ensureSchema();
        global $wpdb;

        $wpdb->insert(
            \Metis_Tables::get( 'hermes_messages' ),
            [
                'session_id'    => $session_id,
                'role_name'     => $role_name,
                'message_hash'  => hash( 'sha256', $role_name . '|' . $content ),
                'content'       => $content,
                'metadata_json' => $this->encodeJson( $metadata ),
            ],
            [ '%d', '%s', '%s', '%s', '%s' ]
        );

        $this->touchSession( $session_id );

        return [
            'id'         => (int) $wpdb->insert_id,
            'session_id' => $session_id,
            'role_name'  => $role_name,
            'content'    => $content,
            'metadata'   => $metadata,
        ];
    }

    public function createAction( int $session_id, int $message_id, string $action_type, string $title, array $payload, array $preview ): array {
        SchemaManager::ensureSchema();
        global $wpdb;

        $action_code = $this->generateCode( 'HAC' );
        $wpdb->insert(
            \Metis_Tables::get( 'hermes_actions' ),
            [
                'session_id'       => $session_id,
                'message_id'       => $message_id > 0 ? $message_id : null,
                'action_code'      => $action_code,
                'action_type'      => $action_type,
                'title'            => $title,
                'approval_status'  => 'pending',
                'payload_json'     => $this->encodeJson( $payload ),
                'preview_json'     => $this->encodeJson( $preview ),
            ],
            [ '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s' ]
        );

        return $this->getActionByCode( $action_code ) ?? [];
    }

    public function pendingActionsForUser( int $user_id, int $limit = 12 ): array {
        SchemaManager::ensureSchema();
        global $wpdb;

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT a.action_code, a.action_type, a.title, a.preview_json, a.created_at, s.session_code
                 FROM ' . \Metis_Tables::get( 'hermes_actions' ) . ' a
                 INNER JOIN ' . \Metis_Tables::get( 'hermes_sessions' ) . ' s ON s.id = a.session_id
                 WHERE s.user_id = %d
                   AND a.approval_status = %s
                 ORDER BY a.id DESC
                 LIMIT %d',
                $user_id,
                'pending',
                max( 1, min( 50, $limit ) )
            ),
            ARRAY_A
        ) ?: [];

        foreach ( $rows as &$row ) {
            $row['preview'] = $this->decodeJson( (string) ( $row['preview_json'] ?? '' ) );
            unset( $row['preview_json'] );
        }

        return $rows;
    }

    public function getActionByCode( string $action_code ): ?array {
        SchemaManager::ensureSchema();
        global $wpdb;

        $row = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT * FROM ' . \Metis_Tables::get( 'hermes_actions' ) . ' WHERE action_code = %s LIMIT 1',
                $action_code
            ),
            ARRAY_A
        );

        return \is_array( $row ) ? $this->hydrateAction( $row ) : null;
    }

    public function approveAction( string $action_code, int $user_id, string $note = '' ): ?array {
        SchemaManager::ensureSchema();
        global $wpdb;

        $wpdb->update(
            \Metis_Tables::get( 'hermes_actions' ),
            [
                'approval_status' => 'approved',
                'approved_by'     => $user_id > 0 ? $user_id : null,
                'approval_note'   => $note !== '' ? $note : null,
            ],
            [ 'action_code' => $action_code ],
            [ '%s', '%d', '%s' ],
            [ '%s' ]
        );

        return $this->getActionByCode( $action_code );
    }

    public function markActionExecuted( string $action_code, array $result ): ?array {
        SchemaManager::ensureSchema();
        global $wpdb;

        $wpdb->update(
            \Metis_Tables::get( 'hermes_actions' ),
            [
                'approval_status' => 'executed',
                'executed_at'     => \current_time( 'mysql' ),
                'result_json'     => $this->encodeJson( $result ),
            ],
            [ 'action_code' => $action_code ],
            [ '%s', '%s', '%s' ],
            [ '%s' ]
        );

        return $this->getActionByCode( $action_code );
    }

    public function saveReport( string $report_type, string $subject_key, array $summary, ?int $session_id = null, string $status = 'ready' ): array {
        SchemaManager::ensureSchema();
        global $wpdb;

        $report_code = $this->generateCode( 'HRP' );
        $wpdb->insert(
            \Metis_Tables::get( 'hermes_reports' ),
            [
                'report_code'   => $report_code,
                'session_id'    => $session_id,
                'report_type'   => $report_type,
                'subject_key'   => $subject_key !== '' ? $subject_key : null,
                'status'        => $status,
                'summary_json'  => $this->encodeJson( $summary ),
            ],
            [ '%s', '%d', '%s', '%s', '%s', '%s' ]
        );

        return [
            'report_code'  => $report_code,
            'report_type'  => $report_type,
            'subject_key'  => $subject_key,
            'status'       => $status,
            'summary'      => $summary,
        ];
    }

    public function recentReports( int $limit = 8 ): array {
        SchemaManager::ensureSchema();
        global $wpdb;

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT report_code, report_type, subject_key, status, summary_json, updated_at
                 FROM ' . \Metis_Tables::get( 'hermes_reports' ) . '
                 ORDER BY updated_at DESC, id DESC
                 LIMIT %d',
                max( 1, min( 50, $limit ) )
            ),
            ARRAY_A
        ) ?: [];

        foreach ( $rows as &$row ) {
            $row['summary'] = $this->decodeJson( (string) ( $row['summary_json'] ?? '' ) );
            unset( $row['summary_json'] );
        }

        return $rows;
    }

    public function upsertMemory( string $memory_key, string $memory_type, string $scope_key, array $contents ): void {
        SchemaManager::ensureSchema();
        global $wpdb;

        $table = \Metis_Tables::get( 'hermes_memory' );
        $existing = $wpdb->get_var(
            $wpdb->prepare( "SELECT id FROM {$table} WHERE memory_key = %s LIMIT 1", $memory_key )
        );

        $payload = [
            'memory_key'    => $memory_key,
            'memory_type'   => $memory_type,
            'scope_key'     => $scope_key !== '' ? $scope_key : null,
            'contents_json' => $this->encodeJson( $contents ),
        ];

        if ( $existing ) {
            $wpdb->update( $table, $payload, [ 'id' => (int) $existing ], [ '%s', '%s', '%s', '%s' ], [ '%d' ] );
            return;
        }

        $wpdb->insert( $table, $payload, [ '%s', '%s', '%s', '%s' ] );
    }

    public function recentMemory( string $scope_key = '', int $limit = 6 ): array {
        SchemaManager::ensureSchema();
        global $wpdb;

        if ( $scope_key !== '' ) {
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    'SELECT memory_key, memory_type, scope_key, contents_json, updated_at
                     FROM ' . \Metis_Tables::get( 'hermes_memory' ) . '
                     WHERE scope_key = %s
                     ORDER BY updated_at DESC, id DESC
                     LIMIT %d',
                    $scope_key,
                    max( 1, min( 20, $limit ) )
                ),
                ARRAY_A
            ) ?: [];
        } else {
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    'SELECT memory_key, memory_type, scope_key, contents_json, updated_at
                     FROM ' . \Metis_Tables::get( 'hermes_memory' ) . '
                     ORDER BY updated_at DESC, id DESC
                     LIMIT %d',
                    max( 1, min( 20, $limit ) )
                ),
                ARRAY_A
            ) ?: [];
        }

        foreach ( $rows as &$row ) {
            $row['contents'] = $this->decodeJson( (string) ( $row['contents_json'] ?? '' ) );
            unset( $row['contents_json'] );
        }

        return $rows;
    }

    public function queueSummary(): array {
        global $wpdb;
        $table = \Metis_Tables::get( 'job_queue' );

        $row = $wpdb->get_row(
            "SELECT
                SUM(CASE WHEN status = 'queued' THEN 1 ELSE 0 END) AS queued_count,
                SUM(CASE WHEN status = 'processing' THEN 1 ELSE 0 END) AS processing_count,
                SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) AS failed_count
             FROM {$table}",
            ARRAY_A
        );

        return \is_array( $row ) ? $row : [ 'queued_count' => 0, 'processing_count' => 0, 'failed_count' => 0 ];
    }

    public function hydrateAction( array $row ): array {
        $row['payload'] = $this->decodeJson( (string) ( $row['payload_json'] ?? '' ) );
        $row['preview'] = $this->decodeJson( (string) ( $row['preview_json'] ?? '' ) );
        $row['result']  = $this->decodeJson( (string) ( $row['result_json'] ?? '' ) );
        unset( $row['payload_json'], $row['preview_json'], $row['result_json'] );
        return $row;
    }

    private function generateCode( string $prefix ): string {
        return $prefix . strtoupper( substr( bin2hex( random_bytes( 10 ) ), 0, 20 ) );
    }

    private function encodeJson( array $payload ): string {
        return \function_exists( 'metis_json_encode' )
            ? (string) \metis_json_encode( $payload )
            : (string) json_encode( $payload );
    }

    private function decodeJson( string $payload ): array {
        $decoded = json_decode( $payload, true );
        return \is_array( $decoded ) ? $decoded : [];
    }
}
