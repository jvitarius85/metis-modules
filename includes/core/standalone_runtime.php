<?php
declare(strict_types=1);

if ( defined( 'METIS_STANDALONE_RUNTIME_LOADED' ) ) {
    return;
}

define( 'METIS_STANDALONE_RUNTIME_LOADED', true );

if ( session_status() !== PHP_SESSION_ACTIVE ) {
    session_start();
}

if ( ! defined( 'ABSPATH' ) ) {
    define( 'ABSPATH', dirname( __DIR__, 2 ) . '/' );
}

if ( ! defined( 'WP_CONTENT_DIR' ) ) {
    define( 'WP_CONTENT_DIR', dirname( __DIR__, 2 ) );
}

if ( ! defined( 'ARRAY_A' ) ) {
    define( 'ARRAY_A', 'ARRAY_A' );
}

if ( ! defined( 'OBJECT' ) ) {
    define( 'OBJECT', 'OBJECT' );
}

if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
    define( 'MINUTE_IN_SECONDS', 60 );
}

if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
    define( 'HOUR_IN_SECONDS', 3600 );
}

if ( ! defined( 'DAY_IN_SECONDS' ) ) {
    define( 'DAY_IN_SECONDS', 86400 );
}

$GLOBALS['wp_filter'] = $GLOBALS['wp_filter'] ?? [];
$GLOBALS['merged_filters'] = $GLOBALS['merged_filters'] ?? [];
$GLOBALS['metis_query_vars'] = $GLOBALS['metis_query_vars'] ?? [];
$GLOBALS['shortcode_tags'] = $GLOBALS['shortcode_tags'] ?? [];
$GLOBALS['metis_assets'] = $GLOBALS['metis_assets'] ?? [
    'styles' => [],
    'scripts' => [],
    'inline_styles' => [],
    'inline_scripts_before' => [],
    'inline_scripts_after' => [],
    'enqueued_styles' => [],
    'enqueued_scripts' => [],
    'styles_printed' => [],
    'scripts_printed' => [],
];
$GLOBALS['metis_runtime_config'] = $GLOBALS['metis_runtime_config'] ?? [];

final class WP_User {
    public int $ID = 1;
    public string $user_email = 'admin@localhost';
    public string $user_login = 'admin';
    public string $user_pass = '';
    public string $display_name = 'Metis Admin';
    public string $first_name = 'Metis';
    public string $last_name = 'Admin';
    public array $roles = [
        'administrator',
        'board',
        'donor_admin',
        'donor_user',
        'newsletter_admin',
    ];

    public function __construct( array $data = [] ) {
        foreach ( $data as $key => $value ) {
            if ( property_exists( $this, (string) $key ) ) {
                $this->{$key} = $value;
            }
        }
    }
}

final class WP_Query {
    public bool $is_404 = false;
    public bool $is_home = false;
    public bool $is_archive = false;
    public bool $is_singular = false;
}

final class WP_Error {
    public function __construct(
        private readonly string $code = 'error',
        private readonly string $message = '',
        private readonly mixed $data = null
    ) {}

    public function get_error_code(): string {
        return $this->code;
    }

    public function get_error_message(): string {
        return $this->message;
    }

    public function get_error_data(): mixed {
        return $this->data;
    }
}

if ( ! isset( $GLOBALS['wp_query'] ) || ! $GLOBALS['wp_query'] instanceof WP_Query ) {
    $GLOBALS['wp_query'] = new WP_Query();
}

function metis_runtime_config_get( string $key, mixed $default = null ): mixed {
    return $GLOBALS['metis_runtime_config'][ $key ] ?? $default;
}

function metis_runtime_storage_path( string $file ): string {
    $dir = dirname( __DIR__, 2 ) . '/storage/runtime';
    if ( ! is_dir( $dir ) ) {
        mkdir( $dir, 0775, true );
    }

    $path = $dir . '/' . ltrim( $file, '/' );
    $path_dir = dirname( $path );
    if ( ! is_dir( $path_dir ) ) {
        mkdir( $path_dir, 0775, true );
    }

    return $path;
}

function metis_runtime_json_store_read( string $file ): array {
    $path = metis_runtime_storage_path( $file );
    if ( ! is_file( $path ) ) {
        return [];
    }

    $raw = file_get_contents( $path );
    if ( $raw === false || trim( $raw ) === '' ) {
        return [];
    }

    $decoded = json_decode( $raw, true );
    return is_array( $decoded ) ? $decoded : [];
}

function metis_runtime_json_store_write( string $file, array $payload ): void {
    $path = metis_runtime_storage_path( $file );
    file_put_contents( $path, json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ), LOCK_EX );
}

function add_action( string $hook, callable $callback, int $priority = 10, int $accepted_args = 1 ): bool {
    $GLOBALS['wp_filter'][ $hook ][ $priority ][] = [
        'function' => $callback,
        'accepted_args' => $accepted_args,
    ];
    ksort( $GLOBALS['wp_filter'][ $hook ] );
    $GLOBALS['merged_filters'][ $hook ] = true;
    return true;
}

function add_filter( string $hook, callable $callback, int $priority = 10, int $accepted_args = 1 ): bool {
    return add_action( $hook, $callback, $priority, $accepted_args );
}

function remove_filter( string $hook, callable $callback, int $priority = 10 ): bool {
    if ( empty( $GLOBALS['wp_filter'][ $hook ][ $priority ] ) ) {
        return false;
    }

    foreach ( $GLOBALS['wp_filter'][ $hook ][ $priority ] as $index => $registered ) {
        if ( $registered['function'] === $callback ) {
            unset( $GLOBALS['wp_filter'][ $hook ][ $priority ][ $index ] );
            return true;
        }
    }

    return false;
}

function has_action( string $hook ): bool {
    return ! empty( $GLOBALS['wp_filter'][ $hook ] );
}

function do_action( string $hook, mixed ...$args ): void {
    if ( empty( $GLOBALS['wp_filter'][ $hook ] ) ) {
        return;
    }

    foreach ( $GLOBALS['wp_filter'][ $hook ] as $callbacks ) {
        foreach ( $callbacks as $registered ) {
            $accepted = (int) ( $registered['accepted_args'] ?? count( $args ) );
            call_user_func_array( $registered['function'], array_slice( $args, 0, $accepted ) );
        }
    }
}

function apply_filters( string $hook, mixed $value, mixed ...$args ): mixed {
    if ( empty( $GLOBALS['wp_filter'][ $hook ] ) ) {
        return $value;
    }

    foreach ( $GLOBALS['wp_filter'][ $hook ] as $callbacks ) {
        foreach ( $callbacks as $registered ) {
            $accepted = max( 1, (int) ( $registered['accepted_args'] ?? ( count( $args ) + 1 ) ) );
            $params = array_merge( [ $value ], $args );
            $value = call_user_func_array( $registered['function'], array_slice( $params, 0, $accepted ) );
        }
    }

    return $value;
}

function sanitize_key( string $key ): string {
    $key = strtolower( $key );
    return preg_replace( '/[^a-z0-9_\-]/', '', $key ) ?? '';
}

function sanitize_text_field( mixed $value ): string {
    $value = is_scalar( $value ) ? (string) $value : '';
    $value = strip_tags( $value );
    return trim( preg_replace( '/[\r\n\t ]+/', ' ', $value ) ?? '' );
}

function sanitize_textarea_field( mixed $value ): string {
    $value = is_scalar( $value ) ? (string) $value : '';
    return trim( strip_tags( $value ) );
}

function sanitize_email( mixed $value ): string {
    return filter_var( (string) $value, FILTER_SANITIZE_EMAIL ) ?: '';
}

function is_email( string $email ): string|false {
    $validated = filter_var( $email, FILTER_VALIDATE_EMAIL );
    return $validated !== false ? (string) $validated : false;
}

function sanitize_title( mixed $value ): string {
    $value = strtolower( trim( (string) $value ) );
    $value = preg_replace( '/[^a-z0-9]+/', '-', $value ) ?? '';
    return trim( $value, '-' );
}

function sanitize_title_with_dashes( string $title, string $raw_title = '', string $context = 'display' ): string {
    return sanitize_title( $title );
}

