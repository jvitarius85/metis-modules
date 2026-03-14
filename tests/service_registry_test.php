<?php
declare(strict_types=1);

define('ABSPATH', dirname(__DIR__) . '/');
define('METIS_STANDALONE', true);
define('METIS_PATH', dirname(__DIR__) . '/');

if (!function_exists('sanitize_key')) {
    function sanitize_key(string $key): string {
        $key = strtolower($key);
        return preg_replace('/[^a-z0-9_\\-]/', '', $key) ?? '';
    }
}

if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field(string $value): string {
        return trim($value);
    }
}

if (!function_exists('trailingslashit')) {
    function trailingslashit(string $path): string {
        return rtrim($path, '/') . '/';
    }
}

if (!function_exists('current_user_can')) {
    function current_user_can(string $capability): bool {
        return false;
    }
}

if (!function_exists('get_bloginfo')) {
    function get_bloginfo(string $show): string {
        return 'UTF-8';
    }
}

if (!function_exists('metis_add_action')) {
    function metis_add_action(string $hook, callable $callback, int $priority = 10): void {}
}

if (!function_exists('metis_add_filter')) {
    function metis_add_filter(string $hook, callable $callback, int $priority = 10): void {}
}

if (!class_exists('Metis_Logger')) {
    final class Metis_Logger {
        public static function debug(string $message, array $context = []): void {}
        public static function info(string $message, array $context = []): void {}
        public static function warn(string $message, array $context = []): void {}
        public static function error(string $message, array $context = []): void {}
        public static function log_path(): string { return '/tmp/metis-test.log'; }
        public static function module(string $slug): void {}
        public static function module_registered(string $slug): void {}
        public static function boot_end(): void {}
    }
}

if (!class_exists('Metis_Tables')) {
    final class Metis_Tables {
        public static function get(string $key): string {
            return 'metis_' . $key;
        }
    }
}

if (!class_exists('Core_Settings_Service')) {
    final class Core_Settings_Service {
        private static array $data = [];

        public static function init(): void {}
        public static function preload(): void {}
        public static function get(string $key, mixed $default = null): mixed { return self::$data[$key] ?? $default; }
        public static function set(string $key, mixed $value, bool $autoload = true): bool { self::$data[$key] = $value; return true; }
        public static function delete(string $key): bool { unset(self::$data[$key]); return true; }
        public static function has(string $key): bool { return array_key_exists($key, self::$data); }
        public static function all(): array { return self::$data; }
    }
}

require_once dirname(__DIR__) . '/includes/core/bootstrap.php';
metis_core_bootstrap(['http', 'service_registry', 'router']);

function assert_true(bool $condition, string $message = 'Assertion failed'): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function assert_same(mixed $expected, mixed $actual, string $message = 'Values are not equal'): void {
    if ($expected !== $actual) {
        throw new RuntimeException($message . ' Expected ' . var_export($expected, true) . ' got ' . var_export($actual, true));
    }
}

$tests = [];

$tests['singleton_services_resolve_once'] = function (): void {
    $registry = new Metis_Service_Registry();
    $count = 0;
    $registry->singleton('demo', static function () use (&$count): stdClass {
        $count++;
        return (object) ['count' => $count];
    });

    $first = $registry->get('demo');
    $second = $registry->get('demo');

    assert_true($first === $second, 'Singleton should return the same instance.');
    assert_same(1, $count, 'Singleton factory should run once.');
};

$tests['metis_facade_exposes_registered_services'] = function (): void {
    Metis::set_registry(new Metis_Service_Registry());
    Metis::singleton('settings', static fn (): Metis_Settings_Service_Adapter => new Metis_Settings_Service_Adapter());

    Metis::service('settings')->set('portal_name', 'Metis');
    assert_same('Metis', Metis::service('settings')->get('portal_name'), 'Facade should return registered service.');
};

