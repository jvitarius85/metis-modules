<?php
declare(strict_types=1);

if ( PHP_SAPI !== 'cli' ) {
    fwrite( STDERR, "This tool must be run from the command line.\n" );
    exit( 1 );
}

$root = dirname( __DIR__ );

define( 'METIS_STANDALONE', true );
define( 'METIS_PREFIX', 'metis' );
define( 'METIS_PATH', dirname( $root ) . '/' );
define( 'METIS_URL', 'http://localhost/metis/' );

$_SERVER['HTTP_HOST'] = $_SERVER['HTTP_HOST'] ?? 'localhost';
$_SERVER['HTTPS'] = $_SERVER['HTTPS'] ?? 'off';
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = '/';

require_once $root . '/src/Metis/Core/CoreBootstrap.php';
metis_define_system_version( dirname( $root ) . '/' );
metis_core_bootstrap( 'standalone_bootstrap' );

$options = parse_cli_options( $argv );
if ( ! empty( $options['help'] ) ) {
    print_help();
    exit( 0 );
}

$dump_path = (string) ( $options['dump'] ?? '' );
if ( $dump_path === '' ) {
    fwrite( STDERR, "Missing required --dump=/path/to/database.sql[.gz]\n" );
    print_help();
    exit( 1 );
}

if ( ! is_file( $dump_path ) || ! is_readable( $dump_path ) ) {
    fwrite( STDERR, "Dump file is not readable: {$dump_path}\n" );
    exit( 1 );
}

$apply = ! empty( $options['apply'] );
$dry_run = ! $apply || ! empty( $options['dry-run'] );
$debug = ! empty( $options['debug'] );

