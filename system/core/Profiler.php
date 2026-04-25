<?php
declare(strict_types=1);

final class Profiler {
    private static array $marks = [];
    private static float $start = 0.0;
    private static bool $enabled = false;
    private static bool $reported = false;

    public static function requestEnabled( bool $default = false ): bool {
        if ( PHP_SAPI === 'cli' ) {
            return $default;
        }

        $value = $_GET['metis_profiler'] ?? $_GET['profiler'] ?? null;
        if ( $value === null ) {
            return $default;
        }

        $value = strtolower( trim( (string) $value ) );
        return in_array( $value, [ '1', 'true', 'yes', 'on' ], true );
    }

    public static function init( bool $enabled ): void {
        self::$enabled = $enabled;
        self::$start = microtime( true );
        self::$marks = [];

        if ( ! self::$enabled ) {
            return;
        }

        self::$marks[] = [
            'label' => 'START',
            'time'  => self::$start,
        ];
    }

    public static function mark( string $label ): void {
        if ( ! self::$enabled ) {
            return;
        }

        self::$marks[] = [
            'label' => $label,
            'time'  => microtime( true ),
        ];
    }

    public static function report(): void {
        if ( ! self::$enabled || self::$reported ) {
            return;
        }

        self::$reported = true;

        if ( ! self::shouldRenderOverlay() ) {
            return;
        }

        try {
            $previous = self::$start;
            $lines = [];

            foreach ( self::$marks as $mark ) {
                $label = (string) ( $mark['label'] ?? '' );
                $time = (float) ( $mark['time'] ?? self::$start );
                $delta = max( 0.0, $time - $previous );
                $total = max( 0.0, $time - self::$start );
                $previous = $time;

                $lines[] = sprintf(
                    '%s | +%ss | %ss',
                    $label,
                    number_format( $delta, 6, '.', '' ),
                    number_format( $total, 6, '.', '' )
                );
            }

            $total = max( 0.0, microtime( true ) - self::$start );
            $lines[] = 'TOTAL: ' . number_format( $total, 6, '.', '' ) . 's';

            echo '<div style="position:fixed;left:0;right:0;bottom:0;width:100%;max-height:40vh;overflow:auto;background:#111;color:#0f0;font:12px/1.45 monospace;z-index:2147483647;padding:8px 12px;box-sizing:border-box;white-space:pre-wrap;text-align:left;">';
            foreach ( $lines as $line ) {
                echo htmlspecialchars( $line, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ) . "\n";
            }
            echo '</div>';
        } catch ( Throwable ) {
            return;
        }
    }

    private static function shouldRenderOverlay(): bool {
        if ( PHP_SAPI === 'cli' ) {
            return false;
        }

        foreach ( headers_list() as $header ) {
            if ( stripos( $header, 'Content-Type:' ) !== 0 ) {
                continue;
            }

            $contentType = strtolower( trim( substr( $header, 13 ) ) );
            return $contentType === '' || str_contains( $contentType, 'text/html' );
        }

        $accept = strtolower( (string) ( $_SERVER['HTTP_ACCEPT'] ?? '' ) );
        if ( $accept !== '' && str_contains( $accept, 'application/json' ) && ! str_contains( $accept, 'text/html' ) ) {
            return false;
        }

        return true;
    }
}
