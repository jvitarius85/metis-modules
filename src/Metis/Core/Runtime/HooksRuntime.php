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

function metis_runtime_do_action( string $hook, mixed ...$args ): void {
    if ( empty( $GLOBALS['metis_hooks'][ $hook ] ) ) {
        return;
    }

    foreach ( $GLOBALS['metis_hooks'][ $hook ] as $callbacks ) {
        foreach ( $callbacks as $registered ) {
            $accepted = (int) ( $registered['accepted_args'] ?? count( $args ) );
            call_user_func_array( $registered['function'], array_slice( $args, 0, $accepted ) );
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

function add_shortcode( string $tag, callable $callback ): void {
    $GLOBALS['shortcode_tags'][ $tag ] = $callback;
}

function shortcode_atts( array $pairs, array $atts, string $shortcode = '' ): array {
    $out = $pairs;
    foreach ( $atts as $name => $value ) {
        if ( array_key_exists( $name, $pairs ) ) {
            $out[ $name ] = $value;
        }
    }
    return $out;
}

function do_shortcode( string $content ): string {
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
