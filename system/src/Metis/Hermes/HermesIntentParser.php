<?php
declare(strict_types=1);

namespace Metis\Hermes;

final class HermesIntentParser {

    private const DATA_INTENT_MAP = [
        'how many'   => 'count',
        'count of'   => 'count',
        'count'      => 'count',
        'total'      => 'aggregate',
        'sum of'     => 'aggregate',
        'sum'        => 'aggregate',
        'average'    => 'aggregate',
        'avg'        => 'aggregate',
        'breakdown'  => 'aggregate',
        'grouped by' => 'aggregate',
        'group by'   => 'aggregate',
        'top '       => 'top',
        'biggest'    => 'top',
        'highest'    => 'top',
        'largest'    => 'top',
        'export'     => 'export',
        'download'   => 'export',
        'csv'        => 'export',
        'list all'   => 'list',
        'list'       => 'list',
        'show me'    => 'list',
        'show all'   => 'list',
        'show'       => 'list',
        'display'    => 'list',
        'show me all'=> 'list',
        'find all'   => 'list',
        'search for' => 'search',
        'search'     => 'search',
        'find'       => 'search',
        'get all'    => 'list',
        'get'        => 'get',
    ];

    private const DATE_PRESET_MAP = [
        'today'        => 'today',
        'this week'    => 'this_week',
        'this month'   => 'this_month',
        'last month'   => 'last_month',
        'this year'    => 'this_year',
        'last year'    => 'last_year',
        'ytd'          => 'ytd',
        'year to date' => 'ytd',
    ];

    private const AGGREGATE_OP_MAP = [
        'total'    => 'sum',
        'sum'      => 'sum',
        'average'  => 'avg',
        'avg'      => 'avg',
        'count'    => 'count',
        'how many' => 'count',
        'minimum'  => 'min',
        'min'      => 'min',
        'maximum'  => 'max',
        'max'      => 'max',
    ];

    private HermesCommandRegistry $commands;
    private ?EntityRegistryBuilder $entityRegistry;
    private HermesIntentRegistry $intentRegistry;
    private HermesAttributeRegistry $attributeRegistry;

    public function __construct(
        HermesCommandRegistry  $commands,
        ?EntityRegistryBuilder $entityRegistry = null,
        ?HermesIntentRegistry $intentRegistry = null,
        ?HermesAttributeRegistry $attributeRegistry = null
    ) {
        $this->commands       = $commands;
        $this->entityRegistry = $entityRegistry;
        $this->intentRegistry = $intentRegistry ?? new HermesIntentRegistry();
        $this->attributeRegistry = $attributeRegistry ?? new HermesAttributeRegistry();
    }

    // ------------------------------------------------------------------
    // parse() — main entry point
    // ------------------------------------------------------------------

    public function parse( string $query ): array {
        $normalized = strtolower( trim( $query ) );

        if ( $normalized === '' ) {
            return $this->commandResult( 'unknown', 'general', 0.0, [] );
        }

        if ( $this->entityRegistry !== null ) {
            $dataIntent = $this->detectDataIntent( $normalized, $query );
            if ( $dataIntent !== null ) {
                return $dataIntent;
            }
        }

        $action     = 'unknown';
        $domain     = 'general';
        $confidence = 0.0;
        $payload    = [];

        if ( $this->matchesInstructionalHelp( $normalized ) ) {
            $action = 'resolve_help_issue';   $domain = 'general';       $confidence = 0.94;
            $payload = [ 'user_message' => $query, 'current_route' => '', 'current_module' => '', 'session_context' => [] ];
        } elseif ( $this->matchesBackup( $normalized ) ) {
            $action = 'run_backup';           $domain = 'system';        $confidence = 0.93;
        } elseif ( $this->matchesDriveSync( $normalized ) ) {
            $action = 'sync_drive';           $domain = 'system';        $confidence = 0.92;
        } elseif ( $this->matchesCalendarSync( $normalized ) ) {
            $action = 'sync_calendar';        $domain = 'system';        $confidence = 0.92;
        } elseif ( $this->matchesClearCache( $normalized ) ) {
            $action = 'clear_cache';          $domain = 'system';        $confidence = 0.92;
        } elseif ( $this->matchesUpdateCheck( $normalized ) ) {
            $action = 'check_system_updates'; $domain = 'system';        $confidence = 0.92;
        } elseif ( $this->matchesUpdateInstall( $normalized ) ) {
            $action = 'update_install';       $domain = 'system';        $confidence = 0.91;
        } elseif ( $this->matchesSelfHeal( $normalized ) ) {
            $action = 'aut_self_heal';        $domain = 'system';        $confidence = 0.91;
        } elseif ( $this->matchesAnnouncement( $normalized ) ) {
            $action = 'send_announcement';    $domain = 'communications'; $confidence = 0.9;
            $payload = [ 'announcement' => $this->extractAnnouncementPayload( $query ) ];
        } elseif ( $this->matchesCapabilityQuery( $normalized ) ) {
            $action = 'query_capability_actors'; $domain = 'security';   $confidence = 0.9;
            $payload = [ 'capability_request' => $this->extractCapabilityPayload( $query ) ];
        } elseif ( $this->matchesContactUpdate( $normalized ) ) {
            $action = 'update_contact';      $domain = 'contacts';      $confidence = 0.91;
            $payload = [ 'contact_update_request' => $this->extractContactUpdatePayload( $query ) ];
        } elseif ( $this->matchesPublishPost( $normalized ) ) {
            $action = 'publish_post';        $domain = 'website';       $confidence = 0.9;
            $payload = [ 'post_request' => $this->extractPostPayload( $query, false ) ];
        } elseif ( $this->matchesCreatePost( $normalized ) ) {
            $action = 'create_post';         $domain = 'website';       $confidence = 0.9;
            $payload = [ 'post_request' => $this->extractPostPayload( $query, true ) ];
        } elseif ( $this->matchesCreateUser( $normalized ) ) {
            $action = 'create_user';          $domain = 'people';        $confidence = 0.92;
            $payload = [ 'user_request' => $this->extractCreateUserPayload( $query ) ];
        } elseif ( $this->matchesOffboardUser( $normalized ) ) {
            $action = 'offboard_user';        $domain = 'people';        $confidence = 0.92;
            $payload = [ 'offboard_request' => [ 'subject' => $this->extractSubject( $query ) ] ];
        } elseif ( $this->matchesWorkspaceGroupChange( $normalized ) ) {
            $action = 'manage_workspace_groups'; $domain = 'people';     $confidence = 0.9;
            $payload = [ 'group_request' => $this->extractWorkspaceGroupPayload( $query ) ];
        } elseif ( $this->matchesMfaReset( $normalized ) ) {
            $action = 'reset_user_mfa';       $domain = 'people';        $confidence = 0.92;
            $payload = [ 'mfa_request' => $this->extractMfaPayload( $query ) ];
        } elseif ( $this->matchesWorkspacePasswordReset( $normalized ) ) {
            $action = 'reset_workspace_password'; $domain = 'people';    $confidence = 0.91;
            $payload = [ 'password_request' => $this->extractPasswordResetPayload( $query ) ];
        } elseif ( $this->matchesMetisPasswordReset( $normalized ) ) {
            $action = 'reset_metis_password'; $domain = 'people';        $confidence = 0.91;
            $payload = [ 'password_request' => $this->extractPasswordResetPayload( $query ) ];
        } elseif ( $this->matchesAmbiguousPasswordReset( $normalized ) ) {
            $action = 'clarify_password_reset'; $domain = 'people';      $confidence = 0.9;
            $payload = [ 'password_request' => $this->extractPasswordResetPayload( $query ) ];
        } elseif ( $this->matchesDriveLink( $normalized ) ) {
            $action = 'link_drive_folder';    $domain = 'drive';         $confidence = 0.89;
            $payload = [ 'drive_request' => $this->extractDriveLinkPayload( $query ) ];
        } elseif ( $this->matchesRoleChange( $normalized ) ) {
            $action = 'manage_user_roles';    $domain = 'people';        $confidence = 0.88;
            $payload = [ 'role_request' => $this->extractRolePayload( $query ) ];
        } elseif ( $this->matchesGivingSummaryQuery( $normalized ) ) {
            $action = 'query_giving_summary'; $domain = 'donations';     $confidence = 0.89;
            $payload = [ 'giving_request' => $this->extractGivingSummaryPayload( $query ) ];
        } elseif ( $this->matchesEntityAttributeQuery( $normalized, $query ) ) {
            $action = 'get_entity_attribute';  $domain = 'directory';    $confidence = 0.91;
            $payload = [ 'attribute_request' => $this->extractAttributePayload( $query ) ];
        } elseif ( $this->matchesProfileLookup( $normalized, $query ) ) {
            $action = 'lookup_profile';       $domain = 'directory';     $confidence = 0.87;
            $payload = [ 'profile_request' => $this->extractProfilePayload( $query ) ];
        } elseif ( $this->matchesPermissionDiagnostic( $normalized ) ) {
            $action = 'diagnose_permissions'; $domain = 'security';      $confidence = 0.91;
            $payload = [ 'diagnostic_request' => [ 'query' => $query ] ];
        }

        return $this->commandResult( $action, $domain, $confidence, $payload, $query );
    }

