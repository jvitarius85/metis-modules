<?php
declare(strict_types=1);

namespace Metis\Hermes;

use Metis\Core\HelpSearchStore;
use Metis\Services\PermissionsService;

final class HelpIssueResolver {
    /** @var array<int,array<string,mixed>>|null */
    private ?array $catalog = null;

    public function __construct(
        private readonly \Metis_Help_Service $help,
        private readonly HelpSearchStore $store,
        private readonly PermissionsService $permissions,
        private readonly HermesRepository $repository,
        private readonly HermesCommandRegistry $commands
    ) {}

    public function resolve(
        string $userMessage,
        int $currentUserId = 0,
        string $currentRoute = '',
        string $currentModule = '',
        array $sessionContext = []
    ): array {
        $normalized = $this->normalize( $userMessage );
        $responseMode = $this->responseMode( $normalized );
        $match = $this->matchIssue( $normalized, $currentModule );
        $articles = $this->searchArticles( $userMessage, $match );
        $classification = (string) ( $match['classification'] ?? $this->inferClassification( $normalized ) );
        $moduleLabel = (string) ( $match['module'] ?? $this->inferModuleLabel( $currentModule ) );
        $moduleKey = (string) ( $match['module_key'] ?? $this->normalizeModuleKey( $currentModule ) );
        $action = (string) ( $match['action'] ?? $this->inferActionKey( $normalized ) );
        $confidenceScore = (float) ( $match['score'] ?? 0.0 );
        $confidenceLabel = $this->confidenceLabel( $confidenceScore );
        $permissionChecks = $this->permissionChecks( (array) ( $match['permissions'] ?? [] ), $moduleKey );
        $configChecks = $this->configChecks( $moduleKey, (array) ( $match['feature_flags'] ?? [] ), $currentRoute, $currentModule );
        $diagnostics = array_merge( $permissionChecks, $configChecks, $this->recentSignals( $currentUserId, $moduleKey, $action ) );
        $checks = $this->buildUserChecks( $responseMode, $classification, (array) ( $match['checks'] ?? [] ), $diagnostics );
        $adminEscalation = $this->buildEscalation( $responseMode, (array) ( $match['admin_escalation'] ?? [] ), $diagnostics );
        $steps = array_values( array_map( 'strval', (array) ( $match['steps'] ?? [] ) ) );
        $relatedArticles = $this->normalizeArticleMatches( $articles, (array) ( $match['related_articles'] ?? [] ) );
        $summary = (string) ( $match['summary'] ?? ( $relatedArticles[0]['summary'] ?? $this->defaultSummary( $responseMode ) ) );
        $proposedActions = $this->proposedActions( (array) ( $match['proposed_actions'] ?? [] ), $currentUserId, $userMessage, $moduleLabel, $moduleKey );
        $guidanceLinks = $this->guidanceLinks( $moduleKey, $action, $currentRoute, $currentModule );
        $sectionLabels = $this->sectionLabels( $responseMode );

        $result = [
            'success' => $match !== [] || $relatedArticles !== [],
            'response_mode' => $responseMode,
            'classification' => $classification,
            'module' => $moduleLabel !== '' ? $moduleLabel : 'Unknown',
            'action' => $action,
            'confidence' => $confidenceLabel,
            'summary' => $summary,
            'steps' => $steps,
            'checks' => $checks,
            'admin_escalation' => $adminEscalation,
            'related_articles' => $relatedArticles,
            'guidance_links' => $guidanceLinks,
            'proposed_actions' => $proposedActions,
            'diagnostics' => $diagnostics,
            'section_labels' => $sectionLabels,
            'formatted_response' => $this->formatResponse( $summary, $steps, $checks, $adminEscalation, $relatedArticles, $sectionLabels ),
            'message' => $summary,
        ];

        $this->repository->logHelpIssueResolution( [
            'session_code' => (string) ( $sessionContext['session_code'] ?? '' ),
            'user_id' => $currentUserId,
            'raw_message' => $userMessage,
            'normalized_issue' => $normalized,
            'classification' => $classification,
            'module_key' => $moduleKey,
            'module_label' => $moduleLabel,
            'action_key' => $action,
            'confidence_label' => $confidenceLabel,
            'confidence_score' => $confidenceScore,
            'help_articles' => $relatedArticles,
            'diagnostics' => $diagnostics,
            'proposed_actions' => $proposedActions,
            'executed_actions' => [],
            'result' => $result,
        ] );

        return $result;
    }

