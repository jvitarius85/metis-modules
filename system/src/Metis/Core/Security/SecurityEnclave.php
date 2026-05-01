<?php
if ( ! defined( 'METIS_ROOT' ) && ! defined( 'METIS_STANDALONE' ) ) {
    define( 'METIS_STANDALONE', true );
}

interface Metis_Security_Audit_Logger_Interface {
    public function audit( string $event, array $context = [] ): void;
    public function security( string $event, array $context = [] ): void;
}

interface Metis_Security_Session_Store_Interface {
    public function is_valid( string $session_id, array $actor, array $context = [] ): bool;
}

interface Metis_Security_Rate_Limiter_Interface {
    public function consume( string $bucket, int $limit, int $window_seconds ): bool;
}

interface Metis_Security_Nonce_Verifier_Interface {
    public function is_valid( string $nonce, string $nonce_key, array $context = [] ): bool;
}

interface Metis_Security_Database_Gateway_Interface {
    public function select( string $statement, array $params = [], array $context = [] ): mixed;
    public function insert( string $target, array $payload, array $context = [] ): mixed;
    public function update( string $target, array $payload, array $where, array $context = [] ): mixed;
    public function delete( string $target, array $where, array $context = [] ): mixed;
    public function execute( string $statement, array $params = [], array $context = [] ): mixed;
}

interface Metis_Security_File_Gateway_Interface {
    public function read( string $path, array $context = [] ): string;
    public function write( string $path, string $contents, array $context = [] ): void;
    public function delete( string $path, array $context = [] ): void;
    public function exists( string $path, array $context = [] ): bool;
}

interface Metis_Security_Module_Gateway_Interface {
    public function dispatch( string $module, string $action, array $payload = [], array $context = [] ): mixed;
}

final class Metis_Security_Enclave_Exception extends RuntimeException {
    public function __construct(
        string $message,
        private readonly string $code_name = 'security_error',
        private readonly int $status = 400,
        private readonly array $context = []
    ) {
        parent::__construct( $message );
    }

    public function code_name(): string {
        return $this->code_name;
    }

    public function status(): int {
        return $this->status;
    }

    public function context(): array {
        return $this->context;
    }
}

final class Metis_Security_Policy {
    public function __construct(
        public readonly string $operation,
        public readonly ?string $module = null,
        public readonly string $permission = 'view',
        public readonly bool $require_authentication = true,
        public readonly bool $require_session = true,
        public readonly bool $require_nonce = true,
        public readonly ?string $nonce_key = null,
        public readonly int $rate_limit = 120,
        public readonly int $rate_window_seconds = 60
    ) {}
}

final class Metis_Security_Gateway_Bundle {
    public function __construct(
        public readonly Metis_Security_Database_Gateway_Interface $db,
        public readonly Metis_Security_File_Gateway_Interface $files,
        public readonly Metis_Security_Module_Gateway_Interface $modules
    ) {}
}

final class Metis_Security_Null_Logger implements Metis_Security_Audit_Logger_Interface {
    public function audit( string $event, array $context = [] ): void {}
    public function security( string $event, array $context = [] ): void {}
}

final class Metis_Security_Array_Session_Store implements Metis_Security_Session_Store_Interface {
    public function is_valid( string $session_id, array $actor, array $context = [] ): bool {
        return $session_id !== '';
    }
}

final class Metis_Security_Fixed_Window_Rate_Limiter implements Metis_Security_Rate_Limiter_Interface {
    private array $windows = [];

    public function consume( string $bucket, int $limit, int $window_seconds ): bool {
        $window_seconds = max( 1, $window_seconds );
        $limit          = max( 1, $limit );
        $window_start   = (int) floor( time() / $window_seconds ) * $window_seconds;

        if ( ! isset( $this->windows[ $bucket ] ) || $this->windows[ $bucket ]['start'] !== $window_start ) {
            $this->windows[ $bucket ] = [
                'start' => $window_start,
                'count' => 0,
            ];
        }

        $this->windows[ $bucket ]['count']++;
        return $this->windows[ $bucket ]['count'] <= $limit;
    }
}

