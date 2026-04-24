<?php
declare(strict_types=1);

namespace Metis\Core\Error;

final class TraceIdGenerator {
    public function generate(): string {
        try {
            return strtoupper( bin2hex( random_bytes( 8 ) ) );
        } catch ( \Throwable ) {
            return strtoupper( dechex( time() ) . dechex( mt_rand( 0, 0xffff ) ) );
        }
    }
}
