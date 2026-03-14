<?php
declare(strict_types=1);

namespace Metis\Services;

final class LoggerService {
    public function debug( string $message, array $context = [] ): void {
        \Metis_Logger::debug( $message, $context );
    }

    public function info( string $message, array $context = [] ): void {
        \Metis_Logger::info( $message, $context );
    }

    public function warn( string $message, array $context = [] ): void {
        \Metis_Logger::warn( $message, $context );
    }

    public function error( string $message, array $context = [] ): void {
        \Metis_Logger::error( $message, $context );
    }

    public function path(): string {
        return \Metis_Logger::log_path();
    }
}