    // ------------------------------------------------------------------
    // Data intent detection
    // ------------------------------------------------------------------

    private function detectDataIntent( string $normalized, string $raw ): ?array {
        $intent     = null;
        $intentVerb = '';
        foreach ( self::DATA_INTENT_MAP as $phrase => $intentKey ) {
            if ( str_contains( $normalized, $phrase ) ) {
                $intent     = $intentKey;
                $intentVerb = $phrase;
                break;
            }
        }
        $entityKey = $this->resolveEntityFromQuery( $normalized );
        if ( $entityKey === null ) {
            return null;
        }

        if ( $intent === null ) {
            $intent = $this->inferImplicitDataIntent( $normalized, $entityKey );
            if ( $intent === null ) {
                return null;
            }
        }

        // If the query contains a proper name (two+ capitalised words) after the entity
        // keyword, it is a person-specific lookup — not a list/search. Let the command
        // path handle it via lookup_profile instead.
        if ( in_array( $intent, [ 'list', 'search', 'get' ], true )
            && preg_match( '/\b[A-Z][a-z]{1,}\s+[A-Z][a-z]{1,}/', $raw ) ) {
            return null;
        }

        $definition = $this->entityRegistry->definition( $entityKey );
        if ( $definition === null ) {
            return null;
        }

        $filters   = $this->extractDataFilters( $normalized, $definition );
        $dateRange = $this->extractDateRange( $normalized );
        $aggregate = ( $intent === 'aggregate' || $intent === 'count' )
            ? $this->extractAggregates( $normalized, $definition )
            : [];
        $groupBy   = $this->extractGroupBy( $normalized, $definition );
        $topN      = $intent === 'top' ? $this->extractTopN( $normalized ) : null;
        $fields    = $this->extractRequestedFields( $normalized, $definition );
        $confidence = $this->scoreDataConfidence( $entityKey, $intent, $filters, $aggregate, $dateRange );

        $interpretation = [
            'type'              => 'data',
            'intent'            => $intent,
            'top_level_intent'  => in_array( $intent, [ 'count', 'aggregate', 'top', 'export' ], true ) ? 'REPORT' : 'LOOKUP',
            'action'            => $intent,
            'domain'            => (string) ( $definition['module'] ?? 'data' ),
            'entity'            => $entityKey,
            'confidence'        => $confidence,
            'command'           => null,
            'payload'           => [],
            'filters'           => $filters,
            'fields_requested'  => $fields,
            'aggregate'         => $aggregate,
            'group_by'          => $groupBy,
            'date_range'        => $dateRange,
            'sort'              => null,
            'sort_dir'          => 'desc',
            'limit'             => 50,
            'offset'            => 0,
        ];

        if ( $topN !== null ) {
            $interpretation['top_n'] = $topN;
            $interpretation['limit'] = $topN;
        }

        $implicitLimit = $this->implicitLatestRecordLimit( $normalized, $intent );
        if ( $implicitLimit !== null ) {
            $interpretation['limit'] = $implicitLimit;
        }

        if ( $confidence < 0.5 ) {
            $interpretation['needs_clarification'] = true;
            $interpretation['clarification_hint']  = sprintf(
                'Did you want to %s %s? You can add filters like a status, date range, or specific field.',
                $intent, $entityKey
            );
        }

        return $interpretation;
    }

    private function inferImplicitDataIntent( string $normalized, string $entityKey ): ?string {
        if ( preg_match( '/\b(current|latest|last)\b/', $normalized ) !== 1 ) {
            return null;
        }

        if ( str_contains( $normalized, $entityKey ) || str_contains( $normalized, str_replace( '_', ' ', $entityKey ) ) ) {
            return 'list';
        }

        return 'list';
    }

    private function resolveEntityFromQuery( string $normalized ): ?string {
        $bestKey    = null;
        $bestLength = 0;

        foreach ( $this->entityRegistry->registry() as $entityKey => $definition ) {
            $candidates = array_merge(
                [ $entityKey, (string) ( $definition['label'] ?? '' ), (string) ( $definition['plural'] ?? '' ) ],
                (array) ( $definition['aliases'] ?? [] )
            );
            foreach ( $candidates as $alias ) {
                $alias = strtolower( trim( (string) $alias ) );
                if ( $alias !== '' && str_contains( $normalized, $alias ) && strlen( $alias ) > $bestLength ) {
                    $bestKey    = $entityKey;
                    $bestLength = strlen( $alias );
                }
            }
        }

        return $bestKey;
    }