try {
    if ( ! function_exists( 'metis_standalone_has_database_config' ) || ! metis_standalone_has_database_config() ) {
        fwrite( STDERR, 'Missing database config at ' . metis_standalone_database_config_path() . PHP_EOL );
        exit( 1 );
    }

    metis_standalone_boot();

    /** @var \Metis\Services\DatabaseService $db */
    $db = metis_db();

    $transactions_table = \Metis_Tables::get( 'transactions' );
    $campaigns_table = \Metis_Tables::get( 'campaigns' );
    $contacts_table = \Metis_Tables::get( 'contacts' );
    $batches_table = \Metis_Tables::get( 'batches' );
    $deposits_table = \Metis_Tables::get( 'deposits' );

    $summary = [
        'live_backfill' => [ 'campaign_code' => 0, 'did' => 0, 'deposit_batch_id' => 0 ],
        'dump_rows' => [ 'campaigns' => 0, 'contacts' => 0, 'batches' => 0, 'deposits' => 0, 'transactions' => 0 ],
        'updates' => [ 'campaign_code' => 0, 'did' => 0, 'deposit_batch_id' => 0, 'rows_changed' => 0 ],
    ];

    // Run the in-database backfill first for fast-path updates.
    if ( class_exists( '\Metis\Modules\Donations\DonationsModule' ) ) {
        $live = \Metis\Modules\Donations\DonationsModule::backfillTransactionEntityReferences();
        if ( is_array( $live ) ) {
            $summary['live_backfill']['campaign_code'] = (int) ( $live['campaign_code'] ?? 0 );
            $summary['live_backfill']['did'] = (int) ( $live['did'] ?? 0 );
            $summary['live_backfill']['deposit_batch_id'] = (int) ( $live['deposit_batch_id'] ?? 0 );
        }
    }

    $legacy = parse_legacy_dump( $dump_path );
    $summary['dump_rows'] = [
        'campaigns' => count( $legacy['campaign_ref_to_id'] ),
        'contacts' => count( $legacy['contact_ref_to_id'] ),
        'batches' => count( $legacy['batch_ref_to_id'] ),
        'deposits' => count( $legacy['deposit_ref_to_id'] ),
        'transactions' => count( $legacy['transactions'] ),
    ];

    $campaign_canonical_by_id = load_canonical_map( $db, $campaigns_table, 'id', 'campaign_uid', 'cid' );
    $donor_canonical_by_id = load_canonical_map( $db, $contacts_table, 'id', 'donor_uid', 'did' );
    $batch_canonical_by_id = load_canonical_map( $db, $batches_table, 'id', 'batch_uid', 'batch_code' );
    $deposit_canonical_by_id = load_canonical_map( $db, $deposits_table, 'id', 'deposit_uid', 'provider_ref' );

    $candidate_updates = build_candidate_updates(
        $legacy['transactions'],
        $legacy['campaign_ref_to_id'],
        $legacy['contact_ref_to_id'],
        $legacy['batch_ref_to_id'],
        $legacy['deposit_ref_to_id'],
        $campaign_canonical_by_id,
        $donor_canonical_by_id,
        $batch_canonical_by_id,
        $deposit_canonical_by_id
    );

    if ( $debug ) {
        emit_debug_summary( $db, $transactions_table, $legacy['transactions'], $candidate_updates );
    }

    $rows_changed = 0;
    foreach ( $candidate_updates as $update ) {
        $changed = apply_or_count_transaction_update( $db, $transactions_table, $update, $dry_run );
        if ( ! $changed ) {
            continue;
        }

        $rows_changed++;
        if ( ! empty( $update['campaign_new'] ) ) {
            $summary['updates']['campaign_code']++;
        }
        if ( ! empty( $update['did_new'] ) ) {
            $summary['updates']['did']++;
        }
        if ( ! empty( $update['batch_new'] ) ) {
            $summary['updates']['deposit_batch_id']++;
        }
    }

    $summary['updates']['rows_changed'] = $rows_changed;

    echo $dry_run ? "Dump-assisted transaction ref backfill dry run complete.\n" : "Dump-assisted transaction ref backfill complete.\n";
    echo "Dump: {$dump_path}\n";
    echo 'Live backfill campaign_code: ' . (int) $summary['live_backfill']['campaign_code'] . "\n";
    echo 'Live backfill did: ' . (int) $summary['live_backfill']['did'] . "\n";
    echo 'Live backfill deposit_batch_id: ' . (int) $summary['live_backfill']['deposit_batch_id'] . "\n";
    echo 'Parsed legacy campaign refs: ' . (int) $summary['dump_rows']['campaigns'] . "\n";
    echo 'Parsed legacy contact refs: ' . (int) $summary['dump_rows']['contacts'] . "\n";
    echo 'Parsed legacy batch refs: ' . (int) $summary['dump_rows']['batches'] . "\n";
    echo 'Parsed legacy deposit refs: ' . (int) $summary['dump_rows']['deposits'] . "\n";
    echo 'Parsed legacy transactions: ' . (int) $summary['dump_rows']['transactions'] . "\n";
    echo 'Transaction rows changed: ' . (int) $summary['updates']['rows_changed'] . "\n";
    echo 'Updated campaign_code values: ' . (int) $summary['updates']['campaign_code'] . "\n";
    echo 'Updated did values: ' . (int) $summary['updates']['did'] . "\n";
    echo 'Updated deposit_batch_id values: ' . (int) $summary['updates']['deposit_batch_id'] . "\n";
} catch ( \Throwable $e ) {
    fwrite( STDERR, 'backfill_transaction_refs_from_dump failed: ' . $e->getMessage() . PHP_EOL );
    exit( 1 );
}

/**
 * @return array<string,mixed>
 */
function parse_cli_options( array $argv ): array {
    $options = [];
    foreach ( $argv as $arg ) {
        if ( $arg === '--help' || $arg === '-h' ) {
            $options['help'] = true;
            continue;
        }
        if ( $arg === '--apply' ) {
            $options['apply'] = true;
            continue;
        }
        if ( $arg === '--dry-run' ) {
            $options['dry-run'] = true;
            continue;
        }
        if ( $arg === '--debug' ) {
            $options['debug'] = true;
            continue;
        }
        if ( str_starts_with( $arg, '--dump=' ) ) {
            $options['dump'] = substr( $arg, 7 );
        }
    }
    return $options;
}

function print_help(): void {
    echo "Usage: php tools/backfill_transaction_refs_from_dump.php --dump=/path/to/database.sql[.gz] [--apply] [--dry-run] [--debug]\n";
    echo "  --dump     Path to SQL backup (supports .sql and .sql.gz).\n";
    echo "  --apply    Persist changes. Without this flag, runs as dry-run.\n";
    echo "  --dry-run  Force dry-run mode.\n";
    echo "  --debug    Print matching diagnostics.\n";
}

