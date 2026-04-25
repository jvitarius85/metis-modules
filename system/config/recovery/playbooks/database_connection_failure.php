<?php
return [
    'playbook_key' => 'database_connection_failure',
    'description' => 'Validate database availability and escalate without mutating database state.',
    'severity' => 'critical',
    'trigger_conditions' => [ 'database_connection_failure' ],
    'validation_rules' => [ 'database_unavailable' ],
    'backup_scope' => [ 'files' => 'none', 'database' => false ],
    'execution_steps' => [ 'acquire_lock', 'verify_database_connection', 'log_failure', 'release_lock' ],
    'verification_steps' => [ 'database_select_one_passes' ],
    'rollback_steps' => [ 'log_failure' ],
    'max_attempts' => 1,
    'cooldown_seconds' => 300,
    'requires_user_approval' => true,
    'requires_secure_enclave' => true,
];
