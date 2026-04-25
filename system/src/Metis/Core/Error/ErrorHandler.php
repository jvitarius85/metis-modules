<?php
declare(strict_types=1);

namespace Metis\Core\Error;

final class ErrorHandler {
    public function __construct(
        private readonly ErrorKernel $kernel
    ) {}

    public function __invoke( int $severity, string $message, string $file = '', int $line = 0 ): bool {
        if ( ! ( error_reporting() & $severity ) ) {
            return false;
        }

        throw new \ErrorException( $message, 0, $severity, $file, $line );
    }
}
