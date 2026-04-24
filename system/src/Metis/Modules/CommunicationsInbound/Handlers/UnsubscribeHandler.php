<?php
declare(strict_types=1);

namespace Metis\Modules\CommunicationsInbound\Handlers;

use Metis\Modules\CommunicationsInbound\Contracts\MessageHandlerInterface;
use Metis\Modules\CommunicationsInbound\ValueObjects\NormalizedInboundMessage;
use Metis\Modules\CommunicationsInbound\ValueObjects\ParseResult;

final class UnsubscribeHandler implements MessageHandlerInterface {
    public function key(): string {
        return 'unsubscribe';
    }

    public function handle( array $message_row, NormalizedInboundMessage $message, ParseResult $result ): array {
        $db = \metis_db();
        $now = \metis_current_time( 'mysql' );
        $email = strtolower( trim( (string) ( $result->metadata()['request_email'] ?? $message->senderEmail() ) ) );

        $contacts_table = \Metis_Tables::get( 'contacts' );
        $subs_table = \Metis_Tables::get( 'newsletter_subs' );
        $suppressions_table = \Metis_Tables::get( 'newsletter_suppressions' );
        $events_table = \Metis_Tables::get( 'newsletter_events' );

        $contact_id = 0;
        if ( $email !== '' ) {
            $contact_id = (int) $db->scalar( "SELECT id FROM {$contacts_table} WHERE email = %s LIMIT 1", [ $email ] );

            $db->query(
                "UPDATE {$subs_table}
                 SET status = 'unsubscribed',
                     unsubscribed_at = %s,
                     last_event_at = %s,
                     updated_at = %s
                 WHERE email = %s OR contact_id = %d",
                [ $now, $now, $now, $email, $contact_id ]
            );

            $suppression_id = (int) $db->scalar(
                "SELECT id FROM {$suppressions_table} WHERE email = %s AND is_active = 1 LIMIT 1",
                [ $email ]
            );

            if ( $suppression_id < 1 ) {
                $db->insert(
                    $suppressions_table,
                    [
                        'suppression_code' => \metis_generate_code( 'NS', $suppressions_table, 'suppression_code' ),
                        'contact_id'       => $contact_id > 0 ? $contact_id : null,
                        'email'            => $email,
                        'reason'           => 'unsubscribe',
                        'source'           => 'inbound_email',
                        'is_active'        => 1,
                        'created_at'       => $now,
                        'updated_at'       => $now,
                    ]
                );
            }

            $db->insert(
                $events_table,
                [
                    'event_code'  => \metis_generate_code( 'NE', $events_table, 'event_code' ),
                    'message_id'  => null,
                    'campaign_id' => null,
                    'contact_id'  => $contact_id > 0 ? $contact_id : null,
                    'email'       => $email,
                    'event_type'  => 'unsubscribe',
                    'reason'      => 'Inbound unsubscribe reply',
                    'source'      => 'inbound_email',
                    'event_at'    => $now,
                    'payload_json'=> \metis_json_encode(
                        [
                            'inbound_message_id' => (int) ( $message_row['id'] ?? 0 ),
                            'provider_message_id'=> $message->providerMessageId(),
                        ]
                    ),
                    'created_at'  => $now,
                ]
            );
        }

        return [
            'handled'  => true,
            'status'   => 'handled',
            'metadata' => [
                'request_email' => $email,
                'contact_id'    => $contact_id,
            ],
        ];
    }
}
