<?php
declare(strict_types=1);

namespace Metis\Modules\CommunicationsInbound\Handlers;

use Metis\Modules\CommunicationsInbound\Contracts\MessageHandlerInterface;
use Metis\Modules\CommunicationsInbound\ValueObjects\NormalizedInboundMessage;
use Metis\Modules\CommunicationsInbound\ValueObjects\ParseResult;

final class GrandyStashHandler implements MessageHandlerInterface {
    public function key(): string {
        return 'grandys_stash';
    }

    public function handle( array $message_row, NormalizedInboundMessage $message, ParseResult $result ): array {
        $ticket_code = strtoupper( trim( (string) ( $result->metadata()['ticket_code'] ?? '' ) ) );
        $ticket_id = (int) ( $result->metadata()['ticket_id'] ?? 0 );
        if ( $ticket_code === '' || ! class_exists( '\Metis\Modules\GrandyStash\GrandyStashRepository' ) ) {
            return [
                'handled'  => false,
                'status'   => 'unknown',
                'metadata' => [ 'reason' => 'Grandy\'s Stash ticket code was not resolvable.' ],
            ];
        }

        $ticket = $ticket_id > 0
            ? \Metis\Modules\GrandyStash\GrandyStashRepository::getTicket( $ticket_id )
            : \Metis\Modules\GrandyStash\GrandyStashRepository::findTicketByCode( $ticket_code );
        if ( ! is_array( $ticket ) || (int) ( $ticket['id'] ?? 0 ) < 1 ) {
            return [
                'handled'  => false,
                'status'   => 'unknown',
                'metadata' => [
                    'reason'      => 'Grandy\'s Stash ticket was not found.',
                    'ticket_code' => $ticket_code,
                ],
            ];
        }

        $stored = \Metis\Modules\GrandyStash\GrandyStashRepository::recordInboundMessage(
            (int) $ticket['id'],
            $message_row,
            $message->all()
        );
        $stash_message_id = (int) ( $stored['id'] ?? 0 );
        if ( $stash_message_id < 1 ) {
            throw new \RuntimeException( 'Grandy\'s Stash reply was classified but not stored in the ticket conversation.' );
        }

        return [
            'handled'  => true,
            'status'   => 'handled',
            'metadata' => [
                'ticket_id'    => (int) ( $ticket['id'] ?? 0 ),
                'ticket_code'  => $ticket_code,
                'stash_message_id' => $stash_message_id,
            ],
            'links' => [
                [
                    'module_slug' => 'grandys_stash',
                    'entity_type' => 'ticket',
                    'entity_id'   => (int) ( $ticket['id'] ?? 0 ),
                    'link_type'   => 'thread',
                    'metadata'    => [ 'ticket_code' => $ticket_code ],
                ],
            ],
        ];
    }
}