    // ------------------------------------------------------------------
    // Data extraction helpers
    // ------------------------------------------------------------------

    private function extractDataFilters( string $normalized, array $definition ): array {
        $filters    = [];
        $filterable = array_keys( array_filter(
            (array) ( $definition['fields'] ?? [] ),
            static function ( array $f ): bool { return ! empty( $f['filterable'] ); }
        ) );

        $statusMap = [
            'active'    => 'active',    'inactive'  => 'inactive',
            'pending'   => 'pending',   'completed' => 'completed',
            'cancelled' => 'cancelled', 'failed'    => 'failed',
            'open'      => 'open',      'closed'    => 'closed',
        ];
        if ( in_array( 'status', $filterable, true ) ) {
            foreach ( $statusMap as $word => $value ) {
                if ( str_contains( $normalized, $word ) ) {
                    $filters['status'] = [ 'field' => 'status', 'op' => '=', 'value' => $value, 'type' => 'string' ];
                    break;
                }
            }
        }

        if ( in_array( 'is_board', $filterable, true ) && str_contains( $normalized, 'board' ) ) {
            $filters['is_board'] = [ 'field' => 'is_board', 'op' => '=', 'value' => 1, 'type' => 'integer' ];
        }

        return array_values( $filters );
    }

    private function extractDateRange( string $normalized ): array {
        foreach ( self::DATE_PRESET_MAP as $phrase => $preset ) {
            if ( str_contains( $normalized, $phrase ) ) {
                return [ 'preset' => $preset ];
            }
        }

        $dates = [];
        preg_match_all( '/\b(\d{4}-\d{2}-\d{2})\b/', $normalized, $matches );
        if ( ! empty( $matches[1] ) ) {
            if ( count( $matches[1] ) >= 2 ) {
                return [ 'from' => $matches[1][0], 'to' => $matches[1][1] ];
            }
            $singleDate = $matches[1][0];
            if ( str_contains( $normalized, 'after' ) || str_contains( $normalized, 'since' ) ) {
                return [ 'from' => $singleDate ];
            }
            if ( str_contains( $normalized, 'before' ) || str_contains( $normalized, 'until' ) ) {
                return [ 'to' => $singleDate ];
            }
            $dates = [ 'from' => $singleDate ];
        }

        return $dates;
    }

    private function extractAggregates( string $normalized, array $definition ): array {
        $aggregates   = [];
        $aggregatable = array_keys( array_filter(
            (array) ( $definition['fields'] ?? [] ),
            static function ( array $f ): bool { return ! empty( $f['aggregatable'] ); }
        ) );

        foreach ( self::AGGREGATE_OP_MAP as $phrase => $operation ) {
            if ( ! str_contains( $normalized, $phrase ) ) {
                continue;
            }
            $field = '';
            foreach ( $aggregatable as $candidate ) {
                $label = strtolower( (string) ( $definition['fields'][ $candidate ]['label'] ?? $candidate ) );
                if ( str_contains( $normalized, $candidate ) || str_contains( $normalized, $label ) ) {
                    $field = $candidate;
                    break;
                }
            }
            if ( $field === '' && $aggregatable !== [] && $operation !== 'count' ) {
                $field = $aggregatable[0];
            }
            $alias        = $operation === 'count' ? 'total' : $operation . '_' . $field;
            $aggregates[] = [ 'field' => $field, 'operation' => $operation, 'alias' => $alias ];
            break;
        }

        if ( $aggregates === [] ) {
            $aggregates[] = [ 'field' => '', 'operation' => 'count', 'alias' => 'total' ];
        }

        return $aggregates;
    }

    private function extractGroupBy( string $normalized, array $definition ): array {
        $groupable = array_keys( array_filter(
            (array) ( $definition['fields'] ?? [] ),
            static function ( array $f ): bool { return ! empty( $f['groupable'] ); }
        ) );
        $groupBy = [];
        foreach ( $groupable as $field ) {
            $label = strtolower( (string) ( $definition['fields'][ $field ]['label'] ?? $field ) );
            if ( str_contains( $normalized, 'by ' . $field ) || str_contains( $normalized, 'by ' . $label ) ) {
                $groupBy[] = $field;
            }
        }
        return $groupBy;
    }

    private function extractTopN( string $normalized ): int {
        if ( preg_match( '/top\s+(\d+)/', $normalized, $matches ) ) {
            return min( 100, max( 1, (int) $matches[1] ) );
        }
        $wordNumbers = [ 'five' => 5, 'ten' => 10, 'twenty' => 20, 'fifty' => 50 ];
        foreach ( $wordNumbers as $word => $n ) {
            if ( str_contains( $normalized, 'top ' . $word ) ) {
                return $n;
            }
        }
        return 10;
    }

    private function extractRequestedFields( string $normalized, array $definition ): array {
        $allFields = array_keys( (array) ( $definition['fields'] ?? [] ) );
        $requested = [];
        foreach ( $allFields as $field ) {
            $label = strtolower( (string) ( $definition['fields'][ $field ]['label'] ?? $field ) );
            if ( str_contains( $normalized, $field ) || str_contains( $normalized, $label ) ) {
                $requested[] = $field;
            }
        }
        return $requested;
    }

    private function scoreDataConfidence( string $entity, string $intent, array $filters, array $aggregate, array $dateRange ): float {
        $score = 0.55;
        $score += $filters   !== [] ? 0.10 : 0.0;
        $score += $dateRange !== [] ? 0.10 : 0.0;
        $score += $aggregate !== [] ? 0.10 : 0.0;
        $score += $entity    !== '' ? 0.05 : 0.0;
        $score += in_array( $intent, [ 'count', 'aggregate', 'top' ], true ) ? 0.05 : 0.0;
        return min( 1.0, round( $score, 2 ) );
    }

    private function implicitLatestRecordLimit( string $normalized, string $intent ): ?int {
        if ( ! in_array( $intent, [ 'list', 'get', 'search' ], true ) ) {
            return null;
        }

        if ( preg_match( '/\blast\s+(\d+)\b/', $normalized, $matches ) === 1 ) {
            return min( 100, max( 1, (int) $matches[1] ) );
        }

        $wordNumbers = [ 'one' => 1, 'two' => 2, 'three' => 3, 'four' => 4, 'five' => 5, 'ten' => 10 ];
        foreach ( $wordNumbers as $word => $value ) {
            if ( str_contains( $normalized, 'last ' . $word ) ) {
                return $value;
            }
        }

        return preg_match( '/\b(current|latest|last)\b/', $normalized ) === 1 ? 1 : null;
    }

