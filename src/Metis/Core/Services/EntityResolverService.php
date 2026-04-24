<?php
declare(strict_types=1);

namespace Metis\Core\Services;

use Metis\Modules\Contacts\SchemaManager as ContactsSchemaManager;
use Metis\Modules\People\SchemaManager as PeopleSchemaManager;
use Metis\Services\DatabaseService;

final class EntityResolverService {
    private const STATUS_RESOLVED = 'resolved';
    private const STATUS_AMBIGUOUS = 'ambiguous';
    private const STATUS_NOT_FOUND = 'not_found';

    private const CONFIDENCE_HIGH = 'high';
    private const CONFIDENCE_MEDIUM = 'medium';
    private const CONFIDENCE_LOW = 'low';
    private const CONFIDENCE_NONE = 'none';

    /** @var array<string, callable> */
    private array $entityResolvers = [];

    /** @var array<string, array<string, bool>> */
    private array $columnCache = [];

    public function __construct(
        private readonly ?DatabaseService $db = null
    ) {
        $this->registerEntityType( 'user', fn ( string $input, array $context = [] ): EntityResolutionResult => $this->resolveBuiltIn( $input, 'user', $context ) );
        $this->registerEntityType( 'contact', fn ( string $input, array $context = [] ): EntityResolutionResult => $this->resolveBuiltIn( $input, 'contact', $context ) );
        $this->registerEntityType( 'donor', fn ( string $input, array $context = [] ): EntityResolutionResult => $this->resolveBuiltIn( $input, 'donor', $context ) );
    }

    public function registerEntityType( string $type, callable $resolver ): void {
        $type = strtolower( trim( $type ) );
        if ( $type === '' ) {
            throw new \InvalidArgumentException( 'Entity type cannot be empty.' );
        }

        $this->entityResolvers[ $type ] = $resolver;
    }

    public function resolve( string $input, string $entityType, array $context = [] ): EntityResolutionResult {
        $input = trim( $input );
        $entityType = strtolower( trim( $entityType ) );

        if ( $input === '' ) {
            return $this->notFoundResult( $entityType, $input, 'Please provide a name, email, or role so I can resolve the entity.' );
        }

        if ( $entityType === '' ) {
            return $this->notFoundResult( 'unknown', $input, 'Please specify an entity type such as user, contact, or donor.' );
        }

        $sessionResolved = $this->resolveFromSessionContext( $input, $entityType, (array) ( $context['session_entities'] ?? [] ) );
        if ( $sessionResolved !== null ) {
            return $sessionResolved;
        }

        $resolver = $this->entityResolvers[ $entityType ] ?? null;
        if ( ! is_callable( $resolver ) ) {
            return $this->notFoundResult( $entityType, $input, sprintf( 'Entity type "%s" is not registered. Register it with registerEntityType().' , $entityType ) );
        }

        $result = $resolver( $input, $context );
        if ( ! ( $result instanceof EntityResolutionResult ) ) {
            throw new \RuntimeException( sprintf( 'Entity resolver for "%s" must return EntityResolutionResult.', $entityType ) );
        }

        return $result;
    }

