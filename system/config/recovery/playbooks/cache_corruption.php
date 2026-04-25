<?php
return [
    'playbook_key' => 'cache_corruption',
    'description' => 'Clear and rebuild application caches without touching persistent data.',
    'severity' => 'medium',
    'trigger_conditions' => [ 'cache_corruption' ],
    'validation_rules' => [ 'cache_path_detected' ],
    'backup_scope' => [ 'files' => 'none', 'database' => false ],
    'execution_steps' => [ 'acquire_lock', 'clear_runtime_cache', 'rebuild_system_caches', 'release_lock' ],
    'verification_steps' => [ 'cache_rebuild_passes' ],
    'rollback_steps' => [ 'log_failure' ],
    'max_attempts' => 2,
    'cooldown_seconds' => 120,
    'requires_user_approval' => true,
    'requires_secure_enclave' => true,
];