function sanitize_file_name( string $filename ): string {
    $filename = preg_replace( '/[^A-Za-z0-9\.\-_]/', '-', $filename ) ?? 'file';
    return trim( $filename, '-.' ) ?: 'file';
}

function sanitize_hex_color( string $color ): string {
    return preg_match( '/^#([0-9a-f]{3}|[0-9a-f]{6})$/i', $color ) ? $color : '';
}

function wp_unslash( mixed $value ): mixed {
    if ( is_array( $value ) ) {
        return array_map( 'wp_unslash', $value );
    }

    return is_string( $value ) ? stripslashes( $value ) : $value;
}

function wp_parse_url( string $url, int $component = -1 ): mixed {
    return parse_url( $url, $component );
}

function trailingslashit( string $value ): string {
    return rtrim( $value, '/' ) . '/';
}

function untrailingslashit( string $value ): string {
    return rtrim( $value, '/' );
}

function metis_runtime_config_base_url(): string {
    $base_url = metis_runtime_config_get( 'base_url', '' );
    if ( is_array( $base_url ) ) {
        $base_url = reset( $base_url );
    }

    $base_url = trim( (string) $base_url );
    if ( $base_url === '' ) {
        return '';
    }

    $parsed = parse_url( $base_url );
    if ( ! is_array( $parsed ) || empty( $parsed['scheme'] ) || empty( $parsed['host'] ) ) {
        return '';
    }

    $scheme = strtolower( (string) $parsed['scheme'] );
    if ( ! in_array( $scheme, [ 'http', 'https' ], true ) ) {
        return '';
    }

    $host = strtolower( (string) $parsed['host'] );
    $path = isset( $parsed['path'] ) ? '/' . trim( (string) $parsed['path'], '/' ) : '';
    if ( $path === '/' ) {
        $path = '';
    }

    $authority = $host;
    if ( isset( $parsed['port'] ) && is_int( $parsed['port'] ) ) {
        $authority .= ':' . $parsed['port'];
    }

    return $scheme . '://' . $authority . $path;
}

function metis_runtime_forwarded_value( string $header ): string {
    $value = trim( (string) ( $_SERVER[ $header ] ?? '' ) );
    if ( $value === '' ) {
        return '';
    }

    $parts = explode( ',', $value );
    return trim( (string) reset( $parts ) );
}

function metis_runtime_forwarded_proto(): string {
    $forwarded = metis_runtime_forwarded_value( 'HTTP_X_FORWARDED_PROTO' );
    if ( $forwarded !== '' ) {
        $proto = strtolower( trim( explode( ',', $forwarded )[0] ?? '' ) );
        if ( in_array( $proto, [ 'http', 'https' ], true ) ) {
            return $proto;
        }
    }

    $forwarded_header = trim( (string) ( $_SERVER['HTTP_FORWARDED'] ?? '' ) );
    if ( $forwarded_header !== '' && preg_match( '/proto=([^;,\s]+)/i', $forwarded_header, $matches ) ) {
        $proto = strtolower( trim( $matches[1], "\"'" ) );
        if ( in_array( $proto, [ 'http', 'https' ], true ) ) {
            return $proto;
        }
    }

    if ( strtolower( (string) ( $_SERVER['HTTP_X_FORWARDED_SSL'] ?? '' ) ) === 'on' ) {
        return 'https';
    }

    if ( strtolower( (string) ( $_SERVER['HTTP_FRONT_END_HTTPS'] ?? '' ) ) === 'on' ) {
        return 'https';
    }

    $request_scheme = strtolower( (string) ( $_SERVER['REQUEST_SCHEME'] ?? '' ) );
    if ( in_array( $request_scheme, [ 'http', 'https' ], true ) ) {
        return $request_scheme;
    }

    return ( ! empty( $_SERVER['HTTPS'] ) && strtolower( (string) $_SERVER['HTTPS'] ) !== 'off' ) ? 'https' : 'http';
}

function metis_runtime_normalize_host( string $host ): string {
    $host = trim( $host );
    if ( $host === '' ) {
        return '';
    }

    if ( str_contains( $host, '/' ) || str_contains( $host, '\\' ) || preg_match( '/[\r\n\t ]/', $host ) ) {
        return '';
    }

    return $host;
}

function metis_runtime_host(): string {
    $configured = metis_runtime_config_base_url();
    if ( $configured !== '' ) {
        return (string) parse_url( $configured, PHP_URL_HOST ) . ( parse_url( $configured, PHP_URL_PORT ) ? ':' . parse_url( $configured, PHP_URL_PORT ) : '' );
    }

    $forwarded_host = metis_runtime_normalize_host( metis_runtime_forwarded_value( 'HTTP_X_FORWARDED_HOST' ) );
    if ( $forwarded_host !== '' ) {
        return $forwarded_host;
    }

    $host = metis_runtime_normalize_host( (string) ( $_SERVER['HTTP_HOST'] ?? '' ) );
    if ( $host !== '' ) {
        return $host;
    }

    $server_name = metis_runtime_normalize_host( (string) ( $_SERVER['SERVER_NAME'] ?? '' ) );
    if ( $server_name !== '' ) {
        $port = (string) ( $_SERVER['SERVER_PORT'] ?? '' );
        if ( $port !== '' && ! in_array( $port, [ '80', '443' ], true ) ) {
            return $server_name . ':' . $port;
        }

        return $server_name;
    }

    return 'localhost';
}

function metis_runtime_base_path( string $fallback = '' ): string {
    $configured = metis_runtime_config_base_url();
    if ( $configured !== '' ) {
        $path = (string) parse_url( $configured, PHP_URL_PATH );
        return $path === '/' ? '' : rtrim( $path, '/' );
    }

    $base = trim( (string) metis_runtime_config_get( 'base_path', '' ) );
    if ( $base !== '' ) {
        return $base === '/' ? '' : rtrim( $base, '/' );
    }

    $fallback = trim( $fallback );
    if ( $fallback === '' || $fallback === '/' ) {
        return '';
    }

    return rtrim( $fallback, '/' );
}

function metis_runtime_base_url( string $fallback_base_path = '' ): string {
    $configured = metis_runtime_config_base_url();
    if ( $configured !== '' ) {
        return $configured;
    }

    return metis_runtime_forwarded_proto() . '://' . metis_runtime_host() . metis_runtime_base_path( $fallback_base_path );
}

function home_url( string $path = '' ): string {
    $url = metis_runtime_base_url();
    return $path === '' ? $url : rtrim( $url, '/' ) . '/' . ltrim( $path, '/' );
}

function site_url( string $path = '' ): string {
    return home_url( $path );
}

function admin_url( string $path = '' ): string {
    return home_url( $path );
}

function plugin_dir_path( string $file ): string {
    return trailingslashit( dirname( $file ) );
}

function plugin_dir_url( string $file ): string {
    return trailingslashit( dirname( home_url( basename( dirname( $file ) ) . '/' . basename( $file ) ) ) );
}

function get_bloginfo( string $show ): string {
    return $show === 'charset' ? 'UTF-8' : '';
}

function wp_json_encode( mixed $value, int $flags = 0 ): string|false {
    return json_encode( $value, $flags | JSON_UNESCAPED_SLASHES );
}