$tests['module_loader_and_permissions_are_registry_backed'] = function (): void {
    Metis::set_registry(new Metis_Service_Registry());
    metis_register_core_services();

    /** @var Metis_Module_Loader_Service $modules */
    $modules = Metis::service('modules');
    $modules->register('people', '/tmp/people', [
        'permissions' => [
            'view' => [
                'key' => 'people.view',
                'action' => 'view',
                'name' => 'People View',
                'roles' => ['board'],
            ],
            'edit' => [
                'key' => 'people.edit',
                'action' => 'edit',
                'name' => 'People Edit',
                'roles' => ['administrator'],
            ],
        ],
        'permission_definitions' => [
            [
                'key' => 'people.view',
                'module' => 'people',
                'action' => 'view',
                'name' => 'People View',
                'roles' => ['board'],
            ],
            [
                'key' => 'people.edit',
                'module' => 'people',
                'action' => 'edit',
                'name' => 'People Edit',
                'roles' => ['administrator'],
            ],
        ],
    ]);

    $resolved = Metis::service('permissions')->can('people', 'view', ['roles' => ['board']]);
    $denied = Metis::service('permissions')->can('people', 'edit', ['roles' => ['board']]);

    assert_true(isset($modules->all()['people']), 'Registered module should be available from the loader service.');
    assert_true($resolved, 'Permissions service should allow configured roles.');
    assert_true(!$denied, 'Permissions service should deny roles not configured for the permission.');
};

$tests['module_loader_normalizes_manifest_permission_definitions'] = function (): void {
    $normalized = Metis_Module_Loader_Service::normalizeManifestPermissions('people', [
        'view' => ['board'],
        'workspace_manage' => [
            'key' => 'people.workspace_manage',
            'action' => 'workspace_manage',
            'name' => 'People Workspace Manage',
            'roles' => ['administrator', 'workspace_manager'],
        ],
    ]);

    assert_same(['board'], $normalized['roles']['view'] ?? null, 'Legacy action role lists should remain usable for access checks.');
    assert_same(['administrator', 'workspace_manager'], $normalized['roles']['workspace_manage'] ?? null, 'Object permissions should expose role coverage by action.');
    assert_same('people.view', $normalized['definitions'][0]['key'] ?? null, 'Missing keys should default to module.action.');
    assert_same('People View', $normalized['definitions'][0]['name'] ?? null, 'Missing permission names should be generated from module and action.');
    assert_same('people.workspace_manage', $normalized['definitions'][1]['key'] ?? null, 'Explicit manifest permission keys should be preserved.');
};

$tests['event_bus_dispatches_exact_and_wildcard_listeners_in_priority_order'] = function (): void {
    Metis::set_registry(new Metis_Service_Registry());
    metis_register_core_services();

    $trace = [];

    metis_subscribe_event('newsletter.*', static function (Metis_Event $event) use (&$trace): void {
        $trace[] = 'wildcard:' . $event->name();
    }, 5);
    metis_subscribe_event('newsletter.sent', static function (Metis_Event $event) use (&$trace): void {
        $trace[] = 'exact:' . (string) $event->payload('email');
    }, 10);
    metis_subscribe_event('newsletter.sent', static function () use (&$trace): void {
        $trace[] = 'late';
    }, 20);

    $event = metis_publish_event('newsletter.sent', ['email' => 'person@example.com']);

    assert_same(['wildcard:newsletter.sent', 'exact:person@example.com', 'late'], $trace, 'Event bus should dispatch wildcard and exact listeners by ascending priority.');
    assert_same('newsletter.sent', $event->name(), 'Published event should keep its normalized name.');
    assert_same('person@example.com', $event->payload('email'), 'Published event should expose payload values.');
    assert_same([], $event->errors(), 'Successful listeners should not record event errors.');
};

$tests['job_queue_services_register_workers_and_resolve_queue_singletons'] = function (): void {
    Metis::set_registry(new Metis_Service_Registry());
    metis_register_core_services();

    $workers = metis_job_workers();
    $jobs = metis_job_queue();

    $workers->register('demo.job', static function (array $payload, array $job): array {
        return [
            'payload' => $payload,
            'job_id' => (int) ($job['id'] ?? 0),
        ];
    });

    assert_true($workers === Metis::service('job_workers'), 'Job worker registry should be a shared singleton.');
    assert_true($jobs === Metis::service('jobs'), 'Job queue should be a shared singleton.');
    assert_true($workers->has('demo.job'), 'Registered worker should be discoverable.');
    assert_same(
        ['payload' => ['x' => 1], 'job_id' => 7],
        $workers->run('demo.job', ['x' => 1], ['id' => 7]),
        'Worker registry should dispatch handlers with payload and job metadata.'
    );
};