    private function resolveBuiltIn( string $input, string $entityType, array $context ): EntityResolutionResult {
        $this->ensureSchema( $entityType );
        $descriptor = $this->descriptor( $entityType );

        // 0) identifier match via linked contact details (phone/address)
        if ( in_array( $entityType, [ 'contact', 'donor' ], true ) ) {
            $identifier = $this->searchByContactDetailsIdentifier( $descriptor, $input );
            if ( $identifier !== [] ) {
                return $this->decide( $input, $entityType, $identifier, self::CONFIDENCE_MEDIUM );
            }
        }

        // 1) exact match
        $exact = $this->searchExact( $descriptor, $input );
        if ( $exact !== [] ) {
            return $this->decide( $input, $entityType, $exact, self::CONFIDENCE_HIGH );
        }

        // 2) case-insensitive match
        $caseInsensitive = $this->searchCaseInsensitive( $descriptor, $input );
        if ( $caseInsensitive !== [] ) {
            return $this->decide( $input, $entityType, $caseInsensitive, self::CONFIDENCE_HIGH );
        }

        // 3) partial match
        $partial = $this->searchPartial( $descriptor, $input );
        if ( $partial !== [] ) {
            return $this->decide( $input, $entityType, $partial, self::CONFIDENCE_MEDIUM );
        }

        // 4) alias match
        $alias = $this->searchAlias( $descriptor, $input );
        if ( $alias !== [] ) {
            return $this->decide( $input, $entityType, $alias, self::CONFIDENCE_MEDIUM );
        }

        // 5) fuzzy match (limited candidate pool)
        $fuzzy = $this->searchFuzzy( $descriptor, $input );
        if ( $fuzzy !== [] ) {
            return $this->decide( $input, $entityType, $fuzzy, self::CONFIDENCE_LOW );
        }

        return $this->notFoundResult( $entityType, $input );
    }

    /**
     * @param array<string, mixed> $descriptor
     * @return array<int, array<string, mixed>>
     */
    private function searchExact( array $descriptor, string $input ): array {
        $table = (string) $descriptor['table'];
        $where = (string) $descriptor['where'];
        $select = $this->selectClause( $descriptor );

        $clauses = [];
        $args = [];

        foreach ( (array) ( $descriptor['exact_columns'] ?? [] ) as $column ) {
            $clauses[] = sprintf( "COALESCE(%s, '') = %%s", $column );
            $args[] = $input;
        }

        $nameParts = $this->nameParts( $input );
        if ( $nameParts !== null ) {
            $clauses[] = "COALESCE(first_name, '') = %s AND COALESCE(last_name, '') = %s";
            $args[] = $nameParts['first'];
            $args[] = $nameParts['last'];
        }

        if ( $clauses === [] ) {
            return [];
        }

        $query = sprintf(
            "SELECT %s FROM %s WHERE (%s) AND (%s) ORDER BY id DESC LIMIT 10 /* ers:exact:%s */",
            $select,
            $table,
            implode( ' OR ', $clauses ),
            $where,
            $descriptor['type']
        );

        return $this->normalizeCandidates( $descriptor, $this->database()->fetchAll( $query, $args ) );
    }

    /**
     * @param array<string, mixed> $descriptor
     * @return array<int, array<string, mixed>>
     */
    private function searchCaseInsensitive( array $descriptor, string $input ): array {
        $table = (string) $descriptor['table'];
        $where = (string) $descriptor['where'];
        $select = $this->selectClause( $descriptor );
        $needle = strtolower( $input );

        $clauses = [];
        $args = [];

        foreach ( (array) ( $descriptor['exact_columns'] ?? [] ) as $column ) {
            $clauses[] = sprintf( "LOWER(COALESCE(%s, '')) = %%s", $column );
            $args[] = $needle;
        }

        $nameParts = $this->nameParts( $input );
        if ( $nameParts !== null ) {
            $clauses[] = "LOWER(COALESCE(first_name, '')) = %s AND LOWER(COALESCE(last_name, '')) = %s";
            $args[] = strtolower( $nameParts['first'] );
            $args[] = strtolower( $nameParts['last'] );
        }

        if ( $clauses === [] ) {
            return [];
        }

        $query = sprintf(
            "SELECT %s FROM %s WHERE (%s) AND (%s) ORDER BY id DESC LIMIT 10 /* ers:case:%s */",
            $select,
            $table,
            implode( ' OR ', $clauses ),
            $where,
            $descriptor['type']
        );

        return $this->normalizeCandidates( $descriptor, $this->database()->fetchAll( $query, $args ) );
    }