/**
 * @param array<int,array<string,mixed>> $legacy_transactions
 * @param array<int,array<string,mixed>> $candidate_updates
 */
function emit_debug_summary(
    \Metis\Services\DatabaseService $db,
    string $transactions_table,
    array $legacy_transactions,
    array $candidate_updates
): void {
    $ids = [];
    $tids = [];
    $id_min = null;
    $id_max = null;
    foreach ( $legacy_transactions as $tx ) {
        $id = (int) ( $tx['id'] ?? 0 );
        $tid = (string) ( $tx['tid'] ?? '' );
        if ( $id > 0 ) {
            $ids[] = $id;
            $id_min = $id_min === null ? $id : min( $id_min, $id );
            $id_max = $id_max === null ? $id : max( $id_max, $id );
        }
        if ( $tid !== '' ) {
            $tids[] = $tid;
        }
    }
    $ids = array_values( array_unique( $ids ) );
    $tids = array_values( array_unique( $tids ) );

    $current_range = $db->fetchOne( "SELECT MIN(id) AS min_id, MAX(id) AS max_id, COUNT(*) AS c FROM {$transactions_table}" );
    $existing_id_matches = count_existing_ids( $db, $transactions_table, $ids );
    $existing_tid_matches = count_existing_tids( $db, $transactions_table, $tids );

    $candidate_campaign = 0;
    $candidate_donor = 0;
    $candidate_batch = 0;
    foreach ( $candidate_updates as $candidate ) {
        if ( ! empty( $candidate['campaign_new'] ) ) {
            $candidate_campaign++;
        }
        if ( ! empty( $candidate['did_new'] ) ) {
            $candidate_donor++;
        }
        if ( ! empty( $candidate['batch_new'] ) ) {
            $candidate_batch++;
        }
    }

    echo '--- debug ---' . PHP_EOL;
    echo 'Legacy tx id range: ' . ( $id_min ?? 0 ) . ' to ' . ( $id_max ?? 0 ) . PHP_EOL;
    echo 'Current tx id range: ' . (int) ( $current_range['min_id'] ?? 0 ) . ' to ' . (int) ( $current_range['max_id'] ?? 0 ) . PHP_EOL;
    echo 'Legacy tx ids present in current table: ' . $existing_id_matches . ' / ' . count( $ids ) . PHP_EOL;
    echo 'Legacy tx tids present in current table: ' . $existing_tid_matches . ' / ' . count( $tids ) . PHP_EOL;
    echo 'Candidates built: ' . count( $candidate_updates ) . PHP_EOL;
    echo 'Candidates with campaign updates: ' . $candidate_campaign . PHP_EOL;
    echo 'Candidates with donor updates: ' . $candidate_donor . PHP_EOL;
    echo 'Candidates with batch updates: ' . $candidate_batch . PHP_EOL;
}

/**
 * @param array<int,int> $ids
 */
function count_existing_ids( \Metis\Services\DatabaseService $db, string $transactions_table, array $ids ): int {
    if ( $ids === [] ) {
        return 0;
    }
    $chunks = array_chunk( $ids, 500 );
    $count = 0;
    foreach ( $chunks as $chunk ) {
        $placeholders = implode( ',', array_fill( 0, count( $chunk ), '%d' ) );
        $sql = "SELECT COUNT(*) FROM {$transactions_table} WHERE id IN ({$placeholders})";
        $count += (int) $db->scalar( $sql, $chunk );
    }
    return $count;
}

/**
 * @param array<int,string> $tids
 */
function count_existing_tids( \Metis\Services\DatabaseService $db, string $transactions_table, array $tids ): int {
    if ( $tids === [] ) {
        return 0;
    }
    $chunks = array_chunk( $tids, 500 );
    $count = 0;
    foreach ( $chunks as $chunk ) {
        $placeholders = implode( ',', array_fill( 0, count( $chunk ), '%s' ) );
        $sql = "SELECT COUNT(*) FROM {$transactions_table} WHERE tid IN ({$placeholders})";
        $count += (int) $db->scalar( $sql, $chunk );
    }
    return $count;
}

