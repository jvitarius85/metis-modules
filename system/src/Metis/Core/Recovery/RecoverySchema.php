<?php
declare(strict_types=1);

namespace Metis\Core\Recovery;

final class RecoverySchema {
    private static bool $schemaBackupWritten = false;

    public static function ensureSchema(): void {
        if (!class_exists('\Metis_Tables') || !function_exists('\metis_db_delta')) {
            return;
        }

        $events = \Metis_Tables::get('recovery_events');
        $actions = \Metis_Tables::get('recovery_actions');
        $backups = \Metis_Tables::get('recovery_backups');
        $manifest = \Metis_Tables::get('recovery_integrity_manifest');
        $locks = \Metis_Tables::get('recovery_locks');
        $charset = self::charsetCollate();

        self::backupDatabaseSchema('recovery_schema_install');

        $statements = [
            "CREATE TABLE IF NOT EXISTS {$events} (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  event_code VARCHAR(80) NOT NULL,
  severity VARCHAR(32) NOT NULL DEFAULT 'warning',
  trigger_source VARCHAR(80) NOT NULL DEFAULT '',
  issue_type VARCHAR(80) NOT NULL DEFAULT '',
  detected_at DATETIME NOT NULL,
  status VARCHAR(32) NOT NULL DEFAULT 'detected',
  selected_playbook VARCHAR(120) NOT NULL DEFAULT '',
  backup_reference VARCHAR(190) NOT NULL DEFAULT '',
  git_reference VARCHAR(190) NOT NULL DEFAULT '',
  started_at DATETIME DEFAULT NULL,
  completed_at DATETIME DEFAULT NULL,
  result_summary LONGTEXT NULL,
  created_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY event_code (event_code),
  KEY status_detected (status, detected_at),
  KEY issue_type (issue_type),
  KEY trigger_source (trigger_source)
) {$charset}",
            "CREATE TABLE IF NOT EXISTS {$actions} (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  recovery_event_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
  action_key VARCHAR(120) NOT NULL DEFAULT '',
  action_type VARCHAR(80) NOT NULL DEFAULT '',
  action_status VARCHAR(32) NOT NULL DEFAULT 'pending',
  action_started_at DATETIME DEFAULT NULL,
  action_completed_at DATETIME DEFAULT NULL,
  details_json LONGTEXT NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY recovery_event_id (recovery_event_id),
  KEY action_status (action_status),
  KEY action_key (action_key)
) {$charset}",
            "CREATE TABLE IF NOT EXISTS {$backups} (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  backup_reference VARCHAR(190) NOT NULL,
  backup_type VARCHAR(60) NOT NULL DEFAULT 'files',
  backup_path TEXT NULL,
  includes_files TINYINT(1) NOT NULL DEFAULT 1,
  includes_database TINYINT(1) NOT NULL DEFAULT 0,
  source_context VARCHAR(190) NOT NULL DEFAULT '',
  verification_status VARCHAR(32) NOT NULL DEFAULT 'pending',
  created_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY backup_reference (backup_reference),
  KEY backup_type (backup_type),
  KEY verification_status (verification_status),
  KEY created_at (created_at)
) {$charset}",
            "CREATE TABLE IF NOT EXISTS {$manifest} (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  version VARCHAR(80) NOT NULL DEFAULT '',
  manifest_hash CHAR(64) NOT NULL DEFAULT '',
  file_path VARCHAR(500) NOT NULL,
  file_hash CHAR(64) NOT NULL DEFAULT '',
  file_size BIGINT UNSIGNED NOT NULL DEFAULT 0,
  last_verified_at DATETIME DEFAULT NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY version_file_path (version, file_path),
  KEY manifest_hash (manifest_hash),
  KEY last_verified_at (last_verified_at)
) {$charset}",
            "CREATE TABLE IF NOT EXISTS {$locks} (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  lock_key VARCHAR(120) NOT NULL,
  lock_status VARCHAR(32) NOT NULL DEFAULT 'active',
  acquired_at DATETIME NOT NULL,
  expires_at DATETIME NOT NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY lock_key (lock_key),
  KEY lock_status (lock_status),
  KEY expires_at (expires_at)
) {$charset}",
        ];

        foreach ($statements as $statement) {
            \metis_db_delta($statement);
        }
    }

    private static function charsetCollate(): string {
        $connection = $GLOBALS['metis_db_connection'] ?? null;
        if (is_object($connection) && method_exists($connection, 'get_charset_collate')) {
            return (string) $connection->get_charset_collate();
        }
        return 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';
    }

    private static function backupDatabaseSchema(string $context): void {
        if (self::$schemaBackupWritten || !function_exists('\metis_db')) {
            return;
        }
        self::$schemaBackupWritten = true;

        try {
            $policy = new RecoveryPolicyService();
            $root = $policy->backupRoot();
            if ($root === '' || (!is_dir($root) && !@mkdir($root, 0775, true))) {
                return;
            }

            $directory = $root . '/recovery/database-schema';
            if (!is_dir($directory)) {
                @mkdir($directory, 0775, true);
            }
            if (!is_dir($directory)) {
                return;
            }

            $prefix = '';
            $connection = $GLOBALS['metis_db_connection'] ?? null;
            if (is_object($connection) && isset($connection->prefix)) {
                $prefix = (string) $connection->prefix;
            }
            if ($prefix === '') {
                $prefix = 'metis_';
            }

            $tables = \metis_db()->column('SHOW TABLES LIKE %s', [$prefix . '%']);
            $schema = [];
            foreach ($tables as $table) {
                $table = (string) $table;
                $row = \metis_db()->fetchOne('SHOW CREATE TABLE `' . str_replace('`', '``', $table) . '`');
                $schema[$table] = is_array($row) ? array_values($row)[1] ?? '' : '';
            }

            $payload = [
                'created_at' => gmdate('c'),
                'context' => $context,
                'table_count' => count($schema),
                'schema' => $schema,
            ];
            $path = $directory . '/' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(3)) . '.json';
            @file_put_contents($path, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
            if (is_file($path)) {
                @chmod($path, 0664);
            }
        } catch (\Throwable) {
        }
    }
}