    /**
     * @param array<string, mixed> $descriptor
     * @return array<int, array<string, mixed>>
     */
    private function searchPartial( array $descriptor, string $input ): array {
        $table = (string) $descriptor['table'];
        $where = (string) $descriptor['where'];
        $select = $this->selectClause( $descriptor );

        $prefix = $this->database()->escapeLike( $input ) . '%';
        $likeClauses = [];
        $args = [];

        foreach ( (array) ( $descriptor['partial_columns'] ?? [] ) as $column ) {
            $likeClauses[] = sprintf( "COALESCE(%s, '') LIKE %%s", $column );
            $args[] = $prefix;
        }

        $likeClauses[] = "CONCAT(COALESCE(first_name, ''), ' ', COALESCE(last_name, '')) LIKE %s";
        $args[] = $prefix;

        $query = sprintf(
            "SELECT %s FROM %s WHERE (%s) AND (%s) ORDER BY id DESC LIMIT 15 /* ers:partial:%s */",
            $select,
            $table,
            implode( ' OR ', $likeClauses ),
            $where,
            $descriptor['type']
        );

        return $this->normalizeCandidates( $descriptor, $this->database()->fetchAll( $query, $args ) );
    }

    /**
     * @param array<string, mixed> $descriptor
     * @return array<int, array<string, mixed>>
     */
    private function searchAlias( array $descriptor, string $input ): array {
        $columns = (array) ( $descriptor['alias_columns'] ?? [] );
        if ( $columns === [] ) {
            return [];
        }

        $availableColumns = array_values( array_filter( $columns, fn ( string $column ): bool => $this->columnExists( (string) $descriptor['table'], $column ) ) );
        if ( $availableColumns === [] ) {
            return [];
        }

        $table = (string) $descriptor['table'];
        $where = (string) $descriptor['where'];
        $select = $this->selectClause( $descriptor );
        $needle = strtolower( $input );
        $like = '%' . $this->database()->escapeLike( $needle ) . '%';

        $clauses = [];
        $args = [];
        foreach ( $availableColumns as $column ) {
            $clauses[] = sprintf( "LOWER(COALESCE(%s, '')) LIKE %%s", $column );
            $args[] = $like;
        }

        $query = sprintf(
            "SELECT %s, %s FROM %s WHERE (%s) AND (%s) ORDER BY id DESC LIMIT 25 /* ers:alias:%s */",
            $select,
            implode( ', ', $availableColumns ),
            $table,
            implode( ' OR ', $clauses ),
            $where,
            $descriptor['type']
        );

        $rows = $this->database()->fetchAll( $query, $args );
        $matches = [];
        foreach ( $rows as $row ) {
            $aliases = [];
            foreach ( $availableColumns as $column ) {
                $aliases = array_merge( $aliases, $this->decodeAliasValues( (string) ( $row[ $column ] ?? '' ) ) );
            }

            foreach ( $aliases as $alias ) {
                if ( strtolower( trim( $alias ) ) === $needle ) {
                    $matches[] = $row;
                    break;
                }
            }
        }

        return $this->normalizeCandidates( $descriptor, $matches );
    }

