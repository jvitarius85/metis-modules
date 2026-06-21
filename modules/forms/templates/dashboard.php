<?php
if ( ! defined( 'METIS_ROOT' ) ) {
    exit;
}

$view = __DIR__ . '/../views/dashboard.php';
if ( ! is_file( $view ) ) {
    echo '<div class="metis-alert metis-alert-error">Forms dashboard view is missing.</div>';
    return;
}

require $view;