$tests['module_loader_boots_manifest_services_and_routes'] = function (): void {
    $root = sys_get_temp_dir() . '/metis-module-manifest-' . bin2hex(random_bytes(4));
    $module_dir = $root . '/demo';
    $services_dir = $module_dir . '/services';

    mkdir($services_dir, 0777, true);

    file_put_contents($module_dir . '/demo.json', json_encode([
        'slug' => 'demo',
        'views' => [
            'dashboard' => 'dashboard.php',
        ],
        'permissions' => [
            'view' => [
                'key' => 'demo.view',
                'action' => 'view',
                'name' => 'Demo View',
                'roles' => ['board'],
            ],
        ],
        'services' => [
            'services/demo.php',
        ],
        'routes' => [
            [
                'name' => 'demo.route',
                'methods' => ['GET'],
                'pattern' => '#^/demo/(?P<id>[0-9]+)$#',
                'handler' => 'metis_demo_manifest_handler',
            ],
        ],
    ], JSON_PRETTY_PRINT));
    file_put_contents($module_dir . '/bootstrap.php', "<?php\n\$GLOBALS['metis_demo_bootstrap_loaded'] = true;\n");
    file_put_contents($module_dir . '/services/demo.php', "<?php\nfunction metis_demo_manifest_handler(Metis_Http_Request \$request): Metis_Http_Response { return Metis_Http_Response::html((string) \$request->attribute('id', 'missing')); }\n");

    Metis::set_registry(new Metis_Service_Registry());
    Metis::singleton('logger', static fn (): Metis_Logger_Service => new Metis_Logger_Service());
    Metis::singleton('modules', static function () use ($root): Metis_Module_Loader_Service {
        return new Metis_Module_Loader_Service($root);
    });

    /** @var Metis_Module_Loader_Service $modules */
    $modules = Metis::service('modules');
    $modules->boot();

    assert_true(isset($modules->all()['demo']), 'Manifest module should be registered from JSON.');
    assert_true(function_exists('metis_demo_manifest_handler'), 'Manifest service file should be autoloaded.');
    assert_true(!empty($GLOBALS['metis_demo_bootstrap_loaded']), 'Module bootstrap should still execute after manifest registration.');
    assert_same('demo', $modules->routes()[0]['module'] ?? null, 'Manifest route should be attributed to the module.');
    assert_same('demo.view', $modules->declaredPermissions()[0]['key'] ?? null, 'Manifest permissions should be available for downstream syncing.');

    $router = new Metis_Http_Router();
    metis_register_manifest_module_routes($router);
    $response = $router->dispatch(new Metis_Http_Request('GET', '/demo/42', '/demo/42'));

    assert_same(200, $response->status(), 'Manifest route should dispatch through the shared router.');
    assert_same('42', $response->body(), 'Manifest route should hydrate named regex captures.');
};

$tests['module_loader_registers_manifest_event_listeners'] = function (): void {
    $root = sys_get_temp_dir() . '/metis-module-events-' . bin2hex(random_bytes(4));
    $module_dir = $root . '/demo';
    $services_dir = $module_dir . '/services';

    mkdir($services_dir, 0777, true);

    file_put_contents($module_dir . '/demo.json', json_encode([
        'slug' => 'demo',
        'services' => [
            'services/demo.php',
        ],
        'listeners' => [
            [
                'event' => 'user.created',
                'handler' => 'metis_demo_user_created_listener',
                'priority' => 8,
            ],
        ],
    ], JSON_PRETTY_PRINT));
    file_put_contents($module_dir . '/bootstrap.php', "<?php\n");
    file_put_contents($module_dir . '/services/demo.php', "<?php\nfunction metis_demo_user_created_listener(Metis_Event \$event): void { \$GLOBALS['metis_demo_user_events'][] = ['name' => \$event->name(), 'email' => \$event->payload('email')]; }\n");

    Metis::set_registry(new Metis_Service_Registry());
    Metis::singleton('logger', static fn (): Metis_Logger_Service => new Metis_Logger_Service());
    Metis::singleton('events', static fn (): Metis_Event_Bus => new Metis_Event_Bus());
    Metis::singleton('modules', static function () use ($root): Metis_Module_Loader_Service {
        return new Metis_Module_Loader_Service($root);
    });

    Metis::service('modules')->boot();
    metis_publish_event('user.created', ['email' => 'listener@example.com']);

    assert_same(
        [['name' => 'user.created', 'email' => 'listener@example.com']],
        $GLOBALS['metis_demo_user_events'] ?? [],
        'Manifest listeners should subscribe module handlers to the shared event bus.'
    );
};

