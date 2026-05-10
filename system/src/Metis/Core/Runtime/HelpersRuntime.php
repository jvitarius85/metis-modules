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

    $type = trim( $type );
    if ( $type === '' || $type === 'mysql' ) {
        return $now->format( 'Y-m-d H:i:s' );
    }

    if ( in_array( $type, [ 'timestamp', 'U' ], true ) ) {
        return $now->getTimestamp();
    }

    if ( in_array( $type, [ 'mysql_utc', 'mysql_gmt', 'utc' ], true ) ) {
        return $now->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
    }

    return $now->format( $type );
}

function metis_runtime_timezone_name( ?string $candidate = null ): string {
    $candidates = [];
    if ( is_string( $candidate ) && trim( $candidate ) !== '' ) {
        $candidates[] = trim( $candidate );
    }

    if ( class_exists( 'Core_Settings_Service' ) ) {
        $configured = Core_Settings_Service::get( 'timezone', null );
        $site = Core_Settings_Service::get( 'site_timezone', null );
        if ( is_string( $configured ) && trim( $configured ) !== '' ) {
            $candidates[] = trim( $configured );
        }
        if ( is_string( $site ) && trim( $site ) !== '' ) {
            $candidates[] = trim( $site );
        }
    }

    $option_tz = metis_runtime_get_option( 'timezone_string', '' );
    if ( is_string( $option_tz ) && trim( $option_tz ) !== '' ) {
        $candidates[] = trim( $option_tz );
    }

    $php_tz = date_default_timezone_get();
    if ( is_string( $php_tz ) && trim( $php_tz ) !== '' ) {
        $candidates[] = trim( $php_tz );
    }

    foreach ( $candidates as $tz ) {
        if ( in_array( $tz, timezone_identifiers_list(), true ) ) {
            return $tz;
        }
    }

    return 'UTC';
}

function metis_runtime_timezone( ?string $timezone = null ): DateTimeZone {
    try {
        return new DateTimeZone( metis_runtime_timezone_name( $timezone ) );
    } catch ( Throwable ) {
        return new DateTimeZone( 'UTC' );
    }
}

