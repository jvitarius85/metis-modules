<?php
declare(strict_types=1);

namespace Metis\Hermes;

final class HermesOperationalEngine {

    /** Data-oriented intents routed to HermesReportingService. */
    private const DATA_INTENTS = [ 'list', 'search', 'get', 'count', 'aggregate', 'top', 'export' ];

    private HermesIntentRouter        $router;
    private HermesContextPackLoader   $contextLoader;
    private HermesCommandRegistry     $commands;
    private HermesPermissionValidator $permissions;
    private HermesExecutionEngine     $execution;
    private HermesResponseRenderer    $responses;
    private ?EntityResolver           $entityResolver;
    private ?AttributeResolver        $attributeResolver;
    private ?HermesDebugLogger        $debugLogger;

    public function __construct(
        HermesIntentRouter        $router,
        HermesContextPackLoader   $contextLoader,
        HermesCommandRegistry     $commands,
        HermesPermissionValidator $permissions,
        HermesExecutionEngine     $execution,
        HermesResponseRenderer    $responses,
        ?EntityResolver           $entityResolver    = null,
        ?AttributeResolver        $attributeResolver = null,
        ?HermesDebugLogger        $debugLogger       = null
    ) {
        $this->router            = $router;
        $this->contextLoader     = $contextLoader;
        $this->commands          = $commands;
        $this->permissions       = $permissions;
        $this->execution         = $execution;
        $this->responses         = $responses;
        $this->entityResolver    = $entityResolver;
        $this->attributeResolver = $attributeResolver;
        $this->debugLogger       = $debugLogger;
    }

    public function process( string $query, array $runtimeContext = [] ): array {
        $route = $this->router->route( $query, $runtimeContext );
        $parsed = (array) ( $route['parsed'] ?? [] );
        $intent = (array) ( $route['intent'] ?? [] );
        $command = (array) ( $route['command'] ?? [] );
        $routeType = (string) ( $route['route_type'] ?? 'knowledge' );

        if ( $this->debugLogger ) {
            $this->debugLogger->query( $query, (string) ( $intent['action'] ?? $intent['intent'] ?? 'unknown' ) );
        }

        if ( ! empty( $parsed['requires_clarification'] ) ) {
            return [
                'intent'        => $intent,
                'command'       => null,
                'context_packs' => [],
                'action_plan'   => [],
                'permission'    => [ 'status' => 'clarification_required', 'required_permission' => '', 'reason' => '' ],
                'response'      => [
                    'status' => 'clarification_required',
                    'message' => (string) ( $parsed['clarification_prompt'] ?? 'Please clarify the request.' ),
                    'response_type' => 'ClarificationPrompt',
                ],
                'parsed' => $parsed,
            ];
        }

        // Route entity-attribute queries directly
        if ( $routeType === 'entity_attribute' ) {
            return $this->processEntityAttributeIntent( $query, $intent );
        }

        if ( $routeType === 'data' || $this->isDataIntent( $intent ) ) {
            return $this->processDataIntent( $intent );
        }

        if ( $command === [] ) {
            return [
                'intent'        => $intent,
                'command'       => null,
                'context_packs' => [],
                'action_plan'   => [],
                'permission'    => [ 'status' => 'not_applicable', 'required_permission' => '', 'reason' => '' ],
                'response'      => $this->responses->error( $intent, 'Request could not be mapped to a registered Hermes operation.' ),
            ];
        }

        $intent['payload'] = (array) ( $parsed['intents'][0]['payload'] ?? $intent['payload'] ?? [] );
        $prep = $this->prepareIntentPayload( $intent, $command );
        $intent = (array) ( $prep['intent'] ?? $intent );
        $parsed = $this->syncExecutionPlanPayloads( $parsed, $intent );
        $payloadError = (string) ( $prep['error'] ?? '' );
        if ( $payloadError !== '' ) {
            return [
                'intent'        => $intent,
                'command'       => $command,
                'context_packs' => [],
                'action_plan'   => [],
                'permission'    => [ 'status' => 'not_applicable', 'required_permission' => (string) ( $command['permission'] ?? '' ), 'reason' => '' ],
                'response'      => $this->responses->error( $intent, $payloadError ),
            ];
        }

        if ( array_key_exists( 'supported', $command ) && empty( $command['supported'] ) ) {
            $unsupportedMessage = trim( (string) ( $command['unsupported_message'] ?? '' ) );
            if ( $unsupportedMessage === '' ) {
                $unsupportedMessage = 'This Hermes operation does not have an executable backend yet.';
            }

            return [
                'intent'        => $intent,
                'command'       => $command,
                'context_packs' => [],
                'action_plan'   => [],
                'permission'    => [ 'status' => 'not_applicable', 'required_permission' => (string) ( $command['permission'] ?? '' ), 'reason' => '' ],
                'response'      => $this->responses->unsupported( $intent, $command, $unsupportedMessage ),
                'parsed'        => $parsed,
            ];
        }

        $contextPacks = $this->contextLoader->loadForCommand( $command );
        $executionPlan = $this->resolvedExecutionPlan( $parsed, $command );
        $permission   = $this->validateExecutionPlanPermissions( $executionPlan );
        $executionPlan = array_values( array_filter(
            (array) ( $permission['steps'] ?? $executionPlan ),
            static fn ( mixed $step ): bool => is_array( $step )
        ) );
        $plan         = $this->buildPlan( $command, $contextPacks, $executionPlan );

        if ( (string) ( $permission['status'] ?? '' ) !== 'granted' ) {
            $response = $this->responses->denied( $intent, $plan, $contextPacks, (string) ( $permission['reason'] ?? 'Permission denied.' ) );
        } elseif ( $this->executionPlanRequiresApproval( $executionPlan ) ) {
            $response = $this->responses->awaitingApproval( $intent, $plan, $contextPacks );
        } else {
            $result = count( $executionPlan ) > 1
                ? $this->executeExecutionPlan( $executionPlan )
                : $this->execution->execute( $command, (array) ( $intent['payload'] ?? [] ) );
            $response = $this->responses->executionResult( $command, $contextPacks, $plan, $result );
        }

        return [
            'intent'        => $intent,
            'command'       => $command,
            'context_packs' => $contextPacks,
            'action_plan'   => $plan,
            'permission'    => $permission,
            'response'      => $response,
            'parsed'        => $parsed,
        ];
    }

