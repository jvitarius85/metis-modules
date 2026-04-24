<?php
declare(strict_types=1);

namespace Metis\Modules\People;

final class ActivityService {
    public static function logActivity( ?int $person_id, string $activity_type, string $summary, array $details = [] ): void {
        $table = \Metis_Tables::get( 'people_activity' );
        $actor_person_id = AccessManager::getCurrentPersonId();
        \metis_db()->insert(
            $table,
            [
                'person_id' => $person_id ?: null,
                'actor_person_id' => $actor_person_id > 0 ? $actor_person_id : null,
                'activity_type' => \metis_key_clean( $activity_type ),
                'summary' => \metis_text_clean( $summary ),
                'details' => ! empty( $details ) ? \metis_json_encode( $details ) : null,
                'created_at' => \metis_current_time( 'mysql' ),
            ]
        );

        \metis_audit_log_activity(
            'people_' . \metis_key_clean( $activity_type ),
            [
                'module' => 'people',
                'resource' => [
                    'type' => $person_id ? 'person' : 'people',
                    'id' => $person_id ? (string) $person_id : '',
                    'label' => $summary,
                ],
                'context' => $details,
            ]
        );

        if (
            str_contains( (string) $activity_type, 'security' ) ||
            str_contains( (string) $activity_type, 'passkey' ) ||
            str_contains( (string) $activity_type, 'totp' ) ||
            str_contains( (string) $activity_type, 'access_request' )
        ) {
            \metis_audit_log_security(
                'people_' . \metis_key_clean( $activity_type ),
                [
                    'module' => 'people',
                    'severity' => 'info',
                    'outcome' => 'recorded',
                    'resource' => [
                        'type' => $person_id ? 'person' : 'people',
                        'id' => $person_id ? (string) $person_id : '',
                        'label' => $summary,
                    ],
                    'context' => $details,
                ]
            );
        }
    }
}
