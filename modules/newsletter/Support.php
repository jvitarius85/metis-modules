<?php
declare(strict_types=1);

namespace Metis\Modules\Newsletter;

final class Support {
    public static function baseUrl(): string {
        return rtrim( \metis_portal_url( 'newsletter' ), '/' );
    }

    public static function resolvedTimezone(): \DateTimeZone {
        if ( function_exists( 'metis_runtime_timezone' ) ) {
            return \metis_runtime_timezone();
        }

        if ( function_exists( 'metis_contacts_resolved_timezone' ) ) {
            return \metis_contacts_resolved_timezone();
        }

        $configured_tz = \metis_get_option( 'timezone_string' );
        if ( is_string( $configured_tz ) && trim( $configured_tz ) !== '' ) {
            try {
                return new \DateTimeZone( trim( $configured_tz ) );
            } catch ( \Exception ) {
            }
        }

        return \metis_runtime_timezone();
    }

    public static function formatDatetime( string $mysql_datetime, string $format = 'm/d/y g:ia' ): string {
        $mysql_datetime = trim( $mysql_datetime );
        if ( $mysql_datetime === '' ) {
            return '—';
        }

        if ( \function_exists( 'metis_runtime_format_datetime' ) ) {
            $display_format = match ( $format ) {
                '', 'm/d/y g:ia', 'm/d/y g:i a', 'M j, Y g:i a' => null,
                'm/d/y', 'M j, Y' => \metis_runtime_date_format(),
                default => $format,
            };
            return \metis_runtime_format_datetime( $mysql_datetime, $display_format, null, null, '—' );
        }

        $tz = self::resolvedTimezone();
        $dt = \DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', $mysql_datetime, $tz );
        if ( $dt instanceof \DateTimeImmutable ) {
            return \metis_runtime_date( $format, $dt->getTimestamp(), $tz );
        }

        $ts = strtotime( $mysql_datetime );
        if ( ! $ts ) {
            return '—';
        }

        return \metis_runtime_date( $format, $ts, $tz );
    }

    public static function renderTemplate( string $html, array $contact ): string {
        $first_name   = (string) ( $contact['first_name'] ?? '' );
        $last_name    = (string) ( $contact['last_name'] ?? '' );
        $full_name    = trim( $first_name . ' ' . $last_name );
        $unsubscribe_url = trim( (string) ( $contact['unsubscribe_url'] ?? '' ) );
        $manage_url      = trim( (string) ( $contact['manage_subscription_url'] ?? '' ) );
        $view_url        = trim( (string) ( $contact['view_online_url'] ?? $contact['view_newsletter_url'] ?? '' ) );
        if ( strpos( $html, '<' ) === false ) {
            $html = str_replace(
                [ '{{unsubscribe_url}}', '{{manage_subscription_url}}', '{{view_online_url}}', '{{view_newsletter_url}}' ],
                [
                    'Unsubscribe: {{unsubscribe_url}}',
                    'Manage Preferences: {{manage_subscription_url}}',
                    'View online: {{view_online_url}}',
                    'View online: {{view_newsletter_url}}',
                ],
                $html
            );
        }
        $replacements = [
            '{{first_name}}'             => $first_name,
            '{{last_name}}'              => $last_name,
            '{{full_name}}'              => $full_name,
            '{{name}}'                   => $full_name,
            '{{email}}'                  => (string) ( $contact['email'] ?? '' ),
            '{{unsubscribe_url}}'        => $unsubscribe_url !== '' ? $unsubscribe_url : '#',
            '{{manage_subscription_url}}'=> $manage_url !== '' ? $manage_url : '#',
            '{{view_online_url}}'        => $view_url !== '' ? $view_url : '#',
            '{{view_newsletter_url}}'    => $view_url !== '' ? $view_url : '#',
            '{{contact_cid}}'            => (string) ( $contact['contact_cid'] ?? '' ),
            '{{campaign_name}}'          => (string) ( $contact['campaign_name'] ?? '' ),
            '{{city}}'                   => (string) ( $contact['city'] ?? '' ),
            '{{state}}'                  => (string) ( $contact['state'] ?? '' ),
        ];

        return strtr( $html, $replacements );
    }

    public static function ensureEmailContainer( string $html ): string {
        $html = trim( $html );
        if ( $html === '' ) {
            return '';
        }
        if ( stripos( $html, 'data-metis-newsletter-shell="1"' ) !== false ) {
            return $html;
        }

        $canvas_bg = '#ffffff';
        return '<table role="presentation" data-metis-newsletter-shell="1" cellspacing="0" cellpadding="0" border="0" width="100%" style="width:100%;background:' . \metis_escape_attr( $canvas_bg ) . ';"><tr><td align="center" style="padding:0;">'
            . '<table role="presentation" cellspacing="0" cellpadding="0" border="0" width="680" style="width:680px;min-width:680px;max-width:680px;margin:0 auto;background:#ffffff;color:#1f2937;font-size:16px;"><tr><td style="padding:0;">'
            . $html
            . '</td></tr></table>'
            . '</td></tr></table>';
    }

    public static function b64url( string $value ): string {
        return rtrim( strtr( base64_encode( $value ), '+/', '-_' ), '=' );
    }

    public static function plainTextFromHtml( string $html ): string {
        $html = str_replace( [ "\r\n", "\r" ], "\n", $html );
        $html = preg_replace( '#<(br|/p|/div|/li|/tr|/h[1-6])\b[^>]*>#i', "$0\n", $html ) ?? $html;
        $html = preg_replace( '#<(p|div|li|tr|h[1-6]|table|section|article)\b[^>]*>#i', "\n$0", $html ) ?? $html;
        $html = preg_replace( '#<td\b[^>]*>#i', "\t", $html ) ?? $html;
        $text = strip_tags( $html );
        $text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
        $text = str_replace( "\t", ' ', $text );
        $text = preg_replace( "/[ \t]+\n/", "\n", (string) $text );
        $text = preg_replace( "/\n[ \t]+/", "\n", (string) $text );
        $text = preg_replace( '/[ \t]{2,}/', ' ', (string) $text );
        $text = preg_replace( "/\n{3,}/", "\n\n", (string) $text );
        return trim( (string) $text );
    }

    public static function googleUsageDailyLimit(): int {
        $configured = (int) \Core_Settings_Service::get( 'newsletter_google_daily_limit', 2000 );
        if ( $configured < 100 ) {
            $configured = 2000;
        }

        return $configured;
    }
}
