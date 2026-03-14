<?php
declare(strict_types=1);

namespace Metis\Modules\Newsletter;

final class Support {
    public static function baseUrl(): string {
        return rtrim( \metis_portal_url( 'newsletter' ), '/' );
    }

    public static function resolvedTimezone(): \DateTimeZone {
        if ( function_exists( 'metis_contacts_resolved_timezone' ) ) {
            return \metis_contacts_resolved_timezone();
        }

        $wp_tz = \get_option( 'timezone_string' );
        if ( is_string( $wp_tz ) && trim( $wp_tz ) !== '' ) {
            try {
                return new \DateTimeZone( trim( $wp_tz ) );
            } catch ( \Exception ) {
            }
        }

        return \metis_timezone();
    }

    public static function formatDatetime( string $mysql_datetime, string $format = 'm/d/y g:ia' ): string {
        $mysql_datetime = trim( $mysql_datetime );
        if ( $mysql_datetime === '' ) {
            return '—';
        }

        $tz = self::resolvedTimezone();
        $dt = \DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', $mysql_datetime, $tz );
        if ( $dt instanceof \DateTimeImmutable ) {
            return \metis_date( $format, $dt->getTimestamp(), $tz );
        }

        $ts = strtotime( $mysql_datetime );
        if ( ! $ts ) {
            return '—';
        }

        return \metis_date( $format, $ts, $tz );
    }

    public static function renderTemplate( string $html, array $contact ): string {
        $first_name   = (string) ( $contact['first_name'] ?? '' );
        $last_name    = (string) ( $contact['last_name'] ?? '' );
        $full_name    = trim( $first_name . ' ' . $last_name );
        $replacements = [
            '{{first_name}}'             => $first_name,
            '{{last_name}}'              => $last_name,
            '{{full_name}}'              => $full_name,
            '{{name}}'                   => $full_name,
            '{{email}}'                  => (string) ( $contact['email'] ?? '' ),
            '{{unsubscribe_url}}'        => '#',
            '{{manage_subscription_url}}'=> '#',
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
        return '<table role="presentation" data-metis-newsletter-shell="1" cellspacing="0" cellpadding="0" border="0" width="100%" style="width:100%;background:' . esc_attr( $canvas_bg ) . ';"><tr><td align="center" style="padding:0;">'
            . '<table role="presentation" cellspacing="0" cellpadding="0" border="0" width="680" style="width:680px;min-width:680px;max-width:680px;margin:0 auto;background:#ffffff;color:#1f2937;font-size:16px;"><tr><td style="padding:0;">'
            . $html
            . '</td></tr></table>'
            . '</td></tr></table>';
    }

    public static function b64url( string $value ): string {
        return rtrim( strtr( base64_encode( $value ), '+/', '-_' ), '=' );
    }

    public static function plainTextFromHtml( string $html ): string {
        $text = \metis_strip_all_tags( $html, true );
        $text = preg_replace( '/[ \t]+/', ' ', (string) $text );
        $text = preg_replace( "/\r\n|\r/", "\n", (string) $text );
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
