<?php
if ( ! defined( 'METIS_ROOT' ) ) exit;

/**
 * Metis Table Rename Utility
 *
 * Renames legacy prefixed metis tables to their metis_* equivalents.
 *
 * Trigger (admin only):
 *   yoursite.com/admin/?metis_rename_tables=1
 *
 * Safe to run multiple times:
 *   - Tables already renamed are skipped
 *   - Tables that don't exist are skipped
 *   - Each rename is logged individually
 *
 * After running, verify at:
 *   yoursite.com/admin/?metis_rename_tables=verify
 */

// -------------------------------------------------------------------------
// Rename map: prefixed source table => canonical target table
// Source names are legacy prefixed tables; target names are canonical
// unprefixed metis_* tables.
// -------------------------------------------------------------------------

function metis_get_rename_map(): array {
    $map = [];
    $prefix = (string) ( metis_db()->connection()->prefix ?? '' );

    foreach ( Metis_Tables::definitions() as $bare_name ) {
        $map[ $prefix . $bare_name ] = $bare_name;
    }

    return $map;
}

function metis_rename_tables_maintenance(): \Metis\Core\Services\MaintenanceService {
    if ( \Metis\Core\Application::has_service( 'maintenance' ) ) {
        return \Metis\Core\Application::service( 'maintenance' );
    }

    return new \Metis\Core\Services\MaintenanceService();
}

function metis_rename_tables_mutation_mode( string $mode ): bool {
    return in_array( $mode, [ '1', 'run', 'drop_old', 'resolve' ], true );
}

// -------------------------------------------------------------------------
// Rename runner
// -------------------------------------------------------------------------

function metis_run_table_renames(): array {
    $db      = metis_db();
    $map     = metis_get_rename_map();
    $renamed = [];
    $skipped = [];
    $errors  = [];

    // Fetch all existing tables in the DB once — avoids N SHOW TABLES queries
    $existing = $db->column( "SHOW TABLES" );
    $existing = array_flip( $existing ); // key lookup O(1)

    foreach ( $map as $old => $new ) {

        // Old table doesn't exist — nothing to rename
        if ( ! isset( $existing[ $old ] ) ) {
            $skipped[] = "{$old} (not found)";
            Metis_Logger::info( "Table rename skipped — source not found: {$old}" );
            continue;
        }

        // Target already exists — already renamed or collision
        if ( isset( $existing[ $new ] ) ) {
            $skipped[] = "{$old} → {$new} (target already exists)";
            Metis_Logger::info( "Table rename skipped — target already exists: {$new}" );
            continue;
        }

        // Execute rename
        $result = $db->execute( "RENAME TABLE `{$old}` TO `{$new}`" );

        if ( $result === false ) {
            $error    = (string) ( $db->connection()->last_error ?? '' );
            $error    = $error !== '' ? $error : 'Unknown error';
            $errors[] = "{$old} → {$new}: {$error}";
            Metis_Logger::error( "Table rename failed: {$old} → {$new}", [ 'error' => $error ] );
        } else {
            $renamed[] = "{$old} → {$new}";
            Metis_Logger::info( "Table renamed: {$old} → {$new}" );
        }
    }

    return compact( 'renamed', 'skipped', 'errors' );
}

// -------------------------------------------------------------------------
// Verify runner — reports current state without making changes
// -------------------------------------------------------------------------

function metis_verify_table_renames(): array {
    $map      = metis_get_rename_map();
    $existing = array_flip( metis_db()->column( "SHOW TABLES" ) );

    $complete  = [];
    $pending   = [];
    $collision = [];

    foreach ( $map as $old => $new ) {

        $old_exists = isset( $existing[ $old ] );
        $new_exists = isset( $existing[ $new ] );

        if ( $new_exists && ! $old_exists ) {
            $complete[] = $new;
        } elseif ( $old_exists && $new_exists ) {
            $collision[] = "{$old} AND {$new} both exist";
        } elseif ( $old_exists && ! $new_exists ) {
            $pending[] = "{$old} → {$new}";
        }
        // neither exists = table never created, not tracked here
    }

    return compact( 'complete', 'pending', 'collision' );
}