    /**
     * Normalize and complete command payload before approval/execution handoff.
     *
     * @return array{intent: array<string,mixed>, error: string}
     */
    private function prepareIntentPayload( array $intent, array $command ): array {
        $operation = (string) ( $command['key'] ?? '' );
        $payload = (array) ( $intent['payload'] ?? [] );

        if ( ! in_array( $operation, [ 'create_user', 'workspace_user_create' ], true ) ) {
            return [ 'intent' => $intent, 'error' => '' ];
        }

        $request = (array) ( $payload['user_request'] ?? [] );
        $email = strtolower( trim( (string) ( $request['email'] ?? '' ) ) );
        $displayName = trim( (string) ( $request['display_name'] ?? '' ) );

        if ( $email === '' && $displayName !== '' && $this->entityResolver !== null ) {
            foreach ( [ 'person', 'contact', 'donor' ] as $type ) {
                $resolved = $this->entityResolver->resolve( $displayName, $type );
                if ( empty( $resolved['ok'] ) ) {
                    continue;
                }

                $record = (array) ( $resolved['record'] ?? [] );
                $candidateEmail = strtolower( trim( (string) ( $record['email'] ?? '' ) ) );
                if ( $candidateEmail !== '' && function_exists( 'metis_email_is_valid' ) && \metis_email_is_valid( $candidateEmail ) ) {
                    $email = $candidateEmail;
                    break;
                }
            }
        }

        if ( $email !== '' ) {
            $request['email'] = $email;
            if ( trim( (string) ( $request['workspace_email'] ?? '' ) ) === '' ) {
                $request['workspace_email'] = $email;
            }
        }

        if ( $operation === 'workspace_user_create' ) {
            $request['workspace_enabled'] = true;
        }

        $payload['user_request'] = $request;
        $intent['payload'] = $payload;

        if ( $email === '' ) {
            return [
                'intent' => $intent,
                'error' => 'Create User requires a valid email. Try: "create a new user for Riley Vitarius with email riley@example.com".',
            ];
        }

        return [ 'intent' => $intent, 'error' => '' ];
    }

    public function validatePreparedAction( array $payload, array $actor = [] ): array {
        $executionPlan = array_values( array_filter(
            (array) ( $payload['execution_plan'] ?? [] ),
            static fn ( mixed $step ): bool => is_array( $step )
        ) );
        $operation = \metis_key_clean( (string) ( $payload['operation'] ?? ( $executionPlan[0]['intent'] ?? '' ) ) );
        $command   = $this->commands->definition( $operation );

        if ( ! is_array( $command ) ) {
            throw new \RuntimeException( 'Hermes command is not registered.' );
        }

        $executionPlan = $this->resolvedExecutionPlan( [ 'execution_plan' => $executionPlan ], $command );
        $contextPacks = $this->contextLoader->loadForCommand( $command );
        $permission   = $this->validateExecutionPlanPermissions( $executionPlan, $actor );
        $executionPlan = array_values( array_filter(
            (array) ( $permission['steps'] ?? $executionPlan ),
            static fn ( mixed $step ): bool => is_array( $step )
        ) );

        return [
            'command'          => $command,
            'context_packs'    => $contextPacks,
            'permission'       => $permission,
            'action_plan'      => (array) ( $payload['action_plan'] ?? [] ),
            'command_payload'  => (array) ( $payload['command_payload'] ?? [] ),
            'execution_plan'   => $executionPlan,
        ];
    }