    /**
     * @param array<string, mixed> $descriptor
     * @return array<int, array<string, mixed>>
     */
    private function searchFuzzy( array $descriptor, string $input ): array {
        $table = (string) $descriptor['table'];
        $where = (string) $descriptor['where'];
        $select = $this->selectClause( $descriptor );

        $parts = preg_split( '/\s+/', strtolower( trim( $input ) ) ) ?: [];
        $parts = array_values( array_filter( array_map(
            static fn ( string $part ): string => preg_replace( '/[^a-z0-9]/i', '', $part ) ?? '',
            $parts
        ), static fn ( string $part ): bool => $part !== '' ) );

        if ( $parts === [] ) {
            return [];
        }

        $prefixes = [];
        foreach ( $parts as $part ) {
            $prefixes[] = $this->database()->escapeLike( substr( $part, 0, min( strlen( $part ), 4 ) ) ) . '%';
        }
        $prefixes = array_values( array_unique( $prefixes ) );

        $searchableColumns = array_values( array_filter(
            [ 'first_name', 'last_name', 'display_name', 'email' ],
            fn ( string $column ): bool => $this->columnExists( $table, $column )
        ) );
        if ( $searchableColumns === [] ) {
            return [];
        }

        $prefixClauses = [];
        $args = [];
        foreach ( $prefixes as $prefix ) {
            $clauses = [];
            foreach ( $searchableColumns as $column ) {
                $clauses[] = sprintf( "COALESCE(%s, '') LIKE %%s", $column );
                $args[] = $prefix;
            }

            if ( in_array( 'first_name', $searchableColumns, true ) && in_array( 'last_name', $searchableColumns, true ) ) {
                $clauses[] = "CONCAT(COALESCE(first_name, ''), ' ', COALESCE(last_name, '')) LIKE %s";
                $args[] = $prefix;
            }

            $prefixClauses[] = '(' . implode( ' OR ', $clauses ) . ')';
        }

        $query = sprintf(
            "SELECT %s FROM %s WHERE (%s) AND (%s) ORDER BY id DESC LIMIT 50 /* ers:fuzzy:%s */",
            $select,
            $table,
            implode( ' OR ', $prefixClauses ),
            $where,
            $descriptor['type']
        );

        $rows = $this->database()->fetchAll( $query, $args );
        if ( $rows === [] ) {
            return [];
        }

        $needle = strtolower( $input );
        $ranked = [];
        foreach ( $rows as $row ) {
            $name = strtolower( trim( (string) ( $row['first_name'] ?? '' ) . ' ' . (string) ( $row['last_name'] ?? '' ) ) );
            $display = strtolower( trim( (string) ( $row['display_name'] ?? '' ) ) );
            $email = strtolower( trim( (string) ( $row['email'] ?? '' ) ) );

            $distances = [];
            foreach ( [ $name, $display, $email ] as $value ) {
                if ( $value !== '' ) {
                    $distances[] = levenshtein( $needle, $value );
                }
            }

            if ( $distances === [] ) {
                continue;
            }

            $distance = min( $distances );
            $referenceLength = max( strlen( $needle ), 1 );
            $ratio = $distance / $referenceLength;

            if ( $ratio > 0.45 ) {
                continue;
            }

            $row['__distance'] = $distance;
            $ranked[] = $row;
        }

        usort( $ranked, static fn ( array $a, array $b ): int => (int) ( $a['__distance'] ?? 0 ) <=> (int) ( $b['__distance'] ?? 0 ) );
        $ranked = array_slice( $ranked, 0, 8 );

        return $this->normalizeCandidates( $descriptor, $ranked );
    }

