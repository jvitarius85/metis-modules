<?php
declare(strict_types=1);

namespace Metis\Core\Error;

final class RecoveryRegistry {
    /** @var array<int, RecoveryStrategyInterface> */
    private array $strategies = [];

    public function register( RecoveryStrategyInterface $strategy ): void {
        $this->strategies[] = $strategy;
    }

    /** @return array<int, RecoveryStrategyInterface> */
    public function all(): array {
        return $this->strategies;
    }
}