$tests['router_service_dispatches_through_registered_builder'] = function (): void {
    Metis::set_registry(new Metis_Service_Registry());
    metis_register_core_services();

    /** @var Metis_Router_Service $router */
    $router = Metis::service('router');
    $router->set_builder(static function (): Metis_Http_Router {
        $instance = new Metis_Http_Router();
        $instance->register(
            'test.route',
            ['GET'],
            static fn (Metis_Http_Request $request): ?array => $request->path() === '/ok' ? [] : null,
            static fn (Metis_Http_Request $request): Metis_Http_Response => Metis_Http_Response::html('ok')
        );
        return $instance;
    });

    $response = $router->dispatch(new Metis_Http_Request('GET', '/ok', '/ok'));
    assert_same(200, $response->status(), 'Router service should dispatch requests.');
    assert_same('ok', $response->body(), 'Router service should return handler response.');
};

$tests['http_router_applies_global_and_group_middleware_in_order'] = function (): void {
    $router = new Metis_Http_Router();
    $router->register_middleware('global.audit', static function (Metis_Http_Request $request, callable $next): Metis_Http_Response {
        $trace = (array) $request->attribute('trace', []);
        $trace[] = 'global';
        return $next($request->with_attribute('trace', $trace));
    });
    $router->register_middleware('auth.require', static function (Metis_Http_Request $request, callable $next): Metis_Http_Response {
        $trace = (array) $request->attribute('trace', []);
        $trace[] = 'group';
        return $next($request->with_attribute('trace', $trace)->with_attribute('auth_checked', true));
    });
    $router->register_middleware('request.normalize', static function (Metis_Http_Request $request, callable $next): Metis_Http_Response {
        $trace = (array) $request->attribute('trace', []);
        $trace[] = 'route';
        return $next($request->with_attribute('trace', $trace)->with_attribute('normalized', true));
    });
    $router->register_middleware_group('secured.stack', ['auth.require']);
    $router->push_global_middleware('global.audit');

    $router->group(['secured.stack'], static function (Metis_Http_Router $router): void {
        $router->register(
            'test.middleware',
            ['GET'],
            static fn (Metis_Http_Request $request): ?array => $request->path() === '/pipeline' ? [] : null,
            static function (Metis_Http_Request $request): Metis_Http_Response {
                return new Metis_Http_Response(200, ['Content-Type' => 'application/json'], json_encode([
                    'trace' => $request->attribute('trace', []),
                    'auth_checked' => $request->attribute('auth_checked', false),
                    'normalized' => $request->attribute('normalized', false),
                ]) ?: '{}');
            },
            ['request.normalize']
        );
    });

    $response = $router->dispatch(new Metis_Http_Request('GET', '/pipeline', '/pipeline'));
    $payload = json_decode($response->body(), true);

    assert_same(200, $response->status(), 'Middleware pipeline should still dispatch successfully.');
    assert_same(['global', 'group', 'route'], $payload['trace'] ?? [], 'Middleware should execute global, group, then route middleware in order.');
    assert_true(!empty($payload['auth_checked']), 'Group middleware should be able to decorate the request.');
    assert_true(!empty($payload['normalized']), 'Route middleware should run before the handler.');
};

$tests['http_router_resolves_nested_middleware_groups'] = function (): void {
    $router = new Metis_Http_Router();
    $router->register_middleware('one', static function (Metis_Http_Request $request, callable $next): Metis_Http_Response {
        $trace = (array) $request->attribute('trace', []);
        $trace[] = 'one';
        return $next($request->with_attribute('trace', $trace));
    });
    $router->register_middleware('two', static function (Metis_Http_Request $request, callable $next): Metis_Http_Response {
        $trace = (array) $request->attribute('trace', []);
        $trace[] = 'two';
        return $next($request->with_attribute('trace', $trace));
    });
    $router->register_middleware_group('inner.stack', ['two']);
    $router->register_middleware_group('outer.stack', ['one', 'inner.stack']);
    $router->register(
        'test.nested.groups',
        ['GET'],
        static fn (Metis_Http_Request $request): ?array => $request->path() === '/groups' ? [] : null,
        static fn (Metis_Http_Request $request): Metis_Http_Response => new Metis_Http_Response(
            200,
            ['Content-Type' => 'application/json'],
            json_encode([
                'trace' => $request->attribute('trace', []),
            ]) ?: '{}'
        ),
        ['outer.stack']
    );

    $response = $router->dispatch(new Metis_Http_Request('GET', '/groups', '/groups'));
    $payload = json_decode($response->body(), true);

    assert_same(['one', 'two'], $payload['trace'] ?? [], 'Nested middleware groups should flatten into the route pipeline.');
};

foreach ($tests as $name => $test) {
    $test();
}

echo "Service registry verified.\n";
