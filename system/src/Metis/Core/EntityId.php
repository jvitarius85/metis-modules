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
        $charset = $this->db()->get_charset_collate();

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

        $entity_type = EntityCatalog::normalizeEntityType($entity_type);
        $prefix = (string) ($definition['prefix'] ?? '');
        $attempts = 0;
        $max_attempts = 200;

        do {
            $number = random_int(0, 999999);
            $uid = $this->formatUid($prefix, $number);
            $attempts++;
            if (!$this->uidExists($entity_type, $uid)) {
                return $uid;
            }
        } while ($attempts < $max_attempts);

        throw new RuntimeException(
            sprintf('Could not allocate unique entity UID for type "%s" after %d attempts.', $entity_type, $max_attempts)
        );
    }

    private function uidExists(string $entity_type, string $uid): bool {
        $db = $this->db();
        $registry = \Metis_Tables::get('entity_registry');
        $definition = EntityCatalog::definition($entity_type);
        if (!is_array($definition)) {
            return true;
        }

        $exists_in_registry = (int) $db->scalar(
            "SELECT COUNT(1) FROM {$registry} WHERE entity_uid = %s",
            [ $uid ]
        );
        if ($exists_in_registry > 0) {
            return true;
        }

        $table_key = (string) ($definition['table_key'] ?? '');
        $uid_column = (string) ($definition['uid_column'] ?? '');
        if ($table_key === '' || $uid_column === '' || !\Metis_Tables::has($table_key)) {
            return false;
        }

        $table = \Metis_Tables::get($table_key);
        if (!$this->tableExists($table) || !$this->columnExists($table, $uid_column)) {
            return false;
        }

        $exists_in_entity_table = (int) $db->scalar(
            "SELECT COUNT(1) FROM {$table} WHERE {$uid_column} = %s",
            [ $uid ]
        );

        return $exists_in_entity_table > 0;
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
                        $current_uid = $this->generate($entity_type);
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

    /**
     * @return array<string, mixed>
     */
    public function repairEntityCodes(
        bool $sync_legacy_columns = true,
        bool $rewrite_all = false,
        bool $dry_run = false
    ): array {
        $this->ensureSchema();

        $summary = [
            'dry_run' => $dry_run,
            'rewrite_all' => $rewrite_all,
            'entities' => [],
            'updated_rows' => 0,
            'registry_rows' => 0,
        ];
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

            $updated_rows = 0;
            $registry_rows = 0;
            $last_id = 0;
            $expected_prefix = strtoupper(trim((string) ($definition['prefix'] ?? '')));

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
                    $current_uid = strtoupper(trim((string) ($row[$uid_column] ?? '')));

                    $target_uid = $this->resolveTargetUid(
                        $entity_type,
                        $definition,
                        $row,
                        $table,
                        $uid_column,
                        $row_id,
                        $legacy_columns,
                        $expected_prefix,
                        $rewrite_all
                    );

                    $update_payload = [];
                    $update_formats = [];
                    if ($current_uid !== $target_uid) {
                        $update_payload[$uid_column] = $target_uid;
                        $update_formats[] = '%s';
                    }

                    if ($sync_legacy_columns) {
                        foreach ($legacy_columns as $legacy_column) {
                            $existing = strtoupper(trim((string) ($row[$legacy_column] ?? '')));
                            if ($existing !== $target_uid) {
                                $update_payload[$legacy_column] = $target_uid;
                                $update_formats[] = '%s';
                            }
                        }
                    }

                    if ($update_payload !== []) {
                        if (!$dry_run) {
                            $db->update($table, $update_payload, ['id' => $row_id], $update_formats, ['%d']);
                        }
                        $updated_rows++;
                    }

                    if (!$dry_run) {
                        $db->execute(
                            $db->prepare(
                                "DELETE FROM {$registry_table}
                                 WHERE entity_table = %s
                                   AND entity_id = %d
                                   AND entity_uid <> %s",
                                $table,
                                $row_id,
                                $target_uid
                            )
                        );
                    }

                    $registered = $dry_run
                        ? true
                        : $db->execute(
                            $db->prepare(
                                "INSERT INTO {$registry_table} (entity_uid, entity_type, entity_table, entity_id, module_slug)
                                 VALUES (%s, %s, %s, %d, %s)
                                 ON DUPLICATE KEY UPDATE
                                    entity_type = VALUES(entity_type),
                                    entity_table = VALUES(entity_table),
                                    entity_id = VALUES(entity_id),
                                    module_slug = VALUES(module_slug)",
                                $target_uid,
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

            $summary['entities'][$entity_type] = [
                'table' => $table,
                'updated_rows' => $updated_rows,
                'registry_rows' => $registry_rows,
            ];
            $summary['updated_rows'] += $updated_rows;
            $summary['registry_rows'] += $registry_rows;
        }

        return $summary;
    }

    /**
     * @param array<string, mixed> $definition
     * @param array<string, mixed> $row
     * @param array<int, string> $legacy_columns
     */
    private function resolveTargetUid(
        string $entity_type,
        array $definition,
        array $row,
        string $table,
        string $uid_column,
        int $row_id,
        array $legacy_columns,
        string $expected_prefix,
        bool $rewrite_all
    ): string {
        $current_uid = strtoupper(trim((string) ($row[$uid_column] ?? '')));
        if (
            !$rewrite_all
            && $this->uidMatchesPrefix($current_uid, $expected_prefix)
            && !$this->uidConflicts($entity_type, $current_uid, $table, $uid_column, $row_id)
        ) {
            return $current_uid;
        }

        if (!$rewrite_all) {
            foreach ($legacy_columns as $legacy_column) {
                $candidate = strtoupper(trim((string) ($row[$legacy_column] ?? '')));
                if (
                    $this->uidMatchesPrefix($candidate, $expected_prefix)
                    && !$this->uidConflicts($entity_type, $candidate, $table, $uid_column, $row_id)
                ) {
                    return $candidate;
                }
            }
        }

        $max_attempts = 200;
        for ($attempt = 0; $attempt < $max_attempts; $attempt++) {
            $candidate = $this->generate($entity_type);
            if (!$this->uidConflicts($entity_type, $candidate, $table, $uid_column, $row_id)) {
                return $candidate;
            }
        }

        throw new RuntimeException(
            sprintf('Could not generate conflict-free UID for %s row %d.', $entity_type, $row_id)
        );
    }

    private function uidMatchesPrefix(string $uid, string $expected_prefix): bool {
        if (!preg_match('/^([A-Z]{2,8})-(\d{6})$/', strtoupper(trim($uid)), $matches)) {
            return false;
        }

        return strtoupper((string) ($matches[1] ?? '')) === strtoupper($expected_prefix);
    }

    private function uidConflicts(
        string $entity_type,
        string $uid,
        string $table,
        string $uid_column,
        int $row_id
    ): bool {
        if ($uid === '') {
            return true;
        }

        $db = $this->db();
        $registry = \Metis_Tables::get('entity_registry');

        $registry_conflict = (int) $db->scalar(
            "SELECT COUNT(1)
             FROM {$registry}
             WHERE entity_uid = %s
               AND NOT (entity_type = %s AND entity_table = %s AND entity_id = %d)",
            [ $uid, $entity_type, $table, $row_id ]
        );
        if ($registry_conflict > 0) {
            return true;
        }

        $table_conflict = (int) $db->scalar(
            "SELECT COUNT(1) FROM {$table} WHERE {$uid_column} = %s AND id <> %d",
            [ $uid, $row_id ]
        );

        return $table_conflict > 0;
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
