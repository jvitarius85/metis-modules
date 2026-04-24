<?php
declare(strict_types=1);

namespace Metis\Services;

use Metis\Core\Services\EmailService;

final class CommunicationsService {
    public function sendAnnouncement( mixed $announcement = null ): array {
        $announcement = is_array( $announcement ) ? $announcement : [];
        $subject      = trim( (string) ( $announcement['subject'] ?? 'Announcement' ) );
        $body         = trim( (string) ( $announcement['body'] ?? '' ) );
        $recipient    = trim( (string) ( $announcement['recipient_email'] ?? '' ) );

        if ( $recipient === '' ) {
            return [
                'status' => 'error',
                'error' => 'Announcement dispatch requires a recipient_email in the approved payload.',
            ];
        }

        $result = EmailService::sendHtml(
            $recipient,
            $subject,
            $body !== '' ? nl2br( \metis_escape_html( $body ) ) : '<p>&nbsp;</p>',
            [ 'module' => 'communications' ]
        );

        return [
            'status' => ! empty( $result['ok'] ) ? 'success' : 'error',
            'subject' => $subject,
            'recipient_email' => $recipient,
            'provider_result' => $result,
        ];
    }
}
