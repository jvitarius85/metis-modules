<?php
return [
    'playbook_key' => 'failed_cron',
    'description' => 'Recover stalled scheduled tasks by releasing expired locks and rebuilding queue state.',
    'severity' => 'medium',
    'trigger_conditions' => [ 'failed_cron' ],
    'validation_rules' => [ 'cron_failure_detected' ],
    'backup_scope' => [ 'files' => 'none', 'database' => false ],
    'execution_steps' => [ 'acquire_lock', 'recover_expired_jobs', 'drain_queue', 'release_lock' ],
    'verification_steps' => [ 'cron_queue_healthy' ],
    'rollback_steps' => [ 'log_failure' ],
    'max_attempts' => 2,
    'cooldown_seconds' => 300,
    'requires_user_approval' => true,
    'requires_secure_enclave' => true,
];
