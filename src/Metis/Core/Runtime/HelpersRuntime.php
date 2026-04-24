<?php
declare(strict_types=1);

function metis_runtime_parse_args( mixed $args, array $defaults = [] ): array {
    if ( is_array( $args ) ) {
        return array_merge( $defaults, $args );
    }

    if ( is_string( $args ) && $args !== '' ) {
        parse_str( $args, $parsed );
        if ( is_array( $parsed ) ) {
            return array_merge( $defaults, $parsed );
        }
    }

    return $defaults;
}

function metis_runtime_current_time( string $type = 'mysql' ): string|int {
    $tz = metis_runtime_timezone();
    $now = new DateTimeImmutable( 'now', $tz );
    return $type === 'mysql' ? $now->format( 'Y-m-d H:i:s' ) : $now->getTimestamp();
}

function metis_runtime_timezone(): DateTimeZone {
    $tz = '';
    if ( class_exists( 'Core_Settings_Service' ) ) {
        $tz = (string) Core_Settings_Service::get( 'timezone', Core_Settings_Service::get( 'site_timezone', '' ) );
    }
    if ( $tz === '' ) {
        $tz = (string) metis_runtime_get_option( 'timezone_string', 'UTC' );
    }
    try {
        return new DateTimeZone( $tz !== '' ? $tz : 'UTC' );
    } catch ( Throwable ) {
        return new DateTimeZone( 'UTC' );
    }
}

function metis_runtime_date( string $format, ?int $timestamp = null, ?DateTimeZone $timezone = null ): string {
    $timezone = $timezone ?? metis_runtime_timezone();
    $date = new DateTimeImmutable( '@' . ( $timestamp ?? time() ) );
    return $date->setTimezone( $timezone )->format( $format );
}

function metis_runtime_current_datetime(): DateTimeImmutable {
    return new DateTimeImmutable( 'now', metis_runtime_timezone() );
}

if ( ! function_exists( 'metis_runtime_generate_uuid' ) ) {
function metis_runtime_generate_uuid(): string {
    $bytes = random_bytes( 16 );
    $bytes[6] = chr( ( ord( $bytes[6] ) & 0x0f ) | 0x40 );
    $bytes[8] = chr( ( ord( $bytes[8] ) & 0x3f ) | 0x80 );
    $hex = bin2hex( $bytes );
    return vsprintf( '%s%s-%s-%s-%s-%s%s%s', str_split( $hex, 4 ) );
}
}

function metis_runtime_number_format( float|int|string $number, int $decimals = 0 ): string {
    return number_format( (float) $number, $decimals, '.', ',' );
}

function metis_runtime_next_scheduled( string $hook ): int|false {
    $events = metis_runtime_get_option( 'metis_scheduled_events', [] );
    return isset( $events[ $hook ]['timestamp'] ) ? (int) $events[ $hook ]['timestamp'] : false;
}

function metis_runtime_schedule_event( int $timestamp, string $recurrence, string $hook ): bool {
    $events = metis_runtime_get_option( 'metis_scheduled_events', [] );
    $events[ $hook ] = [
        'timestamp' => $timestamp,
        'recurrence' => $recurrence,
    ];
    metis_runtime_update_option( 'metis_scheduled_events', $events, false );
    return true;
}

function metis_runtime_mail( string|array $to, string $subject, string $message, array|string $headers = [] ): bool {
    $recipients = is_array( $to ) ? implode( ',', $to ) : $to;
    return @mail( $recipients, $subject, $message, is_array( $headers ) ? implode( "\r\n", $headers ) : $headers );
}

function metis_runtime_add_rewrite_tag( string $tag, string $regex ): void {}
function metis_runtime_add_rewrite_rule( string $regex, string $query, string $position = 'bottom' ): void {}
function metis_runtime_flush_rewrite_rules( bool $hard = true ): void {}
function metis_runtime_register_activation_hook( string $file, callable|string $callback ): void {}
function metis_runtime_register_deactivation_hook( string $file, callable|string $callback ): void {}
function metis_runtime_show_admin_bar( bool $show ): bool { return $show; }

function metis_runtime_register_script( string $handle, string $src, array $deps = [], ?string $ver = null, bool $in_footer = true ): void {
    $GLOBALS['metis_assets']['scripts'][ $handle ] = [
        'src' => $src,
        'deps' => $deps,
        'ver' => $ver,
        'in_footer' => $in_footer,
    ];
}

function metis_runtime_register_style( string $handle, string $src, array $deps = [], ?string $ver = null ): void {
    $GLOBALS['metis_assets']['styles'][ $handle ] = [
        'src' => $src,
        'deps' => $deps,
        'ver' => $ver,
    ];
}

function metis_runtime_register_style_alias( string $handle, string $src, array $deps = [], ?string $ver = null ): void {
    metis_runtime_register_style( $handle, $src, $deps, $ver );
}

