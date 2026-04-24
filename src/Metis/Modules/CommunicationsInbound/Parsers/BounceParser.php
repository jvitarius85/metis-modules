<?php
declare(strict_types=1);

namespace Metis\Modules\CommunicationsInbound\Parsers;

use Metis\Modules\CommunicationsInbound\Contracts\MessageParserInterface;
use Metis\Modules\CommunicationsInbound\ValueObjects\NormalizedInboundMessage;
use Metis\Modules\CommunicationsInbound\ValueObjects\ParseResult;

final class BounceParser implements MessageParserInterface {
    public function key(): string {
        return 'bounce';
    }

    public function priority(): int {
        return 10;
    }

    public function parse( NormalizedInboundMessage $message ): ParseResult {
        $score = 0;
        $subject = strtolower( $message->subject() );
        $sender = strtolower( $message->senderEmail() );
        $body = strtolower( $message->textBody() . "\n" . strip_tags( $message->htmlBody() ) );

        if ( preg_match( '/mailer-daemon|postmaster|mail delivery subsystem|bounce/i', $sender ) ) {
            $score += 2;
        }

        if ( preg_match( '/delivery status notification|undelivered mail|delivery has failed|returned mail|mail delivery failed/i', $subject ) ) {
            $score += 2;
        }

        if ( $message->headerFirst( 'auto-submitted' ) !== '' || $message->headerFirst( 'x-failed-recipients' ) !== '' ) {
            $score++;
        }

        if ( preg_match( '/final-recipient|diagnostic-code|address not found|recipient address rejected|delivery to the following recipient failed/i', $body ) ) {
            $score += 2;
        }

        if ( $score < 3 ) {
            return ParseResult::unknown();
        }

        $recipient = $this->extractBouncedRecipient( $message, $body );
        $reason = $this->extractReason( $message, $body );

        return ParseResult::matched(
            'bounce',
            $this->key(),
            'bounce',
            min( 1.0, 0.5 + ( $score * 0.1 ) ),
            [
                'bounced_recipient' => $recipient,
                'reason'            => $reason,
            ]
        );
    }

    private function extractBouncedRecipient( NormalizedInboundMessage $message, string $body ): string {
        $candidates = [
            $message->headerFirst( 'x-failed-recipients' ),
            $message->headerFirst( 'final-recipient' ),
        ];

        foreach ( $candidates as $candidate ) {
            if ( preg_match( '/([A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,})/i', $candidate, $matches ) ) {
                return strtolower( $matches[1] );
            }
        }

        if ( preg_match( '/([A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,})/i', $body, $matches ) ) {
            return strtolower( $matches[1] );
        }

        return '';
    }

    private function extractReason( NormalizedInboundMessage $message, string $body ): string {
        $headers = [
            $message->headerFirst( 'diagnostic-code' ),
            $message->headerFirst( 'status' ),
        ];

        foreach ( $headers as $header ) {
            $header = trim( $header );
            if ( $header !== '' ) {
                return substr( $header, 0, 255 );
            }
        }

        if ( preg_match( '/(address not found|recipient address rejected|delivery to the following recipient failed[^\\n]*)/i', $body, $matches ) ) {
            return substr( trim( $matches[1] ), 0, 255 );
        }

        return 'Delivery failure';
    }
}
