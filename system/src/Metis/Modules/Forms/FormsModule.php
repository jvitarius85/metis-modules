<?php
declare(strict_types=1);

namespace Metis\Modules\Forms;

use Metis\Core\Error\ErrorPageRenderer;
use Metis\Http\Request;
use Metis\Http\Response;

final class FormsModule {
    private static bool $booted = false;

    public static function boot(): void {
        if ( self::$booted ) {
            return;
        }

        self::$booted = true;
        \metis_on( 'init', [ self::class, 'ensureSchema' ], 5 );
    }

    public static function ensureSchema(): void {
        SchemaManager::ensureSchema();
    }

    public static function canView(): bool {
        return Support::canView();
    }

    public static function canManage(): bool {
        return Support::canManage();
    }

    public static function canDelete(): bool {
        return Support::canDelete();
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
        self::ensureSchema();

        $slug = \metis_slug_clean( (string) $request->attribute( 'form_slug', '' ) );
        $form = Repository::getFormBySlug( $slug, true );
        if ( ! is_array( $form ) ) {
            $trace_id = function_exists( 'metis_audit_request_id' ) ? (string) \metis_audit_request_id() : '';
            if ( class_exists( ErrorPageRenderer::class ) ) {
                return Response::html(
                    ( new ErrorPageRenderer() )->render( 404, $trace_id, 'The requested public form is not published or does not exist.', 'Form Not Found' ),
                    404
                );
            }

            return Response::html( '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>Form Not Found</title></head><body><main><h1>Form Not Found</h1><p>The requested public form is not published or does not exist.</p></main></body></html>', 404 );
        }

        if ( strtoupper( $request->method() ) === 'POST' ) {
            $input = $request->parsed_body();
            if ( isset( $input['payload'] ) && is_string( $input['payload'] ) ) {
                $decoded = json_decode( $input['payload'], true );
                if ( is_array( $decoded ) ) {
                    $input = $decoded;
                }
            }

            $payload = is_array( $input ) ? $input : [];
            $availability = Repository::publicAvailability( $form, $payload );
            if ( empty( $availability['ok'] ) ) {
                return self::respondPublic( $request, $form, $availability, (int) ( $availability['status'] ?? 403 ) );
            }

            $mode = \metis_key_clean( (string) ( $payload['mode'] ?? 'submit' ) );
            if ( $mode === 'prepare_payment' ) {
                $result = Repository::preparePublicPayment( $form, $payload, $request->files(), $request->uri() );
            } elseif ( $mode === 'finalize_payment' ) {
                $result = Repository::finalizePaymentSession(
                    (string) ( $payload['payment_session'] ?? '' ),
                    (string) ( $payload['payment_intent_id'] ?? '' )
                );
            } else {
                $result = Repository::submitForm( $form, $payload, $request->files(), $request->uri() );
            }

            return self::respondPublic( $request, $form, $result, (int) ( $result['status'] ?? 200 ) );
        }

        $input = $request->query();
        $payload = is_array( $input ) ? $input : [];
        $availability = Repository::publicAvailability( $form, $payload );
        if ( empty( $availability['ok'] ) ) {
            return FormRenderer::render( $form, $availability );
        }

        $result = [];
        if ( ! empty( $payload['payment_return'] ) && ! empty( $payload['payment_session'] ) ) {
            $result = Repository::finalizePaymentSession(
                (string) $payload['payment_session'],
                is_scalar( $payload['payment_intent'] ?? null ) ? (string) $payload['payment_intent'] : ''
            );
        }

        return FormRenderer::render( $form, $result );
    }

    private static function respondPublic( Request $request, array $form, array $result, int $status ): Response {
        $expects_json = str_contains( strtolower( (string) $request->header( 'accept', '' ) ), 'application/json' )
            || strtolower( (string) $request->header( 'x-requested-with', '' ) ) === 'xmlhttprequest';

        if ( $expects_json ) {
            return Response::json( $result, $status );
        }

        return FormRenderer::render( $form, $result );
    }
}