    public function executePreparedAction( array $payload, array $actor = [] ): array {
        $prepared   = $this->validatePreparedAction( $payload, $actor );
        $permission = (array) ( $prepared['permission'] ?? [] );

        if ( (string) ( $permission['status'] ?? '' ) !== 'granted' ) {
            throw new \RuntimeException( (string) ( $permission['reason'] ?? 'Permission denied.' ) );
        }

        $command      = (array) ( $prepared['command'] ?? [] );
        $plan         = (array) ( $prepared['action_plan'] ?? [] );
        $contextPacks = (array) ( $prepared['context_packs'] ?? [] );
        $executionPlan = (array) ( $prepared['execution_plan'] ?? [] );
        $executionOptions = [
            // Approved actions execute from the Hermes execute-action flow,
            // even when individual steps are read-only.
            'force_enclave_operation' => 'hermes.tool.execute',
        ];
        if ( count( $executionPlan ) > 1 ) {
            $result = $this->executeExecutionPlan( $executionPlan, $executionOptions );
        } else {
            $result = $this->execution->execute( $command, (array) ( $prepared['command_payload'] ?? [] ), $executionOptions );
        }

        return $this->responses->executionResult( $command, $contextPacks, $plan, $result );
    }

    public function processEntityAttributeRequest( array $request, string $query = '' ): array {
        return $this->processEntityAttributeIntent( $query, [
            'action' => 'get_entity_attribute',
            'top_level_intent' => 'LOOKUP',
            'payload' => [
                'attribute_request' => $request,
            ],
        ] );
    }

    // ------------------------------------------------------------------
    // Entity attribute intent handler
    // ------------------------------------------------------------------

    private function processEntityAttributeIntent( string $query, array $intent ): array {
        $request     = (array) ( $intent['payload']['attribute_request'] ?? [] );
        $subject     = trim( (string) ( $request['subject']     ?? '' ) );
        $attribute   = trim( (string) ( $request['attribute']   ?? '' ) );
        $entity_hint = trim( (string) ( $request['entity_hint'] ?? 'auto' ) );

        // Detect attribute from query if parser didn't extract it
        if ( $attribute === '' && $this->attributeResolver !== null ) {
            $attribute = $this->attributeResolver->detectFromQuery( strtolower( $query ) ) ?? '';
        }

        if ( $this->debugLogger ) {
            $this->debugLogger->query( $query, 'get_entity_attribute', [
                'entity'    => $entity_hint,
                'attribute' => $attribute,
                'parameters' => [ 'subject' => $subject ],
            ] );
        }

        // Permission check — attribute reads require people.view
        $viewCommand = [ 'permission' => 'people.view', 'read_only' => true ];
        $permission  = $this->permissions->validate( $viewCommand );
        if ( (string) ( $permission['status'] ?? '' ) !== 'granted' ) {
            if ( $this->debugLogger ) {
                $this->debugLogger->permission( 'get_entity_attribute', 'denied', (string) ( $permission['reason'] ?? '' ) );
            }
            return [
                'intent'      => $intent,
                'command'     => null,
                'context_packs' => [],
                'action_plan' => [],
                'permission'  => $permission,
                'response'    => [
                    'status'      => 'error',
                    'message'     => (string) ( $permission['reason'] ?? 'Permission denied.' ),
                    'response_type' => 'PermissionDenied',
                ],
            ];
        }

        if ( $this->debugLogger ) {
            $this->debugLogger->permission( 'get_entity_attribute', 'allowed' );
        }

        // Resolve entity
        if ( $this->entityResolver === null ) {
            return $this->entityAttributeError( $intent, 'Entity resolution service is unavailable.' );
        }
        if ( $subject === '' ) {
            return $this->entityAttributeError( $intent, 'No entity subject found in query.' );
        }

        $resolved = $this->entityResolver->resolve( $subject, $entity_hint );

        if ( ! empty( $resolved['multiple'] ) ) {
            if ( $this->debugLogger ) {
                $this->debugLogger->entityNotFound( $query, $subject, (string) ( $resolved['error'] ?? '' ) );
            }
            $candidates = array_map( static function ( array $c ): string {
                return sprintf( '%s %s (%s)', $c['name'], $c['entity_type'] ? '[' . $c['entity_type'] . ']' : '', $c['email'] );
            }, (array) ( $resolved['candidates'] ?? [] ) );
            return [
                'intent'      => $intent,
                'command'     => null,
                'context_packs' => [],
                'action_plan' => [],
                'permission'  => $permission,
                'response'    => [
                    'status'        => 'disambiguation_required',
                    'message'       => $this->disambiguationPrompt( (array) ( $resolved['candidates'] ?? [] ) ),
                    'response_type' => 'Disambiguation',
                    'candidates'    => $resolved['candidates'] ?? [],
                ],
            ];
        }

        if ( empty( $resolved['ok'] ) ) {
            if ( $this->debugLogger ) {
                $this->debugLogger->entityNotFound( $query, $subject, (string) ( $resolved['error'] ?? '' ) );
            }
            return $this->entityAttributeError( $intent, 'Entity not found.' );
        }

        $entity_type = (string) ( $resolved['entity_type'] ?? '' );
        $entity_id   = (int)   ( $resolved['id']          ?? 0  );
        $record      = (array) ( $resolved['record']       ?? [] );

        if ( $this->debugLogger ) {
            $this->debugLogger->entityResolved( $query, $entity_type, $entity_id, $subject );
        }

        // Resolve attribute if still unknown
        if ( $attribute === '' ) {
            return $this->entityAttributeError( $intent, 'Could not determine which attribute was requested.' );
        }

        // Extract attribute value
        if ( $this->attributeResolver === null ) {
            return $this->entityAttributeError( $intent, 'Attribute resolver is unavailable.' );
        }

        $attrResult = $this->attributeResolver->extract( $record, $attribute, $entity_type );

        if ( empty( $attrResult['ok'] ) ) {
            $fallback = $this->fallbackAttributeLookup( $subject, $attribute, $entity_type );
            if ( $fallback !== null && ! empty( $fallback['ok'] ) ) {
                $attrResult = $fallback;
            }
        }

        if ( empty( $attrResult['ok'] ) ) {
            if ( $this->debugLogger ) {
                $this->debugLogger->trace( [
                    'input'              => $query,
                    'intent'             => 'get_entity_attribute',
                    'entity'             => $entity_type,
                    'resolved_entity_id' => $entity_id,
                    'attribute'          => $attribute,
                    'permissions'        => 'allowed',
                    'service'            => 'AttributeResolver',
                    'status'             => 'error',
                    'detail'             => (string) ( $attrResult['error'] ?? '' ),
                ] );
            }
            return $this->entityAttributeError( $intent, 'Attribute not available.' );
        }

        if ( $this->debugLogger ) {
            $this->debugLogger->attributeFetched( $query, $attribute, $attrResult['value'], $entity_id );
        }

        $name = trim( (string) ( $record['first_name'] ?? '' ) . ' ' . (string) ( $record['last_name'] ?? '' ) );
        $value = $attrResult['value'];
        $display = is_array( $value ) ? implode( ', ', $value ) : (string) $value;

        $message = sprintf( '%s\'s %s is: %s', $name ?: $subject, $attribute, $display );
        if ( $attribute === 'name' ) {
            if ( str_contains( $subject, '@' ) ) {
                $message = sprintf( 'That email belongs to %s.', $display );
            } elseif ( preg_match( '/\d{7,}/', preg_replace( '/\D+/', '', $subject ) ?? '' ) ) {
                $message = sprintf( 'That phone number belongs to %s.', $display );
            } else {
                $message = sprintf( 'That identifier belongs to %s.', $display );
            }
        }

        return [
            'intent'      => $intent,
            'command'     => null,
            'context_packs' => [],
            'action_plan' => [],
            'permission'  => $permission,
            'response'    => [
                'status'        => 'success',
                'message'       => $message,
                'response_type' => 'EntityAttribute',
                'entity'        => $entity_type,
                'id'            => $entity_id,
                'attribute'     => $attribute,
                'value'         => $value,
                'ui_components' => [[
                    'type'      => 'AttributeCard',
                    'entity'    => $entity_type,
                    'id'        => $entity_id,
                    'name'      => $name ?: $subject,
                    'attribute' => $attribute,
                    'value'     => $value,
                ]],
            ],
        ];
    }

