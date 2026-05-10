<?php
declare(strict_types=1);

if ( ! defined( 'METIS_ROOT' ) ) {
	exit;
}

// @metis-governance ajax-security: delegated website handlers enforce nonce, csrf, permission, and SecureEnclave contracts.
require_once dirname( __DIR__ ) . '/ajax/website.ajax.php';