    /**
     * @param array<string, mixed> $descriptor
     * @return array<int, array<string, mixed>>
     */
    private function searchByContactDetailsIdentifier( array $descriptor, string $input ): array {
        if ( ! class_exists( 'Metis_Tables' ) ) {
            return [];
        }

        $contactsTable = (string) ( $descriptor['table'] ?? '' );
        $detailsTable = \Metis_Tables::get( 'contact_details' );
        if ( $contactsTable === '' || ! $this->tableExists( $detailsTable ) ) {
            return [];
        }

        $select = $this->selectClause( $descriptor );
        $where = (string) ( $descriptor['where'] ?? '1=1' );

        $needle = trim( $input );
        $phoneDigits = preg_replace( '/\D+/', '', $needle ) ?? '';
        $emailNeedle = strtolower( $needle );
        $isEmail = str_contains( $emailNeedle, '@' );
        $hasAddressColumns = $this->columnExists( $detailsTable, 'address' )
            || $this->columnExists( $detailsTable, 'city' )
            || $this->columnExists( $detailsTable, 'state' )
            || $this->columnExists( $detailsTable, 'zip' );

        $clauses = [];
        $args = [];

        if ( $phoneDigits !== '' && strlen( $phoneDigits ) >= 7 && $this->columnExists( $detailsTable, 'phone' ) ) {
            $clauses[] = "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(COALESCE(cd.phone, ''), '-', ''), '(', ''), ')', ''), ' ', ''), '.', '') = %s";
            $args[] = $phoneDigits;
        }

        if ( $isEmail ) {
            if ( $this->columnExists( $contactsTable, 'email' ) ) {
                $clauses[] = "LOWER(COALESCE(c.email, '')) = %s";
                $args[] = $emailNeedle;
            }
            if ( $this->columnExists( $detailsTable, 'additional_emails_json' ) ) {
                $clauses[] = "LOWER(COALESCE(cd.additional_emails_json, '')) LIKE %s";
                $args[] = '%' . $this->database()->escapeLike( '"' . $emailNeedle . '"' ) . '%';
            }
        }

        if ( $needle !== '' && $hasAddressColumns ) {
            $addressLike = '%' . $this->database()->escapeLike( strtolower( $needle ) ) . '%';
            if ( $this->columnExists( $detailsTable, 'address' ) ) {
                $clauses[] = "LOWER(COALESCE(cd.address, '')) LIKE %s";
                $args[] = $addressLike;
            }
            if ( $this->columnExists( $detailsTable, 'city' ) ) {
                $clauses[] = "LOWER(COALESCE(cd.city, '')) LIKE %s";
                $args[] = $addressLike;
            }
            if ( $this->columnExists( $detailsTable, 'state' ) ) {
                $clauses[] = "LOWER(COALESCE(cd.state, '')) LIKE %s";
                $args[] = $addressLike;
            }
            if ( $this->columnExists( $detailsTable, 'zip' ) ) {
                $clauses[] = "LOWER(COALESCE(cd.zip, '')) LIKE %s";
                $args[] = $addressLike;
            }
        }

        if ( $clauses === [] ) {
            return [];
        }

        $query = sprintf(
            "SELECT DISTINCT c.%s
             FROM %s c
             LEFT JOIN %s cd
               ON (
                    (cd.contact_id IS NOT NULL AND cd.contact_id = c.id)
                    OR (COALESCE(cd.contact_cid, '') <> '' AND cd.contact_cid = c.cid)
                    OR (COALESCE(cd.did, '') <> '' AND cd.did = c.did)
               )
             WHERE (%s) AND (%s)
             ORDER BY c.id DESC
             LIMIT 15 /* ers:identifier:%s */",
            str_replace( ', ', ', c.', $select ),
            $contactsTable,
            $detailsTable,
            implode( ' OR ', $clauses ),
            $where,
            (string) ( $descriptor['type'] ?? 'contact' )
        );

        return $this->normalizeCandidates( $descriptor, $this->database()->fetchAll( $query, $args ) );
    }

