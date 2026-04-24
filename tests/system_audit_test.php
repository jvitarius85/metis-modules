<?php
declare(strict_types=1);

if ( PHP_SAPI !== 'cli' ) {
    fwrite( STDERR, "This test must be run from the command line.\n" );
    exit( 1 );
}

$root = dirname( __DIR__ );

define( 'METIS_STANDALONE', true );
define( 'METIS_PREFIX', 'metis' );
define( 'METIS_PATH', $root . '/' );
define( 'METIS_URL', 'http://localhost/metis/' );

$_SERVER['HTTP_HOST'] = $_SERVER['HTTP_HOST'] ?? 'localhost';
$_SERVER['HTTPS'] = $_SERVER['HTTPS'] ?? 'off';
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = '/';

require_once $root . '/src/Metis/Core/CoreBootstrap.php';
metis_define_system_version( $root . '/' );
metis_core_bootstrap( [ 'standalone_bootstrap', 'service_registry', 'ajax', 'router', 'security_runtime_bridge' ] );
metis_standalone_boot();
metis_register_core_services();

use Metis\Http\Request as Metis_Http_Request;

$failures = [];

$assert = static function ( bool $condition, string $message ) use ( &$failures ): void {
    if ( ! $condition ) {
        $failures[] = $message;
    }
};

$scan_for_matches = static function ( array $directories, array $patterns ): array {
    $matches = [];

    foreach ( $directories as $directory ) {
        if ( ! is_dir( $directory ) ) {
            continue;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator( $directory, FilesystemIterator::SKIP_DOTS )
        );

        foreach ( $iterator as $file ) {
            if ( ! $file instanceof SplFileInfo || ! $file->isFile() ) {
                continue;
            }

            $extension = strtolower( $file->getExtension() );
            if ( ! in_array( $extension, [ 'php', 'js' ], true ) ) {
                continue;
            }

            $contents = @file_get_contents( $file->getPathname() );
            if ( ! is_string( $contents ) || $contents === '' ) {
                continue;
            }

            foreach ( $patterns as $pattern ) {
                if ( preg_match_all( $pattern, $contents, $captures, PREG_SET_ORDER ) !== false ) {
                    foreach ( $captures as $capture ) {
                        $token = (string) ( $capture[1] ?? '' );
                        if ( $token === '' ) {
                            continue;
                        }

                        $matches[ $token ][] = $file->getPathname();
                    }
                }
            }
        }
    }

    return $matches;
};

$router = metis_http_router();
$modules = metis_get_modules();
$routes = Metis::service( 'modules' )->routes();
$controllers = metis_ajax_registry()->all();
$handler_registry = metis_ajax_handler_registry();

$assert( $modules !== [], 'No modules loaded during audit bootstrap.' );
$assert( $controllers !== [], 'No AJAX controllers registered during audit bootstrap.' );

if ( function_exists( 'metis_security_register_ajax_policies' ) ) {
    metis_security_register_ajax_policies();
}
$enclave = metis_security_enclave();

foreach ( $routes as $route ) {
    $name = trim( (string) ( $route['name'] ?? '' ) );
    $pattern = (string) ( $route['pattern'] ?? '' );
    $handler = $route['handler'] ?? null;

    $assert( $name !== '', 'Manifest route missing name.' );
    $assert( $pattern !== '', sprintf( 'Manifest route [%s] missing regex pattern.', $name ) );
    $assert( is_callable( $handler ), sprintf( 'Manifest route [%s] handler is not callable.', $name ) );
    $assert( @preg_match( $pattern, '/audit-probe' ) !== false, sprintf( 'Manifest route [%s] has an invalid regex pattern.', $name ) );
}

foreach ( $controllers as $action => $controller ) {
    $assert( $handler_registry->has( $action ), sprintf( 'AJAX action [%s] is missing a handler.', $action ) );

    $module = metis_key_clean( (string) ( $controller['module'] ?? '' ) );
    if ( $module === '' ) {
        $module = (string) ( function_exists( 'metis_security_infer_module_from_ajax_action' ) ? metis_security_infer_module_from_ajax_action( $action ) : '' );
    }

    $assert( $module !== '', sprintf( 'AJAX action [%s] does not resolve to a module slug.', $action ) );
    if ( $module !== '' ) {
        $assert(
            $enclave->has_policy( sprintf( 'ajax.%s.%s', $module, $action ) ),
            sprintf( 'AJAX action [%s] is missing its enclave policy.', $action )
        );
    }
}

