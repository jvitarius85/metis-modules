<?php
declare(strict_types=1);

define( 'METIS_ENTRY', 'ajax' );

// @metis-governance ajax-security: kernel delegates AJAX nonce, csrf, permission, and SecureEnclave enforcement to RouterRuntime/AjaxRuntime.
require_once __DIR__ . '/src/Metis/Core/Kernel/Runtime.php';

metis_kernel_execute( 'ajax' );
