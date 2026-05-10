<?php
declare(strict_types=1);

namespace Metis\Services;

use Metis\Core\Cache\CacheService;
use Metis\Core\Services\EntityResolverService;
use Metis\Modules\People\AccessManager;
use Metis\Modules\People\SchemaManager;

final class HermesDirectoryService {
    public function __construct(
        private readonly ?DatabaseService $db = null,
        private readonly ?EntityResolverService $entityResolver = null
    ) {}

    public function lookupProfile( mixed $request = null ): array {
        $request = is_array( $request ) ? $request : [];
        $subject = trim( (string) ( $request['subject'] ?? '' ) );
        $entityHint = \metis_key_clean( (string) ( $request['entity_hint'] ?? 'auto' ) );

        $resolve = function () use ( $subject, $entityHint ): array {
            if ( $subject === '' ) {
                return [
                    'status' => 'error',
                    'message' => 'A person, contact, or donor identifier is required.',
                ];
            }

            $person = null;
            $contact = null;

            if ( in_array( $entityHint, [ 'person', 'people' ], true ) ) {
                $person = $this->resolvePerson( $subject );
            } elseif ( $entityHint === 'contact' ) {
                $contact = $this->resolveContact( $subject );
                if ( ! is_array( $contact ) ) {
                    $person = $this->resolvePerson( $subject );
                    if ( is_array( $person ) ) {
                        $contact = $this->resolveLinkedContactForPerson( $person );
                    }
                }
            } elseif ( $entityHint === 'donor' ) {
                $contact = $this->resolveDonor( $subject );
            } else {
                $person = $this->resolvePerson( $subject );
                $contact = $contact ?? $this->resolveContact( $subject );
                if ( ! is_array( $contact ) ) {
                    $contact = $this->resolveDonor( $subject );
                }
            }

            if ( ! is_array( $person ) && is_array( $contact ) ) {
                $person = $this->resolveLinkedPersonForContact( $contact );
            }

            if ( ! is_array( $contact ) && is_array( $person ) ) {
                $contact = $this->resolveLinkedContactForPerson( $person );
            }

            if ( is_array( $contact ) ) {
                $contact = $this->hydrateContactDetails( $contact );
            }

            if ( ! is_array( $person ) && ! is_array( $contact ) ) {
                return [
                    'status' => 'error',
                    'message' => 'Sorry, I had trouble getting that for you.',
                    'detail' => 'No matching person, contact, or donor was found.',
                    'query' => $subject,
                ];
            }

            $did = trim( (string) ( $contact['did'] ?? $person['linked_donor_id'] ?? '' ) );
            $includeDonorMetrics = $entityHint === 'donor';
            $metrics = ( $includeDonorMetrics && $did !== '' ) ? $this->donationMetricsForDid( $did ) : [
                'this_year_total' => 0.0,
                'last_year_total' => 0.0,
                'lifetime_total' => 0.0,
                'gift_count' => 0,
                'last_gift_at' => '',
            ];

            $profile = [
                'entity_type' => $this->entityType( $person, $contact, $did ),
                'name' => $this->bestName( $person, $contact ),
                'person' => $this->personPayload( $person ),
                'contact' => $this->contactPayload( $contact ),
                'donor' => [
                    'did' => $did,
                    'show_summary' => $includeDonorMetrics,
                    'this_year_total' => (float) ( $metrics['this_year_total'] ?? 0 ),
                    'last_year_total' => (float) ( $metrics['last_year_total'] ?? 0 ),
                    'lifetime_total' => (float) ( $metrics['lifetime_total'] ?? 0 ),
                    'gift_count' => (int) ( $metrics['gift_count'] ?? 0 ),
                    'last_gift_at' => (string) ( $metrics['last_gift_at'] ?? '' ),
                ],
            ];

            return [
                'status' => 'success',
                'profile' => $profile,
                'message' => sprintf( 'Profile loaded for %s.', (string) ( $profile['name'] ?? 'record' ) ),
            ];
        };

        // Contact-focused lookups should reflect most-recent detail updates immediately.
        if ( $entityHint === 'contact' ) {
            return $resolve();
        }

        $cacheKey = 'hermes.lookup_profile.v2.' . md5( strtolower( $subject ) . '|' . $entityHint );
        return CacheService::remember( $cacheKey, 600, $resolve );
    }

