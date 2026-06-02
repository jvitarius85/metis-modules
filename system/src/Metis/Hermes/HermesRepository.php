<?php
declare(strict_types=1);

namespace Metis\Hermes;

use Metis\Modules\Hermes\SchemaManager;

final class HermesRepository {
    public function purgeExpiredConversationData( int $hours = 24 ): void {
        SchemaManager::ensureSchema();
        $db = $this->db();

        $hours = max( 1, min( 168, $hours ) );
        $cutoff = gmdate( 'Y-m-d H:i:s', time() - ( $hours * 3600 ) );

        $db->execute(
            $db->prepare(
                'DELETE FROM ' . \Metis_Tables::get( 'hermes_messages' ) . ' WHERE created_at < %s',
                $cutoff
            )
        );

        $db->execute(
            $db->prepare(
                'DELETE s FROM ' . \Metis_Tables::get( 'hermes_sessions' ) . ' s
                 LEFT JOIN ' . \Metis_Tables::get( 'hermes_messages' ) . ' m ON m.session_id = s.id
                 WHERE s.updated_at < %s
                   AND m.id IS NULL',
                $cutoff
            )
        );
    }

    public function ensureSession( int $user_id, string $session_code = '', string $title = 'Hermes Session' ): array {
        SchemaManager::ensureSchema();
        $this->purgeExpiredConversationData();

        if ( $session_code !== '' ) {
            $session = $this->findSessionByCode( $session_code );
            if ( $session !== null ) {
                return $session;
            }
        }

        $db = $this->db();

        $table        = \Metis_Tables::get( 'hermes_sessions' );
        $session_code = $session_code !== '' ? $this->normalizeSessionCode( $session_code ) : $this->generateCode( 'HMS' );
        $db->insert(
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

        $session = $this->findSessionByCode( $session_code );
        if ( $session !== null ) {
            return $session;
        }

        $fallback_code = $this->generateCode( 'HMS' );
        $db->insert(
            $table,
            [
                'session_code' => $fallback_code,
                'user_id'      => $user_id > 0 ? $user_id : null,
                'title'        => $title,
                'status'       => 'open',
                'last_intent'  => null,
            ],
            [ '%s', '%d', '%s', '%s', '%s' ]
        );

        return $this->findSessionByCode( $fallback_code ) ?? [];
    }

    public function findSessionByCode( string $session_code ): ?array {
        SchemaManager::ensureSchema();
        $db = $this->db();

        $row = $db->fetchOne(
            'SELECT * FROM ' . \Metis_Tables::get( 'hermes_sessions' ) . ' WHERE session_code = %s LIMIT 1',
            [ $session_code ]
        );

        return \is_array( $row ) ? $row : null;
    }

    public function touchSession( int $session_id, string $intent = '', string $title = '' ): void {
        SchemaManager::ensureSchema();
        $db = $this->db();

        $payload = [ 'updated_at' => \metis_current_time( 'mysql' ) ];
        if ( $intent !== '' ) {
            $payload['last_intent'] = $intent;
        }
        if ( $title !== '' ) {
            $payload['title'] = $title;
        }

        $db->update(
            \Metis_Tables::get( 'hermes_sessions' ),
            $payload,
            [ 'id' => $session_id ],
            array_fill( 0, count( $payload ), '%s' ),
            [ '%d' ]
        );
    }

    public function recentSessions( int $user_id, int $limit = 8 ): array {
        SchemaManager::ensureSchema();
        $db = $this->db();

        return $db->fetchAll(
            'SELECT id, session_code, title, status, last_intent, updated_at
             FROM ' . \Metis_Tables::get( 'hermes_sessions' ) . '
             WHERE user_id = %d
             ORDER BY updated_at DESC, id DESC
             LIMIT %d',
            [ $user_id, max( 1, min( 20, $limit ) ) ]
        );
    }

    public function sessionMessages( int $session_id, int $limit = 40 ): array {
        SchemaManager::ensureSchema();
        $db = $this->db();

        $rows = $db->fetchAll(
            'SELECT id, role_name, content, metadata_json, created_at
             FROM ' . \Metis_Tables::get( 'hermes_messages' ) . '
             WHERE session_id = %d
             ORDER BY id DESC
             LIMIT %d',
            [ $session_id, max( 1, min( 200, $limit ) ) ]
        );

        foreach ( $rows as &$row ) {
            $row['metadata'] = $this->decodeJson( (string) ( $row['metadata_json'] ?? '' ) );
            unset( $row['metadata_json'] );
        }

        return array_reverse( $rows );
    }

    public function latestSessionForUser( int $user_id ): ?array {
        SchemaManager::ensureSchema();
        $this->purgeExpiredConversationData();
        $db = $this->db();

        $row = $db->fetchOne(
            'SELECT id, session_code, title, status, last_intent, updated_at
             FROM ' . \Metis_Tables::get( 'hermes_sessions' ) . '
             WHERE user_id = %d
             ORDER BY updated_at DESC, id DESC
             LIMIT 1',
            [ $user_id ]
        );

        return is_array( $row ) ? $row : null;
    }

    public function saveMessage( int $session_id, string $role_name, string $content, array $metadata = [] ): array {
        SchemaManager::ensureSchema();
        $db = $this->db();

        $db->insert(
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
            'id'         => $db->lastInsertId(),
            'session_id' => $session_id,
            'role_name'  => $role_name,
            'content'    => $content,
            'metadata'   => $metadata,
        ];
    }

    public function createAction( int $session_id, int $message_id, string $action_type, string $title, array $payload, array $preview ): array {
        SchemaManager::ensureSchema();
        $db = $this->db();

        $action_code = $this->generateCode( 'HAC' );
        $db->insert(
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
        $db = $this->db();

        $rows = $db->fetchAll(
            'SELECT a.action_code, a.action_type, a.title, a.preview_json, a.created_at, s.session_code
             FROM ' . \Metis_Tables::get( 'hermes_actions' ) . ' a
             INNER JOIN ' . \Metis_Tables::get( 'hermes_sessions' ) . ' s ON s.id = a.session_id
             WHERE s.user_id = %d
               AND a.approval_status = %s
             ORDER BY a.id DESC
             LIMIT %d',
            [ $user_id, 'pending', max( 1, min( 50, $limit ) ) ]
        );

        foreach ( $rows as &$row ) {
            $row['preview'] = $this->decodeJson( (string) ( $row['preview_json'] ?? '' ) );
            unset( $row['preview_json'] );
        }

        return $rows;
    }

    public function getActionByCode( string $action_code ): ?array {
        SchemaManager::ensureSchema();
        $db = $this->db();

        $row = $db->fetchOne(
            'SELECT * FROM ' . \Metis_Tables::get( 'hermes_actions' ) . ' WHERE action_code = %s LIMIT 1',
            [ $action_code ]
        );

        return \is_array( $row ) ? $this->hydrateAction( $row ) : null;
    }

    public function latestPendingActionForSession( int $session_id ): ?array {
        SchemaManager::ensureSchema();
        $db = $this->db();

        $row = $db->fetchOne(
            'SELECT * FROM ' . \Metis_Tables::get( 'hermes_actions' ) . '
             WHERE session_id = %d
               AND approval_status = %s
             ORDER BY created_at DESC, id DESC
             LIMIT 1',
            [ $session_id, 'pending' ]
        );

        return \is_array( $row ) ? $this->hydrateAction( $row ) : null;
    }

    public function approveAction( string $action_code, int $user_id, string $note = '' ): ?array {
        SchemaManager::ensureSchema();
        $db = $this->db();

        $db->update(
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

    public function cancelAction( string $action_code, int $user_id = 0, string $note = '' ): ?array {
        SchemaManager::ensureSchema();
        $db = $this->db();

        $db->update(
            \Metis_Tables::get( 'hermes_actions' ),
            [
                'approval_status' => 'cancelled',
                'approved_by'     => $user_id > 0 ? $user_id : null,
                'approval_note'   => $note !== '' ? $note : null,
            ],
            [ 'action_code' => $action_code ],
            [ '%s', '%d', '%s' ],
            [ '%s' ]
        );

        return $this->getActionByCode( $action_code );
    }

    public function expireAction( string $action_code, string $note = '' ): ?array {
        SchemaManager::ensureSchema();
        $db = $this->db();

        $db->update(
            \Metis_Tables::get( 'hermes_actions' ),
            [
                'approval_status' => 'expired',
                'approval_note'   => $note !== '' ? $note : null,
            ],
            [ 'action_code' => $action_code ],
            [ '%s', '%s' ],
            [ '%s' ]
        );

        return $this->getActionByCode( $action_code );
    }

    public function markActionExecuted( string $action_code, array $result ): ?array {
        SchemaManager::ensureSchema();
        $db = $this->db();

        $db->update(
            \Metis_Tables::get( 'hermes_actions' ),
            [
                'approval_status' => 'executed',
                'executed_at'     => \metis_current_time( 'mysql' ),
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
        $db = $this->db();

        $report_code = $this->generateCode( 'HRP' );
        $db->insert(
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
        $db = $this->db();

        $rows = $db->fetchAll(
            'SELECT report_code, report_type, subject_key, status, summary_json, updated_at
             FROM ' . \Metis_Tables::get( 'hermes_reports' ) . '
             ORDER BY updated_at DESC, id DESC
             LIMIT %d',
            [ max( 1, min( 50, $limit ) ) ]
        );

        foreach ( $rows as &$row ) {
            $row['summary'] = $this->decodeJson( (string) ( $row['summary_json'] ?? '' ) );
            unset( $row['summary_json'] );
        }

        return $rows;
    }

    public function logCommandTrace( array $entry ): void {
        SchemaManager::ensureSchema();
        $db = $this->db();

        $db->insert(
            \Metis_Tables::get( 'hermes_command_logs' ),
            [
                'session_code' => (string) ( $entry['session_code'] ?? '' ) !== '' ? (string) $entry['session_code'] : null,
                'user_id' => ! empty( $entry['user_id'] ) ? (int) $entry['user_id'] : null,
                'raw_input' => (string) ( $entry['raw_input'] ?? '' ) !== '' ? (string) $entry['raw_input'] : null,
                'normalized_input' => (string) ( $entry['normalized_input'] ?? '' ) !== '' ? (string) $entry['normalized_input'] : null,
                'selected_intent' => (string) ( $entry['selected_intent'] ?? '' ) !== '' ? (string) $entry['selected_intent'] : null,
                'tool_key' => (string) ( $entry['tool_key'] ?? '' ) !== '' ? (string) $entry['tool_key'] : null,
                'confidence_score' => isset( $entry['confidence_score'] ) ? (float) $entry['confidence_score'] : null,
                'payload_json' => $this->encodeJson( (array) ( $entry['payload'] ?? [] ) ),
                'enclave_request_id' => (string) ( $entry['enclave_request_id'] ?? '' ) !== '' ? (string) $entry['enclave_request_id'] : null,
                'result_json' => $this->encodeJson( (array) ( $entry['result'] ?? [] ) ),
            ],
            [ '%s', '%d', '%s', '%s', '%s', '%s', '%f', '%s', '%s', '%s' ]
        );
    }

    public function recentCommandLogs( string $session_code = '', int $limit = 20 ): array {
        SchemaManager::ensureSchema();
        $db = $this->db();

        $limit = max( 1, min( 100, $limit ) );
        if ( $session_code !== '' ) {
            $rows = $db->fetchAll(
                'SELECT * FROM ' . \Metis_Tables::get( 'hermes_command_logs' ) . ' WHERE session_code = %s ORDER BY id DESC LIMIT %d',
                [ $session_code, $limit ]
            );
        } else {
            $rows = $db->fetchAll(
                'SELECT * FROM ' . \Metis_Tables::get( 'hermes_command_logs' ) . ' ORDER BY id DESC LIMIT %d',
                [ $limit ]
            );
        }

        foreach ( $rows as &$row ) {
            $row['payload'] = $this->decodeJson( (string) ( $row['payload_json'] ?? '' ) );
            $row['result'] = $this->decodeJson( (string) ( $row['result_json'] ?? '' ) );
            unset( $row['payload_json'], $row['result_json'] );
        }

        return $rows;
    }

    public function logHelpIssueResolution( array $entry ): void {
        SchemaManager::ensureSchema();
        $db = $this->db();

        $db->insert(
            \Metis_Tables::get( 'hermes_help_issue_logs' ),
            [
                'session_code' => (string) ( $entry['session_code'] ?? '' ) !== '' ? (string) $entry['session_code'] : null,
                'user_id' => ! empty( $entry['user_id'] ) ? (int) $entry['user_id'] : null,
                'raw_message' => (string) ( $entry['raw_message'] ?? '' ) !== '' ? (string) $entry['raw_message'] : null,
                'normalized_issue' => (string) ( $entry['normalized_issue'] ?? '' ) !== '' ? (string) $entry['normalized_issue'] : null,
                'classification' => (string) ( $entry['classification'] ?? '' ) !== '' ? (string) $entry['classification'] : null,
                'module_key' => (string) ( $entry['module_key'] ?? '' ) !== '' ? (string) $entry['module_key'] : null,
                'module_label' => (string) ( $entry['module_label'] ?? '' ) !== '' ? (string) $entry['module_label'] : null,
                'action_key' => (string) ( $entry['action_key'] ?? '' ) !== '' ? (string) $entry['action_key'] : null,
                'confidence_label' => (string) ( $entry['confidence_label'] ?? '' ) !== '' ? (string) $entry['confidence_label'] : null,
                'confidence_score' => isset( $entry['confidence_score'] ) ? (float) $entry['confidence_score'] : null,
                'help_articles_json' => $this->encodeJson( (array) ( $entry['help_articles'] ?? [] ) ),
                'diagnostics_json' => $this->encodeJson( (array) ( $entry['diagnostics'] ?? [] ) ),
                'proposed_actions_json' => $this->encodeJson( (array) ( $entry['proposed_actions'] ?? [] ) ),
                'executed_actions_json' => $this->encodeJson( (array) ( $entry['executed_actions'] ?? [] ) ),
                'result_json' => $this->encodeJson( (array) ( $entry['result'] ?? [] ) ),
            ],
            [ '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%f', '%s', '%s', '%s', '%s', '%s' ]
        );
    }

    public function helpIssueCoverage( int $limit = 25 ): array {
        SchemaManager::ensureSchema();
        $db = $this->db();
        $limit = max( 1, min( 100, $limit ) );
        $logsTable = \Metis_Tables::get( 'hermes_help_issue_logs' );
        $articlesTable = \Metis_Tables::get( 'help_articles' );

        $frequent = $db->fetchAll(
            "SELECT normalized_issue, COUNT(*) AS hits, MAX(created_at) AS last_seen
             FROM {$logsTable}
             WHERE normalized_issue IS NOT NULL AND normalized_issue <> ''
             GROUP BY normalized_issue
             ORDER BY hits DESC, last_seen DESC
             LIMIT %d",
            [ $limit ]
        );

        $unresolved = $db->fetchAll(
            "SELECT normalized_issue, classification, confidence_label, created_at
             FROM {$logsTable}
             WHERE (confidence_label IN ('low', 'none') OR confidence_label IS NULL)
             ORDER BY id DESC
             LIMIT %d",
            [ $limit ]
        );

        $failedMatches = $db->fetchAll(
            "SELECT normalized_issue, module_key, action_key, confidence_label, created_at
             FROM {$logsTable}
             WHERE JSON_EXTRACT(COALESCE(result_json, '{}'), '$.related_articles.0.title') IS NULL
             ORDER BY id DESC
             LIMIT %d",
            [ $limit ]
        );

        $missingTerms = $db->fetchAll(
            "SELECT id, title, slug, module_key, action_key, updated_at
             FROM {$articlesTable}
             WHERE COALESCE(search_terms, '') = ''
             ORDER BY updated_at DESC, id DESC
             LIMIT %d",
            [ $limit ]
        );

        return [
            'frequent_issue_phrases' => $frequent,
            'unresolved_issue_phrases' => $unresolved,
            'low_confidence_classifications' => $unresolved,
            'failed_help_search_matches' => $failedMatches,
            'articles_needing_search_terms' => $missingTerms,
        ];
    }

    public function recentHelpIssueLogs( int $limit = 20 ): array {
        SchemaManager::ensureSchema();
        $db = $this->db();
        $rows = $db->fetchAll(
            'SELECT * FROM ' . \Metis_Tables::get( 'hermes_help_issue_logs' ) . ' ORDER BY id DESC LIMIT %d',
            [ max( 1, min( 100, $limit ) ) ]
        );

        foreach ( $rows as &$row ) {
            $row['help_articles'] = $this->decodeJson( (string) ( $row['help_articles_json'] ?? '' ) );
            $row['diagnostics'] = $this->decodeJson( (string) ( $row['diagnostics_json'] ?? '' ) );
            $row['proposed_actions'] = $this->decodeJson( (string) ( $row['proposed_actions_json'] ?? '' ) );
            $row['executed_actions'] = $this->decodeJson( (string) ( $row['executed_actions_json'] ?? '' ) );
            $row['result'] = $this->decodeJson( (string) ( $row['result_json'] ?? '' ) );
            unset(
                $row['help_articles_json'],
                $row['diagnostics_json'],
                $row['proposed_actions_json'],
                $row['executed_actions_json'],
                $row['result_json']
            );
        }

        return $rows;
    }

    public function upsertMemory( string $memory_key, string $memory_type, string $scope_key, array $contents ): void {
        SchemaManager::ensureSchema();
        $db = $this->db();

        $table = \Metis_Tables::get( 'hermes_memory' );
        $existing = $db->scalar( "SELECT id FROM {$table} WHERE memory_key = %s LIMIT 1", [ $memory_key ] );

        $payload = [
            'memory_key'    => $memory_key,
            'memory_type'   => $memory_type,
            'scope_key'     => $scope_key !== '' ? $scope_key : null,
            'contents_json' => $this->encodeJson( $contents ),
        ];

        if ( $existing ) {
            $db->update( $table, $payload, [ 'id' => (int) $existing ], [ '%s', '%s', '%s', '%s' ], [ '%d' ] );
            return;
        }

        $db->insert( $table, $payload, [ '%s', '%s', '%s', '%s' ] );
    }

    public function recentMemory( string $scope_key = '', int $limit = 6 ): array {
        SchemaManager::ensureSchema();
        $db = $this->db();

        if ( $scope_key !== '' ) {
            $rows = $db->fetchAll(
                'SELECT memory_key, memory_type, scope_key, contents_json, updated_at
                 FROM ' . \Metis_Tables::get( 'hermes_memory' ) . '
                 WHERE scope_key = %s
                 ORDER BY updated_at DESC, id DESC
                 LIMIT %d',
                [ $scope_key, max( 1, min( 20, $limit ) ) ]
            );
        } else {
            $rows = $db->fetchAll(
                'SELECT memory_key, memory_type, scope_key, contents_json, updated_at
                 FROM ' . \Metis_Tables::get( 'hermes_memory' ) . '
                 ORDER BY updated_at DESC, id DESC
                 LIMIT %d',
                [ max( 1, min( 20, $limit ) ) ]
            );
        }

        foreach ( $rows as &$row ) {
            $row['contents'] = $this->decodeJson( (string) ( $row['contents_json'] ?? '' ) );
            unset( $row['contents_json'] );
        }

        return $rows;
    }

    public function queueSummary(): array {
        $db = $this->db();
        $table = \Metis_Tables::get( 'job_queue' );

        $row = $db->fetchOne(
            "SELECT
                SUM(CASE WHEN status = 'queued' THEN 1 ELSE 0 END) AS queued_count,
                SUM(CASE WHEN status = 'processing' THEN 1 ELSE 0 END) AS processing_count,
                SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) AS failed_count
             FROM {$table}"
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

    private function normalizeSessionCode( string $session_code ): string {
        $normalized = strtoupper( preg_replace( '/[^A-Z0-9_-]/i', '', trim( $session_code ) ) ?? '' );
        if ( $normalized === '' ) {
            return $this->generateCode( 'HMS' );
        }

        return substr( $normalized, 0, 32 );
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

    private function db(): \Metis\Services\DatabaseService {
        return \metis_db();
    }
}
