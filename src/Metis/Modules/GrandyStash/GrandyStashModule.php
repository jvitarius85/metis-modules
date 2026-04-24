<?php
declare(strict_types=1);

namespace Metis\Modules\GrandyStash;

use Metis\Http\Request;
use Metis\Http\Response;

final class GrandyStashModule {
    private static bool $booted = false;

    public static function boot(): void {
        if ( self::$booted ) {
            return;
        }

        self::$booted = true;
        \metis_on( 'init', [ self::class, 'ensureReady' ], 6 );
        \metis_on( 'init', [ self::class, 'registerCronTasks' ], 8 );
    }

    public static function ensureReady(): void {
        try {
            GrandyStashRepository::ensureModuleReady();
        } catch ( \Throwable $e ) {
            if ( class_exists( 'Metis_Logger', false ) ) {
                \Metis_Logger::warn(
                    'Grandy\'s Stash initialization skipped after startup failure',
                    [
                        'module'  => 'grandys_stash',
                        'service' => 'ensure_ready',
                        'error'   => $e->getMessage(),
                    ]
                );
            }
        }
    }

    public static function registerCronTasks(): void {
        if ( ! class_exists( 'Metis_Cron_Manager' ) ) {
            return;
        }
        \Metis_Cron_Manager::register_task(
            'grandys_stash_daily_summary',
            static function (): array {
                return GrandyStashDailySummary::send();
            },
            [
                'label'    => "Grandy's Stash Daily Summary",
                'interval' => 86400,
                'lock_ttl' => 600,
                'module'   => 'grandys_stash',
            ]
        );
    }

    public static function canView(): bool {
        return GrandyStashSupport::canView();
    }

    public static function canManage(): bool {
        return GrandyStashSupport::canManage();
    }

    public static function baseUrl(): string {
        return GrandyStashSupport::baseUrl();
    }

    public static function viewUrl( string $ticket_code = '' ): string {
        return GrandyStashSupport::viewUrl( $ticket_code );
    }

    public static function handleViewRoute( Request $request ): Response {
        if ( ! self::canView() ) {
            return Response::html( '<div class="mw-alert mw-alert-error">You do not have permission to view Grandy&apos;s Stash.</div>', 403 );
        }

        self::ensureReady();

        $ticket_code = strtoupper( trim( \metis_text_clean( (string) $request->attribute( 'ticket_code', '' ) ) ) );
        if ( $ticket_code === '' ) {
            return Response::html( '<div class="mw-alert mw-alert-error">Ticket code is required.</div>', 404 );
        }

        \metis_set_query_var( 'metis_domain', 'grandys_stash' );
        \metis_set_query_var( 'metis_view', 'ticket' );
        \metis_set_query_var( 'metis_grandys_stash_ticket_code', $ticket_code );

        nocache_headers();
        if ( ! defined( 'DONOTCACHEPAGE' ) ) {
            define( 'DONOTCACHEPAGE', true );
        }

        $shell = METIS_PATH . 'src/Metis/Core/Runtime/ShellTemplate.php';
        if ( ! file_exists( $shell ) ) {
            return Response::html( '<div class="metis-error">METIS shell is missing.</div>', 500 );
        }

        ob_start();
        if ( function_exists( 'metis_security_trusted_include' ) ) {
            \metis_security_trusted_include( $shell );
        } else {
            require_once $shell;
        }
        $body = (string) ob_get_clean();

        return Response::html( $body, 200, [ 'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0' ] );
    }
}

\class_alias( __NAMESPACE__ . '\\GrandyStashModule', 'Metis\\Modules\\GrandyStashModule' );