    // ------------------------------------------------------------------
    // Command path helpers
    // ------------------------------------------------------------------

    private function commandResult( string $action, string $domain, float $confidence, array $payload, string $query = '' ): array {
        return [
            'type'       => 'command',
            'action'     => $action,
            'intent'     => $action,
            'top_level_intent' => $this->intentRegistry->classifyCommand( $action, $query ),
            'domain'     => $domain,
            'confidence' => $confidence,
            'command'    => $this->commands->definition( $action ),
            'payload'    => $payload,
        ];
    }

    private function matchesBackup( string $query ): bool {
        return str_contains( $query, 'backup' )
            || str_contains( $query, 'back up' )
            || str_contains( $query, 'run a backup' );
    }

    private function matchesDriveSync( string $query ): bool {
        $hasDrive = str_contains( $query, 'drive' );
        $hasSync = str_contains( $query, 'sync' );
        return ( str_contains( $query, 'sync drive' ) || str_contains( $query, 'drive sync' ) || ( $hasDrive && $hasSync ) )
            && ! str_contains( $query, 'calendar' );
    }

    private function matchesCalendarSync( string $query ): bool {
        $hasCalendar = str_contains( $query, 'calendar' );
        $hasSync = str_contains( $query, 'sync' );
        return str_contains( $query, 'sync calendar' ) || str_contains( $query, 'calendar sync' ) || ( $hasCalendar && $hasSync );
    }

