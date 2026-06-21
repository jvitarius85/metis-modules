<?php
declare(strict_types=1);

namespace Metis\Core\Services;

final class EmailService {
    private static bool $usageTableReady = false;
    private static bool $eventTableReady = false;

    public static function normalizeInternalReference( mixed $value ): string {
        if ( ! is_scalar( $value ) ) {
            return '';
        }

        $reference = (string) $value;
        if ( \function_exists( 'metis_text_clean' ) ) {
            $reference = (string) \metis_text_clean( $reference );
        }
        $reference = strtoupper( trim( $reference ) );
        if ( $reference === '' ) {
            return '';
        }

        return preg_replace( '/[^A-Z0-9:_\\-]+/', '', $reference ) ?? '';
    }

    public static function appendInternalReferenceToText( string $textBody, string $reference ): string {
        $reference = self::normalizeInternalReference( $reference );
        if ( $reference === '' ) {
            return $textBody;
        }

        $needle = 'Internal ID: ' . $reference;
        if ( stripos( $textBody, $needle ) !== false ) {
            return $textBody;
        }

        $body = rtrim( $textBody );
        if ( $body === '' ) {
            return $needle;
        }

        return $body . "\n\n" . $needle;
    }

    public static function appendInternalReferenceToHtml( string $htmlBody, string $reference ): string {
        $reference = self::normalizeInternalReference( $reference );
        if ( $reference === '' ) {
            return $htmlBody;
        }

        $needle = 'Internal ID: ' . $reference;
        if ( stripos( $htmlBody, $needle ) !== false ) {
            return $htmlBody;
        }

        $footer = '<div data-metis-internal-reference="1" style="margin-top:24px;font-size:12px;line-height:1.5;color:#98A2B3;">Internal ID: '
            . htmlspecialchars( $reference, ENT_QUOTES, 'UTF-8' )
            . '</div>';

        $trimmed = rtrim( $htmlBody );
        if ( $trimmed === '' ) {
            return $footer;
        }

        return $trimmed . $footer;
    }

    public static function ensureUsageTrackingReady(): void {
        $db = \metis_db();
        $usageTable = \Metis_Tables::get( 'email_usage_daily' );
        $eventTable = \Metis_Tables::get( 'email_send_events' );
        self::ensureUsageTable( $db, $usageTable );
        self::ensureEventTable( $db, $eventTable );
        self::repairUsageRollups( $db, $usageTable, $eventTable );
    }

    /**
     * @param array<string,mixed> $options
     * @return array{ok:bool,error?:string,provider?:string,fallback?:string}
     */
    public static function sendHtml( string $toEmail, string $subject, string $htmlBody, array $options = [] ): array {
        $to = strtolower( trim( $toEmail ) );
        $options['to_email'] = $to;
        $options['subject'] = $subject;
        $options['internal_reference'] = self::normalizeInternalReference( $options['internal_reference'] ?? '' );
        $options = self::applyConfiguredSenderDefaults( $options );
        if ( $to === '' || ! \metis_email_is_valid( $to ) ) {
            $result = [ 'ok' => false, 'error' => 'A valid recipient email is required.' ];
            self::trackUsage( self::detectModuleSlug( $options ), $result, $options );
            return $result;
        }

        if ( $options['internal_reference'] !== '' ) {
            $htmlBody = self::appendInternalReferenceToHtml( $htmlBody, $options['internal_reference'] );
        }

        // Preferred provider path (module-level implementation behind stable runtime function).
        if ( \function_exists( 'metis_newsletter_gmail_send' ) ) {
            $send = \metis_newsletter_gmail_send( $to, $subject, $htmlBody, $options );
            if ( \is_array( $send ) ) {
                $result = $send + [ 'provider' => 'newsletter.gmail' ];
                self::trackUsage( self::detectModuleSlug( $options ), $result, $options );
                return $result;
            }
            $result = [ 'ok' => false, 'error' => 'Email provider returned an invalid response.' ];
            self::trackUsage( self::detectModuleSlug( $options ), $result, $options );
            return $result;
        }

        // Lightweight fallback path for core runtime safety.
        $headers = [ 'Content-Type: text/html; charset=UTF-8' ];
        $fromName = trim( (string) ( $options['from_name'] ?? '' ) );
        $fromEmail = trim( (string) ( $options['from_email'] ?? '' ) );
        $replyTo = self::replyToHeaderValue( $options['reply_to'] ?? '' );
        if ( $fromEmail !== '' && \metis_email_is_valid( $fromEmail ) ) {
            $fromHeader = $fromEmail;
            if ( $fromName !== '' ) {
                $fromHeader = sprintf( '%s <%s>', self::encodeHeaderText( $fromName ), $fromEmail );
            }
            $headers[] = 'From: ' . $fromHeader;
        }
        if ( $replyTo !== '' && \metis_email_is_valid( $replyTo ) ) {
            $headers[] = 'Reply-To: ' . $replyTo;
        }
        if ( ! empty( $options['attachments'] ) ) {
            $result = [ 'ok' => false, 'error' => 'Email provider unavailable for attachments.' ];
            self::trackUsage( self::detectModuleSlug( $options ), $result, $options );
            return $result;
        }
        $ok = \metis_runtime_mail( $to, self::encodeHeaderText( $subject ), $htmlBody, $headers );
        if ( ! $ok ) {
            $result = [ 'ok' => false, 'error' => 'Fallback email delivery failed.' ];
            self::trackUsage( self::detectModuleSlug( $options ), $result, $options );
            return $result;
        }
        $result = [ 'ok' => true, 'provider' => 'runtime.mail', 'fallback' => 'mail()' ];
        self::trackUsage( self::detectModuleSlug( $options ), $result, $options );
        return $result;
    }

