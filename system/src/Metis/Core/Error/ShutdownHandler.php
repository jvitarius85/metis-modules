<?php
declare(strict_types=1);

namespace Metis\Core\Error;

final class ShutdownHandler {
    public function __construct(
        private readonly ErrorKernel $kernel
    ) {}

    public function __invoke(): void {
        $this->kernel->handleShutdown();
    }
}
