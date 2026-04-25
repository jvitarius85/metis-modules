<?php
return [
    'playbook_key' => 'php_fpm_failure',
    'description' => 'Record PHP runtime failure and escalate restart work through approved operations.',
    'severity' => 'critical',
    'trigger_conditions' => [ 'php_fpm_failure' ],
    'validation_rules' => [ 'php_runtime_unavailable' ],
    'backup_scope' => [ 'files' => 'none', 'database' => false ],
    'execution_steps' => [ 'acquire_lock', 'record_service_failure', 'queue_approved_operation', 'release_lock' ],
    'verification_steps' => [ 'php_runtime_healthy' ],
    'rollback_steps' => [ 'log_failure' ],
    'max_attempts' => 1,
    'cooldown_seconds' => 300,
    'requires_user_approval' => true,
    'requires_secure_enclave' => true,
];