    public function queryCapabilityActors( mixed $request = null ): array {
        $request = is_array( $request ) ? $request : [];
        $permissionKey = trim( (string) ( $request['permission_key'] ?? '' ) );
        $boardOnly = ! empty( $request['board_only'] );

        $cacheKey = 'hermes.capability_actors.' . md5( strtolower( $permissionKey ) . '|' . ( $boardOnly ? '1' : '0' ) );

        return CacheService::remember( $cacheKey, 600, function () use ( $permissionKey, $boardOnly ): array {
            if ( $permissionKey === '' ) {
                return [
                    'status' => 'error',
                    'message' => 'A capability permission key is required.',
                ];
            }

            if ( ! class_exists( 'Metis_Tables' ) ) {
                return [
                    'status' => 'error',
                    'message' => 'People permissions are not available.',
                ];
            }

            SchemaManager::ensureSchema();
            AccessManager::seedPermissionsAndRoles();

            $peopleTable = \Metis_Tables::get( 'people' );
            $userRolesTable = \Metis_Tables::get( 'people_user_roles' );
            $rolesTable = \Metis_Tables::get( 'people_roles' );
            $rolePermsTable = \Metis_Tables::get( 'people_role_perms' );
            $permsTable = \Metis_Tables::get( 'people_permissions' );

            $boardClause = $boardOnly ? ' AND p.is_board = 1' : '';
            $now = \function_exists( 'metis_current_time' ) ? \metis_current_time( 'mysql' ) : gmdate( 'Y-m-d H:i:s' );
            $rows = $this->database()->fetchAll(
                "SELECT
                        p.id,
                        p.pid,
                        p.display_name,
                        p.first_name,
                        p.last_name,
                        p.email,
                        p.is_board,
                        r.role_key
                     FROM {$peopleTable} p
                     INNER JOIN {$userRolesTable} ur ON ur.person_id = p.id
                     INNER JOIN {$rolesTable} r ON r.id = ur.role_id
                     INNER JOIN {$rolePermsTable} rp ON rp.role_id = ur.role_id AND rp.allow_access = 1
                     INNER JOIN {$permsTable} perms ON perms.id = rp.permission_id
                     WHERE perms.permission_key = %s
                       AND p.status = 'active'
                       {$boardClause}
                       AND (ur.start_at IS NULL OR ur.start_at <= %s)
                       AND (ur.end_at IS NULL OR ur.end_at >= %s)
                     ORDER BY p.last_name ASC, p.first_name ASC, p.display_name ASC",
                [ $permissionKey, $now, $now ]
            ) ?: [];

            $actors = [];
            foreach ( $rows as $row ) {
                $personId = (int) ( $row['id'] ?? 0 );
                if ( $personId < 1 ) {
                    continue;
                }

                if ( ! isset( $actors[ $personId ] ) ) {
                    $actors[ $personId ] = [
                        'pid' => (string) ( $row['pid'] ?? '' ),
                        'name' => $this->nameFromRow( $row ),
                        'email' => (string) ( $row['email'] ?? '' ),
                        'is_board' => ! empty( $row['is_board'] ),
                        'roles' => [],
                    ];
                }

                $roleKey = (string) ( $row['role_key'] ?? '' );
                if ( $roleKey !== '' ) {
                    $actors[ $personId ]['roles'][ $roleKey ] = true;
                }
            }

            foreach ( $actors as &$actor ) {
                $actor['roles'] = array_values( array_keys( (array) ( $actor['roles'] ?? [] ) ) );
            }

            $summary = sprintf(
                '%d %s %s.',
                count( $actors ),
                $boardOnly ? 'board members' : 'people',
                count( $actors ) === 1 ? 'match this capability' : 'match this capability'
            );

            return [
                'status' => 'success',
                'permission_key' => $permissionKey,
                'actor_count' => count( $actors ),
                'actors' => array_values( $actors ),
                'summary' => $summary,
                'message' => $summary,
            ];
        } );
    }

