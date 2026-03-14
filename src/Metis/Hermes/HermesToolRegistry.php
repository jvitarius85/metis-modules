<?php
declare(strict_types=1);

namespace Metis\Hermes;

final class HermesToolRegistry {
    public function definitions(): array {
        return [
            'run_diagnostic' => [
                'title' => 'Run Diagnostic',
                'summary' => 'Generate a bounded diagnostic report and store the result.',
            ],
            'open_help_topic' => [
                'title' => 'Open Help Topic',
                'summary' => 'Resolve a Metis help topic and return grounded guidance.',
            ],
            'launch_walkthrough' => [
                'title' => 'Launch Walkthrough',
                'summary' => 'Mark a walkthrough as started and return launch metadata.',
            ],
            'execute_mission' => [
                'title' => 'Execute Mission',
                'summary' => 'Run a Hermes mission plan through approved enclave execution.',
            ],
            'queue_scheduled_diagnostics' => [
                'title' => 'Queue Scheduled Diagnostics',
                'summary' => 'Enqueue the Hermes diagnostics worker for deferred execution.',
            ],
        ];
    }

    public function definition( string $action_type ): array {
        return $this->definitions()[ $action_type ] ?? [
            'title' => ucwords( str_replace( '_', ' ', $action_type ) ),
            'summary' => 'Hermes action.',
        ];
    }
}
