<?php
declare(strict_types=1);

namespace Metis\Modules\CommunicationsInbound;

use Metis\Modules\CommunicationsInbound\ValueObjects\NormalizedInboundMessage;

final class InboundMessageNormalizer {
    /**
     * @param array<string, mixed> $mailbox
     * @param array<string, mixed> $gmail_message
     */
    public function normalizeGmailMessage( array $mailbox, array $gmail_message ): NormalizedInboundMessage {
        $payload = is_array( $gmail_message['payload'] ?? null ) ? (array) $gmail_message['payload'] : [];
        $headers = $this->normalizeHeaders( (array) ( $payload['headers'] ?? [] ) );
        $parts = $this->collectMessageParts( $payload );

        $subject = $this->decodeMimeHeader( $this->firstHeaderValue( $headers, 'subject' ) );
        $from_header = $this->decodeMimeHeader( $this->firstHeaderValue( $headers, 'from' ) );
        $reply_to_header = $this->decodeMimeHeader( $this->firstHeaderValue( $headers, 'reply-to' ) );
        $to_header = $this->decodeMimeHeader( $this->firstHeaderValue( $headers, 'to' ) );
        $cc_header = $this->decodeMimeHeader( $this->firstHeaderValue( $headers, 'cc' ) );
        $message_id_header = $this->firstHeaderValue( $headers, 'message-id' );
        $date_header = $this->firstHeaderValue( $headers, 'date' );

        $text_body = trim( (string) ( $parts['text'] ?? '' ) );
        $html_body = trim( (string) ( $parts['html'] ?? '' ) );
        if ( $text_body === '' && $html_body !== '' ) {
            $text_body = trim( preg_replace( '/\s+/', ' ', strip_tags( $html_body ) ) ?? '' );
        }

        $from = $this->parseAddressHeader( $from_header );
        $reply_to = $this->parseAddressHeader( $reply_to_header );
        $to = $this->parseAddressHeader( $to_header );
        $cc = $this->parseAddressHeader( $cc_header );

        $internal_date = trim( (string) ( $gmail_message['internalDate'] ?? '' ) );
        $received_at = $internal_date !== '' && ctype_digit( $internal_date )
            ? gmdate( 'Y-m-d H:i:s', (int) floor( (int) $internal_date / 1000 ) )
            : $this->normalizeDateHeader( $date_header );

        return new NormalizedInboundMessage(
            [
                'provider'                    => 'gmail',
                'provider_mailbox'            => (string) ( $mailbox['mailbox_email'] ?? '' ),
                'provider_message_id'         => (string) ( $gmail_message['id'] ?? '' ),
                'provider_thread_id'          => (string) ( $gmail_message['threadId'] ?? '' ),
                'provider_history_id'         => (string) ( $gmail_message['historyId'] ?? '' ),
                'rfc_message_id'              => trim( $message_id_header, " \t\n\r\0\x0B<>" ),
                'subject'                     => $subject,
                'from'                        => $from,
                'to'                          => $to,
                'cc'                          => $cc,
                'reply_to'                    => $reply_to,
                'sent_at'                     => $this->normalizeDateHeader( $date_header ),
                'received_at'                 => $received_at,
                'headers'                     => $headers,
                'raw_headers'                 => (array) ( $payload['headers'] ?? [] ),
                'text_body'                   => $text_body,
                'html_body'                   => $html_body,
                'attachments'                 => (array) ( $parts['attachments'] ?? [] ),
                'raw_provider_payload'        => $gmail_message,
                'canonical_sender_email'      => strtolower( trim( (string) ( $from[0]['email'] ?? $reply_to[0]['email'] ?? '' ) ) ),
                'canonical_recipient_emails'  => $this->collectRecipientEmails( $to, $cc ),
            ]
        );
    }

    /**
     * @param array<int, array<string, mixed>> $raw_headers
     * @return array<string, array<int, string>>
     */
    private function normalizeHeaders( array $raw_headers ): array {
        $headers = [];
        foreach ( $raw_headers as $row ) {
            if ( ! is_array( $row ) ) {
                continue;
            }

            $name = strtolower( trim( (string) ( $row['name'] ?? '' ) ) );
            if ( $name === '' ) {
                continue;
            }

            $headers[ $name ] ??= [];
            $headers[ $name ][] = (string) ( $row['value'] ?? '' );
        }

        return $headers;
    }

