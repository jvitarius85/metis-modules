<?php
declare(strict_types=1);

namespace Metis\Modules\Forms\Requests;

use Metis\Http\Request;

final class PublicFormRequest {
    private function __construct(
        private readonly string $slug,
        private readonly bool $isPost,
        private readonly array $payload,
        private readonly string $mode,
        private readonly bool $expectsJson
    ) {}

    public static function fromRequest( Request $request ): self {
        $payload = strtoupper( $request->method() ) === 'POST'
            ? self::normalizePayload( $request->parsed_body() )
            : self::normalizePayload( $request->query() );

        return new self(
            \metis_slug_clean( (string) $request->attribute( 'form_slug', '' ) ),
            strtoupper( $request->method() ) === 'POST',
            $payload,
            \metis_key_clean( (string) ( $payload['mode'] ?? 'submit' ) ),
            str_contains( strtolower( (string) $request->header( 'accept', '' ) ), 'application/json' )
                || strtolower( (string) $request->header( 'x-requested-with', '' ) ) === 'xmlhttprequest'
        );
    }

    public function slug(): string {
        return $this->slug;
    }

    public function isPost(): bool {
        return $this->isPost;
    }

    public function payload(): array {
        return $this->payload;
    }

    public function mode(): string {
        return $this->mode;
    }

    public function expectsJson(): bool {
        return $this->expectsJson;
    }

    public function paymentSession(): string {
        return (string) ( $this->payload['payment_session'] ?? '' );
    }

    public function paymentIntentId(): string {
        return (string) ( $this->payload['payment_intent_id'] ?? '' );
    }

    public function paymentIntent(): string {
        return is_scalar( $this->payload['payment_intent'] ?? null ) ? (string) $this->payload['payment_intent'] : '';
    }

    public function isPaymentReturn(): bool {
        return ! empty( $this->payload['payment_return'] ) && $this->paymentSession() !== '';
    }

    private static function normalizePayload( mixed $input ): array {
        $payload = is_array( $input ) ? $input : [];
        if ( isset( $payload['payload'] ) && is_string( $payload['payload'] ) ) {
            $decoded = json_decode( $payload['payload'], true );
            if ( is_array( $decoded ) ) {
                return $decoded;
            }
        }

        return $payload;
    }
}