    private function fallbackAttributeLookup( string $subject, string $attribute, string $resolvedType ): ?array {
        if ( $this->entityResolver === null || $this->attributeResolver === null ) {
            return null;
        }

        if ( ! in_array( $attribute, [ 'phone', 'address' ], true ) ) {
            return null;
        }

        // Common case: person record lacks phone/address while linked contact holds it.
        if ( $resolvedType === 'person' ) {
            foreach ( [ 'contact', 'donor' ] as $fallbackType ) {
                $resolved = $this->entityResolver->resolve( $subject, $fallbackType );
                if ( empty( $resolved['ok'] ) ) {
                    continue;
                }

                $fallbackRecord = (array) ( $resolved['record'] ?? [] );
                $result = $this->attributeResolver->extract( $fallbackRecord, $attribute, $fallbackType );
                if ( ! empty( $result['ok'] ) ) {
                    return $result;
                }
            }
        }

        // Last fallback: profile lookup service can bridge person/contact linkage.
        if ( class_exists( '\Metis\Core\Application' ) && \Metis\Core\Application::has_service( 'hermes_directory' ) ) {
            $profileResult = \Metis\Core\Application::service( 'hermes_directory' )->lookupProfile( [
                'subject' => $subject,
                'entity_hint' => 'auto',
            ] );

            $profile = (array) ( $profileResult['profile'] ?? [] );
            $contact = (array) ( $profile['contact'] ?? [] );
            if ( $attribute === 'phone' ) {
                $phone = trim( (string) ( $contact['phone'] ?? '' ) );
                if ( $phone !== '' ) {
                    return [ 'ok' => true, 'attribute' => 'phone', 'value' => $phone ];
                }
            } elseif ( $attribute === 'address' ) {
                $line1 = trim( (string) ( $contact['address_line_1'] ?? '' ) );
                $city = trim( (string) ( $contact['city'] ?? '' ) );
                $state = trim( (string) ( $contact['state'] ?? '' ) );
                $zip = trim( (string) ( $contact['zip'] ?? '' ) );
                $composed = trim( implode( ', ', array_values( array_filter( [
                    $line1,
                    $city,
                    trim( $state . ( $zip !== '' ? ' ' . $zip : '' ) ),
                ] ) ) ) );
                $address = $composed !== '' ? $composed : trim( (string) ( $contact['address'] ?? '' ) );
                if ( $address !== '' ) {
                    return [ 'ok' => true, 'attribute' => 'address', 'value' => $address ];
                }
            }
        }

        return null;
    }