/**
 * @return array{
 *   campaign_ref_to_id: array<string,int>,
 *   contact_ref_to_id: array<string,int>,
 *   batch_ref_to_id: array<string,int>,
 *   deposit_ref_to_id: array<string,int>,
 *   transactions: array<int,array<string,mixed>>
 * }
 */
function parse_legacy_dump( string $dump_path ): array {
    $campaign_ref_to_id = [];
    $contact_ref_to_id = [];
    $batch_ref_to_id = [];
    $deposit_ref_to_id = [];
    $transactions = [];

    $handle = open_dump_stream( $dump_path );
    if ( $handle === false ) {
        throw new \RuntimeException( 'Unable to open dump stream.' );
    }

    $statement = '';
    while ( ( $line = dump_get_line( $handle, $dump_path ) ) !== false ) {
        if ( $statement === '' ) {
            if ( stripos( $line, 'INSERT INTO' ) === false ) {
                continue;
            }
            $statement = $line;
            if ( str_contains( $line, ';' ) ) {
                process_insert_statement( $statement, $campaign_ref_to_id, $contact_ref_to_id, $batch_ref_to_id, $deposit_ref_to_id, $transactions );
                $statement = '';
            }
            continue;
        }

        $statement .= $line;
        if ( str_contains( $line, ';' ) ) {
            process_insert_statement( $statement, $campaign_ref_to_id, $contact_ref_to_id, $batch_ref_to_id, $deposit_ref_to_id, $transactions );
            $statement = '';
        }
    }

    close_dump_stream( $handle, $dump_path );

    return [
        'campaign_ref_to_id' => $campaign_ref_to_id,
        'contact_ref_to_id' => $contact_ref_to_id,
        'batch_ref_to_id' => $batch_ref_to_id,
        'deposit_ref_to_id' => $deposit_ref_to_id,
        'transactions' => $transactions,
    ];
}

/**
 * @param resource $handle
 */
function dump_get_line( $handle, string $path ): string|false {
    if ( str_ends_with( strtolower( $path ), '.gz' ) ) {
        return gzgets( $handle );
    }
    return fgets( $handle );
}

/**
 * @return resource|false
 */
function open_dump_stream( string $path ) {
    if ( str_ends_with( strtolower( $path ), '.gz' ) ) {
        return gzopen( $path, 'rb' );
    }
    return fopen( $path, 'rb' );
}

/**
 * @param resource $handle
 */
function close_dump_stream( $handle, string $path ): void {
    if ( str_ends_with( strtolower( $path ), '.gz' ) ) {
        gzclose( $handle );
        return;
    }
    fclose( $handle );
}

/**
 * @param array<string,int> $campaign_ref_to_id
 * @param array<string,int> $contact_ref_to_id
 * @param array<string,int> $batch_ref_to_id
 * @param array<string,int> $deposit_ref_to_id
 * @param array<int,array<string,mixed>> $transactions
 */