    /**
     * @param array<string, mixed> $descriptor
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private function normalizeCandidates( array $descriptor, array $rows ): array {
        $deduped = [];
        foreach ( $rows as $row ) {
            $id = (int) ( $row['id'] ?? 0 );
            if ( $id < 1 ) {
                continue;
            }

            $name = trim( (string) ( $row['first_name'] ?? '' ) . ' ' . (string) ( $row['last_name'] ?? '' ) );
            if ( $name === '' ) {
                $name = trim( (string) ( $row['display_name'] ?? '' ) );
            }
            if ( $name === '' ) {
                $name = trim( (string) ( $row['email'] ?? '' ) );
            }

            $candidate = [
                'id' => $id,
                'name' => $name,
                'email' => (string) ( $row['email'] ?? '' ),
                'metadata' => [
                    'record' => $row,
                    'entity_type' => (string) ( $descriptor['type'] ?? '' ),
                    'uid' => $this->firstNonEmpty( [
                        (string) ( $row['person_uid'] ?? '' ),
                        (string) ( $row['contact_uid'] ?? '' ),
                        (string) ( $row['donor_uid'] ?? '' ),
                        (string) ( $row['pid'] ?? '' ),
                        (string) ( $row['cid'] ?? '' ),
                        (string) ( $row['did'] ?? '' ),
                    ] ),
                ],
            ];

            $deduped[ $id ] = $candidate;
        }

        return array_values( $deduped );
    }

    private function decide( string $input, string $entityType, array $candidates, string $baseConfidence ): EntityResolutionResult {
        $count = count( $candidates );
        $top = (array) ( $candidates[0] ?? [] );

        if ( $count === 1 && $baseConfidence === self::CONFIDENCE_HIGH ) {
            return new EntityResolutionResult(
                self::STATUS_RESOLVED,
                self::CONFIDENCE_HIGH,
                $entityType,
                $top,
                [],
                sprintf( 'Resolved "%s" to %s (%s).', $input, (string) ( $top['name'] ?? 'entity' ), (string) ( $top['email'] ?? 'no email' ) )
            );
        }

        if ( $count === 1 && $baseConfidence === self::CONFIDENCE_MEDIUM ) {
            return new EntityResolutionResult(
                self::STATUS_AMBIGUOUS,
                self::CONFIDENCE_MEDIUM,
                $entityType,
                $top,
                [ $top ],
                $this->singleCandidateConfirmationMessage( $input, $top )
            );
        }

        $confidence = $baseConfidence === self::CONFIDENCE_LOW || $count > 1
            ? self::CONFIDENCE_LOW
            : $baseConfidence;

        return new EntityResolutionResult(
            self::STATUS_AMBIGUOUS,
            $confidence,
            $entityType,
            $top,
            $candidates,
            $this->multipleCandidateMessage( $input, $candidates )
        );
    }

    private function notFoundResult( string $entityType, string $input, string $prefixMessage = '' ): EntityResolutionResult {
        $base = $prefixMessage !== '' ? rtrim( $prefixMessage, '.' ) . '.' : sprintf( 'I couldn\'t find a match for "%s".', $input );
        $message = $base . "\nTry using:\n- full name\n- email\n- role";

        return new EntityResolutionResult(
            self::STATUS_NOT_FOUND,
            self::CONFIDENCE_NONE,
            $entityType,
            [],
            [],
            $message
        );
    }

    /**
     * @param array<string, mixed> $candidate
     */
    private function singleCandidateConfirmationMessage( string $input, array $candidate ): string {
        return sprintf(
            "I found a close match for \"%s\":\n- %s\nPlease confirm this is the right entity or provide a full name/email.",
            $input,
            $this->candidateLabel( $candidate )
        );
    }

    /**
     * @param array<int, array<string, mixed>> $candidates
     */
    private function multipleCandidateMessage( string $input, array $candidates ): string {
        $lines = array_map( fn ( array $candidate ): string => '- ' . $this->candidateLabel( $candidate ), array_slice( $candidates, 0, 8 ) );
        return sprintf(
            "I found multiple matches for \"%s\":\n%s\nWhich one do you want?",
            $input,
            implode( "\n", $lines )
        );
    }

    /**
     * @param array<string, mixed> $candidate
     */
    private function candidateLabel( array $candidate ): string {
        $name = trim( (string) ( $candidate['name'] ?? '' ) );
        $email = trim( (string) ( $candidate['email'] ?? '' ) );

        if ( $name !== '' && $email !== '' ) {
            return sprintf( '%s <%s>', $name, $email );
        }

        if ( $name !== '' ) {
            return $name;
        }

        if ( $email !== '' ) {
            return $email;
        }

        return sprintf( 'ID %d', (int) ( $candidate['id'] ?? 0 ) );
    }