    public function queryGivingSummary( mixed $request = null ): array {
        $request = is_array( $request ) ? $request : [];
        $period = \metis_key_clean( (string) ( $request['period'] ?? 'this_year' ) );
        $allowed = [ 'this_year', 'last_year', 'lifetime', 'this_month' ];
        if ( ! in_array( $period, $allowed, true ) ) {
            $period = 'this_year';
        }

        $cacheKey = 'hermes.giving_summary.' . $period;
        return CacheService::remember( $cacheKey, 120, function () use ( $period ): array {
            if ( ! class_exists( 'Metis_Tables' ) ) {
                return [
                    'status' => 'error',
                    'message' => 'Donation data is not available.',
                ];
            }

            $transactionsTable = \Metis_Tables::get( 'transactions' );
            $where = "LOWER(COALESCE(status, 'completed')) = 'completed'";
            $params = [];
            $periodLabel = 'this year';

            if ( $period === 'this_year' ) {
                $year = (int) gmdate( 'Y' );
                $start = sprintf( '%04d-01-01 00:00:00', $year );
                $end = sprintf( '%04d-01-01 00:00:00', $year + 1 );
                $where .= ' AND tran_date >= %s AND tran_date < %s';
                $params = [ $start, $end ];
                $periodLabel = 'this year';
            } elseif ( $period === 'last_year' ) {
                $year = (int) gmdate( 'Y' );
                $start = sprintf( '%04d-01-01 00:00:00', $year - 1 );
                $end = sprintf( '%04d-01-01 00:00:00', $year );
                $where .= ' AND tran_date >= %s AND tran_date < %s';
                $params = [ $start, $end ];
                $periodLabel = 'last year';
            } elseif ( $period === 'this_month' ) {
                $year = (int) gmdate( 'Y' );
                $month = (int) gmdate( 'n' );
                $start = sprintf( '%04d-%02d-01 00:00:00', $year, $month );
                $nextMonth = $month === 12 ? 1 : ( $month + 1 );
                $nextYear = $month === 12 ? ( $year + 1 ) : $year;
                $end = sprintf( '%04d-%02d-01 00:00:00', $nextYear, $nextMonth );
                $where .= ' AND tran_date >= %s AND tran_date < %s';
                $params = [ $start, $end ];
                $periodLabel = 'this month';
            } else {
                $periodLabel = 'all time';
            }

            $row = $this->database()->fetchOne(
                "SELECT
                    COALESCE(SUM(amount), 0) AS total_raised,
                    COUNT(*) AS gift_count,
                    COUNT(DISTINCT did) AS donor_count,
                    MAX(tran_date) AS last_gift_at
                 FROM {$transactionsTable}
                 WHERE {$where}",
                $params
            ) ?: [];

            $total = (float) ( $row['total_raised'] ?? 0 );

            return [
                'status' => 'success',
                'giving_summary' => [
                    'period' => $period,
                    'total_raised' => $total,
                    'gift_count' => (int) ( $row['gift_count'] ?? 0 ),
                    'donor_count' => (int) ( $row['donor_count'] ?? 0 ),
                    'last_gift_at' => (string) ( $row['last_gift_at'] ?? '' ),
                ],
                'message' => sprintf( 'Total raised %s: $%s.', $periodLabel, number_format( $total, 2 ) ),
            ];
        } );
    }

    public function resolvePersonReference( string $subject ): ?array {
        return $this->resolvePerson( $subject );
    }

    private function database(): DatabaseService {
        if ( $this->db instanceof DatabaseService ) {
            return $this->db;
        }
        return function_exists( 'metis_resolve_db_service' ) ? \metis_resolve_db_service() : new DatabaseService();
    }

    private function entityResolver(): EntityResolverService {
        if ( $this->entityResolver instanceof EntityResolverService ) {
            return $this->entityResolver;
        }

        return function_exists( 'metis_entity_resolution_service' )
            ? \metis_entity_resolution_service()
            : new EntityResolverService( $this->database() );
    }

    private function resolvePerson( string $subject ): ?array {
        if ( trim( $subject ) === '' ) {
            return null;
        }

        $result = $this->entityResolver()->resolve( $subject, 'user' )->toArray();
        return $this->recordFromResult( $result );
    }

    private function resolveContact( string $subject ): ?array {
        if ( trim( $subject ) === '' ) {
            return null;
        }

        $result = $this->entityResolver()->resolve( $subject, 'contact' )->toArray();
        return $this->recordFromResult( $result );
    }

    private function resolveDonor( string $subject ): ?array {
        if ( trim( $subject ) === '' ) {
            return null;
        }

        $result = $this->entityResolver()->resolve( $subject, 'donor' )->toArray();
        return $this->recordFromResult( $result );
    }

