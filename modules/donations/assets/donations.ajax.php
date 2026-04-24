<?php
if ( ! defined( 'METIS_ROOT' ) ) exit;

/**
 * Donations AJAX Loader
 * Bootstraps all donations AJAX handler files.
 */

$metis_ajax_modules = [
    __DIR__ . '/notes.ajax.php',
    __DIR__ . '/offline.ajax.php',
    __DIR__ . '/donor_intelligence.ajax.php',
    __DIR__ . '/reports.ajax.php',
    __DIR__ . '/deposits.ajax.php',
    __DIR__ . '/campaigns.ajax.php',
];

foreach ( $metis_ajax_modules as $module ) {
    if ( file_exists( $module ) ) {
        require_once $module;
    } else {
        Metis_Logger::warn( 'Missing donations AJAX module', [ 'file' => basename( $module ) ] );
    }
}

Metis_Logger::info( 'Donations AJAX loader complete' );