final class Metis_Security_Null_Nonce_Verifier implements Metis_Security_Nonce_Verifier_Interface {
    public function is_valid( string $nonce, string $nonce_key, array $context = [] ): bool {
        return $nonce !== '' && $nonce_key !== '';
    }
}

final class Metis_Security_No_Database_Gateway implements Metis_Security_Database_Gateway_Interface {
    public function select( string $statement, array $params = [], array $context = [] ): mixed {
        throw new Metis_Security_Enclave_Exception( 'No database gateway configured.', 'db_gateway_missing', 500 );
    }
    public function insert( string $target, array $payload, array $context = [] ): mixed {
        throw new Metis_Security_Enclave_Exception( 'No database gateway configured.', 'db_gateway_missing', 500 );
    }
    public function update( string $target, array $payload, array $where, array $context = [] ): mixed {
        throw new Metis_Security_Enclave_Exception( 'No database gateway configured.', 'db_gateway_missing', 500 );
    }
    public function delete( string $target, array $where, array $context = [] ): mixed {
        throw new Metis_Security_Enclave_Exception( 'No database gateway configured.', 'db_gateway_missing', 500 );
    }
    public function execute( string $statement, array $params = [], array $context = [] ): mixed {
        throw new Metis_Security_Enclave_Exception( 'No database gateway configured.', 'db_gateway_missing', 500 );
    }
}

final class Metis_Security_No_File_Gateway implements Metis_Security_File_Gateway_Interface {
    public function read( string $path, array $context = [] ): string {
        throw new Metis_Security_Enclave_Exception( 'No file gateway configured.', 'file_gateway_missing', 500 );
    }
    public function write( string $path, string $contents, array $context = [] ): void {
        throw new Metis_Security_Enclave_Exception( 'No file gateway configured.', 'file_gateway_missing', 500 );
    }
    public function delete( string $path, array $context = [] ): void {
        throw new Metis_Security_Enclave_Exception( 'No file gateway configured.', 'file_gateway_missing', 500 );
    }
    public function exists( string $path, array $context = [] ): bool {
        throw new Metis_Security_Enclave_Exception( 'No file gateway configured.', 'file_gateway_missing', 500 );
    }
}

final class Metis_Security_No_Module_Gateway implements Metis_Security_Module_Gateway_Interface {
    public function dispatch( string $module, string $action, array $payload = [], array $context = [] ): mixed {
        throw new Metis_Security_Enclave_Exception( 'No module gateway configured.', 'module_gateway_missing', 500 );
    }
}

final class Metis_Security_Enclave {
    private array $policies = [];
    private array $trusted_stack = [];
    private $permission_resolver;

    public function __construct(
        private readonly Metis_Security_Audit_Logger_Interface $logger,
        private readonly Metis_Security_Session_Store_Interface $sessions,
        private readonly Metis_Security_Rate_Limiter_Interface $rate_limiter,
        private readonly Metis_Security_Nonce_Verifier_Interface $nonce_verifier,
        private readonly Metis_Security_Gateway_Bundle $gateways,
        ?callable $permission_resolver = null
    ) {
        $this->permission_resolver = $permission_resolver;
    }

    public function register_policy( Metis_Security_Policy $policy ): void {
        $this->policies[ $policy->operation ] = $policy;
    }

    public function has_policy( string $operation ): bool {
        return isset( $this->policies[ $operation ] );
    }

    public function policy( string $operation ): Metis_Security_Policy {
        if ( ! $this->has_policy( $operation ) ) {
            throw new Metis_Security_Enclave_Exception(
                'Unregistered enclave operation.',
                'operation_not_registered',
                403,
                [ 'operation' => $operation ]
            );
        }

        return $this->policies[ $operation ];
    }