    /**
     * Accept a direct resolve, or a single-candidate ambiguous response.
     */
    private function recordFromResult( array $result ): ?array {
        $status = (string) ( $result['status'] ?? '' );
        if ( $status === 'resolved' ) {
            $match = (array) ( $result['match'] ?? [] );
            $metadata = (array) ( $match['metadata'] ?? [] );
            $record = (array) ( $metadata['record'] ?? [] );
            return $record !== [] ? $record : null;
        }

        if ( $status === 'ambiguous' ) {
            $candidates = (array) ( $result['candidates'] ?? [] );
            if ( count( $candidates ) === 1 ) {
                $match = (array) ( $result['match'] ?? [] );
                $metadata = (array) ( $match['metadata'] ?? [] );
                $record = (array) ( $metadata['record'] ?? [] );
                return $record !== [] ? $record : null;
            }
        }

        return null;
    }

    private function resolveLinkedPersonForContact( array $contact ): ?array {
        $did = trim( (string) ( $contact['did'] ?? '' ) );
        $email = trim( (string) ( $contact['email'] ?? '' ) );

        if ( $did !== '' && class_exists( 'Metis_Tables' ) ) {
            $peopleTable = \Metis_Tables::get( 'people' );
            $row = $this->database()->fetchOne( "SELECT * FROM {$peopleTable} WHERE linked_donor_id = %s LIMIT 1", [ $did ] );
            if ( is_array( $row ) ) {
                return $row;
            }
        }

        return $email !== '' ? $this->resolvePerson( $email ) : null;
    }

    private function resolveLinkedContactForPerson( array $person ): ?array {
        $did = trim( (string) ( $person['linked_donor_id'] ?? '' ) );
        $email = trim( (string) ( $person['email'] ?? '' ) );
        $workspaceEmail = trim( (string) ( $person['workspace_email'] ?? '' ) );
        $name = trim( (string) ( $person['first_name'] ?? '' ) . ' ' . (string) ( $person['last_name'] ?? '' ) );
        $firstName = trim( (string) ( $person['first_name'] ?? '' ) );
        $lastName = trim( (string) ( $person['last_name'] ?? '' ) );
        if ( $name === '' ) {
            $name = trim( (string) ( $person['display_name'] ?? '' ) );
        }

        if ( $did !== '' ) {
            $donor = $this->resolveDonor( $did );
            if ( is_array( $donor ) ) {
                return $donor;
            }
        }

        $byPersonIdentity = $this->resolveContactByPersonIdentity( $email, $workspaceEmail, $firstName, $lastName );
        if ( is_array( $byPersonIdentity ) ) {
            return $byPersonIdentity;
        }

        if ( $email !== '' ) {
            $byEmail = $this->resolveContact( $email );
            if ( is_array( $byEmail ) ) {
                return $byEmail;
            }
        }

        if ( $workspaceEmail !== '' ) {
            $byWorkspaceEmail = $this->resolveContact( $workspaceEmail );
            if ( is_array( $byWorkspaceEmail ) ) {
                return $byWorkspaceEmail;
            }
        }

        if ( $name !== '' ) {
            return $this->resolveContact( $name );
        }

        return null;
    }

    private function resolveContactByPersonIdentity( string $email, string $workspaceEmail, string $firstName, string $lastName ): ?array {
        if ( ! class_exists( 'Metis_Tables' ) ) {
            return null;
        }

        $contactsTable = \Metis_Tables::get( 'contacts' );
        $conditions = [];
        $params = [];

        $emails = [];
        foreach ( [ $email, $workspaceEmail ] as $candidate ) {
            $candidate = strtolower( trim( $candidate ) );
            if ( $candidate !== '' && ! in_array( $candidate, $emails, true ) ) {
                $emails[] = $candidate;
            }
        }
        if ( $emails !== [] ) {
            $placeholders = implode( ', ', array_fill( 0, count( $emails ), '%s' ) );
            $conditions[] = "LOWER(COALESCE(email, '')) IN ({$placeholders})";
            $params = array_merge( $params, $emails );
        }

        $firstName = strtolower( trim( $firstName ) );
        $lastName = strtolower( trim( $lastName ) );
        if ( $firstName !== '' && $lastName !== '' ) {
            $conditions[] = "(LOWER(COALESCE(first_name, '')) = %s AND LOWER(COALESCE(last_name, '')) = %s)";
            $params[] = $firstName;
            $params[] = $lastName;
        }

        if ( $conditions === [] ) {
            return null;
        }

        $where = implode( ' OR ', $conditions );
        $row = $this->database()->fetchOne(
            "SELECT *
             FROM {$contactsTable}
             WHERE {$where}
             ORDER BY CASE WHEN LOWER(COALESCE(status, 'active')) = 'active' THEN 0 ELSE 1 END, id DESC
             LIMIT 1",
            $params
        );

        return is_array( $row ) ? $row : null;
    }

