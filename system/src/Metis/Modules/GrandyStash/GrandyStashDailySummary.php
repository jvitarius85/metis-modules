<?php
declare(strict_types=1);

namespace Metis\Modules\GrandyStash;

final class GrandyStashDailySummary {

    public static function send(): array {
        $recipients = self::recipients();
        if ( empty( $recipients ) ) {
            return [ 'status' => 'skipped', 'message' => 'No recipients for daily summary.' ];
        }

        $data = self::gatherData();
        if ( $data['total_actionable'] === 0 ) {
            return [ 'status' => 'skipped', 'message' => 'Nothing to report.' ];
        }

        $subject = "Grandy's Stash Daily Summary — " . \metis_runtime_format_date( \metis_current_time( 'mysql' ) );
        $body    = self::buildBody( $data );
        $fromEmail = 'noreply@' . ( $_SERVER['SERVER_NAME'] ?? 'vitarius.org' );

        $sent = 0;
        foreach ( $recipients as $email ) {
            $result = \Metis\Core\Services\EmailService::sendHtml(
                (string) $email,
                $subject,
                $body,
                [
                    'module' => 'grandys_stash',
                    'from_name' => 'Metis',
                    'from_email' => $fromEmail,
                ]
            );
            if ( ! empty( $result['ok'] ) ) {
                $sent++;
            }
        }

        return [
            'status'     => 'sent',
            'recipients' => $sent,
            'total'      => count( $recipients ),
            'data'       => $data,
        ];
    }

    private static function recipients(): array {
        $db         = \metis_db();
        $prefs      = \Metis_Tables::get( 'grandys_stash_email_prefs' );
        $auth       = \Metis_Tables::get( 'auth_users' );

        $rows = $db->fetchAll(
            "SELECT u.user_email
             FROM {$prefs} p
             INNER JOIN {$auth} u ON u.id = p.user_id
             WHERE p.receive_grandys_summary = 1
               AND u.is_active = 1
               AND u.user_email <> ''"
        );

        $emails = [];
        foreach ( $rows as $row ) {
            $email = trim( (string) ( $row['user_email'] ?? '' ) );
            if ( $email !== '' ) {
                $emails[] = $email;
            }
        }

        return $emails;
    }

    private static function gatherData(): array {
        $db      = \metis_db();
        $tickets = \Metis_Tables::get( 'grandys_stash_tickets' );
        $items   = \Metis_Tables::get( 'grandys_stash_ticket_items' );

        $new_tickets = $db->fetchAll(
            "SELECT code, submit_name, type, urgency, submitted_at
             FROM {$tickets}
             WHERE status = 'NEW'
             ORDER BY submitted_at DESC
             LIMIT 20"
        ) ?: [];

        $waitlist_tickets = $db->fetchAll(
            "SELECT t.code, t.submit_name, t.type,
                    MIN(i.waitlist_at) AS earliest_waitlist
             FROM {$tickets} t
             INNER JOIN {$items} i ON i.ticket_id = t.id AND i.status = 'unavailable'
             WHERE t.status = 'WAITLIST'
             GROUP BY t.id
             ORDER BY earliest_waitlist ASC
             LIMIT 20"
        ) ?: [];

        $seven_days_ago = \gmdate( 'Y-m-d H:i:s', time() - 7 * 86400 );
        $aging_tickets = $db->fetchAll(
            "SELECT code, submit_name, type, status, submitted_at
             FROM {$tickets}
             WHERE status IN ('NEW', 'REVIEWING')
               AND submitted_at < %s
             ORDER BY submitted_at ASC
             LIMIT 20",
            [ $seven_days_ago ]
        ) ?: [];

        $counts = $db->fetchOne(
            "SELECT COUNT(*) AS total,
                    SUM(CASE WHEN status = 'NEW' THEN 1 ELSE 0 END) AS new_count,
                    SUM(CASE WHEN status = 'REVIEWING' THEN 1 ELSE 0 END) AS reviewing,
                    SUM(CASE WHEN status = 'WAITLIST' THEN 1 ELSE 0 END) AS waitlist,
                    SUM(CASE WHEN status = 'READY' THEN 1 ELSE 0 END) AS ready,
                    SUM(CASE WHEN status = 'COMPLETED' THEN 1 ELSE 0 END) AS completed,
                    SUM(CASE WHEN status = 'CLOSED' THEN 1 ELSE 0 END) AS closed
             FROM {$tickets}"
        ) ?: [];

        return [
            'new_tickets'      => $new_tickets,
            'waitlist_tickets'  => $waitlist_tickets,
            'aging_tickets'     => $aging_tickets,
            'counts'            => $counts,
            'total_actionable'  => count( $new_tickets ) + count( $waitlist_tickets ) + count( $aging_tickets ),
        ];
    }

