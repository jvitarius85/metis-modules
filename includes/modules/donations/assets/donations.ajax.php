<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Donations AJAX Loader
 * Bootstraps all donations AJAX handler files.
 */

$metis_ajax_modules = [
    __DIR__ . '/notes.ajax.php',
    __DIR__ . '/donor_intelligence.ajax.php',
    __DIR__ . '/reports.ajax.php',
    __DIR__ . '/deposits.ajax.php',
    __DIR__ . '/campaigns.ajax.php',
];

// Stripe import handler (lives in apis/stripe, loaded separately)
require_once dirname( __DIR__, 3 ) . '/apis/stripe/stripe-import-transactions.php';

foreach ( $metis_ajax_modules as $module ) {
    if ( file_exists( $module ) ) {
        require_once $module;
    } else {
        Metis_Logger::warn( 'Missing donations AJAX module', [ 'file' => basename( $module ) ] );
    }
}

Metis_Logger::info( 'Donations AJAX loader complete' );