function wp_parse_args( mixed $args, array $defaults = [] ): array {
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

function current_time( string $type = 'mysql' ): string|int {
    $tz = wp_timezone();
    $now = new DateTimeImmutable( 'now', $tz );
    return $type === 'mysql' ? $now->format( 'Y-m-d H:i:s' ) : $now->getTimestamp();
}

function wp_timezone(): DateTimeZone {
    $tz = (string) get_option( 'timezone_string', 'UTC' );
    try {
        return new DateTimeZone( $tz !== '' ? $tz : 'UTC' );
    } catch ( Throwable ) {
        return new DateTimeZone( 'UTC' );
    }
}

function wp_date( string $format, ?int $timestamp = null, ?DateTimeZone $timezone = null ): string {
    $timezone = $timezone ?? wp_timezone();
    $date = new DateTimeImmutable( '@' . ( $timestamp ?? time() ) );
    return $date->setTimezone( $timezone )->format( $format );
}

function current_datetime(): DateTimeImmutable {
    return new DateTimeImmutable( 'now', wp_timezone() );
}

function wp_generate_uuid4(): string {
    $bytes = random_bytes( 16 );
    $bytes[6] = chr( ( ord( $bytes[6] ) & 0x0f ) | 0x40 );
    $bytes[8] = chr( ( ord( $bytes[8] ) & 0x3f ) | 0x80 );
    $hex = bin2hex( $bytes );
    return vsprintf( '%s%s-%s-%s-%s-%s%s%s', str_split( $hex, 4 ) );
}

function esc_html( mixed $value ): string {
    return htmlspecialchars( (string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
}

function esc_attr( mixed $value ): string {
    return esc_html( $value );
}

function esc_textarea( mixed $value ): string {
    return esc_html( $value );
}

function esc_url( mixed $value ): string {
    return filter_var( (string) $value, FILTER_SANITIZE_URL ) ?: '';
}

function esc_sql( string $value ): string {
    return addslashes( $value );
}

function esc_js( mixed $value ): string {
    return addslashes( (string) $value );
}

function disabled( bool $disabled, bool $current = true, bool $display = true ): string {
    $result = $disabled === $current ? 'disabled' : '';
    if ( $display ) {
        echo $result;
    }
    return $result;
}

function selected( mixed $selected, mixed $current = true, bool $display = true ): string {
    $result = (string) $selected === (string) $current ? 'selected' : '';
    if ( $display ) {
        echo $result;
    }
    return $result;
}

function checked( mixed $checked, mixed $current = true, bool $display = true ): string {
    $result = (string) $checked === (string) $current ? 'checked' : '';
    if ( $display ) {
        echo $result;
    }
    return $result;
}

function add_query_arg( string|array $args, mixed $value = null, string $url = '' ): string {
    if ( is_array( $args ) ) {
        $query_args = $args;
        $target_url = is_string( $value ) ? $value : '';
    } else {
        $query_args = [ $args => $value ];
        $target_url = $url;
    }

    if ( $target_url === '' ) {
        $target_url = (string) ( $_SERVER['REQUEST_URI'] ?? '/' );
    }

    $parts = parse_url( $target_url );
    $query = [];
    if ( ! empty( $parts['query'] ) ) {
        parse_str( $parts['query'], $query );
    }
    foreach ( $query_args as $key => $value ) {
        $query[ (string) $key ] = $value;
    }
    $parts['query'] = http_build_query( $query );
    if ( isset( $parts['scheme'], $parts['host'] ) ) {
        $result = $parts['scheme'] . '://' . $parts['host'];
    } elseif ( isset( $parts['host'] ) ) {
        $result = '//' . $parts['host'];
    } else {
        $result = '';
    }
    if ( isset( $parts['port'] ) ) {
        $result .= ':' . $parts['port'];
    }
    $result .= $parts['path'] ?? '';
    if ( $parts['query'] !== '' ) {
        $result .= '?' . $parts['query'];
    }
    if ( isset( $parts['fragment'] ) && $parts['fragment'] !== '' ) {
        $result .= '#' . $parts['fragment'];
    }
    return $result;
}

function number_format_i18n( float|int|string $number, int $decimals = 0 ): string {
    return number_format( (float) $number, $decimals, '.', ',' );
}

function wp_mkdir_p( string $target ): bool {
    return is_dir( $target ) || mkdir( $target, 0775, true );
}

function metis_normalize_mime_map( array $mimes ): array {
    $normalized = [];
    foreach ( $mimes as $extensions => $mime ) {
        $mime = strtolower( trim( (string) $mime ) );
        if ( $mime === '' ) {
            continue;
        }

        foreach ( explode( '|', strtolower( (string) $extensions ) ) as $extension ) {
            $extension = trim( $extension, ". \t\n\r\0\x0B" );
            if ( $extension === '' ) {
                continue;
            }
            $normalized[ $extension ] = $mime;
        }
    }

    return $normalized;
}

function metis_detect_file_mime_type( string $path ): string {
    if ( ! is_file( $path ) ) {
        return '';
    }

    if ( function_exists( 'finfo_open' ) ) {
        $finfo = finfo_open( FILEINFO_MIME_TYPE );
        if ( $finfo ) {
            $mime = finfo_file( $finfo, $path );
            if ( is_string( $mime ) && $mime !== '' ) {
                return strtolower( $mime );
            }
        }
    }

    if ( function_exists( 'mime_content_type' ) ) {
        $mime = mime_content_type( $path );
        if ( is_string( $mime ) && $mime !== '' ) {
            return strtolower( $mime );
        }
    }

    return '';
}

function metis_detect_binary_mime_type( string $contents ): string {
    if ( $contents === '' ) {
        return '';
    }

    if ( function_exists( 'finfo_open' ) ) {
        $finfo = finfo_open( FILEINFO_MIME_TYPE );
        if ( $finfo ) {
            $mime = finfo_buffer( $finfo, $contents );
            if ( is_string( $mime ) && $mime !== '' ) {
                return strtolower( $mime );
            }
        }
    }

    return '';
}

function metis_extension_for_mime_type( string $mime_type, ?array $mimes = null ): string {
    $mime_type = strtolower( trim( $mime_type ) );
    if ( $mime_type === '' ) {
        return '';
    }

    $normalized = metis_normalize_mime_map( $mimes ?? [
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
        'svg' => 'image/svg+xml',
        'pdf' => 'application/pdf',
        'txt' => 'text/plain',
        'csv' => 'text/csv',
        'json' => 'application/json',
        'doc' => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xls' => 'application/vnd.ms-excel',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'ppt' => 'application/vnd.ms-powerpoint',
        'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'zip' => 'application/zip',
    ] );

    foreach ( $normalized as $extension => $candidate_mime ) {
        if ( $candidate_mime === $mime_type ) {
            return $extension;
        }
    }

    return '';
}

function metis_generate_upload_filename( string $original_name = '', string $mime_type = '', ?array $mimes = null ): string {
    $extension = '';

    if ( $mime_type !== '' ) {
        $extension = metis_extension_for_mime_type( $mime_type, $mimes );
    }

    if ( $extension === '' ) {
        $extension = strtolower( pathinfo( sanitize_file_name( $original_name ), PATHINFO_EXTENSION ) );
    }

    $filename = bin2hex( random_bytes( 16 ) );
    if ( $extension !== '' ) {
        $filename .= '.' . $extension;
    }

    return $filename;
}

function metis_upload_dir(): array {
    $subdir = '/' . gmdate( 'Y' ) . '/' . gmdate( 'm' );
    $basedir = dirname( __DIR__, 2 ) . '/storage/uploads';
    $baseurl = home_url( '/storage/uploads' );
    $path = $basedir . $subdir;
    $url = rtrim( $baseurl, '/' ) . $subdir;

    if ( ! wp_mkdir_p( $path ) ) {
        return [
            'path' => $path,
            'url' => $url,
            'subdir' => $subdir,
            'basedir' => $basedir,
            'baseurl' => $baseurl,
            'error' => 'Failed to create upload directory.',
        ];
    }

    return [
        'path' => $path,
        'url' => $url,
        'subdir' => $subdir,
        'basedir' => $basedir,
        'baseurl' => $baseurl,
        'error' => false,
    ];
}

function metis_upload_bits( string $name, ?string $deprecated, string $bits, ?string $time = null ): array {
    $uploads = metis_upload_dir();
    if ( ! empty( $uploads['error'] ) ) {
        return [
            'file' => '',
            'url' => '',
            'error' => (string) $uploads['error'],
        ];
    }

    $detected_mime = metis_detect_binary_mime_type( $bits );
    $filename = metis_generate_upload_filename( $name, $detected_mime );

    $path = rtrim( (string) $uploads['path'], '/' ) . '/' . $filename;
    $url = rtrim( (string) $uploads['url'], '/' ) . '/' . rawurlencode( $filename );
    $suffix = 1;
    while ( file_exists( $path ) ) {
        $extension = pathinfo( $filename, PATHINFO_EXTENSION );
        $stem = pathinfo( $filename, PATHINFO_FILENAME );
        $candidate = $stem . '-' . $suffix;
        if ( $extension !== '' ) {
            $candidate .= '.' . $extension;
        }
        $path = rtrim( (string) $uploads['path'], '/' ) . '/' . $candidate;
        $url = rtrim( (string) $uploads['url'], '/' ) . '/' . rawurlencode( $candidate );
        $suffix++;
    }

    $written = file_put_contents( $path, $bits );
    if ( $written === false ) {
        return [
            'file' => '',
            'url' => '',
            'error' => 'Failed to write upload.',
        ];
    }

    return [
        'file' => $path,
        'url' => $url,
        'error' => false,
    ];
}

function wp_upload_dir(): array {
    return metis_upload_dir();
}

function wp_upload_bits( string $name, ?string $deprecated, string $bits, ?string $time = null ): array {
    return metis_upload_bits( $name, $deprecated, $bits, $time );
}

function get_query_var( string $key, mixed $default = '' ): mixed {
    return $GLOBALS['metis_query_vars'][ $key ] ?? $default;
}

function set_query_var( string $key, mixed $value ): void {
    $GLOBALS['metis_query_vars'][ $key ] = $value;
}

function wp_get_current_user(): WP_User {
    if ( isset( $GLOBALS['metis_current_user_override'] ) && $GLOBALS['metis_current_user_override'] instanceof WP_User ) {
        return $GLOBALS['metis_current_user_override'];
    }

    if ( empty( $_SESSION['metis_user'] ) || ! is_array( $_SESSION['metis_user'] ) ) {
        return new WP_User( [
            'ID' => 0,
            'user_login' => '',
            'user_email' => '',
            'display_name' => '',
            'first_name' => '',
            'last_name' => '',
            'roles' => [],
            'user_pass' => '',
        ] );
    }

    return new WP_User( $_SESSION['metis_user'] );
}

function is_user_logged_in(): bool {
    return (int) wp_get_current_user()->ID > 0;
}

function get_current_user_id(): int {
    return wp_get_current_user()->ID;
}

function current_user_can( string $capability ): bool {
    $user = wp_get_current_user();
    if ( in_array( 'administrator', $user->roles, true ) ) {
        return true;
    }

    return in_array( $capability, $user->roles, true );
}

function is_admin(): bool {
    $path = (string) ( $_SERVER['REQUEST_URI'] ?? '' );
    return str_contains( $path, '/wp-admin' );
}

function wp_get_session_token(): string {
    if ( empty( $_SESSION['metis_session_token'] ) ) {
        $_SESSION['metis_session_token'] = bin2hex( random_bytes( 16 ) );
    }

    return (string) $_SESSION['metis_session_token'];
}

function wp_doing_ajax(): bool {
    return defined( 'DOING_AJAX' ) && DOING_AJAX;
}

function status_header( int $code ): void {
    $text = match ( $code ) {
        200 => 'OK',
        201 => 'Created',
        302 => 'Found',
        400 => 'Bad Request',
        401 => 'Unauthorized',
        403 => 'Forbidden',
        404 => 'Not Found',
        405 => 'Method Not Allowed',
        410 => 'Gone',
        422 => 'Unprocessable Entity',
        500 => 'Internal Server Error',
        503 => 'Service Unavailable',
        default => '',
    };
    $header = sprintf( 'HTTP/1.1 %d %s', $code, $text );
    $header = apply_filters( 'status_header', $header, $code, $text );
    header( $header, true, $code );
}

function nocache_headers(): void {
    header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0', true );
    header( 'Pragma: no-cache', true );
}

function wp_die( string $message = '', string $title = '', array $args = [] ): never {
    $handler = apply_filters( 'wp_die_handler', null );
    if ( is_string( $handler ) && function_exists( $handler ) ) {
        $handler( $message, $title, $args );
    }

    $handler = apply_filters( 'wp_die_ajax_handler', null );
    if ( is_string( $handler ) && function_exists( $handler ) ) {
        $handler( $message, $title, $args );
    }

    $status = isset( $args['response'] ) ? (int) $args['response'] : 500;
    status_header( $status );
    exit( $message );
}

function wp_send_json( array $payload, int $status_code = 200 ): never {
    status_header( $status_code );
    header( 'Content-Type: application/json; charset=UTF-8' );
    echo wp_json_encode( $payload );
    exit;
}

function wp_send_json_success( array $data = [], int $status_code = 200 ): never {
    wp_send_json( [ 'success' => true, 'data' => $data ], $status_code );
}

function wp_send_json_error( mixed $data = [], int $status_code = 400 ): never {
    wp_send_json( [ 'success' => false, 'data' => $data ], $status_code );
}

function wp_create_nonce( string $action = '' ): string {
    $secret = (string) metis_runtime_config_get( 'app_key', 'metis-local-key' );
    $ttl = max( 60, (int) metis_runtime_config_get( 'csrf_ttl', 2 * HOUR_IN_SECONDS ) );
    $issued_at = (int) floor( time() / $ttl ) * $ttl;
    $mac = hash_hmac( 'sha256', $action . '|' . wp_get_session_token() . '|' . $issued_at, $secret );

    return $issued_at . ':' . $mac;
}

function wp_verify_nonce( string $nonce, string $action = '' ): bool {
    $nonce = trim( $nonce );
    if ( $nonce === '' ) {
        return false;
    }

    $secret = (string) metis_runtime_config_get( 'app_key', 'metis-local-key' );
    $ttl = max( 60, (int) metis_runtime_config_get( 'csrf_ttl', 2 * HOUR_IN_SECONDS ) );

    if ( ! str_contains( $nonce, ':' ) ) {
        return hash_equals( hash_hmac( 'sha256', $action . '|' . wp_get_session_token(), $secret ), $nonce );
    }

    [ $issued_at, $mac ] = array_pad( explode( ':', $nonce, 2 ), 2, '' );
    if ( ! ctype_digit( $issued_at ) || $mac === '' ) {
        return false;
    }

    $issued_at_int = (int) $issued_at;
    if ( $issued_at_int < ( time() - ( 2 * $ttl ) ) || $issued_at_int > ( time() + $ttl ) ) {
        return false;
    }

    foreach ( [ $issued_at_int, $issued_at_int - $ttl, $issued_at_int + $ttl ] as $candidate_window ) {
        if ( $candidate_window < 0 ) {
            continue;
        }

        $expected = hash_hmac( 'sha256', $action . '|' . wp_get_session_token() . '|' . $candidate_window, $secret );
        if ( hash_equals( $expected, $mac ) ) {
            return true;
        }
    }

    return false;
}

function wp_nonce_field( string $action = '', string $name = '_wpnonce' ): void {
    echo '<input type="hidden" name="' . esc_attr( $name ) . '" value="' . esc_attr( wp_create_nonce( $action ) ) . '">';
}

function metis_request_nonce_candidates( string|bool $query_arg = false ): array {
    $fields = [];
    if ( is_string( $query_arg ) && $query_arg !== '' ) {
        $fields[] = $query_arg;
    }

    $fields = array_merge(
        $fields,
        [ '_ajax_nonce', 'metis_action_nonce', 'nonce', 'security', '_wpnonce' ]
    );

    $candidates = [];
    foreach ( $fields as $field ) {
        if ( ! is_string( $field ) || $field === '' || ! isset( $_REQUEST[ $field ] ) ) {
            continue;
        }

        $value = (string) $_REQUEST[ $field ];
        if ( $value !== '' ) {
            $candidates[] = $value;
        }
    }

    return array_values( array_unique( $candidates ) );
}

function check_ajax_referer( string $action = '-1', string|bool $query_arg = false, bool $stop = true ): bool {
    $candidates = metis_request_nonce_candidates( $query_arg );
    $valid = false;

    foreach ( $candidates as $candidate ) {
        if ( wp_verify_nonce( $candidate, $action ) ) {
            $valid = true;
            break;
        }
    }

    if ( ! $valid ) {
        $request_action = sanitize_key( (string) ( $_REQUEST['action'] ?? '' ) );
        if ( $request_action !== '' ) {
            $ajax_nonce_action = function_exists( 'metis_ajax_nonce_action' )
                ? metis_ajax_nonce_action( $request_action )
                : 'metis_ajax:' . $request_action;

            if ( $ajax_nonce_action !== '' && $ajax_nonce_action !== $action ) {
                foreach ( $candidates as $candidate ) {
                    if ( wp_verify_nonce( $candidate, $ajax_nonce_action ) ) {
                        $valid = true;
                        break;
                    }
                }
            }
        }
    }

    if ( ! $valid && $stop ) {
        wp_die( 'Invalid nonce.', 'Error', [ 'response' => 403 ] );
    }
    return $valid;
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

function get_posts( array $args = [] ): array {
    return [];
}

function wp_generate_password( int $length = 12, bool $special_chars = true, bool $extra_special_chars = false ): string {
    $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
    if ( $special_chars ) {
        $chars .= '!@#$%^&*()-_ []{}<>~`+=,.;:/?|';
    }
    if ( $extra_special_chars ) {
        $chars .= '\'"';
    }
    $password = '';
    $max = strlen( $chars ) - 1;
    for ( $i = 0; $i < $length; $i++ ) {
        $password .= $chars[ random_int( 0, $max ) ];
    }
    return $password;
}

function get_user_by( string $field, string|int $value ): WP_User|false {
    if ( function_exists( 'metis_auth_find_user' ) ) {
        $row = metis_auth_find_user( $field, $value );
        if ( is_array( $row ) ) {
            return new WP_User( metis_auth_user_row_to_session( $row ) );
        }
    }

    global $wpdb;
    if ( ! isset( $wpdb ) || ! is_object( $wpdb ) || ! isset( $wpdb->prefix ) ) {
        return false;
    }

    $candidates = [];
    $prefixed = (string) $wpdb->prefix . 'users';
    if ( $prefixed !== 'users' ) {
        $candidates[] = $prefixed;
    }
    $candidates[] = 'users';

    $users_table = $prefixed;
    foreach ( array_unique( $candidates ) as $candidate ) {
        $found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $candidate ) );
        if ( is_string( $found ) && $found === $candidate ) {
            $users_table = $candidate;
            break;
        }
    }
    $column = match ( $field ) {
        'id' => 'ID',
        'login' => 'user_login',
        'email' => 'user_email',
        default => '',
    };

    if ( $column === '' ) {
        return false;
    }

    $placeholder = $column === 'ID' ? '%d' : '%s';
    $row = $wpdb->get_row(
        $wpdb->prepare( "SELECT ID, user_login, user_email, display_name, user_pass FROM {$users_table} WHERE {$column} = {$placeholder} LIMIT 1", $value ),
        ARRAY_A
    );
    if ( ! is_array( $row ) ) {
        return false;
    }

    return new WP_User( [
        'ID' => (int) ( $row['ID'] ?? 0 ),
        'user_login' => (string) ( $row['user_login'] ?? '' ),
        'user_email' => (string) ( $row['user_email'] ?? '' ),
        'display_name' => (string) ( $row['display_name'] ?? '' ),
        'user_pass' => (string) ( $row['user_pass'] ?? '' ),
        'roles' => [],
    ] );
}

function get_userdata( int $user_id ): WP_User|false {
    return get_user_by( 'id', $user_id );
}

function wp_set_current_user( int $user_id ): WP_User|false {
    $user = get_user_by( 'id', $user_id );
    if ( ! $user instanceof WP_User ) {
        unset( $GLOBALS['metis_current_user_override'] );
        return false;
    }

    $GLOBALS['metis_current_user_override'] = $user;
    return $user;
}

function wp_check_password( string $password, string $hash, int|string $user_id = '' ): bool {
    if ( function_exists( 'metis_auth_check_password' ) ) {
        return metis_auth_check_password( $password, $hash, $user_id );
    }
    if ( $hash === '' ) {
        return false;
    }
    return password_verify( $password, $hash ) || hash_equals( $hash, $password );
}

function is_wp_error( mixed $value ): bool {
    return $value instanceof WP_Error;
}

function metis_runtime_http_request( string $method, string $url, array $args = [] ): array|WP_Error {
    $headers = [];
    foreach ( (array) ( $args['headers'] ?? [] ) as $name => $value ) {
        $headers[] = $name . ': ' . $value;
    }

    $ch = curl_init( $url );
    if ( $ch === false ) {
        return new WP_Error( 'http_init_failed', 'cURL init failed.' );
    }

    curl_setopt_array( $ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => (int) ( $args['timeout'] ?? 20 ),
        CURLOPT_CUSTOMREQUEST => strtoupper( $method ),
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_HEADER => true,
    ] );

    if ( array_key_exists( 'body', $args ) ) {
        curl_setopt( $ch, CURLOPT_POSTFIELDS, $args['body'] );
    }

    $response = curl_exec( $ch );
    if ( $response === false ) {
        $error = curl_error( $ch );
        curl_close( $ch );
        return new WP_Error( 'http_request_failed', $error );
    }

    $status = (int) curl_getinfo( $ch, CURLINFO_RESPONSE_CODE );
    $headerSize = (int) curl_getinfo( $ch, CURLINFO_HEADER_SIZE );
    curl_close( $ch );

    return [
        'response' => [ 'code' => $status ],
        'headers' => [],
        'body' => substr( $response, $headerSize ),
    ];
}

function wp_remote_get( string $url, array $args = [] ): array|WP_Error {
    return metis_runtime_http_request( 'GET', $url, $args );
}

function wp_remote_post( string $url, array $args = [] ): array|WP_Error {
    return metis_runtime_http_request( 'POST', $url, $args );
}

function wp_remote_request( string $url, array $args = [] ): array|WP_Error {
    $method = strtoupper( (string) ( $args['method'] ?? 'GET' ) );
    return metis_runtime_http_request( $method, $url, $args );
}

function wp_remote_retrieve_body( array $response ): string {
    return (string) ( $response['body'] ?? '' );
}

function wp_remote_retrieve_response_code( array $response ): int {
    return (int) ( $response['response']['code'] ?? 0 );
}

function wp_safe_redirect( string $location, int $status = 302 ): never {
    status_header( $status );
    header( 'Location: ' . $location, true, $status );
    exit;
}

function wp_redirect( string $location, int $status = 302 ): never {
    wp_safe_redirect( $location, $status );
}

function get_option( string $key, mixed $default = false ): mixed {
    $options = metis_runtime_json_store_read( 'options.json' );
    return array_key_exists( $key, $options ) ? $options[ $key ] : $default;
}

function update_option( string $key, mixed $value, bool $autoload = true ): bool {
    $options = metis_runtime_json_store_read( 'options.json' );
    $options[ $key ] = $value;
    metis_runtime_json_store_write( 'options.json', $options );
    return true;
}

function delete_option( string $key ): bool {
    $options = metis_runtime_json_store_read( 'options.json' );
    unset( $options[ $key ] );
    metis_runtime_json_store_write( 'options.json', $options );
    return true;
}

function get_transient( string $key ): mixed {
    $store = metis_runtime_json_store_read( 'transients.json' );
    $row = $store[ $key ] ?? null;
    if ( ! is_array( $row ) ) {
        return false;
    }
    if ( (int) ( $row['expires_at'] ?? 0 ) < time() ) {
        unset( $store[ $key ] );
        metis_runtime_json_store_write( 'transients.json', $store );
        return false;
    }
    return $row['value'] ?? false;
}

function set_transient( string $key, mixed $value, int $expiration ): bool {
    $store = metis_runtime_json_store_read( 'transients.json' );
    $store[ $key ] = [
        'value' => $value,
        'expires_at' => time() + max( 1, $expiration ),
    ];
    metis_runtime_json_store_write( 'transients.json', $store );
    return true;
}

function delete_transient( string $key ): bool {
    $store = metis_runtime_json_store_read( 'transients.json' );
    unset( $store[ $key ] );
    metis_runtime_json_store_write( 'transients.json', $store );
    return true;
}

function wp_next_scheduled( string $hook ): int|false {
    $events = get_option( 'metis_scheduled_events', [] );
    return isset( $events[ $hook ]['timestamp'] ) ? (int) $events[ $hook ]['timestamp'] : false;
}

function wp_schedule_event( int $timestamp, string $recurrence, string $hook ): bool {
    $events = get_option( 'metis_scheduled_events', [] );
    $events[ $hook ] = [
        'timestamp' => $timestamp,
        'recurrence' => $recurrence,
    ];
    update_option( 'metis_scheduled_events', $events, false );
    return true;
}

function wp_mail( string|array $to, string $subject, string $message, array|string $headers = [] ): bool {
    $recipients = is_array( $to ) ? implode( ',', $to ) : $to;
    return @mail( $recipients, $subject, $message, is_array( $headers ) ? implode( "\r\n", $headers ) : $headers );
}

function add_rewrite_tag( string $tag, string $regex ): void {}
function add_rewrite_rule( string $regex, string $query, string $position = 'bottom' ): void {}
function flush_rewrite_rules( bool $hard = true ): void {}
function register_activation_hook( string $file, callable|string $callback ): void {}
function register_deactivation_hook( string $file, callable|string $callback ): void {}
function show_admin_bar( bool $show ): bool { return $show; }

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

function wp_register_style( string $handle, string $src, array $deps = [], ?string $ver = null ): void {
    metis_runtime_register_style( $handle, $src, $deps, $ver );
}

function wp_register_script( string $handle, string $src, array $deps = [], ?string $ver = null, bool $in_footer = true ): void {
    metis_runtime_register_script( $handle, $src, $deps, $ver, $in_footer );
}

function wp_enqueue_style( string $handle, string $src = '', array $deps = [], ?string $ver = null ): void {
    if ( $src !== '' && empty( $GLOBALS['metis_assets']['styles'][ $handle ] ) ) {
        wp_register_style( $handle, $src, $deps, $ver );
    }
    $GLOBALS['metis_assets']['enqueued_styles'][ $handle ] = true;
}

function wp_enqueue_script( string $handle, string $src = '', array $deps = [], ?string $ver = null, bool $in_footer = true ): void {
    if ( $src !== '' && empty( $GLOBALS['metis_assets']['scripts'][ $handle ] ) ) {
        wp_register_script( $handle, $src, $deps, $ver, $in_footer );
    }
    $GLOBALS['metis_assets']['enqueued_scripts'][ $handle ] = true;
}

function wp_add_inline_style( string $handle, string $css ): void {
    $GLOBALS['metis_assets']['inline_styles'][ $handle ][] = $css;
}

function wp_add_inline_script( string $handle, string $script, string $position = 'after' ): void {
    $bucket = $position === 'before' ? 'inline_scripts_before' : 'inline_scripts_after';
    $GLOBALS['metis_assets'][ $bucket ][ $handle ][] = $script;
}

function wp_localize_script( string $handle, string $object_name, array $data ): void {
    wp_add_inline_script( $handle, 'window.' . $object_name . ' = ' . wp_json_encode( $data ) . ';', 'before' );
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
        echo '<link rel="stylesheet" href="' . esc_attr( $src ) . '">' . "\n";
    }
    foreach ( (array) ( $GLOBALS['metis_assets']['inline_styles'][ $handle ] ?? [] ) as $css ) {
        echo "<style>\n" . $css . "\n</style>\n";
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
    foreach ( (array) ( $GLOBALS['metis_assets']['inline_scripts_before'][ $handle ] ?? [] ) as $script ) {
        echo "<script>\n" . $script . "\n</script>\n";
    }
    $src = (string) ( $asset['src'] ?? '' );
    $ver = (string) ( $asset['ver'] ?? '' );
    if ( $src !== '' ) {
        if ( $ver !== '' ) {
            $src .= ( str_contains( $src, '?' ) ? '&' : '?' ) . 'ver=' . rawurlencode( $ver );
        }
        echo '<script src="' . esc_attr( $src ) . '"></script>' . "\n";
    }
    foreach ( (array) ( $GLOBALS['metis_assets']['inline_scripts_after'][ $handle ] ?? [] ) as $script ) {
        echo "<script>\n" . $script . "\n</script>\n";
    }
    $GLOBALS['metis_assets']['scripts_printed'][ $handle ] = true;
}

function wp_head(): void {
    do_action( 'wp_enqueue_scripts' );
    foreach ( array_keys( $GLOBALS['metis_assets']['enqueued_styles'] ) as $handle ) {
        metis_runtime_print_style( (string) $handle );
    }
    foreach ( array_keys( $GLOBALS['metis_assets']['enqueued_scripts'] ) as $handle ) {
        metis_runtime_print_script( (string) $handle, false );
    }
    do_action( 'wp_head' );
}

function wp_footer(): void {
    foreach ( array_keys( $GLOBALS['metis_assets']['enqueued_scripts'] ) as $handle ) {
        metis_runtime_print_script( (string) $handle, true );
    }
    do_action( 'wp_footer' );
}

metis_runtime_register_script( 'jquery', 'https://code.jquery.com/jquery-3.7.1.min.js', [], '3.7.1', false );

final class wpdb {
    public string $prefix = '';
    public int $insert_id = 0;
    public string $last_error = '';
    private mysqli $mysqli;

    public function __construct( string $dbuser, string $dbpassword, string $dbname, string $dbhost = '127.0.0.1', string $prefix = '' ) {
        $socket = (string) metis_runtime_config_get( 'db_socket', '' );
        $port = 3306;
        $host = $dbhost;

        if ( str_contains( $dbhost, ':' ) ) {
            [ $host, $portString ] = explode( ':', $dbhost, 2 );
            $port = (int) $portString;
        }

        $this->mysqli = metis_runtime_connect_mysqli( $host, $dbuser, $dbpassword, $dbname, $port, $socket );

        $charset = (string) metis_runtime_config_get( 'db_charset', 'utf8mb4' );
        $this->mysqli->set_charset( $charset );
        $this->prefix = $prefix;
    }

    public function get_charset_collate(): string {
        $charset = (string) metis_runtime_config_get( 'db_charset', 'utf8mb4' );
        $collate = (string) metis_runtime_config_get( 'db_collation', 'utf8mb4_unicode_ci' );
        return sprintf( 'DEFAULT CHARACTER SET %s COLLATE %s', $charset, $collate );
    }

    public function prepare( string $query, mixed ...$args ): string {
        if ( count( $args ) === 1 && is_array( $args[0] ) ) {
            $args = $args[0];
        }
        $index = 0;
        return preg_replace_callback(
            '/%(%|s|d|f)/',
            function ( array $matches ) use ( &$index, $args ): string {
                $token = $matches[1];
                if ( $token === '%' ) {
                    return '%';
                }
                $value = $args[ $index ] ?? null;
                $index++;
                return match ( $token ) {
                    'd' => (string) (int) $value,
                    'f' => (string) (float) $value,
                    's' => "'" . $this->mysqli->real_escape_string( (string) $value ) . "'",
                    default => "''",
                };
            },
            $query
        ) ?? $query;
    }

    public function query( string $query ): int|bool {
        $this->last_error = '';
        $result = $this->mysqli->query( $query );
        if ( $result === false ) {
            $this->last_error = $this->mysqli->error;
            return false;
        }
        if ( $result instanceof mysqli_result ) {
            $count = $result->num_rows;
            $result->free();
            return $count;
        }
        $this->insert_id = $this->mysqli->insert_id;
        return $this->mysqli->affected_rows;
    }

    public function get_results( string $query, string $output = OBJECT ): array {
        $this->last_error = '';
        $result = $this->mysqli->query( $query );
        if ( $result === false ) {
            $this->last_error = $this->mysqli->error;
            return [];
        }
        $rows = [];
        while ( $row = $result->fetch_assoc() ) {
            $rows[] = $output === ARRAY_A ? $row : (object) $row;
        }
        $result->free();
        return $rows;
    }

    public function get_row( string $query, string $output = OBJECT ): mixed {
        $rows = $this->get_results( $query, $output );
        return $rows[0] ?? null;
    }

    public function get_var( string $query ): mixed {
        $row = $this->get_row( $query, ARRAY_A );
        if ( ! is_array( $row ) || $row === [] ) {
            return null;
        }
        return reset( $row );
    }

    public function get_col( string $query ): array {
        $rows = $this->get_results( $query, ARRAY_A );
        return array_map( static fn ( array $row ) => reset( $row ), $rows );
    }

    public function insert( string $table, array $data, array $format = [] ): int|false {
        return $this->write( 'INSERT', $table, $data );
    }

    public function replace( string $table, array $data, array $format = [] ): int|false {
        return $this->write( 'REPLACE', $table, $data );
    }

    private function write( string $verb, string $table, array $data ): int|false {
        $columns = array_keys( $data );
        $values = array_map(
            fn ( mixed $value ): string => $value === null ? 'NULL' : "'" . $this->mysqli->real_escape_string( (string) $value ) . "'",
            array_values( $data )
        );
        $sql = sprintf(
            '%s INTO %s (%s) VALUES (%s)',
            $verb,
            $table,
            implode( ',', array_map( fn ( string $col ): string => '`' . $col . '`', $columns ) ),
            implode( ',', $values )
        );
        $result = $this->query( $sql );
        return $result === false ? false : $result;
    }

    public function update( string $table, array $data, array $where, array $format = [], array $where_format = [] ): int|false {
        $set = [];
        foreach ( $data as $column => $value ) {
            $set[] = '`' . $column . '`=' . ( $value === null ? 'NULL' : "'" . $this->mysqli->real_escape_string( (string) $value ) . "'" );
        }
        $conditions = [];
        foreach ( $where as $column => $value ) {
            $conditions[] = '`' . $column . '`=' . ( $value === null ? 'NULL' : "'" . $this->mysqli->real_escape_string( (string) $value ) . "'" );
        }
        return $this->query( sprintf( 'UPDATE %s SET %s WHERE %s', $table, implode( ',', $set ), implode( ' AND ', $conditions ) ) );
    }

    public function delete( string $table, array $where, array $where_format = [] ): int|false {
        $conditions = [];
        foreach ( $where as $column => $value ) {
            $conditions[] = '`' . $column . '`=' . ( $value === null ? 'NULL' : "'" . $this->mysqli->real_escape_string( (string) $value ) . "'" );
        }
        return $this->query( sprintf( 'DELETE FROM %s WHERE %s', $table, implode( ' AND ', $conditions ) ) );
    }

    public function esc_like( string $text ): string {
        return addcslashes( $text, '_%\\' );
    }
}

function dbDelta( string $sql ): void {
    global $wpdb;
    foreach ( preg_split( '/;\s*(?:\r?\n|$)/', trim( $sql ) ) as $statement ) {
        $statement = trim( (string) $statement );
        if ( $statement !== '' ) {
            $wpdb->query( $statement );
        }
    }
}

function metis_runtime_connect_mysqli(
    string $host,
    string $user,
    string $password,
    string $database,
    int $port = 3306,
    string $socket = ''
): mysqli {
    mysqli_report( MYSQLI_REPORT_OFF );

    $attempts = [];
    $candidates = [];

    if ( $socket !== '' ) {
        $candidates[] = [ 'host' => 'localhost', 'port' => 0, 'socket' => $socket, 'label' => 'socket:' . $socket ];
    }

    $candidates[] = [ 'host' => $host, 'port' => $port, 'socket' => null, 'label' => $host . ':' . $port ];

    if ( $host === 'localhost' ) {
        $candidates[] = [ 'host' => '127.0.0.1', 'port' => $port, 'socket' => null, 'label' => '127.0.0.1:' . $port ];
    } elseif ( $host === '127.0.0.1' ) {
        $candidates[] = [ 'host' => 'localhost', 'port' => $port, 'socket' => null, 'label' => 'localhost:' . $port ];
    }

    foreach ( $candidates as $candidate ) {
        $mysqli = mysqli_init();
        mysqli_options( $mysqli, MYSQLI_OPT_CONNECT_TIMEOUT, 3 );
        $ok = @mysqli_real_connect(
            $mysqli,
            (string) $candidate['host'],
            $user,
            $password,
            $database,
            (int) $candidate['port'],
            $candidate['socket']
        );

        if ( $ok ) {
            return $mysqli;
        }

        $attempts[] = [
            'target' => (string) $candidate['label'],
            'errno' => mysqli_connect_errno(),
            'error' => mysqli_connect_error(),
        ];
    }

    throw new RuntimeException( 'Database connection failed: ' . wp_json_encode( $attempts ) );
}

function metis_add_action( string $hook, callable $callback, int $priority = 10, int $accepted_args = 1 ): bool {
    return add_action( $hook, $callback, $priority, $accepted_args );
}

function metis_add_filter( string $hook, callable $callback, int $priority = 10, int $accepted_args = 1 ): bool {
    return add_filter( $hook, $callback, $priority, $accepted_args );
}

function metis_remove_filter( string $hook, callable $callback, int $priority = 10 ): bool {
    return remove_filter( $hook, $callback, $priority );
}

function metis_has_action( string $hook ): bool {
    return has_action( $hook );
}

function metis_do_action( string $hook, mixed ...$args ): void {
    do_action( $hook, ...$args );
}

function metis_apply_filters( string $hook, mixed $value, mixed ...$args ): mixed {
    return apply_filters( $hook, $value, ...$args );
}

function metis_unslash( mixed $value ): mixed {
    return wp_unslash( $value );
}

function metis_parse_url( string $url, int $component = -1 ): mixed {
    return wp_parse_url( $url, $component );
}

function metis_json_encode( mixed $value, int $flags = 0 ): string|false {
    return wp_json_encode( $value, $flags );
}

function metis_parse_args( mixed $args, array $defaults = [] ): array {
    return wp_parse_args( $args, $defaults );
}

function metis_timezone(): DateTimeZone {
    return wp_timezone();
}

function metis_date( string $format, ?int $timestamp = null, ?DateTimeZone $timezone = null ): string {
    return wp_date( $format, $timestamp, $timezone );
}

function metis_generate_uuid(): string {
    return wp_generate_uuid4();
}

function metis_make_dir( string $target ): bool {
    return wp_mkdir_p( $target );
}

function metis_current_user(): WP_User {
    return wp_get_current_user();
}

function metis_user_logged_in(): bool {
    return is_user_logged_in();
}

function metis_current_user_id(): int {
    return get_current_user_id();
}

function metis_current_user_can( string $capability ): bool {
    return current_user_can( $capability );
}

function metis_session_token(): string {
    return wp_get_session_token();
}

function metis_login_url( string $redirect = '' ): string {
    return function_exists( 'metis_auth_login_url' ) ? metis_auth_login_url( $redirect ) : home_url( '/login' );
}

function metis_logout_url( string $redirect = '' ): string {
    $url = function_exists( 'metis_auth_logout_url' ) ? metis_auth_logout_url() : home_url( '/logout' );
    if ( $redirect !== '' ) {
        $url = add_query_arg( [ 'redirect_to' => $redirect ], $url );
    }
    return $url;
}

function metis_doing_ajax(): bool {
    return wp_doing_ajax();
}

function metis_die( string $message = '', string $title = '', array $args = [] ): never {
    wp_die( $message, $title, $args );
}

function metis_send_json( array $payload, int $status_code = 200 ): never {
    wp_send_json( $payload, $status_code );
}

function metis_send_json_success( array $data = [], int $status_code = 200 ): never {
    wp_send_json_success( $data, $status_code );
}

function metis_send_json_error( mixed $data = [], int $status_code = 400 ): never {
    wp_send_json_error( $data, $status_code );
}

function metis_create_nonce( string $action = '' ): string {
    return wp_create_nonce( $action );
}

function metis_verify_nonce( string $nonce, string $action = '' ): bool {
    return wp_verify_nonce( $nonce, $action );
}

function metis_nonce_field( string $action = '', string $name = '_wpnonce' ): void {
    wp_nonce_field( $action, $name );
}

function metis_check_referer( string $action = '', string $query_arg = '_wpnonce' ): bool {
    return check_admin_referer( $action, $query_arg );
}

function metis_generate_password( int $length = 12, bool $special_chars = true, bool $extra_special_chars = false ): string {
    return wp_generate_password( $length, $special_chars, $extra_special_chars );
}

function metis_get_user_by( string $field, string|int $value ): WP_User|false {
    return get_user_by( $field, $value );
}

function metis_get_user_data( int $user_id ): WP_User|false {
    return get_userdata( $user_id );
}

function metis_set_current_user( int $user_id ): WP_User|false {
    return wp_set_current_user( $user_id );
}

function metis_check_password( string $password, string $hash, int|string $user_id = '' ): bool {
    return wp_check_password( $password, $hash, $user_id );
}

function metis_is_error( mixed $value ): bool {
    return is_wp_error( $value );
}

function metis_remote_get( string $url, array $args = [] ): array|WP_Error {
    return wp_remote_get( $url, $args );
}

function metis_remote_post( string $url, array $args = [] ): array|WP_Error {
    return wp_remote_post( $url, $args );
}

function metis_remote_request( string $url, array $args = [] ): array|WP_Error {
    return wp_remote_request( $url, $args );
}

function metis_remote_retrieve_body( array $response ): string {
    return wp_remote_retrieve_body( $response );
}

function metis_remote_retrieve_response_code( array $response ): int {
    return wp_remote_retrieve_response_code( $response );
}

function metis_redirect( string $location, int $status = 302 ): never {
    wp_safe_redirect( $location, $status );
}

function metis_next_scheduled( string $hook ): int|false {
    return wp_next_scheduled( $hook );
}

function metis_schedule_event( int $timestamp, string $recurrence, string $hook ): bool {
    return wp_schedule_event( $timestamp, $recurrence, $hook );
}

function metis_mail( string|array $to, string $subject, string $message, array|string $headers = [] ): bool {
    return wp_mail( $to, $subject, $message, $headers );
}

function metis_register_style( string $handle, string $src, array $deps = [], ?string $ver = null ): void {
    wp_register_style( $handle, $src, $deps, $ver );
}

function metis_register_script( string $handle, string $src, array $deps = [], ?string $ver = null, bool $in_footer = true ): void {
    wp_register_script( $handle, $src, $deps, $ver, $in_footer );
}

function metis_enqueue_style( string $handle, string $src = '', array $deps = [], ?string $ver = null ): void {
    wp_enqueue_style( $handle, $src, $deps, $ver );
}

function metis_enqueue_script( string $handle, string $src = '', array $deps = [], ?string $ver = null, bool $in_footer = true ): void {
    wp_enqueue_script( $handle, $src, $deps, $ver, $in_footer );
}

function metis_add_inline_style( string $handle, string $css ): void {
    wp_add_inline_style( $handle, $css );
}

function metis_add_inline_script( string $handle, string $script, string $position = 'after' ): void {
    wp_add_inline_script( $handle, $script, $position );
}

function metis_localize_script( string $handle, string $object_name, array $data ): void {
    wp_localize_script( $handle, $object_name, $data );
}

function metis_head(): void {
    wp_head();
}

function metis_footer(): void {
    wp_footer();
}

function metis_strip_all_tags( string $text, bool $remove_breaks = false ): string {
    $filtered = strip_tags( $text );
    if ( ! $remove_breaks ) {
        return $filtered;
    }

    return trim( preg_replace( '/[\r\n\t ]+/', ' ', $filtered ) ?? '' );
}

function metis_kses_post( string $html ): string {
    return $html;
}

function metis_normalize_path( string $path ): string {
    $normalized = str_replace( '\\', '/', $path );
    return preg_replace( '#/+#', '/', $normalized ) ?? $normalized;
}

function esc_url_raw( string $url ): string {
    $sanitized = filter_var( $url, FILTER_SANITIZE_URL );
    return is_string( $sanitized ) ? $sanitized : '';
}

function metis_basename( string $path ): string {
    return basename( $path );
}

function metis_trim_words( string $text, int $num_words = 55, string $more = '...' ): string {
    $words = preg_split( '/\s+/', trim( metis_strip_all_tags( $text, true ) ) ) ?: [];
    if ( count( $words ) <= $num_words ) {
        return implode( ' ', $words );
    }

    return implode( ' ', array_slice( $words, 0, $num_words ) ) . $more;
}

function metis_rand( int $min = 0, int $max = 0 ): int {
    return random_int( $min, $max );
}

function metis_check_filetype( string $filename, ?array $mimes = null ): array {
    $extension = strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) );
    $types = metis_normalize_mime_map( $mimes ?? [
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
        'svg' => 'image/svg+xml',
        'pdf' => 'application/pdf',
        'txt' => 'text/plain',
        'csv' => 'text/csv',
        'json' => 'application/json',
        'doc' => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xls' => 'application/vnd.ms-excel',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'ppt' => 'application/vnd.ms-powerpoint',
        'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'zip' => 'application/zip',
    ] );

    return [
        'ext' => $extension,
        'type' => $types[ $extension ] ?? '',
    ];
}

