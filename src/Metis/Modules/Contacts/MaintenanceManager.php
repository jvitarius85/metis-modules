<?php
declare(strict_types=1);

namespace Metis\Modules\Contacts;

final class MaintenanceManager {
    public static function backfillCid(): int {
        global $wpdb;

        $contacts_table = \Metis_Tables::get( 'contacts' );
        if ( ! SchemaManager::tableExists( $contacts_table ) || ! SchemaManager::columnExists( $contacts_table, 'cid' ) ) {
            return 0;
        }

        $rows    = $wpdb->get_results( "SELECT id FROM {$contacts_table} WHERE cid IS NULL OR cid = '' ORDER BY id ASC" );
        $updated = 0;

        foreach ( $rows as $row ) {
            $cid = \metis_generate_code( 'CN', $contacts_table, 'cid' );
            $res = $wpdb->update(
                $contacts_table,
                [ 'cid' => $cid ],
                [ 'id' => (int) $row->id ],
                [ '%s' ],
                [ '%d' ]
            );

            if ( $res !== false ) {
                $updated++;
            }
        }

        \Metis_Logger::info( 'Contacts CID backfill completed', [ 'updated' => $updated ] );
        return $updated;
    }

    public static function migrateNotesToCid(): array {
        global $wpdb;

        $contacts_table = \Metis_Tables::get( 'contacts' );
        $notes_table    = \Metis_Tables::get( 'contact_notes' );

        if ( ! SchemaManager::tableExists( $contacts_table ) || ! SchemaManager::tableExists( $notes_table ) ) {
            return [ 'updated' => 0, 'skipped' => 'missing table(s)' ];
        }

        if ( ! SchemaManager::columnExists( $notes_table, 'cid' ) ) {
            SchemaManager::addColumnIfMissing( $notes_table, 'cid', 'VARCHAR(191) DEFAULT NULL' );
        }

        $updated = 0;

        if ( SchemaManager::columnExists( $notes_table, 'did' ) && SchemaManager::columnExists( $contacts_table, 'did' ) && SchemaManager::columnExists( $contacts_table, 'cid' ) ) {
            $res = $wpdb->query(
                "UPDATE {$notes_table} n
                 INNER JOIN {$contacts_table} c ON c.did = n.did
                 SET n.cid = c.cid
                 WHERE (n.cid IS NULL OR n.cid = '')
                   AND n.did IS NOT NULL
                   AND n.did <> ''
                   AND c.cid IS NOT NULL
                   AND c.cid <> ''"
            );
            if ( is_numeric( $res ) ) {
                $updated += (int) $res;
            }
        }

        if ( SchemaManager::columnExists( $notes_table, 'contact_id' ) && SchemaManager::columnExists( $contacts_table, 'id' ) && SchemaManager::columnExists( $contacts_table, 'cid' ) ) {
            $res = $wpdb->query(
                "UPDATE {$notes_table} n
                 INNER JOIN {$contacts_table} c ON c.id = n.contact_id
                 SET n.cid = c.cid
                 WHERE (n.cid IS NULL OR n.cid = '')
                   AND n.contact_id IS NOT NULL
                   AND c.cid IS NOT NULL
                   AND c.cid <> ''"
            );
            if ( is_numeric( $res ) ) {
                $updated += (int) $res;
            }
        }

        \Metis_Logger::info( 'Contacts notes migration (did/contact_id -> cid) completed', [ 'updated' => $updated ] );
        return [ 'updated' => $updated ];
    }

