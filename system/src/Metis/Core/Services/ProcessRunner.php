<?php
declare(strict_types=1);

namespace Metis\Core\Services;

final class ProcessRunner {
    /**
     * @param array<int,string> $command
     * @return array{exit_code:int,stdout:string,stderr:string}
     */
    public function run( array $command, ?string $cwd = null, array $context = [] ): array {
        $validated = $this->validateCommand( $command );
        if ( $validated === [] ) {
            return [ 'exit_code' => 1, 'stdout' => '', 'stderr' => 'Process command is not allowed.' ];
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
        $stdout = \stream_get_contents( $pipes[1] );
        $stderr = \stream_get_contents( $pipes[2] );
        \fclose( $pipes[1] );
        \fclose( $pipes[2] );
        $exitCode = \proc_close( $process );

        $result = [
            'exit_code' => \is_int( $exitCode ) ? $exitCode : 1,
            'stdout' => \is_string( $stdout ) ? $stdout : '',
            'stderr' => \is_string( $stderr ) ? $stderr : '',
        ];

        $this->audit(
            $result['exit_code'] === 0 ? 'process_execution_completed' : 'process_execution_failed',
            $validated,
            $workingDirectory,
            $context + [ 'exit_code' => $result['exit_code'] ],
            $result['exit_code'] === 0 ? 'completed' : 'failed'
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

    /** @param array<int,string> $command */
    private function audit( string $event, array $command, string $cwd, array $context, string $outcome ): void {
        $payload = [
            'command' => $command[0] ?? '',
            'argc' => \count( $command ),
            'cwd' => $cwd,
            'context' => $context,
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
}