function metis_runtime_register_script_alias( string $handle, string $src, array $deps = [], ?string $ver = null, bool $in_footer = true ): void {
    metis_runtime_register_script( $handle, $src, $deps, $ver, $in_footer );
}

function metis_runtime_enqueue_style( string $handle, string $src = '', array $deps = [], ?string $ver = null ): void {
    if ( $src !== '' && empty( $GLOBALS['metis_assets']['styles'][ $handle ] ) ) {
        metis_runtime_register_style_alias( $handle, $src, $deps, $ver );
    }
    $GLOBALS['metis_assets']['enqueued_styles'][ $handle ] = true;
}

function metis_runtime_enqueue_script( string $handle, string $src = '', array $deps = [], ?string $ver = null, bool $in_footer = true ): void {
    if ( $src !== '' && empty( $GLOBALS['metis_assets']['scripts'][ $handle ] ) ) {
        metis_runtime_register_script_alias( $handle, $src, $deps, $ver, $in_footer );
    }
    $GLOBALS['metis_assets']['enqueued_scripts'][ $handle ] = true;
}

function metis_runtime_add_inline_style( string $handle, string $css ): void {
    $GLOBALS['metis_assets']['inline_styles'][ $handle ][] = $css;
}

function metis_runtime_add_inline_script( string $handle, string $script, string $position = 'after' ): void {
    $bucket = $position === 'before' ? 'inline_scripts_before' : 'inline_scripts_after';
    $GLOBALS['metis_assets'][ $bucket ][ $handle ][] = $script;
}

function metis_runtime_localize_script( string $handle, string $object_name, array $data ): void {
    metis_runtime_add_inline_script( $handle, 'window.' . $object_name . ' = ' . metis_runtime_json_encode( $data ) . ';', 'before' );
}

function metis_runtime_print_style( string $handle ): void {
    if ( isset( $GLOBALS['metis_assets']['styles_printed'][ $handle ] ) ) {
        return;
    }
    $asset = $GLOBALS['metis_assets']['styles'][ $handle ] ?? null;
    if ( ! is_array( $asset ) ) {
        return;
    }
    foreach ( (array) ( $asset['deps'] ?? [] ) as $dep ) {
        metis_runtime_print_style( (string) $dep );
    }
    $src = (string) ( $asset['src'] ?? '' );
    $ver = (string) ( $asset['ver'] ?? '' );
    if ( $src !== '' ) {
        if ( $ver !== '' ) {
            $src .= ( str_contains( $src, '?' ) ? '&' : '?' ) . 'ver=' . rawurlencode( $ver );
        }
        echo '<link rel="stylesheet" href="' . metis_escape_attr( $src ) . '">' . "\n";
    }
    $GLOBALS['metis_assets']['styles_printed'][ $handle ] = true;
}

function metis_runtime_print_script( string $handle, bool $footer ): void {
    if ( isset( $GLOBALS['metis_assets']['scripts_printed'][ $handle ] ) ) {
        return;
    }
    $asset = $GLOBALS['metis_assets']['scripts'][ $handle ] ?? null;
    if ( ! is_array( $asset ) ) {
        return;
    }
    if ( (bool) ( $asset['in_footer'] ?? true ) !== $footer ) {
        return;
    }
    foreach ( (array) ( $asset['deps'] ?? [] ) as $dep ) {
        metis_runtime_print_script( (string) $dep, $footer );
    }
    $src = (string) ( $asset['src'] ?? '' );
    $ver = (string) ( $asset['ver'] ?? '' );
    if ( $src !== '' ) {
        if ( $ver !== '' ) {
            $src .= ( str_contains( $src, '?' ) ? '&' : '?' ) . 'ver=' . rawurlencode( $ver );
        }
        echo '<script src="' . metis_escape_attr( $src ) . '" defer></script>' . "\n";
    }
    $GLOBALS['metis_assets']['scripts_printed'][ $handle ] = true;
}

function metis_head(): void {
    metis_runtime_do_action( 'metis_assets_enqueue' );
    foreach ( array_keys( $GLOBALS['metis_assets']['enqueued_styles'] ) as $handle ) {
        metis_runtime_print_style( (string) $handle );
    }
    foreach ( array_keys( $GLOBALS['metis_assets']['enqueued_scripts'] ) as $handle ) {
        metis_runtime_print_script( (string) $handle, false );
    }
    metis_runtime_do_action( 'metis_head' );
}

function metis_footer(): void {
    foreach ( array_keys( $GLOBALS['metis_assets']['enqueued_scripts'] ) as $handle ) {
        metis_runtime_print_script( (string) $handle, true );
    }
    metis_runtime_do_action( 'metis_footer' );
}

metis_runtime_register_script( 'jquery', 'https://code.jquery.com/jquery-3.7.1.min.js', [], '3.7.1', false );
