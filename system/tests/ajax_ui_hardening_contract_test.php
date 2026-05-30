<?php
declare(strict_types=1);

if ( PHP_SAPI !== 'cli' ) {
    fwrite( STDERR, "This test must be run from the command line.\n" );
    exit( 1 );
}

$system = dirname( __DIR__ );
$root = dirname( $system );
$failures = [];
$rawSqlPattern = '/\bSELECT\s+.+\bFROM\b|\bINSERT\s+INTO\b|\bUPDATE\s+\S+\s+SET\b|\bDELETE\s+FROM\b/us';

$assert = static function ( bool $condition, string $message ) use ( &$failures ): void {
    if ( ! $condition ) {
        $failures[] = $message;
    }
};

$read = static function ( string $relative ) use ( $system ): string {
    $contents = @file_get_contents( $system . '/' . ltrim( $relative, '/\\' ) );
    return is_string( $contents ) ? $contents : '';
};

$responseRuntime = $read( 'src/Metis/Core/Runtime/ResponseRuntime.php' );
$coreJs = $read( 'assets/core.js' );
$formsJs = $read( 'modules/forms/assets/forms.js' );
$formsRepository = $read( 'src/Metis/Modules/Forms/Concerns/SharedRepositoryLogic.php' );
$donationsCampaignService = $read( 'src/Metis/Modules/Donations/CampaignService.php' );
$donationsReadService = $read( 'src/Metis/Modules/Donations/ReadService.php' );
$peopleReadService = $read( 'src/Metis/Modules/People/ReadService.php' );
$newsletterReadService = $read( 'src/Metis/Modules/Newsletter/ReadService.php' );
$governanceChecker = $read( '../tools/governance/check-ajax-ui-hardening.php' );

$assert( str_contains( $responseRuntime, 'function metis_runtime_send_json_success' ), 'Response runtime must expose the canonical JSON success helper.' );
$assert( str_contains( $responseRuntime, "'message' => \$message" ), 'Structured JSON responses must include message.' );
$assert( str_contains( $responseRuntime, "'data'    => \$data" ) || str_contains( $responseRuntime, "'data'    => \$payload" ), 'Structured JSON responses must include data.' );
$assert( str_contains( $responseRuntime, "'errors'  => []" ) && str_contains( $responseRuntime, "'success' => true" ), 'Structured JSON success responses must include errors and success fields.' );
$assert( str_contains( $responseRuntime, "'request_id' => \$request_id !== '' ? \$request_id : null" ), 'Structured JSON responses must include request_id.' );

$assert( str_contains( $coreJs, 'Metis.ui.ajax = Metis.ajax;' ), 'Core UI runtime must expose Metis.ui.ajax alias.' );
$assert( str_contains( $coreJs, 'Metis.ui.toast = Metis.toast;' ), 'Core UI runtime must expose Metis.ui.toast alias.' );
$assert( str_contains( $coreJs, 'Metis.ui.modal = Metis.modal;' ), 'Core UI runtime must expose Metis.ui.modal alias.' );
$assert( str_contains( $coreJs, 'Metis.ui.confirm = Metis.confirm;' ), 'Core UI runtime must expose Metis.ui.confirm alias.' );
$assert( str_contains( $coreJs, 'Metis.ui.loading = (function() {' ), 'Core UI runtime must expose Metis.ui.loading helper.' );
$assert( str_contains( $coreJs, 'Metis.ui.form = (function() {' ), 'Core UI runtime must expose Metis.ui.form helper.' );
$assert( str_contains( $coreJs, 'Metis.ui.select = (function() {' ), 'Core UI runtime must expose Metis.ui.select helper.' );
$assert( str_contains( $coreJs, 'Metis.ui.dropdown = {' ), 'Core UI runtime must expose Metis.ui.dropdown helper.' );
$assert( str_contains( $coreJs, 'Metis.ui.modal.confirm = function(options)' ), 'Core UI runtime must expose Metis.ui.modal.confirm helper.' );
$assert( str_contains( $coreJs, 'Metis.ui.modal.form = function(target)' ), 'Core UI runtime must expose Metis.ui.modal.form helper.' );

