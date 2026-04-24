<?php
declare(strict_types=1);

namespace Metis\Core\Error;

interface RecoveryStrategyInterface {
    public function supports( ErrorContext $context, array $payload = [] ): bool;

    public function recover( ErrorContext $context, array $payload = [] ): array;
}