    /**
     * @param array<int,array<string,mixed>> $candidates
     */
    private function disambiguationPrompt( array $candidates ): string {
        $lines = [ 'I found multiple matches:' ];
        foreach ( $candidates as $index => $candidate ) {
            $name = trim( (string) ( $candidate['name'] ?? '' ) );
            $email = trim( (string) ( $candidate['email'] ?? '' ) );
            $suffix = $email !== '' ? ' (' . $email . ')' : '';
            $lines[] = sprintf( '%d. %s%s', $index + 1, $name !== '' ? $name : 'Unknown', $suffix );
        }
        $lines[] = 'Which person would you like?';

        return implode( "\n", $lines );
    }

    private function entityAttributeError( array $intent, string $message ): array {
        return [
            'intent'      => $intent,
            'command'     => null,
            'context_packs' => [],
            'action_plan' => [],
            'permission'  => [ 'status' => 'not_applicable' ],
            'response'    => [
                'status'        => 'error',
                'message'       => $message,
                'response_type' => 'Error',
            ],
        ];
    }

    // ------------------------------------------------------------------
    // Data intent routing
    // ------------------------------------------------------------------

    private function isDataIntent( array $intent ): bool {
        // The Chunk 1 data-intent extension sets intent['type'] = 'data'
        // and intent['intent'] to a DATA_INTENTS member.
        if ( (string) ( $intent['type'] ?? '' ) === 'data' ) {
            return true;
        }
        // Fallback: check if the intent action is a known data intent
        // and no command is mapped.
        return ( $intent['command'] ?? null ) === null
            && in_array( strtolower( (string) ( $intent['intent'] ?? $intent['action'] ?? '' ) ), self::DATA_INTENTS, true );
    }

    private function processDataIntent( array $intent ): array {
        try {
            if ( $this->isDirectUserCountIntent( $intent ) ) {
                return $this->processDirectUserCountIntent( $intent );
            }

            $reporting = \Metis\Core\Application::service( 'hermes_reporting' );
            $actor     = $this->resolveActor();

            $interpretation = array_merge( $intent, [
                'intent' => strtolower( (string) ( $intent['intent'] ?? $intent['action'] ?? 'list' ) ),
            ] );

            $result = $reporting->handle( $interpretation, $actor );

            $message = $result['ok']
                ? $this->describeResult( $result, $intent )
                : (string) ( $result['message'] ?? 'Data query failed.' );

            $response = [
                'intent'        => (string) ( $intent['action'] ?? $intent['intent'] ?? 'data_query' ),
                'status'        => $result['ok'] ? 'success' : 'error',
                'message'       => $message,
                'response_type' => 'DataResult',
                'report'        => $result,
                'ui_components' => [
                    [
                        'type'   => 'DataTable',
                        'entity' => $result['entity'] ?? '',
                        'data'   => $result['data']   ?? [],
                        'total'  => $result['total']  ?? 0,
                    ],
                ],
            ];

            return [
                'intent'        => $intent,
                'command'       => null,
                'context_packs' => [],
                'action_plan'   => [],
                'permission'    => [ 'status' => 'not_applicable' ],
                'response'      => $response,
            ];
        } catch ( \Throwable $e ) {
            return [
                'intent'        => $intent,
                'command'       => null,
                'context_packs' => [],
                'action_plan'   => [],
                'permission'    => [ 'status' => 'not_applicable' ],
                'response'      => $this->responses->error( $intent, 'Data query could not be completed.' ),
            ];
        }
    }

    private function resolveActor(): array {
        if ( function_exists( 'metis_current_user_id' ) ) {
            $userId = (int) \metis_current_user_id();
            if ( $userId > 0 && function_exists( 'metis_runtime_current_user' ) ) {
                $user = \metis_runtime_current_user();
                if ( is_object( $user ) ) {
                    return [
                        'user_id' => $userId,
                        'roles'   => array_values( array_map( 'strval', (array) ( $user->roles ?? [] ) ) ),
                    ];
                }
            }
            return [ 'user_id' => $userId, 'roles' => [] ];
        }
        return [ 'user_id' => 0, 'roles' => [] ];
    }

    private function isDirectUserCountIntent( array $intent ): bool {
        $query = strtolower( trim( (string) ( $intent['query'] ?? $intent['raw_query'] ?? '' ) ) );

        return (string) ( $intent['action'] ?? '' ) === 'count'
            && (string) ( $intent['entity'] ?? '' ) === 'person'
            && preg_match( '/\bhow many users?\b/', $query ) === 1;
    }

