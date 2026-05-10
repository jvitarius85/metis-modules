<?php
if ( ! defined( 'METIS_ROOT' ) ) exit;

// @metis-governance ajax-security: delegated handlers register nonce, csrf, permission, and SecureEnclave contracts through AjaxRuntime.
require_once __DIR__ . '/../ajax/contacts.ajax.php';
require_once __DIR__ . '/../ajax/relationships.ajax.php';
require_once __DIR__ . '/../ajax/imports.ajax.php';
require_once __DIR__ . '/../ajax/exports.ajax.php';
require_once __DIR__ . '/../ajax/lists.ajax.php';
require_once __DIR__ . '/../ajax/carddav.ajax.php';
