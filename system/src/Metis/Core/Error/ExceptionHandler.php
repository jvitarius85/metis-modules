<?php
declare(strict_types=1);

namespace Metis\Core\Error;

final class ExceptionHandler {
    public function __construct(
        private readonly ErrorKernel $kernel
    ) {}

    public function __invoke( \Throwable $throwable ): void {
        $this->kernel->handleThrowable( $throwable );
    }
}