function metis_runtime_sanitize_date_time_format( mixed $format, string $fallback ): string {
    $format = is_string( $format ) ? trim( $format ) : '';
    if ( $format === '' || strlen( $format ) > 64 || preg_match( '/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $format ) ) {
        return $fallback;
    }

    return $format;
}

function metis_runtime_date_format(): string {
    $default = 'm/d/y';
    if ( class_exists( 'Core_Settings_Service' ) ) {
        return metis_runtime_sanitize_date_time_format( Core_Settings_Service::get( 'date_format', $default ), $default );
    }

    return $default;
}

function metis_runtime_time_format(): string {
    $default = 'g:i:s a';
    if ( class_exists( 'Core_Settings_Service' ) ) {
        return metis_runtime_sanitize_date_time_format( Core_Settings_Service::get( 'time_format', $default ), $default );
    }

    return $default;
}

function metis_runtime_datetime_format(): string {
    return trim( metis_runtime_date_format() . ' ' . metis_runtime_time_format() );
}

function metis_runtime_display_format( string $scope = 'datetime' ): string {
    $scope = strtolower( preg_replace( '/[^a-z0-9_-]+/', '_', trim( $scope ) ) ?: 'datetime' );
    return match ( $scope ) {
        'date' => metis_runtime_date_format(),
        'time' => metis_runtime_time_format(),
        default => metis_runtime_datetime_format(),
    };
}

function metis_runtime_date( string $format, ?int $timestamp = null, ?DateTimeZone $timezone = null ): string {
    $timezone = $timezone ?? metis_runtime_timezone();
    $date = new DateTimeImmutable( '@' . ( $timestamp ?? time() ) );
    return $date->setTimezone( $timezone )->format( $format );
}

function metis_runtime_current_datetime(): DateTimeImmutable {
    return new DateTimeImmutable( 'now', metis_runtime_timezone() );
}

function metis_runtime_datetime_has_timezone_hint( string $value ): bool {
    return preg_match( '/(?:Z|[+\-]\d{2}:?\d{2}|[A-Za-z_]+\/[A-Za-z_]+)$/', trim( $value ) ) === 1;
}

function metis_runtime_datetime_from_value( mixed $value, DateTimeZone|string|null $source_timezone = null ): ?DateTimeImmutable {
    if ( $value instanceof DateTimeImmutable ) {
        return $value;
    }

    if ( $value instanceof DateTimeInterface ) {
        return DateTimeImmutable::createFromInterface( $value );
    }

    if ( is_int( $value ) || is_float( $value ) ) {
        return ( new DateTimeImmutable( '@' . (int) $value ) )->setTimezone( metis_runtime_timezone() );
    }

    $raw = is_string( $value ) ? trim( $value ) : '';
    if ( $raw === '' ) {
        return null;
    }

    if ( ctype_digit( $raw ) && strlen( $raw ) >= 10 ) {
        return ( new DateTimeImmutable( '@' . (int) $raw ) )->setTimezone( metis_runtime_timezone() );
    }

    $source = $source_timezone instanceof DateTimeZone
        ? $source_timezone
        : metis_runtime_timezone( is_string( $source_timezone ) ? $source_timezone : null );

    try {
        if ( metis_runtime_datetime_has_timezone_hint( $raw ) ) {
            return new DateTimeImmutable( $raw );
        }

        foreach ( [ '!Y-m-d H:i:s', '!Y-m-d H:i', '!Y-m-d' ] as $format ) {
            $dt = DateTimeImmutable::createFromFormat( $format, $raw, $source );
            if ( $dt instanceof DateTimeImmutable ) {
                return $dt;
            }
        }

        return new DateTimeImmutable( $raw, $source );
    } catch ( Throwable ) {
        return null;
    }
}

function metis_runtime_format_datetime( mixed $value, ?string $format = null, DateTimeZone|string|null $timezone = null, DateTimeZone|string|null $source_timezone = null, string $empty = '' ): string {
    $dt = metis_runtime_datetime_from_value( $value, $source_timezone );
    if ( ! $dt instanceof DateTimeImmutable ) {
        return $empty;
    }

    $target = $timezone instanceof DateTimeZone
        ? $timezone
        : metis_runtime_timezone( is_string( $timezone ) ? $timezone : null );
    $format = metis_runtime_sanitize_date_time_format( $format ?? metis_runtime_datetime_format(), metis_runtime_datetime_format() );

    return $dt->setTimezone( $target )->format( $format );
}

function metis_runtime_format_date( mixed $value, ?string $format = null, DateTimeZone|string|null $timezone = null, DateTimeZone|string|null $source_timezone = null, string $empty = '' ): string {
    return metis_runtime_format_datetime( $value, $format ?? metis_runtime_date_format(), $timezone, $source_timezone, $empty );
}

function metis_runtime_format_time( mixed $value, ?string $format = null, DateTimeZone|string|null $timezone = null, DateTimeZone|string|null $source_timezone = null, string $empty = '' ): string {
    return metis_runtime_format_datetime( $value, $format ?? metis_runtime_time_format(), $timezone, $source_timezone, $empty );
}

function metis_runtime_format_datetime_local_value( mixed $value, DateTimeZone|string|null $source_timezone = null ): string {
    return metis_runtime_format_datetime( $value, 'Y-m-d\TH:i', metis_runtime_timezone(), $source_timezone, '' );
}

function metis_runtime_sync_default_timezone(): void {
    @date_default_timezone_set( metis_runtime_timezone_name() );
}

if ( function_exists( 'metis_on' ) ) {
    metis_on( 'metis_runtime_loaded', 'metis_runtime_sync_default_timezone', 20, 0 );
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
    foreach ( (array) ( $GLOBALS['metis_assets']['inline_styles'][ $handle ] ?? [] ) as $css ) {
        $css = trim( (string) $css );
        if ( $css === '' ) {
            continue;
        }
        echo '<style id="' . metis_escape_attr( $handle . '-inline-css' ) . '">' . $css . '</style>' . "\n";
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
    foreach ( (array) ( $GLOBALS['metis_assets']['inline_scripts_before'][ $handle ] ?? [] ) as $index => $script ) {
        $script = trim( (string) $script );
        if ( $script === '' ) {
            continue;
        }
        echo '<script id="' . metis_escape_attr( $handle . '-inline-before-' . (int) $index . '-js' ) . '">' . $script . '</script>' . "\n";
    }
    $src = (string) ( $asset['src'] ?? '' );
    $ver = (string) ( $asset['ver'] ?? '' );
    if ( $src !== '' ) {
        if ( $ver !== '' ) {
            $src .= ( str_contains( $src, '?' ) ? '&' : '?' ) . 'ver=' . rawurlencode( $ver );
        }
        echo '<script src="' . metis_escape_attr( $src ) . '" defer></script>' . "\n";
    }
    foreach ( (array) ( $GLOBALS['metis_assets']['inline_scripts_after'][ $handle ] ?? [] ) as $index => $script ) {
        $script = trim( (string) $script );
        if ( $script === '' ) {
            continue;
        }
        echo '<script id="' . metis_escape_attr( $handle . '-inline-after-' . (int) $index . '-js' ) . '">' . $script . '</script>' . "\n";
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