    public function execute( string $operation, array $request, callable $callback ): mixed {
        $policy = $this->policy( $operation );
        $actor  = $this->sanitize_actor( $request['actor'] ?? [] );
        $meta   = $this->sanitize_meta( $request['meta'] ?? [] );
        $input  = $this->sanitize_input( $request['input'] ?? [] );

        $this->assert_authenticated( $policy, $actor, $operation );
        $this->assert_session( $policy, $actor, $meta, $operation );
        $this->assert_nonce( $policy, $actor, $input, $meta, $operation );
        $this->assert_rate_limit( $policy, $actor, $meta, $operation );
        $this->assert_permission( $policy, $actor, $meta, $operation );

        $context = [
            'operation' => $operation,
            'policy'    => $policy,
            'actor'     => $actor,
            'meta'      => $meta,
            'input'     => $input,
        ];

        $this->logger->audit( 'enclave.approved', $this->log_context( $context ) );
        $this->trusted_stack[] = $context;

        try {
            return $callback( $input, $context, $this->gateways );
        } catch ( Metis_Security_Enclave_Exception $e ) {
            $this->logger->security( 'enclave.denied_runtime', $this->log_context( $context, [
                'error'   => $e->getMessage(),
                'code'    => $e->code_name(),
                'status'  => $e->status(),
                'details' => $e->context(),
            ] ) );
            throw $e;
        } finally {
            array_pop( $this->trusted_stack );
        }
    }

    public function require_trusted_context( string $kind, array $extra = [] ): array {
        $context = end( $this->trusted_stack );
        if ( ! is_array( $context ) ) {
            throw new Metis_Security_Enclave_Exception(
                sprintf( '%s access attempted outside enclave trusted path.', ucfirst( $kind ) ),
                'untrusted_execution',
                403,
                $extra
            );
        }

        return $context;
    }

    public function db(): Metis_Security_Database_Gateway_Interface {
        $this->require_trusted_context( 'database' );
        return $this->gateways->db;
    }

    public function files(): Metis_Security_File_Gateway_Interface {
        $this->require_trusted_context( 'file' );
        return $this->gateways->files;
    }

    public function modules(): Metis_Security_Module_Gateway_Interface {
        $this->require_trusted_context( 'module' );
        return $this->gateways->modules;
    }

    public function audit(): Metis_Security_Audit_Logger_Interface {
        $this->require_trusted_context( 'audit' );
        return $this->logger;
    }

    public function permissions(): mixed {
        $this->require_trusted_context( 'permission' );

        if ( class_exists( 'Metis' ) && Metis::has_service( 'permissions' ) ) {
            return Metis::service( 'permissions' );
        }

        return null;
    }

    public function sanitize_input( mixed $value ): mixed {
        if ( is_array( $value ) ) {
            $clean = [];
            foreach ( $value as $key => $item ) {
                $clean_key          = is_string( $key ) ? preg_replace( '/[^a-zA-Z0-9_\-\.]/', '', $key ) : $key;
                $clean[ $clean_key ] = $this->sanitize_input( $item );
            }
            return $clean;
        }

        if ( is_string( $value ) ) {
            $value = trim( $value );
            $value = str_replace( "\0", '', $value );
        }

        return $value;
    }

    private function sanitize_actor( mixed $actor ): array {
        if ( ! is_array( $actor ) ) {
            return [];
        }

        return [
            'id'          => isset( $actor['id'] ) ? (string) $actor['id'] : '',
            'roles'       => array_values( array_filter( array_map( 'strval', (array) ( $actor['roles'] ?? [] ) ) ) ),
            'permissions' => array_values( array_filter( array_map( 'strval', (array) ( $actor['permissions'] ?? [] ) ) ) ),
            'session_id'  => isset( $actor['session_id'] ) ? (string) $actor['session_id'] : '',
        ];
    }

