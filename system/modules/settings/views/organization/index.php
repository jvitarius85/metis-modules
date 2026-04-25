<?php
if ( ! defined( 'METIS_ROOT' ) ) exit;
require_once dirname( __DIR__ ) . '/_settings_bootstrap.php';
$route = metis_settings_route_parts();
$page = (string) ( $route['tail'] ?? 'email' );
if ( $page === '' ) {
    $page = 'email';
}

switch ( $page ) {
    case 'email':
        require dirname( __DIR__ ) . '/newsletter.php';
        break;
    case 'payments':
        require dirname( __DIR__ ) . '/payments.php';
        break;
    case 'google-workspace':
        require dirname( __DIR__ ) . '/workspace.php';
        break;
    case 'calendar':
        require dirname( __DIR__ ) . '/calendar.php';
        break;
    case 'drive':
        require dirname( __DIR__ ) . '/drive.php';
        break;
    default:
        require dirname( __DIR__ ) . '/newsletter.php';
        break;
}
