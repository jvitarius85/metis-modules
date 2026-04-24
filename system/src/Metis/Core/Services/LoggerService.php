<?php
declare(strict_types=1);

namespace Metis\Core\Services;

final class LoggerService {
    public function debug(string $message, array $context = []): void {
        if (\class_exists('Metis_Logger')) {
            \Metis_Logger::debug($message, $context);
        }
    }

    public function info(string $message, array $context = []): void {
        if (\class_exists('Metis_Logger')) {
            \Metis_Logger::info($message, $context);
        }
    }

    public function warn(string $message, array $context = []): void {
        if (\class_exists('Metis_Logger')) {
            \Metis_Logger::warn($message, $context);
        }
    }

    public function error(string $message, array $context = []): void {
        if (\class_exists('Metis_Logger')) {
            \Metis_Logger::error($message, $context);
        }
    }

    public function activity(string $action, array $context = []): void {
        if (\function_exists('metis_audit_log_activity')) {
            \metis_audit_log_activity($action, ['module' => 'core', 'context' => $context]);
        }
    }

    public function security(string $action, array $context = [], string $severity = 'warning', string $outcome = 'blocked'): void {
        if (\function_exists('metis_audit_log_security')) {
            \metis_audit_log_security($action, [
                'module' => 'core',
                'severity' => $severity,
                'outcome' => $outcome,
                'context' => $context,
            ]);
        }
    }
}