    private function sanitize_meta( mixed $meta ): array {
        if ( ! is_array( $meta ) ) {
            return [];
        }

        return [
            'ip'         => isset( $meta['ip'] ) ? (string) $meta['ip'] : '',
            'user_agent' => isset( $meta['user_agent'] ) ? (string) $meta['user_agent'] : '',
            'request_id' => isset( $meta['request_id'] ) ? (string) $meta['request_id'] : bin2hex( random_bytes( 8 ) ),
        ];
    }

    private function assert_authenticated( Metis_Security_Policy $policy, array $actor, string $operation ): void {
        if ( ! $policy->require_authentication ) {
            return;
        }

        if ( ( $actor['id'] ?? '' ) === '' ) {
            $this->record_threat_signal( 'failed_login', $actor, [], [], $operation, 10 );
            $this->logger->security( 'enclave.denied_authentication', [ 'operation' => $operation ] );
            throw new Metis_Security_Enclave_Exception( 'Authentication required.', 'authentication_required', 401 );
        }
    }

    private function assert_session( Metis_Security_Policy $policy, array $actor, array $meta, string $operation ): void {
        if ( ! $policy->require_session ) {
            return;
        }

        $session_id = (string) ( $actor['session_id'] ?? '' );
        if ( $session_id === '' || ! $this->sessions->is_valid( $session_id, $actor, $meta ) ) {
            $this->record_threat_signal( 'invalid_session', $actor, $meta, [], $operation, 20 );
            $this->logger->security( 'enclave.denied_session', [
                'operation' => $operation,
                'actor_id'  => $actor['id'] ?? '',
            ] );
            throw new Metis_Security_Enclave_Exception( 'Invalid session.', 'invalid_session', 401 );
        }
    }

    private function assert_nonce( Metis_Security_Policy $policy, array $actor, array $input, array $meta, string $operation ): void {
        if ( ! $policy->require_nonce ) {
            return;
        }

        $nonce_values = [];
        foreach ( [ 'metis_action_nonce', 'security', 'nonce' ] as $field ) {
            if ( ! isset( $input[ $field ] ) || ! is_string( $input[ $field ] ) ) {
                continue;
            }
            $candidate = trim( (string) $input[ $field ] );
            if ( $candidate === '' ) {
                continue;
            }
            $nonce_values[] = $candidate;
        }
        $nonce_values = array_values( array_unique( $nonce_values ) );

        $nonce_keys = [];
        $nonce_key = $policy->nonce_key ?? $operation;
        if ( is_string( $nonce_key ) && trim( $nonce_key ) !== '' ) {
            $nonce_keys[] = trim( $nonce_key );
        }

        // Module editors may submit module/core nonce values alongside
        // action-scoped nonce fields.
        if ( str_starts_with( $operation, 'ajax.website.metis_website_' ) ) {
            $nonce_keys[] = 'metis_website';
            $nonce_keys[] = 'metis_core';
        } elseif ( str_starts_with( $operation, 'ajax.cms.metis_cms_' ) ) {
            $nonce_keys[] = 'metis_cms';
            $nonce_keys[] = 'metis_core';
        } elseif ( str_starts_with( $operation, 'ajax.newsletter.metis_newsletter_' ) ) {
            $nonce_keys[] = 'metis_newsletter';
            $nonce_keys[] = 'metis_core';
        }
        $nonce_keys = array_values( array_unique( array_filter( $nonce_keys, static fn ( $v ): bool => is_string( $v ) && $v !== '' ) ) );

        $valid = false;
        if ( $nonce_values !== [] && $nonce_keys !== [] ) {
            foreach ( $nonce_values as $nonce_value ) {
                foreach ( $nonce_keys as $key ) {
                    if ( $this->nonce_verifier->is_valid( $nonce_value, $key, $meta ) ) {
                        $valid = true;
                        break 2;
                    }
                }
            }
        }

        if ( ! $valid ) {
            $this->record_threat_signal( 'invalid_nonce', $actor, $meta, $input, $operation, 15 );
            $this->logger->security( 'enclave.denied_nonce', [ 'operation' => $operation ] );
            throw new Metis_Security_Enclave_Exception( 'Nonce verification failed.', 'invalid_nonce', 403 );
        }
    }

