<?php
declare(strict_types=1);

define( 'METIS_ENTRY', 'webhook' );

require_once __DIR__ . '/src/Metis/Core/Runtime/RequestRuntime.php';
require_once __DIR__ . '/src/Metis/Core/Kernel/Runtime.php';

metis_kernel_execute( 'webhook', [
    'provider' => (string) ( metis_request_get()['provider'] ?? '' ),
] );