    private function donationMetricsForDid( string $did ): array {
        if ( $did === '' || ! class_exists( 'Metis_Tables' ) ) {
            return [];
        }

        return CacheService::remember( 'query.donation_metrics.' . strtolower( $did ), 300, function () use ( $did ): array {
            $transactionsTable = \Metis_Tables::get( 'transactions' );
            $year = (int) gmdate( 'Y' );
            $thisYear = sprintf( '%04d-01-01 00:00:00', $year );
            $nextYear = sprintf( '%04d-01-01 00:00:00', $year + 1 );
            $lastYear = sprintf( '%04d-01-01 00:00:00', $year - 1 );

            $row = $this->database()->fetchOne(
                "SELECT
                        COALESCE(SUM(CASE WHEN tran_date >= %s AND tran_date < %s THEN amount ELSE 0 END), 0) AS this_year_total,
                        COALESCE(SUM(CASE WHEN tran_date >= %s AND tran_date < %s THEN amount ELSE 0 END), 0) AS last_year_total,
                        COALESCE(SUM(amount), 0) AS lifetime_total,
                        COUNT(*) AS gift_count,
                        MAX(tran_date) AS last_gift_at
                     FROM {$transactionsTable}
                     WHERE did = %s
                       AND LOWER(COALESCE(status, 'completed')) = 'completed'",
                [ $thisYear, $nextYear, $lastYear, $thisYear, $did ]
            );

            return is_array( $row ) ? $row : [];
        } );
    }

    private function entityType( ?array $person, ?array $contact, string $did ): string {
        if ( is_array( $person ) ) {
            return 'person';
        }
        if ( $did !== '' ) {
            return 'donor';
        }
        return 'contact';
    }

    private function bestName( ?array $person, ?array $contact ): string {
        $name = is_array( $person ) ? $this->nameFromRow( $person ) : '';
        if ( $name !== '' ) {
            return $name;
        }

        return is_array( $contact ) ? $this->nameFromRow( $contact ) : '';
    }

    private function personPayload( ?array $person ): array {
        if ( ! is_array( $person ) ) {
            return [];
        }

        return [
            'pid' => (string) ( $person['pid'] ?? '' ),
            'email' => (string) ( $person['email'] ?? '' ),
            'workspace_email' => (string) ( $person['workspace_email'] ?? '' ),
            'status' => (string) ( $person['status'] ?? '' ),
            'lifecycle_status' => (string) ( $person['lifecycle_status'] ?? '' ),
            'department' => (string) ( $person['department'] ?? '' ),
            'manager_pid' => (string) ( $person['manager_pid'] ?? '' ),
            'linked_donor_id' => (string) ( $person['linked_donor_id'] ?? '' ),
        ];
    }

    private function contactPayload( ?array $contact ): array {
        if ( ! is_array( $contact ) ) {
            return [];
        }

        $primaryEmail = trim( (string) ( $contact['email'] ?? '' ) );
        $additionalEmails = array_values( array_filter( array_map( 'strval', (array) ( $contact['additional_emails'] ?? [] ) ) ) );
        $emails = [];
        if ( $primaryEmail !== '' ) {
            $emails[] = $primaryEmail;
        }
        foreach ( $additionalEmails as $additional ) {
            $additional = trim( $additional );
            if ( $additional !== '' && ! in_array( $additional, $emails, true ) ) {
                $emails[] = $additional;
            }
        }

        $address = trim( (string) ( $contact['address'] ?? '' ) );
        $city = trim( (string) ( $contact['city'] ?? '' ) );
        $state = trim( (string) ( $contact['state'] ?? '' ) );
        $zip = trim( (string) ( $contact['zip'] ?? '' ) );
        $addressParts = array_values( array_filter( [
            $address,
            $city,
            trim( $state . ( $zip !== '' ? ' ' . $zip : '' ) ),
        ] ) );

        return [
            'cid' => (string) ( $contact['cid'] ?? '' ),
            'did' => (string) ( $contact['did'] ?? '' ),
            'email' => $primaryEmail,
            'emails' => $emails,
            'newsletter_lists' => $this->newsletterSubscriptionsForContact( $contact ),
            'phone' => (string) ( $contact['phone'] ?? '' ),
            'address_line_1' => $address,
            'city' => $city,
            'state' => $state,
            'zip' => $zip,
            'address' => implode( ', ', $addressParts ),
        ];
    }