    private function assert_rate_limit( Metis_Security_Policy $policy, array $actor, array $meta, string $operation ): void {
        if ( $this->is_loopback_request( $meta ) ) {
            return;
        }

        $this->enforce_threat_threshold( $actor, $meta, [], $operation );
        $subject = (string) ( $actor['id'] ?? $meta['ip'] ?? 'anonymous' );
        $bucket  = sprintf( 'metis:%s:%s', $operation, $subject );

        if ( ! $this->rate_limiter->consume( $bucket, $policy->rate_limit, $policy->rate_window_seconds ) ) {
            $this->record_threat_signal( 'rate_limit_violation', $actor, $meta, [], $operation, 25 );
            $this->logger->security( 'enclave.denied_rate_limit', [
                'operation' => $operation,
                'subject'   => $subject,
            ] );
            throw new Metis_Security_Enclave_Exception( 'Rate limit exceeded.', 'rate_limit_exceeded', 429 );
        }
    }

    private function assert_permission( Metis_Security_Policy $policy, array $actor, array $meta, string $operation ): void {
        if ( ! is_callable( $this->permission_resolver ) ) {
            return;
        }

        $allowed = (bool) call_user_func( $this->permission_resolver, $policy, $actor, $meta );
        if ( $allowed ) {
            return;
        }

        $this->record_threat_signal( 'permission_violation', $actor, $meta, [], $operation, 20 );
        $this->logger->security( 'enclave.denied_permission', [
            'operation'  => $operation,
            'module'     => $policy->module,
            'permission' => $policy->permission,
            'actor_id'   => $actor['id'] ?? '',
        ] );

        throw new Metis_Security_Enclave_Exception( 'Permission denied.', 'permission_denied', 403 );
    }

    private function log_context( array $context, array $extra = [] ): array {
        return array_merge( [
            'operation'  => (string) ( $context['operation'] ?? '' ),
            'module'     => (string) ( $context['policy']->module ?? '' ),
            'permission' => (string) ( $context['policy']->permission ?? '' ),
            'actor_id'   => (string) ( $context['actor']['id'] ?? '' ),
            'request_id' => (string) ( $context['meta']['request_id'] ?? '' ),
            'ip'         => (string) ( $context['meta']['ip'] ?? '' ),
        ], $extra );
    }

    private function security_kernel(): ?\Metis\Core\Security\SecurityKernel {
        if ( ! class_exists( 'Metis' ) || ! Metis::has_service( 'security_kernel' ) ) {
            return null;
        }

        $service = Metis::service( 'security_kernel' );
        return $service instanceof \Metis\Core\Security\SecurityKernel ? $service : null;
    }

    private function security_context( array $actor, array $meta, array $input, string $operation ): ?\Metis\Core\Security\SecurityContext {
        $kernel = $this->security_kernel();
        if ( ! $kernel instanceof \Metis\Core\Security\SecurityKernel ) {
            return null;
        }

        return $kernel->buildContext( $operation, $input, $meta, [
            'id'         => (int) ( $actor['id'] ?? 0 ),
            'roles'      => (array) ( $actor['roles'] ?? [] ),
            'session_id' => (string) ( $actor['session_id'] ?? '' ),
        ] );
    }

    private function threat_level_from_score( int $score, ?\Metis\Core\Security\SecurityKernel $kernel = null ): string {
        $kernel = $kernel ?? $this->security_kernel();
        if ( ! $kernel instanceof \Metis\Core\Security\SecurityKernel ) {
            return 'low';
        }

        return match ( $kernel->threatScores()->responseLevel( $score ) ) {
            'block' => 'high',
            'lockout', 'throttle' => 'medium',
            default => 'low',
        };
    }