    private function processDirectUserCountIntent( array $intent ): array {
        $db = \Metis\Core\Application::service( 'db' );
        $peopleTable = \Metis_Tables::get( 'people' );
        $count = (int) $db->scalar( "SELECT COUNT(*) FROM {$peopleTable} WHERE status <> 'deleted'" );
        $report = [
            'ok' => true,
            'report_type' => 'count',
            'status' => 'success',
            'entity' => 'person',
            'data' => [ [ 'total' => $count ] ],
            'total' => $count,
            'limit' => 1,
            'offset' => 0,
            'meta' => [],
        ];
        $message = sprintf( 'Found %d user%s.', $count, $count === 1 ? '' : 's' );

        return [
            'intent'        => $intent,
            'command'       => null,
            'context_packs' => [],
            'action_plan'   => [],
            'permission'    => [ 'status' => 'not_applicable' ],
            'response'      => [
                'intent'        => (string) ( $intent['action'] ?? 'count' ),
                'status'        => 'success',
                'message'       => $message,
                'response_type' => 'DataResult',
                'report'        => $report,
                'ui_components' => [
                    [
                        'type'   => 'DataTable',
                        'entity' => 'person',
                        'data'   => $report['data'],
                        'total'  => $count,
                    ],
                ],
            ],
        ];
    }

    private function describeResult( array $result, array $intent = [] ): string {
        $type   = (string) ( $result['report_type'] ?? 'list' );
        $query  = strtolower( trim( (string) ( $intent['query'] ?? $intent['raw_query'] ?? '' ) ) );
        $entity = $this->humanizeEntityName( (string) ( $result['entity'] ?? 'records' ), $query );
        $total  = (int) ( $result['total'] ?? 0 );
        $rows   = array_values( (array) ( $result['data'] ?? [] ) );
        $first  = (array) ( $rows[0] ?? [] );

        if ( $type === 'list' && (string) ( $result['entity'] ?? '' ) === 'donation_transaction' ) {
            if ( str_contains( $query, 'who made the last donation' ) && $first !== [] ) {
                $donor = trim( (string) ( $first['donor_name'] ?? '' ) );
                $amount = isset( $first['amount'] ) ? '$' . number_format( (float) $first['amount'], 2 ) : '';
                $campaign = trim( (string) ( $first['campaign_name'] ?? '' ) );
                $date = trim( (string) ( $first['transaction_date'] ?? '' ) );

                return sprintf(
                    '%s made the last donation%s%s%s.',
                    $donor !== '' ? $donor : 'An unknown donor',
                    $amount !== '' ? ' of ' . $amount : '',
                    $campaign !== '' ? ' to ' . $campaign : '',
                    $date !== '' ? ' on ' . $date : ''
                );
            }

            if ( str_contains( $query, 'last donation' ) && $first !== [] ) {
                $donor = trim( (string) ( $first['donor_name'] ?? '' ) );
                $amount = isset( $first['amount'] ) ? '$' . number_format( (float) $first['amount'], 2 ) : '';
                $campaign = trim( (string) ( $first['campaign_name'] ?? '' ) );
                $date = trim( (string) ( $first['transaction_date'] ?? '' ) );

                return sprintf(
                    'The last donation was%s%s%s%s.',
                    $amount !== '' ? ' ' . $amount : '',
                    $donor !== '' ? ' from ' . $donor : '',
                    $campaign !== '' ? ' to ' . $campaign : '',
                    $date !== '' ? ' on ' . $date : ''
                );
            }

            if ( preg_match( '/\blast\s+\d+\s+donations?\b/', $query ) === 1 ) {
                return sprintf( 'Showing the last %d donations.', count( $rows ) );
            }
        }

        if ( $type === 'list' && (string) ( $result['entity'] ?? '' ) === 'donation_campaign' && str_contains( $query, 'current campaign' ) && $first !== [] ) {
            $name = trim( (string) ( $first['name'] ?? '' ) );
            if ( $name !== '' ) {
                return sprintf( 'The current campaign is %s.', $name );
            }
        }

        if ( $type === 'top' && (string) ( $result['entity'] ?? '' ) === 'donor' && $rows !== [] ) {
            $entries = [];
            foreach ( array_slice( $rows, 0, 5 ) as $index => $row ) {
                $row = (array) $row;
                $name = trim( (string) ( $row['donor_name'] ?? $row['name'] ?? '' ) );
                if ( $name === '' ) {
                    $name = 'Unknown donor';
                }
                $amount = isset( $row['total_raised'] ) ? (float) $row['total_raised'] : ( isset( $row['amount'] ) ? (float) $row['amount'] : 0.0 );
                $entries[] = sprintf( '%d. %s ($%s)', $index + 1, $name, number_format( $amount, 2 ) );
            }

            if ( $entries !== [] ) {
                return "Top donors:\n" . implode( "\n", $entries );
            }
        }

        if ( $type === 'top' && (string) ( $result['entity'] ?? '' ) === 'donation_campaign' && $first !== [] ) {
            $name = trim( (string) ( $first['name'] ?? '' ) );
            $amount = isset( $first['total_raised'] ) ? '$' . number_format( (float) $first['total_raised'], 2 ) : '';

            if ( $name !== '' && preg_match( '/\b(which|what)\s+campaign\b/', $query ) === 1 ) {
                return sprintf(
                    'The best-performing campaign is %s%s.',
                    $name,
                    $amount !== '' ? ' (' . $amount . ')' : ''
                );
            }
        }

        return match ( $type ) {
            'count'     => "Found {$total} " . $entity . ( $total === 1 ? '' : 's' ) . '.',
            'aggregate' => 'Aggregation complete for ' . $entity . '.',
            'top'       => 'Top ' . ( $result['meta']['top_n'] ?? $total ) . ' ' . $entity . ' results.',
            'export'    => 'Export ready: ' . $total . ' rows.',
            default     => 'Found ' . $total . ' ' . $entity . ( $total === 1 ? '' : 's' ) . '.',
        };
    }

