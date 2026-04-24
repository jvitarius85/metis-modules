<?php
if ( ! defined( 'METIS_ROOT' ) ) {
    exit;
}

$view = __DIR__ . '/../views/settings.php';
if ( ! is_file( $view ) ) {
    echo '<div class="mw-alert mw-alert-error">Forms settings view is missing.</div>';
    return;
}

require $view;
