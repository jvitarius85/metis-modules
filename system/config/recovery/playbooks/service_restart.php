<?php
return [
    'playbook_key' => 'service_restart',
    'description' => 'Log service unavailability and route restart work through approved operations.',
    'severity' => 'high',
    'trigger_conditions' => [ 'service_unavailable' ],
    'validation_rules' => [ 'service_unavailable_detected' ],
    'backup_scope' => [ 'files' => 'none', 'database' => false ],
    'execution_steps' => [ 'acquire_lock', 'record_service_failure', 'queue_approved_operation', 'release_lock' ],
    'verification_steps' => [ 'service_health_passes' ],
    'rollback_steps' => [ 'log_failure' ],
    'max_attempts' => 1,
    'cooldown_seconds' => 300,
    'requires_user_approval' => true,
    'requires_secure_enclave' => true,
];
