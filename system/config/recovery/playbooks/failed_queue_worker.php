<?php
return [
    'playbook_key' => 'failed_queue_worker',
    'description' => 'Recover expired queue workers and resume queued operations.',
    'severity' => 'medium',
    'trigger_conditions' => [ 'failed_queue_worker' ],
    'validation_rules' => [ 'queue_worker_failure_detected' ],
    'backup_scope' => [ 'files' => 'none', 'database' => false ],
    'execution_steps' => [ 'acquire_lock', 'recover_expired_jobs', 'drain_queue', 'release_lock' ],
    'verification_steps' => [ 'queue_summary_healthy' ],
    'rollback_steps' => [ 'log_failure' ],
    'max_attempts' => 2,
    'cooldown_seconds' => 300,
    'requires_user_approval' => true,
    'requires_secure_enclave' => true,
];
