<?php
declare(strict_types=1);

namespace Metis\Modules\Finance;

final class FinanceModule {
    private static bool $booted = false;

    public static function boot(): void {
        if ( self::$booted ) {
            return;
        }

        self::$booted = true;
        \Metis_Logger::info( 'Finance V2 bootstrap loaded' );

        \metis_on( 'init', [ self::class, 'ensureRuntimeSchema' ], 5 );

        if ( \Metis\Core\Application::has_service( 'job_workers' ) ) {
            \metis_job_workers()->register(
                'finance_v2.mode_switch.execute',
                static function ( array $payload ): array {
                    return ModeSwitchService::executeQueuedSwitch( $payload );
                }
            );
            \metis_job_workers()->register(
                'finance_v2.recon_pdf_ocr',
                static function ( array $payload ): array {
                    return FinanceV2Service::processQueuedPdfOcr( $payload );
                }
            );
        }
    }

    public static function canView(): bool { return Access::canView(); }
    public static function canManage(): bool { return Access::canManage(); }
    public static function baseUrl(): string { return Support::baseUrl(); }
    public static function ensureSchema(): void { SchemaManager::ensureSchema(); }
    public static function ensureRuntimeSchema(): void {
        if ( function_exists( 'metis_runtime_run_once_per_signature' ) ) {
            \metis_runtime_run_once_per_signature(
                'finance_schema',
                [ __FILE__, __DIR__ . '/SchemaManager.php' ],
                static function (): void {
                    SchemaManager::ensureSchema();
                }
            );
            return;
        }

        self::ensureSchema();
    }
    public static function currentMode(): string { return ModeService::currentMode(); }
    public static function scheduleModeSwitch( string $targetMode, string $effectiveAt, int $requestedBy = 0 ): array {
        return ModeService::scheduleSwitch( $targetMode, $effectiveAt, $requestedBy );
    }
    public static function modeSwitchStatus(): array { return ModeService::switchStatus(); }
    public static function dashboardWidgets( array $context = [] ): array {
        return [
            [
                'key' => 'finance',
                'title' => 'Finance',
                'desc' => 'Revenue, deposits, and reconciliation status.',
                'url' => self::baseUrl(),
                'metrics' => (array) ( $context['finance_metrics'] ?? [] ),
                'priority' => 35,
                'updated' => \metis_current_datetime()->format( 'M j, g:i a' ),
            ],
        ];
    }
}
