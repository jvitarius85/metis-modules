<?php
declare(strict_types=1);

namespace Metis\Core\Services;

final class ProcessRunner {
    /**
     * @param array<int,string> $command
     * @return array{exit_code:int,stdout:string,stderr:string}
     */
    public function run( array $command, ?string $cwd = null, array $context = [], int $timeoutSeconds = 0 ): array {
        $validated = $this->validateCommand( $command );
        if ( $validated === [] ) {
            return [ 'exit_code' => 1, 'stdout' => '', 'stderr' => 'Process command is not allowed.' ];
        }

        $contextError = $this->validateExecutionContext( $context );
        if ( $contextError !== '' ) {
            $this->audit( 'process_execution_rejected', $validated, $this->workingDirectory( $cwd ) ?: '', $context + [ 'reason' => $contextError ], 'blocked' );
            return [ 'exit_code' => 1, 'stdout' => '', 'stderr' => $contextError ];
        }

        if ( ! $this->contextAllowsExecution( $context ) ) {
            $this->audit( 'process_execution_rejected', $validated, $this->workingDirectory( $cwd ) ?: '', $context + [ 'reason' => 'permission_denied' ], 'blocked' );
            return [ 'exit_code' => 1, 'stdout' => '', 'stderr' => 'Process permission context denied execution.' ];
        }

        if ( ! \function_exists( 'proc_open' ) ) {
            return [ 'exit_code' => 1, 'stdout' => '', 'stderr' => 'proc_open unavailable' ];
        }

        $workingDirectory = $this->workingDirectory( $cwd );
        if ( $workingDirectory === '' ) {
            return [ 'exit_code' => 1, 'stdout' => '', 'stderr' => 'Process working directory is invalid.' ];
        }

        $this->audit( 'process_execution_started', $validated, $workingDirectory, $context, 'attempted' );

        $descriptors = [
            0 => [ 'pipe', 'r' ],
            1 => [ 'pipe', 'w' ],
            2 => [ 'pipe', 'w' ],
        ];
        $process = @\proc_open( $validated, $descriptors, $pipes, $workingDirectory );
        if ( ! \is_resource( $process ) ) {
            $this->audit( 'process_execution_failed', $validated, $workingDirectory, $context, 'failed' );
            return [ 'exit_code' => 1, 'stdout' => '', 'stderr' => 'proc_open failed' ];
        }

        \fclose( $pipes[0] );
        $stdout = '';
        $stderr = '';
        $timedOut = false;
        $startedAt = \time();

        if ( $timeoutSeconds > 0 ) {
            \stream_set_blocking( $pipes[1], false );
            \stream_set_blocking( $pipes[2], false );
            while ( true ) {
                $status = \proc_get_status( $process );
                $stdout .= (string) \stream_get_contents( $pipes[1] );
                $stderr .= (string) \stream_get_contents( $pipes[2] );

                if ( ! (bool) ( $status['running'] ?? false ) ) {
                    break;
                }

                if ( ( \time() - $startedAt ) >= $timeoutSeconds ) {
                    $timedOut = true;
                    \proc_terminate( $process, 9 );
                    break;
                }

                \usleep( 50000 );
            }
        } else {
            $stdout = \stream_get_contents( $pipes[1] );
            $stderr = \stream_get_contents( $pipes[2] );
        }

        \fclose( $pipes[1] );
        \fclose( $pipes[2] );
        $exitCode = \proc_close( $process );

        $result = [
            'exit_code' => $timedOut ? 124 : ( \is_int( $exitCode ) ? $exitCode : 1 ),
            'stdout' => \is_string( $stdout ) ? $stdout : '',
            'stderr' => ( \is_string( $stderr ) ? $stderr : '' ) . ( $timedOut ? "\nProcess timed out." : '' ),
        ];

        $this->audit(
            $timedOut ? 'process_execution_timeout' : ( $result['exit_code'] === 0 ? 'process_execution_completed' : 'process_execution_failed' ),
            $validated,
            $workingDirectory,
            $context + [ 'exit_code' => $result['exit_code'] ],
            $timedOut ? 'blocked' : ( $result['exit_code'] === 0 ? 'completed' : 'failed' )
        );

        return $result;
    }

