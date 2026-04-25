<?php
return [
    'playbook_key' => 'preboot_git_restore',
    'description' => 'Restore affected startup files from the installed Git version when backup recovery fails.',
    'severity' => 'critical',
    'trigger_conditions' => [ 'backup_recovery_failed' ],
    'validation_rules' => [ 'critical_issue_present', 'backup_created', 'allowed_git_remote', 'installed_ref_available' ],
    'backup_scope' => [ 'files' => 'affected', 'database' => false ],
    'execution_steps' => [ 'acquire_lock', 'backup_current_files', 'quarantine_corrupted_file', 'restore_from_git', 'verify_hash', 'release_lock' ],
    'verification_steps' => [ 'file_exists', 'hash_matches_manifest', 'boot_check_passes' ],
    'rollback_steps' => [ 'restore_quarantined_file', 'log_failure' ],
    'max_attempts' => 1,
    'cooldown_seconds' => 300,
    'requires_user_approval' => false,
    'requires_secure_enclave' => true,
];
