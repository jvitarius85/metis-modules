<?php
declare(strict_types=1);

function metis_runtime_listen( string $hook, callable $callback, int $priority = 10, int $accepted_args = 1 ): bool {
    $GLOBALS['metis_hooks'][ $hook ][ $priority ][] = [
        'function' => $callback,
        'accepted_args' => $accepted_args,
    ];
    ksort( $GLOBALS['metis_hooks'][ $hook ] );
    $GLOBALS['merged_filters'][ $hook ] = true;
    return true;
}

function metis_runtime_add_filter( string $hook, callable $callback, int $priority = 10, int $accepted_args = 1 ): bool {
    return metis_runtime_listen( $hook, $callback, $priority, $accepted_args );
}

function metis_runtime_remove_filter( string $hook, callable $callback, int $priority = 10 ): bool {
    if ( empty( $GLOBALS['metis_hooks'][ $hook ][ $priority ] ) ) {
        return false;
    }

    foreach ( $GLOBALS['metis_hooks'][ $hook ][ $priority ] as $index => $registered ) {
        if ( $registered['function'] === $callback ) {
            unset( $GLOBALS['metis_hooks'][ $hook ][ $priority ][ $index ] );
            return true;
        }
    }

    return false;
}

function metis_runtime_has_action( string $hook ): bool {
    return ! empty( $GLOBALS['metis_hooks'][ $hook ] );
}

function metis_runtime_profiler_callback_label( string $hook, mixed $callback ): string {
    if ( is_array( $callback ) && isset( $callback[0], $callback[1] ) ) {
        $class = is_object( $callback[0] ) ? $callback[0]::class : (string) $callback[0];
        $parts = explode( '\\', $class );
        $label = end( $parts ) . '_' . (string) $callback[1];
    } elseif ( is_string( $callback ) ) {
        $parts = explode( '\\', $callback );
        $label = (string) end( $parts );
    } elseif ( $callback instanceof Closure ) {
        $label = 'closure';
    } else {
        $label = 'callback';
    }

    $label = preg_replace( '/[^A-Za-z0-9_]+/', '_', $hook . '_' . $label );
    $label = is_string( $label ) ? trim( $label, '_' ) : $hook;
    return substr( 'HOOK_' . $label, 0, 96 );
}

function metis_runtime_do_action( string $hook, mixed ...$args ): void {
    if ( empty( $GLOBALS['metis_hooks'][ $hook ] ) ) {
        return;
    }

    foreach ( $GLOBALS['metis_hooks'][ $hook ] as $callbacks ) {
        foreach ( $callbacks as $registered ) {
            $accepted = (int) ( $registered['accepted_args'] ?? count( $args ) );
            $profiler_label = class_exists( 'Profiler', false )
                ? metis_runtime_profiler_callback_label( $hook, $registered['function'] ?? null )
                : '';
            if ( $profiler_label !== '' ) {
                Profiler::mark( $profiler_label );
            }
            try {
                call_user_func_array( $registered['function'], array_slice( $args, 0, $accepted ) );
            } finally {
                if ( $profiler_label !== '' ) {
                    Profiler::mark( $profiler_label . '_DONE' );
                }
            }
        }
    }
}

function metis_runtime_filter( string $hook, mixed $value, mixed ...$args ): mixed {
    if ( empty( $GLOBALS['metis_hooks'][ $hook ] ) ) {
        return $value;
    }

    foreach ( $GLOBALS['metis_hooks'][ $hook ] as $callbacks ) {
        foreach ( $callbacks as $registered ) {
            $accepted = max( 1, (int) ( $registered['accepted_args'] ?? ( count( $args ) + 1 ) ) );
            $params = array_merge( [ $value ], $args );
            $value = call_user_func_array( $registered['function'], array_slice( $params, 0, $accepted ) );
        }
    }

    return $value;
}

if ( ! function_exists( 'metis_shortcode_register' ) ) {
    function metis_shortcode_register( string $tag, callable $callback ): void {
        $GLOBALS['shortcode_tags'][ $tag ] = $callback;
    }
}

if ( ! function_exists( 'metis_shortcode_defaults' ) ) {
    function metis_shortcode_defaults( array $pairs, array $atts, string $shortcode = '' ): array {
        unset( $shortcode );
        $out = $pairs;
        foreach ( $atts as $name => $value ) {
            if ( array_key_exists( $name, $pairs ) ) {
                $out[ $name ] = $value;
            }
        }
        return $out;
    }
}

if ( ! function_exists( 'metis_shortcode_render' ) ) {
    function metis_shortcode_render( string $content ): string {
        if ( $content === '' || empty( $GLOBALS['shortcode_tags'] ) ) {
            return $content;
        }

        return preg_replace_callback(
            '/\[([a-zA-Z0-9_\-]+)([^\]]*)\]/',
            static function ( array $matches ): string {
                $tag = $matches[1];
                $callback = $GLOBALS['shortcode_tags'][ $tag ] ?? null;
                if ( ! is_callable( $callback ) ) {
                    return $matches[0];
                }

                $atts = [];
                if ( preg_match_all( '/([a-zA-Z0-9_\-]+)\s*=\s*"([^"]*)"/', $matches[2], $attributeMatches, PREG_SET_ORDER ) ) {
                    foreach ( $attributeMatches as $attributeMatch ) {
                        $atts[ $attributeMatch[1] ] = $attributeMatch[2];
                    }
                }

                return (string) call_user_func( $callback, $atts, null, $tag );
            },
            $content
        ) ?? $content;
    }
}
