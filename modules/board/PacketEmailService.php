<?php
declare(strict_types=1);

namespace Metis\Modules\Board;

final class PacketEmailService {
    public static function sendPacketPublishEmail( int $meeting_id ): array {
        if ( $meeting_id < 1 ) {
            return [ 'ok' => false, 'error' => 'Meeting is required.' ];
        }
        if ( ! class_exists( '\Metis\Core\Services\EmailService' ) ) {
            return [ 'ok' => false, 'error' => 'Email delivery service is unavailable.' ];
        }

        $db = \metis_db();
        $meetings_table = \Metis_Tables::get( 'board_meetings' );
        $documents_table = \Metis_Tables::get( 'board_documents' );
        $people_table = \Metis_Tables::get( 'people' );
        $committees_table = \Metis_Tables::get( 'board_committees' );

        $meeting = $db->fetchOne(
            "SELECT m.id, m.title, m.meeting_code, m.meeting_date, m.location, m.board_packet_notes, m.meeting_type, m.committee_id,
                    c.name AS committee_name
             FROM {$meetings_table} m
             LEFT JOIN {$committees_table} c ON c.id = m.committee_id
             WHERE m.id = %d
             LIMIT 1",
            [ $meeting_id ]
        );
        if ( ! $meeting ) {
            return [ 'ok' => false, 'error' => 'Meeting not found.' ];
        }

        $president = $db->fetchOne(
            "SELECT id, email, display_name, first_name, last_name
             FROM {$people_table}
             WHERE is_board = 1
               AND status = 'active'
               AND email <> ''
               AND LOWER(TRIM(COALESCE(board_position, ''))) IN ('president', 'board president')
             ORDER BY id ASC
             LIMIT 1"
        );
        $packet_doc = $db->fetchOne(
            "SELECT id, title, google_file_id, google_drive_url
             FROM {$documents_table}
             WHERE meeting_id = %d
               AND doc_type = 'board_packet'
               AND google_file_id IS NOT NULL
               AND google_file_id <> ''
             ORDER BY updated_at DESC, id DESC
             LIMIT 1",
            [ $meeting_id ]
        );
        if ( ! $packet_doc ) {
            return [ 'ok' => false, 'error' => 'Board packet document is not available yet.' ];
        }

        $recipient_context = \metis_board_resolve_packet_recipients( $meeting );
        if ( empty( $recipient_context['ok'] ) ) {
            return [ 'ok' => false, 'error' => (string) ( $recipient_context['error'] ?? 'No packet recipients found.' ) ];
        }
        $recipients = (array) ( $recipient_context['recipients'] ?? [] );
        $audience_label = (string) ( $recipient_context['audience_label'] ?? 'recipients' );
        $packet_heading = (string) ( $recipient_context['packet_heading'] ?? 'Meeting Packet' );

        $download = \metis_board_drive_download_file_payload( (string) ( $packet_doc['google_file_id'] ?? '' ) );
        if ( empty( $download['ok'] ) ) {
            return [ 'ok' => false, 'error' => (string) ( $download['error'] ?? 'Unable to attach board packet.' ) ];
        }

        $meeting_title = trim( (string) ( $meeting['title'] ?? 'Board Meeting' ) );
        if ( $meeting_title === '' ) {
            $meeting_title = 'Board Meeting';
        }
        $meeting_date = Support::formatDatetime( (string) ( $meeting['meeting_date'] ?? '' ) );
        $meeting_code = trim( (string) ( $meeting['meeting_code'] ?? '' ) );
        $packet_notes = trim( (string) ( $meeting['board_packet_notes'] ?? '' ) );
        $meeting_location = trim( (string) ( $meeting['location'] ?? '' ) );
        $logo_url = \metis_board_portal_logo_url();

        $settings_from_name = class_exists( 'Core_Settings_Service' ) ? trim( (string) \Core_Settings_Service::get( 'newsletter_default_from_name', '' ) ) : '';
        $settings_from_email = class_exists( 'Core_Settings_Service' ) ? strtolower( trim( (string) \Core_Settings_Service::get( 'newsletter_default_from_email', '' ) ) ) : '';
        $settings_reply_to = class_exists( 'Core_Settings_Service' ) ? strtolower( trim( (string) \Core_Settings_Service::get( 'newsletter_default_reply_to', '' ) ) ) : '';
        $org_name = class_exists( 'Core_Settings_Service' ) ? trim( (string) \Core_Settings_Service::get( 'org_name', '' ) ) : '';

        $president_email = strtolower( trim( (string) ( $president['email'] ?? '' ) ) );
        $from_email = \metis_email_is_valid( $settings_from_email ) ? $settings_from_email : $president_email;
        if ( ! \metis_email_is_valid( $from_email ) ) {
            return [ 'ok' => false, 'error' => 'A default sender email or Board President email is required before sending packet emails.' ];
        }
        $reply_to = \metis_email_is_valid( $settings_reply_to ) ? $settings_reply_to : $from_email;

        $from_name = $settings_from_name;
        if ( $from_name === '' ) {
            $from_name = trim( (string) ( $president['display_name'] ?? '' ) );
        }
        if ( $from_name === '' ) {
            $from_name = trim( (string) ( $president['first_name'] ?? '' ) . ' ' . (string) ( $president['last_name'] ?? '' ) );
        }
        if ( $from_name === '' && $org_name !== '' ) {
            $from_name = $org_name;
        }
        if ( $from_name === '' ) {
            $from_name = 'Board Office';
        }
        $subject = $packet_heading . ': ' . $meeting_title . ( $meeting_date !== '' ? ( ' - ' . $meeting_date ) : '' );

        $sent = 0;
        $failed = [];
        foreach ( $recipients as $recipient ) {
            $greeting_name = trim( (string) ( $recipient['first_name'] ?? '' ) );
            if ( $greeting_name === '' ) {
                $greeting_name = trim( (string) ( $recipient['display_name'] ?? '' ) );
            }
            if ( $greeting_name === '' ) {
                $greeting_name = $audience_label === 'committee members' ? 'Committee Member' : 'Board Member';
            }
            $safe_notes = $packet_notes !== '' ? \metis_runtime_kses_post( $packet_notes ) : '<p>No additional packet notes were provided.</p>';
            $safe_notes = str_replace( [ "\xC2\xA0", '&nbsp;', '&#160;' ], ' ', $safe_notes );
            $safe_notes = preg_replace( '/Â(?=\s|<|$)/u', '', (string) $safe_notes ) ?? (string) $safe_notes;
            $safe_title = \metis_escape_html( $meeting_title );
            $safe_date = \metis_escape_html( $meeting_date );
            $safe_code = \metis_escape_html( $meeting_code );
            $safe_location = \metis_escape_html( $meeting_location );
            $safe_greeting = \metis_escape_html( $greeting_name );
            $safe_sender = \metis_escape_html( $from_name );
            $inline_images = [];
            $logo_src = trim( $logo_url );
            if ( stripos( $logo_src, 'data:' ) === 0 ) {
                $inline_logo = \metis_board_email_inline_logo_from_data_uri( $logo_src );
                if ( ! empty( $inline_logo ) ) {
                    $inline_images[] = $inline_logo;
                    $logo_src = 'cid:' . (string) ( $inline_logo['cid'] ?? 'metis-board-logo' );
                } else {
                    $logo_src = '';
                }
            }
            $logo_markup = '';
            if ( $logo_src !== '' ) {
                $logo_markup =
                    '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="border-collapse:collapse;">'
                    . '<tr><td align="center" style="text-align:center;">'
                    . '<img src="' . \metis_escape_attr( $logo_src ) . '" alt="Organization logo" width="120" style="display:block;border:0;outline:none;text-decoration:none;max-width:120px;max-height:64px;height:auto;width:auto;">'
                    . '</td></tr>'
                    . '<tr><td height="20" style="line-height:20px;font-size:20px;">&nbsp;</td></tr>'
                    . '</table>';
            }
            $location_markup = $safe_location !== '' ? '<p style="margin:0 0 16px;color:#334155;"><strong>Location:</strong> ' . $safe_location . '</p>' : '';
            $html = '<div style="font-family:Arial,sans-serif;background:#f5f7fb;padding:20px;">'
                . '<div style="max-width:700px;margin:0 auto;background:#fff;border:1px solid #dfe5f2;border-radius:12px;padding:24px;">'
                . $logo_markup
                . '<h2 style="margin:0 0 8px;color:#1f2937;">' . \metis_escape_html( $packet_heading ) . '</h2>'
                . '<p style="margin:0 0 14px;color:#334155;">' . $safe_title . '</p>'
                . '<p style="margin:0 0 16px;color:#334155;"><strong>Date:</strong> ' . $safe_date . ( $safe_code !== '' ? ( ' &nbsp;|&nbsp; <strong>Code:</strong> ' . $safe_code ) : '' ) . '</p>'
                . $location_markup
                . '<p style="margin:0 0 14px;color:#1f2937;">Hi ' . $safe_greeting . ',</p>'
                . '<div style="margin:0 0 16px;color:#1f2937;line-height:1.55;">' . $safe_notes . '</div>'
                . '<p style="margin:18px 0 0;color:#334155;">Thank you,<br><strong>' . $safe_sender . '</strong></p>'
                . '</div></div>';

            $result = \Metis\Core\Services\EmailService::sendHtml(
                (string) $recipient['email'],
                $subject,
                $html,
                [
                    'from_name' => $from_name,
                    'from_email' => $from_email,
                    'reply_to' => $reply_to,
                    'inline_images' => $inline_images,
                    'attachments' => [
                        [
                            'path' => (string) ( $download['path'] ?? '' ),
                            'name' => (string) ( $download['name'] ?? 'Board-Packet.pdf' ),
                            'mime' => (string) ( $download['mime'] ?? 'application/pdf' ),
                        ],
                    ],
                ]
            );
            if ( ! empty( $result['ok'] ) ) {
                $sent++;
            } else {
                $failed[] = [
                    'email' => (string) ( $recipient['email'] ?? '' ),
                    'error' => (string) ( $result['error'] ?? 'Email send failed.' ),
                ];
            }
        }

        $tmp = (string) ( $download['path'] ?? '' );
        if ( $tmp !== '' && file_exists( $tmp ) ) {
            @unlink( $tmp );
        }

        return [
            'ok' => $sent > 0,
            'total' => count( $recipients ),
            'sent' => $sent,
            'failed' => $failed,
            'audience_label' => $audience_label,
            'packet_heading' => $packet_heading,
        ];
    }
}