    private function normalize( string $message ): string {
        $value = strtolower( \metis_help_plain_text( $message ) );
        $value = str_replace(
            [ 'cannot', 'can not', 'wont', 'do not', 'does not', 'dont' ],
            [ "can't", "can't", "won't", "don't", "doesn't", "don't" ],
            $value
        );
        return trim( preg_replace( '/\s+/', ' ', $value ) ?? $value );
    }

    /**
     * @return array<string,mixed>
     */
    private function matchIssue( string $normalized, string $currentModule ): array {
        $best = [];
        $bestScore = 0.0;
        $variants = $this->issueVariants( $normalized );

        foreach ( $this->catalog() as $entry ) {
            $score = 0.0;
            $matchedContent = false;
            $title = strtolower( \metis_help_plain_text( (string) ( $entry['title'] ?? '' ) ) );

            $titleScore = $this->variantMatchScore( $variants, $title, 1.0, 0.7 );
            if ( $titleScore > 0.0 ) {
                $score += $titleScore;
                $matchedContent = true;
            }

            foreach ( (array) ( $entry['phrases'] ?? [] ) as $phrase ) {
                $phraseScore = $this->variantMatchScore(
                    $variants,
                    strtolower( \metis_help_plain_text( (string) $phrase ) ),
                    0.85,
                    0.7
                );
                if ( $phraseScore > 0.0 ) {
                    $score += $phraseScore;
                    $matchedContent = true;
                }
            }

            foreach ( (array) ( $entry['search_terms'] ?? [] ) as $term ) {
                $termScore = $this->variantMatchScore(
                    $variants,
                    strtolower( \metis_help_plain_text( (string) $term ) ),
                    0.45,
                    0.3
                );
                if ( $termScore > 0.0 ) {
                    $score += $termScore;
                    $matchedContent = true;
                }
            }

            $moduleKey = $this->normalizeModuleKey( (string) ( $entry['module_key'] ?? '' ) );
            if ( $matchedContent && $moduleKey !== '' && $moduleKey === $this->normalizeModuleKey( $currentModule ) ) {
                $score += 0.15;
            }

            if ( $score > $bestScore ) {
                $entry['score'] = min( 1.0, $score );
                $best = $entry;
                $bestScore = $score;
            }
        }

        return $best;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function searchArticles( string $message, array $match ): array {
        $normalized = $this->normalize( $message );
        $queries = array_values( array_unique( array_filter( array_merge(
            [ $message, $normalized ],
            $this->issueVariants( $normalized ),
            [
                (string) ( $match['title'] ?? '' ),
                trim( (string) ( $match['module'] ?? '' ) . ' ' . (string) ( $match['action'] ?? '' ) ),
            ]
        ) ) ) );

        $results = [];
        foreach ( $queries as $query ) {
            $payload = $this->store->search( $query, '', 6, 1, false );
            foreach ( (array) ( $payload['results'] ?? [] ) as $row ) {
                $slug = (string) ( $row['slug'] ?? '' );
                if ( $slug === '' || isset( $results[ $slug ] ) ) {
                    continue;
                }
                $results[ $slug ] = $row;
            }
        }

        return array_values( $results );
    }

    /**
     * @return array<int,string>
     */
    private function issueVariants( string $normalized ): array {
        $variants = [];
        $base = trim( strtolower( \metis_help_plain_text( $normalized ) ) );
        if ( $base !== '' ) {
            $variants[] = $base;
        }

        $core = preg_replace(
            '/^(?:how do i|how can i|where do i|show me how(?: to)?|walk me through|why can\'?t i|why cant i|i can\'?t|i cant|i don\'?t see|i dont see|cannot|can not|can\'t|cant)\s+/',
            '',
            $base
        );
        $core = trim( (string) $core );
        if ( $core !== '' && $core !== $base && strlen( $core ) >= 6 ) {
            $variants[] = $core;
        }

        $simplified = trim( preg_replace( '/\b(?:a|an|the)\b\s+/', ' ', $core !== '' ? $core : $base ) ?? ( $core !== '' ? $core : $base ) );
        $simplified = trim( preg_replace( '/\s+/', ' ', $simplified ) ?? $simplified );
        if ( $simplified !== '' && ! in_array( $simplified, $variants, true ) && strlen( $simplified ) >= 6 ) {
            $variants[] = $simplified;
        }

        return $variants;
    }

    private function variantMatchScore( array $inputVariants, string $candidate, float $exactScore, float $containsScore ): float {
        $candidate = trim( strtolower( \metis_help_plain_text( $candidate ) ) );
        if ( $candidate === '' ) {
            return 0.0;
        }

        $candidateVariants = $this->issueVariants( $candidate );
        foreach ( $inputVariants as $inputVariant ) {
            $inputVariant = trim( strtolower( \metis_help_plain_text( (string) $inputVariant ) ) );
            if ( $inputVariant === '' ) {
                continue;
            }

            foreach ( $candidateVariants as $candidateVariant ) {
                if ( $candidateVariant === '' ) {
                    continue;
                }
                if ( $inputVariant === $candidateVariant ) {
                    return $exactScore;
                }
                if (
                    strlen( $inputVariant ) >= 6
                    && strlen( $candidateVariant ) >= 6
                    && ( str_contains( $inputVariant, $candidateVariant ) || str_contains( $candidateVariant, $inputVariant ) )
                ) {
                    return $containsScore;
                }
            }
        }

        return 0.0;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function normalizeArticleMatches( array $articles, array $relatedTitles ): array {
        $results = [];
        foreach ( $articles as $article ) {
            $results[] = [
                'title' => (string) ( $article['title'] ?? '' ),
                'slug' => (string) ( $article['slug'] ?? '' ),
                'summary' => (string) ( $article['summary'] ?? '' ),
                'url' => (string) ( $article['url'] ?? '' ),
                'module_key' => (string) ( $article['module_key'] ?? '' ),
                'action_key' => (string) ( $article['action_key'] ?? '' ),
            ];
        }

        foreach ( $relatedTitles as $title ) {
            $title = trim( (string) $title );
            if ( $title === '' ) {
                continue;
            }
            $results[] = [
                'title' => $title,
                'slug' => '',
                'summary' => '',
                'url' => '',
                'module_key' => '',
                'action_key' => '',
            ];
        }

        $deduped = [];
        foreach ( $results as $result ) {
            $key = strtolower( trim( (string) ( $result['title'] ?? '' ) ) );
            if ( $key !== '' && ! isset( $deduped[ $key ] ) ) {
                $deduped[ $key ] = $result;
            }
        }

        return array_slice( array_values( $deduped ), 0, 6 );
    }

    /**
     * @return array<int,array<string,string>>
     */
    private function guidanceLinks( string $moduleKey, string $action, string $currentRoute, string $currentModule ): array {
        $target = $this->guidanceTarget( $moduleKey, $action, $currentRoute, $currentModule );
        if ( $target === [] ) {
            return [];
        }

        $route = trim( (string) ( $target['route'] ?? '' ) );
        if ( $route === '' ) {
            return [];
        }

        return [
            [
                'label' => (string) ( $target['label'] ?? 'Go there' ),
                'url' => $route,
                'highlight_selector' => (string) ( $target['highlight_selector'] ?? '' ),
                'highlight_label' => (string) ( $target['highlight_label'] ?? '' ),
                'topic' => (string) ( $target['topic'] ?? '' ),
                'walkthrough_id' => (string) ( $target['walkthrough_id'] ?? '' ),
            ],
        ];
    }

    /**
     * @return array<int,array<string,string>>
     */
    private function permissionChecks( array $permissionKeys, string $moduleKey ): array {
        $checks = [];
        foreach ( $permissionKeys as $permissionKey ) {
            $permissionKey = trim( (string) $permissionKey );
            if ( $permissionKey === '' ) {
                continue;
            }

            [ $module, $action ] = array_pad( explode( '.', $permissionKey, 2 ), 2, 'view' );
            $checks[] = [
                'type' => 'permission',
                'status' => $this->permissions->can( $module, $action ) ? 'ok' : 'blocked',
                'message' => sprintf( 'Permission check: %s.', $permissionKey ),
            ];
        }

        if ( $checks === [] && $moduleKey !== '' ) {
            $checks[] = [
                'type' => 'permission',
                'status' => $this->permissions->can( $moduleKey, 'view' ) ? 'ok' : 'blocked',
                'message' => sprintf( 'Module access check: %s.view.', $moduleKey ),
            ];
        }

        return $checks;
    }

    /**
     * @return array<int,array<string,string>>
     */
    private function configChecks( string $moduleKey, array $featureFlags, string $currentRoute, string $currentModule ): array {
        $checks = [];
        if ( $moduleKey !== '' ) {
            $modules = function_exists( 'metis_get_modules' ) ? (array) \metis_get_modules() : [];
            $checks[] = [
                'type' => 'module',
                'status' => isset( $modules[ $moduleKey ] ) ? 'ok' : 'unknown',
                'message' => isset( $modules[ $moduleKey ] )
                    ? sprintf( 'Module appears registered: %s.', $moduleKey )
                    : sprintf( 'Module state could not be confirmed for %s.', $moduleKey ),
            ];
        }

        foreach ( $featureFlags as $flag ) {
            $flag = trim( (string) $flag );
            if ( $flag === '' ) {
                continue;
            }

            $enabled = class_exists( 'Core_Settings_Service' )
                ? (bool) \Core_Settings_Service::get( $flag, 0 )
                : null;

            $checks[] = [
                'type' => 'configuration',
                'status' => $enabled === null ? 'unknown' : ( $enabled ? 'ok' : 'blocked' ),
                'message' => sprintf( 'Configuration flag %s is %s.', $flag, $enabled === null ? 'not available' : ( $enabled ? 'enabled' : 'disabled' ) ),
            ];
        }

        if ( $currentRoute !== '' ) {
            $checks[] = [
                'type' => 'workflow',
                'status' => 'ok',
                'message' => sprintf( 'Current route: %s.', $currentRoute ),
            ];
        }
        if ( $currentModule !== '' && $moduleKey !== '' && $this->normalizeModuleKey( $currentModule ) !== $moduleKey ) {
            $checks[] = [
                'type' => 'workflow',
                'status' => 'warning',
                'message' => sprintf( 'You may be in the wrong module now: %s.', $currentModule ),
            ];
        }

        return $checks;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function recentSignals( int $userId, string $moduleKey, string $actionKey ): array {
        $rows = $this->repository->recentCommandLogs( '', 20 );
        $signals = [];
        foreach ( $rows as $row ) {
            if ( $userId > 0 && (int) ( $row['user_id'] ?? 0 ) !== $userId ) {
                continue;
            }

            $result = (array) ( $row['result'] ?? [] );
            $status = (string) ( $result['status'] ?? '' );
            if ( ! in_array( $status, [ 'error', 'failed' ], true ) ) {
                continue;
            }

            $message = trim( (string) ( $result['message'] ?? $row['selected_intent'] ?? 'Unknown failure' ) );
            if ( $message === '' ) {
                continue;
            }
            if ( str_starts_with( strtolower( $message ), 'sorry, i had trouble getting that for you' ) ) {
                continue;
            }
            $toolKey = strtolower( (string) ( $row['tool_key'] ?? '' ) );
            $intentKey = strtolower( (string) ( $row['selected_intent'] ?? '' ) );
            if ( $moduleKey !== '' && ! str_contains( $toolKey, $moduleKey ) && ! str_contains( $intentKey, $moduleKey ) ) {
                continue;
            }
            if ( $actionKey !== '' && ! str_contains( $toolKey, $actionKey ) && ! str_contains( $intentKey, $actionKey ) ) {
                continue;
            }

            $signals[] = [
                'type' => 'recent_failure',
                'status' => 'warning',
                'message' => sprintf( 'Recent Hermes failure: %s.', $message ),
            ];

            if ( count( $signals ) >= 2 ) {
                break;
            }
        }

        return $signals;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function proposedActions( array $actions, int $currentUserId, string $userMessage, string $moduleLabel, string $moduleKey ): array {
        $results = [];
        foreach ( $actions as $action ) {
            if ( ! is_array( $action ) ) {
                continue;
            }

            $commandKey = trim( (string) ( $action['command'] ?? '' ) );
            $command = $commandKey !== '' ? $this->commands->definition( $commandKey ) : [];
            $permission = (string) ( $command['permission'] ?? '' );

            $results[] = [
                'action_summary' => (string) ( $action['summary'] ?? $action['title'] ?? 'Proposed Hermes action' ),
                'title' => (string) ( $action['title'] ?? ucwords( str_replace( '_', ' ', $commandKey ) ) ),
                'command' => $commandKey,
                'affected_module' => $moduleLabel,
                'risk_level' => (string) ( $action['risk_level'] ?? 'low' ),
                'required_permission' => $permission,
                'requires_approval' => ! empty( $action['requires_approval'] ) || ! empty( $command['requires_approval'] ),
                'enclave_payload' => [
                    'action' => 'hermes.resolve_help_issue',
                    'requested_by' => $currentUserId,
                    'issue' => $userMessage,
                    'module' => $moduleLabel,
                    'module_key' => $moduleKey,
                    'proposed_fix' => $commandKey,
                    'requires_permission' => $permission,
                    'risk_level' => (string) ( $action['risk_level'] ?? 'low' ),
                    'nonce' => '',
                ],
            ];
        }

        return $results;
    }

    /**
     * @return array<string,string>
     */
    private function guidanceTarget( string $moduleKey, string $action, string $currentRoute, string $currentModule ): array {
        $routeFor = fn ( string $module, string $view ): string => $this->portalUrl( $module, $view );

        return match ( $action ) {
            'create_gl_entry', 'save_gl_entry', 'post_gl_entry', 'balance_gl_entry', 'view_accounting_module' => [
                'label' => 'Open GL Entry',
                'route' => $routeFor( 'finance', 'gl_entry' ),
                'highlight_selector' => '[data-open-gl-modal="1"], .metis-finance-v2-app .mw-settings-card',
                'highlight_label' => 'GL entry area',
                'topic' => 'finance.gl_entry',
                'walkthrough_id' => 'finance_gl_entry',
            ],
            'accounting_period_status' => [
                'label' => 'Open Finance Settings',
                'route' => $routeFor( 'finance', 'settings' ),
                'highlight_selector' => '.metis-finance-v2-app [data-finance-fiscal-settings-form="1"], .metis-finance-v2-app .mw-settings-card',
                'highlight_label' => 'Fiscal settings',
                'topic' => 'finance.settings',
                'walkthrough_id' => '',
            ],
            'create_donation', 'edit_donation' => [
                'label' => 'Open Donations Transactions',
                'route' => $routeFor( 'donations', 'transactions' ),
                'highlight_selector' => '.mw-transactions-view, .mw-tx-table, .mw-list-content',
                'highlight_label' => 'Donation entry workflow',
                'topic' => 'donations.transactions',
                'walkthrough_id' => $action === 'edit_donation' ? 'donations_edit_donation' : 'donations_create_donation',
            ],
            'create_deposit_batch', 'balance_deposit_batch' => [
                'label' => 'Open Deposits',
                'route' => $routeFor( 'donations', 'deposits' ),
                'highlight_selector' => '[data-metis-topic="donations.deposits"], .mw-page-header, .mw-list-content',
                'highlight_label' => 'Deposit workflow',
                'topic' => 'donations.deposits',
                'walkthrough_id' => 'create_deposit',
            ],
            'send_newsletter', 'send_test_email' => [
                'label' => 'Open Newsletter Campaigns',
                'route' => $routeFor( 'newsletter', 'campaigns' ),
                'highlight_selector' => '.metis-newsletter, .mw-page-header-right, .mw-page-header',
                'highlight_label' => 'Newsletter campaign actions',
                'topic' => 'newsletter.campaigns',
                'walkthrough_id' => 'newsletter_campaign',
            ],
            'publish_page', 'edit_page' => [
                'label' => 'Open Website Pages',
                'route' => $routeFor( 'website', 'pages' ),
                'highlight_selector' => '#metis-pages-list-shell, #metis-create-page-btn, .metis-pages-table',
                'highlight_label' => 'Website pages workflow',
                'topic' => 'website.pages',
                'walkthrough_id' => 'website_publish_page',
            ],
            'create_calendar_event' => [
                'label' => 'Open Calendar',
                'route' => $routeFor( 'calendar', 'dashboard' ),
                'highlight_selector' => '#metis-calendar-new, .metis-calendar-toolbar-left, .metis-calendar',
                'highlight_label' => 'Calendar event controls',
                'topic' => 'calendar.dashboard',
                'walkthrough_id' => 'calendar_create_event',
            ],
            'create_user', 'update_user', 'disable_user', 'enable_user', 'get_user' => [
                'label' => 'Open People List',
                'route' => $routeFor( 'people', 'people_list' ),
                'highlight_selector' => '#metis-people-add-open, #metis-people-search, #metis-people-rows, .metis-people',
                'highlight_label' => 'People management tools',
                'topic' => 'people.people_list',
                'walkthrough_id' => $action === 'create_user' ? 'people_create_user' : '',
            ],
            'assign_role' => [
                'label' => 'Open People',
                'route' => $routeFor( 'people', 'dashboard' ),
                'highlight_selector' => '.metis-people-search-tile, .mw-tile[href*="/people/roles"], .metis-people-dashboard',
                'highlight_label' => 'People access tools',
                'topic' => 'people.dashboard',
                'walkthrough_id' => 'people_assign_role',
            ],
            'find_person_record' => [
                'label' => 'Open People',
                'route' => $routeFor( 'people', 'dashboard' ),
                'highlight_selector' => '#metis-people-dashboard-search, #metis-people-dashboard-results, .metis-people-search-tile',
                'highlight_label' => 'People search tools',
                'topic' => 'people.dashboard',
                'walkthrough_id' => 'people_find_person',
            ],
            'run_report', 'export_report' => [
                'label' => 'Open Reports',
                'route' => $this->reportRoute( $currentRoute, $currentModule ),
                'highlight_selector' => '[data-metis-topic$=".reports"], .mw-page-header, .mw-main',
                'highlight_label' => 'Report workflow',
                'topic' => $this->reportTopic( $currentModule ),
                'walkthrough_id' => $action === 'export_report' ? 'reports_export_report' : 'reports_run_report',
            ],
            'save_settings' => [
                'label' => 'Open Settings',
                'route' => $this->settingsRoute( $currentRoute ),
                'highlight_selector' => '[data-metis-settings-form], .mw-settings-actions .mw-btn, .mw-main',
                'highlight_label' => 'Settings form',
                'topic' => 'settings.identity',
                'walkthrough_id' => 'settings_save_settings',
            ],
            'upload_file' => [
                'label' => 'Open Drive',
                'route' => $routeFor( 'drive', 'dashboard' ),
                'highlight_selector' => '#metis-drive-upload, #metis-drive-browser, .metis-drive',
                'highlight_label' => 'Drive upload controls',
                'topic' => 'drive.dashboard',
                'walkthrough_id' => 'drive_upload_file',
            ],
            'find_file' => [
                'label' => 'Open Drive',
                'route' => $routeFor( 'drive', 'dashboard' ),
                'highlight_selector' => '#metis-drive-search, #metis-drive-path-bar, #metis-drive-rows',
                'highlight_label' => 'Drive search tools',
                'topic' => 'drive.dashboard',
                'walkthrough_id' => 'drive_find_file',
            ],
            default => [],
        };
    }

    private function portalUrl( string $module, string $view ): string {
        if ( function_exists( 'metis_portal_url' ) ) {
            return (string) \metis_portal_url( $module, $view );
        }

        return (string) \metis_home_url( '/admin/' . trim( $module, '/' ) . '/' . trim( $view, '/' ) . '/' );
    }

    private function reportRoute( string $currentRoute, string $currentModule ): string {
        $normalizedRoute = trim( $currentRoute );
        if ( $normalizedRoute !== '' && str_contains( $normalizedRoute, '/report' ) ) {
            return $normalizedRoute;
        }

        return $this->portalUrl( $currentModule === 'finance' ? 'finance' : 'donations', 'reports' );
    }

    private function reportTopic( string $currentModule ): string {
        return $currentModule === 'finance' ? 'finance.reports' : 'donations.reports';
    }

    private function settingsRoute( string $currentRoute ): string {
        $normalizedRoute = trim( $currentRoute );
        if ( $normalizedRoute !== '' && str_contains( $normalizedRoute, '/settings/' ) ) {
            return $normalizedRoute;
        }

        return $this->portalUrl( 'settings', 'identity' );
    }

    private function inferClassification( string $normalized ): string {
        return match ( true ) {
            str_contains( $normalized, 'permission denied' ), str_contains( $normalized, "don't see" ) => 'PERMISSION',
            str_contains( $normalized, 'locked' ), str_contains( $normalized, 'closed' ) => 'LOCKED_STATE',
            str_contains( $normalized, "won't send" ), str_contains( $normalized, 'will not load' ) => 'SYSTEM',
            str_contains( $normalized, "can't save" ), str_contains( $normalized, 'does not balance' ) => 'VALIDATION',
            str_contains( $normalized, 'settings' ), str_contains( $normalized, 'disabled' ) => 'CONFIGURATION',
            default => 'INSTRUCTIONAL',
        };
    }

    private function inferModuleLabel( string $currentModule ): string {
        return $currentModule !== '' ? ucwords( str_replace( [ '_', '-' ], ' ', $currentModule ) ) : '';
    }

    private function inferActionKey( string $normalized ): string {
        foreach ( $this->catalog() as $entry ) {
            foreach ( (array) ( $entry['phrases'] ?? [] ) as $phrase ) {
                if ( str_contains( $normalized, strtolower( \metis_help_plain_text( (string) $phrase ) ) ) ) {
                    return (string) ( $entry['action'] ?? '' );
                }
            }
        }

        return '';
    }

    private function normalizeModuleKey( string $module ): string {
        $module = strtolower( trim( $module ) );
        return match ( $module ) {
            'accounting' => 'finance',
            default => \metis_help_plain_key( $module ),
        };
    }

    private function responseMode( string $normalized ): string {
        return preg_match( '/\b(how do i|how can i|where do i|show me how|walk me through)\b/', $normalized ) === 1
            ? 'instructional'
            : 'troubleshooting';
    }

    private function defaultSummary( string $responseMode ): string {
        return $responseMode === 'instructional'
            ? 'I can walk you through that.'
            : 'I found a likely path to work through this issue.';
    }

    /**
     * @return array<string,string>
     */
    private function sectionLabels( string $responseMode ): array {
        if ( $responseMode === 'instructional' ) {
            return [
                'summary' => 'How to do it',
                'steps' => 'Step-by-step instructions',
                'checks' => 'Before you start',
                'admin' => 'When you may need an admin',
                'articles' => 'Related help articles',
            ];
        }

        return [
            'summary' => 'What this means',
            'steps' => 'Step-by-step fix',
            'checks' => 'Things to check',
            'admin' => 'When to contact an admin',
            'articles' => 'Related help articles',
        ];
    }

    private function confidenceLabel( float $score ): string {
        if ( $score >= 0.85 ) {
            return 'high';
        }
        if ( $score >= 0.6 ) {
            return 'medium';
        }
        if ( $score > 0.0 ) {
            return 'low';
        }

        return 'none';
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function catalog(): array {
        if ( is_array( $this->catalog ) ) {
            return $this->catalog;
        }

        $path = \METIS_PATH . 'config/hermes/help_issue_catalog.php';
        $catalog = is_file( $path ) ? require $path : [];
        $this->catalog = is_array( $catalog ) ? $catalog : [];

        return $this->catalog;
    }

    /**
     * @param array<int,string> $checks
     * @param array<int,string> $adminEscalation
     * @param array<int,array<string,mixed>> $relatedArticles
     * @param array<string,string> $sectionLabels
     */
    private function formatResponse(
        string $summary,
        array $steps,
        array $checks,
        array $adminEscalation,
        array $relatedArticles,
        array $sectionLabels
    ): string {
        $lines = [ '## ' . ( $sectionLabels['summary'] ?? 'What this means' ), $summary, '', '## ' . ( $sectionLabels['steps'] ?? 'Step-by-step fix' ) ];

        if ( $steps === [] ) {
            $lines[] = '1. Open the related module and retry the action from the standard workflow.';
        } else {
            foreach ( array_values( $steps ) as $index => $step ) {
                $lines[] = sprintf( '%d. %s', $index + 1, $step );
            }
        }

        $lines[] = '';
        $lines[] = '## ' . ( $sectionLabels['checks'] ?? 'Things to check' );
        foreach ( array_values( array_unique( array_filter( $checks ) ) ) as $check ) {
            $lines[] = '- ' . $check;
        }

        $lines[] = '';
        $lines[] = '## ' . ( $sectionLabels['admin'] ?? 'When to contact an admin' );
        foreach ( array_values( array_unique( array_filter( $adminEscalation ) ) ) as $item ) {
            $lines[] = '- ' . $item;
        }

        $lines[] = '';
        $lines[] = '## ' . ( $sectionLabels['articles'] ?? 'Related help articles' );
        foreach ( $relatedArticles as $article ) {
            $lines[] = '- ' . (string) ( $article['title'] ?? '' );
        }

        return implode( "\n", $lines );
    }

    /**
     * @param array<int,string> $baseChecks
     * @param array<int,array<string,mixed>> $diagnostics
     * @return array<int,string>
     */
    private function buildUserChecks( string $responseMode, string $classification, array $baseChecks, array $diagnostics ): array {
        $checks = array_values( array_map( 'strval', $baseChecks ) );

        foreach ( $diagnostics as $diagnostic ) {
            $type = (string) ( $diagnostic['type'] ?? '' );
            $status = (string) ( $diagnostic['status'] ?? '' );
            $message = trim( (string) ( $diagnostic['message'] ?? '' ) );
            if ( $message === '' ) {
                continue;
            }

            if ( $responseMode === 'instructional' ) {
                if ( $type === 'recent_failure' ) {
                    continue;
                }
                if ( $type === 'permission' ) {
                    continue;
                }
                if ( $type === 'workflow' && $status === 'ok' ) {
                    continue;
                }
            }

            if ( $type === 'recent_failure' && $classification !== 'SYSTEM' ) {
                continue;
            }

            $checks[] = $message;
        }

        return array_values( array_unique( array_filter( $checks ) ) );
    }

    /**
     * @param array<int,string> $baseline
     * @param array<int,array<string,mixed>> $diagnostics
     * @return array<int,string>
     */
    private function buildEscalation( string $responseMode, array $baseline, array $diagnostics ): array {
        $escalation = array_values( array_map( 'strval', $baseline ) );
        if ( $responseMode === 'instructional' ) {
            return array_values( array_unique( array_filter( $escalation ) ) );
        }

        foreach ( $diagnostics as $diagnostic ) {
            if ( (string) ( $diagnostic['status'] ?? '' ) === 'blocked' ) {
                $escalation[] = (string) ( $diagnostic['message'] ?? '' );
            }
        }

        return array_values( array_unique( array_filter( $escalation ) ) );
    }
}
