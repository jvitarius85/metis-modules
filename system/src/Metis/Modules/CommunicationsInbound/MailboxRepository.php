<?php
declare(strict_types=1);

namespace Metis\Modules\CommunicationsInbound;

use Metis\Services\DatabaseService;

final class MailboxRepository {
    private bool $config_sync_in_progress = false;
    private bool $config_synced = false;

    public function __construct( private readonly DatabaseService $db ) {}

    /**
     * @return array<int, array<string, mixed>>
     */
    public function ensureConfiguredMailboxes(): array {
        if ( $this->config_sync_in_progress ) {
            return $this->enabledMailboxesWithoutSync();
        }

        if ( $this->config_synced ) {
            return $this->enabledMailboxesWithoutSync();
        }

        $this->config_sync_in_progress = true;
        $rows = [];
        try {
            foreach ( Settings::mailboxes() as $mailbox ) {
                $rows[] = $this->upsertConfiguredMailbox( $mailbox );
            }
            $this->config_synced = true;
        } finally {
            $this->config_sync_in_progress = false;
        }

        return $rows;
    }

    /**
     * @param array<string, mixed> $mailbox
     * @return array<string, mixed>
     */
    public function upsertConfiguredMailbox( array $mailbox ): array {
        $table = \Metis_Tables::get( 'communications_inbound_mailboxes' );
        $provider = 'gmail';
        $mailbox_email = strtolower( trim( (string) ( $mailbox['mailbox_email'] ?? '' ) ) );
        $existing = $this->db->fetchOne(
            "SELECT * FROM {$table} WHERE provider = %s AND mailbox_email = %s LIMIT 1",
            [ $provider, $mailbox_email ]
        );

        $payload = [
            'mailbox_key'           => (string) ( $mailbox['mailbox_key'] ?? '' ),
            'provider'              => $provider,
            'mailbox_email'         => $mailbox_email,
            'display_name'          => (string) ( $mailbox['display_name'] ?? '' ),
            'delegated_user'        => (string) ( $mailbox['delegated_user'] ?? $mailbox_email ),
            'topic_name'            => (string) ( Settings::config()['pubsub_topic_name'] ?? '' ),
            'label_ids_json'        => \metis_json_encode( (array) ( $mailbox['label_ids'] ?? [] ) ),
            'label_filter_behavior' => (string) ( $mailbox['label_filter_behavior'] ?? '' ),
            'enabled'               => ! empty( $mailbox['enabled'] ) ? 1 : 0,
            'settings_hash'         => sha1( \metis_json_encode( $mailbox ) ),
            'updated_at'            => \metis_current_time( 'mysql' ),
        ];

        if ( is_array( $existing ) ) {
            $this->db->update(
                $table,
                $payload,
                [ 'id' => (int) ( $existing['id'] ?? 0 ) ],
                [ '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s' ],
                [ '%d' ]
            );

            return $this->findByEmailWithoutSync( $mailbox_email ) ?? $existing;
        }

        $payload['created_at'] = \metis_current_time( 'mysql' );
        $this->db->insert(
            $table,
            $payload,
            [ '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s' ]
        );

        return $this->findByEmailWithoutSync( $mailbox_email ) ?? $payload;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByEmail( string $mailbox_email ): ?array {
        $mailbox_email = strtolower( trim( self::cleanEmail( $mailbox_email ) ) );
        if ( $mailbox_email === '' ) {
            return null;
        }

        $this->ensureConfiguredMailboxes();
        return $this->findByEmailWithoutSync( $mailbox_email );
    }

    private static function cleanEmail( string $email ): string {
        if ( function_exists( 'metis_email_clean' ) ) {
            return (string) \metis_email_clean( $email );
        }

        $sanitized = filter_var( $email, FILTER_SANITIZE_EMAIL );
        return is_string( $sanitized ) ? $sanitized : '';
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findByEmailWithoutSync( string $mailbox_email ): ?array {
        $table = \Metis_Tables::get( 'communications_inbound_mailboxes' );
        $row = $this->db->fetchOne(
            "SELECT * FROM {$table} WHERE provider = %s AND mailbox_email = %s LIMIT 1",
            [ 'gmail', $mailbox_email ]
        );

        return is_array( $row ) ? $this->hydrateMailboxRow( $row ) : null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function enabledMailboxes(): array {
        $this->ensureConfiguredMailboxes();
        return $this->enabledMailboxesWithoutSync();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function enabledMailboxesWithoutSync(): array {
        $table = \Metis_Tables::get( 'communications_inbound_mailboxes' );
        $rows = $this->db->fetchAll(
            "SELECT * FROM {$table} WHERE provider = %s AND enabled = 1 ORDER BY mailbox_email ASC",
            [ 'gmail' ]
        );

        return array_map( [ $this, 'hydrateMailboxRow' ], $rows );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function mailboxesDueForRenewal( int $lead_seconds = 86400 ): array {
        $rows = [];
        $threshold = gmdate( 'Y-m-d H:i:s', time() + max( 300, $lead_seconds ) );
        foreach ( $this->enabledMailboxes() as $row ) {
            $expires_at = (string) ( $row['watch_expiration_at'] ?? '' );
            if ( $expires_at === '' || $expires_at <= $threshold ) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /**
     * @param array<string, mixed> $state
     */
    public function updateState( int $mailbox_id, array $state ): void {
        $table = \Metis_Tables::get( 'communications_inbound_mailboxes' );
        $payload = [];
        $formats = [];

        $map = [
            'current_history_id'     => '%s',
            'last_watch_history_id'  => '%s',
            'watch_expiration_at'    => '%s',
            'last_watch_requested_at'=> '%s',
            'last_sync_requested_at' => '%s',
            'last_synced_at'         => '%s',
            'last_message_received_at'=> '%s',
            'sync_status'            => '%s',
            'last_error'             => '%s',
            'metadata_json'          => '%s',
        ];

        foreach ( $map as $key => $format ) {
            if ( ! array_key_exists( $key, $state ) ) {
                continue;
            }

            $payload[ $key ] = $state[ $key ];
            $formats[] = $format;
        }

        if ( $payload === [] ) {
            return;
        }

        $payload['updated_at'] = \metis_current_time( 'mysql' );
        $formats[] = '%s';

        $this->db->update( $table, $payload, [ 'id' => $mailbox_id ], $formats, [ '%d' ] );
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function hydrateMailboxRow( array $row ): array {
        $label_ids = json_decode( (string) ( $row['label_ids_json'] ?? '[]' ), true );
        $row['label_ids'] = is_array( $label_ids ) ? $label_ids : [];
        return $row;
    }
}