$assert( str_contains( $formsRepository, 'CampaignService::getActiveCampaignOptions()' ), 'Form Builder campaign options must route through the canonical campaign service.' );
$assert( str_contains( $donationsCampaignService, 'getActiveCampaignOptions' ), 'Donations campaign service must expose active campaign options.' );
$assert( str_contains( $formsJs, 'Metis.ui.ajax.post' ), 'Form Builder admin requests must delegate through Metis.ui.ajax.post.' );
$assert( str_contains( $formsJs, 'Metis.ui.form.setSubmitting' ), 'Form Builder public submit state must delegate through Metis.ui.form.' );
$assert( str_contains( $formsJs, 'Metis.ui.select.init(root);' ), 'Form Builder render cycle must reinitialize the canonical select helper.' );
$assert( ! preg_match( '/(^|[^A-Za-z0-9_])alert\s*\(|(^|[^A-Za-z0-9_])confirm\s*\(/u', $formsJs ), 'Form Builder must not use browser-native alert/confirm fallbacks.' );

$assert( str_contains( $donationsReadService, 'public static function dashboardSnapshot()' ), 'Donations read service must expose dashboardSnapshot().' );
$assert( str_contains( $peopleReadService, 'public static function workspaceSnapshot' ) && str_contains( $peopleReadService, 'public static function personSnapshot' ), 'People read service must expose workspace and person snapshots.' );
$assert( str_contains( $newsletterReadService, 'public static function campaignsSnapshot' ) && str_contains( $newsletterReadService, 'public static function dashboardSnapshot' ), 'Newsletter read service must expose canonical campaign/dashboard snapshots.' );

$viewExpectations = [
    'modules/donations/views/dashboard.php' => 'ReadService::dashboardSnapshot()',
    'modules/people/views/workspace.php' => 'ReadService::workspaceSnapshot(',
    'modules/people/views/person.php' => 'ReadService::personSnapshot(',
    'modules/newsletter/views/campaigns.php' => 'ReadService::campaignsSnapshot(',
    'modules/newsletter/views/editor.php' => 'ReadService::editorId(',
];

foreach ( $viewExpectations as $relative => $needle ) {
    $contents = $read( $relative );
    $assert( $contents !== '', 'Expected hardened view is missing: ' . $relative );
    $assert( str_contains( $contents, $needle ), 'Hardened view must delegate through canonical read service: ' . $relative );
    $assert( preg_match( $rawSqlPattern, $contents ) !== 1, 'Hardened view must not embed raw SQL: ' . $relative );
}

$handlerExpectations = [
    'modules/board/assets/board.ajax.php',
    'modules/contacts/ajax/contacts.ajax.php',
    'modules/donations/assets/deposits.ajax.php',
    'modules/newsletter/assets/newsletter.ajax.php',
    'modules/people/ajax/people.ajax.php',
    'modules/people/ajax/workspace.ajax.php',
];

foreach ( $handlerExpectations as $relative ) {
    $contents = $read( $relative );
    $assert( $contents !== '', 'Expected hardened AJAX handler is missing: ' . $relative );
    $assert( str_contains( $contents, 'metis_ajax_register_controller(' ), 'Hardened AJAX handler must register controller metadata: ' . $relative );
    $assert( preg_match( $rawSqlPattern, $contents ) !== 1, 'Hardened AJAX handler must not embed request-path raw SQL: ' . $relative );
}

$legacyUiCallers = [
    'modules/website/assets/website.js',
    'modules/website/views/webparts.php',
    'modules/website/views/theme.php',
    'modules/website/views/redirects.php',
    'modules/donations/views/recurring.php',
    'modules/donations/views/transaction.php',
    'modules/import/assets/import.js',
];

foreach ( $legacyUiCallers as $relative ) {
    $contents = $read( $relative );
    $assert( $contents !== '', 'Expected hardened UI module is missing: ' . $relative );
    $assert( ! str_contains( $contents, 'window.metis_toast' ), 'Hardened UI module must not call legacy toast alias directly: ' . $relative );
    $assert( ! str_contains( $contents, 'window.metis_confirm' ), 'Hardened UI module must not call legacy confirm alias directly: ' . $relative );
}

$assert( str_contains( $governanceChecker, 'No governance issues detected.' ), 'Governance checker must report a clean state when no issues remain.' );

$governanceOutput = [];
$governanceCode = 0;
exec( 'php ' . escapeshellarg( $root . '/tools/governance/check-ajax-ui-hardening.php' ) . ' 2>&1', $governanceOutput, $governanceCode );
$governanceText = implode( PHP_EOL, $governanceOutput );
$assert( $governanceCode === 0, 'Governance checker must exit cleanly. Output: ' . $governanceText );
$assert( str_contains( $governanceText, 'No governance issues detected.' ), 'Governance checker must report no issues. Output: ' . $governanceText );

if ( $failures !== [] ) {
    fwrite( STDERR, implode( PHP_EOL, $failures ) . PHP_EOL );
    exit( 1 );
}

fwrite( STDOUT, "AJAX/UI hardening contract checks passed.\n" );
