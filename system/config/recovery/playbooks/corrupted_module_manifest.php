<?php
return [
    'playbook_key' => 'corrupted_module_manifest',
    'description' => 'Restore an invalid module manifest from backup, then Git if backup verification fails.',
    'severity' => 'high',
    'trigger_conditions' => [ 'corrupted_module_manifest', 'missing_module_manifest' ],
    'validation_rules' => [ 'manifest_invalid_or_missing', 'module_path_allowed', 'lock_available' ],
    'backup_scope' => [ 'files' => 'affected', 'database' => false ],
    'execution_steps' => [ 'acquire_lock', 'backup_current_files', 'quarantine_corrupted_file', 'restore_from_backup', 'restore_from_git_if_needed', 'verify_module_manifest', 'release_lock' ],
    'verification_steps' => [ 'module_json_valid', 'module_slug_present' ],
    'rollback_steps' => [ 'restore_quarantined_file', 'log_failure' ],
    'max_attempts' => 2,
    'cooldown_seconds' => 300,
    'requires_user_approval' => true,
    'requires_secure_enclave' => true,
];