    private static function buildBody( array $data ): string {
        $counts = $data['counts'] ?? [];
        $html   = '<!DOCTYPE html><html><head><meta charset="utf-8"></head><body style="font-family:-apple-system,BlinkMacSystemFont,sans-serif;color:#183043;max-width:640px;margin:0 auto;padding:20px;">';

        $html .= '<h1 style="color:#485bc7;font-size:22px;margin-bottom:4px;">Grandy\'s Stash Daily Summary</h1>';
        $html .= '<p style="color:#67798b;margin-top:0;">' . date( 'l, F j, Y' ) . '</p>';

        // Summary counts
        $html .= '<table style="width:100%;border-collapse:collapse;margin:20px 0;">';
        $html .= '<tr>';
        foreach ( ['New' => 'new_count', 'Reviewing' => 'reviewing', 'Waitlist' => 'waitlist', 'Ready' => 'ready', 'Completed' => 'completed'] as $label => $key ) {
            $val = (int) ( $counts[$key] ?? 0 );
            $html .= '<td style="text-align:center;padding:12px;border:1px solid #d7e1e8;"><div style="font-size:11px;text-transform:uppercase;color:#67798b;">' . $label . '</div><div style="font-size:24px;font-weight:700;">' . $val . '</div></td>';
        }
        $html .= '</tr></table>';

        // New tickets
        if ( ! empty( $data['new_tickets'] ) ) {
            $html .= '<h2 style="font-size:16px;color:#183043;border-bottom:2px solid #485bc7;padding-bottom:6px;">New Tickets</h2>';
            $html .= self::ticketTable( $data['new_tickets'], ['Code', 'Name', 'Type', 'Urgency', 'Submitted'] );
        }

        // Waitlist
        if ( ! empty( $data['waitlist_tickets'] ) ) {
            $html .= '<h2 style="font-size:16px;color:#b54708;border-bottom:2px solid #fedf89;padding-bottom:6px;">Waitlist (Longest Waiting First)</h2>';
            $html .= self::ticketTable( $data['waitlist_tickets'], ['Code', 'Name', 'Type', 'Waiting Since'] );
        }

        // Aging
        if ( ! empty( $data['aging_tickets'] ) ) {
            $html .= '<h2 style="font-size:16px;color:#b42318;border-bottom:2px solid #fecdca;padding-bottom:6px;">Aging Tickets (7+ Days)</h2>';
            $html .= self::ticketTable( $data['aging_tickets'], ['Code', 'Name', 'Type', 'Status', 'Submitted'] );
        }

        $html .= '<p style="color:#67798b;font-size:12px;margin-top:30px;border-top:1px solid #d7e1e8;padding-top:12px;">This is an automated summary from Metis. Manage your subscription in Grandy\'s Stash Settings.</p>';
        $html .= '</body></html>';

        return $html;
    }

    private static function ticketTable( array $rows, array $headers ): string {
        $html = '<table style="width:100%;border-collapse:collapse;margin:10px 0 20px;font-size:13px;">';
        $html .= '<tr>';
        foreach ( $headers as $h ) {
            $html .= '<th style="text-align:left;padding:8px;border-bottom:2px solid #d7e1e8;color:#67798b;font-size:11px;text-transform:uppercase;">' . htmlspecialchars( $h ) . '</th>';
        }
        $html .= '</tr>';

        foreach ( $rows as $row ) {
            $html .= '<tr>';
            $html .= '<td style="padding:8px;border-bottom:1px solid #d7e1e8;font-weight:600;">' . htmlspecialchars( (string) ( $row['code'] ?? '' ) ) . '</td>';
            $html .= '<td style="padding:8px;border-bottom:1px solid #d7e1e8;">' . htmlspecialchars( (string) ( $row['submit_name'] ?? '' ) ) . '</td>';
            $html .= '<td style="padding:8px;border-bottom:1px solid #d7e1e8;">' . ucfirst( htmlspecialchars( (string) ( $row['type'] ?? '' ) ) ) . '</td>';

            if ( isset( $row['urgency'] ) ) {
                $html .= '<td style="padding:8px;border-bottom:1px solid #d7e1e8;">' . ucfirst( htmlspecialchars( (string) $row['urgency'] ) ) . '</td>';
            }
            if ( isset( $row['status'] ) && ! isset( $row['urgency'] ) ) {
                $html .= '<td style="padding:8px;border-bottom:1px solid #d7e1e8;">' . htmlspecialchars( (string) $row['status'] ) . '</td>';
            }
            if ( isset( $row['earliest_waitlist'] ) ) {
                $html .= '<td style="padding:8px;border-bottom:1px solid #d7e1e8;">' . htmlspecialchars( \metis_runtime_format_date( (string) $row['earliest_waitlist'] ) ) . '</td>';
            }
            if ( isset( $row['submitted_at'] ) ) {
                $html .= '<td style="padding:8px;border-bottom:1px solid #d7e1e8;">' . htmlspecialchars( \metis_runtime_format_date( (string) $row['submitted_at'] ) ) . '</td>';
            }
            $html .= '</tr>';
        }

        $html .= '</table>';
        return $html;
    }
}

\class_alias( __NAMESPACE__ . '\\GrandyStashDailySummary', 'Metis\\Modules\\GrandyStashDailySummary' );