function metis_store_upload_bits( string $name, string $bits, array $allowed_mimes = [] ): array {
    if ( $bits === '' ) {
        return [
            'file' => '',
            'url' => '',
            'type' => '',
            'error' => 'Uploaded file is empty.',
        ];
    }

    $uploads = metis_upload_dir();
    if ( ! empty( $uploads['error'] ) ) {
        return [
            'file' => '',
            'url' => '',
            'type' => '',
            'error' => (string) $uploads['error'],
        ];
    }

    $normalized_mimes = metis_normalize_mime_map( $allowed_mimes );
    $detected_mime = metis_detect_binary_mime_type( $bits );
    if ( $detected_mime === '' ) {
        return [
            'file' => '',
            'url' => '',
            'type' => '',
            'error' => 'Unable to determine uploaded file type.',
        ];
    }

    if ( ! empty( $normalized_mimes ) && ! in_array( $detected_mime, $normalized_mimes, true ) ) {
        return [
            'file' => '',
            'url' => '',
            'type' => '',
            'error' => 'Uploaded file type is not allowed.',
        ];
    }

    $filename = metis_generate_upload_filename( $name, $detected_mime, $normalized_mimes );
    $path = rtrim( (string) $uploads['path'], '/' ) . '/' . $filename;
    $url = rtrim( (string) $uploads['url'], '/' ) . '/' . rawurlencode( $filename );
    $written = file_put_contents( $path, $bits );
    if ( $written === false ) {
        return [
            'file' => '',
            'url' => '',
            'type' => '',
            'error' => 'Failed to write upload.',
        ];
    }

    @chmod( $path, 0644 );

    return [
        'file' => $path,
        'url' => $url,
        'type' => $detected_mime,
        'error' => false,
    ];
}