    private function matchesClearCache( string $query ): bool {
        return str_contains( $query, 'clear cache' ) || str_contains( $query, 'flush cache' );
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

    private function matchesUpdateCheck( string $query ): bool {
        return $query === 'aut-update-check'
            || ( str_contains( $query, 'update' ) && ( str_contains( $query, 'check' ) || str_contains( $query, 'latest version' ) ) );
    }

    private function matchesUpdateInstall( string $query ): bool {
        return $query === 'aut-update-install'
            || ( str_contains( $query, 'update' ) && ( str_contains( $query, 'install' ) || str_contains( $query, 'apply' ) ) );
    }

    private function matchesSelfHeal( string $query ): bool {
        return $query === 'aut-self-heal'
            || str_contains( $query, 'self heal' )
            || str_contains( $query, 'self-heal' )
            || ( str_contains( $query, 'repair' ) && str_contains( $query, 'system' ) );
    }

    private function matchesPermissionDiagnostic( string $query ): bool {
        foreach ( [ "can't", 'cannot', 'permission', 'permissions', 'access denied', 'why can', 'why cant', "why can't" ] as $keyword ) {
            if ( str_contains( $query, $keyword ) ) {
                return true;
            }
        }
        return false;
    }

    private function matchesInstructionalHelp( string $query ): bool {
        foreach ( [ 'how do i', 'how can i', 'where do i', 'show me how', 'walk me through' ] as $phrase ) {
            if ( str_contains( $query, $phrase ) ) {
                return true;
            }
        }

        return false;
    }

    private function matchesCreateUser( string $query ): bool {
        foreach ( [ 'who can', 'who has', 'list who', 'list all', 'which users', 'which board members' ] as $lead ) {
            if ( str_contains( $query, $lead ) ) {
                return false;
            }
        }
        foreach ( [ 'create user', 'new user', 'add user', 'onboard user', 'provision user' ] as $phrase ) {
            if ( str_contains( $query, $phrase ) ) {
                return true;
            }
        }
        return false;
    }

    private function matchesContactUpdate( string $query ): bool {
        foreach ( [ 'create user', 'new user', 'offboard', 'reset password', 'workspace group', 'role' ] as $blocked ) {
            if ( str_contains( $query, $blocked ) ) {
                return false;
            }
        }

        $hasListPhrase = str_contains( $query, 'newsletter list' ) || str_contains( $query, ' to list ' ) || str_contains( $query, ' from list ' ) || str_contains( $query, ' to the list ' ) || str_contains( $query, ' from the list ' );
        $hasFieldPhrase = false;
        foreach ( [ 'phone', 'phone number', 'address', 'city', 'state', 'zip', 'postal', 'preferred name', 'contact method', 'birthday' ] as $fieldPhrase ) {
            if ( str_contains( $query, $fieldPhrase ) ) {
                $hasFieldPhrase = true;
                break;
            }
        }

        if ( ! $hasListPhrase && ! $hasFieldPhrase ) {
            return false;
        }

        foreach ( [ 'set ', 'update ', 'change ', 'add ', 'remove ' ] as $verb ) {
            if ( str_contains( $query, $verb ) ) {
                return true;
            }
        }

        return false;
    }

    private function matchesOffboardUser( string $query ): bool {
        foreach ( [ 'offboard', 'deactivate user', 'remove user', 'disable user', 'terminate access' ] as $phrase ) {
            if ( str_contains( $query, $phrase ) ) {
                return true;
            }
        }
        return false;
    }

    private function matchesCreatePost( string $query ): bool {
        foreach ( [ 'create user', 'new user', 'reset password', 'workspace' ] as $blocked ) {
            if ( str_contains( $query, $blocked ) ) {
                return false;
            }
        }

        foreach ( [ 'create post', 'new post', 'draft post', 'write post' ] as $phrase ) {
            if ( str_contains( $query, $phrase ) ) {
                return true;
            }
        }

        return str_contains( $query, 'create ' ) && str_contains( $query, ' post' );
    }

    private function matchesPublishPost( string $query ): bool {
        foreach ( [ 'publish post', 'post live', 'go live' ] as $phrase ) {
            if ( str_contains( $query, $phrase ) ) {
                return true;
            }
        }

        return ( str_contains( $query, 'publish' ) || str_contains( $query, 'go live' ) )
            && str_contains( $query, 'post' );
    }

    private function matchesWorkspaceGroupChange( string $query ): bool {
        return str_contains( $query, 'group' )
            && ( str_contains( $query, 'add ' ) || str_contains( $query, 'remove ' )
                || str_contains( $query, 'change ' ) || str_contains( $query, 'update ' ) );
    }

    private function matchesWorkspacePasswordReset( string $query ): bool {
        if ( ! str_contains( $query, 'password' ) ) {
            return false;
        }

        $hasActionVerb = str_contains( $query, 'reset' ) || str_contains( $query, 'change' ) || str_contains( $query, 'set' );
        $isWorkspaceScoped = str_contains( $query, 'workspace' ) || str_contains( $query, 'google' );

        return $hasActionVerb && $isWorkspaceScoped;
    }

    private function matchesMetisPasswordReset( string $query ): bool {
        if ( ! str_contains( $query, 'password' ) ) {
            return false;
        }

        $hasActionVerb = str_contains( $query, 'reset' ) || str_contains( $query, 'change' ) || str_contains( $query, 'set' );
        $isMetisScoped = str_contains( $query, 'metis' ) || str_contains( $query, 'local' ) || str_contains( $query, 'internal' );

        return $hasActionVerb && $isMetisScoped;
    }

    private function matchesAmbiguousPasswordReset( string $query ): bool {
        if ( ! str_contains( $query, 'password' ) ) {
            return false;
        }

        $hasActionVerb = str_contains( $query, 'reset' ) || str_contains( $query, 'change' ) || str_contains( $query, 'set' );
        if ( ! $hasActionVerb ) {
            return false;
        }

        $isScoped = str_contains( $query, 'workspace' )
            || str_contains( $query, 'google' )
            || str_contains( $query, 'metis' )
            || str_contains( $query, 'local' )
            || str_contains( $query, 'internal' );

        return ! $isScoped;
    }

    private function matchesMfaReset( string $query ): bool {
        $hasMfaTerm = str_contains( $query, 'mfa' )
            || str_contains( $query, '2fa' )
            || str_contains( $query, 'authenticator' )
            || str_contains( $query, 'passkey' )
            || str_contains( $query, 'passkeys' )
            || str_contains( $query, 'security key' );
        $hasActionVerb = str_contains( $query, 'reset' )
            || str_contains( $query, 'clear' )
            || str_contains( $query, 'revoke' )
            || str_contains( $query, 'disable' )
            || str_contains( $query, 'turn off' );

        return $hasMfaTerm && $hasActionVerb;
    }

    private function matchesGivingSummaryQuery( string $query ): bool {
        if ( ! ( str_contains( $query, 'raised' ) || str_contains( $query, 'raise' ) ) ) {
            return false;
        }

        if ( ! str_contains( $query, 'how much' ) && ! str_contains( $query, 'total' ) ) {
            return false;
        }

        return str_contains( $query, 'we ' ) || str_contains( $query, 'organization' ) || str_contains( $query, 'this year' ) || str_contains( $query, 'year' );
    }

    private function matchesDriveLink( string $query ): bool {
        return str_contains( $query, 'drive' )
            && ( str_contains( $query, 'link' ) || str_contains( $query, 'attach' )
                || str_contains( $query, 'create folder' ) || str_contains( $query, 'folder' ) );
    }

    private function matchesRoleChange( string $query ): bool {
        if ( str_contains( $query, 'permission' ) || str_contains( $query, 'permissions' ) ) {
            return false;
        }
        foreach ( [ 'role', 'roles', 'board member', 'administrator', 'admin', 'newsletter admin', 'donor admin' ] as $phrase ) {
            if ( str_contains( $query, $phrase ) ) {
                foreach ( [ 'add', 'remove', 'change', 'update', 'make ', 'set ' ] as $verb ) {
                    if ( str_contains( $query, $verb ) ) {
                        return true;
                    }
                }
            }
        }
        return false;
    }

    private function matchesCapabilityQuery( string $query ): bool {
        foreach ( [ 'who can', 'who has', 'list who', 'list all', 'which users', 'which board members' ] as $lead ) {
            if ( str_contains( $query, $lead ) ) {
                return true;
            }
        }
        return false;
    }

    private function matchesEntityAttributeQuery( string $normalized, string $raw = '' ): bool {
        // Patterns like "what is JD's email", "show donor phone", "what's JD's role"
        if (
            str_contains( $normalized, 'how much' )
            || str_contains( $normalized, 'donated' )
            || str_contains( $normalized, 'donation' )
            || str_starts_with( $normalized, 'show me' )
            || str_contains( $normalized, ' and ' )
        ) {
            return false;
        }

        $attributeKeywords = [
            'email', 'phone', 'address', 'role', 'status', 'groups', 'permissions',
            'phone number', 'email address', 'user role', 'last login', 'number', 'birthday', 'volunteer',
        ];
        $hasAttribute = false;
        foreach ( $attributeKeywords as $keyword ) {
            if ( str_contains( $normalized, $keyword ) ) {
                $hasAttribute = true;
                break;
            }
        }
        if ( ! $hasAttribute ) {
            return false;
        }
        if ( $this->isOwnershipIdentifierQuery( $normalized ) ) {
            return true;
        }
        // Must also reference a specific entity (possessive 's, or name/email/id)
        if ( str_contains( $normalized, "'s " ) || str_contains( $normalized, "' " ) ) {
            return true;
        }
        // "what is X email" with a proper name following
        if ( preg_match( '/\b[A-Z][a-z]+\b/', $raw ) ) {
            return true;
        }
        return false;
    }

    private function extractAttributePayload( string $query ): array {
        $normalized = strtolower( $query );
        $subject    = $this->extractSubject( $query );

        // Detect attribute from keyword
        $attribute = $this->attributeRegistry->detectFromQuery( $normalized ) ?? '';

        if ( $attribute === 'name' && $this->isOwnershipIdentifierQuery( $normalized ) ) {
            $identifierSubject = $this->extractOwnershipIdentifier( $query );
            if ( $identifierSubject !== '' ) {
                $subject = $identifierSubject;
            }
        }

        $entityHint = 'auto';
        if ( str_contains( $normalized, 'donor' ) ) {
            $entityHint = 'donor';
        } elseif ( str_contains( $normalized, 'contact' ) ) {
            $entityHint = 'contact';
        }

        return [
            'subject'     => $subject,
            'attribute'   => $attribute,
            'entity_hint' => $entityHint,
        ];
    }

    private function isOwnershipIdentifierQuery( string $normalized ): bool {
        return str_contains( $normalized, 'whose ' )
            || str_contains( $normalized, 'who\'s ' )
            || str_contains( $normalized, 'who is this ' );
    }

    private function extractOwnershipIdentifier( string $query ): string {
        $email = $this->extractEmail( $query );
        if ( $email !== '' ) {
            return $email;
        }

        if ( preg_match( '/(\+?\d[\d\-\(\)\.\s]{6,}\d)/', $query, $matches ) ) {
            return trim( (string) ( $matches[1] ?? '' ) );
        }

        $parts = explode( '?', $query, 2 );
        if ( count( $parts ) === 2 ) {
            $tail = trim( (string) ( $parts[1] ?? '' ) );
            if ( $tail !== '' ) {
                return $tail;
            }
        }

        if ( preg_match( '/\bis this\s+(.+)$/i', $query, $matches ) ) {
            return trim( (string) ( $matches[1] ?? '' ) );
        }

        return '';
    }

    private function matchesProfileLookup( string $normalized, string $raw = '' ): bool {
        foreach ( [ 'email', 'phone', 'donated', 'donation', 'contact info', 'profile', 'who is', 'show me', 'lookup', 'newsletter', 'registered for', 'subscribed' ] as $keyword ) {
            if ( str_contains( $normalized, $keyword ) ) {
                return true;
            }
        }
        // Two capitalised words in the raw query = person-specific lookup
        if ( $raw !== '' && preg_match( '/\b[A-Z][a-z]+\s+[A-Z][a-z]+/', $raw ) ) {
            return true;
        }
        return false;
    }

    // ------------------------------------------------------------------
    // Payload extractors (command path)
    // ------------------------------------------------------------------

    private function extractAnnouncementPayload( string $query ): array {
        $subject = 'Announcement';
        if ( preg_match( '/announcement[:\-\s]+(.+)/i', $query, $matches ) ) {
            $subject = trim( (string) ( $matches[1] ?? '' ) ) ?: $subject;
        }
        return [ 'subject' => $subject, 'body' => $query ];
    }

    private function extractCreateUserPayload( string $query ): array {
        $email = $this->extractEmail( $query );
        $name  = $this->extractNameNearEmail( $query, $email );
        list( $firstName, $lastName ) = $this->splitName( $name );
        return [
            'email'             => $email,
            'first_name'        => $firstName,
            'last_name'         => $lastName,
            'display_name'      => $name,
            'workspace_enabled' => str_contains( strtolower( $query ), 'workspace' ) || str_contains( strtolower( $query ), 'google' ),
            'workspace_email'   => $email,
            'roles'             => $this->extractRoles( $query ),
            'workspace_groups'  => $this->extractEmailsAfterKeyword( $query, [ 'group', 'groups' ] ),
            'password'          => $this->extractQuotedValueAfterKeyword( $query, [ 'password', 'with password', 'set password to' ] ),
        ];
    }

    private function extractWorkspaceGroupPayload( string $query ): array {
        return [
            'subject'      => $this->extractSubject( $query ),
            'mode'         => str_contains( strtolower( $query ), 'remove' ) ? 'remove' : 'add',
            'group_emails' => $this->extractEmailsAfterKeyword( $query, [ 'group', 'groups', 'to', 'from' ] ),
        ];
    }

    private function extractPasswordResetPayload( string $query ): array {
        $subject = $this->extractSubject( $query );
        $explicit = '';

        if ( preg_match( '/\b(?:metis|workspace|google|local|internal)\s+password\s+for\s+(.+)$/i', $query, $matches ) ) {
            $explicit = trim( (string) ( $matches[1] ?? '' ) );
        } elseif ( preg_match( '/\bpassword\s+for\s+(.+)$/i', $query, $matches ) ) {
            $explicit = trim( (string) ( $matches[1] ?? '' ) );
        } elseif ( preg_match( '/\b(?:reset|change|set)\s+(.+?)\s+(?:metis|workspace|google|local|internal)?\s*password\b/i', $query, $matches ) ) {
            $explicit = trim( (string) ( $matches[1] ?? '' ) );
        }

        if ( $explicit !== '' ) {
            $explicit = preg_replace( '/\s+(?:to|as)\s+.+$/i', '', $explicit ) ?? $explicit;
            $explicit = preg_replace( '/\'s$/i', '', $explicit ) ?? $explicit;
            $explicit = trim( $explicit );
            if ( $explicit !== '' ) {
                $subject = $this->normalizeExtractedName( $explicit );
            }
        }

        return [
            'subject'      => $subject,
            'new_password' => $this->extractQuotedValueAfterKeyword( $query, [ 'password', 'to', 'as' ] ),
        ];
    }

    private function extractMfaPayload( string $query ): array {
        $subject = $this->extractSubject( $query );
        $explicit = '';

        if ( preg_match( '/\b(?:mfa|2fa|authenticator|passkeys?|security key)\s+for\s+(.+)$/i', $query, $matches ) ) {
            $explicit = trim( (string) ( $matches[1] ?? '' ) );
        } elseif ( preg_match( '/\b(?:reset|clear|revoke|disable|turn off)\s+(.+?)\s+(?:mfa|2fa|authenticator|passkeys?|security key)\b/i', $query, $matches ) ) {
            $explicit = trim( (string) ( $matches[1] ?? '' ) );
        }

        if ( $explicit !== '' ) {
            $explicit = preg_replace( '/\'s$/i', '', $explicit ) ?? $explicit;
            $explicit = trim( $explicit );
            if ( $explicit !== '' ) {
                $subject = $this->normalizeExtractedName( $explicit );
            }
        }

        return [ 'subject' => $subject ];
    }

    private function extractDriveLinkPayload( string $query ): array {
        $folderId = '';
        if ( preg_match( '/folder(?:\s+id)?[:=\s]+([a-z0-9_\-]{10,})/i', $query, $matches ) ) {
            $folderId = trim( (string) ( $matches[1] ?? '' ) );
        }
        return [ 'subject' => $this->extractSubject( $query ), 'folder_id' => $folderId ];
    }

    private function extractPostPayload( string $query, bool $create ): array {
        $subject = '';

        if ( preg_match( '/["\']([^"\']+)["\']/', $query, $matches ) ) {
            $subject = trim( (string) ( $matches[1] ?? '' ) );
        }

        if ( $subject === '' && preg_match( '/\b(?:titled|called|named)\s+(.+)$/i', $query, $matches ) ) {
            $subject = trim( (string) ( $matches[1] ?? '' ) );
        }

        if ( $subject === '' && preg_match( '/\b(?:create|write|draft|publish)\s+(?:a|an|the)?\s*post(?:\s+for)?\s+(.+)$/i', $query, $matches ) ) {
            $subject = trim( (string) ( $matches[1] ?? '' ) );
        }

        $subject = preg_replace( '/\s+(please|now|today)$/i', '', $subject ) ?? $subject;

        $slug = '';
        if ( preg_match( '/\bslug\s*(?:to|as|=)?\s*["\']?([a-z0-9\-]+)["\']?/i', $query, $matches ) ) {
            $slug = strtolower( trim( (string) ( $matches[1] ?? '' ) ) );
        }

        if ( $create ) {
            return [
                'title' => $subject,
                'slug' => $slug,
                'status' => 'draft',
            ];
        }

        return [ 'subject' => $subject ];
    }

    private function extractRolePayload( string $query ): array {
        $normalized = strtolower( $query );
        $mode = 'replace';
        if ( str_contains( $normalized, 'add ' ) || str_contains( $normalized, 'make ' ) ) {
            $mode = 'add';
        } elseif ( str_contains( $normalized, 'remove ' ) ) {
            $mode = 'remove';
        }
        return [ 'subject' => $this->extractSubject( $query ), 'mode' => $mode, 'roles' => $this->extractRoles( $query ) ];
    }

    private function extractCapabilityPayload( string $query ): array {
        return [
            'permission_key' => $this->mapPermissionKeyFromQuery( $query ),
            'board_only'     => str_contains( strtolower( $query ), 'board member' ) || str_contains( strtolower( $query ), 'board members' ),
        ];
    }

    private function extractContactUpdatePayload( string $query ): array {
        $normalized = strtolower( $query );
        $operation = 'set_field';
        if ( ( str_contains( $normalized, 'add ' ) || str_contains( $normalized, 'subscribe ' ) ) && str_contains( $normalized, 'list' ) ) {
            $operation = 'add_to_list';
        } elseif ( ( str_contains( $normalized, 'remove ' ) || str_contains( $normalized, 'unsubscribe ' ) ) && str_contains( $normalized, 'list' ) ) {
            $operation = 'remove_from_list';
        }

        $field = '';
        foreach ( [
            'phone number' => 'phone',
            'phone' => 'phone',
            'mobile' => 'phone',
            'address' => 'address_full',
            'city' => 'city',
            'state' => 'state',
            'zip' => 'zip',
            'postal code' => 'zip',
            'birthday' => 'birthday',
            'preferred name' => 'preferred_name',
            'contact method' => 'preferred_contact_method',
        ] as $phrase => $mapped ) {
            if ( str_contains( $normalized, $phrase ) ) {
                $field = $mapped;
                break;
            }
        }

        $subject = $this->extractSubject( $query );
        $subject = preg_replace(
            '/\s+(?:phone|phone number|mobile|address|city|state|zip|postal code|birthday|preferred name|contact method)$/i',
            '',
            $subject
        ) ?? $subject;
        $subject = preg_replace( '/\'s$/i', '', $subject ) ?? $subject;

        return [
            'subject'   => trim( $subject ),
            'operation' => $operation,
            'field'     => $field,
            'value'     => $operation === 'set_field' ? $this->extractContactUpdateValue( $query, $field ) : '',
            'list_name' => $operation === 'set_field' ? '' : $this->extractListName( $query ),
        ];
    }

    private function extractProfilePayload( string $query ): array {
        $entityHint = 'auto';
        $normalized = strtolower( $query );
        if ( str_contains( $normalized, 'donor' ) || str_contains( $normalized, 'donated' ) || str_contains( $normalized, 'donation' ) ) {
            $entityHint = 'donor';
        } elseif ( str_contains( $normalized, 'contact' ) || str_contains( $normalized, 'newsletter' ) || str_contains( $normalized, 'subscribed' ) || str_contains( $normalized, 'registered for' ) ) {
            $entityHint = 'contact';
        } elseif ( str_contains( $normalized, 'person' ) || str_contains( $normalized, 'people' ) || str_contains( $normalized, 'user' ) ) {
            $entityHint = 'person';
        }
        return [ 'subject' => $this->extractSubject( $query ), 'entity_hint' => $entityHint ];
    }

    private function extractGivingSummaryPayload( string $query ): array {
        $normalized = strtolower( $query );
        $period = 'this_year';
        if ( str_contains( $normalized, 'last year' ) ) {
            $period = 'last_year';
        } elseif ( str_contains( $normalized, 'lifetime' ) || str_contains( $normalized, 'all time' ) ) {
            $period = 'lifetime';
        } elseif ( str_contains( $normalized, 'this month' ) ) {
            $period = 'this_month';
        }

        return [ 'period' => $period ];
    }

    // ------------------------------------------------------------------
    // String extraction utilities
    // ------------------------------------------------------------------

    private function extractSubject( string $query ): string {
        $email = $this->extractEmail( $query );
        if ( $email !== '' ) {
            return $email;
        }
        $patterns = [
            '/how much\s+(?:has|did|as)\s+([a-z][a-z\.\'\-]+(?:\s+[a-z][a-z\.\'\-]+){1,3})\s+donated\b/i',
            '/what\s+newsletters?\s+(?:is|are)\s+([a-z][a-z\.\'\-]+(?:\s+[a-z][a-z\.\'\-]+){0,3})\s+(?:registered|subscribed)\b/i',
            '/what(?:\s+is|\'s)\s+([a-z][a-z\.\'\-]+(?:\s+[a-z][a-z\.\'\-]+){0,3})\'?s?\s+(?:email|phone|phone number|address|contact|profile|role|status|permissions|groups)\b/i',
            '/what\s+([a-z][a-z\.\'\-]+(?:\s+[a-z][a-z\.\'\-]+){0,3})\s+(?:email|phone|phone number|address|contact|profile|role|status|permissions|groups)\b/i',
            '/is\s+([a-z][a-z\.\'\-]+(?:\s+[a-z][a-z\.\'\-]+){0,3})\s+a\s+volunteer\b/i',
            '/show me\s+([A-Z][A-Za-z\.\'\-]+(?:\s+[A-Z][A-Za-z\.\'\-]+){1,3}?)(?=\s+(?:email|phone|contact|profile|and|who|what|how)\b|$)/i',
            '/who is\s+([A-Z][A-Za-z\.\'\-]+(?:\s+[A-Z][A-Za-z\.\'\-]+){1,3})/i',
            '/(?:for|from|of|user|person|contact|donor)\s+([A-Z][A-Za-z\.\'\-]+(?:\s+[A-Z][A-Za-z\.\'\-]+){0,3})/',
            '/(?:offboard|remove|disable|deactivate|lookup|show|reset|change|set|update|link|attach|add|make|clear|revoke)\s+([A-Z][A-Za-z\.\'\-]+(?:\s+[A-Z][A-Za-z\.\'\-]+){0,3})/i',
            '/([A-Z][A-Za-z\.\'\-]+(?:\s+[A-Z][A-Za-z\.\'\-]+){1,3})/',
        ];
        foreach ( $patterns as $pattern ) {
            if ( preg_match( $pattern, $query, $matches ) ) {
                $subject = trim( (string) ( $matches[1] ?? '' ) );
                if ( $subject !== '' ) {
                    $subject = preg_replace( '/\s+(to|from|with|and)$/i', '', $subject ) ?? $subject;
                    $subject = preg_replace( '/^(?:is|are|was|were)\s+/i', '', $subject ) ?? $subject;
                    $subject = preg_replace( '/\'s$/i', '', $subject ) ?? $subject;
                    return $this->normalizeExtractedName( $subject );
                }
            }
        }
        return trim( $query );
    }

    private function extractEmail( string $query ): string {
        if ( preg_match( '/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $query, $matches ) ) {
            return strtolower( trim( (string) ( $matches[0] ?? '' ) ) );
        }
        return '';
    }

    private function extractEmailsAfterKeyword( string $query, array $keywords ): array {
        preg_match_all( '/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $query, $matches );
        $emails = array_values( array_unique( array_map( 'strtolower', (array) ( $matches[0] ?? [] ) ) ) );
        if ( count( $emails ) <= 1 ) {
            return $emails;
        }
        $normalized = strtolower( $query );
        foreach ( $keywords as $keyword ) {
            $position = strpos( $normalized, strtolower( (string) $keyword ) );
            if ( $position === false ) {
                continue;
            }
            $tail = substr( $query, $position );
            preg_match_all( '/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $tail, $tailMatches );
            $tailEmails = array_values( array_unique( array_map( 'strtolower', (array) ( $tailMatches[0] ?? [] ) ) ) );
            if ( $tailEmails !== [] ) {
                return $tailEmails;
            }
        }
        return $emails;
    }

    private function extractQuotedValueAfterKeyword( string $query, array $keywords ): string {
        foreach ( $keywords as $keyword ) {
            $pattern = '/(?:' . preg_quote( (string) $keyword, '/' ) . ')\s*(?::|=|to|as)?\s*[\'"]([^\'"]+)[\'"]/i';
            if ( preg_match( $pattern, $query, $matches ) ) {
                return trim( (string) ( $matches[1] ?? '' ) );
            }
        }
        return '';
    }

    private function extractNameNearEmail( string $query, string $email ): string {
        if ( $email !== '' ) {
            $withoutEmail = trim( str_replace( $email, '', $query ) );
            if ( preg_match( '/([A-Z][A-Za-z\.\'\-]+(?:\s+[A-Z][A-Za-z\.\'\-]+){1,3})/', $withoutEmail, $matches ) ) {
                return trim( (string) ( $matches[1] ?? '' ) );
            }
        }
        return $this->extractSubject( $query );
    }

    private function extractContactUpdateValue( string $query, string $field ): string {
        if ( $field === '' ) {
            return '';
        }

        $phrases = match ( $field ) {
            'phone' => [ 'phone number', 'phone', 'mobile' ],
            'address_full' => [ 'address' ],
            'city' => [ 'city' ],
            'state' => [ 'state' ],
            'zip' => [ 'zip', 'postal code' ],
            'preferred_name' => [ 'preferred name' ],
            'preferred_contact_method' => [ 'contact method' ],
            default => [ $field ],
        };

        foreach ( $phrases as $phrase ) {
            $pattern = '/\b' . preg_quote( $phrase, '/' ) . '\b\s*(?:to|as|is|=)\s*(.+)$/i';
            if ( preg_match( $pattern, $query, $matches ) ) {
                return trim( (string) ( $matches[1] ?? '' ), " \t\n\r\0\x0B\"'" );
            }
        }

        if ( preg_match( '/\b(?:to|as)\s+(.+)$/i', $query, $matches ) ) {
            return trim( (string) ( $matches[1] ?? '' ), " \t\n\r\0\x0B\"'" );
        }

        return '';
    }

    private function extractListName( string $query ): string {
        if ( preg_match( '/\b(?:newsletter\s+)?list\s+[\'"]([^\'"]+)[\'"]/i', $query, $matches ) ) {
            return trim( (string) ( $matches[1] ?? '' ) );
        }

        if ( preg_match( '/\b(?:to|from)\s+(?:the\s+)?(?:newsletter\s+)?list\s+([a-z0-9][a-z0-9\s\-_]+)/i', $query, $matches ) ) {
            return trim( preg_replace( '/\s+(for|on|with)\b.*$/i', '', (string) ( $matches[1] ?? '' ) ) ?? '' );
        }

        if ( preg_match( '/\b(?:newsletter\s+)?list\s+([a-z0-9][a-z0-9\s\-_]+)/i', $query, $matches ) ) {
            return trim( preg_replace( '/\s+(for|on|with)\b.*$/i', '', (string) ( $matches[1] ?? '' ) ) ?? '' );
        }

        return '';
    }

    private function splitName( string $name ): array {
        $name  = trim( $name );
        if ( $name === '' ) {
            return [ '', '' ];
        }
        $parts = preg_split( '/\s+/', $name ) ?: [];
        $first = (string) array_shift( $parts );
        return [ $first, trim( implode( ' ', $parts ) ) ];
    }

    private function normalizeExtractedName( string $name ): string {
        $name = trim( preg_replace( '/\s+/', ' ', $name ) ?? $name );
        if ( $name === '' ) {
            return '';
        }
        $parts = preg_split( '/\s+/', $name ) ?: [];
        return implode( ' ', array_map( static function ( string $part ): string {
            $lower = strtolower( $part );
            if ( preg_match( '/^[A-Z]{2,3}$/', $part ) ) {
                return $part;
            }
            if ( preg_match( '/^[a-z]\.$/i', $part ) ) {
                return strtoupper( $part );
            }
            return ucfirst( $lower );
        }, $parts ) );
    }

    private function extractRoles( string $query ): array {
        $normalized = strtolower( $query );
        $roleMap = [
            'administrator'    => [ 'administrator', 'admin' ],
            'board'            => [ 'board member', 'board' ],
            'donor_admin'      => [ 'donor admin' ],
            'donor_user'       => [ 'donor user' ],
            'newsletter_admin' => [ 'newsletter admin' ],
        ];
        $roles = [];
        foreach ( $roleMap as $roleKey => $phrases ) {
            foreach ( $phrases as $phrase ) {
                if ( str_contains( $normalized, $phrase ) ) {
                    $roles[] = $roleKey;
                    break;
                }
            }
        }
        return array_values( array_unique( $roles ) );
    }

    private function mapPermissionKeyFromQuery( string $query ): string {
        $normalized = strtolower( $query );
        $map = [
            'people.create'           => [ 'create users', 'create user', 'add user', 'new user' ],
            'people.edit'             => [ 'edit people', 'manage users', 'edit users' ],
            'people.workspace_manage' => [ 'workspace groups', 'workspace access', 'reset passwords', 'change passwords', 'manage workspace' ],
            'board.create'            => [ 'create boards', 'create board records' ],
            'newsletter.create'       => [ 'send announcements', 'create newsletters', 'newsletter create' ],
            'donations.view'          => [ 'view donations', 'donations access' ],
        ];
        foreach ( $map as $permissionKey => $phrases ) {
            foreach ( $phrases as $phrase ) {
                if ( str_contains( $normalized, $phrase ) ) {
                    return $permissionKey;
                }
            }
        }
        if ( preg_match( '/([a-z_]+)\.(view|edit|create|delete|workspace_manage)/', $normalized, $matches ) ) {
            return (string) ( $matches[0] ?? '' );
        }
        return 'people.create';
    }
}
