<?php
declare(strict_types=1);

namespace Metis\Core;

use RuntimeException;
use Metis\Services\DatabaseService;

final class EntityId {
    private bool $schemaReady = false;

    public function ensureSchema(): void {
        if ($this->schemaReady) {
            return;
        }

        $this->schemaReady = true;

        $prefixes = \Metis_Tables::get('entity_prefixes');
        $sequences = \Metis_Tables::get('id_sequences');
        $registry = \Metis_Tables::get('entity_registry');
        $connection = $this->db()->connection();
        $charset = method_exists($connection, 'get_charset_collate') ? (string) $connection->get_charset_collate() : '';

        \metis_db_delta("CREATE TABLE {$prefixes} (
            entity_type VARCHAR(64) PRIMARY KEY,
            prefix VARCHAR(8) NOT NULL,
            description VARCHAR(255) DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY prefix (prefix)
        ) {$charset};");

        \metis_db_delta("CREATE TABLE {$sequences} (
            entity_type VARCHAR(64) PRIMARY KEY,
            next_value INT NOT NULL DEFAULT 1
        ) {$charset};");

        \metis_db_delta("CREATE TABLE {$registry} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            entity_uid VARCHAR(16) NOT NULL,
            entity_type VARCHAR(64) NOT NULL,
            entity_table VARCHAR(64) NOT NULL,
            entity_id BIGINT UNSIGNED NOT NULL,
            module_slug VARCHAR(64) DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY entity_uid (entity_uid),
            KEY idx_entity_uid (entity_uid),
            KEY idx_entity_lookup (entity_table, entity_id)
        ) {$charset};");

        $this->seedPrefixes();
        $this->ensureEntityColumns();
    }

    public function seedPrefixes(): void {
        try {
            $db = $this->db();
            $prefixes = \Metis_Tables::get('entity_prefixes');
            $sequences = \Metis_Tables::get('id_sequences');

            foreach (EntityCatalog::definitions() as $entity_type => $definition) {
                $db->replace(
                    $prefixes,
                    [
                        'entity_type' => $entity_type,
                        'prefix' => (string) ($definition['prefix'] ?? ''),
                        'description' => (string) ($definition['description'] ?? $entity_type),
                    ],
                    ['%s', '%s', '%s']
                );

                $db->execute(
                    $db->prepare(
                        "INSERT INTO {$sequences} (entity_type, next_value) VALUES (%s, 1)
                     ON DUPLICATE KEY UPDATE entity_type = entity_type",
                        $entity_type
                    )
                );
            }
        } catch ( \Throwable $e ) {
            // Schema seeding is best-effort; unavailable DB in test/isolation contexts is non-fatal.
        }
    }

    public function ensureEntityColumns(): void {
        try {
            $db = $this->db();
            foreach ( EntityCatalog::definitions() as $definition ) {
                $table_key  = (string) ( $definition['table_key'] ?? '' );
                $uid_column = (string) ( $definition['uid_column'] ?? '' );

                if ( $table_key === '' || $uid_column === '' || ! \Metis_Tables::has( $table_key ) ) {
                    continue;
                }

                $table = \Metis_Tables::get( $table_key );
                if ( ! $this->tableExists( $table ) ) {
                    continue;
                }

                if ( ! $this->columnExists( $table, $uid_column ) ) {
                    $db->execute( "ALTER TABLE {$table} ADD COLUMN {$uid_column} VARCHAR(16) DEFAULT NULL" );
                }

                if ( ! $this->indexExists( $table, $uid_column ) ) {
                    $db->execute( "ALTER TABLE {$table} ADD UNIQUE KEY {$uid_column} ({$uid_column})" );
                }
            }
        } catch ( \Throwable $e ) {
            // Schema column checks are best-effort; unavailable DB in test/isolation contexts is non-fatal.
        }
    }

    public function generate(string $entity_type): string {
        $this->ensureSchema();

        $definition = EntityCatalog::definition($entity_type);
        if (!is_array($definition)) {
            throw new RuntimeException('Unknown entity type: ' . $entity_type);
        }

        $db = $this->db();
        $sequences = \Metis_Tables::get('id_sequences');
        $entity_type = EntityCatalog::normalizeEntityType($entity_type);
        $prefix = (string) ($definition['prefix'] ?? '');

        $db->execute('START TRANSACTION');

        try {
            $row = $db->fetchOne(
                "SELECT next_value FROM {$sequences} WHERE entity_type = %s FOR UPDATE",
                [ $entity_type ]
            );

            if (!is_array($row)) {
                $number = 1;
                $db->insert($sequences, ['entity_type' => $entity_type, 'next_value' => 2], ['%s', '%d']);
            } else {
                $number = max(1, (int) ($row['next_value'] ?? 1));
                $db->update($sequences, ['next_value' => $number + 1], ['entity_type' => $entity_type], ['%d'], ['%s']);
            }

            $db->execute('COMMIT');
        } catch (\Throwable $e) {
            $db->execute('ROLLBACK');
            throw $e;
        }

        return $this->formatUid($prefix, $number);
    }

