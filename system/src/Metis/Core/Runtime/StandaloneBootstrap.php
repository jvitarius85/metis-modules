<?php
declare(strict_types=1);

if ( defined( 'METIS_STANDALONE_RUNTIME_LOADED' ) ) {
    return;
}

define( 'METIS_STANDALONE_RUNTIME_LOADED', true );

require_once dirname( __DIR__ ) . '/Autoload.php';
require_once dirname( __DIR__ ) . '/CoreBootstrap.php';

$metis_runtime_session_security = new \Metis\Core\Services\SessionSecurityService();
$metis_runtime_session_security->startSession();
unset( $metis_runtime_session_security );

if ( ! defined( 'METIS_ROOT' ) ) {
    define( 'METIS_ROOT', dirname( __DIR__, 4 ) . '/' );
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

$GLOBALS['metis_hooks'] = $GLOBALS['metis_hooks'] ?? [];
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

if ( ! class_exists( 'MetisUser' ) ) {
    final class MetisUser {
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
}

final class MetisQueryState {
    public bool $is_404 = false;
    public bool $is_home = false;
    public bool $is_archive = false;
    public bool $is_singular = false;
}

final class MetisError {
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

if ( ! isset( $GLOBALS['metis_query_state'] ) || ! $GLOBALS['metis_query_state'] instanceof MetisQueryState ) {
    $GLOBALS['metis_query_state'] = new MetisQueryState();
}

function metis_runtime_config_get( string $key, mixed $default = null ): mixed {
    return $GLOBALS['metis_runtime_config'][ $key ] ?? $default;
}

function metis_runtime_insecure_app_key_values(): array {
    return [ '', 'metis-local-key', 'changeme', 'change-me', 'default', 'local-key' ];
}

function metis_runtime_is_installer_context(): bool {
    if ( defined( 'METIS_INSTALLER_CONTEXT' ) && METIS_INSTALLER_CONTEXT ) {
        return true;
    }

    if ( ! defined( 'METIS_PATH' ) ) {
        return false;
    }

    $config_path = defined( 'METIS_CONFIG_PATH' )
        ? rtrim( (string) METIS_CONFIG_PATH, '/\\' ) . '/database.php'
        : rtrim( (string) METIS_PATH, '/\\' ) . '/system/config/database.php';

    return ! is_file( rtrim( (string) METIS_PATH, '/\\' ) . '/storage/install.lock' )
        && ! is_file( $config_path );
}

function metis_runtime_is_test_context(): bool {
    $env = strtolower( trim( (string) ( getenv( 'METIS_APP_ENV' ) ?: getenv( 'APP_ENV' ) ?: '' ) ) );
    return in_array( $env, [ 'test', 'testing', 'dev', 'development', 'local' ], true )
        && trim( (string) getenv( 'METIS_TEST_APP_KEY' ) ) !== '';
}

function metis_runtime_configured_app_key(): string {
    $key = trim( (string) metis_runtime_config_get( 'app_key', '' ) );
    if ( $key === '' && metis_runtime_is_test_context() ) {
        $key = trim( (string) getenv( 'METIS_TEST_APP_KEY' ) );
    }

    return $key;
}

function metis_runtime_require_app_key( string $purpose = 'security' ): string {
    $key = metis_runtime_configured_app_key();
    if ( ! in_array( strtolower( $key ), metis_runtime_insecure_app_key_values(), true ) && strlen( $key ) >= 32 ) {
        return $key;
    }

    if ( metis_runtime_is_installer_context() ) {
        if ( session_status() !== PHP_SESSION_ACTIVE ) {
            @session_start();
        }
        if ( empty( $_SESSION['metis_installer_app_key'] ) || ! is_string( $_SESSION['metis_installer_app_key'] ) ) {
            $_SESSION['metis_installer_app_key'] = bin2hex( random_bytes( 32 ) );
        }
        return (string) $_SESSION['metis_installer_app_key'];
    }

    throw new RuntimeException( 'Metis security configuration is missing a strong app_key for ' . $purpose . '.' );
}

function metis_runtime_create_nonce( string $action = '' ): string {
    $secret = metis_runtime_require_app_key( 'nonce generation' );
    $ttl = max( 60, (int) metis_runtime_config_get( 'csrf_ttl', 2 * HOUR_IN_SECONDS ) );
    $issued_at = (int) floor( time() / $ttl ) * $ttl;
    $mac = hash_hmac( 'sha256', $action . '|' . metis_runtime_session_token() . '|' . $issued_at, $secret );

    return $issued_at . ':' . $mac;
}

function metis_runtime_verify_nonce( string $nonce, string $action = '' ): bool {
    $nonce = trim( $nonce );
    if ( $nonce === '' ) {
        return false;
    }

    $secret = metis_runtime_require_app_key( 'nonce verification' );
    $ttl = max( 60, (int) metis_runtime_config_get( 'csrf_ttl', 2 * HOUR_IN_SECONDS ) );

    if ( ! str_contains( $nonce, ':' ) ) {
        return hash_equals( hash_hmac( 'sha256', $action . '|' . metis_runtime_session_token(), $secret ), $nonce );
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

        $expected = hash_hmac( 'sha256', $action . '|' . metis_runtime_session_token() . '|' . $candidate_window, $secret );
        if ( hash_equals( $expected, $mac ) ) {
            return true;
        }
    }

    return false;
}

function metis_runtime_nonce_field( string $action = '', string $name = 'metis_action_nonce' ): void {
    echo '<input type="hidden" name="' . metis_escape_attr( $name ) . '" value="' . metis_escape_attr( metis_runtime_create_nonce( $action ) ) . '">';
}


function get_posts( array $args = [] ): array {
    return [];
}

function metis_runtime_generate_password( int $length = 12, bool $special_chars = true, bool $extra_special_chars = false ): string {
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

function get_user_by( string $field, string|int $value ): MetisUser|false {
    if ( function_exists( 'metis_auth_find_user' ) ) {
        $row = metis_auth_find_user( $field, $value );
        if ( is_array( $row ) ) {
            return new MetisUser( metis_auth_user_row_to_session( $row ) );
        }
    }

    if ( ! function_exists( 'metis_db' ) ) {
        return false;
    }

    try {
        $db         = metis_db();
        $connection = $db->connection();
    } catch ( Throwable ) {
        return false;
    }

    if ( ! is_object( $connection ) || ! isset( $connection->prefix ) ) {
        return false;
    }

    $candidates = [];
    $prefixed = (string) $connection->prefix . 'users';
    if ( $prefixed !== 'users' ) {
        $candidates[] = $prefixed;
    }
    $candidates[] = 'users';

    $users_table = $prefixed;
    foreach ( array_unique( $candidates ) as $candidate ) {
        $found = $db->scalar( 'SHOW TABLES LIKE %s', [ $candidate ] );
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

    $row = $db->fetchOne(
        "SELECT ID, user_login, user_email, display_name, user_pass FROM {$users_table} WHERE {$column} = %s LIMIT 1",
        [ (string) $value ]
    );
    if ( ! is_array( $row ) ) {
        return false;
    }

    return new MetisUser( [
        'ID' => (int) ( $row['ID'] ?? 0 ),
        'user_login' => (string) ( $row['user_login'] ?? '' ),
        'user_email' => (string) ( $row['user_email'] ?? '' ),
        'display_name' => (string) ( $row['display_name'] ?? '' ),
        'user_pass' => (string) ( $row['user_pass'] ?? '' ),
        'roles' => [],
    ] );
}

function get_userdata( int $user_id ): MetisUser|false {
    return get_user_by( 'id', $user_id );
}

function metis_runtime_set_current_user( int $user_id ): MetisUser|false {
    $user = get_user_by( 'id', $user_id );
    if ( ! $user instanceof MetisUser ) {
        unset( $GLOBALS['metis_current_user_override'] );
        return false;
    }

    $GLOBALS['metis_current_user_override'] = $user;
    return $user;
}

function metis_runtime_check_password( string $password, string $hash, int|string $user_id = '' ): bool {
    if ( function_exists( 'metis_auth_check_password' ) ) {
        return metis_auth_check_password( $password, $hash, $user_id );
    }
    if ( $hash === '' ) {
        return false;
    }
    return password_verify( $password, $hash ) || hash_equals( $hash, $password );
}

function metis_runtime_is_error( mixed $value ): bool {
    return $value instanceof MetisError;
}

function metis_runtime_validate_remote_url( string $url ): array|MetisError {
    $parts = parse_url( $url );
    if ( ! is_array( $parts ) ) {
        return new MetisError( 'http_url_invalid', 'HTTP request URL is invalid.' );
    }

    $scheme = strtolower( (string) ( $parts['scheme'] ?? '' ) );
    $host = trim( (string) ( $parts['host'] ?? '' ) );
    if ( $scheme === '' || ! in_array( $scheme, [ 'http', 'https' ], true ) || $host === '' ) {
        return new MetisError( 'http_url_invalid', 'HTTP request URL must be a valid HTTP(S) URL.' );
    }

    if ( isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
        return new MetisError( 'http_url_invalid', 'HTTP request URL must not include credentials.' );
    }

    return $parts;
}

function metis_runtime_http_request( string $method, string $url, array $args = [] ): array|MetisError {
    $validated = metis_runtime_validate_remote_url( $url );
    if ( $validated instanceof MetisError ) {
        return $validated;
    }

    if ( class_exists( '\Metis\Core\Application' ) && \Metis\Core\Application::has_service( 'http' ) ) {
        try {
            $raw_body = $args['body'] ?? '';
            $body_string = is_array( $raw_body ) ? http_build_query( $raw_body ) : (string) $raw_body;
            $response = \Metis\Core\Application::service( 'http' )->request(
                $method,
                $url,
                (array) ( $args['headers'] ?? [] ),
                $body_string
            );

            return [
                'response' => [ 'code' => (int) ( $response['status'] ?? 0 ) ],
                'headers' => [],
                'body' => (string) ( $response['body'] ?? '' ),
            ];
        } catch ( \Throwable $e ) {
            return new MetisError( 'http_request_failed', $e->getMessage() );
        }
    }

    $headers = [];
    foreach ( (array) ( $args['headers'] ?? [] ) as $name => $value ) {
        $headers[] = $name . ': ' . $value;
    }

    $ch = curl_init( $url );
    if ( $ch === false ) {
        return new MetisError( 'http_init_failed', 'cURL init failed.' );
    }

    curl_setopt_array( $ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT => (int) ( $args['timeout'] ?? 20 ),
        CURLOPT_CUSTOMREQUEST => strtoupper( $method ),
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_HEADER => true,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_MAXREDIRS => 0,
    ] );
    if ( defined( 'CURLOPT_PROTOCOLS' ) && defined( 'CURLPROTO_HTTP' ) && defined( 'CURLPROTO_HTTPS' ) ) {
        curl_setopt( $ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS );
    }
    if ( defined( 'CURLOPT_REDIR_PROTOCOLS' ) && defined( 'CURLPROTO_HTTP' ) && defined( 'CURLPROTO_HTTPS' ) ) {
        curl_setopt( $ch, CURLOPT_REDIR_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS );
    }

    if ( array_key_exists( 'body', $args ) ) {
        curl_setopt( $ch, CURLOPT_POSTFIELDS, $args['body'] );
    }

    $response = curl_exec( $ch );
    if ( $response === false ) {
        $error = curl_error( $ch );
        curl_close( $ch );
        return new MetisError( 'http_request_failed', $error );
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

function metis_runtime_remote_get( string $url, array $args = [] ): array|MetisError {
    return metis_runtime_http_request( 'GET', $url, $args );
}

function metis_runtime_remote_post( string $url, array $args = [] ): array|MetisError {
    return metis_runtime_http_request( 'POST', $url, $args );
}

function metis_runtime_remote_request( string $url, array $args = [] ): array|MetisError {
    $method = strtoupper( (string) ( $args['method'] ?? 'GET' ) );
    return metis_runtime_http_request( $method, $url, $args );
}

function metis_runtime_remote_retrieve_body( array $response ): string {
    return (string) ( $response['body'] ?? '' );
}

function metis_runtime_remote_retrieve_response_code( array $response ): int {
    return (int) ( $response['response']['code'] ?? 0 );
}

final class MetisRuntimeDbConnection {
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

function metis_runtime_db_delta( string $sql ): void {
    $db = function_exists( 'metis_db' ) ? metis_db() : null;
    foreach ( preg_split( '/;\s*(?:\r?\n|$)/', trim( $sql ) ) as $statement ) {
        $statement = trim( (string) $statement );
        if ( $statement !== '' && $db !== null ) {
            $db->execute( $statement );
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

    throw new RuntimeException( 'Database connection failed: ' . metis_runtime_json_encode( $attempts ) );
}

if ( ! function_exists( 'metis_on' ) ) {
    function metis_on( string $hook, callable $callback, int $priority = 10, int $accepted_args = 1 ): bool {
        return metis_runtime_listen( $hook, $callback, $priority, $accepted_args );
    }
}

if ( ! function_exists( 'metis_runtime_setup_signature' ) ) {
    function metis_runtime_setup_signature( string $scope, array $files = [] ): string {
        $payload = [
            'scope'   => strtolower( preg_replace( '/[^a-z0-9_]+/i', '_', $scope ) ?? $scope ),
            'version' => defined( 'METIS_VERSION' ) ? (string) METIS_VERSION : 'unknown',
            'files'   => [],
        ];

        foreach ( $files as $file ) {
            $path = (string) $file;
            $payload['files'][] = [
                $path,
                is_file( $path ) ? (int) @filemtime( $path ) : 0,
                is_file( $path ) ? (int) @filesize( $path ) : 0,
            ];
        }

        $json = function_exists( 'metis_runtime_json_encode' )
            ? metis_runtime_json_encode( $payload )
            : ( json_encode( $payload, JSON_UNESCAPED_SLASHES ) ?: serialize( $payload ) );

        return hash( 'sha256', $json );
    }
}

if ( ! function_exists( 'metis_runtime_run_once_per_signature' ) ) {
    function metis_runtime_run_once_per_signature( string $scope, array $files, callable $callback ): void {
        $scope = strtolower( preg_replace( '/[^a-z0-9_]+/i', '_', $scope ) ?? $scope );
        if ( $scope === '' ) {
            $callback();
            return;
        }

        $signature = metis_runtime_setup_signature( $scope, $files );
        $setting   = 'metis_runtime_setup_signatures';

        if ( class_exists( 'Core_Settings_Service', false ) ) {
            $signatures = \Core_Settings_Service::get( $setting, [] );
            if ( is_array( $signatures ) && (string) ( $signatures[ $scope ] ?? '' ) === $signature ) {
                return;
            }
        }

        $callback();

        if ( class_exists( 'Core_Settings_Service', false ) ) {
            $signatures = \Core_Settings_Service::get( $setting, [] );
            if ( ! is_array( $signatures ) ) {
                $signatures = [];
            }
            $signatures[ $scope ] = $signature;
            \Core_Settings_Service::set( $setting, $signatures, true );
        }
    }
}

if ( ! function_exists( 'metis_add_filter' ) ) {
    function metis_add_filter( string $hook, callable $callback, int $priority = 10, int $accepted_args = 1 ): bool {
        return metis_runtime_add_filter( $hook, $callback, $priority, $accepted_args );
    }
}

if ( ! function_exists( 'metis_remove_filter' ) ) {
    function metis_remove_filter( string $hook, callable $callback, int $priority = 10 ): bool {
        return metis_runtime_remove_filter( $hook, $callback, $priority );
    }
}

if ( ! function_exists( 'metis_has_action' ) ) {
    function metis_has_action( string $hook ): bool {
        return metis_runtime_has_action( $hook );
    }
}

if ( ! function_exists( 'metis_do_action' ) ) {
    function metis_do_action( string $hook, mixed ...$args ): void {
        metis_runtime_do_action( $hook, ...$args );
    }
}

if ( ! function_exists( 'metis_apply' ) ) {
    function metis_apply( string $hook, mixed $value, mixed ...$args ): mixed {
        return metis_runtime_filter( $hook, $value, ...$args );
    }
}

if ( ! function_exists( 'esc_url_raw' ) ) {
    function esc_url_raw( string $url ): string {
        $sanitized = filter_var( $url, FILTER_SANITIZE_URL );
        return is_string( $sanitized ) ? $sanitized : '';
    }
}

if ( ! function_exists( 'metis_url_clean' ) ) {
    function metis_url_clean( string $url ): string {
        return esc_url_raw( $url );
    }
}
