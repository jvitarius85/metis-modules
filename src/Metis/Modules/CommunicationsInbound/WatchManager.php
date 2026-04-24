<?php
declare(strict_types=1);

namespace Metis\Modules\CommunicationsInbound;

final class WatchManager {
    public function __construct(
        private readonly GmailClient $gmail,
        private readonly MailboxRepository $mailboxes
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function watchMailboxByEmail( string $mailbox_email, bool $force = false ): array {
        $mailbox = $this->mailboxes->findByEmail( $mailbox_email );
        if ( ! is_array( $mailbox ) ) {
            return [ 'ok' => false, 'error' => 'Mailbox is not configured.' ];
        }

        $expires_at = (string) ( $mailbox['watch_expiration_at'] ?? '' );
        if ( ! $force && $expires_at !== '' && strtotime( $expires_at ) > time() + DAY_IN_SECONDS ) {
            return [ 'ok' => true, 'status' => 'fresh', 'mailbox' => $mailbox_email ];
        }

        $response = $this->gmail->watchMailbox( $mailbox );
        if ( empty( $response['ok'] ) ) {
            $this->mailboxes->updateState(
                (int) $mailbox['id'],
                [
                    'sync_status' => 'watch_failed',
                    'last_error'  => (string) ( $response['error'] ?? 'Watch request failed.' ),
                ]
            );
            return $response;
        }

        $expiration_unix = (string) ( $response['expiration'] ?? '' );
        $expiration_at = ctype_digit( $expiration_unix )
            ? gmdate( 'Y-m-d H:i:s', (int) floor( (int) $expiration_unix / 1000 ) )
            : null;

        $this->mailboxes->updateState(
            (int) $mailbox['id'],
            [
                'current_history_id'      => (string) ( $response['history_id'] ?? '' ),
                'last_watch_history_id'   => (string) ( $response['history_id'] ?? '' ),
                'watch_expiration_at'     => $expiration_at,
                'last_watch_requested_at' => \metis_current_time( 'mysql' ),
                'sync_status'             => 'watching',
                'last_error'              => '',
            ]
        );

        return [
            'ok'          => true,
            'mailbox'     => $mailbox_email,
            'history_id'  => (string) ( $response['history_id'] ?? '' ),
            'expiration'  => $expiration_at,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function renewDueWatches(): array {
        $results = [];
        foreach ( $this->mailboxes->mailboxesDueForRenewal() as $mailbox ) {
            $results[] = $this->watchMailboxByEmail( (string) ( $mailbox['mailbox_email'] ?? '' ), true );
        }

        return [
            'ok'      => true,
            'count'   => count( $results ),
            'results' => $results,
        ];
    }
}