    /**
     * @param array<string, array<int, string>> $headers
     */
    private function firstHeaderValue( array $headers, string $name ): string {
        $name = strtolower( trim( $name ) );
        return isset( $headers[ $name ][0] ) ? (string) $headers[ $name ][0] : '';
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{text: string, html: string, attachments: array<int, array<string, mixed>>}
     */
    private function collectMessageParts( array $payload ): array {
        $text = '';
        $html = '';
        $attachments = [];

        $walker = function ( array $part ) use ( &$walker, &$text, &$html, &$attachments ): void {
            $mime_type = strtolower( trim( (string) ( $part['mimeType'] ?? '' ) ) );
            $filename = trim( (string) ( $part['filename'] ?? '' ) );
            $body = is_array( $part['body'] ?? null ) ? (array) $part['body'] : [];
            $data = (string) ( $body['data'] ?? '' );

            if ( $filename !== '' || ! empty( $body['attachmentId'] ) ) {
                $attachments[] = [
                    'filename'      => $filename,
                    'mime_type'     => $mime_type,
                    'attachment_id' => (string) ( $body['attachmentId'] ?? '' ),
                    'size'          => (int) ( $body['size'] ?? 0 ),
                    'part_id'       => (string) ( $part['partId'] ?? '' ),
                ];
            }

            if ( $data !== '' ) {
                $decoded = WorkspaceGoogleService::b64urlDecode( $data );
                if ( $mime_type === 'text/plain' ) {
                    $text .= ( $text !== '' ? "\n\n" : '' ) . $decoded;
                } elseif ( $mime_type === 'text/html' ) {
                    $html .= ( $html !== '' ? "\n" : '' ) . $decoded;
                }
            }

            foreach ( (array) ( $part['parts'] ?? [] ) as $child ) {
                if ( is_array( $child ) ) {
                    $walker( $child );
                }
            }
        };

        $walker( $payload );

        return [
            'text'        => $text,
            'html'        => $html,
            'attachments' => $attachments,
        ];
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function parseAddressHeader( string $value ): array {
        $value = trim( $value );
        if ( $value === '' ) {
            return [];
        }

        $matches = [];
        preg_match_all(
            '/(?:(?:"?([^"<]+)"?\s*)?<([^>]+)>)|([A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,})/i',
            $value,
            $matches,
            PREG_SET_ORDER
        );

        $entries = [];
        foreach ( $matches as $match ) {
            $email = strtolower( trim( (string) ( $match[2] ?? $match[3] ?? '' ) ) );
            $valid_email = \function_exists( 'metis_email_is_valid' )
                ? \metis_email_is_valid( $email )
                : filter_var( $email, FILTER_VALIDATE_EMAIL );
            if ( $email === '' || ! $valid_email ) {
                continue;
            }

            $name = trim( (string) ( $match[1] ?? '' ) );
            $entries[] = [
                'name'  => $this->decodeMimeHeader( $name ),
                'email' => $email,
                'raw'   => trim( $match[0] ?? '' ),
            ];
        }

        return $entries;
    }

    /**
     * @param array<int, array<string, string>> $to
     * @param array<int, array<string, string>> $cc
     * @return array<int, string>
     */
    private function collectRecipientEmails( array $to, array $cc ): array {
        $emails = [];
        foreach ( array_merge( $to, $cc ) as $row ) {
            $email = strtolower( trim( (string) ( $row['email'] ?? '' ) ) );
            if ( $email !== '' && ! in_array( $email, $emails, true ) ) {
                $emails[] = $email;
            }
        }

        return $emails;
    }

    private function normalizeDateHeader( string $value ): ?string {
        $value = trim( $value );
        if ( $value === '' ) {
            return null;
        }

        $timestamp = strtotime( $value );
        return $timestamp ? gmdate( 'Y-m-d H:i:s', $timestamp ) : null;
    }

    private function decodeMimeHeader( string $value ): string {
        $value = trim( $value );
        if ( $value === '' ) {
            return '';
        }

        if ( function_exists( 'iconv_mime_decode' ) ) {
            $decoded = @iconv_mime_decode( $value, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8' );
            if ( is_string( $decoded ) && $decoded !== '' ) {
                return $decoded;
            }
        }

        if ( function_exists( 'mb_decode_mimeheader' ) ) {
            $decoded = @mb_decode_mimeheader( $value );
            if ( is_string( $decoded ) && $decoded !== '' ) {
                return $decoded;
            }
        }

        return $value;
    }
}
