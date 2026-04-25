<?php
declare(strict_types=1);

require_once __DIR__ . '/system/core/Profiler.php';
Profiler::init( Profiler::requestEnabled( defined( 'APP_DEBUG' ) && (bool) APP_DEBUG ) );
Profiler::mark( 'BOOTSTRAP_START' );

require_once __DIR__ . '/system/src/Metis/Core/Kernel/Runtime.php';
Profiler::mark( 'BOOTSTRAP_LOADED' );

try {
    metis_kernel_execute( 'web' );
} finally {
    Profiler::report();
}
