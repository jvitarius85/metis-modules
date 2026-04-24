<?php
declare(strict_types=1);

namespace Metis\Hermes;

use Metis\Services\HermesDefinitionLibrary;

final class HermesContextPackLoader {
    public function __construct(
        private readonly HermesDefinitionLibrary $library
    ) {}

    public function loadForCommand( array $command ): array {
        $packs = [];

        foreach ( (array) ( $command['context'] ?? [] ) as $key ) {
            $resolved = $this->library->getContextPack( (string) $key );
            if ( is_array( $resolved ) ) {
                $packs[] = $resolved;
            }
        }

        return $packs;
    }
}
