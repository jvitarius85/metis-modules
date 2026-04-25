<?php
if ( ! defined( 'METIS_ROOT' ) ) exit;
require_once dirname( __DIR__ ) . '/_settings_bootstrap.php';
$route = metis_settings_route_parts();
$page = (string) ( $route['tail'] ?? 'general' );
if ( $page === '' ) {
    $page = 'general';
}

switch ( $page ) {
    case 'general':
        require dirname( __DIR__ ) . '/general.php';
        break;
    case 'user-experience':
        require dirname( __DIR__ ) . '/user_experience.php';
        break;
    case 'branding':
        require dirname( __DIR__ ) . '/customization.php';
        break;
    case 'navigation':
        require dirname( __DIR__ ) . '/menu.php';
        break;
    default:
        require dirname( __DIR__ ) . '/general.php';
        break;
}
