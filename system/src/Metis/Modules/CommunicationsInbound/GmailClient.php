<?php
declare(strict_types=1);

namespace Metis\Modules\CommunicationsInbound;

class GmailClient {
    public function __construct( private readonly WorkspaceGoogleService $google ) {}

    /**
     * @param array<string, mixed> $mailbox
     * @return array<string, mixed>
     */
    public function watchMailbox( array $mailbox ): array {
        $config = Settings::config();
        $topic_name = (string) ( $config['pubsub_topic_name'] ?? '' );
        if ( $topic_name === '' ) {
            return [ 'ok' => false, 'error' => 'Inbound Pub/Sub topic is not configured.' ];
        }

        $body = [
            'topicName' => $topic_name,
        ];

        $label_ids = (array) ( $mailbox['label_ids'] ?? [] );
        if ( $label_ids !== [] ) {
            $body['labelIds'] = array_values( $label_ids );
        }

        $label_filter_behavior = (string) ( $mailbox['label_filter_behavior'] ?? '' );
        if ( $label_filter_behavior !== '' ) {
            $body['labelFilterBehavior'] = strtoupper( $label_filter_behavior );
        }

        $cfg = $this->google->configForMailbox( $mailbox );
        $response = $this->google->request(
            'POST',
            'https://gmail.googleapis.com/gmail/v1/users/me/watch',
            $cfg,
            $body
        );

        if ( empty( $response['ok'] ) ) {
            return $response;
        }

        $payload = (array) ( $response['body'] ?? [] );

        return [
            'ok'         => true,
            'history_id' => (string) ( $payload['historyId'] ?? '' ),
            'expiration' => (string) ( $payload['expiration'] ?? '' ),
            'body'       => $payload,
        ];
    }

    /**
     * @param array<string, mixed> $mailbox
     * @return array<string, mixed>
     */
    public function getProfile( array $mailbox ): array {
        $cfg = $this->google->configForMailbox( $mailbox );
        $response = $this->google->request(
            'GET',
            'https://gmail.googleapis.com/gmail/v1/users/me/profile',
            $cfg
        );

        if ( empty( $response['ok'] ) ) {
            return $response;
        }

        $body = (array) ( $response['body'] ?? [] );
        return [
            'ok'           => true,
            'emailAddress' => (string) ( $body['emailAddress'] ?? '' ),
            'historyId'    => (string) ( $body['historyId'] ?? '' ),
            'body'         => $body,
        ];
    }

    /**
     * @param array<string, mixed> $mailbox
     * @return array<string, mixed>
     */
    public function sendRawMessage( array $mailbox, string $raw_mime, string $thread_id = '' ): array {
        $cfg = $this->google->configForMailbox(
            $mailbox,
            [ 'https://www.googleapis.com/auth/gmail.send' ]
        );

        $body = [
            'raw' => WorkspaceGoogleService::b64urlEncode( $raw_mime ),
        ];
        $thread_id = trim( $thread_id );
        if ( $thread_id !== '' ) {
            $body['threadId'] = $thread_id;
        }

        $response = $this->google->request(
            'POST',
            'https://gmail.googleapis.com/gmail/v1/users/me/messages/send',
            $cfg,
            $body
        );

        if ( empty( $response['ok'] ) ) {
            return $response;
        }

        $payload = (array) ( $response['body'] ?? [] );

        return [
            'ok'         => true,
            'gmail_id'   => (string) ( $payload['id'] ?? '' ),
            'thread_id'  => (string) ( $payload['threadId'] ?? '' ),
            'label_ids'  => array_values( array_map( 'strval', (array) ( $payload['labelIds'] ?? [] ) ) ),
            'body'       => $payload,
        ];
    }

    /**
     * @param array<string, mixed> $mailbox
     * @return array<string, mixed>
     */
    public function fetchMessage( array $mailbox, string $message_id ): array {
        $cfg = $this->google->configForMailbox( $mailbox );
        $url = \metis_add_query_arg(
            [ 'format' => 'full' ],
            'https://gmail.googleapis.com/gmail/v1/users/me/messages/' . rawurlencode( $message_id )
        );

        return $this->google->request( 'GET', $url, $cfg );
    }

    /**
     * @param array<string, mixed> $mailbox
     * @return array<string, mixed>
     */
    public function fetchAttachment( array $mailbox, string $message_id, string $attachment_id ): array {
        $message_id = trim( $message_id );
        $attachment_id = trim( $attachment_id );
        if ( $message_id === '' || $attachment_id === '' ) {
            return [ 'ok' => false, 'error' => 'Attachment lookup is missing message context.' ];
        }

        $cfg = $this->google->configForMailbox( $mailbox );
        $url = 'https://gmail.googleapis.com/gmail/v1/users/me/messages/'
            . rawurlencode( $message_id )
            . '/attachments/'
            . rawurlencode( $attachment_id );

        $response = $this->google->request( 'GET', $url, $cfg );
        if ( empty( $response['ok'] ) ) {
            return $response;
        }

        $body = (array) ( $response['body'] ?? [] );
        $data = (string) ( $body['data'] ?? '' );
        return [
            'ok'    => true,
            'bytes' => $data !== '' ? WorkspaceGoogleService::b64urlDecode( $data ) : '',
            'size'  => (int) ( $body['size'] ?? 0 ),
            'body'  => $body,
        ];
    }

