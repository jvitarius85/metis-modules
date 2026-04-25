<?php
return [
    'playbook_key' => 'preboot_backup_restore',
    'description' => 'Restore critical startup files from a verified backup before boot continues.',
    'severity' => 'critical',
    'trigger_conditions' => [ 'missing_core_file', 'corrupted_core_file', 'missing_module_manifest', 'corrupted_module_manifest', 'preboot_critical_corruption' ],
    'validation_rules' => [ 'critical_issue_present', 'backup_directory_writable', 'lock_available' ],
    'backup_scope' => [ 'files' => 'affected', 'database' => false ],
    'execution_steps' => [ 'acquire_lock', 'backup_current_files', 'quarantine_corrupted_file', 'restore_from_backup', 'verify_hash', 'release_lock' ],
    'verification_steps' => [ 'file_exists', 'hash_matches_manifest', 'boot_check_passes' ],
    'rollback_steps' => [ 'restore_quarantined_file', 'log_failure' ],
    'max_attempts' => 2,
    'cooldown_seconds' => 300,
    'requires_user_approval' => false,
    'requires_secure_enclave' => true,
];