    /**
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    private static function applyConfiguredSenderDefaults( array $options ): array {
        if ( ! \class_exists( '\Core_Settings_Service' ) ) {
            return $options;
        }

        $fromName = trim( (string) ( $options['from_name'] ?? '' ) );
        $fromEmail = strtolower( trim( \metis_email_clean( (string) ( $options['from_email'] ?? '' ) ) ) );
        $replyTo = self::normalizeReplyToList( $options['reply_to'] ?? '' );

        $defaultFromName = trim( (string) \Core_Settings_Service::get( 'newsletter_default_from_name', '' ) );
        $defaultFromEmail = strtolower( trim( \metis_email_clean( (string) \Core_Settings_Service::get( 'newsletter_default_from_email', '' ) ) ) );
        $defaultReplyTo = strtolower( trim( \metis_email_clean( (string) \Core_Settings_Service::get( 'newsletter_default_reply_to', '' ) ) ) );

        if ( $fromName === '' && $defaultFromName !== '' ) {
            $options['from_name'] = $defaultFromName;
        }
        if ( ( $fromEmail === '' || ! \metis_email_is_valid( $fromEmail ) ) && $defaultFromEmail !== '' && \metis_email_is_valid( $defaultFromEmail ) ) {
            $options['from_email'] = $defaultFromEmail;
        }
        if ( $replyTo === [] && $defaultReplyTo !== '' && \metis_email_is_valid( $defaultReplyTo ) ) {
            $options['reply_to'] = [ $defaultReplyTo ];
        } elseif ( $replyTo !== [] ) {
            $options['reply_to'] = $replyTo;
        }

        return $options;
    }

    /**
     * @return array<int,string>
     */
    private static function normalizeReplyToList( mixed $replyTo ): array {
        $candidates = [];
        if ( is_array( $replyTo ) ) {
            $candidates = $replyTo;
        } elseif ( is_scalar( $replyTo ) ) {
            $candidates = preg_split( '/\s*,\s*/', (string) $replyTo ) ?: [];
        }

        $normalized = [];
        foreach ( $candidates as $candidate ) {
            $email = strtolower( trim( \metis_email_clean( (string) $candidate ) ) );
            if ( $email === '' || ! \metis_email_is_valid( $email ) ) {
                continue;
            }
            $normalized[] = $email;
        }

        return array_values( array_unique( $normalized ) );
    }

    private static function replyToHeaderValue( mixed $replyTo ): string {
        $emails = self::normalizeReplyToList( $replyTo );
        return $emails === [] ? '' : implode( ', ', $emails );
    }

    private static function encodeHeaderText( string $value ): string {
        $value = trim( $value );
        if ( $value === '' ) {
            return '';
        }

        if ( ! preg_match( '/[^\x20-\x7E]/', $value ) ) {
            return $value;
        }

        if ( function_exists( 'mb_encode_mimeheader' ) ) {
            $encoded = @mb_encode_mimeheader( $value, 'UTF-8', 'B', "\r\n" );
            if ( is_string( $encoded ) && $encoded !== '' ) {
                return $encoded;
            }
        }

        return '=?UTF-8?B?' . base64_encode( $value ) . '?=';
    }

