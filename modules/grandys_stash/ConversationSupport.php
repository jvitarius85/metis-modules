<?php
declare(strict_types=1);

namespace Metis\Modules\GrandyStash;

final class ConversationSupport {
    private const TICKET_CODE_PATTERN = '/\b(GST-\d{6})\b/i';
    private const INTERNAL_ID_PATTERN = '/^\s*Internal ID:\s*(.+?)\s*$/mi';

    public static function extractTicketCode( string $subject ): string {
        if ( preg_match( self::TICKET_CODE_PATTERN, $subject, $matches ) !== 1 ) {
            return '';
        }

        return strtoupper( trim( (string) ( $matches[1] ?? '' ) ) );
    }

    public static function ensureTicketCodeInSubject( string $subject, string $ticket_code ): string {
        $ticket_code = strtoupper( trim( $ticket_code ) );
        $subject = trim( preg_replace( '/\s+/', ' ', $subject ) ?? '' );

        if ( $ticket_code === '' ) {
            return $subject !== '' ? $subject : "Grandy's Stash Update";
        }

        if ( self::extractTicketCode( $subject ) === $ticket_code ) {
            return $subject;
        }

        if ( $subject === '' ) {
            return '[' . $ticket_code . "] Grandy's Stash Update";
        }

        if ( preg_match( '/^((?:(?:re|fw|fwd|aw)\s*:\s*)+)(.*)$/i', $subject, $matches ) === 1 ) {
            $prefix = trim( (string) ( $matches[1] ?? '' ) );
            $rest = trim( (string) ( $matches[2] ?? '' ) );

            return trim( $prefix . ' [' . $ticket_code . '] ' . $rest );
        }

        return '[' . $ticket_code . '] ' . $subject;
    }

    public static function internalReferenceToken( string $ticket_code ): string {
        return strtoupper( trim( $ticket_code ) );
    }

    public static function appendInternalIdFooterToText( string $text, string $token ): string {
        $text = trim( str_replace( [ "\r\n", "\r" ], "\n", $text ) );
        $token = self::internalReferenceToken( $token );
        if ( $token === '' ) {
            return $text;
        }

        return $text
            . ( $text !== '' ? "\n\n" : '' )
            . "Internal ID: {$token}";
    }

    public static function appendInternalIdFooterToHtml( string $html, string $token ): string {
        $token = self::internalReferenceToken( $token );
        if ( $token === '' ) {
            return $html;
        }

        $footer = '<div style="margin-top:24px;font-size:12px;line-height:1.5;color:#667085;">Internal ID: '
            . htmlspecialchars( $token, ENT_QUOTES, 'UTF-8' )
            . '</div>';

        return $html . $footer;
    }

    public static function extractTicketCodeFromBody( string $body ): string {
        if ( preg_match( self::INTERNAL_ID_PATTERN, $body, $matches ) !== 1 ) {
            return '';
        }

        return self::extractTicketCode( (string) ( $matches[1] ?? '' ) );
    }

    /**
     * @param array<int, string>|string $raw
     * @return array<int, string>
     */
    public static function extractMessageIdTokens( array|string $raw ): array {
        $values = is_array( $raw ) ? $raw : [ $raw ];
        $tokens = [];

        foreach ( $values as $value ) {
            $value = trim( (string) $value );
            if ( $value === '' ) {
                continue;
            }

            if ( preg_match_all( '/<[^>]+>/', $value, $matches ) === 1 || ( isset( $matches[0] ) && is_array( $matches[0] ) ) ) {
                foreach ( (array) ( $matches[0] ?? [] ) as $token ) {
                    $normalized = self::normalizeMessageId( (string) $token );
                    if ( $normalized !== '' && ! in_array( $normalized, $tokens, true ) ) {
                        $tokens[] = $normalized;
                    }
                }
                continue;
            }

            foreach ( preg_split( '/\s+/', $value ) ?: [] as $token ) {
                $normalized = self::normalizeMessageId( (string) $token );
                if ( $normalized !== '' && ! in_array( $normalized, $tokens, true ) ) {
                    $tokens[] = $normalized;
                }
            }
        }

        return $tokens;
    }

    public static function normalizeMessageId( string $message_id ): string {
        $message_id = trim( $message_id );
        if ( $message_id === '' ) {
            return '';
        }

        $message_id = trim( $message_id, " \t\n\r\0\x0B<>" );
        if ( $message_id === '' ) {
            return '';
        }

        return '<' . $message_id . '>';
    }

    /**
     * @param array<int, string> $existing
     */
    public static function buildReferencesHeader( array $existing, string $message_id ): string {
        $tokens = self::extractMessageIdTokens( $existing );
        $message_id = self::normalizeMessageId( $message_id );

        if ( $message_id !== '' && ! in_array( $message_id, $tokens, true ) ) {
            $tokens[] = $message_id;
        }

        return implode( ' ', $tokens );
    }

    public static function extractLatestReplyText( string $text ): string {
        $text = str_replace( [ "\r\n", "\r" ], "\n", trim( $text ) );
        if ( $text === '' ) {
            return '';
        }

        $patterns = [
            '/\n\s*On .+ wrote:\s*$/mi',
            '/\n\s*From:\s.+$/mi',
            '/\n\s*>.+$/m',
            '/\n-{2,}\s*Original Message\s*-{2,}$/mi',
        ];

        $cut_at = null;
        foreach ( $patterns as $pattern ) {
            if ( preg_match( $pattern, $text, $matches, PREG_OFFSET_CAPTURE ) === 1 ) {
                $offset = (int) ( $matches[0][1] ?? -1 );
                if ( $offset >= 0 && ( $cut_at === null || $offset < $cut_at ) ) {
                    $cut_at = $offset;
                }
            }
        }

        if ( $cut_at !== null ) {
            $text = substr( $text, 0, $cut_at ) ?: '';
        }

        $text = trim( preg_replace( "/\n{3,}/", "\n\n", $text ) ?? $text );
        return $text;
    }
}

\class_alias( __NAMESPACE__ . '\\ConversationSupport', 'Metis\\Modules\\GrandyStashConversationSupport' );
