<?php
if ( ! defined( 'METIS_ROOT' ) ) exit;
$route = function_exists( 'metis_settings_route_parts' ) ? metis_settings_route_parts() : [ 'tail' => 'about' ];
$page = metis_key_clean( (string) ( $route['tail'] ?? 'about' ) );
if ( $page === '' ) {
    $page = 'about';
}

$view = dirname( __DIR__ ) . '/' . $page . '.php';
if ( ! is_file( $view ) ) {
    $view = dirname( __DIR__ ) . '/about.php';
}

require $view;