function metis_handle_upload( array $file, array $overrides = [] ): array {
    if ( empty( $file['tmp_name'] ) || empty( $file['name'] ) ) {
        return [ 'error' => 'Invalid upload payload.' ];
    }

    $tmp = (string) $file['tmp_name'];
    $is_uploaded_file = function_exists( 'is_uploaded_file' ) && is_uploaded_file( $tmp );
    if ( ! $is_uploaded_file && ! is_file( $tmp ) ) {
        return [ 'error' => 'Uploaded file not found.' ];
    }

    $normalized_mimes = metis_normalize_mime_map( $overrides['mimes'] ?? [] );
    $detected_mime = metis_detect_file_mime_type( $tmp );
    if ( $detected_mime === '' ) {
        return [ 'error' => 'Unable to determine uploaded file type.' ];
    }
    if ( ! empty( $normalized_mimes ) && ! in_array( $detected_mime, $normalized_mimes, true ) ) {
        return [ 'error' => 'Uploaded file type is not allowed.' ];
    }

    $uploads = metis_upload_dir();
    if ( ! empty( $uploads['error'] ) ) {
        return [ 'error' => (string) $uploads['error'] ];
    }

    $filename = metis_generate_upload_filename( (string) $file['name'], $detected_mime, $normalized_mimes );
    $destination = trailingslashit( (string) $uploads['path'] ) . $filename;
    $path_info = pathinfo( $destination );
    $counter = 1;
    while ( is_file( $destination ) ) {
        $base = (string) ( $path_info['filename'] ?? 'upload' );
        $ext = isset( $path_info['extension'] ) ? '.' . $path_info['extension'] : '';
        $destination = trailingslashit( (string) $uploads['path'] ) . $base . '-' . $counter . $ext;
        $counter++;
    }

    $moved = $is_uploaded_file ? @move_uploaded_file( $tmp, $destination ) : @rename( $tmp, $destination );
    if ( ! $moved && ! @copy( $tmp, $destination ) ) {
        return [ 'error' => 'Failed to move uploaded file.' ];
    }

    @chmod( $destination, 0644 );

    return [
        'file' => $destination,
        'url' => trailingslashit( (string) $uploads['url'] ) . basename( $destination ),
        'type' => $detected_mime,
    ];
}