    /** @param array<int,string> $command @return array<int,string> */
    private function validateCommand( array $command ): array {
        $validated = [];
        foreach ( $command as $arg ) {
            if ( ! \is_string( $arg ) ) {
                return [];
            }
            $arg = trim( $arg );
            if ( $arg === '' || str_contains( $arg, "\0" ) ) {
                return [];
            }
            $validated[] = $arg;
        }

        return $validated;
    }

    private function workingDirectory( ?string $cwd ): string {
        $candidate = $cwd;
        if ( $candidate === null || trim( $candidate ) === '' ) {
            $candidate = \defined( 'METIS_PATH' ) ? (string) \METIS_PATH : \dirname( __DIR__, 5 );
        }

        $real = \realpath( $candidate );
        return \is_string( $real ) && \is_dir( $real ) ? $real : '';
    }

    private function validateExecutionContext( array $context ): string {
        foreach ( [ 'security_context', 'audit_context', 'permission_context' ] as $required ) {
            if ( ! isset( $context[ $required ] ) || ! \is_array( $context[ $required ] ) || $context[ $required ] === [] ) {
                return 'Process execution requires explicit ' . $required . '.';
            }
        }

        $security = (array) $context['security_context'];
        $audit = (array) $context['audit_context'];
        $permission = (array) $context['permission_context'];

        if ( trim( (string) ( $security['operation'] ?? '' ) ) === '' || trim( (string) ( $security['source'] ?? '' ) ) === '' ) {
            return 'Process security context requires operation and source.';
        }

        if ( trim( (string) ( $audit['event'] ?? '' ) ) === '' ) {
            return 'Process audit context requires an event.';
        }

        if ( trim( (string) ( $permission['permission'] ?? $permission['capability'] ?? '' ) ) === '' ) {
            return 'Process permission context requires a permission.';
        }

        return '';
    }

    private function contextAllowsExecution( array $context ): bool {
        $permission = (array) ( $context['permission_context'] ?? [] );
        $capability = trim( (string) ( $permission['permission'] ?? $permission['capability'] ?? '' ) );
        $preauthorized = ! empty( $permission['preauthorized'] ) && trim( (string) ( $permission['authorization_source'] ?? '' ) ) !== '';

        if ( isset( $permission['enforce'] ) && $permission['enforce'] === false ) {
            return $preauthorized;
        }

        if ( \function_exists( 'metis_security_user_can' ) && $capability !== '' ) {
            return (bool) \metis_security_user_can( $capability );
        }

        return $preauthorized;
    }

    /** @param array<int,string> $command */
    private function audit( string $event, array $command, string $cwd, array $context, string $outcome ): void {
        $payload = [
            'command' => $command[0] ?? '',
            'argc' => \count( $command ),
            'cwd' => $cwd,
            'context' => $this->redactContext( $context ),
            'outcome' => $outcome,
        ];

        if ( \function_exists( 'metis_audit_log_activity' ) ) {
            try {
                \metis_audit_log_activity( $event, [
                    'module' => 'system',
                    'resource' => [ 'type' => 'process', 'id' => (string) ( $command[0] ?? '' ) ],
                    'context' => $payload,
                ] );
                return;
            } catch ( \Throwable $e ) {
                if ( \class_exists( '\Metis_Logger' ) ) {
                    \Metis_Logger::warn( 'Process audit failed', [ 'event' => $event, 'error' => $e->getMessage() ] );
                }
            }
        }

        if ( \class_exists( '\Metis_Logger' ) ) {
            \Metis_Logger::info( $event, $payload );
        }
    }

    private function redactContext( array $context ): array {
        $redacted = [];
        foreach ( $context as $key => $value ) {
            $keyString = \strtolower( (string) $key );
            if ( \preg_match( '/secret|token|password|credential|key/', $keyString ) === 1 ) {
                $redacted[ $key ] = '[redacted]';
                continue;
            }

            $redacted[ $key ] = \is_array( $value ) ? $this->redactContext( $value ) : $value;
        }

        return $redacted;
    }
}