    private function enforce_threat_threshold( array $actor, array $meta, array $input, string $operation ): void {
        if ( $this->is_loopback_request( $meta ) ) {
            return;
        }

        $kernel  = $this->security_kernel();
        $context = $this->security_context( $actor, $meta, $input, $operation );
        if ( ! $kernel instanceof \Metis\Core\Security\SecurityKernel || ! $context instanceof \Metis\Core\Security\SecurityContext ) {
            return;
        }

        $score = $kernel->threatScores()->currentScore( $context );
        $level = $this->threat_level_from_score( $score, $kernel );

        if ( $level === 'medium' ) {
            $this->logger->security( 'enclave.threat_medium', [
                'operation' => $operation,
                'score'     => $score,
                'ip'        => (string) ( $meta['ip'] ?? '' ),
            ] );
            return;
        }

        if ( $level === 'high' ) {
            $this->logger->security( 'enclave.threat_blocked', [
                'operation' => $operation,
                'score'     => $score,
                'ip'        => (string) ( $meta['ip'] ?? '' ),
            ] );

            throw new Metis_Security_Enclave_Exception(
                'Threat score blocked request.',
                'threat_score_blocked',
                429,
                [ 'operation' => $operation, 'score' => $score ]
            );
        }
    }

    private function record_threat_signal( string $event, array $actor, array $meta, array $input, string $operation, int $weight ): void {
        if ( $this->is_loopback_request( $meta ) ) {
            return;
        }

        $kernel  = $this->security_kernel();
        $context = $this->security_context( $actor, $meta, $input, $operation );
        if ( ! $kernel instanceof \Metis\Core\Security\SecurityKernel || ! $context instanceof \Metis\Core\Security\SecurityContext ) {
            return;
        }

        $score = $kernel->threatScores()->recordEvent( $event, $context, $weight );
        $level = $this->threat_level_from_score( $score, $kernel );

        if ( $level === 'low' ) {
            return;
        }

        if ( $level === 'medium' ) {
            $this->logger->security( 'enclave.threat_medium', [
                'event'     => $event,
                'operation' => $operation,
                'score'     => $score,
                'ip'        => (string) ( $meta['ip'] ?? '' ),
            ] );
            return;
        }

        throw new Metis_Security_Enclave_Exception(
            'Threat score blocked request.',
            'threat_score_blocked',
            429,
            [ 'event' => $event, 'operation' => $operation, 'score' => $score ]
        );
    }

    private function is_loopback_request( array $meta ): bool {
        $ip = trim( (string) ( $meta['ip'] ?? '' ) );
        if ( $ip === '' ) {
            return false;
        }

        if ( $ip === '::1' || strcasecmp( $ip, 'localhost' ) === 0 ) {
            return true;
        }

        return str_starts_with( $ip, '127.' );
    }
}

final class Metis_Security_Enclave_Container {
    private static ?Metis_Security_Enclave $instance = null;

    public static function set( Metis_Security_Enclave $enclave ): void {
        self::$instance = $enclave;
    }

    public static function get(): Metis_Security_Enclave {
        if ( self::$instance instanceof Metis_Security_Enclave ) {
            return self::$instance;
        }

        self::$instance = new Metis_Security_Enclave(
            new Metis_Security_Null_Logger(),
            new Metis_Security_Array_Session_Store(),
            new Metis_Security_Fixed_Window_Rate_Limiter(),
            new Metis_Security_Null_Nonce_Verifier(),
            new Metis_Security_Gateway_Bundle(
                new Metis_Security_No_Database_Gateway(),
                new Metis_Security_No_File_Gateway(),
                new Metis_Security_No_Module_Gateway()
            )
        );

        return self::$instance;
    }
}

function metis_security_enclave(): Metis_Security_Enclave {
    return Metis_Security_Enclave_Container::get();
}