    // ------------------------------------------------------------------
    // Plan builder (extracted for reuse)
    // ------------------------------------------------------------------

    private function buildPlan( array $command, array $contextPacks, array $executionPlan = [] ): array {
        $steps = $executionPlan !== []
            ? array_values( array_map( static function ( array $step ): array {
                return [
                    'step' => (int) ( $step['step'] ?? 0 ),
                    'intent' => (string) ( $step['intent'] ?? '' ),
                    'title' => (string) ( $step['command']['title'] ?? $step['intent'] ?? '' ),
                    'tool_key' => (string) ( $step['command']['tool_key'] ?? '' ),
                    'required_permission' => (string) ( $step['permission']['required_permission'] ?? $step['command']['permission'] ?? '' ),
                    'requires_approval' => ! empty( $step['requires_approval'] ),
                    'read_only' => ! empty( $step['command']['read_only'] ),
                ];
            }, $executionPlan ) )
            : array_values( (array) ( $command['steps'] ?? [] ) );

        return [
            'operation'          => (string) ( $command['key'] ?? '' ),
            'tool_key'           => (string) ( $command['tool_key'] ?? $command['key'] ?? '' ),
            'title'              => (string) ( $command['title'] ?? '' ),
            'category'           => (string) ( $command['category'] ?? '' ),
            'description'        => (string) ( $command['description'] ?? '' ),
            'steps'              => $steps,
            'required_permission'=> (string) ( $command['permission'] ?? '' ),
            'context_loaded'     => array_values( array_filter( array_map(
                static fn ( array $pack ): string => (string) ( $pack['title'] ?? $pack['key'] ?? '' ),
                $contextPacks
            ) ) ),
            'approval_required'  => $executionPlan !== [] ? $this->executionPlanRequiresApproval( $executionPlan ) : ! (bool) ( $command['read_only'] ?? false ),
            'read_only'          => (bool) ( $command['read_only'] ?? false ),
            'service'            => (array) ( $command['service'] ?? [] ),
            'capability'         => [
                'tool_key'             => (string) ( $command['tool_key'] ?? $command['key'] ?? '' ),
                'module'               => (string) ( $command['module'] ?? $command['domain'] ?? '' ),
                'version'              => (int)    ( $command['version'] ?? 1 ),
                'category'             => (string) ( $command['category'] ?? '' ),
                'read_only'            => (bool)   ( $command['read_only'] ?? false ),
                'cache_ttl_seconds'    => (int)    ( $command['cache_ttl_seconds'] ?? 0 ),
                'enclave_operation'    => (string) ( $command['enclave_operation'] ?? '' ),
                'idempotency_strategy' => (string) ( $command['idempotency_strategy'] ?? '' ),
                'diagnostic_tags'      => array_values( array_map( 'strval', (array) ( $command['diagnostic_tags'] ?? [] ) ) ),
                'input_schema'         => (array)  ( $command['input_schema'] ?? [] ),
                'output_schema'        => (array)  ( $command['output_schema'] ?? [] ),
            ],
        ];
    }

    private function syncExecutionPlanPayloads( array $parsed, array $intent ): array {
        if ( ! isset( $parsed['execution_plan'] ) || ! is_array( $parsed['execution_plan'] ) ) {
            return $parsed;
        }

        foreach ( $parsed['execution_plan'] as $index => $step ) {
            if ( ! is_array( $step ) ) {
                continue;
            }

            $stepIntent = \metis_key_clean( (string) ( $step['intent'] ?? '' ) );
            if ( $stepIntent === (string) ( $intent['action'] ?? '' ) ) {
                $parsed['execution_plan'][ $index ]['payload'] = (array) ( $intent['payload'] ?? [] );
            }
        }

        return $parsed;
    }