function process_insert_statement(
    string $statement,
    array &$campaign_ref_to_id,
    array &$contact_ref_to_id,
    array &$batch_ref_to_id,
    array &$deposit_ref_to_id,
    array &$transactions
): void {
    if ( ! preg_match( '/INSERT INTO\s+`?([a-zA-Z0-9_]+)`?\s*\((.*?)\)\s*VALUES\s*(.*)\s*;\s*$/is', $statement, $m ) ) {
        return;
    }

    $table = strtolower( trim( $m[1] ) );
    $columns_raw = $m[2];
    $values_raw = $m[3];

    if (
        ! str_ends_with( $table, '_campaigns' )
        && ! str_ends_with( $table, '_contacts' )
        && ! str_ends_with( $table, '_batches' )
        && ! str_ends_with( $table, '_deposits' )
        && ! str_ends_with( $table, '_transactions' )
    ) {
        return;
    }

    $columns = parse_column_list( $columns_raw );
    $tuples = parse_values_tuples( $values_raw );

    foreach ( $tuples as $tuple_raw ) {
        $values = parse_tuple_values( $tuple_raw );
        if ( count( $values ) !== count( $columns ) ) {
            continue;
        }

        $row = [];
        foreach ( $columns as $idx => $column ) {
            $row[ $column ] = $values[ $idx ] ?? null;
        }

        if ( str_ends_with( $table, '_campaigns' ) ) {
            $id = as_int( $row['id'] ?? null );
            if ( $id <= 0 ) {
                continue;
            }
            add_ref_map( $campaign_ref_to_id, (string) ( $row['cid'] ?? '' ), $id );
            add_ref_map( $campaign_ref_to_id, (string) ( $row['campaign_uid'] ?? '' ), $id );
            continue;
        }

        if ( str_ends_with( $table, '_contacts' ) ) {
            $id = as_int( $row['id'] ?? null );
            if ( $id <= 0 ) {
                continue;
            }
            add_ref_map( $contact_ref_to_id, (string) ( $row['did'] ?? '' ), $id );
            add_ref_map( $contact_ref_to_id, (string) ( $row['donor_uid'] ?? '' ), $id );
            add_ref_map( $contact_ref_to_id, (string) ( $row['cid'] ?? '' ), $id );
            add_ref_map( $contact_ref_to_id, (string) ( $row['contact_uid'] ?? '' ), $id );
            continue;
        }

        if ( str_ends_with( $table, '_batches' ) ) {
            $id = as_int( $row['id'] ?? null );
            if ( $id <= 0 ) {
                continue;
            }
            add_ref_map( $batch_ref_to_id, (string) ( $row['batch_code'] ?? '' ), $id );
            add_ref_map( $batch_ref_to_id, (string) ( $row['batch_uid'] ?? '' ), $id );
            continue;
        }

        if ( str_ends_with( $table, '_deposits' ) ) {
            $id = as_int( $row['id'] ?? null );
            if ( $id <= 0 ) {
                continue;
            }
            add_ref_map( $deposit_ref_to_id, (string) ( $row['provider_ref'] ?? '' ), $id );
            add_ref_map( $deposit_ref_to_id, (string) ( $row['deposit_uid'] ?? '' ), $id );
            continue;
        }

        if ( str_ends_with( $table, '_transactions' ) ) {
            $transactions[] = [
                'id' => as_int( $row['id'] ?? null ),
                'tid' => normalize_ref( (string) ( $row['tid'] ?? '' ) ),
                'campaign_code' => normalize_ref( (string) ( $row['campaign_code'] ?? '' ) ),
                'did' => normalize_ref( (string) ( $row['did'] ?? '' ) ),
                'deposit_batch_id' => normalize_ref( (string) ( $row['deposit_batch_id'] ?? '' ) ),
            ];
        }
    }
}

/**
 * @return array<int,array<string,mixed>>
 */
function build_candidate_updates(
    array $transactions,
    array $campaign_ref_to_id,
    array $contact_ref_to_id,
    array $batch_ref_to_id,
    array $deposit_ref_to_id,
    array $campaign_canonical_by_id,
    array $donor_canonical_by_id,
    array $batch_canonical_by_id,
    array $deposit_canonical_by_id
): array {
    $updates = [];

    foreach ( $transactions as $tx ) {
        $campaign_old = (string) ( $tx['campaign_code'] ?? '' );
        $donor_old = (string) ( $tx['did'] ?? '' );
        $batch_old = (string) ( $tx['deposit_batch_id'] ?? '' );

        $campaign_new = '';
        if ( $campaign_old !== '' && isset( $campaign_ref_to_id[ $campaign_old ] ) ) {
            $campaign_new = (string) ( $campaign_canonical_by_id[ $campaign_ref_to_id[ $campaign_old ] ] ?? '' );
        }

        $donor_new = '';
        if ( $donor_old !== '' && isset( $contact_ref_to_id[ $donor_old ] ) ) {
            $donor_new = (string) ( $donor_canonical_by_id[ $contact_ref_to_id[ $donor_old ] ] ?? '' );
        }

        $batch_new = '';
        if ( $batch_old !== '' && isset( $batch_ref_to_id[ $batch_old ] ) ) {
            $batch_new = (string) ( $batch_canonical_by_id[ $batch_ref_to_id[ $batch_old ] ] ?? '' );
        } elseif ( $batch_old !== '' && isset( $deposit_ref_to_id[ $batch_old ] ) ) {
            // Legacy DP* references in transactions often map to deposits.provider_ref.
            $batch_new = (string) ( $deposit_canonical_by_id[ $deposit_ref_to_id[ $batch_old ] ] ?? '' );
        }

        if ( $campaign_new === $campaign_old ) {
            $campaign_new = '';
        }
        if ( $donor_new === $donor_old ) {
            $donor_new = '';
        }
        if ( $batch_new === $batch_old ) {
            $batch_new = '';
        }

        if ( $campaign_new === '' && $donor_new === '' && $batch_new === '' ) {
            continue;
        }

        $tid = (string) ( $tx['tid'] ?? '' );
        $id = (int) ( $tx['id'] ?? 0 );
        if ( $tid === '' && $id <= 0 ) {
            continue;
        }

        $updates[] = [
            'id' => $id,
            'tid' => $tid,
            'campaign_old' => $campaign_old,
            'campaign_new' => $campaign_new,
            'did_old' => $donor_old,
            'did_new' => $donor_new,
            'batch_old' => $batch_old,
            'batch_new' => $batch_new,
        ];
    }

    return $updates;
}

