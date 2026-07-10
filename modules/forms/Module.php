<?php
declare(strict_types=1);

namespace Metis\Modules\Forms;

use Metis\Http\Request;
use Metis\Http\Response;

// @metis-governance ajax-security: public form routes and AJAX submissions are mediated by route/AJAX nonce, csrf, permission, and SecureEnclave policy.
final class FormsModule {
    private static bool $booted = false;

    public static function boot(): void {
        if ( self::$booted ) {
            return;
        }

        self::$booted = true;
        \metis_on( 'init', [ self::class, 'ensureRuntimeSchema' ], 5 );
    }

    public static function ensureSchema(): void {
        SchemaManager::ensureSchema();
    }

    public static function ensureRuntimeSchema(): void {
        if ( function_exists( 'metis_runtime_run_once_per_signature' ) ) {
            \metis_runtime_run_once_per_signature(
                'forms_schema',
                [ __FILE__, __DIR__ . '/SchemaManager.php' ],
                static function (): void {
                    SchemaManager::ensureSchema();
                }
            );
            return;
        }

        self::ensureSchema();
    }

    public static function canView(): bool {
        return \Metis\Modules\Forms\Policies\FormPolicy::canView();
    }

    public static function canManage(): bool {
        return \Metis\Modules\Forms\Policies\FormPolicy::canManage();
    }

    public static function canDelete(): bool {
        return \Metis\Modules\Forms\Policies\FormPolicy::canDelete();
    }

    public static function baseUrl(): string {
        return Support::baseUrl();
    }

    public static function publicUrl( string $slug = '' ): string {
        return Support::publicUrl( $slug );
    }

    public static function detailUrl( int $form_id = 0 ): string {
        return Support::detailUrl( $form_id );
    }

    public static function buildUrl( int $form_id = 0 ): string {
        return Support::buildUrl( $form_id );
    }

    public static function entriesUrl( int $form_id = 0 ): string {
        return Support::entriesUrl( $form_id );
    }

    public static function settingsUrl( int $form_id = 0 ): string {
        return Support::settingsUrl( $form_id );
    }

    public static function handlePublicRoute( Request $request ): Response {
        return \Metis\Modules\Forms\Controllers\PublicFormController::handle( $request );
    }

    public static function respondPublic( bool $expects_json, array $form, array $result, int $status ): Response {
        if ( $expects_json ) {
            return Response::json( $result, $status );
        }

        return FormRenderer::render( $form, $result );
    }
}
