<?php
declare(strict_types=1);

namespace Metis\Modules\Forms;

use Metis\Http\Request;
use Metis\Http\Response;

final class FormsModule {
    private static bool $booted = false;

    public static function boot(): void {
        if ( self::$booted ) {
            return;
        }

        self::$booted = true;
        \metis_add_action( 'init', [ self::class, 'ensureSchema' ], 5 );
    }

    public static function ensureSchema(): void {
        SchemaManager::ensureSchema();
    }

    public static function canView(): bool { return Support::canView(); }
    public static function canManage(): bool { return Support::canManage(); }
    public static function canDelete(): bool { return Support::canDelete(); }
    public static function baseUrl(): string { return Support::baseUrl(); }
    public static function publicUrl( string $slug = '' ): string { return Support::publicUrl( $slug ); }
    public static function detailUrl( int $form_id = 0 ): string { return Support::detailUrl( $form_id ); }
    public static function buildUrl( int $form_id = 0 ): string { return Support::buildUrl( $form_id ); }
    public static function entriesUrl( int $form_id = 0 ): string { return Support::entriesUrl( $form_id ); }
    public static function settingsUrl( int $form_id = 0 ): string { return Support::settingsUrl( $form_id ); }

    public static function handlePublicRoute( Request $request ): Response {
        self::ensureSchema();

        $slug = \sanitize_title( (string) $request->attribute( 'form_slug', '' ) );
        $form = Repository::getFormBySlug( $slug, true );
        if ( ! $form ) {
            return Response::html( '<div class="metis-error">Form not found.</div>', 404 );
        }

        if ( strtoupper( $request->method() ) === 'POST' ) {
            $input = $request->parsed_body();
            if ( isset( $input['payload'] ) && is_string( $input['payload'] ) ) {
                $decoded = json_decode( $input['payload'], true );
                if ( is_array( $decoded ) ) {
                    $input = $decoded;
                }
            }

            $result = Repository::submitForm(
                $form,
                is_array( $input ) ? $input : [],
                $request->files(),
                $request->uri()
            );

            if ( str_contains( strtolower( (string) $request->header( 'accept', '' ) ), 'application/json' )
                || strtolower( (string) $request->header( 'x-requested-with', '' ) ) === 'xmlhttprequest'
            ) {
                return Response::json( $result, (int) ( $result['status'] ?? 200 ) );
            }

            return FormRenderer::render( $form, $result );
        }

        $result = [];
        $input = $request->query();
        if ( ! empty( $input['payment_return'] ) && ! empty( $input['submission'] ) && is_scalar( $input['submission'] ) ) {
            $sync = Repository::syncPaymentStatus( (string) $input['submission'] );
            if ( ! empty( $sync['ok'] ) ) {
                $payment_status = (string) ( $sync['submission']['payment_status'] ?? 'pending' );
                $result = [
                    'message' => $payment_status === 'paid'
                        ? 'Payment completed successfully.'
                        : 'Submission received. Payment is still processing.',
                ];
            } else {
                $result = [ 'message' => (string) ( $sync['error'] ?? 'Payment status could not be verified.' ) ];
            }
        }

        return FormRenderer::render( $form, $result );
    }
}