    public static function cleanupMergeNotes(): array {
        global $wpdb;

        $notes_table = \Metis_Tables::get( 'contact_notes' );
        if ( ! SchemaManager::tableExists( $notes_table ) || ! SchemaManager::columnExists( $notes_table, 'cid' ) ) {
            return [ 'groups_consolidated' => 0, 'notes_deleted' => 0, 'notes_created' => 0, 'skipped' => 'notes table/cid unavailable' ];
        }

        $rows = $wpdb->get_results(
            "SELECT id, cid, note, created_at, admin_user_id
             FROM {$notes_table}
             WHERE note LIKE 'System merge by %'
               AND cid IS NOT NULL
               AND cid <> ''
               AND (deleted_at IS NULL OR deleted_at = '')
             ORDER BY created_at ASC, id ASC"
        ) ?: [];

        if ( empty( $rows ) ) {
            return [ 'groups_consolidated' => 0, 'notes_deleted' => 0, 'notes_created' => 0 ];
        }

        $groups = [];
        foreach ( $rows as $row ) {
            $cid        = (string) ( $row->cid ?? '' );
            $created_at = (string) ( $row->created_at ?? '' );
            $minute_key = $created_at !== '' ? substr( $created_at, 0, 16 ) : 'unknown';
            $admin_id   = isset( $row->admin_user_id ) ? (int) $row->admin_user_id : 0;
            $gkey       = $cid . '|' . $admin_id . '|' . $minute_key;
            $groups[ $gkey ][] = $row;
        }

        $groups_consolidated = 0;
        $notes_deleted       = 0;
        $notes_created       = 0;

        foreach ( $groups as $group_rows ) {
            if ( count( $group_rows ) < 2 ) {
                continue;
            }

            $target_cid     = (string) ( $group_rows[0]->cid ?? '' );
            $created_at     = (string) ( $group_rows[0]->created_at ?? \current_time( 'mysql' ) );
            $admin_id       = isset( $group_rows[0]->admin_user_id ) ? (int) $group_rows[0]->admin_user_id : 0;
            $actor_name     = 'System';
            $merged_entries = [];
            $seen_entry     = [];

            foreach ( $group_rows as $row ) {
                $note = (string) ( $row->note ?? '' );
                if ( preg_match( '/^System merge by\s+([^:]+):/i', $note, $m_actor ) ) {
                    $actor_name = trim( (string) $m_actor[1] );
                }
                if ( preg_match( '/merged contact\s+([A-Z0-9]+).*?DID\s+([A-Z0-9]+)/i', $note, $m_old ) ) {
                    $entry_key = strtoupper( $m_old[1] ) . '|' . strtoupper( $m_old[2] );
                    if ( ! isset( $seen_entry[ $entry_key ] ) ) {
                        $seen_entry[ $entry_key ] = true;
                        $merged_entries[]         = strtoupper( $m_old[1] ) . ' (Donor ID ' . strtoupper( $m_old[2] ) . ')';
                    }
                    continue;
                }
                if ( preg_match( '/merged contacts\s+(.+?)\s+into\s+([A-Z0-9]+)/i', $note, $m_new ) ) {
                    $parts = array_map( 'trim', explode( ';', (string) $m_new[1] ) );
                    foreach ( $parts as $part ) {
                        if ( $part === '' ) {
                            continue;
                        }
                        $entry_key = strtolower( $part );
                        if ( ! isset( $seen_entry[ $entry_key ] ) ) {
                            $seen_entry[ $entry_key ] = true;
                            $merged_entries[]         = $part;
                        }
                    }
                }
            }

            if ( empty( $merged_entries ) ) {
                continue;
            }

            $system_note = sprintf(
                'System merge by %s: merged contacts %s into %s.',
                $actor_name !== '' ? $actor_name : 'System',
                implode( '; ', $merged_entries ),
                $target_cid
            );

            $payload = [
                'cid'        => $target_cid,
                'note'       => $system_note,
                'created_at' => $created_at,
            ];
            $format = [ '%s', '%s', '%s' ];
            if ( SchemaManager::columnExists( $notes_table, 'admin_user_id' ) ) {
                $payload['admin_user_id'] = $admin_id;
                $format[]                 = '%d';
            }

            $inserted = $wpdb->insert( $notes_table, $payload, $format );
            if ( $inserted === false ) {
                continue;
            }

            $notes_created++;
            $groups_consolidated++;

            foreach ( $group_rows as $old_row ) {
                $res = $wpdb->delete( $notes_table, [ 'id' => (int) $old_row->id ], [ '%d' ] );
                if ( $res !== false ) {
                    $notes_deleted++;
                }
            }
        }

        \Metis_Logger::info( 'Contacts merge notes cleanup completed', [
            'groups_consolidated' => $groups_consolidated,
            'notes_created'       => $notes_created,
            'notes_deleted'       => $notes_deleted,
        ] );

        return [
            'groups_consolidated' => $groups_consolidated,
            'notes_created'       => $notes_created,
            'notes_deleted'       => $notes_deleted,
        ];
    }
}
