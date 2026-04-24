<?php
declare(strict_types=1);

namespace Metis\Modules\CommunicationsInbound\Parsers;

use Metis\Modules\CommunicationsInbound\Contracts\MessageParserInterface;
use Metis\Modules\CommunicationsInbound\ValueObjects\NormalizedInboundMessage;
use Metis\Modules\CommunicationsInbound\ValueObjects\ParseResult;
use Metis\Modules\GrandyStash\ConversationSupport;

final class GrandyStashParser implements MessageParserInterface {
    public function key(): string {
        return 'grandys_stash';
    }

    public function priority(): int {
        return 20;
    }

    public function parse( NormalizedInboundMessage $message ): ParseResult {
        $ticket_code = ConversationSupport::extractTicketCode( $message->subject() );
        $ticket_id = 0;
        $match_via = $ticket_code !== '' ? 'subject' : '';

        if ( $ticket_code === '' ) {
            $ticket_code = ConversationSupport::extractTicketCodeFromBody( $message->textBody() );
            if ( $ticket_code !== '' ) {
                $match_via = 'body_token';
            }
        }

        if ( $ticket_code === '' && class_exists( '\Metis\Modules\GrandyStash\GrandyStashRepository' ) ) {
            $resolved = \Metis\Modules\GrandyStash\GrandyStashRepository::findTicketByConversationHeaders(
                $message->headerFirst( 'in-reply-to' ),
                $message->headerValues( 'references' )
            );
            if ( is_array( $resolved ) ) {
                $ticket_id = (int) ( $resolved['id'] ?? 0 );
                $ticket_code = strtoupper( trim( (string) ( $resolved['code'] ?? '' ) ) );
                $match_via = 'headers';
            }
        }

        if ( $ticket_code === '' && class_exists( '\Metis\Modules\GrandyStash\GrandyStashRepository' ) ) {
            $resolved = \Metis\Modules\GrandyStash\GrandyStashRepository::findTicketByProviderThreadId(
                $message->providerThreadId()
            );
            if ( is_array( $resolved ) ) {
                $ticket_id = (int) ( $resolved['id'] ?? 0 );
                $ticket_code = strtoupper( trim( (string) ( $resolved['code'] ?? '' ) ) );
                $match_via = 'provider_thread';
            }
        }

        if ( $ticket_code === '' ) {
            return ParseResult::unknown();
        }

        $metadata = [
            'ticket_code' => $ticket_code,
            'match_via'   => $match_via !== '' ? $match_via : 'subject',
        ];
        if ( $ticket_id > 0 ) {
            $metadata['ticket_id'] = $ticket_id;
        }

        return ParseResult::matched(
            'grandys_stash',
            $this->key(),
            'grandys_stash',
            0.98,
            $metadata
        );
    }
}
