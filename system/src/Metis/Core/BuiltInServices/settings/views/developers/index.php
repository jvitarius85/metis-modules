<?php
if ( ! defined( 'METIS_ROOT' ) ) exit;
require_once dirname( __DIR__ ) . '/_settings_bootstrap.php';
$route = metis_settings_route_parts();
$page = (string) ( $route['tail'] ?? 'api' );
if ( $page === '' ) {
    $page = 'api';
}

switch ( $page ) {
    case 'api':
        require dirname( __DIR__ ) . '/developers_api.php';
        break;
    default:
        require dirname( __DIR__ ) . '/developers_api.php';
        break;
}