    private function hydrateContactDetails( array $contact ): array {
        if ( ! class_exists( 'Metis_Tables' ) ) {
            return $contact;
        }

        $detailsTable = \Metis_Tables::get( 'contact_details' );
        $contactsTable = \Metis_Tables::get( 'contacts' );
        $contactId = (int) ( $contact['id'] ?? 0 );
        $cid = trim( (string) ( $contact['cid'] ?? '' ) );
        $did = trim( (string) ( $contact['did'] ?? '' ) );

        if ( $contactId < 1 && $cid === '' ) {
            return $contact;
        }

        $detail = $this->database()->fetchOne(
            "SELECT *
             FROM {$detailsTable}
             WHERE contact_id = %d
                OR contact_cid = %s
                OR did = %s
             ORDER BY id DESC
             LIMIT 1",
            [ $contactId, $cid, $did ]
        );

        if ( ! is_array( $detail ) ) {
            return $contact;
        }

        $additional = json_decode( (string) ( $detail['additional_emails_json'] ?? '[]' ), true );
        $additional = is_array( $additional ) ? array_values( array_filter( array_map( 'strval', $additional ) ) ) : [];

        $contact['phone'] = (string) ( $detail['phone'] ?? ( $contact['phone'] ?? '' ) );
        $contact['address'] = (string) ( $detail['address'] ?? '' );
        $contact['city'] = (string) ( $detail['city'] ?? '' );
        $contact['state'] = (string) ( $detail['state'] ?? '' );
        $contact['zip'] = (string) ( $detail['zip'] ?? '' );
        $contact['additional_emails'] = $additional;

        if ( trim( (string) ( $contact['email'] ?? '' ) ) === '' && $contactId > 0 ) {
            $primary = (string) $this->database()->scalar(
                "SELECT email FROM {$contactsTable} WHERE id = %d LIMIT 1",
                [ $contactId ]
            );
            if ( trim( $primary ) !== '' ) {
                $contact['email'] = trim( $primary );
            }
        }

        return $contact;
    }

    private function nameFromRow( array $row ): string {
        $full = trim( (string) ( $row['first_name'] ?? '' ) . ' ' . (string) ( $row['last_name'] ?? '' ) );
        if ( $full !== '' ) {
            return $full;
        }

        $display = trim( (string) ( $row['display_name'] ?? '' ) );
        if ( $display !== '' ) {
            return $display;
        }

        return trim( (string) ( $row['email'] ?? '' ) );
    }

    private function newsletterSubscriptionsForContact( array $contact ): array {
        if ( ! class_exists( 'Metis_Tables' ) ) {
            return [];
        }

        $contactId = (int) ( $contact['id'] ?? 0 );
        if ( $contactId < 1 ) {
            $cid = trim( (string) ( $contact['cid'] ?? '' ) );
            if ( $cid !== '' ) {
                $contactsTable = \Metis_Tables::get( 'contacts' );
                $contactId = (int) $this->database()->scalar(
                    "SELECT id
                     FROM {$contactsTable}
                     WHERE cid = %s
                     LIMIT 1",
                    [ $cid ]
                );
            }
        }

        if ( $contactId < 1 ) {
            return [];
        }

        $cacheKey = 'query.newsletter_lists.contact.' . $contactId;
        return CacheService::remember( $cacheKey, 300, function () use ( $contactId ): array {
            $listsTable = \Metis_Tables::get( 'newsletter_lists' );
            $subsTable = \Metis_Tables::get( 'newsletter_subs' );

            $rows = $this->database()->fetchAll(
                "SELECT l.name
                 FROM {$subsTable} s
                 INNER JOIN {$listsTable} l ON l.id = s.list_id
                 WHERE s.contact_id = %d
                   AND s.status = 'subscribed'
                   AND l.is_active = 1
                 ORDER BY l.name ASC",
                [ $contactId ]
            ) ?: [];

            $lists = [];
            foreach ( $rows as $row ) {
                $name = trim( (string) ( $row['name'] ?? '' ) );
                if ( $name !== '' ) {
                    $lists[] = $name;
                }
            }

            return array_values( array_unique( $lists ) );
        } );
    }

}