    public function formatUid(string $prefix, int $number): string {
        return strtoupper(trim($prefix)) . '-' . str_pad((string) max(1, $number), 6, '0', STR_PAD_LEFT);
    }

    public function parseNumericSuffix(string $uid): int {
        if (!preg_match('/^[A-Z]{2,8}-(\d{6})$/', strtoupper(trim($uid)), $matches)) {
            return 0;
        }

        return (int) ($matches[1] ?? 0);
    }

    public function assignForInsert(string $entity_type, array $payload, bool $sync_legacy_columns = true): array {
        $definition = EntityCatalog::definition($entity_type);
        if (!is_array($definition)) {
            return $payload;
        }

        $uid_column = (string) ($definition['uid_column'] ?? '');
        if ($uid_column === '') {
            return $payload;
        }

        $uid = trim((string) ($payload[$uid_column] ?? ''));
        if ($uid === '') {
            $uid = $this->generate($entity_type);
            $payload[$uid_column] = $uid;
        }

        if ($sync_legacy_columns) {
            foreach ((array) ($definition['legacy_columns'] ?? []) as $legacy_column) {
                if (!array_key_exists($legacy_column, $payload) || trim((string) ($payload[$legacy_column] ?? '')) === '') {
                    $payload[$legacy_column] = $uid;
                }
            }
        }

        return $payload;
    }

    public function register(string $entity_type, int $entity_id, ?string $entity_uid = null): bool {
        $definition = EntityCatalog::definition($entity_type);
        if (!is_array($definition) || $entity_id < 1) {
            return false;
        }

        $table_key = (string) ($definition['table_key'] ?? '');
        $uid_column = (string) ($definition['uid_column'] ?? '');
        if ($table_key === '' || $uid_column === '') {
            return false;
        }

        $table = \Metis_Tables::get($table_key);
        $db = $this->db();
        if ($entity_uid === null || trim($entity_uid) === '') {
            $entity_uid = (string) $db->scalar("SELECT {$uid_column} FROM {$table} WHERE id = %d LIMIT 1", [ $entity_id ]);
        }

        if ($entity_uid === '') {
            return false;
        }

        $registry = \Metis_Tables::get('entity_registry');
        $result = $db->execute(
            $db->prepare(
                "INSERT INTO {$registry} (entity_uid, entity_type, entity_table, entity_id, module_slug)
                 VALUES (%s, %s, %s, %d, %s)
                 ON DUPLICATE KEY UPDATE
                    entity_type = VALUES(entity_type),
                    entity_table = VALUES(entity_table),
                    entity_id = VALUES(entity_id),
                    module_slug = VALUES(module_slug)",
                $entity_uid,
                $entity_type,
                $table,
                $entity_id,
                (string) ($definition['module_slug'] ?? '')
            )
        );

        return $result !== false;
    }

