<?php
return [
    'playbook_key' => 'routing_failure',
    'description' => 'Recover routing failures by validating route files and clearing route caches.',
    'severity' => 'high',
    'trigger_conditions' => [ 'routing_failure' ],
    'validation_rules' => [ 'route_resolution_failed' ],
    'backup_scope' => [ 'files' => 'affected', 'database' => false ],
    'execution_steps' => [ 'acquire_lock', 'backup_current_files', 'run_integrity_scan', 'clear_route_cache', 'release_lock' ],
    'verification_steps' => [ 'router_resolves_request' ],
    'rollback_steps' => [ 'restore_quarantined_file', 'log_failure' ],
    'max_attempts' => 2,
    'cooldown_seconds' => 300,
    'requires_user_approval' => true,
    'requires_secure_enclave' => true,
];
