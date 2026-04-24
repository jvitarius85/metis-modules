<?php
if ( ! defined( 'METIS_ROOT' ) ) {
    exit;
}

$view = __DIR__ . '/../views/build.php';
if ( ! is_file( $view ) ) {
    echo '<div class="mw-alert mw-alert-error">Forms builder view is missing.</div>';
    return;
}

require $view;