    private function resolvedExecutionPlan( array $parsed, array $fallbackCommand ): array {
        $rawPlan = array_values( array_filter(
            (array) ( $parsed['execution_plan'] ?? [] ),
            static fn ( mixed $step ): bool => is_array( $step )
        ) );

        if ( $rawPlan === [] ) {
            return $fallbackCommand === []
                ? []
                : [ [
                    'step' => 1,
                    'intent' => (string) ( $fallbackCommand['key'] ?? '' ),
                    'payload' => [],
                    'requires_approval' => ! empty( $fallbackCommand['requires_approval'] ),
                    'command' => $fallbackCommand,
                    'permission' => [ 'status' => 'granted', 'required_permission' => (string) ( $fallbackCommand['permission'] ?? '' ), 'reason' => '' ],
                ] ];
        }

        $resolved = [];
        foreach ( $rawPlan as $index => $step ) {
            $stepIntent = \metis_key_clean( (string) ( $step['intent'] ?? '' ) );
            $stepCommand = $this->commands->definition( $stepIntent );
            if ( $stepCommand === [] ) {
                continue;
            }

            $resolved[] = [
                'step' => (int) ( $step['step'] ?? $index + 1 ),
                'fragment' => (string) ( $step['fragment'] ?? '' ),
                'intent' => $stepIntent,
                'payload' => (array) ( $step['payload'] ?? [] ),
                'requires_approval' => ! empty( $step['requires_approval'] ) || ! empty( $stepCommand['requires_approval'] ),
                'command' => $stepCommand,
            ];
        }

        return $resolved;
    }

    private function executionPlanRequiresApproval( array $executionPlan ): bool {
        foreach ( $executionPlan as $step ) {
            if ( ! empty( $step['requires_approval'] ) || empty( $step['command']['read_only'] ) ) {
                return true;
            }
        }

        return false;
    }

    private function validateExecutionPlanPermissions( array $executionPlan, array $actor = [] ): array {
        $deniedSteps = [];
        $requiredPermissions = [];

        foreach ( $executionPlan as $index => $step ) {
            $command = (array) ( $step['command'] ?? [] );
            $permission = $this->permissions->validate( $command, $actor );
            $executionPlan[ $index ]['permission'] = $permission;
            $required = (string) ( $permission['required_permission'] ?? '' );
            if ( $required !== '' ) {
                $requiredPermissions[] = $required;
            }

            if ( (string) ( $permission['status'] ?? '' ) !== 'granted' ) {
                $deniedSteps[] = [
                    'step' => (int) ( $step['step'] ?? $index + 1 ),
                    'intent' => (string) ( $step['intent'] ?? '' ),
                    'required_permission' => $required,
                    'reason' => (string) ( $permission['reason'] ?? 'Permission denied.' ),
                ];
            }
        }

        if ( $deniedSteps === [] ) {
            return [
                'status' => 'granted',
                'required_permission' => implode( ', ', array_values( array_unique( array_filter( $requiredPermissions ) ) ) ),
                'reason' => '',
                'steps' => $executionPlan,
            ];
        }

        return [
            'status' => 'denied',
            'required_permission' => implode( ', ', array_values( array_unique( array_filter( $requiredPermissions ) ) ) ),
            'reason' => (string) ( $deniedSteps[0]['reason'] ?? 'Permission denied.' ),
            'denied_steps' => $deniedSteps,
            'steps' => $executionPlan,
        ];
    }

    private function executeExecutionPlan( array $executionPlan, array $options = [] ): array {
        $stepResults = [];

        foreach ( $executionPlan as $step ) {
            $stepCommand = (array) ( $step['command'] ?? [] );
            $stepIntent = \metis_key_clean( (string) ( $step['intent'] ?? '' ) );
            if ( $stepCommand === [] || $stepIntent === '' ) {
                throw new \RuntimeException( 'Execution plan contains an invalid step.' );
            }

            $stepResult = $this->execution->execute( $stepCommand, (array) ( $step['payload'] ?? [] ), $options );
            $stepResults[] = [
                'step' => (int) ( $step['step'] ?? count( $stepResults ) + 1 ),
                'intent' => $stepIntent,
                'result' => $stepResult,
            ];

            if ( in_array( (string) ( $stepResult['status'] ?? '' ), [ 'error', 'failed' ], true ) ) {
                $stepMessage = trim( (string) ( $stepResult['message'] ?? '' ) );
                return [
                    'status' => 'error',
                    'message' => $stepMessage !== ''
                        ? $stepMessage
                        : sprintf( 'Execution stopped at step %d.', (int) ( $step['step'] ?? count( $stepResults ) ) ),
                    'steps' => $stepResults,
                ];
            }
        }

        return [
            'status' => 'success',
            'steps' => $stepResults,
            'message' => 'Execution plan completed.',
        ];
    }

    private function humanizeEntityName( string $entity, string $query = '' ): string {
        $entity = trim( str_replace( '_', ' ', $entity ) );
        if ( $entity === 'person' && preg_match( '/\busers?\b/', $query ) === 1 ) {
            return 'user';
        }
        if ( $entity === 'donation transaction' ) {
            return 'donation transaction';
        }

        return $entity !== '' ? $entity : 'records';
    }
}
