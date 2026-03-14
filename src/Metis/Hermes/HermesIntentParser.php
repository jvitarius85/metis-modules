<?php
declare(strict_types=1);

namespace Metis\Hermes;

final class HermesIntentParser {
    public function __construct(
        private readonly HermesCommandRegistry $commands
    ) {}

    public function parse( string $query ): array {
        $normalized = strtolower( trim( $query ) );
        $action     = 'unknown';
        $domain     = 'general';
        $confidence = 0.0;
        $payload    = [];

        if ( $normalized !== '' && $this->matchesBackup( $normalized ) ) {
            $action = 'run_backup';
            $domain = 'system';
            $confidence = 0.93;
        } elseif ( $normalized !== '' && $this->matchesAnnouncement( $normalized ) ) {
            $action = 'send_announcement';
            $domain = 'communications';
            $confidence = 0.9;
            $payload = [
                'announcement' => $this->extractAnnouncementPayload( $query ),
            ];
        } elseif ( $normalized !== '' && $this->matchesPermissionDiagnostic( $normalized ) ) {
            $action = 'diagnose_permissions';
            $domain = 'security';
            $confidence = 0.91;
            $payload = [
                'diagnostic_request' => [
                    'query' => $query,
                ],
            ];
        }

        return [
            'action' => $action,
            'domain' => $domain,
            'confidence' => $confidence,
            'command' => $this->commands->definition( $action ),
            'payload' => $payload,
        ];
    }

    private function matchesBackup( string $query ): bool {
        return str_contains( $query, 'backup' )
            || str_contains( $query, 'back up' )
            || str_contains( $query, 'run a backup' );
    }

    private function matchesAnnouncement( string $query ): bool {
        if ( ! str_contains( $query, 'announcement' ) ) {
            return false;
        }

        foreach ( [ 'send', 'publish', 'queue', 'dispatch' ] as $keyword ) {
            if ( str_contains( $query, $keyword ) ) {
                return true;
            }
        }

        return false;
    }

    private function matchesPermissionDiagnostic( string $query ): bool {
        foreach ( [ "can't", 'cannot', 'permission', 'permissions', 'access denied', 'why can', 'why cant', 'why can\'t' ] as $keyword ) {
            if ( str_contains( $query, $keyword ) ) {
                return true;
            }
        }

        return false;
    }

    private function extractAnnouncementPayload( string $query ): array {
        $subject = 'Announcement';
        if ( preg_match( '/announcement[:\-\s]+(.+)/i', $query, $matches ) ) {
            $subject = trim( (string) ( $matches[1] ?? '' ) ) ?: $subject;
        }

        return [
            'subject' => $subject,
            'body' => $query,
        ];
    }
}
