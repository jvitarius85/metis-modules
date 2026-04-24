<?php
if ( ! defined( 'METIS_ROOT' ) ) {
    exit;
}

$view = __DIR__ . '/../views/settings.php';
if ( ! is_file( $view ) ) {
    echo '<div class="metis-alert metis-alert-error">Forms settings view is missing.</div>';
    return;
}

require $view;
