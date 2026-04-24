<?php
declare(strict_types=1);

namespace Metis\Core\Error;

final class ErrorClassifier {
    public const USER_ERROR = 'USER_ERROR';
    public const VALIDATION_ERROR = 'VALIDATION_ERROR';
    public const MODULE_ERROR = 'MODULE_ERROR';
    public const INTEGRATION_ERROR = 'INTEGRATION_ERROR';
    public const CACHE_ERROR = 'CACHE_ERROR';
    public const DATABASE_ERROR = 'DATABASE_ERROR';
    public const SYSTEM_ERROR = 'SYSTEM_ERROR';
    public const FATAL_ERROR = 'FATAL_ERROR';
    public const SECURITY_ERROR = 'SECURITY_ERROR';

    public function classifyThrowable( \Throwable $throwable, array $context = [] ): array {
        $message = strtolower( $throwable->getMessage() );
        $class = $throwable::class;
        $boundary = strtolower( (string) ( $context['boundary'] ?? '' ) );
        $service = strtolower( (string) ( $context['service'] ?? '' ) );
        $module = strtolower( (string) ( $context['module'] ?? '' ) );

        $classification = self::SYSTEM_ERROR;
        $status = 500;
        $severity = 'error';
        $recoverable = false;
        $retryable = false;
        $backgroundRepair = false;
        $securitySensitive = (bool) ( $context['security_sensitive'] ?? false );

        if (
            $securitySensitive
            || str_contains( strtolower( $class ), 'security' )
            || str_contains( strtolower( $class ), 'csrf' )
            || str_contains( strtolower( $class ), 'auth' )
            || str_contains( $message, 'forbidden' )
            || str_contains( $message, 'unauthorized' )
            || in_array( (int) ( $context['status_code'] ?? 0 ), [ 401, 403 ], true )
        ) {
            $classification = self::SECURITY_ERROR;
            $status = (int) ( $context['status_code'] ?? 403 ) ?: 403;
            $severity = 'error';
        } elseif (
            $throwable instanceof \InvalidArgumentException
            || $throwable instanceof \DomainException
            || str_contains( $message, 'invalid' )
            || str_contains( $message, 'required' )
        ) {
            $classification = self::VALIDATION_ERROR;
            $status = 422;
            $severity = 'warn';
        } elseif (
            str_contains( $boundary, 'cache' )
            || str_contains( $service, 'cache' )
            || str_contains( $message, 'cache' )
        ) {
            $classification = self::CACHE_ERROR;
            $status = 503;
            $severity = 'warn';
            $recoverable = true;
            $retryable = true;
            $backgroundRepair = true;
        } elseif (
            str_contains( $boundary, 'database' )
            || str_contains( $service, 'database' )
            || str_contains( $message, 'sql' )
            || str_contains( $message, 'mysqli' )
            || str_contains( $message, 'database' )
            || str_contains( $message, 'connection failed' )
        ) {
            $classification = self::DATABASE_ERROR;
            $status = 503;
            $severity = 'error';
            $recoverable = str_contains( $message, 'connection' ) || str_contains( $message, 'gone away' ) || str_contains( $message, 'refused' );
            $retryable = $recoverable;
        } elseif (
            str_contains( $boundary, 'integration' )
            || str_contains( $boundary, 'service' )
            || in_array( $service, [ 'drive', 'calendar', 'mail', 'github', 'http', 'redis' ], true )
            || str_contains( $message, 'http request failed' )
            || str_contains( $message, 'curl' )
            || str_contains( $message, 'api' )
            || str_contains( $message, 'redis' )
        ) {
            $classification = self::INTEGRATION_ERROR;
            $status = 503;
            $severity = 'warn';
            $recoverable = true;
            $retryable = true;
            $backgroundRepair = true;
        } elseif (
            str_contains( $boundary, 'module' )
            || $module !== ''
        ) {
            $classification = self::MODULE_ERROR;
            $status = 500;
            $severity = 'error';
            $recoverable = (bool) ( $context['optional'] ?? false );
        } elseif (
            $throwable instanceof \Error
            || (bool) ( $context['fatal'] ?? false )
        ) {
            $classification = self::FATAL_ERROR;
            $status = 500;
            $severity = 'error';
        } elseif ( $throwable instanceof \RuntimeException ) {
            $classification = self::USER_ERROR;
            $status = 400;
            $severity = 'warn';
        }

        return [
            'classification' => $classification,
            'status_code' => $status,
            'severity' => $severity,
            'recoverable' => $recoverable && $classification !== self::SECURITY_ERROR,
            'retryable' => $retryable && $classification !== self::SECURITY_ERROR,
            'background_repair_allowed' => $backgroundRepair && $classification !== self::SECURITY_ERROR,
            'security_sensitive' => $classification === self::SECURITY_ERROR,
        ];
    }

    public function classifyShutdownError( array $error, array $context = [] ): array {
        $message = (string) ( $error['message'] ?? 'Fatal shutdown error.' );
        $base = $this->classifyThrowable(
            new \ErrorException(
                $message,
                0,
                (int) ( $error['type'] ?? E_ERROR ),
                (string) ( $error['file'] ?? '' ),
                (int) ( $error['line'] ?? 0 )
            ),
            $context + [ 'fatal' => true ]
        );

        $base['classification'] = self::FATAL_ERROR;
        $base['status_code'] = 500;
        $base['severity'] = 'error';
        $base['recoverable'] = false;
        $base['retryable'] = false;
        return $base;
    }
}
