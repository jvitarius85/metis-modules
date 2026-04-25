<?php
return [
    'playbook_key' => 'corrupted_core_file',
    'description' => 'Restore a corrupted core file from backup, then Git if backup verification fails.',
    'severity' => 'critical',
    'trigger_conditions' => [ 'corrupted_core_file' ],
    'validation_rules' => [ 'manifest_hash_mismatch', 'backup_directory_writable', 'lock_available' ],
    'backup_scope' => [ 'files' => 'affected', 'database' => false ],
    'execution_steps' => [ 'acquire_lock', 'backup_current_files', 'quarantine_corrupted_file', 'restore_from_backup', 'restore_from_git_if_needed', 'verify_hash', 'release_lock' ],
    'verification_steps' => [ 'file_exists', 'hash_matches_manifest' ],
    'rollback_steps' => [ 'restore_quarantined_file', 'log_failure' ],
    'max_attempts' => 2,
    'cooldown_seconds' => 300,
    'requires_user_approval' => true,
    'requires_secure_enclave' => true,
];
