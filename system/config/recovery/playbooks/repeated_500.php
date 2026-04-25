<?php
return [
    'playbook_key' => 'repeated_500',
    'description' => 'Classify repeated server errors and run safe cache and integrity checks.',
    'severity' => 'high',
    'trigger_conditions' => [ 'repeated_500' ],
    'validation_rules' => [ 'error_threshold_exceeded' ],
    'backup_scope' => [ 'files' => 'affected', 'database' => false ],
    'execution_steps' => [ 'acquire_lock', 'backup_current_files', 'run_integrity_scan', 'clear_runtime_cache', 'release_lock' ],
    'verification_steps' => [ 'request_error_rate_recovered' ],
    'rollback_steps' => [ 'log_failure' ],
    'max_attempts' => 2,
    'cooldown_seconds' => 300,
    'requires_user_approval' => true,
    'requires_secure_enclave' => true,
];