$client_action_refs = $scan_for_matches(
    [
        $root . '/assets',
        $root . '/modules',
    ],
    [
        '/formData\.set\(\s*[\'"]action[\'"]\s*,\s*[\'"](metis_[a-z0-9_]+)[\'"]/i',
        '/[\'"]action[\'"]\s*:\s*[\'"](metis_[a-z0-9_]+)[\'"]/i',
    ]
);

foreach ( $client_action_refs as $action => $paths ) {
    $assert(
        isset( $controllers[ $action ] ),
        sprintf( 'Client AJAX action [%s] is referenced but not registered. Seen in: %s', $action, implode( ', ', array_slice( array_values( array_unique( $paths ) ), 0, 3 ) ) )
    );
}

$probe = static function (
    string $method,
    string $path,
    array $body = [],
    array $headers = [],
    array $server = []
) use ( $router ): int {
    $request = new Metis_Http_Request(
        $method,
        $path,
        $path,
        [],
        $body,
        array_change_key_case( $headers, CASE_LOWER ),
        [],
        [],
        $server,
        '',
        []
    );

    return $router->dispatch( $request )->status();
};

$ajax_probe_action = array_key_first( $controllers );
if ( is_string( $ajax_probe_action ) && $ajax_probe_action !== '' ) {
    $status = $probe(
        'POST',
        '/api/ajax',
        [ 'action' => $ajax_probe_action, 'nonce' => 'audit-nonce' ],
        [
            'Origin' => 'http://localhost',
            'Referer' => 'http://localhost/metis',
        ],
        [
            'HTTP_HOST' => 'localhost',
            'HTTP_ORIGIN' => 'http://localhost',
            'HTTP_REFERER' => 'http://localhost/metis',
            'REQUEST_METHOD' => 'POST',
        ]
    );
    $assert( $status !== 404, sprintf( 'AJAX endpoint did not resolve for probe action [%s].', $ajax_probe_action ) );
}

$static_endpoint_statuses = [
    '/api/system/version' => $probe( 'GET', '/api/system/version' ),
    '/api/auth/resolve' => $probe( 'POST', '/api/auth/resolve', [ 'nonce' => 'audit-nonce' ], [], [ 'REQUEST_METHOD' => 'POST' ] ),
    '/api/auth/passkeys/begin' => $probe( 'POST', '/api/auth/passkeys/begin', [ 'nonce' => 'audit-nonce' ], [], [ 'REQUEST_METHOD' => 'POST' ] ),
    '/api/auth/passkeys/complete' => $probe( 'POST', '/api/auth/passkeys/complete', [ 'nonce' => 'audit-nonce' ], [], [ 'REQUEST_METHOD' => 'POST' ] ),
    '/api/auth/session/keepalive' => $probe( 'GET', '/api/auth/session/keepalive' ),
];

foreach ( $static_endpoint_statuses as $path => $status ) {
    $assert( $status !== 404, sprintf( 'Core endpoint [%s] did not resolve.', $path ) );
}

$forbidden_wp_tokens = $scan_for_matches(
    [
        $root . '/src',
        $root . '/modules',
        $root . '/tools',
    ],
    [
        '/\b(wp_strip_all_tags|wp_rand|wp_mail|add_action|do_action|has_action|apply_filters)\b/',
    ]
);

$compatibility_runtime_paths = [];

foreach ( $forbidden_wp_tokens as $token => $paths ) {
    $allow = false;
    foreach ( $paths as $path ) {
        if (
            str_contains( $path, '/Core/Runtime/StandaloneBootstrap.php' )
            || str_contains( $path, '/Core/Runtime/HooksRuntime.php' )
            || in_array( $path, $compatibility_runtime_paths, true )
        ) {
            $allow = true;
            break;
        }
    }
    if ( $allow ) {
        continue;
    }

    $assert( false, sprintf( 'Forbidden WordPress runtime token [%s] still present. Seen in: %s', $token, implode( ', ', array_slice( array_values( array_unique( $paths ) ), 0, 5 ) ) ) );
}

