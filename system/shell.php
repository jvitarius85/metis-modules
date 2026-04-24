<?php
declare(strict_types=1);

define( 'METIS_ENTRY', 'shell' );

require_once dirname( __DIR__ ) . '/src/Metis/Core/Kernel/Runtime.php';

metis_kernel_execute( 'cli' );
