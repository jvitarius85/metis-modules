<?php
declare(strict_types=1);

define( 'METIS_ENTRY', 'ajax' );

require_once __DIR__ . '/src/Metis/Core/Kernel/Runtime.php';

metis_kernel_execute( 'ajax' );