$compatibility_aliases = [
    'sanitize_key' => 'metis_key_clean',
    'sanitize_text_field' => 'metis_text_clean',
    'sanitize_textarea_field' => 'metis_textarea_clean',
    'sanitize_email' => 'metis_email_clean',
    'is_email' => 'metis_email_is_valid',
    'sanitize_title' => 'metis_slug_clean',
    'sanitize_file_name' => 'metis_filename_clean',
    'sanitize_hex_color' => 'metis_hex_color_clean',
    'esc_html' => 'metis_escape_html',
    'esc_attr' => 'metis_escape_attr',
    'esc_url' => 'metis_escape_url',
    'esc_url_raw' => 'metis_url_clean',
    'selected' => 'metis_attr_selected',
    'checked' => 'metis_attr_checked',
    'status_header' => 'metis_send_status',
    'add_shortcode' => 'metis_shortcode_register',
    'shortcode_atts' => 'metis_shortcode_defaults',
    'do_shortcode' => 'metis_shortcode_render',
];

foreach ( $compatibility_aliases as $legacy => $modern ) {
    $assert(
        function_exists( $modern ),
        sprintf( 'Compatibility modernization alias [%s] is missing for legacy helper [%s].', $modern, $legacy )
    );
}

$modernized_shared_paths = [
    $root . '/src/Metis/Core/Api/Batch/BatchController.php',
    $root . '/src/Metis/Core/Api/Batch/BatchValidator.php',
    $root . '/src/Metis/Core/Ajax/AjaxRuntime.php',
    $root . '/src/Metis/Core/AuditRuntime.php',
    $root . '/src/Metis/Core/AssetsRuntime.php',
    $root . '/src/Metis/Core/Auth/AuthRuntime.php',
    $root . '/src/Metis/Core/CoreHelpers.php',
    $root . '/src/Metis/Core/Cron/CronRuntime.php',
    $root . '/src/Metis/Core/DatabaseRuntime.php',
    $root . '/src/Metis/Core/Editor/BlockRegistry.php',
    $root . '/src/Metis/Core/Editor/Blocks/button/render.php',
    $root . '/src/Metis/Core/Editor/Blocks/button_group/render.php',
    $root . '/src/Metis/Core/Editor/Blocks/campaign_description_block/render.php',
    $root . '/src/Metis/Core/Editor/Blocks/cta/render.php',
    $root . '/src/Metis/Core/Editor/Blocks/divider/render.php',
    $root . '/src/Metis/Core/Editor/Blocks/form/render.php',
    $root . '/src/Metis/Core/Editor/Blocks/grid/render.php',
    $root . '/src/Metis/Core/Editor/Blocks/heading/render.php',
    $root . '/src/Metis/Core/Editor/Blocks/hero/render.php',
    $root . '/src/Metis/Core/Editor/Blocks/image/render.php',
    $root . '/src/Metis/Core/Editor/Blocks/image_content/render.php',
    $root . '/src/Metis/Core/Editor/Blocks/list/render.php',
    $root . '/src/Metis/Core/Editor/Blocks/progress_bar_block/render.php',
    $root . '/src/Metis/Core/Editor/Blocks/spacer/render.php',
    $root . '/src/Metis/Core/Editor/Blocks/text/render.php',
    $root . '/src/Metis/Core/Editor/EditorContextPolicy.php',
    $root . '/src/Metis/Core/EntityCatalog.php',
    $root . '/src/Metis/Core/Error/ErrorLogger.php',
    $root . '/src/Metis/Core/Error/ErrorKernel.php',
    $root . '/src/Metis/Core/HelpService.php',
    $root . '/src/Metis/Core/Integrations/StripeImportHandler.php',
    $root . '/src/Metis/Core/Integrations/StripeWebhookRuntime.php',
    $root . '/src/Metis/Core/IntegrityRuntime.php',
    $root . '/src/Metis/Core/Kernel/Runtime.php',
    $root . '/src/Metis/Core/LoggerRuntime.php',
    $root . '/src/Metis/Core/ManagerRuntime.php',
    $root . '/src/Metis/Core/ModuleLoader.php',
    $root . '/src/Metis/Core/Modules/ModuleValidator.php',
    $root . '/src/Metis/Core/RenameTablesRuntime.php',
    $root . '/src/Metis/Core/Routing/RouterRuntime.php',
    $root . '/src/Metis/Http/Router.php',
    $root . '/src/Metis/Core/Runtime/RequestRuntime.php',
    $root . '/src/Metis/Core/Runtime/ShellTemplate.php',
    $root . '/src/Metis/Core/Runtime/SidebarLayout.php',
    $root . '/src/Metis/Core/Runtime/SidebarModuleLayout.php',
    $root . '/src/Metis/Core/Runtime/StandaloneApplicationBootstrap.php',
    $root . '/src/Metis/Core/Runtime/UploadsRuntime.php',
    $root . '/src/Metis/Core/Security/AuthProtectionService.php',
    $root . '/src/Metis/Core/Security/BehaviorProfiler.php',
    $root . '/src/Metis/Core/Security/Guards/ProgressiveDelayGuard.php',
    $root . '/src/Metis/Core/Security/SecurityContext.php',
    $root . '/src/Metis/Core/Security/SecurityRuntimeBridge.php',
    $root . '/src/Metis/Core/Security/ThreatScoreStore.php',
    $root . '/src/Metis/Core/ServiceRegistry.php',
    $root . '/src/Metis/Core/Services/CredentialService.php',
    $root . '/src/Metis/Core/Services/CsrfService.php',
    $root . '/src/Metis/Core/Services/EmailService.php',
    $root . '/src/Metis/Core/Services/GitHubUpdateService.php',
    $root . '/src/Metis/Core/Services/MaintenanceService.php',
    $root . '/src/Metis/Core/Services/NavigationService.php',
    $root . '/src/Metis/Core/Services/OperationsService.php',
    $root . '/src/Metis/Core/Services/QuickActionsRegistryService.php',
    $root . '/src/Metis/Core/Services/ReleaseExecutionService.php',
    $root . '/src/Metis/Core/Services/SchedulerService.php',
    $root . '/src/Metis/Core/Services/UploadPolicyService.php',
    $root . '/src/Metis/Core/WalkthroughService.php',
    $root . '/src/Metis/Core/Webhooks/WebhookRuntime.php',
    $root . '/src/Metis/Backup/BackupService.php',
    $root . '/src/Metis/Modules/Board/Support.php',
    $root . '/src/Metis/Modules/Board/WorkspaceService.php',
    $root . '/src/Metis/Modules/Calendar/CalendarModule.php',
    $root . '/src/Metis/Modules/Calendar/Settings.php',
    $root . '/src/Metis/Modules/Calendar/SyncStore.php',
    $root . '/src/Metis/Modules/CommunicationsInbound/InboundMessageNormalizer.php',
    $root . '/src/Metis/Modules/CommunicationsInbound/MailboxRepository.php',
    $root . '/src/Metis/Modules/CommunicationsInbound/Settings.php',
    $root . '/src/Metis/Modules/CommunicationsInbound/WorkspaceGoogleService.php',
    $root . '/src/Metis/Modules/Donations/DonationsModule.php',
    $root . '/src/Metis/Modules/Finance/FinanceService.php',
    $root . '/src/Metis/Modules/Finance/SchemaManager.php',
    $root . '/src/Metis/Modules/Finance/FinanceV2Service.php',
    $root . '/src/Metis/Modules/Forms/Concerns/SharedRepositoryLogic.php',
    $root . '/src/Metis/Modules/Forms/FormRenderer.php',
    $root . '/src/Metis/Modules/Forms/FormSubmissionRepository.php',
    $root . '/src/Metis/Modules/Forms/FormsModule.php',
    $root . '/src/Metis/Modules/GrandyStash/GrandyStashRepository.php',
    $root . '/src/Metis/Modules/GrandyStash/GrandyStashModule.php',
    $root . '/src/Metis/Modules/GrandyStash/GrandyStashSupport.php',
    $root . '/src/Metis/Modules/Newsletter/DeliveryService.php',
    $root . '/src/Metis/Modules/Newsletter/QueueService.php',
    $root . '/src/Metis/Modules/Newsletter/Support.php',
    $root . '/src/Metis/Modules/People/AccessManager.php',
    $root . '/src/Metis/Modules/People/ActivityService.php',
    $root . '/src/Metis/Modules/People/SchemaManager.php',
    $root . '/src/Metis/Modules/People/Support.php',
    $root . '/src/Metis/Modules/Website/Services/BlockRenderer.php',
    $root . '/src/Metis/Modules/Website/Services/BannerService.php',
    $root . '/src/Metis/Modules/Website/Services/LayoutProfileService.php',
    $root . '/src/Metis/Modules/Website/Services/PageService.php',
    $root . '/src/Metis/Modules/Website/Services/PopupService.php',
    $root . '/src/Metis/Modules/Website/Services/PostCategoryService.php',
    $root . '/src/Metis/Modules/Website/Services/PostService.php',
    $root . '/src/Metis/Modules/Website/Services/ReusableBlockService.php',
    $root . '/src/Metis/Modules/Website/Services/RevisionTimelineService.php',
    $root . '/src/Metis/Modules/Website/Services/StructuredWebsiteBuilderService.php',
    $root . '/src/Metis/Modules/Website/Services/TemplateService.php',
    $root . '/src/Metis/Modules/Website/Services/ThemeService.php',
    $root . '/src/Metis/Modules/Website/Services/WebPartService.php',
    $root . '/src/Metis/Modules/Website/Services/WebsiteRenderer.php',
    $root . '/src/Metis/Hermes/HermesCommandRegistry.php',
    $root . '/src/Metis/Hermes/HermesExecutionEngine.php',
    $root . '/src/Metis/Hermes/HermesGateway.php',
    $root . '/src/Metis/Hermes/HermesOperationalEngine.php',
    $root . '/src/Metis/Hermes/HermesPermissionValidator.php',
    $root . '/src/Metis/Auth/AuthSessionManager.php',
    $root . '/src/Metis/Services/CommunicationsService.php',
    $root . '/src/Metis/Services/HermesContactAdminService.php',
    $root . '/src/Metis/Services/HermesDefinitionLibrary.php',
    $root . '/src/Metis/Services/HermesDirectoryService.php',
    $root . '/src/Metis/Services/HermesUserAdminService.php',
    $root . '/src/Metis/Services/HermesWebsiteAdminService.php',
    $root . '/src/Metis/Services/PermissionsService.php',
    $root . '/src/Metis/Services/SystemVersionService.php',
    $root . '/modules/calendar/assets/calendar.ajax.php',
    $root . '/modules/calendar/views/dashboard.php',
    $root . '/modules/board/assets/board.ajax.php',
    $root . '/modules/board/meeting.php',
    $root . '/modules/board/views/dashboard.php',
    $root . '/modules/board/views/meeting.php',
    $root . '/modules/contacts/ajax/contacts.ajax.php',
    $root . '/modules/contacts/ajax/imports.ajax.php',
    $root . '/modules/contacts/ajax/lists.ajax.php',
    $root . '/modules/contacts/ajax/relationships.ajax.php',
    $root . '/modules/contacts/views/contact.php',
    $root . '/modules/contacts/views/dashboard.php',
    $root . '/modules/donations/assets/reports.ajax.php',
    $root . '/modules/donations/bootstrap.php',
    $root . '/modules/donations/views/campaign.php',
    $root . '/modules/donations/views/campaigns.php',
    $root . '/modules/donations/views/deposit.php',
    $root . '/modules/donations/views/transaction.php',
    $root . '/modules/donations/views/transactions.php',
    $root . '/modules/finance/views/finance.php',
    $root . '/modules/finance/assets/finance.ajax.php',
    $root . '/modules/finance/views/ledger.php',
    $root . '/modules/finance/views/reconciliations.php',
    $root . '/modules/finance/views/reports.php',
    $root . '/modules/donations/views/dashboard.php',
    $root . '/modules/hermes/views/dashboard.php',
    $root . '/modules/hermes/assets/hermes.ajax.php',
    $root . '/modules/media/assets/media.ajax.php',
    $root . '/modules/newsletter/assets/newsletter.ajax.php',
    $root . '/modules/newsletter/services/audit.php',
    $root . '/modules/newsletter/services/tracking.php',
    $root . '/modules/newsletter/views/campaigns.php',
    $root . '/modules/newsletter/views/dashboard.php',
    $root . '/modules/newsletter/views/editor.php',
    $root . '/modules/newsletter/views/lists.php',
    $root . '/modules/newsletter/views/subscribers.php',
    $root . '/modules/newsletter/views/theme.php',
    $root . '/modules/portal/views/dashboard.php',
    $root . '/modules/portal/assets/portal.ajax.php',
    $root . '/modules/portal/views/email_usage.php',
    $root . '/modules/portal/views/settings.php',
    $root . '/modules/people/ajax/people.ajax.php',
    $root . '/modules/people/ajax/groups.ajax.php',
    $root . '/modules/people/ajax/jobs.ajax.php',
    $root . '/modules/people/ajax/mfa.ajax.php',
    $root . '/modules/people/ajax/permissions.ajax.php',
    $root . '/modules/people/ajax/requests.ajax.php',
    $root . '/modules/people/ajax/roles.ajax.php',
    $root . '/modules/people/ajax/templates.ajax.php',
    $root . '/modules/people/ajax/workspace.ajax.php',
    $root . '/modules/people/views/access_requests.php',
    $root . '/modules/people/views/activity.php',
    $root . '/modules/people/views/bulk_actions.php',
    $root . '/modules/people/views/dashboard.php',
    $root . '/modules/people/views/people_list.php',
    $root . '/modules/people/views/person.php',
    $root . '/modules/people/views/positions.php',
    $root . '/modules/people/views/role.php',
    $root . '/modules/people/views/roles_list.php',
    $root . '/modules/people/views/templates.php',
    $root . '/modules/people/views/permissions.php',
    $root . '/modules/people/views/workspace.php',
    $root . '/modules/profile/assets/profile.ajax.php',
    $root . '/modules/profile/views/dashboard.php',
    $root . '/modules/settings/views/customization.php',
    $root . '/modules/settings/views/developers_api.php',
    $root . '/modules/settings/views/general.php',
    $root . '/modules/settings/views/logging.php',
    $root . '/modules/settings/views/about.php',
    $root . '/modules/settings/views/accessibility.php',
    $root . '/modules/settings/views/backup.php',
    $root . '/modules/settings/views/cache.php',
    $root . '/modules/settings/views/calendar.php',
    $root . '/modules/settings/views/checker.php',
    $root . '/modules/settings/views/drive.php',
    $root . '/modules/settings/views/help.php',
    $root . '/modules/settings/views/jobs_tasks.php',
    $root . '/modules/settings/views/menu.php',
    $root . '/modules/settings/views/newsletter.php',
    $root . '/modules/settings/views/operations.php',
    $root . '/modules/settings/views/payments.php',
    $root . '/modules/settings/views/profile.php',
    $root . '/modules/settings/views/runtime.php',
    $root . '/modules/settings/views/scheduler.php',
    $root . '/modules/settings/views/user_experience.php',
    $root . '/modules/settings/views/workspace.php',
    $root . '/modules/settings/views/_settings_bootstrap.php',
    $root . '/modules/settings/assets/settings.ajax.php',
    $root . '/tools/communications_inbound_watch.php',
    $root . '/tools/migrate_remote_transcript_posts.php',
    $root . '/tools/migrate_transcript_posts_to_sections.php',
    $root . '/modules/website/ajax/website.ajax.php',
    $root . '/modules/website/routes/routes.php',
    $root . '/modules/website/Templates/default/layout.php',
    $root . '/modules/website/Templates/default/homepage.php',
    $root . '/modules/website/Templates/default/page.php',
    $root . '/modules/website/Templates/default/post.php',
    $root . '/modules/website/views/categories.php',
    $root . '/modules/website/views/dashboard.php',
    $root . '/modules/website/views/editor.php',
    $root . '/modules/website/views/media.php',
    $root . '/modules/website/views/menus.php',
    $root . '/modules/website/views/import.php',
    $root . '/modules/website/views/pages.php',
    $root . '/modules/website/views/popups.php',
    $root . '/modules/website/views/posts.php',
    $root . '/modules/website/views/redirects.php',
    $root . '/modules/website/views/templates.php',
    $root . '/modules/website/views/banners.php',
    $root . '/modules/website/views/theme.php',
    $root . '/modules/drive/assets/drive.ajax.php',
    $root . '/modules/drive/includes/config.php',
    $root . '/modules/drive/includes/schema.php',
    $root . '/modules/drive/includes/store.php',
    $root . '/modules/drive/includes/sync_utils.php',
    $root . '/modules/drive/views/dashboard.php',
    $root . '/modules/import/assets/import.ajax.php',
    $root . '/modules/import/converters/BeaverBuilderConverter.php',
    $root . '/modules/import/services/ImportService.php',
    $root . '/modules/import/views/dashboard.php',
    $root . '/modules/grandys_stash/assets/grandys_stash.ajax.php',
    $root . '/modules/grandys_stash/views/dashboard.php',
    $root . '/modules/grandys_stash/views/report.php',
    $root . '/modules/grandys_stash/views/settings.php',
    $root . '/modules/grandys_stash/views/ticket.php',
    $root . '/modules/forms/assets/forms.ajax.php',
    $root . '/modules/forms/bootstrap.php',
    $root . '/modules/forms/views/build.php',
    $root . '/modules/forms/views/dashboard.php',
    $root . '/modules/forms/views/entries.php',
    $root . '/modules/forms/views/form.php',
    $root . '/modules/forms/views/settings.php',
    $root . '/modules/donations/assets/campaigns.ajax.php',
    $root . '/modules/donations/assets/donor_intelligence.ajax.php',
    $root . '/modules/donations/assets/notes.ajax.php',
    $root . '/modules/donations/views/batch-detail.php',
    $root . '/modules/donations/views/deposits.php',
    $root . '/modules/donations/views/donors.php',
    $root . '/modules/donations/views/reports.php',
];