// -------------------------------------------------------------------------
// Admin URL trigger
// -------------------------------------------------------------------------

metis_on( 'metis_admin_init', function () {

    if ( ! current_user_can( 'manage_options' ) ) return;
    if ( ! isset( $_GET['metis_rename_tables'] ) && ! isset( $_POST['metis_rename_tables'] ) ) return;

    $mode = sanitize_key( (string) ( $_POST['metis_rename_tables'] ?? $_GET['metis_rename_tables'] ) );

    if ( metis_rename_tables_mutation_mode( $mode ) && $_SERVER['REQUEST_METHOD'] !== 'POST' ) {
        $hidden = [
            'metis_rename_tables' => $mode,
        ];
        if ( isset( $_GET['table'] ) ) {
            $hidden['table'] = sanitize_text_field( (string) $_GET['table'] );
        }

        metis_rename_tables_maintenance()->renderPostConfirmation(
            'Confirm Table Rename Operation',
            'This maintenance action now requires an authenticated POST confirmation.',
            'metis_rename_tables:' . $mode,
            $hidden
        );
    }

    if ( metis_rename_tables_mutation_mode( $mode ) ) {
        metis_rename_tables_maintenance()->assertAuthorizedMutation( 'metis_rename_tables:' . $mode );
    }

    if ( $mode === 'collisions' ) {

        $result = metis_verify_table_renames();
        $lines  = [];

        $lines[] = '=== Metis Table Rename — Collision Detail ===';
        $lines[] = '';

        if ( empty( $result['collision'] ) ) {
            $lines[] = '✅ No collisions found.';
            metis_runtime_die(
                '<pre style="font-family:monospace; font-size:14px; line-height:1.7;">' . esc_html( implode( "\n", $lines ) ) . '</pre>',
                'Metis — Collision Detail',
                [ 'response' => 200 ]
            );
        }

        $db = metis_db();

        foreach ( $result['collision'] as $entry ) {
            // Parse "old AND new both exist" string back to table names
            preg_match( '/^(\S+) AND (\S+)/', $entry, $m );
            if ( empty( $m[1] ) || empty( $m[2] ) ) continue;

            $old = $m[1];
            $new = $m[2];

            $old_count  = (int) $db->scalar( "SELECT COUNT(*) FROM `{$old}`" );
            $new_count  = (int) $db->scalar( "SELECT COUNT(*) FROM `{$new}`" );
            $old_cols   = $db->column( "DESCRIBE `{$old}`" );
            $new_cols   = $db->column( "DESCRIBE `{$new}`" );
            $col_match  = ( $old_cols === $new_cols ) ? 'identical' : 'DIFFER';

            $lines[] = "⚠️  {$old}";
            $lines[] = "    rows : {$old_count}";
            $lines[] = "    cols : " . implode( ', ', $old_cols );
            $lines[] = '';
            $lines[] = "    {$new}";
            $lines[] = "    rows : {$new_count}";
            $lines[] = "    cols : " . implode( ', ', $new_cols );
            $lines[] = "    schema: {$col_match}";
            $lines[] = '';

            if ( $old_count > 0 && $new_count === 0 ) {
                $lines[] = "    ✅ Safe to drop {$new} and rename {$old} → {$new}";
                $lines[] = "       Run: ?metis_rename_tables=resolve&table={$old}";
            } elseif ( $old_count === 0 && $new_count > 0 ) {
                $lines[] = "    ✅ {$new} already has data. Safe to drop empty {$old}.";
                $lines[] = "       Run: ?metis_rename_tables=drop_old&table={$old}";
            } elseif ( $old_count > 0 && $new_count > 0 ) {
                $lines[] = "    ⚠️  BOTH tables have data — manual review required.";
                $lines[] = "       Do NOT run an automated resolve on this one.";
            } else {
                $lines[] = "    ℹ️  Both tables are empty. Safe to drop old.";
                $lines[] = "       Run: ?metis_rename_tables=drop_old&table={$old}";
            }

            $lines[] = str_repeat( '-', 60 );
        }

        metis_runtime_die(
            '<pre style="font-family:monospace; font-size:14px; line-height:1.7;">' . esc_html( implode( "\n", $lines ) ) . '</pre>',
            'Metis — Collision Detail',
            [ 'response' => 200 ]
        );
    }

    // Drop old (empty) prefixed table and keep the canonical metis_ table
    if ( $mode === 'drop_old' ) {

        $db    = metis_db();
        $table = sanitize_text_field( (string) ( $_POST['table'] ?? $_GET['table'] ?? '' ) );
        $prefix = (string) ( $db->connection()->prefix ?? '' );
        if ( ! $table || ! preg_match( '/(^|_)metis_/', $table ) || ! str_starts_with( $table, $prefix ) ) {
            metis_runtime_die( 'Invalid table parameter. Must be a prefixed metis table.', 'Error', [ 'response' => 400 ] );
        }

        $existing = array_flip( $db->column( "SHOW TABLES" ) );

        if ( ! isset( $existing[ $table ] ) ) {
            metis_runtime_die( "Table {$table} does not exist.", 'Error', [ 'response' => 400 ] );
        }

        $count = (int) $db->scalar( "SELECT COUNT(*) FROM `{$table}`" );
        if ( $count > 0 ) {
            metis_runtime_die(
                "<pre>❌ Refused: {$table} has {$count} rows. Drop it manually after confirming the data is not needed.</pre>",
                'Metis — Drop Refused',
                [ 'response' => 200 ]
            );
        }

        $db->execute( "DROP TABLE `{$table}`" );
        Metis_Logger::info( "Dropped empty legacy table: {$table}" );
        metis_rename_tables_maintenance()->auditMutation( 'rename_tables_drop_old', [ 'table' => $table ] );

        metis_runtime_die(
            "<pre>✅ Dropped empty table: {$table}\n\nRun ?metis_rename_tables=verify to confirm state.</pre>",
            'Metis — Table Dropped',
            [ 'response' => 200 ]
        );
    }

    // Drop empty canonical table and rename the prefixed source into place
    if ( $mode === 'resolve' ) {

        $db     = metis_db();
        $old    = sanitize_text_field( (string) ( $_POST['table'] ?? $_GET['table'] ?? '' ) );
        $prefix = (string) ( $db->connection()->prefix ?? '' );
        if ( ! $old || ! preg_match( '/(^|_)metis_/', $old ) || ! str_starts_with( $old, $prefix ) ) {
            metis_runtime_die( 'Invalid table parameter. Must be a prefixed metis table.', 'Error', [ 'response' => 400 ] );
        }

        $existing = array_flip( $db->column( "SHOW TABLES" ) );
        $new      = preg_replace( '/^' . preg_quote( $prefix, '/' ) . '/', '', $old, 1 );

        if ( ! isset( $existing[ $old ] ) ) {
            metis_runtime_die( "Source table {$old} does not exist.", 'Error', [ 'response' => 400 ] );
        }
        if ( ! isset( $existing[ $new ] ) ) {
            metis_runtime_die( "Target table {$new} does not exist — use regular rename instead.", 'Error', [ 'response' => 400 ] );
        }

        $old_count = (int) $db->scalar( "SELECT COUNT(*) FROM `{$old}`" );
        $new_count = (int) $db->scalar( "SELECT COUNT(*) FROM `{$new}`" );

        if ( $new_count > 0 ) {
            metis_runtime_die(
                "<pre>❌ Refused: {$new} already has {$new_count} rows.\nRun ?metis_rename_tables=collisions for full detail.</pre>",
                'Metis — Resolve Refused',
                [ 'response' => 200 ]
            );
        }

        // Safe: new is empty — drop it, rename old → new
        $db->execute( "DROP TABLE `{$new}`" );
        $result = $db->execute( "RENAME TABLE `{$old}` TO `{$new}`" );

        if ( $result === false ) {
            metis_runtime_die(
                "<pre>❌ Rename failed: " . esc_html( (string) ( $db->connection()->last_error ?? 'Unknown error' ) ) . "</pre>",
                'Metis — Rename Failed',
                [ 'response' => 500 ]
            );
        }

        Metis_Logger::info( "Collision resolved: {$old} → {$new} (empty target dropped)" );
        metis_rename_tables_maintenance()->auditMutation( 'rename_tables_resolve', [ 'old_table' => $old, 'new_table' => $new ] );

        metis_runtime_die(
            "<pre>✅ Resolved: dropped empty {$new}, renamed {$old} → {$new}\n\nRun ?metis_rename_tables=verify to confirm.</pre>",
            'Metis — Collision Resolved',
            [ 'response' => 200 ]
        );
    }

    if ( $mode === 'verify' ) {

        $result = metis_verify_table_renames();
        $lines  = [];

        $lines[] = '=== Metis Table Rename — Verification ===';
        $lines[] = '';

        $lines[] = '✅ Already renamed (' . count( $result['complete'] ) . '):';
        foreach ( $result['complete'] as $t ) $lines[] = "   {$t}";

        $lines[] = '';
        $lines[] = '⏳ Pending rename (' . count( $result['pending'] ) . '):';
        foreach ( $result['pending'] as $t ) $lines[] = "   {$t}";

        if ( ! empty( $result['collision'] ) ) {
            $lines[] = '';
            $lines[] = '⚠️  Collisions — both old and new exist (' . count( $result['collision'] ) . '):';
            foreach ( $result['collision'] as $t ) $lines[] = "   {$t}";
        }

        metis_runtime_die(
            '<pre style="font-family:monospace; font-size:14px; line-height:1.7;">'
            . esc_html( implode( "\n", $lines ) )
            . '</pre>',
            'Metis — Table Rename Verification',
            [ 'response' => 200 ]
        );
    }

    if ( $mode === '1' || $mode === 'run' ) {

        $result = metis_run_table_renames();
        metis_rename_tables_maintenance()->auditMutation( 'rename_tables_run', [
            'renamed_count' => count( $result['renamed'] ),
            'skipped_count' => count( $result['skipped'] ),
            'error_count' => count( $result['errors'] ),
        ] );
        $lines  = [];

        $lines[] = '=== Metis Table Rename — Complete ===';
        $lines[] = '';

        $lines[] = '✅ Renamed (' . count( $result['renamed'] ) . '):';
        foreach ( $result['renamed'] as $r ) $lines[] = "   {$r}";

        $lines[] = '';
        $lines[] = '⏭  Skipped (' . count( $result['skipped'] ) . '):';
        foreach ( $result['skipped'] as $s ) $lines[] = "   {$s}";

        if ( ! empty( $result['errors'] ) ) {
            $lines[] = '';
            $lines[] = '❌ Errors (' . count( $result['errors'] ) . '):';
            foreach ( $result['errors'] as $e ) $lines[] = "   {$e}";
        }

        $lines[] = '';
        $lines[] = 'Check logs for full detail.';
        $lines[] = 'Run ?metis_rename_tables=verify to confirm current state.';

        metis_runtime_die(
            '<pre style="font-family:monospace; font-size:14px; line-height:1.7;">'
            . esc_html( implode( "\n", $lines ) )
            . '</pre>',
            'Metis — Table Rename Complete',
            [ 'response' => 200 ]
        );
    }
} );