/**
 * @return array<int,string>
 */
function parse_column_list( string $columns_raw ): array {
    $parts = explode( ',', $columns_raw );
    $columns = [];
    foreach ( $parts as $part ) {
        $columns[] = trim( trim( $part ), " \t\n\r\0\x0B`" );
    }
    return $columns;
}

/**
 * @return array<int,string>
 */
function parse_values_tuples( string $values_raw ): array {
    $tuples = [];
    $len = strlen( $values_raw );
    $in_quote = false;
    $escaped = false;
    $depth = 0;
    $start = -1;

    for ( $i = 0; $i < $len; $i++ ) {
        $ch = $values_raw[ $i ];

        if ( $in_quote ) {
            if ( $escaped ) {
                $escaped = false;
                continue;
            }
            if ( $ch === '\\' ) {
                $escaped = true;
                continue;
            }
            if ( $ch === "'" ) {
                $in_quote = false;
            }
            continue;
        }

        if ( $ch === "'" ) {
            $in_quote = true;
            continue;
        }

        if ( $ch === '(' ) {
            if ( $depth === 0 ) {
                $start = $i + 1;
            }
            $depth++;
            continue;
        }

        if ( $ch === ')' ) {
            $depth--;
            if ( $depth === 0 && $start >= 0 ) {
                $tuples[] = substr( $values_raw, $start, $i - $start );
                $start = -1;
            }
        }
    }

    return $tuples;
}

/**
 * @return array<int,mixed>
 */
function parse_tuple_values( string $tuple_raw ): array {
    $values = [];
    $len = strlen( $tuple_raw );
    $in_quote = false;
    $escaped = false;
    $token = '';

    for ( $i = 0; $i < $len; $i++ ) {
        $ch = $tuple_raw[ $i ];

        if ( $in_quote ) {
            $token .= $ch;
            if ( $escaped ) {
                $escaped = false;
                continue;
            }
            if ( $ch === '\\' ) {
                $escaped = true;
                continue;
            }
            if ( $ch === "'" ) {
                $in_quote = false;
            }
            continue;
        }

        if ( $ch === "'" ) {
            $in_quote = true;
            $token .= $ch;
            continue;
        }

        if ( $ch === ',' ) {
            $values[] = decode_sql_literal( trim( $token ) );
            $token = '';
            continue;
        }

        $token .= $ch;
    }

    $values[] = decode_sql_literal( trim( $token ) );
    return $values;
}

function decode_sql_literal( string $raw ): mixed {
    if ( strtoupper( $raw ) === 'NULL' ) {
        return null;
    }

    $len = strlen( $raw );
    if ( $len >= 2 && $raw[0] === "'" && $raw[ $len - 1 ] === "'" ) {
        $inner = substr( $raw, 1, -1 );
        $inner = str_replace( [ "\\'", "\\\\" ], [ "'", "\\" ], $inner );
        return $inner;
    }

    if ( is_numeric( $raw ) ) {
        return (string) $raw;
    }

    return $raw;
}