    /**
     * @param array<string, mixed> $descriptor
     */
    private function descriptor( string $entityType ): array {
        $contactsTable = class_exists( 'Metis_Tables' ) ? \Metis_Tables::get( 'contacts' ) : 'contacts';
        $peopleTable = class_exists( 'Metis_Tables' ) ? \Metis_Tables::get( 'people' ) : 'people';

        return match ( $entityType ) {
            'user' => [
                'type' => 'user',
                'table' => $peopleTable,
                'where' => '1=1',
                'select_columns' => [ 'id', 'pid', 'person_uid', 'email', 'first_name', 'last_name', 'display_name', 'status', 'linked_donor_id' ],
                'exact_columns' => [ 'pid', 'person_uid', 'email', 'display_name' ],
                'partial_columns' => [ 'email', 'display_name', 'first_name', 'last_name' ],
                'alias_columns' => [ 'aliases', 'aliases_json' ],
            ],
            'contact' => [
                'type' => 'contact',
                'table' => $contactsTable,
                'where' => '1=1',
                'select_columns' => [ 'id', 'cid', 'contact_uid', 'did', 'donor_uid', 'email', 'first_name', 'last_name', 'status' ],
                'exact_columns' => [ 'cid', 'contact_uid', 'email' ],
                'partial_columns' => [ 'email', 'first_name', 'last_name' ],
                'alias_columns' => [ 'aliases', 'aliases_json' ],
            ],
            'donor' => [
                'type' => 'donor',
                'table' => $contactsTable,
                'where' => "COALESCE(did, '') <> ''",
                'select_columns' => [ 'id', 'cid', 'contact_uid', 'did', 'donor_uid', 'email', 'first_name', 'last_name', 'status' ],
                'exact_columns' => [ 'did', 'donor_uid', 'email' ],
                'partial_columns' => [ 'did', 'email', 'first_name', 'last_name' ],
                'alias_columns' => [ 'aliases', 'aliases_json' ],
            ],
            default => throw new \InvalidArgumentException( sprintf( 'Unsupported built-in entity type [%s].', $entityType ) ),
        };
    }

    /**
     * @param array<string, mixed> $descriptor
     */
    private function selectClause( array $descriptor ): string {
        $table = (string) ( $descriptor['table'] ?? '' );
        $columns = (array) ( $descriptor['select_columns'] ?? [] );
        if ( $table === '' || $columns === [] ) {
            return 'id';
        }

        $safe = [];
        foreach ( $columns as $column ) {
            if ( is_string( $column ) && $column !== '' && $this->columnExists( $table, $column ) ) {
                $safe[] = $column;
            }
        }

        if ( ! in_array( 'id', $safe, true ) ) {
            $safe[] = 'id';
        }

        return implode( ', ', array_values( array_unique( $safe ) ) );
    }

    private function ensureSchema( string $entityType ): void {
        static $ensured = [];

        if ( ! empty( $ensured[ $entityType ] ) ) {
            return;
        }

        if ( $entityType === 'user' ) {
            PeopleSchemaManager::ensureSchema();
        }

        if ( $entityType === 'contact' || $entityType === 'donor' ) {
            ContactsSchemaManager::ensureSchema();
        }

        $ensured[ $entityType ] = true;
    }

    private function database(): DatabaseService {
        if ( $this->db instanceof DatabaseService ) {
            return $this->db;
        }
        return function_exists( 'metis_resolve_db_service' ) ? \metis_resolve_db_service() : new DatabaseService();
    }

    private function columnExists( string $table, string $column ): bool {
        if ( isset( $this->columnCache[ $table ][ $column ] ) ) {
            return $this->columnCache[ $table ][ $column ];
        }

        try {
            $exists = (bool) $this->database()->scalar( "SHOW COLUMNS FROM {$table} LIKE %s", [ $column ] );
        } catch ( \Throwable ) {
            $exists = false;
        }

        $this->columnCache[ $table ][ $column ] = $exists;
        return $exists;
    }

