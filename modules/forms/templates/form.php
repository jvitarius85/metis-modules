<?php
if ( ! defined( 'METIS_ROOT' ) ) {
    exit;
}

$view = __DIR__ . '/../views/form.php';
if ( ! is_file( $view ) ) {
    echo '<div class="mw-alert mw-alert-error">Forms detail view is missing.</div>';
    return;
}

require $view;