function normalize_ref( string $value ): string {
    $value = trim( $value );
    if ( $value === '' || strtoupper( $value ) === 'NULL' ) {
        return '';
    }
    return $value;
}

function as_int( mixed $value ): int {
    if ( $value === null || $value === '' ) {
        return 0;
    }
    return (int) $value;
}

/**
 * @param array<string,int> $map
 */
function add_ref_map( array &$map, string $value, int $id ): void {
    $key = normalize_ref( $value );
    if ( $key === '' || $id <= 0 ) {
        return;
    }
    $map[ $key ] = $id;
}

/**
 * @return array<int,string>
 */
function load_canonical_map(
    \Metis\Services\DatabaseService $db,
    string $table,
    string $id_column,
    string $uid_column,
    string $legacy_column
): array {
    $rows = $db->fetchAll(
        "SELECT {$id_column} AS id, COALESCE(NULLIF({$uid_column}, ''), {$legacy_column}) AS canonical FROM {$table}"
    );

    $map = [];
    foreach ( $rows as $row ) {
        $id = as_int( $row['id'] ?? null );
        $canonical = normalize_ref( (string) ( $row['canonical'] ?? '' ) );
        if ( $id <= 0 || $canonical === '' ) {
            continue;
        }
        $map[ $id ] = $canonical;
    }

    return $map;
}

/**
 * @param array<string,mixed> $update
 */
function apply_or_count_transaction_update(
    \Metis\Services\DatabaseService $db,
    string $transactions_table,
    array $update,
    bool $dry_run
): bool {
    $set = [];
    $where = [];
    $set_params = [];
    $where_params = [];

    if ( ! empty( $update['campaign_new'] ) && ! empty( $update['campaign_old'] ) ) {
        $set[] = 'campaign_code = %s';
        $where[] = 'campaign_code = %s';
        $set_params[] = (string) $update['campaign_new'];
        $where_params[] = (string) $update['campaign_old'];
    }

    if ( ! empty( $update['did_new'] ) && ! empty( $update['did_old'] ) ) {
        $set[] = 'did = %s';
        $where[] = 'did = %s';
        $set_params[] = (string) $update['did_new'];
        $where_params[] = (string) $update['did_old'];
    }

    if ( ! empty( $update['batch_new'] ) && ! empty( $update['batch_old'] ) ) {
        $set[] = 'deposit_batch_id = %s';
        $where[] = 'deposit_batch_id = %s';
        $set_params[] = (string) $update['batch_new'];
        $where_params[] = (string) $update['batch_old'];
    }

    if ( $set === [] ) {
        return false;
    }

    $key_sql = '';
    // Prefer numeric transaction id because legacy dumps may have pre-migration tid values.
    $key_params = [];
    if ( (int) ( $update['id'] ?? 0 ) > 0 ) {
        $key_sql = 'id = %d';
        $key_params[] = (int) $update['id'];
    } elseif ( ! empty( $update['tid'] ) ) {
        $key_sql = 'tid = %s';
        $key_params[] = (string) $update['tid'];
    } else {
        return false;
    }

    $sql = sprintf(
        'UPDATE %s SET %s WHERE %s AND (%s)',
        $transactions_table,
        implode( ', ', $set ),
        $key_sql,
        implode( ' OR ', $where )
    );

    $params = array_merge( $set_params, $key_params, $where_params );
    $prepared = $db->prepare( $sql, ...$params );
    if ( ! is_string( $prepared ) || $prepared === '' ) {
        return false;
    }

    if ( $dry_run ) {
        $check_sql = preg_replace( '/^UPDATE\s+.+?\s+SET\s+.+?\s+WHERE\s+/is', 'SELECT COUNT(*) FROM ' . $transactions_table . ' WHERE ', $prepared, 1 );
        if ( ! is_string( $check_sql ) || $check_sql === '' ) {
            return false;
        }
        return (int) $db->scalar( $check_sql ) > 0;
    }

    $result = $db->execute( $prepared );
    return is_numeric( $result ) && (int) $result > 0;
}
