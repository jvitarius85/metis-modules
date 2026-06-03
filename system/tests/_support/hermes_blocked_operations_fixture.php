<?php
declare(strict_types=1);

require_once dirname( __DIR__ ) . '/../src/Metis/Hermes/HermesBlockedOperationCatalog.php';

/**
 * @return array<string,array<string,string>>
 */
function metis_hermes_blocked_operations_fixture(): array {
    return \Metis\Hermes\HermesBlockedOperationCatalog::definitions();
}