    /**
     * @return array<string, mixed>
     */
    public function migrateExistingRecords(bool $sync_legacy_columns = true): array {
        $this->ensureSchema();

        $summary = [
            'entities' => [],
            'updated_rows' => 0,
            'registry_rows' => 0,
        ];
        $sequences = \Metis_Tables::get('id_sequences');
        $registry_table = \Metis_Tables::get('entity_registry');
        $db = $this->db();

        foreach (EntityCatalog::definitions() as $entity_type => $definition) {
            $table_key = (string) ($definition['table_key'] ?? '');
            $uid_column = (string) ($definition['uid_column'] ?? '');
            if ($table_key === '' || $uid_column === '' || !\Metis_Tables::has($table_key)) {
                continue;
            }

            $table = \Metis_Tables::get($table_key);
            if (!$this->tableExists($table) || !$this->columnExists($table, 'id')) {
                continue;
            }

            $where = trim((string) ($definition['where'] ?? ''));
            $legacy_columns = array_values(array_filter((array) ($definition['legacy_columns'] ?? []), static fn ($value): bool => $value !== ''));
            $select_columns = array_merge(['id', $uid_column], $legacy_columns);
            if ($where !== '' && preg_match_all('/\b([a-z_][a-z0-9_]*)\b/i', $where, $matches)) {
                foreach ((array) ($matches[1] ?? []) as $column_name) {
                    if (!in_array($column_name, $select_columns, true) && $this->columnExists($table, $column_name)) {
                        $select_columns[] = $column_name;
                    }
                }
            }

            $max_suffix = 0;
            $updated_rows = 0;
            $registry_rows = 0;
            $last_id = 0;

            while (true) {
                $sql = "SELECT " . implode(', ', array_unique($select_columns)) . " FROM {$table} WHERE id > %d";
                if ($where !== '') {
                    $sql .= " AND ({$where})";
                }
                $sql .= " ORDER BY id ASC LIMIT 500";

                $rows = $db->fetchAll($sql, [ $last_id ]);
                if ($rows === []) {
                    break;
                }

                foreach ($rows as $row) {
                    $row_id = (int) ($row['id'] ?? 0);
                    if ($row_id < 1) {
                        continue;
                    }

                    $last_id = $row_id;
                    $current_uid = trim((string) ($row[$uid_column] ?? ''));
                    foreach ($legacy_columns as $legacy_column) {
                        $legacy_value = trim((string) ($row[$legacy_column] ?? ''));
                        if ($current_uid === '' && $legacy_value !== '' && $this->parseNumericSuffix($legacy_value) > 0) {
                            $current_uid = strtoupper($legacy_value);
                            break;
                        }
                    }

                    if ($current_uid === '') {
                        $current_uid = $this->formatUid((string) ($definition['prefix'] ?? ''), $max_suffix + 1);
                    }

                    $max_suffix = max($max_suffix, $this->parseNumericSuffix($current_uid));

                    $update_payload = [];
                    $update_formats = [];
                    if ((string) ($row[$uid_column] ?? '') !== $current_uid) {
                        $update_payload[$uid_column] = $current_uid;
                        $update_formats[] = '%s';
                    }

                    if ($sync_legacy_columns) {
                        foreach ($legacy_columns as $legacy_column) {
                            if (($row[$legacy_column] ?? null) !== $current_uid) {
                                $update_payload[$legacy_column] = $current_uid;
                                $update_formats[] = '%s';
                            }
                        }
                    }

                    if ($update_payload !== []) {
                        $db->update($table, $update_payload, ['id' => $row_id], $update_formats, ['%d']);
                        $updated_rows++;
                    }

                    $registered = $db->execute(
                        $db->prepare(
                            "INSERT INTO {$registry_table} (entity_uid, entity_type, entity_table, entity_id, module_slug)
                             VALUES (%s, %s, %s, %d, %s)
                             ON DUPLICATE KEY UPDATE
                                entity_type = VALUES(entity_type),
                                entity_table = VALUES(entity_table),
                                entity_id = VALUES(entity_id),
                                module_slug = VALUES(module_slug)",
                            $current_uid,
                            $entity_type,
                            $table,
                            $row_id,
                            (string) ($definition['module_slug'] ?? '')
                        )
                    );
                    if ($registered !== false) {
                        $registry_rows++;
                    }
                }
            }

            $db->replace(
                $sequences,
                [
                    'entity_type' => $entity_type,
                    'next_value' => max(1, $max_suffix + 1),
                ],
                ['%s', '%d']
            );

            $summary['entities'][$entity_type] = [
                'table' => $table,
                'updated_rows' => $updated_rows,
                'registry_rows' => $registry_rows,
                'next_value' => max(1, $max_suffix + 1),
            ];
            $summary['updated_rows'] += $updated_rows;
            $summary['registry_rows'] += $registry_rows;
        }

        return $summary;
    }

    public function tableExists(string $table): bool {
        $exists = $this->db()->scalar('SHOW TABLES LIKE %s', [ $table ]);
        return $exists === $table;
    }

    public function columnExists(string $table, string $column): bool {
        if (!$this->tableExists($table)) {
            return false;
        }

        $exists = $this->db()->scalar("SHOW COLUMNS FROM {$table} LIKE %s", [ $column ]);
        return !empty($exists);
    }

    private function indexExists(string $table, string $index_name): bool {
        if (!$this->tableExists($table)) {
            return false;
        }

        $index = $this->db()->scalar("SHOW INDEX FROM {$table} WHERE Key_name = %s", [ $index_name ]);
        return $index !== null;
    }

    private function db(): DatabaseService {
        /** @var DatabaseService $db */
        $db = \metis_db();
        return $db;
    }
}
