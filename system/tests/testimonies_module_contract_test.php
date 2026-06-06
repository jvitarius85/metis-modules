<?php
declare(strict_types=1);

if ( PHP_SAPI !== 'cli' ) {
    fwrite( STDERR, "This test must be run from the command line.\n" );
    exit( 1 );
}

$root = dirname( __DIR__ );

define( 'METIS_STANDALONE', true );
define( 'METIS_PREFIX', 'metis' );
define( 'METIS_PATH', dirname( $root ) . '/' );
define( 'METIS_URL', 'http://localhost/metis/' );

$_SERVER['HTTP_HOST'] = $_SERVER['HTTP_HOST'] ?? 'localhost';
$_SERVER['HTTPS'] = $_SERVER['HTTPS'] ?? 'off';
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = '/';

require_once $root . '/src/Metis/Core/CoreBootstrap.php';
metis_define_system_version( dirname( $root ) . '/' );
metis_core_bootstrap( [ 'standalone_bootstrap', 'service_registry', 'ajax', 'router', 'security_runtime_bridge' ] );
metis_standalone_boot();
metis_register_core_services();

$failures = [];

$assert = static function ( bool $condition, string $message ) use ( &$failures ): void {
    if ( ! $condition ) {
        $failures[] = $message;
    }
};

$manifestPath = $root . '/modules/testimonies/module.json';
$manifestJson = @file_get_contents( $manifestPath );
$manifest = is_string( $manifestJson ) ? json_decode( $manifestJson, true ) : null;
$ajaxSource = (string) @file_get_contents( $root . '/modules/testimonies/assets/testimonies.ajax.php' );
$jsSource = (string) @file_get_contents( $root . '/modules/testimonies/assets/testimonies.js' );
$editorJsSource = (string) @file_get_contents( $root . '/assets/js/editor/simple-editor.js' );
$viewSource = (string) @file_get_contents( $root . '/modules/testimonies/views/dashboard.php' );
$websiteRendererSource = (string) @file_get_contents( $root . '/src/Metis/Modules/Website/Services/WebsiteRenderer.php' );

$assert( is_array( $manifest ), 'Testimonies module.json must decode as valid JSON.' );
$assert( (string) ( $manifest['slug'] ?? '' ) === 'testimonies', 'Testimonies manifest slug must be testimonies.' );
$assert( (string) ( $manifest['entry'] ?? '' ) === 'Module.php', 'Testimonies manifest entry must point to Module.php.' );
$assert( str_contains( $ajaxSource, "metis_ajax_register_controller( \$action, [" ), 'Testimonies AJAX asset must register controller metadata.' );
$assert( str_contains( $ajaxSource, "metis_testimonies_ajax_verify_nonce" ), 'Testimonies AJAX asset must define a nonce verifier.' );
$assert( str_contains( $jsSource, 'Metis.request.post(' ), 'Testimonies UI must use the shared request service.' );
$assert( str_contains( $jsSource, 'Metis.confirm.open' ), 'Testimonies UI must use the shared confirm service.' );
$assert( str_contains( $editorJsSource, 'state.options.testimonyCategories' ) && str_contains( $editorJsSource, "categoryChipField('metis-v2-testimony-category-ids'" ), 'Website testimony block editor must use testimonyCategories, not generic Website categories.' );
$assert( str_contains( $editorJsSource, "} else if (out.type === 'testimonials') {"
    ) && str_contains( $editorJsSource, 'out.content.category_ids = normalizeIdList(content.category_ids || []);'
    ) && str_contains( $editorJsSource, "out.content.layout = ['grid', 'list', 'rotator'].indexOf(testimonyLayout) === -1 ? 'grid' : testimonyLayout;"
    ), 'Website testimony block editor must rehydrate saved category_ids and layout values.' );
$assert( str_contains( $editorJsSource, "if (fieldId === 'metis-v2-posts-category-ids' || fieldId === 'metis-v2-testimony-category-ids') {" )
    && str_contains( $editorJsSource, 'activeSection().content.category_ids = selectedIds;'
    ) && str_contains( $editorJsSource, 'renderBuilderCanvas();' ),
    'Website testimony block editor must sync testimony category chip clicks back into section state and rerender the block summary.'
);
$assert( str_contains( $websiteRendererSource, "'campaign_summary', 'testimonials', 'divider'" )
    && str_contains( $websiteRendererSource, "if ( \$type === 'testimonials' ) {" )
    && str_contains( $websiteRendererSource, "return self::renderStructuredBlockModule(" )
    && str_contains( $websiteRendererSource, "'testimonies_block'" ),
    'Website public renderer must recognize testimonials sections and route them to testimonies_block rendering.'
);
$assert( ! str_contains( $viewSource, 'metis-testimonies-alert' ), 'Testimonies dashboard must not render inline error containers.' );

$loader = new \Metis\Core\ModuleLoader();
$report = (array) $loader->complianceReport( true );
$results = is_array( $report['results'] ?? null ) ? $report['results'] : [];
$testimoniesRow = null;
foreach ( $results as $row ) {
    if ( is_array( $row ) && (string) ( $row['module'] ?? '' ) === 'testimonies' ) {
        $testimoniesRow = $row;
        break;
    }
}

$assert( is_array( $testimoniesRow ), 'Module compliance report must include testimonies.' );
$assert( (string) ( $testimoniesRow['status'] ?? '' ) === 'ok', 'Testimonies module must pass module compliance.' );

$expectedControllers = [
    'metis_testimonies_save',
    'metis_testimonies_delete',
    'metis_testimony_categories_save',
    'metis_testimony_categories_delete',
];

$controllers = metis_ajax_registry()->all();
$nonces = metis_ajax_action_nonces();
$enclave = metis_security_enclave();

if ( function_exists( 'metis_security_register_ajax_policies' ) ) {
    metis_security_register_ajax_policies();
}

foreach ( $expectedControllers as $action ) {
    $controller = $controllers[ $action ] ?? null;
    $assert( is_array( $controller ), sprintf( 'Testimonies controller [%s] must be registered.', $action ) );
    $assert( (string) ( $controller['module'] ?? '' ) === 'testimonies', sprintf( 'Testimonies controller [%s] must resolve to module testimonies.', $action ) );
    $assert( isset( $nonces[ $action ] ) && trim( (string) $nonces[ $action ] ) !== '', sprintf( 'Testimonies controller [%s] must have an action nonce.', $action ) );
    $assert( $enclave->has_policy( sprintf( 'ajax.testimonies.%s', $action ) ), sprintf( 'Testimonies controller [%s] must have an enclave policy.', $action ) );
}

if ( $failures !== [] ) {
    fwrite( STDERR, implode( PHP_EOL, $failures ) . PHP_EOL );
    exit( 1 );
}

fwrite( STDOUT, "Testimonies module contract checks passed.\n" );
