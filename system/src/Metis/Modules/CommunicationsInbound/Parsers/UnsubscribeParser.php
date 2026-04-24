<?php
declare(strict_types=1);

namespace Metis\Modules\CommunicationsInbound\Parsers;

use Metis\Modules\CommunicationsInbound\Contracts\MessageParserInterface;
use Metis\Modules\CommunicationsInbound\ValueObjects\NormalizedInboundMessage;
use Metis\Modules\CommunicationsInbound\ValueObjects\ParseResult;

final class UnsubscribeParser implements MessageParserInterface {
    public function key(): string {
        return 'unsubscribe';
    }

    public function priority(): int {
        return 30;
    }

    public function parse( NormalizedInboundMessage $message ): ParseResult {
        $body = strtolower( trim( preg_replace( '/\s+/', ' ', $message->textBody() ) ?? '' ) );
        if ( $body === '' ) {
            $body = strtolower( trim( preg_replace( '/\s+/', ' ', strip_tags( $message->htmlBody() ) ) ?? '' ) );
        }

        if ( $body === '' ) {
            return ParseResult::unknown();
        }

        if ( preg_match( '/\b(do not|don\'t|dont)\s+unsubscribe\b/i', $body ) ) {
            return ParseResult::unknown();
        }

        $patterns = [
            '/\bunsubscribe\b/i',
            '/\bremove me\b/i',
            '/\bstop\b/i',
            '/\bstop emailing me\b/i',
            '/\bstop sending\b/i',
        ];

        $matched_pattern = '';
        foreach ( $patterns as $pattern ) {
            if ( preg_match( $pattern, $body ) ) {
                $matched_pattern = $pattern;
                break;
            }
        }

        if ( $matched_pattern === '' ) {
            return ParseResult::unknown();
        }

        $short_body = strlen( $body ) <= 240;
        $phrase_at_start = preg_match( '/^(unsubscribe|remove me|stop|please unsubscribe|please remove me)\b/i', $body ) === 1;
        if ( ! $short_body && ! $phrase_at_start ) {
            return ParseResult::unknown();
        }

        return ParseResult::matched(
            'unsubscribe',
            $this->key(),
            'unsubscribe',
            0.95,
            [
                'request_email'   => $message->senderEmail(),
                'matched_pattern' => $matched_pattern,
            ]
        );
    }
}
