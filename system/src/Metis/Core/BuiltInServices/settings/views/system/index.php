<?php
if ( ! defined( 'METIS_ROOT' ) ) exit;
require_once dirname( __DIR__ ) . '/_settings_bootstrap.php';
$route = metis_settings_route_parts();
$page = (string) ( $route['tail'] ?? 'runtime' );
if ( $page === '' ) {
    $page = 'runtime';
}

switch ( $page ) {
    case 'runtime':
        require dirname( __DIR__ ) . '/runtime.php';
        break;
    case 'jobs-tasks':
        require dirname( __DIR__ ) . '/jobs_tasks.php';
        break;
    default:
        require dirname( __DIR__ ) . '/runtime.php';
        break;
}
