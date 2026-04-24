<?php
declare(strict_types=1);

namespace Metis\Hermes;

/**
 * HermesDebugLogger
 *
 * Full-trace debug logger for Hermes intent → entity → attribute → action
 * pipeline. Enabled via HERMES_DEBUG=true constant or metis_option.
 *
 * Writes structured JSON entries to /storage/logs/hermes.log.
 * Admin-only: non-admin users never receive debug output.
 *
 * Log entry shape:
 * {
 *   "timestamp": "...",
 *   "input": "...",
 *   "intent": "...",
 *   "entity": "...",
 *   "resolved_entity_id": 42,
 *   "attribute": "...",
 *   "action": "...",
 *   "parameters": {},
 *   "permissions": "allowed|denied",
 *   "service": "...",
 *   "status": "success|error",
 *   "detail": "..."
 * }
 */
final class HermesDebugLogger {

    private string $logPath;
    private bool   $enabled;

    public function __construct( ?string $log_path = null ) {
        $root          = defined( 'METIS_ROOT' ) ? rtrim( (string) METIS_ROOT, '/' ) : '';
        $this->logPath = $log_path ?? ( $root . '/storage/logs/hermes.log' );
        $this->enabled = $this->detectEnabled();
    }

    // ------------------------------------------------------------------
    // Public API
    // ------------------------------------------------------------------

    /**
     * Log a complete pipeline trace.
     *
     * @param array $trace {
     *   input, intent, entity, resolved_entity_id, attribute,
     *   action, parameters, permissions, service, status, detail
     * }
     */
    public function trace( array $trace ): void {
        if ( ! $this->enabled ) {
            return;
        }

        $entry = array_merge( [
            'timestamp'          => gmdate( 'Y-m-d\TH:i:s\Z' ),
            'input'              => '',
            'intent'             => '',
            'entity'             => '',
            'resolved_entity_id' => null,
            'attribute'          => '',
            'action'             => '',
            'parameters'         => [],
            'permissions'        => '',
            'service'            => '',
            'status'             => '',
            'detail'             => '',
        ], $trace );

        $this->write( $entry );
    }

    /** Log a query entry point. */
    public function query( string $input, string $intent, array $extra = [] ): void {
        $this->trace( array_merge( [
            'input'  => $input,
            'intent' => $intent,
            'status' => 'routing',
        ], $extra ) );
    }

    /** Log entity resolution result. */
    public function entityResolved( string $input, string $entity_type, int $id, string $subject ): void {
        $this->trace( [
            'input'              => $input,
            'intent'             => 'entity_resolved',
            'entity'             => $entity_type,
            'resolved_entity_id' => $id,
            'parameters'         => [ 'subject' => $subject ],
            'status'             => 'resolved',
        ] );
    }

    /** Log entity resolution failure. */
    public function entityNotFound( string $input, string $subject, string $error ): void {
        $this->trace( [
            'input'   => $input,
            'intent'  => 'entity_not_found',
            'detail'  => $error,
            'parameters' => [ 'subject' => $subject ],
            'status'  => 'error',
        ] );
    }

    /** Log attribute fetch result. */
    public function attributeFetched( string $input, string $attribute, mixed $value, int $entity_id ): void {
        $this->trace( [
            'input'              => $input,
            'intent'             => 'get_entity_attribute',
            'attribute'          => $attribute,
            'resolved_entity_id' => $entity_id,
            'parameters'         => [ 'value_type' => gettype( $value ) ],
            'status'             => 'success',
        ] );
    }

    /** Log a permission check outcome. */
    public function permission( string $action, string $result, string $reason = '' ): void {
        $this->trace( [
            'action'      => $action,
            'permissions' => $result,
            'detail'      => $reason,
            'status'      => $result === 'allowed' ? 'permission_granted' : 'permission_denied',
        ] );
    }

    /** Log a full structured action execution. */
    public function action( array $context ): void {
        $this->trace( $context );
    }

    /** Returns whether debug logging is active. */
    public function isEnabled(): bool {
        return $this->enabled;
    }

    /** Returns recent log entries (last $limit lines) for admin display. */
    public function recentEntries( int $limit = 50 ): array {
        if ( ! is_file( $this->logPath ) ) {
            return [];
        }

        $lines = array_filter( array_map( 'trim', file( $this->logPath ) ?: [] ) );
        $lines = array_values( array_slice( array_reverse( $lines ), 0, $limit ) );

        $entries = [];
        foreach ( $lines as $line ) {
            $decoded = json_decode( $line, true );
            if ( is_array( $decoded ) ) {
                $entries[] = $decoded;
            }
        }

        return $entries;
    }

    // ------------------------------------------------------------------
    // Private helpers
    // ------------------------------------------------------------------

    private function detectEnabled(): bool {
        if ( defined( 'HERMES_DEBUG' ) && HERMES_DEBUG ) {
            return true;
        }

        if ( function_exists( 'metis_get_option' ) ) {
            return (bool) \metis_get_option( 'hermes_debug_enabled', false );
        }

        return false;
    }

    private function write( array $entry ): void {
        $dir = dirname( $this->logPath );
        if ( ! is_dir( $dir ) ) {
            @mkdir( $dir, 0755, true );
        }

        $line = json_encode( $entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n";
        @file_put_contents( $this->logPath, $line, FILE_APPEND | LOCK_EX );
    }
}
