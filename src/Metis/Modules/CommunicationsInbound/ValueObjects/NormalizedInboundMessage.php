<?php
declare(strict_types=1);

namespace Metis\Modules\CommunicationsInbound\ValueObjects;

final class NormalizedInboundMessage {
    /**
     * @param array<string, mixed> $data
     */
    public function __construct( private readonly array $data ) {}

    /**
     * @return array<string, mixed>
     */
    public function all(): array {
        return $this->data;
    }

    public function get( string $key, mixed $default = null ): mixed {
        return $this->data[ $key ] ?? $default;
    }

    public function provider(): string {
        return (string) $this->get( 'provider', '' );
    }

    public function providerMailbox(): string {
        return (string) $this->get( 'provider_mailbox', '' );
    }

    public function providerMessageId(): string {
        return (string) $this->get( 'provider_message_id', '' );
    }

    public function providerThreadId(): string {
        return (string) $this->get( 'provider_thread_id', '' );
    }

    public function subject(): string {
        return (string) $this->get( 'subject', '' );
    }

    public function textBody(): string {
        return (string) $this->get( 'text_body', '' );
    }

    public function htmlBody(): string {
        return (string) $this->get( 'html_body', '' );
    }

    public function senderEmail(): string {
        return strtolower( trim( (string) $this->get( 'canonical_sender_email', '' ) ) );
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function headers(): array {
        $headers = $this->get( 'headers', [] );
        return is_array( $headers ) ? $headers : [];
    }

    /**
     * @return array<int, string>
     */
    public function headerValues( string $name ): array {
        $headers = $this->headers();
        $key = strtolower( trim( $name ) );
        $values = $headers[ $key ] ?? [];
        return is_array( $values ) ? array_values( array_map( 'strval', $values ) ) : [];
    }

    public function headerFirst( string $name ): string {
        $values = $this->headerValues( $name );
        return $values[0] ?? '';
    }
}