    /**
     * @param array<string, mixed> $mailbox
     * @return array<string, mixed>
     */
    public function collectChangedMessages( array $mailbox, string $start_history_id, bool $force_full = false ): array {
        if ( $force_full || trim( $start_history_id ) === '' ) {
            return $this->fullSyncRecentMessages( $mailbox );
        }

        $cfg = $this->google->configForMailbox( $mailbox );
        $messages = [];
        $seen = [];
        $latest_history_id = $start_history_id;
        $page_token = '';

        do {
            $params = [
                'startHistoryId' => $start_history_id,
                'historyTypes'   => 'messageAdded',
                'maxResults'     => 500,
            ];

            if ( $page_token !== '' ) {
                $params['pageToken'] = $page_token;
            }

            $url = \metis_add_query_arg( $params, 'https://gmail.googleapis.com/gmail/v1/users/me/history' );
            $response = $this->google->request( 'GET', $url, $cfg );

            if ( empty( $response['ok'] ) ) {
                if ( (int) ( $response['status'] ?? 0 ) === 404 ) {
                    return $this->fullSyncRecentMessages( $mailbox, true );
                }

                return $response;
            }

            $body = (array) ( $response['body'] ?? [] );
            $latest_history_id = (string) ( $body['historyId'] ?? $latest_history_id );
            foreach ( (array) ( $body['history'] ?? [] ) as $history_row ) {
                if ( ! is_array( $history_row ) ) {
                    continue;
                }

                $history_id = (string) ( $history_row['id'] ?? '' );
                if ( $history_id !== '' ) {
                    $latest_history_id = $history_id;
                }

                foreach ( (array) ( $history_row['messagesAdded'] ?? [] ) as $added ) {
                    $message_id = trim( (string) ( $added['message']['id'] ?? '' ) );
                    if ( $message_id === '' || isset( $seen[ $message_id ] ) ) {
                        continue;
                    }

                    $seen[ $message_id ] = true;
                    $message_response = $this->fetchMessage( $mailbox, $message_id );
                    if ( empty( $message_response['ok'] ) ) {
                        continue;
                    }

                    $message = (array) ( $message_response['body'] ?? [] );
                    if ( ! $this->isInboundMessage( $message ) ) {
                        continue;
                    }

                    $messages[] = $message;
                }
            }

            $page_token = trim( (string) ( $body['nextPageToken'] ?? '' ) );
        } while ( $page_token !== '' );

        return [
            'ok'                 => true,
            'mode'               => 'history',
            'messages'           => $messages,
            'latest_history_id'  => $latest_history_id,
        ];
    }

    /**
     * @param array<string, mixed> $mailbox
     * @return array<string, mixed>
     */
    public function fullSyncRecentMessages( array $mailbox, bool $recovered_from_history_gap = false ): array {
        $cfg = $this->google->configForMailbox( $mailbox );
        $days = Settings::config()['full_sync_days'] ?? 30;
        $page_token = '';
        $messages = [];
        $seen = [];

        do {
            $params = [
                'maxResults' => 100,
                'q'          => 'newer_than:' . (int) $days . 'd',
            ];

            if ( $page_token !== '' ) {
                $params['pageToken'] = $page_token;
            }

            $url = \metis_add_query_arg( $params, 'https://gmail.googleapis.com/gmail/v1/users/me/messages' );
            $response = $this->google->request( 'GET', $url, $cfg );
            if ( empty( $response['ok'] ) ) {
                return $response;
            }

            $body = (array) ( $response['body'] ?? [] );
            foreach ( (array) ( $body['messages'] ?? [] ) as $row ) {
                if ( ! is_array( $row ) ) {
                    continue;
                }

                $message_id = trim( (string) ( $row['id'] ?? '' ) );
                if ( $message_id === '' || isset( $seen[ $message_id ] ) ) {
                    continue;
                }

                $seen[ $message_id ] = true;
                $message_response = $this->fetchMessage( $mailbox, $message_id );
                if ( empty( $message_response['ok'] ) ) {
                    continue;
                }

                $message = (array) ( $message_response['body'] ?? [] );
                if ( ! $this->isInboundMessage( $message ) ) {
                    continue;
                }

                $messages[] = $message;
            }

            $page_token = trim( (string) ( $body['nextPageToken'] ?? '' ) );
        } while ( $page_token !== '' );

        $profile = $this->getProfile( $mailbox );

        return [
            'ok'                        => true,
            'mode'                      => $recovered_from_history_gap ? 'full_fallback' : 'full',
            'messages'                  => $messages,
            'latest_history_id'         => (string) ( $profile['historyId'] ?? '' ),
            'recovered_from_history_gap'=> $recovered_from_history_gap,
        ];
    }

    /**
     * @param array<string, mixed> $message
     */
    public function isInboundMessage( array $message ): bool {
        $label_ids = array_map( 'strval', (array) ( $message['labelIds'] ?? [] ) );
        $blocked = [ 'SENT', 'DRAFT', 'CHAT', 'TRASH' ];
        foreach ( $blocked as $label_id ) {
            if ( in_array( $label_id, $label_ids, true ) ) {
                return false;
            }
        }

        return true;
    }
}