    /**
     * @param array<string,mixed> $options
     */
    private static function detectModuleSlug( array $options ): string {
        $explicit = self::cleanKey( (string) ( $options['module'] ?? '' ) );
        if ( $explicit !== '' ) {
            return $explicit;
        }

        $trace = \debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 18 );
        foreach ( $trace as $frame ) {
            $class = (string) ( $frame['class'] ?? '' );
            if ( $class !== '' && \preg_match( '#\\\\Metis\\\\Modules\\\\([A-Za-z0-9_]+)\\\\#', $class, $matches ) ) {
                $slug = self::cleanKey( (string) ( $matches[1] ?? '' ) );
                if ( $slug !== '' ) {
                    return $slug;
                }
            }

            $file = (string) ( $frame['file'] ?? '' );
            if ( $file !== '' && \preg_match( '#/modules/([a-z0-9_\\-]+)/#i', $file, $matches ) ) {
                $slug = self::cleanKey( (string) ( $matches[1] ?? '' ) );
                if ( $slug !== '' ) {
                    return $slug;
                }
            }
        }

        return 'core';
    }

    /**
     * @param array<string,mixed> $result
     * @param array<string,mixed> $options
     */
    private static function trackUsage( string $moduleSlug, array $result, array $options ): void {
        $moduleSlug = self::cleanKey( $moduleSlug );
        if ( $moduleSlug === '' ) {
            $moduleSlug = 'core';
        }

        $db = \metis_db();
        $table = \Metis_Tables::get( 'email_usage_daily' );
        $eventTable = \Metis_Tables::get( 'email_send_events' );
        self::ensureUsageTable( $db, $table );
        self::ensureEventTable( $db, $eventTable );

        $provider = self::cleanKey( (string) ( $result['provider'] ?? '' ) );
        if ( $provider === '' ) {
            $provider = ! empty( $result['ok'] ) ? 'unknown' : 'error';
        }

        $sent = ! empty( $result['ok'] ) ? 1 : 0;
        $failed = ! empty( $result['ok'] ) ? 0 : 1;
        $timestamp = \gmdate( 'Y-m-d H:i:s' );
        $date = \gmdate( 'Y-m-d' );
        $toEmail = self::cleanEmail( (string) ( $options['to_email'] ?? $options['to'] ?? $options['recipient'] ?? '' ) );
        $subject = trim( (string) ( $options['subject'] ?? '' ) );
        $errorMessage = trim( (string) ( $result['error'] ?? '' ) );
        $meta = [
            'from_name' => (string) ( $options['from_name'] ?? '' ),
            'from_email' => (string) ( $options['from_email'] ?? '' ),
            'reply_to' => self::replyToHeaderValue( $options['reply_to'] ?? '' ),
            'internal_reference' => (string) ( $options['internal_reference'] ?? '' ),
            'fallback' => (string) ( $result['fallback'] ?? '' ),
        ];

        try {
            $inserted = $db->insert(
                $eventTable,
                [
                    'event_at' => $timestamp,
                    'module_slug' => $moduleSlug,
                    'status' => $sent > 0 ? 'sent' : 'failed',
                    'provider' => $provider,
                    'to_email' => $toEmail,
                    'subject' => $subject,
                    'error_message' => $errorMessage,
                    'meta_json' => \metis_json_encode( $meta ),
                ],
                [ '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ]
            );
            if ( $inserted === false ) {
                $lastError = $db->lastError() !== '' ? $db->lastError() : 'Unknown email event insert failure.';
                throw new \RuntimeException( $lastError );
            }

            $lastSentValue = $sent > 0 ? '%s' : 'NULL';
            $lastFailedValue = $failed > 0 ? '%s' : 'NULL';
            $upsertParams = [
                $date,
                $moduleSlug,
                $sent,
                $failed,
                $provider,
            ];
            if ( $sent > 0 ) {
                $upsertParams[] = $timestamp;
            }
            if ( $failed > 0 ) {
                $upsertParams[] = $timestamp;
            }

            $upserted = $db->executePrepared(
                "INSERT INTO {$table} (
                    usage_date, module_slug, sent_count, failed_count, last_provider, last_sent_at, last_failed_at
                 ) VALUES (%s, %s, %d, %d, %s, {$lastSentValue}, {$lastFailedValue})
                 ON DUPLICATE KEY UPDATE
                    sent_count = sent_count + VALUES(sent_count),
                    failed_count = failed_count + VALUES(failed_count),
                    last_provider = VALUES(last_provider),
                    last_sent_at = CASE WHEN VALUES(sent_count) > 0 THEN VALUES(last_sent_at) ELSE last_sent_at END,
                    last_failed_at = CASE WHEN VALUES(failed_count) > 0 THEN VALUES(last_failed_at) ELSE last_failed_at END",
                $upsertParams
            );
            if ( $upserted === false ) {
                $lastError = $db->lastError() !== '' ? $db->lastError() : 'Unknown email usage upsert failure.';
                throw new \RuntimeException( $lastError );
            }
        } catch ( \Throwable $e ) {
            \error_log( '[email.trackUsage] ' . $e->getMessage() );
        }
    }

    private static function ensureUsageTable( object $db, string $table ): void {
        if ( self::$usageTableReady ) {
            return;
        }

        $usage_table = \Metis_Tables::get( 'email_usage_daily' );
        if ( $table !== '' ) {
            $usage_table = $table;
        }

        $charset = \function_exists( 'metis_db_charset_collate' ) ? (string) \metis_db_charset_collate() : '';
        $sql = "CREATE TABLE IF NOT EXISTS {$usage_table} (
            usage_date DATE NOT NULL,
            module_slug VARCHAR(64) NOT NULL,
            sent_count INT UNSIGNED NOT NULL DEFAULT 0,
            failed_count INT UNSIGNED NOT NULL DEFAULT 0,
            last_provider VARCHAR(64) DEFAULT NULL,
            last_sent_at DATETIME DEFAULT NULL,
            last_failed_at DATETIME DEFAULT NULL,
            PRIMARY KEY (usage_date, module_slug),
            KEY module_date (module_slug, usage_date)
        ) {$charset}";

        if ( \function_exists( 'metis_db_delta' ) ) {
            \metis_db_delta( $sql );
        } else {
            $db->execute( $sql );
        }

        self::$usageTableReady = true;
    }

    private static function ensureEventTable( object $db, string $table ): void {
        if ( self::$eventTableReady ) {
            return;
        }

        $event_table = \Metis_Tables::get( 'email_send_events' );
        if ( $table !== '' ) {
            $event_table = $table;
        }

        $charset = \function_exists( 'metis_db_charset_collate' ) ? (string) \metis_db_charset_collate() : '';
        $sql = "CREATE TABLE IF NOT EXISTS {$event_table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            event_at DATETIME NOT NULL,
            module_slug VARCHAR(64) NOT NULL,
            status VARCHAR(16) NOT NULL,
            provider VARCHAR(64) DEFAULT NULL,
            to_email VARCHAR(191) DEFAULT NULL,
            subject VARCHAR(255) DEFAULT NULL,
            error_message TEXT DEFAULT NULL,
            meta_json LONGTEXT DEFAULT NULL,
            PRIMARY KEY (id),
            KEY module_event_at (module_slug, event_at),
            KEY status_event_at (status, event_at)
        ) {$charset}";

        if ( \function_exists( 'metis_db_delta' ) ) {
            \metis_db_delta( $sql );
        } else {
            $db->execute( $sql );
        }

        self::$eventTableReady = true;
    }

    private static function repairUsageRollups( object $db, string $usageTable, string $eventTable ): void {
        $sql = "INSERT INTO {$usageTable} (
                usage_date, module_slug, sent_count, failed_count, last_provider, last_sent_at, last_failed_at
            )
            SELECT
                DATE(e.event_at) AS usage_date,
                e.module_slug,
                SUM(CASE WHEN e.status = 'sent' THEN 1 ELSE 0 END) AS sent_count,
                SUM(CASE WHEN e.status = 'failed' THEN 1 ELSE 0 END) AS failed_count,
                SUBSTRING_INDEX(GROUP_CONCAT(e.provider ORDER BY e.event_at DESC SEPARATOR ','), ',', 1) AS last_provider,
                MAX(CASE WHEN e.status = 'sent' THEN e.event_at ELSE NULL END) AS last_sent_at,
                MAX(CASE WHEN e.status = 'failed' THEN e.event_at ELSE NULL END) AS last_failed_at
            FROM {$eventTable} e
            LEFT JOIN {$usageTable} u
                ON u.usage_date = DATE(e.event_at)
               AND u.module_slug = e.module_slug
            WHERE u.module_slug IS NULL
            GROUP BY DATE(e.event_at), e.module_slug";

        try {
            $db->execute( $sql );
        } catch ( \Throwable $e ) {
            \error_log( '[email.repairUsageRollups] ' . $e->getMessage() );
        }
    }

    private static function cleanKey( string $value ): string {
        $value = strtolower( trim( $value ) );
        if ( $value === '' ) {
            return '';
        }
        $value = preg_replace( '/[^a-z0-9_\\-]/', '', $value ) ?? '';
        return (string) $value;
    }

    private static function cleanEmail( string $value ): string {
        $value = strtolower( trim( $value ) );
        if ( $value === '' ) {
            return '';
        }
        return \filter_var( $value, FILTER_VALIDATE_EMAIL ) ? $value : '';
    }
}