foreach ( $modernized_shared_paths as $path ) {
    $contents = @file_get_contents( $path );
    if ( ! is_string( $contents ) || $contents === '' ) {
        $assert( false, sprintf( 'Modernized shared path is unreadable: %s', $path ) );
        continue;
    }

    if ( preg_match( '/\b(esc_html|esc_attr|esc_url|esc_url_raw|sanitize_key|sanitize_text_field|sanitize_email|is_email|selected|checked|status_header)\s*\(/', $contents, $matches ) === 1 ) {
        $assert( false, sprintf( 'Legacy helper [%s] still present in modernized shared path: %s', (string) ( $matches[1] ?? 'unknown' ), $path ) );
    }
}

$raw_mail_hits = $scan_for_matches(
    [
        $root . '/src',
        $root . '/modules',
        $root . '/tools',
    ],
    [
        '/(^|[^A-Za-z0-9_])mail\s*\(/m',
    ]
);

foreach ( $raw_mail_hits as $token => $paths ) {
    foreach ( $paths as $path ) {
        $normalized = str_replace( '\\', '/', $path );
        if (
            str_contains( $normalized, '/src/Metis/Core/Runtime/HelpersRuntime.php' )
            || str_contains( $normalized, '/src/Metis/Core/Services/EmailService.php' )
        ) {
            continue;
        }

        $assert( false, sprintf( 'Direct raw mail() usage found outside the email service: %s', $normalized ) );
    }
}

$provider_bypass_hits = $scan_for_matches(
    [
        $root . '/src',
        $root . '/modules',
        $root . '/tools',
    ],
    [
        '/\b(metis_newsletter_gmail_send)\s*\(/',
    ]
);

foreach ( $provider_bypass_hits as $token => $paths ) {
    foreach ( $paths as $path ) {
        $normalized = str_replace( '\\', '/', $path );
        if (
            str_contains( $normalized, '/src/Metis/Core/Services/EmailService.php' )
            || str_contains( $normalized, '/modules/newsletter/bootstrap.php' )
        ) {
            continue;
        }

        $assert( false, sprintf( 'Direct provider send helper [%s] bypasses the email service: %s', $token, $normalized ) );
    }
}

if ( $failures !== [] ) {
    fwrite( STDERR, implode( PHP_EOL, $failures ) . PHP_EOL );
    exit( 1 );
}

fwrite(
    STDOUT,
    json_encode(
        [
            'ok' => true,
            'modules' => count( $modules ),
            'routes' => count( $routes ),
            'ajax_controllers' => count( $controllers ),
            'checked_at' => gmdate( 'c' ),
        ],
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
    ) . PHP_EOL
);