    private function tableExists( string $table ): bool {
        try {
            $exists = $this->database()->scalar( 'SHOW TABLES LIKE %s', [ $table ] );
            return $exists === $table;
        } catch ( \Throwable ) {
            return false;
        }
    }

    /**
     * @return array{first: string, last: string}|null
     */
    private function nameParts( string $input ): ?array {
        $parts = preg_split( '/\s+/', trim( $input ) ) ?: [];
        if ( count( $parts ) < 2 ) {
            return null;
        }

        return [
            'first' => (string) $parts[0],
            'last' => (string) $parts[count( $parts ) - 1],
        ];
    }

    /**
     * @return array<int, string>
     */
    private function decodeAliasValues( string $raw ): array {
        $raw = trim( $raw );
        if ( $raw === '' ) {
            return [];
        }

        $decoded = json_decode( $raw, true );
        if ( ! is_array( $decoded ) ) {
            return [];
        }

        $values = [];
        foreach ( $decoded as $value ) {
            if ( is_string( $value ) ) {
                $value = trim( $value );
                if ( $value !== '' ) {
                    $values[] = $value;
                }
            }
        }

        return $values;
    }

    /**
     * @param array<string, mixed> $sessionEntities
     */
    private function resolveFromSessionContext( string $input, string $entityType, array $sessionEntities ): ?EntityResolutionResult {
        if ( $sessionEntities === [] ) {
            return null;
        }

        $needle = strtolower( trim( $input ) );
        if ( $needle === '' ) {
            return null;
        }

        $entries = [];

        $typed = $sessionEntities[ $entityType ] ?? null;
        if ( is_array( $typed ) ) {
            $entries = array_merge( $entries, $this->normalizeSessionEntries( $typed, $entityType ) );
        }

        $entries = array_merge( $entries, $this->normalizeSessionEntries( $sessionEntities, $entityType ) );

        foreach ( $entries as $entry ) {
            $aliases = array_map( static fn ( string $value ): string => strtolower( trim( $value ) ), array_filter( [
                (string) ( $entry['name'] ?? '' ),
                (string) ( $entry['email'] ?? '' ),
                (string) ( $entry['shorthand'] ?? '' ),
                ...array_filter( array_map( 'strval', (array) ( $entry['aliases'] ?? [] ) ) ),
            ] ) );

            if ( in_array( $needle, $aliases, true ) ) {
                $candidate = [
                    'id' => (int) ( $entry['id'] ?? 0 ),
                    'name' => (string) ( $entry['name'] ?? '' ),
                    'email' => (string) ( $entry['email'] ?? '' ),
                    'metadata' => (array) ( $entry['metadata'] ?? [] ),
                ];

                return new EntityResolutionResult(
                    self::STATUS_RESOLVED,
                    self::CONFIDENCE_HIGH,
                    $entityType,
                    $candidate,
                    [],
                    sprintf( 'Resolved "%s" from session context as %s.', $input, $this->candidateLabel( $candidate ) )
                );
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $source
     * @return array<int, array<string, mixed>>
     */
    private function normalizeSessionEntries( array $source, string $entityType ): array {
        if ( isset( $source['id'] ) || isset( $source['name'] ) ) {
            $source = [ $source ];
        }

        $entries = [];
        foreach ( $source as $entry ) {
            if ( ! is_array( $entry ) ) {
                continue;
            }

            $entryType = strtolower( trim( (string) ( $entry['entity_type'] ?? $entityType ) ) );
            if ( $entryType !== $entityType ) {
                continue;
            }

            $entries[] = $entry;
        }

        return $entries;
    }

    /**
     * @param array<int, string> $values
     */
    private function firstNonEmpty( array $values ): string {
        foreach ( $values as $value ) {
            $value = trim( $value );
            if ( $value !== '' ) {
                return $value;
            }
        }

        return '';
    }
}
