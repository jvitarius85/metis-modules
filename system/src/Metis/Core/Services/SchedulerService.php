<?php
declare(strict_types=1);

namespace Metis\Core\Services;

final class SchedulerService {
    public function tasks(): array {
        if (\class_exists('Metis_Cron_Manager')) {
            return \Metis_Cron_Manager::registered_tasks();
        }

        return [];
    }

    public function run(string $task, bool $force = false, string $trigger = 'manual'): array {
        if (\class_exists('Metis_Cron_Manager')) {
            $result = \Metis_Cron_Manager::run_due_tasks([\metis_key_clean($task)], $force, $trigger);
            return (array) ($result['results'][\metis_key_clean($task)] ?? ['status' => 'missing']);
        }

        return ['status' => 'unavailable'];
    }
}
